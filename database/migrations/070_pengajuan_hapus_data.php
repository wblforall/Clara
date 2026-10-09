<?php
/**
 * Form Pengajuan Penghapusan Data.
 *
 * Sebelum ini, menghapus data hanya bisa dilakukan superadmin, langsung, tanpa
 * jejak permintaan. PIC yang salah input harus menghubungi admin di luar
 * aplikasi, dan tidak ada catatan siapa meminta apa dengan alasan apa.
 *
 * Alurnya: PIC mengajukan → pemutus (umumnya Manager) menyetujui atau menolak
 * → superadmin yang benar-benar menghapus. Tiga tangan, satu jejak.
 *
 * Dua tabel:
 *   deletion_requests  — pengajuannya sendiri
 *   deletion_approver  — siapa pemutusnya, disetel per properti
 */

$ada = $pdo->query("SHOW TABLES LIKE 'deletion_requests'")->fetch();
if (!$ada) {
    $pdo->exec("
        CREATE TABLE deletion_requests (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            property_id     INT UNSIGNED NOT NULL,
            skp_id          INT UNSIGNED NOT NULL,
            transaction_id  INT UNSIGNED NULL,

            -- Salinan keterangan dokumen, diambil saat pengajuan dibuat.
            -- Riwayat penghapusan harus tetap terbaca setelah dokumen aslinya
            -- hilang; tanpa salinan ini isinya cuma deretan nomor tanpa arti.
            doc_no          VARCHAR(60)  NULL,
            doc_type        VARCHAR(20)  NULL,   -- skp | sks | fu
            module          VARCHAR(20)  NULL,   -- cl | media | gudang
            client_name     VARCHAR(190) NULL,
            master_code     VARCHAR(60)  NULL,
            periode         VARCHAR(60)  NULL,
            nilai           DECIMAL(18,2) NOT NULL DEFAULT 0,
            pic_name        VARCHAR(120) NULL,

            alasan          TEXT NOT NULL,

            -- menunggu → disetujui → dihapus, atau menunggu → batal
            status          VARCHAR(20) NOT NULL DEFAULT 'menunggu',

            requested_by      VARCHAR(120) NULL,
            requested_user_id INT UNSIGNED NULL,
            requested_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            decided_by      VARCHAR(120) NULL,
            decided_role    VARCHAR(120) NULL,
            decided_at      DATETIME NULL,
            decision_note   VARCHAR(500) NULL,

            executed_by     VARCHAR(120) NULL,
            executed_at     DATETIME NULL,
            -- Berapa baris alokasi yang ikut dilepas — dipakai di riwayat untuk
            -- menjelaskan kenapa income seseorang berkurang.
            alokasi_dilepas INT UNSIGNED NOT NULL DEFAULT 0,

            KEY idx_dr_property (property_id),
            KEY idx_dr_status (property_id, status),
            KEY idx_dr_skp (skp_id),
            KEY idx_dr_pemohon (requested_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "     tabel deletion_requests dibuat\n";
}

$adaA = $pdo->query("SHOW TABLES LIKE 'deletion_approver'")->fetch();
if (!$adaA) {
    $pdo->exec("
        CREATE TABLE deletion_approver (
            property_id INT UNSIGNED NOT NULL PRIMARY KEY,
            -- Jabatan pemutus. Ditulis sebagai JABATAN, bukan nama orang, supaya
            -- pengaturannya tidak perlu diubah saat orangnya berganti.
            role_name   VARCHAR(120) NOT NULL,
            -- Opsional: kalau diisi, hanya orang ini yang boleh memutuskan.
            pic_name    VARCHAR(120) NULL,
            updated_by  VARCHAR(120) NULL,
            updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "     tabel deletion_approver dibuat\n";
}

// Pemutus bawaan: Manager tiap properti. Tanpa isian awal, pengajuan pertama
// tidak punya tujuan dan menggantung tanpa penjelasan.
foreach ($pdo->query("SELECT id FROM properties")->fetchAll(PDO::FETCH_COLUMN) as $pid) {
    $pdo->prepare("INSERT IGNORE INTO deletion_approver (property_id, role_name, updated_by)
                   VALUES (?, 'Manager', 'migrasi')")->execute([$pid]);
}

// Penanda dokumen terhapus. Dokumennya TIDAK dibuang dari basis data —
// nomornya sudah terbit dan pernah dikirim ke client, jadi jejaknya harus
// tetap bisa dibuka. Yang berubah hanya: tidak lagi tampil di daftar SKP.
foreach (['deleted_at' => "DATETIME NULL", 'deleted_by' => "VARCHAR(120) NULL"] as $kol => $tipe) {
    $adaK = $pdo->query("SHOW COLUMNS FROM `skp_documents` LIKE '$kol'")->fetchColumn();
    if (!$adaK) {
        $pdo->exec("ALTER TABLE `skp_documents` ADD COLUMN `$kol` $tipe");
        echo "     skp_documents.$kol ditambahkan\n";
    }
}

// Izin baru. Diberikan ke peran yang memang sudah memegang pekerjaan itu:
// yang boleh membuat dokumen boleh mengajukan penghapusannya, dan yang boleh
// menyetujui dokumen boleh memutuskan penghapusan.
$beri = $pdo->prepare("INSERT IGNORE INTO role_permissions (role, permission) VALUES (?, ?)");
foreach ($pdo->query("SELECT DISTINCT role FROM role_permissions WHERE permission = 'manage_skp'")
              ->fetchAll(PDO::FETCH_COLUMN) as $r) {
    $beri->execute([$r, 'request_delete']);
}
foreach ($pdo->query("SELECT DISTINCT role FROM role_permissions WHERE permission = 'approve_skp'")
              ->fetchAll(PDO::FETCH_COLUMN) as $r) {
    $beri->execute([$r, 'approve_delete']);
}
