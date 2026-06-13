<?php
// PIC sales pembuat penawaran (untuk tarik email/phone/tanda tangan ke surat).
$cols = array_column($pdo->query('SHOW COLUMNS FROM offers')->fetchAll(), 'Field');
if (!in_array('pic_name', $cols, true)) {
    $pdo->exec("ALTER TABLE offers ADD COLUMN pic_name VARCHAR(120) NULL AFTER contact_id");
}
