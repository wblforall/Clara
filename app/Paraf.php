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
     * Geometri paraf mode OTOMATIS — "menindih" blok tanda tangan seperti gambar
     * di-depan-teks pada Word: QR dan nama di bawahnya tidak bergeser sama sekali.
     *
     * Caranya: sebuah tabel setinggi OTO_TARIK ditarik kembali ke atas sejauh
     * tinggi itu juga, sehingga tinggi bersih yang ia tambahkan ke aliran
     * dokumen = NOL. Ia lalu tergambar menumpang di ruang kosong sebelah kanan
     * QR. mPDF dan browser sama-sama menghormati margin atas negatif pada
     * tabel, jadi pratinjau HTML dan PDF memberi hasil yang sama — berbeda
     * dengan position:absolute yang tidak didukung mPDF di aliran normal.
     *
     * Angkanya berasal dari lebar kolom "Mengetahui" pada blok tanda tangan:
     * lebar isi kolom ±56mm, QR 18mm di tengahnya, jadi tepi kanan QR ada di
     * ±37mm dan paraf mulai 1mm setelahnya. OTO_GESER + OTO_LEBAR harus tetap
     * di bawah 56mm, kalau lebih tabelnya diciutkan dan parafnya ikut meleset.
     */
    public const OTO_GESER  = 38.0;   // jarak dari tepi kiri kolom ke awal paraf
    public const OTO_LEBAR  = 17.0;   // ruang tersisa untuk paraf sampai tepi kolom
    public const OTO_TARIK  = 21.0;   // tinggi tarikan — puncak paraf jadi sejajar puncak QR

    /**
     * Kelebihan tinggi tetap yang selalu ditambahkan mPDF pada sebuah tabel.
     * Diukur langsung (0,51pt) dan terbukti KONSTAN pada tinggi sel 10, 20,
     * maupun 30mm, jadi cukup dikurangkan sekali supaya tinggi bersihnya
     * benar-benar nol dan nama di bawah QR tidak turun 0,2mm.
     */
    public const OTO_SISA = 0.18;

    /**
     * Batas tinggi gambar paraf dalam mode otomatis. Tanpa ini, paraf yang
     * bentuknya tinggi-ramping bisa membuat sel penindih ikut memanjang —
     * dan begitu selnya lebih tinggi dari tarikannya, QR pun ikut terdorong.
     */
    public const OTO_TINGGI_GAMBAR = 11.0;

    /** Lebar maksimum paraf dalam mode otomatis — ruang di samping QR terbatas. */
    public const LEBAR_OTOMATIS_MAKS = self::OTO_LEBAR;

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
            'tampil_nama'  => 1,
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
                                float $maksTinggiMm = 0.0): string
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
            $url = $src === 'file'
                ? $berkas
                : (function_exists('upload_url') ? upload_url($path) : $path);
            if ($src === 'file' && !is_file($url)) return '';
            // Paraf yang tinggi-ramping dikecilkan sampai tingginya muat, supaya
            // tidak memanjangkan kotak penindih (lihat OTO_TINGGI_GAMBAR).
            $lebarGbr = $lebar;
            if ($maksTinggiMm > 0 && is_file($berkas)) {
                $dim = @getimagesize($berkas);
                if ($dim && (int) $dim[0] > 0 && (int) $dim[1] > 0) {
                    $tinggi = $lebar * (int) $dim[1] / (int) $dim[0];
                    if ($tinggi > $maksTinggiMm) $lebarGbr = $lebar * $maksTinggiMm / $tinggi;
                }
            }
            $isi = '<img src="' . $h($url) . '" style="width:' . round($lebarGbr, 1) . 'mm">';
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
        return '<div style="width:' . round($lebar, 1) . 'mm">' . $isi . $ket . '</div>';
    }

    /**
     * Bungkus paraf mode otomatis menjadi lapisan yang MENINDIH blok tanda
     * tangan: tampil di kanan atas QR tanpa menggeser QR maupun nama di
     * bawahnya. Lihat OTO_* untuk cara kerja dan asal angkanya.
     *
     * Dipasang SESUDAH .sigarea di kolom "Mengetahui" — urutan itu penting,
     * karena tarikannya dihitung mundur dari dasar area tanda tangan.
     */
    public static function overlay(string $isi): string
    {
        if ($isi === '') return '';
        return '<table style="width:' . (self::OTO_GESER + self::OTO_LEBAR) . 'mm;border-collapse:collapse;'
             . 'margin:-' . (self::OTO_TARIK + self::OTO_SISA) . 'mm 0 0 0"><tr>'
             . '<td style="width:' . self::OTO_GESER . 'mm;padding:0;font-size:1pt">&nbsp;</td>'
             . '<td style="width:' . self::OTO_LEBAR . 'mm;height:' . self::OTO_TARIK . 'mm;padding:0;'
             . 'vertical-align:top;text-align:left">' . $isi . '</td>'
             . '</tr></table>';
    }
}
