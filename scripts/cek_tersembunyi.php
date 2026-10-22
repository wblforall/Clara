<?php
/**
 * Income yang terhitung tetapi barisnya tidak muncul di daftar modulnya.
 *
 * Daftar Exhibition/Media/Gudang menyaring isinya: dokumen yang ditolak, yang
 * sedang direvisi, dan — sejak aturan tanda tangan berlaku — yang belum diteken
 * klien. Laporan income TIDAK ikut menyaring: app/pages/laporan.php hanya
 * membuang baris yang dibatalkan. Akibatnya sebuah kontrak bisa menyumbang
 * puluhan juta ke Dashboard sementara barisnya tidak ada di layar siapa pun —
 * tidak bisa diperiksa, tidak bisa dibetulkan, dan tidak ada yang tahu.
 *
 * Skrip ini mendaftar persis baris-baris itu. MURNI MELIHAT.
 *
 * Pakai:
 *   php scripts/cek_tersembunyi.php --bulan=2026-10
 *   php scripts/cek_tersembunyi.php --bulan=2026-10 --prop=2
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';

$opt = array_merge(['bulan' => date('Y-m'), 'prop' => ''], getopt('', ['bulan:', 'prop:']));
$bln = (string) $opt['bulan'];
if (!preg_match('/^\d{4}-\d{2}$/', $bln)) { fwrite(STDERR, "--bulan harus YYYY-MM\n"); exit(2); }

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$rp = fn($v) => number_format((float) $v, 0, ',', '.');

$ttd = (string) (get_setting($pdo, 'ttd_wajib_mulai', '') ?: '');

// Status dokumen terakhir — disalin apa adanya dari app/pages/transactions.php:22
// supaya yang diperiksa benar-benar dokumen yang sama dengan yang dipakai
// menyaring daftar, termasuk penawaran paket lewat transactions.skp_id.
$doc = fn(string $kol) => "(SELECT s2.$kol FROM skp_documents s2
         WHERE s2.property_id = t.property_id AND (s2.transaction_id = t.id OR s2.id = t.skp_id)
         ORDER BY s2.id DESC LIMIT 1)";

$syarat = ['t.deleted_at IS NULL', 'a.period_key = ?', 'a.amount > 0'];
$args   = [$bln];
if ($opt['prop'] !== '') { $syarat[] = 't.property_id = ?'; $args[] = (int) $opt['prop']; }

$st = $pdo->prepare(
    "SELECT t.id, t.module, t.master_code, t.pic_name, t.property_id,
            t.start_date, t.end_date, t.created_at,
            COALESCE(c.brand_name, c.company_name, '(tanpa nama)') nama,
            SUM(a.amount) rp,
            COALESCE(" . $doc('status') . ", '') st_doc,
            COALESCE(" . $doc('revisi_ke') . ", 0) revisi
       FROM transactions t
       JOIN transaction_allocations a ON a.transaction_id = t.id
       LEFT JOIN master_clients c ON c.id = t.client_id
      WHERE " . implode(' AND ', $syarat) . "
      GROUP BY t.id
      ORDER BY SUM(a.amount) DESC"
);
$st->execute($args);

$sembunyi = []; $totalSemua = 0.0;
foreach ($st->fetchAll() as $r) {
    $totalSemua += (float) $r['rp'];
    $s = (string) $r['st_doc'];
    $alasan = null;
    if ($s === 'rejected') {
        $alasan = 'dokumennya DITOLAK';
    } elseif ($s === 'draft' && (int) $r['revisi'] > 0) {
        $alasan = 'dokumennya sedang DIREVISI (draft, revisi ke-' . (int) $r['revisi'] . ')';
    } elseif ($ttd !== '' && !in_array($s, ['', 'signed'], true)
              && substr((string) $r['created_at'], 0, 10) >= $ttd) {
        $alasan = 'belum ditandatangani klien (status dokumen "' . $s . '")';
    }
    if ($alasan !== null) $sembunyi[] = $r + ['alasan' => $alasan];
}

echo "\nINCOME YANG TERHITUNG TAPI BARISNYA TIDAK MUNCUL DI DAFTAR MODUL\n";
echo str_repeat('=', 104) . "\n";
printf("  Bulan %s%s · aturan tanda tangan mulai %s\n\n", $bln,
    $opt['prop'] !== '' ? ' · properti ' . (int) $opt['prop'] : ' · semua properti',
    $ttd !== '' ? $ttd : '(belum diatur)');

if (!$sembunyi) {
    printf("  Tidak ada. Seluruh income %s berasal dari baris yang memang kelihatan. (total Rp %s)\n\n",
        $bln, $rp($totalSemua));
    exit(0);
}

$nama = ['cl' => 'Exhibition', 'gudang' => 'Gudang', 'media' => 'Media'];
$jml  = 0.0;
printf("  %-6s %-26s %-11s %-9s %-9s %16s\n", 'TRX', 'PENYEWA', 'MODUL', 'UNIT', 'PIC', 'NILAI BULAN INI');
echo '  ' . str_repeat('-', 102) . "\n";
foreach ($sembunyi as $r) {
    printf("  #%-5s %-26s %-11s %-9s %-9s %16s\n", $r['id'], mb_substr((string) $r['nama'], 0, 26),
        $nama[$r['module']] ?? $r['module'], $r['master_code'], $r['pic_name'] ?: '-', $rp($r['rp']));
    printf("         %s · dibuat %s · sewa %s s/d %s\n", $r['alasan'],
        substr((string) $r['created_at'], 0, 10),
        substr((string) $r['start_date'], 0, 10), substr((string) $r['end_date'], 0, 10));
    $jml += (float) $r['rp'];
}
echo '  ' . str_repeat('-', 102) . "\n";
printf("  %d baris · Rp %s dari total income %s sebesar Rp %s (%.1f%%)\n\n",
    count($sembunyi), $rp($jml), $bln, $rp($totalSemua),
    $totalSemua > 0 ? $jml / $totalSemua * 100 : 0);

echo "  Artinya: angka di Dashboard dan Laporan PIC sudah memuat nilai di atas, tetapi\n";
echo "  orang yang membuka menu modulnya tidak akan menemukan barisnya — jadi tidak\n";
echo "  bisa diperiksa maupun dibetulkan dari layar.\n";
echo "  Yang perlu diputuskan: apakah kontrak yang belum diteken klien boleh ikut\n";
echo "  terhitung. Kalau tidak boleh, alokasinya yang harus dikosongkan — bukan\n";
echo "  daftarnya yang dilonggarkan.\n\n";
