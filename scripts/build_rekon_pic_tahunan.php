<?php
/**
 * Rekonsiliasi setahun per PIC — satu PDF untuk satu orang.
 *
 * Isinya bukan daftar selisih, melainkan PEMBUKTIAN. Tiap bulan yang belum
 * klop dipecah sampai tiap rupiahnya punya nama: baris mana yang belum
 * diinput, baris mana yang nilainya salah, baris mana yang ada di CLARA tapi
 * tidak di spreadsheet. Lalu ditutup satu tabel aritmetika — nilai CLARA
 * sekarang, ditambah/dikurangi tiap perbaikan, harus mendarat PERSIS di angka
 * spreadsheet. Kalau tidak mendarat, dokumen ini mengatakannya apa adanya
 * alih-alih menyembunyikannya; selisih yang tidak bisa dijelaskan adalah
 * temuan, bukan aib.
 *
 * Kenapa per orang dan bukan satu laporan besar: yang memperbaiki data adalah
 * PIC-nya sendiri. Laporan gabungan membuat tiap orang harus mencari bagiannya
 * dulu di antara pekerjaan tujuh orang lain, dan yang paling sering terjadi
 * sesudah itu adalah tidak jadi dikerjakan.
 *
 * Spreadsheet adalah PATOKAN. Skrip ini tidak pernah menilai spreadsheet
 * salah; yang dicari selalu "apa yang membuat CLARA berbeda dari patokannya".
 *
 * MURNI MEMBACA — tidak mengubah satu baris pun di database.
 *
 * Masukannya hasil dua skrip sebelumnya:
 *   1. scripts/banding/ekstrak_semua_pic.py  -> sheet_pic.json  (sisi spreadsheet)
 *   2. scripts/cek_semua_pic.php --json=...  -> ew|pc-YYYY-MM.json (hasil sandingan)
 *
 * Pakai:
 *   php scripts/build_rekon_pic_tahunan.php \
 *       --banding-dir=/path/banding --sheet=/path/sheet_pic.json \
 *       --bulan=2026-01..2026-10 --keluar-dir=/path/keluar \
 *       --sumber-db="dump 2 November 2026" --sumber-xlsx="unduhan 16 November 2026"
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/pdf.php';

$opt = array_merge([
    'banding-dir' => '', 'sheet' => '', 'bulan' => '2026-01..2026-10',
    'keluar-dir' => '', 'sumber-db' => '', 'sumber-xlsx' => '',
], getopt('', ['banding-dir:', 'sheet:', 'bulan:', 'keluar-dir:', 'sumber-db:', 'sumber-xlsx:']));

foreach (['banding-dir', 'sheet', 'keluar-dir'] as $w) {
    if ($opt[$w] === '') {
        fwrite(STDERR, "Pakai: php scripts/build_rekon_pic_tahunan.php --banding-dir=DIR --sheet=sheet_pic.json --keluar-dir=DIR\n");
        exit(2);
    }
}

/** "2026-01..2026-10" -> daftar bulan. */
function rk_bulan(string $spec): array
{
    if (!str_contains($spec, '..')) return array_map('trim', explode(',', $spec));
    [$a, $b] = explode('..', $spec, 2);
    $out = []; $t = strtotime($a . '-01');
    while (date('Y-m', $t) <= trim($b)) { $out[] = date('Y-m', $t); $t = strtotime('+1 month', $t); }
    return $out;
}

function rk_rp(float $v): string
{
    return ($v < 0 ? '-' : '') . 'Rp ' . number_format(abs($v), 0, ',', '.');
}

function rk_tanda(float $v): string
{
    return ($v > 0 ? '+' : ($v < 0 ? '-' : '')) . 'Rp ' . number_format(abs($v), 0, ',', '.');
}

function h2($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

const RK_NAMA_BULAN = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
];

function rk_bulan_teks(string $b): string
{
    [$th, $bl] = explode('-', $b);
    return (RK_NAMA_BULAN[$bl] ?? $bl) . ' ' . $th;
}

$bulanList = rk_bulan((string) $opt['bulan']);
$sheet = json_decode((string) file_get_contents((string) $opt['sheet']), true) ?: [];

/** Nilai satu baris spreadsheet, dicari dari keterangannya. */
$nilaiSs = function (string $pic, string $bln, ?string $ket) use ($sheet): ?float {
    if ($ket === null) return null;
    foreach ($sheet[$pic]['rincian'][$bln] ?? [] as $r) {
        if ((string) $r['ket'] === $ket) return (float) $r['nilai'];
    }
    return null;
};

// ── Kumpulkan seluruh hasil sandingan ────────────────────────────────────────
$data = [];      // [PIC][bulan] = hasil sandingan
$prop = [];      // [PIC] = nama properti
foreach ([['ew', 'e-Walk Simply FUNtastic'], ['pc', 'Pentacity Shopping Venue']] as [$pre, $namaProp]) {
    foreach ($bulanList as $b) {
        $f = rtrim((string) $opt['banding-dir'], '/') . "/$pre-$b.json";
        if (!is_readable($f)) continue;
        foreach ((json_decode((string) file_get_contents($f), true) ?: []) as $pic => $v) {
            $data[$pic][$b] = $v;
            $prop[$pic] = $namaProp;
        }
    }
}
if (!$data) { fwrite(STDERR, "Tidak ada berkas sandingan terbaca di {$opt['banding-dir']}.\n"); exit(1); }

// ── Susun daftar tindakan per bulan ──────────────────────────────────────────
/**
 * Tiap temuan diterjemahkan jadi satu TINDAKAN berikut efek rupiahnya terhadap
 * angka CLARA. Efek inilah yang nanti dijumlahkan sebagai pembuktian, jadi
 * tandanya harus persis: yang menaikkan CLARA positif, yang menurunkan negatif.
 */
function rk_tindakan(array $v, string $pic, string $bln, callable $nilaiSs): array
{
    $t = [];
    foreach ($v['kurang'] ?? [] as $x) {
        $t[] = [
            'aksi'  => 'TAMBAH',
            'judul' => (string) $x['ket'],
            'efek'  => (float) $x['nilai'],
            'ket'   => !empty($x['kode'])
                ? 'Dugaan unit: ' . $x['kode'] . (!empty($x['label']) ? ' — ' . $x['label'] : '')
                  . (empty($x['unit_pasti']) ? ' (pastikan dulu)' : '')
                : 'Unit belum dikenali — cari di Master, atau tanyakan ke Admin bila belum terdaftar.',
            'trx'   => null,
        ];
    }
    foreach ($v['belum_diakui'] ?? [] as $x) {
        $n = $nilaiSs($pic, $bln, $x['ket'] ?? null);
        $adaSs = !empty($x['di_spreadsheet']) && $n !== null;
        $t[] = [
            'aksi'  => $adaSs ? 'AKUI' : 'PERIKSA',
            'judul' => (string) $x['nama'] . ' — ' . (string) $x['kode'],
            'efek'  => $adaSs ? (float) $n : 0.0,
            'ket'   => 'Transaksi #' . $x['trx'] . ' sudah ada (' . $x['mulai'] . ' s/d ' . $x['selesai']
                     . ', status dokumen: ' . $x['status_dokumen'] . ') tetapi bulan ini diakui Rp 0.'
                     . ($adaSs ? '' : ' Barisnya juga tidak ada di spreadsheet bulan ini — periksa apakah memang belum waktunya diakui.'),
            'trx'   => (int) $x['trx'],
        ];
    }
    foreach ($v['beda'] ?? [] as $x) {
        $pengakuan = ($x['sebab'] ?? '') === 'pengakuan';
        $t[] = [
            'aksi'  => $pengakuan ? 'UBAH PENGAKUAN' : 'PERBAIKI NILAI',
            'judul' => (string) $x['nama'] . ' — ' . (string) $x['kode'],
            'efek'  => (float) $x['nilai_ss'] - (float) $x['nilai_clara'],
            'ket'   => 'Transaksi #' . $x['trx'] . ' · ' . $x['mulai'] . ' s/d ' . $x['selesai']
                     . ' · ' . $x['harga'] . ' / ' . $x['metode']
                     . ' · nilai kontrak ' . rk_rp((float) $x['nilai_kontrak']) . '. '
                     . ($pengakuan
                        ? 'Nilai kontraknya SUDAH benar — yang berbeda hanya bulan pengakuannya. Jangan ubah angkanya.'
                        : 'Nilai kontraknya sendiri berbeda dari spreadsheet.')
                     . (!empty($x['kelipatan']) ? ' Spreadsheet ' . $x['kelipatan'] . '× nilai CLARA — ciri kontrak yang dibagi rata ke beberapa bulan.' : ''),
            'trx'   => (int) $x['trx'],
        ];
    }
    foreach ($v['lebih'] ?? [] as $x) {
        $geser = ($x['saran'] ?? '') === 'geser_ke_awal';
        $t[] = [
            'aksi'  => $geser ? 'GESER' : 'PERIKSA',
            'judul' => (string) $x['nama'] . ' — ' . (string) $x['kode'],
            'efek'  => -(float) $x['nilai'],
            'ket'   => 'Transaksi #' . $x['trx'] . ' · ' . $x['mulai'] . ' s/d ' . $x['selesai']
                     . ' · ' . $x['metode'] . '. Ada di CLARA, tidak ada di spreadsheet bulan ini. '
                     . ($geser
                        ? 'Kontraknya MULAI ' . rk_bulan_teks((string) $x['bulan_mulai'])
                          . ' tetapi nilainya dibagi rata — akui seluruhnya di bulan mulainya, jangan dihapus.'
                        : ($x['lintas_bulan']
                           ? 'Kontraknya lintas bulan. Periksa apakah spreadsheet membukukannya di bulan lain.'
                           : 'Periksa apakah barisnya memang belum tercatat di spreadsheet.')),
            'trx'   => (int) $x['trx'],
        ];
    }
    // Yang paling besar pengaruhnya dikerjakan lebih dulu.
    usort($t, fn($a, $b) => abs($b['efek']) <=> abs($a['efek']));
    return $t;
}

// ── Penjelasan tindakan: satu tempat, dipakai ulang di tiap bab ──────────────
const RK_CARA = [
    'TAMBAH' => [
        'Transaksinya belum ada di CLARA — harus dibuat.',
        [
            'Buka <b>Transaksi</b> &rsaquo; <b>Tambah Transaksi</b>.',
            'Pilih <b>Unit</b> sesuai yang tertulis di baris spreadsheet. Kalau unitnya tidak ada di daftar, berhenti di sini dan minta Admin mendaftarkannya dulu — jangan dipaksakan ke unit lain yang mirip.',
            'Isi <b>Tanggal Mulai</b> dan <b>Tanggal Selesai</b> sesuai kontrak, bukan sesuai bulan laporan.',
            'Isi <b>Pricing Type</b> dan <b>Rate</b>. Periksa angka <b>Kalkulasi</b> yang muncul di bawah form — itu nilai yang akan diakui.',
            'Kalau Kalkulasi tidak sama dengan nilai di spreadsheet, isi <b>Override Aktual</b> dengan nilai spreadsheet.',
            'Pastikan <b>PIC</b> adalah nama Anda sendiri.',
            'Simpan dengan <b>Simpan &amp; Hitung Ulang Alokasi</b>.',
        ],
    ],
    'AKUI' => [
        'Transaksinya sudah ada, tetapi bulan ini belum diakui sama sekali (Rp 0).',
        [
            'Buka transaksinya lewat <b>Transaksi</b>, cari nomornya, klik <b>Alokasi</b> untuk melihat pembagian per bulan.',
            'Kalau nilainya nol karena dokumennya belum disetujui, dokumen itu yang harus diselesaikan dulu — bukan datanya yang diubah.',
            'Kalau dokumennya sudah beres, buka <b>Edit</b> lalu periksa <b>Nilai Diakui di Bulan</b>.',
            'Simpan dengan <b>Simpan &amp; Hitung Ulang Alokasi</b>, lalu lihat lagi halaman <b>Alokasi</b> untuk memastikan bulan ini sudah terisi.',
        ],
    ],
    'UBAH PENGAKUAN' => [
        'Nilai kontraknya sudah benar. Yang berbeda hanya bulan mana yang mengakui nilainya.',
        [
            'Buka transaksinya &rsaquo; <b>Edit</b>.',
            '<b>Jangan ubah Rate maupun Override Aktual.</b> Nilai kontraknya sudah cocok dengan spreadsheet.',
            'Ubah <b>Nilai Diakui di Bulan</b>: pilih <b>Bulan Awal (bulan mulai)</b> bila spreadsheet membukukan seluruhnya di bulan mulai, atau <b>Dibagi rata tiap bulan</b> bila spreadsheet memecahnya.',
            'Untuk kontrak bulanan yang dibagi rata, periksa juga <b>Pengakuan per Siklus</b>.',
            'Simpan dengan <b>Simpan &amp; Hitung Ulang Alokasi</b>.',
        ],
    ],
    'PERBAIKI NILAI' => [
        'Nilai kontrak di CLARA berbeda dari spreadsheet.',
        [
            'Cocokkan dulu dengan <b>surat/kontraknya</b>, bukan dengan spreadsheet. Yang salah bisa saja justru yang satunya.',
            'Kalau kontrak membenarkan spreadsheet: buka <b>Edit</b>, betulkan <b>Rate</b> (atau <b>Luas</b>/<b>Tanggal</b> bila itu sumber bedanya).',
            'Kalau nilainya hasil negosiasi yang tidak bisa diturunkan dari rate, isi <b>Override Aktual</b> dengan nilai yang benar.',
            'Simpan dengan <b>Simpan &amp; Hitung Ulang Alokasi</b>.',
            'Kalau kontrak membenarkan CLARA, jangan ubah apa pun — laporkan ke Admin supaya spreadsheet yang disesuaikan.',
        ],
    ],
    'GESER' => [
        'Nilainya sah, tetapi tercatat di bulan yang salah. Jangan dihapus.',
        [
            'Buka transaksinya &rsaquo; <b>Edit</b>.',
            'Ubah <b>Nilai Diakui di Bulan</b> menjadi <b>Bulan Awal (bulan mulai)</b>.',
            'Simpan dengan <b>Simpan &amp; Hitung Ulang Alokasi</b>.',
            'Buka <b>Alokasi</b> dan pastikan nilainya kini utuh di bulan mulainya, dan bulan ini jadi nol.',
            'Ingat: ini memindahkan income, bukan menghapusnya. Bulan mulainya akan ikut berubah.',
        ],
    ],
    'PERIKSA' => [
        'Belum bisa dipastikan dari data. Harus dilihat orangnya.',
        [
            'Buka transaksinya dan bandingkan dengan surat/kontraknya.',
            'Periksa apakah spreadsheet membukukannya di bulan lain — kontrak lintas bulan sering begitu.',
            'Kalau ternyata memang tidak pernah terjadi, ajukan penghapusan ke Admin; jangan menghapus sendiri.',
            'Kalau sah dan spreadsheet yang belum mencatatnya, laporkan supaya spreadsheet ditambahi.',
        ],
    ],
];

// ── Bangun satu PDF per PIC ──────────────────────────────────────────────────
$gaya = '
<style>
body { font-family: sans-serif; font-size: 10.5pt; color: #1f2937; line-height: 1.45; }
h1 { font-size: 20pt; color: #0f766e; margin: 0 0 2px; }
h2 { font-size: 13pt; color: #0f766e; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1.5px solid #0f766e; }
h3 { font-size: 11.5pt; color: #111827; margin: 12px 0 4px; }
p  { margin: 5px 0; text-align: justify; }
table { width: 100%; border-collapse: collapse; margin: 6px 0; }
th { background: #0f766e; color: #fff; font-size: 9pt; padding: 5px 6px; text-align: left; }
td { border: 1px solid #d1d5db; font-size: 9pt; padding: 4px 6px; vertical-align: top; }
td.n { text-align: right; white-space: nowrap; }
tr.klop td { background: #f0fdf4; }
tr.beda td { background: #fef2f2; }
.kecil { font-size: 8.5pt; color: #4b5563; }
.kotak { border: 1px solid #0f766e; background: #f0fdfa; padding: 8px 10px; margin: 8px 0; }
.kotak-ingat { border: 1px solid #f59e0b; background: #fffbeb; padding: 8px 10px; margin: 8px 0; }
.aksi { font-weight: bold; font-size: 8.5pt; }
ol, ul { margin: 4px 0 4px 16px; padding: 0; }
li { margin-bottom: 2px; font-size: 9.5pt; }
.bukti td { font-size: 9.5pt; }
.bukti tr.hasil td { background: #0f766e; color: #fff; font-weight: bold; }
.pb { page-break-before: always; }
</style>';

@mkdir((string) $opt['keluar-dir'], 0775, true);
$ringkasSemua = [];

foreach ($data as $pic => $perBulan) {
    $namaProp = $prop[$pic] ?? '';
    $picClara = '';
    foreach ($perBulan as $v) { if (!empty($v['pic_clara'])) { $picClara = (string) $v['pic_clara']; break; } }

    // Ringkasan tiap bulan + daftar bulan yang belum klop.
    $baris = []; $perluBulan = []; $totalSel = 0.0; $klop = 0;
    foreach ($bulanList as $b) {
        $v = $perBulan[$b] ?? null;
        if ($v === null) continue;
        $sel = (float) $v['clara'] - (float) $v['spreadsheet'];
        // Selisih Rp 1-2 adalah pembulatan alokasi harian, bukan kesalahan input.
        $nyata = abs($sel) > 2;
        $baris[] = ['bulan' => $b, 'clara' => (float) $v['clara'],
                    'ss' => (float) $v['spreadsheet'], 'sel' => $sel, 'nyata' => $nyata];
        $totalSel += $sel;
        if ($nyata) $perluBulan[] = $b; else $klop++;
    }

    $html = $gaya;

    // ── Sampul ──
    $html .= '<h1>Rekonsiliasi Pendapatan ' . h2($pic) . '</h1>';
    $html .= '<p style="font-size:11.5pt;margin-top:0">' . h2($namaProp)
           . ' &middot; PIC di CLARA: <b>' . h2($picClara ?: '—') . '</b><br>'
           . 'Periode ' . h2(rk_bulan_teks($bulanList[0])) . ' &ndash; '
           . h2(rk_bulan_teks($bulanList[count($bulanList) - 1])) . '</p>';

    $html .= '<div class="kotak"><b>Apa isi dokumen ini.</b> Tiap bulan yang angkanya belum sama dengan '
           . 'spreadsheet dipecah sampai tiap rupiahnya punya nama, berikut cara memperbaikinya di CLARA. '
           . 'Tiap bab ditutup tabel pembuktian: nilai CLARA sekarang ditambah seluruh perbaikan harus '
           . 'mendarat <b>persis</b> di angka spreadsheet. Kalau Anda mengerjakan seluruh daftarnya, '
           . 'angkanya pasti klop — itu bukan harapan, melainkan hasil hitungan yang sudah diperiksa.</div>';

    $html .= '<div class="kotak-ingat"><b>Spreadsheet adalah patokan.</b> Dokumen ini tidak pernah '
           . 'menyebut spreadsheet salah. Kalau menurut kontrak justru CLARA yang benar, jangan ubah '
           . 'CLARA — laporkan supaya spreadsheet yang disesuaikan. Mengubah CLARA agar "kelihatan klop" '
           . 'padahal kontraknya lain hanya memindahkan kesalahan.</div>';

    $html .= '<p class="kecil"><b>Sumber data.</b> Sisi CLARA: ' . h2($opt['sumber-db'] ?: 'database yang sedang dipakai')
           . '. Sisi spreadsheet: ' . h2($opt['sumber-xlsx'] ?: 'berkas Casual Leasing') . '. '
           . 'Perbaikan yang dilakukan setelah tanggal itu belum terlihat di sini.</p>';

    // ── Ringkasan ──
    $html .= '<h2>Ringkasan ' . count($baris) . ' Bulan</h2>';
    $html .= '<table><tr><th>Bulan</th><th style="text-align:right">CLARA</th>'
           . '<th style="text-align:right">Spreadsheet</th><th style="text-align:right">Selisih</th><th>Status</th></tr>';
    foreach ($baris as $r) {
        $html .= '<tr class="' . ($r['nyata'] ? 'beda' : 'klop') . '">'
               . '<td>' . h2(rk_bulan_teks($r['bulan'])) . '</td>'
               . '<td class="n">' . rk_rp($r['clara']) . '</td>'
               . '<td class="n">' . rk_rp($r['ss']) . '</td>'
               . '<td class="n">' . ($r['nyata'] ? rk_tanda($r['sel']) : '&mdash;') . '</td>'
               . '<td>' . ($r['nyata'] ? 'Perlu perbaikan' : (abs($r['sel']) > 0 ? 'Klop (beda Rp ' . number_format(abs($r['sel']), 0, ',', '.') . ' — pembulatan)' : 'Klop')) . '</td></tr>';
    }
    $html .= '</table>';
    $html .= '<p><b>' . $klop . ' bulan sudah klop</b>, <b>' . count($perluBulan) . ' bulan perlu perbaikan</b>. '
           . 'Jumlah seluruh selisih: <b>' . rk_tanda($totalSel) . '</b> '
           . '(tanda minus berarti CLARA lebih kecil daripada spreadsheet).</p>';

    if (!$perluBulan) {
        $html .= '<div class="kotak"><b>Tidak ada yang perlu dikerjakan.</b> Seluruh bulan pada periode ini '
               . 'sudah sama dengan spreadsheet.</div>';
    } else {
        $html .= '<h2>Cara Membaca Bab Berikutnya</h2>';
        $html .= '<p>Tiap bulan yang belum klop punya satu bab sendiri. Di dalamnya ada daftar tindakan, '
               . 'masing-masing diberi label yang menentukan apa yang Anda lakukan:</p><table>'
               . '<tr><th style="width:26%">Label</th><th>Artinya</th></tr>';
        foreach (RK_CARA as $aksi => [$arti, $_]) {
            $html .= '<tr><td class="aksi">' . h2($aksi) . '</td><td>' . $arti . '</td></tr>';
        }
        $html .= '</table>';
        $html .= '<p class="kecil">Kolom <b>Efek</b> adalah perubahan angka CLARA bila tindakan itu dikerjakan. '
               . 'Jumlah seluruh Efek dalam satu bulan selalu sama dengan selisih bulan itu — itulah yang '
               . 'dibuktikan di akhir tiap bab.</p>';
    }

    // ── Satu bab per bulan ──
    foreach ($perluBulan as $b) {
        $v = $perBulan[$b];
        $sel = (float) $v['clara'] - (float) $v['spreadsheet'];
        $tind = rk_tindakan($v, (string) $pic, $b, $nilaiSs);

        $html .= '<div class="pb"></div>';
        $html .= '<h2>' . h2(rk_bulan_teks($b)) . '</h2>';
        $html .= '<table><tr>'
               . '<td style="width:34%">CLARA sekarang</td><td class="n">' . rk_rp((float) $v['clara']) . '</td></tr>'
               . '<tr><td>Spreadsheet (patokan)</td><td class="n">' . rk_rp((float) $v['spreadsheet']) . '</td></tr>'
               . '<tr class="beda"><td><b>Selisih</b></td><td class="n"><b>' . rk_tanda($sel) . '</b></td></tr></table>';
        $html .= '<p>' . (int) $v['cocok'] . ' baris sudah cocok dan tidak perlu disentuh. '
               . 'Yang di bawah ini saja yang berbeda — <b>' . count($tind) . ' tindakan</b>.</p>';

        // Daftar tindakan.
        $html .= '<h3>Yang harus dikerjakan</h3>';
        $html .= '<table><tr><th style="width:15%">Tindakan</th><th>Baris</th>'
               . '<th style="width:18%;text-align:right">Efek</th></tr>';
        foreach ($tind as $i => $t) {
            $html .= '<tr><td class="aksi">' . h2($t['aksi']) . '</td>'
                   . '<td><b>' . h2($t['judul']) . '</b><br><span class="kecil">' . $t['ket'] . '</span></td>'
                   . '<td class="n">' . ($t['efek'] == 0.0 ? '&mdash;' : rk_tanda($t['efek'])) . '</td></tr>';
        }
        $html .= '</table>';

        // Langkah per jenis tindakan yang benar-benar muncul bulan ini.
        $jenis = [];
        foreach ($tind as $t) $jenis[$t['aksi']] = true;
        $html .= '<h3>Langkahnya</h3>';
        foreach (array_keys($jenis) as $aksi) {
            if (!isset(RK_CARA[$aksi])) continue;
            [$arti, $langkah] = RK_CARA[$aksi];
            $html .= '<p style="margin-bottom:2px"><b>' . h2($aksi) . '</b> &mdash; ' . $arti . '</p><ol>';
            foreach ($langkah as $l) $html .= '<li>' . $l . '</li>';
            $html .= '</ol>';
        }

        // Pembuktian.
        $html .= '<h3>Pembuktian</h3>';
        $html .= '<p>Mulai dari angka CLARA hari ini, lalu terapkan tiap tindakan di atas satu per satu:</p>';
        $html .= '<table class="bukti"><tr><th>Langkah</th><th style="width:20%;text-align:right">Efek</th>'
               . '<th style="width:22%;text-align:right">Angka CLARA</th></tr>';
        $jalan = (float) $v['clara'];
        $html .= '<tr><td>CLARA sebelum diperbaiki</td><td class="n">&mdash;</td>'
               . '<td class="n">' . rk_rp($jalan) . '</td></tr>';
        foreach ($tind as $t) {
            if ($t['efek'] == 0.0) continue;
            $jalan += $t['efek'];
            $html .= '<tr><td>' . h2($t['aksi']) . ' &middot; ' . h2(mb_substr($t['judul'], 0, 52)) . '</td>'
                   . '<td class="n">' . rk_tanda($t['efek']) . '</td>'
                   . '<td class="n">' . rk_rp($jalan) . '</td></tr>';
        }
        $sisa = $jalan - (float) $v['spreadsheet'];
        $html .= '<tr class="hasil"><td>Hasil akhir &mdash; bandingkan dengan spreadsheet '
               . rk_rp((float) $v['spreadsheet']) . '</td><td class="n">&nbsp;</td>'
               . '<td class="n">' . rk_rp($jalan) . '</td></tr></table>';
        if (abs($sisa) <= 2) {
            $html .= '<div class="kotak"><b>Klop.</b> Sesudah seluruh tindakan di atas dikerjakan, angka '
                   . h2(rk_bulan_teks($b)) . ' Anda menjadi ' . rk_rp($jalan) . ' &mdash; sama dengan spreadsheet'
                   . (abs($sisa) > 0 ? ' (beda Rp ' . number_format(abs($sisa), 0, ',', '.') . ' karena pembulatan alokasi harian)' : '')
                   . '.</div>';
        } else {
            $html .= '<div class="kotak-ingat"><b>Masih tersisa ' . rk_tanda($sisa) . ' yang belum bisa '
                   . 'dijelaskan dari data.</b> Artinya ada baris yang belum tertangkap sandingan ini. '
                   . 'Jangan dicocok-cocokkan dengan menambah angka karangan &mdash; bawa sisa ini ke Admin '
                   . 'supaya ditelusuri bersama.</div>';
        }
    }

    // ── Penutup ──
    if ($perluBulan) {
        $html .= '<div class="pb"></div><h2>Sesudah Selesai Memperbaiki</h2>';
        $html .= '<ol>'
               . '<li>Buka <b>Monitoring Transaksi</b> atau <b>Dashboard</b>, pilih bulan yang baru Anda perbaiki.</li>'
               . '<li>Cocokkan angka achievement Anda dengan kolom Spreadsheet di tabel Ringkasan dokumen ini.</li>'
               . '<li>Kalau sudah sama, bulan itu selesai. Kalau belum, lihat lagi daftar tindakan bulan tersebut '
               . '&mdash; biasanya ada satu baris yang terlewat atau tersimpan dengan tanggal yang berbeda.</li>'
               . '<li>Jangan memperbaiki bulan berikutnya sebelum bulan sebelumnya klop. Kontrak lintas bulan '
               . 'membuat perbaikan satu bulan ikut mengubah bulan tetangganya.</li>'
               . '</ol>';
        $html .= '<div class="kotak-ingat"><b>Satu hal yang paling sering keliru.</b> Kalau sebuah baris ada di '
               . 'CLARA tetapi tidak ada di spreadsheet bulan ini, dorongan pertama biasanya menghapusnya. '
               . 'Padahal kontrak lintas bulan memang sering dibukukan spreadsheet di bulan mulainya saja. '
               . 'Menghapus akan membuang income yang sah dan merusak bulan yang tadinya sudah benar. '
               . 'Yang benar adalah memindahkan bulan pengakuannya &mdash; itulah tindakan <b>GESER</b>.</div>';
    }

    $berkas = rtrim((string) $opt['keluar-dir'], '/') . '/Rekonsiliasi_' . preg_replace('/[^A-Za-z0-9]+/', '_', (string) $pic) . '_2026.pdf';
    $mpdf = clara_letterhead_mpdf();
    $mpdf->SetTitle('Rekonsiliasi ' . $pic);
    $mpdf->WriteHTML($html);
    $mpdf->Output($berkas, \Mpdf\Output\Destination::FILE);

    $ringkasSemua[] = [$pic, $picClara, $namaProp, $klop, count($perluBulan), $totalSel, $berkas];
    printf("  %-10s %-26s %d klop · %d perlu perbaikan · total %s\n",
        $pic, basename($berkas), $klop, count($perluBulan), rk_tanda($totalSel));
}

echo "\n  " . count($ringkasSemua) . " berkas PDF dibuat di " . $opt['keluar-dir'] . "\n";
