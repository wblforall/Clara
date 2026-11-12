<?php
/**
 * "Kelola Template Penawaran" dibuka untuk SEMUA peran.
 *
 * Sampai sekarang yang boleh mengubah isi surat penawaran cuma administrasi &
 * asst_manager (warisan migrasi 108), ditambah satu sales lewat izin per-orang.
 * Pemilik CLARA memutuskan pembatasan itu tidak lagi diperlukan: template surat
 * adalah alat kerja sehari-hari tim, bukan pengaturan sistem.
 *
 * DUA izin, bukan satu. Halaman daftar template berada di balik `view_master`
 * (lihat permission_for_route di app/helpers.php) sementara tombol tambah,
 * simpan, duplikat, dan pratinjaunya di balik `manage_offer_templates`. Memberi
 * yang kedua saja membuat orang punya wewenang mengubah template tapi tidak
 * punya pintu untuk membukanya — menunya tidak pernah muncul.
 *
 * Yang TIDAK diubah: superadmin/admin tetap lolos lewat can(), dan izin
 * per-orang di user_permissions dibiarkan apa adanya (isinya hanya menambah,
 * tidak pernah mencabut).
 *
 * Bisa dibatalkan tanpa migrasi: hilangkan centangnya di Admin > Hak Akses.
 */

// Peran yang benar-benar ada di basis data, digabung dengan daftar baku —
// pemasangan baru yang tabel izinnya masih kosong tetap kebagian.
$adaDiDb = $pdo->query('SELECT DISTINCT role FROM role_permissions')->fetchAll(PDO::FETCH_COLUMN) ?: [];
$baku    = ['sales', 'administrasi', 'asst_manager', 'finance', 'supervisor', 'viewer'];
$peran   = array_values(array_unique(array_filter(array_merge($adaDiDb, $baku))));

$ins = $pdo->prepare('INSERT IGNORE INTO role_permissions (role, permission) VALUES (?, ?)');
$baris = 0;
foreach ($peran as $r) {
    foreach (['view_master', 'manage_offer_templates'] as $izin) {
        $ins->execute([$r, $izin]);
        $baris += $ins->rowCount();
    }
}
echo '     ' . count($peran) . ' peran diperiksa, ' . $baris . " izin baru ditambahkan\n";
echo "     (superadmin selalu punya akses penuh, tidak perlu baris izin)\n";
