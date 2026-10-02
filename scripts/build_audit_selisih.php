<?php
/**
 * Pembangun laporan selisih Excel vs CLARA — satu PDF per sales.
 *
 * Sumber angka: Audit_Data_Excel_vs_CLARA_2026.xlsx (tarikan 23 Agustus 2026).
 * Status transaksi duplikat diverifikasi langsung ke database saat dijalankan,
 * supaya yang sudah dibereskan tidak ikut ditagihkan lagi.
 *
 * Jalankan: php scripts/build_audit_selisih.php [file.xlsx] [folder-tujuan]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root    = dirname(__DIR__);
$xlsx    = $argv[1] ?? $root . '/Audit_Data_Excel_vs_CLARA_2026.xlsx';
$outRoot = $argv[2] ?? $root . '/Audit_Selisih_Jan-Agt_2026';

require_once $root . '/vendor/autoload.php';
require_once $root . '/app/pdf.php';

// ─── Baca xlsx (tanpa pustaka: ZipArchive + XML) ─────────────────────────────

function xlsx_sheets(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        fwrite(STDERR, "Tidak bisa membuka $path\n");
        exit(1);
    }
    $wb = $zip->getFromName('xl/workbook.xml');
    preg_match_all('/<sheet[^>]*name="([^"]+)"/', $wb, $m);
    $names = $m[1];

    // sharedStrings (kalau ada)
    $shared = [];
    if ($ss = $zip->getFromName('xl/sharedStrings.xml')) {
        preg_match_all('/<si>(.*?)<\/si>/s', $ss, $sm);
        foreach ($sm[1] as $si) {
            preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm);
            $shared[] = html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }

    $sheets = [];
    foreach ($names as $i => $name) {
        $xml = $zip->getFromName('xl/worksheets/sheet' . ($i + 1) . '.xml');
        if ($xml === false) continue;
        $rows = [];
        preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $xml, $rm);
        foreach ($rm[1] as $rowXml) {
            preg_match_all('/<c\s([^>]*)>(.*?)<\/c>/s', $rowXml, $cm, PREG_SET_ORDER);
            $cells = [];
            foreach ($cm as $c) {
                $attr = $c[1];
                $inner = $c[2];
                preg_match('/r="([A-Z]+)\d+"/', $attr, $rr);
                $col = 0;
                foreach (str_split($rr[1] ?? 'A') as $ch) $col = $col * 26 + (ord($ch) - 64);
                $col--;
                $type = preg_match('/t="([^"]+)"/', $attr, $tt) ? $tt[1] : '';
                $val  = '';
                if (preg_match('/<is>(.*?)<\/is>/s', $inner, $im)) {
                    preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $im[1], $tm2);
                    $val = implode('', $tm2[1]);
                } elseif (preg_match('/<v>(.*?)<\/v>/s', $inner, $vm)) {
                    $val = $type === 's' ? ($shared[(int) $vm[1]] ?? '') : $vm[1];
                }
                $cells[$col] = html_entity_decode($val, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            if (!$cells) continue;
            $max = max(array_keys($cells));
            $row = [];
            for ($j = 0; $j <= $max; $j++) $row[$j] = trim((string) ($cells[$j] ?? ''));
            $rows[] = $row;
        }
        $sheets[$name] = $rows;
    }
    $zip->close();
    return $sheets;
}

$S = xlsx_sheets($xlsx);

// ─── Susun data per sales ────────────────────────────────────────────────────

$num = fn($v) => $v === '' ? null : (float) $v;

// Ringkasan: angka utama + peringkat per sales
$angka = [];
$rank  = [];
$prop  = [];
$mode  = '';
foreach ($S['Ringkasan'] ?? [] as $r) {
    $c0 = $r[0] ?? '';
    if (str_starts_with($c0, 'ANGKA UTAMA'))            { $mode = 'angka'; continue; }
    if (str_starts_with($c0, 'PER PROPERTI'))           { $mode = 'prop';  continue; }
    if (str_starts_with($c0, 'PERINGKAT KELENGKAPAN'))  { $mode = 'rank';  continue; }
    if ($mode === 'angka' && $c0 !== '' && $c0 !== 'Pos' && isset($r[1]) && $r[1] !== '') {
        $angka[$c0] = ['nilai' => $num($r[1]), 'catatan' => $r[5] ?? ''];
    }
    if ($mode === 'prop' && $c0 !== '' && $c0 !== 'Properti' && isset($r[1]) && $r[1] !== '') {
        $prop[] = ['nama' => $c0, 'op' => (float) $r[1], 'clara' => (float) $r[2], 'gap' => (float) $r[3], 'pct' => (float) $r[4]];
    }
    if ($mode === 'rank' && $c0 !== '' && $c0 !== 'Sales' && isset($r[1]) && $r[1] !== '') {
        $rank[] = [
            'sales' => $c0, 'op' => (float) $r[1], 'clara' => (float) $r[2],
            'gap' => (float) $r[3], 'pct' => (float) $r[4], 'properti' => $r[5] ?? '',
        ];
    }
}
usort($rank, fn($a, $b) => $a['pct'] <=> $b['pct']);

// Nilai & jumlah transaksi per bulan
$nilai = $trx = [];
foreach ($S['Nilai per Bulan'] ?? [] as $r) {
    if (($r[2] ?? '') === '' || ($r[3] ?? '') === '' || ($r[1] ?? '') === 'Sales') continue;
    if (!isset($r[5]) || $r[5] === '') continue;
    $nilai[$r[1]][] = ['bulan' => $r[2], 'op' => (float) $r[3], 'clara' => (float) $r[4], 'gap' => (float) $r[5]];
}
foreach ($S['Jumlah Trx'] ?? [] as $r) {
    if (($r[2] ?? '') === '' || ($r[3] ?? '') === '' || ($r[1] ?? '') === 'Sales') continue;
    if (!isset($r[5]) || $r[5] === '') continue;
    $trx[$r[1]][] = ['bulan' => $r[2], 'op' => (int) $r[3], 'clara' => (int) $r[4], 'gap' => (int) $r[5], 'ket' => $r[6] ?? ''];
}

// Duplikat: A (pasti), B (perlu dicek), C (split event — sah)
$dupA = $dupB = $split = [];
$sec = '';
foreach ($S['Duplikat'] ?? [] as $r) {
    $c0 = $r[0] ?? '';
    if (str_starts_with($c0, 'A ·')) { $sec = 'A'; continue; }
    if (str_starts_with($c0, 'B ·')) { $sec = 'B'; continue; }
    if (str_starts_with($c0, 'C ·')) { $sec = 'C'; continue; }
    if ($c0 === 'Properti' || $c0 === '' || str_starts_with($c0, 'TOTAL')) continue;

    if ($sec === 'A') {
        $dupA[] = ['properti' => $r[0], 'slot' => $r[1], 'klien' => $r[2], 'pic' => $r[3], 'periode' => $r[4],
                   'salinan' => (int) $r[5], 'nilai' => (float) $r[6], 'lebih' => (float) $r[7],
                   'ids' => $r[8] ?? '', 'input' => $r[9] ?? ''];
    } elseif ($sec === 'B') {
        $dupB[] = ['properti' => $r[0], 'slot' => $r[1], 'klien' => $r[2], 'pic' => $r[3], 'periode' => $r[4],
                   'baris' => (int) $r[5], 'nilai' => $r[6] ?? '', 'ids' => $r[8] ?? ''];
    } elseif ($sec === 'C') {
        $cells = array_values(array_filter($r, fn($v) => $v !== ''));
        $ids   = array_pop($cells);
        $total = (float) array_pop($cells);
        $bag   = [];
        foreach ($cells as $cx) {
            if (preg_match('/^([A-Za-zÀ-ÿ\. ]+?)\s+([\d.,]+)$/u', $cx, $bm)) {
                $bag[] = ['pic' => trim($bm[1]), 'nilai' => (float) str_replace([',', '.'], '', $bm[2])];
            }
        }
        $split[] = ['properti' => $r[0], 'slot' => $r[1], 'klien' => $r[2], 'periode' => $r[4] ?? '',
                    'bagian' => $bag, 'total' => $total, 'ids' => $ids];
    }
}

// Catatan metode (sheet 5)
$catatan = [];
foreach ($S['Catatan'] ?? [] as $r) {
    if (($r[0] ?? '') !== '' && ctype_digit((string) $r[0])) $catatan[(int) $r[0]] = ['judul' => $r[1] ?? '', 'isi' => ''];
    elseif (($r[1] ?? '') !== '' && $catatan) { $k = array_key_last($catatan); $catatan[$k]['isi'] = $r[1]; }
}

// ─── Verifikasi status transaksi ke database (kalau bisa) ────────────────────

$live = [];
$dbNote = 'Status transaksi tidak dicek ke database (koneksi tidak tersedia).';
$allIds = [];
foreach (array_merge($dupA, $dupB) as $d) {
    foreach (preg_split('/[,\s]+/', (string) $d['ids']) as $id) if (ctype_digit($id)) $allIds[] = (int) $id;
}
$allIds = array_values(array_unique($allIds));
if ($allIds) {
    try {
        require_once $root . '/app/env.php';
        require_once $root . '/app/Database.php';
        $pdo = Database::connect();
        $in  = implode(',', $allIds);
        $q   = $pdo->query(
            "SELECT t.id, t.module, t.master_code, COALESCE(c.company_name,'-') klien, t.start_date, t.end_date,
                    t.final_amount, t.pic_name, t.created_by, DATE(t.created_at) dibuat, t.deleted_at
             FROM transactions t LEFT JOIN master_clients c ON c.id = t.client_id
             WHERE t.id IN ($in)"
        );
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $live[(int) $row['id']] = $row;
        $dbNote = 'Status tiap transaksi sudah dicek langsung ke database CLARA pada ' . tgl_id() . '.';
    } catch (Throwable $e) {
        fwrite(STDERR, "  (lewati cek database: {$e->getMessage()})\n");
    }
}

// ─── Bantu tampilan ──────────────────────────────────────────────────────────

function rp($v): string
{
    $v = (float) $v;
    return ($v < 0 ? '&minus;' : '') . 'Rp ' . number_format(abs($v), 0, ',', '.');
}
function rpJt($v): string
{
    $v = (float) $v;
    $sign = $v < 0 ? '&minus;' : '';
    $a = abs($v);
    if ($a >= 1_000_000_000) return $sign . 'Rp ' . rtrim(rtrim(number_format($a / 1_000_000_000, 2, ',', '.'), '0'), ',') . ' miliar';
    if ($a >= 1_000_000)     return $sign . 'Rp ' . rtrim(rtrim(number_format($a / 1_000_000, 1, ',', '.'), '0'), ',') . ' juta';
    return rp($v);
}
function pct($v): string { return number_format(((float) $v) * 100, 1, ',', '.') . '%'; }
function e(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function tgl_id(?int $ts = null): string
{
    $b = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    $ts = $ts ?? time();
    return date('j', $ts) . ' ' . $b[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

function slug(string $s): string { return preg_replace('/[^A-Za-z0-9_\-]+/', '_', $s); }

/** Tindakan yang harus dilakukan untuk satu bulan. */
function tindakan_bulan(array $m): array
{
    $g = $m['gap'];
    $agt = $m['bulan'] === 'Agustus';
    if ($m['op'] == 0 && $m['clara'] == 0) return ['nol', 'Belum ada data di kedua sisi — tidak ada yang perlu dikerjakan.'];
    if ($g == 0)              return ['ok',    'Sudah cocok — tidak ada tindakan.'];
    if ($g > 0 && $g < 1_000_000)  return ['kecil', 'Selisih kecil ' . rp($g) . ' — cek pembulatan/diskon pada nilai kontrak.'];
    if ($g < 0 && $g > -1_000_000) return ['kecil', 'CLARA lebih ' . rp(-$g) . ' — cek pembulatan nilai kontrak.'];
    if ($g > 0) {
        return ['kurang', 'Input ke CLARA transaksi bulan ini yang belum tercatat, total ' . rp($g) . '.'
            . ($agt ? ' (Agustus masih berjalan, angka bisa berubah.)' : '')];
    }
    return ['lebih', 'CLARA kelebihan ' . rp(-$g) . ' — cek input ganda atau kontrak yang jatuh alokasinya di bulan ini.'];
}

// ─── Gaya dokumen ────────────────────────────────────────────────────────────

const CSS = '
<style>
  body { font-family: sans-serif; font-size: 9.6pt; color: #1F2937; line-height: 1.5; }
  h1 { font-size: 16pt; color: #0F1623; margin: 0 0 2mm; }
  h2 { font-size: 12pt; color: #0A7267; margin: 0 0 2mm; padding-bottom: 1.5mm; border-bottom: 1.2pt solid #0D9488; }
  h3 { font-size: 10.5pt; color: #0F1623; margin: 4mm 0 1.5mm; }
  p  { margin: 0 0 2.5mm; }
  .kicker { font-size: 8.5pt; letter-spacing: .08em; text-transform: uppercase; color: #0D9488; font-weight: bold; margin-bottom: 1mm; }
  .sub { color: #6B7280; font-size: 8.8pt; margin-bottom: 4mm; }
  .page-break { page-break-before: always; }
  table { width: 100%; border-collapse: collapse; }
  tr { page-break-inside: avoid; }
  th, td { border: 0.4pt solid #D1D5DB; padding: 1.6mm 2mm; vertical-align: top; }
  th { background: #F0FDF4; color: #0A7267; font-size: 8.4pt; text-transform: uppercase; letter-spacing: .03em; }
  td.n, th.n { text-align: right; }
  td.c, th.c { text-align: center; }
  .tot td { background: #F3F4F6; font-weight: bold; }
  .kurang { color: #B91C1C; font-weight: bold; }
  .lebih  { color: #B45309; font-weight: bold; }
  .ok     { color: #047857; font-weight: bold; }
  .muted  { color: #6B7280; }
  .box { page-break-inside: avoid; border: 0.6pt solid #D1D5DB; border-left: 2.4pt solid #0D9488; background: #F8FAFC; padding: 2.6mm 3mm; margin-bottom: 3mm; }
  .box.warn { border-left-color: #B45309; background: #FFFBEB; }
  .box.bad  { border-left-color: #B91C1C; background: #FEF2F2; }
  .box.good { border-left-color: #047857; background: #F0FDF4; }
  .big { font-size: 14pt; font-weight: bold; color: #0F1623; }
  .lbl { font-size: 8.2pt; color: #6B7280; text-transform: uppercase; letter-spacing: .04em; }
  .kv td { border: none; padding: 0 0 1.2mm; }
  ol, ul { margin: 0 0 3mm 5mm; padding: 0; }
  li { margin-bottom: 1.6mm; }
  .cek { font-size: 13pt; color: #9CA3AF; }
</style>';

/** Empat kotak angka utama milik satu sales. */
function kartu_angka(array $p): string
{
    $gapCls = $p['gap'] > 0 ? 'kurang' : ($p['gap'] < 0 ? 'lebih' : 'ok');
    $gapLbl = $p['gap'] > 0 ? 'Belum tercatat di CLARA' : ($p['gap'] < 0 ? 'Kelebihan di CLARA' : 'Selisih');
    return '<table style="margin-bottom:4mm"><tr>'
        . '<td style="width:25%"><div class="lbl">Seharusnya (file operasional)</div><div class="big">' . rpJt($p['op']) . '</div><div class="muted" style="font-size:8pt">' . rp($p['op']) . '</div></td>'
        . '<td style="width:25%"><div class="lbl">Tercatat di CLARA</div><div class="big">' . rpJt($p['clara']) . '</div><div class="muted" style="font-size:8pt">' . rp($p['clara']) . '</div></td>'
        . '<td style="width:25%"><div class="lbl">' . $gapLbl . '</div><div class="big ' . $gapCls . '">' . rpJt(abs($p['gap'])) . '</div><div class="muted" style="font-size:8pt">' . rp(abs($p['gap'])) . '</div></td>'
        . '<td style="width:25%"><div class="lbl">Porsi tercatat</div><div class="big ' . $gapCls . '">' . pct($p['pct']) . '</div><div class="muted" style="font-size:8pt">100% = lengkap</div></td>'
        . '</tr></table>';
}

/** Laporan lengkap satu sales. */
function halaman_sales(array $p, array $bulanNilai, array $bulanTrx, array $dupA, array $dupB, array $split, array $live, string $dbNote, array $rank, array $angka): string
{
    $nama = $p['sales'];
    $pos  = 1;
    foreach ($rank as $i => $r) if ($r['sales'] === $nama) $pos = $i + 1;
    $jml  = count($rank);

    $perluBackfill = array_values(array_filter($bulanNilai, fn($m) => $m['gap'] >= 1_000_000));
    $perluCek      = array_values(array_filter($bulanNilai, fn($m) => $m['gap'] <= -1_000_000));
    $totalBackfill = array_sum(array_column($perluBackfill, 'gap'));
    $totalLebih    = abs(array_sum(array_column($perluCek, 'gap')));
    $myDupA = array_values(array_filter($dupA, fn($d) => $d['pic'] === $nama));
    $myDupB = array_values(array_filter($dupB, fn($d) => $d['pic'] === $nama));
    $mySplit = array_values(array_filter($split, function ($s) use ($nama) {
        foreach ($s['bagian'] as $b) if (stripos($b['pic'], $nama) === 0) return true;
        return false;
    }));

    $h = CSS;

    // ── Halaman 1 — ringkasan ────────────────────────────────────────────────
    $h .= '<div class="kicker">Audit data · Januari–Agustus 2026</div>';
    $h .= '<h1>Laporan Selisih Data — ' . e($nama) . '</h1>';
    $h .= '<div class="sub">Properti ' . e($p['properti']) . ' &middot; perbandingan file operasional Casual Leasing dengan aplikasi CLARA &middot; data ditarik 23 Agustus 2026.</div>';
    $h .= kartu_angka($p);

    if ($p['gap'] > 0) {
        $h .= '<div class="box bad"><b>Bacaan singkat.</b> Dari ' . rpJt($p['op']) . ' dealing yang tercatat di file operasional atas nama Anda, '
            . 'yang sudah masuk CLARA baru ' . rpJt($p['clara']) . '. Ada <b>' . rp($p['gap']) . '</b> yang belum tercatat. '
            . 'Selama angka itu belum masuk, perhitungan pencapaian dan komisi Anda memakai angka yang lebih kecil dari kenyataan.</div>';
    } elseif ($p['gap'] < 0) {
        $h .= '<div class="box warn"><b>Bacaan singkat.</b> CLARA justru mencatat <b>' . rp(abs($p['gap'])) . '</b> lebih banyak dari file operasional. '
            . 'Yang perlu dilakukan bukan menambah data, tapi memastikan tidak ada transaksi yang terinput dua kali atau salah bulan.</div>';
    } else {
        $h .= '<div class="box good"><b>Bacaan singkat.</b> Angka CLARA sudah sama dengan file operasional.</div>';
    }

    $h .= '<h3>Yang harus Anda kerjakan</h3><table>'
        . '<tr><th style="width:62%">Pekerjaan</th><th class="c" style="width:14%">Jumlah</th><th class="n" style="width:24%">Nilai</th></tr>'
        . '<tr><td>Bulan yang perlu <b>diinput susulan</b> (data kurang di CLARA)</td><td class="c">' . count($perluBackfill) . ' bulan</td><td class="n ' . ($totalBackfill > 0 ? 'kurang' : '') . '">' . rp($totalBackfill) . '</td></tr>'
        . '<tr><td>Bulan yang perlu <b>dicek kelebihan</b> catatnya</td><td class="c">' . count($perluCek) . ' bulan</td><td class="n ' . ($totalLebih > 0 ? 'lebih' : '') . '">' . rp($totalLebih) . '</td></tr>'
        . '<tr><td>Transaksi <b>duplikat</b> atas nama Anda yang harus dihapus</td><td class="c">' . count($myDupA) . ' pasang</td><td class="n ' . (count($myDupA) ? 'kurang' : '') . '">' . rp(array_sum(array_column($myDupA, 'lebih'))) . '</td></tr>'
        . '<tr><td>Transaksi yang perlu <b>dicek manual</b> (nilai beda)</td><td class="c">' . count($myDupB) . ' kasus</td><td class="n">&mdash;</td></tr>'
        . '</table>';

    $h .= '<h3>Posisi Anda di tim</h3>';
    $h .= '<p>Porsi tercatat Anda <b>' . pct($p['pct']) . '</b>, sedangkan rata-rata tim ' . pct($angka['Porsi tercatat']['nilai'] ?? 0) . '. '
        . 'Urutan kelengkapan: <b>ke-' . $pos . ' dari ' . $jml . '</b> (nomor 1 = paling banyak selisihnya).</p>';
    $h .= '<table><tr><th class="c" style="width:8%">#</th><th>Sales</th><th class="n">Seharusnya</th><th class="n">Di CLARA</th><th class="n">Selisih</th><th class="n">% tercatat</th></tr>';
    foreach ($rank as $i => $r) {
        $me = $r['sales'] === $nama;
        $st = $me ? ' style="background:#E6F7F5;font-weight:bold"' : '';
        $h .= '<tr' . $st . '><td class="c">' . ($i + 1) . '</td><td>' . e($r['sales']) . ($me ? ' &larr; Anda' : '') . '</td>'
            . '<td class="n">' . rpJt($r['op']) . '</td><td class="n">' . rpJt($r['clara']) . '</td>'
            . '<td class="n">' . rpJt($r['gap']) . '</td><td class="n">' . pct($r['pct']) . '</td></tr>';
    }
    $h .= '</table>';
    $h .= '<p class="muted" style="font-size:8.4pt">Selisih bertanda minus berarti CLARA mencatat lebih banyak daripada file operasional.</p>';
    $h .= '<div class="box"><b>Sebelum lanjut.</b> Data Agustus ditarik tanggal 23, jadi bulan itu belum final. '
        . 'Bulan Juli seluruh tim sudah 100% cocok — artinya persoalan terbesar ada di Januari–Maret dan sifatnya kerja rapikan data, bukan bahan teguran.</div>';

    // ── Halaman 2 — nilai per bulan ──────────────────────────────────────────
    $h .= '<div class="page-break"></div>';
    $h .= '<h2>Rincian nilai per bulan</h2>';
    $h .= '<p>Kolom <b>Seharusnya</b> diambil dari file operasional Casual Leasing, kolom <b>Di CLARA</b> dari aplikasi. '
        . 'Kerjakan baris yang berwarna dari atas ke bawah.</p>';
    $h .= '<table><tr><th style="width:12%">Bulan</th><th class="n" style="width:16%">Seharusnya</th><th class="n" style="width:16%">Di CLARA</th>'
        . '<th class="n" style="width:15%">Selisih</th><th class="c" style="width:9%">%</th><th style="width:32%">Yang harus dikerjakan</th></tr>';
    $sumOp = $sumCl = 0;
    foreach ($bulanNilai as $m) {
        [$kode, $aksi] = tindakan_bulan($m);
        $cls = ['kurang' => 'kurang', 'lebih' => 'lebih', 'ok' => 'ok', 'kecil' => 'muted', 'nol' => 'muted'][$kode];
        $pctBulan = $m['op'] > 0 ? pct($m['clara'] / $m['op']) : '&mdash;';
        $sumOp += $m['op']; $sumCl += $m['clara'];
        $h .= '<tr><td><b>' . e($m['bulan']) . '</b></td><td class="n">' . rp($m['op']) . '</td><td class="n">' . rp($m['clara']) . '</td>'
            . '<td class="n ' . $cls . '">' . ($m['gap'] == 0 ? '0' : ($m['gap'] > 0 ? '+' : '&minus;') . rp(abs($m['gap']))) . '</td>'
            . '<td class="c">' . $pctBulan . '</td><td>' . $aksi . '</td></tr>';
    }
    $h .= '<tr class="tot"><td>TOTAL</td><td class="n">' . rp($sumOp) . '</td><td class="n">' . rp($sumCl) . '</td>'
        . '<td class="n">' . ($sumOp - $sumCl >= 0 ? '+' : '&minus;') . rp(abs($sumOp - $sumCl)) . '</td><td class="c">' . ($sumOp > 0 ? pct($sumCl / $sumOp) : '&mdash;') . '</td><td></td></tr></table>';
    $h .= '<div class="box"><b>Kenapa bisa ada bulan yang CLARA-nya lebih besar?</b> CLARA menyebar kontrak berjalan (recurring) ke tiap bulan, '
        . 'sedangkan file operasional kadang mencatatnya sekaligus di bulan penandatanganan. Jadi sebelum menyimpulkan input ganda, '
        . 'cek dulu apakah nilai itu milik kontrak yang periodenya melewati batas bulan.</div>';

    // ── Halaman 3 — jumlah transaksi ─────────────────────────────────────────
    $h .= '<div class="page-break"></div>';
    $h .= '<h2>Jumlah transaksi per bulan</h2>';
    $h .= '<p>Ini <b>bukan</b> ukuran benar atau salah. Satu baris booking di file operasional bisa menjadi beberapa transaksi di CLARA '
        . '(misalnya event atrium yang dibagi ke beberapa sales). Gunakan tabel ini hanya untuk menandai bulan yang perlu ditengok.</p>';
    $h .= '<table><tr><th style="width:14%">Bulan</th><th class="c" style="width:18%">Baris di file</th><th class="c" style="width:18%">Transaksi di CLARA</th>'
        . '<th class="c" style="width:14%">Beda</th><th style="width:36%">Artinya</th></tr>';
    foreach ($bulanTrx as $m) {
        $arti = $m['gap'] === 0 ? 'Sama persis.'
            : ($m['gap'] > 0 ? 'CLARA punya ' . $m['gap'] . ' baris lebih banyak — wajar bila ada event yang dibagi atau kontrak yang dipecah per bulan.'
                             : 'CLARA kurang ' . abs($m['gap']) . ' baris — cocokkan dengan daftar booking bulan ini.');
        $cls = $m['gap'] < 0 ? 'kurang' : ($m['gap'] > 0 ? 'muted' : 'ok');
        $h .= '<tr><td><b>' . e($m['bulan']) . '</b></td><td class="c">' . $m['op'] . '</td><td class="c">' . $m['clara'] . '</td>'
            . '<td class="c ' . $cls . '">' . ($m['gap'] > 0 ? '+' : '') . $m['gap'] . '</td><td>' . $arti . '</td></tr>';
    }
    $h .= '</table>';

    // ── Halaman 4 — temuan atas nama sales ini ───────────────────────────────
    $h .= '<div class="page-break"></div>';
    $h .= '<h2>Temuan atas nama Anda</h2>';
    $h .= '<p class="muted">' . e($dbNote) . '</p>';

    if ($myDupA) {
        $h .= '<h3 style="color:#B91C1C">A. Duplikat pasti — harus dihapus salah satunya</h3>';
        $h .= '<p>Slot, klien, tanggal, PIC, dan nilainya identik. Salinan yang dibuat lebih dulu <b>disimpan</b>, salinan berikutnya <b>dihapus</b>.</p>';
        foreach ($myDupA as $d) {
            $ids = array_values(array_filter(preg_split('/[,\s]+/', (string) $d['ids']), 'ctype_digit'));
            usort($ids, function ($a, $b) use ($live) {
                $da = $live[(int) $a]['dibuat'] ?? ''; $db = $live[(int) $b]['dibuat'] ?? '';
                return [$da, (int) $a] <=> [$db, (int) $b];
            });
            $h .= '<div class="box bad" style="margin-bottom:2.5mm">'
                . '<b>' . e($d['slot']) . ' &middot; ' . e($d['klien']) . '</b> &nbsp;<span class="muted">' . e($d['periode']) . ' &middot; ' . e($d['properti']) . '</span><br>'
                . 'Nilai satu kontrak ' . rp($d['nilai']) . ' &rarr; tercatat ' . (int) $d['salinan'] . ' kali, kelebihan <b>' . rp($d['lebih']) . '</b>.'
                . '<table style="margin-top:2mm"><tr><th class="c" style="width:12%">ID</th><th style="width:20%">Dibuat</th><th style="width:20%">Oleh</th>'
                . '<th class="n" style="width:20%">Nilai</th><th style="width:28%">Tindakan</th></tr>';
            foreach ($ids as $i => $id) {
                $L = $live[(int) $id] ?? null;
                $keep = $i === 0;
                $stat = $L === null ? 'tidak ditemukan' : ($L['deleted_at'] ? 'sudah dihapus' : 'masih aktif');
                $aksi = $L && $L['deleted_at'] ? '<span class="ok">Sudah beres.</span>'
                      : ($keep ? '<span class="ok">Simpan</span> — ini catatan aslinya.'
                               : '<span class="kurang">Hapus</span> — minta Superadmin menghapus ID ini.');
                $h .= '<tr><td class="c"><b>' . e((string) $id) . '</b></td><td>' . e($L['dibuat'] ?? '&mdash;') . '</td>'
                    . '<td>' . e($L['created_by'] ?? '&mdash;') . '</td><td class="n">' . ($L ? rp($L['final_amount']) : '&mdash;') . '</td>'
                    . '<td>' . $aksi . ' <span class="muted">(' . $stat . ')</span></td></tr>';
            }
            $h .= '</table></div>';
        }
    } else {
        $h .= '<div class="box good"><b>Tidak ada duplikat pasti atas nama Anda.</b> Tidak ada yang perlu dihapus.</div>';
    }

    if ($myDupB) {
        $h .= '<h3 style="color:#B45309">B. Perlu dicek manual — slot &amp; tanggal sama, nilai berbeda</h3>';
        $h .= '<p>Bisa jadi memang dua kontrak berbeda, bisa juga satu kontrak yang nilainya salah ketik. Buka keduanya di CLARA lalu bandingkan dengan berkas kontraknya.</p>';
        foreach ($myDupB as $d) {
            $h .= '<div class="box warn" style="margin-bottom:2.5mm"><b>' . e($d['slot']) . ' &middot; ' . e($d['klien']) . '</b> '
                . '<span class="muted">' . e($d['periode']) . ' &middot; ' . e($d['properti']) . '</span><br>Nilai tercatat: ' . e($d['nilai']) . '<table style="margin-top:2mm">'
                . '<tr><th class="c" style="width:12%">ID</th><th style="width:20%">Dibuat</th><th class="n" style="width:22%">Nilai di CLARA</th><th>Yang harus dicek</th></tr>';
            foreach (preg_split('/[,\s]+/', (string) $d['ids']) as $id) {
                if (!ctype_digit($id)) continue;
                $L = $live[(int) $id] ?? null;
                $h .= '<tr><td class="c"><b>' . e($id) . '</b></td><td>' . e($L['dibuat'] ?? '&mdash;') . '</td>'
                    . '<td class="n">' . ($L ? rp($L['final_amount']) : '&mdash;') . '</td>'
                    . '<td>Cocokkan dengan kontrak: kalau salah nilai, perbaiki lewat Edit; kalau memang dobel, minta Superadmin hapus.</td></tr>';
            }
            $h .= '</table></div>';
        }
    }

    if ($mySplit) {
        $h .= '<h3 style="color:#047857">C. Event yang dibagi beberapa sales — sah, tidak perlu diapa-apakan</h3>';
        $h .= '<p>Muncul di sini supaya Anda tidak salah mengira ini duplikat.</p><table>'
            . '<tr><th style="width:22%">Slot &amp; klien</th><th style="width:22%">Periode</th><th style="width:38%">Pembagian</th><th class="n" style="width:18%">Total</th></tr>';
        foreach ($mySplit as $s) {
            $bag = [];
            foreach ($s['bagian'] as $b) {
                $me = stripos($b['pic'], $nama) === 0;
                $bag[] = ($me ? '<b>' : '') . e($b['pic']) . ' ' . rp($b['nilai']) . ($me ? '</b>' : '');
            }
            $h .= '<tr><td><b>' . e($s['slot']) . '</b><br><span class="muted">' . e($s['klien']) . '</span></td><td>' . e($s['periode']) . '</td>'
                . '<td>' . implode('<br>', $bag) . '</td><td class="n">' . rp($s['total']) . '</td></tr>';
        }
        $h .= '</table>';
    }

    // ── Halaman 5 — langkah perbaikan ────────────────────────────────────────
    $h .= '<div class="page-break"></div>';
    $h .= '<h2>Langkah perbaikan &amp; daftar centang</h2>';
    $h .= '<h3>A. Menambah transaksi yang belum tercatat</h3><ol>'
        . '<li>Buka CLARA, masuk dengan akun Anda sendiri.</li>'
        . '<li>Pilih menu sesuai jenisnya: <b>Exhibition</b> (unit pameran), <b>Media</b> (billboard/branding), atau <b>Gudang</b>.</li>'
        . '<li>Klik <b>Tambah Transaksi</b>. Isi slot/unit, klien, periode kontrak, nilai, dan pastikan kolom <b>PIC</b> berisi nama Anda (' . e($nama) . ').</li>'
        . '<li>Simpan. Ulangi untuk setiap booking yang ada di file operasional tapi belum ada di CLARA.</li>'
        . '<li>Kalau kliennya belum ada, buat dulu lewat <b>Master Client</b>.</li></ol>';
    $h .= '<h3>B. Membereskan duplikat</h3><ol>'
        . '<li>Akun sales <b>tidak bisa menghapus</b> transaksi — itu hak Superadmin, supaya tidak ada data hilang tanpa jejak.</li>'
        . '<li>Catat ID yang bertanda <span class="kurang">Hapus</span> di bagian <b>Temuan atas nama Anda</b>, lalu kirimkan ke Superadmin/IT untuk dihapus.</li>'
        . '<li>Yang <b>salah nilai</b> (bukan dobel) boleh Anda perbaiki sendiri lewat tombol <b>Edit</b> di transaksi tersebut.</li></ol>';
    $h .= '<h3>C. Memastikan sudah benar</h3><ol>'
        . '<li>Buka menu <b>Laporan PIC</b>, pilih bulan yang baru Anda perbaiki.</li>'
        . '<li>Bandingkan angkanya dengan kolom <b>Seharusnya</b> di bagian <b>Rincian nilai per bulan</b>.</li>'
        . '<li>Kalau sudah sama, centang bulan itu di tabel bawah ini.</li></ol>';

    $h .= '<h3>Daftar centang per bulan</h3><table>'
        . '<tr><th class="c" style="width:10%">Selesai</th><th style="width:16%">Bulan</th><th class="n" style="width:22%">Kekurangan</th><th style="width:52%">Catatan pengerjaan</th></tr>';
    $adaTugas = false;
    foreach ($bulanNilai as $m) {
        [$kode] = tindakan_bulan($m);
        if (!in_array($kode, ['kurang', 'lebih'], true)) continue;
        $adaTugas = true;
        $h .= '<tr><td class="c cek">&#9744;</td><td><b>' . e($m['bulan']) . '</b></td>'
            . '<td class="n ' . ($m['gap'] > 0 ? 'kurang' : 'lebih') . '">' . rp(abs($m['gap'])) . ($m['gap'] < 0 ? ' (lebih)' : '') . '</td><td></td></tr>';
    }
    foreach ($myDupA as $d) {
        $adaTugas = true;
        $h .= '<tr><td class="c cek">&#9744;</td><td><b>Duplikat</b></td><td class="n kurang">' . rp($d['lebih']) . '</td>'
            . '<td>' . e($d['slot'] . ' · ' . $d['klien']) . ' — ID ' . e($d['ids']) . ' sudah dilaporkan ke Superadmin</td></tr>';
    }
    if (!$adaTugas) $h .= '<tr><td class="c">&mdash;</td><td colspan="3">Tidak ada tugas perbaikan. Pertahankan.</td></tr>';
    $h .= '</table>';

    $h .= '<table class="kv" style="margin-top:8mm"><tr>'
        . '<td style="width:50%">Dikerjakan oleh<br><br><br>( ' . e($nama) . ' )</td>'
        . '<td style="width:50%">Diperiksa oleh<br><br><br>( ......................................... )</td></tr></table>';
    return $h;
}

/** Rekap untuk atasan: angka utama, peringkat, seluruh temuan, catatan metode. */
function halaman_rekap(array $angka, array $prop, array $rank, array $nilai, array $dupA, array $dupB, array $split, array $catatan, array $live, string $dbNote): string
{
    $h = CSS;
    $h .= '<div class="kicker">Rekap tim &middot; Januari–Agustus 2026</div>';
    $h .= '<h1>Audit Input Data Sales — Excel vs CLARA</h1>';
    $h .= '<div class="sub">Perbandingan file operasional Casual Leasing dengan aplikasi CLARA &middot; data ditarik 23 Agustus 2026 &middot; ' . e($dbNote) . '</div>';

    $tot = $rank ? array_sum(array_column($rank, 'op')) : 0;
    $totC = $rank ? array_sum(array_column($rank, 'clara')) : 0;
    $h .= '<table style="margin-bottom:4mm"><tr>'
        . '<td style="width:25%"><div class="lbl">Total seharusnya</div><div class="big">' . rpJt($angka['Total dealing — file operasional']['nilai'] ?? $tot) . '</div></td>'
        . '<td style="width:25%"><div class="lbl">Tercatat di CLARA</div><div class="big">' . rpJt($angka['Total dealing — CLARA']['nilai'] ?? $totC) . '</div></td>'
        . '<td style="width:25%"><div class="lbl">Belum tercatat</div><div class="big kurang">' . rpJt($angka['Tidak tercatat di CLARA']['nilai'] ?? 0) . '</div></td>'
        . '<td style="width:25%"><div class="lbl">Porsi tercatat</div><div class="big">' . pct($angka['Porsi tercatat']['nilai'] ?? 0) . '</div></td>'
        . '</tr></table>';

    if ($prop) {
        $h .= '<h3>Per properti</h3><table><tr><th>Properti</th><th class="n">Seharusnya</th><th class="n">Di CLARA</th><th class="n">Belum tercatat</th><th class="c">% tercatat</th></tr>';
        foreach ($prop as $p) {
            $isTot = strtoupper($p['nama']) === 'TOTAL';
            $h .= '<tr' . ($isTot ? ' class="tot"' : '') . '><td>' . e($p['nama']) . '</td><td class="n">' . rp($p['op']) . '</td><td class="n">' . rp($p['clara']) . '</td>'
                . '<td class="n kurang">' . rp($p['gap']) . '</td><td class="c">' . pct($p['pct']) . '</td></tr>';
        }
        $h .= '</table>';
    }

    $h .= '<h3>Peringkat kelengkapan &amp; beban perbaikan</h3><table>'
        . '<tr><th class="c" style="width:6%">#</th><th style="width:14%">Sales</th><th style="width:12%">Properti</th><th class="n" style="width:19%">Belum tercatat</th>'
        . '<th class="c" style="width:9%">%</th><th style="width:40%">Fokus perbaikan</th></tr>';
    foreach ($rank as $i => $r) {
        $bulan = $nilai[$r['sales']] ?? [];
        $kurang = array_values(array_filter($bulan, fn($m) => $m['gap'] >= 1_000_000));
        $lebih  = array_values(array_filter($bulan, fn($m) => $m['gap'] <= -1_000_000));
        $dup    = array_values(array_filter($dupA, fn($d) => $d['pic'] === $r['sales']));
        $f = [];
        if ($kurang) $f[] = 'input susulan ' . count($kurang) . ' bulan (' . implode(', ', array_column($kurang, 'bulan')) . ')';
        if ($lebih)  $f[] = 'cek kelebihan ' . count($lebih) . ' bulan (' . implode(', ', array_column($lebih, 'bulan')) . ')';
        if ($dup)    $f[] = '<span class="kurang">' . count($dup) . ' duplikat harus dihapus</span>';
        $h .= '<tr><td class="c">' . ($i + 1) . '</td><td><b>' . e($r['sales']) . '</b></td><td>' . e($r['properti']) . '</td>'
            . '<td class="n ' . ($r['gap'] > 0 ? 'kurang' : 'lebih') . '">' . rpJt($r['gap'])
            . '<br><span class="muted" style="font-weight:normal;font-size:7.8pt">' . rp($r['gap']) . '</span></td>'
            . '<td class="c">' . pct($r['pct']) . '</td>'
            . '<td>' . ($f ? ucfirst(implode('; ', $f)) . '.' : 'Tidak ada tugas perbaikan.') . '</td></tr>';
    }
    $h .= '</table>';

    // Halaman 2 — daftar temuan
    $h .= '<div class="page-break"></div><h2>Daftar temuan duplikat</h2>';
    $aktif = 0;
    foreach ($dupA as $d) foreach (preg_split('/[,\s]+/', (string) $d['ids']) as $id)
        if (ctype_digit($id) && isset($live[(int) $id]) && !$live[(int) $id]['deleted_at']) $aktif++;
    $h .= '<p>Total kelebihan catat <b>' . rp(array_sum(array_column($dupA, 'lebih'))) . '</b> dari ' . count($dupA) . ' pasang transaksi. '
        . ($live ? 'Saat laporan ini dibuat, <b>' . $aktif . ' transaksi</b> dari pasangan itu masih aktif di database.' : '') . '</p>';
    $h .= '<table><tr><th style="width:12%">Properti</th><th style="width:12%">Slot</th><th style="width:22%">Klien</th><th style="width:10%">PIC</th>'
        . '<th style="width:16%">Periode</th><th class="n" style="width:14%">Kelebihan</th><th style="width:14%">ID</th></tr>';
    foreach ($dupA as $d) {
        $h .= '<tr><td>' . e($d['properti']) . '</td><td>' . e($d['slot']) . '</td><td>' . e($d['klien']) . '</td><td><b>' . e($d['pic']) . '</b></td>'
            . '<td>' . e($d['periode']) . '</td><td class="n kurang">' . rp($d['lebih']) . '</td><td>' . e($d['ids']) . '</td></tr>';
    }
    $h .= '</table>';

    if ($dupB) {
        $h .= '<h3>Perlu dicek manual (nilai berbeda)</h3><table>'
            . '<tr><th style="width:12%">Properti</th><th style="width:12%">Slot</th><th style="width:26%">Klien</th><th style="width:10%">PIC</th><th style="width:20%">Nilai</th><th style="width:20%">ID</th></tr>';
        foreach ($dupB as $d) {
            $h .= '<tr><td>' . e($d['properti']) . '</td><td>' . e($d['slot']) . '</td><td>' . e($d['klien']) . '</td><td>' . e($d['pic']) . '</td>'
                . '<td>' . e($d['nilai']) . '</td><td>' . e($d['ids']) . '</td></tr>';
        }
        $h .= '</table>';
    }

    if ($split) {
        $h .= '<h3>Event yang dibagi antar sales (sah)</h3><table>'
            . '<tr><th style="width:12%">Properti</th><th style="width:26%">Klien</th><th style="width:18%">Periode</th><th style="width:30%">Pembagian</th><th class="n" style="width:14%">Total</th></tr>';
        foreach ($split as $s) {
            $bag = [];
            foreach ($s['bagian'] as $b) $bag[] = e($b['pic']) . ' ' . rp($b['nilai']);
            $h .= '<tr><td>' . e($s['properti']) . '</td><td>' . e($s['klien']) . '</td><td>' . e($s['periode']) . '</td>'
                . '<td>' . implode('<br>', $bag) . '</td><td class="n">' . rp($s['total']) . '</td></tr>';
        }
        $h .= '</table>';
    }

    // Halaman 3 — catatan metode
    if ($catatan) {
        $h .= '<div class="page-break"></div><h2>Catatan metode — baca sebelum menagih siapa pun</h2>';
        foreach ($catatan as $no => $c) {
            $h .= '<div class="box"><b>' . (int) $no . '. ' . e($c['judul']) . '</b><br>' . e($c['isi']) . '</div>';
        }
    }
    return $h;
}

// ─── Tulis berkas ────────────────────────────────────────────────────────────

@mkdir($outRoot, 0777, true);

$hasil = [];
foreach ($rank as $p) {
    $nama = $p['sales'];
    $dir  = $outRoot . '/' . slug($nama);
    @mkdir($dir, 0777, true);
    $html = halaman_sales($p, $nilai[$nama] ?? [], $trx[$nama] ?? [], $dupA, $dupB, $split, $live, $dbNote, $rank, $angka);
    $file = $dir . '/Laporan_Selisih_' . slug($nama) . '_Jan-Agt_2026.pdf';
    $mpdf = clara_letterhead_mpdf();
    $mpdf->SetTitle('Laporan Selisih Data — ' . $nama);
    $mpdf->WriteHTML($html);
    $mpdf->Output($file, \Mpdf\Output\Destination::FILE);
    if (getenv('AUDIT_DUMP_HTML')) file_put_contents($dir . '/_pratinjau.html', $html);
    $hasil[] = [$nama, $file, $mpdf->page];
    echo '  ✓ ' . str_pad($nama, 10) . ' → ' . basename($file) . ' (' . $mpdf->page . " halaman)\n";
}

$dirRekap = $outRoot . '/00_Rekap_Tim';
@mkdir($dirRekap, 0777, true);
$mpdf = clara_letterhead_mpdf();
$mpdf->SetTitle('Rekap Audit Data Sales — Jan–Agt 2026');
$mpdf->WriteHTML(halaman_rekap($angka, $prop, $rank, $nilai, $dupA, $dupB, $split, $catatan, $live, $dbNote));
$fileRekap = $dirRekap . '/Rekap_Audit_Tim_Jan-Agt_2026.pdf';
$mpdf->Output($fileRekap, \Mpdf\Output\Destination::FILE);
echo '  ✓ ' . str_pad('REKAP', 10) . ' → ' . basename($fileRekap) . ' (' . $mpdf->page . " halaman)\n";

// Petunjuk singkat di folder induk
$baca = "# Audit Selisih Data — Januari–Agustus 2026\n\n"
    . "Sumber: Audit_Data_Excel_vs_CLARA_2026.xlsx (data ditarik 23 Agustus 2026).\n"
    . "Dibuat ulang dengan: php scripts/build_audit_selisih.php\n\n"
    . "## Isi folder\n\n"
    . "- `00_Rekap_Tim/` — rekap untuk atasan: angka utama, peringkat, seluruh temuan, dan catatan metode.\n"
    . "- Satu folder per sales, masing-masing berisi laporan pribadi 5 halaman:\n"
    . "  1. Ringkasan — seharusnya berapa, tercatat berapa, kurang berapa.\n"
    . "  2. Rincian nilai per bulan + tindakan tiap bulan.\n"
    . "  3. Jumlah transaksi per bulan (pembanding, bukan penilaian).\n"
    . "  4. Temuan atas namanya: duplikat yang harus dihapus, yang perlu dicek, dan event bagi-bagi yang sah.\n"
    . "  5. Langkah perbaikan di CLARA + daftar centang per bulan.\n\n"
    . "## Urutan yang perlu ditangani lebih dulu\n\n";
foreach ($rank as $i => $r) {
    $teks = fn(string $v): string => str_replace(['&minus;', '&mdash;'], ['-', '-'], $v);
    $baca .= sprintf("%d. %-10s %-11s belum tercatat %s (%s)\n", $i + 1, $r['sales'], '(' . $r['properti'] . ')', $teks(rp($r['gap'])), $teks(pct($r['pct'])));
}
$baca .= "\nCatatan: Agustus belum final (data per tanggal 23). Juli seluruh tim sudah 100%, jadi fokus utama ada di Januari–Maret.\n";
file_put_contents($outRoot . '/BACA_DULU.md', $baca);

echo "\nSelesai. Folder: $outRoot\n";
