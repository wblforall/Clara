<?php
/**
 * Tutorial: cara mengisi harga di CLARA supaya tidak ada angka yang tercetak
 * dua kali.
 *
 * Isinya tutorial LANGKAH DEMI LANGKAH — kotak demi kotak, persis seperti nama
 * yang tertulis di layar — lalu ditutup hasil pemeriksaan seluruh dokumen.
 * Angka pemeriksaannya dibaca LANGSUNG dari database saat dicetak lewat
 * cek_rincian_pindai(), fungsi yang sama dengan yang dipakai surat untuk
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

/** Satu langkah tutorial: nomor, nama kotak di layar, isi penjelasan. */
$langkah = function (int $no, string $kotak, string $isi): string {
    return '<table class="lk"><tr>'
         . '<td class="no">' . $no . '</td>'
         . '<td class="isi"><div class="kotak">' . $kotak . '</div>' . $isi . '</td>'
         . '</tr></table>';
};
/** Contoh baris yang tercetak di surat. */
$cetak = function (string $judul, array $baris): string {
    // Dibungkus TABEL, bukan div: mPDF menggambar garis tabel dengan andal,
    // sedangkan border pada div di dalam sel tabel tidak pernah muncul.
    $x = '<table class="ctk"><tr><td class="ctkc"><div class="cetak-j">' . $judul
       . '</div><table class="srt">';
    foreach ($baris as [$l, $n]) {
        $x .= '<tr><td>' . $l . '</td><td class="n">' . $n . '</td></tr>';
    }
    return $x . '</table></td></tr></table>';
};

ob_start(); ?>
<style>
    body { font-family: sans-serif; font-size: 10.2pt; color: #111; line-height: 1.42; }
    h1 { font-size: 16pt; margin: 0 0 2px; }
    h2 { font-size: 12.5pt; margin: 13px 0 5px; color: #0f766e; border-bottom: 1px solid #99f6e4; padding-bottom: 3px; page-break-after: avoid; }
    h3 { font-size: 10.8pt; margin: 9px 0 3px; color: #334155; page-break-after: avoid; }
    .sub { color: #555; font-size: 9.5pt; margin-bottom: 9px; }
    table.d { width: 100%; border-collapse: collapse; margin: 5px 0 7px; font-size: 9.3pt; page-break-inside: avoid; }
    table.d th, table.d td { border: 1px solid #cbd5e1; padding: 3px 6px; text-align: left; vertical-align: top; }
    table.d th { background: #f1f5f9; }
    table.d td.n { text-align: right; white-space: nowrap; }
    .box { border: 1px solid #cbd5e1; border-left: 3px solid #0D9488; background: #f8fafc; padding: 6px 10px; margin: 6px 0; page-break-inside: avoid; }
    .warn { border-left-color: #b45309; background: #fffbeb; }
    .ok   { border-left-color: #16a34a; background: #f0fdf4; }
    .muted { color: #666; }
    .tebal { font-weight: 700; }
    .merah { color: #b91c1c; }
    .hijau { color: #166534; }
    ol, ul { margin: 4px 0 7px 17px; padding: 0; }
    li { margin-bottom: 3px; }
    p { margin: 5px 0; }
    /* Satu langkah: nomor bulat di kiri, isinya di kanan. */
    table.lk { width: 100%; border-collapse: collapse; margin: 7px 0; page-break-inside: avoid; }
    table.lk td { border: 0; vertical-align: top; padding: 0; }
    table.lk td.no { width: 17px; font-size: 12pt; font-weight: 700; color: #0D9488; padding-right: 7px; }
    table.lk td.isi { font-size: 9.9pt; }
    .kotak { font-weight: 700; font-size: 10.4pt; margin-bottom: 1px; }
    .kotak .di { font-weight: 400; font-size: 8.8pt; color: #64748b; }
    /* Contoh hasil cetak surat. */
    table.ctk { width: 100%; border-collapse: collapse; margin: 4px 0 3px; page-break-inside: avoid; }
    table.ctk td.ctkc { border: 1px solid #94a3b8; background: #f8fafc; padding: 4px 8px; }
    .cetak-j { font-size: 8pt; text-transform: uppercase; letter-spacing: .05em; color: #64748b; margin-bottom: 2px; }
    table.srt { width: 100%; border-collapse: collapse; font-family: monospace; font-size: 8.4pt; line-height: 1.4; }
    table.srt td { border: 0; padding: 1px 0; }
    table.srt td.n { text-align: right; white-space: nowrap; }
</style>

<h1>Cara Mengisi Harga di CLARA</h1>
<div class="sub">Tutorial langkah demi langkah &mdash; supaya tidak ada satu angka pun yang
    tercetak dua kali di surat<br>
    Untuk tim Casual Leasing e-Walk &amp; Pentacity &middot; dibuat <?= date('d/m/Y H:i') ?> &middot;
    angka pemeriksaan di halaman terakhir dibaca langsung dari database CLARA saat dicetak.</div>

<div class="box">
    <p style="margin:0"><span class="tebal">Aturan satu kalimat:</span> setiap angka diketik
    <b>di satu kotak saja</b>. Kalau sebuah kotak isinya akan sama persis dengan kotak lain,
    <b>kosongkan</b> &mdash; yang kosong tidak tercetak, dan surat jadi mudah dibaca client.</p>
</div>

<h2>Kartu contekan</h2>
<table class="d">
    <tr><th style="width:27%">Kotak di layar</th><th style="width:30%">Diisi kapan</th>
        <th style="width:25%">Dikosongkan kapan</th><th>Kalau kosong</th></tr>
    <tr><td><b>Rate</b><br><span class="muted">Surat Penawaran</span></td>
        <td>selalu &mdash; ini tarif dasarnya</td><td>harga borongan (Lump sum)</td>
        <td>baris tarif tidak tercetak</td></tr>
    <tr><td><b>Harga / Bulan</b><br><span class="muted">Surat Penawaran</span></td>
        <td>kontrak lebih dari 1 bulan yang ditagih bulanan</td>
        <td><b>kontrak 1 bulan</b>, atau dibayar sekaligus di muka</td>
        <td>baris &ldquo;Harga Sewa / Bulan&rdquo; tidak tercetak</td></tr>
    <tr><td><b>Harga Nego Final</b><br><span class="muted">Surat Penawaran</span></td>
        <td>nilai akhirnya beda dari hasil kalkulasi</td>
        <td>nilainya sama dengan kalkulasi</td>
        <td>dipakai Total Kontrak hasil kalkulasi</td></tr>
    <tr><td><b>Total Biaya Sewa</b><br><span class="muted">SKP</span></td>
        <td>nilainya memang beda dari tarif &times; periode</td>
        <td>sama dengan hitungan otomatis</td>
        <td>dihitung sendiri dari tarif &times; periode</td></tr>
    <tr><td><b>Harga Sewa /Bulan</b><br><span class="muted">Gudang (SKS)</span></td>
        <td>sewa lebih dari 1 bulan</td><td><b>sewa 1 bulan</b></td>
        <td>kolomnya hilang dari tabel harga</td></tr>
    <tr><td><b>Luas (m&sup2;)</b><br><span class="muted">semua formulir</span></td>
        <td>tulis angkanya saja: <b>4</b>, atau <b>14,62</b> bila memang diukur</td>
        <td>&mdash;</td><td>tercetak &ldquo;4 m&sup2;&rdquo;, bukan &ldquo;4,00 m&sup2;&rdquo;</td></tr>
</table>

<h2>Bagian 1 &mdash; Surat Penawaran (Pameran)</h2>
<p class="muted" style="margin-top:0">Contoh yang dipakai sepanjang bagian ini: Atrium GF,
<b>4 m&sup2;</b>, <b>1 bulan</b>, tarif <b>Rp 3.600.000 / m&sup2; / bulan</b>.</p>

<?= $langkah(1, 'Unit / Lokasi <span class="di">&mdash; bagian Spesifikasi</span>',
    '<p style="margin:2px 0">Pilih unitnya dari daftar. <b>Luas (m&sup2;)</b> dan <b>Rate</b> terisi
     sendiri dari Master. Kalau luasnya perlu diubah, tulis angkanya saja &mdash; <b>4</b>, bukan
     <code>4,00</code>. Desimal hanya ditulis kalau memang hasil ukur, mis. <b>14,62</b>.</p>') ?>

<?= $langkah(2, 'Dasar Harga Sewa <span class="di">&mdash; pilih SATU</span>',
    '<p style="margin:2px 0">Pilihan ini menentukan <b>arti kotak Rate</b> di sebelahnya sekaligus
     satuan yang tercetak di surat. Salah pilih di sini, seluruh perhitungan ikut salah.</p>'
    . '<table class="d"><tr><th style="width:33%">Pilihan</th><th style="width:30%">Artinya kotak Rate</th><th>Tercetak di surat</th></tr>'
    . '<tr><td>Per m&sup2; / hari <span class="muted">(bawaan)</span></td><td>harga 1 m&sup2; sehari</td><td>Biaya Sewa / m&sup2; / hari</td></tr>'
    . '<tr><td>Per m&sup2; / bulan</td><td>harga 1 m&sup2; sebulan</td><td>Biaya Sewa / m&sup2; / bulan</td></tr>'
    . '<tr><td>Per slot / hari</td><td>harga 1 slot sehari</td><td>Biaya Sewa / slot / hari</td></tr>'
    . '<tr><td>Per titik / hari</td><td>harga 1 titik sehari</td><td>Biaya Sewa / titik / hari</td></tr>'
    . '<tr><td>Per bulan <span class="muted">(tanpa hitungan luas)</span></td><td>harga sebulan, luas tidak dikali</td><td>Biaya Sewa / bulan</td></tr>'
    . '<tr><td>Lump sum <span class="muted">(satu nilai)</span></td><td>&mdash; tidak dipakai</td><td>Nilai Kontrak (lump sum)</td></tr>'
    . '</table>'
    . '<div class="box warn"><p style="margin:0">Memilih <b>Per bulan</b> lalu <i>juga</i> mengisi
     <b>Harga / Bulan</b> menghasilkan dua baris bernama hampir sama dengan angka yang sama.
     Sekarang keduanya dilebur sendiri oleh sistem, tetapi lebih baik tidak dibuat sejak awal.</p></div>') ?>

<?= $langkah(3, 'Rate',
    '<p style="margin:2px 0">Isi tarif sesuai Dasar Harga yang baru dipilih &mdash; untuk contoh kita:
     <b>3.600.000</b>. Kotak ini <b>boleh dikosongkan</b>; kalau kosong, baris tarifnya tidak
     tercetak di surat, PDF, maupun halaman tanda tangan client.</p>') ?>

<?= $langkah(4, 'Tanggal Mulai &amp; Tanggal Selesai',
    '<p style="margin:2px 0">Begitu keduanya terisi, <b>Total Kontrak</b> di bagian Harga terhitung
     sendiri (tarif &times; luas &times; periode) dan <b>tidak bisa diketik</b>. Jangan mengetik ulang
     angka itu di kotak lain mana pun.</p>') ?>

<?= $langkah(5, 'Harga / Bulan <span class="di">&mdash; inilah kotak yang paling sering bikin dobel</span>',
    '<table class="d"><tr><th style="width:36%">Lama kontrak</th><th style="width:24%">Kotak Harga / Bulan</th><th>Alasan</th></tr>'
    . '<tr><td><b>1 bulan</b></td><td class="merah"><b>kosongkan</b></td><td>angkanya sama persis dengan Harga Sewa / Periode</td></tr>'
    . '<tr><td>lebih dari 1 bulan, ditagih bulanan</td><td class="hijau"><b>isi</b> harga sebulan</td><td>client perlu tahu cicilan tiap bulannya</td></tr>'
    . '<tr><td>setahun sekaligus, tidak ditagih bulanan</td><td class="merah"><b>kosongkan</b></td><td>surat tidak menyebut angka bulanan yang tidak pernah disepakati</td></tr>'
    . '</table>'
    . $cetak('Kontrak 1 bulan &mdash; Harga / Bulan DIISI (dulu)', [
        ['a. Biaya Sewa / m&sup2; / bulan', 'Rp 3.600.000'],
        ['b. Harga Sewa / Bulan', '<b class="merah">Rp 14.400.000</b>'],
        ['c. Harga Sewa / Periode', '<b class="merah">Rp 14.400.000</b>'],
      ])
    . $cetak('Kontrak 1 bulan &mdash; Harga / Bulan DIKOSONGKAN (benar)', [
        ['a. Biaya Sewa / m&sup2; / bulan', 'Rp 3.600.000'],
        ['b. Harga Sewa / Periode', '<b class="hijau">Rp 14.400.000</b>'],
      ])
    . $cetak('Kontrak 6 bulan &mdash; Harga / Bulan DIISI (benar)', [
        ['a. Biaya Sewa / m&sup2; / bulan', 'Rp 3.600.000'],
        ['b. Harga Sewa / Bulan', 'Rp 14.400.000'],
        ['c. Harga Sewa / Periode (6 bulan)', 'Rp 86.400.000'],
      ])) ?>

<?= $langkah(6, 'Harga Nego Final <span class="di">&mdash; opsional</span>',
    '<p style="margin:2px 0">Diisi <b>hanya bila</b> nilai akhir yang disepakati berbeda dari
     Total Kontrak hasil kalkulasi. Mengisinya dengan angka yang sama persis tidak menambah apa pun
     di surat, dan membuat kolom tarif tidak lagi mencerminkan harga sebenarnya.</p>
     <p style="margin:2px 0">Kalau yang turun adalah <b>tarifnya</b> (bukan totalnya), pakai
     <b>Harga Diskon</b>: kotak <b>Rate</b> diisi tarif publish, dan <b>Tarif setelah diskon</b>
     diisi tarif nego. Surat lalu mencetak tarif turun dari berapa ke berapa beserta rupiah
     potongannya &mdash; satu baris, bukan dua angka yang saling bertabrakan.</p>') ?>

<?= $langkah(7, 'Lihat Contoh Surat <span class="di">&mdash; sebelum Simpan</span>',
    '<p style="margin:2px 0">Tombol ini mencetak surat persis seperti yang nanti terbit, bercap
     melintang <b>CONTOH &mdash; BELUM SAH</b> dan tanpa nomor. Baca blok <b>Rincian Biaya</b>-nya
     sekali: kalau ada dua baris bernominal sama, berarti ada kotak yang terisi dua kali.</p>') ?>

<h2>Bagian 2 &mdash; SKP (Surat Konfirmasi Pemesanan)</h2>
<p style="margin-top:0">SKP lahir dari Surat Penawaran yang sudah ditandatangani client, jadi
harganya <b>sudah terbawa</b>. Yang masih boleh disentuh hanya ini:</p>
<table class="d">
    <tr><th style="width:30%">Kotak</th><th>Kapan diubah</th></tr>
    <tr><td><b>Dasar Harga Sewa</b></td><td>hanya bila di penawaran memang salah pilih</td></tr>
    <tr><td><b>Jumlah Bulan Kontrak</b></td><td>kosongkan saja &mdash; terhitung sendiri dari tanggalnya</td></tr>
    <tr><td><b>Total Biaya Sewa</b><br><span class="muted">(kosongkan = hitung otomatis)</span></td>
        <td><b>kosongkan</b> bila sama dengan hitungan otomatis. Diisi hanya kalau nilai yang
            disepakati memang beda &mdash; mis. ada pembulatan ke bawah</td></tr>
    <tr><td><b>Jaminan / Security Deposit</b></td>
        <td>isi nominalnya. Kalau sudah disetor di kontrak sebelumnya, centang
            <b>Sudah dibayarkan</b> &mdash; tetap tercetak sebagai keterangan, tapi tidak ditagih ulang</td></tr>
</table>
<div class="box ok"><p style="margin:0">Blok <b>Rincian Pembayaran Sewa</b> di SKP menurunkan
    hurufnya (a, b, c &hellip;) <b>setelah</b> baris kembar dilebur, jadi urutannya tidak pernah
    meloncat. Keterangan dalam kurung &mdash; mis. &ldquo;(4 m&sup2; &times; Rp 3.600.000)&rdquo; &mdash;
    ikut pindah ke baris yang bertahan, sehingga client tetap bisa menghitung ulang sendiri.</p></div>

<h2>Bagian 3 &mdash; Gudang (Surat Konfirmasi Sewa)</h2>
<p style="margin-top:0">Tabel harganya punya tiga kolom nominal. Isi <b>dua</b>, bukan tiga:</p>
<table class="d">
    <tr><th style="width:28%">Kolom</th><th style="width:22%">Sewa 1 bulan</th><th style="width:22%">Sewa &gt; 1 bulan</th><th>Keterangan</th></tr>
    <tr><td>Harga Sewa /m&sup2;/Bulan</td><td>isi</td><td>isi</td><td>tarif dasarnya</td></tr>
    <tr><td>Harga Sewa /Bulan</td><td class="merah"><b>kosongkan</b></td><td class="hijau"><b>isi</b></td>
        <td>sama dengan Total kalau cuma sebulan</td></tr>
    <tr><td>Total Harga Sewa</td><td>isi</td><td>isi</td><td>yang ditagih</td></tr>
</table>
<p style="margin-top:-2px">Kalau <b>semua</b> baris di tabel itu isinya &ldquo;Harga Sewa /Bulan&rdquo;
sama dengan &ldquo;Total Harga Sewa&rdquo;, kolom tengahnya <b>disembunyikan sendiri</b> saat
dicetak. Yang hilang kolomnya &mdash; angkanya tidak diubah sama sekali.</p>

<h2>Bagian 4 &mdash; Media (Form Utilities)</h2>
<?= $langkah(1, 'Tarif &amp; Total Nilai Sewa',
    '<p style="margin:2px 0"><b>Tarif</b> diisi harga per hari/titik; <b>Total Nilai Sewa</b>
     terhitung sendiri dari tarif &times; periode. Ketik ulang Total hanya kalau nilainya memang
     beda &mdash; begitu diketik, ia tidak ditimpa lagi oleh hitungan otomatis.</p>') ?>
<?= $langkah(2, 'Daftar Utilities / Media Promo / Parkir Kendaraan',
    '<p style="margin:2px 0">Centang yang dipakai, isi <b>Biaya</b> dan <b>Satuan</b> (mis.
     &ldquo;/Hari&rdquo;). Biaya item yang dicentang <b>ikut menambah Total Nilai Sewa</b> &mdash;
     jadi jangan menambahkannya sekali lagi dengan tangan ke kotak Total.</p>
     <p style="margin:2px 0">Item boleh dicentang <b>tanpa biaya</b>: biayanya dikosongkan saja,
     dan tidak ada angka yang masuk.</p>') ?>
<?= $langkah(3, 'Sembunyikan harga di surat <span class="di">&mdash; saklar di tiap judul kelompok</span>',
    '<p style="margin:2px 0">Dicentang &rarr; kolom biaya kelompok itu <b>tidak dicetak</b> dan
     biayanya <b>tidak ikut menambah</b> Total Nilai Sewa. Harga yang sudah diketik tetap
     tersimpan, jadi saklarnya bisa dilepas lagi tanpa mengetik ulang.</p>
     <p style="margin:2px 0">Dipakai untuk penyewa yang cukup diberi tahu fasilitas apa yang
     dipakai, sedangkan tarif tiap fasilitas bukan urusannya.</p>') ?>
<?= $langkah(4, 'Rincian Biaya',
    $cetak('Yang tercetak di halaman terakhir Form Utilities', [
        ['Biaya / Hari', 'Rp. 25.000.000'],
        ['Total Biaya Sewa', 'Rp. 100.000.000'],
        ['PPN 12%', 'Rp. 11.000.000'],
        ['Jaminan Area (Security Deposit)', 'Rp. 10.000.000'],
        ['<b>Grand Total</b>', '<b>Rp. 121.000.000</b>'],
      ])
    . '<p style="margin:3px 0 0">Baris pertama mengikuti Dasar Harga &mdash; kontrak bulanan
     tercetak &ldquo;Biaya / Bulan&rdquo;. <b>PPN 12%</b> dihitung nilai &times; 11/12 &times; 12%
     (jadi 11% dari nilai sewa), sesuai PMK No. 131 Tahun 2024. <b>Grand Total</b> sudah termasuk
     jaminan; jaminan yang sudah disetor tetap tercetak tapi tidak ditagih ulang.</p>') ?>

<h2>Bagian 5 &mdash; tiga kesalahan yang paling sering</h2>
<table class="d">
    <tr><th style="width:38%">Kesalahan</th><th style="width:30%">Yang terjadi di surat</th><th>Benarnya</th></tr>
    <tr><td>Harga / Bulan diisi pada kontrak 1 bulan</td>
        <td>dua baris bernominal sama persis</td><td>kosongkan kotaknya</td></tr>
    <tr><td>Harga Nego Final diisi angka yang sama dengan Total Kontrak</td>
        <td>kolom tarif tidak lagi mencerminkan harga asli</td><td>kosongkan kotaknya</td></tr>
    <tr><td>Luas ditulis <code>4,00</code></td>
        <td>tercetak &ldquo;4,00 m&sup2;&rdquo;</td><td>tulis <b>4</b></td></tr>
</table>
<div class="box ok">
    <p style="margin:0"><span class="tebal">Kalau terlanjur salah, suratnya tetap benar.</span>
    Baris yang nilainya sama dengan baris di bawahnya dilebur sendiri oleh sistem saat dicetak,
    termasuk pada surat lama yang dicetak ulang. Mengisi dengan benar sejak awal hanya membuat
    niat kita terbaca jelas oleh pemeriksa.</p>
    <p style="margin:5px 0 0"><span class="tebal">Nominalnya tidak ada yang berubah.</span>
    Yang hilang barisnya, bukan uangnya &mdash; Total, PPN, deposit, dan Grand Total sama persis.</p>
</div>

<h2>Bagian 6 &mdash; hasil pemeriksaan seluruh dokumen sejak <?= $h($sejakTeks) ?></h2>
<table class="d">
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

<h2>Bagian 7 &mdash; memeriksa sendiri</h2>
<p style="margin-top:0">Di server, lewat Terminal cPanel:</p>
<div class="box">
    <p style="margin:0;font-family:monospace;font-size:8.6pt">php scripts/cek_rincian_double.php --sejak=<?= $h($sejak) ?> --rinci</p>
</div>
<p style="margin-top:-2px">Skrip ini <b>membaca saja</b> &mdash; tidak menulis satu baris pun ke
database. Ia menyebut berapa surat yang memuat baris kembar, berapa baris yang dilebur, dan
membuktikan nilai tertagih sebelum &amp; sesudah sama persis (<b>Selisih Rp 0</b>).</p>

<h3>Kalau masih terlihat dobel</h3>
<ol>
    <li><b>Hard refresh sekali</b> (Cmd/Ctrl + Shift + R) setelah deploy. Tanpa itu browser masih
        memakai skrip lama.</li>
    <li>Pastikan yang dilihat bukan <b>dua tingkatan tarif</b> yang kebetulan bernilai sama &mdash;
        itu memang harus tercetak dua-duanya, dan sengaja tidak dilebur.</li>
    <li>Pada <b>Detail Alokasi</b>, satu bulan yang dulu muncul dua kali kini digabung jadi satu
        baris, ditandai &ldquo;(2 potongan siklus)&rdquo;. Kalau masih dua baris, periksa kolom
        <b>PIC</b>: income yang dibagi ke dua PIC memang punya satu baris per PIC, dan subtotal
        bulannya dicetak tepat di bawahnya.</li>
    <li>Kalau tetap ada dua baris bernominal sama yang bukan dua tingkatan tarif, kirimkan nomor
        suratnya &mdash; itu pola baru yang belum dikenali.</li>
</ol>

<h2>Daftar periksa sebelum menekan Simpan</h2>
<table class="d">
    <tr><td style="width:17px;text-align:center">&#9633;</td><td><b>Luas</b> ditulis angka saja &mdash; <code>4</code>, bukan <code>4,00</code>.</td></tr>
    <tr><td style="text-align:center">&#9633;</td><td><b>Dasar Harga Sewa</b> sudah benar, dan cuma satu yang dipilih.</td></tr>
    <tr><td style="text-align:center">&#9633;</td><td><b>Rate</b> terisi sesuai Dasar Harga tadi &mdash; bukan harga sebulan dimasukkan ke kotak per hari.</td></tr>
    <tr><td style="text-align:center">&#9633;</td><td><b>Harga / Bulan</b> <u>kosong</u> kalau kontraknya 1 bulan, atau kalau dibayar sekaligus di muka.</td></tr>
    <tr><td style="text-align:center">&#9633;</td><td><b>Harga Nego Final</b> <u>kosong</u> kalau nilainya sama dengan Total Kontrak.</td></tr>
    <tr><td style="text-align:center">&#9633;</td><td>SKP: <b>Total Biaya Sewa</b> <u>kosong</u> kalau sama dengan hitungan otomatis.</td></tr>
    <tr><td style="text-align:center">&#9633;</td><td>Gudang: <b>Harga Sewa /Bulan</b> <u>kosong</u> kalau sewanya 1 bulan.</td></tr>
    <tr><td style="text-align:center">&#9633;</td><td>Media: <b>Total Nilai Sewa</b> tidak diketik ulang setelah biaya item dicentang.</td></tr>
    <tr><td style="text-align:center">&#9633;</td><td>Sudah menekan <b>Lihat Contoh Surat</b> dan membaca blok <b>Rincian Biaya</b>-nya.</td></tr>
    <tr><td style="text-align:center">&#9633;</td><td>Di contoh surat itu <b>tidak ada dua baris bernominal sama</b>.</td></tr>
</table>
<p class="muted" style="margin-top:-2px">Halaman ini boleh dicetak sendiri dan ditempel di meja.</p>
<?php
$html = ob_get_clean();

$mpdf = clara_letterhead_mpdf();
$mpdf->SetTitle('Cara Mengisi Harga di CLARA');
$mpdf->WriteHTML($html);
$mpdf->Output($opt['keluar'], \Mpdf\Output\Destination::FILE);

echo "PDF dibuat: " . $opt['keluar'] . "\n";
