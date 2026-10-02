<?php
/** Laporan perbaikan 22 Sep 2026 — TTD basah SP, ruang TTD di PDF, bukti transfer pindah ke Permintaan Kontrak. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/app/pdf.php';

$IMG = $root . '/docs/perbaikan_assets/';
$img = function (string $f, string $cap, string $w = '100%') use ($IMG): string {
    return '<div style="margin:2mm 0 3.5mm"><img src="' . $IMG . $f . '" style="width:' . $w . '">'
        . '<div class="cap">' . $cap . '</div></div>';
};

$CSS = '<style>
 body { font-family: sans-serif; font-size: 9.6pt; color:#1F2937; line-height:1.55; }
 h1 { font-size:17pt; margin:0 0 2mm; }
 h2 { font-size:11.5pt; color:#0A7267; margin:6mm 0 2mm; padding-bottom:1.2mm; border-bottom:1pt solid #0D9488; page-break-after:avoid; }
 h3 { font-size:10pt; margin:3.5mm 0 1.5mm; page-break-after:avoid; color:#0A7267; }
 p { margin:0 0 2.6mm; }
 .kicker { font-size:8.4pt; letter-spacing:.08em; text-transform:uppercase; color:#0D9488; font-weight:bold; }
 .sub { color:#6B7280; font-size:8.8pt; margin-bottom:4mm; }
 .page-break { page-break-before:always; }
 table { width:100%; border-collapse:collapse; }
 tr { page-break-inside:avoid; }
 th,td { border:0.4pt solid #D1D5DB; padding:1.6mm 2mm; vertical-align:top; font-size:8.7pt; }
 th { background:#F0FDF4; color:#0A7267; font-size:7.8pt; text-transform:uppercase; letter-spacing:.03em; }
 td.c, th.c { text-align:center; }
 .muted { color:#6B7280; }
 .box { page-break-inside:avoid; border:0.6pt solid #D1D5DB; border-left:2.8pt solid #0D9488; background:#F8FAFC; padding:2.8mm 3.2mm; margin-bottom:3mm; }
 .box.good { border-left-color:#047857; background:#F0FDF4; }
 .box.warn { border-left-color:#B45309; background:#FFFBEB; }
 .code { font-family:monospace; background:#F1F5F9; border:0.4pt solid #CBD5E1; padding:0.4mm 1.4mm; font-size:8.1pt; }
 .cap { font-size:7.8pt; color:#6B7280; font-style:italic; margin:1mm 0 0; }
 .ok { color:#047857; font-weight:bold; }
 .kv td { border:none; padding:0 0 1.2mm; }
 ul { margin:0 0 3mm 5mm; padding:0; } li { margin-bottom:1.6mm; }
</style>';

$h  = $CSS;
$h .= '<div class="kicker">Laporan Perbaikan</div>';
$h .= '<h1>Surat Penawaran, SKP &amp; Permintaan Kontrak</h1>';
$h .= '<div class="sub">CLARA — Casual Leasing Achievement &amp; Revenue Analytics &nbsp;·&nbsp; '
    . '3 perubahan &nbsp;·&nbsp; 22 September 2026</div>';

$h .= '<div class="box">Tiga permintaan tim Casual Leasing sudah dikerjakan: <b>dua cara persetujuan customer</b> di Surat '
    . 'Penawaran, <b>ruang tanda tangan</b> pada PDF yang tadinya tidak ada, dan <b>bukti transfer</b> yang dipindah '
    . 'dari SKP ke Permintaan Kontrak. Semua gambar di laporan ini diambil langsung dari aplikasi yang berjalan, '
    . 'bukan gambaran rencana.</div>';

// ── Ringkasan ────────────────────────────────────────────────────────────────
$h .= '<h3>Ringkasan</h3>';
$h .= '<table><thead><tr><th class="c" style="width:6%">No</th><th style="width:27%">Perubahan</th>'
    . '<th style="width:33%">Sebelumnya</th><th>Sekarang</th></tr></thead><tbody>'
    . '<tr><td class="c"><b>1</b></td><td>Dua cara persetujuan di Surat Penawaran</td>'
    . '<td>Hanya satu cara: customer wajib membuka tautan dan menandatangani di layar. Yang tidak mau, prosesnya berhenti.</td>'
    . '<td>Cara lama tetap ada (<b>Opsi A</b>), ditambah <b>Opsi B</b>: unggah PDF/scan surat yang sudah ditandatangani. Hasilnya sama — penawaran langsung <b>DEAL</b>.</td></tr>'
    . '<tr><td class="c"><b>2</b></td><td>Ruang tanda tangan pada PDF</td>'
    . '<td>Nama penanggung jawab menempel tepat di bawah tulisan &ldquo;Menyetujui&rdquo; — tidak ada tempat membubuhkan tanda tangan.</td>'
    . '<td>Ada ruang 20 mm bergaris di atas nama, pada Surat Penawaran <b>dan</b> SKP.</td></tr>'
    . '<tr><td class="c"><b>3</b></td><td>Bukti transfer pindah tahap</td>'
    . '<td>SKP tidak bisa di-submit sebelum bukti transfer diunggah — padahal klien baru membayar setelah SKP terbit.</td>'
    . '<td>SKP cukup KTP &amp; NPWP. Bukti transfer diunggah di <b>Permintaan Kontrak</b> dan wajib sebelum berkas dikirim ke Legal.</td></tr>'
    . '</tbody></table>';

// ── 1. TTD basah ─────────────────────────────────────────────────────────────
$h .= '<h2>1. Dua cara persetujuan customer di Surat Penawaran</h2>';
$h .= '<p>Di halaman penawaran sekarang tertulis dua pilihan yang sederajat. <b>Opsi A</b> — cara lama: kirim tautan, '
    . 'customer menandatangani langsung di layar. <b>Opsi B</b> — cara baru: kirim atau cetak suratnya, customer '
    . 'menandatangani di berkasnya sendiri, lalu sales mengunggah kembali <b>PDF</b> (atau foto/scan) yang sudah '
    . 'ber-TTD. Cukup isi nama penanda tangan, lampirkan berkasnya, tekan <b>Unggah &amp; Tandai TTD</b>. '
    . 'Keduanya berakhir sama: penawaran menjadi <b>DEAL</b> dan bisa dilanjutkan ke SKP.</p>';
$h .= $img('01_sp_form_upload.png', 'Gambar 1 — Opsi A dan Opsi B berdampingan dalam satu panel. Penawaran #002, status masih <i>sent</i>.');
$h .= $img('02_sp_sudah_ttd.png', 'Gambar 2 — Setelah berkas diunggah: penawaran menjadi DEAL, dan dokumennya bisa dibuka lewat tombol <b>Lihat Dokumen ber-TTD</b>.');
$h .= '<div class="box good"><b>Pengaman yang dipasang.</b>'
    . '<ul><li>Hanya penawaran <b>bernomor</b> yang belum DEAL/batal yang bisa ditandai — yang sudah bertanda tangan ditolak.</li>'
    . '<li><b>Nilai penawaran ikut dikunci</b> (snapshot) persis seperti Opsi A, jadi angka yang jadi dasar SKP tidak bisa berubah setelahnya.</li>'
    . '<li>Berkas <b>PDF</b>/jpg/png/webp maksimal 8&nbsp;MB, disimpan di <span class="code">public/uploads/offer</span>.</li>'
    . '<li>Tercatat di <b>Activity Log</b> sebagai <span class="code">customer_sign_wet</span> lengkap dengan nama penanda tangan dan berkasnya.</li>'
    . '<li>Hanya pengguna dengan hak <b>Kelola Surat Penawaran</b>, tidak ada hak akses baru yang perlu diatur.</li></ul></div>';

// ── 2. Ruang TTD ─────────────────────────────────────────────────────────────
$h .= '<h2>2. Ruang tanda tangan pada PDF dirapikan</h2>';
$h .= '<p>Sebelumnya surat yang dicetak tidak menyediakan tempat menandatangani: nama langsung menempel di bawah '
    . 'kata &ldquo;Menyetujui&rdquo;. Sekarang tersedia ruang kosong bergaris di atas nama — berlaku untuk '
    . '<b>Surat Penawaran</b> dan <b>SKP/SKS</b>.</p>';
$h .= $img('08_ttd_sp_banding.png', 'Gambar 3 — Surat Penawaran, halaman tanda tangan. Kiri sebelum perbaikan, kanan sesudah.', '150mm');
$h .= $img('09_ttd_skp_banding.png', 'Gambar 4 — SKP, kolom &ldquo;Menyetujui&rdquo;. Ruang tanda tangan yang sama juga ditambahkan.', '150mm');
$h .= '<div class="box">Dokumen yang <b>sudah ditandatangani elektronik</b> tidak berubah — gambar tanda tangan '
    . 'beserta keterangan waktunya tetap tampil seperti sebelumnya. Ruang kosong ini hanya muncul pada dokumen '
    . 'yang belum ditandatangani, yaitu yang memang akan dicetak.</div>';

// ── 3. Bukti transfer ────────────────────────────────────────────────────────
$h .= '<h2>3. Bukti transfer pindah ke Permintaan Kontrak</h2>';
$h .= '<p>Urutan di lapangan: <b>SKP terbit → klien membayar → bukti transfer masuk → berkas dikirim ke Legal</b>. '
    . 'Karena itu bukti transfer dikeluarkan dari syarat submit SKP, dan dijadikan syarat di tahap Permintaan Kontrak '
    . 'supaya kontrol pembayarannya tidak hilang.</p>';
$h .= '<h3>a. Di SKP — tidak lagi diminta</h3>';
$h .= $img('03_skp_lampiran.png', 'Gambar 5 — Lampiran SKP kini hanya KTP &amp; NPWP yang bertanda wajib (*). Keterangan di bawah formulir menyebutkan ke mana bukti transfer pindah.');
$h .= '<h3>b. Di Permintaan Kontrak — wajib sebelum ke Legal</h3>';
$h .= $img('04_cr_bukti_kosong.png', 'Gambar 6 — Belum ada bukti transfer: kotak kuning, dengan tempat mengunggah. Berkasnya tersimpan sebagai lampiran SKP terkait, jadi Legal tetap menerima satu berkas utuh.');
$h .= $img('05_cr_ditolak.png', 'Gambar 7 — Menekan &ldquo;Simpan &amp; Tandai Terkirim&rdquo; tanpa bukti transfer: ditolak, perubahan lain tetap tersimpan sebagai draft.');
$h .= $img('06_cr_bukti_terisi.png', 'Gambar 8 — Setelah diunggah: kotak berubah hijau dan berkasnya bisa dibuka.');
$h .= $img('07_cr_terkirim.png', 'Gambar 9 — Percobaan kirim berikutnya lolos: nomor formulir terbit (FPK/PC/2026/001) dan status menjadi <i>Terkirim ke Legal</i>.');

// ── Bukti pengujian ──────────────────────────────────────────────────────────
$h .= '<div class="page-break"></div>';
$h .= '<h2>Bukti tidak ada error</h2>';
$h .= '<p>Pengujian dijalankan 22 September 2026 pada server lokal (PHP 8.2.4 + MySQL, basis data pengembangan). '
    . 'Tiap layar dibuka lewat peramban sungguhan, bukan sekadar dibaca kodenya.</p>';

$h .= '<h3>A. Pemeriksaan sintaks seluruh berkas yang disentuh</h3>';
$berkas = [
    ['app/pages/offers.php', 'Opsi B — unggah dokumen ber-TTD'],
    ['app/pages/offer_print_template.php', 'Ruang TTD Surat Penawaran'],
    ['app/pages/skp.php', 'Lampiran SKP'],
    ['app/pages/skp_print_body.php', 'Ruang TTD SKP'],
    ['app/pages/contract_request.php', 'Bukti transfer + penghalang ke Legal'],
    ['app/helpers.php', 'Hak akses rute baru'],
    ['public/index.php', 'Pendaftaran rute'],
    ['database/migrations/043_offer_wet_sign.php', 'Kolom sign_method &amp; signed_doc_path'],
];
$h .= '<table><thead><tr><th style="width:44%">Berkas</th><th style="width:31%">Untuk perubahan</th>'
    . '<th class="c">Hasil <span class="code">php -l</span></th></tr></thead><tbody>';
foreach ($berkas as [$f, $u]) {
    $h .= '<tr><td><i>' . $f . '</i></td><td>' . $u . '</td><td class="c ok">Bersih</td></tr>';
}
$h .= '</tbody></table>';
$h .= '<p class="muted" style="font-size:8.2pt">Enam berkas lain yang ikut berubah pada periode yang sama '
    . '(<i>transactions.php</i>, <i>AllocationService.php</i>, <i>users.php</i>, <i>bootstrap.php</i>, '
    . '<i>stock_report.php</i>, <i>mobile-tables.js</i>) juga diperiksa dengan hasil yang sama.</p>';

$h .= '<h3>B. Uji jalannya fitur — 11 langkah, semuanya berhasil</h3>';
$langkah = [
    ['Masuk sebagai pengguna', 'Halaman login terbuka &amp; berhasil masuk', '200'],
    ['SP #2 — panel persetujuan', 'Opsi A &amp; Opsi B tampil berdampingan (Gambar 1)', '200'],
    ['SP #5 — disetujui lewat Opsi B', 'Status DEAL &amp; tombol Lihat Dokumen ber-TTD (Gambar 2)', '200'],
    ['Formulir SKP baru', 'Lampiran wajib tinggal KTP &amp; NPWP (Gambar 5)', '200'],
    ['Pindah properti (e-Walk ⇄ Pentacity)', 'Sesi berpindah tanpa gangguan', '200'],
    ['Permintaan Kontrak dibuka', 'Kotak Bukti Transfer tampil, status belum ada (Gambar 6)', '200'],
    ['Kirim ke Legal <b>tanpa</b> bukti transfer', '<b>Ditolak</b> — sesuai rancangan (Gambar 7)', '200'],
    ['Unggah bukti transfer', 'Tersimpan sebagai lampiran SKP (Gambar 8)', '200'],
    ['Kirim ke Legal <b>setelah</b> bukti ada', '<b>Lolos</b> — nomor formulir terbit (Gambar 9)', '200'],
    ['Cetak PDF Surat Penawaran', 'PDF terbentuk, ruang TTD tampil (Gambar 3)', '200'],
    ['Cetak PDF SKP', 'PDF terbentuk, ruang TTD tampil (Gambar 4)', '200'],
];
$h .= '<table><thead><tr><th class="c" style="width:6%">No</th><th style="width:34%">Langkah</th>'
    . '<th>Hasil</th><th class="c" style="width:11%">Kode HTTP</th><th class="c" style="width:13%">Error PHP</th></tr></thead><tbody>';
foreach ($langkah as $i => [$l, $r, $s]) {
    $h .= '<tr><td class="c">' . ($i + 1) . '</td><td>' . $l . '</td><td>' . $r . '</td>'
        . '<td class="c ok">' . $s . '</td><td class="c">Tidak ada</td></tr>';
}
$h .= '</tbody></table>';
$h .= '<p class="muted" style="font-size:8.2pt">Dua perpindahan properti digabung menjadi baris 5; seluruhnya tercatat sebagai 13 permintaan halaman. '
    . 'Seluruh keluaran halaman diperiksa terhadap kata kunci <span class="code">Fatal error</span>, '
    . '<span class="code">Warning</span>, <span class="code">Notice</span> dan <span class="code">Uncaught</span> — '
    . 'tidak satu pun ditemukan.</p>';

$h .= '<div class="box warn"><b>Satu catatan jujur.</b> Di layar pengembang muncul peringatan '
    . '<span class="code">libur.deno.dev</span> terblokir. Itu pengambilan kalender libur nasional dari internet '
    . '(<i>app/pages/bootstrap.php</i>) yang memang tidak bisa dijangkau dari komputer uji — <b>sudah ada sejak '
    . 'sebelum perubahan ini</b>, muncul juga di halaman login, dan tidak mempengaruhi satu pun fitur di laporan ini.</div>';

$h .= '<div class="box good"><b>Data uji dibersihkan.</b> Akun uji, formulir permintaan kontrak percobaan, '
    . 'bukti transfer contoh, dan nomor formulir yang terpakai sudah dihapus kembali setelah pengujian selesai. '
    . 'Basis data pengembangan kembali seperti semula.</div>';

// ── Penerapan ────────────────────────────────────────────────────────────────
$h .= '<h2>Yang perlu dilakukan saat dipasang ke server</h2>';
$h .= '<table><thead><tr><th class="c" style="width:8%">No</th><th style="width:38%">Langkah</th><th>Keterangan</th></tr></thead><tbody>'
    . '<tr><td class="c">1</td><td>Jalankan <span class="code">php db_migrate.php</span></td>'
    . '<td>Menambah kolom <span class="code">sign_method</span> &amp; <span class="code">signed_doc_path</span> pada tabel penawaran (migrasi 043). Aman diulang.</td></tr>'
    . '<tr><td class="c">2</td><td>Pastikan folder <span class="code">public/uploads/offer</span> bisa ditulis</td>'
    . '<td>Tempat menyimpan scan surat ber-TTD. Dibuat otomatis bila izinnya mengizinkan.</td></tr>'
    . '<tr><td class="c">3</td><td>Tidak ada hak akses baru</td>'
    . '<td>Ketiga perubahan memakai hak yang sudah ada: <b>Kelola Surat Penawaran</b> dan <b>Kelola SKP</b>.</td></tr>'
    . '</tbody></table>';

$h .= '<table class="kv" style="margin-top:12mm"><tr>'
    . '<td style="width:50%">Dikerjakan oleh<br><br><br>( Tim Pengembang CLARA )</td>'
    . '<td style="width:50%">Diperiksa oleh<br><br><br>( ......................................... )</td></tr></table>';

$out = $root . '/docs';
@mkdir($out, 0777, true);
$file = $out . '/Laporan_Perbaikan_Penawaran_SKP_22Sep2026.pdf';
$mpdf = clara_letterhead_mpdf();
$mpdf->SetTitle('Laporan Perbaikan — Surat Penawaran, SKP & Permintaan Kontrak');
$mpdf->WriteHTML($h);
$mpdf->Output($file, \Mpdf\Output\Destination::FILE);
echo '  ✓ ' . basename($file) . ' (' . $mpdf->page . " halaman)\n";
