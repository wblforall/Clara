<?php
// Dokumen Gudang (SKS) & Media (Form Utilities) dibuat LANGSUNG — tidak lagi
// menumpang Surat Penawaran maupun transaksi yang sudah ada. Karena itu datanya
// (client, unit, periode, nilai) harus bisa disimpan di dokumen itu sendiri.
//
// Transaksinya terbit otomatis saat manager menyetujui, sama seperti SKP
// Exhibition yang lahir dari penawaran DEAL.

$cols = array_column($pdo->query('SHOW COLUMNS FROM skp_documents')->fetchAll(), 'Field');

$tambah = [
    'module'       => "VARCHAR(10) NULL AFTER doc_type",
    'client_id'    => 'INT UNSIGNED NULL AFTER module',
    'contact_id'   => 'INT UNSIGNED NULL AFTER client_id',
    'master_code'  => 'VARCHAR(60) NULL AFTER contact_id',
    'start_date'   => 'DATE NULL AFTER master_code',
    'end_date'     => 'DATE NULL AFTER start_date',
    'unit_rate'    => 'DECIMAL(18,2) NULL AFTER end_date',
    'total_amount' => 'DECIMAL(18,2) NULL AFTER unit_rate',
    'pic_name'     => 'VARCHAR(120) NULL AFTER total_amount',
];
foreach ($tambah as $kol => $def) {
    if (!in_array($kol, $cols, true)) {
        $pdo->exec("ALTER TABLE skp_documents ADD COLUMN `$kol` $def");
    }
}

// Dokumen lama: isi kolom module supaya jenisnya tetap terbaca walau sumbernya
// (offer/transaksi) dihapus suatu saat.
$pdo->exec("UPDATE skp_documents SET module = CASE doc_type WHEN 'sks' THEN 'gudang' WHEN 'fu' THEN 'media' ELSE 'cl' END WHERE module IS NULL");
