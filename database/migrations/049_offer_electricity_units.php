<?php
// Jumlah satuan listrik boleh dipilih sendiri.
//
// Hitungan otomatis memakai lama hari sewa (30 hari = 1 satuan, dibulatkan).
// Kadang kesepakatannya lain — mis. sewa 36 hari tapi listriknya disepakati
// 2 satuan. Kolom ini menyimpan pilihan itu.
//
// NULL  = ikut hitungan otomatis
// 1..12 = dipakai sebagai pengali tarif

$cols = array_column($pdo->query('SHOW COLUMNS FROM offers')->fetchAll(), 'Field');
if (!in_array('electricity_units', $cols, true)) {
    $pdo->exec('ALTER TABLE offers ADD COLUMN electricity_units TINYINT UNSIGNED NULL AFTER electricity_monthly');
}
