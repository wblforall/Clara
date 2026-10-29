<?php
/**
 * Bersihkan kolom Titik di Master Media yang isinya mengulang kolom lain.
 *
 * Label titik media dirangkai Jenis Media + Lokasi + Titik. Bila Titik ditulis
 * ulang lengkap — mis. Jenis Media "Atrium", Lokasi "GF", Titik "Atrium - GF" —
 * labelnya jadi "Atrium - GF - Atrium - GF", dan itulah yang tercetak di
 * Lokasi Kegiatan pada Form Utilities.
 *
 * Yang dikosongkan HANYA kolom Titik, dan hanya bila seluruh penggalnya memang
 * sudah ada di Jenis Media / Lokasi — jadi tidak ada keterangan yang hilang.
 * Kolom Lokasi tidak pernah disentuh: ia juga dipakai sebagai Lantai di
 * kalender dan laporan.
 *
 * Perangkaian labelnya sendiri sudah melebur penggal berulang (lihat
 * clara_gabung_lokasi di app/helpers.php), jadi baris yang tidak tersentuh di
 * sini pun tidak lagi mencetak dobel.
 */

$penggal = static function (string $v): array {
    $out = [];
    foreach (preg_split('/\s+-\s+/', trim($v)) as $t) {
        $t = trim($t);
        if ($t !== '') $out[] = mb_strtolower($t);
    }
    return $out;
};

$rows = $pdo->query("SELECT id, property_id, code, media_type, location, point FROM master_media")
            ->fetchAll(PDO::FETCH_ASSOC);
$set  = $pdo->prepare("UPDATE master_media SET point = '' WHERE id = ?");
$n = 0; $sisa = 0;
foreach ($rows as $r) {
    $titik = trim((string) ($r['point'] ?? ''));
    $lain  = array_merge($penggal((string) $r['media_type']), $penggal((string) $r['location']));
    if ($titik !== '') {
        $tp = $penggal($titik);
        if ($tp && !array_diff($tp, $lain)) {
            $set->execute([(int) $r['id']]);
            echo "     {$r['code']} (properti {$r['property_id']}): Titik \"$titik\" sudah termuat di Jenis/Lokasi — dikosongkan\n";
            $n++;
            continue;
        }
    }
    // Sisa pengulangan (mis. Jenis Media sama persis dengan Lokasi) dibiarkan
    // datanya; labelnya sudah dilebur saat dirangkai.
    if (count($lain) !== count(array_unique($lain))) $sisa++;
}
echo "     $n titik media dibersihkan\n";
if ($sisa) echo "     $sisa baris masih punya penggal berulang antara Jenis Media & Lokasi — labelnya dilebur saat dicetak\n";
