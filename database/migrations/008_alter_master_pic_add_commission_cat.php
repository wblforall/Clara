<?php
// Tambah dua kolom ke master_pic:
// commission_cat: kategori komisi (NULL = tidak dapat)
// show_achievement: tampil di tabel Achievement PIC (1=ya, 0=tidak)
$col = $pdo->query("SHOW COLUMNS FROM master_pic LIKE 'commission_cat'")->fetchColumn();
if (!$col) {
    $pdo->exec("ALTER TABLE master_pic ADD COLUMN commission_cat VARCHAR(20) DEFAULT NULL AFTER role_name");
}
$col2 = $pdo->query("SHOW COLUMNS FROM master_pic LIKE 'show_achievement'")->fetchColumn();
if (!$col2) {
    $pdo->exec("ALTER TABLE master_pic ADD COLUMN show_achievement TINYINT(1) NOT NULL DEFAULT 1 AFTER commission_cat");
}
