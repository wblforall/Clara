<?php
// ─── Dokumen konfirmasi per modul ────────────────────────────────────────────
// Exhibition → SKP (Surat Konfirmasi Pameran)
// Gudang     → SKS (Surat Konfirmasi Sewa Gudang)   — tabel harga sewa per unit
// Media      → FU  (Form Utilities Casual Leasing)  — daftar centang utilities
//
// Ketiganya memakai satu alur yang sama (draft → submit → approve manager +
// nomor & QR → TTD customer lewat tautan / unggah dokumen ber-TTD), hanya isi
// formulir & cetakannya yang berbeda. Isi teksnya (intro, peraturan, catatan
// kaki, daftar item) ditarik dari Template Dokumen sehingga bisa diubah user —
// lihat migrasi 044. Isi per dokumen disimpan di skp_documents.detail_json
// (migrasi 045) dan ikut terkunci ke snapshot saat approve.

/** Jenis dokumen untuk sebuah modul transaksi. */
function skp_doc_type(?string $module): string
{
    return match ($module) { 'gudang' => 'sks', 'media' => 'fu', default => 'skp' };
}

/** Modul asal sebuah jenis dokumen. */
function skp_doc_module(string $docType): string
{
    return match ($docType) { 'sks' => 'gudang', 'fu' => 'media', default => 'cl' };
}

/** Singkatan untuk nomor dokumen & judul halaman. */
function skp_doc_short(string $docType): string
{
    return match ($docType) { 'sks' => 'SKS', 'fu' => 'FU', default => 'SKP' };
}

/** Label panjang untuk badge & judul halaman. */
function skp_doc_label(string $docType): string
{
    return match ($docType) {
        'sks'   => 'SKS (Surat Konfirmasi Sewa Gudang)',
        'fu'    => 'Form Utilities (Media)',
        default => 'SKP (Surat Konfirmasi Pameran)',
    };
}

/** Judul baku di kepala cetakan (bisa ditimpa "Perihal" template). */
function skp_doc_title(string $docType): string
{
    return match ($docType) {
        'sks'   => 'Surat Konfirmasi Sewa Gudang',
        'fu'    => 'Form Utilities Casual Leasing',
        default => 'Surat Konfirmasi Pameran',
    };
}

/**
 * Judul yang tercetak di kepala dokumen.
 *
 * Gudang & Media memakai perihal dari templatenya (memang sudah berbunyi
 * "Surat Konfirmasi Sewa Gudang" / "Form Utilities"). SKP TIDAK boleh ikut
 * template Surat Penawaran — perihal di sana berbunyi "Surat Penawaran Sewa
 * Area Pameran", padahal dokumen ini surat konfirmasi, bukan penawaran.
 */
function skp_doc_heading(string $docType, array $tpl = []): string
{
    if ($docType === 'skp') return skp_doc_title($docType);   // Surat Konfirmasi Pameran
    return ((string) ($tpl['perihal'] ?? '')) ?: skp_doc_title($docType);
}

/**
 * Template dokumen milik sebuah modul (default properti). Query langsung supaya
 * halaman SKP tidak perlu memuat seluruh offers.php.
 */
function skp_template(PDO $pdo, int $pid, string $module): array
{
    $st = $pdo->prepare("SELECT * FROM offer_templates WHERE property_id=? AND module=? AND unit_type='' AND status='active' LIMIT 1");
    $st->execute([$pid, $module]);
    $t = $st->fetch() ?: [];
    $arr = fn(string $k) => json_decode((string) ($t[$k] ?? '[]'), true) ?: [];
    return [
        'name'    => (string) ($t['name'] ?? ''),
        'perihal' => (string) ($t['perihal'] ?? ''),
        'intro'   => (string) ($t['intro'] ?? ''),
        'terms'   => $arr('terms_json'),
        'notes'   => $arr('notes_json'),
        'extra'   => json_decode((string) ($t['extra_json'] ?? '{}'), true) ?: [],
    ];
}

/** Nama hari: Senin … Minggu. */
function skp_hari(?string $d): string
{
    $t = strtotime((string) $d);
    if (!$t) return '';
    return ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', "Jumat", 'Sabtu'][(int) date('w', $t)];
}

/** Tanggal gaya Indonesia: 1 Maret 2026. */
function skp_tgl(?string $d): string
{
    $t = strtotime((string) $d);
    if (!$t) return "";
    $bln = [1=>"Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"];
    return (int) date("j", $t) . " " . $bln[(int) date("n", $t)] . " " . date("Y", $t);
}

/** Ganti placeholder pada teks template dokumen. */
function skp_fill(string $text, array $v): string
{
    return strtr($text, [
        '{hari}'    => $v['hari'] ?? '',
        '{periode}' => $v['periode'] ?? '',
        '{deposit}' => $v['deposit'] ?? '',
        '{total}'   => $v['total'] ?? '',
        '{ppn}'     => $v['ppn'] ?? '',
        '{grand}'   => $v['grand'] ?? '',
    ]);
}

// ─── Isi awal formulir (dari template + data sumber) ─────────────────────────

/** Struktur detail kosong/berisi default utk dokumen baru. */
function skp_detail_defaults(array $tpl, string $docType, array $src, array $amt = []): array
{
    if ($docType === 'sks') {
        $mulai   = trim((string) ($src['start_date'] ?? ''));
        $selesai = trim((string) ($src['end_date'] ?? ''));
        $periode = $mulai !== '' ? skp_tgl($mulai) . ' s.d ' . skp_tgl($selesai) : '';
        $hari    = ($mulai !== '' && $selesai !== '')
            ? (string) ((int) ((strtotime($selesai) - strtotime($mulai)) / 86400) + 1) : '';
        $ket = $mulai === '' ? [] : array_map(
            fn($b) => skp_fill((string) $b, [
                'periode' => $periode, 'hari' => $hari,
                'deposit' => money((float) ($src['deposit_amount'] ?? 0)),
            ]),
            $tpl['extra']['keterangan_default'] ?? []
        );
        return [
            'nama_toko'     => '',
            'lokasi_tenant' => '',
            'jenis_usaha'   => (string) ($src['business_type'] ?? ''),
            'lokasi_gudang' => (string) ($src['floor'] ?: ($src['location_name'] ?? $src['master_code'] ?? '')),
            // Bullet keterangan mentah (masih ber-placeholder) supaya bisa disusun
            // ulang di layar begitu tanggalnya diisi.
            'ket_tpl'       => array_values($tpl['extra']['keterangan_default'] ?? []),
            'rows'          => [[
                'lokasi'      => (string) ($src['floor'] ?: ($src['location_name'] ?? $src['master_code'] ?? '')),
                'luas'        => ((float) ($src['area_sqm'] ?? $src['unit_area'] ?? 0)) ?: '',
                'harga_m2'    => '',
                'harga_bulan' => '',
                'total'       => ((float) ($amt['total'] ?? 0)) ?: '',
                'keterangan'  => implode("\n", $ket),
            ]],
        ];
    }

    if ($docType === 'fu') {
        // 'ket' hanya dipakai item bernama "Lainnya" — namanya sendiri belum
        // menyebut apa pun, jadi isinya ditulis sendiri oleh pengisi formulir.
        $mk = fn(array $names) => array_map(
            fn($n) => ['nama' => (string) $n, 'checked' => 0, 'ket' => '', 'lokasi' => '', 'biaya' => '', 'satuan' => ''],
            $names
        );
        return [
            'lokasi_kegiatan'   => (string) ($src['location_name'] ?? $src['master_code'] ?? ''),
            'tanggal_pemakaian' => trim((string) ($src['start_date'] ?? '')) !== ''
                ? skp_tgl((string) $src['start_date']) . ' – ' . skp_tgl((string) $src['end_date'])
                : '',
            'utilities'   => $mk($tpl['extra']['utilities']   ?? []),
            'media_promo' => $mk($tpl['extra']['media_promo'] ?? []),
            'parkir'      => $mk($tpl['extra']['parkir']      ?? []),
            // Bawaannya harga TAMPIL, seperti selama ini.
            'sembunyi_harga' => ['utilities' => 0, 'media_promo' => 0, 'parkir' => 0],
            'plat_nomor'  => '',
            'ppn'         => '',
            'grand'       => '',
            'terbilang'   => '',
        ];
    }

    return [];
}

/** Baca isi formulir modul dari POST. */
function skp_detail_from_post(string $docType): array
{
    if ($docType === 'sks') {
        $rows = [];
        foreach ((array) ($_POST['row'] ?? []) as $r) {
            $lokasi = trim((string) ($r['lokasi'] ?? ''));
            $luas   = trim((string) ($r['luas'] ?? ''));
            $luasNum = $luas === '' ? '' : (float) str_replace(',', '.', str_replace('.', '', $luas));
            if ($lokasi === '' && $luas === '') continue;   // baris kosong dibuang
            $rows[] = [
                'lokasi'      => $lokasi,
                'luas'        => $luasNum,
                // Disimpan sebagai angka murni — supaya saat dibuka lagi tetap
                // terbaca & cetakannya bisa diformat 'Rp 110.000' dengan benar.
                'harga_m2'    => parse_rupiah((string) ($r['harga_m2'] ?? '0')),
                'harga_bulan' => parse_rupiah((string) ($r['harga_bulan'] ?? '0')),
                'total'       => parse_rupiah((string) ($r['total'] ?? '0')),
                'keterangan'  => trim((string) ($r['keterangan'] ?? '')),
            ];
        }
        return [
            'ket_tpl'       => json_decode((string) post('d_ket_tpl', '[]'), true) ?: [],
            'nama_toko'     => trim((string) post('d_nama_toko', '')),
            'lokasi_tenant' => trim((string) post('d_lokasi_tenant', '')),
            'jenis_usaha'   => trim((string) post('d_jenis_usaha', '')),
            'lokasi_gudang' => trim((string) post('d_lokasi_gudang', '')),
            'rows'          => $rows,
        ];
    }

    if ($docType === 'fu') {
        $grup = function (string $key): array {
            $out = [];
            foreach ((array) ($_POST[$key] ?? []) as $i => $r) {
                $nama = trim((string) ($r['nama'] ?? ''));
                if ($nama === '') continue;
                $out[] = [
                    'nama'    => $nama,
                    'checked' => !empty($r['checked']) ? 1 : 0,
                    // Isian bebas pada baris "Lainnya".
                    'ket'     => trim((string) ($r['ket'] ?? '')),
                    'lokasi'  => trim((string) ($r['lokasi'] ?? '')),
                    // Angka murni supaya bisa dijumlahkan & dicetak "Rp. 400.000".
                    'biaya'   => parse_rupiah((string) ($r['biaya'] ?? '0')),
                    // Keterangan satuan bebas, mis. "/Titik/Hari".
                    'satuan'  => trim((string) ($r['satuan'] ?? '')),
                ];
            }
            return $out;
        };
        $sembunyi = (array) ($_POST['d_sembunyi'] ?? []);
        return [
            'lokasi_kegiatan'   => trim((string) post('d_lokasi_kegiatan', '')),
            'tanggal_pemakaian' => trim((string) post('d_tanggal_pemakaian', '')),
            'utilities'         => $grup('util'),
            'media_promo'       => $grup('promo'),
            'parkir'            => $grup('parkir'),
            // Sembunyikan kolom biaya per kelompok saat dicetak. Biayanya tetap
            // tersimpan — yang berubah hanya apa yang tercetak dan apa yang
            // ikut dijumlahkan ke Total Nilai Sewa.
            'sembunyi_harga'    => [
                'utilities'   => !empty($sembunyi['utilities'])   ? 1 : 0,
                'media_promo' => !empty($sembunyi['media_promo']) ? 1 : 0,
                'parkir'      => !empty($sembunyi['parkir'])      ? 1 : 0,
            ],
            'plat_nomor'        => trim((string) post('d_plat_nomor', '')),
            // Angka rincian boleh diketik sendiri — yang tersimpan dipakai apa
            // adanya saat dicetak.
            'ppn'               => parse_rupiah((string) post('d_ppn', '0')),
            'grand'             => parse_rupiah((string) post('d_grand', '0')),
            'terbilang'         => trim((string) post('d_terbilang', '')),
        ];
    }

    return [];
}

/**
 * Samakan daftar item Form Utilities dengan daftar di Template Dokumen.
 *
 * Isi dokumen disimpan sebagai salinan (detail_json) supaya dokumen yang sudah
 * terbit tidak ikut berubah ketika templatenya diubah. Untuk dokumen yang MASIH
 * draft salinan itu justru menyesatkan: daftar itemnya membeku di keadaan saat
 * dokumen dibuat.
 *
 * Maka selama dokumennya masih bisa diedit, daftarnya disamakan DUA ARAH:
 *
 *   - item template yang belum ada di dokumen → ditambahkan di bawah daftarnya;
 *   - item yang sudah DIHAPUS dari template → ikut hilang dari dokumen.
 *
 * Yang kedua dijaga ketat: item hanya boleh hilang bila belum tersentuh sama
 * sekali — belum dicentang, belum berisi biaya, keterangan, atau lokasi. Item
 * yang sudah dipakai tetap tinggal walaupun templatenya berubah, karena itu
 * keputusan orang yang mengisi dokumennya, bukan keputusan template.
 *
 * Penjaga kedua: bila daftar template kelompok itu KOSONG (templatenya memang
 * belum diatur), tidak ada yang dibuang sama sekali. Tanpa itu, template yang
 * belum diisi akan mengosongkan daftar item seluruh dokumen draft.
 */
function skp_fu_sinkron_tpl(array $det, array $tpl): array
{
    foreach (['utilities', 'media_promo', 'parkir'] as $k) {
        $tplNama = [];
        foreach ((array) ($tpl['extra'][$k] ?? []) as $n) {
            $n = trim((string) $n);
            if ($n !== '') $tplNama[mb_strtolower($n)] = $n;
        }
        $bolehBuang = $tplNama !== [];
        $hasil = $ada = [];
        foreach ((array) ($det[$k] ?? []) as $it) {
            $nama = trim((string) ($it['nama'] ?? ''));
            if ($nama === '') continue;
            $kunci = mb_strtolower($nama);
            if (in_array($kunci, $ada, true)) continue;          // kembar di dokumen lama
            $terpakai = !empty($it['checked'])
                || (float) ($it['biaya'] ?? 0) > 0
                || trim((string) ($it['ket'] ?? '')) !== ''
                || trim((string) ($it['lokasi'] ?? '')) !== '';
            if ($bolehBuang && !$terpakai && !isset($tplNama[$kunci])) continue;
            $hasil[] = $it;
            $ada[]   = $kunci;
        }
        foreach ($tplNama as $kunci => $nama) {
            if (in_array($kunci, $ada, true)) continue;
            $hasil[] = ['nama' => $nama, 'checked' => 0, 'ket' => '', 'lokasi' => '', 'biaya' => '', 'satuan' => ''];
            $ada[]   = $kunci;
        }
        $det[$k] = $hasil;
    }
    return $det;
}

// ─── Blok formulir (dipanggil dari skp_form) ─────────────────────────────────

function skp_detail_form(string $docType, array $d, bool $editable, array $srcHarga = []): void
{
    $dis = $editable ? '' : 'disabled';
    // Blok harga disisipkan setelah data client/kegiatan modulnya, sehingga
    // semua angka uang berkumpul di bagian bawah formulir.
    $harga = fn(string $modul) => $srcHarga ? skp_blok_harga($modul, $srcHarga, $editable) : null;
    if ($docType === 'sks') {
        $rows = $d['rows'] ?: [['lokasi' => '', 'luas' => '', 'harga_m2' => '', 'harga_bulan' => '', 'total' => '', 'keterangan' => '']];
        ?>
        <input type="hidden" name="d_ket_tpl" value="<?= h(json_encode($d['ket_tpl'] ?? [], JSON_UNESCAPED_UNICODE)) ?>">
        <h3>Identitas Tambahan (Gudang)</h3>
        <div class="form-grid">
            <div><label>Nama Toko</label><input name="d_nama_toko" value="<?= h($d['nama_toko'] ?? '') ?>" <?= $dis ?>></div>
            <div><label>Lokasi Tenant (Lantai/Unit)</label><input name="d_lokasi_tenant" value="<?= h($d['lokasi_tenant'] ?? '') ?>" placeholder="mis. P5 Pentacity" <?= $dis ?>></div>
            <div><label>Jenis Usaha</label><input name="d_jenis_usaha" value="<?= h($d['jenis_usaha'] ?? '') ?>" <?= $dis ?>></div>
            <div><label>Lokasi Gudang Yang Akan Disewa</label><input name="d_lokasi_gudang" value="<?= h($d['lokasi_gudang'] ?? '') ?>" placeholder="mis. P5 B13" <?= $dis ?>></div>
        </div>

        <?php $harga('gudang'); ?>

        <h3>Rincian Harga Sewa Gudang <span style="font-weight:400;font-size:12px;color:var(--muted)">(bisa lebih dari satu unit)</span></h3>
        <div style="overflow-x:auto">
        <table class="data" id="sks-rows" style="width:100%;min-width:720px">
            <thead><tr>
                <th style="width:16%">Lokasi</th><th style="width:9%">Luasan (m²)</th>
                <th style="width:15%">Harga Sewa /m²/Bulan</th><th style="width:15%">Harga Sewa /Bulan</th>
                <th style="width:15%">Total Harga Sewa</th><th>Keterangan <span class="muted" style="font-weight:400">(1 baris = 1 bullet)</span></th>
                <?php if ($editable): ?><th style="width:34px"></th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $r): ?>
                <tr>
                    <td><input name="row[<?= $i ?>][lokasi]" value="<?= h($r['lokasi'] ?? '') ?>" style="width:100%" <?= $dis ?>></td>
                    <td><input name="row[<?= $i ?>][luas]" value="<?= h(skp_num($r['luas'] ?? '')) ?>" inputmode="decimal" style="width:100%;text-align:right" <?= $dis ?>></td>
                    <td><?php skp_input_rp("row[{$i}][harga_m2]", $r['harga_m2'] ?? '', $dis, false, true); ?></td>
                    <td><?php skp_input_rp("row[{$i}][harga_bulan]", $r['harga_bulan'] ?? '', $dis, false, true); ?></td>
                    <td><?php skp_input_rp("row[{$i}][total]", $r['total'] ?? '', $dis, false, true); ?></td>
                    <td><textarea name="row[<?= $i ?>][keterangan]" class="ket-baris" rows="4" style="width:100%" <?= $dis ?>><?= h($r['keterangan'] ?? '') ?></textarea></td>
                    <?php if ($editable): ?><td><button type="button" class="btn light sks-del" title="Hapus baris" style="padding:2px 8px">✕</button></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($editable): ?>
        <p style="margin:8px 0 0"><button type="button" class="btn light" id="sks-add">+ Tambah Baris Unit</button></p>
        <script>
        (function () {
            var tb = document.querySelector('#sks-rows tbody');
            document.getElementById('sks-add').addEventListener('click', function () {
                var i = tb.rows.length;
                var tr = tb.rows[0].cloneNode(true);
                tr.querySelectorAll('input, textarea').forEach(function (el) {
                    el.name = el.name.replace(/row\[\d+\]/, 'row[' + i + ']');
                    el.value = '';
                });
                tb.appendChild(tr);
            });
            tb.addEventListener('click', function (e) {
                if (!e.target.classList.contains('sks-del')) return;
                if (tb.rows.length === 1) { e.target.closest('tr').querySelectorAll('input, textarea').forEach(function (el) { el.value = ''; }); return; }
                e.target.closest('tr').remove();
            });
        })();
        </script>
        <?php endif;
        return;
    }

    if ($docType === 'fu') {
        ?>
        <h3>Data Permohonan (Media)</h3>
        <div class="form-grid">
            <?php /* Keduanya terisi sendiri dari Titik Media & tanggal di atas —
                     tetap bisa diketik ulang bila penulisannya mau lain. */ ?>
            <?php /* Dokumen lama bisa menyimpan penggal yang berulang ("Atrium - GF -
                     Atrium - GF"). Dilebur di sini juga, bukan hanya saat dicetak,
                     supaya yang terlihat di formulir sama dengan yang tercetak —
                     dan tersimpan rapi begitu dokumennya disimpan lagi. */ ?>
            <div><label>Lokasi Kegiatan</label><input name="d_lokasi_kegiatan" value="<?= h(clara_gabung_lokasi($d['lokasi_kegiatan'] ?? '')) ?>" <?= $dis ?>><div class="help">Otomatis mengikuti Titik Media.</div></div>
            <div><label>Tanggal Pemakaian</label><input name="d_tanggal_pemakaian" value="<?= h($d['tanggal_pemakaian'] ?? '') ?>" <?= $dis ?>><div class="help">Otomatis mengikuti tanggal sewa.</div></div>
        </div>

        <?php $harga('media'); ?>

        <?php
        /* Satu saklar per kelompok: "Sembunyikan harga". Dicentang → kolom
           biaya tidak dicetak di surat, DAN biayanya tidak ikut menambah Total
           Nilai Sewa. Harga yang sudah diketik tetap tersimpan, jadi saklarnya
           bisa dilepas lagi kapan saja tanpa mengetik ulang. */
        $sbn = (array) ($d['sembunyi_harga'] ?? []);
        $saklar = function (string $key) use ($sbn, $dis) {
            $on = !empty($sbn[$key]);
            echo '<label class="fu-sembunyi" style="font-weight:400;font-size:12px;color:var(--muted);display:inline-flex;align-items:center;gap:6px;margin-left:10px">'
               . '<input type="checkbox" id="fu-sbn-' . h($key) . '" name="d_sembunyi[' . h($key) . ']" value="1" data-grup="' . h($key) . '"'
               . ($on ? ' checked' : '') . ' ' . $dis . ' style="width:15px;height:15px">'
               . 'Sembunyikan harga di surat</label>';
        };
        ?>
        <h3>Utilities <span style="font-weight:400;font-size:12px;color:var(--muted)">(centang yang dipakai, isi biayanya — biaya yang dicentang ikut menambah Total Nilai Sewa)</span><?php $saklar('utilities'); ?></h3>
        <?php skp_fu_group('util', $d['utilities'] ?? [], false, $editable, 'utilities'); ?>

        <h3>Media Promo<?php $saklar('media_promo'); ?></h3>
        <?php skp_fu_group('promo', $d['media_promo'] ?? [], true, $editable, 'media_promo'); ?>

        <h3>Parkir Kendaraan (Test Drive)<?php $saklar('parkir'); ?></h3>
        <?php skp_fu_group('parkir', $d['parkir'] ?? [], false, $editable, 'parkir'); ?>
        <div class="form-grid" style="margin-top:8px">
            <div class="wide"><label>Plat Nomor Kendaraan <span class="muted" style="font-weight:400">(STNK dilampirkan)</span></label><input name="d_plat_nomor" value="<?= h($d['plat_nomor'] ?? '') ?>" <?= $dis ?>></div>
        </div>
        <?php
        return;
    }
}

/**
 * Satu kelompok daftar centang pada Form Utilities.
 *
 * Bentuknya tabel — kolomnya lurus (centang / item / lokasi / biaya) supaya
 * daftar sepanjang ini terbaca sekali lihat; versi lama memakai baris flex
 * sehingga nama item ikut membungkus dan kotaknya tidak sejajar.
 */
function skp_fu_group(string $key, array $items, bool $withLokasi, bool $editable, string $grup = ''): void
{
    $dis = $editable ? '' : 'disabled';
    if (!$items) { echo '<p class="muted" style="margin:0">Daftar item belum diatur — isi lewat menu <strong>Template Penawaran → Media</strong>.</p>'; return; }
    ?>
    <div style="overflow-x:auto">
    <table class="data fu-tabel" style="width:100%">
        <thead><tr>
            <th style="width:54px;text-align:center">Pakai</th>
            <th style="<?= $withLokasi ? 'width:26%' : '' ?>">Item</th>
            <?php if ($withLokasi): ?><th>Lokasi Pemasangan</th><?php endif; ?>
            <th style="width:150px;text-align:right">Biaya</th>
            <th style="width:120px">Satuan</th>
        </tr></thead>
        <tbody>
        <?php foreach ($items as $i => $it):
            $on = !empty($it['checked']);
            // "Lainnya" tidak menyebut apa pun — barisnya diberi kotak isian
            // supaya yang dicentang itu jelas barangnya, bukan cuma biayanya.
            $bebas = strcasecmp(trim((string) $it['nama']), 'Lainnya') === 0; ?>
            <tr class="fu-row" data-grup="<?= h($grup) ?>" style="background:<?= $on ? '#f0fdf4' : 'transparent' ?>">
                <td style="text-align:center">
                    <input type="hidden" name="<?= $key ?>[<?= $i ?>][nama]" value="<?= h($it['nama']) ?>">
                    <input type="checkbox" class="fu-cek" name="<?= $key ?>[<?= $i ?>][checked]" value="1" <?= $on ? 'checked' : '' ?> <?= $dis ?>>
                </td>
                <td style="font-weight:600"><?= h($it['nama']) ?>
                    <?php if ($bebas): ?>
                        <input name="<?= $key ?>[<?= $i ?>][ket]" value="<?= h($it['ket'] ?? '') ?>" placeholder="tulis sendiri itemnya"
                               style="width:100%;margin-top:4px;font-weight:400;font-size:11.5px" <?= $dis ?>>
                    <?php else: ?>
                        <input type="hidden" name="<?= $key ?>[<?= $i ?>][ket]" value="<?= h($it['ket'] ?? '') ?>">
                    <?php endif; ?>
                </td>
                <?php if ($withLokasi): ?>
                <td><input name="<?= $key ?>[<?= $i ?>][lokasi]" value="<?= h($it['lokasi'] ?? '') ?>" placeholder="mis. Lobby Utama Lt. 1" style="width:100%" <?= $dis ?>></td>
                <?php endif; ?>
                <td><?php skp_input_rp("{$key}[{$i}][biaya]", $it['biaya'] ?? '', $dis, false, true, 'fu-biaya'); ?></td>
                <td><input name="<?= $key ?>[<?= $i ?>][satuan]" value="<?= h($it['satuan'] ?? '') ?>" placeholder="/Titik/Hari" style="width:100%;font-size:11.5px" <?= $dis ?>></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php
}

// ─── Blok cetakan (dipanggil dari skp_print_body) ────────────────────────────

/**
 * Badan dokumen untuk SKS / Form Utilities. Mengembalikan HTML; blok tanda
 * tangan (QR manager + TTD customer) tetap dipakai bersama dari skp_print_body.
 */
function skp_detail_print(string $docType, array $d, array $tpl): string
{
    $h  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $rp = fn($v) => is_numeric($v) ? 'Rp ' . number_format((float) $v, 0, ',', '.') : (string) $v;
    $det = $d['detail'] ?? [];
    $amt = $d['amounts'] ?? [];
    $o   = '';

    // Identitas penyewa — susunannya mengikuti formulir kertas masing-masing.
    if ($docType === 'sks') {
        // Susunan mengikuti berkas kertas "Surat Konfirmasi Sewa Gudang".
        $o .= '<table class="idn">';
        foreach ([
            'Nama Perusahaan/Pribadi'        => $d['company_name'] ?? '-',
            'Nama Penanggung Jawab'          => $d['cp_name'] ?? '-',
            'Alamat'                         => $d['address'] ?? '-',
            'No. Telepon/Fax'                => $d['phone'] ?? '-',
            'Nama Toko'                      => $det['nama_toko'] ?? '-',
            'Lokasi (Unit/Outlet)'           => $det['lokasi_tenant'] ?? '-',
            'Jenis Usaha'                    => $det['jenis_usaha'] ?? ($d['business_type'] ?? '-'),
            'Lokasi Gudang Yang Akan Disewa' => $det['lokasi_gudang'] ?? '-',
        ] as $l => $v) {
            $o .= '<tr><td class="l">' . $h($l) . '</td><td class="c">:</td><td>' . $h($v ?: '-') . '</td></tr>';
        }
        $o .= '</table>';

        if (!empty($tpl['intro'])) {
            $o .= '<p class="just">' . $h($tpl['intro']) . '</p>';
        }

        // Tabel harga — kolomnya tetap milik sistem (bisa banyak unit), hanya
        // bentuknya yang disamakan dengan kertas: kepala biru tua, isi biru muda.
        //
        // Kolom "Harga Sewa /Bulan" disembunyikan bila SELURUH barisnya berisi
        // angka yang sama dengan "Total Harga Sewa" — yang selalu terjadi pada
        // sewa gudang 1 bulan. Dua kolom berjudul beda berisi angka sama persis
        // membuat penyewa mengira ada dua komponen biaya, lalu menagih
        // penjelasan yang tidak ada. Kolomnya hanya disembunyikan; angkanya
        // tidak diubah sama sekali.
        $barisGdg = array_values((array) ($det['rows'] ?? []));
        $adaKolomBulan = false;
        foreach ($barisGdg as $r) {
            $hb = (float) ($r['harga_bulan'] ?? 0);
            if ($hb > 0 && round($hb) !== round((float) ($r['total'] ?? 0))) { $adaKolomBulan = true; break; }
        }
        $o .= '<table class="harga"><tr>'
            . '<th style="width:28mm">Lokasi</th><th style="width:13mm">Luasan (m2)</th>'
            . '<th style="width:23mm">Harga Sewa /m²/Bulan</th>'
            . ($adaKolomBulan ? '<th style="width:25mm">Harga Sewa /Bulan</th>' : '')
            . '<th style="width:25mm">Total Harga Sewa</th><th>Keterangan</th></tr>';
        foreach ($barisGdg as $r) {
            $ket = '';
            foreach (preg_split('/\R/', (string) ($r['keterangan'] ?? '')) as $b) {
                if (trim($b) !== '') $ket .= '● ' . $h(trim($b)) . '<br>';
            }
            $o .= '<tr>'
                . '<td class="n">' . $h($r['lokasi'] ?? '') . '</td>'
                . '<td class="n">' . $h(skp_num($r['luas'] ?? '')) . (($r['luas'] ?? '') !== '' ? ' m' : '') . '</td>'
                // Tarif dan harga per bulan boleh dikosongkan — kosong dicetak
                // kosong, bukan "Rp 0" yang terbaca sebagai gratis.
                . '<td class="n">' . ((float) ($r['harga_m2'] ?? 0) > 0 ? $h($rp($r['harga_m2'])) : '&nbsp;') . '</td>'
                . ($adaKolomBulan
                    ? '<td class="n">' . ((float) ($r['harga_bulan'] ?? 0) > 0 ? $h($rp($r['harga_bulan'])) : '&nbsp;') . '</td>'
                    : '')
                . '<td class="n">' . $h($rp($r['total'] ?? '')) . '</td>'
                . '<td class="ket">' . ($ket ?: '&nbsp;') . '</td></tr>';
        }
        $o .= '</table>';

        // Catatan PPN (catatan kaki pertama) berdiri tepat di bawah tabel.
        $notes = $tpl['notes'] ?? [];
        if (!empty($notes[0])) {
            $o .= '<div class="kaki">' . $h($notes[0]) . '</div>';
        }

        if (!empty($tpl['terms'])) {
            $o .= '<p style="margin-top:16px"><strong>Peraturan Sewa Gudang :</strong></p><ol class="aturan">';
            foreach ($tpl['terms'] as $t) {
                $t = skp_fill((string) $t, ['deposit' => $rp($d['deposit_amount'] ?? ($amt['deposit'] ?? 0))]);
                $o .= '<li>' . $h($t) . '</li>';
            }
            $o .= '</ol>';
        }

        // Sisa catatan kaki (mis. nomor rekening) — tebal, seperti di kertas.
        foreach (array_slice($notes, 1) as $n) {
            $o .= '<p class="bayar">' . $h($n) . '</p>';
        }
        return $o;
    }

    // ── Form Utilities (media) ───────────────────────────────────────────────
    {
        $rp = fn($v) => is_numeric($v) ? 'Rp. ' . number_format((float) $v, 0, ',', '.') : (string) $v;
        $o .= '<div class="sec">Data Permohonan</div><table class="kv tebal">';
        $tglDoc = (string) ($d['doc_date'] ?? date('Y-m-d'));
        foreach ([
            'Hari / Tanggal Permohonan' => skp_hari($tglDoc) . ', ' . skp_tgl($tglDoc),
            'Nama Perusahaan / Tenant' => $d['company_name'] ?? '-',
            'Nama Penanggung Jawab'    => $d['cp_name'] ?? '-',
            'No. KTP Penanggung Jawab' => $d['ktp_pj'] ?? '-',
            'No. NPWP'                 => $d['npwp'] ?? '-',
            'No. Telepon / Handphone'  => $d['phone'] ?? '-',
            // Penggal yang berulang dilebur: Jenis Media dan Lokasi di Master
            // Media bisa saling memuat, dan rangkaian lugasnya mencetak
            // "Atrium - GF - Atrium - GF". Berlaku juga untuk dokumen lama,
            // karena peleburannya terjadi saat dicetak.
            'Lokasi Kegiatan'          => clara_gabung_lokasi($det['lokasi_kegiatan'] ?? ''),
            'Tanggal Pemakaian'        => $det['tanggal_pemakaian'] ?? '-',
        ] as $l => $v) {
            $o .= '<tr><td class="l">' . $h($l) . '</td><td class="c">:</td><td class="v">' . $h($v ?: '-') . '</td></tr>';
        }
        $o .= '</table>';

        // Daftar centang. Kolom biaya bisa disembunyikan per kelompok: ada
        // penyewa yang cukup diberi tahu fasilitas APA yang dipakai, sedangkan
        // tarif tiap fasilitas bukan urusannya — tarifnya tetap tersimpan di
        // dokumen, hanya tidak dicetak.
        $sembunyi = (array) ($det['sembunyi_harga'] ?? []);
        $grup = function (string $judul, array $items, bool $withLokasi, bool $tanpaHarga) use ($h, $rp): string {
            if (!$items) return '';
            $x = '<div class="sec">' . $h($judul) . '</div><table class="pay">';
            foreach ($items as $it) {
                // ■/□ (bukan ☑/☐) — font PDF DejaVu tidak punya glyph ballot-box.
                $tanda = !empty($it['checked'])
                    ? '<span style="color:#0D9488">■</span>'
                    : '<span style="color:#9ca3af">□</span>';
                // Baris "Lainnya" dicetak beserta isian bebasnya, selain lokasinya.
                $ket = trim((string) ($it['ket'] ?? '')) !== ''
                    ? ' <span class="muted">— ' . $h($it['ket']) . '</span>' : '';
                if ($withLokasi && trim((string) ($it['lokasi'] ?? '')) !== '') {
                    $ket .= ' <span class="muted">— Lokasi: ' . $h($it['lokasi']) . '</span>';
                }
                $b   = $it['biaya'] ?? '';
                $sat = trim((string) ($it['satuan'] ?? ''));
                $x .= '<tr><td class="lbl"' . ($tanpaHarga ? ' colspan="2" style="width:auto"' : '') . '>'
                    . $tanda . ' ' . $h($it['nama']) . $ket . '</td>'
                    . ($tanpaHarga ? '' : '<td class="amt">'
                        . ((float) $b > 0 ? $h($rp($b) . ($sat !== '' ? ' ' . $sat : '')) : '<span class="muted">—</span>')
                        . '</td>')
                    . '</tr>';
            }
            return $x . '</table>';
        };
        $o .= $grup('Utilities', $det['utilities'] ?? [], false, !empty($sembunyi['utilities']));
        $o .= $grup('Media Promo', $det['media_promo'] ?? [], true, !empty($sembunyi['media_promo']));
        $o .= $grup('Parkir Kendaraan (Test Drive)', $det['parkir'] ?? [], false, !empty($sembunyi['parkir']));
        if (trim((string) ($det['plat_nomor'] ?? '')) !== '') {
            $o .= '<p style="margin:4px 0;font-size:11px">Plat Nomor Kendaraan (STNK dilampirkan): <strong>' . $h($det['plat_nomor']) . '</strong></p>';
        }

        // Rincian biaya sebagai TABEL — satu baris satu angka, jumlahnya bisa
        // ditelusuri dari atas ke bawah. Sebelumnya berupa kalimat
        // "100.000.000 + 11.000.000 = Rp. 111.000.000,-" lalu baris "Total Yang
        // Harus Dibayarkan" yang mengulang angka yang sama ditambah jaminan —
        // dua angka besar berdampingan yang artinya sulit dibedakan.
        //
        // Angkanya sama persis dengan sebelumnya; hanya susunannya yang berubah.
        $nf    = fn($v) => number_format((float) $v, 0, ',', '.');
        $tot   = (float) ($amt['total'] ?? 0);
        $ppn   = (float) ($det['ppn'] ?? 0) > 0 ? (float) $det['ppn'] : (float) ($amt['ppn'] ?? 0);
        $grand = (float) ($det['grand'] ?? 0) > 0 ? (float) $det['grand'] : $tot + $ppn;
        $dep   = (float) ($amt['deposit'] ?? 0);
        $depOk = !empty($amt['deposit_paid']);
        // Jaminan yang SUDAH disetor tetap tercetak sebagai keterangan, tapi
        // tidak ditagih ulang — sama seperti di SKP.
        $bayar = $grand + ($dep > 0 && !$depOk ? $dep : 0.0);
        $tarif = (float) ($amt['rate_m_day'] ?? 0);
        // Nama baris tarif mengikuti dasar harga yang dipakai kontraknya, supaya
        // "Biaya / Hari" tidak tercetak di dokumen yang dihitung per bulan.
        $lblTarif = match ((string) ($d['pricing_type'] ?? '')) {
            'daily_area'   => 'Biaya / m² / Hari',
            'daily_slot'   => 'Biaya / Slot / Hari',
            'monthly'      => 'Biaya / Bulan',
            'monthly_area' => 'Biaya / m² / Bulan',
            'fixed'        => 'Nilai Kontrak',
            default        => 'Biaya / Hari',
        };
        $brs = fn(string $l, float $v, string $gaya = '') => '<tr><td class="lbl"' . $gaya . '>' . $l
            . '</td><td class="amt"' . $gaya . '>Rp. ' . $nf($v) . '</td></tr>';
        $o .= '<div style="page-break-inside:avoid">'
            . '<div class="sec">Rincian Biaya</div><table class="pay">';
        if ($tarif > 0) $o .= $brs($h($lblTarif), $tarif);
        $o .= $brs('Total Biaya Sewa', $tot);
        if ($ppn > 0) $o .= $brs('PPN 12%', $ppn);
        if ($dep > 0) {
            $o .= $brs('Jaminan Area (Security Deposit)' . ($depOk ? ' <span class="muted">(Sudah Dibayarkan)</span>' : ''),
                       $dep, $depOk ? ' style="color:#166534"' : '');
        }
        $o .= '<tr class="grand"><td class="lbl">Grand Total</td><td class="amt">Rp. ' . $nf($bayar) . '</td></tr>'
            . '</table>';
        if (trim((string) ($det['terbilang'] ?? '')) !== '') {
            $o .= '<p style="font-size:10.5px;line-height:1.6;margin:5px 0 7px;font-style:italic;font-weight:bold">(terbilang : ' . $h($det['terbilang']) . ')</p>';
        }
        // Catatan kaki: butir pertama (*PPN) kecil, tebal, miring — keterangan
        // dasar hukum, bukan bagian dari angka. Sisanya — nomor rekening
        // pembayaran — tebal seukuran badan surat, mengikuti formulir aslinya.
        foreach (($tpl['notes'] ?? []) as $i => $n) {
            $o .= $i === 0
                ? '<p style="font-size:9px;line-height:1.5;margin:0 0 8px;font-style:italic;font-weight:bold">' . $h($n) . '</p>'
                : '<p style="font-size:10.5px;line-height:1.6;margin:0 0 6px;font-weight:bold">' . $h($n) . '</p>';
        }
        $o .= '</div>';

        // Blok "CATATAN" sengaja tidak dicetak di sini — di formulir aslinya
        // letaknya di bawah tanda tangan (lihat skp_print_body.php).
    }

    return $o;
}

// ─── Dokumen berdiri sendiri (Gudang & Media) ────────────────────────────────
// Kedua modul ini tidak lewat Surat Penawaran: dokumennya dibuat langsung,
// datanya (client, unit, periode, nilai) disimpan di dokumen itu sendiri
// (migrasi 046), dan transaksinya terbit otomatis saat manager menyetujui —
// sama seperti SKP Exhibition yang lahir dari penawaran DEAL.

/**
 * Data sewa dokumen berdiri sendiri, dibentuk menyerupai _skp_source() supaya
 * sisa alur (hitungan, snapshot, pembuatan transaksi) tidak perlu tahu bedanya.
 */
function skp_standalone_src(PDO $pdo, int $pid, string $module, ?array $skp): array
{
    $src = [
        'id' => null, 'property_id' => $pid, 'module' => $module,
        'client_id'   => (int) ($skp['client_id'] ?? 0),
        'contact_id'  => (int) ($skp['contact_id'] ?? 0),
        'master_code' => (string) ($skp['master_code'] ?? ''),
        'start_date'  => (string) ($skp['start_date'] ?? ''),
        'end_date'    => (string) ($skp['end_date'] ?? ''),
        'unit_rate'   => (float) ($skp['unit_rate'] ?? 0),
        'final_amount'     => (float) ($skp['total_amount'] ?? 0),
        'total_calculated' => (float) ($skp['total_amount'] ?? 0),
        'deposit_amount'   => (float) ($skp['deposit_amount'] ?? 0),
        'pic_name'    => (string) ($skp['pic_name'] ?? ''),
        'quantity' => 1, 'slots' => 1, 'area_sqm' => 0.0,
        'pricing_type' => $module === 'gudang' ? 'monthly' : 'daily',
        // Pengakuan & Recurring diatur di dokumennya sendiri (migrasi 099) —
        // Gudang & Media tidak lewat Surat Penawaran, jadi tidak ada tempat lain
        // yang bisa menentukannya. Nilainya diteruskan apa adanya ke transaksi
        // yang terbit saat dokumen disetujui.
        'contract_months' => null,
        'billing_method'    => (string) ($skp['billing_method'] ?? ''),
        'cycle_recognition' => (string) ($skp['cycle_recognition'] ?? ''),
        'recurring_flag'    => (int) ($skp['recurring_flag'] ?? 0),
        'recognition_json'  => (string) ($skp['recognition_json'] ?? ''),
        'renewal_status' => null, 'content_note' => null,
        'offer_no' => null, 'doc_type' => skp_doc_type($module),
        'company_name' => '', 'brand_name' => '', 'npwp' => '', 'client_ktp' => '',
        'siup' => '', 'address' => '', 'business_type' => '',
        'cp_name' => '', 'cp_phone' => '',
        'location_name' => '', 'floor' => '', 'unit_area' => 0.0,
    ];

    // Dokumen BARU: Sales/PIC diisi dengan PIC milik yang sedang membuatnya.
    // Dialah yang namanya tercetak di kolom "Dibuat Oleh", jadi membiarkannya
    // kosong hanya menghasilkan surat tanpa nama pembuat — dan dokumen Gudang/
    // Media lama memang banyak yang tersimpan dengan pic_name kosong.
    // Tetap bisa diganti lewat kotak Sales/PIC di formulir.
    if (!$skp && $src['pic_name'] === '' && ($uid = (int) ($_SESSION['user']['id'] ?? 0))) {
        $st = $pdo->prepare("SELECT name FROM master_pic WHERE user_id = ? AND property_id = ? AND status = 'active' LIMIT 1");
        $st->execute([$uid, $pid]);
        $src['pic_name'] = (string) ($st->fetchColumn() ?: '');
    }

    if ($src['client_id']) {
        $st = $pdo->prepare('SELECT company_name, brand_name, npwp, ktp AS client_ktp, siup, address, business_type FROM master_clients WHERE id=? LIMIT 1');
        $st->execute([$src['client_id']]);
        if ($c = $st->fetch()) $src = array_merge($src, $c);
    }
    if ($src['contact_id']) {
        $st = $pdo->prepare('SELECT name, phone FROM master_client_contacts WHERE id=? LIMIT 1');
        $st->execute([$src['contact_id']]);
        if ($k = $st->fetch()) { $src['cp_name'] = $k['name']; $src['cp_phone'] = $k['phone']; }
    }
    if ($src['master_code'] !== '') {
        if ($module === 'gudang') {
            $st = $pdo->prepare('SELECT name AS location_name, location AS floor, area_sqm, ROUND(monthly_rate * area_sqm) AS monthly_rate FROM master_gudang WHERE code=? AND property_id=? LIMIT 1');
            $st->execute([$src['master_code'], $pid]);
            if ($u = $st->fetch()) {
                $src['location_name'] = $u['location_name'];
                $src['floor']     = $u['floor'];
                $src['unit_area'] = (float) $u['area_sqm'];
                $src['area_sqm']  = (float) $u['area_sqm'];
                if (!$src['unit_rate']) $src['unit_rate'] = (float) $u['monthly_rate'];
            }
        } elseif ($module === 'cl') {
            // SKP Pameran biasanya punya sumber (Penawaran/Transaksi), jadi jalur
            // ini hanya terpakai saat sumbernya hilang dan dokumennya dibaca dari
            // dirinya sendiri — lihat skp_form().
            $st = $pdo->prepare('SELECT location_name, floor, area_sqm, rate FROM master_cl_units WHERE code=? AND property_id=? LIMIT 1');
            $st->execute([$src['master_code'], $pid]);
            if ($u = $st->fetch()) {
                $src['location_name'] = $u['location_name'];
                $src['floor']     = $u['floor'];
                $src['unit_area'] = (float) $u['area_sqm'];
                $src['area_sqm']  = (float) $u['area_sqm'];
                if (!$src['unit_rate']) $src['unit_rate'] = (float) $u['rate'];
            }
        } else {
            // Labelnya dirangkai di PHP, bukan lewat CONCAT_WS: Jenis Media dan
            // Lokasi bisa saling memuat, dan rangkaian lugasnya mencetak
            // "Atrium - GF - Atrium - GF" di Lokasi Kegiatan.
            $st = $pdo->prepare("SELECT media_type, location, point, location AS floor, rate, pricing_type, slots FROM master_media WHERE code=? AND property_id=? LIMIT 1");
            $st->execute([$src['master_code'], $pid]);
            if ($u = $st->fetch()) {
                $src['location_name'] = clara_gabung_lokasi($u['media_type'], $u['location'], $u['point']);
                $src['floor']  = $u['floor'];
                $src['slots']  = (float) ($u['slots'] ?: 1);
                $src['pricing_type'] = $u['pricing_type'] ?: 'daily';
                if (!$src['unit_rate']) $src['unit_rate'] = (float) $u['rate'];
            }
        }
    }
    // Jumlah bulan kontrak (dipakai saat transaksi terbit) — dari rentang tanggal.
    if ($src['start_date'] && $src['end_date']) {
        $a = new DateTimeImmutable($src['start_date']);
        $b = new DateTimeImmutable($src['end_date']);
        $src['contract_months'] = max(1, (int) round(($a->diff($b)->days + 1) / 30));
    }
    return $src;
}

/** Angka desimal gaya Indonesia: 6 → "6", 12.5 → "12,5". */
function skp_num($v): string
{
    // Satu sumber aturan angka rapi untuk seluruh CLARA — lihat angka() di
    // app/helpers.php. Dulu rumus trim-nya disalin di beberapa berkas, dan
    // setiap salinan yang tertinggal mencetak "4,00" lagi.
    return angka($v);
}

/** Kotak isian rupiah: ada label "Rp." di depan & angkanya dirapikan saat diketik. */
function skp_input_rp(string $name, $value, string $dis, bool $wajib = false, bool $ringkas = false, string $kelas = ''): void
{
    $v = (float) $value > 0 ? number_format((float) $value, 0, ',', '.') : '';
    $pad = $ringkas ? '0 6px' : '0 10px';
    $fs  = $ringkas ? '11.5px' : '13px';
    ?>
    <div style="display:flex;align-items:stretch">
        <span style="display:flex;align-items:center;padding:<?= $pad ?>;background:#f1f5f9;border:1px solid var(--border,#e2e8f0);border-right:none;border-radius:8px 0 0 8px;font-size:<?= $fs ?>;font-weight:700;color:#475569">Rp.</span>
        <input name="<?= h($name) ?>" class="rp-input <?= h($kelas) ?>" inputmode="numeric" value="<?= h($v) ?>" placeholder="0"
               style="border-top-left-radius:0;border-bottom-left-radius:0;flex:1;min-width:0;text-align:right" <?= $wajib ? 'required' : '' ?> <?= $dis ?>>
    </div>
    <?php
}
/** Blok "Data Sewa" untuk dokumen berdiri sendiri. */
/**
 * Blok harga untuk dokumen Gudang/Media yang berdiri sendiri: tarif & total
 * nilai sewa. Dipisah dari blok Data Sewa supaya semua angka uang berkumpul di
 * bagian bawah formulir, sejajar dengan Security Deposit & Rincian Biaya.
 */
/**
 * Bulan-bulan tempat nilai kontrak ini akan diakui, beserta nominal bawaannya.
 *
 * Mengikuti aturan yang sama persis dengan yang dipakai saat alokasi ditulis
 * (AllocationService::saveAllocations), supaya daftar bulan di formulir tidak
 * pernah berbeda dari bulan yang nanti benar-benar terbentuk:
 *
 *   - diakui sekaligus  → satu bulan saja (awal atau akhir, sesuai siklusnya);
 *   - dibagi rata, harga BULANAN tanpa prorata kalender → per siklus tanggal;
 *   - selebihnya        → per bulan kalender, lewat preview().
 *
 * Mengembalikan [period_key => nominal]. Tanggal yang belum lengkap atau
 * terbalik mengembalikan daftar kosong — formulirnya lalu tidak menampilkan
 * tabel apa pun, bukan menampilkan bulan karangan.
 */
function skp_periode_akui(array $src): array
{
    require_once dirname(__DIR__) . '/AllocationService.php';
    $mulai = trim((string) ($src['start_date'] ?? ''));
    $akhir = trim((string) ($src['end_date'] ?? ''));
    if ($mulai === '' || $akhir === '' || $akhir < $mulai) return [];
    $total = (float) (($src['final_amount'] ?? 0) ?: ($src['total_calculated'] ?? 0));
    if ($total <= 0) return [];

    $bln = (int) ($src['contract_months'] ?? 0);
    $bm  = (string) ($src['billing_method'] ?? '');
    $sebar = in_array($bm, ['spread', 'anchor_cycle'], true) ? $bm === 'spread' : ($bln > 1);
    $cr = ($src['cycle_recognition'] ?? '') === 'cycle_end' ? 'cycle_end' : 'cycle_start';

    if (!$sebar) {
        return [substr($cr === 'cycle_end' ? $akhir : $mulai, 0, 7) => $total];
    }

    $pt = (string) ($src['pricing_type'] ?? '');
    try {
        if (empty($src['prorata_kalender']) && in_array($pt, ['monthly', 'monthly_area'], true)) {
            $baris = AllocationService::monthlyCycleAllocations(
                new DateTimeImmutable($mulai), new DateTimeImmutable($akhir), $total, $cr);
        } else {
            $baris = AllocationService::preview(array_merge($src, [
                'final_amount'   => $total,
                'billing_method' => 'spread',
            ]));
        }
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($baris as $b) {
        $pk = (string) ($b['period_key'] ?? '');
        if ($pk === '') continue;
        $out[$pk] = ($out[$pk] ?? 0) + (float) ($b['amount'] ?? 0);
    }
    ksort($out);
    if (!$out) return [];

    // preview() menghitung dari TARIF, bukan dari nilai kontrak — pada kontrak
    // yang nilainya di-override hasilnya bisa jauh berbeda. Yang dipakai dari
    // sana hanya BULAN-nya; nominalnya diskalakan ke nilai kontrak, dan sisa
    // pembulatannya ditaruh di bulan terakhir supaya jumlahnya pas.
    $jumlah = array_sum($out);
    if ($jumlah > 0 && round($jumlah) !== round($total)) {
        $kunci = array_keys($out);
        $akhirnya = array_pop($kunci);
        $sisa = $total;
        foreach ($kunci as $pk) {
            $out[$pk] = round($out[$pk] / $jumlah * $total);
            $sisa -= $out[$pk];
        }
        $out[$akhirnya] = round($sisa);
    }
    return $out;
}

/** "2026-12" → "Desember 2026". */
function skp_nama_periode(string $pk): string
{
    $bln = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni',
            '07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
    return ($bln[substr($pk, 5, 2)] ?? $pk) . ' ' . substr($pk, 0, 4);
}

function skp_blok_harga(string $module, array $src, bool $editable): void
{
    $dis = $editable ? '' : 'disabled';
    ?>
    <h3>Harga Sewa</h3>
    <div class="form-grid">
        <div>
            <label>Tarif <span class="muted" style="font-weight:400">(<?= $module === 'gudang' ? 'per bulan' : 'per hari/titik' ?>)</span></label>
            <?php skp_input_rp('s_unit_rate', $src['unit_rate'], $dis); ?>
        </div>
        <?php /* Media: nilai sewanya diisi di blok "Rincian Biaya" — satu tempat
                 saja, persis seperti formulir kertasnya. */ ?>
        <?php if ($module !== 'media'): ?>
        <div>
            <label>Total Nilai Sewa <span style="color:#dc2626">*</span></label>
            <?php skp_input_rp('s_total_amount', $src['final_amount'], $dis, true); ?>
            <div class="help" id="s-total-info" style="margin-top:3px">Dihitung otomatis dari tarif × periode — boleh diubah manual.</div>
        </div>
        <?php endif; ?>
    </div>

    <?php
    /* ── Pengakuan & Recurring ─────────────────────────────────────────────
       Isinya sama dengan yang di Surat Penawaran, karena hasil akhirnya memang
       sama: kolom billing_method / cycle_recognition / recurring_flag pada
       transaksi yang terbit saat dokumen ini disetujui.

       Tabel nominal per bulan hanya muncul bila nilainya memang jatuh ke lebih
       dari satu bulan. Daftar bulannya mengikuti tanggal & metode yang
       TERSIMPAN — bukan yang sedang diketik — supaya tidak pernah menampilkan
       bulan yang nanti tidak terbentuk. Ganti tanggalnya, simpan, daftarnya
       ikut menyesuaikan. */
    $bmKini = (string) ($src['billing_method'] ?? '');
    $crKini = ($src['cycle_recognition'] ?? '') === 'cycle_end' ? 'cycle_end' : 'cycle_start';
    $periode  = skp_periode_akui($src);
    $tersimpan = json_decode((string) ($src['recognition_json'] ?? ''), true);
    if (!is_array($tersimpan)) $tersimpan = [];
    ?>
    <h3 id="s-pengakuan">Pengakuan &amp; Recurring</h3>
    <div class="form-grid">
        <div>
            <label>Metode Pengakuan</label>
            <select name="s_billing_method" id="s-bm" <?= $dis ?>>
                <option value="" <?= $bmKini === '' ? 'selected' : '' ?>>Otomatis (ikut periode)</option>
                <option value="anchor_cycle" <?= $bmKini === 'anchor_cycle' ? 'selected' : '' ?>>Sekaligus &mdash; diakui utuh di 1 bulan</option>
                <option value="spread" <?= $bmKini === 'spread' ? 'selected' : '' ?>>Dibagi rata tiap bulan</option>
            </select>
            <div class="help">Otomatis: kontrak <b>lebih dari 1 bulan</b> &rarr; dibagi rata. Satu periode &rarr; diakui utuh di bulan mulai.</div>
        </div>
        <div>
            <label>Pengakuan per Siklus</label>
            <select name="s_cycle_recognition" id="s-cr" <?= $dis ?>>
                <option value="cycle_start" <?= $crKini === 'cycle_start' ? 'selected' : '' ?>>Bulan Awal siklus</option>
                <option value="cycle_end" <?= $crKini === 'cycle_end' ? 'selected' : '' ?>>Bulan Akhir siklus</option>
            </select>
            <div class="help">Nilai tiap siklus diakui di bulan awal atau bulan akhir siklus itu.</div>
        </div>
        <div class="wide" style="display:flex;align-items:flex-start;gap:10px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:11px 14px">
            <input type="checkbox" name="s_recurring_flag" id="s-recurring" value="1" style="width:18px;height:18px;flex-shrink:0;margin-top:1px" <?= !empty($src['recurring_flag']) ? 'checked' : '' ?> <?= $dis ?>>
            <label for="s-recurring" style="margin:0;cursor:pointer">
                <span style="font-weight:700;color:#0369a1">Diakui Recurring</span>
                <span class="help" style="display:block;margin-top:2px;font-weight:400">Penggolongan untuk Dashboard &amp; Laporan: centang bila ini bagian dari kontrak berulang. <b>Tidak</b> mengubah nilai maupun bulan jatuhnya income &mdash; itu diatur dua kotak di atas.</span>
            </label>
        </div>
    </div>

    <?php if (count($periode) >= 2): $jml = 0.0; ?>
    <h3 style="margin-top:14px">Nominal Diakui per Bulan
        <span style="font-weight:400;font-size:12px;color:var(--muted)">(<?= count($periode) ?> bulan &mdash; boleh berbeda tiap bulan)</span></h3>
    <div style="overflow-x:auto">
    <table class="data" id="s-akui" style="width:100%;max-width:560px">
        <thead><tr><th>Bulan</th><th style="width:190px;text-align:right">Nominal Diakui</th></tr></thead>
        <tbody>
        <?php foreach ($periode as $pk => $bawaan):
            $nilai = array_key_exists($pk, $tersimpan) ? (float) $tersimpan[$pk] : (float) $bawaan;
            $jml += $nilai; ?>
            <tr>
                <td style="font-weight:600"><?= h(skp_nama_periode((string) $pk)) ?></td>
                <td><?php skp_input_rp('s_akui[' . $pk . ']', $nilai, $dis, false, true, 'akui-nilai'); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
            <td style="font-weight:700">Jumlah</td>
            <td style="text-align:right;font-weight:700"><span id="s-akui-jml"><?= h(number_format($jml, 0, ',', '.')) ?></span></td>
        </tr></tfoot>
    </table>
    </div>
    <p class="help" id="s-akui-cek" style="margin-top:5px"></p>
    <?php if ($editable): ?>
    <p style="margin:6px 0 0"><button type="button" class="btn light" id="s-akui-rata">Bagi rata lagi</button></p>
    <script>
    (function () {
        var tabel = document.getElementById('s-akui');
        if (!tabel) return;
        var angka = function (el) { return el ? parseInt((el.value || '').replace(/\D/g, ''), 10) || 0 : 0; };
        var fmt   = function (n) { return (n || 0).toLocaleString('id-ID'); };
        var baris = function () { return [].slice.call(tabel.querySelectorAll('.akui-nilai')); };
        var totalKontrak = function () {
            return angka(document.querySelector('input[name="s_total_amount"]'));
        };
        function periksa() {
            var isi = baris(), jml = 0;
            isi.forEach(function (el) { jml += angka(el); });
            document.getElementById('s-akui-jml').textContent = fmt(jml);
            var t = totalKontrak(), cek = document.getElementById('s-akui-cek');
            if (!t) { cek.textContent = ''; return; }
            if (jml === t) {
                cek.innerHTML = '<span style="color:#166534">Cocok dengan Total Nilai Sewa ' + fmt(t) + '.</span>';
            } else {
                cek.innerHTML = '<span style="color:#b45309">Jumlahnya ' + fmt(jml)
                    + ', sedangkan Total Nilai Sewa ' + fmt(t) + ' &mdash; selisih ' + fmt(Math.abs(jml - t))
                    + '. Nilai kontraknya akan mengikuti jumlah di tabel ini.</span>';
            }
        }
        function bagiRata() {
            var isi = baris(), t = totalKontrak();
            if (!isi.length || !t) return;
            var per = Math.floor(t / isi.length);
            isi.forEach(function (el, i) {
                var v = (i === isi.length - 1) ? t - per * (isi.length - 1) : per;
                el.value = fmt(v);
                el.dispatchEvent(new Event('input', { bubbles: true }));
            });
            periksa();
        }
        document.addEventListener('input', function (e) {
            if (!e.target) return;
            if (e.target.classList && e.target.classList.contains('akui-nilai')) periksa();
            if (e.target.name === 's_total_amount') periksa();
        });
        document.getElementById('s-akui-rata').addEventListener('click', bagiRata);
        periksa();
    })();
    </script>
    <?php endif; ?>
    <?php endif; ?>
    <?php
}

function skp_standalone_form(PDO $pdo, int $pid, string $module, array $src, bool $editable): void
{
    $dis = $editable ? '' : 'disabled';
    $clients = $pdo->query("SELECT id, company_name, brand_name, address, npwp, ktp, siup, business_type FROM master_clients WHERE status='active' ORDER BY company_name")->fetchAll();
    $kontak  = $pdo->query("SELECT id, client_id, name, phone FROM master_client_contacts WHERE status='active' ORDER BY name")->fetchAll();
    $pics    = $pdo->prepare("SELECT name FROM master_pic WHERE property_id=? AND status='active' ORDER BY name");
    $pics->execute([$pid]);
    $pics = $pics->fetchAll(PDO::FETCH_COLUMN);
    if ($module === 'gudang') {
        $units = $pdo->prepare("SELECT code, CONCAT(code, ' — ', name) lbl, name nm, location lantai, area_sqm luas, ROUND(monthly_rate * area_sqm) tarif FROM master_gudang WHERE property_id=? AND status='active' ORDER BY sort_order, code");
    } else {
        $units = $pdo->prepare("SELECT code, CONCAT(code, ' — ', CONCAT_WS(' ', media_type, location)) lbl, media_type, location, point, location lantai, 0 luas, rate tarif FROM master_media WHERE property_id=? AND status='active' ORDER BY sort_order, code");
    }
    $units->execute([$pid]);
    $units = $units->fetchAll();
    // Nama titik media dirangkai di sini (bukan di SQL) supaya penggal yang
    // berulang — "Atrium - GF - Atrium - GF" — dilebur jadi satu.
    foreach ($units as $i => $u) {
        if (!array_key_exists('nm', $u)) {
            $units[$i]['nm'] = clara_gabung_lokasi($u['media_type'] ?? '', $u['location'] ?? '', $u['point'] ?? '');
        }
    }
    $rp = fn($v) => $v > 0 ? number_format((float) $v, 0, ',', '.') : '';
    ?>
    <?php
    // Label yang sudah terpilih (ditampilkan di kotak pencarian).
    $lblClient = '';
    foreach ($clients as $c) if ((int) $c['id'] === (int) $src['client_id']) $lblClient = $c['company_name'] . ($c['brand_name'] ? ' · ' . $c['brand_name'] : '');
    $lblKontak = '';
    foreach ($kontak as $k) if ((int) $k['id'] === (int) $src['contact_id']) $lblKontak = $k['name'] . ($k['phone'] ? ' · ' . $k['phone'] : '');
    $lblUnit = '';
    foreach ($units as $u) if ($u['code'] === $src['master_code']) $lblUnit = $u['lbl'];
    ?>
    <h3>Data Sewa <span style="font-weight:400;font-size:12px;color:var(--muted)">(<?= $module === 'gudang' ? 'gudang' : 'media' ?> tidak lewat Surat Penawaran — isi di sini)</span></h3>
    <div class="form-grid">
        <div class="wide">
            <label>Client <span style="color:#dc2626">*</span></label>
            <?php if ($editable): ?>
            <div style="position:relative">
                <input type="text" id="s-client-cari" autocomplete="off" placeholder="Ketik nama perusahaan atau brand…" value="<?= h($lblClient) ?>">
                <input type="hidden" name="s_client_id" id="s-client" value="<?= (int) $src['client_id'] ?: '' ?>">
                <div id="s-client-drop" style="display:none"></div>
            </div>
            <div class="help">Ketik untuk mencari, lalu pilih dari daftar.</div>
            <?php else: ?><input value="<?= h($lblClient) ?>" disabled><?php endif; ?>
        </div>
        <div>
            <label>Kontak / Penanggung Jawab</label>
            <?php if ($editable): ?>
            <div style="position:relative">
                <input type="text" id="s-kontak-cari" autocomplete="off" placeholder="Ketik nama kontak…" value="<?= h($lblKontak) ?>">
                <input type="hidden" name="s_contact_id" id="s-contact" value="<?= (int) $src['contact_id'] ?: '' ?>">
                <div id="s-kontak-drop" style="display:none"></div>
            </div>
            <?php else: ?><input value="<?= h($lblKontak) ?>" disabled><?php endif; ?>
        </div>
        <div>
            <label><?= $module === 'gudang' ? 'Unit Gudang' : 'Titik Media' ?> <span style="color:#dc2626">*</span></label>
            <?php if ($editable): ?>
            <div style="position:relative">
                <input type="text" id="s-unit-cari" autocomplete="off" placeholder="Ketik kode atau nama…" value="<?= h($lblUnit) ?>">
                <input type="hidden" name="s_master_code" id="s-unit" value="<?= h($src['master_code']) ?>">
                <div id="s-unit-drop" style="display:none"></div>
            </div>
            <?php else: ?><input value="<?= h($lblUnit) ?>" disabled><?php endif; ?>
        </div>
        <div>
            <label>Sales / PIC</label>
            <select name="s_pic_name" <?= $dis ?>>
                <option value="">— pilih —</option>
                <?php foreach ($pics as $pn): ?><option value="<?= h($pn) ?>" <?= $src['pic_name'] === $pn ? 'selected' : '' ?>><?= h($pn) ?></option><?php endforeach; ?>
            </select>
        </div>
        <?php /* Tanggal mulai & selesai sengaja bersebelahan supaya periodenya
                 terbaca sekali lihat. */ ?>
        <div><label>Tanggal Mulai <span style="color:#dc2626">*</span></label><input type="date" name="s_start_date" value="<?= h($src['start_date']) ?>" required <?= $dis ?>></div>
        <div><label>Tanggal Selesai <span style="color:#dc2626">*</span></label><input type="date" name="s_end_date" value="<?= h($src['end_date']) ?>" required <?= $dis ?>></div>
        <?php /* Tarif & Total Nilai Sewa TIDAK di sini — semua yang menyangkut
                 uang dikumpulkan di bagian bawah formulir (skp_blok_harga). */ ?>
    </div>
    <p class="help" style="margin-top:6px">Transaksi &amp; alokasi bulanannya terbit otomatis saat manager menyetujui dokumen ini.</p>
    <?php if ($editable): ?>
    <script>
    (function () {
        var CLIENTS = <?= json_encode(array_map(fn($c) => [
            'v' => (int) $c['id'], 'lbl' => $c['company_name'], 'sub' => (string) $c['brand_name'],
            'company' => $c['company_name'], 'address' => (string) $c['address'], 'npwp' => (string) $c['npwp'],
            'ktp' => (string) $c['ktp'], 'siup' => (string) $c['siup'], 'usaha' => (string) $c['business_type'],
            'brand' => (string) $c['brand_name'],
        ], $clients), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var KONTAK = <?= json_encode(array_map(fn($k) => [
            'v' => (int) $k['id'], 'lbl' => $k['name'], 'sub' => (string) $k['phone'], 'client' => (int) $k['client_id'],
        ], $kontak), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var UNITS = <?= json_encode(array_map(fn($u) => [
            'v' => $u['code'], 'lbl' => $u['lbl'], 'sub' => '',
            'nama' => (string) $u['nm'], 'lantai' => (string) $u['lantai'],
            'luas' => (float) $u['luas'], 'tarif' => (int) $u['tarif'],
        ], $units), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function esc(s) { return String(s == null ? '' : s).replace(/[<>&"]/g, function (c) { return ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' })[c]; }); }
        function set(sel, val, hanyaBilaKosong) {
            var el = document.querySelector(sel); if (!el) return;
            if (hanyaBilaKosong && el.value && el.value !== '-') return;
            el.value = val || '';
        }

        /** Dropdown yang bisa dicari — pola sama dengan form Surat Penawaran. */
        function picker(o) {
            var src = document.getElementById(o.cari), hid = document.getElementById(o.nilai), dd = document.getElementById(o.drop);
            if (!src || !hid || !dd) return null;
            document.body.appendChild(dd);
            dd.style.cssText = 'display:none;position:fixed;background:#fff;border:1px solid var(--line,#e2e8f0);border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.12);z-index:9000;max-height:260px;overflow-y:auto';
            function pos() { var r = src.getBoundingClientRect(); dd.style.top = (r.bottom + 2) + 'px'; dd.style.left = r.left + 'px'; dd.style.width = r.width + 'px'; }
            function isi() { return typeof o.isi === 'function' ? o.isi() : o.isi; }
            function render(q) {
                pos();
                var lq = String(q || '').toLowerCase().trim();
                var list = isi().filter(function (it) {
                    if (!lq) return true;
                    return (it.lbl + ' ' + (it.sub || '') + ' ' + it.v).toLowerCase().indexOf(lq) >= 0;
                });
                dd.innerHTML = '';
                list.slice(0, 80).forEach(function (it) {
                    var d = document.createElement('div');
                    d.style.cssText = 'padding:9px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid #f1f5f9';
                    d.innerHTML = '<strong>' + esc(it.lbl) + '</strong>' + (it.sub ? ' <span style="color:var(--muted,#64748b);font-size:11px">· ' + esc(it.sub) + '</span>' : '');
                    d.addEventListener('mouseover', function () { this.style.background = '#f0fdf4'; });
                    d.addEventListener('mouseout', function () { this.style.background = ''; });
                    d.addEventListener('mousedown', function (e) {
                        e.preventDefault();
                        src.value = it.lbl + (it.sub ? ' · ' + it.sub : '');
                        hid.value = it.v;
                        src.style.outline = '';
                        dd.style.display = 'none';
                        if (o.onPilih) o.onPilih(it);
                    });
                    dd.appendChild(d);
                });
                if (!list.length) dd.innerHTML = '<div style="padding:10px 14px;font-size:13px;color:var(--muted,#64748b)">Tidak ditemukan</div>';
                dd.style.display = '';
            }
            src.addEventListener('input', function () { hid.value = ''; render(this.value); });
            src.addEventListener('focus', function () { render(this.value); });
            src.addEventListener('blur', function () { setTimeout(function () { dd.style.display = 'none'; }, 200); });
            window.addEventListener('scroll', function () { if (dd.style.display !== 'none') pos(); }, true);
            return { el: src, hid: hid };
        }

        // Identitas & spesifikasi ikut terisi dari master begitu pilihan dibuat.
        var pClient = picker({
            cari: 's-client-cari', nilai: 's-client', drop: 's-client-drop', isi: CLIENTS,
            onPilih: function (it) {
                set('#idn-company', it.company); set('#idn-address', it.address); set('#spec-usaha', it.usaha);
                set('input[name="ktp_pj"]', it.ktp, true); set('input[name="npwp_no"]', it.npwp, true);
                set('input[name="siup_no"]', it.siup, true); set('input[name="produk"]', it.brand, true);
                var k = document.getElementById('s-kontak-cari');
                if (k) { k.value = ''; document.getElementById('s-contact').value = ''; }
            },
        });
        picker({
            cari: 's-kontak-cari', nilai: 's-contact', drop: 's-kontak-drop',
            // Kontak dibatasi milik client terpilih supaya tidak salah pasang.
            isi: function () {
                var cid = pClient && pClient.hid.value ? parseInt(pClient.hid.value, 10) : 0;
                return cid ? KONTAK.filter(function (k) { return k.client === cid; }) : KONTAK;
            },
            onPilih: function (it) {
                set('input[name="cp_name"]', it.lbl, true);
                if (it.sub) set('input[name="phone_pj"]', it.sub, true);
            },
        });
        picker({
            cari: 's-unit-cari', nilai: 's-unit', drop: 's-unit-drop', isi: UNITS,
            onPilih: function (it) {
                set('#spec-lokasi', it.nama || it.v);
                set('#spec-lantai', it.lantai || '-');
                set('#spec-luas', (it.luas || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
                // Tarif ikut master tiap kali titik/unit diganti — kecuali sudah
                // diketik sendiri (setAuto hanya menimpa angka hasil otomatis).
                if (it.tarif) setAuto('input[name="s_unit_rate"]', (it.tarif).toLocaleString('id-ID'));
                setAuto('input[name="d_lokasi_kegiatan"]', it.nama || it.v);
                hitungTotal();
                isiBaris();
            },
        });

        // Masa sewa ikut terhitung begitu tanggal diisi.
        var d1 = document.querySelector('input[name="s_start_date"]'), d2 = document.querySelector('input[name="s_end_date"]');
        function isiMasa() {
            if (!d1 || !d2 || !d1.value || !d2.value) return;
            var hari = Math.round((new Date(d2.value) - new Date(d1.value)) / 86400000) + 1;
            set('#spec-masa', d1.value + ' s/d ' + d2.value + (hari > 0 ? ' (' + hari + ' hari)' : ''));
        }
        function isiPemakaian() {
            if (!d1 || !d2 || !d1.value || !d2.value) return;
            setAuto('input[name="d_tanggal_pemakaian"]', tglID(d1.value) + ' – ' + tglID(d2.value));
        }
        if (d1) d1.addEventListener('change', function () { isiMasa(); isiPemakaian(); });
        if (d2) d2.addEventListener('change', function () { isiMasa(); isiPemakaian(); });

        // Kotak rupiah: angka dirapikan saat diketik (1000 → 1.000).
        // Delegasi: baris tabel gudang yang baru ditambah ikut kebagian format ini.
        document.addEventListener('input', function (e) {
            var el = e.target;
            if (!el.classList || !el.classList.contains('rp-input')) return;
            var raw = el.value.replace(/\D/g, '');
            el.value = raw ? parseInt(raw, 10).toLocaleString('id-ID') : '';
        });

        // ── Total nilai sewa = tarif × periode ────────────────────────────────
        // Dihitung otomatis, TAPI begitu diisi manual angkanya tidak ditimpa lagi
        // (pelajaran dari kasus DP Rp 0 yang dulu ketimpa hitungan otomatis).
        // Mengosongkan kotaknya = minta dihitung otomatis lagi.
        var MODE  = '<?= h($module) ?>';
        // Kotak tarif & total dirender SETELAH skrip ini (blok harga ada di
        // bagian bawah formulir), jadi pencariannya ditunda sampai siap.
        var tarif = null, total = null, info = null;
        function pasangTarif() { if (!tarif) tarif = document.querySelector('input[name="s_unit_rate"]'); return !!tarif; }
        // Pada Form Utilities kotak totalnya dirender setelah skrip ini, jadi
        // pencariannya diulang saat halaman siap.
        function pasangTotal() {
            if (total) return true;
            total = document.querySelector('input[name="s_total_amount"]');
            info  = document.getElementById('s-total-info');
            if (!total) return false;
            // Sementara ditandai manual supaya angka tersimpan tidak tertimpa
            // sebelum daftar item ikut terbaca; dinilai ulang di tandaiManual().
            if (total.value.replace(/\D/g, '') !== '') total.dataset.manual = '1';
            total.addEventListener('input', function () {
                this.dataset.manual = this.value.replace(/\D/g, '') === '' ? '' : '1';
                hitungTotal();
            });
            return true;
        }
        pasangTotal();

        function angka(el) { return el ? parseInt((el.value || '').replace(/\D/g, ''), 10) || 0 : 0; }
        function durasi() {
            if (!d1 || !d2 || !d1.value || !d2.value) return null;
            var a = new Date(d1.value), b = new Date(d2.value);
            if (isNaN(a) || isNaN(b) || b < a) return null;
            var hari = Math.round((b - a) / 86400000) + 1;
            if (MODE !== 'gudang') return { n: hari, satuan: 'hari' };
            // Gudang dihitung per bulan: hitung selisih bulan dari tanggal+1 hari.
            var e = new Date(b); e.setDate(e.getDate() + 1);
            var bln = (e.getFullYear() - a.getFullYear()) * 12 + (e.getMonth() - a.getMonth());
            if (e.getDate() < a.getDate()) bln -= 1;
            return { n: Math.max(1, bln), satuan: 'bulan' };
        }
        function fmt(n) { return (n || 0).toLocaleString('id-ID'); }
        function rupiah(n) { return 'Rp. ' + fmt(n); }

        // Biaya utilities / media promo / parkir yang dicentang — hanya ada di
        // Form Utilities; untuk Gudang hasilnya 0 sehingga hitungannya tak berubah.
        // Kelompok yang harganya disembunyikan tidak ikut dijumlahkan: harga yang
        // tidak tercetak di surat tidak boleh diam-diam menambah tagihan.
        function grupSembunyi(g) {
            if (!g) return false;
            var cb = document.getElementById('fu-sbn-' + g);
            return !!(cb && cb.checked);
        }
        function itemFU() {
            var s = 0;
            var baris = document.querySelectorAll('tr.fu-row');
            for (var i = 0; i < baris.length; i++) {
                if (grupSembunyi(baris[i].getAttribute('data-grup'))) continue;
                var c = baris[i].querySelector('.fu-cek'), b = baris[i].querySelector('.fu-biaya');
                if (c && c.checked && b) s += angka(b);
            }
            return s;
        }

        function hitungTotal() {
            var d = durasi(), t = angka(tarif), item = itemFU();
            if (!info) { isiRekap(); return; }
            if (!d || !t) {
                info.textContent = 'Dihitung otomatis dari tarif × periode — boleh diubah manual.';
                isiRekap();
                return;
            }
            var sewa = t * d.n, nilai = sewa + item;
            var ket = d.n + ' ' + d.satuan + ' × ' + rupiah(t) + ' = ' + rupiah(sewa)
                + (item ? ' + item ' + rupiah(item) + ' = ' + rupiah(nilai) : '');
            if (total && total.dataset.manual === '1') {
                info.innerHTML = 'Diisi manual. Hitungan otomatis: ' + ket + ' — <a href="#" id="s-total-auto">pakai hitungan ini</a>';
                var lk = document.getElementById('s-total-auto');
                if (lk) lk.addEventListener('click', function (e) {
                    e.preventDefault();
                    total.dataset.manual = '';
                    total.value = fmt(nilai);
                    hitungTotal();
                });
                isiRekap();
                return;
            }
            if (total) { total.value = fmt(nilai); total.dataset.auto = total.value; }
            info.textContent = 'Otomatis: ' + ket + ' — boleh diubah manual.';
            isiBaris();
            isiRekap();
        }

        // ── Rincian Biaya Form Utilities ─────────────────────────────────────
        // Susunannya mengikuti formulir kertas: satu baris
        // "sewa + ppn = Rp. total,-". Ketiga angkanya terisi otomatis tapi boleh
        // diketik sendiri — begitu diketik, angkanya tidak ditimpa lagi.
        function isiAuto(el, v) {
            if (!el) return;
            var isi = el.value.trim();
            // Angka tersimpan yang masih sama dengan hitungan otomatis dianggap
            // hasil otomatis, jadi tetap ikut berubah.
            if (el.dataset.auto === undefined && isi !== '' && isi === v) el.dataset.auto = el.value;
            if (isi === '' || el.dataset.auto === el.value) { el.value = v; el.dataset.auto = v; }
        }
        function isiRekap() {
            if (!document.getElementById('fu-rekap')) return;   // hanya dokumen media
            var ppnEl   = document.querySelector('input[name="d_ppn"]');
            var grandEl = document.querySelector('input[name="d_grand"]');
            var tot = angka(total);
            isiAuto(ppnEl, fmt(Math.round(tot * 11 / 12 * 0.12)));
            var ppn = angka(ppnEl);
            isiAuto(grandEl, fmt(tot + ppn));
            var grand = angka(grandEl);
            // Jaminan area: yang SUDAH disetor tetap tercetak sebagai keterangan
            // tapi tidak ditagih lagi, jadi hanya yang belum disetor menambah
            // angka yang harus dibayar. Kosong/0 = tidak ada jaminan sama sekali.
            var depEl = document.querySelector('.skp-dep-val'),
                depCb = document.getElementById('skp_dep_paid'),
                depBantu = document.getElementById('skp_dep_help');
            var dep = depEl ? (parseInt(depEl.value, 10) || 0) : 0;
            var depLunas = !!(depCb && depCb.checked);
            var bayar = grand + (dep > 0 && !depLunas ? dep : 0);
            if (depBantu) depBantu.innerHTML = dep <= 0
                ? 'Kosongkan atau isi 0 bila tidak ada jaminan.'
                : (depLunas
                    ? '<span style="color:#166534">Tercetak sebagai <b>' + rupiah(dep) + ' (Sudah Dibayarkan)</b> — tidak menambah yang harus dibayar.</span>'
                    : 'Ditagih di dokumen ini — yang harus dibayar jadi <b>' + rupiah(bayar) + '</b>.');
            var baris = document.getElementById('fu-baris');
            var ketDep = dep > 0
                ? ' · Jaminan Rp. ' + fmt(dep) + ',-' + (depLunas ? ' (sudah dibayarkan)' : '')
                  + ' · harus dibayar Rp. ' + fmt(bayar) + ',-'
                : '';
            if (baris) baris.textContent = tot ? fmt(tot) + ' + ' + fmt(ppn) + ' = Rp. ' + fmt(grand) + ',-' + ketDep : '—';
            // Terbilang mengikuti angka yang benar-benar harus dibayar.
            isiAuto(document.getElementById('fu-terbilang'), terbilang(bayar));
            // Angka yang diketik sendiri boleh berapa saja, tapi kalau
            // penjumlahannya tidak nyambung surat jadi janggal — diingatkan di
            // sini, lengkap dengan cara mengembalikannya ke hitungan otomatis.
            var tanda = document.getElementById('fu-cek-hitung');
            if (tanda) {
                tanda.innerHTML = (!tot || tot + ppn === grand) ? '' :
                    '<span style="color:#b45309">Penjumlahannya tidak cocok — ' + fmt(tot) + ' + ' + fmt(ppn)
                    + ' seharusnya ' + fmt(tot + ppn) + '.</span> <a href="#" id="fu-reset">Kembalikan ke hitungan otomatis</a>';
            }
        }

        function terbilang(n) {
            var a = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
            function baca(x) {
                if (x < 12) return a[x];
                if (x < 20) return baca(x - 10) + ' belas';
                if (x < 100) return baca(Math.floor(x / 10)) + ' puluh' + (x % 10 ? ' ' + baca(x % 10) : '');
                if (x < 200) return 'seratus' + (x % 100 ? ' ' + baca(x % 100) : '');
                if (x < 1000) return baca(Math.floor(x / 100)) + ' ratus' + (x % 100 ? ' ' + baca(x % 100) : '');
                if (x < 2000) return 'seribu' + (x % 1000 ? ' ' + baca(x % 1000) : '');
                if (x < 1e6) return baca(Math.floor(x / 1000)) + ' ribu' + (x % 1000 ? ' ' + baca(x % 1000) : '');
                if (x < 1e9) return baca(Math.floor(x / 1e6)) + ' juta' + (x % 1e6 ? ' ' + baca(x % 1e6) : '');
                return baca(Math.floor(x / 1e9)) + ' miliar' + (x % 1e9 ? ' ' + baca(x % 1e9) : '');
            }
            return n > 0 ? baca(n).replace(/\s+/g, ' ').trim() + ' rupiah' : '';
        }
        // ── Baris pertama tabel harga ikut Data Sewa ─────────────────────────
        // Diisi otomatis selama sel-nya masih kosong atau masih berisi angka
        // hasil isian otomatis sebelumnya — ketikan sendiri tidak ditimpa.
        function setAuto(sel, val) {
            var el = document.querySelector(sel);
            if (!el) return;
            var kosong = el.value === '' || el.value === '0' || el.value === '0,00';
            if (!kosong && el.dataset.auto !== el.value) return;
            el.value = val;
            el.dataset.auto = val;
        }
        function isiBaris() {
            var lok = document.querySelector('input[name="row[0][lokasi]"]');
            if (!lok) return;                       // dokumen media tidak punya tabel ini
            var u = document.getElementById('s-unit');
            var uItem = u && u.value ? UNITS.filter(function (x) { return x.v === u.value; })[0] : null;
            if (uItem) {
                // Lokasi gudang = letak fisiknya (mis. "Lantai UG ( Samping Rockie)"),
                // bukan nama tenant yang tercatat di master unit.
                setAuto('input[name="d_lokasi_gudang"]', uItem.lantai || uItem.v);
                if (uItem.luas) setAuto('input[name="row[0][luas]"]', (uItem.luas).toLocaleString('id-ID', { maximumFractionDigits: 2 }));
            }
            var lg = document.querySelector('input[name="d_lokasi_gudang"]');
            var namaLokasi = lg && lg.value.trim() !== '' ? lg.value.trim() : (uItem ? (uItem.lantai || uItem.v) : '');
            if (namaLokasi) setAuto('input[name="row[0][lokasi]"]', namaLokasi);
            var t = angka(tarif), tot = angka(total);
            if (t) setAuto('input[name="row[0][harga_bulan]"]', t.toLocaleString('id-ID'));
            if (tot) setAuto('input[name="row[0][total]"]', tot.toLocaleString('id-ID'));
            var luasEl = document.querySelector('input[name="row[0][luas]"]');
            var luas = luasEl ? parseFloat((luasEl.value || '0').replace(/\./g, '').replace(',', '.')) || 0 : 0;
            if (t && luas > 0) setAuto('input[name="row[0][harga_m2]"]', Math.round(t / luas).toLocaleString('id-ID'));
            isiKeterangan();
        }

        // Bullet kolom Keterangan disusun dari template: {hari} & {periode} diisi
        // dari tanggal sewa. Tidak menimpa kalau sudah diketik sendiri.
        function ketTemplate() {
            // Dibaca saat dipakai, bukan saat skrip dimuat: kolom tersembunyi ini
            // dirender setelah blok Data Sewa.
            var el = document.querySelector('input[name="d_ket_tpl"]');
            try { return el ? (JSON.parse(el.value || '[]') || []) : []; } catch (e) { return []; }
        }
        function isiKeterangan() {
            var ta = document.querySelector('textarea[name="row[0][keterangan]"]');
            var KET = ketTemplate();
            if (!ta || !KET.length || !d1 || !d2 || !d1.value || !d2.value) return;
            var hari = Math.round((new Date(d2.value) - new Date(d1.value)) / 86400000) + 1;
            var per  = tglID(d1.value) + ' s.d ' + tglID(d2.value);
            var teks = KET.map(function (b) {
                return String(b).replace('{hari}', hari).replace('{periode}', per);
            }).join('\n');
            var kosong = ta.value.trim() === '' || ta.dataset.auto === ta.value;
            if (!kosong) return;
            ta.value = teks;
            ta.dataset.auto = teks;
        }
        function tglID(v) {
            var b = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
            var t = new Date(v);
            return t.getDate() + ' ' + b[t.getMonth()] + ' ' + t.getFullYear();
        }

        var lgEl = document.querySelector('input[name="d_lokasi_gudang"]');
        if (lgEl) lgEl.addEventListener('input', isiBaris);
        // Tarif dipasang saat halaman siap (kotaknya dirender di bagian bawah).
        function pasangPemicuTarif() { if (pasangTarif() && !tarif.dataset.terikat) { tarif.dataset.terikat = '1'; tarif.addEventListener('input', hitungTotal); } }
        pasangPemicuTarif();
        if (d1) d1.addEventListener('change', hitungTotal);
        if (d2) d2.addEventListener('change', hitungTotal);

        // Daftar item Form Utilities dirender SETELAH skrip ini, jadi pemicunya
        // didelegasikan ke document dan pengisian awalnya menunggu halaman siap.
        document.addEventListener('input', function (e) {
            var el = e.target;
            if (!el.classList || !el.classList.contains('fu-biaya')) return;
            var tr = el.closest('tr'), c = tr ? tr.querySelector('.fu-cek') : null;
            if (c && !c.checked && angka(el) > 0) c.checked = true;   // diisi = dipakai
            if (tr && c) tr.style.background = c.checked ? '#f0fdf4' : 'transparent';
            hitungTotal();
        });
        document.addEventListener('input', function (e) {
            var n = e.target.name;
            // deposit_amount diformat oleh skrip di skp.php yang terpasang
            // langsung di kotaknya; pendengar ini menyala setelahnya, jadi
            // nilai tersembunyinya sudah diperbarui saat isiRekap() membacanya.
            if (n === 'd_ppn' || n === 'd_grand' || n === 'deposit_amount') isiRekap();
        });
        document.addEventListener('change', function (e) {
            if (e.target && e.target.id === 'skp_dep_paid') isiRekap();
        });
        document.addEventListener('click', function (e) {
            if (!e.target || e.target.id !== 'fu-reset') return;
            e.preventDefault();
            ['input[name="d_ppn"]', 'input[name="d_grand"]', '#fu-terbilang'].forEach(function (sel) {
                var el = document.querySelector(sel);
                if (el) { el.value = ''; delete el.dataset.auto; }
            });
            isiRekap();
        });
        document.addEventListener('change', function (e) {
            var el = e.target;
            if (!el.classList || !el.classList.contains('fu-cek')) return;
            var tr = el.closest('tr');
            if (tr) tr.style.background = el.checked ? '#f0fdf4' : 'transparent';
            hitungTotal();
        });
        // Saklar "Sembunyikan harga": totalnya langsung dihitung ulang, dan
        // kolom biaya kelompok itu diredupkan supaya terlihat tidak terpakai.
        document.addEventListener('change', function (e) {
            var el = e.target;
            if (!el.id || el.id.indexOf('fu-sbn-') !== 0) return;
            redupkanGrup(el.getAttribute('data-grup'), el.checked);
            hitungTotal();
        });
        function redupkanGrup(g, redup) {
            if (!g) return;
            var baris = document.querySelectorAll('tr.fu-row[data-grup="' + g + '"]');
            for (var i = 0; i < baris.length; i++) {
                var sel = baris[i].querySelectorAll('.fu-biaya, input[name$="[satuan]"]');
                for (var j = 0; j < sel.length; j++) {
                    var kotak = sel[j].closest('div') || sel[j];
                    kotak.style.opacity = redup ? '.4' : '';
                }
            }
        }

        function siap(fn) {
            if (document.readyState !== 'loading') fn();
            else document.addEventListener('DOMContentLoaded', fn);
        }
        // Angka tersimpan yang ternyata sama persis dengan hitungan otomatis tidak
        // perlu dilabeli "diisi manual" — supaya keterangannya tidak membingungkan.
        function tandaiManual() {
            if (!total) return;
            var isi = angka(total);
            if (!isi) { total.dataset.manual = ''; return; }
            var d = durasi(), t = angka(tarif);
            var auto = (d && t) ? t * d.n + itemFU() : 0;
            if (auto && isi === auto) { total.dataset.manual = ''; total.dataset.auto = total.value; }
            else total.dataset.manual = '1';
        }
        siap(function () {
            pasangPemicuTarif();
            pasangTotal();
            ['utilities', 'media_promo', 'parkir'].forEach(function (g) {
                var cb = document.getElementById('fu-sbn-' + g);
                if (cb && cb.checked) redupkanGrup(g, true);
            });
            tandaiManual();
            var u = document.getElementById('s-unit');
            var uItem = u && u.value ? UNITS.filter(function (x) { return x.v === u.value; })[0] : null;
            if (uItem) setAuto('input[name="d_lokasi_kegiatan"]', uItem.nama || uItem.v);
            isiPemakaian();
            hitungTotal();
        });
        hitungTotal();
    })();
    </script>
    <?php endif;
}
