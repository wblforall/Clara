<?php
// Formulir Permintaan Pembuatan/Review Kontrak ke Departemen Legal.
// Langkah SETELAH SKP/SKS ditandatangani customer (lihat [[project-offer-pipeline]]).
// Mode "form generator": sales isi (auto dari SKP) → simpan → cetak PDF formulir
// untuk dikirim ke Legal. Tidak ada workflow/role Legal di aplikasi.

$has = $pdo->query("SHOW TABLES LIKE 'contract_requests'")->fetch();
if (!$has) {
    $pdo->exec("
        CREATE TABLE contract_requests (
            id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            property_id        INT UNSIGNED NOT NULL,
            skp_id             INT UNSIGNED NOT NULL,
            req_no             VARCHAR(60) NULL,
            department         VARCHAR(80) NOT NULL DEFAULT 'Casual Leasing',
            requester_name     VARCHAR(120) NULL,
            requester_position VARCHAR(120) NULL,
            request_date       DATE NULL,
            contract_type      VARCHAR(20) NULL,   -- spk | sewa_menyewa | kerja_sama
            doc_ktp            TINYINT(1) NOT NULL DEFAULT 0,
            doc_npwp           TINYINT(1) NOT NULL DEFAULT 0,
            doc_akta           TINYINT(1) NOT NULL DEFAULT 0,
            doc_surat_kuasa    TINYINT(1) NOT NULL DEFAULT 0,
            important_points   TEXT NULL,
            status             VARCHAR(20) NOT NULL DEFAULT 'draft',  -- draft | sent
            created_by         VARCHAR(120) NULL,
            created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at            DATETIME NULL,
            KEY idx_cr_property (property_id),
            KEY idx_cr_skp (skp_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

// Counter nomor formulir per properti/tahun (mirip skp_counters/offer_counters).
$hasC = $pdo->query("SHOW TABLES LIKE 'contract_request_counters'")->fetch();
if (!$hasC) {
    $pdo->exec("
        CREATE TABLE contract_request_counters (
            property_id INT UNSIGNED NOT NULL,
            year        INT NOT NULL,
            last_no     INT NOT NULL DEFAULT 0,
            PRIMARY KEY (property_id, year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}
