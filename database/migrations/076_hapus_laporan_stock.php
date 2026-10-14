<?php

/**
 * Buang sisa fitur "Laporan Stock" yang tidak pernah dipakai.
 *
 * Halamannya, rutenya, menunya, dan izinnya sudah dihapus dari kode. Yang
 * tersisa hanya tiga tabel kosong dan dua baris izin di role_permissions —
 * dibersihkan di sini supaya daftar tabel tidak lagi memuat sesuatu yang tidak
 * punya pemilik.
 *
 * Tabelnya DIJATUHKAN HANYA BILA MASIH KOSONG. Kalau ternyata ada isinya,
 * migrasi ini sengaja membiarkannya dan menuliskan peringatan: data orang tidak
 * boleh hilang karena anggapan "sudah tidak dipakai" yang ternyata keliru.
 *
 * @var PDO $pdo
 */

$tabel = ['stock_report_sales', 'stock_report_rows', 'stock_reports'];   // urut: anak dulu

foreach ($tabel as $t) {
    $ada = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetchColumn();
    if (!$ada) { echo "     $t tidak ada — dilewati\n"; continue; }

    $isi = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    if ($isi > 0) {
        echo "     $t MASIH BERISI $isi baris — TIDAK dijatuhkan.\n";
        echo "     Periksa dulu isinya; kalau memang tidak dipakai, jatuhkan manual.\n";
        continue;
    }
    $pdo->exec("DROP TABLE `$t`");
    echo "     $t (kosong) dijatuhkan\n";
}

$n = $pdo->prepare("DELETE FROM role_permissions WHERE permission IN ('view_stock_report','manage_stock_report')");
$n->execute();
echo "     " . $n->rowCount() . " baris izin Laporan Stock dibuang dari role_permissions\n";
