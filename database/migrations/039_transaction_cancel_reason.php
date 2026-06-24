<?php
// Batal-sebagian komponen paket: alasan pembatalan wajib (lihat [[project-bundling-package]]).
// Sebuah transaksi (termasuk satu komponen paket) bisa dibatalkan oleh manajer
// (approve_skp) dengan ALASAN WAJIB. Kolom ini menyimpan alasan tsb; soft-delete
// tetap pakai deleted_at/deleted_by yang sudah ada.
$cols = $pdo->query("SHOW COLUMNS FROM transactions")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('cancel_reason', $cols, true)) {
    $pdo->exec("ALTER TABLE transactions ADD COLUMN cancel_reason VARCHAR(255) NULL AFTER deleted_by");
}
