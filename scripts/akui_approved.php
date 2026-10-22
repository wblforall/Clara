<?php
/**
 * Akui income transaksi yang dokumennya SUDAH DISETUJUI MANAGER tetapi belum
 * ditandatangani klien.
 *
 * Aturan bawaan CLARA: alokasi bulanan baru dibangun saat klien meneken
 * (app/pages/skp.php, _skp_bangun_alokasi). Akibatnya kontrak yang sudah
 * lengkap — diinput, diparaf, disetujui Manager — tetap Rp 0 di laporan, dan
 * orang yang membukanya di menu modul melihat barisnya ada tetapi nilainya
 * nol. Dari sisi leasing kesepakatannya sudah jadi; yang tertinggal hanya
 * kertasnya.
 *
 * Skrip ini membangun alokasinya memakai mesin yang sama persis dengan jalur
 * tanda tangan (AllocationService::saveAllocations) — jadi angkanya identik
 * dengan kalau kliennya meneken, tidak ada perhitungan baru di sini.
 *
 * Yang disentuh HANYA yang statusnya 'approved' dan alokasinya masih kosong.
 * Draft, submitted, dan rejected sengaja tidak ikut: itu belum disetujui
 * siapa pun, dan mengakuinya berarti memasukkan angka yang masih bisa berubah.
 *
 * Tanpa --terapkan hanya MELIHAT.
 *
 * Pakai:
 *   php scripts/akui_approved.php --prop=1
 *   php scripts/akui_approved.php --prop=1 --pic="Sita" --terapkan
 *   php scripts/akui_approved.php --trx=2119,2120,2121,2122 --terapkan
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/AllocationService.php';

$opt = array_merge(['prop' => '', 'pic' => '', 'trx' => '', 'status' => 'approved'],
    getopt('', ['prop:', 'pic:', 'trx:', 'status:', 'terapkan']));
$terapkan = array_key_exists('terapkan', getopt('', ['terapkan']));

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$rp = fn($v) => number_format((float) $v, 0, ',', '.');

$status = array_values(array_filter(array_map('trim', explode(',', (string) $opt['status']))));
if (!$status) { fwrite(STDERR, "--status kosong\n"); exit(2); }

// Status dokumen terakhir — dicari dengan cara yang sama seperti daftar modul
// (app/pages/transactions.php:22), termasuk penawaran paket lewat skp_id.
$doc = "(SELECT s2.status FROM skp_documents s2
          WHERE s2.property_id = t.property_id AND (s2.transaction_id = t.id OR s2.id = t.skp_id)
          ORDER BY s2.id DESC LIMIT 1)";

$syarat = ['t.deleted_at IS NULL',
           "COALESCE($doc, '') IN (" . implode(',', array_fill(0, count($status), '?')) . ')',
           'NOT EXISTS (SELECT 1 FROM transaction_allocations a WHERE a.transaction_id = t.id AND a.amount > 0)'];
$args = $status;
if ($opt['trx'] !== '') {
    $ids = array_values(array_filter(array_map('intval', explode(',', (string) $opt['trx']))));
    if (!$ids) { fwrite(STDERR, "--trx kosong\n"); exit(2); }
    $syarat[] = 't.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    $args = array_merge($args, $ids);
}
if ($opt['prop'] !== '') { $syarat[] = 't.property_id = ?'; $args[] = (int) $opt['prop']; }
if ($opt['pic']  !== '') { $syarat[] = 't.pic_name = ?';    $args[] = (string) $opt['pic']; }

$st = $pdo->prepare(
    "SELECT t.*, COALESCE(c.brand_name, c.company_name, '(tanpa nama)') nama, COALESCE($doc, '') st_doc
       FROM transactions t LEFT JOIN master_clients c ON c.id = t.client_id
      WHERE " . implode(' AND ', $syarat) . "
      ORDER BY t.property_id, t.pic_name, t.start_date, t.id"
);
$st->execute($args);
$rows = $st->fetchAll();

echo "\nAKUI INCOME — dokumen sudah disetujui, klien belum menandatangani\n";
echo str_repeat('=', 104) . "\n";
printf("  Status yang diikutkan: %s%s\n\n", implode(', ', $status), $terapkan ? ' · MODE TERAPKAN' : ' · (hanya melihat)');

if (!$rows) { echo "  Tidak ada transaksi yang cocok. Tidak ada yang perlu diakui.\n\n"; exit(0); }

$alok = $pdo->prepare('SELECT period_key, SUM(amount) rp FROM transaction_allocations
                        WHERE transaction_id = ? GROUP BY period_key ORDER BY period_key');
$ok = 0; $gagal = 0; $jumlah = 0.0; $perBulan = [];
foreach ($rows as $r) {
    $nilai = (float) ($r['final_amount'] ?: $r['total_calculated']);
    printf("  #%-5s %-26s %-8s %-7s %s..%s  Rp %s  (%s)\n", $r['id'], mb_substr((string) $r['nama'], 0, 26),
        $r['master_code'], $r['pic_name'] ?: '-', substr((string) $r['start_date'], 0, 10),
        substr((string) $r['end_date'], 0, 10), $rp($nilai), $r['st_doc']);
    if (!$terapkan) { $jumlah += $nilai; continue; }
    try {
        $trx = $r;
        // Sama persis dengan jalur tanda tangan: metode penagihan transaksinya
        // dipakai apa adanya, tidak ditimpa di sini.
        if (($trx['billing_method'] ?? '') !== 'spread') $trx['recognition_period'] = $trx['period_key'];
        else unset($trx['recognition_period']);
        AllocationService::saveAllocations($pdo, (int) $r['id'], $trx);
        $alok->execute([(int) $r['id']]);
        $isi = $alok->fetchAll(); $jml = 0.0;
        foreach ($isi as $a) {
            if ((float) $a['rp'] <= 0) continue;
            printf("        -> %s  Rp %s\n", $a['period_key'], $rp($a['rp']));
            $perBulan[$a['period_key']] = ($perBulan[$a['period_key']] ?? 0) + (float) $a['rp'];
            $jml += (float) $a['rp'];
        }
        if (round($jml) !== round($nilai)) {
            printf("        PERHATIAN: jumlah alokasi Rp %s, nilai kontrak Rp %s\n", $rp($jml), $rp($nilai));
        }
        audit($pdo, 'akui_approved', 'transactions', (string) $r['id'],
            ['status_dokumen' => $r['st_doc'], 'nilai' => $nilai]);
        $jumlah += $jml; $ok++;
    } catch (Throwable $e) {
        $gagal++;
        printf("        GAGAL: %s\n", $e->getMessage());
    }
}

echo "\n";
if (!$terapkan) {
    printf("  %d transaksi · nilai kontrak seluruhnya Rp %s\n", count($rows), $rp($jumlah));
    echo "  Jalankan ulang dengan --terapkan untuk mengakui income-nya.\n\n";
    exit(0);
}
printf("  Berhasil %d · gagal %d · income yang diakui Rp %s\n", $ok, $gagal, $rp($jumlah));
foreach ($perBulan as $k => $v) printf("      %s  Rp %s\n", $k, $rp($v));
echo "\n  Kalau kemudian kliennya menandatangani, alokasinya dibangun ulang dengan\n";
echo "  angka yang sama — tidak akan jadi dobel.\n\n";
