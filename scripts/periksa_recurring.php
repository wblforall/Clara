<?php
/**
 * Periksa (dan perbaiki) transaksi bulanan yang alokasi recurring-nya terbagi
 * salah — nilainya terbelah rata ke tiap bulan kalender, bukan satu nilai utuh
 * per siklus bulanan.
 *
 * Penyebabnya: Pricing Type tersimpan sebagai daily_* padahal kontraknya
 * bulanan. Dengan pricing harian, "Spread per Bulan" membagi nilai menurut
 * jumlah HARI di tiap bulan kalender; dengan pricing monthly, tiap siklus
 * diakui utuh di bulan awal siklusnya.
 *
 *   php scripts/periksa_recurring.php                 # cari transaksi yang kena
 *   php scripts/periksa_recurring.php 1982            # periksa satu transaksi
 *   php scripts/periksa_recurring.php 1982 --perbaiki           # simulasi
 *   php scripts/periksa_recurring.php 1982 --perbaiki --apply   # simpan
 *
 * Perbaikan: pricing_type → monthly, unit_rate diisi nilai per siklus,
 * lalu alokasi bulanan disusun ulang. Nilai kontrak tidak diubah.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/Database.php';
require_once $root . '/app/AllocationService.php';
$pdo = Database::connect();

$rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

/** Jumlah siklus bulanan dalam satu periode. */
$siklus = function (array $t): int {
    return (int) AllocationService::totalCalculated([
        'pricing_type' => 'monthly', 'unit_rate' => 1, 'quantity' => 1, 'slots' => 1,
        'area_sqm' => 0, 'start_date' => $t['start_date'], 'end_date' => $t['end_date'],
        'contract_months' => null,
    ]);
};

/** Tarif master unit (untuk pembanding). */
$tarifMaster = function (PDO $pdo, array $t): ?float {
    $tabel = ['gudang' => ['master_gudang', 'monthly_rate'], 'cl' => ['master_cl_units', 'rate'], 'media' => ['master_media', 'rate']];
    if (!isset($tabel[$t['module']])) return null;
    [$tb, $kol] = $tabel[$t['module']];
    $q = $pdo->prepare("SELECT `$kol` FROM `$tb` WHERE code = ? AND property_id = ? LIMIT 1");
    $q->execute([$t['master_code'], $t['property_id']]);
    $v = $q->fetchColumn();
    return $v === false ? null : (float) $v;
};

$alokasi = function (PDO $pdo, int $id): array {
    $q = $pdo->prepare('SELECT period_key, SUM(amount) amt FROM transaction_allocations WHERE transaction_id = ? GROUP BY period_key ORDER BY period_key');
    $q->execute([$id]);
    return $q->fetchAll();
};

// ── Mode daftar: cari yang mencurigakan ─────────────────────────────────────
if (!isset($argv[1])) {
    echo "Transaksi 'spread' yang pricing-nya harian padahal periodenya lintas bulan:\n\n";
    printf("%-6s %-8s %-10s %-26s %-11s %-16s %s\n", 'ID', 'MODUL', 'KODE', 'PERIODE', 'PRICING', 'NILAI', 'BULAN');
    $q = $pdo->query(
        "SELECT t.*, c.company_name FROM transactions t
           LEFT JOIN master_clients c ON c.id = t.client_id
          WHERE t.deleted_at IS NULL AND t.billing_method = 'spread'
            AND t.pricing_type <> 'monthly'
            AND DATE_FORMAT(t.start_date,'%Y-%m') <> DATE_FORMAT(t.end_date,'%Y-%m')
          ORDER BY t.id DESC LIMIT 40"
    );
    $n = 0;
    foreach ($q as $t) {
        $sik = $siklus($t);
        if ($sik < 2) continue;
        $final = (float) ($t['final_amount'] ?: $t['total_calculated']);
        $tarif = $tarifMaster($pdo, $t);
        // Tanda kuat: nilai kontrak pas habis dibagi tarif master.
        $pas = $tarif && $tarif > 0 && abs($final / $tarif - round($final / $tarif)) < 0.01;
        if (!$pas) continue;
        $n++;
        printf("%-6s %-8s %-10s %-26s %-11s %-16s %s\n", '#' . $t['id'], $t['module'], $t['master_code'],
            $t['start_date'] . '→' . $t['end_date'], $t['pricing_type'], $rp($final),
            count($alokasi($pdo, (int) $t['id'])) . ' (seharusnya ' . $sik . ')');
    }
    echo "\n" . ($n ? "$n transaksi perlu diperiksa.\n" : "Tidak ada yang mencurigakan.\n");
    echo "Periksa satu: php scripts/periksa_recurring.php <id>\n";
    exit;
}

// ── Mode satu transaksi ─────────────────────────────────────────────────────
$id       = (int) $argv[1];
$perbaiki = in_array('--perbaiki', $argv, true);
$apply    = in_array('--apply', $argv, true);

$st = $pdo->prepare('SELECT t.*, c.company_name FROM transactions t LEFT JOIN master_clients c ON c.id = t.client_id WHERE t.id = ?');
$st->execute([$id]);
$t = $st->fetch();
if (!$t) { fwrite(STDERR, "Transaksi #$id tidak ada.\n"); exit(1); }

$sik   = $siklus($t);
$final = (float) ($t['final_amount'] ?: $t['total_calculated']);
$tarif = $tarifMaster($pdo, $t);
$kini  = $alokasi($pdo, $id);
$perSiklus = $sik > 0 ? $final / $sik : 0.0;

echo "Transaksi : #{$t['id']} {$t['master_code']} · {$t['company_name']} · PIC {$t['pic_name']}\n";
echo "Modul     : {$t['module']} · dibuat {$t['created_by']} {$t['created_at']}\n";
echo "Periode   : {$t['start_date']} s/d {$t['end_date']}  → $sik siklus bulanan\n";
echo "Pricing   : {$t['pricing_type']} · rate " . $rp($t['unit_rate'])
   . " · metode {$t['billing_method']}/{$t['cycle_recognition']}" . ($t['recurring_flag'] ? ' · recurring' : '') . "\n";
echo "Nilai     : " . $rp($final) . ($t['override_amount'] !== null ? ' (override)' : ' (kalkulasi)') . "\n";
echo "Tarif unit: " . ($tarif === null ? '(tidak ketemu di master)' : $rp($tarif)) . "\n\n";

echo "Alokasi sekarang (" . count($kini) . " bulan):\n";
foreach ($kini as $r) printf("   %-9s %s\n", $r['period_key'], $rp($r['amt']));
echo "   TOTAL     " . $rp(array_sum(array_column($kini, 'amt'))) . "\n\n";

// ── Vonis ───────────────────────────────────────────────────────────────────
$salah = $t['pricing_type'] !== 'monthly' && $sik >= 2 && $t['billing_method'] === 'spread';
echo "VONIS:\n";
if ($salah) {
    echo "  Pricing Type tersimpan '{$t['pricing_type']}' padahal kontraknya bulanan ($sik siklus).\n";
    echo "  Karena pricing harian, nilai kontrak dibagi menurut jumlah HARI tiap bulan kalender —\n";
    echo "  itu sebabnya tiap bulan dapat sekitar " . $rp($final / max(1, count($kini))) . ",\n";
    echo "  bukan " . $rp($perSiklus) . " per siklus.\n";
    if ($tarif && abs($perSiklus - $tarif) < 1) {
        echo "  Nilai kontraknya sendiri SUDAH BENAR: " . $rp($final) . " = $sik × tarif unit " . $rp($tarif) . ".\n";
    }
    echo "  → Ini salah sistem (form Gudang dulu memilih pricing harian secara bawaan), bukan salah input.\n";
} else {
    echo "  Pricing Type sudah 'monthly' / periode tidak lintas siklus — pembagiannya wajar.\n";
}
echo "\n";
if (!$perbaiki) {
    if ($salah) echo "Perbaiki: php scripts/periksa_recurring.php $id --perbaiki   (tambah --apply untuk menyimpan)\n";
    exit;
}

// ── Perbaikan ───────────────────────────────────────────────────────────────
$pdo->beginTransaction();
$pdo->prepare("UPDATE transactions SET pricing_type='monthly', unit_rate=?, cycle_recognition='cycle_start',
               updated_at=CURRENT_TIMESTAMP, updated_by=? WHERE id=?")
    ->execute([round($perSiklus, 2), 'skrip:periksa_recurring', $id]);
$st->execute([$id]);
AllocationService::saveAllocations($pdo, $id, $st->fetch());
$hasil = $alokasi($pdo, $id);

echo "Alokasi setelah perbaikan (" . count($hasil) . " bulan):\n";
foreach ($hasil as $r) printf("   %-9s %s\n", $r['period_key'], $rp($r['amt']));
echo "   TOTAL     " . $rp(array_sum(array_column($hasil, 'amt'))) . "\n\n";

if (!$apply) { $pdo->rollBack(); echo "SIMULASI — tidak ada yang diubah. Tambahkan --apply untuk menyimpan.\n"; exit; }
$pdo->commit();
echo "TERSIMPAN → pricing_type=monthly, rate " . $rp($perSiklus) . " per siklus.\n";
