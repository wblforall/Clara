<?php

/**
 * CLARA — melepas income dokumen yang sedang direvisi atau ditolak.
 *
 * Dokumen yang dibuka kembali untuk revisi berarti isinya sedang
 * dipertanyakan: tanggalnya, nilainya, atau unitnya mungkin berubah. Selama
 * itu nilainya tidak boleh duduk di laporan seolah sudah pasti, dan
 * transaksinya tidak boleh tampil di Exhibition / Media / Gudang.
 *
 * Mulai 8 Okt 2026 pelepasan itu terjadi otomatis saat revisi disetujui.
 * Skrip ini untuk yang TERLANJUR: dokumen yang sudah masuk revisi sebelum
 * perbaikan itu dipasang, dan angkanya masih tertinggal di laporan.
 *
 * Tidak ada yang hilang permanen — alokasinya dibangun kembali begitu
 * dokumennya selesai diperbaiki dan menempuh persetujuan lagi.
 *
 * Laporan saja:
 *   php scripts/lepas_income_revisi.php
 *
 * Lepaskan:
 *   php scripts/lepas_income_revisi.php --terapkan
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('CLARA_ROOT', dirname(__DIR__));
require_once CLARA_ROOT . '/app/env.php';
require_once CLARA_ROOT . '/app/Database.php';

function lir_rp(float $v): string { return 'Rp ' . number_format($v, 0, ',', '.'); }

function lir_main(PDO $pdo, array $argv): int
{
    $terapkan = in_array('--terapkan', $argv, true);

    // Dokumen TERAKHIR milik tiap transaksi.
    $kol = fn(string $c) => "(SELECT s2.$c FROM skp_documents s2
                               WHERE s2.transaction_id = t.id AND s2.property_id = t.property_id
                               ORDER BY s2.id DESC LIMIT 1)";
    $sedangDibongkar = "(COALESCE({$kol('status')},'') = 'rejected'
                         OR (COALESCE({$kol('status')},'') = 'draft'
                             AND COALESCE({$kol('revisi_ke')},0) > 0))";

    $rows = $pdo->query(
        "SELECT t.id, t.master_code, t.pic_name, t.start_date, t.end_date,
                p.name AS properti,
                COALESCE({$kol('skp_no')}, CONCAT('draft #', COALESCE({$kol('id')},0))) AS no_dok,
                COALESCE({$kol('status')},'') AS status_dok,
                COALESCE({$kol('revisi_ke')},0) AS revisi_ke,
                COALESCE(SUM(a.amount),0) AS nilai, COUNT(a.id) AS baris
           FROM transactions t
           JOIN transaction_allocations a ON a.transaction_id = t.id
           LEFT JOIN properties p ON p.id = t.property_id
          WHERE t.deleted_at IS NULL AND $sedangDibongkar
          GROUP BY t.id, t.master_code, t.pic_name, t.start_date, t.end_date, p.name,
                   no_dok, status_dok, revisi_ke
          ORDER BY nilai DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo "Tidak ada. Semua dokumen yang sedang direvisi / ditolak sudah tidak terhitung.\n";
        return 0;
    }

    echo ($terapkan ? "DILEPAS" : "AKAN DILEPAS") . " dari laporan — dokumen sedang direvisi / ditolak\n\n";
    printf("  %-6s %-22s %-12s %-11s %-6s %16s\n", 'TRX', 'DOKUMEN', 'PIC', 'STATUS', 'REV', 'INCOME');
    echo '  ' . str_repeat('-', 80) . "\n";
    $tot = 0.0;
    foreach ($rows as $r) {
        printf("  #%-5d %-22s %-12s %-11s %-6s %16s\n", (int) $r['id'],
            mb_substr((string) $r['no_dok'], 0, 22), mb_substr((string) $r['pic_name'], 0, 12),
            (string) $r['status_dok'], (int) $r['revisi_ke'], lir_rp((float) $r['nilai']));
        $tot += (float) $r['nilai'];
    }
    echo '  ' . str_repeat('-', 80) . "\n";
    printf("  %-60s %16s\n\n", 'TOTAL (' . count($rows) . ' transaksi)', lir_rp($tot));

    if (!$terapkan) {
        echo "Belum ada yang diubah (mode laporan).\n"
           . "Tambahkan --terapkan untuk melepasnya.\n"
           . "Angkanya kembali sendiri begitu dokumennya disetujui / ditandatangani ulang.\n";
        return 1;
    }

    $pdo->beginTransaction();
    try {
        $ids = implode(',', array_map(fn($r) => (int) $r['id'], $rows));
        $n = $pdo->exec("DELETE FROM transaction_allocations WHERE transaction_id IN ($ids)");
        $pdo->commit();
        echo "Selesai — $n baris alokasi dilepas dari " . count($rows) . " transaksi.\n"
           . "Transaksinya juga tidak lagi tampil di Exhibition / Media / Gudang\n"
           . "sampai dokumennya selesai diperbaiki.\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "GAGAL, semua dibatalkan: " . $e->getMessage() . "\n");
        return 1;
    }
    return 0;
}

if (isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    exit(lir_main(Database::connect(), $argv));
}
