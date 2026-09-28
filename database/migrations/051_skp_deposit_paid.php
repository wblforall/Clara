<?php
// Security deposit yang SUDAH dibayarkan di kontrak sebelumnya.
//
// Pada perpanjangan, jaminan/deposit biasanya sudah disetor saat kontrak
// pertama dan tidak ditagih ulang. Angkanya tetap perlu tercetak di SKP
// sebagai catatan, tapi tidak boleh ikut menambah Grand Total.
//
// 0 = ditagih seperti biasa (ikut Grand Total)
// 1 = sudah dibayarkan (tercetak "Sudah Dibayarkan", di luar Grand Total)

$cols = array_column($pdo->query('SHOW COLUMNS FROM skp_documents')->fetchAll(), 'Field');
if (!in_array('deposit_paid', $cols, true)) {
    $pdo->exec("ALTER TABLE skp_documents ADD COLUMN `deposit_paid` TINYINT(1) NOT NULL DEFAULT 0 AFTER deposit_amount");
}
