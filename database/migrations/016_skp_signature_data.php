<?php
// Simpan tanda tangan customer sebagai data URL base64 di DB (bukan file),
// agar tidak bergantung pada izin tulis folder di server.

$cols = array_column($pdo->query('SHOW COLUMNS FROM skp_documents')->fetchAll(), 'Field');

if (!in_array('signature_data', $cols, true)) {
    $pdo->exec("ALTER TABLE skp_documents ADD COLUMN signature_data MEDIUMTEXT NULL");
}
