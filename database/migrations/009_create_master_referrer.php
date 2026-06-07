<?php
// Buat tabel master_referrer dan tambah kolom referrer_name ke transactions

// Tabel master_referrer
$tbl = $pdo->query("SHOW TABLES LIKE 'master_referrer'")->fetchColumn();
if (!$tbl) {
    $pdo->exec("CREATE TABLE master_referrer (
        id      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name    VARCHAR(120) NOT NULL,
        dept    VARCHAR(100) DEFAULT NULL COMMENT 'Departemen/bagian',
        status  ENUM('active','inactive') NOT NULL DEFAULT 'active',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Kolom referrer_name di transactions
$col = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'referrer_name'")->fetchColumn();
if (!$col) {
    $pdo->exec("ALTER TABLE transactions ADD COLUMN referrer_name VARCHAR(120) DEFAULT NULL AFTER pic_name");
}
