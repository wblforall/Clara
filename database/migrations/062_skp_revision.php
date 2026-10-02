<?php
// Permintaan revisi dokumen yang SUDAH disetujui tetapi BELUM ditandatangani client.
//
// Setelah Manager menyetujui, dokumen terkunci — itu memang disengaja supaya
// nilainya tidak berubah diam-diam. Tetapi kenyataannya masih ada koreksi yang
// baru ketahuan setelah approval (salah nama PJ, salah produk, salah periode),
// dan selama client belum menandatangani, memperbaikinya jauh lebih masuk akal
// daripada membatalkan lalu membuat dokumen baru.
//
// Alurnya: PIC mengajukan revisi → penanggung jawab revisi (jabatan yang diatur
// sendiri, umumnya Asst. Manager) menyetujui → dokumen kembali bisa diedit PIC,
// lalu menempuh rantai persetujuan dari awal lagi. Nomor dokumennya TIDAK
// berubah; yang bertambah hanya nomor urut revisinya.
//
// Dokumen yang sudah ditandatangani client TIDAK bisa direvisi — untuk itu
// jalurnya menerbitkan dokumen baru.

$tabel = $pdo->query("SHOW TABLES LIKE 'skp_revision_requests'")->fetchColumn();
if (!$tabel) {
    $pdo->exec(
        "CREATE TABLE `skp_revision_requests` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `property_id` TINYINT NOT NULL DEFAULT 1,
            `skp_id` INT NOT NULL,
            `alasan` VARCHAR(500) NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `requested_by` VARCHAR(120) NULL,
            `requested_user_id` INT NULL,
            `requested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `decided_by` VARCHAR(120) NULL,
            `decided_role` VARCHAR(120) NULL,
            `decided_at` DATETIME NULL,
            `decision_note` VARCHAR(500) NULL,
            KEY `idx_rev_skp` (`skp_id`, `status`),
            KEY `idx_rev_prop` (`property_id`, `status`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

// Siapa yang berwenang menyetujui permintaan revisi — diatur per properti &
// jenis dokumen, berbasis JABATAN seperti rantai persetujuan. Kosong = jatuh ke
// tahap pertama rantai persetujuan (umumnya Asst. Manager); tanpa rantai sama
// sekali, jatuh ke pemegang izin approve_skp seperti perilaku lama.
$tabel = $pdo->query("SHOW TABLES LIKE 'skp_revision_flow'")->fetchColumn();
if (!$tabel) {
    $pdo->exec(
        "CREATE TABLE `skp_revision_flow` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `property_id` TINYINT NOT NULL DEFAULT 1,
            `doc_type` VARCHAR(20) NULL,
            `role_name` VARCHAR(120) NOT NULL,
            `pic_name` VARCHAR(120) NULL,
            `is_active` TINYINT NOT NULL DEFAULT 1,
            `created_by` VARCHAR(120) NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_rev_flow` (`property_id`, `doc_type`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

// Berapa kali dokumen ini sudah direvisi — dicetak di dokumennya supaya versi
// yang dipegang client bisa dibedakan dari versi sebelumnya.
$kol = $pdo->query("SHOW COLUMNS FROM skp_documents LIKE 'revisi_ke'")->fetchColumn();
if (!$kol) {
    $pdo->exec("ALTER TABLE `skp_documents`
                ADD COLUMN `revisi_ke` SMALLINT NOT NULL DEFAULT 0 AFTER `approval_level`");
}
