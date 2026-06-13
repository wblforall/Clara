<?php
// Tanda tangan online customer untuk SKP (canvas + metadata).
// Setelah manager approve, sistem buat sign_token → link publik dikirim ke
// customer → customer tanda tangan (canvas) → status 'signed'.

$cols = array_column($pdo->query('SHOW COLUMNS FROM skp_documents')->fetchAll(), 'Field');

$add = [
    'sign_token'     => "VARCHAR(64) NULL",
    'sign_name'      => "VARCHAR(190) NULL",
    'sign_ip'        => "VARCHAR(45) NULL",
    'sign_ua'        => "VARCHAR(255) NULL",
    'signature_path' => "VARCHAR(255) NULL",
    'signed_at'      => "DATETIME NULL",
];
foreach ($add as $col => $def) {
    if (!in_array($col, $cols, true)) {
        $pdo->exec("ALTER TABLE skp_documents ADD COLUMN $col $def");
    }
}

// index untuk lookup token cepat
$idx = $pdo->query("SHOW INDEX FROM skp_documents WHERE Key_name = 'idx_sign_token'")->fetchAll();
if (!$idx) {
    $pdo->exec("ALTER TABLE skp_documents ADD INDEX idx_sign_token (sign_token)");
}
