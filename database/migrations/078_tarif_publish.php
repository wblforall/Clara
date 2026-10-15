<?php

/**
 * Harga publish disimpan sebagai TARIF, bukan total.
 *
 * Migrasi 077 menyimpan harga publish sebagai TOTAL, dan cara itu terbukti
 * keliru pada dokumen yang menagih listrik: harga publish (sewa saja)
 * dibandingkan dengan Total Biaya Sewa yang SUDAH memuat listrik, sehingga
 * diskonnya tercetak jauh lebih kecil daripada kenyataannya. Contoh nyata
 * SKP/EW tarif Rp 145.000 dengan pengali 186: diskon tercetak 3,72% padahal
 * dari tarifnya 12,12% — selisihnya persis sebesar biaya listriknya.
 *
 * Dengan tarif, perhitungannya memakai pengali yang sama dengan nilai sewanya
 * sendiri, jadi tidak pernah ada sisa pembulatan:
 *
 *     pengali = nilai sewa / tarif
 *     publish = tarif publish x pengali
 *     diskon  = (tarif publish - tarif) x pengali
 *
 * Kolom harga_publish dari migrasi 077 SENGAJA tidak dibuang: dokumen yang
 * sudah terlanjur memakainya tetap bisa dibaca, dan membuang kolom berisi data
 * tidak bisa dibatalkan. Yang dipakai mulai sekarang adalah tarif_publish.
 *
 * @var PDO $pdo
 */

$kolom = [
    'offers'        => 'tarif sebelum diskon; kosong = tidak dipakai',
    'skp_documents' => 'tarif sebelum diskon; kosong = tidak dipakai',
    'transactions'  => 'tarif sebelum diskon; kosong = tidak dipakai',
];

foreach ($kolom as $tabel => $komentar) {
    $ada = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tabel))->fetchColumn();
    if (!$ada) { echo "     $tabel tidak ada — dilewati\n"; continue; }

    $punya = $pdo->query("SHOW COLUMNS FROM `$tabel` LIKE 'tarif_publish'")->fetchColumn();
    if ($punya) { echo "     $tabel.tarif_publish sudah ada\n"; continue; }

    $pdo->exec("ALTER TABLE `$tabel` ADD COLUMN `tarif_publish` DECIMAL(18,2) NULL DEFAULT NULL"
             . " COMMENT " . $pdo->quote($komentar));
    echo "     $tabel.tarif_publish ditambahkan\n";
}
