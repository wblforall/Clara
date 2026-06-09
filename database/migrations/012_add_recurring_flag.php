<?php
// Tambah kolom recurring_flag ke transactions.
// Penanda MANUAL "Diakui Recurring" dari sales saat input bulanan (anchor_cycle),
// tanpa mengubah billing_method / nominal. Dipakai bersama auto-deteksi recurring
// (lihat helper recurring_match_sql()).

$existing = array_column(
    $pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(),
    'Field'
);

if (!in_array('recurring_flag', $existing)) {
    $pdo->exec(
        "ALTER TABLE transactions
         ADD COLUMN recurring_flag TINYINT(1) NOT NULL DEFAULT 0 AFTER billing_method"
    );
}
