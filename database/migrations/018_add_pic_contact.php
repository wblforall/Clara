<?php
// Kontak PIC sales untuk Surat Penawaran: email & WhatsApp ("Bukti pembayaran
// di email ke ... atau WhatsApp ke ..." + blok tanda tangan sales).
// Lihat [[project-offer-pipeline]].

$cols = array_column($pdo->query('SHOW COLUMNS FROM master_pic')->fetchAll(), 'Field');

if (!in_array('email', $cols, true)) {
    $pdo->exec("ALTER TABLE master_pic ADD COLUMN email VARCHAR(190) NULL AFTER role_name");
}
if (!in_array('phone', $cols, true)) {
    $pdo->exec("ALTER TABLE master_pic ADD COLUMN phone VARCHAR(40) NULL AFTER email");
}
