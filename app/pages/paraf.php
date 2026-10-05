<?php

declare(strict_types=1);

/**
 * "Paraf Saya" — pemeriksa menyiapkan paraf sekali, dipakai seterusnya.
 *
 * Yang diatur ada dua: BENTUK parafnya (gambar yang diunggah, atau inisial
 * yang diketik) dan LETAKNYA di halaman. Keduanya disimpan per jenis dokumen,
 * karena tata letak SKP, SKS Gudang, dan Form Utilities berbeda.
 *
 * Letaknya diatur dengan menggeser kotak di atas pratinjau kertas A4 — ukuran
 * pratinjaunya proporsional terhadap kertas sungguhan, dan angka yang tersimpan
 * adalah milimeter, bukan piksel, sehingga tidak bergantung pada lebar layar
 * siapa pun yang mengaturnya.
 */

require_once dirname(__DIR__) . '/Paraf.php';

function _paraf_dir(): string { return dirname(__DIR__, 2) . '/public/uploads/signatures'; }

/** Jenis dokumen yang sedang diatur. */
function _paraf_jenis(string $v): string
{
    return array_key_exists($v, Paraf::JENIS) ? $v : 'skp';
}

function my_paraf_page(PDO $pdo): void
{
    require_permission('approve_skp');
    $uid   = (int) ($_SESSION['user']['id'] ?? 0);
    $jenis = _paraf_jenis((string) getv('doc_type', 'skp'));
    $set   = Paraf::ambil($pdo, $uid, $jenis) ?: Paraf::bawaan();
    $semua = Paraf::semua($pdo, $uid);
    $nama  = (string) ($_SESSION['user']['name'] ?? '');

    // Jabatan orang ini, untuk ditulis di bawah paraf persis seperti nanti
    // tercetak — supaya yang dilihat saat mengatur sama dengan hasilnya.
    require_once dirname(__DIR__) . '/ApprovalLine.php';
    $jabatan = ApprovalLine::jabatan($pdo, current_property_id());

    layout('Paraf Saya', function () use ($set, $semua, $jenis, $nama, $jabatan, $pdo, $uid) {
        $sudah = Paraf::siap(Paraf::ambil($pdo, $uid, $jenis));
        ?>
        <div class="panel">
            <h2 style="margin-top:0">Paraf Saya</h2>
            <p class="help" style="margin-top:0">Atur <strong>sekali</strong> bentuk paraf Anda dan letaknya di dokumen.
               Setelah itu, setiap kali Anda menekan <strong>Paraf &amp; Teruskan</strong> atau <strong>Setujui</strong>,
               paraf Anda terpasang otomatis di posisi ini &mdash; tidak perlu diatur lagi.
               Pengaturan ini bertahan sampai Anda sendiri mengubah atau mereset-nya.</p>

            <div style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0">
                <?php foreach (Paraf::JENIS as $k => $l): ?>
                <a class="btn <?= $k === $jenis ? '' : 'light' ?>" href="?r=my_paraf&doc_type=<?= $k ?>">
                    <?= h($l) ?><?= Paraf::siap($semua[$k] ?? null) ? ' &check;' : '' ?></a>
                <?php endforeach; ?>
            </div>

            <?php if (!$sudah): ?>
            <div class="panel" style="background:#fffbeb;border:1px solid #fde68a;margin-bottom:12px">
                <p style="margin:0;color:#92400e">Paraf untuk <strong><?= h(Paraf::JENIS[$jenis]) ?></strong> belum diatur.
                   Selama belum diatur, paraf Anda tetap tercatat di dokumen sebagai <em>baris teks</em>
                   &ldquo;Diperiksa sebelum disetujui&rdquo; &mdash; dokumennya tetap sah, hanya tidak ada gambar parafnya.</p>
            </div>
            <?php endif; ?>

            <form method="post" action="?r=my_paraf_save" enctype="multipart/form-data" id="paraf-form">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="doc_type" value="<?= h($jenis) ?>">

                <div style="display:flex;gap:22px;flex-wrap:wrap;align-items:flex-start">

                    <?php /* ── Kertas A4: 210 x 297 mm, ditampilkan 460 px lebar ── */ ?>
                    <div>
                        <div style="font-size:12px;font-weight:700;margin-bottom:6px">Geser kotak ke posisi paraf Anda</div>
                        <div id="kertas" style="position:relative;width:460px;height:650px;background:#fff;
                                    border:1px solid #cbd5e1;border-radius:4px;box-shadow:0 2px 12px rgba(0,0,0,.09);
                                    overflow:hidden;cursor:crosshair;user-select:none">
                            <img src="assets/letterhead-a4.jpg" alt="" style="position:absolute;inset:0;width:100%;height:100%;
                                 object-fit:fill;opacity:.55;pointer-events:none">
                            <?php /* Pemandu: tepi area isi dokumen (17mm) & blok tanda tangan,
                                     supaya pemakai tahu di mana ruang yang masih kosong. */ ?>
                            <div style="position:absolute;left:37px;right:37px;top:66px;bottom:79px;
                                        border:1px dashed #cbd5e1;pointer-events:none"></div>
                            <?php /* Perkiraan letak blok tanda tangan pada halaman TERAKHIR
                                     dokumen (sekitar 120-157 mm dari atas). Letak persisnya
                                     ikut panjang isi dokumen, jadi ini pemandu, bukan patokan mati. */ ?>
                            <div style="position:absolute;left:37px;right:37px;top:262px;height:81px;
                                        border:1px dashed #fca5a5;background:rgba(254,226,226,.3);pointer-events:none">
                                <span style="position:absolute;top:2px;left:5px;font-size:9.5px;color:#b91c1c">perkiraan blok tanda tangan &amp; QR</span>
                            </div>
                            <div id="paraf-kotak" style="position:absolute;border:1px dashed #0d9488;background:rgba(13,148,136,.08);
                                        border-radius:3px;cursor:grab;padding:1px">
                                <div id="paraf-isi" style="pointer-events:none"></div>
                            </div>
                        </div>
                        <div class="help" style="margin-top:5px">Kotak merah = perkiraan blok tanda tangan &amp; QR; hindari menimpanya.
                            Paraf dipasang di <strong>halaman terakhir</strong> dokumen &mdash; halaman tempat blok tanda tangan berada.</div>
                    </div>

                    <?php /* ── Pengaturan ── */ ?>
                    <div style="flex:1;min-width:290px;max-width:420px">
                        <label style="font-size:12px;font-weight:700">Bentuk paraf</label>
                        <div style="display:flex;gap:18px;margin:6px 0 12px;white-space:nowrap">
                            <label style="font-weight:400;display:flex;gap:6px;align-items:center;cursor:pointer">
                                <input type="radio" name="bentuk" value="gambar" <?= ($set['bentuk'] ?? 'gambar') !== 'inisial' ? 'checked' : '' ?>> Gambar
                            </label>
                            <label style="font-weight:400;display:flex;gap:6px;align-items:center;cursor:pointer">
                                <input type="radio" name="bentuk" value="inisial" <?= ($set['bentuk'] ?? '') === 'inisial' ? 'checked' : '' ?>> Inisial (ketik)
                            </label>
                        </div>

                        <div id="blok-gambar">
                            <?php if (!empty($set['gambar_path'])): ?>
                            <div style="margin-bottom:8px">
                                <div style="font-size:12px;color:var(--muted);margin-bottom:4px">Gambar paraf saat ini:</div>
                                <img src="<?= h(upload_url((string) $set['gambar_path'])) ?>" alt="paraf"
                                     style="max-height:70px;max-width:200px;border:1px solid var(--line);border-radius:6px;background:#fff;padding:5px">
                            </div>
                            <?php endif; ?>
                            <label style="font-size:12px;font-weight:700">Unggah gambar paraf (PNG latar transparan paling rapi, maks 2 MB)</label>
                            <input type="file" name="gambar" accept="image/png,image/jpeg,image/webp" id="inp-gambar" style="display:block;margin:6px 0 12px">
                        </div>

                        <div id="blok-inisial" style="display:none">
                            <label style="font-size:12px;font-weight:700">Tulisan inisial</label>
                            <input name="teks" id="inp-teks" maxlength="40" placeholder="mis. YY"
                                   value="<?= h((string) ($set['teks'] ?? '')) ?>" style="margin:6px 0 12px">
                        </div>

                        <label style="font-size:12px;font-weight:700">Lebar paraf: <span id="lbl-lebar"></span> mm</label>
                        <input type="range" name="lebar" id="inp-lebar" min="10" max="80" step="1"
                               value="<?= (int) round((float) ($set['lebar'] ?? 30)) ?>" style="width:100%;margin:6px 0 12px">

                        <div style="display:flex;gap:10px;margin-bottom:12px">
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

                        <label style="font-weight:400;display:flex;gap:7px;align-items:center;cursor:pointer;margin-bottom:14px">
                            <input type="checkbox" name="tampil_nama" value="1" id="inp-nama" <?= !empty($set['tampil_nama']) ? 'checked' : '' ?>>
                            <span style="font-size:13px">Cetak nama &amp; waktu di bawah paraf</span>
                        </label>

                        <button type="submit">💾 Simpan Paraf</button>
                        <?php if ($sudah): ?>
                        <a class="btn warn" href="?r=my_paraf_reset&doc_type=<?= h($jenis) ?>&_csrf=<?= csrf_token() ?>"
                           onclick="return confirm('Reset paraf untuk <?= h(Paraf::JENIS[$jenis]) ?>? Dokumen yang sudah terbit tidak berubah.')">Reset</a>
                        <?php endif; ?>

                        <p class="help" style="margin-top:14px">Dokumen yang <strong>sudah terbit</strong> tidak ikut berubah saat Anda
                           mengubah pengaturan ini &mdash; bentuk dan posisi parafnya sudah dikunci di dokumen itu sejak Anda memarafnya.</p>
                    </div>
                </div>
            </form>
        </div>

        <script>
        (function () {
            var SKALA = 460 / 210;                       // piksel per milimeter di pratinjau
            var kertas = document.getElementById('kertas');
            var kotak  = document.getElementById('paraf-kotak');
            var isi    = document.getElementById('paraf-isi');
            var ix = document.getElementById('inp-x'), iy = document.getElementById('inp-y');
            var il = document.getElementById('inp-lebar'), ll = document.getElementById('lbl-lebar');
            var it = document.getElementById('inp-teks'), ig = document.getElementById('inp-gambar');
            var inm = document.getElementById('inp-nama');
            var NAMA = <?= json_encode(trim($nama . ($jabatan !== '' ? ' · ' . $jabatan : '')), JSON_UNESCAPED_UNICODE) ?>;
            var GBR  = <?= json_encode(!empty($set['gambar_path']) ? upload_url((string) $set['gambar_path']) : '', JSON_UNESCAPED_UNICODE) ?>;
            var gbrBaru = '';

            function bentuk() {
                var r = document.querySelector('input[name=bentuk]:checked');
                return r ? r.value : 'gambar';
            }
            function jepit(v, min, max) { return Math.max(min, Math.min(max, v)); }

            function gambarUlang() {
                var lebar = parseFloat(il.value) || 30;
                ll.textContent = lebar;
                document.getElementById('blok-gambar').style.display  = bentuk() === 'gambar'  ? '' : 'none';
                document.getElementById('blok-inisial').style.display = bentuk() === 'inisial' ? '' : 'none';

                var x = jepit(parseFloat(ix.value) || 0, 0, 210 - lebar);
                var y = jepit(parseFloat(iy.value) || 0, 0, 297 - 8);
                ix.value = Math.round(x * 2) / 2;
                iy.value = Math.round(y * 2) / 2;

                kotak.style.left  = (x * SKALA) + 'px';
                kotak.style.top   = (y * SKALA) + 'px';
                kotak.style.width = (lebar * SKALA) + 'px';

                var html = '';
                if (bentuk() === 'inisial') {
                    var t = (it && it.value ? it.value : '').trim() || 'YY';
                    html = '<div style="font-style:italic;font-weight:700;color:#1e3a8a;line-height:1.1;font-size:'
                         + Math.max(11, lebar * 0.42 * 1.33) + 'px">' + t.replace(/[<>&]/g, '') + '</div>';
                } else {
                    var src = gbrBaru || GBR;
                    html = src
                        ? '<img src="' + src + '" style="width:100%;display:block">'
                        : '<div style="font-size:10px;color:#94a3b8;text-align:center;padding:8px 2px;border:1px dashed #cbd5e1">belum ada gambar</div>';
                }
                if (inm.checked) {
                    html += '<div style="font-size:8px;color:#475569;line-height:1.25;margin-top:1px">' + NAMA + '<br>05/10/2026 09:15</div>';
                }
                isi.innerHTML = html;
            }

            // Geser kotaknya. Dipakai titik tangkap (offset) supaya kotak tidak
            // melompat ke bawah kursor saat pertama ditekan.
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

            // Klik di area kosong kertas = pindahkan paraf ke situ.
            kertas.addEventListener('click', function (e) {
                if (e.target.closest('#paraf-kotak')) return;
                var p = kertas.getBoundingClientRect();
                var lebar = parseFloat(il.value) || 30;
                ix.value = ((e.clientX - p.left) / SKALA - lebar / 2).toFixed(1);
                iy.value = ((e.clientY - p.top) / SKALA - 4).toFixed(1);
                gambarUlang();
            });

            // Pratinjau gambar yang baru dipilih, sebelum disimpan.
            if (ig) ig.addEventListener('change', function () {
                var f = ig.files && ig.files[0];
                if (!f) { gbrBaru = ''; gambarUlang(); return; }
                var fr = new FileReader();
                fr.onload = function () { gbrBaru = fr.result; gambarUlang(); };
                fr.readAsDataURL(f);
            });

            ['change', 'input'].forEach(function (ev) {
                [ix, iy, il, it, inm].forEach(function (el) { if (el) el.addEventListener(ev, gambarUlang); });
                document.querySelectorAll('input[name=bentuk]').forEach(function (el) { el.addEventListener(ev, gambarUlang); });
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
    $uid   = (int) ($_SESSION['user']['id'] ?? 0);
    $jenis = _paraf_jenis((string) post('doc_type', 'skp'));
    if (!$uid) { flash('Sesi tidak valid.'); redirect_to('my_paraf'); }

    $lama   = Paraf::ambil($pdo, $uid, $jenis);
    $gambar = $lama['gambar_path'] ?? null;

    // Gambar baru hanya menimpa bila memang ada yang diunggah — menyimpan ulang
    // untuk menggeser posisi tidak boleh menghapus gambar yang sudah ada.
    if (!empty($_FILES['gambar']['tmp_name']) && is_uploaded_file($_FILES['gambar']['tmp_name'])) {
        $f = $_FILES['gambar'];
        if ($f['size'] <= 0 || $f['size'] > 2 * 1024 * 1024) {
            flash('Ukuran gambar maksimal 2 MB.'); redirect_to('my_paraf', ['doc_type' => $jenis]);
        }
        $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
            flash('Format gambar harus PNG/JPG/WEBP.'); redirect_to('my_paraf', ['doc_type' => $jenis]);
        }
        // Isi berkasnya diperiksa, bukan cuma namanya — ekstensi bisa dipalsukan.
        $info = @getimagesize($f['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            flash('Berkas itu bukan gambar yang sah.'); redirect_to('my_paraf', ['doc_type' => $jenis]);
        }
        $dir = _paraf_dir();
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $fname = 'paraf_u' . $uid . '_' . $jenis . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) {
            flash('Gagal mengunggah gambar.'); redirect_to('my_paraf', ['doc_type' => $jenis]);
        }
        $gambar = 'uploads/signatures/' . $fname;
    }

    $bentuk = post('bentuk') === 'inisial' ? 'inisial' : 'gambar';
    if ($bentuk === 'gambar' && !$gambar) {
        flash('Pilih gambar parafnya dulu, atau ganti bentuknya ke Inisial.');
        redirect_to('my_paraf', ['doc_type' => $jenis]);
    }
    if ($bentuk === 'inisial' && trim((string) post('teks')) === '') {
        flash('Tulisan inisialnya belum diisi.');
        redirect_to('my_paraf', ['doc_type' => $jenis]);
    }

    Paraf::simpan($pdo, $uid, $jenis, [
        'bentuk'      => $bentuk,
        'gambar_path' => $gambar,
        'teks'        => post('teks'),
        'pos_x'       => post('pos_x', 150),
        'pos_y'       => post('pos_y', 232),
        'lebar'       => post('lebar', 30),
        'tampil_nama' => post('tampil_nama') === '1' ? 1 : 0,
    ]);
    audit($pdo, 'update', 'paraf_settings', $jenis, [
        'bentuk' => $bentuk, 'x' => post('pos_x'), 'y' => post('pos_y'), 'lebar' => post('lebar'),
    ]);
    flash('Paraf untuk ' . Paraf::JENIS[$jenis] . ' tersimpan. Mulai sekarang terpasang otomatis setiap kali Anda memaraf.');
    redirect_to('my_paraf', ['doc_type' => $jenis]);
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
    $uid   = (int) ($_SESSION['user']['id'] ?? 0);
    $jenis = _paraf_jenis((string) getv('doc_type', 'skp'));
    Paraf::reset($pdo, $uid, $jenis);
    audit($pdo, 'delete', 'paraf_settings', $jenis, []);
    flash('Paraf untuk ' . Paraf::JENIS[$jenis] . ' direset. Dokumen yang sudah terbit tidak berubah.');
    redirect_to('my_paraf', ['doc_type' => $jenis]);
}
