<?php

/**
 * CLARA — pindah ke aturan "income masuk setelah client menandatangani".
 *
 * Alurnya: PIC > Asst. Manager > Manager > Client.
 * Selama dokumen baru sampai Manager, kesepakatannya belum final — nilainya
 * masih boleh diperbaiki dan direvisi, dan BELUM terhitung sebagai pendapatan.
 * Begitu client meneken, nilainya masuk laporan dan isinya terkunci.
 *
 * Perubahan itu hanya berlaku untuk dokumen yang diproses SETELAH aturannya
 * dipasang. Data yang sudah ada tidak ikut berubah sendiri — skrip ini yang
 * mengurusnya, sekali, dengan daftarnya ditunjukkan lebih dulu.
 *
 * Yang DILEWATI:
 *   - dokumen yang sudah ditandatangani  (memang sudah sah)
 *   - transaksi tanpa dokumen sama sekali (di luar alur SKP)
 *   - dokumen yang ditolak                (alokasinya sudah lepas sejak dulu)
 *
 * Laporan saja (tidak mengubah apa pun):
 *   php scripts/alih_aturan_ttd.php --rinci
 *
 * Terapkan — HANYA bila aturan memang mau diberlakukan SURUT ke kesepakatan
 * yang sudah berjalan. Tanpa --termasuk-lama perintahnya ditolak:
 *   php scripts/alih_aturan_ttd.php --terapkan --termasuk-lama
 *
 * Bisa dibatalkan: begitu client menandatangani dokumennya, angkanya dibangun
 * kembali otomatis. Tidak ada transaksi maupun dokumen yang dihapus di sini.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('CLARA_ROOT', dirname(__DIR__));
require_once CLARA_ROOT . '/app/env.php';
require_once CLARA_ROOT . '/app/Database.php';

function att_rp(float $v): string { return 'Rp ' . number_format($v, 0, ',', '.'); }

/** Tanggal mulai berlakunya aturan tanda tangan ('' bila belum disetel). */
function att_mulai(PDO $pdo): string
{
    try {
        return (string) ($pdo->query("SELECT value FROM settings WHERE `key` = 'ttd_wajib_mulai' LIMIT 1")
                             ->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

function att_main(PDO $pdo, array $argv): int
{
    $terapkan = in_array('--terapkan', $argv, true);
    $rinci    = in_array('--rinci', $argv, true);
    $pakaiLama = in_array('--termasuk-lama', $argv, true);

    // PENGAMAN. Aturan tanda tangan hanya mengikat kesepakatan yang dibuat sejak
    // tanggal berlakunya; yang lebih lama sengaja dibiarkan terhitung. Daftar di
    // bawah hampir seluruhnya kesepakatan lama, jadi --terapkan tanpa sadar akan
    // melepas angka yang memang masih berhak ada di laporan. Harus disebut
    // terang-terangan dengan --termasuk-lama.
    $mulai = att_mulai($pdo);
    if ($terapkan && $mulai !== '' && !$pakaiLama) {
        fwrite(STDERR,
            "DITOLAK. Aturan tanda tangan berlaku untuk kesepakatan sejak $mulai.\n"
          . "Daftar di bawah memuat kesepakatan LAMA yang sengaja dibiarkan terhitung.\n"
          . "Melepasnya berarti memberlakukan aturan secara surut — angka laporan\n"
          . "dan komisi orang-orang akan berubah mundur sampai berbulan-bulan.\n\n"
          . "Jalankan tanpa --terapkan untuk melihat daftarnya.\n"
          . "Kalau memang itu yang dikehendaki, sebut --termasuk-lama.\n");
        return 2;
    }

    $stat = "(SELECT s2.status FROM skp_documents s2
               WHERE s2.transaction_id = t.id AND s2.property_id = t.property_id
               ORDER BY s2.id DESC LIMIT 1)";
    $nomor = "(SELECT COALESCE(s3.skp_no, CONCAT('draft #', s3.id)) FROM skp_documents s3
                WHERE s3.transaction_id = t.id AND s3.property_id = t.property_id
                ORDER BY s3.id DESC LIMIT 1)";

    // Yang terhitung sekarang tetapi dokumennya BELUM diteken.
    $sql = "SELECT t.id, t.pic_name, t.master_code, t.start_date, t.end_date,
                   p.name AS properti, COALESCE($stat,'') AS status_dok, $nomor AS no_dok,
                   COALESCE(SUM(a.amount),0) AS nilai
              FROM transactions t
              JOIN transaction_allocations a ON a.transaction_id = t.id
              LEFT JOIN properties p ON p.id = t.property_id
             WHERE t.deleted_at IS NULL
               AND COALESCE($stat,'') NOT IN ('', 'signed', 'rejected')
             GROUP BY t.id, t.pic_name, t.master_code, t.start_date, t.end_date, p.name, status_dok, no_dok
             ORDER BY t.pic_name, nilai DESC";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        echo "Tidak ada yang berubah. Semua yang terhitung di laporan dokumennya sudah ditandatangani.\n";
        return 0;
    }

    echo ($terapkan ? "BERHENTI DIHITUNG" : "AKAN BERHENTI DIHITUNG") . " — dokumen belum ditandatangani client\n\n";

    // Ringkasan per PIC — ini yang perlu disampaikan ke orangnya.
    $perPic = [];
    foreach ($rows as $r) {
        $k = $r['pic_name'] ?: '(tanpa PIC)';
        $perPic[$k]['jml']   = ($perPic[$k]['jml'] ?? 0) + 1;
        $perPic[$k]['nilai'] = ($perPic[$k]['nilai'] ?? 0) + (float) $r['nilai'];
    }
    uasort($perPic, fn($a, $b) => $b['nilai'] <=> $a['nilai']);
    printf("  %-16s %6s %20s\n", 'PIC', 'TRX', 'NILAI YANG LEPAS');
    echo '  ' . str_repeat('-', 46) . "\n";
    $tot = 0.0;
    foreach ($perPic as $pic => $d) {
        printf("  %-16s %6d %20s\n", $pic, $d['jml'], att_rp((float) $d['nilai']));
        $tot += (float) $d['nilai'];
    }
    echo '  ' . str_repeat('-', 46) . "\n";
    printf("  %-16s %6d %20s\n\n", 'TOTAL', count($rows), att_rp($tot));

    // Dampak per bulan laporan.
    $pb = $pdo->query("SELECT a.period_key, COALESCE(SUM(a.amount),0) n
                         FROM transactions t
                         JOIN transaction_allocations a ON a.transaction_id = t.id
                        WHERE t.deleted_at IS NULL
                          AND COALESCE($stat,'') NOT IN ('', 'signed', 'rejected')
                        GROUP BY a.period_key ORDER BY a.period_key")->fetchAll(PDO::FETCH_ASSOC);
    echo "BULAN LAPORAN YANG BERUBAH\n\n";
    foreach ($pb as $b) printf("  %-10s %20s\n", $b['period_key'], att_rp((float) $b['n']));
    echo "\n";

    if ($rinci) {
        echo "RINCIAN PER TRANSAKSI\n\n";
        printf("  %-6s %-14s %-12s %-11s %-22s %16s\n", 'TRX', 'PIC', 'KODE', 'STATUS', 'DOKUMEN', 'NILAI');
        echo '  ' . str_repeat('-', 88) . "\n";
        foreach ($rows as $r) {
            printf("  #%-5d %-14s %-12s %-11s %-22s %16s\n", (int) $r['id'],
                mb_substr((string) $r['pic_name'], 0, 14), mb_substr((string) $r['master_code'], 0, 12),
                $r['status_dok'], mb_substr((string) $r['no_dok'], 0, 22), att_rp((float) $r['nilai']));
        }
        echo "\n";
    } else {
        echo "Tambahkan --rinci untuk melihat daftar per transaksi beserta nomor dokumennya.\n\n";
    }

    if (!$terapkan) {
        echo "Belum ada yang diubah (mode laporan).\n";
        if ($mulai !== '') {
            echo "Aturan tanda tangan berlaku untuk kesepakatan sejak $mulai; yang di atas\n"
               . "sebagian besar LEBIH LAMA dari itu dan memang dibiarkan tetap terhitung.\n"
               . "Daftar ini untuk diketahui, bukan untuk dieksekusi.\n";
        } else {
            echo "Tambahkan --terapkan untuk melepas alokasi di atas dari laporan.\n"
               . "Transaksi & dokumennya tetap utuh; angkanya kembali begitu client meneken.\n";
        }
        return 1;
    }

    $pdo->beginTransaction();
    try {
        $ids = implode(',', array_map(fn($r) => (int) $r['id'], $rows));
        $n = $pdo->exec("DELETE FROM transaction_allocations WHERE transaction_id IN ($ids)");
        $pdo->commit();
        echo "Selesai — $n baris alokasi dilepas dari " . count($rows) . " transaksi.\n"
           . "Begitu client menandatangani dokumennya, angkanya dibangun kembali otomatis.\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "GAGAL, semua dibatalkan: " . $e->getMessage() . "\n");
        return 1;
    }
    return 0;
}

if (isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    exit(att_main(Database::connect(), $argv));
}
