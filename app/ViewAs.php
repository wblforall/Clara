<?php

declare(strict_types=1);

/**
 * "Lihat sebagai" — Super Admin meminjam SUDUT PANDANG user lain, tanpa
 * passwordnya dan tanpa bisa mengubah apa pun.
 *
 * Latar belakangnya: hak akses di CLARA ditentukan tiga hal yang terpisah —
 * role akun (izin approve_skp), akses properti, dan jabatan di Master PIC.
 * Ketiganya harus benar bersamaan sebelum tombol paraf muncul. Selama ini
 * satu-satunya cara memastikannya adalah meminjam akun orangnya, yang berarti
 * mereset passwordnya dan mengunci dia dari sistem. Itu harga yang terlalu
 * mahal hanya untuk memverifikasi sebuah tombol.
 *
 * MODE INI SENGAJA TIDAK BISA BERTINDAK. Dokumen yang dihasilkan CLARA
 * diserahkan ke client dan parafnya menyatakan siapa yang memeriksa; paraf yang
 * ditekan orang lain "sekadar untuk menguji" merusak arti dokumen itu. Jadi
 * yang bisa dilihat adalah semuanya, yang bisa diubah tidak ada.
 *
 * Penjagaannya berlapis dua:
 *   1. Di routing — semua POST dan rute yang mengubah data ditolak lebih dulu
 *      dengan pesan yang jelas (lihat public/index.php).
 *   2. Di koneksi database — PDO diganti mode baca-saja, sehingga perintah
 *      tulis yang lolos dari lapis pertama tetap gagal (lihat Database.php).
 * Lapis kedua ada karena lapis pertama bergantung pada daftar nama rute, dan
 * daftar semacam itu selalu ketinggalan saat ada rute baru.
 */
final class ViewAs
{
    /** Sedang meminjam sudut pandang orang lain? */
    public static function aktif(): bool
    {
        return !empty($_SESSION['view_as']['oleh']['id']);
    }

    /** Akun asli yang sedang meminjam (Super Admin-nya). */
    public static function asli(): ?array
    {
        return $_SESSION['view_as']['oleh'] ?? null;
    }

    /** Nama orang yang sedang dilihat sudut pandangnya. */
    public static function nama(): string
    {
        return (string) ($_SESSION['user']['name'] ?? '');
    }

    /**
     * Mulai melihat sebagai user lain.
     *
     * @return string '' bila berhasil, selain itu alasan penolakan.
     */
    public static function mulai(PDO $pdo, int $uid): string
    {
        if (self::aktif()) return 'Sedang dalam mode lihat-sebagai. Keluar dulu.';

        // Hanya Super Admin. Admin pun tidak — meminjam sudut pandang orang lain
        // adalah kewenangan pemilik sistem, bukan operasional sehari-hari.
        if (($_SESSION['user']['role'] ?? '') !== 'superadmin') {
            return 'Hanya Super Admin yang boleh memakai mode ini.';
        }
        if ($uid === (int) ($_SESSION['user']['id'] ?? 0)) {
            return 'Itu akun Anda sendiri.';
        }

        $st = $pdo->prepare('SELECT id, name, email, role, status FROM users WHERE id = ?');
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$u)                                 return 'User tidak ditemukan.';
        if (($u['status'] ?? '') !== 'active')   return 'User ' . $u['name'] . ' berstatus nonaktif.';
        // Tidak boleh meminjam sudut pandang sesama Super Admin/Admin: tidak ada
        // gunanya untuk pengujian hak akses, dan membuka jalan saling meminjam
        // yang mengaburkan siapa melakukan apa.
        if (in_array($u['role'], ['superadmin', 'admin'], true)) {
            return 'Tidak bisa melihat sebagai Super Admin / Admin.';
        }

        // Properti yang boleh dibuka orang ini — persis seperti saat dia login
        // sendiri. Inilah yang membuat kendala "akunnya belum diberi akses
        // properti ini" terlihat apa adanya, bukan tersamar oleh akses Anda.
        $ps = $pdo->prepare('SELECT p.id, p.key, p.name FROM properties p
                              JOIN user_properties up ON up.property_id = p.id
                             WHERE up.user_id = ? AND p.status = ? ORDER BY p.id');
        $ps->execute([$uid, 'active']);
        $prop = $ps->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $_SESSION['view_as'] = [
            'oleh'      => $_SESSION['user'],
            'oleh_prop' => $_SESSION['allowed_properties'] ?? [],
            'oleh_pid'  => (int) ($_SESSION['current_property_id'] ?? 1),
            'mulai'     => time(),
        ];
        $_SESSION['user'] = [
            'id'    => (int) $u['id'],
            'name'  => $u['name'],
            'email' => $u['email'],
            'role'  => $u['role'],
        ];
        $_SESSION['allowed_properties'] = $prop;
        if ($prop) {
            $_SESSION['current_property_id'] = (int) $prop[0]['id'];
        }
        // Matriks izin di-cache per sesi — paksa muat ulang, kalau tidak yang
        // dipakai masih izin Super Admin dan seluruh pengujian jadi tidak sah.
        unset($_SESSION['_perm_matrix'], $_SESSION['_perm_cache_at'], $_SESSION['_show_welcome']);
        // Orang yang passwordnya masih default TIDAK dilempar ke layar ganti
        // password — kita cuma menumpang melihat, bukan masuk sebagai dia.
        unset($_SESSION['_must_change_pw']);

        return '';
    }

    /** Kembali ke akun asli. */
    public static function selesai(): void
    {
        if (!self::aktif()) return;
        $simpan = $_SESSION['view_as'];
        $_SESSION['user']               = $simpan['oleh'];
        $_SESSION['allowed_properties'] = $simpan['oleh_prop'];
        $_SESSION['current_property_id'] = $simpan['oleh_pid'];
        unset($_SESSION['view_as'], $_SESSION['_perm_matrix'], $_SESSION['_perm_cache_at']);
    }

    /**
     * Rute yang mengubah data — ditolak selagi mode ini aktif.
     *
     * Dicocokkan per potongan nama, bukan sekadar "mengandung", supaya halaman
     * baca seperti contract_request tidak ikut terjaring.
     */
    public static function rutePengubah(string $route): bool
    {
        foreach ([
            '_save', '_delete', '_update', '_cancel', '_approve', '_reject',
            '_restore', '_override', '_decide', '_submit', '_convert',
            '_execute', '_upload', '_replace', '_close', '_status', '_rule',
            '_sort', '_merge', 'generate_', 'import_',
        ] as $p) {
            if (str_contains($route, $p)) return true;
        }
        return false;
    }

    /** Palang peringatan yang selalu terlihat di atas halaman. */
    public static function banner(): void
    {
        if (!self::aktif()) return;
        $asli = self::asli();
        ?>
        <div style="position:sticky;top:0;z-index:9999;background:#7c2d12;color:#fff;padding:9px 14px;
                    display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:13px;
                    box-shadow:0 2px 6px rgba(0,0,0,.25)">
            <span style="font-size:15px">👁</span>
            <span>Anda sedang <strong>melihat sebagai <?= h(self::nama()) ?></strong>
                  (<?= h((string) ($_SESSION['user']['role'] ?? '-')) ?>) &mdash;
                  tampilan, menu, dan tombol di bawah ini adalah miliknya.</span>
            <span style="background:rgba(255,255,255,.18);padding:2px 8px;border-radius:4px">Tindakan dinonaktifkan</span>
            <a href="?r=view_as_exit" style="margin-left:auto;background:#fff;color:#7c2d12;font-weight:700;
                      padding:5px 12px;border-radius:5px;text-decoration:none">Keluar &amp; kembali ke <?= h((string) ($asli['name'] ?? 'akun saya')) ?></a>
        </div>
        <?php
    }

    /** Halaman penolakan saat ada yang mencoba mengubah data. */
    public static function tolak(string $apa = ''): void
    {
        http_response_code(403);
        $asli = self::asli();
        ?>
        <!doctype html>
        <html lang="id"><head><meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Tindakan dinonaktifkan — CLARA</title></head>
        <body style="font-family:system-ui,-apple-system,'Segoe UI',sans-serif;background:#f8fafc;margin:0;padding:40px 16px">
        <div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:26px">
            <div style="font-size:30px">🔒</div>
            <h1 style="font-size:19px;margin:10px 0 6px">Tindakan dinonaktifkan</h1>
            <p style="color:#475569;font-size:14px;line-height:1.65;margin:0 0 14px">
                Anda sedang <strong>melihat sebagai <?= h(self::nama()) ?></strong>. Mode ini hanya untuk
                memastikan apa yang dia lihat &mdash; menu, tombol, dan antrean dokumennya &mdash;
                sehingga tidak ada yang bisa disimpan, diparaf, disetujui, atau dihapus atas namanya.
                <?= $apa !== '' ? '<br><span style="color:#94a3b8;font-size:12.5px">Yang dicoba: ' . h($apa) . '</span>' : '' ?>
            </p>
            <p style="color:#475569;font-size:14px;line-height:1.65;margin:0 0 18px">
                Untuk benar-benar menguji proses parafnya sampai tuntas, keluar dari mode ini lalu
                pakai akun Anda sendiri atau akun uji tersendiri.
            </p>
            <a href="?r=view_as_exit" style="display:inline-block;background:#0d9488;color:#fff;font-weight:700;
                  padding:9px 16px;border-radius:6px;text-decoration:none;font-size:14px">
               Keluar &amp; kembali ke <?= h((string) ($asli['name'] ?? 'akun saya')) ?></a>
            <a href="javascript:history.back()" style="display:inline-block;margin-left:8px;color:#475569;
                  padding:9px 10px;text-decoration:none;font-size:14px">Kembali</a>
        </div>
        </body></html>
        <?php
        exit;
    }
}

/** Dilempar oleh koneksi baca-saja saat ada perintah tulis yang lolos. */
final class ViewAsTulisDitolak extends RuntimeException
{
}
