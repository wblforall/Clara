<?php
// Tambah kolom pelacakan renewal kontrak ke transactions (Opsi 2 — Papan Renewal)
// Data expiry (end_date, contract_months, billing_method) sudah ada; ini hanya
// menambah status tindak lanjut renewal yang dikelola manual oleh PIC.

$existing = array_column(
    $pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(),
    'Field'
);

if (!in_array('renewal_status', $existing)) {
    // none | contacted | negotiating | will_renew | renewed | churned
    $pdo->exec(
        "ALTER TABLE transactions
         ADD COLUMN renewal_status VARCHAR(20) NOT NULL DEFAULT 'none' AFTER status"
    );
}
if (!in_array('renewal_note', $existing)) {
    $pdo->exec(
        "ALTER TABLE transactions
         ADD COLUMN renewal_note TEXT DEFAULT NULL AFTER renewal_status"
    );
}
if (!in_array('renewal_updated_at', $existing)) {
    $pdo->exec(
        "ALTER TABLE transactions
         ADD COLUMN renewal_updated_at DATETIME DEFAULT NULL AFTER renewal_note"
    );
}
if (!in_array('renewal_updated_by', $existing)) {
    $pdo->exec(
        "ALTER TABLE transactions
         ADD COLUMN renewal_updated_by VARCHAR(255) DEFAULT NULL AFTER renewal_updated_at"
    );
}
