<?php
/** Laporan bug & permintaan user — modul Penawaran & SKP. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/app/pdf.php';

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$IMG = $root . '/docs/bugreport_assets/';

$ITEM = [
[
 'no'=>1, 'jenis'=>'BUG', 'prio'=>'Tinggi', 'modul'=>'Surat Penawaran',
 'judul'=>'Daftar Unit / Lokasi terpotong di 80 baris',
 'gejala'=>'Saat membuka dropdown <b>Unit / Lokasi</b> tanpa mengetik apa pun, daftarnya berhenti di tengah. '
   . 'Unit yang ada di Master Exhibition dan berstatus aktif tidak ikut tampil, sehingga sales terpaksa menebak dan mengetik kodenya.',
 'gbr'=>'01_unit_dropdown.png',
 'gbrcap'=>'Dropdown berhenti di FF-009 — unit setelahnya tidak muncul.',
 'sebab'=>'Di <i>app/pages/offers.php</i> baris <b>1162</b>, daftar dipotong keras:<br>'
   . '<span class="code">list.slice(0, 80).forEach(...)</span><br><br>'
   . 'Query sumbernya (<i>masterOptions()</i>) <b>tidak</b> membatasi jumlah — semua unit aktif sudah terkirim ke browser. '
   . 'Pemotongan murni terjadi di tampilan.',
 'angka'=>'Dari dump produksi 18 September: <b>e-Walk 96 unit aktif</b>, <b>Pentacity 113 unit aktif</b>. '
   . 'Artinya <b>16 unit e-Walk</b> dan <b>33 unit Pentacity</b> tidak pernah muncul kecuali diketik manual.',
 'usul'=>'Naikkan batasnya (mis. 300) atau hilangkan sama sekali — kotaknya sudah bisa di-scroll '
   . '(<span class="code">max-height:260px; overflow-y:auto</span>), jadi daftar panjang tetap aman. '
   . 'Batas 80 juga berlaku untuk dropdown Client (baris 1195, dipotong 60) — sebaiknya sekalian dinaikkan.',
 'risiko'=>'Sangat kecil. Hanya mengubah angka pemotongan di sisi tampilan.',
],
[
 'no'=>2, 'jenis'=>'BUG', 'prio'=>'Tinggi', 'modul'=>'Surat Penawaran',
 'judul'=>'DP yang diisi Rp 0 kembali terisi otomatis saat direvisi',
 'gejala'=>'Sales menetapkan <b>Nominal DP = Rp 0</b> (kesepakatan tanpa DP), penawaran disimpan. '
   . 'Begitu penawaran itu dibuka lagi untuk revisi, DP-nya sudah berubah sendiri menjadi angka hitungan otomatis '
   . '(harga per bulan × jumlah bulan). Nilai nol yang disepakati hilang tanpa disadari.',
 'gbr'=>'', 'gbrcap'=>'',
 'sebab'=>'Dua baris yang saling menguatkan di <i>app/pages/offers.php</i>:<br><br>'
   . '<b>Baris 1234</b> — penanda &ldquo;sudah diisi manual&rdquo; sengaja tidak dipasang kalau nilainya nol:<br>'
   . '<span class="code">if (hid.value &amp;&amp; hid.value !== \'0\') { ... fmt.dataset.touched = \'1\'; }</span><br><br>'
   . '<b>Baris 1246</b> — pengisi otomatis hanya berhenti kalau penanda itu ada:<br>'
   . '<span class="code">if (!fmt || !hid || fmt.dataset.touched) return;</span><br><br>'
   . 'Akibatnya <b>DP 0 tidak bisa dibedakan dari DP yang belum diisi</b>. Diperparah baris <b>1028</b>, '
   . 'yang merender nilai 0 sebagai kotak kosong: <span class="code">value="&lt;?= (int)($offer[\'dp_amount\'] ?? 0) ?: \'\' ?&gt;"</span>.',
 'angka'=>'',
 'usul'=>'Bedakan &ldquo;nol&rdquo; dari &ldquo;kosong&rdquo;. Cara paling ringan: pasang penanda '
   . '<span class="code">touched</span> untuk semua nilai yang sudah tersimpan, termasuk nol — yaitu ubah syaratnya jadi '
   . '<span class="code">if (hid.value !== \'\')</span>, dan render nilai 0 apa adanya, bukan jadi string kosong. '
   . 'Opsi lain: tambahkan centang <b>&ldquo;Tanpa DP&rdquo;</b> yang eksplisit.',
 'risiko'=>'Kecil, tapi <b>harus diuji dua arah</b>: penawaran baru (DP masih boleh terisi otomatis) '
   . 'dan revisi (DP tersimpan tidak boleh ketimpa), termasuk kolom Security Deposit yang memakai mekanisme sama.',
],
[
 'no'=>3, 'jenis'=>'PERMINTAAN', 'prio'=>'Tinggi', 'modul'=>'SKP Pameran',
 'judul'=>'Bukti Transfer dijadikan opsional — urutannya terbalik dengan proses nyata',
 'gejala'=>'SKP tidak bisa di-submit sebelum <b>Bukti Transfer</b> diunggah. Padahal di lapangan urutannya kebalikan: '
   . 'klien butuh invoice/SKP dulu sebagai dasar membayar. Bukti transfer sebelum SKP terbit hampir tidak pernah ada.',
 'gbr'=>'02_skp_lampiran.png',
 'gbrcap'=>'Bukti Transfer bertanda wajib (*), sejajar dengan Scan KTP dan NPWP.',
 'sebab'=>'Dua daftar di <i>app/pages/skp.php</i> yang harus diubah bersamaan:<br>'
   . '<b>Baris 285</b> &nbsp;<span class="code">$wajib = [\'ktp\', \'npwp\', \'bukti_transfer\'];</span> &mdash; tanda * di formulir<br>'
   . '<b>Baris 518</b> &nbsp;<span class="code">$need = [\'ktp\'=&gt;..., \'npwp\'=&gt;..., \'bukti_transfer\'=&gt;...];</span> &mdash; penghalang submit<br><br>'
   . 'Teks bantuan di baris <b>342</b> juga menyebut ketiganya dan perlu ikut disesuaikan.',
 'angka'=>'',
 'usul'=>'Keluarkan <span class="code">bukti_transfer</span> dari kedua daftar. Supaya tetap terkontrol, '
   . 'jadikan syarat di <b>tahap berikutnya</b>: bukti transfer wajib sebelum SKP boleh berpindah ke status '
   . 'lunas/aktif, bukan saat pengajuan. Dengan begitu tidak ada pembayaran yang lolos tanpa bukti.',
 'risiko'=>'Kecil secara teknis. Yang perlu disepakati adalah <b>di titik mana</b> bukti transfer menjadi wajib, '
   . 'supaya kontrol keuangannya tidak hilang.',
],
[
 'no'=>4, 'jenis'=>'PERMINTAAN', 'prio'=>'Sedang', 'modul'=>'Surat Penawaran',
 'judul'=>'Tanda tangan bisa diunggah dari foto, bukan hanya digambar',
 'gejala'=>'Halaman tanda tangan hanya menyediakan kanvas untuk mencoret dengan jari/mouse. '
   . 'Kenyataannya banyak klien tidak mau repot membuka tautan dan menggambar; mereka sudah mengonfirmasi lewat chat '
   . 'lalu mengirimkan foto atau berkas tanda tangannya, tinggal ditempel oleh sales.',
 'gbr'=>'05_skp_ttd.png',
 'gbrcap'=>'Praktik yang berjalan sekarang: dokumen dicetak, ditandatangani basah, lalu difoto.',
 'sebab'=>'<i>app/pages/offer_sign_template.php</i> baris <b>157&ndash;189</b> hanya menyediakan '
   . '<span class="code">&lt;canvas id="pad"&gt;</span> dan mengirim hasilnya lewat '
   . '<span class="code">canvas.toDataURL(\'image/png\')</span>. Tidak ada jalur unggah berkas.',
 'angka'=>'',
 'usul'=>'<b>Kabar baiknya, penyimpanannya sudah siap.</b> Fungsi penyimpan di <i>offers.php</i> baris <b>1803</b> '
   . 'menerima format <span class="code">data:image/png;base64,</span> — sama persis dengan hasil kanvas. '
   . 'Jadi cukup tambahkan tombol <b>Unggah foto tanda tangan</b> yang membaca berkas, menggambarnya ke kanvas '
   . '(sekaligus memberi kesempatan mengatur posisi &amp; ukuran), lalu mengirimnya lewat jalur yang sudah ada. '
   . '<b>Tidak perlu ubah struktur database.</b>',
 'risiko'=>'Sedang. Batasi jenis berkas (JPG/PNG) dan ukurannya — batas sekarang 800 KB di baris 1807, '
   . 'foto dari HP biasanya lebih besar, jadi perlu dikecilkan dulu di browser. '
   . 'Perlu juga disepakati siapa yang boleh menempelkan TTD klien, karena ini menyangkut keabsahan dokumen.',
],
[
 'no'=>5, 'jenis'=>'SEBAGIAN DATA', 'prio'=>'Rendah', 'modul'=>'Surat Penawaran',
 'judul'=>'Nomor WhatsApp &amp; email di surat masih nomor kantor, bukan nomor sales',
 'gejala'=>'Di surat penawaran tercetak &ldquo;Bukti pembayaran dikirim via WhatsApp ke <b>0542-8520555</b>&rdquo; — '
   . 'nomor kantor. Tiap sales ingin mencantumkan nama, nomor, dan email masing-masing.',
 'gbr'=>'04_offer_wa.png',
 'gbrcap'=>'Nomor kantor tercetak, bukan nomor sales yang membuat penawaran.',
 'sebab'=>'<b>Sebenarnya sistem sudah mendukung ini.</b> Di <i>app/pages/offer_print_template.php</i> baris <b>29</b>:<br>'
   . '<span class="code">$payWa = $o[\'pic_phone\'] ?: $OFFICE_PHONE;</span><br><br>'
   . 'Artinya nomor sales dipakai lebih dulu; nomor kantor hanya cadangan kalau kosong. '
   . 'Email pun sudah ikut tercetak bila ada. Yang terjadi: kolom <b>phone</b> dan <b>email</b> di Master PIC '
   . '<b>masih kosong</b> — kolomnya baru dibuat oleh migration <i>018_add_pic_contact.php</i>, '
   . 'yang baru dijalankan di server hari ini.',
 'angka'=>'',
 'usul'=>'<b>Langkah pertama bukan koding, tapi mengisi data:</b> buka <b>Master &rarr; PIC</b>, '
   . 'isi nomor WhatsApp dan email tiap sales. Surat berikutnya langsung memakai data itu.<br><br>'
   . 'Yang memang masih tertanam di kode dan perlu dipindah ke pengaturan: nomor kantor cadangan (baris <b>5</b>) '
   . 'dan nomor rekening perusahaan (baris <b>209&ndash;210</b>).',
 'risiko'=>'Tidak ada. Mengisi Master PIC tidak mengubah surat yang sudah terbit — hanya surat baru.',
],
[
 'no'=>6, 'jenis'=>'PERMINTAAN', 'prio'=>'Sedang', 'modul'=>'Surat Penawaran',
 'judul'=>'Baris &ldquo;Grand Total&rdquo; membingungkan — diminta dihapus',
 'gejala'=>'Baris terakhir tabel biaya berbunyi <b>&ldquo;Grand Total (pembayaran awal + deposit)&rdquo;</b>, '
   . 'mencampur nilai sewa dengan uang jaminan yang nantinya dikembalikan. Klien mengira itu semua biaya sewa.',
 'gbr'=>'03_offer_total.png',
 'gbrcap'=>'Grand Total Rp 8.770.000 mencampur sewa Rp 7.770.000 dengan deposit Rp 1.000.000.',
 'sebab'=>'<i>app/pages/offer_print_template.php</i> baris <b>193</b>:<br>'
   . '<span class="code">&lt;tr class="grand"&gt;&lt;td&gt;Grand Total (pembayaran awal + deposit)&lt;/td&gt;...</span>',
 'angka'=>'',
 'usul'=>'Hapus baris Grand Total. Susunan yang diminta, dari atas ke bawah:<br><br>'
   . '<table class="mini"><tr><td>Subtotal sewa</td><td class="n">Rp 7.000.000</td></tr>'
   . '<tr><td>PPN 12%</td><td class="n">Rp 770.000</td></tr>'
   . '<tr><td>Security Deposit <i>(sudah dibayarkan)</i></td><td class="n">Rp 1.000.000</td></tr>'
   . '<tr class="last"><td><b>Total setelah PPN</b></td><td class="n"><b>Rp 7.770.000</b></td></tr></table><br>'
   . 'Jadi <b>Total setelah PPN</b> pindah ke paling bawah sebagai angka penutup, dan deposit diberi keterangan '
   . '<b>(sudah dibayarkan)</b> — mengikuti format SKP kertas yang selama ini dipakai.',
 'risiko'=>'Kecil. Perlu dicek juga apakah variabel <span class="code">{grand}</span> dipakai di '
   . '<b>Template Penawaran</b> pada bagian Cara Pembayaran — kalau ya, teksnya ikut disesuaikan.',
],
[
 'no'=>7, 'jenis'=>'PERMINTAAN', 'prio'=>'Sedang', 'modul'=>'SKP Pameran',
 'judul'=>'KTP opsional untuk tenant yang kontraknya sudah berjalan',
 'gejala'=>'Nomor KTP dan Scan KTP wajib diisi untuk semua SKP. Untuk tenant lama yang sudah berjalan '
   . 'sejak sebelum CLARA dipakai, dokumennya sering tidak ada di tangan sales, sehingga SKP tidak bisa diterbitkan. '
   . 'Kewajiban ini diminta hanya berlaku untuk <b>klien baru, periode Oktober 2026 ke atas</b>.',
 'gbr'=>'',
 'gbrcap'=>'',
 'sebab'=>'Tiga tempat di <i>app/pages/skp.php</i>:<br>'
   . '<b>Baris 275</b> — label &ldquo;Nomor KTP Penanggung Jawab *&rdquo;<br>'
   . '<b>Baris 285</b> — <span class="code">$wajib</span> memuat <span class="code">\'ktp\'</span><br>'
   . '<b>Baris 561</b> — <span class="code">if (!$ktp) $missNum[] = \'Nomor KTP\';</span>',
 'angka'=>'',
 'usul'=>'Buat kewajiban KTP <b>bersyarat</b>, bukan dimatikan total — supaya tenant baru tetap terdata lengkap. '
   . 'Syarat yang jelas dan mudah diperiksa: wajib bila <b>tanggal mulai sewa &ge; 1 Oktober 2026</b> '
   . '<u>atau</u> klien belum pernah punya SKP sebelumnya. Di luar itu opsional, dengan catatan kecil di formulir '
   . 'seperti &ldquo;tenant berjalan — KTP menyusul&rdquo;.',
 'risiko'=>'Sedang. Batasnya harus ditulis tegas di kode, jangan diserahkan ke penilaian tiap orang, '
   . 'supaya tidak jadi celah permanen. Sebaiknya tetap ada laporan SKP yang KTP-nya belum lengkap.',
],
];

$CSS = '<style>
 body { font-family: sans-serif; font-size: 9.6pt; color:#1F2937; line-height:1.55; }
 h1 { font-size:18pt; margin:0 0 2mm; }
 h2 { font-size:12.5pt; color:#0A7267; margin:0 0 3mm; padding-bottom:1.5mm; border-bottom:1.2pt solid #0D9488; }
 h3 { font-size:10.5pt; margin:4mm 0 1.5mm; page-break-after:avoid; color:#0A7267; }
 p { margin:0 0 2.8mm; }
 .kicker { font-size:8.4pt; letter-spacing:.08em; text-transform:uppercase; color:#0D9488; font-weight:bold; }
 .sub { color:#6B7280; font-size:8.8pt; margin-bottom:4mm; }
 .page-break { page-break-before:always; }
 table { width:100%; border-collapse:collapse; }
 tr { page-break-inside:avoid; }
 th,td { border:0.4pt solid #D1D5DB; padding:1.6mm 2mm; vertical-align:top; font-size:8.7pt; }
 th { background:#F0FDF4; color:#0A7267; font-size:7.8pt; text-transform:uppercase; letter-spacing:.03em; }
 td.n, th.n { text-align:right; } td.c, th.c { text-align:center; }
 .muted { color:#6B7280; }
 .box { page-break-inside:avoid; border:0.6pt solid #D1D5DB; border-left:2.8pt solid #0D9488; background:#F8FAFC; padding:2.8mm 3.2mm; margin-bottom:3mm; }
 .box.bad { border-left-color:#B91C1C; background:#FEF2F2; }
 .box.warn { border-left-color:#B45309; background:#FFFBEB; }
 .box.good { border-left-color:#047857; background:#F0FDF4; }
 .code { font-family:monospace; background:#F1F5F9; border:0.4pt solid #CBD5E1; padding:0.4mm 1.4mm; font-size:8.1pt; }
 .tag { display:inline-block; padding:0.5mm 2.2mm; border-radius:2.5mm; font-size:7.6pt; font-weight:bold; letter-spacing:.03em; }
 .tag.bug { background:#FEE2E2; color:#991B1B; }
 .tag.req { background:#DBEAFE; color:#1E40AF; }
 .tag.dat { background:#FEF3C7; color:#92400E; }
 .no { display:inline-block; background:#0D9488; color:#fff; font-weight:bold; padding:0.5mm 2.6mm; border-radius:3mm; font-size:9.4pt; }
 .cap { font-size:7.8pt; color:#6B7280; font-style:italic; margin:1mm 0 3mm; }
 img.ss { width:100%; border:0.5pt solid #CBD5E1; }
 table.mini td { border:0.4pt solid #D1D5DB; padding:1.2mm 2mm; font-size:8.4pt; }
 table.mini tr.last td { background:#F0FDF4; }
 .kv td { border:none; padding:0 0 1.2mm; }
 ol,ul { margin:0 0 3mm 5.5mm; padding:0; } li { margin-bottom:2mm; }
</style>';

$tagCls = ['BUG'=>'bug', 'PERMINTAAN'=>'req', 'SEBAGIAN DATA'=>'dat'];

$h  = $CSS;
$h .= '<div class="kicker">Laporan Bug &amp; Permintaan Pengguna</div>';
$h .= '<h1>Modul Surat Penawaran &amp; SKP Pameran</h1>';
$h .= '<div class="sub">CLARA — Casual Leasing Achievement &amp; Revenue Analytics &nbsp;·&nbsp; '
    . '7 temuan dari tim Casual Leasing &nbsp;·&nbsp; 22 September 2026</div>';

$h .= '<div class="box">Dokumen ini merangkum tujuh hal yang dilaporkan tim CL setelah modul Penawaran dan SKP mulai dipakai. '
    . 'Tiap temuan sudah saya telusuri sampai ke baris kodenya, jadi bagian <b>Akar masalah</b> bisa langsung dipakai '
    . 'yang mengerjakan tanpa perlu mencari ulang. Nomor baris mengacu pada commit '
    . '<span class="code">e550149</span>.</div>';

$h .= '<h3>Ringkasan</h3>';
$h .= '<table><thead><tr><th class="c" style="width:6%">No</th><th style="width:13%">Jenis</th>'
    . '<th style="width:16%">Modul</th><th>Temuan</th><th class="c" style="width:12%">Prioritas</th></tr></thead><tbody>';
foreach ($ITEM as $it) {
    $h .= '<tr><td class="c"><b>' . $it['no'] . '</b></td>'
        . '<td><span class="tag ' . $tagCls[$it['jenis']] . '">' . $it['jenis'] . '</span></td>'
        . '<td>' . e($it['modul']) . '</td><td>' . $it['judul'] . '</td>'
        . '<td class="c">' . $it['prio'] . '</td></tr>';
}
$h .= '</tbody></table>';

$h .= '<div class="box good"><b>Dua hal yang perlu diluruskan sejak awal.</b><br><br>'
    . '<b>Nomor 5 bukan masalah kode.</b> Sistem sudah memakai nomor &amp; email sales bila ada; yang kosong adalah '
    . 'datanya di Master PIC. Cukup diisi, tidak perlu menunggu pengembangan.<br><br>'
    . '<b>Nomor 4 tidak butuh perubahan database.</b> Jalur penyimpanan tanda tangan yang sekarang sudah menerima '
    . 'bentuk yang sama dengan hasil unggahan foto, jadi yang ditambah hanya di layar depannya.</div>';

$h .= '<h3>Urutan pengerjaan yang disarankan</h3>';
$h .= '<table><thead><tr><th class="c" style="width:10%">Tahap</th><th style="width:24%">Isi</th>'
    . '<th style="width:16%">Perkiraan</th><th>Alasan didahulukan</th></tr></thead><tbody>'
    . '<tr><td class="c"><b>0</b></td><td>Isi Master PIC (no. 5)</td><td>Hari ini, tanpa koding</td>'
    . '<td>Tidak menyentuh kode sama sekali, hasilnya langsung terasa di surat berikutnya.</td></tr>'
    . '<tr><td class="c"><b>1</b></td><td>No. 1, 2, 6</td><td>Cepat</td>'
    . '<td>Tiga-tiganya perubahan kecil dan terkurung di satu tempat. No. 1 dan 2 menghambat kerja harian sales, '
    . 'no. 6 cuma susunan baris di surat.</td></tr>'
    . '<tr><td class="c"><b>2</b></td><td>No. 3, 7</td><td>Sedang</td>'
    . '<td>Keduanya melonggarkan syarat dokumen, jadi perlu kesepakatan dulu: di titik mana syaratnya kembali berlaku, '
    . 'supaya kontrolnya tidak hilang.</td></tr>'
    . '<tr><td class="c"><b>3</b></td><td>No. 4</td><td>Paling lama</td>'
    . '<td>Menyangkut keabsahan tanda tangan — butuh pembahasan siapa yang berwenang menempelkan, '
    . 'dan penanganan ukuran berkas foto.</td></tr>'
    . '</tbody></table>';

// ── Halaman detail ────────────────────────────────────────────────────────
foreach ($ITEM as $it) {
    $h .= '<div class="page-break"></div>';
    $h .= '<h2>' . $it['no'] . '. &nbsp;' . $it['judul'] . '</h2>';
    $h .= '<table class="kv" style="margin-bottom:3mm"><tr>'
        . '<td style="width:16%"><span class="muted">Jenis</span></td>'
        . '<td style="width:34%"><span class="tag ' . $tagCls[$it['jenis']] . '">' . $it['jenis'] . '</span></td>'
        . '<td style="width:16%"><span class="muted">Modul</span></td>'
        . '<td><b>' . e($it['modul']) . '</b> &nbsp;·&nbsp; prioritas <b>' . $it['prio'] . '</b></td></tr></table>';

    $h .= '<h3>Yang terjadi</h3><p>' . $it['gejala'] . '</p>';

    if ($it['gbr'] && is_file($IMG . $it['gbr'])) {
        $lebar = ['01_unit_dropdown.png'=>'48%', '05_skp_ttd.png'=>'38%'];
        $w = $lebar[$it['gbr']] ?? '100%';
        $h .= '<div style="text-align:center"><img class="ss" style="width:' . $w . '" src="' . $IMG . $it['gbr'] . '"></div>';
        $h .= '<div class="cap">' . $it['gbrcap'] . '</div>';
    }

    $h .= '<h3>Akar masalah</h3><div class="box">' . $it['sebab'] . '</div>';
    if ($it['angka']) $h .= '<div class="box warn">' . $it['angka'] . '</div>';
    $h .= '<h3>Usulan perbaikan</h3><div class="box good">' . $it['usul'] . '</div>';
    $h .= '<h3>Risiko &amp; catatan pengujian</h3><p class="muted">' . $it['risiko'] . '</p>';
}

// ── Penutup ───────────────────────────────────────────────────────────────
$h .= '<div class="page-break"></div><h2>Catatan penutup</h2>';
$h .= '<h3>Yang perlu diputuskan tim CL sebelum dikerjakan</h3>';
$h .= '<table><thead><tr><th class="c" style="width:8%">No</th><th style="width:34%">Pertanyaan</th><th>Kenapa perlu jawaban dulu</th></tr></thead><tbody>'
    . '<tr><td class="c">3</td><td>Bukti transfer wajib di titik mana kalau bukan saat pengajuan SKP?</td>'
    . '<td>Kalau syaratnya dihapus begitu saja tanpa pengganti, tidak ada lagi yang memastikan pembayaran terbukti.</td></tr>'
    . '<tr><td class="c">4</td><td>Siapa yang boleh menempelkan foto TTD klien?</td>'
    . '<td>Ini menyangkut keabsahan dokumen. Perlu jelas supaya tidak ada TTD yang ditempel tanpa persetujuan klien.</td></tr>'
    . '<tr><td class="c">7</td><td>Batas &ldquo;tenant berjalan&rdquo; dipatok di tanggal mulai sewa atau di riwayat klien?</td>'
    . '<td>Menentukan aturan mana yang ditulis di kode. Kalau tidak dipatok, kelonggarannya jadi permanen.</td></tr>'
    . '<tr><td class="c">6</td><td>Nilai mana yang jadi angka penutup di surat?</td>'
    . '<td>Sudah dijawab: <b>Total setelah PPN</b>, dengan deposit diberi keterangan (sudah dibayarkan). '
    . 'Dicatat di sini supaya tidak berubah lagi di tengah jalan.</td></tr>'
    . '</tbody></table>';

$h .= '<h3>Berkas yang akan tersentuh</h3>';
$h .= '<table><thead><tr><th style="width:42%">Berkas</th><th style="width:20%">Baris</th><th>Untuk temuan</th></tr></thead><tbody>'
    . '<tr><td><i>app/pages/offers.php</i></td><td>1162, 1195</td><td>No. 1</td></tr>'
    . '<tr><td><i>app/pages/offers.php</i></td><td>1028, 1234, 1246</td><td>No. 2</td></tr>'
    . '<tr><td><i>app/pages/skp.php</i></td><td>285, 342, 518</td><td>No. 3</td></tr>'
    . '<tr><td><i>app/pages/offer_sign_template.php</i></td><td>157&ndash;189</td><td>No. 4</td></tr>'
    . '<tr><td><i>app/pages/offer_print_template.php</i></td><td>5, 193, 209&ndash;211</td><td>No. 5 &amp; 6</td></tr>'
    . '<tr><td><i>app/pages/skp.php</i></td><td>275, 285, 561</td><td>No. 7</td></tr>'
    . '</tbody></table>';

$h .= '<div class="box warn"><b>Satu catatan soal pengujian.</b> Nomor 2, 3 dan 7 semuanya menyentuh '
    . 'aturan &ldquo;wajib / tidak wajib&rdquo;. Aturan seperti ini gampang terlihat benar di satu jalur '
    . 'lalu diam-diam rusak di jalur lain. Sebaiknya tiap perbaikan diuji pada <b>dua keadaan</b>: '
    . 'dokumen baru dan dokumen lama yang dibuka ulang untuk direvisi — karena di situlah nomor 2 pertama kali terlihat.</div>';

$h .= '<table class="kv" style="margin-top:10mm"><tr>'
    . '<td style="width:50%">Dilaporkan oleh<br><br><br>( Tim Casual Leasing )</td>'
    . '<td style="width:50%">Diterima oleh<br><br><br>( ......................................... )</td></tr></table>';

$out = $root . '/docs';
@mkdir($out, 0777, true);
$file = $out . '/Laporan_Bug_Penawaran_SKP_22Sep2026.pdf';
$mpdf = clara_letterhead_mpdf();
$mpdf->SetTitle('Laporan Bug & Permintaan — Surat Penawaran & SKP');
$mpdf->WriteHTML($h);
$mpdf->Output($file, \Mpdf\Output\Destination::FILE);
echo '  ✓ ' . basename($file) . ' (' . $mpdf->page . " halaman)\n";
