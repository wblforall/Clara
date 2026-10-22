<?php
/**
 * Tutorial menutup satu bulan — dibaca sambil mengerjakan, bukan dipelajari.
 *
 * Bedanya dengan build_perbaikan_pic.php: berkas itu dokumen pemeriksaan —
 * lengkap, bertabel, dan memang berat. Yang ini kebalikannya: satu langkah per
 * bagian, kalimat pendek, dan di tiap langkah ada dua hal saja — apa yang
 * ditekan, dan angka berapa yang muncul sesudahnya. Diikuti sampai habis,
 * angka bulan itu cocok.
 *
 * Angka awalnya dibaca langsung dari database, jadi dokumen ini selalu mulai
 * dari keadaan hari ini. Isi langkahnya dari berkas banding, kunci "langkah":
 *
 *   "langkah": {"2026-10": [
 *     {"judul":"...", "hasil": 1500000, "apa":"...",
 *      "isi":["...","..."], "selesai":"...", "awas":"..."}]}
 *
 * hasil = perubahan angkanya (0 kalau langkah itu tidak mengubah apa pun).
 * Penanda *tebal* dipakai untuk nama menu dan tombol.
 *
 * Pakai:
 *   php scripts/build_tutorial_bulan.php --pic="Shinta" --prop=2 \
 *       --banding=berkas.json --bulan=2026-10 --keluar=Tutorial.pdf
 */

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/pdf.php';

$opt = array_merge(['pic' => '', 'prop' => '2', 'banding' => '', 'bulan' => '', 'keluar' => ''],
    getopt('', ['pic:', 'prop:', 'banding:', 'bulan:', 'keluar:']));
if ($opt['pic'] === '' || $opt['banding'] === '' || $opt['bulan'] === '') {
    fwrite(STDERR, "Pakai: --pic=\"Nama\" --banding=berkas.json --bulan=2026-10 [--prop=2] [--keluar=x.pdf]\n");
    exit(2);
}
$pic = (string) $opt['pic'];
$pid = (int) $opt['prop'];
$bln = (string) $opt['bulan'];
$ss  = json_decode((string) file_get_contents((string) $opt['banding']), true);
if (!is_array($ss)) { fwrite(STDERR, "Berkas banding tidak terbaca.\n"); exit(2); }

$langkah = $ss['langkah'][$bln] ?? [];
if (!$langkah) { fwrite(STDERR, "Berkas banding belum memuat langkah untuk $bln.\n"); exit(2); }

if ($opt['keluar'] === '') {
    $opt['keluar'] = $root . '/docs/Tutorial_' . str_replace('-', '_', $bln) . '_'
        . preg_replace('/[^A-Za-z0-9]+/', '_', $pic) . '.pdf';
}

date_default_timezone_set((string) (env_value('APP_TIMEZONE') ?: 'Asia/Makassar'));

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$h  = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$tb = fn($s) => preg_replace('/\*(.+?)\*/u', '<b>$1</b>', htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'));
$rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

$q = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM transaction_allocations
                     WHERE property_id = ? AND pic_name = ? AND period_key = ?');
$q->execute([$pid, $pic, $bln]);
$awal = (float) $q->fetchColumn();

$namaBulan = ['01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
              '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
              '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'];
$blnTeks = ($namaBulan[substr($bln, 5, 2)] ?? $bln) . ' ' . substr($bln, 0, 4);

// Angka berjalan: tiap langkah tahu angka sebelum dan sesudahnya, sehingga
// orang bisa berhenti di tengah dan tetap tahu posisinya.
$jalan = $awal; $titik = [];
foreach ($langkah as $l) {
    $sebelum = $jalan;
    $jalan  += (float) ($l['hasil'] ?? 0);
    $titik[] = ['sebelum' => $sebelum, 'sesudah' => $jalan];
}
$akhir = $jalan;

ob_start(); ?>
<style>
    body { font-family: sans-serif; font-size: 11.5pt; line-height: 1.6; color: #1e293b; }
    h1 { font-size: 21pt; margin: 0 0 2px; color: #0D9488; }
    h2 { font-size: 15pt; margin: 0 0 3px; color: #0D9488; }
    .sub { color: #64748b; font-size: 10pt; margin-bottom: 16px; }
    .angka { border: 1px solid #cbd5e1; border-radius: 4px; padding: 10px 14px; margin: 12px 0; background: #f8fafc; }
    .angka b { font-size: 15pt; }
    .daftar { margin: 14px 0; width: 100%; border-collapse: collapse; }
    .daftar td { border-bottom: 1px solid #e2e8f0; padding: 8px 0; vertical-align: top; }

    .lang { border-left: 4px solid #0D9488; padding: 2px 0 2px 13px; margin-bottom: 10px; }
    .hasil { background: #ecfdf5; border: 1px solid #a7f3d0; padding: 9px 13px; margin: 11px 0; }
    .awas  { background: #fffbeb; border: 1px solid #fcd34d; padding: 9px 13px; margin: 11px 0; }
    .tidak { background: #fef2f2; border: 1px solid #fecaca; padding: 9px 13px; margin: 11px 0; }
    ol { margin: 6px 0 0 20px; padding: 0; } li { margin-bottom: 7px; }
    .muted { color: #64748b; } .hijau { color: #047857; } .merah { color: #b91c1c; }
    table { width: 100%; border-collapse: collapse; font-size: 11pt; }
    td { padding: 5px 0; }
    td.n { text-align: right; white-space: nowrap; }
</style>

<h1>Cara Menutup <?= $h($blnTeks) ?></h1>
<div class="sub"><b><?= $h($pic) ?></b> &middot; dibuat <?= date('d/m/Y H:i') ?> &middot;
    ikuti dari atas ke bawah, satu per satu.</div>

<div class="angka">
<table>
    <tr><td>Angka kamu sekarang di CLARA</td><td class="n"><b><?= $rp($awal) ?></b></td></tr>
    <tr><td class="hijau">Sesudah semua langkah selesai</td><td class="n hijau"><b><?= $rp($akhir) ?></b></td></tr>
</table>
</div>
<p style="margin:0">Ada <b><?= count($langkah) ?> langkah</b>. Kerjakan berurutan.
Setiap langkah menyebutkan angka yang harus muncul sesudahnya &mdash; kalau tidak cocok,
berhenti di situ dan tanyakan, jangan lanjut.</p>

<table class="daftar">
<?php foreach ($langkah as $i => $l): ?>
    <tr>
        <td style="padding-left:4px"><b><?= $i + 1 ?>.</b> <?= $tb($l['judul'] ?? '') ?>
            <span class="muted">&mdash; jadi <?= $rp($titik[$i]['sesudah']) ?></span></td>
    </tr>
<?php endforeach; ?>
</table>

<?php foreach ($langkah as $i => $l):
    $ubah = (float) ($l['hasil'] ?? 0); ?>
<pagebreak>
<h2>Langkah <?= $i + 1 ?> &mdash; <?= $tb($l['judul'] ?? '') ?></h2>
<div class="sub" style="margin-bottom:10px"><?= $tb($l['apa'] ?? '') ?></div>

<div class="lang">
<ol>
<?php foreach (($l['isi'] ?? []) as $x): ?>
    <li><?= $tb($x) ?></li>
<?php endforeach; ?>
</ol>
</div>

<?php if (!empty($l['awas'])): ?>
<div class="<?= $ubah == 0.0 ? 'tidak' : 'awas' ?>"><?= $tb($l['awas']) ?></div>
<?php endif; ?>

<div class="hasil">
<table>
    <tr><td>Angka sebelum langkah ini</td><td class="n"><?= $rp($titik[$i]['sebelum']) ?></td></tr>
    <?php if ($ubah != 0.0): ?>
    <tr><td>Perubahan dari langkah ini</td>
        <td class="n <?= $ubah < 0 ? 'merah' : 'hijau' ?>"><?= ($ubah < 0 ? '&minus;' : '+')
            . number_format(abs($ubah), 0, ',', '.') ?></td></tr>
    <?php else: ?>
    <tr><td colspan="2" class="muted">Langkah ini tidak mengubah angka &mdash; gunanya supaya
        kamu tidak menambah sesuatu yang salah.</td></tr>
    <?php endif; ?>
    <tr><td><b>Angka sesudah langkah ini</b></td>
        <td class="n"><b><?= $rp($titik[$i]['sesudah']) ?></b></td></tr>
</table>
<?php if (!empty($l['selesai'])): ?>
<div style="margin-top:7px"><b>Selesai kalau:</b> <?= $tb($l['selesai']) ?></div>
<?php endif; ?>
</div>
<?php endforeach; ?>

<pagebreak>
<h2>Sudah selesai?</h2>
<p>Buka <b>Analisa &rarr; Laporan PIC</b>, pilih <b><?= $h($blnTeks) ?></b>, lihat bagian namamu.</p>
<div class="angka">
<table>
    <tr><td>Yang harus tertulis di situ</td><td class="n"><b><?= $rp($akhir) ?></b></td></tr>
</table>
</div>
<p>Kalau angkanya sudah sama, <?= $h($blnTeks) ?> selesai dan tidak ada lagi yang perlu diinput.</p>

<h2 style="margin-top:18px">Kalau angkanya belum sama</h2>
<ol>
    <li>Cek langkah mana yang angkanya tidak cocok &mdash; kerjakan ulang yang itu saja.</li>
    <li>Kalau sebuah kontrak sudah kamu input tapi nilainya Rp 0 di laporan: klien belum
        menandatangani SKP-nya. Cek di <b>Input &rarr; SKP Pameran</b>, tab <b>Perlu TTD</b>.
        Itu bukan error &mdash; nilainya masuk begitu diteken.</li>
    <li>Kalau sewanya menyeberang bulan (misalnya 26 Okt&ndash;25 Nov), CLARA membukukan
        seluruhnya di bulan <b>mulai</b>. Kalau spreadsheet menaruhnya di bulan berikutnya,
        selisihnya di situ &mdash; bukan income yang hilang.</li>
    <li>Masih belum cocok juga? Minta dokumen ini dicetak ulang &mdash; angkanya ikut data terbaru.</li>
</ol>
<?php
$html = (string) ob_get_clean();
$mpdf = clara_letterhead_mpdf();
$mpdf->SetTitle('Cara Menutup ' . $blnTeks . ' — ' . $pic);
$mpdf->WriteHTML($html);
@mkdir(dirname($opt['keluar']), 0777, true);
$mpdf->Output($opt['keluar'], \Mpdf\Output\Destination::FILE);
echo "  Dokumen dibuat: {$opt['keluar']}\n";
