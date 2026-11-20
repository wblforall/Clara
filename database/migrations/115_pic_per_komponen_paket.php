<?php
/**
 * PIC penerima income untuk tiap komponen Penawaran Paket.
 *
 * Dokumen paket melahirkan satu transaksi per komponen, dan sampai sekarang
 * SEMUA transaksi itu memakai satu nama: PIC dokumennya. Padahal paket 3 island
 * kerap dikerjakan dua orang — 10/GF ISLAND oleh satu sales, 27/GF & 30/GF oleh
 * sales lain. Tidak ada tempat untuk menyatakan itu: tabel "Pembagian Income
 * per PIC" sengaja dimatikan pada dokumen paket karena satu daftar nominal
 * tidak bisa menyebut komponen mana milik siapa.
 *
 * Tabel ini menyimpannya per komponen, dan dibaca saat dokumen disetujui untuk
 * menentukan pic_name tiap transaksi yang terbit. Dipisah dari
 * transaction_pic_splits karena artinya berbeda: di sana satu transaksi dibagi
 * ke beberapa PIC menurut nominal, di sini satu komponen utuh menjadi milik
 * satu PIC.
 */

$ada = $pdo->query("SHOW TABLES LIKE 'skp_item_pic'")->fetchColumn();
if ($ada) { echo "     tabel skp_item_pic sudah ada — dilewati\n"; return; }

$pdo->exec(
    "CREATE TABLE skp_item_pic (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        property_id   INT NOT NULL,
        skp_id        INT NOT NULL,
        offer_item_id INT NOT NULL,
        pic_name      VARCHAR(120) NOT NULL,
        created_by    VARCHAR(100) NULL,
        created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_skp_item (skp_id, offer_item_id),
        KEY idx_prop (property_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
echo "     tabel skp_item_pic dibuat\n";
