<?php
// Paraf pemeriksa: bentuk & posisinya diatur SEKALI, dipakai seterusnya.
//
// Sebelum ini, paraf Asst. Manager hanya tercetak sebagai baris teks
// "Diperiksa sebelum disetujui: Nama (Jabatan) — tanggal". Itu cukup sebagai
// catatan, tetapi tidak menyerupai dokumen aslinya: di kertas, Pak Yusri
// membubuhkan paraf di tempat yang sama setiap kali, dan orang yang menerima
// dokumen mengenali posisinya.
//
// Jadi pemeriksa menyiapkan parafnya sendiri satu kali — gambarnya dan di mana
// letaknya di halaman — lalu tidak perlu memikirkannya lagi. Begitu ia menekan
// "Paraf & Teruskan", parafnya otomatis terpasang persis seperti yang diatur.
// Pengaturannya bertahan sampai ia sendiri mengubah atau meresetnya.
//
// Posisi disimpan dalam MILIMETER dari pojok kiri-atas halaman A4 (210 x 297),
// bukan persen: ukuran kertasnya tetap, dan milimeter bisa dipakai apa adanya
// baik oleh pratinjau HTML maupun oleh mPDF (WriteFixedPosHTML) tanpa konversi
// yang bisa meleset.
//
// Diatur per JENIS DOKUMEN karena tata letak SKP, SKS Gudang, dan Form
// Utilities berbeda — satu titik yang pas di SKP bisa menimpa tabel di SKS.

$tabel = $pdo->query("SHOW TABLES LIKE 'paraf_settings'")->fetchColumn();
if (!$tabel) {
    $pdo->exec(
        "CREATE TABLE `paraf_settings` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `doc_type` VARCHAR(20) NOT NULL DEFAULT 'skp',
            `bentuk` VARCHAR(20) NOT NULL DEFAULT 'gambar',
            `gambar_path` VARCHAR(255) NULL,
            `teks` VARCHAR(40) NULL,
            `pos_x` DECIMAL(6,2) NOT NULL DEFAULT 150.00,
            `pos_y` DECIMAL(6,2) NOT NULL DEFAULT 232.00,
            `lebar` DECIMAL(6,2) NOT NULL DEFAULT 30.00,
            `tampil_nama` TINYINT NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_paraf_user_doc` (`user_id`, `doc_type`),
            KEY `idx_paraf_user` (`user_id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}
