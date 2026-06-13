<?php
// Tabel SKP (Surat Konfirmasi Pameran) — dokumen konfirmasi untuk transaksi
// Exhibition yang sudah deal. 1 transaksi → 1 SKP. Alur: draft → submitted →
// approved/rejected. Nilai cetak di-snapshot saat approve agar dokumen terbit
// tidak berubah meski transaksi diedit kemudian.

$tables = array_column($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM), 0);

if (!in_array('skp_documents', $tables, true)) {
    $pdo->exec(
        "CREATE TABLE skp_documents (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            property_id     TINYINT UNSIGNED NOT NULL,
            transaction_id  INT UNSIGNED NOT NULL,
            skp_no          VARCHAR(60) NULL,
            status          VARCHAR(20) NOT NULL DEFAULT 'draft',
            -- field tambahan yang tidak ada di transaksi/master
            ktp_pj          VARCHAR(40) NULL,
            phone_pj        VARCHAR(40) NULL,
            seating_area    DECIMAL(12,2) NULL,
            produk          VARCHAR(190) NULL,
            status_sewa     VARCHAR(30) NULL,
            deposit_amount  DECIMAL(18,2) NOT NULL DEFAULT 0,
            admin_siup      TINYINT(1) NOT NULL DEFAULT 0,
            admin_npwp      TINYINT(1) NOT NULL DEFAULT 0,
            admin_ktp       TINYINT(1) NOT NULL DEFAULT 0,
            denah_path      VARCHAR(255) NULL,
            note            TEXT NULL,
            snapshot_json   MEDIUMTEXT NULL,
            -- workflow
            created_by      VARCHAR(120) NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            submitted_at    DATETIME NULL,
            approved_by     VARCHAR(120) NULL,
            approved_at     DATETIME NULL,
            reject_note     TEXT NULL,
            updated_at      DATETIME NULL,
            updated_by      VARCHAR(120) NULL,
            UNIQUE KEY uniq_trx (transaction_id),
            KEY idx_prop_status (property_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// Nomor urut SKP per properti per tahun (untuk format SKP/EW|PC/2026/001).
if (!in_array('skp_counters', $tables, true)) {
    $pdo->exec(
        "CREATE TABLE skp_counters (
            property_id TINYINT UNSIGNED NOT NULL,
            year        SMALLINT UNSIGNED NOT NULL,
            last_no     INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (property_id, year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}
