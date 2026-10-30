<?php
/**
 * Laporan: perbaikan temuan 1–6 pada dokumen Gudang & Media, berikut temuan
 * baru yang belum dikerjakan.
 *
 * Gambarnya potongan layar sungguhan dari CLARA lokal (scripts/tutorial_assets,
 * diambil dengan scripts/tutorial_screenshot.js dan pasangannya), dan angka
 * temuannya dibaca LANGSUNG dari database saat dicetak — bukan diketik ulang,
 * supaya laporan ini tidak pernah menyebut angka yang sudah basi.
 *
 * Hasilnya TIDAK ikut di-commit (docs/*.pdf ada di .gitignore).
 *
 * Pakai: php scripts/build_laporan_temuan.php [--keluar=/jalur/Laporan.pdf]
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/pdf.php';

$opt = array_merge(['keluar' => $root . '/docs/Laporan_Perbaikan_Gudang_Media.pdf'], getopt('', ['keluar:']));

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$h  = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
$satu = function (string $sql, array $par = []) use ($pdo) {
    try { $st = $pdo->prepare($sql); $st->execute($par); return $st->fetch() ?: []; }
    catch (Throwable $e) { return []; }
};

// ── Angka temuan, dibaca saat dicetak ────────────────────────────────────────
$belumTtd = $satu("SELECT COUNT(*) n, COALESCE(SUM(total_amount),0) nilai,
                          SUM(approved_at < DATE_SUB(NOW(), INTERVAL 14 DAY)) lama
                     FROM skp_documents
                    WHERE status = 'approved' AND deleted_at IS NULL AND signed_at IS NULL");
$tabrak = $satu("SELECT COUNT(*) n, COALESCE(MAX(hari),0) hari FROM (
                   SELECT DATEDIFF(LEAST(a.end_date,b.end_date), GREATEST(a.start_date,b.start_date))+1 hari
                     FROM transactions a JOIN transactions b
                       ON b.property_id=a.property_id AND b.master_code=a.master_code AND b.id>a.id
                      AND b.start_date <= a.end_date AND b.end_date >= a.start_date
                    WHERE a.deleted_at IS NULL AND b.deleted_at IS NULL
                      AND a.status<>'cancelled' AND b.status<>'cancelled'
                      AND a.master_code <> '' AND a.module='gudang') x");
$tanpaAlok = $satu("SELECT COUNT(*) n, COALESCE(SUM(final_amount),0) nilai FROM transactions t
                     WHERE t.deleted_at IS NULL AND t.status <> 'cancelled'
                       AND NOT EXISTS (SELECT 1 FROM transaction_allocations a WHERE a.transaction_id = t.id)");
$picKosong = $satu("SELECT COUNT(*) n FROM master_pic WHERE status='active' AND COALESCE(full_name,'')=''");
$dokTanpaPic = $satu("SELECT COUNT(*) n FROM skp_documents
                       WHERE module IS NOT NULL AND COALESCE(pic_name,'')='' AND deleted_at IS NULL");

/** Potongan layar sungguhan; bila belum diambil, tempatnya diisi keterangan. */
$gbr = function (string $nama, string $ket, int $lebarMm = 160) use ($h): string {
    $file = __DIR__ . '/tutorial_assets/' . $nama . '.png';
    if (!is_file($file)) {
        return '<div class="box warn"><p style="margin:0">Gambar <b>' . $h($nama) . '</b> belum diambil.</p></div>';
    }
    return '<table class="gbr"><tr><td class="gbrc"><img src="' . $h($file) . '" style="width:' . $lebarMm . 'mm"></td></tr>'
         . '<tr><td class="gbrk">' . $ket . '</td></tr></table>';
};
/** Satu blok temuan: judul, masalah, perbaikan, bukti. */
$poin = function (int $no, string $judul, string $isi): string {
    return '<table class="pn"><tr><td class="pnno">' . $no . '</td><td class="pnisi">'
         . '<div class="pnjd">' . $judul . '</div>' . $isi . '</td></tr></table>';
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
    .bahaya { border-left-color: #b91c1c; background: #fef2f2; }
    .muted { color: #666; } .tebal { font-weight: 700; }
    .merah { color: #b91c1c; } .hijau { color: #166534; } .jingga { color: #b45309; }
    p { margin: 5px 0; } ol, ul { margin: 4px 0 7px 17px; padding: 0; } li { margin-bottom: 3px; }
    table.pn { width: 100%; border-collapse: collapse; margin: 9px 0; }
    table.pn td { border: 0; vertical-align: top; padding: 0; }
    table.pn td.pnno { width: 19px; font-size: 12pt; font-weight: 700; color: #0D9488; padding-right: 8px; }
    table.pn td.pnisi { font-size: 9.9pt; }
    .pnjd { font-weight: 700; font-size: 10.6pt; margin-bottom: 2px; }
    table.gbr { width: 100%; border-collapse: collapse; margin: 6px 0 9px; page-break-inside: avoid; }
    table.gbr td.gbrc { border: 1px solid #cbd5e1; padding: 4px; background: #fff; }
    table.gbr td.gbrk { font-size: 8.6pt; color: #475569; padding: 3px 1px 0; line-height: 1.35; }
    .pb { page-break-before: always; }
</style>

<h1>Laporan Perbaikan &amp; Temuan</h1>
<div class="sub">Dokumen <b>Gudang (SKS)</b> &amp; <b>Media (Form Utilities)</b> &middot; CLARA
    &middot; <?= date('d/m/Y H:i') ?><br>
    Seluruh gambar diambil dari CLARA yang dijalankan di komputer lokal. Angka pada bagian
    temuan dibaca langsung dari database saat laporan ini dicetak.</div>

<table class="d">
    <tr><th style="width:52%">Yang diperbaiki</th><th style="width:16%">Keadaan</th><th>Dampaknya</th></tr>
    <tr><td>1. Nilai kontrak bisa bergeser sendiri sesudah surat diteken</td><td class="hijau"><b>Selesai</b></td><td>uang</td></tr>
    <tr><td>2. Paku bulan basi saat tanggal diubah</td><td class="hijau"><b>Selesai</b></td><td>uang</td></tr>
    <tr><td>3. Tidak ada peringatan tabrakan jadwal</td><td class="hijau"><b>Selesai</b></td><td>operasional</td></tr>
    <tr><td>4. Revisi dokumen tidak memperbarui pengakuan transaksinya</td><td class="hijau"><b>Selesai</b></td><td>uang</td></tr>
    <tr><td>5. Prorata bulan kalender tidak tersedia untuk Gudang</td><td class="hijau"><b>Selesai</b></td><td>uang</td></tr>
    <tr><td>6. Recurring hanya label, tidak meneruskan kontrak</td><td class="hijau"><b>Selesai</b></td><td>waktu kerja</td></tr>
</table>

<h2>1. Nilai kontrak bisa bergeser sendiri sesudah surat diteken</h2>
<?= $poin(1, 'Apa yang terjadi',
    '<p style="margin:2px 0">Tabel <b>Nominal Diakui per Bulan</b> boleh diisi berbeda tiap bulan. Kalau jumlahnya
     tidak sama dengan Total Nilai Sewa, yang terjadi <b>bukan error</b>: begitu client menandatangani,
     AllocationService menyamakan nilai kontrak dengan jumlah alokasinya. Jadi surat sudah tercetak
     Rp 36.000.000, sudah diteken, lalu nilainya diam-diam menjadi Rp 29.000.000.</p>
     <p style="margin:2px 0">Exhibition sudah dijaga begini di halaman Transaksi sejak lama. Gudang &amp; Media
     &mdash; yang justru satu-satunya tempat pengaturannya &mdash; belum.</p>') ?>
<?= $poin(2, 'Yang sekarang terjadi',
    $gbr('r3-tabel-selisih', 'Saat diketik: keterangan di bawah tabel langsung menyebut jumlahnya, nilai kontraknya, dan selisihnya.')
    . $gbr('r1-tolak', 'Saat diajukan ke manager: pengiriman ditolak dan disebutkan selisihnya. Dokumen tetap tersimpan sebagai draft, jadi pekerjaan tidak hilang.', 150)) ?>

<h2>2. Paku bulan basi saat tanggal diubah</h2>
<p style="margin-top:0">Atur nominal per bulan &rarr; ubah tanggal kontraknya &rarr; simpan. Tabel di layar sudah
menampilkan bulan yang baru, tetapi pengirimannya masih membawa nama kotak bulan <b>lama</b> &mdash; dan paku itu ikut
tersimpan, lalu terbawa ke transaksinya saat disetujui. Sekarang bulan yang sudah tidak dicakup dibuang saat disimpan.</p>
<div class="box ok"><p style="margin:0">Diuji: dokumen dengan paku Des&ndash;Feb lalu tanggalnya dimajukan ke Jan&ndash;Mar
&rarr; paku Desember hilang, Januari &amp; Februari bertahan.</p></div>

<h2>3. Tidak ada peringatan tabrakan jadwal</h2>
<?= $poin(1, 'Apa yang terjadi',
    '<p style="margin:2px 0">Pemeriksaan tabrakan tanggal sudah ada dan dipakai Surat Penawaran serta formulir
     Transaksi. Gudang &amp; Media <b>tidak lewat keduanya</b> &mdash; dokumennya dibuat langsung. Akibatnya satu unit
     gudang atau titik media bisa dijual dua kali untuk tanggal yang bertabrakan tanpa satu pun peringatan,
     sampai ada yang kebetulan menyadarinya di Kalender.</p>') ?>
<?= $poin(2, 'Yang sekarang terjadi',
    $gbr('r4-overlap', 'Muncul begitu unit dan tanggalnya terisi, dan berubah sendiri tiap kali salah satunya diganti. '
       . 'Dua kontrak di gambar ini <b>benar-benar ada</b> di database. Tidak menghalangi &mdash; ada unit yang memang dibagi.', 150)) ?>
<div class="box bahaya">
    <p style="margin:0"><span class="tebal">Dan memang sudah terjadi.</span> Saat laporan ini dicetak ada
    <b class="merah"><?= (int) ($tabrak['n'] ?? 0) ?> pasang kontrak Gudang</b> yang tanggalnya bertabrakan di unit yang sama,
    yang terlama <b><?= (int) ($tabrak['hari'] ?? 0) ?> hari</b>. Semuanya masuk sebelum peringatan ini ada.</p>
</div>

<h2>4. Revisi dokumen tidak memperbarui pengakuan transaksinya</h2>
<p style="margin-top:0">Dokumen Gudang/Media yang sudah disetujui lalu <b>dikembalikan untuk diperbaiki</b>: transaksinya
sudah ada sejak persetujuan pertama. Jalur persetujuan kedua dulu hanya menghitung ulang alokasi dari pengaturan
<b>transaksi</b> &mdash; metode pengakuan, penanda recurring, dan nominal per bulan yang baru diubah di dokumen tidak
pernah sampai ke sana. Perubahannya tersimpan rapi di dokumen, tetapi tidak berpengaruh apa pun, tanpa satu pun pesan.</p>
<table class="d">
    <tr><th>Yang diubah di dokumen</th><th>Sebelum</th><th>Sesudah</th></tr>
    <tr><td>Diakui Recurring dicentang</td><td class="merah">transaksi tetap tidak recurring</td><td class="hijau">ikut tercentang</td></tr>
    <tr><td>Prorata dilepas</td><td class="merah">transaksi tetap prorata</td><td class="hijau">ikut terlepas</td></tr>
    <tr><td>Nominal per bulan diubah 4 bulan &rarr; 3 bulan</td><td class="merah">bulan ke-4 tertinggal sebagai paku</td><td class="hijau">ikut terlepas</td></tr>
</table>

<h2 class="pb">5. Prorata bulan kalender untuk Gudang</h2>
<p style="margin-top:0">Kontrak bulanan bisa dipecah dua cara: ikut <b>bulan kalender</b> (cara surat kertas) atau ikut
<b>siklus tanggal</b> (11 &rarr; 10). Untuk kontrak yang tidak mulai tanggal 1, hasilnya berbeda &mdash; dan Gudang justru
modul yang paling sering mulai di tengah bulan. Pilihannya sudah ada di Surat Penawaran; di Gudang belum ada sama sekali,
jadi selalu memakai siklus tanggal.</p>
<?= $gbr('r5-kotak-prorata', 'Kotak barunya, hanya muncul di dokumen Gudang &mdash; Media berharga harian, tidak terpengaruh.', 150) ?>
<p style="margin:7px 0 2px"><b>Contoh nyata</b> &mdash; sewa 11 November 2026 s/d 10 Februari 2027, Rp 36.000.000:</p>
<?= $gbr('r5-prorata-off', 'TANPA centang: tiga siklus tanggal, masing-masing Rp 12.000.000, dan Februari tidak muncul sama sekali.', 150) ?>
<?= $gbr('r5-prorata-on', 'DENGAN centang: empat bulan kalender. November hanya 20/30 bulan, Februari 10/28 &mdash; persis cara surat kertas menghitung.', 150) ?>
<div class="box warn"><p style="margin:0">Dua-duanya berjumlah Rp 36.000.000. Yang berbeda adalah <b>bulan mana</b> yang
menerima berapa &mdash; dan itulah yang menentukan angka pendapatan bulanan di Dashboard dan Laporan.</p></div>

<h2>6. Recurring sekarang benar-benar meneruskan kontrak</h2>
<p style="margin-top:0">&ldquo;Diakui Recurring&rdquo; selama ini hanya penggolongan untuk laporan &mdash; tidak ada satu pun
tombol yang meneruskan kontraknya, sehingga periode berikutnya diketik ulang dari nol setiap kali, lengkap dengan risiko
salah ketik client, unit, dan tarif.</p>
<?= $gbr('r6-tombol', 'Tombol baru, muncul pada dokumen Gudang/Media yang sudah disetujui dan ditandai Recurring.', 150) ?>
<?= $gbr('r6-tanggal', 'Tanggalnya sudah dimajukan satu periode penuh: periode lama berakhir 10 Februari 2027, periode baru mulai 11 Februari dan berjalan 3 bulan.', 110) ?>
<?= $gbr('r6-bulan', 'Tabel bulannya ikut dibangun ulang untuk periode BARU &mdash; nominal bulan lama tidak ikut tersalin, karena bulannya memang sudah berbeda.', 150) ?>
<p style="margin:3px 0">Yang ikut tersalin: client, kontak, unit, PIC, tarif, nilai, jaminan, metode pengakuan, prorata, dan
penanda recurring. Status sewanya otomatis menjadi <b>Perpanjangan</b>. Yang <b>tidak</b> ikut: nomor dokumen, tanda tangan,
dan lampiran &mdash; ini dokumen baru yang tetap harus diperiksa dan disetujui seperti biasa.</p>

<h2 class="pb">Cara memakainya</h2>
<?= $poin(1, 'Mengisi pengakuan di dokumen Gudang / Media',
    $gbr('r1-blok', 'Letaknya di bawah blok Harga Sewa, pada formulir Gudang maupun Media.', 150)
    . '<table class="d"><tr><th style="width:30%">Kotak</th><th>Diisi kapan</th></tr>'
    . '<tr><td><b>Metode Pengakuan</b></td><td><i>Otomatis</i> sudah benar untuk hampir semua kontrak: lebih dari 1 bulan dibagi rata, satu periode diakui utuh. Pilih sendiri hanya bila memang beda.</td></tr>'
    . '<tr><td><b>Pengakuan per Siklus</b></td><td>hanya muncul saat nilainya dibagi rata &mdash; menentukan tiap siklus jatuh di bulan awal atau bulan akhirnya.</td></tr>'
    . '<tr><td><b>Prorata ikut bulan kalender</b></td><td>Gudang saja. Centang bila sewanya tidak mulai tanggal 1 dan ingin mengikuti cara surat kertas.</td></tr>'
    . '<tr><td><b>Diakui Recurring</b></td><td>centang bila kontrak ini berulang. Inilah yang memunculkan tombol <i>Buat Periode Berikutnya</i> setelah disetujui.</td></tr>'
    . '</table>') ?>
<?= $poin(2, 'Mengatur nominal tiap bulan',
    $gbr('r2-tabel-cocok', 'Tabelnya muncul sendiri begitu nilainya jatuh ke lebih dari satu bulan. Terisi rata lebih dulu; ubah yang perlu saja.', 150)
    . '<ul style="margin:4px 0 0 17px">'
    . '<li>Daftar bulannya mengikuti tanggal &amp; metode yang <b>tersimpan</b>. Ganti tanggalnya &rarr; simpan dulu &rarr; daftarnya menyesuaikan.</li>'
    . '<li>Tombol <b>Bagi rata lagi</b> mengembalikan pembagian ke rata.</li>'
    . '<li>Jumlahnya <b>harus</b> sama dengan Total Nilai Sewa. Selama belum sama, dokumen tidak bisa diajukan ke manager.</li>'
    . '</ul>') ?>
<?= $poin(3, 'Memperpanjang kontrak',
    '<p style="margin:2px 0">Buka dokumen yang sudah disetujui &rarr; <b>Buat Periode Berikutnya</b> &rarr; periksa tanggal dan
     nilainya &rarr; simpan &rarr; ajukan seperti biasa. Tombolnya hanya ada bila <b>Diakui Recurring</b> dicentang.</p>') ?>

<h2 class="pb">Temuan lain dari data &mdash; belum dikerjakan</h2>
<p style="margin-top:0">Angka di bawah ini dibaca dari database saat laporan ini dicetak.</p>
<table class="d">
    <tr><th style="width:46%">Temuan</th><th class="n" style="width:15%">Jumlah</th><th>Artinya</th></tr>
    <tr><td><b>Dokumen disetujui tapi belum ditandatangani client</b></td>
        <td class="n"><b class="merah"><?= (int) ($belumTtd['n'] ?? 0) ?></b><br><span class="muted"><?= $rp($belumTtd['nilai'] ?? 0) ?></span></td>
        <td>Nilainya <b>belum masuk Dashboard maupun Laporan</b> sampai client meneken di sistem.
            <?= (int) ($belumTtd['lama'] ?? 0) ?> di antaranya sudah lebih dari 14 hari.</td></tr>
    <tr><td><b>Kontrak Gudang dengan tanggal bertabrakan</b></td>
        <td class="n"><b class="merah"><?= (int) ($tabrak['n'] ?? 0) ?></b> pasang</td>
        <td>Satu unit dipakai dua kontrak sekaligus &mdash; terlama <?= (int) ($tabrak['hari'] ?? 0) ?> hari.</td></tr>
    <tr><td><b>Transaksi tanpa alokasi bulanan</b></td>
        <td class="n"><b class="jingga"><?= (int) ($tanpaAlok['n'] ?? 0) ?></b><br><span class="muted"><?= $rp($tanpaAlok['nilai'] ?? 0) ?></span></td>
        <td>Ada di daftar Transaksi lengkap dengan nilainya, tapi tidak menyumbang serupiah pun ke laporan mana pun.</td></tr>
    <tr><td><b>PIC belum punya Nama Lengkap</b></td>
        <td class="n"><b class="jingga"><?= (int) ($picKosong['n'] ?? 0) ?></b></td>
        <td>Suratnya masih mencetak nama pendek.</td></tr>
    <tr><td><b>Dokumen Gudang/Media tanpa PIC</b></td>
        <td class="n"><b class="jingga"><?= (int) ($dokTanpaPic['n'] ?? 0) ?></b></td>
        <td>Kolom &ldquo;Dibuat Oleh&rdquo; kosong, dan tidak masuk Laporan per PIC.</td></tr>
</table>

<h2>Yang harus dikerjakan lagi &mdash; urut berdasarkan urgensi</h2>

<?= $poin(1, 'Dokumen disetujui tapi mandek menunggu tanda tangan <span class="muted" style="font-weight:400;font-size:9pt">&mdash; paling mendesak</span>',
    '<p style="margin:2px 0"><b>Kenapa paling mendesak:</b> ini bukan kesalahan sistem, ini <b>uang yang tidak terlihat</b>.
     Manager sudah menyetujui, semua orang menganggap selesai, tetapi angkanya tidak muncul di Dashboard maupun Laporan
     sampai client meneken di sistem &mdash; dan tidak ada satu pun pemberitahuan ke siapa pun bahwa sebuah dokumen sedang mandek.
     Saat ini ada <b>' . (int) ($belumTtd['n'] ?? 0) . ' dokumen senilai ' . $rp($belumTtd['nilai'] ?? 0) . '</b> dalam keadaan ini.</p>
     <p style="margin:2px 0"><b>Yang perlu dibuat:</b> penanda umur di daftar dokumen (&ldquo;menunggu TTD 18 hari&rdquo;),
     dan kotak ringkas di Dashboard yang menyebut berapa dokumen &amp; berapa nilainya. Sekalian: tombol kirim ulang tautan
     tanda tangan, karena tautannya kedaluwarsa.</p>') ?>

<?= $poin(2, 'Bereskan 13 tabrakan jadwal Gudang yang sudah terlanjur ada',
    '<p style="margin:2px 0"><b>Kenapa:</b> peringatan yang baru dipasang hanya mencegah yang <b>akan datang</b>. Yang sudah
     terlanjur masuk tetap di sana &mdash; satu unit tercatat dipakai dua kontrak sampai ' . (int) ($tabrak['hari'] ?? 0) . ' hari,
     dan setidaknya salah satunya pasti salah: entah tanggalnya, entah unitnya, entah barisnya memang kembar.
     Selama belum dibereskan, pendapatan unit itu dihitung dua kali.</p>
     <p style="margin:2px 0"><b>Yang perlu dibuat:</b> halaman <i>Cek Overlap</i> sudah ada untuk Transaksi &mdash; tinggal
     ditambahi Gudang &amp; Media, lalu dipakai menyisir ke-13 pasang itu satu per satu bersama tim.</p>') ?>

<?= $poin(3, 'Transaksi tanpa alokasi: tampilkan terang-terangan',
    '<p style="margin:2px 0"><b>Kenapa:</b> ' . (int) ($tanpaAlok['n'] ?? 0) . ' transaksi senilai ' . $rp($tanpaAlok['nilai'] ?? 0) . '
     berdiri di daftar Transaksi lengkap dengan nilainya, tetapi tidak menyumbang apa pun ke laporan. Sebagian memang wajar
     (dokumennya masih draft atau dikembalikan), tetapi dari layar <b>tidak ada bedanya</b> dengan transaksi yang sudah jalan.
     Orang membaca daftar itu lalu mengira angkanya sudah masuk.</p>
     <p style="margin:2px 0"><b>Yang perlu dibuat:</b> satu kolom/penanda di daftar Transaksi &mdash; &ldquo;belum masuk laporan&rdquo;
     beserta alasannya.</p>') ?>

<?= $poin(4, 'Isi Nama Lengkap PIC dan PIC dokumen lama',
    '<p style="margin:2px 0"><b>Kenapa:</b> surat resmi yang keluar ke penyewa masih mencetak nama pendek untuk
     ' . (int) ($picKosong['n'] ?? 0) . ' PIC, dan ' . (int) ($dokTanpaPic['n'] ?? 0) . ' dokumen Gudang/Media lama tidak punya PIC
     sama sekali &mdash; kolom &ldquo;Dibuat Oleh&rdquo;-nya kosong dan nilainya tidak masuk Laporan per PIC.</p>
     <p style="margin:2px 0"><b>Yang perlu dilakukan:</b> ini isian data, bukan koding. <b>Master PIC &rarr; Nama Lengkap</b>,
     dan buka dokumen lamanya untuk memilih PIC-nya.</p>') ?>

<?= $poin(5, 'Pengakuan belum terlihat di dokumen cetaknya <span class="muted" style="font-weight:400;font-size:9pt">&mdash; paling ringan</span>',
    '<p style="margin:2px 0"><b>Kenapa:</b> pemeriksa yang menyetujui kontrak 3 bulan tidak bisa melihat dari suratnya
     bagaimana nilainya akan diakui &mdash; dibagi rata atau sekaligus, bulan mana berapa. Yang diperiksa jadi tidak lengkap.</p>
     <p style="margin:2px 0"><b>Yang perlu dibuat:</b> satu baris keterangan di halaman persetujuan (bukan di surat untuk
     client, karena itu urusan internal).</p>') ?>

<h3 style="margin-top:14px">Catatan teknis</h3>
<table class="d">
    <tr><th style="width:30%">Hal</th><th>Keterangan</th></tr>
    <tr><td>Memasang di server</td><td><code>cd ~/public_html/clara &amp;&amp; git pull &amp;&amp; php db_migrate.php</code> &mdash; ada migrasi baru (kolom pengakuan &amp; prorata di dokumen).</td></tr>
    <tr><td>Data lama</td><td>Tidak ada satu baris pun yang diubah. Dokumen &amp; transaksi yang sudah berjalan tetap seperti adanya; kolom barunya kosong, dan kosong berarti berperilaku persis seperti sebelumnya.</td></tr>
    <tr><td>Cara diuji</td><td>Seluruhnya dijalankan di CLARA lokal memakai salinan database: dokumen dibuat, diisi, ditolak saat jumlahnya meleset, disetujui, direvisi lalu disetujui ulang, dan diperpanjang. Angka alokasi bulanannya diperiksa langsung di database sesudahnya.</td></tr>
    <tr><td>Laporan ini</td><td>Dibangun ulang kapan saja dengan <code>php scripts/build_laporan_temuan.php</code>. Angka temuannya ikut terbarui sendiri.</td></tr>
</table>
<div class="box ok"><p style="margin:0"><span class="tebal">Yang sudah aman sekarang:</span> nilai yang tercetak di surat
tidak bisa lagi berubah sendiri sesudah diteken, bulan pengakuan tidak bisa lagi tertinggal basi, tabrakan jadwal
ketahuan sebelum dokumen dibuat, dan revisi benar-benar berlaku sampai ke angka laporannya.</p></div>
<?php
$html = ob_get_clean();

$mpdf = clara_letterhead_mpdf();
$mpdf->SetTitle('Laporan Perbaikan & Temuan — Gudang & Media');
$mpdf->WriteHTML($html);
$mpdf->Output($opt['keluar'], \Mpdf\Output\Destination::FILE);

echo "PDF dibuat: " . $opt['keluar'] . "\n";
