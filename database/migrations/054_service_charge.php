<?php
// Service Charge (SC) pada Surat Penawaran & dokumen konfirmasi.
//
// Sebagian penyewa dikenakan service charge bulanan di samping sewa. Di kertas
// ia tercetak sebagai blok tersendiri (poin C) berisi biaya SC per bulan,
// PPN-nya, total per bulan, dan total untuk seluruh masa sewa.
//
// sc_flag    0 = tidak dikenakan (bawaan)   1 = dikenakan
// sc_monthly nominal SC per bulan (sebelum PPN)

foreach (['offers', 'skp_documents'] as $tabel) {
    $cols = array_column($pdo->query("SHOW COLUMNS FROM `$tabel`")->fetchAll(), 'Field');
    if (!in_array('sc_flag', $cols, true)) {
        $pdo->exec("ALTER TABLE `$tabel` ADD COLUMN `sc_flag` TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!in_array('sc_monthly', $cols, true)) {
        $pdo->exec("ALTER TABLE `$tabel` ADD COLUMN `sc_monthly` DECIMAL(18,2) NULL AFTER `sc_flag`");
    }
}
