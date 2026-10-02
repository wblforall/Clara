<?php
// Jadwal harga bertahap dalam SATU kontrak.
//
// Dua kebutuhan nyata yang tidak bisa dijawab satu nominal per kontrak:
//  (1) kontrak 3 tahun dengan diskon berbeda di tahun ke-3 — harus tercantum
//      sejak Surat Penawaran, karena client tidak mau menerima dua surat;
//  (2) kontrak berjalan yang harganya naik mulai periode berikutnya, tanpa
//      mengubah bulan yang sudah lewat.
//
// Satu baris = satu tahap: mulai tanggal `effective_from`, nilai per siklus
// bulanan menjadi `monthly_amount`. Tahap berlaku sampai tahap berikutnya.
// Pemiliknya offer_id (saat masih penawaran) dan/atau transaction_id (setelah
// kontrak terbit) — disalin saat dokumen disetujui.
//
// Tanpa baris di tabel ini, perilaku lama berlaku apa adanya: nilai kontrak
// dibagi rata ke seluruh siklus.

$tabel = $pdo->query("SHOW TABLES LIKE 'price_steps'")->fetchColumn();
if (!$tabel) {
    $pdo->exec(
        "CREATE TABLE `price_steps` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `property_id` TINYINT NOT NULL DEFAULT 1,
            `offer_id` INT NULL,
            `transaction_id` INT NULL,
            `effective_from` DATE NOT NULL,
            `monthly_amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
            `label` VARCHAR(120) NULL,
            `note` VARCHAR(190) NULL,
            `created_by` VARCHAR(120) NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_step_offer` (`offer_id`),
            KEY `idx_step_trx` (`transaction_id`),
            KEY `idx_step_mulai` (`effective_from`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}
