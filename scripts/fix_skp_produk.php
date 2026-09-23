<?php
/**
 * Perbaiki kolom "Produk" pada SKP/SKS yang terlanjur terisi KETERANGAN penawaran
 * (atau Materi/Keterangan transaksi) karena prefill lama. Nilai yang benar adalah
 * NAMA BRAND client.
 *
 * Hanya menyentuh baris yang Produk-nya PERSIS sama dengan keterangan sumbernya —
 * isian yang diketik sendiri oleh sales tidak diutak-atik.
 *
 * Jalankan: php scripts/fix_skp_produk.php          (lihat dulu, tidak mengubah)
 *           php scripts/fix_skp_produk.php --apply  (terapkan)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/Database.php';
require_once $root . '/app/env.php';

$apply = in_array('--apply', $argv, true);
$pdo   = Database::connect();

$rows = $pdo->query(
    "SELECT s.id, s.skp_no, s.doc_type, s.status, s.produk, s.snapshot_json,
            COALESCE(c1.brand_name, c2.brand_name) AS brand_name,
            COALESCE(o.keterangan, t.content_note)  AS keterangan
     FROM skp_documents s
     LEFT JOIN offers o        ON o.id = s.offer_id
     LEFT JOIN transactions t  ON t.id = s.transaction_id
     LEFT JOIN master_clients c1 ON c1.id = o.client_id
     LEFT JOIN master_clients c2 ON c2.id = t.client_id
     ORDER BY s.id"
)->fetchAll(PDO::FETCH_ASSOC);

$upd = $pdo->prepare('UPDATE skp_documents SET produk=?, snapshot_json=? WHERE id=?');
$n = 0;

foreach ($rows as $r) {
    $produk = trim((string) $r['produk']);
    $ket    = trim((string) $r['keterangan']);
    $brand  = trim((string) $r['brand_name']);

    if ($produk === '' || $ket === '' || $produk !== $ket) continue;  // bukan korban prefill
    if ($brand === '') { echo "  lewati {$r['skp_no']} — client belum punya nama brand\n"; continue; }

    echo sprintf("  %-18s \"%s\"  →  \"%s\"\n", $r['skp_no'] ?: ('#' . $r['id']), $produk, $brand);
    $n++;
    if (!$apply) continue;

    // Snapshot ikut diperbarui, karena itulah yang dipakai saat mencetak SKP approved.
    $snap = $r['snapshot_json'] ? json_decode((string) $r['snapshot_json'], true) : null;
    if (is_array($snap)) {
        $snap['produk']     = $brand;
        $snap['brand_name'] = $brand;
    }
    $upd->execute([$brand, $snap ? json_encode($snap, JSON_UNESCAPED_UNICODE) : $r['snapshot_json'], $r['id']]);
}

if ($n === 0) {
    echo "Tidak ada SKP yang perlu diperbaiki.\n";
} elseif ($apply) {
    echo "\n$n dokumen diperbaiki.\n";
} else {
    echo "\n$n dokumen akan diperbaiki. Jalankan ulang dengan --apply untuk menerapkan.\n";
}
