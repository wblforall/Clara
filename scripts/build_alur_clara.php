<?php
/**
 * Bagan alir kerja CLARA — satu halaman HTML.
 *
 * Keluarannya SENGAJA cuma HTML, bukan PDF. Dipaksa masuk A4, bagannya jadi
 * berdesakan: kotak mengecil, teks terpotong, cabang bertumpuk. Di halaman web
 * tidak ada batas tinggi, jadi alurnya boleh memanjang ke bawah dengan kotak
 * yang lega dan cabang yang melebar ke samping.
 *
 * Bentuknya bagan alir sungguhan: belah ketupat untuk titik keputusan, garis
 * penghubung berpanah, cabang Ya/Tidak yang kelihatan ke mana perginya, dan
 * panah balik untuk langkah yang berulang.
 *
 * Caranya digambar: bentuk & garis digambar di SVG (biar tikungan dan panahnya
 * rapi), teksnya ditulis sebagai elemen HTML di atasnya pada koordinat yang
 * sama (biar pemenggalan kata, pilihan font, dan penyalinan teksnya benar).
 * Tinggi tiap kotak dihitung lebih dulu dari isinya supaya kedua lapisan itu
 * selalu bertemu di tempat yang sama.
 *
 * Isinya diambil dari kode, bukan dari ingatan:
 *   - jenis dokumen per modul      → skp_doc_type() di app/pages/skp_modules.php
 *   - syarat SKP lahir             → penjaga di skp_form() app/pages/skp.php
 *   - tahap persetujuan & siapanya → tabel skp_approval_flow (dibaca saat dibangun)
 *   - kapan income dihitung        → _skp_bangun_alokasi(), dipanggil saat TTD client
 *   - batas tautan tanda tangan    → sign_token_expiry_sql() di app/helpers.php
 *   - aturan wajib TTD berlaku     → settings.ttd_wajib_mulai
 *
 * Pakai:
 *   php scripts/build_alur_clara.php
 *   php scripts/build_alur_clara.php --keluar=/path/folder
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';

$opt = array_merge(['keluar' => $root . '/docs'], getopt('', ['keluar:']));
$keluar = rtrim((string) $opt['keluar'], '/');
@mkdir($keluar, 0777, true);
date_default_timezone_set((string) (env_value('APP_TIMEZONE') ?: 'Asia/Makassar'));

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

// ── Pengaturan yang hidup di basis data ──────────────────────────────────────
// Nama pemaraf dan tanggal berlakunya aturan tanda tangan dibaca dari sistem,
// bukan ditulis tetap di sini — supaya bagan ini tidak jadi usang diam-diam
// ketika alurnya diubah lewat halaman Alur Persetujuan.
$tahap = ['skp' => [], 'sks' => [], 'fu' => []];
$ttdMulai = '';
try {
    $pdo = Database::connect();
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Nama disebut PER MAL. Digabung jadi satu daftar ("Ocha / Yusri"), pembaca
    // mengira dokumennya diparaf dua orang — padahal tiap mal punya satu.
    $q = $pdo->query("SELECT f.doc_type, f.step_no, f.role_name, f.label, f.pic_name, p.name prop
                        FROM skp_approval_flow f
                        LEFT JOIN properties p ON p.id = f.property_id
                       WHERE f.is_active = 1 ORDER BY f.doc_type, f.step_no, f.property_id");
    $kumpul = [];
    foreach ($q as $r) {
        $n = (int) $r['step_no']; $d = (string) $r['doc_type'];
        $tahap[$d][$n]['label'] = (string) ($r['label'] ?: $r['role_name']);
        $nama = trim((string) $r['pic_name']);
        if ($nama === '') continue;
        $mal = preg_match('/pentacity/i', (string) $r['prop']) ? 'Pentacity'
             : (preg_match('/walk/i', (string) $r['prop']) ? 'e-Walk' : trim((string) $r['prop']));
        $kumpul[$d][$n][$nama][$mal] = true;
    }
    foreach ($kumpul as $d => $perTahap) {
        foreach ($perTahap as $n => $orang) {
            $bagian = [];
            foreach ($orang as $nama => $mals) {
                $bagian[] = count($mals) >= 2 ? $nama : $nama . ' (' . array_key_first($mals) . ')';
            }
            $tahap[$d][$n]['orang'] = implode(' · ', $bagian);
        }
    }
    $ttdMulai = (string) ($pdo->query("SELECT value FROM settings WHERE `key`='ttd_wajib_mulai' LIMIT 1")->fetchColumn() ?: '');
} catch (Throwable $e) {
    fwrite(STDERR, "Catatan: basis data tidak terbaca, bagan memakai alur bawaan.\n");
}
if (!$tahap['skp']) {
    $tahap['skp'] = [1 => ['label' => 'Asst. Manager', 'orang' => ''], 2 => ['label' => 'Manager', 'orang' => '']];
}
foreach (['sks', 'fu'] as $dt) if (!$tahap[$dt]) $tahap[$dt] = $tahap['skp'];

$lbl1 = (string) ($tahap['skp'][1]['label'] ?? 'Asst. Manager');
$lbl2 = (string) ($tahap['skp'][2]['label'] ?? 'Manager');
$org1 = (string) ($tahap['skp'][1]['orang'] ?? '');
$org2 = (string) ($tahap['skp'][2]['orang'] ?? '');
$pem1 = $lbl1 . ($org1 ? ' — ' . $org1 : '');
$pem2 = $lbl2 . ($org2 ? ' — ' . $org2 : '');

$BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
          'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$ttdTgl = '';
if ($ttdMulai !== '' && ($ts = strtotime($ttdMulai))) {
    $ttdTgl = date('j', $ts) . ' ' . $BULAN[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

/** Logo kop surat, ditanam sebagai data-URI supaya berkasnya berdiri sendiri. */
function potong_uri(string $src, int $x, int $w): string
{
    $im = @imagecreatefromjpeg($src);
    if (!$im) return '';
    $p = imagecreatetruecolor($w, imagesy($im));
    imagecopy($p, $im, 0, 0, $x, 0, $w, imagesy($im));
    ob_start(); imagejpeg($p, null, 94); $bin = (string) ob_get_clean();
    return 'data:image/jpeg;base64,' . base64_encode($bin);
}
$logoMal = potong_uri($root . '/public/assets/letterhead-head.jpg', 410, 420);
$logoWbl = potong_uri($root . '/public/assets/letterhead-foot.jpg', 40, 560);

// ═══════════════════════════════════════════════════════════════════════════
//  Mesin bagan alir — satuan PIKSEL, tanpa batas tinggi.
//
//  Tiga lajur tetap: tulang punggung di kiri, cabang di tengah, catatan di
//  kanan. Alur utama selalu turun lurus di lajur pertama, jadi mata cukup
//  mengikuti satu garis; yang menyimpang baru melebar ke kanan.
// ═══════════════════════════════════════════════════════════════════════════

const LEBAR  = 1240;   // lebar kanvas
const L1     = 60;     // x lajur tulang punggung
const L2     = 540;    // x lajur cabang
const L3     = 1000;   // x lajur catatan
const WN     = 400;    // lebar kotak lajur 1 & 2
const WC     = 240;    // lebar kotak catatan

const WARNA = [
    'sales'   => ['#0F766E', '#F0FDFA', '#99F6E4'],
    'asst'    => ['#B45309', '#FFFBEB', '#FDE68A'],
    'manager' => ['#6D28D9', '#F5F3FF', '#DDD6FE'],
    'client'  => ['#0369A1', '#F0F9FF', '#BAE6FD'],
    'legal'   => ['#BE185D', '#FDF2F8', '#FBCFE8'],
    'sistem'  => ['#475569', '#F8FAFC', '#E2E8F0'],
    'tanya'   => ['#C2410C', '#FFF7ED', '#FED7AA'],
    'hasil'   => ['#15803D', '#F0FDF4', '#BBF7D0'],
    'stop'    => ['#B91C1C', '#FEF2F2', '#FECACA'],
    'ujung'   => ['#1E293B', '#E2E8F0', '#CBD5E1'],
];
const PANAH = ['#94A3B8' => 'p0', '#DC2626' => 'p1', '#B45309' => 'p2', '#0D9488' => 'p3'];

/** Lebar kira-kira satu aksara — untuk menaksir tinggi kotak sebelum dirender. */
function _lebarAksara(string $c, float $fs, bool $tebal): float
{
    $sempit = "iljtfr.,;:'!|()[]/ ";
    $lebar  = "mwMW@%";
    $f = str_contains($sempit, $c) ? 0.33 : (str_contains($lebar, $c) ? 0.85 : 0.53);
    if (ctype_upper($c)) $f += 0.09;
    return $fs * $f * ($tebal ? 1.06 : 1.0);
}

/** Berapa baris teks ini kalau dibungkus selebar $lebar px. */
function _barisTeks(string $teks, float $lebar, float $fs, bool $tebal): int
{
    $kata = preg_split('/\s+/', trim($teks)) ?: [];
    $n = 1; $w = 0.0;
    foreach ($kata as $k) {
        if ($k === '') continue;
        $lw = 0.0;
        foreach (preg_split('//u', strip_tags($k), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) {
            $lw += _lebarAksara($c, $fs, $tebal);
        }
        $spasi = $w > 0 ? $fs * 0.30 : 0.0;
        if ($w > 0 && $w + $spasi + $lw > $lebar) { $n++; $w = $lw; }
        else                                      { $w += $spasi + $lw; }
    }
    return $n;
}

$SIMPUL = [];

/**
 * Daftarkan satu simpul.
 *
 * $bentuk: proses | tanya | ujung | hasil | catatan
 * Tingginya dihitung dari isinya, jadi kotak tidak pernah kesempitan sendiri
 * dan garis penghubungnya selalu menempel di tepi yang benar.
 */
function S(string $id, float $x, float $y, float $w, string $bentuk, string $peran,
           string $judul, string $isi = ''): void
{
    global $SIMPUL;
    $fsJ = $bentuk === 'ujung' ? 15.5 : 16.0;
    $fsI = 13.0;
    // Di belah ketupat, teks hanya muat di bagian tengahnya.
    $pad  = $bentuk === 'tanya' ? $w * 0.22 : 22.0;
    $lebarTeks = $w - 2 * $pad;
    $nJ = _barisTeks($judul, $lebarTeks, $fsJ, true);
    $nI = $isi !== '' ? _barisTeks($isi, $lebarTeks, $fsI, false) : 0;
    $hTeks = $nJ * ($fsJ * 1.35) + ($nI ? 7 + $nI * ($fsI * 1.5) : 0);
    $h = $hTeks + 36;
    if ($bentuk === 'tanya') $h = $hTeks + 74;
    if ($bentuk === 'ujung') $h = max($h, 56);
    $SIMPUL[$id] = compact('x', 'y', 'w', 'h', 'bentuk', 'peran', 'judul', 'isi')
                 + ['pad' => $pad];
}

function T(string $id, string $sisi): array
{
    global $SIMPUL;
    $n = $SIMPUL[$id];
    $cx = $n['x'] + $n['w'] / 2; $cy = $n['y'] + $n['h'] / 2;
    return match ($sisi) {
        'atas'  => [$cx, $n['y']],
        'bawah' => [$cx, $n['y'] + $n['h']],
        'kiri'  => [$n['x'], $cy],
        'kanan' => [$n['x'] + $n['w'], $cy],
        default => [$cx, $cy],
    };
}
/** Tepi bawah sebuah simpul — dipakai menata simpul berikutnya. */
function BW(string $id): float { global $SIMPUL; return $SIMPUL[$id]['y'] + $SIMPUL[$id]['h']; }

/** Bentuk simpul, digambar di lapisan SVG. */
function svgSimpul(array $n): string
{
    [$grs, $bg, $tepi] = WARNA[$n['peran']] ?? WARNA['sistem'];
    $x = $n['x']; $y = $n['y']; $w = $n['w']; $h = $n['h'];
    if ($n['bentuk'] === 'tanya') {
        $p = sprintf('%.1f,%.1f %.1f,%.1f %.1f,%.1f %.1f,%.1f',
            $x + $w / 2, $y, $x + $w, $y + $h / 2, $x + $w / 2, $y + $h, $x, $y + $h / 2);
        return '<polygon points="' . $p . '" fill="' . $bg . '" stroke="' . $tepi . '" stroke-width="2"/>';
    }
    if ($n['bentuk'] === 'ujung') {
        return '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" rx="' . ($h / 2)
             . '" fill="' . $bg . '" stroke="' . $tepi . '" stroke-width="2"/>';
    }
    $putus = $n['bentuk'] === 'catatan' ? ' stroke-dasharray="7 5"' : '';
    $tbl   = $n['bentuk'] === 'hasil' ? 3 : 1.6;
    $s = '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" rx="12" fill="'
       . $bg . '" stroke="' . $tepi . '" stroke-width="' . $tbl . '"' . $putus . '/>';
    if ($n['bentuk'] !== 'catatan') {
        // Pita warna di tepi kiri = siapa pelakunya, terbaca sekilas.
        $s .= '<path d="M' . ($x + 12) . ' ' . $y . ' h-1 a12,12 0 0 0 -11,12 V' . ($y + $h - 12)
            . ' a12,12 0 0 0 11,12 h1 Z" fill="' . $grs . '"/>';
    }
    return $s;
}

/** Teks simpul, ditulis sebagai HTML di atas bentuknya. */
function htmlSimpul(array $n): string
{
    [$grs] = WARNA[$n['peran']] ?? WARNA['sistem'];
    $gy = $n['bentuk'] === 'tanya' ? 0 : 0;
    $st = 'left:' . round($n['x'] + $n['pad']) . 'px;top:' . round($n['y'] + $gy) . 'px;'
        . 'width:' . round($n['w'] - 2 * $n['pad']) . 'px;height:' . round($n['h']) . 'px;';
    $s = '<div class="tx" style="' . $st . '">';
    $s .= '<b style="color:' . $grs . '">' . $n['judul'] . '</b>';
    if ($n['isi'] !== '') $s .= '<i>' . $n['isi'] . '</i>';
    return $s . '</div>';
}

/** Polyline bersiku dengan tikungan membulat. */
function _rute(array $pts, float $r = 14): string
{
    $d = 'M' . round($pts[0][0], 1) . ' ' . round($pts[0][1], 1);
    for ($i = 1, $n = count($pts) - 1; $i < $n; $i++) {
        [$px, $py] = $pts[$i - 1]; [$cx, $cy] = $pts[$i]; [$nx, $ny] = $pts[$i + 1];
        $d1 = hypot($cx - $px, $cy - $py); $d2 = hypot($nx - $cx, $ny - $cy);
        if ($d1 < 1 || $d2 < 1) continue;
        $rr = min($r, $d1 / 2, $d2 / 2);
        $ax = $cx + ($px - $cx) / $d1 * $rr; $ay = $cy + ($py - $cy) / $d1 * $rr;
        $bx = $cx + ($nx - $cx) / $d2 * $rr; $by = $cy + ($ny - $cy) / $d2 * $rr;
        $d .= ' L' . round($ax, 1) . ' ' . round($ay, 1)
            . ' Q' . round($cx, 1) . ' ' . round($cy, 1) . ' ' . round($bx, 1) . ' ' . round($by, 1);
    }
    $last = end($pts);
    return $d . ' L' . round($last[0], 1) . ' ' . round($last[1], 1);
}

$GARIS = []; $LABEL = [];

/** Garis penghubung + label cabangnya. $lp menentukan letak label bila perlu. */
function P(array $titik, string $label = '', string $warna = '#94A3B8', bool $putus = false, ?array $lp = null): void
{
    global $GARIS, $LABEL;
    $m = PANAH[$warna] ?? 'p0';
    $GARIS[] = '<path d="' . _rute($titik) . '" fill="none" stroke="' . $warna . '" stroke-width="2.2"'
        . ($putus ? ' stroke-dasharray="7 6"' : '') . ' marker-end="url(#' . $m . ')"/>';
    if ($label === '') return;
    if ($lp === null) {
        [$x1, $y1] = $titik[0]; [$x2, $y2] = $titik[1];
        $lp = [($x1 + $x2) / 2, ($y1 + $y2) / 2];
        if (abs($x2 - $x1) < 1) $lp[0] += 34; else $lp[1] -= 15;
    }
    $LABEL[] = '<span class="lb" style="left:' . round($lp[0]) . 'px;top:' . round($lp[1]) . 'px;'
        . 'color:' . $warna . ';border-color:' . $warna . '33">' . $label . '</span>';
}

/** Bungkus satu bagan: SVG (bentuk + garis) dan HTML (teks) pada koordinat sama. */
function bagan(float $tinggi): string
{
    global $SIMPUL, $GARIS, $LABEL;
    $def = '';
    foreach (PANAH as $w => $id) {
        $def .= '<marker id="' . $id . '" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6.5"'
              . ' markerHeight="6.5" orient="auto-start-reverse"><path d="M0,1 L9,5 L0,9 z" fill="' . $w . '"/></marker>';
    }
    $svg = '<svg viewBox="0 0 ' . LEBAR . ' ' . $tinggi . '" width="' . LEBAR . '" height="' . $tinggi . '"><defs>'
         . $def . '</defs>';
    foreach ($GARIS as $g) $svg .= $g;
    foreach ($SIMPUL as $n) $svg .= svgSimpul($n);
    $svg .= '</svg>';

    $teks = '';
    foreach ($SIMPUL as $n) $teks .= htmlSimpul($n);
    foreach ($LABEL as $l) $teks .= $l;

    $SIMPUL = []; $GARIS = []; $LABEL = [];
    return '<div class="bagan" style="height:' . round($tinggi) . 'px">' . $svg . $teks . '</div>';
}

// ═══════════════════════════════════════════════════════════════════════════
//  Isi bagan
// ═══════════════════════════════════════════════════════════════════════════
$bab = [];

/* ── 1. Ringkasan: tulang punggung bersama ──────────────────────────────── */
$y = 0;
S('m',  L1 + 60, $y, WN - 120, 'ujung', 'ujung', 'MULAI', '');              $y = BW('m') + 54;
S('tm', L1,      $y, WN, 'tanya',  'tanya',   'Modul yang mana?', '');      $y = BW('tm') + 70;
S('ex', L1,      $y, WN, 'proses', 'sales',   'EXHIBITION &rarr; dokumen SKP',
    'Wajib lewat Surat Penawaran. SKP baru hanya lahir dari penawaran yang sudah diteken client.');
S('gd', L2,      $y, WN, 'proses', 'sales',   'GUDANG &rarr; SKS &nbsp;&middot;&nbsp; MEDIA &rarr; FU',
    'Boleh langsung dibuat tanpa Surat Penawaran.');
$y = max(BW('ex'), BW('gd')) + 64;
S('aj', L1,      $y, WN, 'proses', 'sales',   'Sales mengajukan dokumen', 'Status berubah jadi Menunggu Approval.'); $y = BW('aj') + 56;
S('p1', L1,      $y, WN, 'tanya',  'asst',    'Diparaf ' . $lbl1 . '?', $org1 ?: '');  $y = BW('p1') + 56;
S('p2', L1,      $y, WN, 'tanya',  'manager', 'Disetujui ' . $lbl2 . '?', $org2 ?: ''); $y = BW('p2') + 56;
S('bl', L2,      $SIMPUL['p1']['y'] + 30, WN, 'proses', 'stop', 'Dikembalikan ke sales',
    'Dokumen diperbaiki lalu diajukan ulang. Alokasi income dilepas selama itu.');
S('no', L1,      $y, WN, 'hasil',  'hasil',   'NOMOR DOKUMEN TERBIT',
    'Transaksi lahir di sini, isinya dibekukan. Bukan saat dokumen dibuat, bukan pula saat diparaf.'); $y = BW('no') + 56;
S('tt', L1,      $y, WN, 'tanya',  'client',  'Client menandatangani?', 'Tautan berlaku 30 hari'); $y = BW('tt') + 56;
S('ic', L1,      $y, WN, 'hasil',  'hasil',   'INCOME MASUK LAPORAN',
    'Dashboard, Laporan PIC, dan Achievement' . ($ttdTgl ? ' — untuk kesepakatan sejak ' . $ttdTgl : '') . '.');
S('wt', L2,      $SIMPUL['tt']['y'] + 10, WN, 'proses', 'sistem', 'Menunggu di daftar "Perlu TTD"',
    'Nilainya belum terhitung di laporan mana pun.');
$tinggi = BW('ic') + 30;

P([T('m', 'bawah'), T('tm', 'atas')]);
P([T('tm', 'bawah'), T('ex', 'atas')], 'Exhibition', '#0D9488');
P([T('tm', 'kanan'), [L2 - 44, $SIMPUL['tm']['y'] + $SIMPUL['tm']['h'] / 2], [L2 - 44, $SIMPUL['gd']['y'] + 42], T('gd', 'kiri')], 'Gudang / Media', '#0D9488');
P([T('ex', 'bawah'), T('aj', 'atas')]);
P([T('gd', 'bawah'), [L2 + WN / 2, BW('gd') + 34], [L1 + WN / 2, BW('gd') + 34], T('aj', 'atas')]);
P([T('aj', 'bawah'), T('p1', 'atas')]);
P([T('p1', 'bawah'), T('p2', 'atas')], 'Ya', '#0D9488');
P([T('p1', 'kanan'), T('bl', 'kiri')], 'Tidak', '#DC2626');
P([T('p2', 'kanan'), [L2 - 26, $SIMPUL['p2']['y'] + $SIMPUL['p2']['h'] / 2], [L2 - 26, BW('bl') - 20], [L2, BW('bl') - 20]], 'Tidak', '#DC2626');
P([T('p2', 'bawah'), T('no', 'atas')], 'Ya', '#0D9488');
P([T('no', 'bawah'), T('tt', 'atas')]);
P([T('tt', 'bawah'), T('ic', 'atas')], 'Ya', '#0D9488');
P([T('tt', 'kanan'), T('wt', 'kiri')], 'Belum', '#B45309');
P([T('bl', 'atas'), [L2 + WN / 2, $SIMPUL['aj']['y'] - 34], [L1 + WN + 46, $SIMPUL['aj']['y'] - 34], [L1 + WN + 46, $SIMPUL['aj']['y'] + $SIMPUL['aj']['h'] / 2], T('aj', 'kanan')], 'diajukan ulang', '#94A3B8', true, [L1 + WN + 120, $SIMPUL['aj']['y'] - 34]);
$bab['ringkas'] = bagan($tinggi);

/* ── 2. Exhibition ──────────────────────────────────────────────────────── */
$y = 0;
S('e1', L1 + 60, $y, WN - 120, 'ujung', 'ujung', 'MULAI', '');                       $y = BW('e1') + 50;
S('e2', L1, $y, WN, 'tanya', 'tanya', 'Client &amp; unit sudah ada di Master Data?', '');
S('e2b', L2, $y + 12, WN, 'proses', 'sales', 'Daftarkan dulu',
    '<b>Master Data &rarr; Client</b> (perusahaan, merek, alamat, NPWP, dan minimal satu kontak PJ) serta <b>Master Exhibition</b> (kode unit, lantai, luas, tarif per hari/m&sup2;).');
$y = max(BW('e2'), BW('e2b')) + 50;
S('e3', L1, $y, WN, 'proses', 'sales', 'Buat Surat Penawaran',
    'Pilih client, kontak PJ, unit, periode, harga, diskon, DP &amp; deposit.');      $y = BW('e3') + 42;
S('e4', L1, $y, WN, 'proses', 'sistem', 'Nomor penawaran terbit otomatis',
    'Keluar begitu disimpan. Status awal <b>Draft</b>.');                            $y = BW('e4') + 42;
S('e5', L1, $y, WN, 'proses', 'sales', 'Periksa lalu kirim ke client',
    'Buka <b>Lihat Contoh Surat</b> dulu untuk memastikan isinya, baru cetak PDF dan kirim. Ubah status jadi <b>Terkirim</b>, lalu <b>Nego</b> bila ada tawar-menawar.'); $y = BW('e5') + 50;
S('e6', L1, $y, WN, 'tanya', 'tanya', 'Client setuju dengan penawarannya?', '');
S('e6b', L2, $y + 12, WN, 'proses', 'stop', 'Tutup penawaran',
    'Wajib mengisi alasannya. Alasan itulah yang dipakai menganalisa kenapa deal lepas.');
S('e6c', L2 + 60, BW('e6b') + 36, WN - 120, 'ujung', 'ujung', 'SELESAI — tidak jadi', '');
$y = max(BW('e6'), BW('e6c')) + 50;
S('e7', L1, $y, WN, 'proses', 'client', 'Client menandatangani penawaran',
    'Lewat tautan tanda tangan online, <b>atau</b> unggah surat penawaran yang sudah diteken basah.'); $y = BW('e7') + 42;
S('e8', L1, $y, WN, 'hasil', 'hasil', 'STATUS DEAL',
    'Nilainya dikunci — penawaran tidak bisa diubah lagi.');                         $y = BW('e8') + 42;
S('e9', L1, $y, WN, 'proses', 'sales', 'Buat SKP dari penawaran',
    'Tombol <b>&rarr; Buat SKP (Konfirmasi)</b> di halaman penawaran. Lengkapi penanggung jawab, KTP/NPWP/SIUP, lampiran berkas, dan <b>Pembagian PIC</b> bila deal ini dikerjakan lebih dari satu orang.');
S('e9n', L3, $y + 20, WC, 'catatan', 'sistem', 'Syaratnya satu',
    'Penawarannya harus sudah ditandatangani client. Kalau belum, tombolnya menolak.');
$y = BW('e9') + 42;
S('e10', L1, $y, WN, 'proses', 'sales', 'Ajukan', 'Status berubah jadi <b>Menunggu Approval</b> dan dokumen masuk antrean pemeriksa.'); $y = BW('e10') + 50;
S('e11', L1, $y, WN, 'tanya', 'asst', 'Diparaf ' . $lbl1 . '?', $org1 ?: '');
S('e11b', L2, $y + 12, WN, 'proses', 'stop', 'Dikembalikan ke sales',
    '<b>Kembalikan satu tahap</b> bila yang kurang cuma ketelitian tahap di bawahnya, atau <b>Tolak ke PIC</b> bila isinya memang salah.');
$y = max(BW('e11'), BW('e11b')) + 50;
S('e12', L1, $y, WN, 'tanya', 'manager', 'Disetujui ' . $lbl2 . '?', $org2 ?: ''); $y = BW('e12') + 50;
S('e13', L1, $y, WN, 'hasil', 'hasil', 'NOMOR SKP TERBIT &middot; TRANSAKSI LAHIR',
    'Isinya dibekukan di sini, jadi cetakan ulangnya tidak pernah berubah.');       $y = BW('e13') + 42;
S('e14', L1, $y, WN, 'proses', 'sales', 'Kirim tautan tanda tangan ke client',
    'Tersedia tombol WhatsApp di halaman SKP. <b>Tautannya berlaku 30 hari.</b>');  $y = BW('e14') + 50;
S('e15', L1, $y, WN, 'tanya', 'client', 'Client meneken dalam 30 hari?', '');
S('e15b', L2, $y + 12, WN, 'proses', 'asst', 'Ajukan revisi',
    'Diputus ' . $lbl1 . '. Tautan baru terbit, nomornya tetap sama — hanya naik versi.');
$y = max(BW('e15'), BW('e15b')) + 50;
S('e16', L1, $y, WN, 'hasil', 'hasil', 'INCOME MASUK LAPORAN',
    'Alokasi bulanan terbentuk. Baru pada titik ini nilainya muncul di Dashboard, Laporan PIC, dan Achievement.'); $y = BW('e16') + 50;
S('e17', L1, $y, WN, 'tanya', 'tanya', 'Perlu kontrak dari Legal?', '');
S('e17b', L2, $y + 12, WN, 'proses', 'legal', 'Ajukan Kontrak ke Legal',
    'Berkas tidak bisa dikirim sebelum <b>bukti transfer</b> diunggah.');
$y = max(BW('e17'), BW('e17b')) + 50;
S('e18', L1 + 60, $y, WN - 120, 'ujung', 'ujung', 'SELESAI', '');
$tinggi = BW('e18') + 30;

P([T('e1', 'bawah'), T('e2', 'atas')]);
P([T('e2', 'kanan'), T('e2b', 'kiri')], 'Belum', '#B45309');
P([T('e2b', 'bawah'), [L2 + WN / 2, BW('e2b') + 26], [L1 + WN / 2, BW('e2b') + 26], T('e3', 'atas')]);
P([T('e2', 'bawah'), T('e3', 'atas')], 'Sudah', '#0D9488');
P([T('e3', 'bawah'), T('e4', 'atas')]);
P([T('e4', 'bawah'), T('e5', 'atas')]);
P([T('e5', 'bawah'), T('e6', 'atas')]);
P([T('e6', 'kanan'), T('e6b', 'kiri')], 'Tidak', '#DC2626');
P([T('e6b', 'bawah'), T('e6c', 'atas')], '', '#DC2626');
P([T('e6', 'bawah'), T('e7', 'atas')], 'Ya', '#0D9488');
P([T('e7', 'bawah'), T('e8', 'atas')]);
P([T('e8', 'bawah'), T('e9', 'atas')]);
P([T('e9', 'kanan'), T('e9n', 'kiri')], '', '#94A3B8', true);
P([T('e9', 'bawah'), T('e10', 'atas')]);
P([T('e10', 'bawah'), T('e11', 'atas')]);
P([T('e11', 'kanan'), T('e11b', 'kiri')], 'Tidak', '#DC2626');
P([T('e11', 'bawah'), T('e12', 'atas')], 'Ya', '#0D9488');
P([T('e12', 'kanan'), [L2 - 26, $SIMPUL['e12']['y'] + $SIMPUL['e12']['h'] / 2], [L2 - 26, BW('e11b') - 24], [L2, BW('e11b') - 24]], 'Tidak', '#DC2626');
P([T('e11b', 'kanan'), [L2 + WN + 34, $SIMPUL['e11b']['y'] + $SIMPUL['e11b']['h'] / 2], [L2 + WN + 34, $SIMPUL['e9']['y'] + 30], T('e9', 'kanan')], 'perbaiki, ajukan ulang', '#94A3B8', true, [L2 + WN + 34, $SIMPUL['e11']['y'] - 34]);
P([T('e12', 'bawah'), T('e13', 'atas')], 'Ya', '#0D9488');
P([T('e13', 'bawah'), T('e14', 'atas')]);
P([T('e14', 'bawah'), T('e15', 'atas')]);
P([T('e15', 'kanan'), T('e15b', 'kiri')], 'Lewat 30 hari', '#B45309');
P([T('e15b', 'kanan'), [L2 + WN + 34, $SIMPUL['e15b']['y'] + $SIMPUL['e15b']['h'] / 2], [L2 + WN + 34, $SIMPUL['e14']['y'] + 28], T('e14', 'kanan')], 'kirim ulang', '#94A3B8', true, [L2 + WN + 34, $SIMPUL['e15']['y'] - 34]);
P([T('e15', 'bawah'), T('e16', 'atas')], 'Ya', '#0D9488');
P([T('e16', 'bawah'), T('e17', 'atas')]);
P([T('e17', 'kanan'), T('e17b', 'kiri')], 'Ya', '#0D9488');
P([T('e17b', 'bawah'), [L2 + WN / 2, BW('e17b') + 26], [L1 + WN / 2, BW('e17b') + 26], T('e18', 'atas')]);
P([T('e17', 'bawah'), T('e18', 'atas')], 'Tidak');
$bab['exhibition'] = bagan($tinggi);

/* ── 3. Gudang & Media ──────────────────────────────────────────────────── */
$y = 0;
S('g1', L1 + 60, $y, WN - 120, 'ujung', 'ujung', 'MULAI', '');                   $y = BW('g1') + 50;
S('g2', L1, $y, WN, 'tanya', 'tanya', 'Client &amp; unit sudah ada di Master Data?', '');
S('g2b', L2, $y + 12, WN, 'proses', 'sales', 'Daftarkan dulu',
    '<b>Master Client</b>, lalu <b>Master Gudang</b> (kode, lokasi, luas, tarif per m&sup2;/bulan) atau <b>Master Media</b> (kode, jenis, titik, ukuran, slot per hari, tarif).');
$y = max(BW('g2'), BW('g2b')) + 50;
S('g3', L1, $y, WN, 'tanya', 'tanya', 'Client minta penawaran resmi dulu?', '');
S('g3b', L2, $y + 12, WN, 'proses', 'sales', 'PINTU B &middot; lewat Surat Penawaran',
    'Buat penawaran dengan modul Gudang/Media, client menandatangani, lalu tekan <b>Buat SKS/Form</b> dari halaman penawaran.');
S('g3c', L2, BW('g3b') + 32, WN, 'proses', 'sales', 'PINTU C &middot; dari transaksi lama',
    'Menu <b>&uarr; Dari transaksi yang sudah ada</b> &rarr; pilih barisnya. Untuk transaksi yang dokumennya belum pernah dibuat.');
S('g3n', L3, BW('g3b') + 52, WC, 'catatan', 'sistem', 'Satu transaksi, satu dokumen',
    'Yang sudah punya akan membuka dokumen itu, bukan membuat yang kedua.');
$y = max(BW('g3'), BW('g3c')) + 50;
S('g4', L1, $y, WN, 'proses', 'sales', 'PINTU A &middot; buat dokumen langsung',
    'Menu <b>SKP Pameran</b> &rarr; tombol <b>SKS Gudang</b> atau <b>Form Utilities</b>. Seluruh isian diketik di formulir itu. Cara yang paling sering dipakai.'); $y = BW('g4') + 50;
S('g5', L1, $y, WN, 'proses', 'sales', 'Lengkapi lalu ajukan',
    'Client, kontak PJ, kode unit, periode, luas, tarif, biaya listrik, deposit, dan lampiran. Periksa dulu dengan <b>Lihat Contoh Surat</b>.'); $y = BW('g5') + 50;
S('g6', L1, $y, WN, 'tanya', 'asst', 'Diparaf ' . ($tahap['sks'][1]['label'] ?? $lbl1) . '?', (string) ($tahap['sks'][1]['orang'] ?? $org1));
S('g6b', L2, $y + 12, WN, 'proses', 'stop', 'Dikembalikan ke sales', 'Diperbaiki lalu diajukan ulang — lihat bagian Koreksi.');
$y = max(BW('g6'), BW('g6b')) + 50;
S('g7', L1, $y, WN, 'tanya', 'manager', 'Disetujui ' . ($tahap['sks'][2]['label'] ?? $lbl2) . '?', (string) ($tahap['sks'][2]['orang'] ?? $org2)); $y = BW('g7') + 50;
S('g8', L1, $y, WN, 'hasil', 'hasil', 'NOMOR SKS / FU TERBIT',
    'Transaksinya lahir di sini juga — pada Pintu A memang belum ada sebelumnya.'); $y = BW('g8') + 42;
S('g9', L1, $y, WN, 'proses', 'sales', 'Kirim tautan tanda tangan', 'Berlaku 30 hari sejak dokumen disetujui.'); $y = BW('g9') + 50;
S('g10', L1, $y, WN, 'tanya', 'client', 'Client meneken?', '');
S('g10b', L2, $y + 12, WN, 'proses', 'sistem', 'Menunggu di daftar "Perlu TTD"', 'Nilainya belum terhitung.');
$y = max(BW('g10'), BW('g10b')) + 50;
S('g11', L1, $y, WN, 'hasil', 'hasil', 'INCOME MASUK LAPORAN',
    'Sewa berbulan-bulan dipecah per bulan secara otomatis, jadi tiap bulan kebagian porsinya.');
$tinggi = BW('g11') + 30;

P([T('g1', 'bawah'), T('g2', 'atas')]);
P([T('g2', 'kanan'), T('g2b', 'kiri')], 'Belum', '#B45309');
P([T('g2b', 'bawah'), [L2 + WN / 2, BW('g2b') + 26], [L1 + WN / 2, BW('g2b') + 26], T('g3', 'atas')]);
P([T('g2', 'bawah'), T('g3', 'atas')], 'Sudah', '#0D9488');
P([T('g3', 'kanan'), T('g3b', 'kiri')], 'Ya', '#0D9488');
P([T('g3b', 'bawah'), T('g3c', 'atas')], 'atau', '#94A3B8', true);
P([T('g3c', 'kanan'), T('g3n', 'kiri')], '', '#94A3B8', true);
P([T('g3', 'bawah'), T('g4', 'atas')], 'Tidak');
P([T('g3c', 'bawah'), [L2 + WN / 2, BW('g3c') + 30], [L1 + WN / 2 + 90, BW('g3c') + 30], [L1 + WN / 2 + 90, $SIMPUL['g5']['y'] - 22], T('g5', 'atas')]);
P([T('g4', 'bawah'), T('g5', 'atas')]);
P([T('g5', 'bawah'), T('g6', 'atas')]);
P([T('g6', 'kanan'), T('g6b', 'kiri')], 'Tidak', '#DC2626');
P([T('g6', 'bawah'), T('g7', 'atas')], 'Ya', '#0D9488');
P([T('g7', 'kanan'), [L2 - 26, $SIMPUL['g7']['y'] + $SIMPUL['g7']['h'] / 2], [L2 - 26, BW('g6b') - 24], [L2, BW('g6b') - 24]], 'Tidak', '#DC2626');
P([T('g6b', 'kanan'), [L2 + WN + 34, $SIMPUL['g6b']['y'] + $SIMPUL['g6b']['h'] / 2], [L2 + WN + 34, $SIMPUL['g5']['y'] + 26], T('g5', 'kanan')], 'ajukan ulang', '#94A3B8', true, [L2 + WN + 34, $SIMPUL['g6']['y'] - 34]);
P([T('g7', 'bawah'), T('g8', 'atas')], 'Ya', '#0D9488');
P([T('g8', 'bawah'), T('g9', 'atas')]);
P([T('g9', 'bawah'), T('g10', 'atas')]);
P([T('g10', 'kanan'), T('g10b', 'kiri')], 'Belum', '#B45309');
P([T('g10', 'bawah'), T('g11', 'atas')], 'Ya', '#0D9488');
$bab['gudang'] = bagan($tinggi);

/* ── 4. Perpanjangan & kasus data ───────────────────────────────────────── */
$y = 0;
S('r0', L1 + 60, $y, WN - 120, 'ujung', 'ujung', 'KONTRAK HAMPIR HABIS', '');    $y = BW('r0') + 46;
S('r1', L1, $y, WN, 'proses', 'sales', 'Buka Papan Renewal',
    'Isinya kontrak yang berakhir dalam <b>30 hari</b> ke depan atau baru lewat <b>7 hari</b>. Yang tersisa &le;15 hari ditandai merah.'); $y = BW('r1') + 42;
S('r2', L1, $y, WN, 'proses', 'sales', 'Catat status pendekatan',
    'Belum dihubungi &rarr; sedang nego &rarr; dan seterusnya, supaya tidak ada yang terlewat.'); $y = BW('r2') + 50;
S('r3', L1, $y, WN, 'tanya', 'tanya', 'Client mau perpanjang?', '');
S('r3b', L2, $y + 12, WN, 'proses', 'sistem', 'Tandai saja di papan', 'Kontraknya tetap tercatat sebagai tidak diperpanjang.');
$y = max(BW('r3'), BW('r3b')) + 50;
S('r4', L1, $y, WN, 'proses', 'sales', 'Tekan + Perpanjang',
    'Terbuka <b>Surat Penawaran baru</b> yang sudah terisi data kontrak lama. Lanjutkan seperti jalur baku.');
S('r4n', L2, $y + 12, WN, 'catatan', 'sales', 'Jalan pintas tanpa penawaran',
    'Dari daftar <b>Transaksi</b>, tombol <b>Perpanjang</b> pada baris kontraknya. Status Sewa di dokumennya otomatis terisi "Perpanjangan".');
$y = max(BW('r4'), BW('r4n')) + 80;

S('c0', L1 + 60, $y, WN - 120, 'ujung', 'ujung', 'SATU DEAL, BEBERAPA PIC', ''); $y = BW('c0') + 46;
S('c1', L1, $y, WN, 'tanya', 'tanya', 'Satu acara dikerjakan beberapa PIC?', '');
S('c1b', L2, $y + 12, WN, 'proses', 'sales', 'Jalur biasa', 'Satu PIC, satu dokumen — tidak ada yang perlu dibagi.');
$y = max(BW('c1'), BW('c1b')) + 50;
S('c2', L1, $y, WN, 'proses', 'stop', 'Jangan buat transaksi sendiri-sendiri',
    'Nilainya akan terhitung berkali-kali di Dashboard, Laporan PIC, dan Achievement.'); $y = BW('c2') + 42;
S('c3', L1, $y, WN, 'proses', 'sales', 'Buat SATU dokumen, lalu isi Pembagian PIC',
    'Nilai kontraknya dibagi sesuai porsi masing-masing, bukan digandakan.');
S('c3n', L2, $y + 12, WN, 'catatan', 'sistem', 'Kalau tetap dicoba',
    'Sejak 23 Oktober 2026 sistem menolak transaksi yang unit, client, dan periodenya sama persis — lengkap dengan nomor transaksi yang sudah ada.');
$y = max(BW('c3'), BW('c3n')) + 42;
S('c4', L1, $y, WN, 'catatan', 'sales', 'Beberapa unit untuk satu acara',
    'Nyalakan <b>paket</b> di penawaran lalu tambahkan komponennya. Satu dokumen memayungi semuanya; saat disetujui, lahir satu transaksi per komponen.');
$tinggi = BW('c4') + 30;

P([T('r0', 'bawah'), T('r1', 'atas')]);
P([T('r1', 'bawah'), T('r2', 'atas')]);
P([T('r2', 'bawah'), T('r3', 'atas')]);
P([T('r3', 'kanan'), T('r3b', 'kiri')], 'Tidak', '#94A3B8');
P([T('r3', 'bawah'), T('r4', 'atas')], 'Ya', '#0D9488');
P([T('r4', 'kanan'), T('r4n', 'kiri')], 'atau', '#94A3B8', true);
P([T('c0', 'bawah'), T('c1', 'atas')]);
P([T('c1', 'kanan'), T('c1b', 'kiri')], 'Tidak', '#94A3B8');
P([T('c1', 'bawah'), T('c2', 'atas')], 'Ya', '#DC2626');
P([T('c2', 'bawah'), T('c3', 'atas')]);
P([T('c3', 'kanan'), T('c3n', 'kiri')], '', '#94A3B8', true);
P([T('c3', 'bawah'), T('c4', 'atas')], '', '#94A3B8', true);
$bab['perpanjangan'] = bagan($tinggi);

/* ── 5. Koreksi ─────────────────────────────────────────────────────────── */
$y = 0;
S('k0', L1 + 60, $y, WN - 120, 'ujung', 'ujung', 'ADA YANG SALAH DI DOKUMEN', ''); $y = BW('k0') + 50;
S('k1', L1, $y, WN, 'tanya', 'tanya', 'Sudah diajukan?', '');
S('k1b', L2, $y + 12, WN, 'proses', 'sales', 'Masih draft — perbaiki langsung',
    'Buka, ubah isinya, simpan. Tidak perlu izin siapa pun. Mau dibuang pun boleh: nomornya belum terbit, jadi tidak ada urutan yang bolong.');
$y = max(BW('k1'), BW('k1b')) + 50;
S('k2', L1, $y, WN, 'tanya', 'tanya', 'Sudah disetujui ' . $lbl2 . '?', '');
S('k2b', L2, $y + 12, WN, 'proses', 'asst', 'Masih menunggu approval',
    'Pemeriksa punya dua pilihan: <b>Kembalikan satu tahap</b> (nilai &amp; transaksinya tidak diubah), atau <b>Tolak ke PIC</b> (hanya PIC yang bisa memperbaiki, lalu mengajukan ulang dari awal).');
S('k2n', L3, $y + 32, WC, 'catatan', 'sistem', 'Yang ikut terjadi',
    'Alokasi income dilepas supaya berhenti terhitung selama diperbaiki. Transaksinya sengaja tidak dihapus.');
$y = max(BW('k2'), BW('k2b')) + 50;
S('k3', L1, $y, WN, 'tanya', 'tanya', 'Client sudah meneken?', '');
S('k3b', L2, $y + 12, WN, 'proses', 'sales', 'Sudah disetujui, belum diteken',
    'Ajukan <b>permintaan revisi</b> beserta alasannya, lalu diputus ' . $lbl1 . '. Nomornya tetap sama — hanya naik versi.');
$y = max(BW('k3'), BW('k3b')) + 50;
S('k4', L1, $y, WN, 'tanya', 'tanya', 'Datanya memang harus hilang?', '');
S('k4b', L2, $y + 12, WN, 'proses', 'stop', 'Sudah diteken — terkunci',
    'Tidak bisa direvisi lagi. Kalau isinya memang berubah, <b>terbitkan dokumen baru</b>. Kalau kontraknya batal jalan, pakai <b>Batalkan Kesepakatan</b>.');
$y = max(BW('k4'), BW('k4b')) + 50;
S('k5', L1, $y, WN, 'proses', 'sales', 'Ajukan Hapus Data',
    'Sertakan alasan yang jelas — alasan itu yang dibaca pemutus.');                 $y = BW('k5') + 50;
S('k6', L1, $y, WN, 'tanya', 'manager', 'Disetujui pemutus?', '');
S('k6b', L2, $y + 12, WN, 'proses', 'stop', 'Ditolak — data tetap ada', 'Statusnya batal, alasan penolakannya ikut tercatat.');
$y = max(BW('k6'), BW('k6b')) + 50;
S('k7', L1, $y, WN, 'hasil', 'hasil', 'Pindah ke Data Terhapus',
    'Bukan hilang — masih bisa dipulihkan bila ternyata keliru.');
S('k7n', L2, $y + 12, WN, 'catatan', 'sistem', 'Semuanya tercatat',
    'Siapa yang mengajukan, siapa yang memutus, kapan, dan alasannya tersimpan di jejak audit.');
$tinggi = max(BW('k7'), BW('k7n')) + 30;

P([T('k0', 'bawah'), T('k1', 'atas')]);
P([T('k1', 'kanan'), T('k1b', 'kiri')], 'Belum', '#0D9488');
P([T('k1', 'bawah'), T('k2', 'atas')], 'Sudah');
P([T('k2', 'kanan'), T('k2b', 'kiri')], 'Belum', '#0D9488');
P([T('k2b', 'kanan'), T('k2n', 'kiri')], '', '#94A3B8', true);
P([T('k2', 'bawah'), T('k3', 'atas')], 'Sudah');
P([T('k3', 'kanan'), T('k3b', 'kiri')], 'Belum', '#0D9488');
P([T('k3', 'bawah'), T('k4', 'atas')], 'Sudah');
P([T('k4', 'kanan'), T('k4b', 'kiri')], 'Tidak', '#0D9488');
P([T('k4', 'bawah'), T('k5', 'atas')], 'Ya', '#DC2626');
P([T('k5', 'bawah'), T('k6', 'atas')]);
P([T('k6', 'kanan'), T('k6b', 'kiri')], 'Tidak', '#DC2626');
P([T('k6', 'bawah'), T('k7', 'atas')], 'Ya', '#0D9488');
P([T('k7', 'kanan'), T('k7n', 'kiri')], '', '#94A3B8', true);
$bab['koreksi'] = bagan($tinggi);

// ═══════════════════════════════════════════════════════════════════════════
//  Halaman
// ═══════════════════════════════════════════════════════════════════════════
$CSS = <<<'CSS'
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --tinta:#0F172A; --redup:#64748B; --garis:#E2E8F0; --latar:#F1F5F9;
  --merek:#0D9488; --kartu:#FFFFFF;
}
html{scroll-behavior:smooth}
body{font-family:"Inter","Segoe UI",-apple-system,BlinkMacSystemFont,Helvetica,Arial,sans-serif;
     background:var(--latar);color:var(--tinta);line-height:1.55;
     -webkit-font-smoothing:antialiased}
.bungkus{max-width:1360px;margin:0 auto;padding:0 28px}

/* ── kepala ─────────────────────────────────────────────── */
.kepala{background:linear-gradient(160deg,#0F766E 0%,#115E59 55%,#134E4A 100%);color:#fff;
        padding:40px 0 34px}
.kepala .logo{height:54px;background:#fff;border-radius:10px;padding:7px 14px;display:inline-block}
.kepala h1{font-size:40px;font-weight:800;letter-spacing:-1.1px;margin:22px 0 10px;line-height:1.1}
.kepala p{font-size:17px;color:#A7F3D0;max-width:760px}
.kepala .cap{display:inline-block;margin-top:18px;font-size:13px;color:#CCFBF1;
             background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22);
             padding:6px 14px;border-radius:999px}

/* ── navigasi lengket ───────────────────────────────────── */
.nav{position:sticky;top:0;z-index:50;background:rgba(255,255,255,.92);
     backdrop-filter:blur(10px);border-bottom:1px solid var(--garis)}
.nav .bungkus{display:flex;gap:6px;overflow-x:auto;padding-top:10px;padding-bottom:10px}
.nav a{flex:none;font-size:14px;font-weight:600;color:var(--redup);text-decoration:none;
       padding:8px 16px;border-radius:999px;white-space:nowrap;transition:.15s}
.nav a:hover{background:#F1F5F9;color:var(--tinta)}
.nav a.utama{background:var(--merek);color:#fff}
.nav a.utama:hover{background:#0F766E;color:#fff}

/* ── bagian ─────────────────────────────────────────────── */
section{padding:52px 0 8px;scroll-margin-top:66px}
.judul{display:flex;align-items:baseline;gap:14px;flex-wrap:wrap;margin-bottom:8px}
.judul h2{font-size:28px;font-weight:800;letter-spacing:-.6px}
.judul .no{font-size:13px;font-weight:700;color:var(--merek);background:#CCFBF1;
           padding:4px 11px;border-radius:999px;letter-spacing:.4px}
.lead{font-size:16px;color:var(--redup);max-width:900px;margin-bottom:26px}
.lead b{color:var(--tinta)}

.kartu{background:var(--kartu);border:1px solid var(--garis);border-radius:18px;
       padding:26px 22px;box-shadow:0 1px 2px rgba(15,23,42,.04),0 10px 30px -18px rgba(15,23,42,.25);
       overflow-x:auto}

/* ── bagan ──────────────────────────────────────────────── */
.bagan{position:relative;width:1240px;margin:0 auto}
.bagan svg{position:absolute;inset:0;display:block}
.bagan .tx{position:absolute;display:flex;flex-direction:column;align-items:center;
           justify-content:center;text-align:center;pointer-events:none}
.bagan .tx b{font-size:16px;font-weight:700;line-height:1.3;letter-spacing:-.2px}
.bagan .tx i{font-style:normal;font-size:13px;color:#475569;line-height:1.5;margin-top:7px}
.bagan .tx i b{font-size:13px;color:#1E293B}
.bagan .lb{position:absolute;transform:translate(-50%,-50%);font-size:12px;font-weight:700;
           background:#fff;border:1.5px solid;border-radius:999px;padding:3px 11px;
           white-space:nowrap;letter-spacing:.2px}

/* ── tabel rujukan ──────────────────────────────────────── */
.dua{display:grid;grid-template-columns:1fr 1fr;gap:22px}
table{width:100%;border-collapse:separate;border-spacing:0;font-size:14.5px}
caption{text-align:left;font-size:15px;font-weight:800;padding:0 0 12px;color:var(--merek)}
th,td{text-align:left;padding:12px 14px;border-bottom:1px solid var(--garis);vertical-align:top}
tr:last-child td{border-bottom:none}
td.k{width:38%;font-weight:700;color:var(--tinta)}
td.v{color:#475569}

/* ── legenda ────────────────────────────────────────────── */
.legenda{display:flex;flex-wrap:wrap;gap:10px 26px;font-size:13.5px;color:#475569;
         background:#fff;border:1px solid var(--garis);border-radius:14px;padding:16px 20px;
         margin-bottom:26px}
.legenda .baris2{flex-basis:100%;border-top:1px solid var(--garis);padding-top:12px;
                 margin-top:2px;flex-wrap:wrap;gap:8px 16px}
.legenda span{display:flex;align-items:center;gap:9px}
.legenda .f{width:22px;height:15px;border-radius:4px;border:2px solid;flex:none}
.legenda .f.d{width:16px;height:16px;border-radius:2px;transform:rotate(45deg)}
.legenda .f.u{border-radius:999px}
.legenda .p{width:14px;height:14px;border-radius:4px;flex:none}

/* ── kaki ───────────────────────────────────────────────── */
.kaki{margin-top:56px;padding:30px 0 44px;border-top:1px solid var(--garis);
      font-size:13.5px;color:var(--redup)}
.kaki img{width:230px;height:auto;display:block;margin-bottom:14px;mix-blend-mode:multiply}

@media (max-width:980px){
  .dua{grid-template-columns:1fr}
  .kepala h1{font-size:30px}
  .bungkus{padding:0 16px}
}
CSS;

$nav = [
    'ringkas'      => ['01', 'Ringkasan'],
    'exhibition'   => ['02', 'Exhibition'],
    'gudang'       => ['03', 'Gudang &amp; Media'],
    'perpanjangan' => ['04', 'Perpanjangan &amp; PIC'],
    'koreksi'      => ['05', 'Koreksi &amp; hapus'],
    'rujukan'      => ['06', 'Rujukan isian'],
];

$lead = [
    'ringkas' => 'Apa pun modulnya, begitu dokumen diajukan jalurnya <b>sama persis</b>: diparaf, disetujui, '
        . 'ditandatangani client, baru masuk laporan. Yang membedakan ketiga modul hanya pintu masuknya.',
    'exhibition' => 'Jalur baku untuk sewa unit pameran. Dokumennya <b>SKP</b>. '
        . 'Ikuti garis di lajur kiri dari atas ke bawah; yang melebar ke kanan adalah cabang kalau jawabannya tidak.',
    'gudang' => 'Dokumennya <b>SKS</b> (Gudang) dan <b>FU</b> (Media). Berbeda dari Exhibition, keduanya '
        . '<b>tidak wajib lewat Surat Penawaran</b> — ada tiga pintu masuk yang bertemu di satu titik.',
    'perpanjangan' => 'Dua keadaan yang paling sering bikin bingung: kontrak yang hampir habis, dan satu acara '
        . 'yang dijual beberapa PIC bersama.',
    'koreksi' => 'Yang masih bisa dilakukan berbeda-beda menurut sejauh mana dokumennya sudah berjalan. '
        . 'Turuti rantai pertanyaan di lajur kiri.',
    'rujukan' => 'Dua hal ini bukan alur, melainkan pilihan di dalam formulir — dikumpulkan di sini supaya '
        . 'bagannya tetap ringkas.',
];

ob_start(); ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Alur Kerja CLARA</title>
<style><?= $CSS ?></style>
</head>
<body>

<header class="kepala">
  <div class="bungkus">
    <?php if ($logoMal): ?><img class="logo" src="<?= $logoMal ?>" alt="e-Walk &amp; Pentacity"><?php endif; ?>
    <h1>Alur Kerja CLARA</h1>
    <p>Bagaimana sebuah kesepakatan berjalan di CLARA, dari permintaan client sampai angkanya
       muncul di laporan — untuk Exhibition, Gudang, dan Media.</p>
    <span class="cap">Dibuat <?= $h(date('j') . ' ' . $BULAN[(int) date('n')] . ' ' . date('Y')) ?> ·
      alur persetujuan dibaca langsung dari sistem</span>
  </div>
</header>

<nav class="nav"><div class="bungkus">
  <?php foreach ($nav as $id => [$no, $teks]): ?>
    <a href="#<?= $id ?>"<?= $id === 'ringkas' ? ' class="utama"' : '' ?>><?= $teks ?></a>
  <?php endforeach; ?>
</div></nav>

<main class="bungkus">

  <section id="ringkas">
    <div class="judul"><span class="no">01</span><h2>Ringkasan</h2></div>
    <p class="lead"><?= $lead['ringkas'] ?></p>
    <div class="legenda">
      <span><i class="f u" style="background:#E2E8F0;border-color:#CBD5E1"></i> Mulai / selesai</span>
      <span><i class="f" style="background:#F0FDFA;border-color:#99F6E4"></i> Langkah</span>
      <span><i class="f d" style="background:#FFF7ED;border-color:#FED7AA"></i> Titik keputusan</span>
      <span><i class="f" style="background:#F0FDF4;border-color:#4ADE80;border-width:3px"></i> Titik yang menentukan angka</span>
      <span><i class="f" style="background:#FEF2F2;border-color:#FECACA"></i> Jalan kalau tidak lolos</span>
      <span><i class="f" style="background:#F8FAFC;border-color:#E2E8F0;border-style:dashed"></i> Catatan</span>
      <span class="baris2">Pita kiri = pelakunya:
        <i class="p" style="background:#0F766E"></i> Sales
        <i class="p" style="background:#B45309"></i> <?= $h($pem1) ?>
        <i class="p" style="background:#6D28D9"></i> <?= $h($pem2) ?>
        <i class="p" style="background:#0369A1"></i> Client
        <i class="p" style="background:#BE185D"></i> Legal
        <i class="p" style="background:#475569"></i> Sistem</span>
    </div>
    <div class="kartu"><?= $bab['ringkas'] ?></div>
  </section>

  <?php foreach (['exhibition', 'gudang', 'perpanjangan', 'koreksi'] as $id): ?>
  <section id="<?= $id ?>">
    <div class="judul"><span class="no"><?= $nav[$id][0] ?></span><h2><?= $nav[$id][1] ?></h2></div>
    <p class="lead"><?= $lead[$id] ?></p>
    <div class="kartu"><?= $bab[$id] ?></div>
  </section>
  <?php endforeach; ?>

  <section id="rujukan">
    <div class="judul"><span class="no">06</span><h2>Rujukan isian</h2></div>
    <p class="lead"><?= $lead['rujukan'] ?></p>
    <div class="dua">
      <div class="kartu"><table>
        <caption>Bagian Harga di Surat Penawaran</caption>
        <tr><td class="k">Total Kontrak</td><td class="v">Terhitung sendiri dari tarif &times; luas &times; lama sewa. Tidak bisa diketik.</td></tr>
        <tr><td class="k">Harga Nego Final</td><td class="v">Diisi hanya bila hasil nego berbeda. <b>Angka inilah yang dipakai</b>, menggantikan hitungan otomatis.</td></tr>
        <tr><td class="k">Harga / Bulan</td><td class="v">Boleh dikosongkan. Kosong atau 0 &rarr; barisnya <b>tidak dicetak</b> di surat maupun PDF.</td></tr>
        <tr><td class="k">Jadwal Harga Bertahap</td><td class="v">Untuk kontrak yang tarifnya naik di tengah jalan. Tiap tahap punya tanggal berlakunya sendiri.</td></tr>
        <tr><td class="k">Biaya Listrik</td><td class="v">Ikut masuk nilai kontrak. Bisa dimatikan — yang dimatikan tidak muncul di surat.</td></tr>
        <tr><td class="k">PPN 12%</td><td class="v">Bawaannya menyala. Lepas centang bila harganya sudah bersih.</td></tr>
        <tr><td class="k">Service Charge</td><td class="v">Bawaannya mati. Ditagih per bulan, tercetak sebagai blok tersendiri.</td></tr>
        <tr><td class="k">DP &amp; Deposit</td><td class="v">Dalam bulan atau nominal, plus centang &ldquo;Security Deposit sudah dibayarkan&rdquo;.</td></tr>
      </table></div>
      <div class="kartu"><table>
        <caption>Kapan nilainya masuk bulan mana</caption>
        <tr><td class="k">Otomatis (ikut periode)</td><td class="v">Bawaannya. Multi-bulan atau lintas bulan jadi <i>Spread</i>; selainnya <i>Sekaligus</i>.</td></tr>
        <tr><td class="k">Sekaligus (anchor)</td><td class="v">Seluruh nilainya diakui di satu bulan.</td></tr>
        <tr><td class="k">Spread per Bulan</td><td class="v">Dipecah ke tiap bulan masa sewa. Dipakai kontrak panjang seperti gudang.</td></tr>
        <tr><td class="k">Pengakuan per Siklus</td><td class="v">Bulan awal siklus, atau bulan akhir siklus.</td></tr>
        <tr><td class="k">Prorata ikut bulan kalender</td><td class="v">Centang bila kontrak tidak mulai tanggal 1. Bulan yang tidak penuh dihitung per hari (11&ndash;30 Nov = 20/30 bulan). Tanpa centang, siklusnya ikut tanggal mulai (11 &rarr; 10) dan totalnya bisa beda ratusan ribu.</td></tr>
        <tr><td class="k">Diakui Recurring</td><td class="v">Centang bila kontraknya berulang. Diteruskan ke transaksi saat konfirmasi disetujui.</td></tr>
      </table></div>
      <div class="kartu" style="grid-column:1/-1"><table>
        <caption>Tiga hal yang paling sering salah paham</caption>
        <tr><td class="k">Nomor dokumen terbit &amp; transaksi lahir</td>
            <td class="v">Saat <b><?= $h($pem2) ?></b> menekan <b>Setujui</b> — bukan saat dokumen dibuat, bukan pula saat diparaf.</td></tr>
        <tr><td class="k">Income masuk Dashboard &amp; Achievement</td>
            <td class="v">Saat <b>client menandatangani</b> surat konfirmasi<?= $ttdTgl ? ". Aturan ini mengikat kesepakatan yang dibuat sejak <b>" . $h($ttdTgl) . "</b>; yang lebih lama tetap memakai cara lama" : '' ?>.</td></tr>
        <tr><td class="k">Tautan tanda tangan</td>
            <td class="v">Berlaku <b>30 hari</b> sejak dokumen disetujui. Lewat dari itu client tidak bisa lagi membukanya, dan jalan keluarnya lewat permintaan revisi.</td></tr>
      </table></div>
    </div>
  </section>

  <footer class="kaki">
    <?php if ($logoWbl): ?><img src="<?= $logoWbl ?>" alt="PT Wulandari Bangun Laksana, Tbk"><?php endif; ?>
    Casual Leasing Achievement &amp; Revenue Analytics (CLARA) &middot;
    e-Walk Simply FUNtastic &amp; Pentacity Shopping Venue.<br>
    Alur persetujuan dan nama pemaraf pada halaman ini dibaca langsung dari pengaturan sistem saat
    dokumen ini dibangun — bila alurnya diubah di halaman <b>Alur Persetujuan</b>, bangun ulang
    halaman ini agar ikut berubah.
  </footer>
</main>

<script>
// Sorot menu bagian yang sedang dibaca.
var bagian = [].slice.call(document.querySelectorAll('section'));
var tautan = {};
[].forEach.call(document.querySelectorAll('.nav a'), function (a) { tautan[a.getAttribute('href').slice(1)] = a; });
var pengamat = new IntersectionObserver(function (masuk) {
    masuk.forEach(function (e) {
        if (!e.isIntersecting) return;
        for (var k in tautan) tautan[k].classList.remove('utama');
        if (tautan[e.target.id]) tautan[e.target.id].classList.add('utama');
    });
}, { rootMargin: '-64px 0px -70% 0px' });
bagian.forEach(function (s) { pengamat.observe(s); });
</script>
</body>
</html>
<?php
$html = (string) ob_get_clean();
$berkas = $keluar . '/Alur_Kerja_CLARA.html';
file_put_contents($berkas, $html);
printf("HTML  %s (%s KB)\n", $berkas, number_format(strlen($html) / 1024, 0));
