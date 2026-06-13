<?php
// Tanda tangan tersimpan untuk sales (master_pic) & manager (users), dipakai
// sebagai bukti "TTD terdaftar" pada validasi dokumen via QR. Di PDF, blok
// Dibuat Oleh (sales) & Mengetahui (manager) tampil sebagai QR → halaman
// validasi publik (skp_verify_page) memakai sign_token dokumen.
// Lihat [[project-offer-pipeline]].

$pcols = array_column($pdo->query('SHOW COLUMNS FROM master_pic')->fetchAll(), 'Field');
if (!in_array('signature_path', $pcols, true)) {
    $pdo->exec("ALTER TABLE master_pic ADD COLUMN signature_path VARCHAR(255) NULL");
}
$ucols = array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field');
if (!in_array('signature_path', $ucols, true)) {
    $pdo->exec("ALTER TABLE users ADD COLUMN signature_path VARCHAR(255) NULL");
}
