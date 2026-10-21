<?php
/**
 * Daftar perbaikan data satu PIC — hasil sandingan CLARA vs spreadsheet.
 *
 * Bedanya dengan tutorial: dokumen ini tidak mengajari cara input, melainkan
 * menyebut SATU PER SATU baris mana yang harus ditambah dan mana yang harus
 * dibuang, berikut nilainya. Yang dipegang orang saat memperbaiki data adalah
 * daftar seperti ini, bukan penjelasan umum.
 *
 * Sisi CLARA dibaca langsung dari database yang sedang dipakai. Jalankan di
 * SERVER supaya angkanya angka hari ini — dijalankan di salinan lokal yang
 * sudah ketinggalan, daftarnya akan menyebut hal yang sudah terlanjur dibetulkan.
 *
 * Sisi spreadsheet dibaca dari berkas JSON hasil ekspor, bentuknya:
 *   {"sumber":"...","pic":"SINTA",
 *    "bulan":   {"2026-10": 161616008, ...},
 *    "rincian": {"2026-10": [{"ket":"CIRCLE 5 | CHIKURO","nilai":28125000}, ...]}}
 *
 * Pakai:
 *   php scripts/build_perbaikan_pic.php --pic="Shinta" --prop=2 \
 *       --banding=/path/shinta.json --bulan=2026-10,2026-11
 */

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/pdf.php';

$opt = array_merge(['pic' => '', 'prop' => '2', 'banding' => '', 'bulan' => '', 'keluar' => '', 'gambar' => ''],
    getopt('', ['pic:', 'prop:', 'banding:', 'bulan:', 'keluar:', 'gambar:']));
if ($opt['pic'] === '' || $opt['banding'] === '') {
    fwrite(STDERR, "Pakai: php scripts/build_perbaikan_pic.php --pic=\"Nama\" --banding=berkas.json [--prop=2] [--bulan=2026-10,2026-11]\n");
    exit(2);
}
$pic = (string) $opt['pic'];
$pid = (int) $opt['prop'];
$ss  = json_decode((string) file_get_contents((string) $opt['banding']), true);
if (!is_array($ss) || empty($ss['bulan'])) {
    fwrite(STDERR, "Berkas banding tidak terbaca atau tidak memuat kunci 'bulan'.\n");
    exit(2);
}
if ($opt['keluar'] === '') {
    $opt['keluar'] = $root . '/docs/Perbaikan_Data_' . preg_replace('/[^A-Za-z0-9]+/', '_', $pic) . '.pdf';
}
$fokus = $opt['bulan'] !== ''
    ? array_values(array_filter(array_map('trim', explode(',', (string) $opt['bulan']))))
    : [date('Y-m')];

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$h  = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

$namaProperti = (string) ($pdo->query('SELECT name FROM properties WHERE id = ' . $pid)->fetchColumn() ?: '-');

/** Income CLARA per bulan. */
$qBulan = $pdo->prepare(
    'SELECT COALESCE(SUM(amount), 0) FROM transaction_allocations
      WHERE property_id = ? AND pic_name = ? AND period_key = ?'
);
/** Rincian CLARA satu bulan — hanya baris yang benar-benar bernilai. */
$qRinci = $pdo->prepare(
    "SELECT a.amount, t.id trx, t.module, t.master_code, t.start_date, t.end_date,
            COALESCE(c.brand_name, c.company_name, '(tanpa nama)') nama
       FROM transaction_allocations a
       JOIN transactions t ON t.id = a.transaction_id
       LEFT JOIN master_clients c ON c.id = t.client_id
      WHERE a.property_id = ? AND a.pic_name = ? AND a.period_key = ?
        AND a.amount > 0 AND t.deleted_at IS NULL
      ORDER BY a.amount DESC"
);

/**
 * Nilai yang menyimpang dari siklus-siklus sebelumnya.
 *
 * Penyewa rutin nilainya nyaris selalu sama tiap siklus. Begitu satu siklus
 * nilainya jauh berbeda, hampir pasti salah ketik — yang paling sering, TARIF
 * yang masuk ke kolom nilai (mis. 165.000 pada kontrak yang semestinya
 * 6.000.000). Kesalahan seperti ini tidak pernah ketahuan dari totalnya, dan
 * baru muncul saat seseorang membandingkannya dengan spreadsheet.
 */
$qSiklus = $pdo->prepare(
    "SELECT t.id, t.master_code, t.module, t.start_date, t.end_date,
            COALESCE(NULLIF(t.final_amount,0), t.total_calculated) nilai,
            COALESCE(c.brand_name, c.company_name, '(tanpa nama)') nama,
            CONCAT(COALESCE(t.client_id,0), '|', COALESCE(t.master_code,''), '|', t.module) grup
       FROM transactions t
       LEFT JOIN master_clients c ON c.id = t.client_id
      WHERE t.property_id = ? AND t.deleted_at IS NULL AND t.pic_name = ?
        AND YEAR(t.start_date) = ?
      ORDER BY t.start_date"
);
$qSiklus->execute([$pid, $pic, (int) substr((string) array_key_first($ss['bulan']), 0, 4)]);
$grup = [];
foreach ($qSiklus->fetchAll() as $r) $grup[$r['grup']][] = $r;

$menyimpang = [];
foreach ($grup as $rows) {
    if (count($rows) < 3) continue;          // dua siklus belum cukup jadi patokan
    $hitung = [];
    foreach ($rows as $r) $hitung[(string) round((float) $r['nilai'])] = ($hitung[(string) round((float) $r['nilai'])] ?? 0) + 1;
    arsort($hitung);
    $lazim = (float) array_key_first($hitung);
    if ($lazim <= 0 || reset($hitung) < 2) continue;   // tidak ada nilai yang benar-benar lazim
    foreach ($rows as $r) {
        $n = (float) $r['nilai'];
        // Hanya yang LEBIH KECIL dan hanya muncul SEKALI. Harga yang naik
        // adalah nego baru, bukan salah ketik; dan nilai yang berulang beberapa
        // siklus sudah jadi harga normalnya, bukan penyimpangan. Tanpa dua
        // saringan ini daftarnya penuh penyewa yang harganya memang berubah,
        // dan yang benar-benar salah tenggelam di antaranya.
        if ($n <= 0 || $n >= $lazim) continue;
        if (($lazim - $n) / $lazim <= 0.2) continue;
        if (($hitung[(string) round($n)] ?? 0) > 1) continue;
        $menyimpang[] = $r + ['lazim' => $lazim, 'selisih' => $lazim - $n];
    }
}
usort($menyimpang, fn($a, $b) => $b['selisih'] <=> $a['selisih']);

// Hanya bulan yang diminta. Menyertakan bulan lain membuat orang mengira
// semuanya harus dikerjakan sekaligus, lalu tidak jadi mengerjakan apa pun.
$bulanSS = array_intersect_key($ss['bulan'], array_flip($fokus));
if (!$bulanSS) $bulanSS = $ss['bulan'];
ksort($bulanSS);
$totSS = 0.0; $totCL = 0.0; $tabel = [];
foreach ($bulanSS as $k => $v) {
    $qBulan->execute([$pid, $pic, $k]);
    $cl = (float) $qBulan->fetchColumn();
    $tabel[] = ['bulan' => $k, 'ss' => (float) $v, 'cl' => $cl, 'selisih' => $cl - (float) $v];
    $totSS += (float) $v; $totCL += $cl;
}

/**
 * Sandingkan dua daftar berdasarkan NILAI, bukan nama.
 *
 * Nama di spreadsheet dan di CLARA hampir tidak pernah sama persis ejaannya
 * ("Pentol Chilok" vs "Pentol Cilok"), jadi mencocokkan nama akan melaporkan
 * selisih palsu. Nilainya yang dicocokkan — itu yang menentukan angkanya.
 */
function _norm(string $t): string
{
    return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($t, 'UTF-8')) ?? '');
}

/** Kata penting sebuah nama — yang pendek dibuang karena tidak membedakan apa pun. */
function _kata(string $t): array
{
    return array_values(array_filter(explode(' ', _norm($t)), fn($k) => mb_strlen($k) >= 4));
}

/**
 * Berapa kata penting yang sama antara dua nama.
 *
 * Kata dianggap sama bila persis ATAU beda paling banyak dua huruf. Ejaan nama
 * penyewa berbeda-beda antar berkas — "luca"/"luuca", "chilok"/"cilok" — dan
 * kalau dituntut sama persis, satu penyewa akan terbaca sebagai dua orang:
 * yang satu dilaporkan hilang, yang satu dilaporkan berlebih.
 */
function _mirip(string $a, string $b): int
{
    $ka = _kata($a); $kb = _kata($b); $n = 0;
    foreach ($ka as $x) {
        foreach ($kb as $y) {
            if ($x === $y || levenshtein($x, $y) <= 2) { $n++; break; }
        }
    }
    return $n;
}

/**
 * Sandingkan daftar spreadsheet dengan daftar CLARA.
 *
 * Dua tahap, dan urutannya penting:
 *   1. Pasangkan yang NILAINYA sama DAN namanya masih mirip. Ejaan di dua
 *      sumber jarang sama persis ("Pentol Chilok" vs "Pentol Cilok"), jadi yang
 *      dibandingkan kata-kata pentingnya, bukan huruf per huruf.
 *   2. Sisanya dipasangkan berdasarkan nilai saja.
 *
 * Tanpa tahap pertama, dua penyewa yang kebetulan bernilai sama bisa tertukar —
 * dan daftar perbaikan lalu menyuruh orang menginput sesuatu yang sudah ada,
 * sambil mendiamkan yang benar-benar hilang.
 */
function sandingkan(array $ssRows, array $clRows): array
{
    $sisaCl = $clRows; $sisaSs = $ssRows;
    $pasti = $ragu = [];

    // Tahap 1 — nilai sama DAN nama masih mirip. Ini pasangan yang yakin.
    foreach ($sisaSs as $j => $s) {
        $pilih = null; $skorTerbaik = 0;
        foreach ($sisaCl as $i => $c) {
            if (round((float) $c['amount']) !== round((float) $s['nilai'])) continue;
            $skor = _mirip((string) $s['ket'], (string) $c['nama']);
            if ($skor > $skorTerbaik) { $skorTerbaik = $skor; $pilih = $i; }
        }
        if ($pilih === null) continue;
        $pasti[] = [$s, $sisaCl[$pilih]];
        unset($sisaCl[$pilih], $sisaSs[$j]);
    }

    // Tahap 2 — nilainya sama tetapi namanya tidak mirip. TIDAK dianggap cocok
    // dan TIDAK dianggap hilang: spreadsheet kerap menamai baris dengan lokasi
    // ("Ralling 1 SF") sementara CLARA memakai nama penyewa, jadi nama yang
    // berbeda belum tentu baris yang berbeda. Ditaruh di golongan sendiri
    // supaya orangnya yang memutuskan — bukan ditebak oleh skrip ini.
    foreach ($sisaSs as $j => $s) {
        foreach ($sisaCl as $i => $c) {
            if (round((float) $c['amount']) !== round((float) $s['nilai'])) continue;
            $ragu[] = [$s, $c];
            unset($sisaCl[$i], $sisaSs[$j]);
            break;
        }
    }
    return [$pasti, $ragu, array_values($sisaSs), array_values($sisaCl)];
}

ob_start(); ?>
<style>
    body { font-family: sans-serif; font-size: 10.5pt; color: #111; line-height: 1.5; }
    h1 { font-size: 16pt; margin: 0 0 2px; }
    h2 { font-size: 12.5pt; margin: 14px 0 6px; color: #0f766e; border-bottom: 1px solid #99f6e4; padding-bottom: 3px; }
    h3 { font-size: 11pt; margin: 10px 0 4px; }
    .sub { color: #555; font-size: 9.5pt; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; margin: 5px 0 9px; font-size: 9.5pt; }
    th, td { border: 1px solid #cbd5e1; padding: 4px 7px; text-align: left; vertical-align: top; }
    th { background: #f1f5f9; }
    td.n { text-align: right; white-space: nowrap; }
    .box { border: 1px solid #cbd5e1; border-left: 3px solid #0D9488; background: #f8fafc; padding: 8px 11px; margin: 7px 0; }
    .warn { border-left-color: #b45309; background: #fffbeb; }
    .bad  { border-left-color: #b91c1c; background: #fef2f2; }
    .muted { color: #666; } .tebal { font-weight: 700; }
    .merah { color: #b91c1c; } .hijau { color: #065f46; }
    tr.tambah td { background: #fef2f2; }
    tr.buang  td { background: #fffbeb; }
    ol, ul { margin: 4px 0 8px 17px; padding: 0; } li { margin-bottom: 3px; }
</style>

<h1>Daftar Perbaikan Data</h1>
<div class="sub"><b><?= $h($pic) ?></b> &middot; <?= $h($namaProperti) ?> &middot; dibuat <?= date('d/m/Y H:i') ?><br>
    Kolom CLARA dibaca langsung dari database saat dicetak.
    Pembanding: <?= $h($ss['sumber'] ?? 'spreadsheet') ?>.</div>

<h2>1. Selisih <?= count($bulanSS) === 1 ? h((string) array_key_first($bulanSS)) : 'per bulan' ?></h2>
<table>
    <tr><th>Bulan</th><th class="n">Spreadsheet</th><th class="n">CLARA</th><th class="n">Selisih</th><th>Artinya</th></tr>
    <?php foreach ($tabel as $t): ?>
    <tr<?= in_array($t['bulan'], $fokus, true) ? ' style="font-weight:700;background:#ecfdf5"' : '' ?>>
        <td><?= $h($t['bulan']) ?></td>
        <td class="n"><?= $rp($t['ss']) ?></td>
        <td class="n"><?= $rp($t['cl']) ?></td>
        <td class="n <?= $t['selisih'] < 0 ? 'merah' : ($t['selisih'] > 0 ? 'hijau' : '') ?>">
            <?= $t['selisih'] == 0 ? '—' : ($t['selisih'] > 0 ? '+' : '&minus;') . number_format(abs($t['selisih']), 0, ',', '.') ?></td>
        <td class="muted"><?= $t['selisih'] < 0 ? 'ada yang belum diinput' : ($t['selisih'] > 0 ? 'CLARA lebih besar' : 'sudah cocok') ?></td>
    </tr>
    <?php endforeach; ?>
    <tr style="background:#f1f5f9;font-weight:700">
        <td>TOTAL</td><td class="n"><?= $rp($totSS) ?></td><td class="n"><?= $rp($totCL) ?></td>
        <td class="n"><?= ($totCL - $totSS > 0 ? '+' : '&minus;') . number_format(abs($totCL - $totSS), 0, ',', '.') ?></td>
        <td></td></tr>
</table>
<p class="muted" style="margin-top:-3px">Selisih <b>minus</b> berarti CLARA lebih kecil &mdash;
hampir selalu karena ada yang belum diinput. Spreadsheet dipakai sebagai patokan;
seluruh langkah di dokumen ini dikerjakan di CLARA.</p>

<?php foreach ($fokus as $bln):
    $qRinci->execute([$pid, $pic, $bln]);
    $clRows = $qRinci->fetchAll();
    $ssRows = $ss['rincian'][$bln] ?? [];
    [$cocok, $ragu, $kurang, $lebih] = sandingkan($ssRows, $clRows);
    $jmlKurang = array_sum(array_column($kurang, 'nilai'));
    $jmlLebih  = array_sum(array_map(fn($r) => (float) $r['amount'], $lebih));
?>
<h2>2. Rincian <?= $h($bln) ?> &mdash; apa yang harus dikerjakan</h2>
<p><b><?= count($cocok) ?> baris sudah cocok</b> (nilai dan namanya sama).
   <?php if ($ragu): ?><b><?= count($ragu) ?> baris perlu dipastikan</b> (nilainya sama, namanya beda).
   <?php endif; ?>
   <b class="merah"><?= count($kurang) ?> baris belum ada di CLARA</b> (<?= $rp($jmlKurang) ?>),
   <b><?= count($lebih) ?> baris ada di CLARA saja</b> (<?= $rp($jmlLebih) ?>).</p>

<?php if ($ragu): ?>
<h3>Perlu dipastikan &mdash; nilainya sama, namanya berbeda</h3>
<p class="muted" style="margin-top:-3px">Spreadsheet kadang menulis lokasinya, CLARA menulis nama
penyewanya. Kalau dua kolom di bawah ini orang yang sama, tidak ada yang perlu dikerjakan.
Kalau ternyata berbeda, berarti yang kiri belum diinput dan yang kanan perlu diperiksa.</p>
<table>
    <tr><th>Di spreadsheet</th><th>Di CLARA</th><th class="n">Nilai</th><th class="n">Trx</th></tr>
    <?php foreach ($ragu as [$sRow, $cRow]):
        $silang = substr((string) $cRow['start_date'], 0, 7) !== substr((string) $cRow['end_date'], 0, 7); ?>
    <tr>
        <td><?= $h($sRow['ket']) ?></td>
        <td><?= $h($cRow['nama']) ?> <span class="muted"><?= $h($cRow['master_code']) ?><?= $silang ? ', lintas bulan' : '' ?></span></td>
        <td class="n"><?= $rp($sRow['nilai']) ?></td>
        <td class="n">#<?= (int) $cRow['trx'] ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>

<?php if ($kurang): ?>
<h3 class="merah">A. Harus DIINPUT &mdash; ada di spreadsheet, belum ada di CLARA</h3>
<table>
    <tr><th>Keterangan di spreadsheet</th><th class="n">Nilai</th></tr>
    <?php foreach ($kurang as $k): ?>
    <?php $tbc = stripos((string) $k['ket'], 'TBC') !== false; ?>
    <tr class="tambah"><td><?= $h($k['ket']) ?><?= $tbc
        ? ' <span class="muted">&mdash; bertanda TBC, input hanya bila sudah benar-benar deal</span>' : '' ?></td>
        <td class="n"><?= $rp($k['nilai']) ?></td></tr>
    <?php endforeach; ?>
    <tr style="font-weight:700"><td>Jumlah yang hilang</td><td class="n"><?= $rp($jmlKurang) ?></td></tr>
</table>
<?php endif; ?>

<?php if ($lebih): ?>
<h3>B. PERIKSA &mdash; ada di CLARA, tidak ada di daftar spreadsheet</h3>
<table>
    <tr><th>Penyewa</th><th>Unit</th><th>Periode</th><th class="n">Nilai</th><th class="n">Trx</th></tr>
    <?php foreach ($lebih as $c):
        $silang = substr((string) $c['start_date'], 0, 7) !== substr((string) $c['end_date'], 0, 7); ?>
    <tr class="buang">
        <td><?= $h($c['nama']) ?><?= $silang ? ' <span class="muted">(lintas bulan)</span>' : '' ?></td>
        <td><?= $h($c['master_code']) ?></td>
        <td><?= $h(date('d/m/y', strtotime($c['start_date'])) . '–' . date('d/m/y', strtotime($c['end_date']))) ?></td>
        <td class="n"><?= $rp($c['amount']) ?></td>
        <td class="n">#<?= (int) $c['trx'] ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<div class="box warn">
    <p style="margin:0">Baris bertanda <b>(lintas bulan)</b> hampir pasti <b>bukan kesalahan</b>:
    masa sewanya menyeberang ke bulan berikutnya, dan CLARA memang membukukan seluruh nilainya di
    bulan mulai. Spreadsheet menaruhnya di bulan berikutnya. <b>Jangan dihapus.</b>
    Yang perlu diperiksa hanya baris yang periodenya utuh di dalam bulan ini &mdash;
    itu bisa jadi dobel, atau memang belum tercatat di spreadsheet.</p>
</div>
<?php endif; ?>
<?php endforeach; ?>

<?php if ($menyimpang): ?>
<h2>Nilai yang menyimpang dari siklus sebelumnya</h2>
<p>Penyewa berikut nilainya hampir selalu sama tiap siklus, tetapi ada satu siklus yang jauh
berbeda. Yang paling sering: <b>tarif ikut terketik di kolom nilai</b>. Periksa dan betulkan
lewat menu asalnya.</p>
<table>
    <tr><th>Penyewa</th><th>Unit</th><th>Periode</th><th class="n">Tercatat</th>
        <th class="n">Lazimnya</th><th class="n">Kurang</th><th class="n">Trx</th></tr>
    <?php foreach (array_slice($menyimpang, 0, 12) as $m): ?>
    <tr class="tambah">
        <td><?= $h($m['nama']) ?></td>
        <td><?= $h($m['master_code']) ?></td>
        <td><?= $h(date('d/m/y', strtotime($m['start_date'])) . '–' . date('d/m/y', strtotime($m['end_date']))) ?></td>
        <td class="n merah"><?= $rp($m['nilai']) ?></td>
        <td class="n"><?= $rp($m['lazim']) ?></td>
        <td class="n"><?= $m['selisih'] > 0 ? $rp($m['selisih']) : '—' ?></td>
        <td class="n">#<?= (int) $m['id'] ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<p class="muted" style="margin-top:-4px">Bukan semuanya pasti salah &mdash; siklus pendek atau
harga nego baru memang bisa berbeda. Yang perlu dicurigai adalah yang nilainya kebetulan sama
dengan <b>tarif</b>-nya.</p>
<?php endif; ?>

<?php
// ── Langkah agar bulan ini cocok ────────────────────────────────────────────
//
// Semua tindakan ada di sisi CLARA: spreadsheet jadi patokan, CLARA yang
// menyesuaikan. Yang kurang diinput, yang bulannya berbeda digeser
// pengakuannya — tidak ada nilai yang dibuang.
foreach ($fokus as $bln) {
    $qBulan->execute([$pid, $pic, $bln]);
    $clSekarang = (float) $qBulan->fetchColumn();
    $ssTarget   = (float) ($ss['bulan'][$bln] ?? 0);

    $qRinci->execute([$pid, $pic, $bln]);
    [$ck, $rg, $kr, $lb] = sandingkan($ss['rincian'][$bln] ?? [], $qRinci->fetchAll());
    $raguSama = array_map('mb_strtolower', (array) ($ss['ragu_sama'][$bln] ?? []));
    foreach ($rg as [$sRow, $cRow]) {
        if (!in_array(mb_strtolower((string) $sRow['ket']), $raguSama, true)) $kr[] = $sRow;
    }
    usort($kr, fn($a, $b) => (float) $b['nilai'] <=> (float) $a['nilai']);
    $jmlInput = array_sum(array_column($kr, 'nilai'));
    $aksi     = $ss['aksi_clara'][$bln] ?? [];
    $jmlAksi  = array_sum(array_map(fn($a) => (float) $a['nilai'], $aksi));
    $hasil    = $clSekarang + $jmlInput + $jmlAksi;
?>
<h2>Langkah agar <?= $h($bln) ?> cocok dengan spreadsheet</h2>
<table>
    <tr><th style="width:64%">Langkah di CLARA</th><th class="n">Nilai</th><th class="n">Jadi</th></tr>
    <tr><td>Keadaan sekarang</td><td class="n">&mdash;</td><td class="n"><?= $rp($clSekarang) ?></td></tr>
    <?php $jalan = $clSekarang; foreach ($kr as $k): $jalan += (float) $k['nilai']; ?>
    <tr class="tambah"><td>Input: <?= $h(mb_substr((string) $k['ket'], 0, 70)) ?></td>
        <td class="n hijau">+<?= number_format((float) $k['nilai'], 0, ',', '.') ?></td>
        <td class="n"><?= $rp($jalan) ?></td></tr>
    <?php endforeach; ?>
    <?php foreach ($aksi as $a): $jalan += (float) $a['nilai']; ?>
    <tr class="buang"><td>Geser: <?= $h(mb_substr((string) $a['ket'], 0, 70)) ?></td>
        <td class="n"><?= ((float) $a['nilai'] >= 0 ? '+' : '&minus;')
            . number_format(abs((float) $a['nilai']), 0, ',', '.') ?></td>
        <td class="n"><?= $rp($jalan) ?></td></tr>
    <?php endforeach; ?>
    <tr style="background:#ecfdf5;font-weight:700">
        <td>HASIL</td><td class="n">&mdash;</td><td class="n"><?= $rp($hasil) ?></td></tr>
    <tr style="font-weight:700"><td>Spreadsheet <?= $h($bln) ?></td><td class="n">&mdash;</td>
        <td class="n"><?= $rp($ssTarget) ?></td></tr>
    <tr style="font-weight:700"><td>SELISIH</td><td class="n">&mdash;</td>
        <td class="n"><?= round($hasil - $ssTarget) == 0
            ? '<span class="hijau">Rp 0 &mdash; COCOK</span>'
            : '<span class="merah">' . $rp($hasil - $ssTarget) . '</span>' ?></td></tr>
</table>

<?php foreach ($aksi as $a): ?>
<div class="box warn">
    <p style="margin:0"><span class="tebal">Cara menggesernya.</span>
    <?= $h($a['cara'] ?? '') ?></p>
</div>
<?php endforeach; ?>

<?php foreach (($ss['catatan_khusus'][$bln] ?? []) as $c): ?>
<h3><?= $h($c['judul'] ?? 'Catatan') ?></h3>
<p style="margin:0 0 8px"><?= $h($c['isi'] ?? '') ?></p>
<?php endforeach; ?>
<?php } ?>

<?php
// ── Langkah bergambar ───────────────────────────────────────────────────────
$gbr = [];
if ($opt['gambar'] !== '' && is_dir((string) $opt['gambar'])) {
    foreach (glob(rtrim((string) $opt['gambar'], '/') . '/ss*.png') ?: [] as $g) $gbr[basename($g)] = $g;
    ksort($gbr);
}
$kapsi = [
    'ss1_renewal.png' => ['Menu <b>Renewal Kontrak</b>', 'Semua kontrak yang akan/sudah berakhir ada di sini, lengkap dengan nama PIC-nya. Tekan <b>+ Perpanjang</b> pada baris yang mau diteruskan. <span class="merah">Perhatikan: kontrak yang sudah lewat lebih dari 7 hari TIDAK muncul di sini lagi</span> &mdash; yang seperti itu harus dibuat manual lewat Surat Penawaran.'],
    'ss2_form.png'    => ['Surat Penawaran terisi otomatis', 'Client, unit, rate, dan PIC sudah terisi dari kontrak sebelumnya. <b>Tanggal Mulai</b> otomatis sehari setelah siklus lama berakhir. Periksa tanggalnya cocok dengan kontrak, lalu <b>Simpan</b>.'],
    'ss3_deal.png'    => ['Jadikan DEAL, lalu Buat SKP', 'Setelah client menyetujui (TTD online) atau status dijadikan DEAL manual, tombol <b>&rarr; Buat SKP (Konfirmasi)</b> muncul. Transaksi &mdash; dan income-nya &mdash; baru terbit setelah SKP ini dibuat dan disetujui.'],
];
if ($gbr): ?>
<h2>Langkah inputnya &mdash; dengan gambar</h2>
<p>Input transaksi langsung sudah <b>dimatikan</b> di CLARA. Semua kontrak, termasuk perpanjangan,
wajib lewat <b>Surat Penawaran &rarr; DEAL &rarr; SKP</b>. Urutannya begini:</p>
<?php $no = 0; foreach ($gbr as $nama => $jalur): $no++;
    [$judul, $teks] = $kapsi[$nama] ?? [ucfirst(str_replace(['.png','_'], ['',' '], $nama)), '']; ?>
<h3><?= $no ?>. <?= $judul ?></h3>
<?php if ($teks): ?><p style="margin:0 0 5px"><?= $teks ?></p><?php endif; ?>
<img src="<?= $h($jalur) ?>" style="width:168mm;border:1px solid #cbd5e1">
<?php endforeach; ?>
<?php endif; ?>

<h2>Urutan mengerjakannya</h2>
<ol>
    <li><b>Mulai dari daftar A.</b> Itu yang membuat angka Anda kecil. Untuk tiap barisnya:
        buka <b>Renewal Kontrak</b> lalu tekan <b>+ Perpanjang</b>. Kalau kontraknya sudah lewat
        lebih dari 7 hari dan tidak muncul di sana, buat manual lewat <b>Surat Penawaran</b>.</li>
    <li><b>Simpan penawarannya, jadikan DEAL, lalu tekan Buat SKP (Konfirmasi).</b>
        Income baru terhitung setelah SKP-nya terbit &mdash; menyimpan penawaran saja belum cukup.</li>
    <li><b>Tanggal disalin dari kontrak</b>, jangan dibulatkan. Perpanjangan mulai <b>sehari setelah</b>
        siklus sebelumnya berakhir.</li>
    <li><b>Kolom PIC Sales diisi <?= $h($pic) ?></b> &mdash; kosong berarti tidak masuk ke siapa pun.</li>
    <li><b>Lalu periksa daftar B.</b> Abaikan yang bertanda lintas bulan. Sisanya: kalau benar dobel,
        ajukan lewat <b>Pengajuan Hapus Data</b> dengan alasan <b>Dobel / duplikat</b> &mdash;
        jangan dihapus diam-diam.</li>
    <li><b>Buka Dashboard lagi</b> dan bandingkan ulang. Kalau masih beda, cetak dokumen ini
        sekali lagi &mdash; isinya ikut data terbaru.</li>
</ol>

<div class="box">
    <p style="margin:0"><span class="tebal">Dua hal yang BUKAN kesalahan input.</span></p>
    <ul style="margin:4px 0 0 16px">
        <li><b>Kontrak lintas bulan.</b> Nilainya tidak hilang, hanya dibukukan di bulan mulainya.
            Setahun penuh jumlahnya tetap sama.</li>
        <li><b>Baris bertanda TBC / belum deal di spreadsheet.</b> Spreadsheet boleh menuliskan
            proyeksi; CLARA hanya menghitung yang sudah benar-benar jadi. Selama belum deal,
            CLARA memang tidak akan menampilkannya &mdash; dan itu yang benar.</li>
    </ul>
</div>
<?php
$html = (string) ob_get_clean();
$mpdf = clara_letterhead_mpdf();
$mpdf->SetTitle('Daftar Perbaikan Data — ' . $pic);
$mpdf->WriteHTML($html);
@mkdir(dirname($opt['keluar']), 0777, true);
$mpdf->Output($opt['keluar'], \Mpdf\Output\Destination::FILE);
echo "  Dokumen dibuat: {$opt['keluar']}\n";
