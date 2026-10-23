<?php
/**
 * Bagan alir (flowchart) kerja CLARA.
 *
 * Bentuknya bagan alir sungguhan, bukan diagram kotak-kotak: belah ketupat
 * untuk titik keputusan, garis penghubung berpanah, cabang Ya/Tidak yang
 * kelihatan ke mana perginya, dan panah balik untuk langkah yang berulang.
 *
 * Teks di dalam kotak sengaja pendek. Bagan alir dibaca sambil menelusuri
 * garis; kalimat panjang membuat orang berhenti membaca dan alurnya justru
 * hilang. Keterangan yang panjang dikumpulkan di lembar rujukan terakhir.
 *
 * Isinya diambil dari kode, bukan dari ingatan:
 *   - jenis dokumen per modul      → skp_doc_type() di app/pages/skp_modules.php
 *   - syarat SKP lahir             → penjaga di skp_form() app/pages/skp.php
 *   - tahap persetujuan & siapanya → tabel skp_approval_flow (dibaca saat dibangun)
 *   - kapan income dihitung        → _skp_bangun_alokasi(), dipanggil saat TTD client
 *   - batas tautan tanda tangan    → sign_token_expiry_sql() di app/helpers.php
 *   - aturan wajib TTD berlaku     → settings.ttd_wajib_mulai
 *
 * Keluarannya dua: satu berkas HTML yang berdiri sendiri (gambar ditanam
 * sebagai data-URI, jadi bisa dikirim apa adanya) dan satu PDF A4 menyamping
 * bila Chrome tersedia di mesin ini.
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
// Nama pemaraf dan tanggal berlakunya aturan tanda tangan diambil dari sistem,
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
                // Satu orang yang memegang kedua mal tidak perlu disebut dua kali.
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

// date('F') berbahasa Inggris; dokumen ini berbahasa Indonesia seluruhnya.
$BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
          'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$ttdTgl = '';
if ($ttdMulai !== '' && ($ts = strtotime($ttdMulai))) {
    $ttdTgl = date('j', $ts) . ' ' . $BULAN[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

// ── Potongan kop surat, ditanam sebagai data-URI ─────────────────────────────
// Kop aslinya dibuat untuk halaman tegak selebar 210mm. Dipasang utuh di
// halaman menyamping (297mm) gambarnya akan melar 1,4x dan logonya ikut
// penyok. Jadi kop dipotong jadi blok-blok: pola kiri, logo tengah, pola
// kanan — yang melebar cuma jarak putih di antaranya.
function potong_uri(string $src, int $x, int $w): string
{
    $im = @imagecreatefromjpeg($src);
    if (!$im) return '';
    $p = imagecreatetruecolor($w, imagesy($im));
    imagecopy($p, $im, 0, 0, $x, 0, $w, imagesy($im));
    ob_start(); imagejpeg($p, null, 92); $bin = (string) ob_get_clean();
    return 'data:image/jpeg;base64,' . base64_encode($bin);
}
$A        = $root . '/public/assets/';
$kopKiri  = potong_uri($A . 'letterhead-head.jpg', 0, 376);
$kopLogo  = potong_uri($A . 'letterhead-head.jpg', 410, 420);
$kopKanan = potong_uri($A . 'letterhead-head.jpg', 864, 376);
$kakiKiri = potong_uri($A . 'letterhead-foot.jpg', 40, 560);
$kakiKanan= potong_uri($A . 'letterhead-foot.jpg', 628, 612);

// ═══════════════════════════════════════════════════════════════════════════
//  Mesin bagan alir
//
//  Seluruh koordinat dalam MILIMETER, sama dengan satuan halaman — "lebar 56"
//  benar-benar 56 mm di kertas. Simpul ditempatkan sendiri, bukan oleh mesin
//  penata otomatis: bagan alir yang enak dibaca butuh penataan yang
//  dipikirkan, dan jumlah simpulnya di sini masih sedikit.
// ═══════════════════════════════════════════════════════════════════════════

const WARNA = [
    'sales'   => ['#0F766E', '#F0FDFA', '#5EEAD4'],
    'asst'    => ['#B45309', '#FFFBEB', '#FCD34D'],
    'manager' => ['#6D28D9', '#F5F3FF', '#C4B5FD'],
    'client'  => ['#0369A1', '#F0F9FF', '#7DD3FC'],
    'legal'   => ['#BE185D', '#FDF2F8', '#F9A8D4'],
    'sistem'  => ['#475569', '#F8FAFC', '#CBD5E1'],
    'tanya'   => ['#C2410C', '#FFF7ED', '#FB923C'],
    'hasil'   => ['#15803D', '#F0FDF4', '#4ADE80'],
    'stop'    => ['#B91C1C', '#FEF2F2', '#FCA5A5'],
    'mulai'   => ['#334155', '#E2E8F0', '#94A3B8'],
];
const PANAH = ['#94A3B8' => 'u0', '#DC2626' => 'u1', '#B45309' => 'u2', '#0F766E' => 'u3'];

/** Lebar kira-kira satu aksara, dipakai membungkus teks tanpa mengukur font. */
function _lebarAksara(string $c, float $fs, bool $tebal): float
{
    $sempit = "iljtfr.,;:'!|()[]/ ";
    $lebar  = "mwMW@%";
    $f = str_contains($sempit, $c) ? 0.34 : (str_contains($lebar, $c) ? 0.84 : 0.54);
    if (ctype_upper($c)) $f += 0.08;
    return $fs * $f * ($tebal ? 1.07 : 1.0);
}

/** Bungkus teks jadi baris yang muat di $lebar mm. *kata* dicetak tebal. */
function _bungkus(string $teks, float $lebar, float $fs): array
{
    $kata = preg_split('/\s+/', trim($teks)) ?: [];
    $baris = []; $kini = []; $w = 0.0;
    foreach ($kata as $k) {
        if ($k === '') continue;
        $tebal = str_starts_with($k, '*') || str_ends_with($k, '*');
        $bersih = trim($k, '*');
        $lw = 0.0;
        foreach (preg_split('//u', $bersih, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) {
            $lw += _lebarAksara($c, $fs, $tebal);
        }
        $spasi = $kini ? $fs * 0.30 : 0.0;
        if ($kini && $w + $spasi + $lw > $lebar) { $baris[] = $kini; $kini = []; $w = 0.0; $spasi = 0.0; }
        $kini[] = [$bersih, $tebal];
        $w += $spasi + $lw;
    }
    if ($kini) $baris[] = $kini;
    return $baris;
}

function _barisSvg(array $baris): string
{
    $out = [];
    foreach ($baris as [$kata, $tebal]) {
        $e = htmlspecialchars($kata, ENT_QUOTES, 'UTF-8');
        $out[] = $tebal ? '<tspan font-weight="700">' . $e . '</tspan>' : $e;
    }
    return implode(' ', $out);
}

$SIMPUL = [];

/**
 * Tambahkan satu simpul. Tingginya dihitung dari isinya, jadi kotak tidak
 * pernah kesempitan sendiri.
 *
 * $bentuk: proses | tanya | ujung | hasil | catatan
 */
function S(string $id, float $x, float $y, float $w, string $bentuk, string $peran,
           string $judul, string $isi = ''): void
{
    global $SIMPUL;
    $fsJ = 2.75; $fsI = 2.35;
    $pad = $bentuk === 'tanya' ? 9.0 : 3.6;
    $bJud = _bungkus($judul, $w - 2 * $pad, $fsJ);
    $bIsi = $isi !== '' ? _bungkus($isi, $w - 2 * $pad, $fsI) : [];
    $h = 3.0 + count($bJud) * ($fsJ * 1.24) + (count($bIsi) ? 1.1 + count($bIsi) * ($fsI * 1.28) : 0) + 3.0;
    if ($bentuk === 'tanya') $h += 9.0;
    if ($bentuk === 'ujung') $h = max($h, 9.5);
    $SIMPUL[$id] = compact('x', 'y', 'w', 'h', 'bentuk', 'peran')
                 + ['jud' => $bJud, 'isi' => $bIsi, 'fsJ' => $fsJ, 'fsI' => $fsI];
}

/** Titik tempel di tepi simpul. */
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
/** Sisi bawah simpul, tetapi pada ketinggian apa adanya (untuk rute siku). */
function B(string $id): float { global $SIMPUL; return $SIMPUL[$id]['y'] + $SIMPUL[$id]['h']; }
function Y(string $id): float { global $SIMPUL; return $SIMPUL[$id]['y']; }

function gambarSimpul(array $n): string
{
    [$grs, $isiWarna, $tepi] = WARNA[$n['peran']] ?? WARNA['sistem'];
    $x = $n['x']; $y = $n['y']; $w = $n['w']; $h = $n['h'];
    $putus = $n['bentuk'] === 'catatan' ? ' stroke-dasharray="1.8 1.3"' : '';
    $tbl   = $n['bentuk'] === 'hasil' ? 0.85 : 0.45;
    $s = '';
    if ($n['bentuk'] === 'tanya') {
        $p = sprintf('%.2f,%.2f %.2f,%.2f %.2f,%.2f %.2f,%.2f',
            $x + $w / 2, $y, $x + $w, $y + $h / 2, $x + $w / 2, $y + $h, $x, $y + $h / 2);
        $s .= '<polygon points="' . $p . '" fill="' . $isiWarna . '" stroke="' . $tepi . '" stroke-width="' . $tbl . '"/>';
    } elseif ($n['bentuk'] === 'ujung') {
        $s .= '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" rx="' . ($h / 2)
            . '" fill="' . $isiWarna . '" stroke="' . $tepi . '" stroke-width="' . $tbl . '"/>';
    } else {
        $s .= '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" rx="1.8" fill="'
            . $isiWarna . '" stroke="' . $tepi . '" stroke-width="' . $tbl . '"' . $putus . '/>';
        if ($n['bentuk'] !== 'catatan') {
            // Pita warna di tepi kiri = siapa yang mengerjakan, terbaca sekilas.
            $s .= '<path d="M' . ($x + 1.8) . ' ' . $y . ' h-0.2 a1.8,1.8 0 0 0 -1.6,1.8 V' . ($y + $h - 1.8)
                . ' a1.8,1.8 0 0 0 1.6,1.8 h0.2 Z" fill="' . $grs . '"/>';
        }
    }
    $ty = $y + ($n['bentuk'] === 'tanya' ? 4.5 : 0) + 3.0 + $n['fsJ'] * 0.84;
    $cx = $x + $w / 2 + ($n['bentuk'] === 'proses' || $n['bentuk'] === 'hasil' ? 0.9 : 0);
    foreach ($n['jud'] as $b) {
        $s .= '<text x="' . round($cx, 2) . '" y="' . round($ty, 2) . '" text-anchor="middle" font-size="'
            . $n['fsJ'] . '" font-weight="700" fill="' . $grs . '">' . _barisSvg($b) . '</text>';
        $ty += $n['fsJ'] * 1.24;
    }
    if ($n['isi']) {
        $ty += 0.9;
        foreach ($n['isi'] as $b) {
            $s .= '<text x="' . round($cx, 2) . '" y="' . round($ty, 2) . '" text-anchor="middle" font-size="'
                . $n['fsI'] . '" fill="#475569">' . _barisSvg($b) . '</text>';
            $ty += $n['fsI'] * 1.28;
        }
    }
    return $s;
}

/** Garis penghubung berpanah, bersiku. $label ditaruh di ruas pertama. */
function P(array $titik, string $label = '', string $warna = '#94A3B8', bool $putus = false, ?array $lp = null): string
{
    $d = [];
    foreach ($titik as $i => [$px, $py]) $d[] = ($i ? 'L' : 'M') . round($px, 2) . ' ' . round($py, 2);
    $m = PANAH[$warna] ?? 'u0';
    $s = '<path d="' . implode(' ', $d) . '" fill="none" stroke="' . $warna . '" stroke-width="0.5"'
       . ($putus ? ' stroke-dasharray="1.6 1.2"' : '') . ' marker-end="url(#' . $m . ')"/>';
    if ($label !== '') {
        if ($lp !== null) {
            [$mx, $my] = $lp;
        } else {
            [$x1, $y1] = $titik[0]; [$x2, $y2] = $titik[1];
            $mx = ($x1 + $x2) / 2; $my = ($y1 + $y2) / 2;
            if (abs($x2 - $x1) < 0.5) $mx += 1.0 + strlen($label) * 0.70; else $my -= 2.1;
        }
        $lw = 1.6 + strlen($label) * 1.28;
        $s .= '<rect x="' . round($mx - $lw / 2, 2) . '" y="' . round($my - 2.2, 2) . '" width="' . round($lw, 2)
            . '" height="3.4" rx="1.7" fill="#fff" stroke="' . $warna . '" stroke-width="0.3"/>';
        $s .= '<text x="' . round($mx, 2) . '" y="' . round($my + 0.45, 2) . '" text-anchor="middle" font-size="2.2"'
            . ' font-weight="700" fill="' . $warna . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</text>';
    }
    return $s;
}

function bagan(array $garis, float $tinggi = 150): string
{
    global $SIMPUL;
    $def = '';
    foreach (PANAH as $w => $id) {
        $def .= '<marker id="' . $id . '" viewBox="0 0 10 10" refX="8.5" refY="5" markerWidth="4.6"'
              . ' markerHeight="4.6" orient="auto-start-reverse"><path d="M0,1.2 L9,5 L0,8.8 z" fill="' . $w . '"/></marker>';
    }
    $s = '<svg class="bagan" viewBox="0 0 268 ' . $tinggi . '" preserveAspectRatio="xMidYMin meet"><defs>'
       . $def . '</defs>';
    foreach ($garis as $g) $s .= $g;
    foreach ($SIMPUL as $n) $s .= gambarSimpul($n);
    $SIMPUL = [];
    return $s . '</svg>';
}

$CSS = <<<'CSS'
*{box-sizing:border-box;margin:0;padding:0}
@page{size:A4 landscape;margin:0}
html,body{background:#fff}
body{font-family:"Segoe UI",-apple-system,BlinkMacSystemFont,Helvetica,Arial,sans-serif;
     color:#1F2937;-webkit-print-color-adjust:exact;print-color-adjust:exact}
@media screen{body{background:#E2E8F0}.lembar{box-shadow:0 2mm 6mm rgba(15,23,42,.18)}}
.lembar{position:relative;width:297mm;height:210mm;background:#fff;overflow:hidden;
        page-break-after:always;margin:0 auto 6mm}
.lembar:last-child{page-break-after:auto}
@media print{.lembar{margin:0}}
.kop{position:absolute;top:0;left:0;right:0;height:19mm;display:flex;
     justify-content:space-between;align-items:flex-start}
.kop img{height:19mm;display:block}
.kop .tengah{height:19mm;display:flex;align-items:center}
.kop .tengah img{height:14mm}
.kaki{position:absolute;bottom:0;left:0;right:0;height:20mm;display:flex;
      justify-content:space-between;align-items:flex-end}
.kaki img{display:block;height:20mm}
.isi{position:absolute;top:18mm;left:14.5mm;right:14.5mm;bottom:20mm;display:flex;flex-direction:column}
h1{font-size:14pt;letter-spacing:-.2pt;margin-bottom:.5mm}
h1 small{font-weight:400;font-size:9.6pt;color:#64748B;letter-spacing:0}
.sub{font-size:8.4pt;color:#64748B;margin-bottom:1.6mm;line-height:1.4}
.bagan{width:100%;flex:1;min-height:0}
.legenda{display:flex;gap:4.6mm;flex-wrap:wrap;font-size:7.6pt;color:#475569;
         margin-top:1.2mm;padding-top:1.5mm;border-top:1px solid #F1F5F9}
.legenda span{display:flex;align-items:center;gap:1.3mm}
.legenda i{width:3.4mm;height:3.4mm;border-radius:.8mm;display:inline-block;border:.35mm solid}
.legenda i.d{border-radius:0;transform:rotate(45deg);width:2.8mm;height:2.8mm}
.legenda i.s{border-radius:1.7mm}
.nomor{position:absolute;right:14.5mm;bottom:21.4mm;font-size:7.6pt;color:#94A3B8}
table.ref{width:100%;border-collapse:collapse;font-size:8.3pt}
table.ref th{background:#F0FDF4;color:#0A7267;font-size:7.6pt;text-transform:uppercase;
             letter-spacing:.03em;text-align:left;padding:1.8mm 2.4mm;border:.3mm solid #D1D5DB}
table.ref td{padding:1.8mm 2.4mm;border:.3mm solid #E2E8F0;vertical-align:top;line-height:1.45}
table.ref td.k{width:44mm;font-weight:700;color:#1F2937;background:#FCFDFE}
.duakolom{display:grid;grid-template-columns:1fr 1fr;gap:5mm;flex:1;align-content:start}
CSS;

$lembar = [];

/* ═══ Lembar 1 — tulang punggung bersama ════════════════════════════════ */
ob_start(); ?>
<h1>Alur Kerja CLARA <small>&mdash; tulang punggung yang dipakai ketiga modul</small></h1>
<div class="sub">Apa pun modulnya, sesudah dokumen diajukan jalurnya sama persis. Yang berbeda hanya pintu masuknya.</div>
<?php
$g = [];
S('m',   104,   0, 60, 'ujung',  'mulai',   'MULAI', 'Client minta sewa');
S('tm',   92,  14, 84, 'tanya',  'tanya',   'Modul apa?');
S('ex',    0,  44, 82, 'proses', 'sales',   'EXHIBITION → *SKP*', 'Wajib lewat Surat Penawaran');
S('gd',    93, 44, 82, 'proses', 'sales',   'GUDANG → *SKS*', 'Boleh tanpa penawaran');
S('md',   186, 44, 82, 'proses', 'sales',   'MEDIA → *FU*', 'Boleh tanpa penawaran');
S('aj',    93, 66, 82, 'proses', 'sales',   'Sales ajukan dokumen', 'Status: Menunggu Approval');
S('p1',    93, 86, 82, 'tanya',  'asst',    'Diparaf ' . $lbl1 . '?');
S('p2',    93,116, 82, 'tanya',  'manager', 'Disetujui ' . $lbl2 . '?');
S('bl',     0, 92, 82, 'proses', 'stop',    'Kembali ke sales', 'Alokasi income dilepas');
S('no',   186, 84, 82, 'hasil',  'hasil',   'NOMOR TERBIT', 'Transaksi lahir di sini');
S('tt',   186,110, 82, 'tanya',  'client',  'Client meneken?');
S('ic',   186,140, 82, 'hasil',  'hasil',   'INCOME MASUK LAPORAN');
S('wt',    93,142, 82, 'proses', 'sistem',  'Menunggu di "Perlu TTD"', 'Belum terhitung');

$g[] = P([T('m', 'bawah'), T('tm', 'atas')]);
$g[] = P([[134, 36], [134, 40], [41, 40], T('ex', 'atas')]);
$g[] = P([[134, 36], [134, 44]]);
$g[] = P([[134, 36], [134, 40], [227, 40], T('md', 'atas')]);
$g[] = P([T('ex', 'bawah'), [41, 61], [134, 61], [134, 66]]);
$g[] = P([T('md', 'bawah'), [227, 61], [134, 61], [134, 66]]);
$g[] = P([T('aj', 'bawah'), T('p1', 'atas')]);
$g[] = P([T('p1', 'bawah'), T('p2', 'atas')], 'Ya', '#0F766E', false, [140, 110]);
$g[] = P([T('p1', 'kiri'), [88, 97], T('bl', 'kanan')], 'Tidak', '#DC2626', false, [86, 92]);
$g[] = P([T('p2', 'kiri'), [88, 127], [88, 114], [41, 114], T('bl', 'bawah')], 'Tidak', '#DC2626', false, [86, 133]);
$g[] = P([T('p2', 'kanan'), [181, 127], [181, 95], T('no', 'kiri')], 'Ya', '#0F766E', false, [181, 133]);
$g[] = P([T('no', 'bawah'), T('tt', 'atas')]);
$g[] = P([T('tt', 'bawah'), T('ic', 'atas')], 'Ya', '#0F766E', false, [233, 136]);
$g[] = P([T('tt', 'kiri'), [181, 121], [181, 150], T('wt', 'kanan')], 'Belum', '#B45309', false, [172, 121]);
$g[] = P([T('bl', 'atas'), [41, 86], [88, 86], [88, 75], T('aj', 'kiri')], 'diajukan ulang', '#94A3B8', true, [62, 84]);
echo bagan($g, 158);
?>
<div class="legenda">
    <span><i class="s" style="background:#E2E8F0;border-color:#94A3B8"></i> Mulai / selesai</span>
    <span><i style="background:#F0FDFA;border-color:#5EEAD4"></i> Langkah</span>
    <span><i class="d" style="background:#FFF7ED;border-color:#FB923C"></i> Keputusan</span>
    <span><i style="background:#F0FDF4;border-color:#4ADE80"></i> Titik yang menentukan angka</span>
    <span><i style="background:#FEF2F2;border-color:#FCA5A5"></i> Jalan kalau tidak lolos</span>
    <span>Pita kiri = pelakunya:
        <i style="background:#0F766E;border-color:#0F766E"></i> Sales
        <i style="background:#B45309;border-color:#B45309"></i> <?= $h($lbl1) ?><?= $org1 ? ' (' . $h($org1) . ')' : '' ?>
        <i style="background:#6D28D9;border-color:#6D28D9"></i> <?= $h($lbl2) ?><?= $org2 ? ' (' . $h($org2) . ')' : '' ?>
        <i style="background:#0369A1;border-color:#0369A1"></i> Client</span>
</div>
<?php $lembar[] = ob_get_clean();

/* ═══ Lembar 2 — Exhibition ═════════════════════════════════════════════ */
ob_start(); ?>
<h1>Exhibition <small>&mdash; SKP, dari nol sampai masuk laporan</small></h1>
<div class="sub">Mulai dari kiri atas, telusuri garisnya. Tiap belah ketupat punya dua jalan keluar yang dilabeli.</div>
<?php
$g = [];
// ── Lajur 1
S('m1',  2,   0, 58, 'ujung',  'mulai',  'MULAI');
S('t1',  2,  12, 58, 'tanya',  'tanya',  'Client & unit sudah terdaftar?');
S('d1',  2,  42, 58, 'proses', 'sales',  'Daftarkan di Master Data', 'Client · Master Exhibition');
S('p1',  2,  64, 58, 'proses', 'sales',  'Buat Surat Penawaran');
S('p2',  2,  80, 58, 'proses', 'sistem', 'Nomor penawaran terbit', 'Otomatis saat disimpan');
S('p3',  2, 100, 58, 'proses', 'sales',  'Kirim ke client', 'PDF + tautan tanda tangan');
// ── Lajur 2
S('t2', 68,   0, 58, 'tanya',  'tanya',  'Client setuju?');
S('x1', 68,  30, 58, 'proses', 'stop',   'Tutup penawaran', 'Wajib isi alasan');
S('x2', 68,  48, 58, 'ujung',  'stop',   'SELESAI — tidak jadi');
S('c1', 68,  66, 58, 'proses', 'client', 'Client meneken penawaran', 'Online atau unggah berteken');
S('c2', 68,  88, 58, 'hasil',  'hasil',  'STATUS DEAL', 'Nilai dikunci');
S('c3', 68, 106, 58, 'proses', 'sales',  'Buat SKP dari penawaran', 'Lengkapi PJ, KTP/NPWP, lampiran');
S('c4', 68, 132, 58, 'proses', 'sales',  'Ajukan', 'Status: Menunggu Approval');
// ── Lajur 3
S('a1',134,   4, 58, 'tanya',  'asst',   'Diparaf ' . $lbl1 . '?');
S('a2',134,  40, 58, 'tanya',  'manager','Disetujui ' . $lbl2 . '?');
S('a3',134,  78, 58, 'proses', 'stop',   'Kembali ke sales', 'Dikembalikan 1 tahap, atau ditolak ke PIC');
S('a4',134, 110, 58, 'hasil',  'hasil',  'NOMOR SKP TERBIT', 'Transaksi lahir, isinya dibekukan');
// ── Lajur 4
S('b1',208,   0, 58, 'proses', 'sales',  'Kirim tautan TTD', 'Berlaku 30 hari');
S('b2',208,  18, 58, 'tanya',  'client', 'Client meneken?');
S('b3',208,  48, 58, 'proses', 'asst',   'Ajukan revisi', 'Tautan baru terbit, nomor tetap');
S('b4',208,  70, 58, 'hasil',  'hasil',  'INCOME MASUK LAPORAN', 'Dashboard · Laporan PIC · Achievement');
S('b5',208,  96, 58, 'tanya',  'tanya',  'Perlu kontrak Legal?');
S('b6',208, 124, 58, 'proses', 'legal',  'Ajukan ke Legal', 'Wajib unggah bukti transfer');
S('b7',208, 142, 58, 'ujung',  'mulai',  'SELESAI');

$g[] = P([T('m1', 'bawah'), T('t1', 'atas')]);
$g[] = P([T('t1', 'bawah'), T('d1', 'atas')], 'Belum', '#B45309');
$g[] = P([T('d1', 'bawah'), T('p1', 'atas')]);
$g[] = P([T('t1', 'kanan'), [64, 27], [64, 56], [31, 56], [31, 64]], 'Sudah', '#0F766E');
$g[] = P([T('p1', 'bawah'), T('p2', 'atas')]);
$g[] = P([T('p2', 'bawah'), T('p3', 'atas')]);
$g[] = P([T('p3', 'kanan'), [64, 108], [64, 7], [68, 7]]);
$g[] = P([T('t2', 'bawah'), T('x1', 'atas')], 'Tidak', '#DC2626');
$g[] = P([T('x1', 'bawah'), T('x2', 'atas')]);
$g[] = P([T('t2', 'kanan'), [130, 13], [130, 58], [97, 58], [97, 66]], 'Ya', '#0F766E');
$g[] = P([T('c1', 'bawah'), T('c2', 'atas')]);
$g[] = P([T('c2', 'bawah'), T('c3', 'atas')]);
$g[] = P([T('c3', 'bawah'), T('c4', 'atas')]);
$g[] = P([T('c4', 'kanan'), [130, 139], [130, 11], [134, 11]]);
$g[] = P([T('a1', 'bawah'), T('a2', 'atas')], 'Ya', '#0F766E');
$g[] = P([T('a2', 'bawah'), T('a3', 'atas')], 'Tidak', '#DC2626');
$g[] = P([T('a1', 'kiri'), [130, 18], [130, 86], T('a3', 'kiri')], 'Tidak', '#DC2626', false, [130, 44]);
$g[] = P([T('a3', 'bawah'), [163, 102], [198, 102], [198, 139], T('c4', 'kanan')], 'perbaiki, ajukan ulang', '#94A3B8', true, [180, 100]);
$g[] = P([T('a2', 'kanan'), [202, 54], [202, 118], T('a4', 'kanan')], 'Ya', '#0F766E', false, [199, 60]);
$g[] = P([T('a4', 'atas'), [163, 106], [194, 106], [194, 7], [208, 7]]);
$g[] = P([T('b1', 'bawah'), T('b2', 'atas')]);
$g[] = P([T('b2', 'bawah'), T('b3', 'atas')], 'Lewat 30 hari', '#B45309', false, [248, 44]);
$g[] = P([T('b3', 'kiri'), [206, 56], [206, 8], T('b1', 'kiri')], 'kirim ulang', '#94A3B8', true, [206, 66]);
$g[] = P([T('b2', 'kanan'), [264, 27], [264, 78], T('b4', 'kanan')], 'Ya', '#0F766E', false, [262, 34]);
$g[] = P([T('b4', 'bawah'), T('b5', 'atas')]);
$g[] = P([T('b5', 'bawah'), T('b6', 'atas')], 'Ya', '#0F766E', false, [243, 120]);
$g[] = P([T('b6', 'bawah'), T('b7', 'atas')]);
$g[] = P([T('b5', 'kiri'), [203, 105], [203, 147], T('b7', 'kiri')], 'Tidak', '#94A3B8', false, [203, 115]);
echo bagan($g, 154);
?>
<?php $lembar[] = ob_get_clean();

/* ═══ Lembar 3 — Gudang & Media ═════════════════════════════════════════ */
ob_start(); ?>
<h1>Gudang &amp; Media <small>&mdash; SKS dan FU, tiga pintu masuk</small></h1>
<div class="sub">Tidak wajib lewat Surat Penawaran. Tiga pintu masuknya bertemu di satu titik, sesudah itu sama dengan Exhibition.</div>
<?php
$g = [];
S('m1',  2,   0, 58, 'ujung',  'mulai',  'MULAI');
S('t0',  2,  12, 58, 'tanya',  'tanya',  'Client & unit sudah terdaftar?');
S('d0',  2,  44, 58, 'proses', 'sales',  'Daftarkan di Master Data', 'Client · Gudang / Media');
S('t1',  2,  70, 58, 'tanya',  'tanya',  'Client minta penawaran resmi?');
S('PA',  2, 108, 58, 'proses', 'sales',  'PINTU A · Buat dokumen langsung', 'Menu SKP Pameran → SKS / Form Utilities');
S('PB', 68,  22, 58, 'proses', 'sales',  'PINTU B · Lewat penawaran', 'Client meneken → "Buat SKS/Form"');
S('PC', 68,  76, 58, 'proses', 'sales',  'PINTU C · Dari transaksi lama', '"↑ Dari transaksi yang sudah ada"');
S('cx', 68, 108, 58, 'catatan','sistem', 'Satu transaksi, satu dokumen', 'Yang sudah punya akan membukanya');
S('is',134,  12, 58, 'proses', 'sales',  'Lengkapi lalu ajukan', 'Periode, luas, tarif, listrik, lampiran');
S('a1',134,  46, 58, 'tanya',  'asst',   'Diparaf ' . $lbl1 . '?');
S('a2',134,  86, 58, 'tanya',  'manager','Disetujui ' . $lbl2 . '?');
S('a3',134, 128, 58, 'proses', 'stop',   'Kembali ke sales', 'Lihat lembar 5');
S('n1',208,  12, 58, 'hasil',  'hasil',  'NOMOR SKS / FU TERBIT', 'Transaksi lahir di sini juga');
S('n2',208,  44, 58, 'proses', 'sales',  'Kirim tautan TTD', 'Berlaku 30 hari');
S('n3',208,  68, 58, 'tanya',  'client', 'Client meneken?');
S('n4',208, 104, 58, 'hasil',  'hasil',  'INCOME MASUK LAPORAN', 'Kontrak panjang dipecah per bulan');
S('n5',208, 136, 58, 'proses', 'sistem', 'Menunggu di "Perlu TTD"', 'Belum terhitung');

$g[] = P([T('m1', 'bawah'), T('t0', 'atas')]);
$g[] = P([T('t0', 'bawah'), T('d0', 'atas')], 'Belum', '#B45309');
$g[] = P([T('d0', 'bawah'), T('t1', 'atas')]);
$g[] = P([T('t0', 'kanan'), [64, 27], [64, 62], [31, 62], [31, 70]], 'Sudah', '#0F766E');
$g[] = P([T('t1', 'kanan'), [64, 85], [64, 35], T('PB', 'kiri')], 'Ya', '#0F766E');
$g[] = P([T('t1', 'bawah'), T('PA', 'atas')], 'Tidak');
$g[] = P([T('PA', 'kanan'), [64, 118], [64, 89], T('PC', 'kiri')], 'atau', '#94A3B8', true, [64, 104]);
$g[] = P([T('PB', 'kanan'), [130, 35], [130, 20], T('is', 'kiri')]);
$g[] = P([T('PC', 'kanan'), [130, 89], [130, 24], [134, 24]]);
$g[] = P([T('PC', 'bawah'), T('cx', 'atas')], '', '#94A3B8', true);
$g[] = P([T('is', 'bawah'), T('a1', 'atas')]);
$g[] = P([T('a1', 'bawah'), T('a2', 'atas')], 'Ya', '#0F766E', false, [169, 80]);
$g[] = P([T('a2', 'bawah'), T('a3', 'atas')], 'Tidak', '#DC2626', false, [169, 122]);
$g[] = P([T('a1', 'kiri'), [130, 60], [130, 136], T('a3', 'kiri')], 'Tidak', '#DC2626', false, [130, 72]);
$g[] = P([T('a3', 'kanan'), [198, 136], [198, 24], T('is', 'kanan')], 'ajukan ulang', '#94A3B8', true, [198, 116]);
$g[] = P([T('a2', 'kanan'), [202, 100], [202, 23], T('n1', 'kiri')], 'Ya', '#0F766E', false, [199, 108]);
$g[] = P([T('n1', 'bawah'), T('n2', 'atas')]);
$g[] = P([T('n2', 'bawah'), T('n3', 'atas')]);
$g[] = P([T('n3', 'bawah'), T('n4', 'atas')], 'Ya', '#0F766E', false, [243, 100]);
$g[] = P([T('n3', 'kiri'), [204, 81], [204, 145], T('n5', 'kiri')], 'Belum', '#B45309', false, [204, 92]);
echo bagan($g, 154);
?>
<?php $lembar[] = ob_get_clean();

/* ═══ Lembar 4 — perpanjangan & kasus ═══════════════════════════════════ */
ob_start(); ?>
<h1>Perpanjangan &amp; kasus data <small>&mdash; tiga bagan kecil</small></h1>
<div class="sub">Keadaan yang tidak masuk jalur baku.</div>
<?php
$g = [];
// A — perpanjangan
S('hA',  2,   0, 80, 'ujung',  'mulai',  'A · KONTRAK HAMPIR HABIS');
S('r1',  2,  16, 80, 'proses', 'sales',  'Buka Papan Renewal', 'Berakhir ≤30 hari, atau baru lewat ≤7 hari');
S('r2',  2,  44, 80, 'proses', 'sales',  'Catat status pendekatan', 'Belum dihubungi → nego → dst.');
S('r3',  2,  70, 80, 'tanya',  'tanya',  'Client mau perpanjang?');
S('r4',  2, 102, 80, 'proses', 'sales',  'Tekan + Perpanjang', 'Terbuka penawaran baru berisi data lama');
S('r5',  2, 126, 80, 'proses', 'sales',  'Lanjut jalur baku');
S('r6',  2, 142, 80, 'catatan','sistem', 'Jalan pintas tanpa penawaran', 'Tombol Perpanjang di daftar Transaksi');
$g[] = P([T('hA', 'bawah'), T('r1', 'atas')]);
$g[] = P([T('r1', 'bawah'), T('r2', 'atas')]);
$g[] = P([T('r2', 'bawah'), T('r3', 'atas')]);
$g[] = P([T('r3', 'bawah'), T('r4', 'atas')], 'Ya', '#0F766E', false, [48, 96]);
$g[] = P([T('r4', 'bawah'), T('r5', 'atas')]);
$g[] = P([T('r3', 'kanan'), [86, 82], [86, 111], T('r4', 'kanan')], 'Tidak — tandai saja', '#94A3B8', false, [57, 97]);
$g[] = P([T('r5', 'bawah'), T('r6', 'atas')], 'atau', '#94A3B8', true, [48, 140]);

// B — data master
S('hB',  94,   0, 76, 'ujung',  'mulai',  'B · DATA BELUM ADA');
S('s1',  94,  16, 76, 'tanya',  'tanya',  'Client pernah sewa di sini?');
S('s2',  94,  52, 76, 'proses', 'sales',  'Master Data → Client → Tambah', 'Perusahaan, merek, alamat, NPWP');
S('s3',  94,  80, 76, 'proses', 'sales',  'Tambah kontak PJ', 'Nama ini yang tercetak di surat');
S('s4',  94, 106, 76, 'proses', 'stop',   'Client lama: jangan buat baru', 'Cukup tambah kontak di profilnya');
S('s5',  94, 134, 76, 'proses', 'sales',  'Unit belum ada?', 'Master Exhibition / Gudang / Media → Tambah');
$g[] = P([T('hB', 'bawah'), T('s1', 'atas')]);
$g[] = P([T('s1', 'bawah'), T('s2', 'atas')], 'Belum', '#B45309', false, [140, 48]);
$g[] = P([T('s2', 'bawah'), T('s3', 'atas')]);
$g[] = P([T('s1', 'kiri'), [90, 31], [90, 114], T('s4', 'kiri')], 'Sudah', '#0F766E', false, [90, 44]);
$g[] = P([T('s3', 'bawah'), T('s4', 'atas')]);
$g[] = P([T('s4', 'bawah'), T('s5', 'atas')]);

// C — berbagi PIC
S('hC', 182,   0, 84, 'ujung',  'mulai',  'C · SATU DEAL, BEBERAPA PIC');
S('q1', 182,  16, 84, 'tanya',  'tanya',  'Satu acara, beberapa PIC?');
S('q2', 182,  52, 84, 'proses', 'stop',   'Jangan buat transaksi sendiri-sendiri', 'Nilainya terhitung berkali-kali');
S('q3', 182,  82, 84, 'proses', 'sales',  'Buat SATU dokumen', 'Lalu isi Pembagian PIC di dalamnya');
S('q4', 182, 110, 84, 'catatan','sistem', 'Kalau tetap dicoba', 'Ditolak sistem, disebutkan nomor transaksi yang sudah ada');
S('q5', 182, 140, 84, 'catatan','sales',  'Beberapa unit, satu acara', 'Pakai "paket" — satu SKP, transaksi per komponen');
$g[] = P([T('hC', 'bawah'), T('q1', 'atas')]);
$g[] = P([T('q1', 'bawah'), T('q2', 'atas')], 'Ya', '#DC2626', false, [230, 48]);
$g[] = P([T('q2', 'bawah'), T('q3', 'atas')]);
$g[] = P([T('q3', 'bawah'), T('q4', 'atas')], '', '#94A3B8', true);
$g[] = P([T('q4', 'bawah'), T('q5', 'atas')], '', '#94A3B8', true);
echo bagan($g, 158);
?>
<?php $lembar[] = ob_get_clean();

/* ═══ Lembar 5 — koreksi ════════════════════════════════════════════════ */
ob_start(); ?>
<h1>Kalau ada yang perlu dibetulkan <small>&mdash; mulai dari: dokumennya sudah sampai mana?</small></h1>
<div class="sub">Turuti rantai pertanyaan di kiri; jawabannya menentukan apa yang masih bisa dilakukan.</div>
<?php
$g = [];
S('m',   2,   0, 58, 'ujung',  'mulai',  'ADA YANG SALAH');
S('k1',  2,  14, 58, 'tanya',  'tanya',  'Sudah diajukan?');
S('k2',  2,  50, 58, 'tanya',  'tanya',  'Sudah disetujui ' . $lbl2 . '?');
S('k3',  2,  88, 58, 'tanya',  'tanya',  'Client sudah meneken?');
S('k4',  2, 126, 58, 'tanya',  'tanya',  'Datanya harus hilang?');

S('v1', 68,  14, 58, 'proses', 'sales',  'MASIH DRAFT', 'Perbaiki langsung, atau buang drafnya');
S('v2', 68,  48, 58, 'proses', 'asst',   'MENUNGGU APPROVAL', 'Pemeriksa punya dua pilihan');
S('v3', 68,  86, 58, 'proses', 'sales',  'SUDAH DISETUJUI', 'Ajukan revisi + alasan → diputus ' . $lbl1);
S('v4', 68, 126, 58, 'proses', 'stop',   'SUDAH DITEKEN CLIENT', 'Terkunci, tidak bisa direvisi');

S('w1',134,  36, 58, 'proses', 'asst',   'Kembalikan 1 tahap', 'Nilai & transaksi tidak diubah');
S('w2',134,  64, 58, 'proses', 'stop',   'Tolak ke PIC', 'Diperbaiki, diajukan ulang dari awal');
S('w3',134,  92, 58, 'proses', 'sistem', 'Alokasi income dilepas', 'Berhenti terhitung selama diperbaiki');
S('w4',134,   4, 58, 'catatan','sistem', 'Nomornya tetap sama', 'Revisi hanya menaikkan versi');
S('w5',134, 126, 58, 'proses', 'sales',  'Terbitkan dokumen baru', 'Atau Batalkan Kesepakatan');

S('z1',208,  40, 58, 'proses', 'sales',  'Ajukan Hapus Data', 'Wajib isi alasan');
S('z2',208,  66, 58, 'tanya',  'manager','Disetujui pemutus?');
S('z3',208, 102, 58, 'hasil',  'hasil',  'Pindah ke DATA TERHAPUS', 'Masih bisa dipulihkan');
S('z4',208, 132, 58, 'catatan','sistem', 'Tercatat di jejak audit', 'Siapa, kapan, dan alasannya');
S('z5',208,   4, 58, 'proses', 'stop',   'Ditolak — data tetap ada');

$g[] = P([T('m', 'bawah'), T('k1', 'atas')]);
$g[] = P([T('k1', 'kanan'), [64, 29], [64, 24], T('v1', 'kiri')], 'Belum', '#0F766E', false, [62, 21]);
$g[] = P([T('k1', 'bawah'), T('k2', 'atas')], 'Sudah', '#94A3B8', false, [40, 46]);
$g[] = P([T('k2', 'kanan'), [64, 65], [64, 58], T('v2', 'kiri')], 'Belum', '#0F766E', false, [62, 57]);
$g[] = P([T('k2', 'bawah'), T('k3', 'atas')], 'Sudah', '#94A3B8', false, [40, 84]);
$g[] = P([T('k3', 'kanan'), [64, 103], [64, 96], T('v3', 'kiri')], 'Belum', '#0F766E', false, [62, 95]);
$g[] = P([T('k3', 'bawah'), T('k4', 'atas')], 'Sudah', '#94A3B8', false, [40, 122]);
$g[] = P([T('k4', 'kanan'), [64, 141], [64, 134], T('v4', 'kiri')], 'Tidak', '#0F766E', false, [62, 133]);
$g[] = P([T('k4', 'kiri'), [0, 141], [0, 158], [194, 158], [194, 49], T('z1', 'kiri')], 'Ya — harus dihapus', '#DC2626', false, [40, 155]);
$g[] = P([T('v2', 'kanan'), [130, 58], [130, 45], T('w1', 'kiri')], 'ringan', '#94A3B8', false, [150, 32]);
$g[] = P([T('v2', 'kanan'), [130, 58], [130, 73], T('w2', 'kiri')], 'berat', '#DC2626', false, [150, 60]);
$g[] = P([T('w2', 'bawah'), T('w3', 'atas')]);
$g[] = P([T('v3', 'kanan'), [130, 96], [130, 13], T('w4', 'kiri')]);
$g[] = P([T('v4', 'kanan'), [130, 134], [130, 135], T('w5', 'kiri')]);
$g[] = P([T('z1', 'bawah'), T('z2', 'atas')]);
$g[] = P([T('z2', 'bawah'), T('z3', 'atas')], 'Ya', '#0F766E', false, [243, 98]);
$g[] = P([T('z3', 'bawah'), T('z4', 'atas')], '', '#94A3B8', true);
$g[] = P([T('z2', 'kiri'), [204, 78], [204, 13], T('z5', 'kiri')], 'Tidak', '#DC2626', false, [204, 92]);
echo bagan($g, 162);
?>
<?php $lembar[] = ob_get_clean();

/* ═══ Lembar 6 — rujukan isian ══════════════════════════════════════════ */
ob_start(); ?>
<h1>Rujukan isian <small>&mdash; bagian Harga &amp; cara nilainya diakui</small></h1>
<div class="sub">Dua hal ini bukan alur, melainkan pilihan di dalam formulir &mdash; dikumpulkan di sini
    supaya bagan di lembar sebelumnya tetap ringkas.</div>
<div class="duakolom">
    <table class="ref">
        <tr><th colspan="2">Bagian Harga di Surat Penawaran</th></tr>
        <tr><td class="k">Total Kontrak</td><td>Terhitung sendiri dari tarif &times; luas &times; lama sewa. Tidak bisa diketik.</td></tr>
        <tr><td class="k">Harga Nego Final</td><td>Diisi hanya bila hasil nego berbeda. <b>Angka inilah yang dipakai</b>, menggantikan hitungan otomatis.</td></tr>
        <tr><td class="k">Harga / Bulan</td><td>Boleh dikosongkan. Kosong atau 0 &rarr; barisnya <b>tidak dicetak</b> di surat maupun PDF.</td></tr>
        <tr><td class="k">Jadwal Harga Bertahap</td><td>Untuk kontrak yang tarifnya naik di tengah jalan. Tiap tahap punya tanggal berlakunya sendiri.</td></tr>
        <tr><td class="k">Biaya Listrik</td><td>Ikut masuk nilai kontrak. Bisa dimatikan &mdash; yang dimatikan tidak muncul di surat.</td></tr>
        <tr><td class="k">PPN 12%</td><td>Bawaannya menyala. Lepas centang bila harganya sudah bersih.</td></tr>
        <tr><td class="k">Service Charge</td><td>Bawaannya mati. Ditagih per bulan, tercetak sebagai blok tersendiri.</td></tr>
        <tr><td class="k">DP &amp; Deposit</td><td>Dalam bulan atau nominal, plus centang &ldquo;Security Deposit sudah dibayarkan&rdquo;.</td></tr>
    </table>
    <table class="ref">
        <tr><th colspan="2">Kapan nilainya masuk bulan mana</th></tr>
        <tr><td class="k">Otomatis (ikut periode)</td><td>Bawaannya. Multi-bulan atau lintas bulan jadi <i>Spread</i>; selainnya <i>Sekaligus</i>.</td></tr>
        <tr><td class="k">Sekaligus (anchor)</td><td>Seluruh nilainya diakui di satu bulan.</td></tr>
        <tr><td class="k">Spread per Bulan</td><td>Dipecah ke tiap bulan masa sewa. Dipakai kontrak panjang seperti gudang.</td></tr>
        <tr><td class="k">Pengakuan per Siklus</td><td>Bulan awal siklus, atau bulan akhir siklus.</td></tr>
        <tr><td class="k">Prorata ikut bulan kalender</td><td>Centang bila kontrak tidak mulai tanggal 1. Bulan tak penuh dihitung per hari (11&ndash;30 Nov = 20/30 bulan). Tanpa centang, siklusnya ikut tanggal mulai (11 &rarr; 10).</td></tr>
        <tr><td class="k">Diakui Recurring</td><td>Centang bila kontraknya berulang. Diteruskan ke transaksi saat konfirmasi disetujui.</td></tr>
        <tr><th colspan="2">Dua titik yang menentukan angka</th></tr>
        <tr><td class="k">Nomor terbit &amp; transaksi lahir</td><td>Saat <b><?= $h($lbl2) ?><?= $org2 ? ' (' . $h($org2) . ')' : '' ?></b> menekan Setujui.</td></tr>
        <tr><td class="k">Income masuk laporan</td><td>Saat <b>client menandatangani</b><?= $ttdTgl ? ', untuk kesepakatan yang dibuat sejak <b>' . $h($ttdTgl) . '</b>' : '' ?>.</td></tr>
        <tr><td class="k">Tautan tanda tangan</td><td>Berlaku <b>30 hari</b> sejak dokumen disetujui.</td></tr>
    </table>
</div>
<?php $lembar[] = ob_get_clean();

// ── Rakit berkas HTML ────────────────────────────────────────────────────────
$judul = 'Alur Kerja CLARA';
$html = "<!doctype html>\n<html lang=\"id\"><head><meta charset=\"utf-8\">\n"
      . "<title>$judul</title>\n<style>$CSS</style></head><body>\n";
foreach ($lembar as $i => $isi) {
    $html .= '<div class="lembar">'
        . '<div class="kop">'
        . ($kopKiri ? '<img src="' . $kopKiri . '" alt="">' : '')
        . '<div class="tengah">' . ($kopLogo ? '<img src="' . $kopLogo . '" alt="">' : '') . '</div>'
        . ($kopKanan ? '<img src="' . $kopKanan . '" alt="">' : '')
        . '</div>'
        . '<div class="isi">' . $isi . '</div>'
        . '<div class="nomor">' . ($i + 1) . ' / ' . count($lembar) . '</div>'
        . '<div class="kaki">'
        . ($kakiKiri ? '<img src="' . $kakiKiri . '" alt="">' : '')
        . ($kakiKanan ? '<img src="' . $kakiKanan . '" alt="">' : '')
        . '</div>'
        . "</div>\n";
}
$html .= "</body></html>\n";

$berkasHtml = $keluar . '/Alur_Kerja_CLARA.html';
file_put_contents($berkasHtml, $html);
printf("HTML  %s (%s KB)\n", $berkasHtml, number_format(filesize($berkasHtml) / 1024, 0));

// ── Cetak jadi PDF lewat Chrome bila ada ─────────────────────────────────────
// mPDF tidak dipakai: bagannya SVG, dan mPDF menggambarnya seadanya. Chrome
// memakai mesin yang sama dengan yang dipakai memeriksa tampilannya.
$chrome = null;
foreach ([
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/Applications/Chromium.app/Contents/MacOS/Chromium',
    trim((string) @shell_exec('command -v google-chrome 2>/dev/null')),
    trim((string) @shell_exec('command -v chromium 2>/dev/null')),
] as $c) { if ($c && is_executable($c)) { $chrome = $c; break; } }

if (!$chrome) {
    echo "PDF   dilewati — Chrome tidak ditemukan. Berkas HTML-nya sudah bisa dibuka & dicetak sendiri.\n";
    exit(0);
}
$berkasPdf = $keluar . '/Alur_Kerja_CLARA.pdf';
$profil = sys_get_temp_dir() . '/clara-alur-' . getmypid();
$cmd = escapeshellarg($chrome) . ' --headless --disable-gpu --no-sandbox'
     . ' --user-data-dir=' . escapeshellarg($profil)
     . ' --no-pdf-header-footer --print-to-pdf-no-header'
     . ' --print-to-pdf=' . escapeshellarg($berkasPdf)
     . ' ' . escapeshellarg('file://' . $berkasHtml) . ' 2>&1';
@shell_exec($cmd);
@shell_exec('rm -rf ' . escapeshellarg($profil));
if (is_file($berkasPdf)) {
    printf("PDF   %s (%s KB)\n", $berkasPdf, number_format(filesize($berkasPdf) / 1024, 0));
} else {
    echo "PDF   gagal dibuat — pakai berkas HTML-nya.\n";
    exit(1);
}
