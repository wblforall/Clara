<?php
/**
 * Invoice & pembayaran per transaksi.
 *
 * Selama ini CLARA hanya punya satu kolom opsional `invoice_no`, dan dari
 * ribuan transaksi tak satu pun terisi. Padahal di Excel Casual Leasing tiap
 * baris mencatat nomor invoice, jumlah yang dibayar, tanggal bayar, dan tanda
 * LUNAS — itu yang membuat tim masih membuka Excel untuk menagih.
 *
 * Yang ditambahkan, tanpa mengubah satu pun angka income:
 *   - transactions.invoice_date / invoice_due / invoice_amount: tanggal terbit,
 *     jatuh tempo, dan jumlah tagihan (termasuk PPN bila ada). Kosong = tagihan
 *     dianggap sama dengan nilai transaksi (final_amount).
 *   - transaction_payments: tiap pembayaran satu baris (DP, termin, pelunasan).
 *     Dihapus dengan penanda (deleted_at), bukan dibuang, supaya jejaknya utuh.
 *   - izin manage_payments untuk Admin Sales (administrasi) dan Sales.
 *
 * Status (belum ada invoice / belum dibayar / sebagian / lunas / lewat tempo)
 * TIDAK disimpan — dihitung dari data di atas setiap kali dibaca, jadi tidak
 * bisa tertinggal dari pembayarannya.
 */

$kol = array_column($pdo->query('SHOW COLUMNS FROM `transactions`')->fetchAll(PDO::FETCH_ASSOC), 'Field');
foreach ([
    'invoice_date'   => 'DATE NULL',
    'invoice_due'    => 'DATE NULL',
    'invoice_amount' => 'DECIMAL(18,2) NULL',
] as $nama => $tipe) {
    if (!in_array($nama, $kol, true)) {
        $pdo->exec("ALTER TABLE `transactions` ADD COLUMN `$nama` $tipe");
        echo "     transactions.$nama ditambahkan\n";
    }
}

if (!$pdo->query("SHOW TABLES LIKE 'transaction_payments'")->fetchColumn()) {
    $pdo->exec(
        "CREATE TABLE `transaction_payments` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `property_id` TINYINT NOT NULL DEFAULT 1,
            `transaction_id` INT UNSIGNED NOT NULL,
            `paid_at` DATE NOT NULL,
            `amount` DECIMAL(18,2) NOT NULL,
            `method` VARCHAR(40) NULL,
            `ref_no` VARCHAR(80) NULL,
            `note` VARCHAR(190) NULL,
            `created_by` VARCHAR(120) NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted_at` DATETIME NULL,
            `deleted_by` VARCHAR(120) NULL,
            KEY `idx_pay_trx` (`transaction_id`),
            KEY `idx_pay_date` (`paid_at`),
            CONSTRAINT `fk_pay_trx` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "     transaction_payments dibuat\n";
}

if ($pdo->query("SHOW TABLES LIKE 'role_permissions'")->fetchColumn()) {
    $st = $pdo->prepare('INSERT IGNORE INTO role_permissions (role, permission) VALUES (?, ?)');
    foreach (['administrasi', 'sales'] as $peran) {
        $st->execute([$peran, 'manage_payments']);
    }
    echo "     izin manage_payments diberikan ke administrasi & sales\n";
}
