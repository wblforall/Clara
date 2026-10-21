<?php
/**
 * Tembak langsung satu penawaran: pasang Harga Nego Final sebagai nilai kontrak.
 *
 * Tambalan untuk baris yang terlanjur salah. BUKAN pengganti pembaruan kode —
 * kalau kodenya belum diperbarui, penyimpanan berikutnya akan merusaknya lagi.
 *
 * Aman: hanya menyentuh tabel offers, dan hanya penawaran yang BELUM deal
 * (belum melahirkan transaksi maupun alokasi income).
 *
 * Pakai: php betulkan_nego.php <id_penawaran> <nilai_nego>
 *        php betulkan_nego.php 83 54000000
 */
$root = dirname(__DIR__);
foreach ([__DIR__, getcwd()] as $c) if (is_file("$c/app/env.php")) { $root = $c; break; }
require_once "$root/app/env.php";
require_once "$root/app/Database.php";

$id   = (int) ($argv[1] ?? 0);
$nego = (float) preg_replace('/[^0-9]/', '', (string) ($argv[2] ?? '0'));
if ($id <= 0 || $nego <= 0) { fwrite(STDERR, "Pakai: php betulkan_nego.php <id> <nilai>\n"); exit(2); }

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

$o = $pdo->query("SELECT * FROM offers WHERE id = $id")->fetch();
if (!$o) { fwrite(STDERR, "Penawaran #$id tidak ada.\n"); exit(1); }
if (in_array($o['status'], ['deal', 'cancelled'], true)) {
    fwrite(STDERR, "Penawaran #$id berstatus {$o['status']} — terkunci, tidak disentuh.\n"); exit(1);
}

// Bulan kontrak: dipakai menghitung harga/bulan, cara yang sama dengan aplikasi.
$bulan = (int) ($o['contract_months'] ?? 0);
if ($bulan < 1 && $o['start_date'] && $o['end_date']) {
    $a = new DateTime($o['start_date']); $b = new DateTime($o['end_date']);
    $bulan = max(1, (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400 / 30.4));
}
$bulan   = max(1, $bulan);
$perBulan = round($nego / $bulan);

echo "SEBELUM\n";
printf("  %-18s %s\n", 'offer_no',        $o['offer_no']);
printf("  %-18s %s\n", 'status',          $o['status']);
printf("  %-18s %s\n", 'total_calculated', $rp($o['total_calculated']));
printf("  %-18s %s\n", 'override_amount',  $rp($o['override_amount']));
printf("  %-18s %s\n", 'monthly_amount',   $rp($o['monthly_amount']));

$st = $pdo->prepare('UPDATE offers
       SET override_amount = ?, total_calculated = ?, monthly_amount = ?
     WHERE id = ? AND status NOT IN (\'deal\', \'cancelled\')');
$st->execute([$nego, $nego, $perBulan, $id]);

$o2 = $pdo->query("SELECT total_calculated, override_amount, monthly_amount FROM offers WHERE id = $id")->fetch();
echo "SESUDAH  (baris berubah: " . $st->rowCount() . ", {$bulan} bulan)\n";
printf("  %-18s %s\n", 'total_calculated', $rp($o2['total_calculated']));
printf("  %-18s %s\n", 'override_amount',  $rp($o2['override_amount']));
printf("  %-18s %s\n", 'monthly_amount',   $rp($o2['monthly_amount']));
