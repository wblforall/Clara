<?php
// Master Tipe Unit (controlled vocabulary) untuk dropdown unit_type di Master
// Exhibition — mengganti input teks bebas (sumber typo/variasi: "Puschart",
// "Island L1/L2", singkatan "FC/SC/PC"). Per-properti karena framework master
// meng-scope semua tabel dengan property_id. User bisa tambah/edit tipe sendiri
// lewat menu Master Tipe Unit. Dipakai juga utk template Surat Penawaran per tipe.

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('master_cl_unit_types', $tables, true)) {
    $pdo->exec(
        "CREATE TABLE master_cl_unit_types (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            property_id TINYINT UNSIGNED NOT NULL,
            name        VARCHAR(80) NOT NULL,
            sort_order  INT NOT NULL DEFAULT 0,
            status      VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME NULL,
            UNIQUE KEY uq_prop_name (property_id, name),
            KEY idx_prop (property_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// Seed daftar baku untuk tiap properti yang punya unit CL (fallback: semua properti).
$baku = [
    'Fashion Booth', 'Food Stall', 'Food Court', 'Snack Corner', 'Pushcart',
    'Island', 'Circle', 'Free Standing', 'Atrium', 'Playground', 'Photobox',
    'Leasable Area', 'Parking Area',
];
$pids = $pdo->query('SELECT DISTINCT property_id FROM master_cl_units')->fetchAll(PDO::FETCH_COLUMN);
if (!$pids) {
    $pids = $pdo->query('SELECT id FROM properties')->fetchAll(PDO::FETCH_COLUMN);
}
// NB: hindari variabel $name di sini — bentrok dgn $name milik db_migrate.php
// (require berbagi scope) yang membuat nama migrasi tercatat salah.
$insType = $pdo->prepare('INSERT IGNORE INTO master_cl_unit_types (property_id, name, sort_order) VALUES (?,?,?)');
foreach ($pids as $seedPid) {
    foreach ($baku as $i => $typeName) {
        $insType->execute([(int) $seedPid, $typeName, $i]);
    }
}
