<?php

/**
 * CLARA — memeriksa pendapatan yang terhitung padahal dokumennya belum diteken.
 *
 * Sejak aturan "income baru dihitung setelah client menandatangani" berlaku,
 * alokasi hanya dibuat pada saat tanda tangan. Tetapi data yang SUDAH ADA
 * sebelum aturan itu tidak ikut berubah sendiri: transaksi yang dokumennya
 * masih menunggu paraf, menunggu TTD, atau bahkan ditolak bisa jadi masih
 * menyumbang angka di Dashboard dan Laporan.
 *
 * Skrip ini menunjukkan persis berapa banyak dan berapa rupiahnya, sebelum
 * Anda memutuskan melepasnya.
 *
 * Laporan saja (tidak mengubah apa pun):
 *   php scripts/periksa_income_ttd.php
 *
 * Lepaskan alokasinya:
 *   php scripts/periksa_income_ttd.php --terapkan
 *
 * Melepas alokasi TIDAK menghapus transaksi maupun dokumennya. Begitu client
 * menandatangani, angkanya dibangun kembali secara otomatis.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('CLARA_ROOT', dirname(__DIR__));
require_once CLARA_ROOT . '/app/env.php';
require_once CLARA_ROOT . '/app/Database.php';

function pit_rp(float $v): string { return 'Rp ' . number_format($v, 0, ',', '.'); }

function pit_main(PDO $pdo, array $argv): int
{
    $terapkan = in_array('--terapkan', $argv, true);

    // Status dokumen TERAKHIR milik tiap transaksi — persis cara daftar modul
    // menentukannya, supaya yang dilaporkan di sini sama dengan yang disembunyikan.
    $statusTerakhir = "(SELECT s2.status FROM skp_documents s2
                         WHERE s2.transaction_id = t.id AND s2.property_id = t.property_id
                         ORDER BY s2.id DESC LIMIT 1)";

    $sql = "SELECT p.name AS properti, t.module, COALESCE($statusTerakhir,'') AS status_dok,
                   COUNT(DISTINCT t.id) AS jml, COALESCE(SUM(a.amount),0) AS nilai
              FROM transactions t
              JOIN transaction_allocations a ON a.transaction_id = t.id
              LEFT JOIN properties p ON p.id = t.property_id
             WHERE t.deleted_at IS NULL
               AND COALESCE($statusTerakhir,'') NOT IN ('', 'signed')
             GROUP BY p.name, t.module, status_dok
             ORDER BY p.name, t.module, status_dok";
    $baris = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if (!$baris) {
        echo "Tidak ada. Semua yang terhitung di laporan dokumennya sudah ditandatangani.\n";
        return 0;
    }

    echo "TERHITUNG DI LAPORAN PADAHAL BELUM DITEKEN CLIENT\n\n";
    printf("  %-26s %-8s %-11s %7s  %18s\n", 'PROPERTI', 'MODUL', 'STATUS DOK', 'TRX', 'NILAI');
    echo '  ' . str_repeat('-', 74) . "\n";
    $totJml = 0; $totNilai = 0.0;
    foreach ($baris as $r) {
        printf("  %-26s %-8s %-11s %7d  %18s\n",
            mb_substr((string) $r['properti'], 0, 26), $r['module'], $r['status_dok'],
            (int) $r['jml'], pit_rp((float) $r['nilai']));
        $totJml += (int) $r['jml']; $totNilai += (float) $r['nilai'];
    }
    echo '  ' . str_repeat('-', 74) . "\n";
    printf("  %-47s %7d  %18s\n\n", 'TOTAL', $totJml, pit_rp($totNilai));

    // Rincian per bulan — supaya terlihat laporan bulan mana yang akan berubah.
    $perBulan = $pdo->query("SELECT a.period_key, COALESCE(SUM(a.amount),0) nilai
                               FROM transactions t
                               JOIN transaction_allocations a ON a.transaction_id = t.id
                              WHERE t.deleted_at IS NULL
                                AND COALESCE($statusTerakhir,'') NOT IN ('', 'signed')
                              GROUP BY a.period_key ORDER BY a.period_key")->fetchAll(PDO::FETCH_ASSOC);
    echo "LAPORAN BULAN YANG AKAN BERUBAH\n\n";
    foreach ($perBulan as $b) printf("  %-10s %18s\n", $b['period_key'], pit_rp((float) $b['nilai']));
    echo "\n";

    if (!$terapkan) {
        echo "Belum ada yang diubah (mode laporan).\n"
           . "Tambahkan --terapkan untuk melepas alokasi di atas dari laporan.\n"
           . "Transaksi & dokumennya tetap utuh; angkanya kembali sendiri saat client meneken.\n";
        return 1;
    }

    $pdo->beginTransaction();
    try {
        $n = $pdo->exec("DELETE a FROM transaction_allocations a
                           JOIN transactions t ON t.id = a.transaction_id
                          WHERE t.deleted_at IS NULL
                            AND COALESCE($statusTerakhir,'') NOT IN ('', 'signed')");
        $pdo->commit();
        echo "Selesai — $n baris alokasi dilepas dari laporan.\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "GAGAL, semua dibatalkan: " . $e->getMessage() . "\n");
        return 1;
    }
    return 0;
}

if (isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    exit(pit_main(Database::connect(), $argv));
}
