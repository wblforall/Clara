<?php
// Template Surat Penawaran: bisa disetel penuh, dan bisa DIPILIH, bukan ditebak.
//
// Masalah yang diselesaikan — semuanya datang dari lima surat penawaran asli
// yang selama ini diketik di Word (Pushcart, Foodcourt, FuniFun!, Atrium, Snack
// Corner). Begitu dibandingkan baris demi baris dengan keluaran aplikasi,
// ketahuan bahwa template yang ada belum bisa menirunya:
//
// 1) TEMPLATE TIDAK BISA DIPILIH. Kunci unik (property_id, module, unit_type)
//    memaksa satu template per tipe unit, dan pemilihannya ditebak dari tipe
//    unitnya. Akibatnya menambah template kedua untuk tipe yang sama mustahil,
//    dan sales tidak pernah bisa memilih sendiri. Kunci itu dilonggarkan jadi
//    indeks biasa; penentunya sekarang template_id yang dipilih di formulir.
//
// 2) TIDAK ADA TEMPLATE BAWAAN YANG TEGAS. Baris dengan unit_type kosong
//    dipakai sebagai "default" secara tersirat. Sekarang ada penanda is_default
//    yang bisa dipindah ke template mana pun.
//
// 3) PPN DIPAKU 12% + RUMUS 11/12. Dua dari lima surat asli memakai kata-kata
//    lain: ada yang menulis "PPN 11%" polos, ada yang "PPN 12%" disertai
//    catatan PMK Nomor 131 Tahun 2024. Angka uangnya kebetulan sama persis
//    (12% x 11/12 = 11%), jadi yang berbeda hanya kalimatnya — tapi surat harus
//    berbunyi sama dengan aslinya. ppn_persen + ppn_rumus + ppn_catatan membuat
//    keduanya bisa ditulis tanpa mengubah satu rupiah pun.
//
// 4) HANYA ADA SATU TATA LETAK. Surat Foodcourt sama sekali berbeda bentuknya:
//    bukan tabel Lokasi/Luas/Harga, melainkan daftar bernomor 1..14 (Lokasi,
//    Alamat, Area & Ukuran, Periode Sewa, Biaya Sewa, Service Charge, Biaya
//    Utilities, Security Deposit, Term of Payment, Jam Operasional, Serah
//    Terima, Facilities, Fit Out Periode, Schedule). Kolom layout memilih
//    bentuk mana yang dipakai.
//
// 5) BAGIAN YANG DIPAKU DI KODE. Judul bagian, "Media promosi yang dapat
//    digunakan", nomor rekening, kalimat penutup, dan bullet kolom Keterangan
//    semuanya tertulis di dalam template cetak sehingga tak bisa disamakan
//    dengan surat asli. Semuanya dipindah ke kolom tersendiri.
//
// Semua kolom diberi nilai bawaan yang SAMA PERSIS dengan perilaku sekarang,
// jadi dokumen yang sudah terbit maupun penawaran berjalan tidak berubah.

$kolom = [
    // Penanda template bawaan — yang otomatis terpilih saat membuat penawaran.
    'is_default'    => "ADD COLUMN `is_default` TINYINT(1) NOT NULL DEFAULT 0 AFTER `name`",
    // tabel  = keluarga Exhibition (tabel Lokasi/Luas/Harga/Keterangan)
    // rincian = keluarga Foodcourt (daftar bernomor Lokasi/Alamat/Area/...)
    'layout'        => "ADD COLUMN `layout` VARCHAR(16) NOT NULL DEFAULT 'tabel' AFTER `is_default`",
    'ppn_persen'    => "ADD COLUMN `ppn_persen` DECIMAL(5,2) NOT NULL DEFAULT 12.00 AFTER `layout`",
    // 1 = pakai rumus PMK 131/2024 (nilai x 11/12 x tarif). 0 = tarif polos.
    'ppn_rumus'     => "ADD COLUMN `ppn_rumus` TINYINT(1) NOT NULL DEFAULT 1 AFTER `ppn_persen`",
    'ppn_catatan'   => "ADD COLUMN `ppn_catatan` VARCHAR(255) NULL AFTER `ppn_rumus`",
    // Tabel "Rincian Biaya" buatan aplikasi tidak ada di surat Word asli.
    // Dibiarkan menyala secara bawaan supaya surat lama tidak berubah.
    'rincian_biaya' => "ADD COLUMN `rincian_biaya` TINYINT(1) NOT NULL DEFAULT 1 AFTER `ppn_catatan`",
    'media_json'    => "ADD COLUMN `media_json` TEXT NULL AFTER `fasilitas_json`",
    'ket_json'      => "ADD COLUMN `ket_json` TEXT NULL AFTER `media_json`",
    'rincian_json'  => "ADD COLUMN `rincian_json` TEXT NULL AFTER `ket_json`",
    'judul_json'    => "ADD COLUMN `judul_json` TEXT NULL AFTER `rincian_json`",
    'bank_json'     => "ADD COLUMN `bank_json` TEXT NULL AFTER `judul_json`",
    'penutup'       => "ADD COLUMN `penutup` TEXT NULL AFTER `bank_json`",
    // Surat kertas memakai bullet untuk Cara Pembayaran & Ketentuan; aplikasi
    // menomorinya. Bawaannya tetap bernomor supaya surat lama tak berubah.
    'gaya_daftar'   => "ADD COLUMN `gaya_daftar` VARCHAR(10) NOT NULL DEFAULT 'nomor' AFTER `layout`",
    // Judul bagian: 'aplikasi' = hijau tanpa nomor (tampilan CLARA selama ini);
    // 'romawi' = hitam tebal bernomor I, II, III — seperti surat kertas.
    'gaya_judul'    => "ADD COLUMN `gaya_judul` VARCHAR(10) NOT NULL DEFAULT 'aplikasi' AFTER `gaya_daftar`",
    // Blok rekening: 'kotak' = kotak abu-abu tersendiri; 'menyatu' = menempel
    // sebagai butir terakhir Cara Pembayaran, apa adanya seperti di kertas.
    'gaya_bank'     => "ADD COLUMN `gaya_bank` VARCHAR(10) NOT NULL DEFAULT 'kotak' AFTER `gaya_judul`",
    // Kalimat "Demikian surat penawaran ini kami buat..." dulu dipaku di kode,
    // padahal tiap surat kertas menulisnya sedikit berbeda.
    'penutup_akhir' => "ADD COLUMN `penutup_akhir` TEXT NULL AFTER `penutup`",
    // Sebutan di kolom tanda tangan kanan ("Calon Penyewa" di aplikasi; surat
    // kertas langsung kosong lalu nama penanggung jawabnya).
    'ttd_kanan'     => "ADD COLUMN `ttd_kanan` VARCHAR(80) NULL AFTER `penutup_akhir`",
    // Kotak "Penawaran ini berlaku s/d ..." dan QR validasi pada tanda tangan
    // sales: dua-duanya buatan aplikasi, tidak ada di surat kertas. Bawaannya
    // tetap MENYALA supaya surat yang sudah terbit tidak berubah.
    'tampil_berlaku' => "ADD COLUMN `tampil_berlaku` TINYINT(1) NOT NULL DEFAULT 1 AFTER `ttd_kanan`",
    'tampil_qr'      => "ADD COLUMN `tampil_qr` TINYINT(1) NOT NULL DEFAULT 1 AFTER `tampil_berlaku`",
    // Penulisan rupiah: 'polos' = Rp 7.000.000 (gaya aplikasi selama ini);
    // 'kertas' = Rp. 7.000.000,- seperti yang diketik di surat Word. Ini penting
    // karena butir Cara Pembayaran di template memang ditulis gaya kertas, jadi
    // satu surat bisa memuat dua gaya sekaligus kalau tidak diseragamkan.
    'gaya_uang'      => "ADD COLUMN `gaya_uang` VARCHAR(10) NOT NULL DEFAULT 'polos' AFTER `tampil_qr`",
];
foreach ($kolom as $nama => $sql) {
    $ada = $pdo->query("SHOW COLUMNS FROM `offer_templates` LIKE " . $pdo->quote($nama))->fetchColumn();
    if (!$ada) $pdo->exec("ALTER TABLE `offer_templates` $sql");
}

// Kunci unik dilepas: satu modul boleh punya banyak template, dan tipe unit
// turun pangkat jadi sekadar usulan awal — bukan penentu.
$idx = $pdo->query("SHOW INDEX FROM `offer_templates` WHERE Key_name = 'uq_prop_mod_type'")->fetchColumn();
if ($idx) {
    $pdo->exec("ALTER TABLE `offer_templates` DROP INDEX `uq_prop_mod_type`");
    $pdo->exec("ALTER TABLE `offer_templates` ADD KEY `idx_prop_mod_type` (`property_id`, `module`, `unit_type`)");
}

// Baris yang selama ini berperan sebagai default (tipe unit kosong) ditandai
// apa adanya, supaya pilihan bawaannya tidak berubah setelah pembaruan.
$pdo->exec("UPDATE `offer_templates` SET `is_default` = 1 WHERE `unit_type` = '' AND `status` = 'active'");

// Template yang dipakai sebuah penawaran ikut dicatat. Isi suratnya memang
// sudah dibekukan di letter_json, jadi ini untuk jejak dan untuk memuat ulang
// pilihan yang sama saat penawaran dibuka kembali.
$ada = $pdo->query("SHOW COLUMNS FROM `offers` LIKE 'template_id'")->fetchColumn();
if (!$ada) $pdo->exec("ALTER TABLE `offers` ADD COLUMN `template_id` INT(10) UNSIGNED NULL AFTER `module`");

// Ukuran apa adanya, mis. "2x3 m2". Surat kertas menulis UKURAN unitnya, bukan
// hasil kali luasnya — dan 2x3 lebih bisa dibayangkan client daripada 6,00 m².
// Kosong = tetap cetak luas seperti sekarang.
$ada = $pdo->query("SHOW COLUMNS FROM `offers` LIKE 'ukuran'")->fetchColumn();
if (!$ada) $pdo->exec("ALTER TABLE `offers` ADD COLUMN `ukuran` VARCHAR(40) NULL AFTER `area_sqm`");
