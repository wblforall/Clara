<?php

/**
 * CLARA — telusuri dari mana sebuah SKP / Surat Penawaran mengambil lokasi & luasnya.
 *
 * Dipakai saat dokumen gabungan hanya menampilkan satu lokasi: skrip ini
 * menunjukkan sumbernya apa, berapa komponen yang sebenarnya ada, dan apa yang
 * AKAN ditampilkan formulir, surat cetak, dan dokumen SKP — supaya tidak perlu
 * menebak-nebak dari layar.
 *
 * Sekaligus memeriksa apakah berkas di server ini sudah memuat perbaikannya.
 *
 *   php scripts/cek_skp_paket.php --penawaran=019     # dicari dari nomor penawaran
 *   php scripts/cek_skp_paket.php --id=200            # kalau nomor SKP-nya sudah tahu
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('CLARA_ROOT', dirname(__DIR__));
require_once CLARA_ROOT . '/app/env.php';
require_once CLARA_ROOT . '/app/Database.php';

$id = 0; $cari = '';
foreach ($argv as $a) {
    if (preg_match('/^--id=(\d+)$/', $a, $m))     $id   = (int) $m[1];
    if (preg_match('/^--penawaran=(.+)$/', $a, $m)) $cari = trim($m[1]);
}
if (!$id && $cari === '') {
    echo "\nPakai salah satu:\n";
    echo "  php scripts/cek_skp_paket.php --penawaran=019     (penggalan nomor penawaran)\n";
    echo "  php scripts/cek_skp_paket.php --id=200            (nomor urut dokumen SKP)\n\n";
    exit(2);
}

$rp  = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
$pdo = Database::connect();

// 0. Apakah berkas di server ini sudah memuat perbaikannya?
$berkas = fn(string $f) => @file_get_contents(CLARA_ROOT . '/app/pages/' . $f) ?: '';
$adaPerbaikan = str_contains($berkas('skp.php'), '_skp_komponen_paket');
// Surat cetak & halaman TTD punya perbaikannya sendiri: keduanya dulu bertanya
// ke penanda is_bundle, jadi surat paket tercetak satu baris.
$adaCetak    = str_contains($berkas('offer_print_template.php'), 'count($items) >= 2');
$adaSkpCetak = str_contains($berkas('skp_print_body.php'), 'bundle_susulan');
echo "\nVersi kode di server ini\n";
printf("   %-24s %s\n", 'Formulir SKP',          $adaPerbaikan ? 'SUDAH' : 'BELUM — jalankan git pull');
printf("   %-24s %s\n", 'Surat Penawaran cetak', $adaCetak     ? 'SUDAH' : 'BELUM — jalankan git pull');
printf("   %-24s %s\n", 'Dokumen SKP cetak',     $adaSkpCetak  ? 'SUDAH' : 'BELUM — jalankan git pull');

/** Komponen penawaran + lantai & luas dari master (sama seperti yang dipakai halamannya). */
$ambilKomponen = function (PDO $pdo, int $offerId, int $pid) {
    $q = $pdo->prepare("SELECT oi.*, COALESCE(u.location_name, g.name, m.location) AS lokasi,
                               COALESCE(u.floor, g.location, m.location) AS lantai,
                               COALESCE(u.area_sqm, g.area_sqm) AS luas_master
                          FROM offer_items oi
                          LEFT JOIN master_cl_units u ON u.code = oi.master_code AND u.property_id = ? AND oi.segment = 'cl'
                          LEFT JOIN master_gudang   g ON g.code = oi.master_code AND g.property_id = ? AND oi.segment = 'gudang'
                          LEFT JOIN master_media    m ON m.code = oi.master_code AND m.property_id = ? AND oi.segment = 'media'
                         WHERE oi.offer_id = ? ORDER BY oi.sort_order ASC, oi.id ASC");
    $q->execute([$pid, $pid, $pid, $offerId]);
    return $q->fetchAll(PDO::FETCH_ASSOC);
};

// ── Dicari dari nomor penawaran ─────────────────────────────────────────────
if ($cari !== '') {
    $oq = $pdo->prepare('SELECT * FROM offers WHERE offer_no LIKE ? ORDER BY id DESC');
    $oq->execute(['%' . $cari . '%']);
    $kandidat = $oq->fetchAll(PDO::FETCH_ASSOC);
    if (!$kandidat) { echo "\nTidak ada penawaran yang nomornya memuat \"$cari\".\n"; exit(1); }
    if (count($kandidat) > 1) {
        echo "\nAda " . count($kandidat) . " penawaran yang cocok — pilih salah satu lalu ulangi\n";
        echo "dengan penggalan yang lebih panjang:\n";
        foreach ($kandidat as $k) printf("   #%-6s %s  (%s)\n", $k['id'], $k['offer_no'], $k['status']);
        exit(2);
    }
    $o = $kandidat[0];
    $dq = $pdo->prepare('SELECT * FROM skp_documents WHERE offer_id = ? ORDER BY id DESC LIMIT 1');
    $dq->execute([(int) $o['id']]);
    $d = $dq->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($d) { $id = (int) $d['id']; }
    else {
        echo "\n1. Penawaran " . $o['offer_no'] . " belum punya dokumen SKP.\n";
        $pid = (int) $o['property_id'];
    }
} else {
    $d = null;
}

// ── Dicari dari nomor dokumen SKP ───────────────────────────────────────────
if ($id) {
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
    $pid = (int) $d['property_id'];
    $oq2 = $pdo->prepare('SELECT * FROM offers WHERE id = ?');
    $oq2->execute([(int) $d['offer_id']]);
    $o = $oq2->fetch(PDO::FETCH_ASSOC);
    if (!$o) { echo "\n   Penawaran #{$d['offer_id']} TIDAK DITEMUKAN (mungkin terhapus).\n"; exit(1); }
}

echo "\n2. Penawaran asalnya\n";
printf("   %-18s %s\n", 'Nomor',       $o['offer_no'] ?: '(belum bernomor)');
printf("   %-18s %s\n", 'Status',      $o['status'] . ($o['status'] !== 'deal' ? '  <- SKP hanya membaca penawaran berstatus deal' : ''));
printf("   %-18s %s\n", 'module',      $o['module']);
printf("   %-18s %s\n", 'is_bundle',   !empty($o['is_bundle']) ? '1 (paket)' : '0 (bukan paket)');
printf("   %-18s %s\n", 'master_code', $o['master_code'] ?: '(kosong)');
printf("   %-18s %s\n", 'Nilai',       $rp($o['override_amount'] ?: $o['total_calculated']));
echo "   → inilah sebabnya: kode lama membaca master_code di atas, satu kode saja.\n";

$items = $ambilKomponen($pdo, (int) $o['id'], $pid);

echo "\n3. Komponen yang sebenarnya tersimpan: " . count($items) . "\n";
if (!$items) {
    echo "   TIDAK ADA satu pun baris di offer_items.\n\n";
    echo "   Artinya penawaran ini TERSIMPAN sebagai satu lokasi (" . ($o['master_code'] ?: '-') . "),\n";
    echo "   bukan sebagai paket. Perbaikan tampilan tidak akan memunculkan lokasi\n";
    echo "   yang memang tidak ada datanya — yang perlu diperbaiki penawarannya.\n\n";
    echo "   Cara memperbaiki (penawaran berstatus deal terkunci, minta admin buka dulu):\n";
    echo "     1. Buka Surat Penawaran " . ($o['offer_no'] ?: '#' . $o['id']) . " → Edit\n";
    echo "     2. Centang \"Penawaran Paket (gabungan beberapa booth/titik media)\"\n";
    echo "     3. Masukkan SEMUA lokasinya beserta nilainya, lalu Simpan\n";
    echo "     4. Jalankan lagi perintah ini — komponennya harus terbaca\n";
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

if ($d) {
    echo "\n4. Yang ditampilkan formulir SKP\n";
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
}

echo "\n5. Yang ditampilkan Surat Penawaran & dokumen cetaknya\n";
if (count($items) < 2) {
    echo "   Satu lokasi — tidak ada yang perlu dirinci.\n";
    exit(0);
}
$nilaiOffer = (float) ($o['override_amount'] ?: $o['total_calculated']);
printf("   %-24s %s\n", 'Badge di daftar', 'Paket · ' . count($items) . ' lokasi'
    . ($o['module'] !== 'bundle' ? "   (kolom module masih '" . $o['module'] . "' — tidak dipakai lagi untuk badge)" : ''));
printf("   %-24s %s\n", 'Surat cetak', $adaCetak
    ? count($items) . ' baris lokasi + baris TOTAL'
    : '1 baris saja — berkasnya belum diperbarui (git pull)');
printf("   %-24s %s\n", 'Dokumen SKP cetak', $adaSkpCetak
    ? 'Lokasi "Paket — ' . count($items) . ' lokasi", luas ' . number_format($luas, 2, ',', '.') . ' m², + tabel Komponen Paket'
    : '1 lokasi saja — berkasnya belum diperbarui (git pull)');

if (abs($nilaiOffer - $nilai) >= 1) {
    echo "\n   Catatan: jumlah daftar harga tiap lokasi " . $rp($nilai) . ", sedangkan nilai\n";
    echo "   penawaran yang disepakati " . $rp($nilaiOffer) . " (selisih " . $rp(abs($nilaiOffer - $nilai)) . ").\n";
    echo "   Selisih itu DICETAK sebagai baris sendiri (\"Penyesuaian harga paket\"),\n";
    echo "   jadi angka di surat tetap bisa dijumlah sendiri oleh client.\n";
}
if (empty($o['is_bundle'])) {
    echo "\n   Penanda is_bundle penawaran ini 0 padahal komponennya " . count($items) . ".\n";
    echo "   Tampilan sudah tidak bergantung penanda itu, jadi surat & dokumennya benar.\n";
    echo "   Yang MASIH ikut penanda hanyalah penerbitan transaksi saat SKP disetujui\n";
    echo "   (1 transaksi, bukan " . count($items) . ") — itu uang, jadi tidak diubah diam-diam.\n";
}
