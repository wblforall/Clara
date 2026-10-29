<?php
/**
 * Buang "Air" dan "Atrium" dari daftar item Utilities (Template Dokumen → Media).
 *
 * Keduanya tidak lagi ditawarkan, jadi tidak perlu lagi tercetak sebagai kotak
 * kosong di Form Utilities. Daftar itemnya tetap bisa diubah kapan saja lewat
 * Template Penawaran → Media, termasuk menambahkannya kembali.
 *
 * Dokumen yang MASIH draft ikut bersih sendiri: penyelarasan daftar item sudah
 * dua arah (lihat skp_fu_sinkron_tpl), dan item yang dihapus dari template ikut
 * hilang dari dokumen SELAMA belum tersentuh. Item yang terlanjur dicentang
 * atau sudah berisi biaya tetap tinggal — itu isian orang, bukan isian
 * template. Dokumen yang sudah disetujui mencetak dari salinan bekunya dan
 * memang tidak berubah sama sekali.
 */

$buang = ['air', 'atrium'];

$rows = $pdo->query("SELECT id, property_id, extra_json FROM offer_templates WHERE module = 'media'")
            ->fetchAll(PDO::FETCH_ASSOC);
$set = $pdo->prepare('UPDATE offer_templates SET extra_json = ? WHERE id = ?');
$n = 0;
foreach ($rows as $r) {
    $extra = json_decode((string) $r['extra_json'], true);
    if (!is_array($extra) || !isset($extra['utilities']) || !is_array($extra['utilities'])) continue;
    $sisa = array_values(array_filter(
        $extra['utilities'],
        fn($nama) => !in_array(mb_strtolower(trim((string) $nama)), $buang, true)
    ));
    if (count($sisa) === count($extra['utilities'])) continue;
    $hilang = array_diff($extra['utilities'], $sisa);
    $extra['utilities'] = $sisa;
    $set->execute([json_encode($extra, JSON_UNESCAPED_UNICODE), (int) $r['id']]);
    echo "     template media properti {$r['property_id']}: " . implode(', ', $hilang) . " dibuang\n";
    $n++;
}
echo "     $n daftar item Utilities dibersihkan\n";
