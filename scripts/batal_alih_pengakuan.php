<?php
/**
 * Batalkan pemindahan pengakuan yang terlanjur dijalankan.
 *
 * alih_pengakuan.php menumpuk nilai penuh sebuah kontrak ke satu bulan. Untuk
 * kontrak yang berjalan beberapa bulan itu membuat bulan tujuan KELEBIHAN
 * sebesar porsi bulan-bulan lainnya. Skrip ini mengembalikannya.
 *
 * Yang dikembalikan hanya transaksi yang MEMANG pernah dipindah — dikenali
 * dari jejak audit 'alih_pengakuan', bukan ditebak. Jadi tidak mungkin
 * menyentuh transaksi yang tidak ada urusannya.
 *
 * Dikembalikan ke keadaan wajar:
 *   billing_method -> spread        (dibagi per bulan sesuai masa sewanya)
 *   period_key     -> bulan MULAI kontrak
 * lalu alokasinya dibangun ulang. Nilai kontraknya tidak disentuh.
 *
 * Tanpa --terapkan hanya MELIHAT.
 *
 * Pakai:
 *   php scripts/batal_alih_pengakuan.php --prop=2 --pic="Shinta"
 *   php scripts/batal_alih_pengakuan.php --prop=2 --pic="Shinta" --terapkan
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/AllocationService.php';

$opt      = array_merge(['pic' => '', 'prop' => ''], getopt('', ['pic:', 'prop:', 'terapkan']));
$terapkan = array_key_exists('terapkan', getopt('', ['terapkan']));

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$rp = fn($v) => number_format((float) $v, 0, ',', '.');

// Transaksi yang pernah dipindah — dari jejak audit, bukan tebakan.
$ids = [];
try {
    foreach ($pdo->query("SELECT DISTINCT record_id FROM audit_logs
                           WHERE action = 'alih_pengakuan' AND table_name = 'transactions'") as $r) {
        $id = (int) $r['record_id'];
        if ($id > 0) $ids[] = $id;
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Gagal membaca audit_logs: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nBATALKAN PEMINDAHAN PENGAKUAN\n";
echo str_repeat('=', 92) . "\n";
if (!$ids) {
    echo "  Tidak ada transaksi yang pernah dipindah. Tidak ada yang perlu dibatalkan.\n\n";
    exit(0);
}

$syarat = ['t.deleted_at IS NULL', 't.id IN (' . implode(',', $ids) . ')'];
$args   = [];
if ($opt['pic']  !== '') { $syarat[] = 't.pic_name = ?';    $args[] = $opt['pic']; }
if ($opt['prop'] !== '') { $syarat[] = 't.property_id = ?'; $args[] = (int) $opt['prop']; }

$st = $pdo->prepare(
    "SELECT t.*, COALESCE(c.brand_name, c.company_name, '(tanpa nama)') nama
       FROM transactions t
       LEFT JOIN master_clients c ON c.id = t.client_id
      WHERE " . implode(' AND ', $syarat) . "
      ORDER BY t.id"
);
$st->execute($args);
$rows = $st->fetchAll();

if (!$rows) { echo "  Tidak ada yang cocok dengan saringan itu.\n\n"; exit(0); }

$alok = $pdo->prepare('SELECT period_key, SUM(amount) rp FROM transaction_allocations
                        WHERE transaction_id = ? GROUP BY period_key ORDER BY period_key');

printf("  %d transaksi pernah dipindah.%s\n\n", count($rows), $terapkan ? ' MODE TERAPKAN.' : ' (hanya melihat)');

$ok = 0; $gagal = 0;
foreach ($rows as $r) {
    $nilai   = (float) ($r['final_amount'] ?: $r['total_calculated']);
    $awalBln = substr((string) $r['start_date'], 0, 7);
    printf("  #%-6s %-26s %-8s %s..%s  Rp %s\n", $r['id'], mb_substr((string) $r['nama'], 0, 26),
        $r['master_code'], substr((string) $r['start_date'], 0, 10), substr((string) $r['end_date'], 0, 10),
        $rp($nilai));
    $alok->execute([(int) $r['id']]);
    foreach ($alok->fetchAll() as $a) printf("      sekarang  %s  Rp %s\n", $a['period_key'], $rp($a['rp']));

    if (!$terapkan) {
        printf("      akan jadi Recurring, diakui mulai %s\n\n", $awalBln);
        continue;
    }
    try {
        $pdo->prepare("UPDATE transactions SET billing_method = 'spread', period_key = ? WHERE id = ?")
            ->execute([$awalBln, (int) $r['id']]);
        $trx = $r;
        $trx['billing_method'] = 'spread';
        $trx['period_key']     = $awalBln;
        unset($trx['recognition_period']);
        AllocationService::saveAllocations($pdo, (int) $r['id'], $trx);

        $alok->execute([(int) $r['id']]);
        $sesudah = $alok->fetchAll();
        $jml = 0.0;
        foreach ($sesudah as $a) { printf("      sesudah   %s  Rp %s\n", $a['period_key'], $rp($a['rp'])); $jml += (float) $a['rp']; }
        // Nilainya harus utuh — kalau menyusut, ada yang salah dan harus terlihat.
        if (round($jml) !== round($nilai)) {
            printf("      PERHATIAN: jumlah alokasi Rp %s, nilai kontrak Rp %s\n", $rp($jml), $rp($nilai));
        }
        audit($pdo, 'batal_alih_pengakuan', 'transactions', (string) $r['id'], ['kembali_ke' => $awalBln]);
        $ok++;
    } catch (Throwable $e) {
        $gagal++;
        printf("      GAGAL: %s\n", $e->getMessage());
    }
    echo "\n";
}

if ($terapkan) printf("  Berhasil %d · gagal %d\n\n", $ok, $gagal);
else           echo "  Jalankan ulang dengan --terapkan untuk mengembalikannya.\n\n";
