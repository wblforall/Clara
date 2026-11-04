<?php
/**
 * Izin tersendiri untuk Template Surat Penawaran, plus izin per-ORANG.
 *
 * Sebelumnya yang boleh mengubah template penawaran adalah pemegang
 * manage_master — izin yang sekaligus membuka unit, PIC, target, dan client.
 * Padahal yang dibutuhkan cuma mengubah isi surat. Jadi dipisah:
 * manage_offer_templates.
 *
 * Peran yang tadinya sudah bisa (administrasi & asst_manager, lewat
 * manage_master) diberi izin barunya supaya tidak ada yang kehilangan akses.
 *
 * Tabel user_permissions dipakai untuk kebutuhan yang tidak pas diwakili
 * peran: satu sales dipercaya mengubah template, tujuh sales lain tidak.
 * Isinya HANYA menambah izin, tidak pernah mencabut izin peran.
 */

$pdo->exec("CREATE TABLE IF NOT EXISTS user_permissions (
    user_id    INT NOT NULL,
    permission VARCHAR(80) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, permission),
    KEY idx_user_permissions_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "     tabel user_permissions siap\n";

// Peran yang sudah boleh lewat manage_master tetap boleh.
$ins = $pdo->prepare("INSERT IGNORE INTO role_permissions (role, permission)
                      SELECT role, 'manage_offer_templates' FROM role_permissions
                       WHERE permission = 'manage_master'");
$ins->execute();
echo "     {$ins->rowCount()} peran ikut dapat manage_offer_templates\n";

// Zulfikar: diminta langsung oleh pemilik CLARA. Hanya dia, bukan seluruh
// peran sales — tujuh sales lain tidak ikut bisa mengubah surat ke client.
$uid = $pdo->query("SELECT id FROM users WHERE email = 'zulfikar@ewalk-bsb.com' LIMIT 1")->fetchColumn();
if ($uid) {
    $pdo->prepare('INSERT IGNORE INTO user_permissions (user_id, permission) VALUES (?, ?)')
        ->execute([(int) $uid, 'manage_offer_templates']);
    echo "     Zulfikar (user $uid) diberi izin Kelola Template Penawaran\n";
} else {
    echo "     Zulfikar tidak ditemukan — izinnya bisa dicentang di Users > Edit\n";
}
