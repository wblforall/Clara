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

$h  = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$tb = fn($s) => preg_replace('/\*(.+?)\*/u', '<b>$1</b>', htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'));

// status: selesai | sebagian | belum
$poin = [
 [1, 'Buat baru / format berbeda → buat SP dulu, baru SKP', 'sebagian',
  'Exhibition sudah wajib lewat Surat Penawaran. Gudang & Media belum — dokumennya dibuat langsung dari menu SKP tanpa penawaran.',
  ['Input transaksi langsung sudah dimatikan untuk Exhibition — app/pages/transactions.php:432 (`offer_first`).',
   'Gudang & Media dibuat lewat *+ Buat Dokumen → SKS Gudang / Form Utilities* — app/pages/skp.php:368-369.'],
  'Tentukan dulu: apakah Gudang & Media WAJIB lewat SP, atau boleh tetap langsung. Kalau wajib, jalur pembuatan langsung di SKP harus ditutup dan form penawaran perlu varian Gudang/Media.'],

 [2, 'SP → TTD client → SKP → kerjakan → paraf Asst. Manager & Manager → TTD client → masuk modul → income', 'belum',
  'Rantainya sudah ada, tetapi satu mata rantai bisa dilewati: status DEAL boleh ditandai sendiri oleh sales tanpa tanda tangan client, dan SKP hanya mensyaratkan status DEAL.',
  ['Tombol *Tandai DEAL* mengubah status tanpa memeriksa tanda tangan — app/pages/offers.php:1233.',
   'Syarat membuat SKP hanya `o.status = \'deal\'` — app/pages/skp.php:76.',
   'Income memang baru terbit saat client meneken SKP — app/pages/skp.php:3467 dan 3576 (`_skp_bangun_alokasi`).'],
  'Tambahkan syarat `offers.signed_at IS NOT NULL` sebelum SKP boleh dibuat. Perlu diputuskan dulu nasib penawaran yang sudah terlanjur DEAL tanpa tanda tangan — kalau syarat ini langsung diberlakukan, pekerjaan yang sedang berjalan akan berhenti.'],

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

 [10, 'Perpanjangan tidak langsung masuk income sebelum SKP di-approve dan di-TTD client', 'belum',
  'Inilah lubang yang paling berpengaruh ke angka. Perpanjangan lewat tombol Perpanjang langsung membangun alokasi income begitu disimpan — tanpa SKP, tanpa persetujuan, tanpa tanda tangan.',
  ['Penyimpanan transaksi langsung memanggil pembangun alokasi — app/pages/transactions.php:1127 dan 1934.'],
  'Setelah poin 8 dikerjakan, lubang ini tertutup dengan sendirinya karena perpanjangan tidak lagi lewat formulir transaksi.'],

 [11, 'Menu SKP tergabung antara Exhibition, Media, dan Gudang — membingungkan', 'belum',
  'Ketiganya memang satu menu. Saringan modul sudah ada di dalamnya, tetapi pintu masuknya tetap satu dan pembuatan dokumen Gudang/Media bersembunyi di dalam menu bernama SKP.',
  ['Menu tunggal *SKP Pameran* — app/pages/bootstrap.php:132.',
   'Pembuatan Gudang/Media di dalam menu itu — app/pages/skp.php:368-369.'],
  'Pisahkan jadi menu sendiri (mis. *SKP Exhibition*, *SKS Gudang*, *Form Utilities*) dengan daftar dan tombol buat masing-masing.'],

 [12, 'Perubahan format di e-Walk ikut berlaku di Pentacity; alurnya sama, isinya boleh beda', 'sebagian',
  'Alur dan formulirnya memang satu kode untuk kedua properti, jadi perubahan apa pun otomatis berlaku di keduanya. Yang dipisah per properti adalah datanya: unit, PIC, target, dan pengaturan.',
  ['Seluruh halaman memakai `current_property_id()` sebagai penyaring data, bukan percabangan kode per mall.'],
  'Perlu dipastikan sekali lagi setelah poin 11 dan 13 dikerjakan, agar menu baru dan kolom baru ikut muncul di kedua properti.'],

 [13, 'Kolom biaya sewa per m² per BULAN di samping per m² per hari — bisa diaktifkan, wajib diisi bila aktif, tampil di SP, SKP, PDF, dan tautan client', 'belum',
  'Jenis harga `monthly_area` (tarif per m² per bulan) sudah ada di mesin perhitungan dan sudah dipakai di surat penawaran. Yang belum ada: sakelar di formulir, kewajiban mengisi saat aktif, dan penampilannya di SKP serta halaman tanda tangan client.',
  ['Jenis harga `monthly_area` sudah dikenali — app/AllocationService.php:32 dan app/helpers.php (`clara_pengali_harga`).',
   'Sudah dipakai di surat penawaran — app/pages/offers.php:591.'],
  'Tambahkan sakelar di formulir SP dan SKP, validasi wajib-isi, lalu baris harganya di cetakan SP, cetakan SKP, dan halaman tanda tangan client.'],

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

 [17, 'Pratinjau surat ber-stempel "contoh" supaya PIC, Asst. Manager, dan Manager bisa memeriksa sebelum semuanya disetujui', 'belum',
  'Saat ini surat hanya bisa dicetak setelah dokumennya disetujui — persis keluhan yang kamu sebut. Sebelum itu tidak ada cara melihat hasil jadinya.',
  ['Cetak ditolak bila status belum `approved` / `signed` — app/pages/skp.php:3505.',
   'Tidak ada stempel/watermark di mana pun — pencarian `watermark` di app/pdf.php dan template cetak tidak menghasilkan apa pun.'],
  'Tambahkan rute pratinjau yang boleh dibuka di status apa pun oleh PIC pemilik dan para penyetuju, memakai template cetak yang sama, dengan stempel diagonal semi-transparan (mis. "CONTOH — BELUM SAH"). Perlu satu penyesuaian: isi surat saat ini dibekukan saat persetujuan (`snapshot_json`), jadi pratinjau harus membangunnya langsung dari data terkini.'],
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

<h2 style="margin-top:16px">Tentang tangkapan layar</h2>
<p>Dokumen ini belum memuat tangkapan layar. Untuk mengambilnya, aplikasinya harus berjalan dan
<b>dalam keadaan login</b> &mdash; dan saya tidak punya akses masuk ke CLARA, baik di server maupun
dengan salinan lokal. Memasang gambar yang saya buat sendiri jelas bukan bukti, jadi tidak saya lakukan.</p>
<p>Dua cara supaya tangkapan layarnya ada:</p>
<ol>
    <li>Kirimkan tangkapan layar halamannya &mdash; saya sisipkan ke dokumen ini di tempat yang tepat.</li>
    <li>Atau beri satu akun uji (boleh akun terbatas) untuk salinan lokal, dan saya ambil sendiri
        seluruh halaman yang disebut di dokumen ini.</li>
</ol>
<p>Sebagai gantinya, tiap klaim di atas sudah menyebut berkas dan nomor baris. Itu bisa dibuka dan
dicocokkan langsung, dan tidak bisa saya karang.</p>
<?php
$html = (string) ob_get_clean();
$mpdf = clara_letterhead_mpdf();
$mpdf->SetTitle('Status 17 Poin Perubahan Alur — CLARA');
$mpdf->WriteHTML($html);
@mkdir(dirname($opt['keluar']), 0777, true);
$mpdf->Output($opt['keluar'], \Mpdf\Output\Destination::FILE);
echo "  Dokumen dibuat: {$opt['keluar']}\n";
