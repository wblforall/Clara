<?php
// Tambah kolom billing_method di transactions jika belum ada
$col = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'billing_method'")->fetchColumn();
if (!$col) {
    $pdo->exec("ALTER TABLE transactions ADD COLUMN billing_method VARCHAR(40) NOT NULL DEFAULT 'anchor_cycle' AFTER contract_months");
}
