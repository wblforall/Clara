<?php
// Tambah kolom cp_name (Nama Penanggung Jawab) ke skp_documents — terlewat di 013.

$cols = array_column($pdo->query('SHOW COLUMNS FROM skp_documents')->fetchAll(), 'Field');

if (!in_array('cp_name', $cols, true)) {
    $pdo->exec("ALTER TABLE skp_documents ADD COLUMN cp_name VARCHAR(190) NULL AFTER status");
}
