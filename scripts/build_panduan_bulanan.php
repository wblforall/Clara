<?php
/**
 * Panduan perbaikan data satu bulan, satu orang — langkah demi langkah.
 *
 * Spreadsheet adalah PATOKAN. Dokumen ini tidak pernah menyimpulkan ada yang
 * salah di sana; yang dikerjakan selalu di sisi CLARA. Kalimatnya pun disusun
 * begitu: "Excel mencatat X, CLARA perlu disamakan" — bukan "Excel salah".
 *
 * Bedanya dengan daftar perbaikan yang lama:
 *
 *   1. Tiap temuan punya LANGKAH BERNOMOR sampai selesai — menu mana, kotak
 *      mana, diisi apa, lalu apa yang harus terlihat sesudah disimpan. Daftar
 *      lama berhenti di "minta Superadmin menghapus #1861", dan orang yang
 *      memegangnya masih harus menebak caranya.
 *   2. Beda NILAI pada penyewa yang sama dikenali sebagai KOREKSI, bukan
 *      "hapus lalu input ulang". Itu yang dulu membuat satu transaksi benar
 *      terbaca sebagai dua pekerjaan.
 *   3. Baris yang nilainya sama dengan transaksi yang SUDAH ADA tidak pernah
 *      disuruh diinput ulang. Dulu sebaliknya, dan hasil akhirnya melebihi
 *      spreadsheet — mengikuti panduannya justru merusak data.
 *   4. Baris PATUNGAN (satu lokasi dibagi dua/tiga orang) disebut terang-
 *      terangan beserta bagian masing-masing, supaya tidak ada yang menginput
 *      nilai penuh milik berdua.
 *   5. Tabel langkah di akhir WAJIB berhenti tepat di angka spreadsheet. Kalau
 *      tidak, dokumen menyebutnya apa adanya, bukan diam-diam dibiarkan.
 *
 * Sisi CLARA dibaca langsung dari basis data saat dicetak. Jalankan di SERVER;
 * dijalankan di salinan lama, isinya menyuruh membetulkan yang sudah betul.
 *
 * Pakai:
 *   php scripts/build_panduan_bulanan.php --banding=docs/banding_oktober/oktober.json
 *   php scripts/build_panduan_bulanan.php --banding=... --pic=Shinta
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/pdf.php';

$opt = array_merge(['banding' => '', 'pic' => '', 'keluar' => $root . '/docs', 'ss' => ''],
    getopt('', ['banding:', 'pic:', 'keluar:', 'ss:']));
if ($opt['banding'] === '') {
    fwrite(STDERR, "Pakai: php scripts/build_panduan_bulanan.php --banding=berkas.json [--pic=Nama]\n");
    exit(2);
}
$data = json_decode((string) file_get_contents((string) $opt['banding']), true);
if (!is_array($data) || empty($data['pic'])) {
    fwrite(STDERR, "Berkas banding tidak terbaca.\n");
    exit(2);
}
date_default_timezone_set((string) (env_value('APP_TIMEZONE') ?: 'Asia/Makassar'));

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$h  = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
$tgl = function (?string $d): string {
    $t = $d ? strtotime($d) : false;
    return $t ? date('d/m/y', $t) : '-';
};

/** Nama modul sebagaimana tertulis di menu CLARA. */
$MENU = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];

/**
 * Menu tempat sebuah baris spreadsheet diinput.
 *
 * Untuk baris yang belum ada di CLARA tidak ada modul yang bisa dibaca, jadi
 * ditebak dari keterangannya. Tebakan yang salah membuat orang membuka menu
 * yang keliru dan menyimpulkan panduannya ngawur — karena itu tebakannya
 * disertai kata "biasanya" di dokumen, bukan disajikan sebagai kepastian.
 */
$tebakMenu = static function (string $ket): string {
    $k = mb_strtoupper($ket, 'UTF-8');
    foreach (['GUDANG', 'GUDA-', 'WAREHOUSE'] as $g) if (str_contains($k, $g)) return 'Gudang';
    foreach (['BANNER', 'LED', 'VIDEOTRON', 'SIGNAGE', 'BRANDING', 'MEDIA',
              'BILLBOARD', 'NEON', 'BACKDROP'] as $m) if (str_contains($k, $m)) return 'Media';
    return 'Exhibition';
};

/** Rincian CLARA satu PIC satu bulan. */
$qCl = $pdo->prepare(
    "SELECT a.amount, t.id trx, t.module, t.master_code, t.start_date, t.end_date,
            COALESCE(NULLIF(t.final_amount,0), t.total_calculated) nilai_kontrak,
            COALESCE(c.brand_name, c.company_name, '(tanpa nama client)') nama
       FROM transaction_allocations a
       JOIN transactions t ON t.id = a.transaction_id
       LEFT JOIN master_clients c ON c.id = t.client_id
      WHERE a.property_id = ? AND a.pic_name = ? AND a.period_key = ?
        AND a.amount > 0 AND t.deleted_at IS NULL
      ORDER BY a.amount DESC"
);

/**
 * Keadaan DOKUMEN di belakang sebuah transaksi.
 *
 * Angka di laporan tidak berdiri sendiri: ia lahir dari Surat Penawaran, lalu
 * SKP, lalu tanda tangan client. Tanpa menyebut rantai itu, panduan ini hanya
 * bisa berkata "tidak ada di spreadsheet" — dan orang yang memegangnya tidak
 * tahu apakah yang di depannya kontrak yang sudah diteken client atau draft
 * yang belum disetujui siapa pun. Dua hal itu diperlakukan sangat berbeda.
 */
function _panduan_dokumen(PDO $pdo, int $trxId): array
{
    static $simpan = [];
    if (isset($simpan[$trxId])) return $simpan[$trxId];
    $st = $pdo->prepare(
        "SELECT s.id skp_id, s.skp_no, s.status s_status, s.approval_level, s.doc_type,
                s.signed_at s_signed, s.approved_by, s.approved_at,
                o.id off_id, o.offer_no, o.status o_status, o.signed_at o_signed
           FROM transactions t
           LEFT JOIN skp_documents s ON (s.transaction_id = t.id OR s.id = t.skp_id) AND s.deleted_at IS NULL
           LEFT JOIN offers o ON o.id = s.offer_id AND o.deleted_at IS NULL
          WHERE t.id = ? LIMIT 1"
    );
    $st->execute([$trxId]);
    return $simpan[$trxId] = ($st->fetch() ?: []);
}

/** Satu kalimat yang menerangkan sampai mana dokumen itu berjalan. */
function _panduan_kalimat_dokumen(array $dok, PDO $pdo, int $pid): string
{
    if (!$dok || empty($dok['skp_id'])) {
        return 'Transaksi ini <b>tidak punya dokumen SKP</b> — nilainya masuk laporan '
             . 'tanpa surat yang menaunginya.';
    }
    $no  = $dok['skp_no'] ? 'No. <b>' . htmlspecialchars((string) $dok['skp_no'], ENT_QUOTES, 'UTF-8') . '</b>'
                          : '<b>nomor belum terbit</b>';
    $sp  = $dok['offer_no']
        ? ' Berasal dari Surat Penawaran <b>' . htmlspecialchars((string) $dok['offer_no'], ENT_QUOTES, 'UTF-8') . '</b>.'
        : ' Dokumen ini <b>tanpa Surat Penawaran</b> (dibuat langsung).';
    switch ((string) $dok['s_status']) {
        case 'signed':
            return 'SKP ' . $no . ' <b>sudah ditandatangani client</b>'
                 . ($dok['s_signed'] ? ' pada ' . date('d/m/Y', strtotime((string) $dok['s_signed'])) : '')
                 . '.' . $sp . ' Kontrak ini sudah jadi — jangan dihapus sebelum benar-benar yakin.';
        case 'approved':
            return 'SKP ' . $no . ' <b>sudah disetujui Manager</b> tetapi <b>belum ditandatangani client</b>.'
                 . $sp . ' Karena itu nilainya belum masuk laporan.';
        case 'submitted':
            $tahap = 'tahap ' . ((int) $dok['approval_level'] + 1);
            return 'SKP ' . $no . ' <b>masih menunggu persetujuan</b> (' . $tahap . ').' . $sp
                 . ' Nilainya baru masuk laporan setelah disetujui dan ditandatangani client.';
        case 'rejected':
            return 'SKP ' . $no . ' <b>ditolak</b> dan dikembalikan ke PIC untuk diperbaiki.' . $sp;
        case 'draft':
            return 'SKP ' . $no . ' <b>masih draft</b> — belum disubmit ke approval.' . $sp;
    }
    return 'SKP ' . $no . ' berstatus ' . htmlspecialchars((string) $dok['s_status'], ENT_QUOTES, 'UTF-8') . '.' . $sp;
}

/**
 * Cari dokumen yang MUNGKIN sudah ada untuk sebuah baris spreadsheet.
 *
 * "Belum ada di CLARA" terlalu sering tidak benar. Yang sebenarnya terjadi
 * biasanya salah satu dari ini, dan tindakannya berbeda-beda:
 *   - Surat Penawaran sudah ada, SKP-nya yang belum dibuat;
 *   - SKP sudah ada tetapi belum disetujui atau belum diteken client, sehingga
 *     nilainya memang belum muncul di laporan;
 *   - Penawarannya ada DUA untuk sewa yang sama.
 * Menyuruh menginput ulang pada ketiga keadaan itu akan melahirkan data dobel.
 */
function _panduan_calon(PDO $pdo, int $pid, string $periode, string $ket, float $nilai): array
{
    $awal = $periode . '-01';
    $akhir = date('Y-m-t', strtotime($awal));
    $st = $pdo->prepare(
        "SELECT o.id, o.offer_no, o.status, o.signed_at, o.master_code, o.start_date, o.end_date,
                COALESCE(NULLIF(o.override_amount,0), o.total_calculated) nilai,
                COALESCE(c.brand_name, c.company_name, '') nama,
                (SELECT COUNT(*) FROM skp_documents s WHERE s.offer_id = o.id AND s.deleted_at IS NULL) punya_skp
           FROM offers o LEFT JOIN master_clients c ON c.id = o.client_id
          WHERE o.property_id = ? AND o.deleted_at IS NULL
            AND o.start_date <= ? AND o.end_date >= ?
          ORDER BY o.id"
    );
    $st->execute([$pid, $akhir, $awal]);
    $calon = [];
    foreach ($st->fetchAll() as $o) {
        $skor = _mirip($ket, (string) $o['nama']);
        $samaNilai = abs((float) $o['nilai'] - $nilai) < 1;
        if ($skor < 0.5 && !$samaNilai) continue;
        $calon[] = $o + ['skor' => $skor, 'sama_nilai' => $samaNilai];
    }
    return $calon;
}

/** Kata penting sebuah nama — yang pendek tidak membedakan apa pun. */
function _kata(string $t): array
{
    $n = trim((string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($t, 'UTF-8')));
    return array_values(array_filter(explode(' ', $n), fn($k) => mb_strlen($k) >= 4));
}

/**
 * Seberapa mirip dua nama, 0..1. Nol berarti "jangan dipasangkan".
 *
 * Ejaan di dua sumber jarang sama persis ("Pentol Chilok" vs "Pentol Cilok"),
 * jadi toleransi ejaan memang perlu. Tetapi toleransi dua huruf untuk kata
 * pendek menyatukan hal yang sama sekali berbeda: "stall" dan "sally" hanya
 * beda dua huruf, sehingga "9/FOOD STALL LG | CHI FRY" terbaca sepasang dengan
 * penyewa "Sour Sally". Panduan lalu menyuruh orang mengubah nilai transaksi
 * yang sudah benar — kerusakan yang lebih buruk daripada selisih yang
 * dilaporkan apa adanya.
 *
 * Karena itu: toleransi ejaan hanya untuk kata PANJANG (>=7 huruf, di situ
 * beda dua huruf memang hampir pasti salah ketik), dan pasangan baru diakui
 * bila ada setidaknya satu kecocokan KUAT — persis, atau fuzzy pada kata
 * panjang. Kecocokan lemah saja tidak cukup.
 */
function _mirip(string $a, string $b): float
{
    $ka = _kata($a); $kb = _kata($b);
    if (!$ka || !$kb) return 0.0;
    $n = 0; $kuat = false;
    foreach ($ka as $x) {
        foreach ($kb as $y) {
            if ($x === $y) { $n++; $kuat = true; break; }
            if (mb_strlen($x) >= 7 && mb_strlen($y) >= 7 && levenshtein($x, $y) <= 2) {
                $n++; $kuat = true; break;
            }
        }
    }
    if (!$kuat) return 0.0;
    return $n / min(count($ka), count($kb));
}

/**
 * Sandingkan baris spreadsheet dengan baris CLARA.
 *
 * Urutannya menentukan mutu panduannya:
 *   1. NILAI sama + nama mirip  -> sudah cocok, tidak ada pekerjaan.
 *   2. NAMA mirip, nilai beda   -> KOREKSI nilai. Inilah yang dulu terbaca
 *      sebagai "hapus satu, input satu" — dua pekerjaan untuk satu kesalahan.
 *   3. NILAI sama, nama tak mirip -> sudah ada, cukup DIPASTIKAN. Tidak boleh
 *      masuk daftar input: itu sumber "disuruh input padahal sudah ada".
 *   4. Sisa spreadsheet -> INPUT. Sisa CLARA -> HAPUS.
 */
function sandingkan(array $ss, array $cl): array
{
    $cocok = $koreksi = $pastikan = [];
    $sisaSs = $ss; $sisaCl = $cl;

    $ambil = function (callable $uji) use (&$sisaSs, &$sisaCl): array {
        $hasil = [];
        foreach ($sisaSs as $j => $s) {
            $pilih = null; $skor = -1.0;
            foreach ($sisaCl as $i => $c) {
                $nilai = $uji($s, $c);
                if ($nilai !== null && $nilai > $skor) { $skor = $nilai; $pilih = $i; }
            }
            if ($pilih === null) continue;
            $hasil[] = [$s, $sisaCl[$pilih]];
            unset($sisaCl[$pilih], $sisaSs[$j]);
        }
        return $hasil;
    };

    $cocok = $ambil(fn($s, $c) => round((float) $c['amount']) === round((float) $s['nilai'])
        && _mirip((string) $s['ket'], (string) $c['nama']) >= 0.5
            ? _mirip((string) $s['ket'], (string) $c['nama']) : null);

    $koreksi = $ambil(fn($s, $c) => _mirip((string) $s['ket'], (string) $c['nama']) >= 0.5
        ? _mirip((string) $s['ket'], (string) $c['nama']) : null);

    $pastikan = $ambil(fn($s, $c) => round((float) $c['amount']) === round((float) $s['nilai']) ? 0.0 : null);

    // ── Baris GABUNGAN ──────────────────────────────────────────────────────
    //
    // Satu baris spreadsheet kerap mewakili BEBERAPA transaksi sekaligus.
    // Contohnya nyata: "4/GF ISLAND — Rp 66.080.000" adalah empat unit island
    // @ Rp 16.520.000, dan di CLARA tercatat sebagai empat transaksi terpisah
    // (GF-005, GF-006, ...). Pencocokan satu-lawan-satu tidak akan pernah
    // menemukan pasangannya, lalu keempatnya dilaporkan "tidak ada di
    // spreadsheet" dan disuruh dihapus — padahal semuanya sah. Mengikuti
    // panduan seperti itu berarti membuang pendapatan yang benar.
    //
    // Penandanya: beberapa transaksi dengan penyewa & nilai yang SAMA PERSIS,
    // dan ada baris spreadsheet yang nilainya kelipatan bulat dari nilai itu.
    $gabungan = [];
    $perGrup = [];
    foreach ($sisaCl as $i => $c) {
        $perGrup[mb_strtolower((string) $c['nama']) . '|' . round((float) $c['amount'])][] = $i;
    }
    foreach ($perGrup as $idx) {
        if (count($idx) < 2) continue;
        // (int) wajib: round() mengembalikan float, sedangkan % dan intdiv()
        // hanya menerima bilangan bulat.
        $satuan = (int) round((float) $sisaCl[$idx[0]]['amount']);
        if ($satuan <= 0) continue;
        foreach ($sisaSs as $j => $s) {
            $nilai = (int) round((float) $s['nilai']);
            if ($nilai < $satuan || $nilai % $satuan !== 0) continue;
            $n = intdiv($nilai, $satuan);
            // Kelipatan harus masuk akal: minimal sebanyak yang sudah ada, dan
            // tidak jauh di atasnya — kalau tidak, angka yang kebetulan habis
            // dibagi akan tertarik jadi "gabungan" yang tidak pernah ada.
            if ($n < count($idx) || $n > count($idx) + 4) continue;
            $anggota = array_map(fn($i) => $sisaCl[$i], $idx);
            $gabungan[] = ['ss' => $s, 'cl' => $anggota, 'satuan' => (float) $satuan,
                           'jumlah_seharusnya' => $n, 'jumlah_ada' => count($idx)];
            foreach ($idx as $i) unset($sisaCl[$i]);
            unset($sisaSs[$j]);
            break;
        }
    }

    // ── Baris DOBEL di CLARA ────────────────────────────────────────────────
    //
    // Penyewa, unit, dan nilai sama persis, tercatat lebih dari sekali —
    // sementara spreadsheet hanya memuatnya satu kali. Dipisahkan dari
    // "tidak ada di spreadsheet" karena alasannya berbeda, dan yang dikerjakan
    // juga berbeda: yang satu disimpan, kelebihannya yang dibuang.
    $dobel = [];
    $kunci = static fn(array $c): string => mb_strtolower((string) $c['nama']) . '|'
        . (string) $c['master_code'] . '|' . round((float) $c['amount']);

    // Kembarannya dicari di SELURUH daftar CLARA, bukan hanya di sisa yang
    // belum berpasangan.
    //
    // Pada sepasang transaksi kembar, satu di antaranya hampir selalu sudah
    // dipasangkan dengan baris spreadsheet-nya — yang tertinggal tinggal satu,
    // sehingga pencarian di antara sisa saja tidak pernah menemukan pasangan
    // dan baris itu dilaporkan sebagai "tidak ada di spreadsheet". Alasannya
    // jadi keliru: bukan tidak ada, melainkan tercatat dua kali.
    $sisaId = [];
    foreach ($sisaCl as $c) $sisaId[(int) $c['trx']] = true;
    foreach ($sisaCl as $i => $c) {
        $k = $kunci($c);
        $pasangan = null;
        foreach ($cl as $lain) {
            if ((int) $lain['trx'] === (int) $c['trx']) continue;
            if ($kunci($lain) !== $k) continue;
            // Yang SUDAH berpasangan didahulukan sebagai "yang disimpan": itu
            // yang sudah terbukti punya baris di spreadsheet.
            if (!isset($sisaId[(int) $lain['trx']])) { $pasangan = $lain; break; }
            // Dua-duanya belum berpasangan: yang id-nya lebih kecil (dibuat
            // lebih dulu) yang disimpan.
            if ($pasangan === null && (int) $lain['trx'] < (int) $c['trx']) $pasangan = $lain;
        }
        if ($pasangan === null) continue;
        $dobel[] = ['cl' => $c, 'simpan' => $pasangan];
        unset($sisaCl[$i], $sisaId[(int) $c['trx']]);
    }

    return [$cocok, $koreksi, $pastikan, $gabungan, $dobel,
            array_values($sisaSs), array_values($sisaCl)];
}

/**
 * Tangkapan layar APLIKASI untuk temuan dobel.
 *
 * Daftar yang menyebut "dobel dengan #2095" masih menuntut orang membuka
 * CLARA dan mencarinya sendiri. Gambar daftar dokumen yang tersaring nama
 * penyewanya memperlihatkan kedua barisnya sekaligus — bukti yang bisa dilihat
 * tanpa meninggalkan kertasnya.
 *
 * Hanya berjalan bila diminta lewat --ss, dan butuh Chrome. Di server tidak
 * ada Chrome, jadi tanpa bendera itu panduannya tetap terbit lengkap, hanya
 * tanpa gambar.
 *
 * Kredensial dibaca dari peubah lingkungan, bukan dari baris perintah — baris
 * perintah tersimpan di riwayat shell.
 */
function _panduan_tangkap(string $alamat, string $berkas, string $cookie): bool
{
    $chrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
    if (!is_file($chrome)) return false;
    $tmp = dirname($berkas) . '/_halaman.html';
    $ch = curl_init($alamat);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookie,
                            CURLOPT_COOKIEJAR => $cookie, CURLOPT_TIMEOUT => 30]);
    $html = (string) curl_exec($ch);
    curl_close($ch);
    if ($html === '' || stripos($html, '<table') === false) return false;
    // <base> supaya gaya & gambar tetap termuat saat halaman dibuka dari berkas.
    $asal = preg_replace('#(\?|/\?).*$#', '/', $alamat);
    $html = preg_replace('/<head>/i', '<head><base href="' . $asal . '">', $html, 1);
    file_put_contents($tmp, $html);
    $profil = dirname($berkas) . '/_chrome';
    @mkdir($profil, 0777, true);
    $cmd = escapeshellarg($chrome) . ' --headless=new --disable-gpu --no-first-run'
         . ' --user-data-dir=' . escapeshellarg($profil)
         . ' --window-size=1500,760 --hide-scrollbars --virtual-time-budget=4000'
         . ' --screenshot=' . escapeshellarg($berkas)
         . ' ' . escapeshellarg('file://' . $tmp) . ' 2>/dev/null';
    shell_exec($cmd);
    @unlink($tmp);
    return is_file($berkas) && filesize($berkas) > 2000;
}

$CSS = '<style>
 body { font-family: sans-serif; font-size: 9.5pt; color:#1F2937; line-height:1.5; }
 h1 { font-size:17pt; margin:0 0 1mm; }
 h2 { font-size:11.5pt; color:#0A7267; margin:7mm 0 2mm; padding-bottom:1.2mm;
      border-bottom:1pt solid #0D9488; page-break-after:avoid; }
 p { margin:0 0 2.4mm; }
 .sub { color:#6B7280; font-size:8.8pt; margin-bottom:4mm; }
 table { width:100%; border-collapse:collapse; margin-bottom:3mm; }
 tr { page-break-inside:avoid; }
 th,td { border:0.4pt solid #D1D5DB; padding:1.5mm 2mm; vertical-align:top; font-size:8.6pt; }
 th { background:#F0FDF4; color:#0A7267; font-size:7.8pt; text-transform:uppercase; letter-spacing:.03em; }
 td.n, th.n { text-align:right; white-space:nowrap; }
 .muted { color:#6B7280; }
 .item { page-break-inside:avoid; border:0.6pt solid #D1D5DB; border-left:3pt solid #0D9488;
         background:#F8FAFC; padding:3mm 3.4mm; margin-bottom:3.5mm; }
 .item.hapus  { border-left-color:#B91C1C; background:#FEF2F2; }
 .item.input  { border-left-color:#047857; background:#F0FDF4; }
 .item.koreksi{ border-left-color:#B45309; background:#FFFBEB; }
 .item.cek    { border-left-color:#4338CA; background:#EEF2FF; }
 .judul { font-weight:bold; font-size:10pt; margin-bottom:2mm; }
 .tag { font-size:7.4pt; font-weight:bold; letter-spacing:.05em; padding:0.5mm 1.6mm;
        border-radius:1mm; color:#fff; }
 .kv { width:100%; border-collapse:collapse; margin:0 0 2mm; }
 .kv td { border:none; padding:0.6mm 0; font-size:8.6pt; vertical-align:top; }
 .kv td.k { width:26mm; color:#6B7280; }
 ol { margin:0 0 0 5mm; padding:0; } li { margin-bottom:1.2mm; font-size:8.7pt; }
 .dampak { font-weight:bold; font-size:8.6pt; margin-top:1.5mm; }
 .ok { color:#047857; } .kurang { color:#B91C1C; }
</style>';

$dibuat = date('d/m/Y H:i');
$hasilAkhir = [];

// Sesi aplikasi untuk tangkapan layar (opsional, hanya saat --ss).
$ssAktif = false; $ssCookie = ''; $ssUrl = rtrim((string) getenv('CLARA_URL'), '/');
$ssDir = rtrim((string) $opt['keluar'], '/') . '/bukti';
if ($opt['ss'] !== '' && $ssUrl !== '' && getenv('CLARA_EMAIL') && getenv('CLARA_PASS')) {
    @mkdir($ssDir, 0777, true);
    $ssCookie = $ssDir . '/_sesi.txt';
    @unlink($ssCookie);
    $ch = curl_init($ssUrl . '/?r=login');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $ssCookie,
                            CURLOPT_COOKIEFILE => $ssCookie, CURLOPT_TIMEOUT => 20]);
    $hal = (string) curl_exec($ch); curl_close($ch);
    if (preg_match('/name="_csrf" value="([^"]+)"/', $hal, $m)) {
        $ch = curl_init($ssUrl . '/?r=login');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $ssCookie,
            CURLOPT_COOKIEFILE => $ssCookie, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_POSTFIELDS => http_build_query([
                '_csrf' => $m[1], 'email' => getenv('CLARA_EMAIL'), 'password' => getenv('CLARA_PASS')])]);
        curl_exec($ch); curl_close($ch);
        $ssAktif = true;
    }
}

foreach ($data['pic'] as $kunci => $d) {
    if ($opt['pic'] !== '' && mb_strtolower((string) $d['pic']) !== mb_strtolower((string) $opt['pic'])) continue;
    $pic = (string) $d['pic'];
    $pid = (int) $d['properti'];
    $per = (string) $d['periode'];
    $namaProp = (string) ($pdo->query('SELECT name FROM properties WHERE id = ' . $pid)->fetchColumn() ?: '-');

    if ($ssAktif) {
        $ch = curl_init($ssUrl . '/?r=switch_property&to=' . $pid);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $ssCookie,
            CURLOPT_COOKIEJAR => $ssCookie, CURLOPT_TIMEOUT => 20]);
        curl_exec($ch); curl_close($ch);
    }
    $qCl->execute([$pid, $pic, $per]);
    $clRows = $qCl->fetchAll();
    $clTotal = array_sum(array_map(fn($r) => (float) $r['amount'], $clRows));
    $ssTotal = (float) $d['total'];

    [$cocok, $koreksi, $pastikan, $gabungan, $dobel, $inputBaru, $lebih] =
        sandingkan($d['rincian'], $clRows);

    // Nomor urut pekerjaan berjalan lintas golongan, supaya orang bisa bilang
    // "nomor 4 sudah" tanpa harus menyebut golongannya.
    //
    // Tiap pekerjaan membawa 'kenapa': alasan yang bisa diperiksa sendiri oleh
    // yang mengerjakan. Daftar yang hanya memerintah "hapus #2120" membuat
    // orang menebak-nebak, dan menebak pada data uang berakhir buruk.
    $no = 0; $kerja = [];
    foreach ($inputBaru as $s) {
        // Sebelum menyuruh input: periksa apakah dokumennya sudah ada tetapi
        // belum selesai jalannya. Menginput ulang pada keadaan itu melahirkan
        // data dobel — persis yang sedang kita bereskan.
        $calon = _panduan_calon($pdo, $pid, $per, (string) $s['ket'], (float) $s['nilai']);
        $dokTxt = ''; $jenis = 'input';
        if ($calon) {
            $baris = [];
            foreach ($calon as $o) {
                $baris[] = 'SP <b>' . $h($o['offer_no'] ?: '(nomor belum terbit)') . '</b> &middot; '
                    . $h($o['nama'] ?: '-') . ' &middot; ' . $h($o['master_code'] ?: '-') . ' &middot; '
                    . $rp($o['nilai']) . ' &middot; status <b>' . $h($o['status']) . '</b>'
                    . ($o['signed_at'] ? ', sudah diteken client' : ', <b>belum diteken client</b>')
                    . ' &middot; ' . ((int) $o['punya_skp'] > 0
                        ? 'SKP-nya <b>sudah ada</b>' : 'SKP-nya <b>belum dibuat</b>');
            }
            $jenis  = 'lanjutkan';
            $dokTxt = 'Ditemukan ' . count($calon) . ' Surat Penawaran yang cocok untuk baris ini:<br>&bull; '
                    . implode('<br>&bull; ', $baris)
                    . (count($calon) > 1
                        ? '<br><b>Ada lebih dari satu SP untuk sewa yang sama.</b> Pakai yang sudah diteken client; '
                        . 'kalau dua-duanya diteken, pakai yang nomornya terbit lebih dulu dan ajukan hapus yang satunya.'
                        : '');
        }
        $kerja[] = ['jenis' => $jenis, 'ss' => $s, 'cl' => null, 'calon' => $calon,
            'nilai' => (float) $s['nilai'],
            'dokumen' => $dokTxt,
            'kenapa' => $calon
                ? 'Nilainya belum muncul di laporan Oktober BUKAN karena belum diinput, melainkan '
                . 'karena dokumennya belum selesai jalannya. Nilai baru dihitung setelah SKP-nya '
                . 'disetujui <b>dan</b> ditandatangani client.'
                : 'Baris ini ada di spreadsheet Anda, tetapi di CLARA tidak ada transaksi Oktober '
                . 'bernilai ' . $rp($s['nilai']) . ', tidak ada nama yang mirip, dan tidak ada Surat '
                . 'Penawaran yang periodenya menyentuh Oktober. Jadi memang belum pernah dibuat.'];
    }
    foreach ($koreksi as [$s, $c]) {
        if (round((float) $c['amount']) === round((float) $s['nilai'])) continue;
        $kerja[] = ['jenis' => 'koreksi', 'ss' => $s, 'cl' => $c,
            'nilai' => (float) $s['nilai'] - (float) $c['amount'],
            'dokumen' => _panduan_kalimat_dokumen(_panduan_dokumen($pdo, (int) $c['trx']), $pdo, $pid),
            'kenapa' => 'Penyewanya sama, hanya nilainya berbeda: spreadsheet ' . $rp($s['nilai'])
                      . ', CLARA ' . $rp($c['amount']) . '. Jadi transaksinya tidak perlu dibuat ulang '
                      . 'atau dihapus — cukup nilainya disamakan.'];
    }
    // Baris gabungan yang jumlah unitnya belum lengkap → yang kurang DIINPUT.
    // Yang sudah ada TIDAK diganggu: itu bagian sah dari baris yang sama.
    foreach ($gabungan as $g) {
        $kurang = $g['jumlah_seharusnya'] - $g['jumlah_ada'];
        if ($kurang <= 0) continue;
        $ids = implode(', ', array_map(fn($c) => '#' . (int) $c['trx'], $g['cl']));
        for ($i = 0; $i < $kurang; $i++) {
            $kerja[] = ['jenis' => 'input', 'ss' => $g['ss'], 'cl' => null, 'nilai' => $g['satuan'],
                'gabungan' => $g,
                'kenapa' => 'Baris spreadsheet ini satu baris untuk ' . $g['jumlah_seharusnya']
                          . ' unit (' . $rp($g['ss']['nilai']) . ' = ' . $g['jumlah_seharusnya']
                          . ' × ' . $rp($g['satuan']) . '). Di CLARA baru ada ' . $g['jumlah_ada']
                          . ' (' . $ids . '), jadi kurang ' . $kurang . ' unit lagi. '
                          . 'Yang sudah ada JANGAN dihapus — semuanya bagian dari baris yang sama.'];
        }
    }
    foreach ($dobel as $x) {
        $c = $x['cl']; $simpan = $x['simpan'];
        $kerja[] = ['jenis' => 'dobel', 'ss' => null, 'cl' => $c, 'simpan' => $simpan,
            'dokumen' => _panduan_kalimat_dokumen(_panduan_dokumen($pdo, (int) $c['trx']), $pdo, $pid)
                       . ' Pembandingnya: '
                       . _panduan_kalimat_dokumen(_panduan_dokumen($pdo, (int) $simpan['trx']), $pdo, $pid),
            'nilai' => -(float) $c['amount'],
            'kenapa' => 'Penyewa, unit, dan nilainya sama persis dengan transaksi #'
                      . (int) $simpan['trx'] . ' — dua baris untuk satu sewa yang sama, '
                      . 'sementara spreadsheet hanya memuatnya satu kali. Yang disimpan #'
                      . (int) $simpan['trx'] . ', karena itulah yang sudah berpasangan dengan '
                      . 'baris spreadsheet Anda; yang ini kelebihannya.'];
        if ($ssAktif) {
            $modul  = in_array((string) $c['module'], ['media', 'gudang'], true) ? (string) $c['module'] : 'cl';
            $berkas = $ssDir . '/dobel_' . preg_replace('/[^A-Za-z0-9]+/', '_', $pic)
                    . '_' . (int) $c['trx'] . '.png';
            $alamat = $ssUrl . '/?r=skp&module=' . $modul . '&q=' . rawurlencode((string) $c['nama']);
            if (_panduan_tangkap($alamat, $berkas, $ssCookie)) {
                $kerja[count($kerja) - 1]['gambar'] = $berkas;
                $kerja[count($kerja) - 1]['gambar_ket'] = 'Daftar dokumen CLARA disaring nama &ldquo;'
                    . $h($c['nama']) . '&rdquo; — kedua barisnya terlihat berdampingan.';
            }
        }
    }
    foreach ($lebih as $c) $kerja[] = ['jenis' => 'hapus', 'ss' => null, 'cl' => $c,
        'nilai' => -(float) $c['amount'],
        'dokumen' => _panduan_kalimat_dokumen(_panduan_dokumen($pdo, (int) $c['trx']), $pdo, $pid),
        'kenapa' => 'Di spreadsheet Oktober Anda tidak ada baris bernilai ' . $rp($c['amount'])
                  . ', tidak ada nama yang mirip &ldquo;' . $h($c['nama']) . '&rdquo;, dan nilainya '
                  . 'juga bukan bagian dari baris gabungan mana pun. '
                  . '<b>Periksa dulu sebelum diajukan hapus</b> — kalau ternyata tercatat di '
                  . 'spreadsheet dengan nama atau lokasi lain, jangan dihapus.'];

    usort($kerja, fn($a, $b) => abs($b['nilai']) <=> abs($a['nilai']));

    $hasil = $clTotal + array_sum(array_column($kerja, 'nilai'));
    $selisihAkhir = $hasil - $ssTotal;

    ob_start();
    ?>
    <h1>Panduan Perbaikan Data — <?= $h($pic) ?></h1>
    <div class="sub"><?= $h($namaProp) ?> &middot; <?= $h($d['bulan']) ?> <?= $h(substr($per, 0, 4)) ?>
        &middot; dibuat <?= $h($dibuat) ?><br>
        Patokan: <?= $h($data['sumber']) ?>. Kolom CLARA dibaca langsung dari basis data saat dokumen ini dibuat.
        Seluruh langkah dikerjakan di CLARA; spreadsheet tidak perlu diubah.</div>

    <h2>Keadaan <?= $h($d['bulan']) ?></h2>
    <table>
        <tr><th>&nbsp;</th><th class="n">Nilai</th><th>Keterangan</th></tr>
        <tr><td>Spreadsheet <?= $h($pic) ?></td><td class="n"><?= $rp($ssTotal) ?></td>
            <td class="muted"><?= count($d['rincian']) ?> baris</td></tr>
        <tr><td>CLARA sekarang</td><td class="n"><?= $rp($clTotal) ?></td>
            <td class="muted"><?= count($clRows) ?> transaksi bernilai</td></tr>
        <tr style="background:#F8FAFC;font-weight:bold">
            <td>Selisih</td>
            <td class="n <?= $clTotal < $ssTotal ? 'kurang' : '' ?>">
                <?= ($clTotal - $ssTotal >= 0 ? '+' : '&minus;') . number_format(abs($clTotal - $ssTotal), 0, ',', '.') ?></td>
            <td><?= $clTotal == $ssTotal ? 'sudah sama' : ($clTotal < $ssTotal ? 'CLARA masih kurang' : 'CLARA kelebihan') ?></td></tr>
    </table>
    <p><?= count($cocok) ?> baris sudah cocok nilai dan namanya &mdash; tidak perlu disentuh.
       <?= count($pastikan) ?> baris nilainya sudah sama tetapi namanya tertulis berbeda; itu cukup dipastikan,
       <b>bukan diinput ulang</b>. Yang perlu dikerjakan: <b><?= count($kerja) ?></b> hal.</p>

    <?php if (!$kerja): ?>
    <div class="item input"><div class="judul">Tidak ada pekerjaan</div>
        Seluruh baris <?= $h($d['bulan']) ?> milik <?= $h($pic) ?> sudah sama dengan spreadsheet.</div>
    <?php endif; ?>

    <?php if ($kerja): ?>
    <h2>Yang perlu dikerjakan (<?= count($kerja) ?>)</h2>
    <?php foreach ($kerja as $k): $no++;
        $s = $k['ss']; $c = $k['cl'];
        $menu = $c ? ($MENU[$c['module']] ?? 'Exhibition') : $tebakMenu((string) ($s['ket'] ?? ''));
        $warna = ['input' => '#047857', 'hapus' => '#B91C1C', 'koreksi' => '#B45309',
                  'dobel' => '#B91C1C', 'lanjutkan' => '#4338CA'][$k['jenis']];
        $tag   = ['input' => 'INPUT BARU', 'hapus' => 'TIDAK ADA DI SPREADSHEET',
                  'koreksi' => 'KOREKSI NILAI', 'dobel' => 'DOBEL — BUANG SATU',
                  'lanjutkan' => 'DOKUMEN SUDAH ADA — TINGGAL DISELESAIKAN'][$k['jenis']];
        $kelas = ['dobel' => 'hapus', 'lanjutkan' => 'cek'][$k['jenis']] ?? $k['jenis'];
    ?>
    <div class="item <?= $kelas ?>">
        <div class="judul"><?= $no ?>. <?= $h($s['ket'] ?? ($c['nama'] . ' — ' . $c['master_code'])) ?>
            <span class="tag" style="background:<?= $warna ?>"><?= $tag ?></span></div>
        <table class="kv">
            <?php if ($s): ?>
            <tr><td class="k">Di spreadsheet</td><td>
                <?= $h($s['sheet']) ?> baris <?= (int) $s['baris'] ?> &mdash;
                <b><?= $h($s['ket']) ?></b>, <?= $rp($s['nilai']) ?>
                <?php if (!empty($s['patungan'])): ?><br>
                <span class="muted">Baris ini <b>patungan</b> dengan
                <?= $h(implode(', ', array_diff($s['pemilik'], [$pic]))) ?>.
                <b>Bagian Anda <?= $rp($s['nilai']) ?></b> &mdash; yang diinput hanya bagian Anda,
                bagian rekan Anda ada di panduannya sendiri.</span><?php endif; ?>
            </td></tr>
            <?php endif; ?>
            <tr><td class="k">Di CLARA</td><td>
                <?php if ($c): ?>
                Transaksi <b>#<?= (int) $c['trx'] ?></b> &middot; <?= $h($c['master_code']) ?> &middot;
                <?= $h($tgl($c['start_date'])) ?>&ndash;<?= $h($tgl($c['end_date'])) ?> &middot;
                <?= $h($c['nama']) ?> &middot; diakui <?= $rp($c['amount']) ?> di bulan ini
                <?php else: ?><span class="muted">belum ada</span><?php endif; ?>
            </td></tr>
            <?php /* Alasannya ditulis, bukan diperintahkan begitu saja. Yang
                     mengerjakan harus bisa memeriksa sendiri apakah kesimpulan
                     ini benar sebelum menyentuh data. */ ?>
            <tr><td class="k">Kenapa</td><td><?= $k['kenapa'] ?? '' ?></td></tr>
            <?php /* Rantai dokumennya disebut: SP -> SKP -> tanda tangan client.
                     Tanpa ini orang tidak bisa membedakan kontrak yang sudah
                     diteken dari draft yang belum disetujui siapa pun. */ ?>
            <?php if (!empty($k['dokumen'])): ?>
            <tr><td class="k">Keadaan dokumen</td><td><?= $k['dokumen'] ?></td></tr>
            <?php endif; ?>
        </table>
        <div style="font-size:8.4pt;color:#6B7280;margin-bottom:1mm">Langkah:</div>
        <ol>
        <?php if ($k['jenis'] === 'input'): ?>
            <li>Buka menu <b><?= $h($menu) ?></b> &rarr; <b>+ Input Transaksi</b>.
                <span class="muted">(dari keterangannya, baris ini biasanya masuk
                <?= $h($menu) ?>; kalau ternyata bukan, pakai menu yang sesuai)</span></li>
            <li>Client: ketik nama penyewanya, lalu <b>pilih dari daftar</b> yang muncul
                &mdash; kalau hanya diketik tanpa dipilih, transaksinya tersimpan tanpa client.</li>
            <li>Unit / Lokasi: <b><?= $h($s['unit'] ?: $s['ket']) ?></b>.</li>
            <li>Tanggal Mulai &amp; Selesai: isi sesuai masa sewanya di bulan
                <b><?= $h($d['bulan']) ?></b>.</li>
            <li>Nilai kontrak: <b><?= $rp($s['nilai']) ?></b><?= !empty($s['patungan'])
                ? ' &mdash; bagian Anda saja' : '' ?>.</li>
            <li>PIC: <b><?= $h($pic) ?></b>.</li>
            <li>Simpan, lalu buka <b>Alokasi</b> transaksinya dan pastikan bulan
                <b><?= $h($per) ?></b> berisi <b><?= $rp($s['nilai']) ?></b>.</li>
        <?php elseif ($k['jenis'] === 'koreksi'): ?>
            <li>Buka menu <b><?= $h($menu) ?></b>, cari transaksi <b>#<?= (int) $c['trx'] ?></b>
                (<?= $h($c['master_code']) ?>, <?= $h($c['nama']) ?>).</li>
            <li>Tekan <b>Edit</b>.</li>
            <li>Ubah nilainya dari <b><?= $rp($c['amount']) ?></b> menjadi
                <b><?= $rp($s['nilai']) ?></b>.</li>
            <li>Periksa juga tanggalnya: spreadsheet mencatat baris ini di
                <b><?= $h($d['bulan']) ?></b>. Kalau tanggalnya membuat nilainya jatuh ke bulan
                lain, perbaiki tanggalnya juga.</li>
            <li>Simpan, lalu pastikan di <b>Alokasi</b> bulan <b><?= $h($per) ?></b>
                tertulis <b><?= $rp($s['nilai']) ?></b>.</li>
        <?php elseif ($k['jenis'] === 'lanjutkan'): ?>
            <?php $c1 = $k['calon'][0] ?? []; $adaSkp = (int) ($c1['punya_skp'] ?? 0) > 0; ?>
            <li><b>Jangan input transaksi baru</b> &mdash; dokumennya sudah ada. Menginput ulang
                akan membuat nilainya terhitung dua kali.</li>
            <?php if (count($k['calon']) > 1): ?>
            <li><b>Pilih dulu SP mana yang dipakai.</b> Yang sudah diteken client yang dipakai.
                Kalau dua-duanya sudah diteken, pakai yang nomornya terbit lebih dulu, lalu
                ajukan hapus yang satunya lewat <b>Pengajuan Hapus Data</b>.</li>
            <?php endif; ?>
            <?php if (!$adaSkp): ?>
            <li>Buka <b>Surat Penawaran</b>, cari No. <b><?= $h($c1['offer_no'] ?? '-') ?></b>.</li>
            <li>Pastikan sudah <b>ditandatangani client</b>. Kalau belum, kirim dulu tautan tanda
                tangannya &mdash; SKP tidak bisa dibuat sebelum SP diteken.</li>
            <li>Sesudah diteken: tekan <b>+ Buat SKP</b> dari penawaran itu.</li>
            <li>Lengkapi Scan KTP &amp; NPWP, lalu <b>Simpan &amp; Submit untuk Approval</b>.</li>
            <?php else: ?>
            <li>Buka <b>SKP Exhibition</b>, cari dokumen milik
                <b><?= $h($c1['nama'] ?? '-') ?></b> (<?= $h($c1['master_code'] ?? '-') ?>).</li>
            <li>Lihat <b>Alur Persetujuan</b> di dokumen itu &mdash; di situ tertulis sedang
                menunggu siapa. Kalau menunggu Anda, kerjakan; kalau menunggu Asst. Manager atau
                Manager, ingatkan orangnya.</li>
            <li>Sesudah disetujui, kirim tautan <b>tanda tangan</b> ke client.</li>
            <li>Nilainya baru masuk laporan Oktober <b>setelah client menandatangani</b>.</li>
            <?php endif; ?>
        <?php elseif ($k['jenis'] === 'dobel'): ?>
            <li>Buka menu <b><?= $h($menu) ?></b>, bandingkan dulu kedua transaksinya:
                <b>#<?= (int) $k['simpan']['trx'] ?></b> (yang sudah berpasangan dengan
                baris spreadsheet) dan <b>#<?= (int) $c['trx'] ?></b> &mdash; keduanya
                <?= $h($c['master_code']) ?>, <?= $h($c['nama']) ?>, <?= $rp($c['amount']) ?>.</li>
            <li>Kalau isinya memang sama, yang dibuang <b>#<?= (int) $c['trx'] ?></b> dan yang
                disimpan <b>#<?= (int) $k['simpan']['trx'] ?></b>.
                Kalau ternyata keduanya sewa yang BERBEDA (mis. periode atau unitnya tidak sama),
                jangan dihapus &mdash; berarti spreadsheet-nya yang perlu ditambah satu baris.</li>
            <li>Buka <b>Pengajuan Hapus Data</b> &rarr; <b>+ Ajukan Hapus</b>, pilih
                <b>#<?= (int) $c['trx'] ?></b>.</li>
            <li>Alasannya: &ldquo;dobel dengan #<?= (int) $k['simpan']['trx'] ?> &mdash;
                <?= $h($c['master_code']) ?> <?= $h($c['nama']) ?> tercatat dua kali,
                di spreadsheet hanya satu&rdquo;.</li>
            <li>Kirim. Penghapusan dikerjakan oleh penyetuju hapus.</li>
        <?php else: ?>
            <li>Buka menu <b><?= $h($menu) ?></b>, cari transaksi <b>#<?= (int) $c['trx'] ?></b>
                (<?= $h($c['master_code']) ?>, <?= $h($c['nama']) ?>,
                <?= $h($tgl($c['start_date'])) ?>&ndash;<?= $h($tgl($c['end_date'])) ?>).</li>
            <li><b>Periksa dulu tiga hal ini</b> sebelum mengajukan hapus:
                (a) apakah penyewanya tercatat di spreadsheet Anda dengan nama lokasi, bukan nama penyewa;
                (b) apakah ia bagian dari satu baris gabungan beberapa unit;
                (c) apakah ia sebenarnya milik bulan lain. Kalau salah satunya ya, <b>jangan dihapus</b>.</li>
            <li>Kalau ketiganya tidak: buka <b>Pengajuan Hapus Data</b> &rarr;
                <b>+ Ajukan Hapus</b>, pilih transaksi <b>#<?= (int) $c['trx'] ?></b>.</li>
            <li>Tulis alasannya apa adanya, mis. &ldquo;tidak ditemukan di spreadsheet
                <?= $h($d['bulan']) ?> milik <?= $h($pic) ?>&rdquo;.</li>
            <li>Kirim. Penghapusan dikerjakan oleh penyetuju hapus &mdash; Anda tidak bisa
                dan tidak perlu menghapusnya sendiri.</li>
        <?php endif; ?>
        </ol>
        <?php if (!empty($k['gambar']) && is_file((string) $k['gambar'])): ?>
        <div style="margin:2.5mm 0 1mm">
            <img src="<?= $h($k['gambar']) ?>" style="width:100%">
            <div style="font-size:7.6pt;color:#6B7280;font-style:italic;margin-top:0.8mm">
                <?= $k['gambar_ket'] ?? '' ?></div>
        </div>
        <?php endif; ?>
        <div class="dampak">Dampak ke <?= $h($d['bulan']) ?>:
            <?= $k['nilai'] >= 0 ? '+' : '&minus;' ?><?= number_format(abs($k['nilai']), 0, ',', '.') ?></div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($pastikan): ?>
    <h2>Cukup dipastikan &mdash; jangan diinput ulang</h2>
    <p>Nilainya sudah sama dengan transaksi yang ada di CLARA, hanya namanya tertulis berbeda.
       Spreadsheet kadang menulis lokasinya, CLARA menulis nama penyewanya. Kalau dua kolom di
       bawah ini orang yang sama, <b>tidak ada yang perlu dikerjakan</b>.</p>
    <table>
        <tr><th>Di spreadsheet</th><th>Di CLARA</th><th class="n">Nilai</th></tr>
        <?php foreach ($pastikan as [$s, $c]): ?>
        <tr><td><?= $h($s['ket']) ?> <span class="muted">(baris <?= (int) $s['baris'] ?>)</span></td>
            <td><?= $h($c['nama']) ?> &middot; <?= $h($c['master_code']) ?>
                <span class="muted">#<?= (int) $c['trx'] ?></span></td>
            <td class="n"><?= $rp($s['nilai']) ?></td></tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <h2>Kalau seluruh langkah di atas dikerjakan</h2>
    <table>
        <tr><th style="width:62%">Langkah</th><th class="n">Nilai</th><th class="n">Jadi</th></tr>
        <tr><td>CLARA sekarang</td><td class="n">&mdash;</td><td class="n"><?= $rp($clTotal) ?></td></tr>
        <?php $jalan = $clTotal; $i = 0; foreach ($kerja as $k): $i++; $jalan += $k['nilai'];
            $lbl = ['input' => 'Input', 'koreksi' => 'Koreksi', 'hapus' => 'Hapus',
                    'dobel' => 'Buang dobel', 'lanjutkan' => 'Selesaikan dokumen'][$k['jenis']];
            $nm  = $k['ss']['ket'] ?? ($k['cl']['nama'] . ' #' . $k['cl']['trx']); ?>
        <tr><td><?= $i ?>. <?= $lbl ?>: <?= $h(mb_substr((string) $nm, 0, 58)) ?></td>
            <td class="n"><?= $k['nilai'] >= 0 ? '+' : '&minus;' ?><?= number_format(abs($k['nilai']), 0, ',', '.') ?></td>
            <td class="n"><?= $rp($jalan) ?></td></tr>
        <?php endforeach; ?>
        <tr style="background:#ECFDF5;font-weight:bold">
            <td>HASIL</td><td class="n">&mdash;</td><td class="n"><?= $rp($hasil) ?></td></tr>
        <tr style="font-weight:bold">
            <td>Spreadsheet <?= $h($pic) ?> <?= $h($d['bulan']) ?></td>
            <td class="n">&mdash;</td><td class="n"><?= $rp($ssTotal) ?></td></tr>
    </table>
    <?php if (abs($selisihAkhir) < 1): ?>
    <p class="ok"><b>Cocok.</b> Sesudah <?= count($kerja) ?> langkah di atas dikerjakan, angka
       <?= $h($d['bulan']) ?> Anda di CLARA sama persis dengan spreadsheet.</p>
    <?php else: ?>
    <p class="kurang"><b>Masih bersisa <?= $rp(abs($selisihAkhir)) ?>.</b>
       Sisa ini belum bisa dijelaskan oleh langkah mana pun di atas &mdash; disebut di sini
       apa adanya, bukan dibiarkan. Kirimkan dokumen ini supaya sisanya ditelusuri bersama.</p>
    <?php endif; ?>
    <?php
    $isi = (string) ob_get_clean();
    $berkas = rtrim((string) $opt['keluar'], '/') . '/Panduan_' . ucfirst(mb_strtolower($d['bulan']))
            . '_' . preg_replace('/[^A-Za-z0-9]+/', '_', $pic) . '.pdf';
    // clara_letterhead_mpdf() mengembalikan objek mPDF berkop surat, bukan
    // menyimpan berkas — penyimpanannya dilakukan di sini.
    $mpdf = clara_letterhead_mpdf();
    $mpdf->SetTitle('Panduan Perbaikan ' . $d['bulan'] . ' — ' . $pic);
    $mpdf->WriteHTML($CSS . $isi);
    @mkdir(dirname($berkas), 0777, true);
    $mpdf->Output($berkas, \Mpdf\Output\Destination::FILE);
    $hasilAkhir[] = [$pic, $namaProp, $clTotal, $ssTotal, count($kerja), $selisihAkhir, $berkas];
}

printf("\nPANDUAN %s\n%s\n", (string) $data['bulan'], str_repeat('=', 92));
printf("  %-10s %-26s %-16s %-16s %5s  %s\n", 'PIC', 'PROPERTI', 'CLARA', 'SPREADSHEET', 'KERJA', 'SESUDAH DIKERJAKAN');
foreach ($hasilAkhir as [$pic, $prop, $cl, $ss, $n, $sisa, $f]) {
    printf("  %-10s %-26s %-16s %-16s %5d  %s\n", $pic, mb_substr($prop, 0, 26),
        number_format($cl, 0, ',', '.'), number_format($ss, 0, ',', '.'), $n,
        abs($sisa) < 1 ? 'sama dengan spreadsheet' : 'masih bersisa ' . number_format(abs($sisa), 0, ',', '.'));
}
printf("\n  %d dokumen ditulis di %s\n\n", count($hasilAkhir), rtrim((string) $opt['keluar'], '/'));
