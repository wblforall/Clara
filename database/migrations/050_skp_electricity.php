<?php
// Biaya listrik pada dokumen konfirmasi (SKP).
//
// Untuk SKP yang lahir dari Surat Penawaran, listriknya sudah ikut dari sana.
// Tapi SKP perpanjangan dibuat langsung dari transaksi — tanpa penawaran —
// sehingga tidak punya tempat mencatat listrik. Kolom ini mengisi celah itu:
// bisa dihitung otomatis (tarif × satuan 30 hari) atau ditetapkan sendiri.
//
// NULL / 0 = dokumen ini tidak menagih listrik.

$cols = array_column($pdo->query('SHOW COLUMNS FROM skp_documents')->fetchAll(), 'Field');
$tambah = [
    'electricity_flag'    => "TINYINT(1) NOT NULL DEFAULT 0 AFTER deposit_amount",
    'electricity_monthly' => 'DECIMAL(18,2) NULL AFTER electricity_flag',
    'electricity_units'   => 'TINYINT UNSIGNED NULL AFTER electricity_monthly',
    'electricity_amount'  => 'DECIMAL(18,2) NULL AFTER electricity_units',
];
foreach ($tambah as $kol => $def) {
    if (!in_array($kol, $cols, true)) {
        $pdo->exec("ALTER TABLE skp_documents ADD COLUMN `$kol` $def");
    }
}
