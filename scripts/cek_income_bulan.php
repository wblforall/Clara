<?php
/**
 * Rincian income satu PIC untuk satu bulan — apa adanya, baris per baris.
 *
 * Dipakai saat angka dashboard tidak cocok dengan spreadsheet. Yang dibutuhkan
 * bukan totalnya (itu sudah kelihatan di dashboard), melainkan DAFTAR
 * penyusunnya: begitu daftar ini disandingkan dengan daftar di spreadsheet,
 * barisnya yang hilang atau yang dobel langsung kelihatan tanpa menebak.
 *
 * Angkanya dibaca dari transaction_allocations — sumber yang sama persis yang
 * dipakai dashboard, jadi totalnya pasti sama dengan yang tampil di layar.
 *
 * Read-only: tidak menulis apa pun.
 *
 * Pakai:
 *   php scripts/cek_income_bulan.php --pic="Shinta" --bulan=2026-10
 *   php scripts/cek_income_bulan.php --pic="Shinta" --bulan=2026-10 --prop=2
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';

$opt = array_merge(['pic' => '', 'bulan' => date('Y-m'), 'prop' => ''],
    getopt('', ['pic:', 'bulan:', 'prop:']));
if ($opt['pic'] === '') {
    fwrite(STDERR, "Pakai: php scripts/cek_income_bulan.php --pic=\"Nama\" --bulan=2026-10 [--prop=2]\n");
    exit(2);
}
if (!preg_match('/^\d{4}-\d{2}$/', (string) $opt['bulan'])) {
    fwrite(STDERR, "Bulan harus bentuk YYYY-MM, contoh 2026-10\n");
    exit(2);
}

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$rp = fn($v) => number_format((float) $v, 0, ',', '.');

// Properti tidak wajib disebut: kalau dikosongkan, PIC-nya dicari di semua
// properti. Satu nama biasanya hanya ada di satu mal, dan memaksa orang
// mengingat nomor propertinya hanya menambah cara untuk salah.
$syarat = 'a.period_key = ? AND a.pic_name = ?';
$args   = [$opt['bulan'], $opt['pic']];
if ($opt['prop'] !== '') { $syarat .= ' AND a.property_id = ?'; $args[] = (int) $opt['prop']; }

$st = $pdo->prepare(
    "SELECT a.amount, a.property_id, t.id trx, t.module, t.master_code,
            t.start_date, t.end_date, t.billing_method,
            COALESCE(c.brand_name, c.company_name, '(tanpa nama)') nama
       FROM transaction_allocations a
       JOIN transactions t ON t.id = a.transaction_id
       LEFT JOIN master_clients c ON c.id = t.client_id
      WHERE $syarat AND t.deleted_at IS NULL
      ORDER BY t.module, a.amount DESC"
);
$st->execute($args);
$baris = $st->fetchAll();

printf("\nRINCIAN INCOME — %s · %s\n", $opt['pic'], $opt['bulan']);
echo str_repeat('=', 104) . "\n";
if (!$baris) {
    echo "  Tidak ada satu baris pun. Periksa ejaan nama PIC-nya, atau bulannya.\n\n";
    exit(0);
}

printf("  %-30s %-8s %-10s %-17s %14s  %s\n",
    'PENYEWA', 'MODUL', 'UNIT', 'PERIODE', 'NILAI', 'TRX');
echo '  ' . str_repeat('-', 100) . "\n";

$total = 0.0;
$perModul = [];
foreach ($baris as $b) {
    $total += (float) $b['amount'];
    $perModul[$b['module']] = ($perModul[$b['module']] ?? 0) + (float) $b['amount'];
    // Kontrak yang melewati pergantian bulan ditandai — itulah baris yang
    // paling sering dikira "salah bulan" padahal memang aturannya begitu.
    $blnMulai = substr((string) $b['start_date'], 0, 7);
    $silang   = $blnMulai !== substr((string) $b['end_date'], 0, 7);
    // Baris bernilai nol bukan kesalahan: kontraknya memang menyinggung bulan
    // ini, tetapi seluruh nilainya sudah dibukukan di bulan mulainya. Ditulis
    // apa adanya supaya tidak dikira "income hilang".
    $catatan = '';
    if ((float) $b['amount'] <= 0 && $blnMulai !== $opt['bulan']) {
        $catatan = '  < nilainya masuk ' . $blnMulai;
    } elseif ($silang) {
        $catatan = '  < lintas bulan, nilai penuh di sini';
    }
    printf("  %-30s %-8s %-10s %-17s %14s  #%-6s%s\n",
        mb_substr((string) $b['nama'], 0, 30), $b['module'], mb_substr((string) $b['master_code'], 0, 10),
        date('d/m/y', strtotime($b['start_date'])) . '-' . date('d/m/y', strtotime($b['end_date'])),
        $rp($b['amount']), $b['trx'], $catatan);
}
echo '  ' . str_repeat('-', 100) . "\n";
foreach ($perModul as $m => $v) printf("  %-30s %59s\n", 'subtotal ' . $m, $rp($v));
printf("  %-30s %59s\n", 'TOTAL (sama dengan dashboard)', $rp($total));

echo "\n  Keterangan:\n";
echo "  \"lintas bulan, nilai penuh di sini\" — masa sewanya menyeberang ke bulan\n";
echo "      berikutnya, dan CLARA membukukan SELURUH nilainya di bulan mulai ini.\n";
echo "      Kalau spreadsheet menaruhnya di bulan berikutnya, selisihnya ada di\n";
echo "      situ — bukan karena inputnya kurang.\n";
echo "  \"nilainya masuk YYYY-MM\" — kontraknya menyinggung bulan ini, tetapi\n";
echo "      nilainya sudah dihitung di bulan mulainya. Bukan income yang hilang.\n\n";
