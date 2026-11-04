<?php
/**
 * Asst. Manager boleh mengubah Master Data.
 *
 * Sampai sekarang perannya hanya punya `view_master` — bisa melihat Master
 * Exhibition tetapi tidak bisa membetulkan satu baris pun, padahal merekalah
 * yang paling sering menemukan unit yang salah luas, salah tarif, atau belum
 * terdaftar sama sekali. Setiap koreksi kecil harus menunggu Admin Sales.
 *
 * Dua izin, karena Master Data memang dijaga dua izin yang berbeda:
 *   - manage_units  : Exhibition, Tipe Unit, Media, Gudang  ← Master Exhibition
 *   - manage_master : Client, Referrer, Hari Libur, Template Penawaran
 * Keduanya diberikan supaya seluruh Master Data bisa dirapikan tanpa harus
 * menebak izin mana yang menjaga halaman yang mana.
 */

$st = $pdo->prepare('INSERT IGNORE INTO role_permissions (role, permission) VALUES (?, ?)');
foreach (['manage_units', 'manage_master'] as $izin) {
    $st->execute(['asst_manager', $izin]);
    echo $st->rowCount() > 0
        ? "     asst_manager: $izin ditambahkan\n"
        : "     asst_manager: $izin sudah ada\n";
}

foreach ($pdo->query("SELECT name, email FROM users WHERE role = 'asst_manager' AND status = 'active'")
             ->fetchAll(PDO::FETCH_ASSOC) as $u) {
    echo "       berlaku untuk: {$u['name']} <{$u['email']}>\n";
}
