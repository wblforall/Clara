<?php
/**
 * Tutorial: cara mengisi harga supaya surat tidak memuat baris dobel.
 *
 * Angka hasil pemeriksaannya dibaca LANGSUNG dari database saat dicetak lewat
 * cek_rincian_pindai() — fungsi yang sama dengan yang dipakai surat untuk
 * melebur barisnya. Dua salinan aturan akan pelan-pelan berbeda, dan
 * tutorialnya lalu mengajarkan angka yang tidak pernah ada.
 *
 * Hasilnya TIDAK ikut di-commit (docs/*.pdf masuk .gitignore) — yang dibagikan
 * skrip pembangunnya.
 *
 * Pakai:
 *   php scripts/build_tutorial_dobel.php
 *   php scripts/build_tutorial_dobel.php --sejak=2026-10 --keluar=/jalur/Tutorial.pdf
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/pdf.php';
require_once __DIR__ . '/cek_rincian_double.php';

$opt = array_merge(['sejak' => '2026-10', 'keluar' => ''], getopt('', ['sejak:', 'keluar:']));
$sejak = (string) $opt['sejak'];
if (!preg_match('/^\d{4}-\d{2}$/', $sejak)) {
    fwrite(STDERR, "--sejak harus bentuk YYYY-MM, contoh 2026-10\n");
    exit(2);
}
if ($opt['keluar'] === '') $opt['keluar'] = $root . '/docs/Cara_Isi_Harga_Tanpa_Dobel.pdf';

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$h  = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

$batas  = $sejak . '-01';
$semua  = cek_rincian_pindai($pdo, $batas, '');
$props  = $pdo->query('SELECT id, name FROM properties ORDER BY id')->fetchAll();
$perProp = [];
foreach ($props as $p) $perProp[] = [$p['name'], cek_rincian_pindai($pdo, $batas, (string) $p['id'])];

// Dokumen Gudang: kolom "Harga Sewa /Bulan" yang isinya sama dengan Total.
$gdgKembar = $gdgTetap = 0;
foreach ($pdo->query("SELECT snapshot_json FROM skp_documents
                       WHERE doc_type = 'sks' AND deleted_at IS NULL")->fetchAll() as $r) {
    $d = json_decode((string) $r['snapshot_json'], true);
    $br = $d['detail']['rows'] ?? null;
    if (!is_array($br) || !$br) continue;
    $beda = false;
    foreach ($br as $x) {
        $hb = (float) ($x['harga_bulan'] ?? 0);
        if ($hb > 0 && round($hb) !== round((float) ($x['total'] ?? 0))) { $beda = true; break; }
    }
    $beda ? $gdgTetap++ : $gdgKembar++;
}

$namaBulan = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni',
              '07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
$sejakTeks = ($namaBulan[substr($sejak, 5, 2)] ?? substr($sejak, 5, 2)) . ' ' . substr($sejak, 0, 4);

ob_start(); ?>
<style>
    body { font-family: sans-serif; font-size: 10.3pt; color: #111; line-height: 1.42; }
    h1 { font-size: 16pt; margin: 0 0 2px; }
    h2 { font-size: 12.5pt; margin: 11px 0 4px; color: #0f766e; border-bottom: 1px solid #99f6e4; padding-bottom: 3px; }
    .sub { color: #555; font-size: 9.5pt; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; margin: 5px 0 7px; font-size: 9.3pt; }
    th, td { border: 1px solid #cbd5e1; padding: 3px 6px; text-align: left; vertical-align: top; }
    th { background: #f1f5f9; }
    td.n { text-align: right; white-space: nowrap; }
    .box { border: 1px solid #cbd5e1; border-left: 3px solid #0D9488; background: #f8fafc; padding: 6px 10px; margin: 6px 0; }
    .warn { border-left-color: #b45309; background: #fffbeb; }
    .ok   { border-left-color: #16a34a; background: #f0fdf4; }
    .muted { color: #666; }
    .tebal { font-weight: 700; }
    .merah { color: #b91c1c; }
    .hijau { color: #166534; }
    ol, ul { margin: 4px 0 8px 17px; padding: 0; }
    li { margin-bottom: 3px; }
    p { margin: 5px 0; }
    .srt { font-family: monospace; font-size: 8.4pt; line-height: 1.42; }
    .srt td { border: 0; padding: 1px 0; }
    .srt td.n { text-align: right; }
</style>

<h1>Satu Angka Jangan Tercetak Dua Kali</h1>
<div class="sub">Cara mengisi harga di <b>Surat Penawaran</b> &amp; <b>SKP / Surat Konfirmasi</b>
    &middot; untuk tim Casual Leasing &middot; dibuat <?= date('d/m/Y H:i') ?><br>
    Angka pemeriksaan di dokumen ini dibaca langsung dari database CLARA saat dicetak.</div>

<h2>1. Apa yang dulu terjadi</h2>
<p>Surat memuat satu nominal <b>dua sampai tiga kali</b> dengan nama berbeda:</p>
<table>
    <tr><th style="width:50%">Sebelum</th><th style="width:50%">Sesudah</th></tr>
    <tr>
        <td>
            <table class="srt">
                <tr><td>a. Biaya Sewa / m&sup2; / bulan</td><td class="n">Rp 3.600.000</td></tr>
                <tr><td>b. Biaya Sewa / Bulan<br>&nbsp;&nbsp;&nbsp;(4 m&sup2; &times; Rp 3.600.000)</td><td class="n"><b class="merah">Rp 14.400.000</b></td></tr>
                <tr><td>c. Nilai Sewa</td><td class="n"><b class="merah">Rp 14.400.000</b></td></tr>
                <tr><td>d. PPN 12% Sewa</td><td class="n">Rp 1.584.000</td></tr>
                <tr><td>e. Biaya Listrik</td><td class="n">Rp 150.000</td></tr>
                <tr><td>f. PPN 12% Listrik</td><td class="n">Rp 16.500</td></tr>
                <tr><td>g. Total Biaya Sewa</td><td class="n">Rp 14.550.000</td></tr>
                <tr><td>h. Total Setelah PPN</td><td class="n">Rp 16.150.500</td></tr>
            </table>
        </td>
        <td>
            <table class="srt">
                <tr><td>a. Biaya Sewa / m&sup2; / bulan</td><td class="n">Rp 3.600.000</td></tr>
                <tr><td>b. Nilai Sewa<br>&nbsp;&nbsp;&nbsp;(4 m&sup2; &times; Rp 3.600.000)</td><td class="n"><b class="hijau">Rp 14.400.000</b></td></tr>
                <tr><td>c. PPN 12% Sewa</td><td class="n">Rp 1.584.000</td></tr>
                <tr><td>d. Biaya Listrik</td><td class="n">Rp 150.000</td></tr>
                <tr><td>e. PPN 12% Listrik</td><td class="n">Rp 16.500</td></tr>
                <tr><td>f. Total Biaya Sewa</td><td class="n">Rp 14.550.000</td></tr>
                <tr><td>g. Total Setelah PPN</td><td class="n">Rp 16.150.500</td></tr>
                <tr><td colspan="2">&nbsp;</td></tr>
            </table>
        </td>
    </tr>
</table>
<p style="margin-top:-2px">Baris <b>b</b> dan <b>c</b> isinya persis sama. Itu bukan dua tagihan &mdash;
itu satu angka yang ditulis dua kali, karena kontraknya cuma <b>1 bulan</b>: harga sebulan memang
sama dengan harga seluruh periode. Client yang membacanya menghitung ulang mencari bedanya,
dan tidak ada bedanya.</p>

<div class="box ok">
    <p style="margin:0"><span class="tebal">Yang sekarang dilakukan sistem sendiri.</span>
    Baris yang nilainya sama dengan baris di bawahnya dilebur. Keterangan dalam kurung
    <b>tidak hilang</b> &mdash; ia pindah ke baris yang bertahan, jadi client tetap bisa menghitung
    ulang sendiri. Huruf urutnya dibagikan setelah peleburan, sehingga tidak pernah meloncat (a, b, d).</p>
    <p style="margin:5px 0 0"><span class="tebal">Nominalnya tidak ada yang berubah.</span>
    Yang hilang barisnya, bukan uangnya. Total, PPN, deposit, dan Grand Total sama persis seperti
    sebelumnya &mdash; termasuk pada surat lama yang dicetak ulang.</p>
</div>

<h2>2. Hasil pemeriksaan seluruh dokumen sejak <?= $h($sejakTeks) ?></h2>
<table>
    <tr><th>Properti</th><th class="n">Surat Penawaran diperiksa</th><th class="n">memuat baris kembar</th>
        <th class="n">SKP diperiksa</th><th class="n">memuat baris kembar</th></tr>
    <?php foreach ($perProp as [$nama, $x]): ?>
    <tr>
        <td><?= $h($nama) ?></td>
        <td class="n"><?= (int) $x['offer']['n'] ?></td>
        <td class="n"><b class="merah"><?= (int) $x['offer']['dobel'] ?></b></td>
        <td class="n"><?= (int) $x['skp']['n'] ?></td>
        <td class="n"><b class="merah"><?= (int) $x['skp']['dobel'] ?></b></td>
    </tr>
    <?php endforeach; ?>
    <tr style="background:#f1f5f9;font-weight:700">
        <td>SELURUHNYA</td>
        <td class="n"><?= (int) $semua['offer']['n'] ?></td>
        <td class="n"><?= (int) $semua['offer']['dobel'] ?></td>
        <td class="n"><?= (int) $semua['skp']['n'] ?></td>
        <td class="n"><?= (int) $semua['skp']['dobel'] ?></td>
    </tr>
</table>
<p class="muted" style="margin-top:-4px">
    Total <b><?= (int) ($semua['offer']['baris'] + $semua['skp']['baris']) ?> baris</b> dilebur.
    Pada dokumen <b>Gudang</b>, kolom &ldquo;Harga Sewa /Bulan&rdquo; yang isinya sama dengan
    &ldquo;Total Harga Sewa&rdquo; disembunyikan di <b><?= (int) $gdgKembar ?> dokumen</b>;
    <?= (int) $gdgTetap ?> dokumen yang masa sewanya lebih dari sebulan tetap menampilkannya.
</p>
<div class="box">
    <p style="margin:0">Nilai tertagih <b>sebelum</b> perbaikan <?= $rp($semua['uang_sebelum']) ?>,
    <b>sesudah</b> <?= $rp($semua['uang_sesudah']) ?> &mdash;
    <b class="hijau">selisih <?= $rp($semua['uang_sesudah'] - $semua['uang_sebelum']) ?></b>.
    Tidak ada satu baris pun di database yang diubah; yang berubah hanya cara suratnya dicetak.</p>
</div>

<h2>3. Yang perlu dilakukan saat mengisi</h2>

<p><span class="tebal">a. Kotak &ldquo;Harga / Bulan&rdquo; &mdash; kosongkan untuk kontrak 1 bulan.</span></p>
<table>
    <tr><th style="width:34%">Lama kontrak</th><th style="width:26%">Kotak Harga / Bulan</th><th>Alasan</th></tr>
    <tr><td>1 bulan</td><td><b>kosongkan</b></td><td>angkanya sama dengan Harga Sewa / Periode</td></tr>
    <tr><td>lebih dari 1 bulan</td><td>isi harga sebulan</td><td>client perlu tahu cicilan bulanannya</td></tr>
    <tr><td>setahun sekaligus, tidak ditagih bulanan</td><td><b>kosongkan</b></td>
        <td>surat tidak menyebut harga bulanan yang tak pernah disepakati</td></tr>
</table>
<p class="muted" style="margin-top:-4px">Kalau terlanjur diisi pada kontrak 1 bulan, suratnya tetap
benar &mdash; sistem meleburnya. Mengosongkannya hanya membuat niatmu terbaca jelas oleh pemeriksa.</p>

<p><span class="tebal">b. Pilih Dasar Harga yang benar, satu saja.</span>
<span class="muted">Per m&sup2; / hari &middot; Per m&sup2; / bulan &middot; Per bulan &middot;
Per slot / hari &middot; Per titik / hari &middot; Lump sum.</span>
Dasar harga menentukan tulisan satuan di surat. Memilih <b>Per bulan</b> lalu juga mengisi
Harga / Bulan menghasilkan dua baris bernama hampir sama &mdash; sekarang dilebur, tapi lebih baik
tidak dibuat sejak awal.</p>

<p><span class="tebal">c. Luas diisi angka saja.</span> Tulis <b>4</b>, bukan 4,00 atau 4.00.
Desimal hanya ditulis kalau memang diukur, mis. <b>14,62</b>. Surat, formulir, dan daftar Master
sekarang mencetak &ldquo;4 m&sup2;&rdquo;, bukan &ldquo;4,00 m&sup2;&rdquo;.</p>

<p><span class="tebal">d. Harga Nego Final hanya diisi bila beda dari kalkulasi.</span>
Mengisinya dengan angka yang sama persis dengan hasil kalkulasi tidak menambah apa pun di surat,
dan membuat kolom tarif tidak lagi mencerminkan harga asli.</p>

<h2>4. Memeriksa sendiri</h2>
<p>Di server, dari Terminal cPanel:</p>
<div class="box">
    <p class="srt" style="margin:0">php scripts/cek_rincian_double.php --sejak=<?= $h($sejak) ?> --rinci</p>
</div>
<p style="margin-top:-2px">Skrip ini <b>membaca saja</b> &mdash; tidak menulis satu baris pun ke
database. Ia menyebut berapa surat yang memuat baris kembar, berapa baris yang dilebur, dan
membuktikan nilai tertagih sebelum &amp; sesudah sama persis (<b>Selisih Rp 0</b>).</p>

<h2>5. Kalau masih terlihat dobel</h2>
<ol>
    <li><b>Hard refresh sekali</b> (Cmd/Ctrl + Shift + R) setelah deploy. Tanpa itu browser masih
        memakai skrip lama.</li>
    <li>Pastikan yang dilihat bukan <b>dua tingkatan tarif</b> yang kebetulan bernilai sama &mdash;
        itu memang harus tercetak dua-duanya, dan sengaja tidak dilebur.</li>
    <li>Pada <b>Detail Alokasi</b>, satu bulan yang dulu muncul dua kali kini digabung jadi satu
        baris, ditandai &ldquo;(2 potongan siklus)&rdquo;. Kalau masih dua baris, periksa kolom
        <b>PIC</b>: income yang dibagi ke dua PIC memang punya satu baris per PIC, dan subtotal
        bulannya dicetak tepat di bawahnya.</li>
</ol>
<?php
$html = ob_get_clean();

$mpdf = clara_letterhead_mpdf();
$mpdf->SetTitle('Cara Isi Harga Tanpa Baris Dobel');
$mpdf->WriteHTML($html);
$mpdf->Output($opt['keluar'], \Mpdf\Output\Destination::FILE);

echo "PDF dibuat: " . $opt['keluar'] . "\n";
