<?php
/**
 * Pisahkan izin MELIHAT tagihan dari izin MENGISI-nya.
 *
 * Sejak Tagihan & Pembayaran terbit, satu-satunya izinnya adalah
 * `manage_payments`, dan izin itu menggerbangi dua hal sekaligus: membuka
 * halamannya DAN mengubah angkanya. Akibatnya Manager — yang perlu memeriksa
 * sudah ditagih atau belum sebelum menyetujui sesuatu — tidak bisa membuka
 * halamannya sama sekali, dan lencana status bayar pun tidak muncul di daftar
 * Exhibition/Media/Gudang miliknya.
 *
 * Sesudah migrasi ini:
 *   view_payments    membuka halaman, mengunduh CSV, melihat lencana status
 *   manage_payments  mengisi invoice, mencatat & menghapus pembayaran
 *
 * Pemegang manage_payments otomatis diberi view_payments juga, supaya tidak
 * ada peran yang harus diingat untuk diberi dua izin sekaligus.
 *
 * Peran yang diberi izin melihat di sini: administrasi, sales, dan viewer
 * (peran yang dipakai Manager & Asst. Manager). Finance dan supervisor
 * sengaja TIDAK diikutkan — kalau memang perlu, tinggal dicentang di
 * halaman Users → Roles, tanpa perlu migrasi lagi.
 */

if (!$pdo->query("SHOW TABLES LIKE 'role_permissions'")->fetchColumn()) {
    echo "     tabel role_permissions belum ada — dilewati\n";
    return;
}

$beri = $pdo->prepare('INSERT IGNORE INTO role_permissions (role, permission) VALUES (?, ?)');

// 1. Semua yang sudah boleh mengisi, otomatis boleh melihat.
$pengisi = $pdo->query("SELECT role FROM role_permissions WHERE permission = 'manage_payments'")
               ->fetchAll(PDO::FETCH_COLUMN);
foreach ($pengisi as $peran) {
    $beri->execute([$peran, 'view_payments']);
}
if ($pengisi) {
    echo "     view_payments mengikuti manage_payments: " . implode(', ', $pengisi) . "\n";
}

// 2. Manager & Asst. Manager memakai peran 'viewer' di CLARA. Mereka diberi
//    izin melihat saja — tidak mengisi.
$beri->execute(['viewer', 'view_payments']);
echo "     view_payments diberikan ke viewer (Manager & Asst. Manager) — hanya melihat\n";

// 3. Jaring pengaman: peran yang belum punya izin mengisi tidak boleh
//    kecolongan mendapatkannya dari migrasi ini.
$st = $pdo->query("SELECT role, GROUP_CONCAT(permission ORDER BY permission SEPARATOR ', ') izin
                     FROM role_permissions
                    WHERE permission IN ('view_payments', 'manage_payments')
                    GROUP BY role ORDER BY role");
foreach ($st as $r) {
    echo "     {$r['role']}: {$r['izin']}\n";
}
