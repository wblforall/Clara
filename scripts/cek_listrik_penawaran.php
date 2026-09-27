<?php
/**
 * Daftar Surat Penawaran yang memakai Biaya Listrik, beserta angka yang
 * BENAR-BENAR tercetak — untuk memeriksa kalau ada yang nominalnya janggal.
 *
 *   php scripts/cek_listrik_penawaran.php            # semua properti
 *   php scripts/cek_listrik_penawaran.php 1          # properti tertentu
 *   php scripts/cek_listrik_penawaran.php 1 Zulfikar # saring per sales
 *
 * Hanya membaca — tidak mengubah apa pun.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/Database.php';
require_once $root . '/app/pages/offers.php';

$pdo  = Database::connect();
$prop = isset($argv[1]) && is_numeric($argv[1]) ? (int) $argv[1] : null;
$pic  = $argv[2] ?? '';

$sql = "SELECT id, offer_no, property_id, pic_name, status, start_date, end_date,
               contract_months, electricity_flag, electricity_monthly
          FROM offers
         WHERE (electricity_flag = 1 OR electricity_monthly > 0)"
     . ($prop ? ' AND property_id = ' . $prop : '')
     . ($pic !== '' ? ' AND pic_name = ' . $pdo->quote($pic) : '')
     . ' ORDER BY id DESC';
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$rp = fn($v) => number_format((float) $v, 0, ',', '.');
printf("\n%-5s %-38s %-10s %-9s %6s %12s %14s\n", 'ID', 'NO. PENAWARAN', 'SALES', 'STATUS', 'HARI', 'PER 30 HARI', 'TERCETAK');
echo str_repeat('─', 100), "\n";
foreach ($rows as $r) {
    $hari  = _offer_days($r['start_date'], $r['end_date']);
    $total = offer_listrik($r);
    printf("%-5s %-38s %-10s %-9s %6d %12s %14s\n",
        $r['id'], mb_substr((string) $r['offer_no'], 0, 38), mb_substr((string) $r['pic_name'], 0, 10),
        $r['status'], $hari, $rp($r['electricity_monthly']), $rp($total));
}
echo str_repeat('─', 100), "\n";
echo count($rows) . " penawaran.\n\n";
echo "Kolom PER 30 HARI = yang diketik sales di formulir.\n";
echo "Kolom TERCETAK    = yang muncul di surat (tarif × jumlah satuan 30 hari, dibulatkan, minimal 1).\n";
echo "Kalau TERCETAK terasa kebesaran, biasanya nominal di formulir diisi total, bukan tarif per 30 hari.\n";
echo "Perbaikannya: buka penawarannya, betulkan kotak Biaya Listrik, simpan. Penawaran berstatus DEAL terkunci.\n\n";
