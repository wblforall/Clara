<?php
/**
 * Biaya listrik dikeluarkan dari nilai transaksi (income).
 *
 * Sampai sekarang listrik yang dicentang di Surat Penawaran dijumlahkan ke
 * `transactions.final_amount` — dan angka itulah yang jadi income bulanan di
 * Laporan PIC maupun Dashboard. Akibatnya listrik terhitung sebagai pendapatan
 * sewa, padahal ia biaya yang ditalangi: halaman Tagihan & Pembayaran sendiri
 * menyebutnya "tidak termasuk nilai transaksi", dan spreadsheet tim mencatat
 * nilai sewa saja.
 *
 * Untuk tiap transaksi yang terkena:
 *   1. listriknya dicatat di dokumennya (electricity_flag + electricity_amount)
 *   2. nilai transaksi, nilai hitung, dan jumlah invoice dikembalikan ke nilai SEWA
 *   3. pengakuan bulanannya DISKALAKAN, bukan dihitung ulang
 *
 * Diskalakan, bukan dihitung ulang, karena menghitung ulang akan kehilangan
 * pola pengakuan yang sudah terbentuk: kontrak yang nilainya diakui penuh di
 * bulan mulai bisa berubah jadi terbagi harian, dan itu menggeser income antar
 * bulan — padahal migrasi ini seharusnya hanya mengeluarkan listriknya.
 *
 * Surat dan tagihan ke client TIDAK berubah: keduanya menambahkan listrik
 * kembali sebagai baris tersendiri. Yang berubah hanya income.
 *
 * Transaksi yang pengakuannya dipatok manual (month override) atau incomenya
 * dibagi antar-PIC sengaja DILEWATI dan dilaporkan.
 */

$cari = $pdo->query(
    "SELECT t.id, t.pic_name, t.master_code, ROUND(t.final_amount) nilai,
            t.invoice_amount, s.id skp_id, s.skp_no, s.electricity_flag, s.snapshot_json
       FROM transactions t JOIN skp_documents s ON s.id = t.skp_id
      WHERE t.deleted_at IS NULL AND s.snapshot_json IS NOT NULL"
)->fetchAll(PDO::FETCH_ASSOC);

$setSkp = $pdo->prepare('UPDATE skp_documents SET electricity_flag = 1, electricity_amount = ? WHERE id = ?');
$adaOverride = $pdo->prepare('SELECT COUNT(*) FROM transaction_month_overrides WHERE transaction_id = ?');
$adaSplit    = $pdo->prepare('SELECT COUNT(*) FROM transaction_pic_splits WHERE transaction_id = ?');
$ambilAlok   = $pdo->prepare('SELECT id, amount FROM transaction_allocations WHERE transaction_id = ? ORDER BY period_key, id');
$setAlok     = $pdo->prepare('UPDATE transaction_allocations SET amount = ? WHERE id = ?');

$n = 0; $turun = 0.0; $lewat = 0;
foreach ($cari as $r) {
    $a = json_decode((string) $r['snapshot_json'], true)['amounts'] ?? null;
    if (!is_array($a)) continue;
    $listrik = (float) ($a['listrik'] ?? 0);
    $sewa    = (float) ($a['sewa'] ?? 0);
    $total   = (float) ($a['total'] ?? 0);
    if ($listrik <= 0 || abs(($sewa + $listrik) - $total) > 1 || abs($total - (float) $r['nilai']) > 1) continue;
    if ($total <= 0) continue;

    $adaOverride->execute([$r['id']]);
    $adaSplit->execute([$r['id']]);
    if ((int) $adaOverride->fetchColumn() > 0 || (int) $adaSplit->fetchColumn() > 0) {
        echo "     ! #{$r['id']} {$r['master_code']} dilewati — pengakuannya dipatok manual / dibagi antar-PIC\n";
        $lewat++;
        continue;
    }

    if (empty($r['electricity_flag'])) $setSkp->execute([$listrik, $r['skp_id']]);

    // Jumlah invoice yang sudah terbit ikut dikembalikan ke nilai sewa — kalau
    // tidak, listriknya terhitung dua kali di kolom "Perkiraan yang ditransfer".
    $invBaru = ($r['invoice_amount'] !== null && abs((float) $r['invoice_amount'] - $total) <= 1) ? $sewa : null;
    $sql = 'UPDATE transactions SET final_amount = ?, total_calculated = ?'
         . ', override_amount = CASE WHEN override_amount IS NULL THEN NULL ELSE ? END'
         . ($invBaru !== null ? ', invoice_amount = ' . (float) $invBaru : '')
         . ' WHERE id = ?';
    $pdo->prepare($sql)->execute([$sewa, $sewa, $sewa, $r['id']]);

    // Pengakuan bulanan diskalakan; sisa pembulatan jatuh ke baris terakhir.
    $ambilAlok->execute([$r['id']]);
    $baris = $ambilAlok->fetchAll(PDO::FETCH_ASSOC);
    $kurang = $sewa;
    foreach ($baris as $i => $b) {
        $nilai = ($i === count($baris) - 1) ? $kurang : round((float) $b['amount'] * $sewa / $total);
        $setAlok->execute([$nilai, $b['id']]);
        $kurang -= $nilai;
    }

    printf("     #%-5s %-9s %-10s %-22s income %s → %s  (listrik %s dipindah%s)\n",
        $r['id'], $r['master_code'], $r['pic_name'], $r['skp_no'],
        number_format($total, 0, ',', '.'), number_format($sewa, 0, ',', '.'),
        number_format($listrik, 0, ',', '.'), $invBaru !== null ? ', invoice disesuaikan' : '');
    $n++; $turun += $listrik;
}
echo "     $n transaksi diperbaiki · income turun Rp " . number_format($turun, 0, ',', '.') . "\n";
if ($lewat) echo "     $lewat transaksi perlu diperiksa manual\n";
