<?php
// Tarif Gudang di master = tarif per m² per bulan; Potensi = luas × tarif/m².
// Lihat docs/TEMUAN-TARIF-POTENSI.md (keputusan 2 Okt 2026).
//
// Kolom monthly_rate selama ini diisi dua arti: sebagian per m² (E-Walk, Rp103.630),
// sebagian total sewa sebulan (kebanyakan Pentacity). Migrasi ini menyeragamkannya:
//
//   - Isian "total"  : potensi == tarif (dan bukan luas × tarif) → tarif := tarif ÷ luas.
//                      Juga tarif yang potensinya 0 tetapi tarif ÷ luas jatuh di kisaran
//                      tarif per m² yang wajar (P402B: 2.647.700 ÷ 24,07 = 110.000).
//   - Isian "per m²" : dibiarkan.
//   - Lalu potensi semua gudang dihitung ulang = luas × tarif/m², lewat snapshot_potential()
//     supaya potensi bulan-bulan lalu tetap beku di angka lama dan perubahannya tercatat
//     di potential_history.
//
// Tarif per m² dibulatkan ke rupiah utuh (103.629,99 → 103.630). Potensi hasil hitung
// ulang bisa bergeser beberapa rupiah dari total lama; itu tercatat di potential_history.

require_once dirname(__DIR__, 2) . '/app/helpers.php';

$rows = $pdo->query('SELECT id, property_id, code, area_sqm, monthly_rate, projection_monthly FROM master_gudang')->fetchAll();
$ubahTarif = $pdo->prepare('UPDATE master_gudang SET monthly_rate = ? WHERE id = ?');
$ubahPot   = $pdo->prepare('UPDATE master_gudang SET projection_monthly = ? WHERE id = ?');

$dikonversi = $potensiBerubah = 0;
foreach ($rows as $r) {
    $luas   = (float) $r['area_sqm'];
    $tarif  = (float) $r['monthly_rate'];
    $pot    = (float) $r['projection_monthly'];
    $perM2  = $luas > 0 ? $tarif / $luas : 0;

    $isiPerM2 = $tarif > 0 && $luas > 0 && abs($pot - round($tarif * $luas)) < 1;
    // Tarif hasil bagi harus jatuh di kisaran tarif per m² yang wajar — tanpa syarat ini
    // gudang nonaktif berisi Rp103.630 (per m²) dengan potensi kebetulan sama dengan
    // tarif ikut terbagi lagi menjadi Rp2.700-an/m².
    $isiTotal = $tarif > 0 && $luas > 0 && !$isiPerM2
        && $perM2 >= 50000 && $perM2 <= 500000
        && (abs($pot - $tarif) < 1 || $pot == 0.0);

    if ($isiTotal) {
        $tarif = round($perM2);
        $ubahTarif->execute([$tarif, (int) $r['id']]);
        $dikonversi++;
        echo "\n    konversi {$r['code']} (prop {$r['property_id']}): {$r['monthly_rate']} → $tarif/m²";
    }

    $baru = master_projection('gudang', ['monthly_rate' => $tarif, 'area_sqm' => $luas]);
    if (abs($baru - $pot) >= 1) {
        $ubahPot->execute([$baru, (int) $r['id']]);
        snapshot_potential($pdo, 'gudang', (int) $r['id'], (string) $r['code'], $baru, (int) $r['property_id'], $pot);
        $potensiBerubah++;
        echo "\n    potensi  {$r['code']} (prop {$r['property_id']}): $pot → $baru";
    }
}
echo "\n    $dikonversi tarif dikonversi ke per m², $potensiBerubah potensi dihitung ulang\n   ";
