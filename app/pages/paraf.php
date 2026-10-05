<?php

declare(strict_types=1);

/**
 * "Paraf Saya" — pemeriksa menyiapkan paraf sekali, dipakai seterusnya.
 *
 * Dua hal yang diatur: BENTUK paraf (diunggah, digambar sendiri dengan mouse,
 * atau diketik sebagai inisial) dan LETAKNYA.
 *
 * Letaknya punya dua mode:
 *
 *  - OTOMATIS (bawaan). Paraf ditempatkan di kanan atas QR Manager pada blok
 *    tanda tangan, dekat tetapi tidak bertabrakan. Tidak ada koordinat yang
 *    perlu diisi, dan satu pengaturan ini langsung berlaku untuk SKP, SKS
 *    Gudang, maupun Form Utilities — termasuk template yang dibuat nanti.
 *    Dokumennya sendiri yang menentukan titiknya saat dicetak, jadi posisinya
 *    tidak pernah meleset walau panjang isi dokumen berubah-ubah.
 *
 *  - MANUAL. Untuk yang ingin menaruh paraf di tempat lain: digeser sendiri di
 *    atas pratinjau kertas A4, diatur per jenis dokumen, disimpan dalam
 *    milimeter.
 *
 * Apa pun modenya, pengaturan itu DIBEKUKAN ke dokumen saat orangnya memaraf
 * (lihat ApprovalLine::jejakBeku), sehingga dokumen yang sudah diserahkan ke
 * client tidak ikut berubah bila pengaturannya diubah kemudian.
 */

require_once dirname(__DIR__) . '/Paraf.php';

function _paraf_dir(): string { return dirname(__DIR__, 2) . '/public/uploads/signatures'; }

function _paraf_jenis(string $v): string
{
    return array_key_exists($v, Paraf::JENIS) ? $v : 'skp';
}

/**
 * Simpan gambar paraf dari coretan kanvas ATAU dari berkas yang diunggah.
 * Mengembalikan path relatif, atau null bila tidak ada gambar baru.
 */
function _paraf_simpan_gambar(int $uid, string $jenis): ?string
{
    $dir = _paraf_dir();

    // (a) Coretan dari kanvas — dikirim sebagai data URL PNG.
    $data = (string) post('gambar_data', '');
    if (str_starts_with($data, 'data:image/png;base64,')) {
        $bin = base64_decode(substr($data, 22), true);
        if ($bin === false || strlen($bin) < 100 || strlen($bin) > 2 * 1024 * 1024) return null;
        // Isinya diperiksa, bukan sekadar awalan string-nya.
        if (substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $f = 'paraf_u' . $uid . '_' . $jenis . '_' . bin2hex(random_bytes(4)) . '.png';
        return @file_put_contents($dir . '/' . $f, $bin) !== false ? 'uploads/signatures/' . $f : null;
    }

    // (b) Berkas yang diunggah.
    if (empty($_FILES['gambar']['tmp_name']) || !is_uploaded_file($_FILES['gambar']['tmp_name'])) return null;
    $f = $_FILES['gambar'];
    if ($f['size'] <= 0 || $f['size'] > 2 * 1024 * 1024) { flash('Ukuran gambar maksimal 2 MB.'); return null; }
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) { flash('Format gambar harus PNG/JPG/WEBP.'); return null; }
    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
        flash('Berkas itu bukan gambar yang sah.'); return null;
    }
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $nama = 'paraf_u' . $uid . '_' . $jenis . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    return @move_uploaded_file($f['tmp_name'], $dir . '/' . $nama) ? 'uploads/signatures/' . $nama : null;
}

function my_paraf_page(PDO $pdo): void
{
    require_permission('approve_skp');
    $uid = (int) ($_SESSION['user']['id'] ?? 0);

    // Mode manual diatur per jenis dokumen; mode otomatis satu untuk semuanya.
    $jenis   = _paraf_jenis((string) getv('doc_type', 'skp'));
    $bersama = Paraf::ambilPersis($pdo, $uid, Paraf::SEMUA);
    $khusus  = Paraf::ambilPersis($pdo, $uid, $jenis);
    // Mode yang ditampilkan: ikuti ?mode= bila diminta; kalau tidak, tebak dari
    // apa yang sudah pernah disimpan — baris bersama berarti otomatis, baris
    // per jenis dokumen berarti manual, belum ada apa-apa berarti bawaan.
    if (getv('mode')) {
        $mode = getv('mode') === 'manual' ? 'manual' : 'otomatis';
    } elseif ($bersama) {
        $mode = 'otomatis';
    } elseif ($khusus) {
        $mode = 'manual';
    } else {
        $mode = 'otomatis';
    }
    $set = $mode === 'otomatis' ? ($bersama ?: Paraf::bawaan()) : ($khusus ?: ($bersama ?: Paraf::bawaan()));
    $semua   = Paraf::semua($pdo, $uid);

    require_once dirname(__DIR__) . '/ApprovalLine.php';
    $jabatan = ApprovalLine::jabatan($pdo, current_property_id());
    $nama    = (string) ($_SESSION['user']['name'] ?? '');

    layout('Paraf Saya', function () use ($set, $semua, $jenis, $mode, $nama, $jabatan, $bersama) {
        $siapBersama = Paraf::siap($bersama);
        ?>
        <div class="panel">
            <h2 style="margin-top:0">Paraf Saya</h2>
            <p class="help" style="margin-top:0">Atur <strong>sekali</strong> bentuk paraf Anda. Setiap kali Anda menekan
               <strong>Paraf &amp; Teruskan</strong> atau <strong>Setujui</strong>, paraf itu terpasang otomatis di dokumen &mdash;
               tidak perlu diatur lagi, dan bertahan sampai Anda sendiri mengubah atau mereset-nya.</p>

            <?php if ($mode === 'otomatis' && $siapBersama): ?>
            <div class="panel" style="background:#f0fdf4;border:1px solid #bbf7d0;margin-bottom:12px">
                <p style="margin:0;color:#166534">Paraf Anda sudah aktif dan <strong>berlaku untuk semua jenis surat</strong> &mdash;
                   SKP Pameran, SKS Gudang, Form Utilities, termasuk template baru nanti.</p>
            </div>
            <?php elseif ($mode === 'otomatis'): ?>
            <div class="panel" style="background:#fffbeb;border:1px solid #fde68a;margin-bottom:12px">
                <p style="margin:0;color:#92400e">Paraf belum diatur. Selama belum diatur, paraf Anda tetap tercatat di dokumen
                   sebagai <em>baris teks</em> &ldquo;Diperiksa sebelum disetujui&rdquo; &mdash; dokumennya tetap sah,
                   hanya tidak ada gambar parafnya.</p>
            </div>
            <?php endif; ?>

            <form method="post" action="?r=my_paraf_save" enctype="multipart/form-data" id="paraf-form">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="doc_type" value="<?= h($jenis) ?>">
                <input type="hidden" name="gambar_data" id="inp-data" value="">

                <div style="font-size:12px;font-weight:700;margin-bottom:6px">Letak paraf di dokumen</div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
                    <label class="pilihmode" style="flex:1;min-width:270px;max-width:420px;display:flex;gap:9px;align-items:flex-start;
                               border:1px solid #e2e8f0;background:#fff;border-radius:8px;padding:10px 12px;cursor:pointer;font-weight:400">
                        <input type="radio" name="mode" value="otomatis" style="margin-top:2px" <?= $mode === 'otomatis' ? 'checked' : '' ?>>
                        <span style="font-size:13px"><strong>Otomatis</strong> &mdash; di kanan atas QR Manager
                            <span class="help" style="display:block;margin-top:2px">Berlaku untuk <strong>semua jenis surat</strong> sekaligus.
                            Tidak perlu mengatur posisi, dan tidak akan menimpa QR.</span></span>
                    </label>
                    <label class="pilihmode" style="flex:1;min-width:270px;max-width:420px;display:flex;gap:9px;align-items:flex-start;
                               border:1px solid #e2e8f0;background:#fff;border-radius:8px;padding:10px 12px;cursor:pointer;font-weight:400">
                        <input type="radio" name="mode" value="manual" style="margin-top:2px" <?= $mode === 'manual' ? 'checked' : '' ?>>
                        <span style="font-size:13px"><strong>Manual</strong> &mdash; saya tentukan sendiri titiknya
                            <span class="help" style="display:block;margin-top:2px">Digeser di atas pratinjau kertas, dan
                            diatur <strong>per jenis dokumen</strong>.</span></span>
                    </label>
                </div>

                <div id="blok-jenis" style="margin-bottom:14px">
                    <div style="font-size:12px;font-weight:700;margin-bottom:6px">Jenis dokumen yang diatur</div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <?php foreach (Paraf::JENIS as $k => $l): ?>
                        <a class="btn <?= $k === $jenis ? '' : 'light' ?>" href="?r=my_paraf&mode=manual&doc_type=<?= $k ?>">
                            <?= h($l) ?><?= Paraf::siap($semua[$k] ?? null) ? ' &check;' : '' ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="display:flex;gap:22px;flex-wrap:wrap;align-items:flex-start">
                    <div>
                        <?php /* Mode otomatis: tiruan blok tanda tangan, supaya hubungan
                                 paraf dengan QR terlihat tanpa membuka dokumen. */ ?>
                        <div id="pratinjau-auto">
                            <div style="font-size:12px;font-weight:700;margin-bottom:6px">Hasilnya di blok tanda tangan</div>
                            <?php
                            /* Tiruan ini memakai PROPORSI DOKUMEN yang sebenarnya: lebar isi
                               A4 178mm dipetakan ke 430px, QR 18mm di tengah kolom, paraf
                               mulai Paraf::OTO_GESER mm dari tepi kiri kolom. Angkanya
                               diturunkan dari konstanta yang sama dengan yang dipakai saat
                               mencetak, jadi tiruan ini tidak bisa menyimpang dari hasil asli. */
                            $PX   = 560 / 178;                       // px per mm
                            $qrPx = round(18 * $PX);                 // QR 18mm
                            $sigPx = round(20.64 * $PX);             // tinggi area tanda tangan (78px di dokumen)
                            $kiri = round(Paraf::OTO_GESER * $PX);   // awal paraf dari tepi kolom
                            $pad  = round(1.5875 * $PX, 1);          // padding kolom (6px di dokumen)
                            ?>
                            <div style="width:560px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;padding:16px 0">
                                <table style="width:560px;table-layout:fixed;border-collapse:collapse">
                                    <tr>
                                        <td style="width:33.33%;text-align:center;vertical-align:top;padding:0 <?= $pad ?>px">
                                            <div style="font-size:11px;margin-bottom:5px">Dibuat Oleh,</div>
                                            <div style="height:<?= $sigPx ?>px">
                                                <div style="width:<?= $qrPx ?>px;height:<?= $qrPx ?>px;margin:0 auto;background:repeating-conic-gradient(#111 0 25%, #fff 0 50%) 0 0/8px 8px"></div>
                                                <div style="font-size:7.5px;color:#94a3b8;margin-top:2px">Scan untuk validasi</div>
                                            </div>
                                            <div style="font-size:11px;font-weight:700">Sales</div>
                                        </td>
                                        <td style="width:33.33%;text-align:center;vertical-align:top;padding:0 <?= $pad ?>px">
                                            <div style="font-size:11px;margin-bottom:5px">Mengetahui,</div>
                                            <?php /* position:relative hanya pembungkus — paraf melayang di
                                                     atasnya dan TIDAK ikut menghitung tinggi, persis seperti
                                                     di dokumen. QR tetap di tengah kolom. */ ?>
                                            <div style="position:relative">
                                                <div style="height:<?= $sigPx ?>px">
                                                    <div style="width:<?= $qrPx ?>px;height:<?= $qrPx ?>px;margin:0 auto;background:repeating-conic-gradient(#111 0 25%, #fff 0 50%) 0 0/8px 8px"></div>
                                                    <div style="font-size:7.5px;color:#94a3b8;margin-top:2px">Scan untuk validasi</div>
                                                </div>
                                                <div id="auto-paraf" style="position:absolute;left:<?= $kiri ?>px;top:-1px;text-align:left"></div>
                                            </div>
                                            <div style="font-size:11px;font-weight:700">Manager</div>
                                        </td>
                                        <td style="width:33.33%;text-align:center;vertical-align:top;padding:0 <?= $pad ?>px">
                                            <div style="font-size:11px;margin-bottom:5px">Menyetujui,</div>
                                            <div style="height:<?= $sigPx ?>px"><div style="height:<?= $sigPx - 6 ?>px;border-bottom:1px solid #111"></div></div>
                                            <div style="font-size:11px;font-weight:700">Client</div>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            <div class="help" style="margin-top:5px">Paraf Anda <strong>menindih</strong> ruang kosong di kanan atas QR
                                &mdash; seperti gambar di-depan-teks pada Word. QR dan nama di bawahnya <strong>tidak bergeser
                                sedikit pun</strong>, dan posisinya sama di semua jenis surat.</div>
                        </div>

                        <div id="pratinjau-manual" style="display:none">
                            <div style="font-size:12px;font-weight:700;margin-bottom:6px">Geser kotak ke posisi paraf Anda</div>
                            <div id="kertas" style="position:relative;width:430px;height:608px;background:#fff;
                                        border:1px solid #cbd5e1;border-radius:4px;box-shadow:0 2px 12px rgba(0,0,0,.09);
                                        overflow:hidden;cursor:crosshair;user-select:none">
                                <img src="assets/letterhead-a4.jpg" alt="" style="position:absolute;inset:0;width:100%;height:100%;object-fit:fill;opacity:.55;pointer-events:none">
                                <div style="position:absolute;left:35px;right:35px;top:62px;bottom:74px;border:1px dashed #cbd5e1;pointer-events:none"></div>
                                <div style="position:absolute;left:35px;right:35px;top:245px;height:76px;border:1px dashed #fca5a5;background:rgba(254,226,226,.3);pointer-events:none">
                                    <span style="position:absolute;top:2px;left:5px;font-size:9.5px;color:#b91c1c">perkiraan blok tanda tangan &amp; QR</span>
                                </div>
                                <div id="paraf-kotak" style="position:absolute;border:1px dashed #0d9488;background:rgba(13,148,136,.08);border-radius:3px;cursor:grab;padding:1px">
                                    <div id="paraf-isi" style="pointer-events:none"></div>
                                </div>
                            </div>
                            <div class="help" style="margin-top:5px">Kotak merah = perkiraan blok tanda tangan; hindari menimpanya.
                                Paraf dipasang di <strong>halaman terakhir</strong> dokumen.</div>
                        </div>
                    </div>

                    <div style="flex:1;min-width:300px;max-width:430px">
                        <label style="font-size:12px;font-weight:700">Bentuk paraf</label>
                        <div style="display:flex;gap:16px;margin:6px 0 12px;white-space:nowrap;flex-wrap:wrap">
                            <label style="font-weight:400;display:flex;gap:6px;align-items:center;cursor:pointer">
                                <input type="radio" name="bentuk" value="gambar" <?= ($set['bentuk'] ?? 'gambar') !== 'inisial' ? 'checked' : '' ?>> Gambar
                            </label>
                            <label style="font-weight:400;display:flex;gap:6px;align-items:center;cursor:pointer">
                                <input type="radio" name="bentuk" value="inisial" <?= ($set['bentuk'] ?? '') === 'inisial' ? 'checked' : '' ?>> Inisial (ketik)
                            </label>
                        </div>

                        <div id="blok-gambar">
                            <?php if (!empty($set['gambar_path'])): ?>
                            <div style="margin-bottom:9px">
                                <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Paraf tersimpan saat ini:</div>
                                <img src="<?= h(upload_url((string) $set['gambar_path'])) ?>" alt="paraf"
                                     style="max-height:62px;max-width:190px;border:1px solid var(--line);border-radius:6px;background:#fff;padding:5px">
                            </div>
                            <?php endif; ?>

                            <div style="display:flex;gap:8px;margin-bottom:9px">
                                <button type="button" class="btn light" id="tab-tulis" style="font-size:12.5px">&#9997; Gambar sendiri</button>
                                <button type="button" class="btn light" id="tab-unggah" style="font-size:12.5px">&#128193; Unggah berkas</button>
                            </div>

                            <div id="blok-tulis">
                                <div class="help" style="margin-bottom:4px">Tahan tombol kiri mouse lalu tarik untuk menggambar paraf Anda.
                                    Di HP bisa pakai jari.</div>
                                <canvas id="kanvas" width="860" height="300"
                                        style="width:100%;max-width:430px;height:150px;border:1px dashed #94a3b8;border-radius:8px;
                                               background:#fff;cursor:crosshair;touch-action:none;display:block"></canvas>
                                <div style="display:flex;gap:8px;align-items:center;margin:7px 0 12px;flex-wrap:wrap">
                                    <button type="button" class="btn light" id="kanvas-hapus" style="font-size:12px">Hapus &amp; ulangi</button>
                                    <label style="font-weight:400;font-size:12px;display:flex;gap:6px;align-items:center">Tebal
                                        <input type="range" id="kanvas-tebal" min="2" max="12" step="1" value="5" style="width:110px"></label>
                                </div>
                            </div>

                            <div id="blok-unggah" style="display:none">
                                <label style="font-size:12px;font-weight:700">Pilih berkas gambar (PNG latar transparan paling rapi, maks 2 MB)</label>
                                <input type="file" name="gambar" accept="image/png,image/jpeg,image/webp" id="inp-gambar" style="display:block;margin:6px 0 12px">
                            </div>
                        </div>

                        <div id="blok-inisial" style="display:none">
                            <label style="font-size:12px;font-weight:700">Tulisan inisial</label>
                            <input name="teks" id="inp-teks" maxlength="40" placeholder="mis. YY"
                                   value="<?= h((string) ($set['teks'] ?? '')) ?>" style="margin:6px 0 12px">
                        </div>

                        <label style="font-size:12px;font-weight:700">Lebar paraf: <span id="lbl-lebar"></span> mm</label>
                        <input type="range" name="lebar" id="inp-lebar" min="10" max="80" step="1"
                               value="<?= (int) round((float) ($set['lebar'] ?? 12)) ?>" style="width:100%;margin:6px 0 12px">

                        <div id="blok-koordinat" style="display:flex;gap:10px;margin-bottom:12px">
                            <div style="flex:1">
                                <label style="font-size:12px;font-weight:700">Dari kiri (mm)</label>
                                <input type="number" name="pos_x" id="inp-x" min="0" max="210" step="0.5"
                                       value="<?= h((string) round((float) ($set['pos_x'] ?? 150), 1)) ?>">
                            </div>
                            <div style="flex:1">
                                <label style="font-size:12px;font-weight:700">Dari atas (mm)</label>
                                <input type="number" name="pos_y" id="inp-y" min="0" max="297" step="0.5"
                                       value="<?= h((string) round((float) ($set['pos_y'] ?? 232), 1)) ?>">
                            </div>
                        </div>

                        <div style="font-size:12px;font-weight:700;margin-bottom:5px">Yang ikut tercetak di bawah paraf</div>
                        <label style="font-weight:400;display:flex;gap:7px;align-items:center;cursor:pointer;margin-bottom:5px">
                            <input type="checkbox" name="tampil_nama" value="1" id="inp-nama" <?= !empty($set['tampil_nama']) ? 'checked' : '' ?>>
                            <span style="font-size:13px">Nama &amp; jabatan</span>
                        </label>
                        <label style="font-weight:400;display:flex;gap:7px;align-items:center;cursor:pointer;margin-bottom:14px">
                            <input type="checkbox" name="tampil_waktu" value="1" id="inp-waktu" <?= !empty($set['tampil_waktu']) ? 'checked' : '' ?>>
                            <span style="font-size:13px">Tanggal &amp; jam</span>
                        </label>

                        <button type="submit">&#128190; Simpan Paraf</button>
                        <?php if (Paraf::siap($set)): ?>
                        <a class="btn warn" href="?r=my_paraf_reset&doc_type=<?= h($mode === 'otomatis' ? Paraf::SEMUA : $jenis) ?>&_csrf=<?= csrf_token() ?>"
                           onclick="return confirm('Reset paraf ini? Dokumen yang sudah terbit tidak berubah.')">Reset</a>
                        <?php endif; ?>

                        <p class="help" style="margin-top:14px">Dokumen yang <strong>sudah terbit</strong> tidak ikut berubah saat Anda
                           mengubah pengaturan ini &mdash; bentuk dan posisi parafnya sudah dikunci di dokumen itu sejak Anda memarafnya.</p>
                    </div>
                </div>
            </form>
        </div>

        <script>
        (function () {
            var SKALA = 430 / 210;
            var kertas = document.getElementById('kertas');
            var kotak  = document.getElementById('paraf-kotak');
            var isi    = document.getElementById('paraf-isi');
            var auto   = document.getElementById('auto-paraf');
            var ix = document.getElementById('inp-x'), iy = document.getElementById('inp-y');
            var il = document.getElementById('inp-lebar'), ll = document.getElementById('lbl-lebar');
            var it = document.getElementById('inp-teks'), ig = document.getElementById('inp-gambar');
            var inm = document.getElementById('inp-nama'), iw = document.getElementById('inp-waktu');
            var idata = document.getElementById('inp-data');
            var NAMA = <?= json_encode(trim($nama . ($jabatan !== '' ? ' · ' . $jabatan : '')), JSON_UNESCAPED_UNICODE) ?>;
            var GBR  = <?= json_encode(!empty($set['gambar_path']) ? upload_url((string) $set['gambar_path']) : '', JSON_UNESCAPED_UNICODE) ?>;
            var MAKS_AUTO = <?= Paraf::LEBAR_OTOMATIS_MAKS ?>;
            var PX = 560 / 178;   // skala tiruan blok tanda tangan (px per mm)
            var gbrBaru = '';

            function mode()   { var r = document.querySelector('input[name=mode]:checked');   return r ? r.value : 'otomatis'; }
            function bentuk() { var r = document.querySelector('input[name=bentuk]:checked'); return r ? r.value : 'gambar'; }
            function jepit(v, min, max) { return Math.max(min, Math.min(max, v)); }

            // ── Kanvas: menggambar paraf dengan mouse / jari ──────────────────
            var kv = document.getElementById('kanvas');
            var ctx = kv.getContext('2d');
            var adaCoretan = false, menggambar = false, xx = 0, yy = 0;
            ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#172554';
            function tebal() { return (parseInt(document.getElementById('kanvas-tebal').value, 10) || 5) * 2; }
            function titik(e) {
                var r = kv.getBoundingClientRect();
                var t = e.touches ? e.touches[0] : e;
                // Kanvas digambar pada resolusi 2x ukuran tampilnya supaya hasilnya
                // tetap tajam saat dicetak, jadi koordinatnya ikut diskalakan.
                return { x: (t.clientX - r.left) * (kv.width / r.width), y: (t.clientY - r.top) * (kv.height / r.height) };
            }
            function mulai(e) { menggambar = true; var p = titik(e); xx = p.x; yy = p.y; e.preventDefault(); }
            function tarik(e) {
                if (!menggambar) return;
                var p = titik(e);
                ctx.lineWidth = tebal();
                ctx.beginPath(); ctx.moveTo(xx, yy); ctx.lineTo(p.x, p.y); ctx.stroke();
                xx = p.x; yy = p.y; adaCoretan = true; e.preventDefault();
            }
            function henti() {
                if (!menggambar) return;
                menggambar = false;
                if (adaCoretan) { gbrBaru = potongKanvas(); idata.value = gbrBaru; gambarUlang(); }
            }
            kv.addEventListener('mousedown', mulai);  kv.addEventListener('touchstart', mulai, { passive: false });
            kv.addEventListener('mousemove', tarik);  kv.addEventListener('touchmove', tarik, { passive: false });
            document.addEventListener('mouseup', henti); kv.addEventListener('touchend', henti);

            // Buang ruang kosong di sekeliling coretan — tanpa ini paraf tampil
            // kecil di tengah kotak besar dan lebarnya tidak sesuai setelan.
            function potongKanvas() {
                var d = ctx.getImageData(0, 0, kv.width, kv.height).data;
                var x1 = kv.width, y1 = kv.height, x2 = 0, y2 = 0, ada = false;
                for (var y = 0; y < kv.height; y++) {
                    for (var x = 0; x < kv.width; x++) {
                        if (d[(y * kv.width + x) * 4 + 3] > 8) {
                            ada = true;
                            if (x < x1) x1 = x;
                            if (x > x2) x2 = x;
                            if (y < y1) y1 = y;
                            if (y > y2) y2 = y;
                        }
                    }
                }
                if (!ada) return '';
                var m = 8;
                x1 = Math.max(0, x1 - m); y1 = Math.max(0, y1 - m);
                x2 = Math.min(kv.width - 1, x2 + m); y2 = Math.min(kv.height - 1, y2 + m);
                var c = document.createElement('canvas');
                c.width = x2 - x1 + 1; c.height = y2 - y1 + 1;
                c.getContext('2d').drawImage(kv, x1, y1, c.width, c.height, 0, 0, c.width, c.height);
                return c.toDataURL('image/png');
            }
            document.getElementById('kanvas-hapus').addEventListener('click', function () {
                ctx.clearRect(0, 0, kv.width, kv.height);
                adaCoretan = false; gbrBaru = ''; idata.value = ''; gambarUlang();
            });

            function caraGambar(c) {
                document.getElementById('blok-tulis').style.display  = c === 'tulis'  ? '' : 'none';
                document.getElementById('blok-unggah').style.display = c === 'unggah' ? '' : 'none';
                document.getElementById('tab-tulis').className  = c === 'tulis'  ? 'btn' : 'btn light';
                document.getElementById('tab-unggah').className = c === 'unggah' ? 'btn' : 'btn light';
            }
            document.getElementById('tab-tulis').addEventListener('click', function () { caraGambar('tulis'); });
            document.getElementById('tab-unggah').addEventListener('click', function () { caraGambar('unggah'); });
            caraGambar('tulis');

            function potonganParaf(lebarMm, pxPerMm) {
                var html = '';
                if (bentuk() === 'inisial') {
                    var t = (it && it.value ? it.value : '').trim() || 'YY';
                    html = '<div style="font-style:italic;font-weight:700;color:#1e3a8a;line-height:1.1;font-size:'
                         + (Math.max(9, lebarMm * 0.42) * 25.4 / 72 * pxPerMm) + 'px">' + t.replace(/[<>&]/g, '') + '</div>';
                } else {
                    var src = gbrBaru || GBR;
                    html = src
                        ? '<img src="' + src + '" style="width:' + (lebarMm * pxPerMm) + 'px;display:block">'
                        : '<div style="height:' + Math.round(lebarMm * pxPerMm * 0.5) + 'px;border:1px dashed #cbd5e1;'
                          + 'border-radius:3px;background:rgba(148,163,184,.08)" title="belum ada gambar"></div>';
                }
                var ket = [];
                if (inm.checked) ket.push(NAMA);
                if (iw.checked)  ket.push('05/10/2026 09:15');
                // 6pt — ukuran yang dipakai Paraf::html() saat mencetak, diubah ke piksel
                // menurut skala tiruan, supaya baris yang membungkus di sini juga
                // membungkus di dokumen.
                if (ket.length) html += '<div style="font-size:' + (6 * 25.4 / 72 * pxPerMm) + 'px;color:#475569;'
                                      + 'line-height:1.25;margin-top:' + (0.6 * pxPerMm) + 'px">' + ket.join('<br>') + '</div>';
                return html;
            }

            function gambarUlang() {
                var otomatis = mode() === 'otomatis';
                document.getElementById('pratinjau-auto').style.display   = otomatis ? '' : 'none';
                document.getElementById('pratinjau-manual').style.display = otomatis ? 'none' : '';
                document.getElementById('blok-koordinat').style.display   = otomatis ? 'none' : 'flex';
                document.getElementById('blok-jenis').style.display       = otomatis ? 'none' : '';
                document.querySelectorAll('.pilihmode').forEach(function (l) {
                    var on = l.querySelector('input').checked;
                    l.style.borderColor = on ? '#0d9488' : '#e2e8f0';
                    l.style.background  = on ? '#f0fdfa' : '#fff';
                });

                // Mode otomatis: ruang di samping QR terbatas, lebarnya dibatasi.
                il.max = otomatis ? MAKS_AUTO : 80;
                if (otomatis && parseFloat(il.value) > MAKS_AUTO) il.value = MAKS_AUTO;
                var lebar = parseFloat(il.value) || 20;
                ll.textContent = lebar;

                document.getElementById('blok-gambar').style.display  = bentuk() === 'gambar'  ? '' : 'none';
                document.getElementById('blok-inisial').style.display = bentuk() === 'inisial' ? '' : 'none';

                if (otomatis) {
                    auto.style.width = (lebar * PX) + 'px';
                    auto.innerHTML = potonganParaf(lebar, PX);
                    return;
                }

                var x = jepit(parseFloat(ix.value) || 0, 0, 210 - lebar);
                var y = jepit(parseFloat(iy.value) || 0, 0, 297 - 8);
                ix.value = Math.round(x * 2) / 2;
                iy.value = Math.round(y * 2) / 2;
                kotak.style.left  = (x * SKALA) + 'px';
                kotak.style.top   = (y * SKALA) + 'px';
                kotak.style.width = (lebar * SKALA) + 'px';
                isi.innerHTML = potonganParaf(lebar, SKALA);
            }

            var geser = false, dx = 0, dy = 0;
            kotak.addEventListener('mousedown', function (e) {
                geser = true; kotak.style.cursor = 'grabbing';
                var k = kotak.getBoundingClientRect();
                dx = e.clientX - k.left; dy = e.clientY - k.top;
                e.preventDefault();
            });
            document.addEventListener('mousemove', function (e) {
                if (!geser) return;
                var p = kertas.getBoundingClientRect();
                ix.value = ((e.clientX - p.left - dx) / SKALA).toFixed(1);
                iy.value = ((e.clientY - p.top  - dy) / SKALA).toFixed(1);
                gambarUlang();
            });
            document.addEventListener('mouseup', function () { geser = false; kotak.style.cursor = 'grab'; });
            kertas.addEventListener('click', function (e) {
                if (e.target.closest('#paraf-kotak')) return;
                var p = kertas.getBoundingClientRect();
                var lebar = parseFloat(il.value) || 20;
                ix.value = ((e.clientX - p.left) / SKALA - lebar / 2).toFixed(1);
                iy.value = ((e.clientY - p.top) / SKALA - 4).toFixed(1);
                gambarUlang();
            });

            if (ig) ig.addEventListener('change', function () {
                var f = ig.files && ig.files[0];
                if (!f) { gbrBaru = ''; gambarUlang(); return; }
                idata.value = '';                     // berkas menang atas coretan
                var fr = new FileReader();
                fr.onload = function () { gbrBaru = fr.result; gambarUlang(); };
                fr.readAsDataURL(f);
            });

            ['change', 'input'].forEach(function (ev) {
                [ix, iy, il, it, inm, iw].forEach(function (el) { if (el) el.addEventListener(ev, gambarUlang); });
                document.querySelectorAll('input[name=bentuk],input[name=mode]').forEach(function (el) { el.addEventListener(ev, gambarUlang); });
            });
            gambarUlang();
        })();
        </script>
        <?php
    });
}

function my_paraf_save(PDO $pdo): void
{
    require_permission('approve_skp');
    verify_csrf();
    $uid = (int) ($_SESSION['user']['id'] ?? 0);
    if (!$uid) { flash('Sesi tidak valid.'); redirect_to('my_paraf'); }

    $mode  = post('mode') === 'manual' ? 'manual' : 'otomatis';
    $jenis = _paraf_jenis((string) post('doc_type', 'skp'));
    // Mode otomatis tidak dibedakan per jenis dokumen — satu baris untuk semua.
    $simpanKe = $mode === 'otomatis' ? Paraf::SEMUA : $jenis;
    $balik    = $mode === 'otomatis' ? ['mode' => 'otomatis'] : ['mode' => 'manual', 'doc_type' => $jenis];

    $lama   = Paraf::ambilPersis($pdo, $uid, $simpanKe);
    $gambar = $lama['gambar_path'] ?? null;

    // Gambar baru hanya menimpa bila memang ada yang dikirim — menyimpan ulang
    // untuk mengubah centang atau posisi tidak boleh menghapus paraf yang ada.
    $baru = _paraf_simpan_gambar($uid, $simpanKe);
    if ($baru !== null) $gambar = $baru;

    $bentuk = post('bentuk') === 'inisial' ? 'inisial' : 'gambar';
    if ($bentuk === 'gambar' && !$gambar) {
        flash('Parafnya belum digambar atau diunggah. Gambar dulu di kotak putih, atau ganti bentuknya ke Inisial.');
        redirect_to('my_paraf', $balik);
    }
    if ($bentuk === 'inisial' && trim((string) post('teks')) === '') {
        flash('Tulisan inisialnya belum diisi.');
        redirect_to('my_paraf', $balik);
    }

    Paraf::simpan($pdo, $uid, $simpanKe, [
        'mode'         => $mode,
        'bentuk'       => $bentuk,
        'gambar_path'  => $gambar,
        'teks'         => post('teks'),
        'pos_x'        => post('pos_x', 150),
        'pos_y'        => post('pos_y', 232),
        'lebar'        => post('lebar', 20),
        'tampil_nama'  => post('tampil_nama') === '1' ? 1 : 0,
        'tampil_waktu' => post('tampil_waktu') === '1' ? 1 : 0,
    ]);
    audit($pdo, 'update', 'paraf_settings', $simpanKe, ['mode' => $mode, 'bentuk' => $bentuk, 'lebar' => post('lebar')]);
    flash($mode === 'otomatis'
        ? 'Paraf tersimpan dan langsung berlaku untuk semua jenis surat — terpasang di kanan atas QR Manager.'
        : 'Paraf untuk ' . Paraf::JENIS[$jenis] . ' tersimpan pada posisi yang Anda tentukan.');
    redirect_to('my_paraf', $balik);
}

function my_paraf_reset(PDO $pdo): void
{
    require_permission('approve_skp');
    // Reset dipanggil lewat tautan, jadi tokennya ikut di query — tetap
    // diperiksa supaya tidak bisa dipicu dari situs lain.
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) getv('_csrf'))) {
        flash('Sesi form sudah kedaluwarsa. Coba lagi.');
        redirect_to('my_paraf');
    }
    $uid = (int) ($_SESSION['user']['id'] ?? 0);
    $dt  = (string) getv('doc_type', Paraf::SEMUA);
    $dt  = $dt === Paraf::SEMUA ? Paraf::SEMUA : _paraf_jenis($dt);
    Paraf::reset($pdo, $uid, $dt);
    audit($pdo, 'delete', 'paraf_settings', $dt, []);
    flash('Paraf direset. Dokumen yang sudah terbit tidak berubah.');
    redirect_to('my_paraf');
}
