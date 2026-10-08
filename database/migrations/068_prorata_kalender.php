<?php
/**
 * Pilihan model prorata untuk sewa bulanan.
 *
 * Selama ini CLARA menagih dengan SIKLUS TANGGAL: kontrak mulai tanggal 11
 * berarti siklusnya 11 → 10 bulan berikutnya, dan sisa di ujung dihitung per
 * hari siklus itu. Surat kertas yang dipakai sehari-hari memakai BULAN
 * KALENDER: bulan pertama yang tidak penuh diprorata per jumlah hari bulan
 * itu, sisanya bulan-bulan penuh.
 *
 * Contoh 11 Nov 2026 – 31 Des 2027 @ Rp 8.500.000:
 *   siklus tanggal : 13 siklus penuh + ekor 21/31 hari  = Rp 116.258.065
 *   bulan kalender : 20/30 hari November + 13 bulan     = Rp 116.166.667  ← surat
 *
 * Bawaannya 0 (siklus tanggal) supaya SELURUH kontrak yang sudah berjalan
 * tidak berubah satu rupiah pun. Hanya kontrak yang dicentang yang memakai
 * cara bulan kalender.
 */
foreach (['offers', 'transactions'] as $tbl) {
    $ada = $pdo->query("SHOW COLUMNS FROM `$tbl` LIKE 'prorata_kalender'")->fetchColumn();
    if (!$ada) {
        $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN `prorata_kalender` TINYINT(1) NOT NULL DEFAULT 0
                    COMMENT '1 = prorata mengikuti bulan kalender, 0 = siklus tanggal'");
        echo "     $tbl.prorata_kalender ditambahkan\n";
    }
}
