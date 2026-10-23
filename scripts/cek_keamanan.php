<?php
/**
 * Periksa tanda-tanda penyusupan, dan apakah lapisan pertahanan benar-benar
 * aktif DI SERVER — bukan hanya ada di kode.
 *
 * Dibuat setelah pertanyaan "PHP jebol oleh RCE, aman tidak di sini?". Jawaban
 * yang jujur atas pertanyaan itu tidak bisa datang dari membaca kode saja:
 * aturan .htaccess bisa diabaikan server, berkas asing bisa sudah terlanjur
 * ada, dan pengaturan PHP di hosting berbeda dari di laptop.
 *
 * MURNI MELIHAT. Tidak ada satu pun berkas atau baris basis data yang diubah.
 *
 * Pakai — di server, dari folder aplikasi:
 *   php scripts/cek_keamanan.php
 *   php scripts/cek_keamanan.php --hari=30     (jendela berkas baru, bawaan 14)
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';

$opt  = array_merge(['hari' => '14'], getopt('', ['hari:']));
$hari = max(1, (int) $opt['hari']);

$temuan = [];   // [berat, judul, keterangan]
$catat  = function (string $berat, string $judul, string $ket) use (&$temuan): void {
    $temuan[] = [$berat, $judul, $ket];
};

echo "\nPEMERIKSAAN KEAMANAN CLARA\n";
echo str_repeat('=', 86) . "\n";
printf("  Folder   : %s\n", $root);
printf("  PHP      : %s (%s)\n", PHP_VERSION, PHP_SAPI);
printf("  Waktu    : %s\n\n", date('d/m/Y H:i'));

// ─── 1. Berkas yang bisa DIJALANKAN di tempat yang seharusnya hanya data ────
//
// Inilah bentuk RCE yang paling sering terjadi pada aplikasi PHP: satu berkas
// .php berhasil mendarat di folder unggahan, lalu dipanggil lewat alamatnya.
$unggah = $root . '/public/uploads';
$bahaya = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phar',
           'cgi', 'pl', 'py', 'sh', 'htaccess'];
$asing = [];
if (is_dir($unggah)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($unggah, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $nama = $f->getFilename();
        $ext  = strtolower(pathinfo($nama, PATHINFO_EXTENSION));
        // .htaccess milik kita sendiri memang harus ada — itu justru pelindungnya.
        if ($nama === '.htaccess') continue;
        if (in_array($ext, $bahaya, true)) $asing[] = $f->getPathname();
    }
}
printf("  1. Berkas bisa-dijalankan di folder unggahan : %s\n",
    $asing ? count($asing) . ' DITEMUKAN' : 'tidak ada');
foreach ($asing as $a) echo "       -> " . str_replace($root . '/', '', $a) . "\n";
if ($asing) {
    $catat('GAWAT', 'Ada berkas skrip di folder unggahan',
        "Periksa isinya sebelum dihapus, dan simpan salinannya: itu bukti.\n"
      . "      Satu berkas seperti ini sudah cukup untuk menjalankan perintah di server.");
}

// ─── 2. Isi berkas unggahan benar-benar gambar/PDF? ─────────────────────────
//
// Nama berkas dibuat ulang oleh aplikasi, jadi muatan tidak bisa bernama .php.
// Yang diperiksa di sini: ada tidaknya berkas yang NAMANYA .jpg tetapi isinya
// bukan gambar — titipan muatan yang menunggu celah lain untuk dijalankan.
$palsu = 0; $dicek = 0;
if (is_dir($unggah)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($unggah, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $ext = strtolower(pathinfo($f->getFilename(), PATHINFO_EXTENSION));
        if (!array_key_exists($ext, CLARA_JENIS_UNGGAH)) continue;
        $dicek++;
        if (!clara_isi_berkas_sah($f->getPathname(), $ext)) {
            $palsu++;
            if ($palsu <= 10) echo "       -> isi tidak cocok: " . str_replace($root . '/', '', $f->getPathname()) . "\n";
        }
    }
}
printf("  2. Isi berkas unggahan cocok dengan namanya  : %d dari %d berkas TIDAK cocok\n", $palsu, $dicek);
if ($palsu) {
    $catat('PERIKSA', 'Ada unggahan yang isinya tidak sesuai namanya',
        "Bisa berkas rusak biasa, bisa juga muatan yang dititipkan sebelum\n"
      . "      pemeriksaan isi dipasang. Buka satu per satu sebelum menyimpulkan.");
}

// ─── 3. Pelindung folder unggahan masih di tempatnya? ───────────────────────
$ht = $unggah . '/.htaccess';
$htAda = is_file($ht);
$htIsi = $htAda ? (string) file_get_contents($ht) : '';
$htSah = $htAda && stripos($htIsi, 'php') !== false && stripos($htIsi, 'denied') !== false;
printf("  3. .htaccess pelindung folder unggahan       : %s\n",
    $htSah ? 'ada & melarang skrip' : ($htAda ? 'ADA TAPI ISINYA BERUBAH' : 'HILANG'));
if (!$htSah) {
    $catat('GAWAT', 'Pelindung folder unggahan hilang atau diubah',
        "Berkas ini yang mencegah skrip dijalankan dari folder unggahan.\n"
      . "      Pulihkan dengan: git checkout -- public/uploads/.htaccess");
}

// ─── 4. Berkas yang baru berubah ────────────────────────────────────────────
//
// Penyusup meninggalkan jejak waktu. Berkas aplikasi hanya berubah saat git
// pull; apa pun di luar itu layak dilihat.
$batas = time() - $hari * 86400;
$baru  = [];
$lewati = ['/vendor/', '/.git/', '/node_modules/', '/public/uploads/', '/storage/', '/tmp/'];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
    $p = str_replace('\\', '/', $f->getPathname());
    foreach ($lewati as $l) if (strpos($p, $l) !== false) continue 2;
    if ($f->getMTime() >= $batas) $baru[] = [$f->getMTime(), str_replace($root . '/', '', $p)];
}
usort($baru, fn($a, $b) => $b[0] <=> $a[0]);
printf("  4. Berkas PHP berubah %d hari terakhir       : %d\n", $hari, count($baru));
foreach (array_slice($baru, 0, 15) as [$t, $p]) printf("       %s  %s\n", date('d/m H:i', $t), $p);
if (count($baru) > 15) printf("       ... dan %d lagi\n", count($baru) - 15);
echo "       (wajar sesudah git pull — yang perlu dicurigai yang TIDAK Anda kenali)\n";

// ─── 5. Tanda khas webshell di berkas PHP aplikasi ──────────────────────────
$jejak = ['eval(', 'assert(', 'base64_decode(', 'shell_exec(', 'passthru(',
          'proc_open(', 'popen(', '$_REQUEST[', 'gzinflate(', 'str_rot13(',
          'preg_replace("/.*/e', "create_function("];
$kena = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
    $p = str_replace('\\', '/', $f->getPathname());
    foreach ($lewati as $l) if (strpos($p, $l) !== false) continue 2;
    if (strpos($p, '/scripts/') !== false) continue;          // skrip perawatan kita sendiri
    $isi = (string) @file_get_contents($f->getPathname());
    foreach ($jejak as $j) {
        if (strpos($isi, $j) !== false) { $kena[] = str_replace($root . '/', '', $p) . "  ($j)"; break; }
    }
}
printf("  5. Tanda khas webshell di berkas aplikasi    : %s\n", $kena ? count($kena) . ' berkas' : 'tidak ada');
foreach (array_slice($kena, 0, 10) as $k) echo "       -> $k\n";
if ($kena) {
    $catat('PERIKSA', 'Ada pola yang biasa dipakai webshell',
        "Belum tentu jahat — import.php memang memanggil Node lewat shell.\n"
      . "      Yang perlu dicurigai: berkas yang TIDAK ada di git (git status).");
}

// ─── 6. Pengaturan PHP yang menentukan seberapa jauh RCE bisa melangkah ─────
$ini = [
    'allow_url_include' => ['0',  'GAWAT',   'memungkinkan menyertakan kode dari alamat luar'],
    'allow_url_fopen'   => ['0',  'PERIKSA', 'memungkinkan mengambil berkas dari alamat luar'],
    'expose_php'        => ['0',  'RINGAN',  'membocorkan versi PHP di header'],
    'display_errors'    => ['0',  'PERIKSA', 'membocorkan jalur berkas & kueri ke penyerang'],
];
echo "  6. Pengaturan PHP di server\n";
foreach ($ini as $k => [$harus, $berat, $ket]) {
    $nilai = (string) ini_get($k);
    $aktif = $nilai !== '' && $nilai !== '0' && strtolower($nilai) !== 'off';
    $ok    = $harus === '0' ? !$aktif : $aktif;
    printf("       %-20s %-5s %s\n", $k, $aktif ? 'ON' : 'off', $ok ? 'aman' : '<- ' . $ket);
    if (!$ok) $catat($berat, "php.ini: $k menyala", '      ' . $ket);
}
$mati = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
printf("       %-20s %s\n", 'disable_functions', $mati ? count($mati) . ' fungsi dimatikan' : 'KOSONG');
if (!$mati) {
    $catat('PERIKSA', 'disable_functions kosong',
        "Kalau suatu saat ada celah, penyerang bisa langsung memanggil\n"
      . "      exec/system/passthru. Di cPanel: MultiPHP INI Editor -> disable_functions.\n"
      . "      Aplikasi ini hanya butuh shell_exec (impor Excel, opsional).");
}

// ─── 7. Akun ────────────────────────────────────────────────────────────────
try {
    $pdo = Database::connect();
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $u = $pdo->query("SELECT id, name, email, role, status, created_at FROM users
                       WHERE role IN ('superadmin','admin') ORDER BY id")->fetchAll();
    printf("\n  7. Akun berwenang penuh (%d)\n", count($u));
    foreach ($u as $r) {
        printf("       #%-3s %-22s %-28s %-11s %s\n",
            $r['id'], mb_substr((string) $r['name'], 0, 22), (string) $r['email'],
            $r['role'], (string) ($r['status'] ?? ''));
    }
    echo "       (kenali semuanya — akun asing di sini sama dengan kunci cadangan)\n";

    $b = $pdo->prepare('SELECT COUNT(*) FROM users WHERE created_at >= ?');
    $b->execute([date('Y-m-d H:i:s', $batas)]);
    $nBaru = (int) $b->fetchColumn();
    printf("       akun dibuat %d hari terakhir: %d\n", $hari, $nBaru);
    if ($nBaru > 0) $catat('PERIKSA', "Ada $nBaru akun baru dalam $hari hari terakhir", '      Pastikan semuanya memang Anda yang buat.');
} catch (Throwable $e) {
    echo "\n  7. Akun: tidak bisa dibaca — " . $e->getMessage() . "\n";
}

// ─── Kesimpulan ─────────────────────────────────────────────────────────────
echo "\n" . str_repeat('-', 86) . "\n";
if (!$temuan) {
    echo "  Tidak ada tanda penyusupan, dan lapisan pertahanannya di tempat.\n\n";
    exit(0);
}
$urut = ['GAWAT' => 0, 'PERIKSA' => 1, 'RINGAN' => 2];
usort($temuan, fn($a, $b) => $urut[$a[0]] <=> $urut[$b[0]]);
printf("  %d hal perlu ditindaklanjuti:\n\n", count($temuan));
foreach ($temuan as [$berat, $judul, $ket]) {
    printf("  [%s] %s\n%s\n\n", $berat, $judul, $ket);
}
echo "  Catatan: skrip ini tidak mengubah apa pun. Kalau ada yang berstatus GAWAT,\n";
echo "  jangan buru-buru menghapus — salin dulu berkasnya, itu satu-satunya bukti\n";
echo "  untuk tahu masuknya lewat mana.\n\n";
exit(1);
