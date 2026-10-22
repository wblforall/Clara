<?php
/**
 * Lihat pengaturan paraf sebuah dokumen — apa adanya dari snapshotnya.
 *
 * Paraf bisa dipasang dengan dua cara, dan letak salahnya berbeda:
 *
 *   manual   — ditempel pada koordinat milimeter yang dipilih sendiri oleh
 *              pemeriksanya. Kalau tata letak suratnya bergeser, parafnya ikut
 *              meleset karena koordinatnya tidak ikut bergerak.
 *   otomatis — menindih ruang kosong di sebelah QR Manager, ditarik ke atas
 *              dengan margin negatif. Kalau QR-nya belum terbit (dokumen belum
 *              disetujui), tarikan itu melempar parafnya ke ruang kosong.
 *
 * MURNI MELIHAT.
 *
 * Pakai:
 *   php scripts/cek_paraf.php --skp=264
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';

$opt = getopt('', ['skp:']);
$id  = (int) ($opt['skp'] ?? 0);
if ($id <= 0) { fwrite(STDERR, "Pakai: php scripts/cek_paraf.php --skp=264\n"); exit(2); }

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$st = $pdo->prepare('SELECT id, skp_no, doc_type, status, property_id, approval_level,
                            sign_token IS NULL AS tanpa_qr, snapshot_json
                       FROM skp_documents WHERE id = ?');
$st->execute([$id]);
$s = $st->fetch();
if (!$s) { fwrite(STDERR, "Dokumen #$id tidak ada.\n"); exit(1); }

echo "\nPENGATURAN PARAF — dokumen #$id\n";
echo str_repeat('=', 78) . "\n";
printf("  nomor    : %s\n", $s['skp_no'] ?: '(belum terbit)');
printf("  jenis    : %s · status %s · tahap %s\n", $s['doc_type'], $s['status'], $s['approval_level']);
printf("  QR       : %s\n", $s['tanpa_qr'] ? 'BELUM ADA (sign_token kosong)' : 'sudah ada');

$snap = json_decode((string) ($s['snapshot_json'] ?? ''), true);
if (!is_array($snap)) {
    echo "\n  Snapshot belum ada — isinya memang baru dibekukan saat disetujui.\n";
    echo "  Paraf yang tampil di pratinjau diambil langsung dari jejak approval.\n\n";
}

// Jejak approval langsung dari tabelnya — inilah yang dipakai pratinjau untuk
// dokumen yang snapshotnya belum ada.
require_once $root . '/app/ApprovalLine.php';
$jejak = ApprovalLine::jejakBeku($pdo, $id, (string) $s['doc_type']);
$sumber = ($snap['paraf'] ?? null) ?: $jejak;

if (!$sumber) { echo "\n  Tidak ada paraf sama sekali di dokumen ini.\n\n"; exit(0); }

echo "\n  PARAF YANG AKAN TERCETAK\n";
foreach ($sumber as $i => $pf) {
    $set = $pf['paraf'] ?? null;
    printf("  %d) %-22s %-16s aksi %s\n", $i + 1,
        (string) ($pf['nama'] ?? '-'), (string) ($pf['role_name'] ?? '-'), (string) ($pf['action'] ?? '-'));
    if (!$set) { echo "       (orang ini belum menyimpan gambar parafnya)\n"; continue; }
    printf("       mode   : %s\n", $set['mode'] ?? 'manual');
    printf("       posisi : x=%s mm · y=%s mm · lebar=%s mm\n",
        $set['pos_x'] ?? '-', $set['pos_y'] ?? '-', $set['lebar'] ?? '-');
    printf("       berkas : %s\n", $set['path'] ?? $set['file'] ?? '(tidak tercatat)');
}
echo "\n  Artinya:\n";
$adaManual = false;
foreach ($sumber as $pf) {
    $set = $pf['paraf'] ?? null;
    if ($set && ($set['mode'] ?? 'manual') === 'manual') $adaManual = true;
}
echo $adaManual
    ? "  Ada paraf MANUAL — letaknya mengikuti koordinat di atas, bukan kolom tanda tangan.\n"
    : "  Semua paraf OTOMATIS — letaknya menempel di sebelah QR Manager.\n";
if ($s['tanpa_qr'] && !$adaManual) {
    echo "  QR Manager belum terbit, jadi paraf otomatis tidak punya tumpuan.\n";
}
echo "\n";
