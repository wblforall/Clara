<?php
/**
 * Tutorial input + pemeriksaan selisih income untuk satu PIC.
 *
 * Dibuat setelah pertanyaan berulang "kenapa income saya di CLARA beda dengan
 * spreadsheet". Jawabannya hampir tidak pernah bug: tiga sebab yang sama terus
 * berulang, dan ketiganya bisa diperiksa sendiri oleh PIC-nya dalam hitungan
 * menit — asal tahu caranya. Itulah isi dokumen ini.
 *
 * Angkanya diambil LANGSUNG dari database, bukan diketik ulang, supaya tutorial
 * ini tidak pernah mengajarkan angka yang sudah basi.
 *
 * Pakai:
 *   php scripts/build_tutorial_input.php --pic="Shinta" --prop=2 --tahun=2026
 *   php scripts/build_tutorial_input.php --pic="Shinta" --keluar=docs/Tutorial.pdf
 */

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/pdf.php';

$opt = array_merge(
    ['pic' => '', 'prop' => '2', 'tahun' => date('Y'), 'keluar' => ''],
    getopt('', ['pic:', 'prop:', 'tahun:', 'keluar:'])
);
if ($opt['pic'] === '') {
    fwrite(STDERR, "Pakai: php scripts/build_tutorial_input.php --pic=\"Nama PIC\" [--prop=2] [--tahun=2026]\n");
    exit(2);
}
$pic   = (string) $opt['pic'];
$pid   = (int) $opt['prop'];
$tahun = (string) $opt['tahun'];
if ($opt['keluar'] === '') {
    $opt['keluar'] = $root . '/docs/Tutorial_Input_' . preg_replace('/[^A-Za-z0-9]+/', '_', $pic) . '.pdf';
}

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$h  = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

$namaProperti = (string) ($pdo->query('SELECT name FROM properties WHERE id = ' . $pid)->fetchColumn() ?: 'Properti ' . $pid);

// ── 1. Income per bulan menurut CLARA ────────────────────────────────────────
$st = $pdo->prepare(
    'SELECT period_key, COALESCE(SUM(amount), 0) rp
       FROM transaction_allocations
      WHERE property_id = ? AND pic_name = ? AND period_key LIKE ?
      GROUP BY period_key ORDER BY period_key'
);
$st->execute([$pid, $pic, $tahun . '-%']);
$perBulan = $st->fetchAll();
$totalTahun = array_sum(array_column($perBulan, 'rp'));

// ── 2. Tiga sebab selisih — dihitung dari data, bukan dikira ─────────────────

// (a) Dobel: client + unit + periode sama persis, lebih dari satu baris.
$dobel = $pdo->prepare(
    "SELECT c.company_name, COALESCE(c.brand_name, c.company_name) merek, t.master_code, t.module,
            t.start_date, t.end_date, COUNT(*) n,
            GROUP_CONCAT(t.id ORDER BY t.id) ids,
            SUM(COALESCE(NULLIF(t.final_amount,0), t.total_calculated)) jumlah,
            MAX(COALESCE(NULLIF(t.final_amount,0), t.total_calculated)) terbesar
       FROM transactions t
       LEFT JOIN master_clients c ON c.id = t.client_id
      WHERE t.property_id = ? AND t.deleted_at IS NULL AND t.pic_name = ?
        AND YEAR(t.start_date) = ?
      GROUP BY c.company_name, c.brand_name, t.master_code, t.module, t.start_date, t.end_date
     HAVING n > 1
      ORDER BY t.start_date"
);
$dobel->execute([$pid, $pic, $tahun]);
$barisDobel = $dobel->fetchAll();
$rpDobel = 0.0;
foreach ($barisDobel as $d) $rpDobel += (float) $d['jumlah'] - (float) $d['terbesar'];

// (b) Kontrak yang melewati pergantian bulan — seluruh nilainya masuk bulan MULAI.
$lewat = $pdo->prepare(
    "SELECT COUNT(*) n, COALESCE(SUM(COALESCE(NULLIF(final_amount,0), total_calculated)), 0) rp
       FROM transactions
      WHERE property_id = ? AND deleted_at IS NULL AND pic_name = ? AND YEAR(start_date) = ?
        AND DATE_FORMAT(start_date, '%Y-%m') <> DATE_FORMAT(end_date, '%Y-%m')"
);
$lewat->execute([$pid, $pic, $tahun]);
$silang = $lewat->fetch();

// (c) Penyewa rutin yang masa sewanya SUDAH LEWAT tapi belum diperpanjang.
//
// Dikelompokkan per penyewa + modul, BUKAN per kode unit: unit yang sekadar
// berganti kode (Guda-003 menjadi GF01) akan tampak seperti penyewa berhenti,
// padahal pindah baris saja. Dan patokannya tanggal HARI INI, bukan akhir
// tahun — kontrak yang masih berjalan bukan kontrak yang terlewat. Dua
// saringan ini yang membuat daftarnya bisa dipercaya; tanpa keduanya isinya
// penuh peringatan palsu dan orang berhenti membacanya.
$putus = $pdo->prepare(
    "SELECT COALESCE(c.brand_name, c.company_name) merek, t.module,
            MAX(t.end_date) akhir, COUNT(*) n,
            SUBSTRING_INDEX(GROUP_CONCAT(t.master_code ORDER BY t.end_date DESC), ',', 1) master_code,
            SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(NULLIF(t.final_amount,0), t.total_calculated)
                            ORDER BY t.end_date DESC), ',', 1) nilai
       FROM transactions t
       LEFT JOIN master_clients c ON c.id = t.client_id
      WHERE t.property_id = ? AND t.deleted_at IS NULL AND t.pic_name = ?
        AND YEAR(t.start_date) = ?
      GROUP BY c.brand_name, c.company_name, t.module
     HAVING n >= 3 AND MAX(t.end_date) < CURDATE()
      ORDER BY akhir DESC"
);
$putus->execute([$pid, $pic, $tahun]);
$barisPutus = $putus->fetchAll();
$rpPutus = 0.0;
foreach ($barisPutus as $b) $rpPutus += (float) $b['nilai'];

ob_start(); ?>
<style>
    body { font-family: sans-serif; font-size: 10.5pt; color: #111; line-height: 1.55; }
    h1 { font-size: 16pt; margin: 0 0 2px; }
    h2 { font-size: 12.5pt; margin: 15px 0 6px; color: #0f766e; border-bottom: 1px solid #99f6e4; padding-bottom: 3px; }
    h3 { font-size: 11pt; margin: 11px 0 4px; }
    .sub { color: #555; font-size: 9.5pt; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; margin: 6px 0 10px; font-size: 9.5pt; }
    th, td { border: 1px solid #cbd5e1; padding: 4px 7px; text-align: left; vertical-align: top; }
    th { background: #f1f5f9; }
    td.n { text-align: right; white-space: nowrap; }
    .box { border: 1px solid #cbd5e1; border-left: 3px solid #0D9488; background: #f8fafc; padding: 8px 11px; margin: 8px 0; }
    .warn { border-left-color: #b45309; background: #fffbeb; }
    .bad  { border-left-color: #b91c1c; background: #fef2f2; }
    .muted { color: #666; }
    .tebal { font-weight: 700; }
    .merah { color: #b91c1c; }
    ol, ul { margin: 4px 0 8px 17px; padding: 0; }
    li { margin-bottom: 4px; }
    .langkah { background: #0D9488; color: #fff; font-weight: 700; padding: 1px 7px; border-radius: 10px; font-size: 9pt; }
</style>

<h1>Cara Input yang Angkanya Pasti Cocok</h1>
<div class="sub">Untuk <b><?= $h($pic) ?></b> &middot; <?= $h($namaProperti) ?> &middot; tahun <?= $h($tahun) ?>
    &middot; dibuat <?= date('d/m/Y H:i') ?><br>
    Seluruh angka di dokumen ini dibaca langsung dari database CLARA saat dicetak.</div>

<h2>1. Kenapa angkanya bisa beda dengan spreadsheet</h2>
<p>Hampir tidak pernah karena sistemnya salah hitung. Dari pemeriksaan baris per baris, selisihnya
selalu kembali ke <b>tiga sebab</b> &mdash; dan ketiganya bisa diperiksa sendiri.</p>

<div class="box">
    <p style="margin:0 0 3px"><span class="tebal">Sebab 1 &mdash; Perpanjangan bulan berikutnya belum diinput.</span>
    Penyewa bulanan harus diinput <b>ulang tiap siklus</b>. Spreadsheet biasanya sudah menuliskan
    bulan depan sebagai proyeksi, CLARA hanya menghitung yang benar-benar sudah masuk.
    Ini penyebab selisih terbesar, dan selalu membuat CLARA tampak lebih kecil.</p>
</div>
<div class="box">
    <p style="margin:0 0 3px"><span class="tebal">Sebab 2 &mdash; Kontrak melewati pergantian bulan.</span>
    Kontrak 10 Okt&ndash;9 Nov dihitung CLARA <b>seluruhnya di bulan Oktober</b> (bulan mulainya),
    sedangkan spreadsheet kerap menaruhnya di November. Nilainya tidak hilang, hanya pindah bulan
    &mdash; setahun penuh jumlahnya tetap sama.</p>
    <p style="margin:4px 0 0" class="muted">Di data <?= $h($pic) ?> ada
    <b><?= (int) ($silang['n'] ?? 0) ?> kontrak</b> seperti ini, senilai <b><?= $rp($silang['rp'] ?? 0) ?></b>.</p>
</div>
<div class="box bad">
    <p style="margin:0 0 3px"><span class="tebal">Sebab 3 &mdash; Satu kontrak terinput dua kali.</span>
    Ini membuat CLARA tampak <b>lebih besar</b> dari seharusnya, dan merusak laporan mal.
    Harus dibersihkan lewat menu <b>Pengajuan Hapus Data</b>, bukan dibiarkan.</p>
</div>

<h2>2. Income <?= $h($pic) ?> di CLARA per bulan</h2>
<table>
    <tr><th>Bulan</th><th class="n">Income menurut CLARA</th></tr>
    <?php foreach ($perBulan as $b): ?>
    <tr><td><?= $h($b['period_key']) ?></td><td class="n"><?= $rp($b['rp']) ?></td></tr>
    <?php endforeach; ?>
    <tr style="background:#f1f5f9;font-weight:700">
        <td>TOTAL <?= $h($tahun) ?></td><td class="n"><?= $rp($totalTahun) ?></td></tr>
</table>
<p class="muted" style="margin-top:-4px">Bandingkan kolom ini dengan baris <b>ACTUAL PIC</b> di
spreadsheet, satu bulan satu bulan. Bulan yang selisihnya besar itulah yang diperiksa dulu &mdash;
jangan mencari di semua bulan sekaligus.</p>

<?php if ($barisDobel): ?>
<h2>3. Yang terinput dua kali &mdash; perbaiki ini lebih dulu</h2>
<p>Baris berikut punya <b>client, unit, dan periode yang sama persis</b> tetapi tercatat lebih dari
satu kali. Kelebihannya <b class="merah"><?= $rp($rpDobel) ?></b>.</p>
<table>
    <tr><th>Penyewa</th><th>Unit</th><th>Periode</th><th class="n">Nomor transaksi</th><th class="n">Nilai</th></tr>
    <?php foreach ($barisDobel as $d): ?>
    <tr>
        <td><?= $h($d['merek'] ?: $d['company_name']) ?></td>
        <td><?= $h($d['master_code']) ?></td>
        <td><?= $h(date('d/m/y', strtotime($d['start_date'])) . ' – ' . date('d/m/y', strtotime($d['end_date']))) ?></td>
        <td class="n">#<?= $h(str_replace(',', ', #', (string) $d['ids'])) ?></td>
        <td class="n"><?= $rp($d['jumlah']) ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<div class="box warn">
    <p style="margin:0"><span class="tebal">Cara membuangnya:</span> buka <b>Pengajuan Hapus Data</b>,
    cari nomor transaksinya, centang <b>yang salah saja</b> (sisakan satu), pilih alasan
    <b>Dobel / duplikat</b>, lalu kirim. Jangan dihapus lewat menu Exhibition &mdash; di sana tidak
    ada jejak siapa menghapus apa, dan Manager tidak bisa memeriksanya.</p>
</div>
<?php endif; ?>

<?php if ($barisPutus): ?>
<h2>4. Penyewa rutin yang masa sewanya sudah lewat</h2>
<p>Penyewa berikut sudah berjalan <b>tiga siklus atau lebih</b>, tetapi baris terakhirnya
<b>sudah berakhir sebelum hari ini</b> dan belum ada perpanjangannya. Kalau kontraknya memang
masih jalan, perpanjangannya belum masuk &mdash; dan selama belum masuk, income-nya tidak akan
muncul di CLARA.</p>
<table>
    <tr><th>Penyewa</th><th>Unit terakhir</th><th>Modul</th><th>Berakhir</th><th class="n">Nilai siklus terakhir</th></tr>
    <?php foreach (array_slice($barisPutus, 0, 25) as $b): ?>
    <tr>
        <td><?= $h($b['merek']) ?></td>
        <td><?= $h($b['master_code']) ?></td>
        <td><?= $h($b['module']) ?></td>
        <td><?= $h(date('d/m/Y', strtotime($b['akhir']))) ?></td>
        <td class="n"><?= $rp($b['nilai']) ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<?php if (count($barisPutus) > 25): ?>
<p class="muted" style="margin-top:-4px"><?= count($barisPutus) - 25 ?> penyewa lain tidak dicetak di sini.</p>
<?php endif; ?>
<div class="box warn">
    <p style="margin:0">Periksa satu per satu: kalau penyewanya <b>sudah keluar</b>, biarkan &mdash;
    memang tidak ada income lagi. Kalau <b>masih jalan</b>, input perpanjangannya sekarang juga.</p>
</div>
<?php endif; ?>

<h2>5. Cara input dari awal sampai angkanya cocok</h2>

<h3><span class="langkah">Langkah 1</span> &nbsp;Pastikan dulu malnya benar</h3>
<p>Di kanan atas ada dua tombol: <b>e-Walk Simply FUNtastic</b> dan <b>Pentacity Shopping Venue</b>.
Yang menyala harus <b><?= $h($namaProperti) ?></b>. Salah mal = datanya masuk ke laporan mal lain
dan tidak akan pernah muncul di income Anda.</p>

<h3><span class="langkah">Langkah 2</span> &nbsp;Pilih menu sesuai jenis sewanya</h3>
<table>
    <tr><th>Jenis sewa</th><th>Menu yang dipakai</th></tr>
    <tr><td>Pameran / island / booth di area mal</td><td><b>Exhibition</b></td></tr>
    <tr><td>Titik media: banner, LED, TVC, hanging banner</td><td><b>Media</b></td></tr>
    <tr><td>Gudang / storage</td><td><b>Gudang</b></td></tr>
</table>
<p class="muted" style="margin-top:-4px">Salah menu adalah sebab klasik "income saya kurang":
sewa media yang diinput sebagai Exhibition tetap terhitung, tetapi tidak akan cocok saat
dibandingkan per kategori dengan spreadsheet.</p>

<h3><span class="langkah">Langkah 3</span> &nbsp;Isi tanggal persis seperti di kontrak</h3>
<ul>
    <li><b>Tanggal Mulai</b> dan <b>Tanggal Selesai</b> disalin apa adanya dari SKP / kontrak.</li>
    <li>Jangan dibulatkan ke awal/akhir bulan supaya "rapi". Tanggal itu yang menentukan
        bulan mana yang menerima income-nya.</li>
    <li>Perpanjangan dimulai <b>sehari setelah</b> kontrak sebelumnya berakhir. Kalau yang lama
        berakhir 9 November, yang baru mulai 10 November &mdash; bukan 9 November.
        Tanggal yang bertindih membuat satu hari terhitung dua kali.</li>
</ul>

<h3><span class="langkah">Langkah 4</span> &nbsp;Isi PIC dengan nama Anda sendiri</h3>
<p>Kolom <b>PIC</b> menentukan income itu masuk ke siapa. Kosong = tidak masuk ke siapa pun dan
tidak akan muncul di pencapaian Anda. Tulis <b><?= $h($pic) ?></b> persis seperti ini &mdash;
jangan disingkat, jangan diganti ejaannya.</p>

<h3><span class="langkah">Langkah 5</span> &nbsp;Isi nilai sewa, jangan diakali</h3>
<ul>
    <li>Isi <b>tarif</b> dan luas/jumlah slot &mdash; biarkan CLARA yang mengalikan.</li>
    <li>Isi <b>Harga Nego Final</b> hanya bila harga kesepakatannya memang berbeda dari hasil
        hitungan tarif. Kalau sama, biarkan kosong.</li>
    <li>Kalau harganya diskon, nyalakan <b>Tampilkan harga diskon</b> lalu isi
        <b>tarif setelah diskon</b> &mdash; totalnya dihitung sendiri, tidak akan ada selisih
        seribu-dua ribu rupiah.</li>
</ul>

<h3><span class="langkah">Langkah 6</span> &nbsp;Periksa hasilnya sebelum menutup halaman</h3>
<p>Buka <b>Dashboard</b>, lihat angka Anda di daftar PIC. Bandingkan dengan spreadsheet bulan itu.
Kalau sudah sama, selesai. Kalau belum, lanjut ke bagian 6.</p>

<h2>6. Cara menemukan selisihnya sendiri &mdash; satu bulan, lima menit</h2>
<p>Jangan mencari di seluruh tahun. Ambil <b>satu bulan</b> yang selisihnya paling besar, lalu:</p>
<ol>
    <li>Tulis daftar penyewa bulan itu dari <b>spreadsheet</b>, lengkap dengan nilainya.</li>
    <li>Buka <b>Exhibition / Media / Gudang</b> di CLARA, saring ke bulan yang sama,
        tulis daftarnya juga.</li>
    <li>Sandingkan kedua daftar. Tiap baris akan jatuh ke salah satu dari empat keadaan:</li>
</ol>
<table>
    <tr><th style="width:30%">Keadaan</th><th>Artinya &amp; tindakannya</th></tr>
    <tr><td><b>Ada di spreadsheet, tidak ada di CLARA</b></td>
        <td>Belum diinput. <b>Input sekarang</b> &mdash; ini penyebab tersering.</td></tr>
    <tr><td><b>Ada di CLARA, tidak ada di spreadsheet</b></td>
        <td>Biasanya kontraknya melewati pergantian bulan (Sebab 2). Periksa tanggalnya:
            kalau mulainya bulan ini, CLARA memang benar menaruhnya di sini.</td></tr>
    <tr><td><b>Ada di dua-duanya, nilainya beda</b></td>
        <td>Periksa Harga Nego Final dan tarifnya. Perbaiki yang salah.</td></tr>
    <tr><td><b>Muncul dua kali di CLARA</b></td>
        <td>Dobel. Ajukan hapus lewat <b>Pengajuan Hapus Data</b>, alasan <b>Dobel / duplikat</b>.</td></tr>
</table>

<div class="box">
    <p style="margin:0"><span class="tebal">Contoh nyata yang sudah terbukti.</span> Satu bulan
    diperiksa dengan cara di atas, selisihnya <b>Rp 30.625.000</b>. Hasilnya: dua perpanjangan
    belum diinput, satu titik media belum diinput, dan satu kontrak melewati pergantian bulan.
    Setelah keempatnya dihitung, selisihnya menjadi <b>nol rupiah</b> &mdash; bukan hampir nol,
    tetapi nol. Artinya sistemnya tidak salah hitung; yang kurang memang inputnya.</p>
</div>

<h2>7. Kebiasaan yang membuat angka selalu cocok</h2>
<ol>
    <li><b>Input di hari yang sama</b> saat kontrak disepakati. Menumpuk input sampai akhir bulan
        adalah sebab utama ada yang terlewat.</li>
    <li><b>Perpanjangan langsung diinput</b> begitu siklus lama berakhir, jangan menunggu ditagih.</li>
    <li><b>Jangan input dua kali</b> karena ragu sudah masuk atau belum &mdash; cari dulu di daftar.</li>
    <li><b>Cocokkan tiap akhir bulan</b>, bukan tiap akhir tahun. Selisih satu bulan mudah dicari;
        selisih setahun hampir mustahil.</li>
    <li><b>Yang salah diajukan hapus</b>, bukan dibiarkan. Data salah yang dibiarkan akan terbawa
        ke laporan mal dan ke komisi.</li>
</ol>

<div class="box warn">
    <p style="margin:0"><span class="tebal">Catatan penting.</span> Selama bulan berjalan, CLARA
    memang akan lebih kecil daripada spreadsheet &mdash; spreadsheet menuliskan proyeksi bulan
    depan, CLARA hanya menghitung yang sudah benar-benar masuk. Itu <b>bukan selisih</b>.
    Yang perlu dicocokkan adalah <b>bulan yang sudah lewat</b>.</p>
</div>
<?php
$html = (string) ob_get_clean();

$mpdf = clara_letterhead_mpdf();
$mpdf->SetTitle('Tutorial Input — ' . $pic);
$mpdf->WriteHTML($html);
@mkdir(dirname($opt['keluar']), 0777, true);
$mpdf->Output($opt['keluar'], \Mpdf\Output\Destination::FILE);
echo "  Tutorial dibuat: {$opt['keluar']}\n";
