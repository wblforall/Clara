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

    public static function bawaan(): array
    {
        return [
            'bentuk'      => 'gambar',
            'gambar_path' => null,
            'teks'        => null,
            'pos_x'       => 150.0,
            'pos_y'       => 232.0,
            'lebar'       => 30.0,
            'tampil_nama' => 1,
        ];
    }

    /** Pengaturan paraf milik satu orang untuk satu jenis dokumen. */
    public static function ambil(PDO $pdo, int $uid, string $docType = 'skp'): ?array
    {
        if ($uid <= 0) return null;
        try {
            $st = $pdo->prepare('SELECT * FROM paraf_settings WHERE user_id = ? AND doc_type = ? LIMIT 1');
            $st->execute([$uid, $docType]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;                        // tabel belum ada (migrasi belum jalan)
        }
        if (!$r) return null;
        $r['pos_x'] = (float) $r['pos_x'];
        $r['pos_y'] = (float) $r['pos_y'];
        $r['lebar'] = (float) $r['lebar'];
        $r['tampil_nama'] = (int) $r['tampil_nama'];
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
        $lebar = $jepit($d['lebar'] ?? 30, 10, 80);
        // Dijepit supaya parafnya tidak bisa disimpan di luar kertas — tanpa ini
        // satu salah ketik membuat paraf hilang dari dokumen tanpa pesan apa pun.
        $x = $jepit($d['pos_x'] ?? 150, 0, self::LEBAR_KERTAS - $lebar);
        $y = $jepit($d['pos_y'] ?? 232, 0, self::TINGGI_KERTAS - 10);

        $pdo->prepare(
            'INSERT INTO paraf_settings (user_id, doc_type, bentuk, gambar_path, teks, pos_x, pos_y, lebar, tampil_nama)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE bentuk=VALUES(bentuk), gambar_path=VALUES(gambar_path), teks=VALUES(teks),
                                     pos_x=VALUES(pos_x), pos_y=VALUES(pos_y), lebar=VALUES(lebar),
                                     tampil_nama=VALUES(tampil_nama)'
        )->execute([
            $uid,
            $docType,
            ($d['bentuk'] ?? 'gambar') === 'inisial' ? 'inisial' : 'gambar',
            ($d['gambar_path'] ?? '') !== '' ? $d['gambar_path'] : null,
            trim((string) ($d['teks'] ?? '')) !== '' ? mb_substr(trim((string) $d['teks']), 0, 40) : null,
            $x, $y, $lebar,
            !empty($d['tampil_nama']) ? 1 : 0,
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
    public static function html(array $p, string $nama, string $jabatan, string $waktu, string $src = 'web'): string
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
            $url = $src === 'file'
                ? dirname(__DIR__) . '/public/' . ltrim($path, '/')
                : (function_exists('upload_url') ? upload_url($path) : $path);
            if ($src === 'file' && !is_file($url)) return '';
            $isi = '<img src="' . $h($url) . '" style="width:' . round($lebar, 1) . 'mm">';
        }

        $ket = '';
        if (!empty($p['tampil_nama'])) {
            $baris = array_filter([$nama, $jabatan]);
            $ket = '<div style="font-family:Helvetica,Arial,sans-serif;font-size:6pt;color:#475569;line-height:1.25;margin-top:0.6mm">'
                 . $h(implode(' · ', $baris))
                 . ($waktu !== '' ? '<br>' . $h($waktu) : '')
                 . '</div>';
        }
        return '<div style="width:' . round($lebar, 1) . 'mm">' . $isi . $ket . '</div>';
    }
}
