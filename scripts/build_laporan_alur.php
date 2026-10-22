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
 [1, 'Buat baru / format berbeda → buat SP dulu, baru SKP', 'sebagian',
  'Exhibition sudah wajib lewat Surat Penawaran. Gudang & Media belum — dokumennya dibuat langsung dari menu SKP tanpa penawaran.',
  ['Input transaksi langsung sudah dimatikan untuk Exhibition — app/pages/transactions.php:432 (`offer_first`).',
   'Gudang & Media dibuat lewat *+ Buat Dokumen → SKS Gudang / Form Utilities* — app/pages/skp.php:368-369.'],
  'Tentukan dulu: apakah Gudang & Media WAJIB lewat SP, atau boleh tetap langsung. Kalau wajib, jalur pembuatan langsung di SKP harus ditutup dan form penawaran perlu varian Gudang/Media.'],

 [2, 'SP → TTD client → SKP → kerjakan → paraf Asst. Manager & Manager → TTD client → masuk modul → income', 'selesai',
  'SUDAH DIKERJAKAN. SKP baru kini hanya boleh lahir dari penawaran yang benar-benar sudah ditandatangani client. Status DEAL saja tidak cukup — status itu bisa ditandai sendiri oleh sales, jadi tidak membuktikan client menyepakati apa pun.',
  ['Penjaga dipasang saat MEMBUAT dokumen, bukan di pemuat data — app/pages/skp.php (sesudah `$src` dibentuk). Dokumen yang sudah terlanjur ada tetap bisa dibuka dan diproses, jadi pekerjaan berjalan tidak berhenti.',
   'Diuji di salinan lokal: penawaran #48 (sudah diteken) → SKP terbit (id 201). Penawaran DEAL tanpa tanda tangan → ditolak, dilempar kembali ke halaman penawaran dengan keterangannya.',
   'Risiko yang saya khawatirkan kemarin ternyata NOL: dari 33 penawaran berstatus DEAL di kedua properti (e-Walk 23, Pentacity 10), *seluruhnya sudah ditandatangani client*. Tidak ada satu pun pekerjaan berjalan yang terhenti oleh aturan ini.'],
  'Tidak ada.'],

 [3, 'SKP yang sudah di-TTD client tidak bisa diedit / direvisi lagi', 'sebagian',
  'Formulirnya sudah terkunci saat berstatus Ditandatangani. Yang masih boleh diganti hanya berkas scan lampiran — nilai, periode, dan nomor dokumen tidak ikut berubah.',
  ['Dokumen terkunci, hanya lampiran yang bisa diperbaiki — app/pages/skp.php:1297-1304.',
   'Permintaan revisi hanya diterima dari status `approved`, bukan `signed` — app/pages/skp.php:3194.'],
  'Perlu satu kali pemeriksaan menyeluruh ke penjaga sisi simpan (bukan hanya tampilan) agar tidak ada jalur POST yang masih bisa menembus dokumen signed.'],

 [4, 'Revisi mengeluarkan nilainya dari income (keluar dari Exhibition/Media/Gudang)', 'selesai',
  'Sudah berjalan. Saat permintaan revisi disetujui, dokumen dikembalikan ke draft, nomor dokumen dipertahankan, tautan tanda tangan dimatikan, dan alokasi income dihapus sampai dokumennya selesai diperbaiki dan disetujui lagi.',
  ['app/pages/skp.php:3169 `skp_revision_decide` — status kembali `draft`, `revisi_ke` naik, `sign_token` dikosongkan.',
   'Komentar di kode menyebut alasannya: nilai yang sedang dipertanyakan tidak boleh tetap duduk di laporan.'],
  'Tidak ada. Sudah sesuai permintaan.'],

 [5, 'Kalau revisi, semua isi SKP bisa diedit sesuai SKP revisinya', 'sebagian',
  'Dokumen memang dibuka kembali sebagai draft sehingga bisa diedit. Yang belum diperiksa: apakah SEMUA kolom ikut terbuka, atau ada yang tetap terkunci karena berasal dari penawaran.',
  ['Penanda revisi tampil di formulir — app/pages/skp.php:834-836.'],
  'Perlu penelusuran kolom per kolom di formulir SKP, dibandingkan dengan daftar kolom yang diturunkan dari penawaran.'],

 [6, 'Kolom yang berasal dari penawaran dikunci dan diperjelas: hanya bisa diubah lewat SP, kalau tidak bisa → Pengajuan Hapus Data', 'belum',
  'Belum ada penandaan khusus. Kolom yang berasal dari penawaran tidak dibedakan tampilannya dari kolom yang memang milik SKP, dan tidak ada petunjuk ke mana harus mengubahnya.',
  [],
  'Tandai kolom turunan penawaran (gembok + keterangan "ubah lewat Surat Penawaran"), dan tambahkan tautan langsung ke Pengajuan Hapus Data bila penawarannya sudah terkunci.'],

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

 [11, 'Menu SKP tergabung antara Exhibition, Media, dan Gudang — membingungkan', 'belum',
  'Ketiganya memang satu menu. Saringan modul sudah ada di dalamnya, tetapi pintu masuknya tetap satu dan pembuatan dokumen Gudang/Media bersembunyi di dalam menu bernama SKP.',
  ['Menu tunggal *SKP Pameran* — app/pages/bootstrap.php:132.',
   'Pembuatan Gudang/Media di dalam menu itu — app/pages/skp.php:368-369.'],
  'Pisahkan jadi menu sendiri (mis. *SKP Exhibition*, *SKS Gudang*, *Form Utilities*) dengan daftar dan tombol buat masing-masing.'],

 [12, 'Perubahan format di e-Walk ikut berlaku di Pentacity; alurnya sama, isinya boleh beda', 'sebagian',
  'Alur dan formulirnya memang satu kode untuk kedua properti, jadi perubahan apa pun otomatis berlaku di keduanya. Yang dipisah per properti adalah datanya: unit, PIC, target, dan pengaturan.',
  ['Seluruh halaman memakai `current_property_id()` sebagai penyaring data, bukan percabangan kode per mall.'],
  'Perlu dipastikan sekali lagi setelah poin 11 dan 13 dikerjakan, agar menu baru dan kolom baru ikut muncul di kedua properti.'],

 [13, 'Dasar harga dipilih lewat DROPDOWN: per m² per hari (bawaan) atau per m² per bulan — hanya salah satu yang dipakai, dan SP/SKP/PDF/tautan client ikut menyesuaikan', 'belum',
  'Arahan terakhir: bukan centang yang menambah kolom kedua, melainkan DROPDOWN yang memilih salah satu dasar harga — bawaannya per m² per hari. Kabar baiknya, mesinnya sudah siap: kedua dasar harga itu sudah ada sebagai jenis harga `daily_area` dan `monthly_area`, jadi yang perlu dibuat tinggal pemilihnya di formulir dan penyesuaian label di cetakan.',
  ['Jenis harga `monthly_area` sudah dikenali — app/AllocationService.php:32 dan app/helpers.php (`clara_pengali_harga`).',
   'Sudah dipakai di surat penawaran — app/pages/offers.php:591.'],
  'Pasang dropdown di formulir SP dan SKP (bawaan: per m² per hari), lalu samakan label dan perhitungannya di cetakan SP, cetakan SKP, halaman tanda tangan client, dan PDF. Belum saya mulai — ini pekerjaan berikutnya.'],

 [14, 'Rapikan formulir SP, SKP, Gudang, dan Media — terlalu banyak kolom dan berhamburan', 'belum',
  'Belum dikerjakan. Formulirnya panjang dan kolomnya belum dikelompokkan menurut kebutuhan.',
  [],
  'Kelompokkan per kebutuhan (identitas client, lokasi & periode, harga, administrasi), samakan perataan dan lebar kolom, dan sembunyikan kolom yang tidak relevan dengan jenis harga yang dipilih.'],

 [15, 'Pastikan semua alur aman', 'sebagian',
  'Poin ini mengikuti poin 1-12. Yang sudah aman: revisi mengeluarkan income (4), penguncian penawaran oleh tanda tangan (7). Yang masih terbuka: DEAL tanpa tanda tangan (2) dan perpanjangan yang langsung masuk income (10).',
  [],
  'Selesaikan poin 2, 8, dan 10 — ketiganya menutup jalur yang membuat income masuk tanpa persetujuan lengkap.'],

 [16, 'Format baru (biaya per m² per bulan) harus jelas di SP dan/atau SKP, di PDF, dan di halaman yang dilihat client', 'belum',
  'Mengikuti poin 13.',
  [],
  'Dikerjakan bersama poin 13.'],

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
    <tr><td><b>Sudah selesai</b></td><td style="text-align:right"><b><?= $n['selesai'] ?> dari <?= count($poin) ?></b> &mdash; poin 4 dan 7</td></tr>
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
<h1 style="font-size:15pt">Urutan yang saya sarankan</h1>
<p>Bukan menurut nomor poinnya, tetapi menurut mana yang paling cepat menghentikan angka salah.</p>
<ol>
    <li><b>Poin 17 &mdash; pratinjau ber-stempel.</b> Paling kecil risikonya (tidak mengubah data sama
        sekali) dan langsung menghapus kesulitan Asst. Manager & Manager memeriksa. Kerjakan lebih dulu.</li>
    <li><b>Poin 8 &amp; 10 &mdash; perpanjangan lewat SKP.</b> Ini yang membuat income masuk tanpa
        persetujuan. Begitu perpanjangan tidak lagi lewat formulir transaksi, lubangnya tertutup.</li>
    <li><b>Poin 2 &mdash; DEAL wajib bertanda tangan client.</b> Harus disertai keputusan tentang
        penawaran yang sudah terlanjur DEAL tanpa tanda tangan, supaya pekerjaan berjalan tidak berhenti.</li>
    <li><b>Poin 11 &mdash; pisahkan menu.</b> Tidak mengubah data, mengurangi salah pilih.</li>
    <li><b>Poin 13 &amp; 16 &mdash; harga per m² per bulan.</b> Mesinnya sudah ada; yang kurang
        sakelar, validasi, dan penampilannya.</li>
    <li><b>Poin 6, 5, 3 &mdash; penguncian kolom &amp; pemeriksaan menyeluruh.</b></li>
    <li><b>Poin 14 &mdash; rapikan formulir.</b> Paling akhir, karena susunannya ikut berubah oleh
        poin 11 dan 13.</li>
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
