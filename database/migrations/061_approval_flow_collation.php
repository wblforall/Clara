<?php
// Samakan collation kolom doc_type dengan skp_documents.
//
// skp_approval_flow dibuat dengan utf8mb4_unicode_ci (mengikuti tabel-tabel
// baru lainnya), sedangkan skp_documents.doc_type memakai utf8mb4_general_ci.
// Selama perbandingannya lewat parameter PHP tidak ada masalah, tetapi begitu
// ada query yang membandingkan KEDUA KOLOM langsung — misalnya laporan atau
// skrip pemeliharaan yang menyambung skp_documents ke skp_approval_flow —
// MariaDB menolaknya dengan "Illegal mix of collations" dan querynya gagal
// seluruhnya. Lebih baik diluruskan sekarang selagi datanya masih sedikit.

$kol = $pdo->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'skp_approval_flow' AND COLUMN_NAME = 'doc_type'")->fetchColumn();

$target = $pdo->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = 'skp_documents' AND COLUMN_NAME = 'doc_type'")->fetchColumn();

if ($kol && $target && $kol !== $target) {
    $charset = explode('_', (string) $target)[0];
    $pdo->exec("ALTER TABLE `skp_approval_flow`
                MODIFY `doc_type` VARCHAR(20) CHARACTER SET {$charset} COLLATE {$target} NULL");
}
