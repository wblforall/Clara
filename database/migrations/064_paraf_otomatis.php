<?php
// Paraf: mode penempatan otomatis, gambar hasil coretan, dan waktu opsional.
//
// Tiga hal yang berubah dari 063:
//
// 1) MODE OTOMATIS. Mengatur koordinat paraf satu per satu untuk setiap jenis
//    dokumen ternyata merepotkan dan mudah salah: tata letak SKP, SKS, dan Form
//    Utilities berbeda, dan panjang isinya pun berubah-ubah sehingga titik yang
//    pas hari ini bisa menabrak tabel besok. Yang sebenarnya diinginkan selalu
//    sama — paraf pemeriksa duduk di sebelah KANAN ATAS QR Manager, dekat tapi
//    tidak bertabrakan. Itu bisa ditentukan sendiri oleh dokumennya saat
//    dicetak, tanpa koordinat apa pun. Mode manual tetap ada untuk yang ingin
//    menaruhnya di tempat lain.
//
// 2) doc_type 'all'. Dalam mode otomatis tidak ada yang perlu dibedakan antar
//    jenis dokumen, jadi satu baris berlaku untuk semuanya. Baris khusus per
//    jenis dokumen tetap boleh ada dan didahulukan bila dibuat.
//
// 3) WAKTU DIPISAH DARI NAMA. Sebelumnya satu centang mengatur keduanya,
//    sehingga mematikan jam berarti ikut menghilangkan nama. Sekarang dua
//    centang terpisah, dan jam MATI secara bawaan — di paraf kertas memang
//    tidak pernah ada jam.

$kol = $pdo->query("SHOW COLUMNS FROM paraf_settings LIKE 'mode'")->fetchColumn();
if (!$kol) {
    $pdo->exec("ALTER TABLE `paraf_settings`
                ADD COLUMN `mode` VARCHAR(10) NOT NULL DEFAULT 'otomatis' AFTER `doc_type`");
    // Pengaturan yang sudah terlanjur dibuat memang dipasang manual — jangan
    // dipindah diam-diam ke mode otomatis, posisinya sudah dipilih sendiri.
    $pdo->exec("UPDATE `paraf_settings` SET `mode` = 'manual'");
}

$kol = $pdo->query("SHOW COLUMNS FROM paraf_settings LIKE 'tampil_waktu'")->fetchColumn();
if (!$kol) {
    $pdo->exec("ALTER TABLE `paraf_settings`
                ADD COLUMN `tampil_waktu` TINYINT NOT NULL DEFAULT 0 AFTER `tampil_nama`");
    // Yang lama mencetak nama DAN waktu dalam satu centang — pertahankan apa
    // adanya supaya cetakan ulang dokumen lama tidak berubah tampilannya.
    $pdo->exec("UPDATE `paraf_settings` SET `tampil_waktu` = `tampil_nama`");
}
