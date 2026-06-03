<?php
// Tambah kolom sort_order ke tiga tabel master agar urutan dropdown bisa dikontrol manual
foreach (['master_cl_units', 'master_gudang', 'master_media'] as $table) {
    $exists = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'sort_order'")->fetchColumn();
    if (!$exists) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER status");
    }
}
