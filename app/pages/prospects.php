<?php
declare(strict_types=1);

/**
 * Prospek — calon yang belum pasti, pengganti "sel kuning" di Google Sheet.
 *
 * Di spreadsheet, PIC menandai kuning calon client yang belum deal: belum ada
 * dokumen, belum tentu jadi, tetapi nilainya tetap ditulis dan ikut dijumlahkan
 * sebagai tabungan. Dari situ mereka menakar "kalau semua yang kuning jadi,
 * target bulan ini tercapai atau tidak" — dan itulah dasar mereka memutuskan
 * harus mengejar berapa lagi di sisa bulan.
 *
 * CLARA baru mencatat sejak surat penawaran resmi terbit, sehingga calon yang
 * masih dibicarakan tidak punya tempat sama sekali. Halaman ini memberi
 * tempatnya, dengan dua aturan keras:
 *
 *   1. Angkanya TIDAK PERNAH masuk pencapaian resmi, income, atau laporan
 *      manajemen. Ia hanya tampil terpisah di Kalender dan di halaman ini.
 *   2. Isiannya sesedikit mungkin — pada tahap ini memang belum ada data resmi.
 *
 * Begitu calonnya jadi, tombol "Buat Surat Penawaran" membawa isiannya ke alur
 * resmi, dan prospeknya ditandai selesai supaya tidak terhitung dua kali.
 */

/** Pilihan keyakinan — sengaja tiga saja supaya tidak jadi pekerjaan tambahan. */
function _prospek_yakin(): array
{
    return [
        'tinggi' => ['Tinggi',  'Sudah sepakat lisan, tinggal dokumen', '#15803D', '#DCFCE7'],
        'sedang' => ['Sedang',  'Masih dibicarakan, arahnya baik',      '#B45309', '#FEF3C7'],
        'rendah' => ['Rendah',  'Baru penjajakan',                      '#64748B', '#F1F5F9'],
    ];
}

/** Warna tiap PIC (dari Master PIC) — dipakai bersama Kalender. */
function prospek_warna_pic(PDO $pdo, int $pid): array
{
    $st = $pdo->prepare("SELECT name, color FROM master_pic WHERE property_id = ? AND status = 'active'");
    $st->execute([$pid]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $w = trim((string) ($r['color'] ?? ''));
        if ($w !== '') $out[(string) $r['name']] = $w;
    }
    return $out;
}

/**
 * Prospek yang menyentuh sebuah bulan, untuk modul tertentu.
 * Dipakai halaman ini DAN Kalender — satu sumber, supaya angkanya tidak pernah
 * berbeda antara dua layar.
 */
function prospek_bulan(PDO $pdo, int $pid, string $bulan, string $modul = ''): array
{
    $awal  = $bulan . '-01';
    $akhir = date('Y-m-t', strtotime($awal));
    $sql = "SELECT * FROM prospects
             WHERE property_id = ? AND deleted_at IS NULL AND status = 'open'
               AND start_date <= ? AND end_date >= ?";
    $arg = [$pid, $akhir, $awal];
    if ($modul !== '') { $sql .= ' AND module = ?'; $arg[] = $modul; }
    $sql .= ' ORDER BY start_date, id';
    $st = $pdo->prepare($sql);
    $st->execute($arg);
    return $st->fetchAll();
}

/** Daftar unit untuk pilihan di formulir, per modul. */
function _prospek_unit(PDO $pdo, int $pid): array
{
    $out = ['cl' => [], 'media' => [], 'gudang' => []];
    $q = [
        'cl'     => "SELECT code, CONCAT(code, ' — ', location_name) lbl FROM master_cl_units WHERE property_id=? AND status='active' ORDER BY sort_order, code",
        'media'  => "SELECT code, CONCAT(code, ' — ', CONCAT_WS(' ', media_type, location)) lbl FROM master_media WHERE property_id=? AND status='active' ORDER BY sort_order, code",
        'gudang' => "SELECT code, CONCAT(code, ' — ', name) lbl FROM master_gudang WHERE property_id=? AND status='active' ORDER BY sort_order, code",
    ];
    foreach ($q as $m => $sql) {
        $st = $pdo->prepare($sql);
        $st->execute([$pid]);
        $out[$m] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $out;
}

// ─── Daftar ──────────────────────────────────────────────────────────────────

function prospects_page(PDO $pdo): void
{
    require_permission('view_transactions');
    $pid   = current_property_id();
    $bulan = periode_bulan('month');
    $modul = in_array(getv('module', ''), ['cl', 'media', 'gudang'], true) ? (string) getv('module') : '';
    $picF  = trim((string) getv('pic', ''));
    $lihatSemua = getv('status', 'open') === 'semua';

    $awal  = $bulan . '-01';
    $akhir = date('Y-m-t', strtotime($awal));
    $sql = "SELECT * FROM prospects WHERE property_id = ? AND deleted_at IS NULL
              AND start_date <= ? AND end_date >= ?";
    $arg = [$pid, $akhir, $awal];
    if (!$lihatSemua) { $sql .= " AND status = 'open'"; }
    if ($modul !== '') { $sql .= ' AND module = ?'; $arg[] = $modul; }
    if ($picF !== '')  { $sql .= ' AND pic_name = ?'; $arg[] = $picF; }
    $sql .= ' ORDER BY start_date, id';
    $st = $pdo->prepare($sql);
    $st->execute($arg);
    $rows = $st->fetchAll();

    $warna = prospek_warna_pic($pdo, $pid);
    $pics  = $pdo->prepare("SELECT name FROM master_pic WHERE status='active' AND property_id = ? ORDER BY name");
    $pics->execute([$pid]);
    $pics  = $pics->fetchAll(PDO::FETCH_COLUMN);
    $bisaIsi = can('manage_prospects');
    $total = 0.0;
    $perPic = [];
    foreach ($rows as $r) {
        if ($r['status'] !== 'open') continue;
        $total += (float) $r['est_amount'];
        $perPic[(string) $r['pic_name']] = ($perPic[(string) $r['pic_name']] ?? 0) + (float) $r['est_amount'];
    }
    arsort($perPic);
    $modLabel = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
    $qs = fn(array $u) => '?' . http_build_query(array_filter(array_merge(
        ['r' => 'prospects', 'month' => $bulan, 'module' => $modul, 'pic' => $picF, 'status' => $lihatSemua ? 'semua' : ''], $u), fn($v) => $v !== ''));

    layout('Prospek', function () use ($rows, $bulan, $modul, $picF, $pics, $warna, $bisaIsi, $total, $perPic, $modLabel, $qs, $lihatSemua) {
        $yakin = _prospek_yakin();
        ?>
        <div class="panel" style="padding:12px 16px;margin-bottom:12px;background:#FFFBEB;border:1px solid #FDE68A">
            <b>Prospek adalah calon yang belum pasti</b> — belum ada surat penawaran, belum tentu jadi.
            Angkanya <b>tidak</b> ikut ke pencapaian resmi, income, atau laporan manajemen; ia hanya ditampilkan
            terpisah supaya terlihat “kalau semua ini jadi, target bulan ini tercapai atau belum”.
        </div>

        <form method="get" class="toolbar" style="gap:10px">
            <input type="hidden" name="r" value="prospects">
            <div><label class="muted">Bulan</label><input type="month" name="month" value="<?= h($bulan) ?>" style="width:160px"></div>
            <div><label class="muted">Modul</label><select name="module"><option value="">Semua</option>
                <?php foreach ($modLabel as $k => $v): ?><option value="<?= h($k) ?>" <?= $modul === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
            <div><label class="muted">PIC</label><select name="pic"><option value="">Semua</option>
                <?php foreach ($pics as $p): ?><option <?= $picF === $p ? 'selected' : '' ?>><?= h($p) ?></option><?php endforeach; ?></select></div>
            <div><label class="muted">Tampilkan</label><select name="status">
                <option value="open" <?= $lihatSemua ? '' : 'selected' ?>>Yang masih berjalan</option>
                <option value="semua" <?= $lihatSemua ? 'selected' : '' ?>>Termasuk yang sudah selesai</option></select></div>
            <button type="submit">Tampilkan</button>
            <?php if ($bisaIsi): ?><a class="btn" href="?r=prospect_form&month=<?= h($bulan) ?>">+ Tambah Prospek</a><?php endif; ?>
            <a class="btn light" href="?r=calendar&month=<?= h($bulan) ?>">Lihat di Kalender</a>
        </form>

        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px">
            <div class="card" style="padding:12px 16px;border-top:3px solid #D97706">
                <div class="muted" style="font-size:12px">Total prospek <?= h(period_label($bulan)) ?></div>
                <div style="font-size:22px;font-weight:800;color:#B45309"><?= money($total) ?></div>
            </div>
            <?php foreach ($perPic as $nm => $v): if ($nm === '') continue; ?>
                <div class="card" style="padding:12px 16px;border-top:3px solid <?= h($warna[$nm] ?? '#CBD5E1') ?>">
                    <div class="muted" style="font-size:12px"><?= h($nm) ?></div>
                    <div style="font-size:18px;font-weight:700"><?= money($v) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="table-wrap"><table class="mobile-cards">
            <thead><tr><th>Calon penyewa</th><th>Unit</th><th>Perkiraan periode</th><th>PIC</th>
                <th style="text-align:right">Perkiraan nilai</th><th>Keyakinan</th><th>Catatan</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr class="mc-full"><td colspan="8" style="text-align:center;color:var(--muted);padding:26px">
                    Belum ada prospek di bulan ini.<?php if ($bisaIsi): ?> Tekan <b>+ Tambah Prospek</b> untuk mencatat calon yang sedang dibicarakan.<?php endif; ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): $y = $yakin[$r['confidence']] ?? $yakin['sedang']; ?>
                <tr style="<?= $r['status'] !== 'open' ? 'opacity:.55' : '' ?>">
                    <td data-label="Calon penyewa"><b><?= h($r['client_name']) ?></b>
                        <?php if ($r['status'] === 'deal'): ?><br><span class="badge" style="color:#166534;background:#dcfce7">Sudah jadi penawaran</span>
                        <?php elseif ($r['status'] === 'batal'): ?><br><span class="badge" style="color:#991B1B;background:#FEE2E2">Tidak jadi</span><?php endif; ?></td>
                    <td data-label="Unit"><?= $r['master_code'] ? h($r['master_code']) : '<span class="muted">belum ditentukan</span>' ?>
                        <br><span class="muted" style="font-size:11px"><?= h($modLabel[$r['module']] ?? $r['module']) ?></span></td>
                    <td data-label="Perkiraan periode" style="white-space:nowrap"><?= h($r['start_date']) ?><br><span class="muted" style="font-size:11px">s/d <?= h($r['end_date']) ?></span></td>
                    <td data-label="PIC"><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:<?= h($warna[$r['pic_name']] ?? '#CBD5E1') ?>;margin-right:5px"></span><?= h($r['pic_name'] ?: '-') ?></td>
                    <td data-label="Perkiraan nilai" style="text-align:right;font-weight:700"><?= money($r['est_amount']) ?></td>
                    <td data-label="Keyakinan"><span class="badge" style="color:<?= $y[2] ?>;background:<?= $y[3] ?>" title="<?= h($y[1]) ?>"><?= h($y[0]) ?></span></td>
                    <td data-label="Catatan" style="font-size:12px;color:var(--muted)"><?= h((string) $r['note']) ?></td>
                    <td style="white-space:nowrap">
                        <?php if ($bisaIsi): ?>
                            <a class="btn light" href="?r=prospect_form&id=<?= (int) $r['id'] ?>">Ubah</a>
                            <?php if ($r['status'] === 'open'): ?>
                                <a class="btn light" style="background:#ECFDF5;color:#065F46;border-color:#A7F3D0"
                                   href="?r=offer_form&module=<?= h($r['module']) ?>&master_code=<?= h((string) $r['master_code']) ?>&start_date=<?= h($r['start_date']) ?>&end_date=<?= h($r['end_date']) ?>&from_prospect=<?= (int) $r['id'] ?>">Jadi penawaran</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php
    });
}

// ─── Formulir ────────────────────────────────────────────────────────────────

function prospect_form(PDO $pdo): void
{
    require_permission('manage_prospects');
    $pid = current_property_id();
    $id  = (int) getv('id', 0);
    $row = ['id' => 0, 'module' => 'cl', 'master_code' => '', 'client_name' => '', 'pic_name' => '',
            'start_date' => '', 'end_date' => '', 'est_amount' => '', 'confidence' => 'sedang',
            'note' => '', 'status' => 'open'];
    if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM prospects WHERE id = ? AND property_id = ? AND deleted_at IS NULL');
        $st->execute([$id, $pid]);
        $ada = $st->fetch();
        if (!$ada) { flash('Prospek tidak ditemukan.'); redirect_to('prospects'); }
        $row = $ada;
    } else {
        $bulan = periode_bulan('month');
        $row['start_date'] = $bulan . '-01';
        $row['end_date']   = date('Y-m-t', strtotime($bulan . '-01'));
        // Sales yang membuka formulir langsung terisi namanya sendiri.
        $saya = current_sales_scope($pdo, $pid);
        $row['pic_name'] = (string) ($saya['pic'] ?? '');
    }
    $unit = _prospek_unit($pdo, $pid);
    $pics = $pdo->prepare("SELECT name FROM master_pic WHERE status='active' AND property_id = ? ORDER BY name");
    $pics->execute([$pid]);
    $pics = $pics->fetchAll(PDO::FETCH_COLUMN);

    layout($id ? 'Ubah Prospek' : 'Tambah Prospek', function () use ($row, $unit, $pics, $id) {
        $yakin = _prospek_yakin();
        ?>
        <div class="toolbar"><a class="btn light" href="?r=prospects">← Daftar Prospek</a></div>
        <form method="post" action="?r=prospect_save" class="panel" style="padding:18px 22px;max-width:760px">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
            <p class="muted" style="margin:0 0 14px">Isi seadanya saja — ini calon, bukan kontrak. Yang wajib hanya
                nama calon penyewa, perkiraan tanggal, dan perkiraan nilainya.</p>

            <div class="form-grid">
                <div class="wide">
                    <label>Calon penyewa <span style="color:#dc2626">*</span></label>
                    <input name="client_name" value="<?= h($row['client_name']) ?>" maxlength="190" required placeholder="mis. PT Sumber Rejeki / Kopi Kenangan">
                    <div class="help">Boleh ditulis apa adanya — belum perlu terdaftar di Master Client.</div>
                </div>
                <div>
                    <label>Modul</label>
                    <select name="module" id="pr-mod">
                        <?php foreach (['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'] as $k => $v): ?>
                            <option value="<?= h($k) ?>" <?= $row['module'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Unit / titik <span class="muted" style="font-weight:400">(boleh kosong)</span></label>
                    <select name="master_code" id="pr-unit"></select>
                    <div class="help">Kalau sudah ada incarannya, pilih di sini supaya muncul di Kalender.</div>
                </div>
                <div><label>Perkiraan mulai <span style="color:#dc2626">*</span></label><input type="date" name="start_date" value="<?= h($row['start_date']) ?>" required></div>
                <div><label>Perkiraan selesai <span style="color:#dc2626">*</span></label><input type="date" name="end_date" value="<?= h($row['end_date']) ?>" required></div>
                <div>
                    <label>Perkiraan nilai <span style="color:#dc2626">*</span></label>
                    <input name="est_amount" id="pr-rp" inputmode="numeric" value="<?= $row['est_amount'] !== '' ? h(number_format((float) $row['est_amount'], 0, ',', '.')) : '' ?>" required placeholder="Rp">
                    <div class="help">Angka kasar pun tidak apa-apa — gunanya untuk menakar sisa target.</div>
                </div>
                <div>
                    <label>PIC</label>
                    <select name="pic_name"><option value="">—</option>
                        <?php foreach ($pics as $p): ?><option <?= $row['pic_name'] === $p ? 'selected' : '' ?>><?= h($p) ?></option><?php endforeach; ?></select>
                </div>
                <div>
                    <label>Seberapa yakin</label>
                    <select name="confidence">
                        <?php foreach ($yakin as $k => $y): ?><option value="<?= h($k) ?>" <?= $row['confidence'] === $k ? 'selected' : '' ?>><?= h($y[0]) ?> — <?= h($y[1]) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="wide"><label>Catatan</label><input name="note" value="<?= h((string) $row['note']) ?>" maxlength="255" placeholder="mis. menunggu keputusan pusat"></div>
                <?php if ($id): ?>
                <div class="wide">
                    <label>Status</label>
                    <select name="status">
                        <option value="open" <?= $row['status'] === 'open' ? 'selected' : '' ?>>Masih berjalan — ikut dihitung sebagai prospek</option>
                        <option value="deal" <?= $row['status'] === 'deal' ? 'selected' : '' ?>>Sudah jadi penawaran — berhenti dihitung</option>
                        <option value="batal" <?= $row['status'] === 'batal' ? 'selected' : '' ?>>Tidak jadi — berhenti dihitung</option>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap">
                <button type="submit">Simpan</button>
                <a class="btn secondary" href="?r=prospects">Batal</a>
                <?php if ($id): ?>
                    <button type="submit" formaction="?r=prospect_delete" class="btn light" style="color:#B91C1C;margin-left:auto"
                            onclick="return confirm('Hapus prospek ini?')">Hapus</button>
                <?php endif; ?>
            </div>
        </form>

        <script>
        (function () {
            var UNIT = <?= json_encode($unit, JSON_UNESCAPED_UNICODE) ?>, TERPILIH = <?= json_encode((string) $row['master_code']) ?>;
            var mod = document.getElementById('pr-mod'), unit = document.getElementById('pr-unit');
            function isi() {
                var d = UNIT[mod.value] || [];
                unit.innerHTML = '<option value="">— belum ditentukan —</option>';
                d.forEach(function (u) {
                    var o = document.createElement('option');
                    o.value = u.code; o.textContent = u.lbl;
                    if (u.code === TERPILIH) o.selected = true;
                    unit.appendChild(o);
                });
            }
            mod.addEventListener('change', isi); isi();
            // Ribuan diberi titik sambil diketik — sama seperti kotak rupiah lain.
            var rp = document.getElementById('pr-rp');
            rp.addEventListener('input', function () {
                var a = this.value.replace(/\D/g, '');
                this.value = a ? parseInt(a, 10).toLocaleString('id-ID') : '';
            });
        })();
        </script>
        <?php
    });
}

function prospect_save(PDO $pdo): void
{
    require_permission('manage_prospects');
    verify_csrf();
    $pid  = current_property_id();
    $id   = (int) post('id', 0);
    // Dibaca sekali lalu divalidasi. Membaca ulang post() di cabang "benar"
    // pernah membuat status tersimpan kosong: isian status memang tidak ada di
    // formulir tambah, jadi bacaan keduanya (tanpa nilai bawaan) mengembalikan ''.
    $modul  = (string) post('module', 'cl');
    $yakinP = (string) post('confidence', 'sedang');
    $statusP = (string) post('status', 'open');
    $modul  = in_array($modul, ['cl', 'media', 'gudang'], true) ? $modul : 'cl';
    $yakin  = isset(_prospek_yakin()[$yakinP]) ? $yakinP : 'sedang';
    $status = in_array($statusP, ['open', 'deal', 'batal'], true) ? $statusP : 'open';
    $nama  = trim((string) post('client_name', ''));
    $mulai = (string) post('start_date', '');
    $akhir = (string) post('end_date', '');
    $nilai = parse_rupiah((string) post('est_amount', '0'));

    $sah = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d) && strtotime((string) $d) !== false;
    if ($nama === '' || !$sah($mulai) || !$sah($akhir)) {
        flash('Nama calon penyewa dan kedua tanggalnya wajib diisi.');
        redirect_to('prospect_form', $id ? ['id' => $id] : []);
    }
    if ($akhir < $mulai) { $t = $mulai; $mulai = $akhir; $akhir = $t; }

    $arg = [$pid, $modul, trim((string) post('master_code', '')) ?: null, substr($nama, 0, 190),
            trim((string) post('pic_name', '')) ?: null, $mulai, $akhir, $nilai, $yakin,
            substr(trim((string) post('note', '')), 0, 255) ?: null, $status];

    if ($id > 0) {
        $cek = $pdo->prepare('SELECT id FROM prospects WHERE id = ? AND property_id = ? AND deleted_at IS NULL');
        $cek->execute([$id, $pid]);
        if (!$cek->fetchColumn()) { flash('Prospek tidak ditemukan.'); redirect_to('prospects'); }
        $arg[] = $id;
        $pdo->prepare('UPDATE prospects SET property_id=?, module=?, master_code=?, client_name=?, pic_name=?,
                              start_date=?, end_date=?, est_amount=?, confidence=?, note=?, status=?,
                              updated_at=NOW() WHERE id=?')->execute($arg);
        flash('Prospek diperbarui.');
    } else {
        $arg[] = clara_pelaku();
        $pdo->prepare('INSERT INTO prospects (property_id, module, master_code, client_name, pic_name,
                              start_date, end_date, est_amount, confidence, note, status, created_by)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute($arg);
        flash('Prospek dicatat. Angkanya muncul terpisah di Kalender — belum masuk pencapaian resmi.');
    }
    redirect_to('prospects', ['month' => substr($mulai, 0, 7)]);
}

function prospect_delete(PDO $pdo): void
{
    require_permission('manage_prospects');
    verify_csrf();
    $id = (int) post('id', 0);
    $pdo->prepare('UPDATE prospects SET deleted_at = NOW() WHERE id = ? AND property_id = ?')
        ->execute([$id, current_property_id()]);
    flash('Prospek dihapus.');
    redirect_to('prospects');
}
