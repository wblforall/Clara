<?php
// Tambah kolom jabatan, no_rekening, nama_bank ke master_referrer

$existing = array_column(
    $pdo->query("SHOW COLUMNS FROM master_referrer")->fetchAll(),
    'Field'
);

if (!in_array('jabatan', $existing)) {
    $pdo->exec("ALTER TABLE master_referrer ADD COLUMN jabatan VARCHAR(100) DEFAULT NULL AFTER name");
}
if (!in_array('no_rekening', $existing)) {
    $pdo->exec("ALTER TABLE master_referrer ADD COLUMN no_rekening VARCHAR(60) DEFAULT NULL AFTER dept");
}
if (!in_array('nama_bank', $existing)) {
    $pdo->exec("ALTER TABLE master_referrer ADD COLUMN nama_bank VARCHAR(80) DEFAULT NULL AFTER no_rekening");
}
