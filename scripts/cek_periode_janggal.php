<?php
/**
 * Transaksi yang MASA SEWANYA janggal — penyebab income "hilang" dari bulannya.
 *
 * Income selalu jatuh ke bulan yang ditentukan TANGGAL kontraknya. Kalau
 * tanggalnya salah ketik — tahunnya meleset setahun, atau siklus sebulan
 * terketik jadi setahun — nilainya tetap tercatat dan tetap tampil di daftar
 * modul, tetapi jatuh ke bulan yang salah. Dari layar tidak kelihatan apa-apa:
 * barisnya ada, nilainya benar, hanya bulannya yang bukan bulan ini.
 *
 * Yang dicari tiga pola:
 *   1. Tahun mulai lebih besar daripada tahun pembuatannya (biasanya salah
 *      pilih tahun di kalender).
 *   2. Masa sewa jauh lebih panjang daripada siklus-siklus sebelumnya milik
 *      penyewa & unit yang sama.
 *   3. Tahun selesai lebih dari satu tahun setelah tahun mulai, padahal
 *      siklus sebelumnya hanya sebulan.
 *
 * Patokannya RIWAYAT PENYEWA ITU SENDIRI, bukan angka karangan: kontrak
 * tahunan yang memang panjang tidak ikut tertuduh.
 *
 * Read-only: tidak menulis apa pun.
 *
 * Pakai: php scripts/cek_periode_janggal.php --prop=2 [--pic="Shinta"]
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';

$opt = array_merge(['pic' => '', 'prop' => ''], getopt('', ['pic:', 'prop:']));

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$rp = fn($v) => number_format((float) $v, 0, ',', '.');

$syarat = ['t.deleted_at IS NULL', 't.start_date IS NOT NULL', 't.end_date IS NOT NULL'];
$args   = [];
if ($opt['pic']  !== '') { $syarat[] = 't.pic_name = ?';    $args[] = $opt['pic']; }
if ($opt['prop'] !== '') { $syarat[] = 't.property_id = ?'; $args[] = (int) $opt['prop']; }

$st = $pdo->prepare(
    "SELECT t.id, t.module, t.master_code, t.client_id, t.pic_name,
            t.start_date, t.end_date, t.created_at,
            COALESCE(NULLIF(t.final_amount,0), t.total_calculated) nilai,
            COALESCE(c.brand_name, c.company_name, '(tanpa nama)') nama
       FROM transactions t
       LEFT JOIN master_clients c ON c.id = t.client_id
      WHERE " . implode(' AND ', $syarat) . "
      ORDER BY t.client_id, t.master_code, t.start_date, t.id"
);
$st->execute($args);

$hari = function (string $a, string $b): int {
    $ta = strtotime($a); $tb = strtotime($b);
    return ($ta === false || $tb === false || $tb < $ta) ? 0 : (int) floor(($tb - $ta) / 86400) + 1;
};

$grup = [];
foreach ($st->fetchAll() as $r) {
    $grup[$r['client_id'] . '|' . $r['master_code'] . '|' . $r['module']][] = $r;
}

$temuan = [];
foreach ($grup as $rows) {
    $durasi = array_map(fn($r) => $hari($r['start_date'], $r['end_date']), $rows);
    // Patokan lazim: durasi DAN nilai yang paling sering muncul pada penyewa &
    // unit yang sama. Butuh 3 siklus ke atas — dua baris belum jadi pola.
    $lazimHari = 0; $lazimNilai = 0.0;
    if (count($rows) >= 3) {
        $hd = array_count_values(array_map('strval', $durasi));
        arsort($hd);
        if (reset($hd) >= 2) $lazimHari = (int) array_key_first($hd);
        $hn = array_count_values(array_map(fn($r) => (string) round((float) $r['nilai']), $rows));
        arsort($hn);
        if (reset($hn) >= 2) $lazimNilai = (float) array_key_first($hn);
    }
    foreach ($rows as $i => $r) {
        $d      = $durasi[$i];
        $nilai  = (float) $r['nilai'];
        $alasan = [];

        // Pola 1 — masa sewa memanjang TETAPI nilainya tetap sebesar satu
        // siklus biasa. Inilah tanda khas salah ketik tanggal: kontrak yang
        // benar-benar lebih panjang harganya ikut naik sebanding. Tanpa
        // syarat nilai ini, setiap kontrak tahunan ikut tertuduh.
        if ($lazimHari > 0 && $lazimNilai > 0
            && $d > max($lazimHari * 2, $lazimHari + 20)
            && $nilai <= $lazimNilai * 1.5) {
            $alasan[] = $d . ' hari tetapi nilainya masih sebesar siklus ' . $lazimHari . ' hari';
        }

        // Pola 2 — tahun mulainya melompat jauh ke depan dari tanggal
        // pembuatannya. Booking jauh hari memang ada, tetapi setahun penuh
        // hampir selalu salah pilih tahun di kalender.
        $thMulai  = (int) substr((string) $r['start_date'], 0, 4);
        $thDibuat = (int) substr((string) $r['created_at'], 0, 4);
        if ($thDibuat > 0 && $thMulai >= $thDibuat + 1
            && strtotime((string) $r['start_date']) > strtotime((string) $r['created_at'] . ' +300 days')) {
            $alasan[] = 'mulai ' . $thMulai . ' padahal dibuat ' . $thDibuat;
        }

        if ($alasan) {
            $usul = ($lazimHari > 0 && $r['start_date'])
                ? date('Y-m-d', strtotime($r['start_date'] . ' +' . ($lazimHari - 1) . ' days')) : '';
            $temuan[] = $r + ['hari' => $d, 'lazim' => $lazimHari, 'lazim_nilai' => $lazimNilai,
                              'alasan' => implode(' · ', $alasan), 'usul' => $usul];
        }
    }
}

usort($temuan, fn($a, $b) => (float) $b['nilai'] <=> (float) $a['nilai']);

echo "\nMASA SEWA YANG JANGGAL — income jatuh ke bulan yang salah\n";
echo str_repeat('=', 104) . "\n";
printf("  Ditemukan : %d transaksi\n\n", count($temuan));
if (!$temuan) {
    echo "  Tidak ada. Semua masa sewa wajar dibanding riwayat penyewanya.\n\n";
    exit(0);
}

printf("  %-24s %-8s %-11s %-11s %13s  %s\n", 'PENYEWA', 'UNIT', 'MULAI', 'SELESAI', 'NILAI', 'TRX');
echo '  ' . str_repeat('-', 100) . "\n";
$jml = 0.0;
foreach ($temuan as $t) {
    $jml += (float) $t['nilai'];
    printf("  %-24s %-8s %-11s %-11s %13s  #%s\n",
        mb_substr((string) $t['nama'], 0, 24), mb_substr((string) $t['master_code'], 0, 8),
        $t['start_date'], $t['end_date'], $rp($t['nilai']), $t['id']);
    printf("      ↳ %s%s\n", $t['alasan'],
        $t['usul'] ? ' · kalau siklusnya ' . $t['lazim'] . ' hari, selesainya ' . $t['usul'] : '');
}
echo '  ' . str_repeat('-', 100) . "\n";
printf("  %-24s %60s\n", 'JUMLAH NILAI YANG SALAH BULAN', 'Rp ' . $rp($jml));
echo "\n  Perbaikannya lewat Exhibition/Media/Gudang → Edit → betulkan tanggalnya → Simpan.\n";
echo "  Alokasi income langsung dibangun ulang ke bulan yang benar.\n\n";
