<?php
/**
 * Prospek — tempat untuk calon yang belum pasti ("client kuning").
 *
 * Ini satu-satunya hal dari daftar keluhan tim yang benar-benar belum ada di
 * CLARA, dan yang paling menahan mereka pindah dari spreadsheet. Di Google
 * Sheet, PIC menandai kuning calon client yang belum deal — belum ada
 * dokumen, belum tentu jadi — tetapi nilainya tetap ditulis dan ikut
 * dijumlahkan sebagai "tabungan". Dari situ mereka menakar: kalau semua yang
 * kuning jadi, target bulan ini tercapai atau tidak?
 *
 * CLARA baru mencatat sejak surat penawaran resmi terbit, jadi calon yang
 * masih dibicarakan tidak punya tempat sama sekali. Selama itu belum ada, tim
 * tetap harus kembali ke spreadsheet untuk memperkirakan pencapaian bulan
 * berjalan.
 *
 * Yang disimpan sengaja seminim mungkin — unit, perkiraan tanggal, perkiraan
 * nilai, calon penyewa — karena pada tahap ini memang belum ada data resmi.
 * Angkanya TIDAK pernah ikut ke pencapaian resmi, laporan, atau income; ia
 * hanya ditampilkan terpisah, persis seperti fungsi warna kuning hari ini.
 */

if ($pdo->query("SHOW TABLES LIKE 'prospects'")->fetchColumn()) {
    echo "     tabel prospects sudah ada\n";
} else {
    $pdo->exec("
        CREATE TABLE `prospects` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `property_id`   TINYINT NOT NULL DEFAULT 1,
            `module`        VARCHAR(10) NOT NULL DEFAULT 'cl',
            `master_code`   VARCHAR(40) NULL DEFAULT NULL,
            `client_name`   VARCHAR(190) NOT NULL,
            `pic_name`      VARCHAR(120) NULL DEFAULT NULL,
            `start_date`    DATE NOT NULL,
            `end_date`      DATE NOT NULL,
            `est_amount`    DECIMAL(18,2) NOT NULL DEFAULT 0,
            `confidence`    VARCHAR(10) NOT NULL DEFAULT 'sedang',
            `note`          VARCHAR(255) NULL DEFAULT NULL,
            `status`        VARCHAR(12) NOT NULL DEFAULT 'open',
            `offer_id`      INT UNSIGNED NULL DEFAULT NULL,
            `created_by`    VARCHAR(120) NULL DEFAULT NULL,
            `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`    DATETIME NULL DEFAULT NULL,
            `deleted_at`    DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_prospek_bulan` (`property_id`, `module`, `start_date`, `end_date`),
            KEY `idx_prospek_pic` (`pic_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "     tabel prospects dibuat\n";
}

// Izin: mengisi prospek mengikuti yang boleh membuat penawaran (sales & admin);
// melihatnya mengikuti yang boleh membuka kalender, jadi tidak perlu izin baru.
if ($pdo->query("SHOW TABLES LIKE 'role_permissions'")->fetchColumn()) {
    $beri = $pdo->prepare('INSERT IGNORE INTO role_permissions (role, permission) VALUES (?, ?)');
    $n = 0;
    foreach ($pdo->query("SELECT DISTINCT role FROM role_permissions WHERE permission = 'manage_offers'")->fetchAll(PDO::FETCH_COLUMN) as $role) {
        $beri->execute([$role, 'manage_prospects']);
        $n += $beri->rowCount();
    }
    // Administrasi memang tidak punya manage_offers, tapi merekalah yang paling
    // sering merapikan daftar ini bersama sales.
    $beri->execute(['administrasi', 'manage_prospects']);
    $n += $beri->rowCount();
    echo "     izin manage_prospects diberikan ke $n peran\n";
}
