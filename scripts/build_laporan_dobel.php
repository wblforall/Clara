<?php
/**
 * Laporan transaksi kembar — satu kesepakatan yang tercatat lebih dari sekali.
 *
 * Yang dicari: baris transaksi yang properti, modul, unit, client, tanggal
 * mulai, dan tanggal selesainya sama persis. Satu unit tidak bisa disewa oleh
 * client yang sama, untuk tanggal yang sama, dua kali — jadi setiap kelompok
 * seperti ini selalu berarti sesuatu, tetapi TIDAK selalu berarti hal yang
 * sama. Karena itu hasilnya dipisah dua:
 *
 *   KEMBAR PASTI   PIC-nya sama DAN nilainya sama. Tidak ada tafsir lain yang
 *                  masuk akal selain satu kesepakatan yang terinput dua kali.
 *                  Di sini laporan berani menyebut baris mana yang dipakai.
 *
 *   PERLU DICEK    PIC atau nilainya berbeda. Hampir semuanya di GF-001 dan
 *                  SF-005 — ruang atrium yang satu acaranya dijual beberapa
 *                  PIC bersama, lalu dicatat sebagai beberapa baris supaya
 *                  masing-masing kebagian achievement. Kalau begitu, baris-
 *                  barisnya BENAR dan tidak boleh dihapus; yang keliru cuma
 *                  caranya (seharusnya satu baris + Pembagian PIC). Laporan
 *                  ini tidak menebak — ia menaruh angkanya berdampingan dan
 *                  meminta PIC-nya yang memastikan ke spreadsheet.
 *
 * Laporan ini TIDAK mengubah apa pun. Ia hanya membaca.
 *
 * Pakai:
 *   php scripts/build_laporan_dobel.php
 *   php scripts/build_laporan_dobel.php --keluar=/path/folder
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';
require_once $root . '/app/pdf.php';

$opt = array_merge(['keluar' => $root . '/docs'], getopt('', ['keluar:']));
date_default_timezone_set((string) (env_value('APP_TIMEZONE') ?: 'Asia/Makassar'));

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$h   = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$rp  = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
$tgl = function (?string $d): string {
    $t = $d ? strtotime($d) : false;
    return $t ? date('d/m/Y', $t) : '-';
};
$MENU = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];

// ── Ambil semua anggota dari setiap kelompok kembar ──────────────────────────
// Dikelompokkan di SQL, lalu seluruh anggotanya diambil utuh — supaya laporan
// bisa menyebut keadaan tiap baris satu per satu, bukan cuma jumlahnya.
$sql = "
SELECT t.id, t.property_id, t.module, t.master_code, t.client_id,
       t.start_date, t.end_date, t.pic_name, t.created_by, t.created_at,
       t.billing_method, t.skp_id, t.bundle_id,
       COALESCE(t.final_amount, t.total_calculated) nilai,
       COALESCE(c.company_name, c.brand_name, '(tanpa client)') client_name,
       p.name prop_name,
       (SELECT COUNT(*) FROM transaction_allocations a WHERE a.transaction_id = t.id) alok_n,
       (SELECT COALESCE(SUM(a.amount),0) FROM transaction_allocations a WHERE a.transaction_id = t.id) alok_rp,
       (SELECT GROUP_CONCAT(CONCAT(a.period_key,':',ROUND(a.amount)) ORDER BY a.period_key SEPARATOR ',')
          FROM transaction_allocations a WHERE a.transaction_id = t.id) alok_rinci,
       (SELECT CONCAT(COALESCE(s.skp_no,''), '|', s.status, '|', COALESCE(s.doc_type,'skp'), '|', s.id)
          FROM skp_documents s
         WHERE s.property_id = t.property_id AND (s.transaction_id = t.id OR s.id = t.skp_id)
         ORDER BY s.id DESC LIMIT 1) dok
  FROM transactions t
  LEFT JOIN master_clients c ON c.id = t.client_id
  LEFT JOIN properties p ON p.id = t.property_id
  JOIN (SELECT property_id, module, master_code, client_id, start_date, end_date
          FROM transactions
         WHERE deleted_at IS NULL AND COALESCE(status,'') <> 'cancelled'
         GROUP BY 1,2,3,4,5,6 HAVING COUNT(*) > 1) g
    ON g.property_id = t.property_id AND g.module = t.module AND g.master_code = t.master_code
   AND g.client_id <=> t.client_id AND g.start_date = t.start_date AND g.end_date = t.end_date
 WHERE t.deleted_at IS NULL AND COALESCE(t.status,'') <> 'cancelled'
 ORDER BY t.property_id, t.master_code, t.start_date, t.id";

$grup = [];
foreach ($pdo->query($sql) as $r) {
    $kunci = $r['property_id'] . '|' . $r['module'] . '|' . $r['master_code'] . '|'
           . ($r['client_id'] ?? 'N') . '|' . $r['start_date'] . '|' . $r['end_date'];
    $grup[$kunci][] = $r;
}

/**
 * Pecah kolom dok hasil GROUP_CONCAT jadi keadaan dokumen yang bisa dibaca
 * orang. Dokumen yang nomornya belum terbit disebut apa adanya — "belum
 * terbit" bukan "tidak ada", karena keduanya artinya berbeda bagi PIC.
 */
function dok_baca(?string $raw): array
{
    if (!$raw) return ['ada' => false, 'label' => 'Tanpa dokumen', 'status' => '', 'no' => '', 'id' => 0];
    [$no, $status, $jenis, $sid] = array_pad(explode('|', $raw), 4, '');
    $nama = ['skp' => 'SKP', 'sks' => 'SKS', 'fu' => 'FU'][$jenis] ?? strtoupper($jenis);
    $kata = [
        'draft'     => 'masih draft (belum diajukan)',
        'submitted' => 'menunggu persetujuan',
        'approved'  => 'disetujui, belum diteken client',
        'signed'    => 'sudah ditandatangani client',
        'rejected'  => 'ditolak',
    ][$status] ?? $status;
    $label = $no !== '' ? "$nama $no — $kata" : "$nama belum terbit nomornya — $kata";
    return ['ada' => true, 'label' => $label, 'status' => $status, 'no' => $no, 'id' => (int) $sid];
}

/**
 * Urutan nomor dokumen (angka terakhir pada SKP/EW/2026/059 → 59). Dipakai
 * saat dua-duanya sudah diteken client: yang dipertahankan adalah nomor yang
 * terbit LEBIH DULU, karena itulah yang lebih mungkin sudah dipegang client
 * dan dirujuk invoice.
 */
function daftar_id(array $ids): string
{
    $ids = array_map(fn($i) => '#' . $i, $ids);
    if (count($ids) <= 2) return implode(' dan ', $ids);
    $akhir = array_pop($ids);
    return implode(', ', $ids) . ', dan ' . $akhir;
}

function no_urut(string $no): int
{
    return preg_match('#/(\d+)$#', $no, $m) ? (int) $m[1] : PHP_INT_MAX;
}

/**
 * Tentukan baris mana yang dipakai, berikut ALASANNYA. Alasan ikut dikembalikan
 * supaya laporan tidak pernah menyuruh tanpa menjelaskan — pengalaman panduan
 * bulanan: perintah tanpa alasan dibaca sebagai tuduhan, lalu diabaikan.
 */
function pilih_dipakai(array $anggota): array
{
    $diteken = array_values(array_filter($anggota, fn($a) => dok_baca($a['dok'])['status'] === 'signed'));
    if (count($diteken) === 1) {
        return [$diteken[0], 'Baris ini yang dokumennya sudah ditandatangani client. '
            . 'Dokumen bertanda tangan tidak boleh dilepas dari transaksinya.'];
    }
    if (count($diteken) > 1) {
        usort($diteken, fn($a, $b) => no_urut(dok_baca($a['dok'])['no']) <=> no_urut(dok_baca($b['dok'])['no']));
        return [$diteken[0], 'Dua-duanya sudah ditandatangani client. Yang dipertahankan adalah '
            . 'nomor yang terbit lebih dulu (' . dok_baca($diteken[0]['dok'])['no'] . '), karena nomor itulah '
            . 'yang lebih mungkin sudah dipegang client dan dirujuk invoice.'];
    }
    $disetujui = array_values(array_filter($anggota, fn($a) => dok_baca($a['dok'])['status'] === 'approved'));
    if ($disetujui) {
        return [$disetujui[0], 'Tidak ada yang sudah diteken client. Baris ini yang dokumennya '
            . 'sudah disetujui Manager dan nomornya sudah terbit.'];
    }
    $beralokasi = array_values(array_filter($anggota, fn($a) => (int) $a['alok_n'] > 0));
    if (count($beralokasi) === 1) {
        return [$beralokasi[0], 'Tidak ada yang punya dokumen terbit. Baris ini satu-satunya yang '
            . 'nilainya sudah masuk laporan, jadi dialah yang dipakai supaya angka laporan tidak berubah.'];
    }
    $adaAlokasi = (bool) array_filter($anggota, fn($a) => (int) $a['alok_n'] > 0);
    return [$anggota[0], 'Tidak ada dokumen terbit pada satu pun barisnya. Yang dipertahankan adalah '
        . 'baris yang dibuat lebih dulu (#' . $anggota[0]['id'] . ')'
        . ($adaAlokasi
            ? ', karena baris itu yang sudah lebih lama dirujuk laporan bulan-bulan sebelumnya.'
            : '. Belum ada nilainya yang masuk laporan, jadi dibuang yang mana pun angkanya tidak berubah — '
              . 'yang dibuat lebih dulu dipilih supaya pilihannya tidak acak.')];
}

// ── Pilah: kembar pasti vs perlu dicek ───────────────────────────────────────
$pasti = []; $cek = [];
foreach ($grup as $kunci => $ang) {
    $pics   = array_unique(array_map(fn($a) => trim((string) $a['pic_name']), $ang));
    $nilais = array_unique(array_map(fn($a) => (int) round((float) $a['nilai']), $ang));
    if (count($pics) === 1 && count($nilais) === 1) $pasti[$kunci] = $ang;
    else                                           $cek[$kunci]   = $ang;
}

// Urut dari dampak rupiah terbesar — yang paling menggeser angka dibaca dulu.
$dampak = function (array $ang): float {
    $ber = array_filter($ang, fn($a) => (float) $a['alok_rp'] > 0);
    if (count($ber) < 2) return 0.0;
    $tot = array_sum(array_map(fn($a) => (float) $a['alok_rp'], $ber));
    return $tot - max(array_map(fn($a) => (float) $a['alok_rp'], $ber));
};
uasort($pasti, fn($a, $b) => $dampak($b) <=> $dampak($a));
uasort($cek,   fn($a, $b) => array_sum(array_column($b, 'nilai')) <=> array_sum(array_column($a, 'nilai')));

// ── Dokumen yang MENUNGGU persetujuan padahal sumbernya sudah punya transaksi ─
// Kembar yang belum terjadi: begitu disetujui, barisnya lahir. Sejak perbaikan
// 23 Oktober 2026 persetujuannya ditahan sistem, tetapi dokumennya tetap perlu
// diselesaikan orang — ditolak atau transaksinya yang dihapus.
$dipintu = $pdo->query("
SELECT s.id skp_id, COALESCE(s.skp_no,'(belum terbit)') skp_no, s.status, s.property_id,
       o.master_code, o.start_date, o.end_date,
       COALESCE(c.company_name, c.brand_name, '-') client_name,
       t.id trx_id, t.pic_name, ROUND(COALESCE(t.final_amount,t.total_calculated)) nilai,
       p.name prop_name
  FROM skp_documents s
  JOIN offers o ON o.id = s.offer_id
  JOIN transactions t ON t.property_id = s.property_id AND t.module = o.module
   AND t.master_code = o.master_code AND t.client_id <=> o.client_id
   AND t.start_date = o.start_date AND t.end_date = o.end_date
   AND t.deleted_at IS NULL AND COALESCE(t.status,'') <> 'cancelled'
  LEFT JOIN master_clients c ON c.id = o.client_id
  LEFT JOIN properties p ON p.id = s.property_id
 WHERE s.status IN ('submitted','draft') AND s.transaction_id IS NULL
 ORDER BY s.id")->fetchAll();

// ── Rekap dampak per bulan ───────────────────────────────────────────────────
// Yang dihitung: alokasi milik baris yang disarankan DIBUANG. Itulah rupiah
// yang sekarang menumpang di laporan tanpa kesepakatan di belakangnya.
$perBulan = [];
$totalDobel = 0.0;
foreach ($pasti as $ang) {
    [$pakai] = pilih_dipakai($ang);
    foreach ($ang as $a) {
        if ((int) $a['id'] === (int) $pakai['id'] || (float) $a['alok_rp'] <= 0) continue;
        $totalDobel += (float) $a['alok_rp'];
        foreach (explode(',', (string) $a['alok_rinci']) as $potong) {
            if ($potong === '') continue;
            [$bln, $rpAlok] = array_pad(explode(':', $potong), 2, '0');
            $perBulan[$bln] = ($perBulan[$bln] ?? 0) + (float) $rpAlok;
        }
    }
}
ksort($perBulan);

// ── Cetak ────────────────────────────────────────────────────────────────────
$CSS = '<style>
 body { font-family: sans-serif; font-size: 9.5pt; color:#1F2937; line-height:1.5; }
 h1 { font-size:17pt; margin:0 0 1mm; }
 h2 { font-size:11.5pt; color:#0A7267; margin:7mm 0 2mm; padding-bottom:1.2mm;
      border-bottom:1pt solid #0D9488; page-break-after:avoid; }
 p { margin:0 0 2.4mm; }
 .sub { color:#6B7280; font-size:8.8pt; margin-bottom:4mm; }
 table { width:100%; border-collapse:collapse; margin-bottom:3mm; }
 tr { page-break-inside:avoid; }
 th,td { border:0.4pt solid #D1D5DB; padding:1.5mm 2mm; vertical-align:top; font-size:8.6pt; }
 th { background:#F0FDF4; color:#0A7267; font-size:7.8pt; text-transform:uppercase; letter-spacing:.03em; }
 td.n, th.n { text-align:right; white-space:nowrap; }
 .muted { color:#6B7280; }
 .item { page-break-inside:avoid; border:0.6pt solid #D1D5DB; border-left:3pt solid #B91C1C;
         background:#FEF2F2; padding:3mm 3.4mm; margin-bottom:3.5mm; }
 .item.cek { border-left-color:#4338CA; background:#EEF2FF; }
 .item.pintu { border-left-color:#B45309; background:#FFFBEB; }
 .judul { font-weight:bold; font-size:10pt; margin-bottom:2mm; }
 .kv { width:100%; border-collapse:collapse; margin:0 0 2mm; }
 .kv td { border:none; padding:0.6mm 0; font-size:8.6pt; vertical-align:top; }
 .kv td.k { width:24mm; color:#6B7280; }
 .pakai { background:#ECFDF5; }
 .buang { background:#FEE2E2; }
 ol { margin:0 0 0 5mm; padding:0; } li { margin-bottom:1.2mm; font-size:8.7pt; }
 .ok { color:#047857; } .kurang { color:#B91C1C; }
</style>';

ob_start();
$dibuat = date('d/m/Y H:i');
?>
<h1>Transaksi Kembar di CLARA</h1>
<p class="sub">Satu kesepakatan yang tercatat lebih dari sekali &mdash; dibuat <?= $h($dibuat) ?> ·
   dibaca langsung dari basis data · <b>laporan ini tidak mengubah apa pun</b></p>

<p>Dokumen ini mendaftar kelompok transaksi yang <b>properti, unit, client, tanggal mulai, dan
   tanggal selesainya sama persis</b>. Satu unit tidak bisa disewa oleh client yang sama untuk
   tanggal yang sama dua kali, jadi setiap kelompok di bawah selalu berarti sesuatu &mdash; tetapi
   tidak selalu berarti hal yang sama. Karena itu isinya dipisah dua bagian, dan hanya bagian
   pertama yang berani menyebut baris mana yang dipakai.</p>

<table>
    <tr><th>Bagian</th><th class="n">Kelompok</th><th class="n">Rupiah</th><th>Artinya</th></tr>
    <tr><td><b>1. Kembar pasti</b></td><td class="n"><?= count($pasti) ?></td>
        <td class="n"><?= $rp($totalDobel) ?></td>
        <td>PIC dan nilainya sama. Nilai di kolom sebelah sudah ikut terhitung di laporan
            tanpa kesepakatan di belakangnya.</td></tr>
    <tr><td><b>2. Perlu dicek</b></td><td class="n"><?= count($cek) ?></td>
        <td class="n"><?= $rp(array_sum(array_map(fn($g) => array_sum(array_column($g, 'nilai')), $cek))) ?>
            <br><span class="muted" style="font-size:7.6pt">jumlah seluruh baris,<br>bukan kelebihan</span></td>
        <td>PIC atau nilainya berbeda. Besar kemungkinan satu acara yang dijual beberapa PIC
            bersama &mdash; <b>jangan dihapus sebelum dipastikan</b>.</td></tr>
    <?php if ($dipintu): ?>
    <tr><td><b>3. Menunggu di pintu</b></td><td class="n"><?= count($dipintu) ?></td>
        <td class="n"><?= $rp(array_sum(array_column($dipintu, 'nilai'))) ?></td>
        <td>Dokumen yang belum disetujui, padahal kesepakatannya sudah punya transaksi.</td></tr>
    <?php endif; ?>
</table>

<?php if ($perBulan): ?>
<h2>Bulan mana yang angkanya terpengaruh</h2>
<p>Rupiah di bawah ini milik baris yang disarankan dibuang pada Bagian 1. Selama baris itu masih
   ada, angka bulan tersebut di Dashboard, Laporan PIC, dan Achievement lebih tinggi dari
   kesepakatan yang sebenarnya ada.</p>
<table>
    <tr><th>Periode</th><th class="n">Kelebihan</th></tr>
    <?php foreach ($perBulan as $bln => $rpB): if ($rpB <= 0) continue; ?>
    <tr><td><?= $h($bln) ?></td><td class="n"><?= $rp($rpB) ?></td></tr>
    <?php endforeach; ?>
    <tr style="background:#FEE2E2;font-weight:bold"><td>JUMLAH</td><td class="n"><?= $rp($totalDobel) ?></td></tr>
</table>
<?php endif; ?>

<h2>Bagian 1 &mdash; Kembar pasti (<?= count($pasti) ?> kelompok)</h2>
<p>Pada kelompok di bawah, seluruh barisnya punya <b>PIC yang sama dan nilai yang sama</b>.
   Tidak ada tafsir lain selain satu kesepakatan yang terinput lebih dari sekali.</p>

<?php $no = 0; foreach ($pasti as $ang):
    $no++;
    [$pakai, $alasan] = pilih_dipakai($ang);
    $s = $ang[0];
    $lebih = 0.0;
    foreach ($ang as $a) if ((int) $a['id'] !== (int) $pakai['id']) $lebih += (float) $a['alok_rp'];
?>
<div class="item">
    <div class="judul"><?= $no ?>. <?= $h($s['client_name']) ?> &mdash; unit <?= $h($s['master_code']) ?>
        (<?= $h($MENU[$s['module']] ?? $s['module']) ?>)</div>
    <table class="kv">
        <tr><td class="k">Properti</td><td><?= $h($s['prop_name']) ?></td></tr>
        <tr><td class="k">Periode</td><td><?= $h($tgl($s['start_date'])) ?> s/d <?= $h($tgl($s['end_date'])) ?></td></tr>
        <tr><td class="k">PIC</td><td><?= $h($s['pic_name'] ?: '-') ?></td></tr>
        <tr><td class="k">Nilai kontrak</td><td><?= $rp($s['nilai']) ?> &mdash; tercatat <?= count($ang) ?> kali</td></tr>
    </table>
    <table>
        <tr><th>Transaksi</th><th>Dibuat</th><th>Keadaan dokumen</th><th class="n">Masuk laporan</th><th>Status</th></tr>
        <?php foreach ($ang as $a): $d = dok_baca($a['dok']); $ini = (int) $a['id'] === (int) $pakai['id']; ?>
        <tr class="<?= $ini ? 'pakai' : 'buang' ?>">
            <td><b>#<?= (int) $a['id'] ?></b></td>
            <td><?= $h($a['created_by'] ?: '-') ?><br><span class="muted"><?= $h($tgl(substr((string) $a['created_at'], 0, 10))) ?></span></td>
            <td><?= $h($d['label']) ?></td>
            <td class="n"><?= (int) $a['alok_n'] > 0 ? $rp($a['alok_rp']) . '<br><span class="muted">' . (int) $a['alok_n'] . ' bulan</span>' : '<span class="muted">belum</span>' ?></td>
            <td><?= $ini ? '<b class="ok">DIPAKAI</b>' : '<b class="kurang">DIBUANG</b>' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p><b>Kenapa #<?= (int) $pakai['id'] ?> yang dipakai.</b> <?= $h($alasan) ?></p>
    <p><b>Langkahnya.</b></p>
    <ol>
        <li>Buka <b>Transaksi &rarr; <?= $h($MENU[$s['module']] ?? $s['module']) ?></b>, cari unit
            <?= $h($s['master_code']) ?> periode <?= $h($tgl($s['start_date'])) ?>.</li>
        <li>Pastikan dulu isinya memang sama &mdash; nilai, tanggal, dan nama client
            pada <?= $h(daftar_id(array_map(fn($a) => (int) $a['id'], $ang))) ?>.</li>
        <?php foreach ($ang as $a): if ((int) $a['id'] === (int) $pakai['id']) continue;
            $dd = dok_baca($a['dok']); ?>
        <li>Ajukan hapus untuk <b>#<?= (int) $a['id'] ?></b> lewat <b>Pengajuan Hapus Data</b>,
            dengan alasan &ldquo;kembar dengan #<?= (int) $pakai['id'] ?>, satu kesepakatan tercatat dua kali&rdquo;.
            <?php if ($dd['ada'] && $dd['status'] === 'signed'): ?>
            <span class="kurang">Dokumen <?= $h($dd['no']) ?> pada baris ini sudah ditandatangani client &mdash;
            sampaikan dulu ke Manager sebelum mengajukan, karena nomornya mungkin sudah dipegang client.</span>
            <?php elseif ($dd['ada']): ?>
            Dokumen <?= $h($dd['no'] ?: 'pada baris ini') ?> ikut dibatalkan bersama transaksinya.
            <?php endif; ?></li>
        <?php endforeach; ?>
        <li>Sesudah disetujui penghapus, angka
            <?= $lebih > 0 ? 'bulan terkait turun ' . $rp($lebih) : 'tidak berubah (baris ini memang belum masuk laporan)' ?>.</li>
    </ol>
</div>
<?php endforeach; ?>

<h2>Bagian 2 &mdash; Perlu dicek dulu (<?= count($cek) ?> kelompok)</h2>
<p>Kelompok di bawah ini unit, client, dan periodenya sama, <b>tetapi PIC atau nilainya berbeda</b>.
   Hampir semuanya ada di <b>GF-001</b> dan <b>SF-005</b> &mdash; ruang atrium yang satu acaranya
   kerap dijual beberapa PIC bersama, lalu dicatat sebagai beberapa baris supaya masing-masing
   kebagian achievement.</p>
<p>Kalau memang begitu, <b>barisnya tidak salah dan tidak boleh dihapus</b> &mdash; jumlah seluruh
   barisnya adalah nilai acara yang sebenarnya. Yang perlu diubah hanya caranya ke depan: satu
   baris transaksi, lalu <b>Pembagian PIC</b> di dalamnya, supaya nilai acara tidak bergantung pada
   berapa baris yang dibuat.</p>
<p><b>Yang perlu dilakukan:</b> cocokkan jumlah tiap kelompok dengan baris acara itu di spreadsheet.
   Kalau jumlahnya sama &rarr; biarkan, cukup catat untuk dirapikan nanti. Kalau jumlahnya lebih
   besar dari spreadsheet &rarr; selisihnya adalah baris yang terinput berlebih, dan baru pada titik
   itu pengajuan hapus masuk akal.</p>

<?php $no = 0; foreach ($cek as $ang): $no++; $s = $ang[0];
    $jml = array_sum(array_map(fn($a) => (float) $a['nilai'], $ang)); ?>
<div class="item cek">
    <div class="judul"><?= $no ?>. <?= $h($s['client_name']) ?> &mdash; unit <?= $h($s['master_code']) ?>,
        <?= $h($tgl($s['start_date'])) ?> s/d <?= $h($tgl($s['end_date'])) ?></div>
    <table>
        <tr><th>Transaksi</th><th>PIC</th><th>Dibuat</th><th>Keadaan dokumen</th><th class="n">Nilai</th><th class="n">Masuk laporan</th></tr>
        <?php foreach ($ang as $a): $d = dok_baca($a['dok']); ?>
        <tr><td><b>#<?= (int) $a['id'] ?></b></td>
            <td><?= $h($a['pic_name'] ?: '-') ?></td>
            <td><?= $h($a['created_by'] ?: '-') ?><br><span class="muted"><?= $h($tgl(substr((string) $a['created_at'], 0, 10))) ?></span></td>
            <td><?= $h($d['label']) ?></td>
            <td class="n"><?= $rp($a['nilai']) ?></td>
            <td class="n"><?= (int) $a['alok_n'] > 0 ? $rp($a['alok_rp']) : '<span class="muted">belum</span>' ?></td></tr>
        <?php endforeach; ?>
        <tr style="background:#E0E7FF;font-weight:bold">
            <td colspan="4">Jumlah seluruh baris &mdash; bandingkan angka ini dengan spreadsheet</td>
            <td class="n"><?= $rp($jml) ?></td>
            <td class="n"><?= $rp(array_sum(array_map(fn($a) => (float) $a['alok_rp'], $ang))) ?></td></tr>
    </table>
    <?php
    $picUnik = array_unique(array_map(fn($a) => trim((string) $a['pic_name']), $ang));
    if (count($picUnik) === 1): ?>
    <p><b>Catatan.</b> Seluruh barisnya atas nama PIC yang sama (<?= $h(reset($picUnik)) ?>) tetapi
       nilainya berbeda. Ini bukan pola bagi-PIC &mdash; lebih mirip satu baris yang diinput ulang
       dengan nilai yang sudah dibetulkan, dan baris lamanya tertinggal. Periksa mana nilai yang
       benar, lalu ajukan hapus untuk yang tidak terpakai.</p>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php if ($dipintu): ?>
<h2>Bagian 3 &mdash; Menunggu di pintu</h2>
<p>Dokumen berikut <b>belum disetujui</b>, tetapi kesepakatannya sudah punya transaksi di CLARA.
   Sejak 23 Oktober 2026 persetujuannya ditahan sistem dengan penjelasan, jadi tidak akan lahir
   baris kedua secara diam-diam. Tetapi dokumennya tetap perlu diselesaikan orang.</p>
<?php foreach ($dipintu as $d): ?>
<div class="item pintu">
    <div class="judul"><?= $h($d['client_name']) ?> &mdash; unit <?= $h($d['master_code']) ?>,
        <?= $h($tgl($d['start_date'])) ?> s/d <?= $h($tgl($d['end_date'])) ?></div>
    <table class="kv">
        <tr><td class="k">Properti</td><td><?= $h($d['prop_name']) ?></td></tr>
        <tr><td class="k">Dokumen</td><td><?= $h($d['skp_no']) ?> (id <?= (int) $d['skp_id'] ?>) &mdash;
            <?= $h($d['status'] === 'submitted' ? 'menunggu persetujuan' : 'masih draft') ?></td></tr>
        <tr><td class="k">Sudah ada</td><td>Transaksi <b>#<?= (int) $d['trx_id'] ?></b> atas nama
            <?= $h($d['pic_name'] ?: '-') ?>, <?= $rp($d['nilai']) ?></td></tr>
    </table>
    <p><b>Pilih salah satu.</b> Kalau transaksi #<?= (int) $d['trx_id'] ?> yang benar &rarr; tolak
       dokumen ini, karena kesepakatannya sudah tercatat. Kalau dokumen ini yang benar &rarr; ajukan
       hapus transaksi #<?= (int) $d['trx_id'] ?> lebih dulu, baru dokumennya disetujui.</p>
</div>
<?php endforeach; ?>
<?php endif; ?>

<h2>Supaya tidak bertambah lagi</h2>
<p>Sejak 23 Oktober 2026 CLARA menolak di sisi server transaksi yang unit, client, dan periodenya
   sama persis dengan transaksi yang sudah ada &mdash; baik saat disimpan dari formulir, saat
   disunting, maupun saat dokumen SKP disetujui. Penolakannya menyebut nomor transaksi yang sudah
   ada beserta PIC-nya, dan menyarankan <b>Pembagian PIC</b> bila deal itu memang dikerjakan lebih
   dari satu orang.</p>
<p>Yang <b>tidak</b> ditolak: periode yang cuma bertindih sebagian &mdash; itu bisa sah, misalnya
   unit berganti penyewa di tengah bulan. Untuk itu peringatan overlap di formulir tetap berlaku
   seperti biasa.</p>
<?php
$isi = (string) ob_get_clean();
$berkas = rtrim((string) $opt['keluar'], '/') . '/Laporan_Transaksi_Kembar_' . date('Ymd') . '.pdf';
@mkdir(dirname($berkas), 0777, true);
$mpdf = clara_letterhead_mpdf();
$mpdf->SetTitle('Laporan Transaksi Kembar — CLARA');
$mpdf->WriteHTML($CSS . $isi);
$mpdf->Output($berkas, \Mpdf\Output\Destination::FILE);

printf("\nLAPORAN TRANSAKSI KEMBAR\n%s\n", str_repeat('=', 76));
printf("  Kembar pasti   : %2d kelompok, %s sudah ikut terhitung di laporan\n",
    count($pasti), 'Rp ' . number_format($totalDobel, 0, ',', '.'));
printf("  Perlu dicek    : %2d kelompok (GF-001/SF-005 — acara berbagi PIC)\n", count($cek));
printf("  Menunggu pintu : %2d dokumen\n", count($dipintu));
foreach ($perBulan as $bln => $rpB) printf("     %s  %s\n", $bln, 'Rp ' . number_format($rpB, 0, ',', '.'));
printf("%s\n  %s\n", str_repeat('=', 76), $berkas);
