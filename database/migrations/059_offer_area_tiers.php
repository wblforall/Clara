<?php
// Tarif bertingkat per m² untuk SATU penawaran (kasus Efata/Mitsubishi).
//
// Ada kesepakatan yang menyewa beberapa lokasi sekaligus, tetapi harganya tidak
// ditetapkan per lokasi melainkan atas LUAS GABUNGAN dengan tarif bertingkat:
//   48 m² × Rp 130.000/m²/hari + 32 m² × Rp 100.000/m²/hari × 7 hari = Rp 66.080.000
// Pembagian 48/32 itu tidak jatuh di batas lokasi (luas tiap island 20/20/20/12),
// jadi tingkatan harga memang milik PAKET, bukan milik lokasinya masing-masing.
//
// Nilai paket dihitung dari tabel ini, lalu dibagi ke tiap komponen lokasi
// secara pro-rata menurut luas masing-masing — supaya 4 transaksi tetap terbit
// dan occupancy 4 unit tetap benar di laporan.
//
// Kosong = penawaran memakai cara lama (harga diketik per komponen).

$tabel = $pdo->query("SHOW TABLES LIKE 'offer_area_tiers'")->fetchColumn();
if (!$tabel) {
    $pdo->exec(
        "CREATE TABLE `offer_area_tiers` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `property_id` TINYINT NOT NULL DEFAULT 1,
            `offer_id` INT NOT NULL,
            `area_sqm` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `rate_per_sqm` DECIMAL(18,2) NOT NULL DEFAULT 0,
            `label` VARCHAR(190) NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `created_by` VARCHAR(120) NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_tier_offer` (`offer_id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

// Luas per komponen paket dipakai sebagai dasar pembagian pro-rata. Kolomnya
// sudah ada di offer_items (area_sqm) tetapi selama ini tidak pernah diisi —
// mulai sekarang diisi dari Master Exhibition saat komponen disimpan.
