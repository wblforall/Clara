<?php
/**
 * Laporan lanjutan: pengerjaan 5 butir "yang harus dikerjakan lagi" dari
 * laporan sebelumnya, berikut koreksi dan temuan baru.
 *
 * Bentuknya sama dengan scripts/build_laporan_temuan.php: gambarnya potongan
 * layar sungguhan dari CLARA lokal (scripts/tutorial_assets), angkanya dibaca
 * langsung dari database saat dicetak.
 *
 * Pakai: php scripts/build_laporan_lanjutan.php [--keluar=/jalur/Laporan.pdf]
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/pdf.php';

$opt = array_merge(['keluar' => $root . '/docs/Laporan_Lanjutan_5_Butir.pdf'], getopt('', ['keluar:']));

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$h  = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
$satu = function (string $sql, array $par = []) use ($pdo) {
    try { $st = $pdo->prepare($sql); $st->execute($par); return $st->fetch() ?: []; }
    catch (Throwable $e) { return []; }
};

$ttdMulai = (string) ($satu("SELECT value v FROM settings WHERE `key`='ttd_wajib_mulai' LIMIT 1")['v'] ?? '');

$belumTtd = $satu("SELECT COUNT(*) n, COALESCE(SUM(" . clara_nilai_dokumen_sql() . "),0) nilai,
                          COALESCE(SUM(s.approved_at < DATE_SUB(NOW(), INTERVAL 14 DAY)),0) lama
                     FROM skp_documents s
                     LEFT JOIN transactions t ON t.id = s.transaction_id
                     LEFT JOIN offers o ON o.id = s.offer_id
                    WHERE s.deleted_at IS NULL AND s.status='approved' AND s.signed_at IS NULL");
$tabrak = $satu("SELECT COUNT(*) n, COALESCE(MAX(hari),0) hari FROM (
                   SELECT DATEDIFF(LEAST(a.end_date,b.end_date), GREATEST(a.start_date,b.start_date))+1 hari
                     FROM transactions a JOIN transactions b
                       ON b.property_id=a.property_id AND b.master_code=a.master_code AND b.id>a.id
                      AND b.start_date <= a.end_date AND b.end_date >= a.start_date
                    WHERE a.deleted_at IS NULL AND b.deleted_at IS NULL
                      AND a.status<>'cancelled' AND b.status<>'cancelled'
                      AND a.master_code <> '' AND a.module='gudang') x");
$tanpaAlok = $satu("SELECT COUNT(*) n, COALESCE(SUM(final_amount),0) nilai FROM transactions t
                     WHERE t.deleted_at IS NULL AND t.status<>'cancelled'
                       AND NOT EXISTS (SELECT 1 FROM transaction_allocations a WHERE a.transaction_id=t.id)");
$picKosong = $satu("SELECT COUNT(*) n FROM master_pic WHERE status='active' AND COALESCE(full_name,'')=''");
$dokTanpaPic = $satu("SELECT COUNT(*) n FROM skp_documents
                       WHERE module IN ('gudang','media') AND COALESCE(pic_name,'')='' AND deleted_at IS NULL");
$bedaLaporan = $satu("SELECT COUNT(*) n, COALESCE(SUM(t.final_amount),0) nilai
                        FROM transactions t
                       WHERE t.deleted_at IS NULL AND t.status<>'cancelled'
                         AND DATE(t.created_at) >= ?
                         AND EXISTS (SELECT 1 FROM transaction_allocations a WHERE a.transaction_id=t.id)
                         AND EXISTS (SELECT 1 FROM skp_documents s WHERE s.transaction_id=t.id
                                       AND s.deleted_at IS NULL AND s.status='approved' AND s.signed_at IS NULL)",
                     [$ttdMulai ?: '2000-01-01']);

$gbr = function (string $nama, string $ket, int $lebarMm = 160) use ($h): string {
    $file = __DIR__ . '/tutorial_assets/' . $nama . '.png';
    if (!is_file($file)) {
        return '<div class="box warn"><p style="margin:0">Gambar <b>' . $h($nama) . '</b> belum diambil.</p></div>';
    }
    return '<table class="gbr"><tr><td class="gbrc"><img src="' . $h($file) . '" style="width:' . $lebarMm . 'mm"></td></tr>'
         . '<tr><td class="gbrk">' . $ket . '</td></tr></table>';
};
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

<h1>Laporan Lanjutan &mdash; 5 Butir Tindak Lanjut</h1>
<div class="sub">Kelanjutan laporan &ldquo;Perbaikan &amp; Temuan Gudang/Media&rdquo; &middot; CLARA &middot; <?= date('d/m/Y H:i') ?><br>
    Gambar diambil dari CLARA yang dijalankan di komputer lokal. Angka pada bagian temuan dibaca
    langsung dari database saat laporan ini dicetak.</div>

<table class="d">
    <tr><th style="width:56%">Butir</th><th style="width:14%">Keadaan</th><th>Bentuknya</th></tr>
    <tr><td>1. Dokumen mandek menunggu tanda tangan</td><td class="hijau"><b>Selesai</b></td><td>penanda umur, ringkasan, terbitkan ulang tautan</td></tr>
    <tr><td>2. Jalan masuk ke pemeriksaan tabrakan jadwal</td><td class="hijau"><b>Selesai</b></td><td>tombol + kolom lama tabrakan</td></tr>
    <tr><td>3. Transaksi tanpa alokasi ditampilkan terang-terangan</td><td class="hijau"><b>Selesai</b></td><td>penanda + daftar khusus</td></tr>
    <tr><td>4. Nama Lengkap PIC &amp; PIC dokumen lama</td><td class="jingga"><b>Alat siap</b></td><td>pengisian datanya di tangan tim</td></tr>
    <tr><td>5. Pengakuan terlihat oleh pemeriksa</td><td class="hijau"><b>Selesai</b></td><td>panel ringkasan di halaman dokumen</td></tr>
</table>

<h2>1. Dokumen mandek menunggu tanda tangan</h2>
<p style="margin-top:0">Dokumen yang sudah disetujui manager <b>tidak menyumbang serupiah pun</b> ke Dashboard maupun
Laporan sampai client menandatanganinya &mdash; dan sebelumnya tidak ada satu tempat pun yang menyebutkan hal itu
sedang terjadi. Sekarang disebut di tiga tempat sekaligus.</p>
<?= $gbr('p1-dash', 'Dashboard: jumlah, nilainya, dan berapa yang sudah lewat 14 hari. Angkanya dihitung dengan rumus yang sama persis dengan daftar dokumen &mdash; sebelumnya dua halaman bisa menyebut dua angka berbeda.', 150) ?>
<?= $gbr('p1-bar', 'Daftar dokumen: baris yang sama, bisa diklik langsung ke saringan "Perlu TTD".', 150) ?>
<?= $gbr('p1-badge', 'Tiap barisnya menyebut sudah berapa hari menunggu. Lewat 14 hari berubah merah.', 150) ?>
<?= $poin(1, 'Dan satu temuan susulan: tautannya bisa kedaluwarsa tanpa ada yang tahu',
    '<p style="margin:2px 0">Tautan tanda tangan berlaku <b>30 hari</b>; sesudah itu client yang mengkliknya hanya
     melihat halaman &ldquo;tautan tidak berlaku&rdquo;. Keterangan di layar justru menulis
     &ldquo;berlaku sampai dokumen ditandatangani&rdquo; &mdash; keliru, dan satu-satunya jalan keluarnya dulu adalah
     merevisi dokumennya. Padahal dokumen yang menunggu lama justru yang paling mungkin kedaluwarsa.</p>'
    . $gbr('p1-token', 'Sekarang masa berlakunya disebut terang-terangan, dan kalau lewat ada tombolnya. Nomor dokumen, isi, nilai, dan jejak persetujuan tidak disentuh &mdash; hanya tokennya yang diganti.', 150)) ?>

<h2 class="pb">2. Jalan masuk ke pemeriksaan tabrakan jadwal</h2>
<p style="margin-top:0">Ternyata halaman <b>Cek Overlap</b> sudah mendukung Gudang &amp; Media sejak awal &mdash; yang
tidak ada adalah <b>jalan masuknya</b>. Gudang &amp; Media hidup di daftar Dokumen, bukan di daftar Transaksi, dan
tombolnya hanya ada di daftar Transaksi tanpa membawa modul yang sedang dibuka.</p>
<ul>
    <li>Tombol <b>&#9888; Cek Tabrakan Jadwal</b> ditambahkan di daftar Dokumen, membawa modulnya.</li>
    <li>Tombol lama di daftar Transaksi sekarang juga membawa modulnya &mdash; sebelumnya menyodorkan semua modul sekaligus.</li>
    <li>Kolom <b>Tabrakan</b> ditambahkan, dan daftarnya diurut dari yang terparah.</li>
</ul>
<?= $gbr('p2-tabel', 'Yang bertabrakan 30 hari jelas bukan unit yang kebetulan dibagi slot. Diurut dari yang terlama supaya bisa disisir dari atas.', 150) ?>
<div class="box bahaya"><p style="margin:0"><span class="tebal">Yang sudah terlanjur ada tetap harus dibereskan orang.</span>
    Saat laporan ini dicetak masih ada <b class="merah"><?= (int) ($tabrak['n'] ?? 0) ?> pasang kontrak Gudang</b>
    yang bertabrakan, terlama <b><?= (int) ($tabrak['hari'] ?? 0) ?> hari</b>. Tombol ini alatnya; keputusan mana yang
    benar tetap milik tim.</p></div>

<h2>3. Transaksi tanpa alokasi</h2>
<div class="box warn">
    <p style="margin:0"><span class="tebal">Koreksi atas laporan sebelumnya.</span> Saya menulis transaksi ini
    &ldquo;berdiri di daftar Transaksi lengkap dengan nilainya&rdquo;. Setelah diperiksa lebih teliti: daftar Transaksi
    justru <b>menyembunyikan</b> sebagian besar di antaranya &mdash; yang dokumennya ditolak, sedang direvisi, atau
    belum diteken. Jadi masalahnya bukan &ldquo;terlihat normal&rdquo;, melainkan <b>tidak terlihat di mana pun</b>:
    tidak di daftar, tidak di laporan.</p>
</div>
<?= $gbr('p3-bar', 'Di daftar Transaksi: satu baris yang menyebut jumlah & nilainya, bisa diklik.', 150) ?>
<?= $gbr('p3-baris', 'Daftar khususnya menampilkan yang biasanya disembunyikan, lengkap dengan ALASAN tiap barisnya &mdash; karena alasannya yang menentukan tindakannya.', 160) ?>

<h2 class="pb">4. Nama Lengkap PIC &amp; PIC dokumen lama</h2>
<div class="box warn">
    <p style="margin:0"><span class="tebal">Koreksi kedua.</span> Laporan sebelumnya menyebut
    &ldquo;5 dokumen Gudang/Media tanpa PIC&rdquo;. Angka itu <b>salah</b> &mdash; query saya ikut menghitung dokumen
    Exhibition, yang PIC-nya memang tidak disimpan di dokumen melainkan di penawaran/transaksinya. Jumlah dokumen
    Gudang/Media tanpa PIC yang sebenarnya: <b><?= (int) ($dokTanpaPic['n'] ?? 0) ?></b>.</p>
</div>
<p>Yang memang perlu diisi tinggal <b>Nama Lengkap PIC</b>. Ini isian data, bukan koding &mdash; jadi yang saya kerjakan
adalah membuat kekosongannya terlihat di tempat mengisinya, berikut nama-namanya.</p>
<?= $gbr('p4-banner', 'Muncul di Master PIC selama masih ada yang kosong, menyebut siapa saja. Hilang sendiri begitu semuanya terisi.', 150) ?>
<p style="margin:3px 0">Penanda <b>&ldquo;PIC belum diisi&rdquo;</b> juga dipasang di daftar dokumen untuk Gudang &amp;
Media &mdash; sekarang nihil, jadi gunanya menjaga yang akan datang.</p>

<h2>5. Pengakuan terlihat oleh pemeriksa</h2>
<p style="margin-top:0">Cara nilai dibukukan ke bulan tidak tercetak di surat mana pun, karena itu urusan internal.
Akibatnya yang menyetujui kontrak 3 bulan tidak punya cara melihatnya tanpa menyusuri formulirnya baris demi baris.</p>
<?= $gbr('p5-panel', 'Muncul di halaman dokumen Gudang/Media sejak diajukan sampai ditandatangani &mdash; di atas tombol Setujui. Tidak ikut tercetak di surat untuk client.', 150) ?>

<h2 class="pb">Temuan baru &mdash; belum dikerjakan</h2>
<table class="d">
    <tr><th style="width:44%">Temuan</th><th class="n" style="width:16%">Jumlah</th><th>Artinya</th></tr>
    <tr><td><b>Laporan dan daftar Transaksi memakai aturan berbeda</b></td>
        <td class="n"><b class="merah"><?= (int) ($bedaLaporan['n'] ?? 0) ?></b><br><span class="muted"><?= $rp($bedaLaporan['nilai'] ?? 0) ?></span></td>
        <td>Transaksi ini <b>dihitung di Laporan</b> (alokasinya ada) tetapi <b>disembunyikan dari daftar Transaksi</b>
            karena dokumennya belum diteken. Dua halaman, dua kebenaran.</td></tr>
    <tr><td><b>Kontrak Gudang bertabrakan</b></td>
        <td class="n"><b class="merah"><?= (int) ($tabrak['n'] ?? 0) ?></b> pasang</td>
        <td>Perlu disisir orang &mdash; alatnya sudah ada.</td></tr>
    <tr><td><b>Dokumen menunggu tanda tangan</b></td>
        <td class="n"><b class="jingga"><?= (int) ($belumTtd['n'] ?? 0) ?></b><br><span class="muted"><?= $rp($belumTtd['nilai'] ?? 0) ?></span></td>
        <td>Sekarang terlihat, tetapi mengejarnya masih manual.</td></tr>
    <tr><td><b>PIC belum punya Nama Lengkap</b></td>
        <td class="n"><b class="jingga"><?= (int) ($picKosong['n'] ?? 0) ?></b></td>
        <td>Surat resmi masih mencetak nama pendek.</td></tr>
</table>

<h2>Yang harus dikerjakan lagi &mdash; urut berdasarkan urgensi</h2>

<?= $poin(1, 'Samakan aturan "apa yang dihitung" antara Laporan dan daftar Transaksi <span class="muted" style="font-weight:400;font-size:9pt">&mdash; paling mendesak</span>',
    '<p style="margin:2px 0"><b>Kenapa paling mendesak:</b> ini bukan soal tampilan, ini dua angka yang berbeda untuk
     hal yang sama. Daftar Transaksi menyembunyikan kontrak yang dokumennya belum diteken; Laporan tidak melihat status
     dokumen sama sekali dan tetap menghitungnya. Saat ini <b>' . (int) ($bedaLaporan['n'] ?? 0) . ' kontrak senilai '
     . $rp($bedaLaporan['nilai'] ?? 0) . '</b> ada di laporan tetapi tidak ada di daftar. Siapa pun yang mencocokkan
     keduanya akan menemukan selisih yang tidak bisa dijelaskan.</p>
     <p style="margin:2px 0"><b>Yang perlu diputuskan lebih dulu:</b> mana yang benar &mdash; kontrak yang belum diteken
     client dihitung atau tidak? Setelah itu baru dikodekan, di satu tempat saja. Ini keputusan bisnis, bukan teknis,
     jadi saya tidak memutuskannya sendiri.</p>') ?>

<?= $poin(2, 'Sisir 13 tabrakan jadwal Gudang',
    '<p style="margin:2px 0"><b>Kenapa:</b> satu unit tercatat dipakai dua kontrak sampai ' . (int) ($tabrak['hari'] ?? 0)
     . ' hari. Setidaknya salah satunya pasti salah &mdash; entah tanggalnya, entah unitnya, entah barisnya memang kembar.
     Selama belum dibereskan, pendapatan unit itu dihitung dua kali.</p>
     <p style="margin:2px 0"><b>Yang perlu dilakukan:</b> buka <b>&#9888; Cek Tabrakan Jadwal</b> dari daftar Dokumen
     Gudang, mulai dari baris paling atas (yang terlama), lalu putuskan bersama tim mana yang dipertahankan. Yang kembar
     diajukan lewat <b>Pengajuan Hapus Data</b> &mdash; jangan dinolkan nilainya.</p>') ?>

<?= $poin(3, 'Pengingat otomatis untuk dokumen yang mandek',
    '<p style="margin:2px 0"><b>Kenapa:</b> sekarang sudah terlihat, tetapi masih menunggu seseorang membuka halamannya.
     Ada <b>' . (int) ($belumTtd['n'] ?? 0) . ' dokumen senilai ' . $rp($belumTtd['nilai'] ?? 0) . '</b> menunggu, dan
     yang paling lama justru yang paling mudah terlupakan.</p>
     <p style="margin:2px 0"><b>Yang perlu dibuat:</b> pengingat berkala ke PIC-nya (WhatsApp/email) untuk dokumen yang
     lewat 7 dan 14 hari. Tautannya sudah bisa diterbitkan ulang sendiri, jadi tinggal pengingatnya.</p>') ?>

<?= $poin(4, 'Isi Nama Lengkap PIC',
    '<p style="margin:2px 0"><b>Kenapa:</b> ' . (int) ($picKosong['n'] ?? 0) . ' PIC masih tercetak nama pendeknya di
     surat resmi yang keluar ke penyewa.</p>
     <p style="margin:2px 0"><b>Yang perlu dilakukan:</b> <b>Master PIC &rarr; Edit &rarr; Nama Lengkap</b>. Daftarnya
     sudah tertulis di halaman itu; peringatannya hilang sendiri begitu terisi. Tidak perlu koding.</p>') ?>

<?= $poin(5, 'Bersihkan transaksi lama tanpa alokasi <span class="muted" style="font-weight:400;font-size:9pt">&mdash; paling ringan</span>',
    '<p style="margin:2px 0"><b>Kenapa:</b> ' . (int) ($tanpaAlok['n'] ?? 0) . ' transaksi senilai '
     . $rp($tanpaAlok['nilai'] ?? 0) . ' tidak masuk laporan mana pun. Sebagian memang wajar (dokumennya masih
     dikerjakan), tetapi yang dokumennya sudah lama ditolak sebaiknya ditutup supaya tidak terus muncul di daftar
     khusus itu.</p>
     <p style="margin:2px 0"><b>Yang perlu dilakukan:</b> buka daftarnya dari tautan merah di halaman Transaksi,
     lalu tiap baris diputuskan: dilanjutkan dokumennya, atau diajukan hapus.</p>') ?>

<h3 style="margin-top:14px">Catatan teknis</h3>
<table class="d">
    <tr><th style="width:30%">Hal</th><th>Keterangan</th></tr>
    <tr><td>Memasang di server</td><td>Lewat <b>cPanel &rarr; Terminal</b> (atau SSH ke akun hosting yang sama):<br>
        <code>cd ~/public_html/clara &amp;&amp; git pull &amp;&amp; php db_check.php</code><br>
        <span class="muted">Tidak ada migrasi baru pada putaran ini &mdash; <code>php db_migrate.php</code> boleh dijalankan,
        hasilnya hanya &ldquo;skip&rdquo;. Langkah lengkapnya ada di docs/PANDUAN_SUPERADMIN.md &sect; Deploy.</span></td></tr>
    <tr><td>Data lama</td><td>Tidak ada satu baris pun yang diubah. Yang bertambah hanya tampilan &amp; satu tombol penerbit tautan.</td></tr>
    <tr><td>Tampilan baru</td><td>Seluruh tambahan diperiksa di atas tampilan terbaru (menu samping ciut, header &amp; saringan seragam) &mdash; memakai kartu, pil, dan lencana yang sama.</td></tr>
    <tr><td>Laporan ini</td><td><code>php scripts/build_laporan_lanjutan.php</code>. Angka temuannya ikut terbarui sendiri.</td></tr>
</table>
<?php
$html = ob_get_clean();

$mpdf = clara_letterhead_mpdf();
$mpdf->SetTitle('Laporan Lanjutan — 5 Butir Tindak Lanjut');
$mpdf->WriteHTML($html);
$mpdf->Output($opt['keluar'], \Mpdf\Output\Destination::FILE);

echo "PDF dibuat: " . $opt['keluar'] . "\n";
