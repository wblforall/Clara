<?php

/**
 * CLARA — tuliskan kembali komponen (lokasi) sebuah Surat Penawaran Paket.
 *
 * Dipakai untuk penawaran yang terlanjur tersimpan sebagai SATU lokasi padahal
 * sebenarnya gabungan beberapa titik. Penyebabnya: kotak "Penawaran Paket"
 * tidak tercentang saat disimpan, sehingga daftar komponennya dibuang tanpa
 * pemberitahuan. Penyebab itu sudah diperbaiki di aplikasi; skrip ini untuk
 * penawaran yang terlanjur, yang statusnya 'deal' dan tidak bisa diedit lagi.
 *
 * Yang DIUBAH  : hanya baris offer_items (daftar lokasinya).
 * Yang DIBIARKAN: nilai penawaran, is_bundle, module, master_code — supaya
 *                 penerbitan transaksi saat SKP disetujui tidak ikut berubah.
 *                 Ini perbaikan TAMPILAN dokumen, bukan perbaikan uang.
 *
 *   php scripts/perbaiki_komponen_penawaran.php --offer=26 --kode=GF-004,GF-005,GF-006,GF-007
 *   php scripts/perbaiki_komponen_penawaran.php --offer=26 --kode=... --terapkan
 *
 * Tanpa --nilai, nilai penawaran dibagi menurut luas tiap lokasi sehingga
 * jumlahnya PAS dengan nilai yang disepakati. Dengan --nilai, angkanya dipakai
 * apa adanya dan selisihnya (bila ada) disebutkan.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('CLARA_ROOT', dirname(__DIR__));
require_once CLARA_ROOT . '/app/env.php';
require_once CLARA_ROOT . '/app/Database.php';

$offerId = 0; $kodeArg = ''; $nilaiArg = ''; $terapkan = false; $timpa = false;
foreach ($argv as $a) {
    if (preg_match('/^--offer=(\d+)$/', $a, $m)) $offerId  = (int) $m[1];
    if (preg_match('/^--kode=(.+)$/', $a, $m))   $kodeArg  = trim($m[1]);
    if (preg_match('/^--nilai=(.+)$/', $a, $m))  $nilaiArg = trim($m[1]);
    if ($a === '--terapkan') $terapkan = true;
    if ($a === '--timpa')    $timpa    = true;
}
if (!$offerId || $kodeArg === '') {
    echo "\nPakai:\n";
    echo "  php scripts/perbaiki_komponen_penawaran.php --offer=26 --kode=GF-004,GF-005,GF-006,GF-007\n\n";
    echo "Pilihan tambahan:\n";
    echo "  --nilai=18200000,16380000,...   nilai tiap lokasi (default: dibagi menurut luas)\n";
    echo "  --terapkan                      benar-benar menulis (tanpa ini hanya menampilkan rencana)\n";
    echo "  --timpa                         boleh menimpa komponen yang sudah ada\n\n";
    exit(2);
}

$rp  = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
$num = fn($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
$pdo = Database::connect();

$oq = $pdo->prepare('SELECT * FROM offers WHERE id = ?');
$oq->execute([$offerId]);
$o = $oq->fetch(PDO::FETCH_ASSOC);
if (!$o) { echo "\nPenawaran #$offerId tidak ada.\n\n"; exit(1); }
$pid = (int) $o['property_id'];

echo "\nPenawaran\n";
printf("   %-14s %s\n", 'Nomor',  $o['offer_no'] ?: '(belum bernomor)');
printf("   %-14s %s\n", 'Status', $o['status']);
printf("   %-14s %s\n", 'Nilai',  $rp($o['override_amount'] ?: $o['total_calculated']));
printf("   %-14s %s\n", 'Periode', ($o['start_date'] ?: '-') . ' s/d ' . ($o['end_date'] ?: '-'));

$ada = $pdo->prepare('SELECT COUNT(*) FROM offer_items WHERE offer_id = ?');
$ada->execute([$offerId]);
$jmlAda = (int) $ada->fetchColumn();
if ($jmlAda > 0 && !$timpa) {
    echo "\nPenawaran ini SUDAH punya $jmlAda komponen. Tidak ada yang diubah.\n";
    echo "Kalau memang mau ditimpa, tambahkan --timpa.\n\n";
    exit(1);
}

// Lama bulan — dipakai persis seperti formulir penawaran (total = bulanan × bulan).
$bulan = 1;
if ($o['start_date'] && $o['end_date']) {
    $s = strtotime((string) $o['start_date']); $e = strtotime((string) $o['end_date']);
    if ($s !== false && $e !== false && $e >= $s) {
        $bulan = ((int) date('Y', $e) - (int) date('Y', $s)) * 12 + ((int) date('n', $e) - (int) date('n', $s));
        if ((int) date('j', $e) >= (int) date('j', $s)) $bulan++;
        $bulan = max(1, $bulan);
    }
}

/** Cari satu kode di master unit / gudang / media. */
$cariMaster = function (PDO $pdo, int $pid, string $kode): ?array {
    $q = $pdo->prepare('SELECT location_name nama, floor lantai, area_sqm luas FROM master_cl_units WHERE code = ? AND property_id = ?');
    $q->execute([$kode, $pid]);
    if ($r = $q->fetch(PDO::FETCH_ASSOC)) return $r + ['segment' => 'cl'];
    $q = $pdo->prepare('SELECT name nama, location lantai, area_sqm luas FROM master_gudang WHERE code = ? AND property_id = ?');
    $q->execute([$kode, $pid]);
    if ($r = $q->fetch(PDO::FETCH_ASSOC)) return $r + ['segment' => 'gudang'];
    $q = $pdo->prepare("SELECT CONCAT_WS(' - ', media_type, location, NULLIF(point,'')) nama, location lantai, 0 luas
                          FROM master_media WHERE code = ? AND property_id = ?");
    $q->execute([$kode, $pid]);
    if ($r = $q->fetch(PDO::FETCH_ASSOC)) return $r + ['segment' => 'media'];
    return null;
};

$kodes = array_values(array_filter(array_map('trim', explode(',', $kodeArg)), fn($x) => $x !== ''));
if (count($kodes) < 2) { echo "\nPaket minimal 2 lokasi — yang diberikan " . count($kodes) . ".\n\n"; exit(2); }

$baris = []; $hilang = [];
foreach ($kodes as $k) {
    $m = $cariMaster($pdo, $pid, $k);
    if (!$m) { $hilang[] = $k; continue; }
    $baris[] = ['kode' => $k, 'segment' => $m['segment'], 'nama' => (string) ($m['nama'] ?: $k),
                'lantai' => (string) ($m['lantai'] ?: '-'), 'luas' => (float) $m['luas']];
}
if ($hilang) {
    echo "\nKode berikut tidak ada di Master properti ini: " . implode(', ', $hilang) . "\n";
    echo "Periksa ejaannya di halaman Master Exhibition / Gudang / Media.\n\n";
    exit(1);
}

$nilaiOffer = (float) ($o['override_amount'] ?: $o['total_calculated']);
if ($nilaiArg !== '') {
    $angka = array_map(fn($x) => (float) preg_replace('/\D/', '', $x), explode(',', $nilaiArg));
    if (count($angka) !== count($baris)) {
        echo "\nJumlah --nilai (" . count($angka) . ") tidak sama dengan jumlah --kode (" . count($baris) . ").\n\n";
        exit(2);
    }
    foreach ($baris as $i => &$b) $b['nilai'] = $angka[$i];
    unset($b);
} else {
    // Dibagi menurut luas, seperti pembagian nilai paket di aplikasi. Baris
    // terakhir menyerap sisa pembulatan supaya jumlahnya PAS, bukan meleset
    // beberapa rupiah dari nilai yang disepakati.
    $luasTotal = array_sum(array_column($baris, 'luas'));
    if ($luasTotal <= 0) {
        echo "\nSemua lokasi berluas 0 — nilainya tidak bisa dibagi menurut luas.\n";
        echo "Sebutkan nilainya sendiri dengan --nilai=...\n\n";
        exit(2);
    }
    $sisa = $nilaiOffer;
    foreach ($baris as $i => &$b) {
        $b['nilai'] = ($i === count($baris) - 1)
            ? round($sisa)
            : round($nilaiOffer * $b['luas'] / $luasTotal);
        $sisa -= $b['nilai'];
    }
    unset($b);
}

echo "\nRencana komponen (" . count($baris) . " lokasi)\n";
printf("   %-10s %-28s %-8s %10s %16s\n", 'KODE', 'LOKASI', 'LANTAI', 'LUAS', 'NILAI');
$luasJml = 0.0; $nilaiJml = 0.0;
foreach ($baris as $b) {
    $luasJml += $b['luas']; $nilaiJml += $b['nilai'];
    printf("   %-10s %-28s %-8s %10s %16s\n", $b['kode'], substr($b['nama'], 0, 28),
        $b['lantai'], $num($b['luas']), $rp($b['nilai']));
}
printf("   %-48s %10s %16s\n", 'TOTAL', $num($luasJml), $rp($nilaiJml));
if (abs($nilaiJml - $nilaiOffer) >= 1) {
    echo "\n   Jumlah di atas " . $rp($nilaiJml) . ", nilai penawaran " . $rp($nilaiOffer)
       . " (selisih " . $rp(abs($nilaiJml - $nilaiOffer)) . ").\n";
    echo "   Selisih itu dicetak sebagai baris \"Penyesuaian harga paket\" di surat.\n";
}

if (!$terapkan) {
    echo "\nBelum ada yang ditulis (mode tampilan). Tambahkan --terapkan untuk menyimpan.\n\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM offer_items WHERE offer_id = ?')->execute([$offerId]);
    $ins = $pdo->prepare(
        'INSERT INTO offer_items
         (offer_id, segment, master_code, name_snapshot, pricing_type, unit_rate, area_sqm, slots,
          monthly_amount, dp_amount, deposit_amount, total_amount, sort_order)
         VALUES (?,?,?,?,NULL,0,?,1,?,0,0,?,?)'
    );
    foreach ($baris as $i => $b) {
        $ins->execute([$offerId, $b['segment'], $b['kode'], $b['nama'], $b['luas'],
                       round($b['nilai'] / max(1, $bulan)), $b['nilai'], $i]);
    }
    try {
        $pdo->prepare('INSERT INTO audit_logs (property_id, actor, user_name, user_role, action, table_name, record_id, after_json)
                       VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$pid, 'cli', 'skrip:perbaiki_komponen', 'system', 'perbaiki_komponen', 'offer_items',
                       (string) $offerId, json_encode(['offer_no' => $o['offer_no'], 'komponen' => $baris,
                       'sebelumnya' => $jmlAda . ' baris'], JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) { /* skema audit beda — jangan gagalkan perbaikannya */ }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\nGAGAL menulis: " . $e->getMessage() . "\nTidak ada yang berubah.\n\n";
    exit(1);
}

echo "\n  ✓ " . count($baris) . " komponen tersimpan untuk penawaran " . ($o['offer_no'] ?: '#' . $offerId) . ".\n";
echo "    Nilai penawaran TIDAK diubah (tetap " . $rp($nilaiOffer) . "), begitu pula\n";
echo "    penanda paket & penerbitan transaksinya.\n";
echo "    Periksa hasilnya: php scripts/cek_skp_paket.php --penawaran=" . ($o['offer_no'] ?: $offerId) . "\n\n";
