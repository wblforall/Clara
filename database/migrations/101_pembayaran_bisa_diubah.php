<?php
/**
 * Jejak perubahan untuk pembayaran yang sudah dicatat.
 *
 * Sampai sekarang baris pembayaran hanya bisa DIHAPUS. Kalau tanggal atau
 * jumlahnya salah ketik, tim menghapusnya lalu mencatat ulang — dan baris
 * yang dihapus itu tetap tersimpan dengan penanda, sehingga riwayat satu
 * transaksi penuh oleh baris mati yang sebenarnya cuma salah ketik.
 *
 * Dua kolom ini membuat baris pembayaran bisa diperbaiki di tempat, dengan
 * jejak siapa yang terakhir mengubahnya. Isi baris lama dibiarkan NULL —
 * artinya "belum pernah diubah sejak dicatat", bukan data hilang.
 */

$kol = array_column($pdo->query('SHOW COLUMNS FROM `transaction_payments`')->fetchAll(PDO::FETCH_ASSOC), 'Field');
foreach ([
    'updated_at' => 'DATETIME NULL',
    'updated_by' => 'VARCHAR(120) NULL',
] as $nama => $tipe) {
    if (in_array($nama, $kol, true)) {
        echo "     transaction_payments.$nama sudah ada\n";
        continue;
    }
    $pdo->exec("ALTER TABLE `transaction_payments` ADD COLUMN `$nama` $tipe");
    echo "     transaction_payments.$nama ditambahkan\n";
}
