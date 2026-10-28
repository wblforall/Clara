<?php
/**
 * Daftar hari libur, supaya kalender menandainya seperti lembar kerja tim.
 *
 * Di berkas mereka, tiap bulan punya blok "KET : HARI LIBUR :" yang menuliskan
 * tanggal merah bulan itu ("1 Hari Lahir Pancasila"). Gunanya praktis: unit
 * yang kosong di tanggal merah bukan kelalaian, dan tanggal merah adalah hari
 * paling laku untuk pameran. Kalender CLARA baru menandai Sabtu–Minggu.
 *
 * Disimpan per properti karena libur lokal (mis. hari jadi kota) bisa berbeda
 * antara Balikpapan dan tempat lain, dan karena halaman Master Data memang
 * bekerja per properti.
 *
 * Yang di-seed hanya libur bertanggal TETAP. Libur yang bergeser tiap tahun
 * (Idul Fitri, Nyepi, Waisak, Imlek, Isra Mikraj, Idul Adha, Tahun Baru Islam,
 * Maulid, Jumat Agung, Kenaikan Isa Almasih) sengaja TIDAK ditebak — tanggalnya
 * ditetapkan SKB Menteri tiap tahun, dan menebaknya hanya akan menyesatkan.
 * Itu diisi sendiri lewat Master Data → Hari Libur.
 */

if ($pdo->query("SHOW TABLES LIKE 'holidays'")->fetchColumn()) {
    echo "     tabel holidays sudah ada\n";
} else {
    $pdo->exec("
        CREATE TABLE `holidays` (
            `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `property_id`  TINYINT NOT NULL DEFAULT 1,
            `holiday_date` DATE NOT NULL,
            `name`         VARCHAR(120) NOT NULL,
            `status`       VARCHAR(20) NOT NULL DEFAULT 'active',
            `sort_order`   INT NOT NULL DEFAULT 0,
            `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`   DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_libur` (`property_id`, `holiday_date`),
            KEY `idx_libur_tgl` (`holiday_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "     tabel holidays dibuat\n";
}

$tetap = [
    '01-01' => 'Tahun Baru Masehi',
    '05-01' => 'Hari Buruh Internasional',
    '06-01' => 'Hari Lahir Pancasila',
    '08-17' => 'Hari Kemerdekaan RI',
    '12-25' => 'Hari Raya Natal',
];
$pids = $pdo->query("SELECT id FROM properties ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) ?: [1];
$ins  = $pdo->prepare('INSERT IGNORE INTO holidays (property_id, holiday_date, name) VALUES (?,?,?)');
$n = 0;
$tahunIni = (int) date('Y');
foreach ($pids as $pid) {
    for ($th = $tahunIni - 1; $th <= $tahunIni + 2; $th++) {
        foreach ($tetap as $md => $nama) {
            $ins->execute([(int) $pid, "$th-$md", $nama]);
            $n += $ins->rowCount();
        }
    }
}
echo "     $n hari libur bertanggal tetap ditambahkan (" . ($tahunIni - 1) . "-" . ($tahunIni + 2) . ")\n";
echo "     Libur yang bergeser tiap tahun diisi sendiri di Master Data -> Hari Libur\n";
