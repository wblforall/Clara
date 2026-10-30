<?php
/**
 * Prorata bulan kalender untuk dokumen Gudang (SKS).
 *
 * Kolom transactions.prorata_kalender menentukan cara kontrak BULANAN dipecah
 * ke bulan: ikut bulan kalender (cara surat kertas) atau ikut siklus tanggal
 * (11 → 10). Nilainya bisa berbeda ratusan ribu untuk kontrak yang tidak mulai
 * tanggal 1 — dan itu diakui sendiri oleh keterangan di Surat Penawaran.
 *
 * Exhibition sudah bisa memilihnya di penawaran. Gudang — satu-satunya modul
 * lain yang berharga bulanan, dan yang paling sering mulai di tengah bulan —
 * tidak punya tempat untuk memilihnya sama sekali, sehingga selalu memakai
 * siklus tanggal. Kolom ini memberi tempat itu di dokumennya sendiri.
 */

$kol = array_column($pdo->query('SHOW COLUMNS FROM `skp_documents`')->fetchAll(PDO::FETCH_ASSOC), 'Field');
if (in_array('prorata_kalender', $kol, true)) { echo "     skp_documents.prorata_kalender sudah ada — dilewati\n"; return; }
$pdo->exec("ALTER TABLE `skp_documents` ADD COLUMN `prorata_kalender` TINYINT(1) NOT NULL DEFAULT 0 AFTER `recognition_json`");
echo "     skp_documents.prorata_kalender ditambahkan\n";
