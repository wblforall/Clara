<?php
// Penagihan PPN 12% bisa dimatikan per dokumen.
//
// Sebagian penyewa lama membayar sewa dengan harga bersih — PPN-nya sudah
// diselesaikan di luar sistem, sehingga suratnya memang tidak pernah
// mencantumkan PPN. Kolom ini membuat perilaku itu bisa dipilih per dokumen
// tanpa mengubah dokumen lain.
//
// 1 = harga dikenakan PPN 12% (bawaan, seperti selama ini)
// 0 = harga bersih, baris PPN tidak dicetak sama sekali

foreach (['offers', 'skp_documents'] as $tabel) {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM `$tabel`")->fetchAll(), 'Field');
    if (!in_array('ppn_flag', $cols, true)) {
        $pdo->exec("ALTER TABLE `$tabel` ADD COLUMN `ppn_flag` TINYINT(1) NOT NULL DEFAULT 1");
    }
}
