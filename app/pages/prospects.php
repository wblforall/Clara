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
    $warna = prospek_warna_pic($pdo, $pid);

    layout($id ? 'Ubah Prospek' : 'Tambah Prospek', function () use ($row, $unit, $pics, $id, $warna) {
        $yakin = _prospek_yakin();
        ?>
        <style>
            /* Satu bahasa rupa dengan halaman Tagihan & Pembayaran: kotak putih,
               garis tipis, tanpa ikon dan tanpa gradasi. */
            .pr-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:14px}
            .pr-judul{font-size:21px;font-weight:800;color:#0F2A3F;line-height:1.2}
            .pr-sub{color:var(--muted);font-size:13px;margin-top:3px}
            .pr-catat{background:#FFFBEB;border:1px solid #FDE68A;border-radius:12px;padding:13px 18px;margin-bottom:14px;font-size:13px;line-height:1.55}
            .pr-kolom{display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:14px;align-items:start}
            .pr-kartu{background:#fff;border:1px solid var(--line);border-radius:12px}
            .pr-kartu h3{margin:0;padding:14px 20px;font-size:14px;font-weight:700;color:#1F2937;border-bottom:1px solid var(--line)}
            .pr-isi{padding:18px 20px}
            .pr-isi label{display:block;font-size:12px;font-weight:600;color:#475467;margin:14px 0 5px}
            .pr-isi label:first-child{margin-top:0}
            .pr-isi input,.pr-isi select,.pr-isi textarea{width:100%}
            .pr-dua{display:grid;grid-template-columns:1fr 1fr;gap:12px}
            .pr-bantu{font-size:11.5px;color:var(--muted);margin-top:5px;line-height:1.45}
            .pr-wajib{color:#dc2626}
            .pr-pilih{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px}
            .pr-pilih label{display:flex;align-items:center;gap:7px;margin:0;padding:8px 13px;border:1px solid var(--line);
                border-radius:9px;cursor:pointer;font-size:12.5px;font-weight:600;color:#475467;background:#fff}
            .pr-pilih input{width:auto;margin:0}
            .pr-pilih label:has(input:checked){border-color:#0D9488;background:#F0FDFA;color:#0F766E}
            .pr-aksi{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:18px;
                border-top:1px solid var(--line);padding-top:16px}
            .pr-lihat{background:#F8FAFC;border:1px solid var(--line);border-radius:10px;padding:12px 16px;font-size:12.5px;line-height:1.6}
            .pr-lihat b{color:#0F2A3F}
            .pr-kotak{display:inline-block;padding:2px 10px;border-radius:5px;font-weight:700;font-size:11.5px;font-style:italic;
                background:repeating-linear-gradient(45deg,#FEF9C3,#FEF9C3 5px,#FEF08A 5px,#FEF08A 10px);
                color:#854D0E;border:1px dashed #EAB308}
        </style>

        <?php /* Judul halamannya sudah dicetak layout(); di sini cukup keterangannya
                 supaya tidak ada dua judul yang sama bertumpuk. */ ?>
        <div class="pr-top">
            <div class="pr-sub" style="margin-top:0">
                <?= $id ? 'Mengubah calon yang sudah dicatat.' : 'Calon yang belum pasti &mdash; belum ada surat penawaran, belum tentu jadi.' ?>
            </div>
            <a class="btn light" href="?r=prospects">Kembali ke daftar</a>
        </div>

        <div class="pr-catat">
            Angka di sini <b>tidak</b> ikut ke pencapaian resmi, income, maupun laporan manajemen.
            Ia hanya ditampilkan terpisah &mdash; di Kalender sebagai kotak kuning, dan di panel atas sebagai
            &ldquo;kalau semua ini jadi, target bulan ini tercapai atau belum&rdquo;.
            Isi seadanya saja: yang wajib hanya nama calon penyewa, perkiraan tanggal, dan perkiraan nilainya.
        </div>

        <form method="post" action="?r=prospect_save" id="pr-form">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">

            <div class="pr-kolom">
                <div class="pr-kartu">
                    <h3>Calon penyewa</h3>
                    <div class="pr-isi">
                        <label>Nama calon penyewa <span class="pr-wajib">*</span></label>
                        <input name="client_name" id="pr-nama" value="<?= h($row['client_name']) ?>" maxlength="190" required
                               placeholder="mis. PT Sumber Rejeki / Kopi Kenangan" autocomplete="off">
                        <div class="pr-bantu">Boleh ditulis apa adanya &mdash; belum perlu terdaftar di Master Client.</div>

                        <label>Perkiraan nilai <span class="pr-wajib">*</span></label>
                        <input name="est_amount" id="pr-rp" inputmode="numeric" required placeholder="Rp"
                               value="<?= $row['est_amount'] !== '' ? h(number_format((float) $row['est_amount'], 0, ',', '.')) : '' ?>">
                        <div class="pr-bantu">Angka kasar pun tidak apa-apa &mdash; gunanya untuk menakar sisa target bulan ini.</div>

                        <label>PIC yang menangani</label>
                        <select name="pic_name" id="pr-pic">
                            <option value="">&mdash; belum ditentukan &mdash;</option>
                            <?php foreach ($pics as $p): ?><option <?= $row['pic_name'] === $p ? 'selected' : '' ?>><?= h($p) ?></option><?php endforeach; ?>
                        </select>
                        <div class="pr-bantu">Dipakai untuk menjumlahkan prospek per orang di panel Kalender.</div>

                        <label>Seberapa yakin</label>
                        <div class="pr-pilih">
                            <?php foreach ($yakin as $k => $y): ?>
                                <label title="<?= h($y[1]) ?>">
                                    <input type="radio" name="confidence" value="<?= h($k) ?>" <?= $row['confidence'] === $k ? 'checked' : '' ?>>
                                    <?= h($y[0]) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="pr-bantu" id="pr-yakin-arti"></div>
                    </div>
                </div>

                <div class="pr-kartu">
                    <h3>Tempat &amp; waktu</h3>
                    <div class="pr-isi">
                        <div class="pr-dua">
                            <div>
                                <label>Modul</label>
                                <select name="module" id="pr-mod">
                                    <?php foreach (['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'] as $k => $v): ?>
                                        <option value="<?= h($k) ?>" <?= $row['module'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Unit / titik</label>
                                <select name="master_code" id="pr-unit"></select>
                            </div>
                        </div>
                        <div class="pr-bantu">Kalau sudah ada incarannya, pilih di sini &mdash; kotak kuningnya akan muncul tepat di baris unit itu.
                            Boleh dikosongkan; nilainya tetap ikut dijumlahkan.</div>

                        <div class="pr-dua">
                            <div><label>Perkiraan mulai <span class="pr-wajib">*</span></label>
                                <input type="date" name="start_date" id="pr-mulai" value="<?= h($row['start_date']) ?>" required></div>
                            <div><label>Perkiraan selesai <span class="pr-wajib">*</span></label>
                                <input type="date" name="end_date" id="pr-selesai" value="<?= h($row['end_date']) ?>" required></div>
                        </div>
                        <div class="pr-bantu" id="pr-lama"></div>

                        <label>Catatan</label>
                        <input name="note" value="<?= h((string) $row['note']) ?>" maxlength="255" placeholder="mis. menunggu keputusan pusat">

                        <?php if ($id): ?>
                        <label>Status</label>
                        <select name="status">
                            <option value="open" <?= $row['status'] === 'open' ? 'selected' : '' ?>>Masih berjalan &mdash; ikut dihitung sebagai prospek</option>
                            <option value="deal" <?= $row['status'] === 'deal' ? 'selected' : '' ?>>Sudah jadi penawaran &mdash; berhenti dihitung</option>
                            <option value="batal" <?= $row['status'] === 'batal' ? 'selected' : '' ?>>Tidak jadi &mdash; berhenti dihitung</option>
                        </select>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php /* Pratinjau kecil: memperlihatkan seperti apa isian ini nanti
                     terlihat di Kalender, supaya yang mengisi tidak perlu menebak. */ ?>
            <div class="pr-lihat" style="margin-top:14px">
                <div style="font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#98A2B3;margin-bottom:7px">Nanti terlihat seperti ini di Kalender</div>
                <span class="pr-kotak" id="pr-contoh">Nama calon penyewa</span>
                <span id="pr-ket" style="margin-left:10px;color:var(--muted)"></span>
            </div>

            <div class="pr-aksi">
                <button type="submit">Simpan prospek</button>
                <a class="btn secondary" href="?r=prospects">Batal</a>
                <?php if ($id): ?>
                    <button type="submit" formaction="?r=prospect_delete" formnovalidate class="btn light"
                            style="color:#B91C1C;margin-left:auto" onclick="return confirm('Hapus prospek ini?')">Hapus prospek</button>
                <?php endif; ?>
            </div>
        </form>

        <script>
        (function () {
            var UNIT = <?= json_encode($unit, JSON_UNESCAPED_UNICODE) ?>,
                TERPILIH = <?= json_encode((string) $row['master_code']) ?>,
                ARTI = <?= json_encode(array_map(fn($y) => $y[1], _prospek_yakin()), JSON_UNESCAPED_UNICODE) ?>,
                WARNA = <?= json_encode($warna, JSON_UNESCAPED_UNICODE) ?>;
            var mod = document.getElementById('pr-mod'), unit = document.getElementById('pr-unit');
            function isiUnit() {
                var d = UNIT[mod.value] || [];
                unit.innerHTML = '<option value="">— belum ditentukan —</option>';
                d.forEach(function (u) {
                    var o = document.createElement('option');
                    o.value = u.code; o.textContent = u.lbl;
                    if (u.code === TERPILIH) o.selected = true;
                    unit.appendChild(o);
                });
            }
            mod.addEventListener('change', function () { TERPILIH = ''; isiUnit(); lihat(); });
            isiUnit();

            // Ribuan diberi titik sambil diketik — sama seperti kotak rupiah lain.
            var rp = document.getElementById('pr-rp');
            rp.addEventListener('input', function () {
                var a = this.value.replace(/\D/g, '');
                this.value = a ? parseInt(a, 10).toLocaleString('id-ID') : '';
                lihat();
            });

            function lamaHari() {
                var a = document.getElementById('pr-mulai').value, b = document.getElementById('pr-selesai').value;
                if (!a || !b) return 0;
                var n = Math.round((new Date(b) - new Date(a)) / 86400000) + 1;
                return n > 0 ? n : 0;
            }
            function lihat() {
                var nama = (document.getElementById('pr-nama').value || '').trim() || 'Nama calon penyewa';
                var angka = parseInt((rp.value || '').replace(/\D/g, ''), 10) || 0;
                var kotak = document.getElementById('pr-contoh');
                kotak.textContent = nama + (angka ? '   Rp ' + angka.toLocaleString('id-ID') : '');
                var pic = document.getElementById('pr-pic').value;
                var n = lamaHari();
                document.getElementById('pr-lama').textContent = n ? 'Lamanya ' + n + ' hari.' : '';
                var u = unit.value ? unit.options[unit.selectedIndex].textContent : 'unit yang belum ditentukan';
                document.getElementById('pr-ket').textContent = 'di ' + u + (pic ? ' · ' + pic : '') + (n ? ' · ' + n + ' hari' : '');
                var ck = document.querySelector('input[name=confidence]:checked');
                document.getElementById('pr-yakin-arti').textContent = ck ? ARTI[ck.value] || '' : '';
            }
            ['pr-nama', 'pr-mulai', 'pr-selesai', 'pr-pic'].forEach(function (id) {
                var el = document.getElementById(id);
                el.addEventListener('input', lihat); el.addEventListener('change', lihat);
            });
            unit.addEventListener('change', lihat);
            document.querySelectorAll('input[name=confidence]').forEach(function (el) { el.addEventListener('change', lihat); });
            lihat();
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
