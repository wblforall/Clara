<?php
// Laporan Stock counter (brand TRISET / WATCHOUT / TRISET KIDS) — replika form
// kertas yang diisi manual di counter. Satu laporan = satu counter × satu bulan.
//
// stock_report_rows menyimpan baris kertasnya. Dua jenis baris:
//   - 'group' : baris pemisah kelompok artikel (TE / TF / TQ / TZ). Kolom kanan
//               digabung jadi satu kolom panjang (group_text).
//   - 'item'  : baris artikel biasa; q36..q41 = jumlah turus (stock awal) per ukuran.
//
// stock_report_sales = satu baris per satu pasang yang laku (1 turus dicoret +
// tanggal merah di kertas). Stock akhir TIDAK disimpan — selalu dihitung
// stock awal (jumlah q36..q41) dikurangi jumlah penjualan, supaya angka di
// layar tak pernah bisa beda dengan catatan penjualannya.

$tables = array_column($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM), 0);

if (!in_array('stock_reports', $tables, true)) {
    $pdo->exec(
        "CREATE TABLE stock_reports (
            id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            property_id      TINYINT UNSIGNED NOT NULL,
            counter_name     VARCHAR(120) NOT NULL,
            brand            VARCHAR(30) NOT NULL DEFAULT 'TRISET',
            period_key       CHAR(7) NOT NULL,
            tipe             VARCHAR(20) NOT NULL DEFAULT 'NORMAL',
            barang_datang    INT NOT NULL DEFAULT 0,
            retur            INT NOT NULL DEFAULT 0,
            discount_note    VARCHAR(120) NULL,
            note             TEXT NULL,
            created_by       VARCHAR(120) NULL,
            created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by       VARCHAR(120) NULL,
            updated_at       DATETIME NULL,
            KEY idx_prop_period (property_id, period_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

if (!in_array('stock_report_rows', $tables, true)) {
    $pdo->exec(
        "CREATE TABLE stock_report_rows (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_id   INT UNSIGNED NOT NULL,
            sort_order  INT UNSIGNED NOT NULL DEFAULT 0,
            row_type    VARCHAR(10) NOT NULL DEFAULT 'item',
            group_code  VARCHAR(20) NULL,
            group_text  VARCHAR(255) NULL,
            artikel     VARCHAR(40) NULL,
            warna       VARCHAR(30) NULL,
            q36         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            q37         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            q38         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            q39         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            q40         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            q41         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            harga       DECIMAL(14,2) NULL,
            keterangan  VARCHAR(190) NULL,
            KEY idx_report (report_id, sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

if (!in_array('stock_report_sales', $tables, true)) {
    $pdo->exec(
        "CREATE TABLE stock_report_sales (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            report_id  INT UNSIGNED NOT NULL,
            row_id     INT UNSIGNED NOT NULL,
            size_code  VARCHAR(5) NOT NULL,
            sold_day   TINYINT UNSIGNED NOT NULL,
            created_by VARCHAR(120) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_row_size (row_id, size_code),
            KEY idx_report (report_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}
