<?php
/**
 * Setel Security Deposit & Biaya Listrik pada satu Surat Penawaran —
 * termasuk penanda "sudah dibayarkan" (tetap tercetak, tapi TIDAK menambah
 * Grand Total). Dipakai untuk surat yang sudah terlanjur terbit/DEAL
 * sehingga formulirnya terkunci.
 *
 *   php scripts/set_deposit_penawaran.php                       # daftar penawaran terbaru
 *   php scripts/set_deposit_penawaran.php 15 lunas
 *   php scripts/set_deposit_penawaran.php 15 lunas --listrik=3 --tarif=150000 --apply
 *   php scripts/set_deposit_penawaran.php 15 tagih --listrik=0 --apply
 *
 *   lunas|tagih  status deposit
 *   --listrik=N  jumlah satuan listrik (0 = matikan biaya listrik)
 *   --tarif=RP   tarif listrik per 30 hari (default 150000)
 *
 * Hanya menyentuh surat penawarannya. Nilai transaksi & alokasi bulanan
 * tidak ikut berubah.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/Database.php';
require_once $root . '/app/pages/offers.php';
$pdo = Database::connect();

$rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

/** Grand Total seperti yang tercetak di surat. */
$hitung = function (array $o) use ($rp): array {
    $sewa    = (float) ($o['override_amount'] ?: $o['total_calculated']);
    $listrik = offer_listrik($o);
    $ppn     = round($sewa * 11 / 12 * 0.12) + round($listrik * 11 / 12 * 0.12);
    $dep     = (float) $o['deposit_amount'];
    $lunas   = !empty($o['deposit_paid']);
    return [
        'sewa' => $sewa, 'listrik' => $listrik, 'ppn' => $ppn,
        'setelah_ppn' => $sewa + $listrik + $ppn,
        'deposit' => $dep, 'lunas' => $lunas,
        'grand' => $sewa + $listrik + $ppn + ($lunas ? 0 : $dep),
    ];
};

if (!isset($argv[1])) {
    echo "Surat Penawaran terbaru:\n\n";
    printf("%-5s %-34s %-7s %-16s %-14s %s\n", 'ID', 'NOMOR', 'PAKET', 'DEPOSIT', 'LUNAS?', 'STATUS');
    foreach ($pdo->query('SELECT * FROM offers ORDER BY id DESC LIMIT 20') as $o) {
        printf("%-5s %-34s %-7s %-16s %-14s %s\n", $o['id'], $o['offer_no'] ?: '-',
            $o['is_bundle'] ? 'ya' : '-', $rp($o['deposit_amount']),
            $o['deposit_paid'] ? 'ya' : 'tidak', $o['status']);
    }
    echo "\nPakai: php scripts/set_deposit_penawaran.php <id> <lunas|tagih> [--listrik=N] [--tarif=RP] [--apply]\n";
    exit;
}

$id    = (int) $argv[1];
$mode  = strtolower((string) ($argv[2] ?? 'lunas'));
$apply = in_array('--apply', $argv, true);
if (!in_array($mode, ['lunas', 'tagih'], true)) { fwrite(STDERR, "Mode harus 'lunas' atau 'tagih'.\n"); exit(1); }

$satuan = null; $tarif = null;
foreach ($argv as $a) {
    if (preg_match('/^--listrik=(\d+)$/', $a, $m)) $satuan = (int) $m[1];
    if (preg_match('/^--tarif=(\d+)$/', $a, $m))   $tarif  = (float) $m[1];
}

$st = $pdo->prepare('SELECT * FROM offers WHERE id = ?');
$st->execute([$id]);
$o = $st->fetch();
if (!$o) { fwrite(STDERR, "Penawaran id $id tidak ada.\n"); exit(1); }

$sebelum = $hitung($o);
$baru = $o;
$baru['deposit_paid'] = $mode === 'lunas' ? 1 : 0;
if ($satuan !== null) {
    $baru['electricity_flag']    = $satuan > 0 ? 1 : 0;
    $baru['electricity_units']   = $satuan > 0 ? $satuan : null;
    $baru['electricity_monthly'] = $satuan > 0 ? ($tarif ?? (float) ($o['electricity_monthly'] ?: 150000)) : null;
    $baru['electricity_amount']  = null;   // biar dihitung tarif × satuan
}
$sesudah = $hitung($baru);

echo "Penawaran : #{$o['id']} " . ($o['offer_no'] ?: '(tanpa nomor)') . ($o['is_bundle'] ? ' · PAKET' : '') . "\n";
echo "Status    : {$o['status']}\n";
echo "Periode   : {$o['start_date']} s/d {$o['end_date']}\n\n";
printf("%-22s %18s   %18s\n", '', 'SEKARANG', 'JADI');
foreach ([
    'Sewa'              => 'sewa',
    'Biaya listrik'     => 'listrik',
    'PPN 12%'           => 'ppn',
    'Total setelah PPN' => 'setelah_ppn',
    'Security deposit'  => 'deposit',
] as $lbl => $k) {
    printf("%-22s %18s   %18s\n", $lbl, $rp($sebelum[$k]), $rp($sesudah[$k]));
}
printf("%-22s %18s   %18s\n", 'Deposit sudah dibayar', $sebelum['lunas'] ? 'ya' : 'tidak', $sesudah['lunas'] ? 'ya' : 'tidak');
printf("%-22s %18s   %18s\n", 'GRAND TOTAL', $rp($sebelum['grand']), $rp($sesudah['grand']));
echo "\n";

if (!$apply) { echo "SIMULASI — tidak ada yang diubah. Tambahkan --apply untuk menyimpan.\n"; exit; }

$sql = 'UPDATE offers SET deposit_paid = ?';
$par = [$baru['deposit_paid']];
if ($satuan !== null) {
    $sql .= ', electricity_flag = ?, electricity_units = ?, electricity_monthly = ?, electricity_amount = NULL';
    $par[] = $baru['electricity_flag'];
    $par[] = $baru['electricity_units'];
    $par[] = $baru['electricity_monthly'];
}
$sql .= ' WHERE id = ?';
$par[] = $id;
$pdo->prepare($sql)->execute($par);

$st->execute([$id]);
$cek = $hitung($st->fetch());
echo "TERSIMPAN → Grand Total sekarang " . $rp($cek['grand']) . ($cek['lunas'] ? " (deposit di luar Grand Total)\n" : "\n");
