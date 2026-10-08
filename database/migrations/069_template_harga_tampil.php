<?php
/**
 * Kolom harga di tabel objek surat: tampilkan TOTAL PERIODE atau HARGA/BULAN.
 *
 * Surat kertas yang dipakai sehari-hari menulis "Harga Sewa / Bulan" berisi
 * Rp 8.500.000, bukan total kontraknya. CLARA selama ini selalu menulis total
 * periode, sehingga angka di surat buatan aplikasi tidak bisa disandingkan
 * langsung dengan surat kertas.
 *
 * Bawaannya 'periode' supaya seluruh template yang sudah ada tidak berubah.
 */
$ada = $pdo->query("SHOW COLUMNS FROM `offer_templates` LIKE 'harga_tampil'")->fetchColumn();
if (!$ada) {
    $pdo->exec("ALTER TABLE `offer_templates`
                ADD COLUMN `harga_tampil` VARCHAR(10) NOT NULL DEFAULT 'periode'
                COMMENT 'periode = total kontrak, bulan = harga per bulan'");
    echo "     offer_templates.harga_tampil ditambahkan\n";
}
