<?php
// Tambah kolom autentikasi yang belum ada di production
$cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'must_change_password'")->fetchColumn();
if (!$cols) {
    $pdo->exec("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
}

$cols2 = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_login_at'")->fetchColumn();
if (!$cols2) {
    $pdo->exec("ALTER TABLE users ADD COLUMN last_login_at DATETIME NULL DEFAULT NULL AFTER must_change_password");
}
