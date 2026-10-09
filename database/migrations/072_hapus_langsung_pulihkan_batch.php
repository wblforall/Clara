<?php
/**
 * Pengajuan Hapus Data — tiga perubahan sekaligus.
 *
 * 1. Persetujuan pemutus LANGSUNG menghapus. Sebelumnya masih menunggu
 *    superadmin menekan tombol kedua; satu langkah yang tidak menambah
 *    pengamanan apa pun karena keputusannya sudah diambil.
 * 2. Bisa dipulihkan. Datanya memang cuma ditandai terhapus, jadi
 *    mengembalikannya hanya perlu tombol — selama ini harus lewat basis data.
 * 3. Beberapa dokumen dalam satu pengajuan. Barisnya tetap satu per dokumen
 *    (supaya rekap per PIC tetap benar), tapi diikat satu nomor batch sehingga
 *    pemutus memutuskannya sekali.
 */

$kolom = [
    // Pengikat satu pengajuan berisi beberapa dokumen. NULL = pengajuan tunggal
    // (semua baris lama), jadi tidak ada yang perlu diisi ulang.
    'batch_no'     => "VARCHAR(20) NULL COMMENT 'pengikat satu pengajuan berisi beberapa dokumen'",
    'restored_by'  => 'VARCHAR(120) NULL',
    'restored_at'  => 'DATETIME NULL',
    'restore_note' => 'VARCHAR(500) NULL',
    // Berapa baris alokasi yang dibangun kembali saat dipulihkan. Bisa 0 secara
    // sah: dokumen yang belum ber-TTD client memang belum boleh masuk laporan.
    'alokasi_pulih' => 'INT UNSIGNED NOT NULL DEFAULT 0',
];
foreach ($kolom as $nama => $tipe) {
    $ada = $pdo->query("SHOW COLUMNS FROM `deletion_requests` LIKE '$nama'")->fetchColumn();
    if (!$ada) {
        $pdo->exec("ALTER TABLE `deletion_requests` ADD COLUMN `$nama` $tipe");
        echo "     deletion_requests.$nama ditambahkan\n";
    }
}

$idx = $pdo->query("SHOW INDEX FROM `deletion_requests` WHERE Key_name = 'idx_dr_batch'")->fetch();
if (!$idx) {
    $pdo->exec("ALTER TABLE `deletion_requests` ADD KEY `idx_dr_batch` (`property_id`, `batch_no`)");
    echo "     indeks idx_dr_batch ditambahkan\n";
}

/*
 * Baris lama berstatus 'disetujui' — sudah diputus tetapi belum dieksekusi
 * superadmin. Dengan alur baru status itu tidak terbit lagi. Baris yang ada
 * TIDAK diubah diam-diam menjadi 'dihapus': datanya memang belum dihapus, dan
 * menandainya terhapus akan membuat riwayat berbohong. Halaman tetap
 * menampilkan tombol hapusnya selama masih ada baris seperti ini.
 */
$sisa = (int) $pdo->query("SELECT COUNT(*) FROM deletion_requests WHERE status = 'disetujui'")->fetchColumn();
if ($sisa > 0) {
    echo "     catatan: $sisa pengajuan lama berstatus 'disetujui' masih menunggu dieksekusi —\n";
    echo "              panel 'Sisa pengajuan alur lama' di halaman itu untuk menuntaskannya.\n";
}
