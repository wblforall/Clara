<?php
/**
 * Jenis alasan pada pengajuan penghapusan.
 *
 * Alasannya tetap ditulis bebas — itu yang dibaca pemutus. Tetapi untuk
 * merekap "siapa yang paling sering bikin dobel", teks bebas tidak bisa
 * dihitung: satu orang menulis "dobel", yang lain "duplikat", yang lain lagi
 * "sudah ada di dokumen sebelumnya". Jenisnya dipilih dari daftar supaya
 * rekapnya berdiri di atas angka, bukan tafsiran kata.
 *
 * Nilainya: dobel | salah_input | batal | lainnya
 */
$ada = $pdo->query("SHOW COLUMNS FROM `deletion_requests` LIKE 'jenis'")->fetchColumn();
if (!$ada) {
    $pdo->exec("ALTER TABLE `deletion_requests`
                ADD COLUMN `jenis` VARCHAR(20) NOT NULL DEFAULT 'lainnya'
                COMMENT 'dobel | salah_input | batal | lainnya' AFTER `alasan`,
                ADD KEY `idx_dr_jenis` (`property_id`, `jenis`)");
    echo "     deletion_requests.jenis ditambahkan\n";
}
