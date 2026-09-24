<?php
/**
 * Ubah BULAN PENGAKUAN sebuah transaksi lalu bangun ulang alokasi bulanannya.
 *
 * Dipakai untuk kasus salah pilih "Nilai Diakui di Bulan" pada Surat Penawaran:
 * nilai kontraknya sudah benar, hanya pembagian ke bulan yang keliru. Menu Edit
 * Transaksi mengunci hal ini karena transaksinya terbit dari SKP bertanda
 * tangan — padahal mengubah bulan pengakuan TIDAK mengubah nilai kontrak
 * maupun isi dokumen, hanya bulan mana yang mencatat pendapatannya.
 *
 *   php scripts/ubah_pengakuan_transaksi.php 1907 awal            # lihat saja
 *   php scripts/ubah_pengakuan_transaksi.php 1907 awal --apply    # simpan
 *
 * Mode: awal   = diakui penuh di bulan mulai      (anchor, paling umum)
 *       akhir  = diakui penuh di bulan selesai    (anchor)
 *       spread = dibagi rata ke tiap bulan kontrak
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/Database.php';
require_once $root . '/app/AllocationService.php';

$id    = (int) ($argv[1] ?? 0);
$mode  = strtolower((string) ($argv[2] ?? ''));
$apply = in_array('--apply', $argv, true);
if ($id <= 0 || !in_array($mode, ['awal', 'akhir', 'spread'], true)) {
    fwrite(STDERR, "Pemakaian: php scripts/ubah_pengakuan_transaksi.php <id_transaksi> <awal|akhir|spread> [--apply]\n");
    exit(1);
}

$pdo = Database::connect();
$rp  = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

$trx = $pdo->prepare('SELECT * FROM transactions WHERE id = ?');
$trx->execute([$id]);
$trx = $trx->fetch(PDO::FETCH_ASSOC);
if (!$trx) { fwrite(STDERR, "Transaksi #$id tidak ada.\n"); exit(1); }
if (!empty($trx['deleted_at'])) { fwrite(STDERR, "Transaksi #$id sudah dihapus.\n"); exit(1); }

$skp = $pdo->prepare("SELECT skp_no, status FROM skp_documents WHERE transaction_id=? ORDER BY id DESC LIMIT 1");
$skp->execute([$id]);
$skp = $skp->fetch(PDO::FETCH_ASSOC);

echo "\nTransaksi #{$trx['id']} · {$trx['module']} · {$trx['master_code']}\n";
echo "  Periode        : {$trx['start_date']} s/d {$trx['end_date']}\n";
echo "  Nilai kontrak  : " . $rp($trx['final_amount']) . "   (TIDAK diubah oleh skrip ini)\n";
echo "  PIC            : {$trx['pic_name']}\n";
echo "  Dokumen        : " . ($skp ? "{$skp['skp_no']} ({$skp['status']})" : '-') . "\n";
echo "  Pengakuan saat ini : {$trx['billing_method']} · period_key {$trx['period_key']}\n";

$lama = $pdo->prepare('SELECT period_key, allocated_days, amount FROM transaction_allocations WHERE transaction_id=? ORDER BY period_key');
$lama->execute([$id]);
$lama = $lama->fetchAll(PDO::FETCH_ASSOC);

// Susun nilai baru — meniru persis logika form Transaksi (transaction_save).
$baru = $trx;
if ($mode === 'spread') {
    $baru['billing_method'] = 'spread';
    $baru['period_key']     = substr((string) $trx['start_date'], 0, 7);
    unset($baru['recognition_period']);
} else {
    $baru['billing_method'] = 'anchor_cycle';
    $baru['period_key']     = $mode === 'akhir'
        ? substr((string) $trx['end_date'], 0, 7)
        : substr((string) $trx['start_date'], 0, 7);
    if (substr((string) $trx['start_date'], 0, 7) !== substr((string) $trx['end_date'], 0, 7)) {
        $baru['recognition_period'] = $baru['period_key'];
    }
}

$pdo->beginTransaction();
AllocationService::saveAllocations($pdo, $id, $baru, []);
$cek = $pdo->prepare('SELECT period_key, allocated_days, amount FROM transaction_allocations WHERE transaction_id=? ORDER BY period_key');
$cek->execute([$id]);
$hasil = $cek->fetchAll(PDO::FETCH_ASSOC);

echo "\n  " . str_pad('BULAN', 10) . str_pad('SEBELUM', 20) . "SESUDAH\n  " . str_repeat('─', 52) . "\n";
$bulan = array_unique(array_merge(array_column($lama, 'period_key'), array_column($hasil, 'period_key')));
sort($bulan);
$idx = fn($rows, $k) => array_values(array_filter($rows, fn($r) => $r['period_key'] === $k))[0]['amount'] ?? null;
foreach ($bulan as $k) {
    $a = $idx($lama, $k); $b = $idx($hasil, $k);
    echo '  ' . str_pad($k, 10) . str_pad($a === null ? '—' : $rp($a), 20) . ($b === null ? '—' : $rp($b)) . "\n";
}
echo '  ' . str_repeat('─', 52) . "\n";
echo '  ' . str_pad('JUMLAH', 10) . str_pad($rp(array_sum(array_column($lama, 'amount'))), 20)
   . $rp(array_sum(array_column($hasil, 'amount'))) . "\n";

if (!$apply) {
    $pdo->rollBack();
    echo "\n  (percobaan saja — tidak ada yang disimpan. Tambahkan --apply untuk menyimpan.)\n\n";
    exit(0);
}

$pdo->prepare('UPDATE transactions SET billing_method=?, period_key=?, updated_at=CURRENT_TIMESTAMP, updated_by=? WHERE id=?')
    ->execute([$baru['billing_method'], $baru['period_key'], 'skrip:ubah_pengakuan', $id]);
$pdo->commit();

// Jejak audit bila tabelnya ada.
try {
    $pdo->prepare('INSERT INTO audit_logs (action, table_name, record_id, payload, created_by) VALUES (?,?,?,?,?)')
        ->execute(['ubah_pengakuan', 'transactions', (string) $id,
            json_encode(['dari' => $trx['billing_method'] . '/' . $trx['period_key'],
                         'ke'   => $baru['billing_method'] . '/' . $baru['period_key']], JSON_UNESCAPED_UNICODE),
            'skrip:ubah_pengakuan']);
} catch (Throwable $e) { /* tabel audit beda skema — abaikan */ }

echo "\n  ✓ Tersimpan. Nilai kontrak tetap " . $rp($trx['final_amount']) . ".\n\n";
