<?php
/**
 * Rincian Pembayaran Sewa gaya KERTAS untuk SKP kontrak bulanan.
 *
 * Masalahnya datang dari surat asli yang masih diketik di Excel — contohnya
 * "SKP Star Toys (1 Juli - 31 Desember 2026) kontrak 6 bulan". Rincian di
 * kertas itu disusun PER BULAN lalu dikalikan lama kontrak, listriknya berdiri
 * sebagai bagian sendiri, dan ditutup jadwal pembayaran:
 *
 *   A. Biaya Sewa Area     a. Harga Paket / Bulan
 *                          b. PPN 12%
 *                          c. Biaya Sewa / Bulan setelah PPN
 *                          d. Total Biaya Sewa 6 Bulan + PPN
 *   B. Jaminan Area        a. Security Deposit  (sudah dibayarkan Januari 2026)
 *   C. Biaya Listrik       a. Harga Listrik / Bulan ... d. Total 6 Bulan + PPN
 *   D. Grand Total         a. Pembayaran Periode Pertama & Terakhir + Listrik
 *                          b. Pembayaran Periode Kedua s.d Kelima + Listrik
 *
 * CLARA selama ini hanya punya satu bentuk rincian: daftar datar berisi total.
 * Benar angkanya, tapi tidak menjawab pertanyaan yang ditanya client tiap kali
 * — "sebulan saya bayar berapa, dan yang di muka berapa".
 *
 * TIDAK semua SKP begitu. Pameran tiga hari tidak punya "per bulan", dan
 * memaksakan bentuk ini ke seluruh dokumen akan merusak ratusan surat yang
 * sudah benar. Jadi bentuknya DIPILIH per dokumen:
 *
 *   rincian_gaya = NULL / 'ringkas'  → bentuk lama, tetap jadi bawaan
 *   rincian_gaya = 'bulanan'         → bentuk kertas di atas
 *
 * Dua kolom pendampingnya hanya terpakai pada gaya 'bulanan':
 *   dp_periode   berapa periode dibayar di muka (kertas contoh: 2 — periode
 *                pertama & terakhir). 0/NULL = jadwalnya tidak dicetak.
 *   deposit_note keterangan di samping Security Deposit, mis. "sudah dibayarkan
 *                bulan Januari 2026". Centang "Sudah dibayarkan" yang sekarang
 *                cuma bisa bilang SUDAH, tidak bisa bilang KAPAN.
 *
 * Dokumen yang sudah terbit tidak tersentuh: kolomnya NULL, dan cetakannya
 * memang dibaca dari snapshot_json masing-masing.
 */

$kolom = [
    // Bentuk rincian yang dipilih di formulir SKP. Sengaja VARCHAR, bukan
    // TINYINT: bentuk ketiga (mis. mingguan) tinggal menambah nilai baru tanpa
    // ada yang perlu menghafal arti angka.
    'rincian_gaya' => "ADD COLUMN `rincian_gaya` VARCHAR(10) NULL AFTER `sc_monthly`",
    'dp_periode'   => "ADD COLUMN `dp_periode` TINYINT NULL AFTER `rincian_gaya`",
    'deposit_note' => "ADD COLUMN `deposit_note` VARCHAR(140) NULL AFTER `deposit_paid`",
];

foreach ($kolom as $nama => $sql) {
    $ada = $pdo->query('SHOW COLUMNS FROM `skp_documents` LIKE ' . $pdo->quote($nama))->fetchColumn();
    if ($ada) { echo "     kolom $nama sudah ada\n"; continue; }
    $pdo->exec("ALTER TABLE `skp_documents` $sql");
    echo "     kolom $nama ditambahkan\n";
}
