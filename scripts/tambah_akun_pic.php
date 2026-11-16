<?php

/**
 * CLARA — menambah SATU orang sekaligus: akun login + barisnya di Master PIC.
 *
 * Lewat layar, dua hal ini dikerjakan di tempat terpisah: menu Users & Role
 * membuat akun login, lalu Master PIC ditambah sendiri dan ditautkan ke akun
 * itu. Kalau langkah kedua terlewat, orangnya TETAP BISA LOGIN — tetapi daftar
 * Penawaran, SKP, dan Transaksinya kosong. Penyebabnya current_sales_scope()
 * di app/helpers.php: nama PIC seorang sales dicari lewat tautan
 * master_pic.user_id, dan selama tautan itu belum ada yang terlihat olehnya
 * hanya dokumen yang DIA BUAT SENDIRI — dokumen yang dibuatkan admin atas
 * namanya tidak pernah muncul. Sales baru lalu melapor "CLARA saya kosong",
 * padahal akunnya sudah jadi.
 *
 * Karena itu keduanya dikerjakan di sini dalam satu transaksi: jadi dua-duanya,
 * atau tidak jadi sama sekali.
 *
 * Laporan dulu, baru menulis: tanpa --terapkan tidak ada satu baris pun diubah.
 *
 * Pakai:
 *   php scripts/tambah_akun_pic.php "<Nama>" --email=<email> --properti=<1|2> [pilihan]
 *
 * Contoh — sales baru di e-Walk, porsi target 15%:
 *   php scripts/tambah_akun_pic.php "Hisan" \
 *       --email=hanisah@ewalk-bsb.com --nama-lengkap="Hanisah" \
 *       --properti=1 --peran=sales --jabatan=Sales --porsi=15 --terapkan
 *
 * Pilihan:
 *   --email=            email login (wajib, sekaligus dipakai di surat penawaran)
 *   --properti=1|2      1 = E-Walk, 2 = Pentacity (wajib)
 *   --nama-lengkap=     yang TERCETAK di bawah tanda tangan surat; kosong = Nama
 *   --peran=sales       peran akun: lihat roles() di app/helpers.php
 *   --jabatan=          jabatan di Master PIC (mis. Sales, Sales Executive)
 *   --porsi=15          porsi target dalam PERSEN; 0 = tidak masuk papan Achievement
 *   --pic=              nama baris Master PIC bila beda dari nama akun
 *   --hp=               nomor WhatsApp untuk surat penawaran
 *   --warna=#RRGGBB     warna di Kalender Pameran; kosong = dipilih yang belum terpakai
 *   --komisi=           kategori komisi: sales|manager|asst_manager|admin|other|""
 *   --achievement=0|1   tampil di papan Achievement PIC
 *   --di-penawaran=0|1  muncul di pilihan PIC Surat Penawaran
 *   --sandi=123456      sandi awal; orangnya diminta menggantinya saat login pertama
 *   --tanpa-pic         hanya membuat akun login, tanpa baris Master PIC
 *   --url=              alamat CLARA untuk teks WA; kosong = APP_URL di .env
 *   --terapkan          benar-benar menyimpan
 *
 * NAMA versus NAMA LENGKAP — jangan ditukar. "Nama" dipakai sebagai KUNCI
 * pencocokan di transaksi, alokasi, dan laporan (pic_name, created_by), jadi
 * ia harus pendek dan tidak boleh berubah lagi sesudah dipakai. Yang tercetak
 * di surat adalah "Nama Lengkap".
 */

declare(strict_types=1);

// Skrip maintenance — hanya lewat command line. Berkasnya ada di dalam web
// root, jadi tanpa penjagaan ini ia masih bisa dipanggil lewat HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('CLARA_ROOT', dirname(__DIR__));
require_once CLARA_ROOT . '/app/env.php';
require_once CLARA_ROOT . '/app/Database.php';
require_once CLARA_ROOT . '/app/helpers.php';   // roles() — daftar peran yang sah

/** Warna latar kotak Kalender Pameran. Sama dengan pilihan cepat di layar
 *  Master PIC; kuning sengaja tidak ada, itu warna Prospek. */
const TAP_WARNA = ['#FBCFE8', '#D8DEE8', '#BBF7D0', '#FECACA', '#BFDBFE', '#DDD6FE', '#FED7AA', '#99F6E4'];

const TAP_KOMISI = ['', 'sales', 'manager', 'asst_manager', 'admin', 'other'];

function tap_nilai(array $argv, string $awalan, ?string $bila = null): ?string
{
    foreach (array_slice($argv, 1) as $a) {
        if (str_starts_with($a, $awalan)) return substr($a, strlen($awalan));
    }
    return $bila;
}

function tap_ada(array $argv, string $tanda): bool
{
    return in_array($tanda, array_slice($argv, 1), true);
}

function tap_persen(float $pecahan): string
{
    return rtrim(rtrim(number_format($pecahan * 100, 2, ',', '.'), '0'), ',') . '%';
}

/** Warna yang belum dipakai PIC lain di properti ini — satu orang satu warna. */
function tap_warna_bebas(PDO $pdo, int $prop): string
{
    $st = $pdo->prepare('SELECT LOWER(color) FROM master_pic WHERE property_id = ? AND color IS NOT NULL');
    $st->execute([$prop]);
    $terpakai = $st->fetchAll(PDO::FETCH_COLUMN);
    foreach (TAP_WARNA as $w) {
        if (!in_array(strtolower($w), $terpakai, true)) return $w;
    }
    return TAP_WARNA[0];
}

/** Daftar PIC yang target bulanannya ikut terbagi, beserta jumlah porsinya. */
function tap_papan(PDO $pdo, int $prop): array
{
    $st = $pdo->prepare("SELECT name, target_share FROM master_pic
                          WHERE property_id = ? AND status = 'active'
                            AND show_achievement = 1 AND target_share > 0
                          ORDER BY target_share DESC, name");
    $st->execute([$prop]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Jejak di Audit Log. audit() yang asli membaca $_SESSION, yang di CLI tidak
 *  ada — jadi barisnya ditulis langsung, dengan pelaku yang jujur: "cli". */
function tap_jejak(PDO $pdo, int $prop, string $aksi, string $tabel, string $recordId, array $isi): void
{
    $pdo->prepare(
        'INSERT INTO audit_logs
            (property_id, user_id, actor, user_name, user_role, action, module, table_name,
             record_id, route, method, after_json)
         VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $prop, 'cli', 'CLI (' . (get_current_user() ?: 'server') . ')', 'system',
        $aksi, 'users', $tabel, $recordId, 'cli:tambah_akun_pic', 'CLI',
        json_encode($isi, JSON_UNESCAPED_UNICODE),
    ]);
}

function tap_main(PDO $pdo, array $argv): int
{
    // ── Bacaan argumen ───────────────────────────────────────────────────────
    $nama = '';
    foreach (array_slice($argv, 1) as $a) {
        if ($a !== '' && $a[0] !== '-' && $nama === '') $nama = trim($a);
    }
    $email    = trim((string) tap_nilai($argv, '--email=', ''));
    $prop     = (int) tap_nilai($argv, '--properti=', '0');
    $lengkap  = trim((string) tap_nilai($argv, '--nama-lengkap=', ''));
    $peran    = trim((string) tap_nilai($argv, '--peran=', 'sales'));
    $jabatan  = tap_nilai($argv, '--jabatan=');
    $porsiPct = (float) str_replace(',', '.', (string) tap_nilai($argv, '--porsi=', '0'));
    $picNama  = trim((string) tap_nilai($argv, '--pic=', '')) ?: $nama;
    $hp       = trim((string) tap_nilai($argv, '--hp=', ''));
    $warna    = strtoupper(trim((string) tap_nilai($argv, '--warna=', '')));
    $komisi   = tap_nilai($argv, '--komisi=');
    $sandi    = (string) tap_nilai($argv, '--sandi=', '123456');
    $tanpaPic = tap_ada($argv, '--tanpa-pic');
    $terapkan = tap_ada($argv, '--terapkan');
    $url      = trim((string) tap_nilai($argv, '--url=', ''));

    $pakai = "Pemakaian: php scripts/tambah_akun_pic.php \"<Nama>\" --email=<email> --properti=<1|2> [--peran=sales] [--porsi=15] [--terapkan]\n"
           . "           (tanpa --terapkan hanya laporan; selengkapnya ada di komentar atas berkas ini)\n";
    if ($nama === '' || $email === '' || $prop <= 0) { fwrite(STDERR, $pakai); return 2; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fwrite(STDERR, "Email \"$email\" tidak berbentuk email.\n"); return 2;
    }
    if (!array_key_exists($peran, roles())) {
        fwrite(STDERR, "Peran \"$peran\" tidak ada. Yang sah: " . implode(', ', array_keys(roles())) . "\n"); return 2;
    }
    if ($porsiPct < 0 || $porsiPct > 100) { fwrite(STDERR, "Porsi target harus 0–100 (persen).\n"); return 2; }
    if ($warna !== '' && !preg_match('/^#[0-9A-F]{6}$/', $warna)) {
        fwrite(STDERR, "Warna harus bentuk #RRGGBB, bukan \"$warna\".\n"); return 2;
    }
    if ($komisi !== null && !in_array($komisi, TAP_KOMISI, true)) {
        fwrite(STDERR, "Kategori komisi \"$komisi\" tidak ada. Yang sah: " . implode(', ', array_map(fn($k) => $k === '' ? '""' : $k, TAP_KOMISI)) . "\n");
        return 2;
    }

    $ps = $pdo->prepare('SELECT id, name FROM properties WHERE id = ?');
    $ps->execute([$prop]);
    $propRow = $ps->fetch(PDO::FETCH_ASSOC);
    if (!$propRow) { fwrite(STDERR, "Properti id=$prop tidak ada.\n"); return 2; }

    // Bawaan yang berbeda per peran: hanya sales yang ikut papan Achievement dan
    // muncul di pilihan PIC Surat Penawaran. Manager/admin punya baris PIC agar
    // bisa memaraf dan tertulis di surat, tapi tidak ikut dibagi target.
    $isSales      = $peran === 'sales';
    $jabatan    ??= $isSales ? 'Sales' : (roles()[$peran] ?? '');
    $komisi     ??= $isSales ? 'sales' : (in_array($peran, TAP_KOMISI, true) ? $peran : '');
    $achievement  = (int) tap_nilai($argv, '--achievement=', $isSales ? '1' : '0') === 1;
    $diPenawaran  = (int) tap_nilai($argv, '--di-penawaran=', $isSales ? '1' : '0') === 1;
    $porsi        = round($porsiPct / 100, 4);

    // ── Keadaan sekarang ─────────────────────────────────────────────────────
    $us = $pdo->prepare('SELECT id, name, full_name, email, role, status FROM users WHERE LOWER(email) = LOWER(?)');
    $us->execute([$email]);
    $akun = $us->fetch(PDO::FETCH_ASSOC) ?: null;

    // Nama akun dipakai sebagai created_by di seluruh dokumen. Kalau sudah ada
    // orang lain dengan nama yang sama, dokumen mereka akan saling terlihat —
    // jadi ini dihentikan, bukan diperingatkan lalu diteruskan.
    $sameName = $pdo->prepare('SELECT id, email FROM users WHERE LOWER(name) = LOWER(?) AND (? = 0 OR id <> ?)');
    $sameName->execute([$nama, (int) ($akun['id'] ?? 0), (int) ($akun['id'] ?? 0)]);
    if ($kembar = $sameName->fetch(PDO::FETCH_ASSOC)) {
        fwrite(STDERR, "BERHENTI: sudah ada akun lain bernama \"$nama\" (id {$kembar['id']}, {$kembar['email']}).\n"
                     . "Nama akun dipakai sebagai penanda pembuat dokumen (created_by); dua nama yang sama\n"
                     . "membuat dokumen keduanya saling terlihat. Pakai nama yang membedakan keduanya.\n");
        return 2;
    }

    $punyaProp = false;
    if ($akun) {
        $st = $pdo->prepare('SELECT 1 FROM user_properties WHERE user_id = ? AND property_id = ?');
        $st->execute([$akun['id'], $prop]);
        $punyaProp = (bool) $st->fetchColumn();
    }

    $pic = null;
    if (!$tanpaPic) {
        $st = $pdo->prepare('SELECT * FROM master_pic WHERE property_id = ? AND LOWER(name) = LOWER(?)');
        $st->execute([$prop, $picNama]);
        $pic = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Warna kotak Kalender Pameran: kalau barisnya sudah ada, yang dipakai
    // warnanya sendiri — supaya yang tercetak di bawah ini bukan angan-angan.
    if ($warna === '' && !$tanpaPic) {
        $warna = strtoupper(trim((string) ($pic['color'] ?? ''))) ?: tap_warna_bebas($pdo, $prop);
    }

    echo "ORANG      : $nama" . ($lengkap !== '' ? " — tercetak di surat sebagai \"$lengkap\"" : ' (nama lengkap belum diisi)') . "\n";
    echo "EMAIL      : $email\n";
    echo "PROPERTI   : {$propRow['name']} (id $prop)\n";
    echo "PERAN AKUN : " . roles()[$peran] . " ($peran)\n";
    if (!$tanpaPic) {
        echo "BARIS PIC  : \"$picNama\" · jabatan " . ($jabatan !== '' ? "\"$jabatan\"" : '(kosong)')
           . ' · porsi target ' . tap_persen($porsi)
           . ' · komisi ' . ($komisi === '' ? '(tidak dapat komisi)' : $komisi)
           . " · warna $warna\n";
        echo "             papan Achievement: " . ($achievement ? 'tampil' : 'tidak')
           . ' · pilihan PIC Penawaran: ' . ($diPenawaran ? 'muncul' : 'tidak') . "\n";
    } else {
        echo "BARIS PIC  : tidak dibuat (--tanpa-pic)\n";
    }
    echo "\n";

    echo "KEADAAN SEKARANG\n";
    printf("  [%s] Akun login dengan email itu%s\n", $akun ? ' ADA ' : 'BELUM',
        $akun ? " — \"{$akun['name']}\", peran \"{$akun['role']}\", status \"{$akun['status']}\", id {$akun['id']}" : '');
    printf("  [%s] Akses ke properti {$propRow['name']}\n", $punyaProp ? ' ADA ' : 'BELUM');
    if (!$tanpaPic) {
        printf("  [%s] Baris Master PIC \"$picNama\" di properti ini%s\n", $pic ? ' ADA ' : 'BELUM',
            $pic ? " — status \"{$pic['status']}\", " . ($pic['user_id'] ? "tertaut akun {$pic['user_id']}" : 'BELUM tertaut akun') . ", id {$pic['id']}" : '');
    }
    echo "\n";

    // ── Rencana ──────────────────────────────────────────────────────────────
    // Baris yang SUDAH ada tidak ditimpa: yang kosong diisi, yang berbeda hanya
    // dilaporkan. Skrip ini boleh dijalankan ulang tanpa merusak perubahan yang
    // sudah dikerjakan orang lewat layar.
    $rencana = [];
    $catatan = [];

    if (!$akun) {
        $rencana[] = [
            "Membuat akun login \"$nama\" <$email> peran \"$peran\", sandi awal \"$sandi\" (wajib diganti saat login pertama)",
            function () use ($pdo, $nama, $lengkap, $email, $peran, $sandi, $prop): array {
                $pdo->prepare(
                    'INSERT INTO users (name, full_name, email, password_hash, role, status, must_change_password, updated_at)
                     VALUES (?, ?, ?, ?, ?, \'active\', 1, NOW())'
                )->execute([$nama, $lengkap ?: null, $email, password_hash($sandi, PASSWORD_DEFAULT), $peran]);
                $id = (int) $pdo->lastInsertId();
                tap_jejak($pdo, $prop, 'create_user', 'users', (string) $id,
                    ['name' => $nama, 'full_name' => $lengkap, 'email' => $email, 'role' => $peran]);
                return ['user_id' => $id];
            },
        ];
    } else {
        if (($akun['status'] ?? '') !== 'active') {
            $rencana[] = ["Mengaktifkan kembali akun id {$akun['id']} (sekarang \"{$akun['status']}\")",
                function () use ($pdo, $akun, $prop): array {
                    $pdo->prepare("UPDATE users SET status='active', updated_at=NOW() WHERE id=?")->execute([$akun['id']]);
                    tap_jejak($pdo, $prop, 'update_user_role', 'users', (string) $akun['id'], ['status' => 'active']);
                    return [];
                }];
        }
        if ($lengkap !== '' && trim((string) ($akun['full_name'] ?? '')) === '') {
            $rencana[] = ["Mengisi Nama Lengkap akun id {$akun['id']} dengan \"$lengkap\"",
                function () use ($pdo, $akun, $lengkap, $prop): array {
                    $pdo->prepare('UPDATE users SET full_name=?, updated_at=NOW() WHERE id=?')->execute([$lengkap, $akun['id']]);
                    tap_jejak($pdo, $prop, 'update_user_role', 'users', (string) $akun['id'], ['full_name' => $lengkap]);
                    return [];
                }];
        } elseif ($lengkap !== '' && trim((string) $akun['full_name']) !== $lengkap) {
            $catatan[] = "Nama Lengkap akun sudah berisi \"{$akun['full_name']}\", tidak ditimpa dengan \"$lengkap\". "
                       . 'Ubah lewat Users & Role bila memang harus berganti.';
        }
        if ($akun['role'] !== $peran) {
            $catatan[] = "Peran akun sekarang \"{$akun['role']}\", bukan \"$peran\". Peran TIDAK diubah oleh skrip ini — "
                       . 'mengubah peran mengubah pula apa yang boleh dia lihat, jadi dikerjakan sadar di Users & Role.';
        }
        if (mb_strtolower($akun['name']) !== mb_strtolower($nama)) {
            $catatan[] = "Nama akun sekarang \"{$akun['name']}\", bukan \"$nama\". Nama akun TIDAK diubah: ia tercatat sebagai "
                       . 'pembuat (created_by) di dokumen yang sudah ada, dan menggantinya memutus kepemilikannya.';
        }
    }

    if (!$punyaProp) {
        $rencana[] = ["Memberi akses properti {$propRow['name']} ke akunnya",
            function (array $hasil) use ($pdo, $akun, $prop): array {
                $uid = (int) ($hasil['user_id'] ?? $akun['id']);
                $pdo->prepare('INSERT IGNORE INTO user_properties (user_id, property_id) VALUES (?, ?)')->execute([$uid, $prop]);
                return [];
            }];
    }

    if (!$tanpaPic && !$pic) {
        $rencana[] = [
            "Membuat baris Master PIC \"$picNama\" lalu menautkannya ke akunnya",
            function (array $hasil) use ($pdo, $akun, $prop, $picNama, $lengkap, $jabatan, $email, $hp, $komisi, $achievement, $porsi, $warna, $diPenawaran): array {
                $uid = (int) ($hasil['user_id'] ?? $akun['id']);
                $pdo->prepare(
                    'INSERT INTO master_pic
                        (property_id, name, full_name, role_name, email, phone, commission_cat,
                         show_achievement, target_share, color, status, user_id, show_in_offer, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?, ?, NOW())'
                )->execute([$prop, $picNama, $lengkap ?: null, $jabatan ?: null, $email, $hp ?: null,
                            $komisi, $achievement ? 1 : 0, $porsi, $warna, $uid, $diPenawaran ? 1 : 0]);
                $id = (int) $pdo->lastInsertId();
                tap_jejak($pdo, $prop, 'create', 'master_pic', (string) $id,
                    ['name' => $picNama, 'full_name' => $lengkap, 'role_name' => $jabatan,
                     'target_share' => $porsi, 'user_id' => $uid]);
                return ['pic_id' => $id];
            },
        ];
    } elseif (!$tanpaPic && $pic) {
        if (($pic['status'] ?? '') !== 'active') {
            $rencana[] = ["Mengaktifkan kembali baris Master PIC id {$pic['id']} (sekarang \"{$pic['status']}\")",
                function () use ($pdo, $pic, $prop): array {
                    $pdo->prepare("UPDATE master_pic SET status='active', updated_at=NOW() WHERE id=?")->execute([$pic['id']]);
                    tap_jejak($pdo, $prop, 'update', 'master_pic', (string) $pic['id'], ['status' => 'active']);
                    return [];
                }];
        }
        if (!$pic['user_id']) {
            $rencana[] = ["Menautkan baris Master PIC id {$pic['id']} ke akunnya",
                function (array $hasil) use ($pdo, $akun, $pic, $prop): array {
                    $uid = (int) ($hasil['user_id'] ?? $akun['id']);
                    $pdo->prepare('UPDATE master_pic SET user_id=?, updated_at=NOW() WHERE id=?')->execute([$uid, $pic['id']]);
                    tap_jejak($pdo, $prop, 'update', 'master_pic', (string) $pic['id'], ['user_id' => $uid]);
                    return [];
                }];
        } elseif ($akun && (int) $pic['user_id'] !== (int) $akun['id']) {
            fwrite(STDERR, "BERHENTI: baris Master PIC \"$picNama\" (id {$pic['id']}) sudah tertaut ke akun lain "
                         . "(id {$pic['user_id']}). Satu baris PIC hanya boleh milik satu akun — kalau tidak,\n"
                         . "dokumen orang itu akan terlihat oleh dua orang. Pakai --pic=<nama lain> atau lepas dulu tautannya.\n");
            return 2;
        }
        foreach ([['full_name', $lengkap, 'Nama Lengkap'], ['role_name', $jabatan, 'Jabatan'],
                  ['email', $email, 'Email'], ['phone', $hp, 'No. WhatsApp'], ['color', $warna, 'Warna']] as [$kol, $isi, $label]) {
            if ($isi === '' || trim((string) ($pic[$kol] ?? '')) !== '') continue;
            $rencana[] = ["Mengisi $label baris Master PIC id {$pic['id']} dengan \"$isi\"",
                function () use ($pdo, $pic, $kol, $isi): array {
                    $pdo->prepare("UPDATE master_pic SET `$kol`=?, updated_at=NOW() WHERE id=?")->execute([$isi, $pic['id']]);
                    return [];
                }];
        }
        if ($porsi > 0 && abs((float) $pic['target_share'] - $porsi) > 0.00005) {
            $catatan[] = 'Porsi target baris PIC sekarang ' . tap_persen((float) $pic['target_share'])
                       . ', diminta ' . tap_persen($porsi) . '. TIDAK diubah oleh skrip ini — porsi membagi target '
                       . 'bulanan seluruh tim, jadi diubah sadar di Master PIC.';
        }
    }

    if (!$rencana) {
        echo "Tidak ada yang perlu dikerjakan — semuanya sudah sesuai.\n";
        if ($catatan) { echo "\nCATATAN\n"; foreach ($catatan as $c) echo "  • $c\n"; }
        return 0;
    }

    echo "YANG AKAN DIKERJAKAN\n";
    foreach ($rencana as $i => [$apa, $_]) echo '  ' . ($i + 1) . ". $apa\n";
    echo "\n";
    if ($catatan) { echo "CATATAN\n"; foreach ($catatan as $c) echo "  • $c\n"; echo "\n"; }

    // Porsi target membagi target bulanan: target satu PIC = porsi × target
    // properti. Jadi jumlahnya harus 100% — kalau tidak, target terbagi
    // kurang/lebih dan papan Achievement memakai angka yang salah. Diperlihatkan
    // SEBELUM disimpan, supaya tidak ketahuan belakangan dari Cek Data.
    if (!$tanpaPic && $achievement && $porsi > 0) {
        $papan = tap_papan($pdo, $prop);
        $baru  = $pic === null || (float) $pic['target_share'] <= 0;
        if ($baru) $papan[] = ['name' => $picNama, 'target_share' => $porsi, '_baru' => true];
        $jumlah = array_sum(array_map(fn($r) => (float) $r['target_share'], $papan));
        echo "PORSI TARGET PAPAN ACHIEVEMENT " . strtoupper($propRow['name']) . " SETELAH INI\n";
        foreach ($papan as $r) {
            printf("  %-22s %7s%s\n", $r['name'], tap_persen((float) $r['target_share']), !empty($r['_baru']) ? '   ← baru' : '');
        }
        printf("  %-22s %7s   %s\n", 'JUMLAH', tap_persen($jumlah),
            abs($jumlah - 1) < 0.0001 ? 'tepat 100% — benar'
                : 'SEHARUSNYA 100% — target akan terbagi ' . ($jumlah < 1 ? 'kurang' : 'lebih'));
        echo "\n";
    }

    if (!$terapkan) {
        echo "SIMULASI — belum ada satu baris pun yang diubah.\n"
           . "Tambahkan --terapkan untuk benar-benar menyimpan.\n";
        return 1;
    }

    // ── Kerjakan ─────────────────────────────────────────────────────────────
    // Satu transaksi: akun tanpa baris PIC (atau sebaliknya) adalah keadaan
    // separuh jalan yang justru jadi alasan skrip ini ada.
    $pdo->beginTransaction();
    try {
        $hasil = [];
        foreach ($rencana as [$apa, $kerjakan]) {
            $hasil = array_merge($hasil, $kerjakan($hasil) ?: []);
            echo "  dikerjakan: $apa\n";
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "GAGAL — tidak ada yang tersimpan: " . $e->getMessage() . "\n");
        return 1;
    }
    echo "\n";

    // ── Pemeriksaan ulang, dibaca ulang dari database ─────────────────────────
    $uid = (int) ($hasil['user_id'] ?? $akun['id']);
    $st  = $pdo->prepare('SELECT u.id, u.name, u.full_name, u.email, u.role, u.status, u.must_change_password,
                                 (SELECT COUNT(*) FROM user_properties WHERE user_id = u.id AND property_id = ?) AS boleh_prop
                            FROM users u WHERE u.id = ?');
    $st->execute([$prop, $uid]);
    $uBaru = $st->fetch(PDO::FETCH_ASSOC);

    $pBaru = null;
    if (!$tanpaPic) {
        $st = $pdo->prepare('SELECT * FROM master_pic WHERE property_id = ? AND LOWER(name) = LOWER(?)');
        $st->execute([$prop, $picNama]);
        $pBaru = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    echo "PEMERIKSAAN (dibaca ulang dari database)\n";
    $syarat = [
        ['Akun login aktif', ($uBaru['status'] ?? '') === 'active',
            "id {$uBaru['id']} · \"{$uBaru['name']}\" · peran \"{$uBaru['role']}\""],
        ['Punya akses properti ' . $propRow['name'], (int) ($uBaru['boleh_prop'] ?? 0) > 0, ''],
    ];
    if (!$tanpaPic) {
        $syarat[] = ['Baris Master PIC aktif & tertaut ke akunnya',
            $pBaru && $pBaru['status'] === 'active' && (int) $pBaru['user_id'] === $uid,
            $pBaru ? "id {$pBaru['id']} · \"{$pBaru['name']}\"" : 'baris tidak ditemukan'];
        $syarat[] = ['Daftarnya sendiri akan terisi (pembatasan per-sales menemukan nama PIC-nya)',
            $peran !== 'sales' || ($pBaru && $pBaru['status'] === 'active' && (int) $pBaru['user_id'] === $uid),
            $peran === 'sales' ? 'lewat master_pic.user_id — lihat current_sales_scope()' : 'bukan peran sales, tidak dibatasi'];
        $syarat[] = ['Masuk papan Achievement PIC',
            (bool) ($pBaru['show_achievement'] ?? 0) && (float) ($pBaru['target_share'] ?? 0) > 0,
            'porsi ' . tap_persen((float) ($pBaru['target_share'] ?? 0))
                . ((bool) ($pBaru['show_achievement'] ?? 0) ? '' : ' · ditandai disembunyikan')];
        $syarat[] = ['Muncul di pilihan PIC Surat Penawaran', (bool) ($pBaru['show_in_offer'] ?? 0), ''];
    }
    foreach ($syarat as [$label, $ok, $ket]) {
        printf("  [%s] %s%s\n", $ok ? ' OK  ' : 'BELUM', $label, $ket !== '' ? "  —  $ket" : '');
    }

    if (!$tanpaPic) {
        $papan  = tap_papan($pdo, $prop);
        $jumlah = array_sum(array_map(fn($r) => (float) $r['target_share'], $papan));
        printf("  [%s] Jumlah porsi target %s = %s%s\n",
            abs($jumlah - 1) < 0.0001 ? ' OK  ' : 'BELUM', $propRow['name'], tap_persen($jumlah),
            abs($jumlah - 1) < 0.0001 ? '' : ' (seharusnya 100% — Cek Data akan menandainya)');
    }
    echo "\n";

    // ── Teks WA, sama dengan yang muncul di layar sesudah menambah user ──────
    if (isset($hasil['user_id'])) {
        $alamat = rtrim($url ?: (string) (env_value('APP_URL', '') ?: 'https://clara.wbl-bsb.com'), '/') . '/';
        echo "TEKS WA UNTUK {$uBaru['name']} (salin apa adanya)\n"
           . "────────────────────────────────────────────────────────────\n"
           . "Halo *{$uBaru['name']}*,\n\n"
           . "Akun CLARA Anda telah dibuat. Berikut detail untuk login:\n\n"
           . "🔗 *Link:* $alamat\n"
           . "📧 *Email:* {$uBaru['email']}\n"
           . "🔑 *Password:* $sandi\n\n"
           . "Silakan login dan ganti password Anda saat pertama kali masuk.\n\n"
           . "Untuk bantuan, hubungi IT Dept.\n"
           . "────────────────────────────────────────────────────────────\n";
    }

    return 0;
}

exit(tap_main(Database::connect(), $argv));
