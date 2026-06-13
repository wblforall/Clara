<?php
// Surat Penawaran (quotation) — titik masuk baru CLARA (offer-first).
// 1 penawaran = 1 unit (1:1 dgn transaksi). Bisa direvisi N kali; tiap revisi
// disimpan di offer_revisions → jumlah nego = COUNT. Saat DEAL → jadi dasar
// Dokumen Konfirmasi (SKP/SKS) lalu transaksi terbit saat approve.
// Lihat [[project-offer-pipeline]].

$tables = array_column($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM), 0);

if (!in_array('offers', $tables, true)) {
    $pdo->exec(
        "CREATE TABLE offers (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            property_id     TINYINT UNSIGNED NOT NULL,
            module          VARCHAR(40) NOT NULL DEFAULT 'cl',
            offer_no        VARCHAR(80) NULL,
            status          VARCHAR(20) NOT NULL DEFAULT 'draft',  -- draft|sent|nego|deal|cancelled
            offer_date      DATE NULL,
            perihal         VARCHAR(190) NULL,
            -- penerima
            client_id       INT UNSIGNED NULL,
            contact_id      INT UNSIGNED NULL,
            -- objek sewa (1 unit)
            master_code     VARCHAR(40) NULL,
            keterangan      TEXT NULL,
            -- harga (ikut model transaksi)
            pricing_type    VARCHAR(40) NULL,
            unit_rate       DECIMAL(18,2) NOT NULL DEFAULT 0,
            area_sqm        DECIMAL(12,2) NOT NULL DEFAULT 0,
            quantity        DECIMAL(12,2) NOT NULL DEFAULT 1,
            slots           DECIMAL(12,2) NOT NULL DEFAULT 1,
            start_date      DATE NULL,
            end_date        DATE NULL,
            contract_months INT NULL,
            monthly_amount  DECIMAL(18,2) NOT NULL DEFAULT 0,   -- harga/bulan
            total_calculated DECIMAL(18,2) NOT NULL DEFAULT 0,
            -- pembayaran terstruktur (semua adjustable oleh sales)
            -- dp_months default 2 = minimum 2 bulan sewa (boleh dinaikkan, divalidasi >= 2)
            dp_months       DECIMAL(5,2) NOT NULL DEFAULT 2,
            dp_amount       DECIMAL(18,2) NOT NULL DEFAULT 0,
            deposit_months  DECIMAL(5,2) NOT NULL DEFAULT 1,
            deposit_amount  DECIMAL(18,2) NOT NULL DEFAULT 0,
            -- workflow
            status_note     TEXT NULL,
            revision_count  INT NOT NULL DEFAULT 0,
            deal_at         DATETIME NULL,
            cancelled_at    DATETIME NULL,
            created_by      VARCHAR(120) NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME NULL,
            updated_by      VARCHAR(120) NULL,
            KEY idx_prop_status (property_id, status),
            KEY idx_client (client_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

if (!in_array('offer_revisions', $tables, true)) {
    $pdo->exec(
        "CREATE TABLE offer_revisions (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            offer_id    INT UNSIGNED NOT NULL,
            rev_no      INT NOT NULL,
            snapshot_json MEDIUMTEXT NULL,
            note        TEXT NULL,
            created_by  VARCHAR(120) NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_offer (offer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// Nomor urut penawaran per properti per tahun (untuk format No. Penawaran)
if (!in_array('offer_counters', $tables, true)) {
    $pdo->exec(
        "CREATE TABLE offer_counters (
            property_id TINYINT UNSIGNED NOT NULL,
            year        SMALLINT UNSIGNED NOT NULL,
            last_no     INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (property_id, year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}
