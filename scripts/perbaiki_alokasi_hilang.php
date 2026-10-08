<?php

/**
 * CLARA — memulihkan alokasi bulanan yang terhapus oleh commit 7 Okt 2026.
 *
 * Commit 4b16c35 memindahkan pengakuan income ke saat tanda tangan client, dan
 * dalam prosesnya MENGHAPUS alokasi setiap kali sebuah dokumen disubmit atau
 * perpanjangan disetujui. Kontrak yang sudah berjalan dan sudah terhitung ikut
 * dikosongkan. Aturannya sudah dikembalikan; skrip ini membangun ulang angka
 * yang hilang.
 *
 * Dibangun ulang memakai AllocationService yang sama dengan yang dipakai
 * aplikasi, jadi hasilnya identik dengan kalau transaksinya disimpan ulang
 * lewat layar.
 *
 * Yang DILEWATI: transaksi yang dokumen terakhirnya DITOLAK — alokasinya memang
 * sengaja dilepas, bukan korban commit itu.
 *
 * Laporan saja (tidak mengubah apa pun):
 *   php scripts/perbaiki_alokasi_hilang.php
 *
 * Bangun ulang:
 *   php scripts/perbaiki_alokasi_hilang.php --terapkan
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('CLARA_ROOT', dirname(__DIR__));
require_once CLARA_ROOT . '/app/env.php';
require_once CLARA_ROOT . '/app/Database.php';
require_once CLARA_ROOT . '/app/AllocationService.php';

function pah_rp(float $v): string { return 'Rp ' . number_format($v, 0, ',', '.'); }

function pah_main(PDO $pdo, array $argv): int
{
    $terapkan = in_array('--terapkan', $argv, true);

    $stat = "(SELECT s2.status FROM skp_documents s2
               WHERE s2.transaction_id = t.id AND s2.property_id = t.property_id
               ORDER BY s2.id DESC LIMIT 1)";

    $rows = $pdo->query("SELECT t.*, COALESCE($stat,'(tanpa dokumen)') AS status_dok
                           FROM transactions t
                          WHERE t.deleted_at IS NULL
                            AND COALESCE($stat,'') <> 'rejected'
                            AND NOT EXISTS (SELECT 1 FROM transaction_allocations a
                                             WHERE a.transaction_id = t.id)
                          ORDER BY t.pic_name, t.id")->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo "Tidak ada yang perlu dipulihkan. Semua transaksi berjalan sudah punya alokasi.\n";
        return 0;
    }

    // Ringkasan per PIC lebih dulu — ini yang ditanyakan orang.
    $perPic = [];
    foreach ($rows as $r) {
        $p = $r['pic_name'] ?: '(tanpa PIC)';
        $perPic[$p]['jml'] = ($perPic[$p]['jml'] ?? 0) + 1;
        $perPic[$p]['nilai'] = ($perPic[$p]['nilai'] ?? 0) + (float) $r['final_amount'];
    }
    arsort($perPic);

    echo ($terapkan ? "MEMULIHKAN" : "AKAN DIPULIHKAN") . " — " . count($rows) . " transaksi\n\n";
    printf("  %-24s %6s %20s\n", 'PIC', 'TRX', 'NILAI KONTRAK');
    echo '  ' . str_repeat('-', 54) . "\n";
    $totNilai = 0.0;
    foreach ($perPic as $pic => $d) {
        printf("  %-24s %6d %20s\n", $pic, $d['jml'], pah_rp((float) $d['nilai']));
        $totNilai += (float) $d['nilai'];
    }
    echo '  ' . str_repeat('-', 54) . "\n";
    printf("  %-24s %6d %20s\n\n", 'TOTAL', count($rows), pah_rp($totNilai));

    if (!$terapkan) {
        echo "Belum ada yang diubah (mode laporan).\n"
           . "Tambahkan --terapkan untuk membangun ulang alokasinya.\n";
        return 1;
    }

    $ok = 0; $gagal = 0; $totBaris = 0;
    foreach ($rows as $t) {
        $tid = (int) $t['id'];
        try {
            // anchor_cycle: seluruh nilai diakui pada satu periode — sama persis
            // dengan perilaku saat transaksinya pertama kali disimpan.
            if (($t['billing_method'] ?? '') !== 'spread') $t['recognition_period'] = $t['period_key'];
            AllocationService::saveAllocations($pdo, $tid, $t);
            $n = (int) $pdo->query("SELECT COUNT(*) FROM transaction_allocations WHERE transaction_id = $tid")->fetchColumn();
            $totBaris += $n;
            $ok++;
        } catch (Throwable $e) {
            $gagal++;
            fwrite(STDERR, "  GAGAL transaksi #$tid: " . $e->getMessage() . "\n");
        }
    }
    echo "Selesai — $ok transaksi dipulihkan ($totBaris baris alokasi)"
       . ($gagal ? ", $gagal gagal" : "") . ".\n";

    // Tunjukkan hasilnya supaya bisa langsung dicocokkan dengan laporan.
    $bulan = date('Y-m');
    $q = $pdo->prepare("SELECT pic_name, COALESCE(SUM(amount),0) n FROM transaction_allocations
                         WHERE period_key = ? GROUP BY pic_name ORDER BY n DESC");
    $q->execute([$bulan]);
    echo "\nIncome bulan $bulan setelah dipulihkan:\n";
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        printf("  %-24s %20s\n", $r['pic_name'] ?: '(tanpa PIC)', pah_rp((float) $r['n']));
    }
    return $gagal ? 1 : 0;
}

if (isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    exit(pah_main(Database::connect(), $argv));
}
