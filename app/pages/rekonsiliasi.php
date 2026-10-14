<?php

/**
 * Rekonsiliasi: menyandingkan spreadsheet kerja tim dengan isi CLARA.
 *
 * Tim Casual Leasing memegang satu berkas Excel yang diperbarui tiap bulan.
 * Selama ini tidak ada cara memeriksa apakah isinya sama dengan yang tercatat
 * di sistem: yang terlihat cuma totalnya meleset, tanpa petunjuk beda di mana.
 * Halaman ini menjawab pertanyaan itu per baris — mana yang belum diinput, mana
 * yang nominalnya beda, mana yang dicatat dua kali, dan mana yang ada di sistem
 * tapi tidak ada di catatan.
 *
 * Istilah hasilnya sengaja sama dengan audit manual yang pernah dibagikan ke
 * tim: cocok / beda nominal / belum diinput / ada di CLARA saja.
 *
 * Berkas yang diunggah TIDAK disimpan di public/uploads — di sana berkas .xlsx
 * bisa diunduh siapa saja tanpa login (lihat public/uploads/.htaccess yang
 * hanya memblokir berkas gambar & pdf). Berkasnya ditaruh di direktori
 * sementara sistem, dipakai selama sesi, lalu dihapus.
 */

require_once dirname(__DIR__) . '/XlsxReader.php';
require_once dirname(__DIR__) . '/ReconCL.php';
require_once dirname(__DIR__) . '/ReconMatch.php';

/** Batas ukuran unggahan: ikut batas PHP, maksimal 12 MB. */
function _rekon_batas_unggah(): array
{
    $ke = function (string $v): int {
        $v = trim($v); $n = (int) $v; $s = strtoupper(substr($v, -1));
        return $n * (['K' => 1024, 'M' => 1048576, 'G' => 1073741824][$s] ?? 1);
    };
    $batas = 12 * 1048576;
    foreach (['upload_max_filesize', 'post_max_size'] as $k) {
        $v = $ke((string) ini_get($k));
        if ($v > 0) $batas = min($batas, $v);
    }
    return [$batas, round($batas / 1048576, 1) . ' MB'];
}

/** Letak berkas unggahan yang sedang dipakai sesi ini (null bila belum ada). */
function _rekon_berkas(): ?array
{
    $b = $_SESSION['rekon_berkas'] ?? null;
    if (!is_array($b) || !is_file($b['path'] ?? '')) return null;
    return $b;
}

function _rekon_hapus_berkas(): void
{
    $b = $_SESSION['rekon_berkas'] ?? null;
    if (is_array($b) && is_file($b['path'] ?? '')) @unlink($b['path']);
    unset($_SESSION['rekon_berkas']);
}

/**
 * Terima unggahan .xlsx.
 *
 * @return string pesan kesalahan ('' bila berhasil)
 */
function _rekon_terima_unggahan(): string
{
    [$batas, $batasTeks] = _rekon_batas_unggah();
    $f = $_FILES['berkas'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return 'Berkas belum dipilih.';
    if (($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($f['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE)
        return 'Berkas terlalu besar — maksimal ' . $batasTeks . '.';
    if (($f['error'] ?? 0) !== UPLOAD_ERR_OK) return 'Unggahan gagal (kode ' . (int) $f['error'] . '). Coba ulangi.';
    if ((int) $f['size'] <= 0) return 'Berkas kosong (0 byte).';
    if ((int) $f['size'] > $batas) return 'Berkas ' . round($f['size'] / 1048576, 1) . ' MB, melebihi batas ' . $batasTeks . '.';

    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') return 'Jenis berkas harus .xlsx. Di Excel: Simpan Sebagai → Excel Workbook (.xlsx).';

    $tujuan = tempnam(sys_get_temp_dir(), 'clara-rekon-');
    if ($tujuan === false) return 'Sistem tidak bisa menyiapkan berkas sementara. Hubungi admin.';
    if (!@move_uploaded_file($f['tmp_name'], $tujuan)) {
        @unlink($tujuan);
        return 'Berkas gagal dipindahkan ke tempat sementara. Coba ulangi.';
    }
    $x = new XlsxReader($tujuan);
    if (!$x->valid()) { @unlink($tujuan); return 'Berkas ini bukan Excel .xlsx yang bisa dibaca (mungkin .xls lama atau rusak).'; }

    _rekon_hapus_berkas();
    $_SESSION['rekon_berkas'] = ['path' => $tujuan, 'nama' => basename((string) $f['name']), 'waktu' => time()];
    return '';
}

/**
 * Baca satu sheet jadi baris siap banding.
 *
 * @return array{baris:array,bulanAda:array,sales:array,peringatan:array,jenis:string}
 */
function _rekon_baca(XlsxReader $x, string $target, string $jenis, int $tahun, string $periode): array
{
    if ($jenis === 'monitoring') {
        $h = ReconCL::bacaMonitoring($x, $target);
        $bulan = $h['bulan'] ?: $periode;
        $baris = [];
        foreach ($h['baris'] as $b) {
            if ($b['nilai'] == 0.0) continue;
            $baris[] = ['klien' => $b['klien'], 'brand' => $b['brand'], 'kode' => '', 'nilai' => $b['nilai'],
                        'mulai' => $b['mulai'], 'selesai' => $b['selesai'], 'pic' => $b['sales'],
                        'rujukan' => 'baris ' . $b['baris']];
        }
        return ['baris' => $baris, 'bulanAda' => [$bulan], 'sales' => $h['sales'],
                'peringatan' => $h['peringatan'], 'jenis' => 'monitoring'];
    }

    $h = ReconCL::bacaKalender($x, $target, $tahun);
    $baris = [];
    foreach ($h['baris'] as $b) {
        if ($periode !== '' && $b['bulan'] !== $periode) continue;
        // Satu baris kalender bisa memuat nominal untuk beberapa PIC sekaligus
        // (sewa yang dibagi). Tiap porsi jadi barisnya sendiri supaya
        // pembandingnya setara dengan baris alokasi di CLARA.
        foreach ($b['per_pic'] as $pic => $v) {
            if ($v == 0.0) continue;
            $baris[] = ['klien' => $b['tenant'], 'brand' => $b['tenant'], 'kode' => $b['unit'], 'nilai' => $v,
                        'mulai' => $b['mulai'], 'selesai' => $b['selesai'], 'pic' => $pic,
                        'rujukan' => 'baris ' . $b['baris']];
        }
    }
    return ['baris' => $baris, 'bulanAda' => $h['bulanAda'], 'sales' => $h['sales'],
            'total_sheet' => $h['total_sheet'][$periode] ?? null,
            'peringatan' => $h['peringatan'], 'jenis' => 'kalender'];
}

/** Daftar sheet + jenisnya (hasilnya dipakai dua kali: pilihan & pembacaan). */
function _rekon_daftar_sheet(XlsxReader $x): array
{
    $out = [];
    foreach ($x->sheets() as $nama => $target) {
        $awal = []; $n = 0;
        foreach ($x->rows($target, 14) as $sel) { $awal[] = $sel; if (++$n >= 14) break; }
        $jenis = ReconCL::jenisSheet($awal);
        if ($jenis === '' || $jenis === 'rekap') continue;    // rekap tidak per transaksi
        $out[$nama] = ['target' => $target, 'jenis' => $jenis];
    }
    return $out;
}

/** Tahun yang tertulis pada nama sheet (untuk sheet kalender). */
function _rekon_tahun_sheet(string $nama): int
{
    return preg_match('/(20\d{2})/', $nama, $m) ? (int) $m[1] : (int) date('Y');
}

function rekonsiliasi_page(PDO $pdo): void
{
    require_permission('view_pic_report');
    $pid = current_property_id();
    $prop = current_property();
    $pesan = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (post('aksi') === 'hapus') { _rekon_hapus_berkas(); redirect_to('rekon'); }
        $pesan = _rekon_terima_unggahan();
        if ($pesan === '') redirect_to('rekon');
    }

    $berkas = _rekon_berkas();
    $x = $berkas ? new XlsxReader($berkas['path']) : null;
    $sheets = $x ? _rekon_daftar_sheet($x) : [];

    // Sheet & bulan yang sedang dilihat. Bawaannya sheet pertama yang namanya
    // memuat nama properti aktif — tim menamai tabnya "e-Walk 2026 - 2".
    $pilihSheet = (string) getv('sheet', '');
    if ($sheets && ($pilihSheet === '' || !isset($sheets[$pilihSheet]))) {
        $kunci = ($prop['key'] ?? '') === 'pentacity' ? 'pentacity' : 'walk';
        foreach ($sheets as $nama => $s) {
            if (stripos($nama, $kunci) !== false) { $pilihSheet = $nama; break; }
        }
        if ($pilihSheet === '') $pilihSheet = array_key_first($sheets);
    }

    $hasil = null; $perPic = []; $peta = []; $isi = null; $periode = (string) getv('periode', '');
    if ($x && $pilihSheet !== '' && isset($sheets[$pilihSheet])) {
        $s = $sheets[$pilihSheet];
        $tahun = _rekon_tahun_sheet($pilihSheet);
        // Dibaca dua kali: sekali untuk tahu bulan apa saja yang ada di sheet,
        // sekali lagi untuk bulan yang dipilih. Berkasnya kecil (±0,3 detik).
        $semua = _rekon_baca($x, $s['target'], $s['jenis'], $tahun, '');
        $bulanAda = $semua['bulanAda'];
        if ($periode === '' || !in_array($periode, $bulanAda, true)) {
            // Bawaannya bulan terakhir yang CLARA-nya sudah berisi — bulan yang
            // belum berjalan pasti "selisih", dan itu bukan temuan.
            $periode = $bulanAda ? end($bulanAda) : '';
            foreach (array_reverse($bulanAda) as $bl) {
                $c = $pdo->prepare('SELECT COUNT(*) FROM transaction_allocations WHERE property_id=? AND period_key=? AND amount<>0');
                $c->execute([$pid, $bl]);
                if ((int) $c->fetchColumn() > 0) { $periode = $bl; break; }
            }
        }
        $isi = _rekon_baca($x, $s['target'], $s['jenis'], $tahun, $periode);
        $clara = ReconCL::dariClara($pdo, $pid, $periode);

        // Nama PIC dipadankan dulu (MUSI → Muslimin), kalau tidak setiap baris
        // akan terbaca "beda PIC" dan laporannya penuh selisih palsu.
        $peta = ReconCL::petakanPic(
            array_values(array_unique(array_column($isi['baris'], 'pic'))),
            array_values(array_unique(array_column($clara, 'pic')))
        );
        foreach ($isi['baris'] as $i => $b) {
            if (($peta[$b['pic']] ?? '') !== '') $isi['baris'][$i]['pic'] = $peta[$b['pic']];
        }
        $hasil  = ReconMatch::cocokkan($isi['baris'], $clara);
        $perPic = ReconMatch::perPic($isi['baris'], $clara);
    }

    layout('Rekonsiliasi Excel', function () use ($berkas, $sheets, $pilihSheet, $periode, $isi, $hasil, $perPic, $peta, $pesan, $prop) {
        $rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
        $bulanNama = function (string $p): string {
            if (!preg_match('/^(\d{4})-(\d{2})$/', $p, $m)) return $p;
            return ReconCL::BULAN[(int) $m[2] - 1] . ' ' . $m[1];
        };
        ?>
        <style>
        .rk-kartu{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin-bottom:14px}
        .rk-kartu .k{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px 14px}
        .rk-kartu .k b{display:block;font-size:19px;margin-top:3px}
        .rk-kartu .k span{font-size:12px;color:var(--muted)}
        .rk-ok{color:#0f766e}.rk-kurang{color:#b45309}.rk-lebih{color:#be123c}
        .rk-pil{display:inline-block;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:700}
        .rk-nol{background:#ecfdf5;color:#047857}.rk-beda{background:#fef3c7;color:#92400e}
        .rk-form{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
        .rk-rinci summary{cursor:pointer;font-weight:700;padding:9px 0}
        .rk-rinci table{width:100%;font-size:13px}
        @media(max-width:768px){.rk-form{flex-direction:column;align-items:stretch}}
        </style>

        <?php if ($pesan !== ''): ?>
        <div class="panel" style="border-left:4px solid #dc2626;background:#fef2f2"><strong>Gagal:</strong> <?= h($pesan) ?></div>
        <?php endif; ?>

        <div class="panel">
            <h3 style="margin-top:0">1. Berkas Excel tim</h3>
            <?php if (!$berkas): ?>
            <p class="help">Unggah berkas rekap Casual Leasing (.xlsx). Berkasnya hanya dibaca untuk
               dibandingkan, tidak disimpan ke dalam aplikasi, dan hilang saat Anda keluar.</p>
            <form method="post" enctype="multipart/form-data" class="rk-form" style="margin-top:8px">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <div><input type="file" name="berkas" accept=".xlsx" required></div>
                <button class="btn" type="submit">Unggah &amp; Bandingkan</button>
            </form>
            <?php else: ?>
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
                <div>📄 <strong><?= h($berkas['nama']) ?></strong>
                     <span class="help">· diunggah <?= h(date('d/m/Y H:i', (int) $berkas['waktu'])) ?></span></div>
                <form method="post" style="margin:0">
                    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="aksi" value="hapus">
                    <button class="btn" type="submit" style="background:#e2e8f0;color:#334155">Ganti berkas</button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($berkas && $sheets): ?>
        <div class="panel">
            <h3 style="margin-top:0">2. Sheet &amp; bulan</h3>
            <form method="get" class="rk-form">
                <input type="hidden" name="r" value="rekon">
                <div>
                    <label style="display:block;font-size:12px;font-weight:700">Sheet</label>
                    <select name="sheet" onchange="this.form.submit()">
                        <?php foreach ($sheets as $nama => $s): ?>
                        <option value="<?= h($nama) ?>" <?= $nama === $pilihSheet ? 'selected' : '' ?>>
                            <?= h($nama) ?> — <?= h($s['jenis']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:12px;font-weight:700">Bulan</label>
                    <select name="periode" onchange="this.form.submit()">
                        <?php foreach (($isi['bulanAda'] ?? []) as $bl): ?>
                        <option value="<?= h($bl) ?>" <?= $bl === $periode ? 'selected' : '' ?>><?= h($bulanNama($bl)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="help">Properti aktif: <strong><?= h($prop['name'] ?? '-') ?></strong></div>
            </form>
            <?php if ($peta): ?>
            <div class="help" style="margin-top:8px">Nama PIC dipadankan otomatis:
                <?php foreach ($peta as $e => $c): ?>
                <span class="rk-pil <?= $c !== '' ? 'rk-nol' : 'rk-beda' ?>"><?= h($e) ?> → <?= h($c !== '' ? $c : 'belum cocok') ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php
            /* Pemeriksaan mandiri: sheet punya baris "TOTAL / REALISASI" sendiri
               di kaki tiap bulan. Hasil baca sistem dijumlahkan lalu dibandingkan
               dengan baris itu. Kalau berbeda, berarti ada baris yang terlewat
               atau terhitung dua kali — dan itu harus kelihatan, bukan lolos jadi
               "selisih" yang menuduh CLARA. Pemeriksaan ini pernah menemukan dua
               hal sekaligus: salah baca di sistem, dan subtotal yang keliru di
               spreadsheet-nya sendiri. */
            $ts = $isi['total_sheet'] ?? null;
            if ($ts && ($ts['actual'] ?? 0) > 0):
                $bacaActual = 0.0;
                foreach (($isi['baris'] ?? []) as $b) $bacaActual += 0;   // nominal per PIC, bukan ACTUAL
                $jmlPicSheet = array_sum($ts['pic'] ?? []);
                $jmlPicBaca  = array_sum(array_column($isi['baris'] ?? [], 'nilai'));
                $cocokTotal  = $jmlPicSheet > 0 ? abs($jmlPicSheet - $jmlPicBaca) < 1 : null;
            ?>
            <div class="help" style="margin-top:6px">
                Pemeriksaan pembacaan:
                jumlah baris yang terbaca <strong><?= $rp($jmlPicBaca) ?></strong>
                <?php if ($jmlPicSheet > 0): ?>
                · baris TOTAL di sheet <strong><?= $rp($jmlPicSheet) ?></strong>
                <span class="rk-pil <?= $cocokTotal ? 'rk-nol' : 'rk-beda' ?>">
                    <?= $cocokTotal ? 'cocok' : 'beda ' . $rp($jmlPicBaca - $jmlPicSheet) ?></span>
                <?php if ($cocokTotal === false): ?>
                <br>Periksa dulu spreadsheet-nya: ada baris yang tidak ikut terjumlah di sana, atau sebaliknya.
                <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php foreach (($isi['peringatan'] ?? []) as $p): ?>
            <div class="help" style="color:#b45309">⚠ <?= h($p) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($hasil): $R = $hasil['ringkas']; ?>
        <div class="rk-kartu">
            <div class="k"><span>Menurut Excel</span><b><?= $rp($R['total_excel']) ?></b>
                <span><?= (int) $R['n_excel'] ?> baris</span></div>
            <div class="k"><span>Tercatat di CLARA</span><b><?= $rp($R['total_clara']) ?></b>
                <span><?= (int) $R['n_clara'] ?> baris</span></div>
            <div class="k"><span>Selisih</span>
                <b class="<?= abs($R['selisih']) < 1 ? 'rk-ok' : ($R['selisih'] < 0 ? 'rk-kurang' : 'rk-lebih') ?>">
                    <?= abs($R['selisih']) < 1 ? 'Rp 0 — sama' : $rp($R['selisih']) ?></b>
                <span><?= $R['selisih'] < 0 ? 'CLARA lebih kecil' : ($R['selisih'] > 0 ? 'CLARA lebih besar' : 'tidak ada beda') ?></span></div>
            <div class="k"><span>Baris sudah cocok</span><b class="rk-ok"><?= (int) $R['n_cocok'] ?></b>
                <span>dari <?= (int) $R['n_excel'] ?> baris Excel</span></div>
        </div>

        <?php if ((int) $R['n_clara'] === 0): ?>
        <div class="panel" style="border-left:4px solid #b45309;background:#fffbeb">
            <strong>CLARA belum punya catatan apa pun untuk <?= h($bulanNama($periode)) ?>.</strong>
            <p class="help" style="margin:4px 0 0">Seluruh isi spreadsheet akan terbaca sebagai &ldquo;belum diinput&rdquo;,
            dan itu bukan temuan. Biasanya karena bulannya di luar jangkauan data CLARA — pilih bulan lain,
            atau pastikan properti yang aktif sudah benar.</p>
        </div>
        <?php endif; ?>

        <div class="panel">
            <h3 style="margin-top:0">Rekap per PIC — <?= h($bulanNama($periode)) ?></h3>
            <table>
                <thead><tr><th>PIC</th><th style="text-align:right">Menurut Excel</th>
                    <th style="text-align:right">Tercatat di CLARA</th><th style="text-align:right">Selisih</th></tr></thead>
                <tbody>
                <?php foreach ($perPic as $p): ?>
                <tr>
                    <td data-label="PIC"><strong><?= h($p['pic']) ?></strong>
                        <span class="help">(<?= (int) $p['n_excel'] ?> / <?= (int) $p['n_clara'] ?> baris)</span></td>
                    <td data-label="Excel" style="text-align:right"><?= $rp($p['excel']) ?></td>
                    <td data-label="CLARA" style="text-align:right"><?= $rp($p['clara']) ?></td>
                    <td data-label="Selisih" style="text-align:right">
                        <span class="rk-pil <?= abs($p['selisih']) < 1 ? 'rk-nol' : 'rk-beda' ?>">
                            <?= abs($p['selisih']) < 1 ? 'sama' : $rp($p['selisih']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="panel">
            <h3 style="margin-top:0">Rincian selisih</h3>
            <p class="help">Angka di Excel dibandingkan dengan nilai yang DIAKUI CLARA pada bulan ini
               (tabel alokasi) — sumber yang sama dengan Laporan PIC, bukan hitungan baru.</p>

            <?php
            $bagian = [
                ['nilai', 'Nominalnya beda', (int) $R['n_nilai'], $rp($R['rp_nilai']),
                 'Penyewa yang sama ketemu di kedua sisi, tapi angkanya tidak sama. Salah satu salah ketik.'],
                ['input', 'Ada di Excel, belum ada di CLARA', (int) $R['n_input'], $rp($R['rp_input']),
                 'Kemungkinan belum diinput, atau dokumennya belum ditandatangani client (income baru diakui setelah TTD).'],
                ['lebih', 'Ada di CLARA, tidak ada di Excel', (int) $R['n_lebih'], $rp($R['rp_lebih']),
                 'Perlu dicek: bisa jadi Excel-nya yang belum diperbarui, atau transaksinya dobel.'],
                ['pic', 'Tercatat atas nama PIC berbeda', (int) $R['n_pic'], '',
                 'Nilainya sama, tapi PIC-nya tidak sama — mengubah pencapaian dua orang sekaligus.'],
            ];
            foreach ($bagian as [$kunci, $judul, $n, $nilai, $ket]):
                $baris = $hasil[$kunci] ?? [];
                if (!$baris) continue;
            ?>
            <details class="rk-rinci" <?= $kunci === 'nilai' ? 'open' : '' ?>>
                <summary><?= h($judul) ?> — <?= $n ?> baris<?= $nilai !== '' ? ' (' . $nilai . ')' : '' ?></summary>
                <p class="help" style="margin:0 0 6px"><?= h($ket) ?></p>
                <table>
                    <thead><tr><th>Penyewa</th><th>Unit</th><th>PIC</th>
                        <th style="text-align:right">Excel</th><th style="text-align:right">CLARA</th>
                        <th style="text-align:right">Selisih</th><th>Letak</th></tr></thead>
                    <tbody>
                    <?php foreach ($baris as $b):
                        $e = $b['excel'] ?? ($kunci === 'input' ? $b : null);
                        $c = $b['clara'] ?? ($kunci === 'lebih' ? $b : null);
                        $sel = isset($b['selisih']) ? (float) $b['selisih']
                             : ($kunci === 'input' ? -(float) $b['nilai'] : (float) $b['nilai']);
                    ?>
                    <tr>
                        <td data-label="Penyewa"><?= h($e['klien'] ?? $c['klien'] ?? '-') ?>
                            <?php if (($c['brand'] ?? '') !== '' && ($c['brand'] ?? '') !== ($c['klien'] ?? '')): ?>
                            <span class="help"><?= h($c['brand']) ?></span><?php endif; ?></td>
                        <td data-label="Unit"><?= h($e['kode'] ?? $c['kode'] ?? '-') ?></td>
                        <td data-label="PIC"><?= h($e['pic'] ?? $c['pic'] ?? '-') ?>
                            <?php if ($kunci === 'pic'): ?><span class="help">→ <?= h($c['pic'] ?? '-') ?></span><?php endif; ?></td>
                        <td data-label="Excel" style="text-align:right"><?= $e ? $rp($e['nilai']) : '—' ?></td>
                        <td data-label="CLARA" style="text-align:right"><?= $c ? $rp($c['nilai']) : '—' ?></td>
                        <td data-label="Selisih" style="text-align:right"><?= $rp($sel) ?></td>
                        <td data-label="Letak"><span class="help"><?= h(($e['rujukan'] ?? '') . ($e && $c ? ' / ' : '') . ($c['rujukan'] ?? '')) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
            <?php endforeach; ?>

            <?php foreach ([['dobel_excel', 'Dugaan dobel di Excel'], ['dobel_clara', 'Dugaan dobel di CLARA']] as [$k, $judul]):
                if (empty($hasil[$k])) continue; ?>
            <details class="rk-rinci">
                <summary><?= h($judul) ?> — <?= count($hasil[$k]) ?> kelompok</summary>
                <p class="help" style="margin:0 0 6px">Penyewa, unit, nominal, dan bulan sama persis lebih dari satu kali. Perlu dicek, belum tentu salah.</p>
                <table>
                    <thead><tr><th>Penyewa</th><th>Unit</th><th>Nominal</th><th>Berulang</th><th style="text-align:right">Kelebihan bila dobel</th></tr></thead>
                    <tbody>
                    <?php foreach ($hasil[$k] as $d): ?>
                    <tr>
                        <td data-label="Penyewa"><?= h($d['baris'][0]['klien']) ?></td>
                        <td data-label="Unit"><?= h($d['baris'][0]['kode'] ?: '-') ?></td>
                        <td data-label="Nominal"><?= $rp($d['baris'][0]['nilai']) ?></td>
                        <td data-label="Berulang"><?= (int) $d['jumlah'] ?> ×
                            <span class="help"><?= h(implode(', ', array_column($d['baris'], 'rujukan'))) ?></span></td>
                        <td data-label="Kelebihan" style="text-align:right"><?= $rp($d['kelebihan']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
            <?php endforeach; ?>

            <?php if (!$R['n_nilai'] && !$R['n_input'] && !$R['n_lebih'] && !$R['n_pic']): ?>
            <p style="color:#047857;font-weight:700">✓ Seluruh baris cocok. Tidak ada yang perlu diperbaiki di bulan ini.</p>
            <?php endif; ?>
        </div>
        <?php elseif ($berkas && !$sheets): ?>
        <div class="panel">Tidak ada sheet yang dikenali sebagai kalender pameran atau monitoring.
            Pastikan berkasnya rekap Casual Leasing, bukan berkas lain.</div>
        <?php endif; ?>
        <?php
    });
}
