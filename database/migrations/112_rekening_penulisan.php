<?php
/**
 * Penulisan nomor rekening pada Template Dokumen — dibetulkan per properti.
 *
 * Migrasi 104 menyeragamkan seluruh template e-Walk ke "2078-01-0005600304",
 * yakni angka yang sama tanpa tanda hubung di belakang. Penulisan itu keliru:
 * yang dipakai e-Walk sehari-hari, dan yang sejak dulu tertulis di kode
 * (offer_bank_bawaan di offers.php), adalah "2078-01-000560-30-4". Nomor yang
 * tercetak di surat harus sama huruf-per-huruf dengan yang disodorkan ke
 * penyewa, supaya tidak ada yang ragu saat memasukkannya ke m-banking.
 *
 * Sekalian dibereskan satu salah tanam yang lebih berbahaya: tiga template
 * Pentacity (Pushcart, Snack Corner, Atrium) memuat nomor rekening e-WALK —
 * warisan migrasi 066 yang menyemai kedua properti dengan satu nomor yang
 * sama. Surat Pentacity yang terbit dari template itu menyuruh penyewa
 * mentransfer ke rekening mal yang lain.
 *
 * PERINGATAN YANG MASIH BERLAKU: nomor Pentacity "2078-01-999999-56-3" dipakai
 * karena begitu tertulis di surat penawaran Pentacity yang sudah terkirim dan
 * dinyatakan pemilik CLARA sebagai rekening Pentacity. Migrasi 104 dulu
 * menganggapnya nomor contoh. Nomor ini sekarang ada di template BAWAAN
 * Pentacity, jadi ia yang terpakai paling sering — layak dipastikan sekali ke
 * Finance.
 */

$TARGET = [
    'ewalk'     => ['baru' => '2078-01-000560-30-4',
                    'lama' => ['2078-01-0005600304', '2078-01-999999-56-3']],
    'pentacity' => ['baru' => '2078-01-999999-56-3',
                    'lama' => ['2078-01-0005600304', '2078-01-000560-30-4']],
];

$sel = $pdo->prepare('SELECT id, name, bank_json, notes_json, penutup FROM offer_templates WHERE property_id = ?');
$upd = $pdo->prepare('UPDATE offer_templates SET bank_json = ?, notes_json = ?, penutup = ?, updated_at = NOW() WHERE id = ?');

$prop = $pdo->query("SELECT id, `key`, name FROM properties ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

foreach ($prop as $p) {
    $t = $TARGET[(string) $p['key']] ?? null;
    if ($t === null) {
        echo "     ! properti {$p['name']} tidak ada di daftar — dilewati\n";
        continue;
    }

    $sel->execute([(int) $p['id']]);
    $n = 0;
    foreach ($sel->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $asal = [$row['bank_json'], $row['notes_json'], $row['penutup']];
        $baru = array_map(
            fn($v) => $v === null ? null : str_replace($t['lama'], $t['baru'], (string) $v),
            $asal
        );
        if ($baru === $asal) continue;
        $upd->execute([$baru[0], $baru[1], $baru[2], (int) $row['id']]);
        echo "     {$p['name']} #{$row['id']} {$row['name']} → {$t['baru']}\n";
        $n++;
    }
    echo "     {$p['name']}: $n template diperbarui\n";
}
