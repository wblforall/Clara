<?php

/**
 * CLARA — cari transaksi yang "Override Aktual"-nya tidak sama dengan nilai kontrak.
 *
 * Penyebabnya: saat nominal PER BULAN disesuaikan di form transaksi, nilai
 * kontrak (final_amount) dihitung ulang dari jumlah alokasi, tetapi kotak
 * Override Aktual (override_amount) dibiarkan menyimpan angka lama. Dua angka
 * untuk satu kontrak, dan yang tampil di daftar Exhibition/Media/Gudang adalah
 * final_amount sementara yang tampil di form Edit adalah override_amount.
 *
 * Bahayanya bukan sekadar beda tampilan: begitu transaksi itu dibuka lalu
 * DISIMPAN tanpa menyentuh nominal per bulan, angka lama di kotak override
 * terkirim kembali dan nilai kontraknya turun diam-diam.
 *
 * Kodenya sudah diperbaiki untuk ke depan. Skrip ini untuk data yang sudah
 * terlanjur.
 *
 * Patokan kebenaran = JUMLAH ALOKASI, karena itulah yang dibaca semua laporan
 * income. Baris yang jumlah alokasinya juga tidak cocok TIDAK disentuh — sebab
 * di situ ada persoalan lain dan menebak akan memperburuk.
 *
 *   php scripts/cek_override_vs_final.php                      # laporan saja
 *   php scripts/cek_override_vs_final.php --id=2062            # periksa satu transaksi
 *   php scripts/cek_override_vs_final.php --id=2062 --terapkan # samakan SATU transaksi
 *   php scripts/cek_override_vs_final.php --terapkan --semua   # samakan semuanya
 *
 * --terapkan tanpa --id= atau --semua DITOLAK. Versi pertama skrip ini menyapu
 * seluruh tabel hanya karena perintahnya pendek, padahal yang ditanyakan satu
 * transaksi. Cakupan harus disebut, bukan disimpulkan.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('CLARA_ROOT', dirname(__DIR__));
require_once CLARA_ROOT . '/app/env.php';
require_once CLARA_ROOT . '/app/Database.php';

$terapkan = in_array('--terapkan', $argv, true);
$semua    = in_array('--semua', $argv, true);
$hanyaId  = 0;
foreach ($argv as $a) if (preg_match('/^--id=(\d+)$/', $a, $m)) $hanyaId = (int) $m[1];

if ($terapkan && !$hanyaId && !$semua) {
    echo "\nDitolak: --terapkan harus menyebut cakupannya.\n";
    echo "  php scripts/cek_override_vs_final.php --id=<ID> --terapkan   (satu transaksi)\n";
    echo "  php scripts/cek_override_vs_final.php --terapkan --semua     (semuanya)\n";
    exit(2);
}

$rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
$pdo = Database::connect();

$sql = "SELECT t.id, t.module, t.master_code, t.pic_name, t.start_date, t.end_date,
               t.total_calculated, t.override_amount, t.final_amount,
               COALESCE(a.jml, 0) AS alokasi, COALESCE(a.n, 0) AS baris,
               c.company_name
          FROM transactions t
          LEFT JOIN (SELECT transaction_id, SUM(amount) jml, COUNT(*) n
                       FROM transaction_allocations GROUP BY transaction_id) a
                 ON a.transaction_id = t.id
          LEFT JOIN master_clients c ON c.id = t.client_id
         WHERE t.deleted_at IS NULL
           AND t.override_amount IS NOT NULL
           AND ABS(t.override_amount - t.final_amount) >= 1"
     . ($hanyaId ? ' AND t.id = ' . $hanyaId : '')
     . ' ORDER BY ABS(t.override_amount - t.final_amount) DESC, t.id';

$baris = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Satu transaksi diperiksa sendiri: tampilkan angkanya walau tidak melenceng,
// supaya pertanyaan "kenapa beda" selalu terjawab, bukan dijawab senyap.
if ($hanyaId && !$baris) {
    $s = $pdo->prepare("SELECT t.*, COALESCE(SUM(a.amount),0) alokasi, COUNT(a.id) baris, c.company_name
                          FROM transactions t
                          LEFT JOIN transaction_allocations a ON a.transaction_id = t.id
                          LEFT JOIN master_clients c ON c.id = t.client_id
                         WHERE t.id = ? GROUP BY t.id");
    $s->execute([$hanyaId]);
    $t = $s->fetch(PDO::FETCH_ASSOC);
    if (!$t) { echo "Transaksi #$hanyaId tidak ada.\n"; exit(1); }
    echo "\nTransaksi #$hanyaId — " . ($t['company_name'] ?? '-') . " · " . $t['master_code'] . "\n";
    echo "  Total hitung      : " . $rp($t['total_calculated']) . "\n";
    echo "  Override Aktual   : " . ($t['override_amount'] === null ? '(kosong)' : $rp($t['override_amount'])) . "\n";
    echo "  Nilai kontrak     : " . $rp($t['final_amount']) . "   <- yang tampil di daftar\n";
    echo "  Jumlah alokasi    : " . $rp($t['alokasi']) . " (" . (int) $t['baris'] . " baris)  <- yang dipakai laporan income\n";
    $beda = abs((float) $t['final_amount'] - (float) $t['alokasi']);
    echo $beda >= 1
        ? "\n  PERHATIAN: nilai kontrak tidak sama dengan jumlah alokasinya (selisih " . $rp($beda) . ").\n"
        : "\n  Nilai kontrak sama dengan jumlah alokasinya.\n";

    // Rincian per bulan. Kalau jumlah alokasi berbeda dari hasil hitung rumus,
    // selisihnya pasti duduk di satu atau dua bulan tertentu — dan hanya dengan
    // melihat daftarnya orang bisa memutuskan mana yang benar.
    $ar = $pdo->prepare("SELECT period_key, allocation_start, allocation_end, allocated_days, pic_name, amount
                           FROM transaction_allocations WHERE transaction_id = ?
                          ORDER BY allocation_start, id");
    $ar->execute([$hanyaId]);
    $rows = $ar->fetchAll(PDO::FETCH_ASSOC);
    if ($rows) {
        echo "\n  Rincian alokasi per bulan:\n";
        printf("    %-9s %-24s %6s %-14s %16s\n", 'PERIODE', 'RENTANG', 'HARI', 'PIC', 'NOMINAL');
        foreach ($rows as $r) {
            printf("    %-9s %-24s %6s %-14s %16s\n", $r['period_key'],
                $r['allocation_start'] . ' s/d ' . $r['allocation_end'],
                (string) $r['allocated_days'], substr((string) ($r['pic_name'] ?? '-'), 0, 14), $rp($r['amount']));
        }
        printf("    %-42s %14s %16s\n", '', 'JUMLAH', $rp($t['alokasi']));
    }

    // Hitung ulang menurut rumus, untuk dibandingkan dengan yang tersimpan.
    require_once CLARA_ROOT . '/app/AllocationService.php';
    try {
        $rumus = AllocationService::totalCalculated($t);
        echo "\n  Hasil hitung rumus  : " . $rp($rumus)
           . "  (" . $t['pricing_type'] . ", rate " . $rp($t['unit_rate']) . ")\n";
        $selisih = round((float) $t['alokasi'] - $rumus);
        if (abs($selisih) >= 1) {
            echo "  Selisih alokasi vs rumus: " . ($selisih > 0 ? '+' : '') . $rp($selisih) . "\n";
            echo "\n  Artinya nominal salah satu bulan pernah diketik manual. Yang benar\n";
            echo "  ditentukan orang, bukan skrip: kalau Rp " . number_format($rumus, 0, ',', '.')
               . " yang betul, perbaiki bulan\n  yang menyimpang lewat halaman Detail Alokasi.\n";
        }
    } catch (Throwable $e) {
        echo "\n  (rumus tidak bisa dihitung: " . $e->getMessage() . ")\n";
    }
    exit(0);
}

if (!$baris) { echo "\nTidak ada transaksi yang Override Aktual-nya berbeda dari nilai kontrak.\n"; exit(0); }

printf("\n%-7s %-9s %-11s %-26s %16s %16s %16s\n",
    'ID', 'MODUL', 'KODE', 'CLIENT', 'OVERRIDE', 'NILAI KONTRAK', 'JUMLAH ALOKASI');
echo str_repeat('-', 112) . "\n";

$amanDiperbaiki = [];
$perluDilihat   = [];
foreach ($baris as $b) {
    printf("%-7s %-9s %-11s %-26s %16s %16s %16s%s\n",
        '#' . $b['id'], $b['module'], substr((string) $b['master_code'], 0, 11),
        substr((string) ($b['company_name'] ?? '-'), 0, 26),
        $rp($b['override_amount']), $rp($b['final_amount']), $rp($b['alokasi']),
        abs((float) $b['final_amount'] - (float) $b['alokasi']) >= 1 ? '  <- alokasi juga beda' : '');
    if (abs((float) $b['final_amount'] - (float) $b['alokasi']) < 1) $amanDiperbaiki[] = $b;
    else                                                             $perluDilihat[] = $b;
}

echo "\n" . count($baris) . " transaksi melenceng. "
   . count($amanDiperbaiki) . " aman diperbaiki otomatis (alokasinya cocok dengan nilai kontrak), "
   . count($perluDilihat) . " perlu diperiksa manual.\n";

if (!$terapkan) {
    echo "\nLaporan saja. Untuk menyamakan Override Aktual ke nilai kontrak:\n";
    echo "  php scripts/cek_override_vs_final.php --terapkan\n";
    echo "Tidak ada nilai kontrak maupun alokasi yang berubah — hanya kotak override yang disamakan.\n";
    exit(0);
}

// Perubahannya DICATAT di Activity Log, supaya nilai lamanya selalu bisa
// dilihat dan dikembalikan tanpa menebak.
require_once CLARA_ROOT . '/app/helpers.php';
$_SESSION['user'] = ['id' => null, 'name' => 'skrip perbaikan override', 'email' => 'cli', 'role' => 'system'];
$upd = $pdo->prepare('UPDATE transactions SET override_amount = final_amount, updated_by = ? WHERE id = ?');
$n = 0;
foreach ($amanDiperbaiki as $b) {
    $upd->execute(['skrip perbaikan override', (int) $b['id']]);
    $n += $upd->rowCount();
    audit($pdo, 'update', 'transactions', (string) $b['id'],
        ['override_amount' => (float) $b['final_amount'], 'alasan' => 'disamakan dengan nilai kontrak'],
        ['override_amount' => (float) $b['override_amount']]);
}
echo "\n$n transaksi disamakan. Nilai kontrak & alokasinya TIDAK disentuh — income tidak berubah sepeser pun.\n";
if ($perluDilihat) {
    echo count($perluDilihat) . " dilewati karena jumlah alokasinya juga berbeda dari nilai kontrak:\n  ";
    echo implode(', ', array_map(fn($b) => '#' . $b['id'], $perluDilihat)) . "\n";
}
