<?php
/**
 * Nama lengkap yang sudah dipastikan pemiliknya.
 *
 * Migrasi 093 hanya mengisi nama yang kebetulan sudah berbentuk lengkap, dan
 * sengaja tidak menebak sisanya. Dua nama berikut diberitahukan langsung oleh
 * pemilik CLARA, jadi diisikan di sini supaya surat tidak perlu menunggu
 * seseorang mengetiknya satu per satu.
 *
 * Yang sudah terisi TIDAK ditimpa — begitu nama lengkapnya diubah lewat
 * Master PIC, migrasi ini tidak akan pernah mengembalikannya.
 */

$nama = [
    'Sita'   => 'Rosita',
    'Tengku' => 'Tengku Fadjirina',
];

$st  = $pdo->prepare("SELECT id, property_id, name FROM master_pic
                       WHERE name = ? AND COALESCE(full_name,'') = ''");
$set = $pdo->prepare('UPDATE master_pic SET full_name = ? WHERE id = ?');
$n = 0;
foreach ($nama as $pendek => $lengkap) {
    $st->execute([$pendek]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $set->execute([$lengkap, (int) $r['id']]);
        echo "     PIC {$r['name']} (properti {$r['property_id']}) → $lengkap\n";
        $n++;
    }
}
echo "     $n nama lengkap PIC diisi\n";
