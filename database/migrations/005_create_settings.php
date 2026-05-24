<?php
// Tabel key-value untuk konfigurasi aplikasi (reward_start_period, dsb.)
$tbl = $pdo->query("SHOW TABLES LIKE 'settings'")->fetchColumn();
if (!$tbl) {
    $pdo->exec("CREATE TABLE settings (
        `key`         VARCHAR(100) NOT NULL PRIMARY KEY,
        `value`       TEXT         NOT NULL DEFAULT '',
        updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
