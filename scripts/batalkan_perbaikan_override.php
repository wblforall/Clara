<?php

/**
 * CLARA — kembalikan Override Aktual yang terlanjur diubah skrip perbaikan.
 *
 * Skrip cek_override_vs_final.php --terapkan menyamakan override_amount ke
 * nilai kontrak pada SEMUA transaksi yang melenceng, dan tidak mencatat apa pun
 * di Activity Log. Itu keliru: cakupannya terlalu luas untuk satu pertanyaan,
 * dan tanpa catatan tidak ada jejak nilai lamanya.
 *
 * Nilai lamanya masih bisa diambil: setiap pengeditan transaksi lewat layar
 * mencatat seluruh isi barisnya di audit_logs (after_json). Karena jalur yang
 * bermasalah hanya mengubah final_amount dan TIDAK pernah menyentuh
 * override_amount, nilai override di catatan terakhir itulah yang ada di kolom
 * sebelum skrip berjalan.
 *
 * Yang TIDAK ada catatannya tidak ditebak — dilaporkan apa adanya.
 *
 * Tidak ada nilai kontrak maupun alokasi yang disentuh di sini.
 *
 *   php scripts/batalkan_perbaikan_override.php              # laporan saja
 *   php scripts/batalkan_perbaikan_override.php --kecuali=2062
 *   php scripts/batalkan_perbaikan_override.php --terapkan
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('CLARA_ROOT', dirname(__DIR__));
require_once CLARA_ROOT . '/app/env.php';
require_once CLARA_ROOT . '/app/Database.php';
require_once CLARA_ROOT . '/app/helpers.php';

const PENANDA = 'skrip perbaikan override';

$terapkan = in_array('--terapkan', $argv, true);
$kecuali  = [];
foreach ($argv as $a) if (preg_match('/^--kecuali=([\d,]+)$/', $a, $m)) $kecuali = array_map('intval', explode(',', $m[1]));

$rp  = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
$pdo = Database::connect();

$q = $pdo->prepare('SELECT id, module, master_code, total_calculated, override_amount, final_amount, pic_name
                      FROM transactions WHERE updated_by = ? ORDER BY id');
$q->execute([PENANDA]);
$baris = $q->fetchAll(PDO::FETCH_ASSOC);

if (!$baris) {
    echo "\nTidak ada transaksi bertanda '" . PENANDA . "'. Tidak ada yang perlu dikembalikan.\n";
    exit(0);
}

// Nilai override terakhir yang tercatat di Activity Log, beserta siapa yang
// menyimpannya — dipakai juga untuk mengembalikan kolom "diedit oleh".
$cari = $pdo->prepare("SELECT after_json, user_name FROM audit_logs
                        WHERE table_name = 'transactions' AND record_id = ? AND action IN ('update','create')
                        ORDER BY id DESC LIMIT 12");

$bisa = $tidak = [];
foreach ($baris as $b) {
    if (in_array((int) $b['id'], $kecuali, true)) continue;
    $cari->execute([(string) $b['id']]);
    $lama = null; $olehSiapa = null;
    foreach ($cari->fetchAll(PDO::FETCH_ASSOC) as $log) {
        $j = json_decode((string) $log['after_json'], true);
        if (!is_array($j) || !array_key_exists('override_amount', $j)) continue;
        $lama      = $j['override_amount'];
        $olehSiapa = $log['user_name'];
        break;
    }
    if ($lama === null) { $tidak[] = $b; continue; }
    // Sudah sama dengan catatan → tidak perlu disentuh.
    if (abs((float) $lama - (float) $b['override_amount']) < 1) continue;
    $b['override_lama'] = (float) $lama;
    $b['oleh']          = $olehSiapa;
    $bisa[] = $b;
}

printf("\n%-7s %-9s %-11s %16s %16s %16s  %s\n",
    'ID', 'MODUL', 'KODE', 'OVERRIDE KINI', 'OVERRIDE LAMA', 'NILAI KONTRAK', 'TERAKHIR DIEDIT');
echo str_repeat('-', 104) . "\n";
foreach ($bisa as $b) {
    printf("%-7s %-9s %-11s %16s %16s %16s  %s\n", '#' . $b['id'], $b['module'],
        substr((string) $b['master_code'], 0, 11), $rp($b['override_amount']),
        $rp($b['override_lama']), $rp($b['final_amount']), $b['oleh'] ?? '-');
}
foreach ($tidak as $b) {
    printf("%-7s %-9s %-11s %16s %16s %16s  tidak ada catatan\n", '#' . $b['id'], $b['module'],
        substr((string) $b['master_code'], 0, 11), $rp($b['override_amount']), '-', $rp($b['final_amount']));
}

echo "\n" . count($baris) . " transaksi pernah disentuh skrip. "
   . count($bisa) . " bisa dikembalikan dari Activity Log, "
   . count($tidak) . " tidak ada catatannya"
   . ($kecuali ? ', ' . count($kecuali) . ' sengaja dilewati (' . implode(', ', array_map(fn($i) => '#' . $i, $kecuali)) . ')' : '')
   . ".\n";

if (!$terapkan) {
    echo "\nLaporan saja. Untuk mengembalikan:\n";
    echo "  php scripts/batalkan_perbaikan_override.php --terapkan\n";
    exit(0);
}

// Kali ini perubahannya DICATAT. Skrip yang mengubah data tanpa jejak adalah
// sebab kenapa pembatalan ini perlu ditulis sama sekali.
$_SESSION['user'] = ['id' => null, 'name' => 'skrip batalkan override', 'email' => 'cli', 'role' => 'system'];
$upd = $pdo->prepare('UPDATE transactions SET override_amount = ?, updated_by = ? WHERE id = ?');
$n = 0;
foreach ($bisa as $b) {
    $upd->execute([$b['override_lama'], $b['oleh'] ?: null, (int) $b['id']]);
    $n += $upd->rowCount();
    audit($pdo, 'update', 'transactions', (string) $b['id'],
        ['override_amount' => $b['override_lama'], 'alasan' => 'pembatalan skrip perbaikan override'],
        ['override_amount' => (float) $b['override_amount']]);
}
echo "\n$n transaksi dikembalikan ke nilai override sebelumnya, dan kali ini tercatat di Activity Log.\n";
echo "Nilai kontrak & alokasi tidak disentuh — income tidak berubah sepeser pun.\n";
if ($tidak) {
    echo count($tidak) . " dilewati karena tidak ada catatannya: "
       . implode(', ', array_map(fn($b) => '#' . $b['id'], $tidak)) . "\n";
}
