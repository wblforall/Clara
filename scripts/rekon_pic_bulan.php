<?php
/**
 * Rincian pendapatan satu sales pada satu bulan — untuk disandingkan dengan Excel.
 *
 * Jalankan:  php scripts/rekon_pic_bulan.php "Muslimin" 2026-09 [property_id]
 * Keluaran:  daftar per transaksi + total, plus pemeriksaan kejanggalan yang
 *            biasa jadi sumber selisih (alokasi yatim, PIC tidak seragam,
 *            jumlah alokasi tidak sama dengan nilai kontrak).
 *
 * Tambahkan --csv untuk menulis berkas CSV yang gampang ditempel ke Excel.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/Database.php';
$pdo = Database::connect();

$pic    = $argv[1] ?? '';
$period = $argv[2] ?? date('Y-m');
$prop   = isset($argv[3]) && is_numeric($argv[3]) ? (int) $argv[3] : null;
$asCsv  = in_array('--csv', $argv, true);
if ($pic === '') { fwrite(STDERR, "Pemakaian: php scripts/rekon_pic_bulan.php \"Nama PIC\" 2026-09 [property_id] [--csv]\n"); exit(1); }

$rp = fn($v) => number_format((float) $v, 0, ',', '.');
$w  = fn($s, $n) => mb_substr(str_pad((string) $s, $n), 0, $n);

$sql = "SELECT a.transaction_id, a.module, a.master_code, a.allocated_days, a.amount,
               t.start_date, t.end_date, t.final_amount, t.billing_method,
               COALESCE(c.company_name, '-') klien
          FROM transaction_allocations a
          JOIN transactions t ON t.id = a.transaction_id
     LEFT JOIN master_clients c ON c.id = t.client_id
         WHERE a.pic_name = ? AND a.period_key = ? AND t.deleted_at IS NULL"
     . ($prop ? ' AND a.property_id = ' . $prop : '')
     . ' ORDER BY a.amount DESC, a.transaction_id';
$st = $pdo->prepare($sql);
$st->execute([$pic, $period]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

echo "\nRincian $pic — $period" . ($prop ? " (properti $prop)" : '') . "\n";
echo str_repeat('─', 118), "\n";
printf("%-6s %-8s %-10s %-26s %-23s %5s %14s %14s\n", 'TRX', 'MODUL', 'KODE', 'KLIEN', 'PERIODE KONTRAK', 'HARI', 'NILAI KONTRAK', 'MASUK BULAN INI');
echo str_repeat('─', 118), "\n";
$total = 0.0; $nol = 0;
foreach ($rows as $r) {
    $total += (float) $r['amount'];
    if ((float) $r['amount'] == 0.0) $nol++;
    printf("%-6s %-8s %-10s %-26s %-23s %5s %14s %14s\n",
        $r['transaction_id'], $r['module'], $w($r['master_code'], 10), $w($r['klien'], 26),
        $r['start_date'] . '→' . $r['end_date'], $r['allocated_days'],
        $rp($r['final_amount']), $rp($r['amount']));
}
echo str_repeat('─', 118), "\n";
printf("%-83s %14s %14s\n", count($rows) . ' baris (' . $nol . ' bernilai 0)', '', $rp($total));

// ── Pemeriksaan yang biasa jadi sumber selisih ──────────────────────────────
echo "\nPemeriksaan:\n";
$cek = function (string $judul, string $sql, array $p) use ($pdo, $rp) {
    $s = $pdo->prepare($sql); $s->execute($p); $r = $s->fetchAll(PDO::FETCH_ASSOC);
    echo '  ' . ($r ? '⚠ ' : '✓ ') . $judul . ': ' . count($r) . " baris\n";
    foreach ($r as $x) echo '      ' . json_encode($x, JSON_UNESCAPED_UNICODE) . "\n";
};
$cek('Alokasi menempel pada transaksi terhapus',
    "SELECT a.transaction_id, a.amount FROM transaction_allocations a JOIN transactions t ON t.id=a.transaction_id
      WHERE a.pic_name=? AND a.period_key=? AND t.deleted_at IS NOT NULL", [$pic, $period]);
$cek('Nama PIC di alokasi beda dengan di transaksi',
    "SELECT a.transaction_id, t.pic_name AS pic_transaksi, a.pic_name AS pic_alokasi FROM transaction_allocations a
       JOIN transactions t ON t.id=a.transaction_id
      WHERE a.period_key=? AND t.deleted_at IS NULL AND (t.pic_name=? OR a.pic_name=?)
        AND COALESCE(t.pic_name,'')<>COALESCE(a.pic_name,'')", [$period, $pic, $pic]);
$cek('Jumlah alokasi tidak sama dengan nilai kontrak',
    "SELECT t.id, t.final_amount, SUM(a.amount) jumlah_alokasi FROM transactions t
       JOIN transaction_allocations a ON a.transaction_id=t.id
      WHERE t.pic_name=? AND t.deleted_at IS NULL GROUP BY t.id
     HAVING ABS(SUM(a.amount)-t.final_amount)>0.5", [$pic]);
$cek('Kontrak menyentuh bulan ini tapi tidak punya baris alokasi',
    "SELECT t.id, t.master_code, t.final_amount FROM transactions t
      WHERE t.pic_name=? AND t.deleted_at IS NULL
        AND t.start_date <= LAST_DAY(CONCAT(?,'-01')) AND t.end_date >= CONCAT(?,'-01')
        AND NOT EXISTS (SELECT 1 FROM transaction_allocations a WHERE a.transaction_id=t.id AND a.period_key=?)",
    [$pic, $period, $period, $period]);

echo "\nCatatan: kontrak ber-metode 'anchor_cycle' diakui PENUH di bulan mulainya.\n";
echo "Barisnya yang bernilai 0 pada bulan berikutnya itu wajar — bukan kehilangan angka.\n";

if ($asCsv) {
    $dir = $root . '/docs/rekon_assets'; @mkdir($dir, 0777, true);
    $f = $dir . '/rekon_' . preg_replace('/\W+/', '_', $pic) . '_' . $period . '.csv';
    $h = fopen($f, 'w');
    fputcsv($h, ['transaksi', 'modul', 'kode', 'klien', 'mulai', 'selesai', 'hari', 'metode', 'nilai_kontrak', 'masuk_bulan_ini']);
    foreach ($rows as $r) fputcsv($h, [$r['transaction_id'], $r['module'], $r['master_code'], $r['klien'],
        $r['start_date'], $r['end_date'], $r['allocated_days'], $r['billing_method'], $r['final_amount'], $r['amount']]);
    fclose($h);
    echo "\nCSV: $f\n";
}
