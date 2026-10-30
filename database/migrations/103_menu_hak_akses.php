<?php
/**
 * Penataan menu & hak akses (30 Okt 2026, keputusan Affan).
 *
 * - Sales tidak lagi "Kelola Semua Master Data": tetap boleh mengubah Client
 *   dan unit (Exhibition, Tipe Unit, Media, Gudang) yang memang ia rawat
 *   sehari-hari, tapi PIC/Target, Hari Libur, Referrer, Template, Opsi
 *   Dropdown, dan Konversi Recurring hanya untuk pengelola master.
 * - Sales mendapat Renewal Kontrak (halaman itu sudah membatasi sales ke
 *   kontrak miliknya sendiri).
 * - Laporan PIC: izin baru view_all_pic. Tanpa izin itu (sales) laporan hanya
 *   menampilkan PIC milik akunnya sendiri.
 * - Peran baru asst_manager (Asst. Manager) = izin viewer + ubah PIC & target
 *   sales. Ocha (E-Walk) dan Yusri Yusuf (Pentacity) dipindah ke sana; alur
 *   approval SKP memakai nama orang, bukan nama peran, jadi tidak terpengaruh.
 *
 * Aman diulang: semua penambahan memakai INSERT IGNORE.
 */

$tambah = $pdo->prepare('INSERT IGNORE INTO role_permissions (role, permission) VALUES (?, ?)');
$punya  = function (string $role) use ($pdo): array {
    $st = $pdo->prepare('SELECT permission FROM role_permissions WHERE role = ?');
    $st->execute([$role]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
};

// Pemegang manage_master tetap bisa semua yang kini dipecah jadi izin sendiri.
foreach ($pdo->query("SELECT DISTINCT role FROM role_permissions WHERE permission = 'manage_master'")->fetchAll(PDO::FETCH_COLUMN) as $role) {
    if ($role === 'sales') continue;
    foreach (['manage_clients', 'manage_units', 'manage_pic_target'] as $p) $tambah->execute([$role, $p]);
    echo "     $role: + manage_clients, manage_units, manage_pic_target\n";
}

// Sales: client + unit, tanpa master lain; renewal miliknya sendiri.
$n = $pdo->exec("DELETE FROM role_permissions WHERE role = 'sales' AND permission = 'manage_master'");
foreach (['manage_clients', 'manage_units', 'view_renewals', 'manage_renewals'] as $p) $tambah->execute(['sales', $p]);
echo "     sales: - manage_master ($n), + manage_clients, manage_units, view_renewals, manage_renewals\n";

// Lihat semua PIC: semua peran yang sudah membuka Laporan PIC, kecuali sales.
foreach ($pdo->query("SELECT DISTINCT role FROM role_permissions WHERE permission = 'view_pic_report' AND role <> 'sales'")->fetchAll(PDO::FETCH_COLUMN) as $role) {
    $tambah->execute([$role, 'view_all_pic']);
    echo "     $role: + view_all_pic\n";
}

// Asst. Manager = salinan izin viewer + ubah PIC & target sales.
foreach ($punya('viewer') as $p) $tambah->execute(['asst_manager', $p]);
foreach (['manage_pic_target', 'view_all_pic'] as $p) $tambah->execute(['asst_manager', $p]);
echo "     asst_manager: " . count($punya('asst_manager')) . " izin\n";

$pindah = $pdo->prepare("UPDATE users SET role = 'asst_manager' WHERE LOWER(email) = ? AND role = 'viewer'");
foreach (['frocha.ewalk@gmail.com', 'yusriyusuf62@gmail.com'] as $email) {
    $pindah->execute([$email]);
    echo "     $email → asst_manager (" . $pindah->rowCount() . " baris)\n";
}
