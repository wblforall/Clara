<?php
// Pembagian income satu dokumen ke beberapa PIC.
//
// Selama ini satu transaksi hanya punya satu nama PIC, dan seluruh nilainya
// jatuh ke orang itu. Kenyataannya satu kesepakatan sering dikerjakan beberapa
// sales dan hasilnya dibagi. Tabel ini menyimpan NIAT pembagiannya, terpisah
// dari baris alokasi bulanan — karena alokasi selalu dihapus-dan-ditulis-ulang
// setiap transaksi disimpan, sehingga pembagian yang hanya hidup di sana akan
// lenyap diam-diam.
//
// Baris alokasi tetap dibentuk dari tabel ini saat penyimpanan, sehingga semua
// laporan per PIC (yang membaca transaction_allocations.pic_name) ikut benar
// tanpa satu query pun diubah.
//
// Collation disamakan dengan transaction_allocations & master_pic
// (utf8mb4_unicode_ci) karena seluruh laporan mencocokkan PIC lewat NAMA.

$tabel = $pdo->query("SHOW TABLES LIKE 'transaction_pic_splits'")->fetchColumn();
if (!$tabel) {
    $pdo->exec(
        "CREATE TABLE `transaction_pic_splits` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `property_id` TINYINT NOT NULL DEFAULT 1,
            `transaction_id` INT NULL,
            `skp_id` INT NULL,
            `pic_name` VARCHAR(120) COLLATE utf8mb4_unicode_ci NOT NULL,
            `amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
            `note` VARCHAR(190) NULL,
            `created_by` VARCHAR(120) NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_split_trx` (`transaction_id`),
            KEY `idx_split_skp` (`skp_id`),
            KEY `idx_split_pic` (`pic_name`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}
