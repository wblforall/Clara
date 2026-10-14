<?php

/**
 * CLARA — telusuri dari mana sebuah SKP mengambil lokasi & luasnya.
 *
 * Dipakai saat dokumen gabungan hanya menampilkan satu lokasi: skrip ini
 * menunjukkan sumbernya apa, berapa komponen yang sebenarnya ada, dan apa yang
 * AKAN ditampilkan formulir — supaya tidak perlu menebak-nebak dari layar.
 *
 * Sekaligus memeriksa apakah berkas di server ini sudah memuat perbaikannya.
 *
 *   php scripts/cek_skp_paket.php --id=200
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('CLARA_ROOT', dirname(__DIR__));
require_once CLARA_ROOT . '/app/env.php';
require_once CLARA_ROOT . '/app/Database.php';

$id = 0;
foreach ($argv as $a) if (preg_match('/^--id=(\d+)$/', $a, $m)) $id = (int) $m[1];
if (!$id) { echo "\nPakai: php scripts/cek_skp_paket.php --id=<nomor SKP>\n"; exit(2); }

$rp  = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
$pdo = Database::connect();

// 0. Apakah berkas di server ini sudah memuat perbaikannya?
$skpFile = @file_get_contents(CLARA_ROOT . '/app/pages/skp.php') ?: '';
$adaPerbaikan = str_contains($skpFile, '_skp_komponen_paket');
echo "\nVersi kode di server ini: "
   . ($adaPerbaikan ? "SUDAH memuat tampilan komponen paket\n"
                    : "BELUM memuat tampilan komponen paket — jalankan: git pull\n");

$st = $pdo->prepare('SELECT * FROM skp_documents WHERE id = ?');
$st->execute([$id]);
$d = $st->fetch(PDO::FETCH_ASSOC);
if (!$d) { echo "SKP #$id tidak ada.\n"; exit(1); }

echo "\n1. Dokumen\n";
printf("   %-18s %s\n", 'Nomor',          $d['skp_no'] ?: '(belum terbit)');
printf("   %-18s %s\n", 'Status',         $d['status']);
printf("   %-18s %s\n", 'offer_id',       $d['offer_id'] ?: '(kosong)');
printf("   %-18s %s\n", 'transaction_id', $d['transaction_id'] ?: '(kosong)');

$sumber = $d['offer_id'] ? 'Surat Penawaran' : ($d['transaction_id'] ? 'Transaksi' : 'dokumen itu sendiri');
echo "   → lokasi & luas diambil dari: $sumber\n";

if (!$d['offer_id']) {
    echo "\n   Dokumen ini BUKAN dari Surat Penawaran, jadi tidak punya komponen paket.\n";
    exit(0);
}

$oq = $pdo->prepare('SELECT * FROM offers WHERE id = ?');
$oq->execute([(int) $d['offer_id']]);
$o = $oq->fetch(PDO::FETCH_ASSOC);
if (!$o) { echo "\n   Penawaran #{$d['offer_id']} TIDAK DITEMUKAN (mungkin terhapus).\n"; exit(1); }

echo "\n2. Penawaran asalnya\n";
printf("   %-18s %s\n", 'Nomor',       $o['offer_no'] ?: '(belum bernomor)');
printf("   %-18s %s\n", 'Status',      $o['status'] . ($o['status'] !== 'deal' ? '  <- SKP hanya membaca penawaran berstatus deal' : ''));
printf("   %-18s %s\n", 'is_bundle',   !empty($o['is_bundle']) ? '1 (paket)' : '0 (bukan paket)');
printf("   %-18s %s\n", 'master_code', $o['master_code'] ?: '(kosong)');
printf("   %-18s %s\n", 'Nilai',       $rp($o['total_calculated']));
echo "   → inilah sebabnya: formulir lama membaca master_code di atas, satu kode saja.\n";

$iq = $pdo->prepare("SELECT oi.*, COALESCE(u.location_name, g.name, m.location) AS lokasi,
                            COALESCE(u.floor, g.location, m.location) AS lantai,
                            COALESCE(u.area_sqm, g.area_sqm) AS luas_master
                       FROM offer_items oi
                       LEFT JOIN master_cl_units u ON u.code = oi.master_code AND u.property_id = ? AND oi.segment = 'cl'
                       LEFT JOIN master_gudang   g ON g.code = oi.master_code AND g.property_id = ? AND oi.segment = 'gudang'
                       LEFT JOIN master_media    m ON m.code = oi.master_code AND m.property_id = ? AND oi.segment = 'media'
                      WHERE oi.offer_id = ? ORDER BY oi.sort_order ASC, oi.id ASC");
$iq->execute([(int) $d['property_id'], (int) $d['property_id'], (int) $d['property_id'], (int) $o['id']]);
$items = $iq->fetchAll(PDO::FETCH_ASSOC);

echo "\n3. Komponen yang sebenarnya tersimpan: " . count($items) . "\n";
if (!$items) {
    echo "   TIDAK ADA satu pun baris di offer_items.\n";
    echo "   Artinya penawaran ini memang tersimpan sebagai satu lokasi — yang perlu\n";
    echo "   diperiksa bukan SKP-nya, melainkan cara penawarannya dibuat.\n";
    exit(0);
}
printf("   %-10s %-28s %-8s %10s %16s\n", 'KODE', 'LOKASI', 'LANTAI', 'LUAS', 'NILAI');
$luas = 0.0; $nilai = 0.0;
foreach ($items as $it) {
    $l = (float) ($it['area_sqm'] > 0 ? $it['area_sqm'] : $it['luas_master']);
    $luas += $l; $nilai += (float) $it['total_amount'];
    printf("   %-10s %-28s %-8s %10s %16s\n",
        $it['master_code'], substr((string) ($it['name_snapshot'] ?: $it['lokasi']), 0, 28),
        $it['lantai'] ?: '-', number_format($l, 2, ',', '.'), $rp($it['total_amount']));
}
printf("   %-48s %10s %16s\n", 'TOTAL', number_format($luas, 2, ',', '.'), $rp($nilai));

echo "\n4. Yang ditampilkan formulir\n";
if (count($items) >= 2 && $adaPerbaikan) {
    echo "   Lokasi     : Paket — " . count($items) . " lokasi\n";
    echo "   Luas Area  : " . number_format($luas, 2, ',', '.') . "\n";
    echo "   + tabel rincian " . count($items) . " komponen di bawah Spesifikasi Tempat & Periode\n";
} elseif (count($items) >= 2) {
    echo "   Lokasi     : " . ($o['master_code'] ?: '-') . "   <- hanya satu, karena kodenya belum diperbarui\n";
    echo "   Jalankan `git pull` di server ini, lalu muat ulang halamannya.\n";
} else {
    echo "   Hanya 1 komponen — memang ditampilkan sebagai satu lokasi, dan itu benar.\n";
}
