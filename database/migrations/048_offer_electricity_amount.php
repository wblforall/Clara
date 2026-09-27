<?php
// Total biaya listrik boleh ditetapkan manual.
//
// Sebelumnya sales hanya bisa mengubah TARIF-nya; pengalinya (tiap 30 hari)
// ditentukan sistem. Untuk kasus yang tidak bulat — mis. sewa 45 hari yang
// disepakati listriknya cukup Rp 200.000, bukan 2 × Rp 150.000 — nilainya perlu
// bisa diketik langsung.
//
// Kosong / NULL = ikut hitungan otomatis (tarif × satuan 30 hari).
// Terisi        = itulah biaya listrik yang dipakai, apa adanya.

$cols = array_column($pdo->query('SHOW COLUMNS FROM offers')->fetchAll(), 'Field');
if (!in_array('electricity_amount', $cols, true)) {
    $pdo->exec('ALTER TABLE offers ADD COLUMN electricity_amount DECIMAL(18,2) NULL AFTER electricity_monthly');
}
