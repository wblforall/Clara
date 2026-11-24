<?php
/**
 * Mengisi kolom "Hitungan" (harga satuan / jumlah titik / jumlah hari) sebuah
 * item pada Form Utilities yang SUDAH TERLANJUR disubmit atau disetujui.
 *
 * Latar: surat FU sekarang mencetak asal angkanya — "Rp 1.000.000 x 5 titik x
 * 5 hari = Rp 25.000.000". Ketiga angka itu baru ada sejak kolom Hitungan
 * dibuat; dokumen yang diisi sebelumnya hanya menyimpan nominal jadinya,
 * sehingga barisnya tidak bisa diuraikan. Untuk item yang tersimpan sebagai
 * beberapa baris titik bersatuan "/Titik/Hari" cetakannya sudah menurunkannya
 * sendiri; yang TIDAK bisa diturunkan adalah item borongan seperti
 * "Rp 25.000.000 /5 Titik/Hari" — harga satuan dan lama pemakaiannya tidak
 * tersimpan di mana pun, dan menebaknya berarti mencetak angka karangan.
 *
 * Mengisinya lewat layar menuntut dokumennya ditolak dulu supaya bisa diedit,
 * lalu ditempuh ulang seluruh persetujuannya — padahal yang ditambahkan di
 * sini TIDAK mengubah satu rupiah pun: nilai item, Total Biaya Sewa, PPN, dan
 * Grand Total tetap apa adanya. Yang berubah hanya keterangan di suratnya.
 *
 * Karena itu skrip ini MENOLAK menyimpan bila harga x titik x hari tidak sama
 * dengan nominal yang sudah tersimpan — supaya tidak ada dokumen yang
 * nominalnya diam-diam bergeser lewat jalan belakang ini.
 *
 * Tanpa --terapkan tidak ada satu baris pun yang diubah.
 *
 * Pakai:
 *   php scripts/set_hitungan_fu.php --doc=469
 *   php scripts/set_hitungan_fu.php --doc=469 --item="Mobil" --tarif=1000000 --titik=5 --hari=5
 *   php scripts/set_hitungan_fu.php --doc=469 --item="Mobil" --tarif=1000000 --titik=5 --hari=5 --terapkan
 *
 * --pecah  : sekalian memecah baris borongan itu menjadi beberapa baris titik,
 *            masing-masing berisi HARGA SATUAN dengan satuan "/Titik/Hari" —
 *            bentuk yang sama dengan item yang titiknya diisi satu per satu
 *            lewat tombol "+ titik lain". Daftar centang di surat lalu memuat
 *            "Mobil (5 titik)" beserta tiap titiknya, bukan satu angka gelondongan.
 *            Jumlah uangnya tetap: 5 x Rp 1.000.000 /titik/hari x 5 hari.
 *   php scripts/set_hitungan_fu.php --doc=469 --item="Mobil" --tarif=1000000 --titik=5 --hari=5 --pecah --lokasi="PJU 1" --terapkan
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';

$opt = array_merge(['doc' => '', 'item' => '', 'tarif' => '', 'titik' => '', 'hari' => '', 'lokasi' => ''],
    getopt('', ['doc:', 'item:', 'tarif:', 'titik:', 'hari:', 'lokasi:', 'terapkan', 'pecah']));
$bendera  = getopt('', ['terapkan', 'pecah']);
$terapkan = array_key_exists('terapkan', $bendera);
$pecah    = array_key_exists('pecah', $bendera);

$docId = (int) $opt['doc'];
if ($docId <= 0) {
    fwrite(STDERR, "Sebutkan dokumennya: --doc=469\n");
    exit(2);
}

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

$st = $pdo->prepare('SELECT id, skp_no, doc_type, status, start_date, end_date, total_amount, detail_json
                       FROM skp_documents WHERE id = ?');
$st->execute([$docId]);
$doc = $st->fetch();
if (!$doc)                        { fwrite(STDERR, "Dokumen $docId tidak ada.\n"); exit(2); }
if (($doc['doc_type'] ?? '') !== 'fu') { fwrite(STDERR, "Dokumen $docId bukan Form Utilities.\n"); exit(2); }

$det = json_decode((string) $doc['detail_json'], true);
if (!is_array($det)) { fwrite(STDERR, "Dokumen $docId belum punya isian detail.\n"); exit(2); }

$hari = 0;
if ($doc['start_date'] && $doc['end_date']) {
    $a = strtotime((string) $doc['start_date']); $b = strtotime((string) $doc['end_date']);
    if ($a && $b && $b >= $a) $hari = (int) round(($b - $a) / 86400) + 1;
}

echo "\nFORM UTILITIES #{$doc['id']}  " . ($doc['skp_no'] ?: '(nomor belum terbit)')
   . "  status \"{$doc['status']}\"\n";
echo str_repeat('=', 78) . "\n";
printf("  Masa sewa : %s s/d %s (%d hari)\n", $doc['start_date'], $doc['end_date'], $hari);
printf("  Nilai     : %s\n\n", $rp($doc['total_amount']));

// ── Daftar item yang dicentang, beserta keadaan hitungannya ─────────────────
$grup = ['utilities' => 'Utilities', 'media_promo' => 'Media Promo', 'parkir' => 'Parkir (Test Drive)'];
$ketemu = null;
echo "  ITEM YANG DICENTANG\n";
foreach ($grup as $gk => $gl) {
    foreach ((array) ($det[$gk] ?? []) as $i => $it) {
        if (empty($it['checked'])) continue;
        $nama  = trim((string) ($it['ket'] ?? '')) !== '' ? (string) $it['ket'] : (string) ($it['nama'] ?? '');
        $t = (float) ($it['tarif'] ?? 0); $n = (int) ($it['titik'] ?? 0); $d = (int) ($it['hari'] ?? 0);
        $sudah = $t > 0 && $n > 0 && $d > 0;
        printf("    [%s] %-22s %-16s satuan \"%s\"%s\n", $gl, $nama, $rp($it['biaya'] ?? 0),
            (string) ($it['satuan'] ?? ''),
            $sudah ? sprintf('  → %s × %d titik × %d hari', $rp($t), $n, $d) : '  → hitungan BELUM diisi');
        if ($opt['item'] !== '' && strcasecmp($nama, (string) $opt['item']) === 0) {
            if ($ketemu === null) {
                $ketemu = ['grup' => $gk, 'idx' => $i, 'nama' => $nama,
                           'biaya' => (float) ($it['biaya'] ?? 0),
                           'lokasi' => (string) ($it['lokasi'] ?? ''), 'baris' => 1];
            } else {
                $ketemu['baris']++;
            }
        }
    }
}
echo "\n";

if ($opt['item'] === '') {
    echo "  Sebutkan itemnya untuk mengisi hitungannya, mis.:\n";
    echo "    php scripts/set_hitungan_fu.php --doc=$docId --item=\"Mobil\" --tarif=1000000 --titik=5 --hari=5\n\n";
    exit(0);
}
if ($ketemu === null) {
    fwrite(STDERR, "Item \"{$opt['item']}\" tidak ada / tidak dicentang di dokumen ini.\n");
    exit(2);
}

$tarif = (float) preg_replace('/\D/', '', (string) $opt['tarif']);
$titik = (int) preg_replace('/\D/', '', (string) $opt['titik']);
$hariP = (int) preg_replace('/\D/', '', (string) $opt['hari']);
if ($tarif <= 0 || $titik <= 0 || $hariP <= 0) {
    fwrite(STDERR, "Isi ketiganya: --tarif= --titik= --hari=\n");
    exit(2);
}

$hasil = $tarif * $titik * $hariP;
printf("  AKAN DIISI\n    %s  →  %s × %d titik × %d hari = %s\n",
    $ketemu['nama'], $rp($tarif), $titik, $hariP, $rp($hasil));
printf("    nominal tersimpan sekarang: %s\n\n", $rp($ketemu['biaya']));

if (abs($hasil - $ketemu['biaya']) > 0.5) {
    fwrite(STDERR, "BERHENTI: hitungannya (" . $rp($hasil) . ") tidak sama dengan nominal yang\n"
                 . "sudah tersimpan (" . $rp($ketemu['biaya']) . "). Skrip ini hanya menambahkan\n"
                 . "KETERANGAN; mengubah nominal dokumen yang sudah berjalan harus lewat alur\n"
                 . "revisi supaya ikut diperiksa ulang.\n");
    exit(2);
}

if ($pecah) {
    if ((int) $ketemu['baris'] !== 1) {
        fwrite(STDERR, "BERHENTI: --pecah hanya untuk item yang masih SATU baris borongan;\n"
                     . "\"{$ketemu['nama']}\" sudah punya {$ketemu['baris']} baris.\n");
        exit(2);
    }
    printf("    --pecah: baris borongan diganti %d baris titik @ %s /Titik/Hari%s\n",
        $titik, $rp($tarif),
        trim((string) $opt['lokasi']) !== '' ? ', lokasi "' . trim((string) $opt['lokasi']) . '"' : '');
    echo "\n";
}

if (!$terapkan) {
    echo "  SIMULASI — belum ada yang diubah. Tambahkan --terapkan untuk menyimpan.\n\n";
    exit(1);
}

$det[$ketemu['grup']][$ketemu['idx']]['tarif'] = $tarif;
$det[$ketemu['grup']][$ketemu['idx']]['titik'] = $titik;
$det[$ketemu['grup']][$ketemu['idx']]['hari']  = $hariP;

if ($pecah) {
    // Baris borongan diganti SEJUMLAH TITIK baris, masing-masing berisi harga
    // satuannya. Hitungan (tarif/titik/hari) tetap menempel di baris pertama —
    // cetakan membacanya dari situ, dan tanpa itu jumlah harinya akan jatuh ke
    // masa sewa dokumen (7 hari) padahal pemakaiannya cuma 5.
    $asal = $det[$ketemu['grup']][$ketemu['idx']];
    $lok  = trim((string) $opt['lokasi']) !== '' ? trim((string) $opt['lokasi']) : (string) $ketemu['lokasi'];
    $baru = [];
    for ($k = 0; $k < $titik; $k++) {
        $baris = $asal;
        $baris['checked'] = 1;
        $baris['biaya']   = $tarif;          // harga SATUAN, bukan total lagi
        $baris['satuan']  = '/Titik/Hari';
        $baris['lokasi']  = $lok;
        if ($k > 0) { $baris['tarif'] = ''; $baris['titik'] = ''; $baris['hari'] = ''; }
        $baru[] = $baris;
    }
    array_splice($det[$ketemu['grup']], $ketemu['idx'], 1, $baru);
}

$pdo->prepare('UPDATE skp_documents SET detail_json = ? WHERE id = ?')
    ->execute([json_encode($det, JSON_UNESCAPED_UNICODE), $docId]);

// Dibaca ULANG dari basis data — yang dilaporkan hasil akhirnya, bukan niatnya.
$st->execute([$docId]);
$ulang = json_decode((string) ($st->fetch()['detail_json'] ?? ''), true);
$cek   = $ulang[$ketemu['grup']][$ketemu['idx']] ?? [];
$nBaris = 0;
foreach ((array) ($ulang[$ketemu['grup']] ?? []) as $r) {
    $nm = trim((string) ($r['ket'] ?? '')) !== '' ? (string) $r['ket'] : (string) ($r['nama'] ?? '');
    if (strcasecmp($nm, $ketemu['nama']) === 0 && !empty($r['checked'])) $nBaris++;
}
printf("  TERSIMPAN — dibaca ulang: %s × %d titik × %d hari  (%d baris titik)\n",
    $rp($cek['tarif'] ?? 0), (int) ($cek['titik'] ?? 0), (int) ($cek['hari'] ?? 0), $nBaris);
echo "  Buka pratinjau suratnya untuk memastikan barisnya tercetak.\n\n";
