<?php
/**
 * Transaksi yang SUDAH TAMPIL di Exhibition/Media/Gudang tetapi income-nya nol.
 *
 * Daftar modul menyaring dengan aturan yang sama dengan pengakuan income:
 * sebuah transaksi hanya tampil bila dokumennya sudah DITANDATANGANI client,
 * atau memang tidak berdokumen, atau dibuat sebelum aturan TTD berlaku.
 * Artinya: begitu sebuah baris terlihat di sana, nilainya SEHARUSNYA sudah
 * duduk di laporan.
 *
 * Kalau tidak, ada yang putus — alokasi bulanannya gagal terbentuk saat
 * tanda tangan masuk. Skrip ini mencari baris seperti itu, dan dengan
 * --perbaiki membangun ulang alokasinya memakai angka transaksi itu sendiri.
 *
 * Tanpa --perbaiki: read-only, tidak menulis apa pun.
 *
 * Pakai:
 *   php scripts/cek_income_hilang.php --prop=2 --pic="Shinta"
 *   php scripts/cek_income_hilang.php --prop=2 --pic="Shinta" --perbaiki
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/AllocationService.php';

$opt = array_merge(['pic' => '', 'prop' => '', 'bulan' => ''],
    getopt('', ['pic:', 'prop:', 'bulan:', 'perbaiki']));
$perbaiki = array_key_exists('perbaiki', getopt('', ['perbaiki']));

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$rp = fn($v) => number_format((float) $v, 0, ',', '.');

$ttdMulai = '';
try {
    $ttdMulai = (string) ($pdo->query("SELECT value FROM settings WHERE `key`='ttd_wajib_mulai' LIMIT 1")
                              ->fetchColumn() ?: '');
} catch (Throwable $e) {
}

// Sub-kueri status dokumen — bentuknya disamakan dengan daftar modul, supaya
// yang diperiksa benar-benar baris yang sama dengan yang dilihat orang.
$skpStatus = "(SELECT s.status FROM skp_documents s
                WHERE (s.transaction_id = t.id OR s.id = t.skp_id)
                  AND s.property_id = t.property_id AND s.deleted_at IS NULL
                ORDER BY s.id DESC LIMIT 1)";

$syarat = ['t.deleted_at IS NULL'];
$args   = [];
if ($opt['pic']  !== '') { $syarat[] = 't.pic_name = ?';    $args[] = $opt['pic']; }
if ($opt['prop'] !== '') { $syarat[] = 't.property_id = ?'; $args[] = (int) $opt['prop']; }
if ($opt['bulan'] !== '') {
    $syarat[] = "DATE_FORMAT(t.start_date, '%Y-%m') = ?";
    $args[]   = $opt['bulan'];
}
// Aturan tampil di daftar modul — disalin apa adanya, TERMASUK saringan
// "sedang direvisi". Tanpa baris itu, dokumen yang sengaja dibongkar untuk
// revisi — alokasinya memang dihapus sampai selesai diperbaiki — akan
// dilaporkan sebagai income hilang. Satu saringan yang terlewat sudah cukup
// membuat seluruh daftar ini tidak bisa dipercaya.
$skpRevisi = "(SELECT s.revisi_ke FROM skp_documents s
                WHERE (s.transaction_id = t.id OR s.id = t.skp_id)
                  AND s.property_id = t.property_id AND s.deleted_at IS NULL
                ORDER BY s.id DESC LIMIT 1)";
$syarat[] = "COALESCE($skpStatus, '') <> 'rejected'";
$syarat[] = "NOT (COALESCE($skpStatus, '') = 'draft' AND COALESCE($skpRevisi, 0) > 0)";
if ($ttdMulai !== '') {
    $syarat[] = "(COALESCE($skpStatus, '') IN ('', 'signed') OR DATE(t.created_at) < ?)";
    $args[]   = $ttdMulai;
}

$sql = "SELECT t.*, COALESCE($skpStatus, '') AS skp_status,
               COALESCE((SELECT SUM(a.amount) FROM transaction_allocations a
                          WHERE a.transaction_id = t.id), 0) AS income,
               COALESCE(c.brand_name, c.company_name, '(tanpa nama)') AS nama
          FROM transactions t
          LEFT JOIN master_clients c ON c.id = t.client_id
         WHERE " . implode(' AND ', $syarat) . "
         ORDER BY t.start_date, t.id";
$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

$putus = array_values(array_filter($rows, function ($r) {
    // Nilainya memang nol? itu bukan income hilang, itu kontrak nol rupiah.
    $nilai = (float) ($r['final_amount'] ?: $r['total_calculated']);
    return $nilai > 0 && (float) $r['income'] <= 0;
}));

echo "\nTAMPIL DI MODUL TAPI INCOME NOL\n";
echo str_repeat('=', 100) . "\n";
echo "  Aturan TTD berlaku sejak : " . ($ttdMulai ?: '(belum disetel)') . "\n";
printf("  Diperiksa                : %d transaksi\n", count($rows));
printf("  Yang putus               : %d\n\n", count($putus));

if (!$putus) {
    echo "  Tidak ada. Semua yang tampil di modul sudah menyumbang income.\n\n";
    exit(0);
}

printf("  %-26s %-7s %-9s %-19s %14s %-10s %s\n",
    'PENYEWA', 'MODUL', 'UNIT', 'PERIODE', 'NILAI', 'STATUS', 'TRX');
echo '  ' . str_repeat('-', 96) . "\n";
$jml = 0.0;
foreach ($putus as $r) {
    $nilai = (float) ($r['final_amount'] ?: $r['total_calculated']);
    $jml  += $nilai;
    printf("  %-26s %-7s %-9s %s..%s  %14s %-10s #%s\n",
        mb_substr((string) $r['nama'], 0, 26), $r['module'], mb_substr((string) $r['master_code'], 0, 9),
        date('d/m/y', strtotime($r['start_date'])), date('d/m/y', strtotime($r['end_date'])),
        $rp($nilai), $r['skp_status'] ?: '(tanpa dok)', $r['id']);
}
echo '  ' . str_repeat('-', 96) . "\n";
printf("  %-26s %47s\n", 'JUMLAH INCOME YANG HILANG', 'Rp ' . $rp($jml));

if (!$perbaiki) {
    echo "\n  Jalankan ulang dengan --perbaiki untuk membangun kembali alokasinya.\n";
    echo "  Angkanya diambil dari transaksi itu sendiri — tidak ada nilai yang dikarang.\n\n";
    exit(0);
}

echo "\n  Membangun ulang alokasi...\n";
$ok = 0; $gagal = 0;
foreach ($putus as $r) {
    try {
        $trx = $r;
        // anchor_cycle: seluruh nilai diakui di satu periode — sama persis
        // dengan yang dilakukan _skp_bangun_alokasi() saat tanda tangan masuk.
        if (($trx['billing_method'] ?? '') !== 'spread') $trx['recognition_period'] = $trx['period_key'];
        AllocationService::saveAllocations($pdo, (int) $r['id'], $trx);
        $cek = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM transaction_allocations WHERE transaction_id = ?');
        $cek->execute([(int) $r['id']]);
        $kini = (float) $cek->fetchColumn();
        printf("    #%-6s %-26s -> Rp %s%s\n", $r['id'], mb_substr((string) $r['nama'], 0, 26),
            $rp($kini), $kini > 0 ? '' : '  (MASIH NOL)');
        $kini > 0 ? $ok++ : $gagal++;
    } catch (Throwable $e) {
        $gagal++;
        printf("    #%-6s GAGAL: %s\n", $r['id'], $e->getMessage());
    }
}
printf("\n  Berhasil %d · gagal %d\n\n", $ok, $gagal);
