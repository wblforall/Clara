<?php

/**
 * CLARA — memeriksa & menyiapkan seseorang agar bisa memaraf dokumen.
 *
 * Halaman "Alur Approval Dokumen" hanya mau memakai orang yang lolos LIMA
 * syarat berurutan (lihat _af_kendala() di app/pages/approval_flow.php). Kalau
 * salah satu gagal, namanya tampil kelabu dan tidak bisa dipilih. Skrip ini
 * memeriksa kelimanya untuk satu akun, lalu — bila diminta — memperbaikinya.
 *
 * Syarat kelimanya dinilai memakai _af_kendala() yang ASLI, bukan salinan,
 * supaya penilaian di sini tidak akan pernah berbeda dari yang di layar.
 *
 * Orangnya boleh disebut dengan nama atau email.
 *
 * Jalankan (laporan saja, tidak mengubah apa pun):
 *   php scripts/siapkan_pemaraf.php "Nama Orang" --properti=2
 *
 * Jalankan dan perbaiki:
 *   php scripts/siapkan_pemaraf.php "Nama Orang" --properti=2 --terapkan
 *
 * Syarat ke-5 (izin "Approve SKP" pada sebuah role) menyentuh SEMUA orang yang
 * memakai role itu, bukan satu orang saja. Karena itu ia tidak ikut --terapkan
 * dan butuh --izin-role yang disebut terpisah dan sadar.
 */

declare(strict_types=1);

// Skrip maintenance — hanya boleh lewat command line. Tanpa penjagaan ini,
// berkas fisik di dalam web root masih bisa dipanggil lewat HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('CLARA_ROOT', dirname(__DIR__));
require_once CLARA_ROOT . '/app/env.php';
require_once CLARA_ROOT . '/app/Database.php';
require_once CLARA_ROOT . '/app/pages/approval_flow.php';   // _af_kendala()

/** Baris master_pic + keadaan akunnya, dalam bentuk yang dimengerti _af_kendala(). */
function sp_baris(PDO $pdo, int $picId, int $propertyId): ?array
{
    $st = $pdo->prepare("SELECT p.id, p.name, p.role_name, p.property_id, p.user_id,
                                p.status AS pic_status,
                                u.id AS akun, u.email, u.name AS akun_nama,
                                u.status AS akun_status, u.role AS akun_role,
                                (up.user_id IS NOT NULL) AS boleh_properti,
                                (u.role IN ('superadmin','admin') OR rp.role IS NOT NULL) AS boleh_approve
                           FROM master_pic p
                           LEFT JOIN users u ON u.id = p.user_id
                           LEFT JOIN user_properties up
                                  ON up.user_id = p.user_id AND up.property_id = ?
                           LEFT JOIN role_permissions rp
                                  ON rp.role = u.role AND rp.permission = 'approve_skp'
                          WHERE p.id = ?");
    $st->execute([$propertyId, $picId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Baris master_pic milik akun ini — yang sudah tertaut, plus calon yang senama. */
function sp_calon(PDO $pdo, array $user): array
{
    $st = $pdo->prepare("SELECT id, name, role_name, property_id, status, user_id
                           FROM master_pic
                          WHERE user_id = ?
                             OR (user_id IS NULL AND (LOWER(name) = LOWER(?)
                                 OR LOWER(?) LIKE CONCAT('%', LOWER(name), '%')))
                          ORDER BY (user_id IS NOT NULL) DESC, property_id, id");
    $st->execute([$user['id'], $user['name'], $user['name']]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function sp_main(PDO $pdo, array $argv): int
{
    $email = '';
    $prop = 0; $terapkan = false; $izinRole = false; $picPilih = 0;
    foreach (array_slice($argv, 1) as $a) {
        if ($a === '--terapkan')                   $terapkan = true;
        elseif ($a === '--izin-role')              $izinRole = true;
        elseif (str_starts_with($a, '--properti=')) $prop = (int) substr($a, 11);
        elseif (str_starts_with($a, '--pic='))      $picPilih = (int) substr($a, 6);
        elseif ($a[0] !== '-' && $email === '')     $email = $a;
    }
    if ($email === '' || $prop <= 0) {
        fwrite(STDERR, "Pemakaian: php scripts/siapkan_pemaraf.php <nama|email> --properti=<id> [--terapkan] [--izin-role] [--pic=<id>]\n"
                     . "  --properti: 1 = E-Walk, 2 = Pentacity (properti tempat dia harus bisa memaraf)\n");
        return 2;
    }

    $nm = $pdo->prepare("SELECT id, name FROM properties WHERE id = ?");
    $nm->execute([$prop]);
    $propRow = $nm->fetch(PDO::FETCH_ASSOC);
    if (!$propRow) { fwrite(STDERR, "Properti id=$prop tidak ada.\n"); return 2; }

    // Boleh disebut dengan email ATAU nama — menghafal email tiap orang hanya
    // menambah ribet, padahal namanya sudah tertera di layar.
    if (str_contains($email, '@')) {
        $us = $pdo->prepare("SELECT id, name, email, role, status FROM users WHERE LOWER(email) = LOWER(?)");
        $us->execute([$email]);
        $cocok = $us->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Nama persis didahulukan; kalau tidak ada, baru dicari yang mengandung.
        $us = $pdo->prepare("SELECT id, name, email, role, status FROM users
                              WHERE LOWER(name) = LOWER(?) OR LOWER(name) LIKE LOWER(CONCAT('%', ?, '%'))
                              ORDER BY (LOWER(name) = LOWER(?)) DESC, name");
        $us->execute([$email, $email, $email]);
        $cocok = $us->fetchAll(PDO::FETCH_ASSOC);
        // Beberapa nama mirip, tapi satu di antaranya persis sama — pakai itu.
        $persis = array_values(array_filter($cocok, fn($u) => mb_strtolower($u['name']) === mb_strtolower($email)));
        if (count($persis) === 1) $cocok = $persis;
    }

    echo "AKUN     : $email\n";
    if (!$cocok) {
        echo "         : TIDAK DITEMUKAN di tabel users.\n\n"
           . "Akunnya harus dibuat dulu lewat menu Users & Role, baru skrip ini bisa dipakai.\n";
        return 1;
    }
    if (count($cocok) > 1) {
        echo "         : ADA " . count($cocok) . " AKUN yang cocok —\n";
        foreach ($cocok as $c) echo "           - {$c['name']} <{$c['email']}>\n";
        echo "\nSebutkan emailnya supaya tidak salah orang.\n";
        return 2;
    }
    $user = $cocok[0];
    echo "         : {$user['name']} <{$user['email']}>\n"
       . "         : role \"{$user['role']}\" · status \"{$user['status']}\" · id {$user['id']}\n";
    echo "PROPERTI : {$propRow['name']} (id $prop)\n\n";

    $calon = sp_calon($pdo, $user);
    if (!$calon) {
        echo "Tidak ada baris Master PIC untuk orang ini — tidak yang tertaut, tidak pula yang senama.\n"
           . "Buat dulu barisnya di Master PIC (nama + jabatan), lalu jalankan ulang.\n";
        return 1;
    }

    echo "BARIS MASTER PIC\n";
    foreach ($calon as $c) {
        $taut = $c['user_id'] ? "tertaut akun {$c['user_id']}" : 'BELUM tertaut akun';
        echo sprintf("  [id %-4s] %-22s %-18s properti %s · %s · %s\n",
            $c['id'], $c['name'], $c['role_name'] ?: '(jabatan kosong)', $c['property_id'], $c['status'], $taut);
    }
    echo "\n";

    // Baris yang dipakai: pilihan eksplisit, atau yang sudah tertaut, atau satu-satunya calon.
    $pakai = 0;
    if ($picPilih) {
        foreach ($calon as $c) if ((int) $c['id'] === $picPilih) $pakai = $picPilih;
        if (!$pakai) { fwrite(STDERR, "--pic=$picPilih bukan salah satu baris di atas.\n"); return 2; }
    } else {
        $tertaut = array_values(array_filter($calon, fn($c) => (int) $c['user_id'] === (int) $user['id']));
        if (count($tertaut) === 1)      $pakai = (int) $tertaut[0]['id'];
        elseif (count($tertaut) > 1)    { fwrite(STDERR, "Ada " . count($tertaut) . " baris tertaut ke akun ini. Pilih satu dengan --pic=<id>.\n"); return 2; }
        elseif (count($calon) === 1)    $pakai = (int) $calon[0]['id'];
        else                            { fwrite(STDERR, "Ada beberapa calon dan belum ada yang tertaut. Pilih satu dengan --pic=<id>.\n"); return 2; }
    }
    echo "Baris yang dipakai: id $pakai\n\n";

    // ── Periksa kelima syarat ────────────────────────────────────────────────
    $langkah = function (PDO $pdo) use ($pakai, $prop, $user): array {
        $b = sp_baris($pdo, $pakai, $prop);
        $rencana = [];
        if (($b['pic_status'] ?? '') !== 'active') {
            $rencana[] = ['Baris Master PIC nonaktif', "UPDATE master_pic SET status='active' WHERE id=$pakai",
                          fn() => $pdo->prepare("UPDATE master_pic SET status='active' WHERE id=?")->execute([$pakai])];
        }
        if (!$b['user_id']) {
            $rencana[] = ['Baris Master PIC belum ditautkan ke akun', "UPDATE master_pic SET user_id={$user['id']} WHERE id=$pakai",
                          fn() => $pdo->prepare("UPDATE master_pic SET user_id=? WHERE id=?")->execute([$user['id'], $pakai])];
        }
        if (($b['akun_status'] ?? $user['status']) !== 'active') {
            $rencana[] = ['Akun login nonaktif', "UPDATE users SET status='active' WHERE id={$user['id']}",
                          fn() => $pdo->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$user['id']])];
        }
        if (!$b['boleh_properti']) {
            $rencana[] = ['Akun belum diberi akses properti ini', "INSERT INTO user_properties (user_id,property_id) VALUES ({$user['id']},$prop)",
                          fn() => $pdo->prepare("INSERT IGNORE INTO user_properties (user_id,property_id) VALUES (?,?)")->execute([$user['id'], $prop])];
        }
        return [$b, $rencana];
    };

    [$b, $rencana] = $langkah($pdo);
    $kendala = _af_kendala($b);

    // Syarat ke-5 dinilai dari role AKUN secara langsung, bukan lewat sambungan
    // master_pic → users. Selama barisnya belum tertaut, sambungan itu kosong
    // dan izin role apa pun akan terbaca "belum ada" — peringatan palsu yang
    // menyuruh orang mengubah izin se-role padahal tidak perlu.
    $ip = $pdo->prepare("SELECT 1 FROM role_permissions WHERE role = ? AND permission = 'approve_skp'");
    $ip->execute([$user['role']]);
    $rolePunyaIzin = in_array($user['role'], ['superadmin', 'admin'], true) || (bool) $ip->fetchColumn();

    echo "PEMERIKSAAN (urutan sama persis dengan yang dipakai halaman Alur Approval)\n";
    $syarat = [
        ['Baris Master PIC aktif',          ($b['pic_status'] ?? '') === 'active'],
        ['Baris itu tertaut ke akun login', (bool) $b['user_id']],
        ['Akun login aktif',                ($b['akun_status'] ?? '') === 'active'],
        ['Akun punya akses properti ini',   (bool) $b['boleh_properti']],
        ['Role punya izin "Approve SKP"',   $rolePunyaIzin],
    ];
    foreach ($syarat as [$label, $ok]) echo sprintf("  [%s] %s\n", $ok ? ' OK  ' : 'BELUM', $label);
    echo "\n  Kesimpulan: " . ($kendala === '' ? "SUDAH BISA dipilih & memaraf." : "belum bisa — $kendala") . "\n\n";

    if ($kendala === '') return 0;

    // ── Rencana perbaikan ────────────────────────────────────────────────────
    $perluIzin = !$rolePunyaIzin;
    echo "YANG PERLU DIUBAH\n";
    foreach ($rencana as $i => [$apa, $sql, $_]) echo sprintf("  %d. %s\n     %s\n", $i + 1, $apa, $sql);
    if ($perluIzin) {
        echo sprintf("  %d. Role \"%s\" belum punya izin Approve SKP\n     INSERT INTO role_permissions (role,permission) VALUES ('%s','approve_skp')\n"
                   . "     PERHATIAN: ini berlaku untuk SEMUA pengguna ber-role \"%s\", bukan orang ini saja.\n",
            count($rencana) + 1, $user['role'], $user['role'], $user['role']);
    }
    echo "\n";

    if (!$terapkan) {
        echo "Belum ada yang diubah (mode laporan).\n"
           . "Tambahkan --terapkan untuk menjalankan nomor 1-" . count($rencana) . ".\n";
        if ($perluIzin) echo "Tambahkan juga --izin-role bila izin role di atas memang dikehendaki.\n";
        return 1;
    }

    $pdo->beginTransaction();
    try {
        foreach ($rencana as [$apa, $sql, $jalankan]) { $jalankan(); echo "  dikerjakan: $apa\n"; }
        if ($perluIzin && $izinRole) {
            $pdo->prepare("INSERT IGNORE INTO role_permissions (role,permission) VALUES (?,'approve_skp')")
                ->execute([$user['role']]);
            echo "  dikerjakan: izin Approve SKP untuk role \"{$user['role']}\"\n";
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "GAGAL, semua dibatalkan: " . $e->getMessage() . "\n");
        return 1;
    }

    // ── Periksa ulang dari database, bukan dari asumsi ───────────────────────
    $b2 = sp_baris($pdo, $pakai, $prop);
    $sisa = _af_kendala($b2);
    echo "\nHASIL AKHIR: " . ($sisa === '' ? "SUDAH BISA dipilih & memaraf di {$propRow['name']}." : "masih belum bisa — $sisa") . "\n";
    if ($sisa !== '' && !$izinRole && $perluIzin) {
        echo "Jalankan ulang dengan --izin-role bila izin role itu memang dikehendaki.\n";
    }
    return $sisa === '' ? 0 : 1;
}

// Dijalankan langsung (bukan di-include oleh skrip uji).
if (isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    exit(sp_main(Database::connect(), $argv));
}
