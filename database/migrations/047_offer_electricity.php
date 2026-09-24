<?php
// Biaya listrik pada Surat Penawaran Exhibition.
//
// Di lapangan hampir semua tenant pameran dikenakan biaya listrik bulanan, tapi
// nominalnya tidak pernah tercantum di surat penawaran sehingga sering jadi
// pertanyaan belakangan. Sekarang:
//   - offers.electricity_flag     → dicentang = listrik ikut tercetak & terhitung
//   - offers.electricity_monthly  → tarif per bulan (boleh diubah per penawaran)
//   - offer_templates.electricity_default → baseline yang dipakai saat membuat
//     penawaran baru, bisa diubah user lewat menu Template Penawaran
//
// PENTING: kolom flag sengaja DEFAULT 0 dan baris lama TIDAK diubah — surat
// penawaran yang sudah terbit harus tetap tercetak persis seperti aslinya.
// Penawaran baru yang mengaktifkannya diisi dari formulir (tercentang otomatis).

$cols = array_column($pdo->query('SHOW COLUMNS FROM offers')->fetchAll(), 'Field');
if (!in_array('electricity_flag', $cols, true)) {
    $pdo->exec("ALTER TABLE offers ADD COLUMN electricity_flag TINYINT(1) NOT NULL DEFAULT 0 AFTER deposit_amount");
}
if (!in_array('electricity_monthly', $cols, true)) {
    $pdo->exec('ALTER TABLE offers ADD COLUMN electricity_monthly DECIMAL(18,2) NULL AFTER electricity_flag');
}

$tcols = array_column($pdo->query('SHOW COLUMNS FROM offer_templates')->fetchAll(), 'Field');
if (!in_array('electricity_default', $tcols, true)) {
    $pdo->exec("ALTER TABLE offer_templates ADD COLUMN electricity_default DECIMAL(18,2) NOT NULL DEFAULT 150000 AFTER dp_months_default");
}
