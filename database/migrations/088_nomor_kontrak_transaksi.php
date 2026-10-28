<?php
/**
 * Nomor kontrak menempel di TRANSAKSI, bukan hanya di permintaan kontrak.
 *
 * Migrasi 086 menaruhnya di contract_requests, tempat Legal mengisinya. Tetapi
 * di lapangan admin kerap sudah memegang nomornya lebih dulu — dari berkas
 * Income Marketing, dari WhatsApp Legal, atau dari kontrak yang sudah dicetak —
 * sementara permintaan kontraknya sendiri belum tentu ada di CLARA. Selama
 * nomornya hanya boleh lahir dari Legal, invoice tetap tidak bisa ditulis
 * lengkap di sini.
 *
 * Maka nomor kontrak disimpan di transaksinya: itu satuan yang ditagih, dan di
 * situlah invoice dibuat. Legal tetap jadi sumber resminya — begitu ia mengisi
 * di Permintaan Kontrak, nomornya mengalir turun ke transaksi milik SKP itu
 * (satu penawaran paket melahirkan beberapa transaksi, dan memang satu nomor
 * berlaku untuk semuanya). Admin bisa mengetik atau membetulkannya langsung di
 * halaman Tagihan & Pembayaran.
 */

$kol = array_column($pdo->query('SHOW COLUMNS FROM `transactions`')->fetchAll(PDO::FETCH_ASSOC), 'Field');

if (!in_array('contract_no', $kol, true)) {
    $pdo->exec("ALTER TABLE `transactions` ADD COLUMN `contract_no` VARCHAR(80) NULL DEFAULT NULL AFTER `invoice_no`");
    echo "     transactions.contract_no ditambahkan\n";
}
if (!in_array('contract_date', $kol, true)) {
    $pdo->exec("ALTER TABLE `transactions` ADD COLUMN `contract_date` DATE NULL DEFAULT NULL AFTER `contract_no`");
    echo "     transactions.contract_date ditambahkan\n";
}
if (!$pdo->query("SHOW INDEX FROM `transactions` WHERE Key_name = 'idx_trx_contract_no'")->fetchAll()) {
    $pdo->exec("ALTER TABLE `transactions` ADD INDEX `idx_trx_contract_no` (`contract_no`)");
    echo "     indeks idx_trx_contract_no dibuat\n";
}

// Nomor yang sudah terlanjur diisi Legal dipindahkan turun sekali di sini,
// supaya halaman tagihan tidak perlu menelusuri rantainya setiap kali tampil.
if ($pdo->query("SHOW TABLES LIKE 'contract_requests'")->fetchColumn()) {
    $ada = array_column($pdo->query('SHOW COLUMNS FROM `contract_requests`')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (in_array('contract_no', $ada, true)) {
        $n = $pdo->exec("
            UPDATE transactions t
              JOIN skp_documents sd ON (sd.transaction_id = t.id OR sd.id = t.skp_id)
              JOIN contract_requests cr ON cr.skp_id = sd.id
               SET t.contract_no = cr.contract_no, t.contract_date = cr.contract_date
             WHERE t.deleted_at IS NULL
               AND (t.contract_no IS NULL OR t.contract_no = '')
               AND cr.contract_no IS NOT NULL AND cr.contract_no <> ''
        ");
        echo "     $n transaksi menerima nomor kontrak dari Legal\n";
    }
}
