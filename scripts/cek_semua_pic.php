<?php
/**
 * Sandingkan income SEMUA PIC dengan spreadsheet, untuk satu bulan.
 *
 * Dipakai untuk pertanyaan "selain Shinta, siapa lagi yang angkanya meleset dan
 * salahnya di mana". Keluarannya bukan sekadar selisih: tiap baris yang tidak
 * cocok digolongkan menurut SEBABnya, karena tindakannya berbeda-beda —
 * yang belum diinput harus dibuat, yang periodenya salah harus dibetulkan
 * tanggalnya, dan yang hanya beda nama tidak perlu disentuh sama sekali.
 *
 * Sisi CLARA dibaca langsung dari database yang sedang dipakai. Sisi
 * spreadsheet dari berkas JSON hasil scripts/banding/ekstrak_semua_pic.py,
 * bentuknya {NAMA_PIC: {"bulan": {...}, "rincian": {...}}}.
 *
 * MURNI MELIHAT.
 *
 * Pakai:
 *   php scripts/cek_semua_pic.php --banding=penta.json --prop=2 --bulan=2026-10
 *   php scripts/cek_semua_pic.php --banding=penta.json --prop=2 --bulan=2026-10 --pic=ALFA
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';

$opt = array_merge(['banding' => '', 'prop' => '', 'bulan' => date('Y-m'), 'pic' => '', 'json' => ''],
    getopt('', ['banding:', 'prop:', 'bulan:', 'pic:', 'json:']));
if ($opt['banding'] === '' || $opt['prop'] === '') {
    fwrite(STDERR, "Pakai: --banding=berkas.json --prop=2 [--bulan=2026-10] [--pic=ALFA] [--json=keluar.json]\n");
    exit(2);
}
$pid = (int) $opt['prop'];
$bln = (string) $opt['bulan'];
$ss  = json_decode((string) file_get_contents((string) $opt['banding']), true);
if (!is_array($ss)) { fwrite(STDERR, "Berkas banding tidak terbaca.\n"); exit(2); }

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$rp = fn($v) => number_format((float) $v, 0, ',', '.');

function _norm2(string $t): string
{
    return trim(preg_replace('/\s+/', ' ',
        preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($t, 'UTF-8')) ?? ''));
}

/**
 * Baris spreadsheet ditulis "LOKASI | PENYEWA". Keduanya dibandingkan dengan
 * hal yang berbeda — lokasi dengan daftar unit, penyewa dengan nama klien —
 * jadi dipisah di satu tempat ini supaya tidak ada yang salah bandingkan.
 */
function _lokasi(string $ket): string { return trim(explode('|', $ket)[0]); }
function _penyewa(string $ket): string
{
    $b = explode('|', $ket);
    return trim(count($b) > 1 ? $b[1] : $b[0]);
}

/** Kata penting sebuah nama. Batas 3 huruf: "MCD", "YSL", "BYD" membedakan. */
function _kata2(string $t): array
{
    return array_values(array_filter(explode(' ', _norm2($t)), fn($k) => mb_strlen($k) >= 3));
}

/**
 * Kemiripan dua nama penyewa, 0..100.
 *
 * Ejaan antar berkas tidak pernah sama: "Soursally"/"Sour Sally",
 * "Tawan"/"Ta Wan", "MCD"/"McD". Spasi pun berbeda, jadi selain mencocokkan
 * kata per kata, seluruh teksnya dibandingkan tanpa spasi sekalian.
 */
/** Beda huruf yang masih boleh disebut ejaan berbeda, menurut panjang katanya. */
function _toleransi(string $x, string $y): int
{
    // Satu huruf pada kata pendek sudah mengubah artinya: "main" dan "skin"
    // berjarak dua huruf tetapi dua penyewa yang sama sekali berlainan.
    $n = min(mb_strlen($x), mb_strlen($y));
    return $n >= 7 ? 2 : ($n >= 5 ? 1 : 0);
}

/**
 * Kemiripan dua nama, 0..100.
 *
 * Baris spreadsheet tidak selalu ditulis "LOKASI | PENYEWA" — ada juga
 * "PENYEWA | PERIODE" ("X8 | 1 Juli - 31 Desember"). Karena itu kedua
 * potongannya dicoba, lalu diambil yang paling mirip; memilih satu potongan
 * saja membuat separuh baris tidak pernah ketemu pasangannya.
 */
function _mirip2(string $a, string $b): int
{
    $calonA = array_unique(array_filter([_penyewa($a), _lokasi($a), $a]));
    $calonB = array_unique(array_filter([_penyewa($b), _lokasi($b), $b]));
    $best = 0;
    foreach ($calonA as $pa) {
        foreach ($calonB as $pb) {
            $na = str_replace(' ', '', _norm2($pa));
            $nb = str_replace(' ', '', _norm2($pb));
            if ($na === '' || $nb === '') continue;
            if ($na === $nb) return 100;
            // Hanya yang cukup panjang: "x8" termuat di hampir semua teks.
            if (mb_strlen($na) >= 4 && mb_strlen($nb) >= 4
                && (str_contains($na, $nb) || str_contains($nb, $na))) { $best = max($best, 90); continue; }
            $n = 0;
            foreach (_kata2($pa) as $x) {
                foreach (_kata2($pb) as $y) {
                    if ($x === $y || levenshtein($x, $y) <= _toleransi($x, $y)) { $n++; break; }
                }
            }
            if ($n > 0) { $best = max($best, 70 + min(20, $n * 10)); continue; }
            similar_text($na, $nb, $persen);
            if ($persen >= 80) $best = max($best, (int) $persen);
        }
    }
    return $best;
}

// Nama PIC di spreadsheet ditulis singkat dan huruf besar; di CLARA ditulis
// lengkap. Dicocokkan dengan awalan, bukan daftar tetap, supaya PIC baru tidak
// perlu ditambahkan ke skrip ini.
$picClara = array_column($pdo->query(
    "SELECT DISTINCT pic_name FROM transaction_allocations WHERE property_id = $pid
      AND pic_name IS NOT NULL AND pic_name <> ''")->fetchAll(), 'pic_name');
$padankan = function (string $ssPic) use ($picClara): ?string {
    foreach ($picClara as $c) {
        if (mb_strtolower($c) === mb_strtolower($ssPic)) return $c;
    }
    // "SINTA" vs "Shinta", "ZUL" vs "Zulfikar": awalan saja tidak cukup, dan
    // daftar tetap akan basi begitu ada PIC baru. Jarak huruf menangkap
    // keduanya tanpa perlu dirawat.
    $best = null; $skor = 0;
    foreach ($picClara as $c) {
        $a = mb_strtolower($c); $b = mb_strtolower($ssPic);
        $cocok = levenshtein($a, $b) <= 2 || str_starts_with($a, $b) || str_starts_with($b, $a);
        if (!$cocok) continue;
        similar_text($a, $b, $persen);
        if ($persen > $skor) { $skor = $persen; $best = $c; }
    }
    return $best;
};

// Daftar unit semua modul — dipakai menebak baris spreadsheet yang belum
// diinput itu unit mana, supaya perbaikannya bisa menyebut menu yang benar.
$unit = [];
foreach ([['master_cl_units', 'cl', 'location_name'],
          ['master_gudang', 'gudang', 'name'],
          ['master_media', 'media', 'location']] as [$tbl, $mod, $kol]) {
    try {
        foreach ($pdo->query("SELECT code, `$kol` AS label FROM `$tbl` WHERE property_id = $pid") as $r) {
            if ((string) $r['label'] !== '') $unit[] = ['kode' => $r['code'], 'modul' => $mod, 'label' => (string) $r['label']];
        }
    } catch (Throwable $e) { /* tabel tidak ada di skema ini — lewati */ }
}
$tebakUnit = function (string $ket) use ($unit): ?array {
    // Hanya bagian LOKASI yang dibandingkan. Membandingkan seluruh baris
    // membuat nama penyewa ikut menentukan, dan "6/ FF FC | SI MBOK" bisa
    // tertebak sebagai gudang hanya karena ada kata yang mirip di sana.
    $cari = str_replace(' ', '', _norm2(_lokasi($ket)));
    if ($cari === '') return null;
    $pilih = null; $skor = 0.0;
    foreach ($unit as $u) {
        $lbl = str_replace(' ', '', _norm2($u['label']));
        if ($lbl === '') continue;
        if ($lbl === $cari) return $u + ['pasti' => true];
        similar_text($cari, $lbl, $persen);
        if ($persen > $skor) { $skor = $persen; $pilih = $u; }
    }
    // Di bawah kecocokan persis, kodenya cuma DUGAAN. Label di spreadsheet dan
    // di Master kadang tidak punya padanan sama sekali ("29/ FF FS" tidak ada;
    // yang ada "28/ FF FS" dan "29/ FF Island"), dan menyebut kode yang salah
    // di tutorial jauh lebih berbahaya daripada tidak menyebutnya.
    return $skor >= 80 ? $pilih + ['pasti' => false] : null;
};

$qTot = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM transaction_allocations
                        WHERE property_id = ? AND pic_name = ? AND period_key = ?');
$qRinci = $pdo->prepare(
    "SELECT a.amount, t.id trx, t.module, t.master_code, t.start_date, t.end_date,
            t.billing_method, t.pricing_type,
            COALESCE(NULLIF(t.final_amount,0), t.total_calculated) nilai_kontrak,
            COALESCE(c.brand_name, c.company_name, '(tanpa nama)') nama
       FROM transaction_allocations a
       JOIN transactions t ON t.id = a.transaction_id
       LEFT JOIN master_clients c ON c.id = t.client_id
      WHERE a.property_id = ? AND a.pic_name = ? AND a.period_key = ?
        AND a.amount > 0 AND t.deleted_at IS NULL
      ORDER BY a.amount DESC"
);

$namaProp = (string) ($pdo->query("SELECT name FROM properties WHERE id = $pid")->fetchColumn() ?: "properti $pid");
echo "\nSANDINGAN SEMUA PIC — $namaProp · $bln\n";
echo str_repeat('=', 104) . "\n\n";

$keluar = [];
$ringkas = [];
foreach ($ss as $ssPic => $data) {
    if ($opt['pic'] !== '' && mb_strtolower($ssPic) !== mb_strtolower((string) $opt['pic'])) continue;
    $pic = $padankan((string) $ssPic);
    if ($pic === null) { $ringkas[] = [$ssPic, '(tidak ada di CLARA)', 0.0, (float) ($data['bulan'][$bln] ?? 0), 0.0]; continue; }

    $qTot->execute([$pid, $pic, $bln]);
    $cl = (float) $qTot->fetchColumn();
    $target = (float) ($data['bulan'][$bln] ?? 0);
    $ringkas[] = [$ssPic, $pic, $cl, $target, $cl - $target];

    $qRinci->execute([$pid, $pic, $bln]);
    $clRows = $qRinci->fetchAll();
    $ssRows = array_values(array_filter($data['rincian'][$bln] ?? [],
        fn($r) => $target <= 0 || (float) $r['nilai'] <= $target));

    // Tahap 1: nilai sama DAN nama mirip. Tahap 2: nilai sama saja.
    $sisaCl = $clRows; $sisaSs = $ssRows; $cocok = []; $ragu = [];
    foreach ($sisaSs as $j => $s) {
        $pilih = null; $best = 0;
        foreach ($sisaCl as $i => $c) {
            if (round((float) $c['amount']) !== round((float) $s['nilai'])) continue;
            $sk = _mirip2((string) $s['ket'], (string) $c['nama']);
            if ($sk > $best) { $best = $sk; $pilih = $i; }
        }
        if ($pilih === null) continue;
        $cocok[] = [$s, $sisaCl[$pilih]]; unset($sisaCl[$pilih], $sisaSs[$j]);
    }
    foreach ($sisaSs as $j => $s) {
        foreach ($sisaCl as $i => $c) {
            if (round((float) $c['amount']) !== round((float) $s['nilai'])) continue;
            $ragu[] = [$s, $c]; unset($sisaCl[$i], $sisaSs[$j]); break;
        }
    }
    // Tahap 3: namanya sama tetapi NILAINYA beda. Tanpa tahap ini satu penyewa
    // terbelah jadi dua laporan — "belum diinput" di satu sisi dan "ada di
    // CLARA saja" di sisi lain — padahal barisnya itu-itu juga dan yang salah
    // cuma angkanya. Inilah bentuk kesalahan yang paling sering: kontrak
    // beberapa bulan yang nilainya dibagi rata, sementara spreadsheet
    // membukukan satu siklus penuh di bulan mulainya.
    $beda = [];
    foreach ($sisaSs as $j => $sRow) {
        $pilih = null; $best = 0;
        foreach ($sisaCl as $i => $c) {
            $sk = _mirip2((string) $sRow['ket'], (string) $c['nama']);
            // Tanpa nilai sebagai jangkar, kemiripan nama harus jauh lebih
            // meyakinkan daripada di tahap 1 — kalau tidak, dua penyewa yang
            // namanya kebetulan sedikit mirip akan dilaporkan sebagai satu
            // baris yang nilainya salah.
            if ($sk >= 85 && $sk > $best) { $best = $sk; $pilih = $i; }
        }
        if ($pilih === null) continue;
        $beda[] = [$sRow, $sisaCl[$pilih]];
        unset($sisaCl[$pilih], $sisaSs[$j]);
    }
    $kurang = array_values($sisaSs); $lebih = array_values($sisaCl);

    $keluar[$ssPic] = [
        'pic_clara' => $pic, 'clara' => $cl, 'spreadsheet' => $target,
        'cocok' => count($cocok), 'ragu' => [], 'beda' => [], 'kurang' => [], 'lebih' => [],
    ];
    foreach ($beda as [$sRow, $c]) {
        $ssN = (float) $sRow['nilai']; $clN = (float) $c['amount'];
        // Kelipatan bulat berarti nilainya dibagi ke beberapa bulan. Itu dugaan
        // penyebab, bukan vonis — tanggal kontraknya ikut dicetak supaya bisa
        // diperiksa sendiri.
        $bagi = $clN > 0 ? $ssN / $clN : 0.0;
        $bulat = $bagi > 1.5 && abs($bagi - round($bagi)) < 0.02 ? (int) round($bagi) : 0;
        // Pembeda yang menentukan tindakannya: apakah NILAI KONTRAKnya sudah
        // benar. Kalau sudah, yang salah hanya bulan pengakuannya — kontraknya
        // tidak boleh disentuh, cukup pengakuannya. Kalau nilai kontraknya
        // sendiri berbeda, justru angkanya yang perlu dibetulkan.
        $kontrak = (float) $c['nilai_kontrak'];
        $sebab = round($kontrak) === round($ssN)
            ? 'pengakuan' : 'nilai';
        $keluar[$ssPic]['beda'][] = [
            'ket' => $sRow['ket'], 'nama' => $c['nama'], 'kode' => $c['master_code'],
            'modul' => $c['module'], 'trx' => (int) $c['trx'],
            'nilai_ss' => $ssN, 'nilai_clara' => $clN, 'selisih' => $ssN - $clN,
            'kelipatan' => $bulat, 'metode' => $c['billing_method'], 'harga' => $c['pricing_type'],
            'nilai_kontrak' => $kontrak, 'sebab' => $sebab,
            'mulai' => substr((string) $c['start_date'], 0, 10),
            'selesai' => substr((string) $c['end_date'], 0, 10),
        ];
    }
    foreach ($ragu as [$s, $c]) {
        $keluar[$ssPic]['ragu'][] = ['ket' => $s['ket'], 'nama' => $c['nama'],
            'kode' => $c['master_code'], 'trx' => (int) $c['trx'], 'nilai' => (float) $s['nilai']];
    }
    foreach ($kurang as $k) {
        $u = $tebakUnit((string) $k['ket']);
        $keluar[$ssPic]['kurang'][] = ['ket' => $k['ket'], 'nilai' => (float) $k['nilai'],
            'modul' => $u['modul'] ?? null, 'kode' => $u['kode'] ?? null, 'label' => $u['label'] ?? null,
            'unit_pasti' => $u['pasti'] ?? false];
    }
    foreach ($lebih as $c) {
        $silang = substr((string) $c['start_date'], 0, 7) !== substr((string) $c['end_date'], 0, 7);
        $blnDepan = date('Y-m', strtotime($bln . '-01 +1 month'));
        $adaDepan = null;
        if (isset($data['rincian'][$blnDepan])) {
            $adaDepan = false;
            foreach ($data['rincian'][$blnDepan] as $r) {
                if (round((float) $r['nilai']) === round((float) $c['amount'])
                    && _mirip2((string) $r['ket'], (string) $c['nama']) > 0) { $adaDepan = true; break; }
            }
        }
        $keluar[$ssPic]['lebih'][] = ['nama' => $c['nama'], 'kode' => $c['master_code'],
            'modul' => $c['module'], 'trx' => (int) $c['trx'], 'nilai' => (float) $c['amount'],
            'mulai' => substr((string) $c['start_date'], 0, 10), 'selesai' => substr((string) $c['end_date'], 0, 10),
            'metode' => $c['billing_method'], 'lintas_bulan' => $silang, 'ada_bulan_depan' => $adaDepan];
    }
}

printf("  %-10s %-10s %18s %18s %18s\n", 'PIC (xlsx)', 'di CLARA', 'CLARA', 'SPREADSHEET', 'SELISIH');
echo '  ' . str_repeat('-', 100) . "\n";
foreach ($ringkas as [$a, $b, $cl, $tg, $sel]) {
    printf("  %-10s %-10s %18s %18s %18s\n", mb_substr($a, 0, 10), mb_substr($b, 0, 10),
        $rp($cl), $rp($tg), ($sel == 0 ? '—' : ($sel > 0 ? '+' : '-') . $rp(abs($sel))));
}
echo "\n";

foreach ($keluar as $ssPic => $d) {
    if (!$d['kurang'] && !$d['lebih'] && !$d['ragu'] && !$d['beda']) {
        printf("  %s — %d baris cocok, tidak ada selisih baris.\n\n", $ssPic, $d['cocok']);
        continue;
    }
    printf("  %s (%s) — %d cocok · %d nilainya beda · %d perlu dipastikan · %d belum ada · %d di CLARA saja\n",
        $ssPic, $d['pic_clara'], $d['cocok'], count($d['beda']), count($d['ragu']),
        count($d['kurang']), count($d['lebih']));
    foreach ($d['beda'] as $b) {
        printf("      NILAI BEDA  %-28s %-9s xlsx %14s  CLARA %14s  #%s\n",
            mb_substr($b['nama'], 0, 28), $b['kode'], $rp($b['nilai_ss']), $rp($b['nilai_clara']), $b['trx']);
        printf("                  %s s/d %s · %s · %s · nilai kontrak %s\n", $b['mulai'], $b['selesai'],
            $b['harga'], $b['metode'] === 'spread' ? 'Recurring' : 'Sekaligus', $rp($b['nilai_kontrak']));
        printf("                  %s%s\n",
            $b['sebab'] === 'pengakuan'
                ? 'NILAI KONTRAKNYA SUDAH BENAR — yang salah bulan pengakuannya'
                : 'NILAI KONTRAKNYA BERBEDA dari spreadsheet',
            $b['kelipatan'] ? ' · CLARA tepat 1/' . $b['kelipatan'] . ' dari spreadsheet' : '');
    }
    foreach ($d['kurang'] as $k) {
        printf("      BELUM ADA   %-46s %16s  %s\n", mb_substr($k['ket'], 0, 46), $rp($k['nilai']),
            $k['kode']
                ? $k['modul'] . ' ' . $k['kode'] . ($k['unit_pasti'] ? '' : '  (dugaan — pastikan)')
                : '(unit tidak dikenali)');
    }
    foreach ($d['lebih'] as $c) {
        $ket = $c['ada_bulan_depan'] === true ? 'lintas bulan, ada di bulan depan — biarkan'
             : ($c['lintas_bulan'] ? 'lintas bulan, bulan depan pun tidak ada — PERIKSA' : 'PERIKSA');
        printf("      DI CLARA    %-28s %-10s %16s  #%-5s %s\n", mb_substr($c['nama'], 0, 28),
            $c['kode'], $rp($c['nilai']), $c['trx'], $ket);
    }
    foreach ($d['ragu'] as $r) {
        printf("      NAMA BEDA   %-28s <-> %-24s %12s #%s\n", mb_substr($r['ket'], 0, 28),
            mb_substr($r['nama'], 0, 24), $rp($r['nilai']), $r['trx']);
    }
    echo "\n";
}

if ($opt['json'] !== '') {
    file_put_contents((string) $opt['json'], json_encode($keluar, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "  Rinciannya disimpan: {$opt['json']}\n\n";
}
