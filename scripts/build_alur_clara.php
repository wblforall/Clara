<?php
/**
 * Papan alur kerja CLARA — satu berkas HTML, kanvas tanpa batas.
 *
 * Bukan halaman bersusun dan bukan PDF. Dipaksa masuk ukuran kertas, bagannya
 * berdesakan; dipaksa masuk satu kolom halaman web, alurnya jadi memanjang ke
 * bawah saja. Di sini seluruh alur ditaruh berdampingan di satu papan lebar
 * yang bisa digeser dan dizum, seperti membaca denah.
 *
 * ── Supaya garisnya tidak pernah bertabrakan ────────────────────────────────
 * Tiap bagan dibagi jadi lajur tetap, dan tiap jenis garis punya jatah
 * lorongnya sendiri:
 *
 *   LAJUR 1  alur utama. Sambungannya selalu tegak lurus antar-kotak
 *            berurutan, jadi mustahil bertabrakan.
 *   LORONG A kosong. Dilewati garis BALIK dari lajur 2 menuju lajur 1.
 *   LAJUR 2  cabang. Tiap kotak cabang ditaruh SEJAJAR TENGAH dengan belah
 *            ketupat asalnya, sehingga garisnya mendatar murni.
 *   LORONG B kosong. Dilewati garis "tidak" yang harus naik/turun dulu
 *            sebelum masuk cabang — masuk lewat sisi KANAN, supaya tidak
 *            berpapasan dengan garis balik yang keluar lewat sisi KIRI.
 *   LAJUR 3  catatan. Hanya disambung dari lajur 2, jadi garisnya tidak
 *            pernah memotong lajur 2.
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
$sks1 = (string) ($tahap['sks'][1]['orang'] ?? $org1);
$sks2 = (string) ($tahap['sks'][2]['orang'] ?? $org2);
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

// ═══════════════════════════════════════════════════════════════════════════
//  Mesin bagan — satuan piksel di dalam papan
// ═══════════════════════════════════════════════════════════════════════════

const W1 = 420, W2 = 420, W3 = 300;
const LJ1 = 0, LJ2 = 560, LJ3 = 1120;
const GA  = 490;                    // lorong A: garis balik lajur 2 → lajur 1
const GB1 = 1020, GB2 = 1056;       // lorong B: garis "tidak" yang naik dulu
const WPAPAN = 1420;                // lebar satu bagan

const WARNA = [
    'sales'   => ['#0F766E', '#F0FDFA', '#99F6E4'],
    'asst'    => ['#B45309', '#FFFBEB', '#FDE68A'],
    'manager' => ['#6D28D9', '#F5F3FF', '#DDD6FE'],
    'client'  => ['#0369A1', '#F0F9FF', '#BAE6FD'],
    'legal'   => ['#BE185D', '#FDF2F8', '#FBCFE8'],
    'sistem'  => ['#475569', '#F8FAFC', '#E2E8F0'],
    'tanya'   => ['#C2410C', '#FFF7ED', '#FDBA74'],
    'hasil'   => ['#15803D', '#F0FDF4', '#86EFAC'],
    'stop'    => ['#B91C1C', '#FEF2F2', '#FECACA'],
    'ujung'   => ['#1E293B', '#E2E8F0', '#CBD5E1'],
];
const PANAH = ['#94A3B8' => 'p0', '#DC2626' => 'p1', '#B45309' => 'p2', '#0D9488' => 'p3'];

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
    $kata = preg_split('/\s+/', trim(strip_tags($teks))) ?: [];
    $n = 1; $w = 0.0;
    foreach ($kata as $k) {
        if ($k === '') continue;
        $lw = 0.0;
        foreach (preg_split('//u', html_entity_decode($k, ENT_QUOTES, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) {
            $lw += _lebarAksara($c, $fs, $tebal);
        }
        $spasi = $w > 0 ? $fs * 0.30 : 0.0;
        if ($w > 0 && $w + $spasi + $lw > $lebar) { $n++; $w = $lw; }
        else                                      { $w += $spasi + $lw; }
    }
    return $n;
}

$SIMPUL = []; $GARIS = []; $LABEL = []; $MAXX = 0.0;

/** Daftarkan simpul; tingginya dihitung dari isinya. */
function S(string $id, float $x, float $y, float $w, string $bentuk, string $peran,
           string $judul, string $isi = ''): void
{
    global $SIMPUL;
    $fsJ = 16.0; $fsI = 13.0;
    $pad = $bentuk === 'tanya' ? $w * 0.22 : 22.0;
    $lt  = $w - 2 * $pad;
    $nJ  = _barisTeks($judul, $lt, $fsJ, true);
    $nI  = $isi !== '' ? _barisTeks($isi, $lt, $fsI, false) : 0;
    $hT  = $nJ * ($fsJ * 1.35) + ($nI ? 7 + $nI * ($fsI * 1.5) : 0);
    $h   = $hT + 36;
    if ($bentuk === 'tanya') $h = $hT + 78;
    if ($bentuk === 'ujung') $h = max($h, 58);
    $SIMPUL[$id] = compact('x', 'y', 'w', 'h', 'bentuk', 'peran', 'judul', 'isi') + ['pad' => $pad];
    $GLOBALS['MAXX'] = max($GLOBALS['MAXX'], $x + $w);
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
function BW(string $id): float { global $SIMPUL; return $SIMPUL[$id]['y'] + $SIMPUL[$id]['h']; }
function CY(string $id): float { global $SIMPUL; return $SIMPUL[$id]['y'] + $SIMPUL[$id]['h'] / 2; }
/** Geser simpul supaya tengahnya sejajar simpul lain — bikin garisnya mendatar murni. */
function sejajar(string $id, string $dengan): void
{
    global $SIMPUL;
    $SIMPUL[$id]['y'] = CY($dengan) - $SIMPUL[$id]['h'] / 2;
}

function svgSimpul(array $n): string
{
    [$grs, $bg, $tepi] = WARNA[$n['peran']] ?? WARNA['sistem'];
    $x = $n['x']; $y = $n['y']; $w = $n['w']; $hh = $n['h'];
    if ($n['bentuk'] === 'tanya') {
        $p = sprintf('%.1f,%.1f %.1f,%.1f %.1f,%.1f %.1f,%.1f',
            $x + $w / 2, $y, $x + $w, $y + $hh / 2, $x + $w / 2, $y + $hh, $x, $y + $hh / 2);
        return '<polygon points="' . $p . '" fill="' . $bg . '" stroke="' . $tepi . '" stroke-width="2.4"/>';
    }
    if ($n['bentuk'] === 'ujung') {
        return '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $hh . '" rx="' . ($hh / 2)
             . '" fill="' . $bg . '" stroke="' . $tepi . '" stroke-width="2.4"/>';
    }
    $putus = $n['bentuk'] === 'catatan' ? ' stroke-dasharray="8 6"' : '';
    $tbl   = $n['bentuk'] === 'hasil' ? 3.4 : 1.8;
    $s = '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $hh . '" rx="14" fill="'
       . $bg . '" stroke="' . $tepi . '" stroke-width="' . $tbl . '"' . $putus . '/>';
    if ($n['bentuk'] !== 'catatan') {
        $s .= '<path d="M' . ($x + 14) . ' ' . $y . ' h-2 a14,14 0 0 0 -12,14 V' . ($y + $hh - 14)
            . ' a14,14 0 0 0 12,14 h2 Z" fill="' . $grs . '"/>';
    }
    return $s;
}

function htmlSimpul(array $n): string
{
    [$grs] = WARNA[$n['peran']] ?? WARNA['sistem'];
    $st = 'left:' . round($n['x'] + $n['pad']) . 'px;top:' . round($n['y']) . 'px;'
        . 'width:' . round($n['w'] - 2 * $n['pad']) . 'px;height:' . round($n['h']) . 'px;';
    $s = '<div class="tx" style="' . $st . '"><b style="color:' . $grs . '">' . $n['judul'] . '</b>';
    if ($n['isi'] !== '') $s .= '<i>' . $n['isi'] . '</i>';
    return $s . '</div>';
}

/** Polyline bersiku dengan tikungan membulat. */
function _rute(array $pts, float $r = 16): string
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
    $l = end($pts);
    return $d . ' L' . round($l[0], 1) . ' ' . round($l[1], 1);
}

function P(array $titik, string $label = '', string $warna = '#94A3B8', bool $putus = false, ?array $lp = null): void
{
    global $GARIS, $LABEL, $MAXX;
    foreach ($titik as [$px, ]) $MAXX = max($MAXX, $px);
    $m = PANAH[$warna] ?? 'p0';
    $GARIS[] = '<path d="' . _rute($titik) . '" fill="none" stroke="' . $warna . '" stroke-width="2.4"'
        . ($putus ? ' stroke-dasharray="8 7"' : '') . ' marker-end="url(#' . $m . ')"/>';
    if ($label === '') return;
    if ($lp === null) {
        [$x1, $y1] = $titik[0]; [$x2, $y2] = $titik[1];
        $lp = [($x1 + $x2) / 2, ($y1 + $y2) / 2];
        if (abs($x2 - $x1) < 1) $lp[0] += 38; else $lp[1] -= 17;
    }
    $LABEL[] = '<span class="lb" style="left:' . round($lp[0]) . 'px;top:' . round($lp[1]) . 'px;'
        . 'color:' . $warna . ';border-color:' . $warna . '55">' . $label . '</span>';
}

/* ── Jalur baku, supaya bentuknya seragam & garisnya pasti bersih ─────────── */

/** Cabang di lajur 2, sejajar tengah dengan asalnya → garisnya mendatar murni. */
function cabang(string $id, string $dari, string $peran, string $judul, string $isi,
                string $label, string $warna): void
{
    $cat = $peran === 'catatan';
    S($id, LJ2, 0, W2, $cat ? 'catatan' : 'proses', $peran, $judul, $isi);
    sejajar($id, $dari);
    P([T($dari, 'kanan'), T($id, 'kiri')], $label, $warna, $cat);
}

/**
 * Garis "tidak" yang asalnya ADA DI BAWAH kotak cabangnya: keluar ke kanan,
 * naik lewat lorong B, lalu masuk cabang dari sisi KANAN. Sisi kiri cabang
 * sengaja dibiarkan kosong untuk garis balik, jadi keduanya tak berpapasan.
 */
function naikKeCabang(string $dari, string $ke, string $label, string $warna, float $lorong): void
{
    global $SIMPUL;
    $kn = $SIMPUL[$ke];
    P([T($dari, 'kanan'), [$lorong, CY($dari)], [$lorong, CY($ke)], [$kn['x'] + $kn['w'], CY($ke)]],
      $label, $warna);
}

/** Garis balik: keluar sisi KIRI cabang, mendatar murni ke sisi kanan tujuannya. */
function balik(string $dari, string $ke, string $label = 'diperbaiki, diajukan ulang'): void
{
    P([T($dari, 'kiri'), T($ke, 'kanan')], $label, '#94A3B8', true);
}

/** Catatan di lajur 3 — hanya disambung dari lajur 2, jadi tak memotong apa pun. */
function catatan(string $id, string $dari, string $judul, string $isi): void
{
    S($id, LJ3, 0, W3, 'catatan', 'sistem', $judul, $isi);
    sejajar($id, $dari);
    P([T($dari, 'kanan'), T($id, 'kiri')], '', '#94A3B8', true);
}

function bagan(float $tinggi): array
{
    global $SIMPUL, $GARIS, $LABEL, $MAXX;
    $lebar = max(640.0, $MAXX + 30);
    $def = '';
    foreach (PANAH as $w => $id) {
        $def .= '<marker id="' . $id . '" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="6"'
              . ' markerHeight="6" orient="auto-start-reverse"><path d="M0,1 L9,5 L0,9 z" fill="' . $w . '"/></marker>';
    }
    $svg = '<svg width="' . round($lebar) . '" height="' . $tinggi . '" viewBox="0 0 ' . round($lebar) . ' ' . $tinggi . '"><defs>'
         . $def . '</defs>';
    foreach ($GARIS as $g) $svg .= $g;
    foreach ($SIMPUL as $n) $svg .= svgSimpul($n);
    $svg .= '</svg>';
    $teks = '';
    foreach ($SIMPUL as $n) $teks .= htmlSimpul($n);
    foreach ($LABEL as $l) $teks .= $l;
    $SIMPUL = []; $GARIS = []; $LABEL = []; $MAXX = 0.0;
    return ['<div class="bagan" style="width:' . round($lebar) . 'px;height:' . round($tinggi) . 'px">'
            . $svg . $teks . '</div>', $lebar];
}

// ═══════════════════════════════════════════════════════════════════════════
//  Isi papan
// ═══════════════════════════════════════════════════════════════════════════
$zona = [];
$G = 76;   // jarak tegak antar-kotak alur utama

/* ── A · Ringkasan ──────────────────────────────────────────────────────── */
$y = 0;
S('a1', 60, $y, 300, 'ujung', 'ujung', 'MULAI', '');                               $y = BW('a1') + $G;
S('a2', LJ1, $y, W1, 'tanya', 'tanya', 'Modul yang mana?', '');                    $y = BW('a2') + $G;
S('a3', LJ1, $y, W1, 'proses', 'sales', 'EXHIBITION &rarr; dokumen SKP',
    'Wajib lewat Surat Penawaran.');
S('a3b', LJ2, 0, W2, 'proses', 'sales', 'GUDANG &rarr; SKS &nbsp;·&nbsp; MEDIA &rarr; FU',
    'Boleh langsung dibuat tanpa penawaran.');
sejajar('a3b', 'a3');
$y = max(BW('a3'), BW('a3b')) + $G;
S('a4', LJ1, $y, W1, 'proses', 'sales', 'Sales mengajukan dokumen', 'Status: Menunggu Approval.'); $y = BW('a4') + $G;
S('a5', LJ1, $y, W1, 'tanya', 'asst', 'Diparaf ' . $lbl1 . '?', $org1);            $y = BW('a5') + $G;
S('a6', LJ1, $y, W1, 'tanya', 'manager', 'Disetujui ' . $lbl2 . '?', $org2);       $y = BW('a6') + $G;
S('a7', LJ1, $y, W1, 'hasil', 'hasil', 'NOMOR DOKUMEN TERBIT',
    'Transaksi lahir di sini. Bukan saat dokumen dibuat, bukan pula saat diparaf.'); $y = BW('a7') + $G;
S('a8', LJ1, $y, W1, 'tanya', 'client', 'Client menandatangani?', 'Tautan berlaku 30 hari'); $y = BW('a8') + $G;
S('a9', LJ1, $y, W1, 'hasil', 'hasil', 'INCOME MASUK LAPORAN',
    'Dashboard, Laporan PIC, dan Achievement.');
// Cabang-cabang (sejajar tengah dengan asalnya → garis mendatar murni)
cabang('a5b', 'a4', 'stop', 'Dikembalikan ke sales',
    'Diperbaiki lalu diajukan ulang. Alokasi income dilepas selama itu.', '', '#94A3B8');
cabang('a8b', 'a8', 'sistem', 'Menunggu di daftar "Perlu TTD"',
    'Nilainya belum terhitung di laporan mana pun.', 'Belum', '#B45309');
$tinggiA = max(BW('a9'), BW('a8b'), BW('a5b')) + 20;

P([T('a1', 'bawah'), T('a2', 'atas')]);
P([T('a2', 'bawah'), T('a3', 'atas')], 'Exhibition', '#0D9488');
P([T('a2', 'kanan'), [GB1, CY('a2')], [GB1, CY('a3b')], [LJ2 + W2, CY('a3b')]], 'Gudang / Media', '#0D9488');
P([T('a3', 'bawah'), T('a4', 'atas')]);
P([T('a3b', 'bawah'), [LJ2 + W2 / 2, BW('a3b') + 44], [LJ1 + W1 / 2, BW('a3b') + 44], T('a4', 'atas')]);
P([T('a4', 'bawah'), T('a5', 'atas')]);
P([T('a5', 'bawah'), T('a6', 'atas')], 'Ya', '#0D9488');
naikKeCabang('a5', 'a5b', 'Tidak', '#DC2626', GB1);
naikKeCabang('a6', 'a5b', 'Tidak', '#DC2626', GB2);
balik('a5b', 'a4', 'diajukan ulang');
P([T('a6', 'bawah'), T('a7', 'atas')], 'Ya', '#0D9488');
P([T('a7', 'bawah'), T('a8', 'atas')]);
P([T('a8', 'bawah'), T('a9', 'atas')], 'Ya', '#0D9488');
[$_i, $_w] = bagan($tinggiA); $zona['ringkas'] = [$_i, $tinggiA, $_w];

/* ── B · Exhibition ─────────────────────────────────────────────────────── */
$y = 0;
S('e1', 60, $y, 300, 'ujung', 'ujung', 'MULAI', 'Client minta sewa unit pameran'); $y = BW('e1') + $G;
S('e2', LJ1, $y, W1, 'tanya', 'tanya', 'Client &amp; unit sudah ada di Master Data?', '');
cabang('e2b', 'e2', 'sales', 'Daftarkan dulu',
    '<b>Master Data &rarr; Client</b> (perusahaan, merek, alamat, NPWP, minimal satu kontak PJ) dan <b>Master Exhibition</b> (kode unit, lantai, luas, tarif per hari/m&sup2;).',
    'Belum', '#B45309');
$y = max(BW('e2'), BW('e2b')) + $G;
S('e3', LJ1, $y, W1, 'proses', 'sales', 'Buat Surat Penawaran',
    'Pilih client, kontak PJ, unit, periode, harga, diskon, DP &amp; deposit.');   $y = BW('e3') + $G;
S('e4', LJ1, $y, W1, 'proses', 'sistem', 'Nomor penawaran terbit otomatis',
    'Keluar begitu disimpan. Status awal <b>Draft</b>.');                          $y = BW('e4') + $G;
S('e5', LJ1, $y, W1, 'proses', 'sales', 'Periksa lalu kirim ke client',
    'Buka <b>Lihat Contoh Surat</b> dulu, baru cetak PDF dan kirim. Status jadi <b>Terkirim</b>, lalu <b>Nego</b> bila ada tawar-menawar.'); $y = BW('e5') + $G;
S('e6', LJ1, $y, W1, 'tanya', 'tanya', 'Client setuju dengan penawarannya?', '');
cabang('e6b', 'e6', 'stop', 'Tutup penawaran',
    'Wajib mengisi alasannya — itulah yang dipakai menganalisa kenapa deal lepas.', 'Tidak', '#DC2626');
S('e6c', LJ2 + 60, BW('e6b') + 52, 300, 'ujung', 'ujung', 'SELESAI — tidak jadi', '');
$y = max(BW('e6'), BW('e6c')) + $G;
S('e7', LJ1, $y, W1, 'proses', 'client', 'Client menandatangani penawaran',
    'Lewat tautan tanda tangan online, <b>atau</b> unggah surat yang sudah diteken basah.'); $y = BW('e7') + $G;
S('e8', LJ1, $y, W1, 'hasil', 'hasil', 'STATUS DEAL', 'Nilainya dikunci — penawaran tidak bisa diubah lagi.'); $y = BW('e8') + $G;
S('e9', LJ1, $y, W1, 'proses', 'sales', 'Buat SKP dari penawaran',
    'Syaratnya: penawaran <b>harus sudah diteken client</b>. Lengkapi PJ, KTP/NPWP/SIUP, lampiran, dan <b>Pembagian PIC</b> bila dikerjakan lebih dari satu orang.'); $y = BW('e9') + $G;
S('e10', LJ1, $y, W1, 'proses', 'sales', 'Ajukan', 'Status jadi <b>Menunggu Approval</b>.'); $y = BW('e10') + $G;
S('e11', LJ1, $y, W1, 'tanya', 'asst', 'Diparaf ' . $lbl1 . '?', $org1);           $y = BW('e11') + $G;
S('e12', LJ1, $y, W1, 'tanya', 'manager', 'Disetujui ' . $lbl2 . '?', $org2);      $y = BW('e12') + $G;
S('e13', LJ1, $y, W1, 'hasil', 'hasil', 'NOMOR SKP TERBIT · TRANSAKSI LAHIR',
    'Isinya dibekukan, jadi cetakan ulangnya tidak pernah berubah.');              $y = BW('e13') + $G;
S('e14', LJ1, $y, W1, 'proses', 'sales', 'Kirim tautan tanda tangan',
    'Tombol WhatsApp di halaman SKP. <b>Berlaku 30 hari.</b>');                    $y = BW('e14') + $G;
S('e15', LJ1, $y, W1, 'tanya', 'client', 'Client meneken dalam 30 hari?', '');     $y = BW('e15') + $G;
S('e16', LJ1, $y, W1, 'hasil', 'hasil', 'INCOME MASUK LAPORAN',
    'Alokasi bulanan terbentuk. Baru di titik ini nilainya muncul di Dashboard, Laporan PIC, dan Achievement.'); $y = BW('e16') + $G;
S('e17', LJ1, $y, W1, 'tanya', 'tanya', 'Perlu kontrak dari Legal?', '');          $y = BW('e17') + $G;
S('e18', 60, $y, 300, 'ujung', 'ujung', 'SELESAI', '');
// Cabang: dipasang sejajar dengan langkah yang jadi tujuan baliknya
cabang('e11b', 'e9', 'stop', 'Dikembalikan ke sales',
    '<b>Kembalikan satu tahap</b> bila yang kurang cuma ketelitian tahap di bawahnya, atau <b>Tolak ke PIC</b> bila isinya memang salah.', '', '#94A3B8');
cabang('e15b', 'e14', 'asst', 'Ajukan revisi',
    'Diputus ' . $lbl1 . '. Tautan baru terbit, nomornya tetap sama — hanya naik versi.', '', '#94A3B8');
cabang('e17b', 'e17', 'legal', 'Ajukan Kontrak ke Legal',
    'Berkas tidak bisa dikirim sebelum <b>bukti transfer</b> diunggah.', 'Ya', '#0D9488');
$tinggiB = max(BW('e18'), BW('e17b')) + 20;

P([T('e1', 'bawah'), T('e2', 'atas')]);
P([T('e2', 'bawah'), T('e3', 'atas')], 'Sudah', '#0D9488');
P([T('e2b', 'bawah'), [LJ2 + W2 / 2, BW('e2b') + 38], [LJ1 + W1 + 56, BW('e2b') + 38],
   [LJ1 + W1 + 56, CY('e3')], T('e3', 'kanan')]);
P([T('e3', 'bawah'), T('e4', 'atas')]);
P([T('e4', 'bawah'), T('e5', 'atas')]);
P([T('e5', 'bawah'), T('e6', 'atas')]);
P([T('e6b', 'bawah'), T('e6c', 'atas')], '', '#DC2626');
P([T('e6', 'bawah'), T('e7', 'atas')], 'Ya', '#0D9488');
P([T('e7', 'bawah'), T('e8', 'atas')]);
P([T('e8', 'bawah'), T('e9', 'atas')]);
P([T('e9', 'bawah'), T('e10', 'atas')]);
P([T('e10', 'bawah'), T('e11', 'atas')]);
P([T('e11', 'bawah'), T('e12', 'atas')], 'Ya', '#0D9488');
naikKeCabang('e11', 'e11b', 'Tidak', '#DC2626', GB1);
naikKeCabang('e12', 'e11b', 'Tidak', '#DC2626', GB2);
balik('e11b', 'e9');
P([T('e12', 'bawah'), T('e13', 'atas')], 'Ya', '#0D9488');
P([T('e13', 'bawah'), T('e14', 'atas')]);
P([T('e14', 'bawah'), T('e15', 'atas')]);
naikKeCabang('e15', 'e15b', 'Lewat 30 hari', '#B45309', GB1);
balik('e15b', 'e14', 'kirim tautan baru');
P([T('e15', 'bawah'), T('e16', 'atas')], 'Ya', '#0D9488');
P([T('e16', 'bawah'), T('e17', 'atas')]);
P([T('e17', 'bawah'), T('e18', 'atas')], 'Tidak');
P([T('e17b', 'bawah'), [LJ2 + W2 / 2, BW('e17b') + 38], [LJ1 + W1 + 56, BW('e17b') + 38],
   [LJ1 + W1 + 56, CY('e18')], T('e18', 'kanan')]);
[$_i, $_w] = bagan($tinggiB); $zona['exhibition'] = [$_i, $tinggiB, $_w];

/* ── C · Gudang & Media ─────────────────────────────────────────────────── */
$y = 0;
S('g1', 60, $y, 300, 'ujung', 'ujung', 'MULAI', 'Client minta sewa gudang / titik media'); $y = BW('g1') + $G;
S('g2', LJ1, $y, W1, 'tanya', 'tanya', 'Client &amp; unit sudah ada di Master Data?', '');
cabang('g2b', 'g2', 'sales', 'Daftarkan dulu',
    '<b>Master Client</b>, lalu <b>Master Gudang</b> (kode, lokasi, luas, tarif per m&sup2;/bulan) atau <b>Master Media</b> (kode, jenis, titik, ukuran, slot per hari, tarif).',
    'Belum', '#B45309');
$y = max(BW('g2'), BW('g2b')) + $G;
S('g3', LJ1, $y, W1, 'tanya', 'tanya', 'Client minta penawaran resmi dulu?', '');
cabang('g3b', 'g3', 'sales', 'PINTU B · lewat Surat Penawaran',
    'Buat penawaran modul Gudang/Media, client menandatangani, lalu tekan <b>Buat SKS/Form</b> dari halaman penawaran.',
    'Ya', '#0D9488');
S('g3c', LJ2, BW('g3b') + 48, W2, 'proses', 'sales', 'PINTU C · dari transaksi lama',
    'Menu <b>&uarr; Dari transaksi yang sudah ada</b> &rarr; pilih barisnya. Untuk transaksi yang dokumennya belum pernah dibuat.');
catatan('g3n', 'g3c', 'Satu transaksi, satu dokumen',
    'Yang sudah punya akan membuka dokumen itu, bukan membuat yang kedua.');
$y = max(BW('g3'), BW('g3c')) + $G;
S('g4', LJ1, $y, W1, 'proses', 'sales', 'PINTU A · buat dokumen langsung',
    'Menu <b>SKP Pameran</b> &rarr; tombol <b>SKS Gudang</b> atau <b>Form Utilities</b>. Semua isian diketik di formulir itu. Paling sering dipakai.'); $y = BW('g4') + $G;
S('g5', LJ1, $y, W1, 'proses', 'sales', 'Lengkapi lalu ajukan',
    'Client, kontak PJ, kode unit, periode, luas, tarif, biaya listrik, deposit, lampiran. Periksa dulu dengan <b>Lihat Contoh Surat</b>.'); $y = BW('g5') + $G;
S('g6', LJ1, $y, W1, 'tanya', 'asst', 'Diparaf ' . ($tahap['sks'][1]['label'] ?? $lbl1) . '?', $sks1); $y = BW('g6') + $G;
S('g7', LJ1, $y, W1, 'tanya', 'manager', 'Disetujui ' . ($tahap['sks'][2]['label'] ?? $lbl2) . '?', $sks2); $y = BW('g7') + $G;
S('g8', LJ1, $y, W1, 'hasil', 'hasil', 'NOMOR SKS / FU TERBIT',
    'Transaksinya lahir di sini juga — pada Pintu A memang belum ada sebelumnya.'); $y = BW('g8') + $G;
S('g9', LJ1, $y, W1, 'proses', 'sales', 'Kirim tautan tanda tangan', 'Berlaku 30 hari sejak disetujui.'); $y = BW('g9') + $G;
S('g10', LJ1, $y, W1, 'tanya', 'client', 'Client meneken?', '');                   $y = BW('g10') + $G;
S('g11', LJ1, $y, W1, 'hasil', 'hasil', 'INCOME MASUK LAPORAN',
    'Sewa berbulan-bulan dipecah per bulan otomatis, jadi tiap bulan kebagian porsinya.');
cabang('g6b', 'g5', 'stop', 'Dikembalikan ke sales', 'Diperbaiki lalu diajukan ulang — lihat papan Koreksi.', '', '#94A3B8');
cabang('g10b', 'g10', 'sistem', 'Menunggu di daftar "Perlu TTD"', 'Nilainya belum terhitung.', 'Belum', '#B45309');
$tinggiC = max(BW('g11'), BW('g10b')) + 20;

P([T('g1', 'bawah'), T('g2', 'atas')]);
P([T('g2', 'bawah'), T('g3', 'atas')], 'Sudah', '#0D9488');
P([T('g2b', 'bawah'), [LJ2 + W2 / 2, BW('g2b') + 38], [LJ1 + W1 + 56, BW('g2b') + 38],
   [LJ1 + W1 + 56, CY('g3')], T('g3', 'kanan')]);
P([T('g3b', 'bawah'), T('g3c', 'atas')], 'atau', '#94A3B8', true);
P([T('g3', 'bawah'), T('g4', 'atas')], 'Tidak');
P([T('g3c', 'bawah'), [LJ2 + W2 / 2, BW('g3c') + 38], [LJ1 + W1 + 56, BW('g3c') + 38],
   [LJ1 + W1 + 56, CY('g5')], T('g5', 'kanan')]);
P([T('g4', 'bawah'), T('g5', 'atas')]);
P([T('g5', 'bawah'), T('g6', 'atas')]);
P([T('g6', 'bawah'), T('g7', 'atas')], 'Ya', '#0D9488');
naikKeCabang('g6', 'g6b', 'Tidak', '#DC2626', GB1);
naikKeCabang('g7', 'g6b', 'Tidak', '#DC2626', GB2);
balik('g6b', 'g5');
P([T('g7', 'bawah'), T('g8', 'atas')], 'Ya', '#0D9488');
P([T('g8', 'bawah'), T('g9', 'atas')]);
P([T('g9', 'bawah'), T('g10', 'atas')]);
P([T('g10', 'bawah'), T('g11', 'atas')], 'Ya', '#0D9488');
[$_i, $_w] = bagan($tinggiC); $zona['gudang'] = [$_i, $tinggiC, $_w];

/* ── D · Perpanjangan & PIC ─────────────────────────────────────────────── */
$y = 0;
S('r0', 60, $y, 300, 'ujung', 'ujung', 'KONTRAK HAMPIR HABIS', '');                $y = BW('r0') + $G;
S('r1', LJ1, $y, W1, 'proses', 'sales', 'Buka Papan Renewal',
    'Berakhir dalam <b>30 hari</b> ke depan atau baru lewat <b>7 hari</b>. Tersisa &le;15 hari ditandai merah.'); $y = BW('r1') + $G;
S('r2', LJ1, $y, W1, 'proses', 'sales', 'Catat status pendekatan',
    'Belum dihubungi &rarr; sedang nego &rarr; dst., supaya tidak ada yang terlewat.'); $y = BW('r2') + $G;
S('r3', LJ1, $y, W1, 'tanya', 'tanya', 'Client mau perpanjang?', '');
cabang('r3b', 'r3', 'sistem', 'Tandai saja di papan', 'Tercatat sebagai tidak diperpanjang.', 'Tidak', '#94A3B8');
$y = max(BW('r3'), BW('r3b')) + $G;
S('r4', LJ1, $y, W1, 'proses', 'sales', 'Tekan + Perpanjang',
    'Terbuka <b>Surat Penawaran baru</b> berisi data kontrak lama. Lanjutkan seperti jalur baku.');
cabang('r4n', 'r4', 'catatan', 'Jalan pintas tanpa penawaran',
    'Dari daftar <b>Transaksi</b>, tombol <b>Perpanjang</b> pada baris kontraknya. Status Sewa otomatis terisi "Perpanjangan".',
    'atau', '#94A3B8');
$y = max(BW('r4'), BW('r4n')) + 150;

S('c0', 60, $y, 300, 'ujung', 'ujung', 'SATU DEAL, BEBERAPA PIC', '');             $y = BW('c0') + $G;
S('c1', LJ1, $y, W1, 'tanya', 'tanya', 'Satu acara dikerjakan beberapa PIC?', '');
cabang('c1b', 'c1', 'sales', 'Jalur biasa', 'Satu PIC, satu dokumen — tidak ada yang perlu dibagi.', 'Tidak', '#94A3B8');
$y = max(BW('c1'), BW('c1b')) + $G;
S('c2', LJ1, $y, W1, 'proses', 'stop', 'Jangan buat transaksi sendiri-sendiri',
    'Nilainya akan terhitung berkali-kali di Dashboard, Laporan PIC, dan Achievement.'); $y = BW('c2') + $G;
S('c3', LJ1, $y, W1, 'proses', 'sales', 'Buat SATU dokumen, lalu isi Pembagian PIC',
    'Nilai kontraknya dibagi sesuai porsi masing-masing, bukan digandakan.');
cabang('c3n', 'c3', 'catatan', 'Kalau tetap dicoba',
    'Sejak 23 Oktober 2026 sistem menolak transaksi yang unit, client, dan periodenya sama persis — lengkap dengan nomor transaksi yang sudah ada.',
    '', '#94A3B8');
$y = max(BW('c3'), BW('c3n')) + $G;
S('c4', LJ1, $y, W1, 'proses', 'sales', 'Beberapa unit untuk satu acara',
    'Nyalakan <b>paket</b> di penawaran lalu tambahkan komponennya. Satu dokumen memayungi semuanya; saat disetujui lahir satu transaksi per komponen.');
$tinggiD = max(BW('c4'), BW('c3n')) + 20;

P([T('r0', 'bawah'), T('r1', 'atas')]);
P([T('r1', 'bawah'), T('r2', 'atas')]);
P([T('r2', 'bawah'), T('r3', 'atas')]);
P([T('r3', 'bawah'), T('r4', 'atas')], 'Ya', '#0D9488');
P([T('c0', 'bawah'), T('c1', 'atas')]);
P([T('c1', 'bawah'), T('c2', 'atas')], 'Ya', '#DC2626');
P([T('c2', 'bawah'), T('c3', 'atas')]);
P([T('c3', 'bawah'), T('c4', 'atas')], '', '#94A3B8', true);
[$_i, $_w] = bagan($tinggiD); $zona['perpanjangan'] = [$_i, $tinggiD, $_w];

/* ── E · Koreksi & hapus ────────────────────────────────────────────────── */
$y = 0;
S('k0', 60, $y, 300, 'ujung', 'ujung', 'ADA YANG SALAH', '');                      $y = BW('k0') + $G;
S('k1', LJ1, $y, W1, 'tanya', 'tanya', 'Sudah diajukan?', '');
cabang('k1b', 'k1', 'sales', 'Masih draft — perbaiki langsung',
    'Buka, ubah, simpan. Tidak perlu izin siapa pun. Mau dibuang pun boleh: nomornya belum terbit, jadi tidak ada urutan yang bolong.',
    'Belum', '#0D9488');
$y = max(BW('k1'), BW('k1b')) + $G;
S('k2', LJ1, $y, W1, 'tanya', 'tanya', 'Sudah disetujui ' . $lbl2 . '?', '');
cabang('k2b', 'k2', 'asst', 'Masih menunggu approval',
    'Pemeriksa punya dua pilihan: <b>Kembalikan satu tahap</b> (nilai &amp; transaksinya tidak diubah), atau <b>Tolak ke PIC</b> (hanya PIC yang bisa memperbaiki, lalu mengajukan ulang dari awal).',
    'Belum', '#0D9488');
catatan('k2n', 'k2b', 'Yang ikut terjadi',
    'Alokasi income dilepas supaya berhenti terhitung selama diperbaiki. Transaksinya sengaja tidak dihapus.');
$y = max(BW('k2'), BW('k2b')) + $G;
S('k3', LJ1, $y, W1, 'tanya', 'tanya', 'Client sudah meneken?', '');
cabang('k3b', 'k3', 'sales', 'Sudah disetujui, belum diteken',
    'Ajukan <b>permintaan revisi</b> beserta alasannya, lalu diputus ' . $lbl1 . '. Nomornya tetap sama — hanya naik versi.',
    'Belum', '#0D9488');
$y = max(BW('k3'), BW('k3b')) + $G;
S('k4', LJ1, $y, W1, 'tanya', 'tanya', 'Datanya memang harus hilang?', '');
cabang('k4b', 'k4', 'stop', 'Sudah diteken — terkunci',
    'Tidak bisa direvisi lagi. Kalau isinya memang berubah, <b>terbitkan dokumen baru</b>. Kalau kontraknya batal jalan, pakai <b>Batalkan Kesepakatan</b>.',
    'Tidak', '#0D9488');
$y = max(BW('k4'), BW('k4b')) + $G;
S('k5', LJ1, $y, W1, 'proses', 'sales', 'Ajukan Hapus Data',
    'Sertakan alasan yang jelas — alasan itu yang dibaca pemutus.');               $y = BW('k5') + $G;
S('k6', LJ1, $y, W1, 'tanya', 'manager', 'Disetujui pemutus?', '');
cabang('k6b', 'k6', 'stop', 'Ditolak — data tetap ada', 'Statusnya batal, alasan penolakannya ikut tercatat.', 'Tidak', '#DC2626');
$y = max(BW('k6'), BW('k6b')) + $G;
S('k7', LJ1, $y, W1, 'hasil', 'hasil', 'Pindah ke Data Terhapus',
    'Bukan hilang — masih bisa dipulihkan bila ternyata keliru.');
cabang('k7n', 'k7', 'catatan', 'Semuanya tercatat',
    'Siapa yang mengajukan, siapa yang memutus, kapan, dan alasannya tersimpan di jejak audit.', '', '#94A3B8');
$tinggiE = max(BW('k7'), BW('k7n')) + 20;

P([T('k0', 'bawah'), T('k1', 'atas')]);
P([T('k1', 'bawah'), T('k2', 'atas')], 'Sudah');
P([T('k2', 'bawah'), T('k3', 'atas')], 'Sudah');
P([T('k3', 'bawah'), T('k4', 'atas')], 'Sudah');
P([T('k4', 'bawah'), T('k5', 'atas')], 'Ya', '#DC2626');
P([T('k5', 'bawah'), T('k6', 'atas')]);
P([T('k6', 'bawah'), T('k7', 'atas')], 'Ya', '#0D9488');
[$_i, $_w] = bagan($tinggiE); $zona['koreksi'] = [$_i, $tinggiE, $_w];

// ═══════════════════════════════════════════════════════════════════════════
//  Tata letak papan: zona berdampingan ke samping
// ═══════════════════════════════════════════════════════════════════════════
$meta = [
    'ringkas'      => ['A', 'Ringkasan', 'Apa pun modulnya, begitu dokumen diajukan jalurnya sama persis.'],
    'exhibition'   => ['B', 'Exhibition — SKP', 'Jalur baku sewa unit pameran. Wajib lewat Surat Penawaran.'],
    'gudang'       => ['C', 'Gudang &amp; Media — SKS / FU', 'Tidak wajib lewat penawaran: ada tiga pintu masuk.'],
    'perpanjangan' => ['D', 'Perpanjangan &amp; berbagi PIC', 'Kontrak hampir habis, dan satu acara yang dijual beberapa PIC.'],
    'koreksi'      => ['E', 'Koreksi &amp; hapus', 'Yang bisa dilakukan tergantung sejauh mana dokumennya berjalan.'],
];
const PAD_Z = 48;      // ruang dalam zona
const SELA  = 150;     // jarak antar-zona
const ATAS  = 128;     // ruang untuk judul zona

$zx = SELA; $papanH = 0; $zonaHtml = ''; $lompat = [];
foreach ($meta as $id => [$kode, $nama, $ket]) {
    [$isi, $tg, $lb] = $zona[$id];
    $zw = $lb + 2 * PAD_Z;
    $zh = $tg + ATAS + PAD_Z;
    $zonaHtml .= '<div class="zona" style="left:' . round($zx) . 'px;top:' . SELA . 'px;'
        . 'width:' . round($zw) . 'px;height:' . round($zh) . 'px">'
        . '<div class="zkepala"><span class="zkode">' . $kode . '</span>'
        . '<div><h2>' . $nama . '</h2><p>' . $ket . '</p></div></div>'
        . $isi . '</div>';
    $lompat[] = ['id' => $id, 'kode' => $kode, 'nama' => $nama,
                 'x' => $zx, 'y' => SELA, 'w' => $zw, 'h' => $zh];
    $zx += $zw + SELA;
    $papanH = max($papanH, $zh);
}
$papanW = $zx;
$papanH += 2 * SELA;

$CSS = <<<'CSS'
*{box-sizing:border-box;margin:0;padding:0}
:root{--tinta:#0F172A;--redup:#64748B;--garis:#E2E8F0;--merek:#0D9488}
html,body{height:100%;overflow:hidden}
body{font-family:"Inter","Segoe UI",-apple-system,BlinkMacSystemFont,Helvetica,Arial,sans-serif;
     color:var(--tinta);background:#EEF2F6;-webkit-font-smoothing:antialiased}

/* ── bilah atas ─────────────────────────────────────────── */
.bilah{position:fixed;inset:0 0 auto 0;height:60px;z-index:30;display:flex;align-items:center;
       gap:18px;padding:0 20px;background:rgba(255,255,255,.94);backdrop-filter:blur(12px);
       border-bottom:1px solid var(--garis)}
.bilah .merk{display:flex;align-items:center;gap:12px;flex:none}
.bilah .merk img{height:30px;display:block}
.bilah .merk b{font-size:16px;letter-spacing:-.3px;white-space:nowrap}
.bilah .merk span{font-size:12.5px;color:var(--redup);white-space:nowrap}
.bilah .pisah{width:1px;height:28px;background:var(--garis);flex:none}
.bilah .zlink{display:flex;gap:6px;overflow-x:auto;flex:1;scrollbar-width:none}
.bilah .zlink::-webkit-scrollbar{display:none}
.bilah .zlink button{flex:none;font:inherit;font-size:13px;font-weight:600;color:var(--redup);
       background:none;border:1px solid transparent;padding:7px 14px;border-radius:999px;
       cursor:pointer;white-space:nowrap}
.bilah .zlink button:hover{background:#F1F5F9;color:var(--tinta)}
.bilah .zlink button.aktif{background:var(--merek);color:#fff}
.bilah .zoom{display:flex;align-items:center;gap:4px;flex:none}
.bilah .zoom button{font:inherit;font-size:15px;font-weight:700;width:34px;height:34px;
       border:1px solid var(--garis);background:#fff;border-radius:9px;cursor:pointer;color:var(--tinta)}
.bilah .zoom button:hover{background:#F1F5F9}
.bilah .zoom button.lebar{width:auto;padding:0 13px;font-size:12.5px;font-weight:600}
.bilah .zoom span{font-size:12.5px;color:var(--redup);width:46px;text-align:center}

/* ── papan ──────────────────────────────────────────────── */
.papan{position:fixed;inset:60px 0 0 0;overflow:hidden;cursor:grab;
       background:#EEF2F6;
       background-image:radial-gradient(#CBD5E1 1.2px,transparent 1.2px);
       background-size:34px 34px;touch-action:none}
.papan.geser{cursor:grabbing}
.kanvas{position:absolute;left:0;top:0;transform-origin:0 0;will-change:transform}

.zona{position:absolute;background:#fff;border:1px solid var(--garis);border-radius:26px;
      padding:28px 48px 48px;box-shadow:0 2px 4px rgba(15,23,42,.04),0 28px 60px -32px rgba(15,23,42,.4)}
.zkepala{display:flex;gap:16px;align-items:flex-start;margin-bottom:26px}
.zkode{flex:none;width:40px;height:40px;border-radius:12px;background:#CCFBF1;color:#0F766E;
       font-size:17px;font-weight:800;display:flex;align-items:center;justify-content:center}
.zkepala h2{font-size:26px;font-weight:800;letter-spacing:-.6px;line-height:1.2}
.zkepala p{font-size:14.5px;color:var(--redup);margin-top:3px;max-width:900px}

/* ── bagan ──────────────────────────────────────────────── */
.bagan{position:relative}
.bagan svg{position:absolute;left:0;top:0;display:block}
.bagan .tx{position:absolute;display:flex;flex-direction:column;align-items:center;
           justify-content:center;text-align:center;pointer-events:none}
.bagan .tx b{font-size:16px;font-weight:700;line-height:1.3;letter-spacing:-.2px}
.bagan .tx i{font-style:normal;font-size:13px;color:#475569;line-height:1.5;margin-top:7px}
.bagan .tx i b{font-size:13px;color:#1E293B}
.bagan .lb{position:absolute;transform:translate(-50%,-50%);font-size:12.5px;font-weight:700;
           background:#fff;border:2px solid;border-radius:999px;padding:3px 12px;white-space:nowrap}

/* ── keterangan mengambang ──────────────────────────────── */
.kunci{position:fixed;left:18px;bottom:18px;z-index:30;background:rgba(255,255,255,.95);
       backdrop-filter:blur(10px);border:1px solid var(--garis);border-radius:16px;
       padding:14px 18px;box-shadow:0 16px 40px -20px rgba(15,23,42,.5);max-width:min(760px,calc(100vw - 36px))}
.kunci .br{display:flex;flex-wrap:wrap;gap:8px 20px;font-size:12.5px;color:#475569;align-items:center}
.kunci .br + .br{margin-top:10px;padding-top:10px;border-top:1px solid var(--garis)}
.kunci span{display:flex;align-items:center;gap:8px}
.kunci i{width:20px;height:14px;border-radius:4px;border:2px solid;flex:none;display:inline-block}
.kunci i.d{width:15px;height:15px;border-radius:2px;transform:rotate(45deg)}
.kunci i.u{border-radius:999px}
.kunci i.p{width:13px;height:13px;border-radius:4px;border:none}
.kunci b{color:var(--tinta)}
.kunci .tutup{position:absolute;right:9px;top:7px;border:none;background:none;cursor:pointer;
              color:#94A3B8;font-size:17px;line-height:1;padding:3px}
.kunci.kecil .br{display:none}
.kunci.kecil{padding:9px 14px}
.kunci .nama{font-weight:700;font-size:12.5px;color:var(--tinta);display:none}
.kunci.kecil .nama{display:block}

.petunjuk{position:fixed;right:18px;bottom:18px;z-index:30;font-size:12.5px;color:#64748B;
          background:rgba(255,255,255,.95);border:1px solid var(--garis);border-radius:999px;
          padding:9px 16px;box-shadow:0 10px 30px -18px rgba(15,23,42,.5)}
CSS;

ob_start(); ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Papan Alur Kerja CLARA</title>
<style><?= $CSS ?></style>
</head>
<body>

<div class="bilah">
  <div class="merk">
    <?php if ($logoMal): ?><img src="<?= $logoMal ?>" alt="e-Walk &amp; Pentacity"><?php endif; ?>
    <div><b>Papan Alur Kerja CLARA</b><br><span>Exhibition · Gudang · Media</span></div>
  </div>
  <div class="pisah"></div>
  <div class="zlink" id="zlink">
    <?php foreach ($lompat as $i => $z): ?>
      <button data-i="<?= $i ?>"><?= $z['kode'] ?> · <?= $z['nama'] ?></button>
    <?php endforeach; ?>
  </div>
  <div class="pisah"></div>
  <div class="zoom">
    <button id="zkecil" title="Perkecil">&minus;</button>
    <span id="zteks">100%</span>
    <button id="zbesar" title="Perbesar">+</button>
    <button id="zpas" class="lebar" title="Tampilkan seluruh papan">Seluruhnya</button>
  </div>
</div>

<div class="papan" id="papan">
  <div class="kanvas" id="kanvas" style="width:<?= round($papanW) ?>px;height:<?= round($papanH) ?>px">
    <?= $zonaHtml ?>
  </div>
</div>

<div class="kunci" id="kunci">
  <button class="tutup" id="ktutup" title="Sembunyikan">&times;</button>
  <div class="nama">Keterangan</div>
  <div class="br">
    <span><i class="u" style="background:#E2E8F0;border-color:#CBD5E1"></i> Mulai / selesai</span>
    <span><i style="background:#F0FDFA;border-color:#99F6E4"></i> Langkah</span>
    <span><i class="d" style="background:#FFF7ED;border-color:#FDBA74"></i> Keputusan</span>
    <span><i style="background:#F0FDF4;border-color:#4ADE80;border-width:3px"></i> Menentukan angka</span>
    <span><i style="background:#FEF2F2;border-color:#FECACA"></i> Tidak lolos</span>
    <span><i style="background:#F8FAFC;border-color:#E2E8F0;border-style:dashed"></i> Catatan</span>
  </div>
  <div class="br">
    <b>Pita kiri = pelakunya:</b>
    <span><i class="p" style="background:#0F766E"></i> Sales</span>
    <span><i class="p" style="background:#B45309"></i> <?= $h($pem1) ?></span>
    <span><i class="p" style="background:#6D28D9"></i> <?= $h($pem2) ?></span>
    <span><i class="p" style="background:#0369A1"></i> Client</span>
    <span><i class="p" style="background:#BE185D"></i> Legal</span>
    <span><i class="p" style="background:#475569"></i> Sistem</span>
  </div>
  <div class="br">
    <b>Dua titik yang menentukan angka:</b>
    <span>Nomor terbit &amp; transaksi lahir saat <b><?= $h($pem2) ?></b> menekan Setujui</span>
    <span>·</span>
    <span>Income masuk laporan saat <b>client menandatangani</b><?= $ttdTgl ? ' — untuk kesepakatan sejak <b>' . $h($ttdTgl) . '</b>' : '' ?></span>
  </div>
</div>

<div class="petunjuk">Seret untuk menggeser · Ctrl + gulir untuk zum</div>

<script>
(function () {
  var papan = document.getElementById('papan'),
      kanvas = document.getElementById('kanvas'),
      zteks = document.getElementById('zteks'),
      zona = <?= json_encode(array_map(fn($z) => ['x' => $z['x'], 'y' => $z['y'], 'w' => $z['w'], 'h' => $z['h']], $lompat)) ?>,
      papanW = <?= round($papanW) ?>, papanH = <?= round($papanH) ?>,
      tx = 0, ty = 0, sk = 1, MIN = 0.12, MAX = 2;

  function pasang() {
    kanvas.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + sk + ')';
    zteks.textContent = Math.round(sk * 100) + '%';
  }
  function kotakPapan() { return papan.getBoundingClientRect(); }

  function zumKe(skBaru, px, py) {
    skBaru = Math.min(MAX, Math.max(MIN, skBaru));
    var r = kotakPapan();
    if (px === undefined) { px = r.width / 2; py = r.height / 2; }
    // Jaga titik di bawah kursor tetap di tempatnya.
    tx = px - (px - tx) * (skBaru / sk);
    ty = py - (py - ty) * (skBaru / sk);
    sk = skBaru;
    pasang();
  }

  // Seluruh papan: dikecilkan sampai muat semuanya.
  function pasSemua() {
    var r = kotakPapan(), m = 40;
    sk = Math.min(MAX, Math.max(MIN, Math.min((r.width - m * 2) / papanW, (r.height - m * 2) / papanH)));
    tx = (r.width - papanW * sk) / 2;
    ty = (r.height - papanH * sk) / 2;
    pasang();
  }

  // Satu zona: JANGAN dipaksa muat seluruhnya — zona yang tinggi akan mengecil
  // sampai tulisannya tidak terbaca. Lebarnya saja yang dipaskan, maksimal 100%,
  // lalu ditaruh di sudut kiri-atas zona supaya langsung bisa dibaca dan
  // sisanya tinggal digulir.
  function keZona(z) {
    var r = kotakPapan(), m = 34;
    sk = Math.min(1, Math.max(MIN, (r.width - m * 2) / z.w));
    tx = m - z.x * sk;
    ty = m - z.y * sk;
    pasang();
  }

  // ── geser dengan seret ──
  var seret = false, ax = 0, ay = 0;
  papan.addEventListener('pointerdown', function (e) {
    seret = true; ax = e.clientX - tx; ay = e.clientY - ty;
    papan.classList.add('geser'); papan.setPointerCapture(e.pointerId);
  });
  papan.addEventListener('pointermove', function (e) {
    if (!seret) return;
    tx = e.clientX - ax; ty = e.clientY - ay; pasang();
  });
  ['pointerup', 'pointercancel'].forEach(function (ev) {
    papan.addEventListener(ev, function () { seret = false; papan.classList.remove('geser'); });
  });

  // ── gulir: biasa = geser, ctrl/cmd = zum ──
  papan.addEventListener('wheel', function (e) {
    e.preventDefault();
    var r = kotakPapan();
    if (e.ctrlKey || e.metaKey) {
      zumKe(sk * Math.pow(0.999, e.deltaY * 1.6), e.clientX - r.left, e.clientY - r.top);
    } else {
      tx -= e.deltaX; ty -= e.deltaY; pasang();
    }
  }, { passive: false });

  document.getElementById('zbesar').onclick = function () { zumKe(sk * 1.25); };
  document.getElementById('zkecil').onclick = function () { zumKe(sk / 1.25); };
  document.getElementById('zpas').onclick = function () { pasSemua(); tandai(-1); };

  var tombol = [].slice.call(document.querySelectorAll('#zlink button'));
  function tandai(i) {
    tombol.forEach(function (b, j) { b.classList.toggle('aktif', j === i); });
  }
  tombol.forEach(function (b, i) {
    b.onclick = function () { keZona(zona[i]); tandai(i); };
  });

  document.getElementById('ktutup').onclick = function () {
    document.getElementById('kunci').classList.toggle('kecil');
  };
  document.getElementById('kunci').addEventListener('click', function (e) {
    if (this.classList.contains('kecil') && e.target.id !== 'ktutup') this.classList.remove('kecil');
  });

  // Mulai dengan zona pertama pada ukuran yang terbaca, bukan seluruh papan
  // yang pasti terlalu kecil di layar biasa.
  keZona(zona[0]); tandai(0);
  window.addEventListener('resize', function () { pasang(); });
})();
</script>
</body>
</html>
<?php
$html = (string) ob_get_clean();
$berkas = $keluar . '/Alur_Kerja_CLARA.html';
file_put_contents($berkas, $html);
printf("HTML   %s (%s KB)\n", $berkas, number_format(strlen($html) / 1024, 0));
printf("Papan  %d x %d px, %d zona\n", $papanW, $papanH, count($lompat));
