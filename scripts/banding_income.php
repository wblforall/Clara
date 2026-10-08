<?php

/**
 * CLARA — menyandingkan income satu bulan dengan angka file operasional.
 *
 * Angka pembandingnya TIDAK ditanam di sini. Repo ini publik, sedangkan nilai
 * pendapatan dan nama orang bukan hal yang boleh ikut terbit. Angkanya dibaca
 * dari sebuah berkas JSON yang Anda simpan sendiri di server.
 *
 * Bentuk berkasnya:
 *   {
 *     "properti": "ewalk",          // atau "pentacity"
 *     "bulan": "2026-10",
 *     "sumber": "nama file operasionalnya",
 *     "total_actual": 0,             // total realisasi versi file itu
 *     "target_team": 0,
 *     "pic": { "Nama PIC": 0, "Nama PIC Lain": 0 }
 *   }
 *
 * Nama di "pic" harus sama persis dengan nama PIC di CLARA.
 *
 * Hanya membaca — tidak mengubah apa pun.
 *
 *   php scripts/banding_income.php pembanding-okt.json
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('CLARA_ROOT', dirname(__DIR__));
require_once CLARA_ROOT . '/app/env.php';
require_once CLARA_ROOT . '/app/Database.php';

function bi_rp(float $v): string {
    return ($v < 0 ? '-' : '') . 'Rp ' . number_format(abs($v), 0, ',', '.');
}

function bi_main(PDO $pdo, array $argv): int
{
    $berkas = '';
    foreach (array_slice($argv, 1) as $a) if ($a[0] !== '-') { $berkas = $a; break; }
    if ($berkas === '' || !is_readable($berkas)) {
        fwrite(STDERR, "Pemakaian: php scripts/banding_income.php <berkas.json>\n"
                     . "Lihat keterangan di bagian atas berkas ini untuk bentuk JSON-nya.\n");
        return 2;
    }
    $d = json_decode((string) file_get_contents($berkas), true);
    if (!is_array($d)) { fwrite(STDERR, "Isi $berkas bukan JSON yang sah.\n"); return 2; }

    $kunci = (string) ($d['properti'] ?? '');
    $bulan = (string) ($d['bulan'] ?? '');
    $xlPic = (array)  ($d['pic'] ?? []);
    $xlTot = (float)  ($d['total_actual'] ?? 0);
    $target = (float) ($d['target_team'] ?? 0);
    if ($kunci === '' || $bulan === '') { fwrite(STDERR, "\"properti\" dan \"bulan\" wajib diisi.\n"); return 2; }

    $pq = $pdo->prepare("SELECT id, name FROM properties WHERE `key` = ?");
    $pq->execute([$kunci]);
    $prop = $pq->fetch(PDO::FETCH_ASSOC);
    if (!$prop) { fwrite(STDERR, "Properti \"$kunci\" tidak ada.\n"); return 2; }
    $pid = (int) $prop['id'];

    echo "SANDINGAN INCOME — {$prop['name']} · $bulan\n";
    if (!empty($d['sumber'])) echo "Pembanding: {$d['sumber']}\n";
    echo "\n";

    // Masih ada yang belum dipulihkan? Angkanya pasti terlihat kurang.
    $belum = (int) $pdo->query("SELECT COUNT(*) FROM transactions t
                                 WHERE t.deleted_at IS NULL AND t.property_id = $pid
                                   AND COALESCE((SELECT s.status FROM skp_documents s
                                                  WHERE s.transaction_id = t.id AND s.property_id = t.property_id
                                                  ORDER BY s.id DESC LIMIT 1),'') <> 'rejected'
                                   AND NOT EXISTS (SELECT 1 FROM transaction_allocations a
                                                    WHERE a.transaction_id = t.id)")->fetchColumn();
    if ($belum > 0) {
        echo "!! $belum transaksi di properti ini masih tanpa alokasi.\n"
           . "   Jalankan dulu: php scripts/perbaiki_alokasi_hilang.php --terapkan\n"
           . "   Tanpa itu, angka di bawah pasti terlihat kurang.\n\n";
    }

    $q = $pdo->prepare("SELECT pic_name, COALESCE(SUM(amount),0) n FROM transaction_allocations
                         WHERE period_key = ? AND property_id = ? GROUP BY pic_name");
    $q->execute([$bulan, $pid]);
    $clara = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $clara[trim((string) $r['pic_name'])] = (float) $r['n'];

    if ($xlPic) {
        echo "PER PIC\n\n";
        printf("  %-14s %18s %18s %18s  %s\n", 'PIC', 'PEMBANDING', 'CLARA', 'SELISIH', 'KETERANGAN');
        echo '  ' . str_repeat('-', 92) . "\n";
        $sx = 0.0; $sc = 0.0;
        foreach ($xlPic as $nama => $xl) {
            $xl = (float) $xl; $cl = $clara[$nama] ?? 0.0; $sel = $cl - $xl;
            printf("  %-14s %18s %18s %18s  %s\n", $nama, bi_rp($xl), bi_rp($cl), bi_rp($sel),
                abs($sel) < 1 ? 'pas' : ($sel < 0 ? 'KURANG di CLARA' : 'lebih di CLARA'));
            $sx += $xl; $sc += $cl;
        }
        echo '  ' . str_repeat('-', 92) . "\n";
        printf("  %-14s %18s %18s %18s\n\n", 'JUMLAH', bi_rp($sx), bi_rp($sc), bi_rp($sc - $sx));

        $lain = array_diff_key($clara, $xlPic);
        if ($lain) {
            echo "PIC LAIN DI CLARA (tidak ada di berkas pembanding)\n\n";
            foreach ($lain as $nama => $v) printf("  %-14s %18s\n", $nama ?: '(tanpa PIC)', bi_rp($v));
            printf("  %-14s %18s\n\n", 'JUMLAH', bi_rp((float) array_sum($lain)));
        }
    }

    $totCl = (float) array_sum($clara);
    echo "TOTAL SATU PROPERTI\n\n";
    printf("  %-26s %18s\n", 'Pembanding', bi_rp($xlTot));
    printf("  %-26s %18s\n", 'CLARA', bi_rp($totCl));
    printf("  %-26s %18s  %s\n", 'SELISIH', bi_rp($totCl - $xlTot),
        $totCl < $xlTot ? 'CLARA KURANG' : ($totCl > $xlTot ? 'CLARA LEBIH' : 'pas'));
    if ($xlTot > 0) printf("  %-26s %17.1f%%\n", 'CLARA terhadap pembanding', $totCl / $xlTot * 100);
    if ($target > 0) {
        printf("\n  Target tim %s — capaian versi pembanding %.0f%%, versi CLARA %.0f%%.\n",
            bi_rp($target), $xlTot / $target * 100, $totCl / $target * 100);
    }
    return 0;
}

if (isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    exit(bi_main(Database::connect(), $argv));
}
