<?php
/**
 * Melengkapi "Hitungan" (harga satuan / jumlah titik / jumlah hari) sebuah item
 * pada Form Utilities yang SUDAH TERLANJUR disubmit atau disetujui.
 *
 * Latar: surat FU mencetak asal angkanya — "Rp 1.000.000 x 5 titik x 5 hari =
 * Rp 25.000.000". Ketiga angka itu baru ada sejak kolom Hitungan dibuat;
 * dokumen yang diisi sebelumnya hanya menyimpan nominal jadinya. Item yang
 * tersimpan sebagai beberapa baris titik bersatuan "/Titik/Hari" sudah bisa
 * diuraikan sendiri oleh cetakan; yang TIDAK bisa adalah item borongan seperti
 * "Rp 25.000.000 /5 Titik/Hari" — harga satuan dan lama pemakaiannya tidak
 * tersimpan di mana pun, dan menebaknya berarti mencetak angka karangan.
 *
 * Mengisinya lewat layar menuntut dokumennya ditolak dulu, lalu menempuh ulang
 * seluruh persetujuannya — padahal yang ditambahkan di sini TIDAK mengubah satu
 * rupiah pun. Nilai item, Total Biaya Sewa, PPN, dan Grand Total tetap apa
 * adanya; yang berubah hanya keterangan di suratnya. Karena itu skrip ini
 * MENOLAK menyimpan bila hitungannya tidak sama dengan nominal yang sudah
 * tersimpan — supaya tidak ada nominal yang bergeser lewat jalan belakang ini.
 *
 * Tanpa --terapkan tidak ada satu baris pun yang diubah.
 *
 * Pakai:
 *   php scripts/set_hitungan_fu.php --doc=469
 *   php scripts/set_hitungan_fu.php --doc=469 --item="Mobil" --tarif=1000000 --titik=5 --hari=5 --terapkan
 *
 * Pilihan:
 *   --pecah        memecah SATU baris borongan jadi beberapa baris titik, masing-masing
 *                  berisi harga satuannya dengan satuan "/Titik/Hari" — bentuk yang sama
 *                  dengan item yang titiknya diisi lewat tombol "+ titik lain", sehingga
 *                  daftar centang memuat "Mobil (5 titik)" beserta tiap titiknya.
 *   --lokasi="…"      tulisan lokasi di tiap baris titik.
 *   --hapus-lokasi    menghapus tulisan lokasinya; barisnya lalu tercetak sebagai
 *                     "Titik ke-1 … ke-5".
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';

$arg      = getopt('', ['doc:', 'item:', 'tarif:', 'titik:', 'hari:', 'lokasi:', 'terapkan', 'pecah', 'hapus-lokasi']);
$opt      = array_merge(['doc' => '', 'item' => '', 'tarif' => '', 'titik' => '', 'hari' => ''], $arg);
$terapkan = array_key_exists('terapkan', $arg);
$pecah    = array_key_exists('pecah', $arg);
// PHP tidak mendaftarkan "--lokasi=" yang nilainya kosong, jadi menghapus
// lokasi punya penandanya sendiri — bukan nilai kosong yang diam-diam hilang.
$hapusLok = array_key_exists('hapus-lokasi', $arg);
$aturLok  = $hapusLok || array_key_exists('lokasi', $arg);
$lokBaru  = $hapusLok ? '' : trim((string) ($arg['lokasi'] ?? ''));

$docId = (int) $opt['doc'];
if ($docId <= 0) { fwrite(STDERR, "Sebutkan dokumennya: --doc=469\n"); exit(2); }

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

$st = $pdo->prepare('SELECT id, skp_no, doc_type, status, start_date, end_date, total_amount, detail_json
                       FROM skp_documents WHERE id = ?');
$st->execute([$docId]);
$doc = $st->fetch();
if (!$doc)                             { fwrite(STDERR, "Dokumen $docId tidak ada.\n"); exit(2); }
if (($doc['doc_type'] ?? '') !== 'fu') { fwrite(STDERR, "Dokumen $docId bukan Form Utilities.\n"); exit(2); }

$det = json_decode((string) $doc['detail_json'], true);
if (!is_array($det)) { fwrite(STDERR, "Dokumen $docId belum punya isian detail.\n"); exit(2); }

$hariDok = 0;
if ($doc['start_date'] && $doc['end_date']) {
    $a = strtotime((string) $doc['start_date']); $b = strtotime((string) $doc['end_date']);
    if ($a && $b && $b >= $a) $hariDok = (int) round(($b - $a) / 86400) + 1;
}

$namaItem = function (array $it): string {
    return trim((string) ($it['ket'] ?? '')) !== '' ? (string) $it['ket'] : (string) ($it['nama'] ?? '');
};

echo "\nFORM UTILITIES #{$doc['id']}  " . ($doc['skp_no'] ?: '(nomor belum terbit)')
   . "  status \"{$doc['status']}\"\n" . str_repeat('=', 78) . "\n";
printf("  Masa sewa : %s s/d %s (%d hari)\n", $doc['start_date'], $doc['end_date'], $hariDok);
printf("  Nilai     : %s\n\n", $rp($doc['total_amount']));

// ── Daftar item yang dicentang, beserta keadaan hitungannya ─────────────────
$grup   = ['utilities' => 'Utilities', 'media_promo' => 'Media Promo', 'parkir' => 'Parkir (Test Drive)'];
$baris  = [];   // indeks seluruh baris milik item yang dicari
echo "  ITEM YANG DICENTANG\n";
foreach ($grup as $gk => $gl) {
    foreach ((array) ($det[$gk] ?? []) as $i => $it) {
        if (empty($it['checked'])) continue;
        $nama = $namaItem($it);
        $t = (float) ($it['tarif'] ?? 0); $n = (int) ($it['titik'] ?? 0); $d = (int) ($it['hari'] ?? 0);
        printf("    [%s] %s — %s · satuan \"%s\"%s%s\n", $gl, $nama, $rp($it['biaya'] ?? 0),
            (string) ($it['satuan'] ?? ''),
            trim((string) ($it['lokasi'] ?? '')) !== '' ? ' · lokasi "' . $it['lokasi'] . '"' : '',
            ($t > 0 && $n > 0 && $d > 0)
                ? sprintf('  → %s × %d titik × %d hari', $rp($t), $n, $d) : '  → hitungan belum diisi');
        if ($opt['item'] !== '' && strcasecmp($nama, (string) $opt['item']) === 0) {
            $baris[] = ['grup' => $gk, 'idx' => $i, 'nama' => $nama, 'biaya' => (float) ($it['biaya'] ?? 0)];
        }
    }
}
echo "\n";

if ($opt['item'] === '') {
    echo "  Sebutkan itemnya untuk melengkapi hitungannya, mis.:\n";
    echo "    php scripts/set_hitungan_fu.php --doc=$docId --item=\"Mobil\" --tarif=1000000 --titik=5 --hari=5\n\n";
    exit(0);
}
if (!$baris) { fwrite(STDERR, "Item \"{$opt['item']}\" tidak ada / tidak dicentang di dokumen ini.\n"); exit(2); }

$tarif = (float) preg_replace('/\D/', '', (string) $opt['tarif']);
$titik = (int) preg_replace('/\D/', '', (string) $opt['titik']);
$hari  = (int) preg_replace('/\D/', '', (string) $opt['hari']);
if ($tarif <= 0 || $titik <= 0 || $hari <= 0) {
    fwrite(STDERR, "Isi ketiganya: --tarif= --titik= --hari=\n");
    exit(2);
}

$nBaris = count($baris);
$utama  = $baris[0];

// ── Penjagaan: uangnya tidak boleh bergeser ─────────────────────────────────
// Satu baris  → nominalnya TOTAL item, jadi harus sama dengan harga×titik×hari.
// Banyak baris → tiap nominalnya HARGA SATUAN, jadi harus sama dengan harga,
//                dan jumlah barisnya harus sama dengan jumlah titiknya.
if ($nBaris === 1) {
    $hasil = $tarif * $titik * $hari;
    if (abs($hasil - $utama['biaya']) > 0.5) {
        fwrite(STDERR, "BERHENTI: hitungannya (" . $rp($hasil) . ") tidak sama dengan nominal yang sudah\n"
                     . "tersimpan (" . $rp($utama['biaya']) . "). Skrip ini hanya menambahkan KETERANGAN;\n"
                     . "mengubah nominal dokumen yang sudah berjalan harus lewat alur revisi.\n");
        exit(2);
    }
} else {
    if ($pecah) {
        fwrite(STDERR, "BERHENTI: \"{$utama['nama']}\" sudah terpecah jadi $nBaris baris titik.\n"
                     . "--pecah hanya untuk baris borongan yang masih satu. Untuk sekadar mengubah\n"
                     . "atau menghapus tulisan lokasinya, jalankan tanpa --pecah dan pakai --lokasi=.\n");
        exit(2);
    }
    if ($nBaris !== $titik) {
        fwrite(STDERR, "BERHENTI: barisnya ada $nBaris, sedangkan --titik=$titik.\n");
        exit(2);
    }
    foreach ($baris as $b) {
        if (abs($tarif - $b['biaya']) > 0.5) {
            fwrite(STDERR, "BERHENTI: --tarif=" . $rp($tarif) . " tidak sama dengan nominal baris titiknya ("
                         . $rp($b['biaya']) . ").\n");
            exit(2);
        }
    }
}

printf("  AKAN DIISI\n    %s  →  %s × %d titik × %d hari = %s\n",
    $utama['nama'], $rp($tarif), $titik, $hari, $rp($tarif * $titik * $hari));
if ($pecah)  printf("    --pecah : satu baris borongan diganti %d baris titik @ %s /Titik/Hari\n", $titik, $rp($tarif));
if ($aturLok) printf("    --lokasi: %s\n", $lokBaru !== '' ? '"' . $lokBaru . '"' : '(dikosongkan — tercetak "Titik ke-1 … ke-' . $titik . '")');
echo "\n";

if (!$terapkan) {
    echo "  SIMULASI — belum ada yang diubah. Tambahkan --terapkan untuk menyimpan.\n\n";
    exit(1);
}

// ── Menulis ─────────────────────────────────────────────────────────────────
if ($pecah) {
    // Hitungan tetap menempel di baris PERTAMA: cetakan membacanya dari situ,
    // dan tanpa itu jumlah harinya jatuh ke masa sewa dokumen — padahal item
    // ini bisa dipakai lebih pendek (test drive 5 hari di dalam sewa 7 hari).
    $asal = $det[$utama['grup']][$utama['idx']];
    $baru = [];
    for ($k = 0; $k < $titik; $k++) {
        $b = $asal;
        $b['checked'] = 1;
        $b['biaya']   = $tarif;            // harga SATUAN, bukan total lagi
        $b['satuan']  = '/Titik/Hari';
        if ($aturLok) $b['lokasi'] = $lokBaru;
        $b['tarif'] = $k === 0 ? $tarif : '';
        $b['titik'] = $k === 0 ? $titik : '';
        $b['hari']  = $k === 0 ? $hari  : '';
        $baru[] = $b;
    }
    array_splice($det[$utama['grup']], $utama['idx'], 1, $baru);
} else {
    foreach ($baris as $k => $b) {
        $r = &$det[$b['grup']][$b['idx']];
        $r['tarif'] = $k === 0 ? $tarif : '';
        $r['titik'] = $k === 0 ? $titik : '';
        $r['hari']  = $k === 0 ? $hari  : '';
        if ($aturLok) $r['lokasi'] = $lokBaru;
        unset($r);
    }
}

$pdo->prepare('UPDATE skp_documents SET detail_json = ? WHERE id = ?')
    ->execute([json_encode($det, JSON_UNESCAPED_UNICODE), $docId]);

// Dibaca ULANG dari basis data — yang dilaporkan hasil akhirnya, bukan niatnya.
$st->execute([$docId]);
$ulang = json_decode((string) ($st->fetch()['detail_json'] ?? ''), true);
$cek = $ulang[$utama['grup']][$utama['idx']] ?? [];
$n = 0; $lok = '';
foreach ((array) ($ulang[$utama['grup']] ?? []) as $r) {
    if (strcasecmp($namaItem($r), $utama['nama']) === 0 && !empty($r['checked'])) {
        $n++; $lok = trim((string) ($r['lokasi'] ?? ''));
    }
}
printf("  TERSIMPAN — dibaca ulang: %s × %d titik × %d hari · %d baris titik · lokasi %s\n",
    $rp($cek['tarif'] ?? 0), (int) ($cek['titik'] ?? 0), (int) ($cek['hari'] ?? 0), $n,
    $lok !== '' ? '"' . $lok . '"' : '(kosong)');
echo "  Buka pratinjau suratnya untuk memastikan.\n\n";
