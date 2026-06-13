<?php
// Toggle per-PIC: tampil/tidak di dropdown PIC pada form Surat Penawaran.
// Diset awal = 1 hanya untuk kategori 'sales' (selain itu 0) agar dropdown
// langsung bersih; admin bisa ubah di Master PIC. PIC baru default tampil.
// Lihat [[project-offer-pipeline]].

$cols = array_column($pdo->query('SHOW COLUMNS FROM master_pic')->fetchAll(), 'Field');
if (!in_array('show_in_offer', $cols, true)) {
    $pdo->exec("ALTER TABLE master_pic ADD COLUMN show_in_offer TINYINT(1) NOT NULL DEFAULT 1");
    // Inisialisasi: hanya sales yang tampil; admin/manager/asst/unit disembunyikan.
    $pdo->exec("UPDATE master_pic SET show_in_offer = CASE WHEN commission_cat = 'sales' THEN 1 ELSE 0 END");
}
