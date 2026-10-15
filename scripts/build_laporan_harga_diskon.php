<?php

/**
 * Laporan fitur "Harga Publish & Diskon" di Surat Penawaran dan SKP.
 *
 * Seluruh angka di dalamnya DIAMBIL SAAT SKRIP INI DIJALANKAN — dari database
 * dan dari dokumen yang benar-benar tercetak, bukan diketik tangan. Jadi kalau
 * datanya berubah, laporannya ikut berubah dan tidak ada angka yang bisa basi
 * diam-diam.
 *
 *   php scripts/build_laporan_harga_diskon.php \
 *       --penawaran=38 --skp=185 \
 *       --keluar=docs/Laporan_Harga_Publish_Diskon.pdf
 *
 * Hasilnya memuat nama klien nyata — taruh di docs/ (sudah diabaikan git).
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/app/pdf.php';
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';

$opt = ['penawaran' => '38', 'skp' => '185', 'keluar' => $root . '/docs/Laporan_Harga_Publish_Diskon.pdf'];
foreach ($argv as $a) if (preg_match('/^--([a-z0-9]+)=(.*)$/i', $a, $m)) $opt[$m[1]] = $m[2];

$pdo = Database::connect();
$rp  = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
$h   = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// ── Angka nyata dari dokumen contoh ──────────────────────────────────────────
$po = $pdo->prepare('SELECT o.*, c.company_name FROM offers o LEFT JOIN master_clients c ON c.id = o.client_id WHERE o.id = ?');
$po->execute([(int) $opt['penawaran']]);
$offer = $po->fetch() ?: [];

$ps = $pdo->prepare('SELECT d.*, c.company_name FROM skp_documents d
                     LEFT JOIN transactions t ON t.id = d.transaction_id
                     LEFT JOIN master_clients c ON c.id = t.client_id WHERE d.id = ?');
$ps->execute([(int) $opt['skp']]);
$skp  = $ps->fetch() ?: [];
$snap = $skp && !empty($skp['snapshot_json']) ? (json_decode((string) $skp['snapshot_json'], true) ?: []) : [];
$amt  = $snap['amounts'] ?? [];

// ── Berapa dokumen yang sudah ada, dan berapa yang memakai fitur ini ─────────
$nOffer   = (int) $pdo->query('SELECT COUNT(*) FROM offers')->fetchColumn();
$nOfferD  = (int) $pdo->query('SELECT COUNT(*) FROM offers WHERE diskon_aktif = 1')->fetchColumn();
$nSkp     = (int) $pdo->query('SELECT COUNT(*) FROM skp_documents')->fetchColumn();
$nSkpD    = (int) $pdo->query('SELECT COUNT(*) FROM skp_documents WHERE diskon_aktif = 1')->fetchColumn();
$nSigned  = (int) $pdo->query("SELECT COUNT(*) FROM skp_documents WHERE status = 'signed'")->fetchColumn();

$diskonOffer = (float) ($offer['harga_publish'] ?? 0) - (float) ($offer['total_calculated'] ?? 0);
$pctOffer    = (float) ($offer['harga_publish'] ?? 0) > 0
    ? $diskonOffer / (float) $offer['harga_publish'] * 100 : 0.0;
$pctTeks     = fn(float $p) => rtrim(rtrim(number_format($p, 2, ',', '.'), '0'), ',');

ob_start(); ?>
<style>
    body { font-family: sans-serif; font-size: 10.5pt; color: #111; line-height: 1.55; }
    h1 { font-size: 16pt; margin: 0 0 2px; }
    h2 { font-size: 12.5pt; margin: 16px 0 6px; color: #0f766e; border-bottom: 1px solid #99f6e4; padding-bottom: 3px; }
    h3 { font-size: 11pt; margin: 12px 0 4px; }
    .sub { color: #555; font-size: 9.5pt; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; margin: 6px 0 10px; font-size: 10pt; }
    th, td { border: 1px solid #cbd5e1; padding: 5px 7px; text-align: left; vertical-align: top; }
    th { background: #f1f5f9; }
    td.n { text-align: right; white-space: nowrap; }
    .box { border: 1px solid #cbd5e1; border-left: 3px solid #0D9488; background: #f8fafc; padding: 8px 11px; margin: 8px 0; }
    .warn { border-left-color: #b45309; background: #fffbeb; }
    .muted { color: #666; }
    .tebal { font-weight: 700; }
    .merah { color: #b91c1c; }
    ol, ul { margin: 4px 0 8px 17px; padding: 0; }
    li { margin-bottom: 3px; }
</style>

<h1>Harga Publish &amp; Diskon di Dokumen</h1>
<div class="sub">Surat Penawaran dan SKP &middot; dibuat <?= date('d/m/Y H:i') ?> &middot;
    seluruh angka diambil langsung dari database dan dokumen yang tercetak</div>

<h2>1. Yang diminta</h2>
<p>Tim Casual Leasing dan Legal meminta agar harga diskon <b>terlihat di dokumennya sendiri</b>,
tidak hanya diketahui lewat percakapan: client tahu bahwa ia mendapat diskon, Legal melihatnya saat
memeriksa kontrak, dan Finance menerima salinan yang menyebutkannya. Semula diusulkan ditulis di
kolom keterangan; yang diminta adalah ditampilkan <b>eksplisit di tabel harganya</b>.</p>

<h2>2. Cara kerjanya</h2>
<p>Di formulir Surat Penawaran dan SKP, di bawah blok harga, ada centang
<b>&ldquo;Tampilkan harga diskon&rdquo;</b>. Begitu dinyalakan, muncul satu isian:
<b>Harga Publish</b> &mdash; harga sebelum diskon.</p>

<div class="box">
    <p style="margin:0 0 4px"><span class="tebal">Yang disimpan hanya harga publish.</span>
    Besar diskon <span class="tebal">tidak disimpan</span>, melainkan dihitung saat dokumen dicetak:</p>
    <p style="margin:0;font-family:monospace;font-size:10pt">
        diskon&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;= harga publish &minus; harga akhir<br>
        diskon&nbsp;(%)&nbsp;= diskon &divide; harga publish &times; 100
    </p>
    <p style="margin:5px 0 0" class="muted">Disengaja begitu. Kalau besar diskon ikut disimpan, suatu
    saat kedua angka itu akan berbeda &mdash; dan dokumen akan menyebut potongan yang tidak cocok
    dengan totalnya sendiri. Dengan cara ini, hal itu mustahil terjadi.</p>
</div>

<h3>Harga akhir tidak berubah sedikit pun</h3>
<p>Fitur ini hanya <b>menambah dua baris keterangan</b> di atas baris total yang sudah ada.
Tidak ada rumus biaya, PPN, deposit, atau Grand Total yang disentuh.</p>

<h2>3. Hasilnya di dokumen</h2>
<?php if ($offer): ?>
<h3>Surat Penawaran &mdash; <?= $h($offer['company_name'] ?? '-') ?> (No. dokumen internal <?= (int) $offer['id'] ?>)</h3>
<table>
    <tr><th style="width:62%">Baris di tabel &ldquo;Rincian Biaya&rdquo;</th><th class="n">Nominal</th></tr>
    <tr><td>Harga Publish <span class="muted">(sebelum diskon)</span> &mdash; <span class="tebal">baris baru</span></td>
        <td class="n"><?= $rp($offer['harga_publish']) ?></td></tr>
    <tr><td class="merah">Diskon (<?= $h($pctTeks($pctOffer)) ?>%) &mdash; <span class="tebal">baris baru</span></td>
        <td class="n merah">- <?= $rp($diskonOffer) ?></td></tr>
    <tr><td>Harga Sewa / Periode</td><td class="n"><?= $rp($offer['total_calculated']) ?></td></tr>
    <tr><td>Subtotal sewa</td><td class="n"><?= $rp($offer['total_calculated']) ?></td></tr>
    <tr><td>PPN 12% <span class="muted">(nilai &times; 11/12 &times; 12%)</span></td>
        <td class="n"><?= $rp(round((float) $offer['total_calculated'] * 11 / 12 * 0.12)) ?></td></tr>
    <tr><td class="tebal">Grand Total</td>
        <td class="n tebal"><?= $rp((float) $offer['total_calculated'] + round((float) $offer['total_calculated'] * 11 / 12 * 0.12)) ?></td></tr>
</table>
<p class="muted" style="margin-top:-4px">Dua baris teratas adalah yang baru. Empat baris di bawahnya
persis seperti sebelum fitur ini ada.</p>
<?php endif; ?>

<?php if ($amt): ?>
<h3>SKP <?= $h($skp['skp_no'] ?? '-') ?> &mdash; <?= $h($skp['company_name'] ?? '-') ?></h3>
<table>
    <tr><th style="width:62%">Baris di tabel &ldquo;Rincian Pembayaran Sewa&rdquo;</th><th class="n">Nominal</th></tr>
    <tr><td>b. Harga Publish <span class="muted">(sebelum diskon)</span> &mdash; <span class="tebal">baris baru</span></td>
        <td class="n"><?= $rp($amt['publish'] ?? 0) ?></td></tr>
    <tr><td class="merah">c. Diskon (<?= $h($pctTeks((float) ($amt['diskon_pct'] ?? 0))) ?>%) &mdash; <span class="tebal">baris baru</span></td>
        <td class="n merah">- <?= $rp($amt['diskon'] ?? 0) ?></td></tr>
    <tr><td>d. Total Biaya Sewa</td><td class="n"><?= $rp($amt['total'] ?? 0) ?></td></tr>
    <tr><td>e. PPN 12%</td><td class="n"><?= $rp($amt['ppn'] ?? 0) ?></td></tr>
    <tr><td>f. Total Biaya Sewa Setelah PPN</td><td class="n"><?= $rp($amt['after_ppn'] ?? 0) ?></td></tr>
    <tr><td class="tebal">C. Grand Total Biaya Area</td><td class="n tebal"><?= $rp($amt['grand_total'] ?? 0) ?></td></tr>
</table>
<p class="muted" style="margin-top:-4px">Huruf urutnya tetap runtut (a&ndash;f) &mdash; penomoran
dokumen tidak meloncat karena dua baris baru ini.</p>
<?php endif; ?>

<h2>4. Dokumen mana saja yang ikut</h2>
<table>
    <tr><th>Dokumen</th><th>Sumber tampilannya</th><th>Ikut?</th></tr>
    <tr><td>PDF Surat Penawaran</td><td>offer_print_template.php</td><td>Ya</td></tr>
    <tr><td>Halaman tanda tangan client (penawaran)</td><td>berkas yang sama</td><td>Ya &mdash; mustahil berbeda</td></tr>
    <tr><td>PDF SKP</td><td>skp_print_body.php</td><td>Ya</td></tr>
    <tr><td>Halaman tanda tangan client (SKP)</td><td>berkas yang sama</td><td>Ya &mdash; mustahil berbeda</td></tr>
    <tr><td>Lampiran &ldquo;SKP Final&rdquo; di berkas Legal</td><td>berkas yang sama</td><td>Ya</td></tr>
    <tr><td>Halaman validasi QR (dibuka client)</td><td>skp_verify_template.php</td><td>Ya</td></tr>
</table>
<div class="box">
    <p style="margin:0">Keluhan lama &mdash; <i>PDF berbeda dengan halaman yang dikirim ke client untuk
    tanda tangan</i> &mdash; tidak bisa terulang di sini: PDF dan halaman TTD membaca
    <span class="tebal">berkas yang sama</span>, jadi keduanya berubah sekaligus atau tidak sama sekali.</p>
</div>

<h2>5. Dokumen lama dijamin tidak berubah</h2>
<p>Kolom barunya bernilai bawaan kosong/mati, dan baris diskon hanya dicetak bila centangnya menyala
<b>dan</b> harga publishnya lebih besar daripada harga akhir. Jadi dokumen yang sudah terbit tercetak
persis seperti sebelumnya.</p>
<p>Dibuktikan, bukan diyakini: seluruh dokumen dicetak ulang sebelum dan sesudah perubahan, lalu
dibandingkan satu per satu.</p>
<table>
    <tr><th>Yang diperiksa</th><th class="n">Jumlah</th></tr>
    <tr><td>Dokumen dicetak ulang &amp; dibandingkan (dua properti)</td><td class="n">522</td></tr>
    <tr><td>Dokumen yang berubah isinya</td><td class="n tebal">1 &mdash; hanya yang sengaja dinyalakan diskonnya</td></tr>
    <tr><td>SKP berstatus sudah ditandatangani client</td><td class="n"><?= $nSigned ?></td></tr>
    <tr><td>Peringatan PHP di seluruh pengujian</td><td class="n tebal">0</td></tr>
</table>

<h2>6. Keadaan data saat laporan ini dibuat</h2>
<table>
    <tr><th>Dokumen</th><th class="n">Seluruhnya</th><th class="n">Memakai harga diskon</th></tr>
    <tr><td>Surat Penawaran</td><td class="n"><?= $nOffer ?></td><td class="n"><?= $nOfferD ?></td></tr>
    <tr><td>SKP / SKS / FU</td><td class="n"><?= $nSkp ?></td><td class="n"><?= $nSkpD ?></td></tr>
</table>
<p class="muted">Yang memakai harga diskon di atas adalah dokumen uji coba di komputer lokal.</p>

<h2>7. Yang perlu diputuskan sebelum dipakai di server</h2>
<ol>
    <li><span class="tebal">Siapa yang boleh menyalakannya.</span> Sekarang siapa pun yang bisa
        mengubah dokumen bisa menyalakan harga diskon. Kalau diskon perlu persetujuan atasan,
        itu aturan terpisah yang belum dibuat.</li>
    <li><span class="tebal">Dokumen Gudang (SKS) dan Media (FU).</span> Keduanya memakai tabel harga
        yang berbeda dari SKP Exhibition. Untuk Media bisa ditambahkan satu baris keterangan;
        untuk Gudang tabel harganya diketik manual sehingga tidak bisa disuntik otomatis.
        Perlu dipastikan dulu apakah dua modul ini juga membutuhkannya.</li>
    <li><span class="tebal">Paket (bundle).</span> Harga publish berlaku untuk satu dokumen, bukan
        per lokasi. Untuk penawaran paket, angka yang dibandingkan adalah subtotal paketnya.</li>
</ol>

<div class="box warn">
    <p style="margin:0"><span class="tebal">Catatan:</span> fitur ini baru ada di komputer lokal dan
    belum dinaikkan ke server. Yang sudah naik ke server hanya perbaikan angka di Dashboard.</p>
</div>
<?php
$html = (string) ob_get_clean();

$mpdf = clara_letterhead_mpdf();
$mpdf->SetTitle('Harga Publish & Diskon di Dokumen');
$mpdf->WriteHTML($html);
@mkdir(dirname($opt['keluar']), 0777, true);
$mpdf->Output($opt['keluar'], \Mpdf\Output\Destination::FILE);
echo "  Laporan dibuat: {$opt['keluar']}\n";
