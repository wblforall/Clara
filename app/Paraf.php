<?php

declare(strict_types=1);

/**
 * Paraf pemeriksa — bentuk & posisinya disiapkan sekali, dipakai seterusnya.
 *
 * Di kertas, Asst. Manager membubuhkan paraf di tempat yang sama setiap kali,
 * dan penerima dokumen mengenalinya dari situ. Kelas ini memindahkan kebiasaan
 * itu ke aplikasi: pemeriksa mengatur gambar paraf dan letaknya satu kali,
 * lalu tidak memikirkannya lagi — setiap kali ia menekan "Paraf & Teruskan",
 * parafnya terpasang persis seperti yang ia atur.
 *
 * Posisi memakai MILIMETER dari pojok kiri-atas kertas A4 (210 x 297). Satuan
 * itu dipakai apa adanya oleh dua jalur cetak yang berbeda — pratinjau HTML
 * (yang kertasnya memang dilebarkan 210mm) dan mPDF lewat WriteFixedPosHTML —
 * sehingga apa yang terlihat saat mengatur sama dengan yang tercetak.
 *
 * Pengaturan ini TIDAK dibaca ulang saat dokumen dicetak. Pada saat memaraf,
 * nilainya disalin ke snapshot dokumen (lihat ApprovalLine::jejakBeku). Kalau
 * tidak, memindahkan paraf hari ini akan ikut menggeser paraf di seluruh
 * dokumen yang sudah terlanjur diserahkan ke client.
 */
final class Paraf
{
    public const JENIS = ['skp' => 'SKP Pameran', 'sks' => 'SKS Gudang', 'fu' => 'Form Utilities'];

    /** Batas kertas A4 dalam milimeter — dipakai untuk menjepit nilai posisi. */
    public const LEBAR_KERTAS = 210.0;
    public const TINGGI_KERTAS = 297.0;

    /** Jenis dokumen + baris bersama yang berlaku untuk semuanya. */
    public const SEMUA = 'all';

    /**
     * Mode OTOMATIS: paraf dicetak pada BARIS PEMERIKSAAN di bawah blok tanda
     * tangan — "Diperiksa sebelum disetujui" — bersebelahan dengan nama
     * pemeriksanya, bukan di dalam salah satu kolom tanda tangan.
     *
     * Dulu parafnya ditempel menindih ruang kosong di kanan QR Manager. Di
     * kertas itu memang kebiasaannya, tetapi di surat ini kolom "Dibuat Oleh"
     * dan "Mengetahui" sama sekali tidak berisi tanda tangan — keduanya
     * divalidasi lewat QR. Coretan yang menempel di sebelah QR Manager jadi
     * terbaca sebagai TANDA TANGAN Manager, padahal satu-satunya tanda tangan
     * di surat ini milik client di kolom "Menyetujui". Dipindah ke barisnya
     * sendiri, paraf tetap tercetak dan tidak bisa salah dibaca lagi.
     */

    /**
     * Batas mutlak tinggi paraf dalam mode otomatis.
     *
     * Paraf yang bentuknya tinggi-ramping (huruf "Y", misalnya) kalau diatur
     * lewat LEBAR akan menjulur ke bawah sepanjang QR — bukan tanda kecil di
     * pojok lagi, melainkan blok di sampingnya. Karena itu di mode otomatis
     * angka pengaturannya dibaca sebagai SISI TERPANJANG, dan apa pun bentuk
     * coretannya tetap tidak boleh lebih tinggi dari ini. Selain soal tampilan,
     * sel penindih yang kelewat tinggi akan mendorong QR-nya turun.
     */
    public const OTO_TINGGI_GAMBAR = 12.0;

    /**
     * Lebar maksimum paraf dalam mode otomatis.
     *
     * Angkanya tidak diubah ketika paraf pindah ke baris pemeriksaan: dokumen
     * yang sudah terbit memakai batas ini, dan membesarkannya sekarang akan
     * ikut membesarkan paraf di surat yang sudah diserahkan ke client.
     */
    public const LEBAR_OTOMATIS_MAKS = 17.0;

    public static function bawaan(): array
    {
        return [
            'mode'         => 'otomatis',
            'bentuk'       => 'gambar',
            'gambar_path'  => null,
            'teks'         => null,
            'pos_x'        => 150.0,
            'pos_y'        => 232.0,
            // Kecil secara bawaan: paraf di kertas memang kecil, dan yang
            // membesarkan selalu bisa menggeser sendiri. Kebalikannya tidak —
            // paraf kebesaran baru ketahuan setelah suratnya terbit.
            'lebar'        => 12.0,
            // Tanpa nama secara bawaan. Paraf di kertas memang cuma coretan,
            // dan baris pemeriksaan sudah menyebut nama, jabatan, serta jamnya
            // tepat di sebelahnya — keterangan yang menjuntai di bawah coretan
            // hanya mengulang hal yang sama dua kali.
            'tampil_nama'  => 0,
            'tampil_waktu' => 0,
        ];
    }

    /**
     * Pengaturan paraf milik satu orang untuk satu jenis dokumen.
     *
     * Baris khusus jenis dokumen didahulukan; kalau tidak ada, dipakai baris
     * bersama ('all') yang berlaku untuk semua surat. Begitulah mode otomatis
     * bisa diatur sekali dan langsung berlaku di SKP, SKS, maupun Form
     * Utilities tanpa disetel satu per satu.
     */
    public static function ambil(PDO $pdo, int $uid, string $docType = 'skp'): ?array
    {
        if ($uid <= 0) return null;
        try {
            $st = $pdo->prepare('SELECT * FROM paraf_settings
                                  WHERE user_id = ? AND doc_type IN (?, ?)
                                  ORDER BY (doc_type = ?) DESC LIMIT 1');
            $st->execute([$uid, $docType, self::SEMUA, $docType]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;                        // tabel belum ada (migrasi belum jalan)
        }
        if (!$r) return null;
        $r['pos_x'] = (float) $r['pos_x'];
        $r['pos_y'] = (float) $r['pos_y'];
        $r['lebar'] = (float) $r['lebar'];
        $r['tampil_nama']  = (int) $r['tampil_nama'];
        $r['tampil_waktu'] = (int) ($r['tampil_waktu'] ?? 0);
        $r['mode'] = ($r['mode'] ?? 'otomatis') === 'manual' ? 'manual' : 'otomatis';
        return $r;
    }

    /** Baris persis untuk satu jenis dokumen (tanpa jatuh ke baris bersama). */
    public static function ambilPersis(PDO $pdo, int $uid, string $docType): ?array
    {
        if ($uid <= 0) return null;
        try {
            $st = $pdo->prepare('SELECT * FROM paraf_settings WHERE user_id = ? AND doc_type = ? LIMIT 1');
            $st->execute([$uid, $docType]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }
        if (!$r) return null;
        $r['pos_x'] = (float) $r['pos_x'];
        $r['pos_y'] = (float) $r['pos_y'];
        $r['lebar'] = (float) $r['lebar'];
        $r['tampil_nama']  = (int) $r['tampil_nama'];
        $r['tampil_waktu'] = (int) ($r['tampil_waktu'] ?? 0);
        $r['mode'] = ($r['mode'] ?? 'otomatis') === 'manual' ? 'manual' : 'otomatis';
        return $r;
    }

    /** Semua pengaturan milik satu orang, dikunci jenis dokumen. */
    public static function semua(PDO $pdo, int $uid): array
    {
        $out = [];
        foreach (array_keys(self::JENIS) as $dt) {
            $out[$dt] = self::ambil($pdo, $uid, $dt);
        }
        return $out;
    }

    /**
     * Sudah siap dipakai? Paraf bergambar tanpa gambarnya tidak akan tercetak
     * apa-apa, dan itu lebih buruk daripada tidak diatur sama sekali — karena
     * pemeriksanya mengira sudah beres.
     */
    public static function siap(?array $p): bool
    {
        if (!$p) return false;
        if (($p['bentuk'] ?? '') === 'inisial') return trim((string) ($p['teks'] ?? '')) !== '';
        return trim((string) ($p['gambar_path'] ?? '')) !== '';
    }

    public static function simpan(PDO $pdo, int $uid, string $docType, array $d): void
    {
        $jepit = fn($v, $min, $max) => max($min, min($max, (float) $v));
        $mode  = ($d['mode'] ?? 'otomatis') === 'manual' ? 'manual' : 'otomatis';
        // Mode otomatis menumpang di sel yang sama dengan QR Manager, dan sel
        // itu hanya selebar sepertiga halaman. Paraf yang lebih lebar dari ini
        // akan mendorong QR-nya turun dan merusak blok tanda tangan.
        $lebar = $mode === 'otomatis'
            ? $jepit($d['lebar'] ?? 12, 10, self::LEBAR_OTOMATIS_MAKS)
            : $jepit($d['lebar'] ?? 30, 10, 80);
        // Dijepit supaya parafnya tidak bisa disimpan di luar kertas — tanpa ini
        // satu salah ketik membuat paraf hilang dari dokumen tanpa pesan apa pun.
        $x = $jepit($d['pos_x'] ?? 150, 0, self::LEBAR_KERTAS - $lebar);
        $y = $jepit($d['pos_y'] ?? 232, 0, self::TINGGI_KERTAS - 10);

        $pdo->prepare(
            'INSERT INTO paraf_settings (user_id, doc_type, mode, bentuk, gambar_path, teks, pos_x, pos_y, lebar, tampil_nama, tampil_waktu)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE mode=VALUES(mode), bentuk=VALUES(bentuk), gambar_path=VALUES(gambar_path),
                                     teks=VALUES(teks), pos_x=VALUES(pos_x), pos_y=VALUES(pos_y),
                                     lebar=VALUES(lebar), tampil_nama=VALUES(tampil_nama),
                                     tampil_waktu=VALUES(tampil_waktu)'
        )->execute([
            $uid,
            $docType,
            $mode,
            ($d['bentuk'] ?? 'gambar') === 'inisial' ? 'inisial' : 'gambar',
            ($d['gambar_path'] ?? '') !== '' ? $d['gambar_path'] : null,
            trim((string) ($d['teks'] ?? '')) !== '' ? mb_substr(trim((string) $d['teks']), 0, 40) : null,
            $x, $y, $lebar,
            !empty($d['tampil_nama']) ? 1 : 0,
            !empty($d['tampil_waktu']) ? 1 : 0,
        ]);
    }

    public static function reset(PDO $pdo, int $uid, string $docType): void
    {
        $pdo->prepare('DELETE FROM paraf_settings WHERE user_id = ? AND doc_type = ?')->execute([$uid, $docType]);
    }

    /**
     * Potongan HTML parafnya saja (tanpa penempatan) — dipakai oleh pratinjau
     * pengaturan, oleh dokumen HTML, dan oleh mPDF.
     *
     * $src: cara menunjuk gambar. 'web' untuk di layar, 'file' untuk mPDF yang
     * membaca berkas langsung dari disk.
     */
    public static function html(array $p, string $nama, string $jabatan, string $waktu, string $src = 'web',
                                float $maksTinggiMm = 0.0, float $lebarBlokMm = 0.0): string
    {
        $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $lebar = (float) ($p['lebar'] ?? 30);
        $isi = '';

        if (($p['bentuk'] ?? 'gambar') === 'inisial') {
            $teks = trim((string) ($p['teks'] ?? ''));
            if ($teks === '') return '';
            // Tinggi huruf diikutkan lebar kotak supaya paraf inisial ikut
            // membesar/mengecil saat lebarnya digeser, seperti paraf bergambar.
            $ukuran = max(9.0, $lebar * 0.42);
            $isi = '<div style="font-family:Helvetica,Arial,sans-serif;font-style:italic;font-weight:700;'
                 . 'font-size:' . round($ukuran, 1) . 'pt;color:#1e3a8a;line-height:1.1">' . $h($teks) . '</div>';
        } else {
            $path = trim((string) ($p['gambar_path'] ?? ''));
            if ($path === '') return '';
            $berkas = dirname(__DIR__) . '/public/' . ltrim($path, '/');
            // Berkasnya harus benar-benar ada. Kalau tidak, barisnya lebih baik
            // tampil sebagai teks saja daripada memuat gambar rusak di tengah
            // kalimat — dan PDF memang sudah berperilaku begitu.
            if (!is_file($berkas)) return '';
            $url = $src === 'file' ? $berkas : self::tanamGambar($berkas, $path);
            if ($url === '') return '';
            // Paraf yang tinggi-ramping dikecilkan sampai tingginya muat, supaya
            // tidak menjulur melewati barisnya (lihat OTO_TINGGI_GAMBAR).
            $lebarGbr = $lebar;
            if ($maksTinggiMm > 0 && is_file($berkas)) {
                $dim = @getimagesize($berkas);
                if ($dim && (int) $dim[0] > 0 && (int) $dim[1] > 0) {
                    $tinggi = $lebar * (int) $dim[1] / (int) $dim[0];
                    if ($tinggi > $maksTinggiMm) $lebarGbr = $lebar * $maksTinggiMm / $tinggi;
                }
            }
            $isi = '<img src="' . $h($url) . '" style="width:' . round($lebarGbr, 1) . 'mm">';
            // Kotak pembungkus ikut menyusut bersama gambarnya. Kalau tetap
            // selebar setelan, coretan yang ramping menyisakan ruang kosong di
            // kanannya — di baris pemeriksaan ruang itu terbaca sebagai jarak
            // yang menganga antara paraf dan nama pemiliknya.
            $lebarIsi = $lebarGbr;
        }

        // Nama dan waktu dua hal terpisah: paraf di kertas memang tidak pernah
        // mencantumkan jam, jadi keduanya bisa dimatikan sendiri-sendiri.
        $baris = [];
        if (!empty($p['tampil_nama'])) {
            $baris[] = $h(implode(' · ', array_filter([$nama, $jabatan])));
        }
        if (!empty($p['tampil_waktu']) && $waktu !== '') {
            $baris[] = $h($waktu);
        }
        $ket = $baris
            ? '<div style="font-family:Helvetica,Arial,sans-serif;font-size:6pt;color:#475569;line-height:1.25;margin-top:0.6mm">'
              . implode('<br>', $baris) . '</div>'
            : '';
        // Kotaknya boleh lebih lebar dari gambarnya: gambar tetap sekecil setelan,
        // tetapi keterangannya punya ruang supaya tidak membungkus jadi tumpukan
        // baris yang membuat paraf terlihat besar.
        $lebarBlok = $lebarBlokMm > 0 ? max($lebarBlokMm, $lebar) : ($lebarIsi ?? $lebar);
        return '<div style="width:' . round($lebarBlok, 1) . 'mm">' . $isi . $ket . '</div>';
    }

    /**
     * Berapa besar paraf masih pantas ditanam langsung ke dalam halaman.
     * Paraf sungguhan cuma coretan — yang digambar di kanvas sekitar 2 KB,
     * yang diunggah jarang lebih dari seratus KB. Batas unggah 2 MB ada untuk
     * menjaring berkas rusak, bukan karena paraf sebesar itu masuk akal.
     */
    private const TANAM_MAKS = 512 * 1024;

    /**
     * Sumber gambar untuk tampilan di LAYAR.
     *
     * Gambar paraf disajikan lewat ?r=file, dan rute itu menolak permintaan
     * tanpa sesi. Halaman tanda tangan client dibuka TANPA login, jadi di sana
     * parafnya balas 403 dan muncul sebagai gambar rusak — tepat di baris yang
     * menerangkan siapa memeriksa surat itu. Karena itu gambarnya ditanam
     * langsung ke halaman sebagai data URI: tidak ada permintaan kedua, tidak
     * ada izin yang perlu dilewati, dan hasilnya sama persis dengan PDF-nya.
     *
     * Berkas yang kelewat besar tetap ditunjuk lewat ?r=file — menanam berkas
     * sebesar itu membuat halamannya berat, dan ukuran sebesar itu sendiri
     * sudah menandakan parafnya perlu diunggah ulang.
     */
    private static function tanamGambar(string $berkas, string $path): string
    {
        $ukuran = @filesize($berkas);
        if ($ukuran === false || $ukuran > self::TANAM_MAKS) {
            return function_exists('upload_url') ? upload_url($path) : $path;
        }
        $isi = @file_get_contents($berkas);
        if ($isi === false || $isi === '') return '';
        $tipe = [
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
        ][strtolower(pathinfo($berkas, PATHINFO_EXTENSION))] ?? 'image/png';
        return 'data:' . $tipe . ';base64,' . base64_encode($isi);
    }

    /**
     * Batas tinggi untuk mode otomatis: angka yang diatur orangnya dibaca
     * sebagai sisi terpanjang, lalu dipotong batas mutlaknya. Dipakai bersama
     * oleh dokumen dan tiruan di halaman pengaturan supaya keduanya sama.
     */
    public static function maksTinggiOtomatis(float $ukuranMm): float
    {
        return min($ukuranMm, self::OTO_TINGGI_GAMBAR);
    }
}
