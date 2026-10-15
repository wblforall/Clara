<?php

/**
 * Narasi AI untuk Exec Summary.
 *
 * Satu baris per (jenis, periode): narasi dihasilkan SEKALI lalu dibaca dari
 * sini, jadi membuka halaman berkali-kali tidak menambah biaya dan teks yang
 * pernah dibaca direksi tidak berubah diam-diam. `fakta_hash` = sidik jari
 * angka yang dikirim ke AI; tombol "Perbarui" tidak memanggil AI lagi bila
 * angkanya belum berubah.
 *
 * @var PDO $pdo
 */

if ($pdo->query("SHOW TABLES LIKE 'ai_narasi'")->fetchColumn()) {
    echo "     ai_narasi sudah ada\n";
    return;
}

$pdo->exec("
    CREATE TABLE ai_narasi (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        jenis        VARCHAR(30)  NOT NULL,
        period_key   CHAR(7)      NOT NULL,
        teks         MEDIUMTEXT   NOT NULL,
        penyedia     VARCHAR(40)  NOT NULL DEFAULT 'Anthropic',
        model        VARCHAR(60)  NOT NULL,
        fakta_hash   CHAR(64)     NOT NULL,
        token_masuk  INT UNSIGNED NULL,
        token_keluar INT UNSIGNED NULL,
        dibuat_oleh  INT UNSIGNED NULL,
        dibuat_pada  DATETIME     NOT NULL,
        UNIQUE KEY uq_ai_narasi (jenis, period_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
echo "     ai_narasi dibuat\n";
