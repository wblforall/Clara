<?php
/**
 * Batas berlakunya aturan "income masuk setelah client menandatangani".
 *
 * Aturannya hanya mengikat kesepakatan yang dibuat MULAI tanggal ini. Yang
 * sudah berjalan sebelumnya tetap seperti semula: angkanya tetap terhitung dan
 * transaksinya tetap terlihat di daftar modul, walaupun dokumennya baru sampai
 * Manager. Memberlakukannya surut akan melepas miliaran rupiah dari laporan
 * belasan orang sekaligus — itu keputusan manajemen, bukan efek samping
 * pembaruan.
 *
 * Tanggalnya diisi saat migrasi ini dijalankan, yaitu saat pembaruan dipasang.
 * Bisa diubah lewat tabel settings bila memang perlu digeser.
 */
$ada = $pdo->query("SELECT COUNT(*) FROM settings WHERE `key` = 'ttd_wajib_mulai'")->fetchColumn();
if (!$ada) {
    $pdo->prepare("INSERT INTO settings (`key`, `value`) VALUES ('ttd_wajib_mulai', ?)")
        ->execute([date('Y-m-d')]);
    echo "     aturan TTD mulai berlaku: " . date('Y-m-d') . "\n";
}
