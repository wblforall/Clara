<?php
/**
 * Pembetulan teks baku Form Utilities (Template Dokumen → Media).
 *
 * 1. Catatan kaki PPN ditulis sesuai bunyi resminya: "PMK No. 131", huruf
 *    besar pada Perhitungan & Nilai Sewa, dan diakhiri titik.
 * 2. Butir catatan ke-3 dulu berbunyi "Mohon untuk pemasangan dan pelepasan
 *    media promosi sesuai dengan tanggal pelaksanaan". Dua hal diperbaiki:
 *    kata "Mohon untuk" dibuang (butir lain pun tidak memakai kata permintaan),
 *    dan patokannya bukan "tanggal pelaksanaan" melainkan PERIODE SEWA — yang
 *    memang tercetak di dokumen yang sama.
 *
 * Hanya menyentuh kalimat yang masih persis seperti bunyi lamanya. Properti
 * yang sudah menyunting kalimatnya sendiri lewat Template Dokumen dibiarkan.
 */

$ganti = [
    // lama (persis) => baru
    '*PPN 12% Sesuai PMK Nomor 131 Tahun 2024 dengan perhitungan (nilai sewa x 11/12 x 12%)'
        => '*PPN 12% Sesuai PMK No. 131 Tahun 2024 dengan Perhitungan (Nilai Sewa x 11/12 x 12%).',
    'Mohon untuk pemasangan dan pelepasan media promosi sesuai dengan tanggal pelaksanaan'
        => 'Pemasangan dan pelepasan media promosi sesuai dengan periode sewa',
];

$rows = $pdo->query("SELECT id, property_id, notes_json, terms_json FROM offer_templates WHERE module = 'media'")
            ->fetchAll(PDO::FETCH_ASSOC);
$set = $pdo->prepare('UPDATE offer_templates SET notes_json = ?, terms_json = ? WHERE id = ?');
$n = 0;
foreach ($rows as $r) {
    $ubah = false;
    $baru = [];
    foreach (['notes_json', 'terms_json'] as $kol) {
        $daftar = json_decode((string) $r[$kol], true);
        if (!is_array($daftar)) { $baru[$kol] = (string) $r[$kol]; continue; }
        foreach ($daftar as $i => $teks) {
            $t = trim((string) $teks);
            if (isset($ganti[$t])) { $daftar[$i] = $ganti[$t]; $ubah = true; }
        }
        $baru[$kol] = json_encode(array_values($daftar), JSON_UNESCAPED_UNICODE);
    }
    if (!$ubah) continue;
    $set->execute([$baru['notes_json'], $baru['terms_json'], (int) $r['id']]);
    echo "     template media properti {$r['property_id']} diperbarui\n";
    $n++;
}
echo "     $n template Form Utilities diperbarui teksnya\n";
