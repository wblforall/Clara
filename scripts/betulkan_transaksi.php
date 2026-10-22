<?php
/**
 * Betulkan periode / harga sebuah transaksi, lalu bangun ulang income-nya.
 *
 * Income selalu mengikuti TANGGAL dan JENIS HARGA kontraknya. Dua kesalahan
 * yang paling sering membuat income "tidak masuk bulan ini":
 *
 *   1. Tahun salah ketik — kontrak Oktober 2026 tertulis 2027, nilainya tetap
 *      tercatat tetapi jatuh setahun kemudian.
 *   2. Beberapa siklus ditulis jadi satu baris panjang dengan jenis harga
 *      harian. CLARA lalu membaginya PER HARI ke bulan-bulan kalender,
 *      sehingga bulan pertama hanya kebagian sisa harinya. Spreadsheet
 *      menghitung per SIKLUS — siklus pertama utuh di bulan mulainya.
 *      Perbaikannya: jenis harga 'monthly' + jumlah bulan kontrak, sehingga
 *      tiap siklus diakui utuh di bulan awalnya.
 *
 * Semua yang diubah ditulis di perintahnya sendiri — tidak ada angka yang
 * ditebak diam-diam. Tanpa --terapkan hanya memperlihatkan rencananya.
 *
 * Pakai:
 *   php scripts/betulkan_transaksi.php --trx=2125 --selesai=2026-10-09
 *   php scripts/betulkan_transaksi.php --trx=2141 --tipe=monthly --rate=7000000 \
 *       --bulan-kontrak=3 --metode=spread --terapkan
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/AllocationService.php';

$opt = getopt('', ['trx:', 'mulai:', 'selesai:', 'rate:', 'tipe:', 'bulan-kontrak:',
                   'nilai:', 'metode:', 'terapkan']);
$terapkan = array_key_exists('terapkan', $opt);
$id = (int) ($opt['trx'] ?? 0);
if ($id <= 0) { fwrite(STDERR, "Pakai: --trx=ID [--mulai=..] [--selesai=..] [--rate=..] [--tipe=..] [--bulan-kontrak=..] [--nilai=..] [--metode=spread|anchor] [--terapkan]\n"); exit(2); }

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$rp = fn($v) => number_format((float) $v, 0, ',', '.');

$st = $pdo->prepare("SELECT t.*, COALESCE(c.brand_name, c.company_name, '(tanpa nama)') nama
                       FROM transactions t LEFT JOIN master_clients c ON c.id = t.client_id
                      WHERE t.id = ? AND t.deleted_at IS NULL");
$st->execute([$id]);
$t = $st->fetch();
if (!$t) { fwrite(STDERR, "Transaksi #$id tidak ada atau sudah dibatalkan.\n"); exit(1); }

$alok = $pdo->prepare('SELECT period_key, SUM(amount) rp FROM transaction_allocations
                        WHERE transaction_id = ? GROUP BY period_key ORDER BY period_key');

$tampil = function (array $t) use ($alok, $rp, $id): void {
    printf("    periode   %s s/d %s\n", $t['start_date'], $t['end_date']);
    printf("    harga     %s · rate %s · %s bulan kontrak · qty %s · luas %s m2\n", $t['pricing_type'],
        $rp($t['unit_rate']), $t['contract_months'] ?: '-',
        $rp($t['quantity'] ?? 1), $rp($t['area_sqm'] ?? 0));
    printf("    nilai     %s · metode %s · diakui %s\n",
        $rp($t['final_amount'] ?: $t['total_calculated']),
        ($t['billing_method'] ?? '') === 'spread' ? 'Recurring' : 'Sekaligus', $t['period_key'] ?: '-');
    $alok->execute([$id]);
    foreach ($alok->fetchAll() as $a) printf("      %s  Rp %s\n", $a['period_key'], $rp($a['rp']));
};

echo "\nBETULKAN TRANSAKSI #$id — " . $t['nama'] . " (" . $t['master_code'] . ")\n";
echo str_repeat('=', 78) . "\n";
echo "  SEBELUM\n";
$tampil($t);

// Rakit perubahannya — hanya yang disebut di perintah.
$baru = $t;
$ubah = [];
if (isset($opt['mulai']))   { $baru['start_date'] = (string) $opt['mulai'];   $ubah['start_date'] = $baru['start_date']; }
if (isset($opt['selesai'])) { $baru['end_date']   = (string) $opt['selesai']; $ubah['end_date']   = $baru['end_date']; }
if (isset($opt['tipe']))    { $baru['pricing_type'] = (string) $opt['tipe'];  $ubah['pricing_type'] = $baru['pricing_type']; }
if (isset($opt['rate']))    { $baru['unit_rate'] = (float) preg_replace('/\D/', '', (string) $opt['rate']); $ubah['unit_rate'] = $baru['unit_rate']; }
if (isset($opt['bulan-kontrak'])) { $baru['contract_months'] = (int) $opt['bulan-kontrak']; $ubah['contract_months'] = $baru['contract_months']; }
if (isset($opt['nilai']))   { $baru['final_amount'] = (float) preg_replace('/\D/', '', (string) $opt['nilai']); $ubah['final_amount'] = $baru['final_amount']; }
if (isset($opt['metode']))  {
    $m = ((string) $opt['metode']) === 'spread' ? 'spread' : 'anchor_cycle';
    $baru['billing_method'] = $m; $ubah['billing_method'] = $m;
}
// Tanpa satu pun pilihan perubahan, skrip ini hanya MELIHAT. Kalau tidak,
// memanggilnya untuk memeriksa sebuah transaksi malah menulis ulang
// alokasinya — perubahan yang tidak pernah diminta siapa pun.
if (!$ubah) { echo "\n  Tidak ada yang diubah — hanya melihat.\n\n"; exit(0); }

// period_key mengikuti bulan mulai — itulah bulan pengakuannya.
$baru['period_key'] = substr((string) $baru['start_date'], 0, 7);
if ((string) ($t['period_key'] ?? '') !== $baru['period_key']) $ubah['period_key'] = $baru['period_key'];

echo "\n  YANG DIUBAH\n";
foreach ($ubah as $k => $v) printf("    %-16s %s  ->  %s\n", $k, (string) ($t[$k] ?? '-'), (string) $v);

// Jalankan perubahannya sungguhan di dalam satu transaksi database, baca
// hasilnya, lalu simpan atau batalkan.
//
// Tanpa --terapkan hasilnya DIBATALKAN — tetapi angkanya sudah dihitung oleh
// mesin alokasi yang sama persis dengan yang dipakai saat menyimpan. Jadi yang
// terlihat di layar memang yang akan terjadi, bukan perkiraan yang ditulis
// ulang di skrip ini dan bisa melenceng dari aslinya.
//
// Ini yang membuat salah tebak tidak bisa terjadi lagi: income per bulan sudah
// kelihatan SEBELUM apa pun tersimpan.
$pdo->beginTransaction();
try {
    $sets = []; $vals = [];
    foreach ($ubah as $k => $v) { $sets[] = "`$k` = ?"; $vals[] = $v; }
    $vals[] = $id;
    $pdo->prepare('UPDATE transactions SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);

    // anchor_cycle: seluruh nilai diakui di satu periode — sama seperti jalur
    // tanda tangan. spread: dibagi menurut masa sewanya.
    if (($baru['billing_method'] ?? '') !== 'spread') $baru['recognition_period'] = $baru['period_key'];
    else unset($baru['recognition_period']);
    AllocationService::saveAllocations($pdo, $id, $baru);

    $st->execute([$id]);
    $t2 = $st->fetch();
    $alok->execute([$id]);
    $hasil = $alok->fetchAll();

    if ($terapkan) {
        audit($pdo, 'betulkan_transaksi', 'transactions', (string) $id, $ubah, array_intersect_key($t, $ubah));
        $pdo->commit();
    } else {
        $pdo->rollBack();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "GAGAL: " . $e->getMessage() . " — tidak ada yang disimpan.\n");
    exit(1);
}

printf("\n  %s\n", $terapkan ? 'SESUDAH' : 'HASILNYA AKAN JADI (belum disimpan)');
printf("    periode   %s s/d %s\n", $t2['start_date'], $t2['end_date']);
printf("    harga     %s · rate %s · %s bulan kontrak\n", $t2['pricing_type'],
    $rp($t2['unit_rate']), $t2['contract_months'] ?: '-');
printf("    nilai     %s · metode %s · diakui %s\n",
    $rp($t2['final_amount'] ?: $t2['total_calculated']),
    ($t2['billing_method'] ?? '') === 'spread' ? 'Recurring' : 'Sekaligus', $t2['period_key'] ?: '-');
$jml = 0.0;
foreach ($hasil as $a) { printf("      %s  Rp %s\n", $a['period_key'], $rp($a['rp'])); $jml += (float) $a['rp']; }
$nilai = (float) ($t2['final_amount'] ?: $t2['total_calculated']);
printf("\n  Jumlah alokasi: Rp %s · nilai kontrak: Rp %s%s\n", $rp($jml), $rp($nilai),
    round($jml) === round($nilai) ? ' (PAS)' : '  <-- PERIKSA');
echo $terapkan ? "\n" : "  Belum ada yang disimpan. Tambahkan --terapkan kalau angka di atas sudah benar.\n\n";
