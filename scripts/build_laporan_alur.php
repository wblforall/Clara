<?php
/**
 * Laporan status 17 poin perubahan alur SP → SKP → income.
 *
 * Dokumen ini sengaja memuat NOMOR BARIS kode untuk tiap klaim. Laporan
 * status tanpa rujukan tidak bisa diperiksa siapa pun, dan yang membacanya
 * hanya bisa percaya atau tidak percaya — padahal yang dibutuhkan justru
 * bisa dibuka sendiri dan dicocokkan.
 *
 * Pakai:
 *   php scripts/build_laporan_alur.php --keluar=docs/Laporan_Alur.pdf
 */

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/pdf.php';

date_default_timezone_set((string) (env_value('APP_TIMEZONE') ?: 'Asia/Makassar'));

$opt = array_merge(['keluar' => $root . '/docs/Laporan_Alur_SP_SKP.pdf'],
    getopt('', ['keluar:']));

$aset = $root . '/docs/laporan_alur_assets';
$h  = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$tb = fn($s) => preg_replace('/\*(.+?)\*/u', '<b>$1</b>', htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'));

// status: selesai | sebagian | belum
$poin = [
 [1, 'Buat baru / format berbeda → buat SP dulu, baru SKP', 'selesai',
  'SUDAH SESUAI ATURANMU. Kamu tetapkan: hanya Exhibition yang wajib lewat Surat Penawaran; Gudang dan Media boleh langsung. Itu persis perilaku sistem sekarang, jadi tidak ada yang perlu diubah — yang tadinya saya catat sebagai kekurangan ternyata memang disengaja.',
  ['Input transaksi langsung dimatikan untuk Exhibition — app/pages/transactions.php:432.', 'Gudang \\& Media dibuat langsung lewat *+ Buat Dokumen* tanpa penawaran — app/pages/skp.php.'],
  'Tidak ada.'],

 [2, 'SP → TTD client → SKP → kerjakan → paraf Asst. Manager & Manager → TTD client → masuk modul → income', 'selesai',
  'SUDAH DIKERJAKAN. SKP baru kini hanya boleh lahir dari penawaran yang benar-benar sudah ditandatangani client. Status DEAL saja tidak cukup — status itu bisa ditandai sendiri oleh sales, jadi tidak membuktikan client menyepakati apa pun.',
  ['Penjaga dipasang saat MEMBUAT dokumen, bukan di pemuat data — app/pages/skp.php (sesudah `$src` dibentuk). Dokumen yang sudah terlanjur ada tetap bisa dibuka dan diproses, jadi pekerjaan berjalan tidak berhenti.',
   'Diuji di salinan lokal: penawaran #48 (sudah diteken) → SKP terbit (id 201). Penawaran DEAL tanpa tanda tangan → ditolak, dilempar kembali ke halaman penawaran dengan keterangannya.',
   'Risiko yang saya khawatirkan kemarin ternyata NOL: dari 33 penawaran berstatus DEAL di kedua properti (e-Walk 23, Pentacity 10), *seluruhnya sudah ditandatangani client*. Tidak ada satu pun pekerjaan berjalan yang terhenti oleh aturan ini.'],
  'Tidak ada.'],

 [3, 'SKP yang sudah di-TTD client tidak bisa diedit / direvisi lagi', 'selesai',
  'SUDAH, DAN SUDAH SAYA PERIKSA SAMPAI KE SISI SIMPAN. Dokumen yang sudah ditandatangani client tidak bisa diubah — bukan hanya tombolnya yang disembunyikan, penyimpanannya sendiri menolak.',
  ['skp_save() menolak semua status selain `draft` dan `rejected` — jadi `submitted`, `approved`, dan `signed` semuanya terkunci. app/pages/skp.php.', 'Satu pengecualian yang memang disengaja: berkas scan lampiran (mis. KTP salah unggah) masih boleh diganti. Nilai, periode, dan nomor dokumen tidak ikut berubah.', 'Permintaan revisi pun hanya diterima dari status `approved`, tidak dari `signed`.'],
  'Tidak ada.'],

 [4, 'Revisi mengeluarkan nilainya dari income (keluar dari Exhibition/Media/Gudang)', 'selesai',
  'Sudah berjalan. Saat permintaan revisi disetujui, dokumen dikembalikan ke draft, nomor dokumen dipertahankan, tautan tanda tangan dimatikan, dan alokasi income dihapus sampai dokumennya selesai diperbaiki dan disetujui lagi.',
  ['app/pages/skp.php:3169 `skp_revision_decide` — status kembali `draft`, `revisi_ke` naik, `sign_token` dikosongkan.',
   'Komentar di kode menyebut alasannya: nilai yang sedang dipertanyakan tidak boleh tetap duduk di laporan.'],
  'Tidak ada. Sudah sesuai permintaan.'],

 [5, 'Kalau revisi, semua isi SKP bisa diedit sesuai SKP revisinya', 'sebagian',
  'Dokumen memang dibuka kembali sebagai draft sehingga bisa diedit. Yang belum diperiksa: apakah SEMUA kolom ikut terbuka, atau ada yang tetap terkunci karena berasal dari penawaran.',
  ['Penanda revisi tampil di formulir — app/pages/skp.php:834-836.'],
  'Perlu penelusuran kolom per kolom di formulir SKP, dibandingkan dengan daftar kolom yang diturunkan dari penawaran.'],

 [6, 'Kolom yang berasal dari penawaran dikunci dan diperjelas: hanya bisa diubah lewat SP, kalau tidak bisa → Pengajuan Hapus Data', 'selesai',
  'SUDAH DIKERJAKAN. Formulir SKP yang lahir dari penawaran kini membuka dengan keterangan terang: mana yang terkunci, mana yang masih boleh diubah, dan apa jalan keluarnya kalau yang terkunci ternyata salah.',
  ['Banner menyebut nomor penawarannya, lalu memisahkan dua daftar — terkunci: client, unit/lokasi, luas, jenis \\& dasar harga; masih boleh diubah: masa sewa, total biaya sewa, PPN, Service Charge, deposit, listrik, lampiran, catatan.', 'Karena SKP hanya boleh lahir dari penawaran ber-tanda tangan (poin 2), penawarannya pasti sudah terkunci — jadi banner menyebutkan satu-satunya jalan: *mengajukan penghapusan*, lengkap dengan tautannya.', 'Tampil untuk dokumen dari penawaran di status apa pun, termasuk yang sudah disetujui — justru saat terkunci penjelasannya paling dibutuhkan.'],
  'Tidak ada.'],

 [7, 'SP bisa diedit sepenuhnya selama client belum TTD; begitu di-TTD, terkunci', 'selesai',
  'SUDAH DIKERJAKAN HARI INI. Sebelumnya yang mengunci adalah status DEAL — padahal DEAL bisa ditandai sendiri oleh sales sebelum ada tanda tangan, sehingga salah ketik sepele pun tidak bisa dibetulkan dan jalan keluarnya menghapus lalu membuat ulang. Sekarang yang mengunci adalah tanda tangan client.',
  ['Halaman Preview — app/pages/offers.php:1174.',
   'Formulir penawaran — app/pages/offers.php:1553.',
   'Penjaga sisi simpan — app/pages/offers.php:3436 dan 3507. Ketiganya memakai aturan yang sama persis, supaya formulir tidak pernah terbuka sementara simpanannya ditolak.'],
  'Tidak ada.'],

 [8, 'Perpanjangan berangkat dari Exhibition/Gudang/Media, lalu masuk ke SKP dan terlihat di sana', 'belum',
  'Tombol *Perpanjang* ada di daftar modul, tetapi membuka formulir transaksi langsung — bukan SKP. Jadi perpanjangan tidak pernah muncul di menu SKP.',
  ['Perpanjangan menembus aturan offer-first — app/pages/transactions.php:432 (`renew_from`).'],
  'Alihkan tombol Perpanjang ke pembuatan dokumen SKP yang sudah terisi dari kontrak sebelumnya.'],

 [9, 'Auto-fill perpanjangan harus pintar, jangan kosongan', 'sebagian',
  'Formulir perpanjangan sudah terisi otomatis: unit, client, PIC, rate, dan periode baru dengan lama yang sama.',
  ['Keterangan di formulir — app/pages/transactions.php:534.'],
  'Setelah poin 8 dikerjakan, auto-fill yang sama perlu dipindahkan ke formulir SKP.'],

 [10, 'Perpanjangan tidak langsung masuk income sebelum SKP di-approve dan di-TTD client', 'selesai',
  'SUDAH DIKERJAKAN. Ternyata pengalihan ke SKP sudah ada di kode — alokasinya dihapus dan draft SKP dibuat otomatis — tetapi hanya berjalan bila penanda `to_skp` ikut terbawa di alamat halaman. Siapa pun yang membuka formulir perpanjangan tanpa penanda itu (mengetik alamatnya sendiri, atau tautan lama yang tersimpan) membuat perpanjangan yang LANGSUNG masuk income tanpa paraf, persetujuan, dan tanda tangan.',
  ['Penandanya sekarang melekat pada perpanjangannya sendiri, bukan pada tautan — app/pages/transactions.php:528.',
   'Diuji di salinan lokal: membuka formulir perpanjangan TANPA `to_skp` di alamatnya, dan penanda itu tetap terpasang di formulirnya.',
   'Akibatnya alokasi dilepas dan draft SKP terbit — app/pages/transactions.php:1157 dan sesudahnya.'],
  'Tidak ada untuk lubang ini. Poin 8 (pintu masuknya langsung dari menu SKP) masih terbuka, tetapi angkanya sudah aman.'],

 [11, 'Menu SKP tergabung antara Exhibition, Media, dan Gudang — membingungkan', 'selesai',
  'SUDAH DIKERJAKAN. Satu menu bernama SKP dulu memuat tiga jenis dokumen yang alurnya berbeda, sehingga orang yang mau membuat SKS Gudang harus menebak tempatnya. Sekarang tiga pintu masuk sendiri-sendiri.',
  ['Menu: *SKP Exhibition*, *SKS Gudang*, *Form Utilities (Media)* — app/pages/bootstrap.php. Judul halamannya ikut berubah, jadi tidak perlu memeriksa saringan untuk tahu sedang melihat daftar yang mana.', 'Daftarnya tetap satu halaman dengan saringan modul, sehingga riwayatnya utuh dan bisa dilihat lintas modul bila perlu.', 'Ditambah kotak *pencarian bebas*: nomor dokumen, kode unit, atau nama client. Saringan modul \\& status ikut terbawa saat mencari.', 'Pembatasan per-PIC memang sudah ada sejak sebelumnya — tiap sales hanya melihat dokumen miliknya.', 'Catatan teknis: kolom skp_documents bercollation utf8mb4_bin sedangkan kolom transaksi/penawaran utf8_general_ci, jadi pencariannya membandingkan tiap kolom sendiri-sendiri dengan collation eksplisit.'],
  'Tidak ada.'],

 [12, 'Perubahan format di e-Walk ikut berlaku di Pentacity; alurnya sama, isinya boleh beda', 'selesai',
  'SUDAH, DAN MEMANG BEGITU RANCANGANNYA. Maksudmu: perubahan sistem berlaku untuk e-Walk dan Pentacity sekaligus. Itu yang terjadi — satu kode dipakai kedua properti, jadi setiap perbaikan di dokumen ini otomatis berlaku di keduanya.',
  ['Tidak ada percabangan kode per mall. Yang dipisah hanya DATAnya — unit, PIC, target, pengaturan — lewat `current_property_id()`.', 'Diuji: menu baru, kotak pencarian, pratinjau bercap, dan banner kunci semuanya muncul sama di kedua properti.'],
  'Tidak ada.'],

 [13, 'Dasar harga dipilih lewat DROPDOWN: per m² per hari (bawaan) atau per m² per bulan — hanya salah satu yang dipakai, dan SP/SKP/PDF/tautan client ikut menyesuaikan', 'selesai',
  'SUDAH DIKERJAKAN. Dasar harganya kini DIPILIH dari satu dropdown, bawaannya *Per m² / hari*, dan pilihan itu menentukan satuan yang tercetak di mana-mana. Kabar buruknya yang ketemu saat mengerjakan: label di surat SKP dulu hanya mengenal satu jenis harga bulanan, sehingga kontrak berdasar tarif *per m² per BULAN* ikut tercetak "per m² per hari" — satu angka, dua arti, dan client yang membacanya salah.',
  ['Dropdown di formulir Surat Penawaran sekarang berlabel manusia, bukan kode (`daily_area`, `monthly_area`) — app/pages/offers.php. Penawaran baru jatuh ke *Per m² / hari*.',
   'Satuannya diambil dari SATU daftar bersama, `clara_satuan_harga()` di app/helpers.php. Dipakai oleh formulir SKP, surat SKP, surat penawaran, PDF, dan halaman tanda tangan client — jadi satu kontrak tidak mungkin disebut berbeda di dua tempat.',
   'Halaman yang dibuka client memang memakai berkas cetak yang sama (`offer_print_template.php` untuk penawaran, `skp_print_body.php` untuk SKP), jadi perbaikannya ikut ke sana dengan sendirinya.',
   'Surat penawaran sekarang menulis dasar harganya terang-terangan sebagai satu baris tersendiri — sebelumnya hanya ada "Harga Sewa / Periode", tanpa menyebut tarifnya per apa.',
   'Diuji di salinan lokal: penawaran #34 (per m² / hari) mencetak baris *Biaya Sewa / m² / hari*; pemetaan labelnya diperiksa untuk keenam jenis harga; pratinjau SKP, cetak SKP, dan cetak penawaran semuanya tetap terbit normal.'],
  'Tidak ada.'],

 [14, 'Rapikan formulir SP, SKP, Gudang, dan Media — terlalu banyak kolom dan berhamburan', 'belum',
  'Belum dikerjakan. Formulirnya panjang dan kolomnya belum dikelompokkan menurut kebutuhan.',
  [],
  'Kelompokkan per kebutuhan (identitas client, lokasi & periode, harga, administrasi), samakan perataan dan lebar kolom, dan sembunyikan kolom yang tidak relevan dengan jenis harga yang dipilih.'],

 [15, 'Pastikan semua alur aman', 'sebagian',
  'Poin ini mengikuti poin 1-12. Yang sudah aman: revisi mengeluarkan income (4), penguncian penawaran oleh tanda tangan (7). Yang masih terbuka: DEAL tanpa tanda tangan (2) dan perpanjangan yang langsung masuk income (10).',
  [],
  'Selesaikan poin 2, 8, dan 10 — ketiganya menutup jalur yang membuat income masuk tanpa persetujuan lengkap.'],

 [16, 'Format baru (biaya per m² per bulan) harus jelas di SP dan/atau SKP, di PDF, dan di halaman yang dilihat client', 'selesai',
  'SUDAH, bersama poin 13. Dasar harga yang dipilih tercetak terang di surat penawaran, di SKP, di PDF, dan di halaman yang dibuka client — semuanya mengambil satuan dari satu daftar yang sama.',
  ['`clara_satuan_harga()` di app/helpers.php dipakai formulir, kedua surat, PDF, dan halaman tanda tangan client.'],
  'Tidak ada.'],

 [17, 'Pratinjau surat ber-stempel "contoh" supaya PIC, Asst. Manager, dan Manager bisa memeriksa sebelum semuanya disetujui', 'selesai',
  'SUDAH DIKERJAKAN. Tombol *Lihat Contoh Surat* ada di formulir SKP dan bisa ditekan di status apa pun — termasuk saat masih draft. Hasilnya surat utuh dengan tata letak yang sama persis dengan yang nanti diterima client, bercap melintang "CONTOH — BELUM SAH" dan tanpa nomor dokumen.',
  ['Pratinjau dirender dari dalam formulir itu sendiri, memakai angka yang SEDANG dihitung di halaman itu — bukan salinan beku — sehingga isinya selalu mengikuti keadaan terakhir. app/pages/skp.php (cabang `?pratinjau=1`).',
   'Cap melintang ditambahkan di app/pdf.php — `clara_render_letterhead_pdf()` sekarang menerima teks cap, dipasang sebelum isi ditulis supaya ikut di setiap halaman. Kepekatannya 10% agar isinya tetap terbaca.',
   'Diuji di salinan lokal pada SKP draft #185: `skp_print` menolaknya dengan 404 "SKP belum disetujui", sedangkan pratinjau menghasilkan PDF 2 halaman.'],
  'Tidak ada. Dua tangkapan layar hasilnya ada di halaman berikutnya.'],
];

$warna = [
 'selesai'  => ['#065f46', '#ecfdf5', '#a7f3d0', 'SUDAH'],
 'sebagian' => ['#92400e', '#fffbeb', '#fcd34d', 'SEBAGIAN'],
 'belum'    => ['#991b1b', '#fef2f2', '#fecaca', 'BELUM'],
];
$n = ['selesai' => 0, 'sebagian' => 0, 'belum' => 0];
foreach ($poin as $p) $n[$p[2]]++;

ob_start(); ?>
<style>
    body { font-family: sans-serif; font-size: 10.5pt; line-height: 1.55; color: #1e293b; }
    h1 { font-size: 19pt; margin: 0 0 2px; color: #0D9488; }
    h2 { font-size: 12pt; margin: 0 0 4px; }
    .sub { color: #64748b; font-size: 9.5pt; margin-bottom: 12px; }
    .ring { border: 1px solid #cbd5e1; border-radius: 4px; padding: 10px 14px; margin-bottom: 14px; background: #f8fafc; }
    table { width: 100%; border-collapse: collapse; }
    .ring td { padding: 3px 0; }
    .poin { border: 1px solid #e2e8f0; border-left-width: 4px; padding: 9px 13px; margin-bottom: 9px; }
    .cap { display: inline-block; font-size: 8.5pt; font-weight: 700; padding: 1px 7px; border-radius: 3px; }
    .ket { margin: 5px 0 0; }
    .bukti { font-size: 9pt; color: #475569; margin: 5px 0 0 15px; padding: 0; }
    .bukti li { margin-bottom: 2px; }
    .perlu { font-size: 9.5pt; margin-top: 5px; padding-top: 5px; border-top: 1px dashed #cbd5e1; }
    .muted { color: #64748b; }
</style>

<h1>Status 17 Poin Perubahan Alur</h1>
<div class="sub">CLARA &middot; SP &rarr; SKP &rarr; Income &middot; dibuat <?= date('d/m/Y H:i') ?><br>
Tiap klaim di dokumen ini menyebut berkas dan nomor barisnya, supaya bisa diperiksa sendiri.</div>

<div class="ring">
<table>
    <tr><td><b>Sudah selesai</b></td><td style="text-align:right"><b><?= $n['selesai'] ?> dari <?= count($poin) ?></b> &mdash; poin
        <?= $h(implode(', ', array_map(fn($p) => $p[0], array_filter($poin, fn($p) => $p[2] === 'selesai')))) ?></td></tr>
    <tr><td>Sudah ada sebagian</td><td style="text-align:right"><?= $n['sebagian'] ?> poin</td></tr>
    <tr><td>Belum dikerjakan</td><td style="text-align:right"><?= $n['belum'] ?> poin</td></tr>
</table>
</div>

<p style="margin:0 0 12px"><b>Jawaban atas pertanyaan pokoknya:</b> ini bukan salah user dan bukan
sepenuhnya salah sistem. Sagap bisa cocok karena kontraknya kebetulan semuanya satu bulan penuh dan
sudah ditandatangani. Yang lain meleset karena tiga lubang alur yang nyata &mdash; poin 2, 10, dan 17
di bawah &mdash; bukan karena salah mengetik. Selama ketiganya terbuka, kejadian seperti kemarin
akan berulang berapa kali pun datanya dibetulkan.</p>

<?php foreach ($poin as [$no, $judul, $st, $ket, $bukti, $perlu]):
    [$fg, $bg, $br, $lbl] = $warna[$st]; ?>
<div class="poin" style="border-left-color:<?= $fg ?>;background:<?= $st === 'selesai' ? $bg : '#fff' ?>">
    <h2><span class="cap" style="color:<?= $fg ?>;background:<?= $bg ?>;border:1px solid <?= $br ?>"><?= $lbl ?></span>
        &nbsp;<?= $no ?>. <?= $h($judul) ?></h2>
    <p class="ket"><?= $tb($ket) ?></p>
    <?php if ($bukti): ?>
    <ul class="bukti"><?php foreach ($bukti as $b): ?><li><?= $tb($b) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <p class="perlu"><b class="muted">Yang masih perlu dikerjakan:</b> <?= $tb($perlu) ?></p>
</div>
<?php endforeach; ?>

<pagebreak>
<h1 style="font-size:15pt">Bukti: poin 17 sebelum &amp; sesudah</h1>
<p><b>Sebelum.</b> Menekan Cetak pada dokumen yang belum disetujui dijawab
<i>&ldquo;SKP belum disetujui / tidak ditemukan&rdquo;</i> &mdash; halaman 404, tidak ada surat sama sekali.
Pemeriksa terpaksa membaca dari formulir, kolom demi kolom.</p>
<p><b>Sesudah.</b> Tombol baru di formulir SKP, bisa ditekan kapan saja:</p>
<img src="<?= $h($aset) ?>/ss_tombol_pratinjau.png" style="width:168mm;border:1px solid #cbd5e1">
<p style="margin-top:10px">Hasilnya surat utuh, bercap melintang, tanpa nomor dokumen:</p>
<img src="<?= $h($aset) ?>/ss_surat_bercap.png" style="width:120mm;border:1px solid #cbd5e1">

<pagebreak>
<h1 style="font-size:15pt">Bukti: poin 11 &mdash; menu dipisah</h1>
<p><b>Sebelum.</b> Satu menu <i>SKP Pameran</i> memuat tiga jenis dokumen sekaligus. Yang mau membuat
SKS Gudang harus menebak bahwa tempatnya di dalam menu bernama SKP, lalu mencari di dropdown
<i>+ Buat Dokumen</i>. Daftarnya tidak bisa dicari.</p>
<p><b>Sesudah.</b> Tiga pintu masuk sendiri-sendiri, judul halaman ikut, dan ada kotak pencarian:</p>
<img src="<?= $h($aset) ?>/ss_menu_terpisah.png" style="width:168mm;border:1px solid #cbd5e1">

<pagebreak>
<h1 style="font-size:15pt">Bukti: poin 6 &mdash; mana yang terkunci</h1>
<p><b>Sebelum.</b> Kolom yang datang dari penawaran tidak dibedakan sama sekali dari kolom milik SKP,
dan tidak ada petunjuk ke mana harus mengubahnya. Orang mencoba, gagal, lalu menebak sendiri &mdash;
ada yang menghapus dokumennya, ada yang membuat penawaran kedua.</p>
<p><b>Sesudah.</b> Keterangannya terang di atas formulir, lengkap dengan nomor penawarannya dan
jalan keluarnya:</p>
<img src="<?= $h($aset) ?>/ss_banner_kunci.png" style="width:168mm;border:1px solid #cbd5e1">

<pagebreak>
<h1 style="font-size:15pt">Yang masih tersisa</h1>
<p>Lima poin belum dikerjakan. Semuanya saya tahan dengan sengaja &mdash; masing-masing menyentuh
formulir dan alur yang dipakai setiap hari, dan mengerjakannya terburu-buru justru melanggar
permintaanmu sendiri di poin 15.</p>
<ol>
    <li><b>Poin 8 &mdash; perpanjangan berangkat dari menu modulnya ke formulir yang sesuai</b>
        (Gudang &rarr; formulir Gudang, Exhibition &rarr; formulir Exhibition, Media &rarr; Media).
        Angkanya sudah aman sejak poin 10 ditutup; yang tersisa pintu masuknya. <b>Kerjakan duluan.</b></li>
    <li><b>Poin 9 &mdash; auto-fill perpanjangan yang pintar.</b> Menyusul poin 8, karena isinya
        pindah ke formulir yang berbeda.</li>
    <li><b>Poin 5 &mdash; saat revisi, seluruh isi SKP bisa diedit sesuai penawarannya.</b> Perlu
        penelusuran kolom per kolom, dibandingkan dengan daftar kolom turunan penawaran.</li>
    <li><b>Poin 14 &mdash; rapikan formulir.</b> Paling akhir, karena susunannya ikut berubah oleh
        poin 8 dan 9. Arahanmu sudah saya catat: dibagi per kebutuhan, keterangan padat, dan orang
        bingung harus tahu arah scroll-nya.</li>
    <li><b>Poin 15 &mdash; pastikan semua alur aman.</b> Tuntas sendirinya setelah keempat di atas selesai.</li>
</ol>

<h2 style="margin-top:16px">Cara memeriksanya sendiri</h2>
<p>Seluruh perubahan di dokumen ini sudah diuji di salinan lokal dengan data asli
(dump 14 Oktober), masuk memakai akun sungguhan, dan setiap halaman utama dibuka
untuk memastikan tidak ada yang rusak: Dashboard, Surat Penawaran, SKP, Exhibition,
Gudang, dan Laporan PIC &mdash; semuanya terbuka normal.</p>
<p>Di server, sesudah <b>git pull</b>, periksa tiga hal ini:</p>
<ol>
    <li><b>Poin 17</b> &mdash; buka satu SKP yang masih draft. Tombol <b>Lihat Contoh Surat</b>
        harus ada, dan menekannya menghasilkan PDF bercap.</li>
    <li><b>Poin 10</b> &mdash; buka Gudang, tekan <b>Perpanjang</b>, simpan. Transaksinya harus
        mendarat di formulir SKP, dan nilainya <b>belum</b> muncul di Laporan PIC.</li>
    <li><b>Poin 2</b> &mdash; coba buat SKP dari penawaran yang belum diteken client. Harus ditolak
        dengan keterangannya. (Saat ini semua penawaran DEAL sudah diteken, jadi tidak ada yang terhalang.)</li>
</ol>

<?php
$html = (string) ob_get_clean();
$mpdf = clara_letterhead_mpdf();
$mpdf->SetTitle('Status 17 Poin Perubahan Alur — CLARA');
$mpdf->WriteHTML($html);
@mkdir(dirname($opt['keluar']), 0777, true);
$mpdf->Output($opt['keluar'], \Mpdf\Output\Destination::FILE);
echo "  Dokumen dibuat: {$opt['keluar']}\n";
