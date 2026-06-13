<?php
// Phase 2 pipeline: Dokumen Konfirmasi (SKP/SKS) berasal dari Surat Penawaran.
// - skp_documents: doc_type (skp|sks), offer_id, transaction_id jadi nullable
//   (transaksi kini OUTPUT saat approve - Phase 3).
// - master_clients.ktp (npwp sudah ada) untuk reuse otomatis.
// - skp_attachments: lampiran (KTP, NPWP, bukti transfer, pengajuan, penawaran).
// Lihat [[project-offer-pipeline]].

$tables = array_column($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM), 0);
$skpCols = array_column($pdo->query('SHOW COLUMNS FROM skp_documents')->fetchAll(), 'Field');

if (!in_array('doc_type', $skpCols, true)) {
    $pdo->exec("ALTER TABLE skp_documents ADD COLUMN doc_type VARCHAR(10) NOT NULL DEFAULT 'skp' AFTER property_id");
}
if (!in_array('offer_id', $skpCols, true)) {
    $pdo->exec("ALTER TABLE skp_documents ADD COLUMN offer_id INT UNSIGNED NULL AFTER doc_type");
    $pdo->exec("ALTER TABLE skp_documents ADD INDEX idx_offer (offer_id)");
}
// transaction_id: jadikan nullable (kini output saat approve)
$pdo->exec("ALTER TABLE skp_documents MODIFY transaction_id INT UNSIGNED NULL");

// master_clients.ktp (nomor KTP penanggung jawab, untuk reuse)
$clientCols = array_column($pdo->query('SHOW COLUMNS FROM master_clients')->fetchAll(), 'Field');
if (!in_array('ktp', $clientCols, true)) {
    $pdo->exec("ALTER TABLE master_clients ADD COLUMN ktp VARCHAR(40) NULL AFTER npwp");
}

// Lampiran dokumen konfirmasi
if (!in_array('skp_attachments', $tables, true)) {
    $pdo->exec(
        "CREATE TABLE skp_attachments (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            skp_id        INT UNSIGNED NOT NULL,
            kind          VARCHAR(30) NOT NULL,   -- ktp|npwp|bukti_transfer|pengajuan|penawaran
            file_path     VARCHAR(255) NOT NULL,
            original_name VARCHAR(190) NULL,
            uploaded_by   VARCHAR(120) NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_skp (skp_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}
