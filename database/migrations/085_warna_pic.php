<?php
/**
 * Warna tetap untuk tiap PIC, dipakai mewarnai kalender.
 *
 * Di Google Sheet tim, setiap kotak pemesanan diberi warna sesuai PIC yang
 * menjualnya, dan warna itu sama dengan warna kolom PIC di rekap. Sekali
 * lihat, seorang PIC tahu mana miliknya dan seberapa banyak dibanding rekannya.
 * Kalender CLARA hanya punya dua warna (jenis transaksi), sehingga pertanyaan
 * "mana punyaku" cuma bisa dijawab dengan menyaring PIC satu per satu — dan
 * begitu disaring, pembandingnya hilang.
 *
 * Warna disimpan di master PIC supaya bisa diubah sendiri tanpa koding, dan
 * supaya satu orang memakai warna yang sama di semua layar.
 *
 * Empat nama pertama disamakan dengan spreadsheet yang dipakai hari ini —
 * pink Sita, abu-abu Muslimin, hijau Zulfikar, merah Icha — supaya tim tidak
 * perlu menghafal warna baru. Sisanya dibagikan dari palet, tanpa kuning:
 * kuning dipakai menandai calon yang belum pasti.
 */

$kol = array_column($pdo->query('SHOW COLUMNS FROM `master_pic`')->fetchAll(PDO::FETCH_ASSOC), 'Field');
if (!in_array('color', $kol, true)) {
    $pdo->exec("ALTER TABLE `master_pic` ADD COLUMN `color` VARCHAR(7) NULL DEFAULT NULL AFTER `target_share`");
    echo "     master_pic.color ditambahkan\n";
}

// Warna lembut: latar untuk kotak kalender, tulisannya tetap gelap & terbaca.
$tetap = [
    'sita'      => '#FBCFE8',   // pink
    'rosita'    => '#FBCFE8',
    'muslimin'  => '#D8DEE8',   // abu-abu
    'zulfikar'  => '#BBF7D0',   // hijau
    'zul'       => '#BBF7D0',
    'icha'      => '#FECACA',   // merah
    'annisa'    => '#FECACA',
];
$palet = ['#BFDBFE', '#DDD6FE', '#FED7AA', '#99F6E4', '#C7D2FE', '#F5D0FE', '#BAE6FD', '#D9F99D'];

$rows = $pdo->query("SELECT id, name, color FROM master_pic ORDER BY property_id, id")->fetchAll(PDO::FETCH_ASSOC);
$set  = $pdo->prepare('UPDATE master_pic SET color = ? WHERE id = ?');
$i = 0; $n = 0;
foreach ($rows as $r) {
    if (trim((string) ($r['color'] ?? '')) !== '') continue;   // sudah dipilih sendiri
    $nama = mb_strtolower(trim((string) $r['name']));
    $warna = null;
    foreach ($tetap as $kunci => $w) {
        if ($nama === $kunci || str_starts_with($nama, $kunci . ' ')) { $warna = $w; break; }
    }
    if ($warna === null) { $warna = $palet[$i % count($palet)]; $i++; }
    $set->execute([$warna, (int) $r['id']]);
    $n++;
}
echo "     $n PIC diberi warna bawaan\n";
