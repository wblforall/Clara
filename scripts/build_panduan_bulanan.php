<?php
/**
 * Panduan perbaikan data satu bulan, satu orang — langkah demi langkah.
 *
 * Spreadsheet adalah PATOKAN. Dokumen ini tidak pernah menyimpulkan ada yang
 * salah di sana; yang dikerjakan selalu di sisi CLARA. Kalimatnya pun disusun
 * begitu: "Excel mencatat X, CLARA perlu disamakan" — bukan "Excel salah".
 *
 * Bedanya dengan daftar perbaikan yang lama:
 *
 *   1. Tiap temuan punya LANGKAH BERNOMOR sampai selesai — menu mana, kotak
 *      mana, diisi apa, lalu apa yang harus terlihat sesudah disimpan. Daftar
 *      lama berhenti di "minta Superadmin menghapus #1861", dan orang yang
 *      memegangnya masih harus menebak caranya.
 *   2. Beda NILAI pada penyewa yang sama dikenali sebagai KOREKSI, bukan
 *      "hapus lalu input ulang". Itu yang dulu membuat satu transaksi benar
 *      terbaca sebagai dua pekerjaan.
 *   3. Baris yang nilainya sama dengan transaksi yang SUDAH ADA tidak pernah
 *      disuruh diinput ulang. Dulu sebaliknya, dan hasil akhirnya melebihi
 *      spreadsheet — mengikuti panduannya justru merusak data.
 *   4. Baris PATUNGAN (satu lokasi dibagi dua/tiga orang) disebut terang-
 *      terangan beserta bagian masing-masing, supaya tidak ada yang menginput
 *      nilai penuh milik berdua.
 *   5. Tabel langkah di akhir WAJIB berhenti tepat di angka spreadsheet. Kalau
 *      tidak, dokumen menyebutnya apa adanya, bukan diam-diam dibiarkan.
 *
 * Sisi CLARA dibaca langsung dari basis data saat dicetak. Jalankan di SERVER;
 * dijalankan di salinan lama, isinya menyuruh membetulkan yang sudah betul.
 *
 * Pakai:
 *   php scripts/build_panduan_bulanan.php --banding=docs/banding_oktober/oktober.json
 *   php scripts/build_panduan_bulanan.php --banding=... --pic=Shinta
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/pdf.php';

$opt = array_merge(['banding' => '', 'pic' => '', 'keluar' => $root . '/docs'],
    getopt('', ['banding:', 'pic:', 'keluar:']));
if ($opt['banding'] === '') {
    fwrite(STDERR, "Pakai: php scripts/build_panduan_bulanan.php --banding=berkas.json [--pic=Nama]\n");
    exit(2);
}
$data = json_decode((string) file_get_contents((string) $opt['banding']), true);
if (!is_array($data) || empty($data['pic'])) {
    fwrite(STDERR, "Berkas banding tidak terbaca.\n");
    exit(2);
}
date_default_timezone_set((string) (env_value('APP_TIMEZONE') ?: 'Asia/Makassar'));

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$h  = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
$tgl = function (?string $d): string {
    $t = $d ? strtotime($d) : false;
    return $t ? date('d/m/y', $t) : '-';
};

/** Nama modul sebagaimana tertulis di menu CLARA. */
$MENU = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];

/**
 * Menu tempat sebuah baris spreadsheet diinput.
 *
 * Untuk baris yang belum ada di CLARA tidak ada modul yang bisa dibaca, jadi
 * ditebak dari keterangannya. Tebakan yang salah membuat orang membuka menu
 * yang keliru dan menyimpulkan panduannya ngawur — karena itu tebakannya
 * disertai kata "biasanya" di dokumen, bukan disajikan sebagai kepastian.
 */
$tebakMenu = static function (string $ket): string {
    $k = mb_strtoupper($ket, 'UTF-8');
    foreach (['GUDANG', 'GUDA-', 'WAREHOUSE'] as $g) if (str_contains($k, $g)) return 'Gudang';
    foreach (['BANNER', 'LED', 'VIDEOTRON', 'SIGNAGE', 'BRANDING', 'MEDIA',
              'BILLBOARD', 'NEON', 'BACKDROP'] as $m) if (str_contains($k, $m)) return 'Media';
    return 'Exhibition';
};

/** Rincian CLARA satu PIC satu bulan. */
$qCl = $pdo->prepare(
    "SELECT a.amount, t.id trx, t.module, t.master_code, t.start_date, t.end_date,
            COALESCE(NULLIF(t.final_amount,0), t.total_calculated) nilai_kontrak,
            COALESCE(c.brand_name, c.company_name, '(tanpa nama client)') nama
       FROM transaction_allocations a
       JOIN transactions t ON t.id = a.transaction_id
       LEFT JOIN master_clients c ON c.id = t.client_id
      WHERE a.property_id = ? AND a.pic_name = ? AND a.period_key = ?
        AND a.amount > 0 AND t.deleted_at IS NULL
      ORDER BY a.amount DESC"
);

/** Kata penting sebuah nama — yang pendek tidak membedakan apa pun. */
function _kata(string $t): array
{
    $n = trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($t, 'UTF-8')));
    return array_values(array_filter(explode(' ', $n), fn($k) => mb_strlen($k) >= 4));
}

/**
 * Seberapa mirip dua nama, 0..1. Nol berarti "jangan dipasangkan".
 *
 * Ejaan di dua sumber jarang sama persis ("Pentol Chilok" vs "Pentol Cilok"),
 * jadi toleransi ejaan memang perlu. Tetapi toleransi dua huruf untuk kata
 * pendek menyatukan hal yang sama sekali berbeda: "stall" dan "sally" hanya
 * beda dua huruf, sehingga "9/FOOD STALL LG | CHI FRY" terbaca sepasang dengan
 * penyewa "Sour Sally". Panduan lalu menyuruh orang mengubah nilai transaksi
 * yang sudah benar — kerusakan yang lebih buruk daripada selisih yang
 * dilaporkan apa adanya.
 *
 * Karena itu: toleransi ejaan hanya untuk kata PANJANG (>=7 huruf, di situ
 * beda dua huruf memang hampir pasti salah ketik), dan pasangan baru diakui
 * bila ada setidaknya satu kecocokan KUAT — persis, atau fuzzy pada kata
 * panjang. Kecocokan lemah saja tidak cukup.
 */
function _mirip(string $a, string $b): float
{
    $ka = _kata($a); $kb = _kata($b);
    if (!$ka || !$kb) return 0.0;
    $n = 0; $kuat = false;
    foreach ($ka as $x) {
        foreach ($kb as $y) {
            if ($x === $y) { $n++; $kuat = true; break; }
            if (mb_strlen($x) >= 7 && mb_strlen($y) >= 7 && levenshtein($x, $y) <= 2) {
                $n++; $kuat = true; break;
            }
        }
    }
    if (!$kuat) return 0.0;
    return $n / min(count($ka), count($kb));
}

/**
 * Sandingkan baris spreadsheet dengan baris CLARA.
 *
 * Urutannya menentukan mutu panduannya:
 *   1. NILAI sama + nama mirip  -> sudah cocok, tidak ada pekerjaan.
 *   2. NAMA mirip, nilai beda   -> KOREKSI nilai. Inilah yang dulu terbaca
 *      sebagai "hapus satu, input satu" — dua pekerjaan untuk satu kesalahan.
 *   3. NILAI sama, nama tak mirip -> sudah ada, cukup DIPASTIKAN. Tidak boleh
 *      masuk daftar input: itu sumber "disuruh input padahal sudah ada".
 *   4. Sisa spreadsheet -> INPUT. Sisa CLARA -> HAPUS.
 */
function sandingkan(array $ss, array $cl): array
{
    $cocok = $koreksi = $pastikan = [];
    $sisaSs = $ss; $sisaCl = $cl;

    $ambil = function (callable $uji) use (&$sisaSs, &$sisaCl): array {
        $hasil = [];
        foreach ($sisaSs as $j => $s) {
            $pilih = null; $skor = -1.0;
            foreach ($sisaCl as $i => $c) {
                $nilai = $uji($s, $c);
                if ($nilai !== null && $nilai > $skor) { $skor = $nilai; $pilih = $i; }
            }
            if ($pilih === null) continue;
            $hasil[] = [$s, $sisaCl[$pilih]];
            unset($sisaCl[$pilih], $sisaSs[$j]);
        }
        return $hasil;
    };

    $cocok = $ambil(fn($s, $c) => round((float) $c['amount']) === round((float) $s['nilai'])
        && _mirip((string) $s['ket'], (string) $c['nama']) >= 0.5
            ? _mirip((string) $s['ket'], (string) $c['nama']) : null);

    $koreksi = $ambil(fn($s, $c) => _mirip((string) $s['ket'], (string) $c['nama']) >= 0.5
        ? _mirip((string) $s['ket'], (string) $c['nama']) : null);

    $pastikan = $ambil(fn($s, $c) => round((float) $c['amount']) === round((float) $s['nilai']) ? 0.0 : null);

    return [$cocok, $koreksi, $pastikan, array_values($sisaSs), array_values($sisaCl)];
}

$CSS = '<style>
 body { font-family: sans-serif; font-size: 9.5pt; color:#1F2937; line-height:1.5; }
 h1 { font-size:17pt; margin:0 0 1mm; }
 h2 { font-size:11.5pt; color:#0A7267; margin:7mm 0 2mm; padding-bottom:1.2mm;
      border-bottom:1pt solid #0D9488; page-break-after:avoid; }
 p { margin:0 0 2.4mm; }
 .sub { color:#6B7280; font-size:8.8pt; margin-bottom:4mm; }
 table { width:100%; border-collapse:collapse; margin-bottom:3mm; }
 tr { page-break-inside:avoid; }
 th,td { border:0.4pt solid #D1D5DB; padding:1.5mm 2mm; vertical-align:top; font-size:8.6pt; }
 th { background:#F0FDF4; color:#0A7267; font-size:7.8pt; text-transform:uppercase; letter-spacing:.03em; }
 td.n, th.n { text-align:right; white-space:nowrap; }
 .muted { color:#6B7280; }
 .item { page-break-inside:avoid; border:0.6pt solid #D1D5DB; border-left:3pt solid #0D9488;
         background:#F8FAFC; padding:3mm 3.4mm; margin-bottom:3.5mm; }
 .item.hapus  { border-left-color:#B91C1C; background:#FEF2F2; }
 .item.input  { border-left-color:#047857; background:#F0FDF4; }
 .item.koreksi{ border-left-color:#B45309; background:#FFFBEB; }
 .item.cek    { border-left-color:#4338CA; background:#EEF2FF; }
 .judul { font-weight:bold; font-size:10pt; margin-bottom:2mm; }
 .tag { font-size:7.4pt; font-weight:bold; letter-spacing:.05em; padding:0.5mm 1.6mm;
        border-radius:1mm; color:#fff; }
 .kv { width:100%; border-collapse:collapse; margin:0 0 2mm; }
 .kv td { border:none; padding:0.6mm 0; font-size:8.6pt; vertical-align:top; }
 .kv td.k { width:26mm; color:#6B7280; }
 ol { margin:0 0 0 5mm; padding:0; } li { margin-bottom:1.2mm; font-size:8.7pt; }
 .dampak { font-weight:bold; font-size:8.6pt; margin-top:1.5mm; }
 .ok { color:#047857; } .kurang { color:#B91C1C; }
</style>';

$dibuat = date('d/m/Y H:i');
$hasilAkhir = [];

foreach ($data['pic'] as $kunci => $d) {
    if ($opt['pic'] !== '' && mb_strtolower((string) $d['pic']) !== mb_strtolower((string) $opt['pic'])) continue;
    $pic = (string) $d['pic'];
    $pid = (int) $d['properti'];
    $per = (string) $d['periode'];
    $namaProp = (string) ($pdo->query('SELECT name FROM properties WHERE id = ' . $pid)->fetchColumn() ?: '-');

    $qCl->execute([$pid, $pic, $per]);
    $clRows = $qCl->fetchAll();
    $clTotal = array_sum(array_map(fn($r) => (float) $r['amount'], $clRows));
    $ssTotal = (float) $d['total'];

    [$cocok, $koreksi, $pastikan, $inputBaru, $lebih] = sandingkan($d['rincian'], $clRows);

    // Nomor urut pekerjaan berjalan lintas golongan, supaya orang bisa bilang
    // "nomor 4 sudah" tanpa harus menyebut golongannya.
    $no = 0; $kerja = [];
    foreach ($inputBaru as $s) $kerja[] = ['jenis' => 'input', 'ss' => $s, 'cl' => null,
        'nilai' => (float) $s['nilai']];
    foreach ($koreksi as [$s, $c]) {
        if (round((float) $c['amount']) === round((float) $s['nilai'])) continue;
        $kerja[] = ['jenis' => 'koreksi', 'ss' => $s, 'cl' => $c,
            'nilai' => (float) $s['nilai'] - (float) $c['amount']];
    }
    foreach ($lebih as $c) $kerja[] = ['jenis' => 'hapus', 'ss' => null, 'cl' => $c,
        'nilai' => -(float) $c['amount']];

    usort($kerja, fn($a, $b) => abs($b['nilai']) <=> abs($a['nilai']));

    $hasil = $clTotal + array_sum(array_column($kerja, 'nilai'));
    $selisihAkhir = $hasil - $ssTotal;

    ob_start();
    ?>
    <h1>Panduan Perbaikan Data — <?= $h($pic) ?></h1>
    <div class="sub"><?= $h($namaProp) ?> &middot; <?= $h($d['bulan']) ?> <?= $h(substr($per, 0, 4)) ?>
        &middot; dibuat <?= $h($dibuat) ?><br>
        Patokan: <?= $h($data['sumber']) ?>. Kolom CLARA dibaca langsung dari basis data saat dokumen ini dibuat.
        Seluruh langkah dikerjakan di CLARA; spreadsheet tidak perlu diubah.</div>

    <h2>Keadaan <?= $h($d['bulan']) ?></h2>
    <table>
        <tr><th>&nbsp;</th><th class="n">Nilai</th><th>Keterangan</th></tr>
        <tr><td>Spreadsheet <?= $h($pic) ?></td><td class="n"><?= $rp($ssTotal) ?></td>
            <td class="muted"><?= count($d['rincian']) ?> baris</td></tr>
        <tr><td>CLARA sekarang</td><td class="n"><?= $rp($clTotal) ?></td>
            <td class="muted"><?= count($clRows) ?> transaksi bernilai</td></tr>
        <tr style="background:#F8FAFC;font-weight:bold">
            <td>Selisih</td>
            <td class="n <?= $clTotal < $ssTotal ? 'kurang' : '' ?>">
                <?= ($clTotal - $ssTotal >= 0 ? '+' : '&minus;') . number_format(abs($clTotal - $ssTotal), 0, ',', '.') ?></td>
            <td><?= $clTotal == $ssTotal ? 'sudah sama' : ($clTotal < $ssTotal ? 'CLARA masih kurang' : 'CLARA kelebihan') ?></td></tr>
    </table>
    <p><?= count($cocok) ?> baris sudah cocok nilai dan namanya &mdash; tidak perlu disentuh.
       <?= count($pastikan) ?> baris nilainya sudah sama tetapi namanya tertulis berbeda; itu cukup dipastikan,
       <b>bukan diinput ulang</b>. Yang perlu dikerjakan: <b><?= count($kerja) ?></b> hal.</p>

    <?php if (!$kerja): ?>
    <div class="item input"><div class="judul">Tidak ada pekerjaan</div>
        Seluruh baris <?= $h($d['bulan']) ?> milik <?= $h($pic) ?> sudah sama dengan spreadsheet.</div>
    <?php endif; ?>

    <?php if ($kerja): ?>
    <h2>Yang perlu dikerjakan (<?= count($kerja) ?>)</h2>
    <?php foreach ($kerja as $k): $no++;
        $s = $k['ss']; $c = $k['cl'];
        $menu = $c ? ($MENU[$c['module']] ?? 'Exhibition') : $tebakMenu((string) ($s['ket'] ?? ''));
        $warna = ['input' => '#047857', 'hapus' => '#B91C1C', 'koreksi' => '#B45309'][$k['jenis']];
        $tag   = ['input' => 'INPUT BARU', 'hapus' => 'HAPUS', 'koreksi' => 'KOREKSI NILAI'][$k['jenis']];
    ?>
    <div class="item <?= $k['jenis'] ?>">
        <div class="judul"><?= $no ?>. <?= $h($s['ket'] ?? ($c['nama'] . ' — ' . $c['master_code'])) ?>
            <span class="tag" style="background:<?= $warna ?>"><?= $tag ?></span></div>
        <table class="kv">
            <?php if ($s): ?>
            <tr><td class="k">Di spreadsheet</td><td>
                <?= $h($s['sheet']) ?> baris <?= (int) $s['baris'] ?> &mdash;
                <b><?= $h($s['ket']) ?></b>, <?= $rp($s['nilai']) ?>
                <?php if (!empty($s['patungan'])): ?><br>
                <span class="muted">Baris ini <b>patungan</b> dengan
                <?= $h(implode(', ', array_diff($s['pemilik'], [$pic]))) ?>.
                <b>Bagian Anda <?= $rp($s['nilai']) ?></b> &mdash; yang diinput hanya bagian Anda,
                bagian rekan Anda ada di panduannya sendiri.</span><?php endif; ?>
            </td></tr>
            <?php endif; ?>
            <tr><td class="k">Di CLARA</td><td>
                <?php if ($c): ?>
                Transaksi <b>#<?= (int) $c['trx'] ?></b> &middot; <?= $h($c['master_code']) ?> &middot;
                <?= $h($tgl($c['start_date'])) ?>&ndash;<?= $h($tgl($c['end_date'])) ?> &middot;
                <?= $h($c['nama']) ?> &middot; diakui <?= $rp($c['amount']) ?> di bulan ini
                <?php else: ?><span class="muted">belum ada</span><?php endif; ?>
            </td></tr>
        </table>
        <div style="font-size:8.4pt;color:#6B7280;margin-bottom:1mm">Langkah:</div>
        <ol>
        <?php if ($k['jenis'] === 'input'): ?>
            <li>Buka menu <b><?= $h($menu) ?></b> &rarr; <b>+ Input Transaksi</b>.
                <span class="muted">(dari keterangannya, baris ini biasanya masuk
                <?= $h($menu) ?>; kalau ternyata bukan, pakai menu yang sesuai)</span></li>
            <li>Client: ketik nama penyewanya, lalu <b>pilih dari daftar</b> yang muncul
                &mdash; kalau hanya diketik tanpa dipilih, transaksinya tersimpan tanpa client.</li>
            <li>Unit / Lokasi: <b><?= $h($s['unit'] ?: $s['ket']) ?></b>.</li>
            <li>Tanggal Mulai &amp; Selesai: isi sesuai masa sewanya di bulan
                <b><?= $h($d['bulan']) ?></b>.</li>
            <li>Nilai kontrak: <b><?= $rp($s['nilai']) ?></b><?= !empty($s['patungan'])
                ? ' &mdash; bagian Anda saja' : '' ?>.</li>
            <li>PIC: <b><?= $h($pic) ?></b>.</li>
            <li>Simpan, lalu buka <b>Alokasi</b> transaksinya dan pastikan bulan
                <b><?= $h($per) ?></b> berisi <b><?= $rp($s['nilai']) ?></b>.</li>
        <?php elseif ($k['jenis'] === 'koreksi'): ?>
            <li>Buka menu <b><?= $h($menu) ?></b>, cari transaksi <b>#<?= (int) $c['trx'] ?></b>
                (<?= $h($c['master_code']) ?>, <?= $h($c['nama']) ?>).</li>
            <li>Tekan <b>Edit</b>.</li>
            <li>Ubah nilainya dari <b><?= $rp($c['amount']) ?></b> menjadi
                <b><?= $rp($s['nilai']) ?></b>.</li>
            <li>Periksa juga tanggalnya: spreadsheet mencatat baris ini di
                <b><?= $h($d['bulan']) ?></b>. Kalau tanggalnya membuat nilainya jatuh ke bulan
                lain, perbaiki tanggalnya juga.</li>
            <li>Simpan, lalu pastikan di <b>Alokasi</b> bulan <b><?= $h($per) ?></b>
                tertulis <b><?= $rp($s['nilai']) ?></b>.</li>
        <?php else: ?>
            <li>Buka menu <b><?= $h($menu) ?></b>, cari transaksi <b>#<?= (int) $c['trx'] ?></b>
                (<?= $h($c['master_code']) ?>, <?= $h($c['nama']) ?>,
                <?= $h($tgl($c['start_date'])) ?>&ndash;<?= $h($tgl($c['end_date'])) ?>).</li>
            <li><b>Sebelum diajukan hapus:</b> pastikan betul baris ini memang tidak ada
                padanannya di spreadsheet <?= $h($d['bulan']) ?> milik Anda. Kalau ternyata ada
                tetapi tertulis dengan nama lain, jangan dihapus &mdash; cukup cocokkan namanya.</li>
            <li>Kalau memang tidak ada: buka <b>Pengajuan Hapus Data</b> &rarr;
                <b>+ Ajukan Hapus</b>, pilih transaksi <b>#<?= (int) $c['trx'] ?></b>.</li>
            <li>Tulis alasannya singkat dan jelas, mis. &ldquo;tidak ada di spreadsheet
                <?= $h($d['bulan']) ?>, dobel dengan transaksi lain&rdquo;.</li>
            <li>Kirim. Penghapusan dikerjakan oleh penyetuju hapus &mdash; Anda tidak bisa
                dan tidak perlu menghapusnya sendiri.</li>
        <?php endif; ?>
        </ol>
        <div class="dampak">Dampak ke <?= $h($d['bulan']) ?>:
            <?= $k['nilai'] >= 0 ? '+' : '&minus;' ?><?= number_format(abs($k['nilai']), 0, ',', '.') ?></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($pastikan): ?>
    <h2>Cukup dipastikan &mdash; jangan diinput ulang</h2>
    <p>Nilainya sudah sama dengan transaksi yang ada di CLARA, hanya namanya tertulis berbeda.
       Spreadsheet kadang menulis lokasinya, CLARA menulis nama penyewanya. Kalau dua kolom di
       bawah ini orang yang sama, <b>tidak ada yang perlu dikerjakan</b>.</p>
    <table>
        <tr><th>Di spreadsheet</th><th>Di CLARA</th><th class="n">Nilai</th></tr>
        <?php foreach ($pastikan as [$s, $c]): ?>
        <tr><td><?= $h($s['ket']) ?> <span class="muted">(baris <?= (int) $s['baris'] ?>)</span></td>
            <td><?= $h($c['nama']) ?> &middot; <?= $h($c['master_code']) ?>
                <span class="muted">#<?= (int) $c['trx'] ?></span></td>
            <td class="n"><?= $rp($s['nilai']) ?></td></tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <h2>Kalau seluruh langkah di atas dikerjakan</h2>
    <table>
        <tr><th style="width:62%">Langkah</th><th class="n">Nilai</th><th class="n">Jadi</th></tr>
        <tr><td>CLARA sekarang</td><td class="n">&mdash;</td><td class="n"><?= $rp($clTotal) ?></td></tr>
        <?php $jalan = $clTotal; $i = 0; foreach ($kerja as $k): $i++; $jalan += $k['nilai'];
            $lbl = ['input' => 'Input', 'koreksi' => 'Koreksi', 'hapus' => 'Hapus'][$k['jenis']];
            $nm  = $k['ss']['ket'] ?? ($k['cl']['nama'] . ' #' . $k['cl']['trx']); ?>
        <tr><td><?= $i ?>. <?= $lbl ?>: <?= $h(mb_substr((string) $nm, 0, 58)) ?></td>
            <td class="n"><?= $k['nilai'] >= 0 ? '+' : '&minus;' ?><?= number_format(abs($k['nilai']), 0, ',', '.') ?></td>
            <td class="n"><?= $rp($jalan) ?></td></tr>
        <?php endforeach; ?>
        <tr style="background:#ECFDF5;font-weight:bold">
            <td>HASIL</td><td class="n">&mdash;</td><td class="n"><?= $rp($hasil) ?></td></tr>
        <tr style="font-weight:bold">
            <td>Spreadsheet <?= $h($pic) ?> <?= $h($d['bulan']) ?></td>
            <td class="n">&mdash;</td><td class="n"><?= $rp($ssTotal) ?></td></tr>
    </table>
    <?php if (abs($selisihAkhir) < 1): ?>
    <p class="ok"><b>Cocok.</b> Sesudah <?= count($kerja) ?> langkah di atas dikerjakan, angka
       <?= $h($d['bulan']) ?> Anda di CLARA sama persis dengan spreadsheet.</p>
    <?php else: ?>
    <p class="kurang"><b>Masih bersisa <?= $rp(abs($selisihAkhir)) ?>.</b>
       Sisa ini belum bisa dijelaskan oleh langkah mana pun di atas &mdash; disebut di sini
       apa adanya, bukan dibiarkan. Kirimkan dokumen ini supaya sisanya ditelusuri bersama.</p>
    <?php endif; ?>
    <?php
    $isi = (string) ob_get_clean();
    $berkas = rtrim((string) $opt['keluar'], '/') . '/Panduan_' . ucfirst(mb_strtolower($d['bulan']))
            . '_' . preg_replace('/[^A-Za-z0-9]+/', '_', $pic) . '.pdf';
    // clara_letterhead_mpdf() mengembalikan objek mPDF berkop surat, bukan
    // menyimpan berkas — penyimpanannya dilakukan di sini.
    $mpdf = clara_letterhead_mpdf();
    $mpdf->SetTitle('Panduan Perbaikan ' . $d['bulan'] . ' — ' . $pic);
    $mpdf->WriteHTML($CSS . $isi);
    @mkdir(dirname($berkas), 0777, true);
    $mpdf->Output($berkas, \Mpdf\Output\Destination::FILE);
    $hasilAkhir[] = [$pic, $namaProp, $clTotal, $ssTotal, count($kerja), $selisihAkhir, $berkas];
}

printf("\nPANDUAN %s\n%s\n", (string) $data['bulan'], str_repeat('=', 92));
printf("  %-10s %-26s %-16s %-16s %5s  %s\n", 'PIC', 'PROPERTI', 'CLARA', 'SPREADSHEET', 'KERJA', 'SESUDAH DIKERJAKAN');
foreach ($hasilAkhir as [$pic, $prop, $cl, $ss, $n, $sisa, $f]) {
    printf("  %-10s %-26s %-16s %-16s %5d  %s\n", $pic, mb_substr($prop, 0, 26),
        number_format($cl, 0, ',', '.'), number_format($ss, 0, ',', '.'), $n,
        abs($sisa) < 1 ? 'sama dengan spreadsheet' : 'masih bersisa ' . number_format(abs($sisa), 0, ',', '.'));
}
printf("\n  %d dokumen ditulis di %s\n\n", count($hasilAkhir), rtrim((string) $opt['keluar'], '/'));
