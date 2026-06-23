<?php
// Masa berlaku token link Legal (contract_legal / contract_legal_print).
// Diisi saat formulir (re)kirim: NOW() + 14 hari. NULL = legacy/tanpa kedaluwarsa.
// Lihat [[project-offer-pipeline]].

$cols = array_column($pdo->query('SHOW COLUMNS FROM contract_requests')->fetchAll(), 'Field');
if (!in_array('share_token_expires_at', $cols, true)) {
    $pdo->exec("ALTER TABLE contract_requests ADD COLUMN share_token_expires_at DATETIME NULL");
}
