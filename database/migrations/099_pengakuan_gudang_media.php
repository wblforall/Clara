<?php
/**
 * Pengakuan & Recurring untuk dokumen Gudang (SKS) dan Media (Form Utilities).
 *
 * Kedua modul ini tidak lewat Surat Penawaran: dokumennya dibuat langsung, dan
 * transaksinya terbit saat manager menyetujui. Akibatnya seluruh pengaturan
 * pengakuan yang di Exhibition ditentukan di penawaran — dibagi rata atau
 * diakui sekaligus, di bulan awal atau akhir siklus, recurring atau bukan —
 * tidak punya tempat sama sekali, dan transaksinya selalu lahir dengan tebakan
 * bawaan.
 *
 * Kolom-kolom ini memberi tempat itu di dokumennya sendiri. Isinya diteruskan
 * apa adanya ke transaksi yang terbit (lihat _skp_create_transaction).
 *
 * recognition_json menyimpan nominal yang diakui PER BULAN bila pengakuannya
 * dibagi rata — bentuknya {"2026-12": 10000000, "2027-01": 12000000}. Saat
 * transaksinya terbit, isinya disalin menjadi nominal bulanan khusus
 * (transaction_month_overrides), persis seperti yang bisa diatur Exhibition
 * lewat halaman Transaksi.
 */

$kol = array_column($pdo->query('SHOW COLUMNS FROM `skp_documents`')->fetchAll(PDO::FETCH_ASSOC), 'Field');
$tambah = [
    'billing_method'    => "VARCHAR(20) NULL AFTER `total_amount`",
    'cycle_recognition' => "VARCHAR(20) NULL AFTER `billing_method`",
    'recurring_flag'    => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `cycle_recognition`",
    'recognition_json'  => "TEXT NULL AFTER `recurring_flag`",
];
$n = 0;
foreach ($tambah as $nama => $def) {
    if (in_array($nama, $kol, true)) { echo "     skp_documents.$nama sudah ada — dilewati\n"; continue; }
    $pdo->exec("ALTER TABLE `skp_documents` ADD COLUMN `$nama` $def");
    echo "     skp_documents.$nama ditambahkan\n";
    $n++;
}
echo "     $n kolom pengakuan ditambahkan\n";
