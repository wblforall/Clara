<?php

/**
 * CLARA — menjawab "kenapa income saya berkurang?"
 *
 * Sejak 7 Okt 2026 berlaku aturan: nilai sebuah kesepakatan baru dihitung
 * setelah dokumennya diparaf Asst. Manager, disetujui Manager, DAN
 * ditandatangani client. Selama belum diteken, nilainya tidak masuk laporan.
 *
 * Skrip ini menunjukkan berapa yang tertahan karena aturan itu, milik siapa,
 * dan bulan mana yang terdampak. Bagian terakhir yang paling menentukan:
 * seberapa sering dokumen benar-benar sampai status "signed". Kalau selama ini
 * tanda tangan jarang dicatat di CLARA, aturannya terlalu ketat untuk cara
 * kerja yang berjalan dan sebaiknya dilonggarkan kembali ke "approved".
 *
 * Hanya membaca — tidak mengubah apa pun.
 *
 *   php scripts/periksa_income_kurang.php
 *   php scripts/periksa_income_kurang.php --bulan=2026-10
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('CLARA_ROOT', dirname(__DIR__));
require_once CLARA_ROOT . '/app/env.php';
require_once CLARA_ROOT . '/app/Database.php';

function pik_rp(float $v): string { return 'Rp ' . number_format($v, 0, ',', '.'); }
function pik_garis(int $n = 78): string { return '  ' . str_repeat('-', $n); }

function pik_main(PDO $pdo, array $argv): int
{
    $bulan = date('Y-m');
    foreach ($argv as $a) if (str_starts_with($a, '--bulan=')) $bulan = substr($a, 8);

    // Status dokumen TERAKHIR milik tiap transaksi — cara yang sama dipakai
    // daftar Exhibition untuk memutuskan baris mana yang ditampilkan.
    $stat = "(SELECT s2.status FROM skp_documents s2
               WHERE s2.transaction_id = t.id AND s2.property_id = t.property_id
               ORDER BY s2.id DESC LIMIT 1)";

    echo "PERIODE YANG DIPERIKSA: $bulan\n\n";

    // ── 1. Yang sekarang TERHITUNG, per PIC ─────────────────────────────────
    echo "1. INCOME YANG SEKARANG MASUK LAPORAN (per PIC, bulan $bulan)\n\n";
    $q = $pdo->prepare("SELECT a.pic_name, COALESCE(SUM(a.amount),0) nilai, COUNT(DISTINCT a.transaction_id) trx
                          FROM transaction_allocations a
                         WHERE a.period_key = ?
                         GROUP BY a.pic_name ORDER BY nilai DESC");
    $q->execute([$bulan]);
    $masuk = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$masuk) { echo "   (tidak ada sama sekali)\n"; }
    $totMasuk = 0.0;
    foreach ($masuk as $r) {
        printf("   %-24s %18s  (%d transaksi)\n", $r['pic_name'] ?: '(tanpa PIC)', pik_rp((float)$r['nilai']), (int)$r['trx']);
        $totMasuk += (float) $r['nilai'];
    }
    echo pik_garis() . "\n";
    printf("   %-24s %18s\n\n", 'TOTAL', pik_rp($totMasuk));

    // ── 2. Yang TERTAHAN karena dokumennya belum diteken ────────────────────
    // Transaksi yang periodenya menyentuh bulan ini tetapi tidak punya satu pun
    // baris alokasi — itulah yang hilang dari laporan.
    echo "2. KONTRAK BERJALAN YANG TIDAK MASUK LAPORAN BULAN INI\n\n";
    $q2 = $pdo->prepare("SELECT t.pic_name, COALESCE($stat,'(tanpa dokumen)') AS st,
                                COUNT(*) jml, COALESCE(SUM(t.final_amount),0) nilai_kontrak
                           FROM transactions t
                          WHERE t.deleted_at IS NULL
                            AND t.start_date <= LAST_DAY(CONCAT(?,'-01'))
                            AND t.end_date   >= CONCAT(?,'-01')
                            AND NOT EXISTS (SELECT 1 FROM transaction_allocations a
                                             WHERE a.transaction_id = t.id)
                          GROUP BY t.pic_name, st
                          ORDER BY nilai_kontrak DESC");
    $q2->execute([$bulan, $bulan]);
    $tahan = $q2->fetchAll(PDO::FETCH_ASSOC);
    if (!$tahan) {
        echo "   Tidak ada. Semua kontrak yang berjalan bulan ini sudah terhitung.\n\n";
    } else {
        printf("   %-24s %-16s %6s %18s\n", 'PIC', 'STATUS DOKUMEN', 'TRX', 'NILAI KONTRAK');
        echo pik_garis() . "\n";
        $totTahan = 0.0;
        foreach ($tahan as $r) {
            printf("   %-24s %-16s %6d %18s\n", $r['pic_name'] ?: '(tanpa PIC)', $r['st'],
                (int)$r['jml'], pik_rp((float)$r['nilai_kontrak']));
            $totTahan += (float) $r['nilai_kontrak'];
        }
        echo pik_garis() . "\n";
        printf("   %-47s %18s\n\n", 'TOTAL NILAI KONTRAK', pik_rp($totTahan));
        echo "   Kolom terakhir = nilai SELURUH kontrak, bukan porsi bulan ini. Yang hilang\n"
           . "   dari laporan bulan $bulan hanyalah bagian bulan ini.\n\n"
           . "   Arti kolom STATUS DOKUMEN:\n"
           . "     submitted / approved  → tertahan oleh aturan tanda tangan (berlaku 7 Okt 2026)\n"
           . "     rejected              → memang ditolak, seharusnya tidak dihitung\n"
           . "     (tanpa dokumen)       → BUKAN karena aturan itu. Transaksi ini belum pernah\n"
           . "                             dibuatkan dokumen, dan alokasinya tidak pernah dihitung.\n"
           . "                             Sebabnya lain — periksa transaksinya satu per satu.\n\n";
    }

    // ── 3. Apakah tanda tangan memang biasa dicatat? ────────────────────────
    // Ini yang menentukan apakah aturannya cocok dengan cara kerja sehari-hari.
    echo "3. SEBERAPA SERING DOKUMEN SAMPAI DITANDATANGANI (seluruh riwayat)\n\n";
    $q3 = $pdo->query("SELECT status, COUNT(*) n FROM skp_documents GROUP BY status ORDER BY n DESC");
    $semua = $q3->fetchAll(PDO::FETCH_ASSOC);
    $tot = array_sum(array_column($semua, 'n')) ?: 1;
    $nSigned = 0;
    foreach ($semua as $r) {
        if ($r['status'] === 'signed') $nSigned = (int) $r['n'];
        printf("   %-14s %6d  %5.1f%%\n", $r['status'], (int)$r['n'], (int)$r['n'] / $tot * 100);
    }
    $persen = $nSigned / $tot * 100;
    echo "\n";
    printf("   Dari %d dokumen, %d (%.1f%%) berstatus \"signed\".\n\n", $tot, $nSigned, $persen);

    if ($persen < 50) {
        echo "   >> PERHATIAN. Tanda tangan client jarang tercatat di CLARA.\n"
           . "      Dengan aturan sekarang, sebagian besar kesepakatan TIDAK AKAN PERNAH\n"
           . "      masuk laporan — bukan karena datanya salah, tetapi karena langkah\n"
           . "      tanda tangannya memang tidak biasa dikerjakan.\n\n"
           . "      Dua pilihan: biasakan mencatat tanda tangan, atau longgarkan\n"
           . "      aturannya kembali ke \"disetujui Manager\".\n";
    } else {
        echo "   Tanda tangan tercatat cukup rutin; aturan sekarang masih wajar dipakai.\n";
    }
    return 0;
}

if (isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    exit(pik_main(Database::connect(), $argv));
}
