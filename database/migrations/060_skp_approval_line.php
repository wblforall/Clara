<?php
// Line approval berjenjang untuk SKP / SKS / Form Utilities.
//
// Sebelum ada aplikasi, SKP diperiksa & diparaf Asst. Manager dulu, baru naik ke
// Manager. Di aplikasi langkah tengah itu hilang — dokumen langsung ke Manager,
// sehingga perannya Asst. Manager tidak terpakai dan koreksi sering terlewat.
//
// Rantainya diatur per PROPERTI dan berbasis JABATAN (master_pic.role_name),
// bukan nama orang yang ditanam di kode — supaya saat orangnya berganti, alurnya
// tidak perlu diubah. Boleh juga dikunci ke satu orang tertentu (pic_name).
//
// PENTING: tidak ada nilai status baru di skp_documents.status. Dokumen yang
// sedang berjalan tetap 'submitted' sampai tahap TERAKHIR disetujui — barulah
// nomor dokumen terbit dan transaksinya lahir, persis seperti sekarang.
// Properti yang belum punya baris konfigurasi berjalan seperti semula:
// satu tahap, langsung ke pemegang izin approve_skp.

$tabel = $pdo->query("SHOW TABLES LIKE 'skp_approval_flow'")->fetchColumn();
if (!$tabel) {
    $pdo->exec(
        "CREATE TABLE `skp_approval_flow` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `property_id` TINYINT NOT NULL DEFAULT 1,
            `step_no` SMALLINT NOT NULL DEFAULT 1,
            `role_name` VARCHAR(120) NOT NULL,
            `label` VARCHAR(190) NULL,
            `pic_name` VARCHAR(120) NULL,
            `doc_type` VARCHAR(20) NULL,
            `is_active` TINYINT NOT NULL DEFAULT 1,
            `created_by` VARCHAR(120) NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_flow_step` (`property_id`, `doc_type`, `step_no`),
            KEY `idx_flow_prop` (`property_id`, `is_active`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

$tabel = $pdo->query("SHOW TABLES LIKE 'skp_approvals'")->fetchColumn();
if (!$tabel) {
    $pdo->exec(
        "CREATE TABLE `skp_approvals` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `property_id` TINYINT NOT NULL DEFAULT 1,
            `skp_id` INT NOT NULL,
            `step_no` SMALLINT NOT NULL DEFAULT 1,
            `role_name` VARCHAR(120) NULL,
            `action` VARCHAR(20) NOT NULL DEFAULT 'approve',
            `approver_user_id` INT NULL,
            `approver_name` VARCHAR(120) NULL,
            `note` VARCHAR(500) NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY `idx_appr_skp` (`skp_id`),
            KEY `idx_appr_step` (`skp_id`, `step_no`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

// Berapa tahap yang SUDAH dilewati dokumen ini. 0 = belum ada yang paraf.
$kol = $pdo->query("SHOW COLUMNS FROM skp_documents LIKE 'approval_level'")->fetchColumn();
if (!$kol) {
    $pdo->exec("ALTER TABLE `skp_documents`
                ADD COLUMN `approval_level` SMALLINT NOT NULL DEFAULT 0 AFTER `status`");
    // Dokumen yang sudah disetujui/ditandatangani dianggap sudah melewati
    // seluruh tahap, supaya tidak tiba-tiba terlihat menunggu paraf lagi.
    $pdo->exec("UPDATE `skp_documents` SET `approval_level` = 99
                WHERE `status` IN ('approved','signed')");
}
