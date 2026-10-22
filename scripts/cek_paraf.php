<?php
/**
 * Lihat pengaturan paraf sebuah dokumen — apa adanya dari snapshotnya.
 *
 * Paraf bisa dipasang dengan dua cara, dan letak salahnya berbeda:
 *
 *   manual   — ditempel pada koordinat milimeter yang dipilih sendiri oleh
 *              pemeriksanya. Kalau tata letak suratnya bergeser, parafnya ikut
 *              meleset karena koordinatnya tidak ikut bergerak.
 *   otomatis — dicetak pada baris "Diperiksa sebelum disetujui" di bawah blok
 *              tanda tangan, sebaris dengan nama pemeriksanya. Tidak pernah
 *              masuk ke kolom tanda tangan, jadi tidak bisa terbaca sebagai
 *              tanda tangan Manager maupun client.
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
    $mode = $set['mode'] ?? 'manual';
    printf("       mode   : %s\n", $mode);
    // Koordinat hanya berlaku di mode manual. Mencetaknya untuk paraf otomatis
    // membuat orang mengira parafnya bisa digeser dari situ — padahal letaknya
    // ditentukan dokumen.
    if ($mode === 'manual') {
        printf("       posisi : x=%s mm · y=%s mm · lebar=%s mm\n",
            $set['pos_x'] ?? '-', $set['pos_y'] ?? '-', $set['lebar'] ?? '-');
    } else {
        printf("       ukuran : %s mm (sisi terpanjang)\n", $set['lebar'] ?? '-');
    }
    printf("       berkas : %s\n", $set['gambar_path'] ?? $set['teks'] ?? '(tidak tercatat)');
}
echo "\n  Artinya:\n";
$adaManual = false;
foreach ($sumber as $pf) {
    $set = $pf['paraf'] ?? null;
    if ($set && ($set['mode'] ?? 'manual') === 'manual') $adaManual = true;
}
echo $adaManual
    ? "  Ada paraf MANUAL — letaknya mengikuti koordinat di atas, bukan kolom tanda tangan.\n"
    : "  Semua paraf OTOMATIS — tercetak di baris \"Diperiksa sebelum disetujui\".\n";
echo "\n";
