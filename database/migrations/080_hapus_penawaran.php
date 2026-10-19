<?php
/**
 * Surat Penawaran ikut bisa diajukan hapus.
 *
 * Sampai sekarang penawaran adalah satu-satunya dokumen yang TIDAK punya jalan
 * keluar sama sekali: tidak ada tombol hapus di mana pun, dan begitu statusnya
 * DEAL isinya pun terkunci dari penyuntingan. Penawaran salah input akhirnya
 * menetap selamanya di daftar — satu-satunya cara membersihkannya adalah lewat
 * basis data langsung, yang justru tidak meninggalkan jejak siapa pun.
 *
 * Polanya disamakan dengan dokumen lain: ditandai terhapus, bukan dibuang.
 * Nomornya sudah terbit dan bisa saja sudah sampai ke client, jadi barisnya
 * harus tetap bisa ditelusuri — yang berubah hanya tidak lagi tampil di daftar.
 */

// ── Penanda terhapus di penawaran ────────────────────────────────────────────
$kolOffer = array_column($pdo->query('SHOW COLUMNS FROM `offers`')->fetchAll(PDO::FETCH_ASSOC), 'Field');
foreach (['deleted_at' => 'DATETIME NULL', 'deleted_by' => 'VARCHAR(120) NULL'] as $kol => $tipe) {
    if (!in_array($kol, $kolOffer, true)) {
        $pdo->exec("ALTER TABLE `offers` ADD COLUMN `$kol` $tipe");
        echo "     offers.$kol ditambahkan\n";
    }
}

// ── Pengajuan boleh menunjuk penawaran ───────────────────────────────────────
//
// Kolomnya sendiri, bukan menumpang skp_id: penawaran dan SKP adalah dua berkas
// berbeda dengan penomoran sendiri-sendiri, dan menumpangkannya membuat
// "pengajuan untuk berkas ini sudah ada" salah menolak berkas yang lain.
$kolDr = array_column($pdo->query('SHOW COLUMNS FROM `deletion_requests`')->fetchAll(PDO::FETCH_ASSOC), 'Field');
if (!in_array('offer_id', $kolDr, true)) {
    $pdo->exec("ALTER TABLE `deletion_requests` ADD COLUMN `offer_id` INT UNSIGNED NULL
                COMMENT 'diisi bila yang diajukan Surat Penawaran' AFTER `transaction_id`");
    echo "     deletion_requests.offer_id ditambahkan\n";
}

$idx = $pdo->query("SHOW INDEX FROM `deletion_requests` WHERE Key_name = 'idx_dr_offer'")->fetch();
if (!$idx) {
    $pdo->exec("ALTER TABLE `deletion_requests` ADD KEY `idx_dr_offer` (`property_id`, `offer_id`)");
    echo "     indeks idx_dr_offer ditambahkan\n";
}
