<?php
/**
 * Pembayaran ditandai untuk bulan mana.
 *
 * Kontrak recurring tidak ditagih sekaligus: McD Rp 763.543.440 berjalan 34
 * bulan, ditagih sebulan sekali. Selama satu baris pembayaran hanya menempel
 * ke transaksinya, tidak ada cara menjawab pertanyaan yang tiap bulan
 * ditanyakan tim — "Oktober sudah lunas belum?" Yang terlihat cuma total
 * terbayar terhadap nilai kontrak penuh, dan itu baru lunas tiga tahun lagi.
 *
 * Satu pembayaran boleh menutup beberapa bulan sekaligus (client kerap
 * mentransfer tiga bulan di muka), jadi pembagiannya ditaruh di tabel anak,
 * bukan satu kolom bulan di baris pembayarannya.
 *
 * Baris pembayaran lama dibiarkan tanpa penanda bulan — artinya "berlaku untuk
 * kontraknya, belum ditentukan bulannya", bukan data hilang. Statusnya di
 * tingkat kontrak tetap sama persis seperti sebelumnya.
 */

if (!$pdo->query("SHOW TABLES LIKE 'transaction_payment_periods'")->fetchColumn()) {
    $pdo->exec(
        "CREATE TABLE `transaction_payment_periods` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `payment_id` INT NOT NULL,
            `transaction_id` INT UNSIGNED NOT NULL,
            `period_key` VARCHAR(7) NOT NULL,
            `amount` DECIMAL(18,2) NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_tpp_pay` (`payment_id`),
            KEY `idx_tpp_trx` (`transaction_id`, `period_key`),
            CONSTRAINT `fk_tpp_pay` FOREIGN KEY (`payment_id`)
                REFERENCES `transaction_payments` (`id`) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "     transaction_payment_periods dibuat\n";
} else {
    echo "     transaction_payment_periods sudah ada\n";
}
