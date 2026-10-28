<?php
/**
 * Nomor kontrak yang diterbitkan Legal — satu isian yang menutup tiga keluhan.
 *
 * Alur hari ini terputus di satu titik: SKP yang sudah diteken client diajukan
 * lewat Permintaan Kontrak, Legal menyetujuinya di CLARA, lalu Legal mencetak
 * kontrak dan memberinya nomor — tetapi nomor itu tidak pernah kembali masuk.
 * Akibatnya tim meminta rekap kontrak ke Legal berulang-ulang, padahal dari 24
 * kolom rekap itu, 23 sudah ada di CLARA. Yang benar-benar dicocokkan setiap
 * kali hanyalah nomor kontrak dan tanggal cetaknya.
 *
 * Nomor itu juga dasar penerbitan invoice, dan di berkas Income Marketing
 * letaknya di kolom kedua — tim menyebut pekerjaannya dengan nomor ini, bukan
 * dengan nama unit atau nama client.
 *
 * Disimpan di contract_requests karena di situlah permintaannya hidup, dan
 * dari sana mengalir ke Tagihan & Pembayaran lewat SKP → transaksi.
 */

if (!$pdo->query("SHOW TABLES LIKE 'contract_requests'")->fetchColumn()) {
    echo "     tabel contract_requests belum ada — dilewati\n";
    return;
}

$kol = array_column($pdo->query('SHOW COLUMNS FROM `contract_requests`')->fetchAll(PDO::FETCH_ASSOC), 'Field');

if (!in_array('contract_no', $kol, true)) {
    $pdo->exec("ALTER TABLE `contract_requests` ADD COLUMN `contract_no` VARCHAR(80) NULL DEFAULT NULL AFTER `legal_note`");
    echo "     contract_requests.contract_no ditambahkan\n";
}
if (!in_array('contract_date', $kol, true)) {
    $pdo->exec("ALTER TABLE `contract_requests` ADD COLUMN `contract_date` DATE NULL DEFAULT NULL AFTER `contract_no`");
    echo "     contract_requests.contract_date ditambahkan\n";
}

// Dicari lewat kolom ini di halaman Tagihan & Pembayaran.
$idx = $pdo->query("SHOW INDEX FROM `contract_requests` WHERE Key_name = 'idx_contract_no'")->fetchAll();
if (!$idx) {
    $pdo->exec("ALTER TABLE `contract_requests` ADD INDEX `idx_contract_no` (`contract_no`)");
    echo "     indeks idx_contract_no dibuat\n";
}
