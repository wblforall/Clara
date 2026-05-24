<?php
// Snapshot potensi per slot per periode
// Dibuat ulang setiap ada perubahan di master data (rate/luas/slot)
// Periode lalu tidak disentuh

$pdo->exec("
    CREATE TABLE IF NOT EXISTS period_potentials (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        property_id     TINYINT UNSIGNED NOT NULL,
        period_key      VARCHAR(7) NOT NULL,
        segment         ENUM('exhibition','media','gudang') NOT NULL,
        slot_id         INT UNSIGNED NOT NULL,
        slot_code       VARCHAR(40) NOT NULL,
        potential_value DECIMAL(18,2) NOT NULL DEFAULT 0,
        updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_slot_period (property_id, period_key, segment, slot_id),
        KEY idx_property_period (property_id, period_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
