<?php

/**
 * Harga Publish & Diskon pada Surat Penawaran dan SKP.
 *
 * Permintaan tim Casual Leasing dan Legal: client, legal, dan finance harus bisa
 * MELIHAT di dokumennya sendiri bahwa harga yang diberikan adalah harga diskon —
 * bukan hanya tahu angka akhirnya. Selama ini diskon hanya hidup di kepala sales
 * dan di percakapan; begitu dokumen diarsipkan, tidak ada jejak bahwa harga
 * publishnya pernah lebih tinggi.
 *
 * Yang disimpan hanya HARGA PUBLISH (harga sebelum diskon) dan satu saklar.
 * Besar diskon TIDAK disimpan, melainkan dihitung saat dicetak:
 *
 *     diskon        = harga_publish - harga akhir yang sudah ada
 *     diskon persen = diskon / harga_publish * 100
 *
 * Disengaja begitu supaya harga akhir tidak pernah bergeser gara-gara fitur ini:
 * seluruh perhitungan biaya, PPN, deposit, dan Grand Total tetap apa adanya.
 * Kalau besar diskon ikut disimpan, suatu saat dua angka itu akan berbeda dan
 * dokumen akan menyebut potongan yang tidak sesuai dengan totalnya sendiri.
 *
 * Nilai bawaannya 0 dan mati, jadi SELURUH dokumen lama tercetak persis seperti
 * sebelumnya — barisnya hanya muncul bila saklarnya dinyalakan DAN harga
 * publishnya terisi lebih besar daripada harga akhir.
 *
 * @var PDO $pdo
 */

$kolom = [
    'offers' => [
        'harga_publish' => "DECIMAL(18,2) NOT NULL DEFAULT 0 COMMENT 'harga sebelum diskon; 0 = tidak dipakai'",
        'diskon_aktif'  => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'tampilkan baris harga publish & diskon di surat'",
    ],
    // Dokumen SKP menyimpan isinya sendiri di skp_documents — inilah yang dibaca
    // saat dokumen disetujui dan dibekukan ke snapshot_json. Tabel transactions
    // ikut diberi kolom yang sama supaya angkanya tetap terbawa saat SKP
    // diposting jadi transaksi, dan bisa dipakai laporan di kemudian hari.
    'skp_documents' => [
        'harga_publish' => "DECIMAL(18,2) NULL DEFAULT NULL COMMENT 'harga sebelum diskon; kosong = tidak dipakai'",
        'diskon_aktif'  => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'tampilkan baris harga publish & diskon di SKP'",
    ],
    'transactions' => [
        'harga_publish' => "DECIMAL(18,2) NOT NULL DEFAULT 0 COMMENT 'harga sebelum diskon; 0 = tidak dipakai'",
        'diskon_aktif'  => "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'tampilkan baris harga publish & diskon di SKP'",
    ],
];

foreach ($kolom as $tabel => $daftar) {
    $ada = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tabel))->fetchColumn();
    if (!$ada) { echo "     $tabel tidak ada — dilewati\n"; continue; }

    foreach ($daftar as $nama => $tipe) {
        $punya = $pdo->query("SHOW COLUMNS FROM `$tabel` LIKE " . $pdo->quote($nama))->fetchColumn();
        if ($punya) { echo "     $tabel.$nama sudah ada\n"; continue; }
        $pdo->exec("ALTER TABLE `$tabel` ADD COLUMN `$nama` $tipe");
        echo "     $tabel.$nama ditambahkan\n";
    }
}
