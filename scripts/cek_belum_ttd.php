<?php
/**
 * Dokumen yang sudah diinput tetapi income-nya BELUM dihitung.
 *
 * Dibuat setelah pertanyaan yang berulang: "saya sudah input, kenapa angkanya
 * tidak naik?". Jawabannya hampir selalu sama — kesepakatan baru dihitung
 * sebagai pendapatan setelah CLIENT MENANDATANGANI dokumennya, bukan setelah
 * diinput, bukan setelah disetujui Manager, dan bukan lewat Legal.
 *
 * Aturan itu hanya mengikat transaksi yang dibuat MULAI tanggal
 * settings.ttd_wajib_mulai. Yang lebih lama tetap terhitung seperti dulu,
 * jadi dua-duanya dipisahkan di sini supaya tidak tertukar.
 *
 * Read-only: tidak menulis apa pun.
 *
 * Pakai: php scripts/cek_belum_ttd.php --pic="Shinta" [--prop=2] [--bulan=2026-10]
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';

$opt = array_merge(['pic' => '', 'prop' => '', 'bulan' => ''],
    getopt('', ['pic:', 'prop:', 'bulan:']));

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$rp = fn($v) => number_format((float) $v, 0, ',', '.');

$ttdMulai = '';
try {
    $ttdMulai = (string) ($pdo->query("SELECT value FROM settings WHERE `key`='ttd_wajib_mulai' LIMIT 1")
                              ->fetchColumn() ?: '');
} catch (Throwable $e) {
}

echo "\nDOKUMEN YANG BELUM DIHITUNG JADI INCOME\n";
echo str_repeat('=', 96) . "\n";
echo "  Aturan: income dihitung saat CLIENT menandatangani dokumen.\n";
echo "  Berlaku untuk transaksi yang dibuat mulai : " . ($ttdMulai ?: '(belum disetel)') . "\n";

$syarat = ['t.deleted_at IS NULL'];
$args   = [];
if ($opt['pic']  !== '') { $syarat[] = 't.pic_name = ?';     $args[] = $opt['pic']; }
if ($opt['prop'] !== '') { $syarat[] = 't.property_id = ?';  $args[] = (int) $opt['prop']; }
if ($opt['bulan'] !== '') {
    $syarat[] = "DATE_FORMAT(t.start_date, '%Y-%m') = ?";
    $args[]   = $opt['bulan'];
}

// Dokumen + transaksinya + jumlah income yang sudah terbentuk. Nol = belum
// menyumbang apa pun ke laporan.
$sql = "SELECT d.id skp_id, d.skp_no, d.status, d.doc_type, d.signed_at, d.sign_name,
               t.id trx, t.module, t.master_code, t.start_date, t.end_date, t.created_at,
               t.pic_name, COALESCE(NULLIF(t.final_amount,0), t.total_calculated) nilai,
               COALESCE((SELECT SUM(a.amount) FROM transaction_allocations a
                          WHERE a.transaction_id = t.id), 0) income,
               COALESCE(c.brand_name, c.company_name, '(tanpa nama)') nama
          FROM transactions t
          LEFT JOIN skp_documents d
                 ON (d.transaction_id = t.id OR d.id = t.skp_id) AND d.property_id = t.property_id
          LEFT JOIN master_clients c ON c.id = t.client_id
         WHERE " . implode(' AND ', $syarat) . "
         ORDER BY t.start_date, t.id";
$st = $pdo->prepare($sql);
$st->execute($args);

$tertahan = []; $jalan = []; $tanpaDok = [];
foreach ($st->fetchAll() as $r) {
    $terikat = $ttdMulai !== '' && substr((string) $r['created_at'], 0, 10) >= $ttdMulai;
    if ((float) $r['income'] > 0) { $jalan[] = $r; continue; }
    if (!$r['skp_id'])            { $tanpaDok[] = $r + ['terikat' => $terikat]; continue; }
    $tertahan[] = $r + ['terikat' => $terikat];
}

$baris = function (array $r): void {
    global $rp;
    printf("  %-26s %-7s %-9s %s..%s  Rp %13s  %-9s %s\n",
        mb_substr((string) $r['nama'], 0, 26), $r['module'], mb_substr((string) $r['master_code'], 0, 9),
        date('d/m/y', strtotime($r['start_date'])), date('d/m/y', strtotime($r['end_date'])),
        $rp($r['nilai']), $r['status'] ?: '-',
        $r['skp_no'] ? $r['skp_no'] : ('trx #' . $r['trx']));
};

echo "\n── BELUM DITANDATANGANI CLIENT — income masih Rp 0 " . str_repeat('─', 44) . "\n";
if (!$tertahan) echo "  (tidak ada)\n";
$jmlTertahan = 0.0;
foreach ($tertahan as $r) { $baris($r); $jmlTertahan += (float) $r['nilai']; }
if ($tertahan) printf("  %-26s %s\n", 'JUMLAH TERTAHAN', 'Rp ' . $rp($jmlTertahan));

echo "\n── BELUM PUNYA DOKUMEN SKP sama sekali " . str_repeat('─', 56) . "\n";
if (!$tanpaDok) echo "  (tidak ada)\n";
$jmlTanpa = 0.0;
foreach ($tanpaDok as $r) { $baris($r); $jmlTanpa += (float) $r['nilai']; }
if ($tanpaDok) printf("  %-26s %s\n", 'JUMLAH', 'Rp ' . $rp($jmlTanpa));

echo "\n── SUDAH MENYUMBANG INCOME " . str_repeat('─', 68) . "\n";
$jmlJalan = 0.0;
foreach ($jalan as $r) $jmlJalan += (float) $r['income'];
printf("  %d transaksi · Rp %s\n", count($jalan), $rp($jmlJalan));

echo "\n" . str_repeat('=', 96) . "\n";
echo "  Yang tertahan BUKAN karena Legal — modul Permintaan Kontrak tidak\n";
echo "  menyentuh income sama sekali. Urutannya: PIC buat dokumen → Asst.\n";
echo "  Manager paraf → Manager setujui → CLIENT TANDA TANGAN → income masuk.\n";
echo "  Kirim tautan TTD-nya dari halaman dokumen, atau unggah berkas yang\n";
echo "  sudah ditandatangani lewat \"Unggah & Tandai TTD\".\n\n";
