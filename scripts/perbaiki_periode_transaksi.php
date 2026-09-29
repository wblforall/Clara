<?php
/**
 * Perbaiki periode (dan nilai) satu transaksi, lalu susun ulang alokasi
 * bulanannya. Dipakai kalau kontrak terlanjur tersimpan lebih panjang dari
 * yang disepakati sehingga laporannya membengkak.
 *
 *   php scripts/perbaiki_periode_transaksi.php 1917                      # lihat keadaannya
 *   php scripts/perbaiki_periode_transaksi.php 1917 2026-11-18           # simulasi
 *   php scripts/perbaiki_periode_transaksi.php 1917 2026-11-18 --nilai=7000000 --apply
 *
 *   --nilai=RP   nilai kontrak baru (kosong = nilai lama dipertahankan)
 *   --rate=RP    harga per bulan / per hari (dipakai kalau transaksinya diedit lagi
 *                lewat formulir — kosongkan kalau tidak perlu diubah)
 *   --apply      simpan; tanpa ini hanya simulasi
 *
 * Alokasi bulanan lama dihapus lalu dihitung ulang dari periode & nilai baru.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/Database.php';
require_once $root . '/app/AllocationService.php';
$pdo = Database::connect();

$rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

if (!isset($argv[1])) {
    fwrite(STDERR, "Pakai: php scripts/perbaiki_periode_transaksi.php <id_transaksi> [tanggal_selesai_baru] [--nilai=RP] [--apply]\n");
    exit(1);
}
$id    = (int) $argv[1];
$akhir = isset($argv[2]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $argv[2]) ? (string) $argv[2] : null;
$apply = in_array('--apply', $argv, true);
$nilai = null; $rate = null;
foreach ($argv as $a) {
    if (preg_match('/^--nilai=(\d+)$/', $a, $m)) $nilai = (float) $m[1];
    if (preg_match('/^--rate=(\d+)$/', $a, $m))  $rate  = (float) $m[1];
}

$st = $pdo->prepare('SELECT t.*, c.company_name FROM transactions t LEFT JOIN master_clients c ON c.id = t.client_id WHERE t.id = ?');
$st->execute([$id]);
$trx = $st->fetch();
if (!$trx) { fwrite(STDERR, "Transaksi #$id tidak ada.\n"); exit(1); }

echo "Transaksi : #{$trx['id']} {$trx['master_code']} · {$trx['company_name']} · PIC {$trx['pic_name']}\n";
echo "Periode   : {$trx['start_date']} s/d {$trx['end_date']}\n";
echo "Nilai     : " . $rp($trx['final_amount'] ?: $trx['total_calculated'])
   . "  (metode {$trx['billing_method']}" . ($trx['recurring_flag'] ? ', recurring' : '') . ")\n";
echo "Dibuat    : {$trx['created_by']} {$trx['created_at']}" . ($trx['deleted_at'] ? "  — SUDAH DIBATALKAN {$trx['deleted_at']}" : '') . "\n\n";

$al = $pdo->prepare('SELECT period_key, SUM(amount) amt FROM transaction_allocations WHERE transaction_id = ? GROUP BY period_key ORDER BY period_key');
$al->execute([$id]);
$lama = $al->fetchAll();
echo "Alokasi bulanan sekarang (" . count($lama) . " bulan):\n";
foreach ($lama as $r) printf("   %-9s %s\n", $r['period_key'], $rp($r['amt']));
echo "   TOTAL     " . $rp(array_sum(array_column($lama, 'amt'))) . "\n\n";

if ($akhir === null) {
    echo "Belum ada tanggal selesai baru — hanya menampilkan keadaannya.\n";
    echo "Lanjut: php scripts/perbaiki_periode_transaksi.php $id <YYYY-MM-DD> [--nilai=RP] [--apply]\n";
    exit;
}

$baru = $trx;
$baru['end_date'] = $akhir;
if ($nilai !== null) {
    $baru['final_amount']     = $nilai;
    $baru['override_amount']  = $nilai;
    $baru['total_calculated'] = $nilai;
}
// Simulasi dijalankan dengan penyimpanan sungguhan di dalam transaksi DB lalu
// dibatalkan — supaya angkanya persis sama dengan hasil akhir. preview() saja
// tidak cukup: nilai kontrak baru baru diterapkan di tahap saveAllocations().
$pdo->beginTransaction();
$sql = 'UPDATE transactions SET end_date = ?';
$par = [$akhir];
if ($rate !== null) { $sql .= ', unit_rate = ?'; $par[] = $rate; }
if ($nilai !== null) {
    $sql .= ', final_amount = ?, override_amount = ?, total_calculated = ?';
    array_push($par, $nilai, $nilai, $nilai);
}
$sql .= ', updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ?';
$par[] = 'skrip:perbaiki_periode';
$par[] = $id;
$pdo->prepare($sql)->execute($par);
$st->execute([$id]);
AllocationService::saveAllocations($pdo, $id, $st->fetch());
$al->execute([$id]);
$hasil = $al->fetchAll();

echo "Periode baru : {$trx['start_date']} s/d {$akhir}\n";
echo "Nilai baru   : " . $rp($nilai !== null ? $nilai : ($trx['final_amount'] ?: $trx['total_calculated'])) . "\n";
if ($rate !== null) echo "Harga/bulan  : " . $rp($rate) . "\n";
echo "Alokasi baru (" . count($hasil) . " bulan):\n";
foreach ($hasil as $r) printf("   %-9s %s\n", $r['period_key'], $rp($r['amt']));
echo "   TOTAL     " . $rp(array_sum(array_column($hasil, 'amt'))) . "\n\n";

if (!$apply) {
    $pdo->rollBack();
    echo "SIMULASI — tidak ada yang diubah. Tambahkan --apply untuk menyimpan.\n";
    exit;
}
$pdo->commit();
$al->execute([$id]);
$cek = $al->fetchAll();
echo "TERSIMPAN → " . count($cek) . " bulan, total " . $rp(array_sum(array_column($cek, 'amt'))) . "\n";