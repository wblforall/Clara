<?php
// Paket Bundling Exhibition + Media (lihat [[project-bundling-package]]).
// Model: 1 offer "paket" = >=2 komponen (offer_items), tiap item segmen+harga
// sendiri, periode SAMA (di level offer). Saat SKP Paket di-approve → 1 transaksi
// per item, semua diikat bundle_id (= offer.id paket). Offer 1-item (penawaran
// biasa) TIDAK menulis offer_items → kompatibel mundur.

// Tabel komponen paket.
$has = $pdo->query("SHOW TABLES LIKE 'offer_items'")->fetch();
if (!$has) {
    $pdo->exec("
        CREATE TABLE offer_items (
            id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            offer_id       INT UNSIGNED NOT NULL,
            segment        VARCHAR(40) NOT NULL DEFAULT 'cl',  -- cl | media | gudang
            master_code    VARCHAR(40) NULL,
            name_snapshot  VARCHAR(190) NULL,                  -- nama unit/titik saat dibuat
            pricing_type   VARCHAR(40) NULL,
            unit_rate      DECIMAL(18,2) NOT NULL DEFAULT 0,
            area_sqm       DECIMAL(12,2) NOT NULL DEFAULT 0,
            slots          DECIMAL(12,2) NOT NULL DEFAULT 1,
            monthly_amount DECIMAL(18,2) NOT NULL DEFAULT 0,   -- harga/bulan komponen ini
            dp_amount      DECIMAL(18,2) NOT NULL DEFAULT 0,
            deposit_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            total_amount   DECIMAL(18,2) NOT NULL DEFAULT 0,   -- nilai kontrak komponen (pra-PPN)
            sort_order     INT NOT NULL DEFAULT 0,
            KEY idx_oi_offer (offer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

// Penanda paket di offers (1-item = 0, paket = 1).
$offerCols = $pdo->query("SHOW COLUMNS FROM offers")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('is_bundle', $offerCols, true)) {
    $pdo->exec("ALTER TABLE offers ADD COLUMN is_bundle TINYINT(1) NOT NULL DEFAULT 0 AFTER module");
}

// Tautan transaksi → paket/SKP/komponen.
//  - bundle_id    = offers.id paket (NULL utk transaksi biasa). Dipakai dedupe metrik COUNT (1 paket = 1 deal).
//  - skp_id       = SKP penerbit (reverse-link; 1 SKP Paket → banyak transaksi).
//  - offer_item_id= komponen offer_items asal transaksi.
$trxCols = $pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('bundle_id', $trxCols, true)) {
    $pdo->exec("ALTER TABLE transactions ADD COLUMN bundle_id INT UNSIGNED NULL AFTER property_id");
    $pdo->exec("ALTER TABLE transactions ADD INDEX idx_trx_bundle (bundle_id)");
}
if (!in_array('skp_id', $trxCols, true)) {
    $pdo->exec("ALTER TABLE transactions ADD COLUMN skp_id INT UNSIGNED NULL AFTER bundle_id");
}
if (!in_array('offer_item_id', $trxCols, true)) {
    $pdo->exec("ALTER TABLE transactions ADD COLUMN offer_item_id INT UNSIGNED NULL AFTER skp_id");
}
