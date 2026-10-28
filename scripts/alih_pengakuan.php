<?php
/**
 * Lihat income sebuah transaksi jatuh di bulan mana — dan pindahkan.
 *
 * Dibuat untuk pertanyaan "barangnya sudah ada di Exhibition, kenapa tidak
 * masuk bulan ini?". Jawabannya hampir selalu: nilainya memang tercatat,
 * hanya jatuh di bulan lain — karena tanggalnya, atau karena metode
 * pengakuannya Recurring sehingga dibagi rata ke banyak bulan.
 *
 * Tanpa --pindah skrip ini hanya MELIHAT. Dengan --pindah=YYYY-MM seluruh
 * nilai transaksi itu diakui di satu bulan saja:
 *   billing_method -> anchor_cycle   (sekaligus, tidak dibagi)
 *   period_key     -> bulan tujuan
 * lalu alokasinya dibangun ulang. Nilai kontraknya TIDAK diubah sedikit pun,
 * dan tidak ada baris baru yang dibuat — jadi tidak mungkin jadi dobel.
 *
 * MODE ACARA PENDEK (--acara-pendek) khusus memperbaiki satu pola yang dulu
 * dipasang sendiri oleh sistem: acara SATU periode yang kebetulan melewati
 * batas bulan — mis. 28 Okt–1 Nov — dipecah separuh-separuh ke dua bulan,
 * padahal di berkas tim satu acara dicatat utuh di bulan ia dimulai. Mode ini
 * memindahkan tiap baris ke BULAN MULAINYA SENDIRI, jadi tidak perlu --pindah.
 *
 * Hanya baris dengan tanda yang jelas yang disentuh: metode Recurring, lama
 * sewa <= 40 hari, dan bukan kontrak multi-bulan. Kontrak yang memang berjalan
 * beberapa bulan (mis. Sangray 12 bulan) TIDAK ikut — justru itu bedanya
 * dengan --pic, yang akan menumpuk seluruh nilai setahun ke satu bulan.
 *
 * Pakai:
 *   php scripts/alih_pengakuan.php --trx=2125,2144,2141
 *   php scripts/alih_pengakuan.php --trx=2125,2144,2141 --pindah=2026-10
 *   php scripts/alih_pengakuan.php --pic="Shinta" --prop=2 --bulan=2026-10
 *   php scripts/alih_pengakuan.php --acara-pendek --prop=1 --bulan=2026-10
 *   php scripts/alih_pengakuan.php --acara-pendek --prop=1 --bulan=2026-10 --terapkan
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/AllocationService.php';

$opt = array_merge(['trx' => '', 'pic' => '', 'prop' => '', 'bulan' => '', 'pindah' => ''],
    getopt('', ['trx:', 'pic:', 'prop:', 'bulan:', 'pindah:', 'acara-pendek', 'terapkan']));

$acaraPendek = array_key_exists('acara-pendek', $opt);
$terapkan    = array_key_exists('terapkan', $opt);
if ($acaraPendek && (string) $opt['pindah'] !== '') {
    fwrite(STDERR, "--acara-pendek memindahkan tiap baris ke bulan mulainya sendiri; jangan pakai --pindah.\n");
    exit(2);
}

$pindah = (string) $opt['pindah'];
if ($pindah !== '' && !preg_match('/^\d{4}-\d{2}$/', $pindah)) {
    fwrite(STDERR, "--pindah harus bentuk YYYY-MM, contoh 2026-10\n");
    exit(2);
}

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$rp = fn($v) => number_format((float) $v, 0, ',', '.');

$syarat = ['t.deleted_at IS NULL'];
$args   = [];
if ($opt['trx'] !== '') {
    $ids = array_values(array_filter(array_map('intval', explode(',', (string) $opt['trx']))));
    if (!$ids) { fwrite(STDERR, "--trx kosong\n"); exit(2); }
    $syarat[] = 't.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    $args     = array_merge($args, $ids);
} else {
    if ($opt['pic']  !== '') { $syarat[] = 't.pic_name = ?';    $args[] = $opt['pic']; }
    if ($opt['prop'] !== '') { $syarat[] = 't.property_id = ?'; $args[] = (int) $opt['prop']; }
    if ($opt['bulan'] !== '') {
        $syarat[] = "DATE_FORMAT(t.start_date, '%Y-%m') = ?";
        $args[]   = $opt['bulan'];
    }
    if ($acaraPendek) {
        // Tanda pola yang keliru, bukan tebakan: Recurring + satu siklus +
        // lama sewa <= 40 hari + mulai dan selesai di bulan yang berbeda.
        $syarat[] = "t.billing_method = 'spread'";
        $syarat[] = 'COALESCE(t.contract_months, 1) <= 1';
        $syarat[] = 'DATEDIFF(t.end_date, t.start_date) + 1 <= 40';
        $syarat[] = "DATE_FORMAT(t.start_date, '%Y-%m') <> DATE_FORMAT(t.end_date, '%Y-%m')";
    } elseif ($opt['pic'] === '' && $opt['bulan'] === '') {
        fwrite(STDERR, "Sebutkan --trx, --acara-pendek, atau --pic dan/atau --bulan.\n");
        exit(2);
    }
}

$st = $pdo->prepare(
    "SELECT t.*, COALESCE(c.brand_name, c.company_name, '(tanpa nama)') nama
       FROM transactions t
       LEFT JOIN master_clients c ON c.id = t.client_id
      WHERE " . implode(' AND ', $syarat) . "
      ORDER BY t.start_date, t.id"
);
$st->execute($args);
$rows = $st->fetchAll();

if (!$rows) { echo "\n  Tidak ada transaksi yang cocok.\n\n"; exit(0); }

$alokasi = $pdo->prepare('SELECT period_key, SUM(amount) rp FROM transaction_allocations
                           WHERE transaction_id = ? GROUP BY period_key ORDER BY period_key');

echo "\nINCOME TIAP TRANSAKSI JATUH DI BULAN MANA\n";
echo str_repeat('=', 92) . "\n";
if ($pindah !== '') echo "  MODE PINDAH → seluruh nilai diakui di $pindah\n\n";

$totalNilai = 0.0;
foreach ($rows as $r) {
    $nilai = (float) ($r['final_amount'] ?: $r['total_calculated']);
    $totalNilai += $nilai;
    printf("  #%-6s %-26s %-8s %s..%s  Rp %s\n", $r['id'], mb_substr((string) $r['nama'], 0, 26),
        $r['master_code'], substr((string) $r['start_date'], 0, 10), substr((string) $r['end_date'], 0, 10),
        $rp($nilai));
    printf("      metode %s · diakui di %s\n",
        ($r['billing_method'] ?? '') === 'spread' ? 'Recurring (dibagi per bulan)' : 'Sekaligus (anchor)',
        $r['period_key'] ?: '-');
    $alokasi->execute([(int) $r['id']]);
    $baris = $alokasi->fetchAll();
    if (!$baris) {
        echo "      (belum ada alokasi sama sekali — income Rp 0)\n";
    } else {
        foreach ($baris as $a) {
            printf("        %s  Rp %s\n", $a['period_key'], $rp($a['rp']));
        }
    }
    echo "\n";
}
printf("  Jumlah nilai kontrak yang diperiksa: Rp %s\n", $rp($totalNilai));

if ($acaraPendek && !$terapkan) {
    echo "\n  Semua baris di atas akan dipindahkan ke BULAN MULAINYA masing-masing.\n";
    echo "  Nilai kontraknya tidak diubah dan tidak ada baris baru — tidak akan jadi dobel.\n";
    echo "  Untuk benar-benar menerapkan, ulangi perintahnya dengan tambahan --terapkan\n\n";
    exit(0);
}

if ($pindah === '' && !$acaraPendek) {
    echo "\n  Untuk memindahkan seluruhnya ke satu bulan:\n";
    echo "    php scripts/alih_pengakuan.php --trx=" . implode(',', array_column($rows, 'id')) . " --pindah=YYYY-MM\n";
    echo "  Nilai kontraknya tidak diubah dan tidak ada baris baru — tidak akan jadi dobel.\n\n";
    exit(0);
}

echo "\n  Memindahkan...\n";
$ok = 0; $gagal = 0;
foreach ($rows as $r) {
    try {
        // Mode acara pendek: tujuannya bulan mulai baris ini, bukan satu bulan
        // untuk semua. Dipasang di sini supaya penjaga di bawah tetap berlaku.
        if ($acaraPendek) $pindah = substr((string) $r['start_date'], 0, 7);
        // PENJAGA: transaksi yang BELUM punya alokasi sama sekali dilewati.
        //
        // Kosongnya bukan kelalaian — kesepakatan yang belum diteken client
        // sengaja tidak masuk income sampai tanda tangannya ada (lihat
        // ttd_wajib_untuk di skp.php). Memanggil saveAllocations di sini akan
        // MEMBUAT income yang tadinya tidak ada, bukan memindahkannya.
        $cekKosong = $pdo->prepare('SELECT COUNT(*) FROM transaction_allocations WHERE transaction_id = ?');
        $cekKosong->execute([(int) $r['id']]);
        if ((int) $cekKosong->fetchColumn() === 0) {
            printf("    #%-6s %-26s DILEWATI: belum punya alokasi (menunggu tanda tangan client).\n",
                $r['id'], mb_substr((string) $r['nama'], 0, 26));
            continue;
        }
        // PENJAGA: bulan tujuan harus benar-benar disentuh masa sewanya.
        //
        // Pengakuan bekerja dengan memilih salah satu bulan yang SUDAH punya
        // baris alokasi, lalu menumpuk seluruh nilai ke sana. Kalau bulannya
        // di luar masa sewa, tidak ada baris yang cocok — dan seluruh
        // alokasinya jadi NOL. Tanpa penjaga ini, perintah yang kelihatannya
        // benar justru menghapus income yang tadinya ada.
        $awalBln = substr((string) $r['start_date'], 0, 7);
        $akhirBln = substr((string) $r['end_date'], 0, 7);
        // PERINGATAN: nilai penuh kontrak ditumpuk ke SATU bulan. Benar untuk
        // kontrak satu siklus; untuk kontrak yang berjalan beberapa bulan,
        // bulan itu jadi kelebihan sebesar porsi bulan-bulan lainnya. Yang
        // dibutuhkan biasanya membetulkan TANGGAL, bukan menumpuk.
        if ($awalBln !== $akhirBln && !$acaraPendek) {
            $nBln = ((int) substr($akhirBln, 0, 4) - (int) substr($awalBln, 0, 4)) * 12
                  + ((int) substr($akhirBln, 5, 2) - (int) substr($awalBln, 5, 2)) + 1;
            printf("    #%-6s %-26s PERHATIAN: masa sewanya %d bulan (%s s/d %s).\n",
                $r['id'], mb_substr((string) $r['nama'], 0, 26), $nBln, $awalBln, $akhirBln);
            printf("             Seluruh Rp %s akan ditumpuk ke %s saja.\n",
                $rp((float) ($r['final_amount'] ?: $r['total_calculated'])), $pindah);
            echo "             Kalau yang benar hanya SATU siklus, betulkan tanggalnya dulu.\n";
        }
        if ($pindah < $awalBln || $pindah > $akhirBln) {
            printf("    #%-6s %-26s DITOLAK: %s di luar masa sewa (%s s/d %s).\n",
                $r['id'], mb_substr((string) $r['nama'], 0, 26), $pindah, $awalBln, $akhirBln);
            echo "             Betulkan dulu TANGGALNYA lewat Exhibition -> Edit.\n";
            $gagal++;
            continue;
        }
        $pdo->prepare("UPDATE transactions SET billing_method = 'anchor_cycle', period_key = ?
                        WHERE id = ?")->execute([$pindah, (int) $r['id']]);
        $trx = $r;
        $trx['billing_method']     = 'anchor_cycle';
        $trx['period_key']         = $pindah;
        $trx['recognition_period'] = $pindah;
        AllocationService::saveAllocations($pdo, (int) $r['id'], $trx);

        $alokasi->execute([(int) $r['id']]);
        $kini = $alokasi->fetchAll();
        $di   = 0.0;
        foreach ($kini as $a) if ($a['period_key'] === $pindah) $di = (float) $a['rp'];
        printf("    #%-6s %-26s -> %s Rp %s%s\n", $r['id'], mb_substr((string) $r['nama'], 0, 26),
            $pindah, $rp($di), $di > 0 ? '' : '  (MASIH NOL)');
        $di > 0 ? $ok++ : $gagal++;
        audit($pdo, 'alih_pengakuan', 'transactions', (string) $r['id'],
            ['ke' => $pindah, 'nilai' => (float) ($r['final_amount'] ?: $r['total_calculated'])]);
    } catch (Throwable $e) {
        $gagal++;
        printf("    #%-6s GAGAL: %s\n", $r['id'], $e->getMessage());
    }
}
printf("\n  Berhasil %d · gagal %d\n\n", $ok, $gagal);
