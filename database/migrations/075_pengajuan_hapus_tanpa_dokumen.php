<?php
/**
 * Pengajuan penghapusan untuk transaksi YANG BELUM PUNYA DOKUMEN.
 *
 * Sebelum ini pengajuan selalu menunjuk sebuah dokumen SKP/SKS/FU, padahal
 * sebagian besar data yang perlu dihapus justru transaksi yang tidak pernah
 * dibuatkan dokumennya — input langsung dari menu Exhibition/Media/Gudang.
 * Akibatnya seluruh Gudang tidak bisa diajukan sama sekali, dan dari ratusan
 * transaksi Exhibition hanya puluhan yang muncul.
 *
 * Perbaikannya: skp_id boleh NULL. Pengajuan yang skp_id-nya kosong menunjuk
 * langsung ke transaction_id.
 */

// NOT NULL → NULL. Kolomnya tidak dibuang dan tidak ada data yang berubah;
// baris lama tetap menunjuk dokumennya seperti semula.
$kol = $pdo->query("SHOW COLUMNS FROM `deletion_requests` LIKE 'skp_id'")->fetch(PDO::FETCH_ASSOC);
if ($kol && strtoupper((string) $kol['Null']) === 'NO') {
    $pdo->exec("ALTER TABLE `deletion_requests` MODIFY `skp_id` INT UNSIGNED NULL
                COMMENT 'NULL = pengajuan atas transaksi yang belum punya dokumen'");
    echo "     deletion_requests.skp_id kini boleh NULL\n";
}

$idx = $pdo->query("SHOW INDEX FROM `deletion_requests` WHERE Key_name = 'idx_dr_trx'")->fetch();
if (!$idx) {
    $pdo->exec("ALTER TABLE `deletion_requests` ADD KEY `idx_dr_trx` (`property_id`, `transaction_id`)");
    echo "     indeks idx_dr_trx ditambahkan\n";
}
