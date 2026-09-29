<?php
// Security deposit pada Surat Penawaran yang SUDAH dibayarkan.
//
// Sama seperti pada dokumen konfirmasi (migrasi 051): nominalnya tetap
// tercetak di surat sebagai catatan, tapi tidak ditagih ulang sehingga tidak
// ikut menambah Grand Total. Dipakai untuk perpanjangan / paket lanjutan.
//
// 0 = ditagih seperti biasa   1 = sudah dibayarkan (di luar Grand Total)

$cols = array_column($pdo->query('SHOW COLUMNS FROM offers')->fetchAll(), 'Field');
if (!in_array('deposit_paid', $cols, true)) {
    $pdo->exec("ALTER TABLE offers ADD COLUMN `deposit_paid` TINYINT(1) NOT NULL DEFAULT 0 AFTER deposit_amount");
}
