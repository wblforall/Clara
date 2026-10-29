<?php
/**
 * Tempat menyimpan nominal bulanan yang DITENTUKAN ORANG (Special Price).
 *
 * Sebelum ini angkanya ditulis langsung ke transaction_allocations, dan baris
 * itu dihapus-tulis-ulang setiap kali AllocationService::saveAllocations()
 * berjalan — saat transaksi disunting, saat SKP disetujui, saat SKP direvisi.
 * Akibatnya nominal khusus hilang diam-diam, dan karena nilai kontrak sempat
 * disamakan dengan jumlah alokasi yang baru, SISA bulan lain ikut bergeser:
 * satu kontrak Rp 36.000.000 yang bulan Januarinya diubah jadi Rp 12.345.678
 * berakhir menjadi Rp 42.345.678 yang dibagi rata enam bulan.
 *
 * Dengan tabel ini niatnya tersimpan terpisah dari hasil hitungannya, jadi
 * perhitungan ulang berapa kali pun tetap menghormatinya.
 */

$ada = $pdo->query("SHOW TABLES LIKE 'transaction_month_overrides'")->fetchColumn();
if ($ada) { echo "     tabel transaction_month_overrides sudah ada — dilewati\n"; return; }

$pdo->exec(
    "CREATE TABLE transaction_month_overrides (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        property_id    INT NOT NULL,
        transaction_id INT NOT NULL,
        period_key     VARCHAR(7) NOT NULL,
        amount         DECIMAL(16,2) NOT NULL,
        created_by     VARCHAR(100) NULL,
        created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_trx_period (transaction_id, period_key),
        KEY idx_prop (property_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
echo "     tabel transaction_month_overrides dibuat\n";
