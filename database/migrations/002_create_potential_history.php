<?php
// Histori setiap perubahan nilai potensi per slot per periode
// Siapa yang mengubah, kapan, dari berapa ke berapa, dan dari mana perubahannya berasal

$pdo->exec("
    CREATE TABLE IF NOT EXISTS potential_history (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        property_id     TINYINT UNSIGNED NOT NULL,
        period_key      VARCHAR(7) NOT NULL,
        segment         ENUM('exhibition','media','gudang') NOT NULL,
        slot_id         INT UNSIGNED NOT NULL,
        slot_code       VARCHAR(40) NOT NULL,
        old_value       DECIMAL(18,2) NOT NULL DEFAULT 0,
        new_value       DECIMAL(18,2) NOT NULL DEFAULT 0,
        changed_by      INT UNSIGNED NOT NULL,
        changed_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        change_source   VARCHAR(60) NOT NULL DEFAULT '',
        KEY idx_property_period (property_id, period_key),
        KEY idx_changed_at (changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
