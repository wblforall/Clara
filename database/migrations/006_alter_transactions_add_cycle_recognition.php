<?php
// Tambah kolom cycle_recognition di transactions jika belum ada
$col = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'cycle_recognition'")->fetchColumn();
if (!$col) {
    $pdo->exec("ALTER TABLE transactions ADD COLUMN cycle_recognition VARCHAR(20) NOT NULL DEFAULT 'cycle_start' AFTER billing_method");
}
