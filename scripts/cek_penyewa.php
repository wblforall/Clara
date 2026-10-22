<?php
/**
 * Lacak transaksi seorang penyewa langsung dari tabelnya.
 *
 * Dibuat untuk pertanyaan "#1604 tidak ada di CLARA, yang mana itu?". Daftar di
 * modul Exhibition/Media/Gudang menyaring isinya, jadi sebuah transaksi bisa
 * punya income tetapi tidak kelihatan di layar. Skrip ini tidak menyaring apa
 * pun: yang ada di tabel, itu yang ditampilkan — termasuk yang sudah dibatalkan.
 *
 * Semua kolom dibaca lewat SHOW COLUMNS, bukan didaftar di sini, supaya tidak
 * ada kolom yang terlewat saat skemanya bertambah.
 *
 * MURNI MELIHAT — tidak ada satu pun perintah yang mengubah data.
 *
 * Pakai:
 *   php scripts/cek_penyewa.php --nama="Planet Surf"
 *   php scripts/cek_penyewa.php --nama="Planet Surf" --prop=2
 *   php scripts/cek_penyewa.php --unit=UG01
 *   php scripts/cek_penyewa.php --trx=1604
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';

$opt = array_merge(['nama' => '', 'unit' => '', 'trx' => '', 'prop' => ''],
    getopt('', ['nama:', 'unit:', 'trx:', 'prop:']));

if ($opt['nama'] === '' && $opt['unit'] === '' && $opt['trx'] === '') {
    fwrite(STDERR, "Sebutkan salah satu: --nama=\"Planet Surf\" | --unit=UG01 | --trx=1604 [--prop=2]\n");
    exit(2);
}

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$rp = fn($v) => number_format((float) $v, 0, ',', '.');

$kolom = array_column($pdo->query('SHOW COLUMNS FROM transactions')->fetchAll(), 'Field');

$syarat = []; $args = [];
if ($opt['trx'] !== '') {
    $ids = array_values(array_filter(array_map('intval', explode(',', (string) $opt['trx']))));
    $syarat[] = 't.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    $args = array_merge($args, $ids);
}
if ($opt['nama'] !== '') {
    // Nama dicari di penyewa DAN di kolom catatan transaksinya: baris lama
    // kadang hanya menyimpan namanya sebagai teks, tanpa client_id.
    $cari = '%' . $opt['nama'] . '%';
    $bagian = ['c.brand_name LIKE ?', 'c.company_name LIKE ?'];
    $args[] = $cari; $args[] = $cari;
    foreach (['tenant_name', 'notes', 'description'] as $k) {
        if (in_array($k, $kolom, true)) { $bagian[] = "t.`$k` LIKE ?"; $args[] = $cari; }
    }
    $syarat[] = '(' . implode(' OR ', $bagian) . ')';
}
if ($opt['unit'] !== '') { $syarat[] = 't.master_code LIKE ?'; $args[] = '%' . $opt['unit'] . '%'; }
if ($opt['prop'] !== '') { $syarat[] = 't.property_id = ?'; $args[] = (int) $opt['prop']; }

$st = $pdo->prepare(
    "SELECT t.*, COALESCE(c.brand_name, c.company_name, '(tanpa nama)') AS nama_penyewa
       FROM transactions t
       LEFT JOIN master_clients c ON c.id = t.client_id
      WHERE " . implode(' AND ', $syarat) . "
      ORDER BY t.start_date, t.id"
);
$st->execute($args);
$rows = $st->fetchAll();

echo "\nLACAK TRANSAKSI PENYEWA\n";
echo str_repeat('=', 100) . "\n";
printf("  Saringan: %s%s%s%s\n",
    $opt['nama'] !== '' ? "nama~\"{$opt['nama']}\" " : '',
    $opt['unit'] !== '' ? "unit~\"{$opt['unit']}\" " : '',
    $opt['trx']  !== '' ? "trx={$opt['trx']} " : '',
    $opt['prop'] !== '' ? "properti={$opt['prop']}" : '(semua properti)');
printf("  Ditemukan: %d transaksi (termasuk yang sudah dibatalkan)\n\n", count($rows));

if (!$rows) {
    echo "  Tidak ada. Coba nama yang lebih pendek — pencariannya cocok sebagian,\n";
    echo "  jadi \"Planet\" lebih luas daripada \"Planet Surf\".\n\n";
    exit(0);
}

$qAlok = $pdo->prepare('SELECT period_key, SUM(amount) rp, MIN(allocation_start) a1, MAX(allocation_end) a2
                          FROM transaction_allocations WHERE transaction_id = ?
                         GROUP BY period_key ORDER BY period_key');

// Dokumen SKP-nya — dicari dengan cara yang SAMA PERSIS seperti daftar modul
// (app/pages/transactions.php:21). Penawaran paket melahirkan beberapa transaksi
// dari satu dokumen: hanya satu yang tercatat sebagai transaction_id, sisanya
// menunjuk balik lewat transactions.skp_id. Kalau di sini dicari dengan cara
// lain, statusnya bisa berbeda dari yang dipakai menyaring daftar — dan
// jawabannya jadi menyesatkan.
$qSkp = $pdo->prepare('SELECT * FROM skp_documents s2
                        WHERE s2.property_id = ? AND (s2.transaction_id = ? OR s2.id = ?)
                        ORDER BY s2.id DESC');

foreach ($rows as $t) {
    $nilai = (float) ($t['final_amount'] ?: $t['total_calculated']);
    printf("  #%-6s %-28s %-8s %-10s %s s/d %s   Rp %s%s\n",
        $t['id'], mb_substr((string) $t['nama_penyewa'], 0, 28), $t['module'], $t['master_code'],
        substr((string) $t['start_date'], 0, 10), substr((string) $t['end_date'], 0, 10), $rp($nilai),
        $t['deleted_at'] ? '   *** SUDAH DIBATALKAN ***' : '');
    printf("        PIC %s · properti %s · harga %s · rate %s · metode %s · diakui %s · siklus %s%s\n",
        $t['pic_name'] ?: '(tanpa PIC)', $t['property_id'], $t['pricing_type'], $rp($t['unit_rate']),
        ($t['billing_method'] ?? '') === 'spread' ? 'Recurring' : 'Sekaligus',
        $t['period_key'] ?: '-', $t['cycle_recognition'] ?: 'cycle_start',
        !empty($t['prorata_kalender']) ? ' · prorata kalender' : '');
    if ($t['deleted_at']) printf("        dibatalkan pada %s\n", $t['deleted_at']);

    $qAlok->execute([(int) $t['id']]);
    $alok = $qAlok->fetchAll();
    if (!$alok) {
        echo "        INCOME: belum ada baris alokasi sama sekali — Rp 0 di semua bulan\n";
    } else {
        $jml = 0.0;
        echo "        INCOME per bulan:\n";
        foreach ($alok as $a) {
            printf("          %s  Rp %-16s (%s s/d %s)\n", $a['period_key'], $rp($a['rp']), $a['a1'], $a['a2']);
            $jml += (float) $a['rp'];
        }
        printf("          jumlah Rp %s%s\n", $rp($jml),
            round($jml) === round($nilai) ? ' (PAS dengan nilai kontrak)' : '  <-- TIDAK SAMA dengan nilai kontrak');
    }

    $qSkp->execute([(int) $t['property_id'], (int) $t['id'], (int) ($t['skp_id'] ?? 0)]);
    $skps = $qSkp->fetchAll();
    if (!$skps) {
        echo "        SKP: tidak ada dokumen\n";
    } else {
        foreach ($skps as $i => $s) {
            printf("        SKP #%s status \"%s\"%s%s%s\n", $s['id'], $s['status'] ?? '',
                isset($s['revisi_ke']) ? ' revisi_ke=' . $s['revisi_ke'] : '',
                !empty($s['signed_at']) ? ' ttd ' . $s['signed_at'] : ' (belum ttd)',
                $i === 0 ? '   <- yang dipakai menyaring daftar' : '');
        }
    }
    echo "\n";
}
