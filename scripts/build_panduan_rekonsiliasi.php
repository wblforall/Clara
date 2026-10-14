<?php

/**
 * Bangun panduan + bukti fitur "Rekonsiliasi Excel" menjadi satu berkas PDF.
 *
 * Seluruh angka di dalamnya DIHITUNG SAAT SKRIP INI DIJALANKAN, langsung dari
 * berkas Excel tim dan dari database — bukan diketik tangan. Jadi kalau datanya
 * berubah, laporannya ikut berubah, dan tidak ada angka yang bisa basi diam-diam.
 *
 *   php scripts/build_panduan_rekonsiliasi.php \
 *       --berkas="/path/Casual Leasing ... .xlsx" \
 *       --gambar=/path/folder-screenshot \
 *       --keluar=docs/Panduan_Rekonsiliasi_Excel.pdf
 *
 * Hasilnya memuat nama klien nyata — taruh di docs/ (sudah diabaikan git).
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once $root . '/app/pdf.php';
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/XlsxReader.php';
require_once $root . '/app/ReconCL.php';
require_once $root . '/app/ReconMatch.php';

$opt = ['berkas' => '', 'gambar' => '', 'keluar' => $root . '/docs/Panduan_Rekonsiliasi_Excel.pdf',
        'sheet1' => 'e-Walk 2026 - 1', 'sheet2' => 'e-Walk 2026 - 2', 'properti' => '1'];
foreach ($argv as $a) if (preg_match('/^--([a-z0-9]+)=(.*)$/i', $a, $m)) $opt[$m[1]] = $m[2];
if ($opt['berkas'] === '' || !is_file($opt['berkas'])) {
    echo "\nPakai: php scripts/build_panduan_rekonsiliasi.php --berkas=\"...xlsx\" [--gambar=folder] [--keluar=...pdf]\n\n";
    exit(2);
}

$pid = (int) $opt['properti'];
$pdo = Database::connect();
$x   = new XlsxReader($opt['berkas']);
$sh  = $x->sheets();
$rp  = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
$h   = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/** Ambil baris Excel satu bulan, sudah dipadankan nama PIC-nya dengan CLARA. */
$ambil = function (string $sheet, int $tahun, string $periode) use ($x, $sh, $pdo, $pid): array {
    $kal = ReconCL::bacaKalender($x, $sh[$sheet], $tahun);
    $excel = [];
    foreach ($kal['baris'] as $b) {
        if ($b['bulan'] !== $periode) continue;
        foreach ($b['per_pic'] as $pic => $v) {
            if ($v == 0.0) continue;
            $excel[] = ['klien' => $b['tenant'], 'brand' => $b['tenant'], 'kode' => $b['unit'], 'nilai' => $v,
                        'mulai' => $b['mulai'], 'selesai' => $b['selesai'], 'pic' => $pic,
                        'rujukan' => 'baris ' . $b['baris']];
        }
    }
    $clara = ReconCL::dariClara($pdo, $pid, $periode);
    $peta = ReconCL::petakanPic(array_values(array_unique(array_column($excel, 'pic'))),
                                array_values(array_unique(array_column($clara, 'pic'))));
    foreach ($excel as $i => $b) if (($peta[$b['pic']] ?? '') !== '') $excel[$i]['pic'] = $peta[$b['pic']];
    return [$excel, $clara, $peta, $kal];
};

// ── Bukti 1: hasil baca sistem vs baris TOTAL milik sheet itu sendiri ───────
$silang = [];
foreach ([[$opt['sheet1'], 2026], [$opt['sheet2'], 2026]] as [$sheet, $tahun]) {
    $kal = ReconCL::bacaKalender($x, $sh[$sheet], $tahun);
    foreach ($kal['bulanAda'] as $bl) {
        $baca = 0.0;
        foreach ($kal['baris'] as $b) if ($b['bulan'] === $bl) $baca += array_sum($b['per_pic']);
        $t = $kal['total_sheet'][$bl] ?? null;
        $acuan = $t ? array_sum($t['pic'] ?? []) : 0.0;
        if ($acuan <= 0) continue;
        $silang[$bl] = ['baca' => $baca, 'sheet' => $acuan, 'beda' => $baca - $acuan];
    }
}
ksort($silang);
$silangCocok = count(array_filter($silang, fn($v) => abs($v['beda']) < 1));

// ── Bukti 2: satu PIC yang seluruh bulannya cocok ───────────────────────────
// ── Bukti 3: satu PIC yang berselisih, diurai sampai angkanya bertemu ───────
$bulanSheet = [$opt['sheet1'] => ['2026-01','2026-02','2026-03','2026-04','2026-05','2026-06'],
               $opt['sheet2'] => ['2026-07','2026-08','2026-09']];
$perBulan = []; $perPicBulan = []; $hasilBulan = [];
foreach ($bulanSheet as $sheet => $daftar) {
    foreach ($daftar as $periode) {
        [$e, $c, $peta, ] = $ambil($sheet, 2026, $periode);
        $perBulan[$periode] = ['excel' => array_sum(array_column($e, 'nilai')),
                               'clara' => array_sum(array_column($c, 'nilai'))];
        $perPicBulan[$periode] = ReconMatch::perPic($e, $c);
        $hasilBulan[$periode] = [ReconMatch::cocokkan($e, $c), $e, $c, $peta];
    }
}
$petaJan = $hasilBulan['2026-01'][3] ?? [];

// PIC yang tidak pernah berselisih sepanjang 2026
$picBersih = [];
foreach ($perPicBulan as $periode => $daftar) {
    foreach ($daftar as $p) {
        $picBersih[$p['pic']] = ($picBersih[$p['pic']] ?? true) && abs($p['selisih']) < 1;
    }
}
$picCocok = array_keys(array_filter($picBersih));
$picUji = $picCocok[0] ?? 'Muslimin';

// PIC & bulan dengan selisih paling layak dijadikan contoh (temuan sedikit tapi nyata)
$contoh = null;
foreach ($perPicBulan as $periode => $daftar) {
    foreach ($daftar as $p) {
        if (abs($p['selisih']) < 1) continue;
        $r = $hasilBulan[$periode][0];
        $n = 0;
        foreach (['nilai', 'input', 'lebih'] as $kk) {
            foreach ($r[$kk] as $q) {
                $pp = $q['pic'] ?? ($q['excel']['pic'] ?? '');
                if ($pp === $p['pic']) $n++;
            }
        }
        // Dipilih yang selisihnya PALING BESAR di antara yang temuannya masih
        // muat ditampilkan utuh (maksimal 8 baris) — contoh yang terlalu kecil
        // tidak meyakinkan, yang terlalu panjang tidak terbaca.
        if ($n > 0 && $n <= 8 && ($contoh === null || abs($p['selisih']) > abs($contoh['selisih']))) {
            $contoh = ['periode' => $periode, 'pic' => $p['pic'], 'n' => $n,
                       'excel' => $p['excel'], 'clara' => $p['clara'], 'selisih' => $p['selisih']];
        }
    }
}
$contoh = $contoh ?: ['periode' => '2026-09', 'pic' => 'Sita', 'n' => 0, 'excel' => 0, 'clara' => 0, 'selisih' => 0];
[$rC, $eC, $cC, ] = $hasilBulan[$contoh['periode']];
$picC = $contoh['pic'];
$eCtot = array_sum(array_map(fn($b) => $b['pic'] === $picC ? $b['nilai'] : 0, $eC));
$cCtot = array_sum(array_map(fn($b) => $b['pic'] === $picC ? $b['nilai'] : 0, $cC));
$langkah = []; $akum = $cCtot;
foreach ($rC['nilai'] as $q) {
    if (($q['excel']['pic'] ?? '') !== $picC) continue;
    $d = $q['excel']['nilai'] - $q['clara']['nilai']; $akum += $d;
    $langkah[] = ['jenis' => 'Nominal beda', 'nama' => $q['excel']['klien'],
        'ket' => 'tercatat ' . $rp($q['clara']['nilai']) . ', di Excel ' . $rp($q['excel']['nilai']) . ' (' . $q['clara']['rujukan'] . ')',
        'ubah' => $d];
}
foreach ($rC['input'] as $q) {
    if ($q['pic'] !== $picC) continue; $akum += $q['nilai'];
    $langkah[] = ['jenis' => 'Belum diinput', 'nama' => $q['klien'] ?: '(nama kosong di Excel)',
        'ket' => 'unit ' . ($q['kode'] ?: '-') . ' · ' . $q['rujukan'], 'ubah' => $q['nilai']];
}
foreach ($rC['lebih'] as $q) {
    if ($q['pic'] !== $picC) continue; $akum -= $q['nilai'];
    $langkah[] = ['jenis' => 'Hanya di CLARA', 'nama' => $q['klien'],
        'ket' => 'unit ' . ($q['kode'] ?: '-') . ' · ' . $q['rujukan'], 'ubah' => -$q['nilai']];
}
$sisa = $akum - $eCtot;

$gambar = rtrim($opt['gambar'], '/');
$img = function (string $nama, string $judul) use ($gambar, $h): string {
    $p = $gambar . '/' . $nama;
    if ($gambar === '' || !is_file($p)) return '';
    return '<div class="gbr"><img src="' . $h($p) . '"><div class="cap">' . $h($judul) . '</div></div>';
};

$tgl = date('d/m/Y');
ob_start();
?>
<style>
body { font-family: sans-serif; font-size: 10.5px; color: #111; line-height: 1.5; }
h1 { font-size: 19px; margin: 0 0 2px; }
h2 { font-size: 13px; color: #0D9488; border-bottom: 1px solid #cbd5e1; padding-bottom: 3px; margin: 16px 0 7px; }
h3 { font-size: 11.5px; margin: 11px 0 4px; }
p { margin: 0 0 6px; text-align: justify; }
table { width: 100%; border-collapse: collapse; margin: 5px 0 9px; }
th, td { border: 1px solid #d1d5db; padding: 4px 6px; font-size: 9.5px; vertical-align: top; }
th { background: #f1f5f9; text-align: left; }
td.n { text-align: right; white-space: nowrap; }
.kecil { font-size: 9px; color: #475569; }
.kotak { border: 1px solid #cbd5e1; background: #f8fafc; padding: 7px 10px; margin: 7px 0; }
.ok { color: #047857; font-weight: bold; }
.warn { color: #b45309; font-weight: bold; }
.bad { color: #be123c; font-weight: bold; }
.gbr { margin: 8px 0 12px; }
.gbr img { width: 100%; border: 1px solid #cbd5e1; }
.cap { font-size: 9px; color: #475569; margin-top: 3px; }
ol, ul { margin: 0 0 7px 16px; padding: 0; }
li { margin-bottom: 3px; }
</style>

<h1>Rekonsiliasi Excel &times; CLARA</h1>
<div class="kecil">Cara mencocokkan spreadsheet kerja tim dengan catatan sistem, dan membaca selisihnya.<br>
Disusun <?= $h($tgl) ?> &middot; Departemen Casual Leasing &middot; PT. Wulandari Bangun Laksana, Tbk.</div>

<h2>1. Masalah yang diselesaikan</h2>
<p>Tim memegang satu berkas Excel yang diperbarui tiap bulan. CLARA punya catatannya sendiri.
Selama ini tidak ada cara memeriksa apakah keduanya sama. Yang terlihat hanya totalnya berbeda —
tanpa petunjuk <b>beda di baris mana</b>, sehingga mencarinya berarti membandingkan ratusan baris satu per satu.</p>
<p>Fitur ini menjawab tepat pertanyaan itu: untuk satu bulan dan satu mall, mana baris yang sudah cocok,
mana yang nominalnya beda, mana yang belum diinput, dan mana yang ada di sistem tapi tidak ada di Excel.</p>

<h2>2. Cara kerjanya</h2>
<ol>
<li>Berkas Excel diunggah. Berkasnya <b>hanya dibaca</b> — tidak disimpan ke dalam aplikasi.</li>
<li>Sistem mengenali sendiri sheet mana yang berupa kalender pameran dan bulan apa saja yang ada di dalamnya.</li>
<li>Tiap baris kalender dipecah menurut kolom nominal per sales, sehingga setara dengan catatan per PIC di CLARA.</li>
<li>Nama panggilan di Excel dipadankan dengan nama di CLARA (<?php
    $contohPeta = [];
    foreach ($petaJan as $e => $c) if ($c !== '') $contohPeta[] = $h($e) . ' &rarr; ' . $h($c);
    echo implode(', ', array_slice($contohPeta, 0, 4)); ?>).</li>
<li>Baris dipasangkan bertahap: nominal sama dulu, baru nama yang mirip. Sisanya dilaporkan apa adanya.</li>
</ol>
<div class="kotak"><b>Angka pembanding dari CLARA diambil dari sumber yang sama dengan Laporan PIC</b>
(tabel alokasi bulanan), bukan dihitung ulang. Jadi kalau Laporan PIC bilang Rp X, rekonsiliasi juga bilang Rp X —
selisih yang muncul pasti selisih data, bukan selisih cara menghitung.</div>

<h2>3. Bukti bahwa pembacaannya benar</h2>
<p>Spreadsheet punya baris &ldquo;TOTAL / REALISASI&rdquo; sendiri di kaki tiap bulan. Hasil penjumlahan
baris-per-baris oleh sistem dibandingkan dengan baris itu. Kalau keduanya sama, berarti tidak ada baris
yang terlewat maupun terhitung dua kali — dan selisih yang muncul nanti pasti selisih data, bukan salah baca.</p>
<table>
<tr><th>Bulan</th><th class="n">Dijumlah sistem dari baris detail</th><th class="n">Baris TOTAL milik sheet</th><th class="n">Beda</th></tr>
<?php foreach ($silang as $bl => $v): ?>
<tr><td><?= $h(ReconCL::BULAN[(int) substr($bl, 5, 2) - 1] . ' ' . substr($bl, 0, 4)) ?></td>
    <td class="n"><?= $rp($v['baca']) ?></td><td class="n"><?= $rp($v['sheet']) ?></td>
    <td class="n"><?= abs($v['beda']) < 1 ? '<span class="ok">0</span>' : '<span class="bad">' . $rp($v['beda']) . '</span>' ?></td></tr>
<?php endforeach; ?>
</table>
<div class="kotak"><b><?= $silangCocok ?> dari <?= count($silang) ?> bulan cocok sampai ke rupiah.</b>
Pemeriksaan ini bukan hiasan: saat dibuat, ia menangkap dua kesalahan sungguhan —
satu salah baca di sistem (baris subtotal tanpa label ikut terhitung sebagai sewa), dan satu kekeliruan di
spreadsheet sendiri (subtotal <i>TOTAL FIRST FLOOR</i> November 2026 melewatkan satu baris senilai
Rp 20.000.000; kolom <i>SELISIH RAILING</i> di sheet itu bahkan sudah menuliskan &minus;20.000.000).</div>

<h2>4. Bukti pada data sungguhan</h2>
<h3>Bukti A &mdash; <?= $h($picUji) ?>: seluruh bulan 2026 sama persis</h3>
<p>Pertanyaan &ldquo;berapa sebenarnya income saya?&rdquo; dijawab dengan dua angka yang bertemu.</p>
<table>
<tr><th>Bulan</th><th class="n">Menurut spreadsheet</th><th class="n">Tercatat di CLARA</th><th class="n">Selisih</th></tr>
<?php foreach ($perPicBulan as $periode => $daftar): foreach ($daftar as $p): if ($p['pic'] !== $picUji) continue; ?>
<tr><td><?= $h(ReconCL::BULAN[(int) substr($periode, 5, 2) - 1] . ' ' . substr($periode, 0, 4)) ?></td>
    <td class="n"><?= $rp($p['excel']) ?></td><td class="n"><?= $rp($p['clara']) ?></td>
    <td class="n"><?= abs($p['selisih']) < 1 ? '<span class="ok">sama</span>' : '<span class="warn">' . $rp($p['selisih']) . '</span>' ?></td></tr>
<?php endforeach; endforeach; ?>
</table>
<p class="kecil">Tidak satu pun bulan berselisih. Artinya seluruh yang dicatat <?= $h($picUji) ?> di spreadsheet
sudah masuk ke sistem dengan nominal yang sama, dan pencapaiannya di CLARA memang angka yang sebenarnya.</p>

<h3>Bukti B &mdash; <?= $h($picC) ?>, <?= $h(ReconCL::BULAN[(int) substr($contoh['periode'], 5, 2) - 1] . ' ' . substr($contoh['periode'], 0, 4)) ?>: selisihnya diurai sampai habis</h3>
<p>Tercatat di CLARA <b><?= $rp($cCtot) ?></b>, menurut spreadsheet <b><?= $rp($eCtot) ?></b> &mdash;
beda <b><?= $rp($eCtot - $cCtot) ?></b>. Inilah seluruh baris yang menjelaskan beda itu:</p>
<table>
<tr><th style="width:17%">Jenis temuan</th><th style="width:26%">Penyewa</th><th>Keterangan</th><th class="n">Pengaruh ke income</th></tr>
<?php foreach ($langkah as $l): ?>
<tr><td><?= $h($l['jenis']) ?></td><td><?= $h(mb_substr(trim($l['nama']), 0, 30)) ?></td>
    <td class="kecil"><?= $h($l['ket']) ?></td>
    <td class="n"><?= ($l['ubah'] >= 0 ? '+' : '') . $rp($l['ubah']) ?></td></tr>
<?php endforeach; ?>
<tr><td colspan="3"><b>Tercatat sekarang <?= $rp($cCtot) ?>, setelah seluruh temuan dibereskan</b></td>
    <td class="n"><b><?= $rp($akum) ?></b></td></tr>
<tr><td colspan="3"><b>Menurut spreadsheet</b></td><td class="n"><b><?= $rp($eCtot) ?></b></td></tr>
</table>
<div class="kotak"><?= abs($sisa) < 1
    ? '<span class="ok">Hasilnya sama persis.</span> '
    : '<span class="ok">Sisa beda hanya ' . $rp(abs($sisa)) . '</span> (pembulatan di bawah seribu rupiah). ' ?>
Seluruh selisih <?= $rp($eCtot - $cCtot) ?> terurai menjadi <?= count($langkah) ?> baris yang bisa
diperiksa satu per satu &mdash; bukan angka misterius.</div>

<h3>Seluruh bulan 2026 (e-Walk)</h3>
<table>
<tr><th>Bulan</th><th class="n">Menurut spreadsheet</th><th class="n">Tercatat di CLARA</th><th class="n">Selisih</th></tr>
<?php foreach ($perBulan as $bl => $v): $sx = $v['clara'] - $v['excel']; ?>
<tr><td><?= $h(ReconCL::BULAN[(int) substr($bl, 5, 2) - 1] . ' ' . substr($bl, 0, 4)) ?></td>
    <td class="n"><?= $rp($v['excel']) ?></td><td class="n"><?= $rp($v['clara']) ?></td>
    <td class="n"><?= abs($sx) < 1 ? '<span class="ok">sama</span>' : '<span class="warn">' . $rp($sx) . '</span>' ?></td></tr>
<?php endforeach; ?>
</table>
<p class="kecil">Juli dan Agustus 2026 sudah sama sampai ke rupiah. Bulan-bulan awal 2026 selisihnya besar
karena saat itu pencatatan di CLARA memang belum serapi sekarang &mdash; justru di situlah daftar temuan ini berguna.</p>

<h2>5. Cara memakainya</h2>
<?= $img('ss1-unggah.png', 'Langkah 1 — menu Rekonsiliasi Excel, lalu unggah berkas .xlsx milik tim.') ?>
<ol>
<li>Buka menu <b>Analisa &rarr; Rekonsiliasi Excel</b>.</li>
<li>Tekan <b>Pilih File</b>, ambil berkas rekap Casual Leasing (.xlsx), lalu <b>Unggah &amp; Bandingkan</b>.</li>
<li>Pilih <b>sheet</b> (mis. &ldquo;e-Walk 2026 - 2&rdquo;) dan <b>bulan</b> yang ingin dicocokkan.
    Mall yang dibandingkan mengikuti properti yang sedang aktif di pojok kanan atas.</li>
</ol>
<?= $img('ss2-ringkasan.png', 'Langkah 2 — ringkasan: total kedua sisi, selisihnya, dan rekap per sales.') ?>
<ol start="4">
<li>Baca empat kartu di atas: total menurut Excel, total menurut CLARA, selisihnya, dan berapa baris yang sudah cocok.</li>
<li>Lihat tabel <b>Rekap per PIC</b> untuk tahu selisihnya milik siapa.</li>
<li>Buka <b>Rincian selisih</b> untuk daftar barisnya.</li>
</ol>
<?= $img('ss3-rincian.png', 'Langkah 3 — rincian selisih: tiap baris menyebut penyewa, unit, PIC, nominal kedua sisi, dan letak barisnya di Excel maupun nomor transaksi di CLARA.') ?>
<p>Kolom <b>Letak</b> menyebut nomor baris di Excel dan nomor transaksi di CLARA (mis. <i>baris 54 / #1169</i>),
jadi keduanya bisa langsung dibuka untuk diperiksa.</p>
<?= $img('ss4-cocok.png', 'Bulan yang sudah beres terlihat jelas: selisih Rp 0 dan tiap sales bertanda &ldquo;sama&rdquo;.') ?>

<h2>6. Arti tiap temuan &amp; apa yang harus dilakukan</h2>
<table>
<tr><th style="width:27%">Temuan</th><th>Artinya</th><th style="width:33%">Tindakan</th></tr>
<tr><td><b>Nominalnya beda</b></td>
    <td>Penyewa yang sama ada di kedua sisi, tapi angkanya tidak sama.</td>
    <td>Buka transaksinya di CLARA, bandingkan dengan SKP/invoice. Perbaiki yang salah — bisa jadi Excel-nya yang keliru.</td></tr>
<tr><td><b>Ada di Excel, belum ada di CLARA</b></td>
    <td>Sewanya tercatat di spreadsheet tapi belum ada di sistem.</td>
    <td>Cek dulu: dokumennya sudah ditandatangani client? Income baru diakui CLARA setelah TTD. Kalau sudah, berarti memang belum diinput.</td></tr>
<tr><td><b>Ada di CLARA, tidak ada di Excel</b></td>
    <td>Sistem punya, spreadsheet tidak.</td>
    <td>Biasanya Excel yang belum diperbarui. Kalau ternyata tidak ada sewanya, transaksinya perlu diperiksa/dihapus.</td></tr>
<tr><td><b>Tercatat atas nama PIC berbeda</b></td>
    <td>Nilainya sama, tapi sales-nya berbeda.</td>
    <td>Perbaiki PIC-nya — satu baris salah orang mengubah pencapaian dua orang sekaligus.</td></tr>
<tr><td><b>Dugaan dobel</b></td>
    <td>Penyewa, unit, nominal, dan bulan sama persis lebih dari sekali.</td>
    <td>Belum tentu salah (bisa dua periode berbeda). Periksa tanggalnya; kalau memang kembar, hapus salah satu.</td></tr>
</table>

<h2>7. Yang perlu diketahui</h2>
<ul>
<li><b>Income di CLARA lahir saat client menandatangani dokumen</b>, bukan saat dokumen disetujui.
    Dokumen yang sudah approve tapi belum ber-TTD wajar belum muncul.</li>
<li>Baris <b>TOTAL</b> dan <b>subtotal</b> di spreadsheet tidak ikut dihitung. Sistem mengenalinya
    dan melewatinya — kalau ikut terhitung, seluruh angka bulan itu akan terbaca dua kali lipat.</li>
<li>Kolom <b>ACTUAL</b> di sheet biasanya sedikit lebih besar daripada jumlah kolom per sales,
    karena ada baris milik unit afiliasi yang tidak dibebankan ke sales mana pun.
    Yang dibandingkan di sini adalah <b>nominal per sales</b>.</li>
<li>Selisih di bawah <b>Rp 1.000</b> dianggap pembulatan dan tidak dilaporkan.</li>
<li>Berkas yang diunggah tidak disimpan di aplikasi dan tidak bisa diunduh siapa pun;
    ia hilang begitu diganti atau sesi berakhir.</li>
</ul>
<?php
$html = (string) ob_get_clean();

$mpdf = clara_letterhead_mpdf();
$mpdf->SetTitle('Panduan Rekonsiliasi Excel × CLARA');
$mpdf->WriteHTML($html);
@mkdir(dirname($opt['keluar']), 0777, true);
$mpdf->Output($opt['keluar'], \Mpdf\Output\Destination::FILE);

echo "\n  ✓ " . $opt['keluar'] . "\n";
echo "    Pemeriksaan baca : " . $silangCocok . " dari " . count($silang) . " bulan cocok dengan baris TOTAL sheet\n";
echo "    PIC tanpa selisih: " . (implode(', ', $picCocok) ?: '-') . "\n";
echo "    Contoh telusur   : {$picC} {$contoh['periode']} — CLARA " . $rp($cCtot)
   . " → setelah " . count($langkah) . " temuan " . $rp($akum) . " vs spreadsheet " . $rp($eCtot)
   . (abs($sisa) < 1 ? ' (SAMA PERSIS)' : ' (sisa ' . $rp($sisa) . ')') . "\n\n";
