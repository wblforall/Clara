<?php
/**
 * Perbaiki modul penawaran PAKET yang terlanjur tersimpan sebagai 'bundle'.
 *
 * `offers.module` seharusnya berisi salah satu dari cl / media / gudang. Untuk
 * penawaran paket, kode lama menulis 'bundle' — nilai yang tidak dikenal
 * sebagai modul di mana pun. Akibat yang terlihat di lapangan: dokumen SKP
 * dari penawaran paket HILANG dari daftar begitu disaring per modul, tepat
 * selama ia menunggu paraf.
 *
 * Sebabnya saringan daftar jatuh ke `offers.module` selama transaksinya belum
 * lahir — dan transaksi memang baru lahir saat Manager menyetujui. Jadi justru
 * di tahap "Menunggu Approval" dokumennya tidak kelihatan oleh PIC maupun
 * pemeriksanya. Begitu disetujui ia muncul kembali, karena saringannya
 * berpindah ke modul transaksinya.
 *
 * Migrasi ini mengisi ulang kolomnya dari segmen komponen penawaran itu
 * sendiri: segmen dengan komponen terbanyak, seri dipecah oleh nilai terbesar.
 *
 * Yang SENGAJA tidak disentuh: `skp_documents.doc_type` pada dokumen yang
 * nomornya sudah terbit. Nomor itu sudah tercetak dan dipegang client —
 * mengubah jenisnya berarti mengubah awalan nomornya. Bila ada yang tidak
 * cocok, dilaporkan di bawah untuk diputuskan orang, bukan diubah diam-diam.
 */

$kol = array_column($pdo->query('SHOW COLUMNS FROM `offers`')->fetchAll(PDO::FETCH_ASSOC), 'Field');
if (!in_array('module', $kol, true)) { echo "     kolom offers.module tidak ada — dilewati\n"; return; }

$salah = $pdo->query("SELECT id FROM offers WHERE module NOT IN ('cl','media','gudang') OR module IS NULL")
             ->fetchAll(PDO::FETCH_COLUMN);
if (!$salah) { echo "     tidak ada penawaran dengan modul tidak dikenal\n"; return; }

$komponen = $pdo->prepare(
    "SELECT segment, COUNT(*) n, COALESCE(SUM(total_amount), 0) nilai
       FROM offer_items WHERE offer_id = ?
      GROUP BY segment ORDER BY n DESC, nilai DESC"
);
$ubah  = $pdo->prepare('UPDATE offers SET module = ? WHERE id = ?');
$rekap = [];

foreach ($salah as $id) {
    $komponen->execute([(int) $id]);
    $baris = $komponen->fetchAll(PDO::FETCH_ASSOC);
    // Tanpa komponen sama sekali, tebakan paling aman adalah Exhibition —
    // modul yang dipakai hampir seluruh penawaran di sistem ini.
    $modul = 'cl';
    foreach ($baris as $b) {
        if (in_array($b['segment'], ['cl', 'media', 'gudang'], true)) { $modul = (string) $b['segment']; break; }
    }
    $ubah->execute([$modul, (int) $id]);
    $rekap[$modul] = ($rekap[$modul] ?? 0) + 1;
}
foreach ($rekap as $m => $n) echo "     $n penawaran paket -> modul '$m'\n";

// Laporkan dokumen yang jenisnya kini tidak sejalan dengan modul penawarannya.
$beda = $pdo->query(
    "SELECT s.id, s.doc_type, s.skp_no, s.status, o.module
       FROM skp_documents s JOIN offers o ON o.id = s.offer_id
      WHERE s.deleted_at IS NULL
        AND s.doc_type <> CASE o.module WHEN 'gudang' THEN 'sks' WHEN 'media' THEN 'fu' ELSE 'skp' END"
)->fetchAll(PDO::FETCH_ASSOC);

if (!$beda) { echo "     jenis dokumen semuanya sudah sejalan dengan modulnya\n"; return; }
foreach ($beda as $d) {
    $nomor = trim((string) $d['skp_no']);
    if ($nomor === '') {
        // Belum bernomor berarti belum ada yang memegang dokumennya — aman
        // diluruskan supaya rantai parafnya memakai alur yang benar.
        $benar = ['gudang' => 'sks', 'media' => 'fu'][$d['module']] ?? 'skp';
        $pdo->prepare('UPDATE skp_documents SET doc_type = ? WHERE id = ?')->execute([$benar, (int) $d['id']]);
        echo "     dokumen #{$d['id']} (belum bernomor) jenisnya diluruskan: {$d['doc_type']} -> $benar\n";
    } else {
        echo "     PERIKSA MANUAL: dokumen #{$d['id']} nomor $nomor berjenis {$d['doc_type']}"
           . " padahal modulnya {$d['module']} — nomornya sudah terbit, tidak diubah otomatis\n";
    }
}
