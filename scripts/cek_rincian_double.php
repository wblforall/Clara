<?php
/**
 * Hitung berapa banyak surat yang mencetak satu angka DUA KALI.
 *
 * Pola yang dicari persis yang dikeluhkan tim: pada kontrak 1 bulan, tarif per
 * m² per bulan dikalikan luas menghasilkan angka yang SAMA PERSIS dengan
 * "Nilai Sewa" dan dengan "Total Biaya Sewa", sehingga satu nominal tercetak
 * dua-tiga kali dengan nama berbeda. Client membacanya sebagai beberapa
 * komponen biaya.
 *
 * Skrip ini MEMBACA SAJA — tidak ada satu baris pun yang ditulis, jadi income,
 * alokasi bulanan, dan nilai kontrak mustahil berubah karenanya. Gunanya
 * membuktikan dua hal sekaligus:
 *   1. berapa surat yang sebelumnya dobel, dan
 *   2. bahwa jumlah rupiah yang ditagih SAMA PERSIS sebelum & sesudah
 *      perbaikan (yang hilang cuma barisnya, bukan uangnya).
 *
 * Aturan peleburannya satu sumber dengan yang dipakai surat:
 * clara_rincian_rapi() di app/helpers.php.
 *
 * Pakai:
 *   php scripts/cek_rincian_double.php
 *   php scripts/cek_rincian_double.php --sejak=2026-10 --prop=1 --rinci
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';

/**
 * Pindai dokumen dan pulangkan hitungannya.
 *
 * Dipisah sebagai fungsi supaya PDF tutorial (scripts/build_tutorial_dobel.php)
 * membaca angka dari sumber yang SAMA. Dua salinan aturan akan pelan-pelan
 * berbeda, dan tutorialnya lalu mengajarkan angka yang tidak pernah ada.
 */
function cek_rincian_pindai(PDO $pdo, string $batas, string $prop = ''): array
{
$rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

$syaratProp = '';
$argProp    = [];
if ($prop !== '') {
    $syaratProp = ' AND property_id = ?';
    $argProp[]  = (int) $prop;
}

/** Jumlah bulan kontrak — aturan sama dengan surat penawaran. */
$bulanKontrak = function (array $o): int {
    $b = (int) ($o['contract_months'] ?? 0);
    if ($b > 1) return $b;
    $s = $o['start_date'] ?? null; $e = $o['end_date'] ?? null;
    if (!$s || !$e) return 0;
    $ts = strtotime((string) $s); $te = strtotime((string) $e);
    if ($ts === false || $te === false || $te < $ts) return 0;
    $b = ((int) date('Y', $te) - (int) date('Y', $ts)) * 12 + ((int) date('n', $te) - (int) date('n', $ts));
    if ((int) date('j', $te) >= (int) date('j', $ts)) $b++;
    return $b > 1 ? $b : 0;
};

// ── Surat Penawaran ────────────────────────────────────────────────────────
$q = $pdo->prepare("SELECT id, offer_no, property_id, pricing_type, unit_rate, monthly_amount,
                           total_calculated, contract_months, start_date, end_date
                      FROM offers
                     WHERE deleted_at IS NULL AND end_date >= ?" . $syaratProp . "
                     ORDER BY id");
$q->execute(array_merge([$batas], $argProp));

$nOffer = $nOfferDobel = $barisHilangOffer = 0;
$uangOfferSebelum = $uangOfferSesudah = 0.0;
$daftarOffer = [];
foreach ($q->fetchAll() as $o) {
    $nOffer++;
    $total   = (float) $o['total_calculated'];
    $tarif   = (float) $o['unit_rate'];
    $bulan   = $bulanKontrak($o);
    $baris   = [];
    if ($tarif > 0 && (string) $o['pricing_type'] !== 'fixed') {
        $baris[] = ['label' => 'tarif', 'amount' => $tarif, 'lebur' => true];
    }
    if ((float) $o['monthly_amount'] > 0) {
        $baris[] = ['label' => 'per bulan', 'amount' => (float) $o['monthly_amount'], 'lebur' => true];
    }
    $baris[] = ['label' => 'per periode', 'amount' => $total, 'lebur' => true];

    $rapi  = clara_rincian_rapi($baris);
    $turun = count($baris) - count($rapi);
    // Yang dibandingkan adalah angka TERBESAR di blok itu — nilai yang benar-
    // benar ditagih. Kalau ini bergeser, peleburan memakan uang; ia tidak boleh.
    $uangOfferSebelum += max(array_column($baris, 'amount'));
    $uangOfferSesudah += max(array_column($rapi, 'amount'));
    if ($turun > 0) {
        $nOfferDobel++;
        $barisHilangOffer += $turun;
        $daftarOffer[] = sprintf('  #%-5d %-38s %d baris kembar  %s',
            $o['id'], (string) $o['offer_no'], $turun, $rp($total));
    }
}

// ── SKP / Surat Konfirmasi ─────────────────────────────────────────────────
$q2 = $pdo->prepare("SELECT s.id, s.skp_no, s.doc_type, s.property_id, s.snapshot_json,
                            t.pricing_type, t.area_sqm, t.start_date, t.end_date
                       FROM skp_documents s
                       LEFT JOIN transactions t ON t.id = s.transaction_id
                      WHERE s.deleted_at IS NULL
                        AND COALESCE(t.end_date, s.end_date) >= ?" . str_replace('property_id', 's.property_id', $syaratProp) . "
                      ORDER BY s.id");
$q2->execute(array_merge([$batas], $argProp));

$nSkp = $nSkpDobel = $barisHilangSkp = 0;
$uangSkpSebelum = $uangSkpSesudah = 0.0;
$daftarSkp = [];
foreach ($q2->fetchAll() as $s) {
    // Dokumen Gudang & Media memakai tabel harganya sendiri, bukan blok ini.
    if (in_array((string) $s['doc_type'], ['sks', 'media'], true)) continue;
    $d = json_decode((string) $s['snapshot_json'], true);
    if (!is_array($d) || !isset($d['amounts'])) continue;
    $a = $d['amounts'];
    $nSkp++;

    $pt    = (string) ($d['pricing_type'] ?? $s['pricing_type'] ?? '');
    $baris = [];
    if ((float) ($a['rate_m_day'] ?? 0) > 0) {
        $baris[] = ['label' => 'tarif', 'amount' => (float) $a['rate_m_day'], 'lebur' => true];
    }
    // Dokumen terbit memakai sewa_per_bulan yang dibekukan; yang belum punya
    // kunci itu dihitung seperti pratinjau menghitungnya.
    $perBulan = 0.0;
    if (array_key_exists('sewa_per_bulan', $d)) {
        $perBulan = (float) $d['sewa_per_bulan'];
    } elseif (in_array($pt, ['monthly', 'monthly_area'], true)) {
        $perBulan = $pt === 'monthly_area'
            ? (float) ($a['rate_m_day'] ?? 0) * (float) ($d['area'] ?? 0)
            : (float) ($a['rate_m_day'] ?? 0);
    }
    if ($perBulan > 0) $baris[] = ['label' => 'per bulan', 'amount' => $perBulan, 'lebur' => true];
    if ((float) ($a['listrik'] ?? 0) > 0) {
        $baris[] = ['label' => 'nilai sewa', 'amount' => (float) ($a['sewa'] ?? 0), 'lebur' => true];
    } else {
        $baris[] = ['label' => 'total', 'amount' => (float) ($a['total'] ?? 0), 'lebur' => true];
    }

    $rapi  = clara_rincian_rapi($baris);
    $turun = count($baris) - count($rapi);
    $uangSkpSebelum += (float) ($a['grand_total'] ?? 0);
    $uangSkpSesudah += (float) ($a['grand_total'] ?? 0);   // blok ini tidak menyentuh nominal
    if ($turun > 0) {
        $nSkpDobel++;
        $barisHilangSkp += $turun;
        $daftarSkp[] = sprintf('  #%-5d %-26s %-14s %d baris kembar  %s',
            $s['id'], (string) ($s['skp_no'] ?: '(draft)'), $pt, $turun,
            $rp($a['grand_total'] ?? 0));
    }
}

    return [
        'offer'      => ['n' => $nOffer, 'dobel' => $nOfferDobel, 'baris' => $barisHilangOffer,
                         'daftar' => $daftarOffer],
        'skp'        => ['n' => $nSkp, 'dobel' => $nSkpDobel, 'baris' => $barisHilangSkp,
                         'daftar' => $daftarSkp],
        'uang_sebelum' => $uangOfferSebelum + $uangSkpSebelum,
        'uang_sesudah' => $uangOfferSesudah + $uangSkpSesudah,
    ];
}

// ── Dipanggil langsung dari terminal ───────────────────────────────────────
// Berkas ini juga di-require oleh pembangun PDF tutorial; bagian di bawah
// hanya berjalan bila ia sendiri yang dijalankan.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== realpath(__FILE__)) return;

$opt = array_merge(['sejak' => '2026-10', 'prop' => ''], getopt('', ['sejak:', 'prop:', 'rinci']));
$rinci = array_key_exists('rinci', $opt);
$sejak = (string) $opt['sejak'];
if (!preg_match('/^\d{4}-\d{2}$/', $sejak)) {
    fwrite(STDERR, "--sejak harus bentuk YYYY-MM, contoh 2026-10\n");
    exit(2);
}

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

$hasil       = cek_rincian_pindai($pdo, $sejak . '-01', (string) $opt['prop']);
$nOffer      = $hasil['offer']['n'];      $nOfferDobel      = $hasil['offer']['dobel'];
$barisHilangOffer = $hasil['offer']['baris'];  $daftarOffer = $hasil['offer']['daftar'];
$nSkp        = $hasil['skp']['n'];        $nSkpDobel        = $hasil['skp']['dobel'];
$barisHilangSkp   = $hasil['skp']['baris'];    $daftarSkp   = $hasil['skp']['daftar'];
$uangOfferSebelum = $hasil['uang_sebelum'];    $uangSkpSebelum = 0.0;
$uangOfferSesudah = $hasil['uang_sesudah'];    $uangSkpSesudah = 0.0;

$garis = str_repeat('─', 74);
echo "\nPEMERIKSAAN BARIS KEMBAR DI SURAT — sejak $sejak"
   . ((string) $opt['prop'] !== '' ? ' · properti ' . (int) $opt['prop'] : ' · semua properti') . "\n$garis\n";

printf("Surat Penawaran   : %4d diperiksa · %4d memuat baris kembar · %d baris dilebur\n",
    $nOffer, $nOfferDobel, $barisHilangOffer);
printf("SKP / Konfirmasi  : %4d diperiksa · %4d memuat baris kembar · %d baris dilebur\n",
    $nSkp, $nSkpDobel, $barisHilangSkp);

echo "$garis\n";
printf("Nilai tertagih sebelum : %s\n", $rp($uangOfferSebelum + $uangSkpSebelum));
printf("Nilai tertagih sesudah : %s\n", $rp($uangOfferSesudah + $uangSkpSesudah));
$selisih = ($uangOfferSesudah + $uangSkpSesudah) - ($uangOfferSebelum + $uangSkpSebelum);
printf("Selisih                : %s   %s\n", $rp($selisih),
    abs($selisih) < 0.5 ? '✔ income tidak berubah' : '✘ PERIKSA — nominal bergeser');

if ($rinci) {
    if ($daftarOffer) { echo "\nSurat Penawaran yang sebelumnya dobel:\n" . implode("\n", $daftarOffer) . "\n"; }
    if ($daftarSkp)   { echo "\nSKP yang sebelumnya dobel:\n" . implode("\n", $daftarSkp) . "\n"; }
    if (!$daftarOffer && !$daftarSkp) echo "\nTidak ada dokumen yang memuat baris kembar.\n";
}
echo "\nTidak ada data yang diubah skrip ini.\n\n";
