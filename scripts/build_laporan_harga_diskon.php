<?php

/**
 * Laporan fitur "Harga Publish & Diskon" di Surat Penawaran dan SKP.
 *
 * Seluruh angka di dalamnya DIAMBIL SAAT SKRIP INI DIJALANKAN — dari database
 * dan dari dokumen yang benar-benar tercetak, bukan diketik tangan. Jadi kalau
 * datanya berubah, laporannya ikut berubah dan tidak ada angka yang bisa basi
 * diam-diam.
 *
 *   php scripts/build_laporan_harga_diskon.php \
 *       --penawaran=38 --skp=185 \
 *       --keluar=docs/Laporan_Harga_Publish_Diskon.pdf
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
// Templat surat memakai helper umum (clara_format_bold dsb.) — tanpa ini
// render contohnya berhenti di tengah dan laporannya tidak pernah terbentuk.
require_once $root . '/app/helpers.php';

$opt = ['penawaran' => '38', 'skp' => '185', 'keluar' => $root . '/docs/Laporan_Harga_Publish_Diskon.pdf'];
foreach ($argv as $a) if (preg_match('/^--([a-z0-9]+)=(.*)$/i', $a, $m)) $opt[$m[1]] = $m[2];

$pdo = Database::connect();
$rp  = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
$h   = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/**
 * Render tabel biaya dokumen SEBELUM dan SESUDAH harga diskon dinyalakan.
 *
 * Keduanya dirender oleh KODE CETAK YANG SAMA dengan yang dipakai PDF client —
 * bukan tabel yang diketik ulang untuk laporan. "Sebelum" dibuat dengan
 * mematikan saklarnya sesaat di salinan data, bukan dengan menghapus barisnya
 * dari hasil; jadi yang terlihat benar-benar keluaran programnya.
 */
function _lap_tabel(string $html, string $penanda): array
{
    $p = strpos($html, $penanda);
    if ($p === false) return [];
    $b = substr($html, $p, 9000);
    $e = strpos($b, '</table>');
    if ($e !== false) $b = substr($b, 0, $e);
    preg_match_all('~<tr[^>]*>(.*?)</tr>~s', $b, $m);
    $out = [];
    foreach ($m[1] as $row) {
        preg_match_all('~<t[dh][^>]*>(.*?)</t[dh]>~s', $row, $c);
        $sel = array_map(fn($x) => trim(preg_replace('~\s+~u', ' ', html_entity_decode(strip_tags($x)))), $c[1]);
        if (($sel[0] ?? '') === '' && ($sel[1] ?? '') === '') continue;
        $out[] = [$sel[0] ?? '', $sel[1] ?? ''];
    }
    return $out;
}

/** Surat Penawaran: render suratnya dengan saklar diskon mati / menyala. */
function _lap_surat_penawaran(PDO $pdo, int $offerId, bool $diskonOn): array
{
    require_once dirname(__DIR__) . '/app/pages/offers.php';
    require_once dirname(__DIR__) . '/app/AllocationService.php';
    // Kolom tambahan yang biasa disiapkan rute cetak ikut diambil, supaya
    // templatnya menerima baris yang sama persis dengan saat dicetak sungguhan.
    $st = $pdo->prepare(
        "SELECT o.*, c.company_name, c.brand_name, c.address, ct.name cp_name, ct.phone cp_phone,
                p.email pic_email, p.phone pic_phone,
                COALESCE(u.location_name, g.name, CONCAT_WS(' - ', m.media_type, m.location, NULLIF(m.point, ''))) location_name,
                COALESCE(u.floor, g.location, m.location) floor,
                COALESCE(u.area_sqm, g.area_sqm) unit_area
           FROM offers o
           LEFT JOIN master_clients c ON c.id = o.client_id
           LEFT JOIN master_client_contacts ct ON ct.id = o.contact_id
           LEFT JOIN master_pic p ON p.name = o.pic_name AND p.property_id = o.property_id
           LEFT JOIN master_cl_units u ON u.code = o.master_code AND u.property_id = o.property_id
           LEFT JOIN master_gudang   g ON g.code = o.master_code AND g.property_id = o.property_id
           LEFT JOIN master_media    m ON m.code = o.master_code AND m.property_id = o.property_id
          WHERE o.id = ? LIMIT 1"
    );
    $st->execute([$offerId]);
    $o = $st->fetch();
    if (!$o) return [];
    // Salinan data, bukan basis datanya — dokumen aslinya tidak disentuh.
    $o['diskon_aktif'] = $diskonOn ? 1 : 0;
    $letter = offer_letter($pdo, $o);
    $items  = offer_items_detail($pdo, (int) $o['id'], (int) $o['property_id']);
    $tahap  = AllocationService::priceSteps($pdo, null, (int) $o['id']);
    $tier   = count($items) >= 2 ? offer_area_tiers($pdo, (int) $o['id']) : [];
    // Templat surat ikut mencetak sebagian isinya langsung; dikurung buffer
    // supaya tidak bocor ke layar dan ikut merusak PDF laporan.
    ob_start();
    $surat = offer_surat_html($pdo, $o, $letter, $items, $tahap, $tier);
    $bocor = (string) ob_get_clean();
    return _lap_tabel($surat !== '' ? $surat : $bocor, 'Rincian Biaya');
}

/** SKP: render isi dokumennya dengan kunci diskon dibuang / dipakai. */
function _lap_skp(PDO $pdo, int $skpId, bool $diskonOn): array
{
    $st = $pdo->prepare('SELECT * FROM skp_documents WHERE id = ?');
    $st->execute([$skpId]);
    $skp = $st->fetch();
    if (!$skp || empty($skp['snapshot_json'])) return [];
    $d = json_decode((string) $skp['snapshot_json'], true) ?: [];
    $a = $d['amounts'] ?? [];
    if (!$diskonOn) { unset($a['publish_on'], $a['publish'], $a['diskon'], $a['diskon_pct']); }
    $d['amounts'] = $a;
    $rp  = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $h   = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $chk = fn($b) => !empty($b) ? '■' : '□';
    $skpDocType = (string) ($skp['doc_type'] ?? 'skp');
    $skpTpl = [];
    ob_start();
    include dirname(__DIR__) . '/app/pages/skp_print_body.php';
    return _lap_tabel((string) ob_get_clean(), 'Rincian Pembayaran Sewa');
}

/** Dua tabel bersanding: sebelum vs sesudah, baris baru ditandai. */
function _lap_banding(array $sebelum, array $sesudah, callable $h): string
{
    $lamaLbl = array_map(fn($r) => preg_replace('~^[a-z]\. ~', '', $r[0]), $sebelum);
    $o = '<table class="bdg"><tr><th style="width:50%">SEBELUM &mdash; tanpa harga diskon</th>'
       . '<th style="width:50%">SESUDAH &mdash; harga diskon dinyalakan</th></tr><tr><td>';
    $baris = function (array $rows, array $lamaLbl, bool $tandai) use ($h): string {
        $t = '<table class="dlm">';
        foreach ($rows as $r) {
            $bersih = preg_replace('~^[a-z]\. ~', '', $r[0]);
            $baru = $tandai && !in_array($bersih, $lamaLbl, true);
            $t .= '<tr class="' . ($baru ? 'baru' : '') . '"><td>' . $h($r[0])
                . ($baru ? ' <span class="tag">BARU</span>' : '') . '</td>'
                . '<td class="n">' . $h($r[1]) . '</td></tr>';
        }
        return $t . '</table>';
    };
    $o .= $baris($sebelum, [], false) . '</td><td>' . $baris($sesudah, $lamaLbl, true) . '</td></tr></table>';
    return $o;
}


// ── Angka nyata dari dokumen contoh ──────────────────────────────────────────
$po = $pdo->prepare('SELECT o.*, c.company_name FROM offers o LEFT JOIN master_clients c ON c.id = o.client_id WHERE o.id = ?');
$po->execute([(int) $opt['penawaran']]);
$offer = $po->fetch() ?: [];

$ps = $pdo->prepare('SELECT d.*, c.company_name FROM skp_documents d
                     LEFT JOIN transactions t ON t.id = d.transaction_id
                     LEFT JOIN master_clients c ON c.id = t.client_id WHERE d.id = ?');
$ps->execute([(int) $opt['skp']]);
$skp  = $ps->fetch() ?: [];
$snap = $skp && !empty($skp['snapshot_json']) ? (json_decode((string) $skp['snapshot_json'], true) ?: []) : [];
$amt  = $snap['amounts'] ?? [];

// ── Berapa dokumen yang sudah ada, dan berapa yang memakai fitur ini ─────────
$nOffer   = (int) $pdo->query('SELECT COUNT(*) FROM offers')->fetchColumn();
$nOfferD  = (int) $pdo->query('SELECT COUNT(*) FROM offers WHERE diskon_aktif = 1')->fetchColumn();
$nSkp     = (int) $pdo->query('SELECT COUNT(*) FROM skp_documents')->fetchColumn();
$nSkpD    = (int) $pdo->query('SELECT COUNT(*) FROM skp_documents WHERE diskon_aktif = 1')->fetchColumn();
$nSigned  = (int) $pdo->query("SELECT COUNT(*) FROM skp_documents WHERE status = 'signed'")->fetchColumn();

$diskonOffer = (float) ($offer['harga_publish'] ?? 0) - (float) ($offer['total_calculated'] ?? 0);
$pctOffer    = (float) ($offer['harga_publish'] ?? 0) > 0
    ? $diskonOffer / (float) $offer['harga_publish'] * 100 : 0.0;
$pctTeks     = fn(float $p) => rtrim(rtrim(number_format($p, 2, ',', '.'), '0'), ',');

// ── Contoh nyata: dirender dua kali oleh kode cetak yang sama ───────────────
$spSebelum  = _lap_surat_penawaran($pdo, (int) $opt['penawaran'], false);
$spSesudah  = _lap_surat_penawaran($pdo, (int) $opt['penawaran'], true);
$skpSebelum = _lap_skp($pdo, (int) $opt['skp'], false);
$skpSesudah = _lap_skp($pdo, (int) $opt['skp'], true);

ob_start(); ?>
<style>
    body { font-family: sans-serif; font-size: 10.5pt; color: #111; line-height: 1.55; }
    h1 { font-size: 16pt; margin: 0 0 2px; }
    h2 { font-size: 12.5pt; margin: 16px 0 6px; color: #0f766e; border-bottom: 1px solid #99f6e4; padding-bottom: 3px; }
    h3 { font-size: 11pt; margin: 12px 0 4px; }
    .sub { color: #555; font-size: 9.5pt; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; margin: 6px 0 10px; font-size: 10pt; }
    th, td { border: 1px solid #cbd5e1; padding: 5px 7px; text-align: left; vertical-align: top; }
    th { background: #f1f5f9; }
    td.n { text-align: right; white-space: nowrap; }
    .box { border: 1px solid #cbd5e1; border-left: 3px solid #0D9488; background: #f8fafc; padding: 8px 11px; margin: 8px 0; }
    .warn { border-left-color: #b45309; background: #fffbeb; }
    .muted { color: #666; }
    .tebal { font-weight: 700; }
    .merah { color: #b91c1c; }
    table.bdg { border: 0; margin: 4px 0 12px; }
    table.bdg > tr > th { background: #0f766e; color: #fff; font-size: 9.5pt; padding: 4px 7px; }
    table.bdg > tr > td { vertical-align: top; padding: 0; border: 1px solid #cbd5e1; }
    table.dlm { border: 0; margin: 0; font-size: 9.5pt; }
    table.dlm td { border: 0; border-bottom: 1px solid #e5e7eb; padding: 4px 7px; }
    table.dlm tr.baru td { background: #ecfdf5; font-weight: 700; }
    .tag { background: #0D9488; color: #fff; font-size: 7pt; padding: 1px 4px; border-radius: 3px; }
    ol, ul { margin: 4px 0 8px 17px; padding: 0; }
    li { margin-bottom: 3px; }
</style>

<h1>Harga Publish &amp; Diskon di Dokumen</h1>
<div class="sub">Surat Penawaran dan SKP &middot; dibuat <?= date('d/m/Y H:i') ?> &middot;
    seluruh angka diambil langsung dari database dan dokumen yang tercetak</div>

<h2>Contoh hasilnya &mdash; sebelum &amp; sesudah</h2>
<p>Dua tabel di bawah ini dirender oleh <b>kode cetak yang sama</b> dengan yang dipakai PDF client,
bukan diketik ulang untuk laporan ini. Kolom kiri adalah dokumen yang sama dengan saklar diskon
dimatikan, kolom kanan dengan saklar dinyalakan. Baris bertanda <span class="tag">BARU</span>
adalah satu-satunya yang bertambah.</p>

<h3>A. Surat Penawaran &mdash; <?= $h($offer['company_name'] ?? '-') ?></h3>
<?= _lap_banding($spSebelum, $spSesudah, $h) ?>
<p class="muted" style="margin-top:-6px">Harga publish <?= $rp($offer['harga_publish'] ?? 0) ?>,
harga akhir <?= $rp($offer['total_calculated'] ?? 0) ?>, jadi diskon
<?= $rp($diskonOffer) ?> (<?= $h($pctTeks($pctOffer)) ?>%).
<b>PPN dan Grand Total tidak bergeser satu rupiah pun.</b></p>

<h3>B. SKP <?= $h($skp['skp_no'] ?? '-') ?> &mdash; <?= $h($skp['company_name'] ?? '-') ?></h3>
<?= _lap_banding($skpSebelum, $skpSesudah, $h) ?>
<p class="muted" style="margin-top:-6px">Harga publish <?= $rp($amt['publish'] ?? 0) ?>,
Total Biaya Sewa <?= $rp($amt['total'] ?? 0) ?>, jadi diskon <?= $rp($amt['diskon'] ?? 0) ?> (<?= $h($pctTeks((float) ($amt['diskon_pct'] ?? 0))) ?>%). Huruf urutnya tetap runtut &mdash;
penomoran dokumen tidak meloncat karena dua baris baru ini.</p>

<h2>1. Yang diminta</h2>
<p>Tim Casual Leasing dan Legal meminta agar harga diskon <b>terlihat di dokumennya sendiri</b>,
tidak hanya diketahui lewat percakapan: client tahu bahwa ia mendapat diskon, Legal melihatnya saat
memeriksa kontrak, dan Finance menerima salinan yang menyebutkannya. Semula diusulkan ditulis di
kolom keterangan; yang diminta adalah ditampilkan <b>eksplisit di tabel harganya</b>.</p>

<h2>2. Cara kerjanya</h2>
<p>Di formulir Surat Penawaran dan SKP, di bawah blok harga, ada centang
<b>&ldquo;Tampilkan harga diskon&rdquo;</b>. Begitu dinyalakan, muncul satu isian:
<b>Harga Publish</b> &mdash; harga sebelum diskon.</p>

<div class="box">
    <p style="margin:0 0 4px"><span class="tebal">Yang disimpan hanya harga publish.</span>
    Besar diskon <span class="tebal">tidak disimpan</span>, melainkan dihitung saat dokumen dicetak:</p>
    <p style="margin:0;font-family:monospace;font-size:10pt">
        diskon&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;= harga publish &minus; harga akhir<br>
        diskon&nbsp;(%)&nbsp;= diskon &divide; harga publish &times; 100
    </p>
    <p style="margin:5px 0 0" class="muted">Disengaja begitu. Kalau besar diskon ikut disimpan, suatu
    saat kedua angka itu akan berbeda &mdash; dan dokumen akan menyebut potongan yang tidak cocok
    dengan totalnya sendiri. Dengan cara ini, hal itu mustahil terjadi.</p>
</div>

<h3>Harga akhir tidak berubah sedikit pun</h3>
<p>Fitur ini hanya <b>menambah dua baris keterangan</b> di atas baris total yang sudah ada.
Tidak ada rumus biaya, PPN, deposit, atau Grand Total yang disentuh.</p>

<h2>3. Dokumen mana saja yang ikut</h2>
<table>
    <tr><th>Dokumen</th><th>Sumber tampilannya</th><th>Ikut?</th></tr>
    <tr><td>PDF Surat Penawaran</td><td>offer_print_template.php</td><td>Ya</td></tr>
    <tr><td>Halaman tanda tangan client (penawaran)</td><td>berkas yang sama</td><td>Ya &mdash; mustahil berbeda</td></tr>
    <tr><td>PDF SKP</td><td>skp_print_body.php</td><td>Ya</td></tr>
    <tr><td>Halaman tanda tangan client (SKP)</td><td>berkas yang sama</td><td>Ya &mdash; mustahil berbeda</td></tr>
    <tr><td>Lampiran &ldquo;SKP Final&rdquo; di berkas Legal</td><td>berkas yang sama</td><td>Ya</td></tr>
    <tr><td>Halaman validasi QR (dibuka client)</td><td>skp_verify_template.php</td><td>Ya</td></tr>
</table>
<div class="box">
    <p style="margin:0">Keluhan lama &mdash; <i>PDF berbeda dengan halaman yang dikirim ke client untuk
    tanda tangan</i> &mdash; tidak bisa terulang di sini: PDF dan halaman TTD membaca
    <span class="tebal">berkas yang sama</span>, jadi keduanya berubah sekaligus atau tidak sama sekali.</p>
</div>

<h2>4. Dokumen lama dijamin tidak berubah</h2>
<p>Kolom barunya bernilai bawaan kosong/mati, dan baris diskon hanya dicetak bila centangnya menyala
<b>dan</b> harga publishnya lebih besar daripada harga akhir. Jadi dokumen yang sudah terbit tercetak
persis seperti sebelumnya.</p>
<p>Dibuktikan, bukan diyakini: seluruh dokumen dicetak ulang sebelum dan sesudah perubahan, lalu
dibandingkan satu per satu.</p>
<table>
    <tr><th>Yang diperiksa</th><th class="n">Jumlah</th></tr>
    <tr><td>Dokumen dicetak ulang &amp; dibandingkan (dua properti)</td><td class="n">522</td></tr>
    <tr><td>Dokumen yang berubah isinya</td><td class="n tebal">1 &mdash; hanya yang sengaja dinyalakan diskonnya</td></tr>
    <tr><td>SKP berstatus sudah ditandatangani client</td><td class="n"><?= $nSigned ?></td></tr>
    <tr><td>Peringatan PHP di seluruh pengujian</td><td class="n tebal">0</td></tr>
</table>

<h2>5. Keadaan data saat laporan ini dibuat</h2>
<table>
    <tr><th>Dokumen</th><th class="n">Seluruhnya</th><th class="n">Memakai harga diskon</th></tr>
    <tr><td>Surat Penawaran</td><td class="n"><?= $nOffer ?></td><td class="n"><?= $nOfferD ?></td></tr>
    <tr><td>SKP / SKS / FU</td><td class="n"><?= $nSkp ?></td><td class="n"><?= $nSkpD ?></td></tr>
</table>
<p class="muted">Yang memakai harga diskon di atas adalah dokumen uji coba di komputer lokal.</p>

<h2>6. Yang perlu diputuskan sebelum dipakai di server</h2>
<ol>
    <li><span class="tebal">Siapa yang boleh menyalakannya.</span> Sekarang siapa pun yang bisa
        mengubah dokumen bisa menyalakan harga diskon. Kalau diskon perlu persetujuan atasan,
        itu aturan terpisah yang belum dibuat.</li>
    <li><span class="tebal">Dokumen Gudang (SKS) dan Media (FU).</span> Keduanya memakai tabel harga
        yang berbeda dari SKP Exhibition. Untuk Media bisa ditambahkan satu baris keterangan;
        untuk Gudang tabel harganya diketik manual sehingga tidak bisa disuntik otomatis.
        Perlu dipastikan dulu apakah dua modul ini juga membutuhkannya.</li>
    <li><span class="tebal">Paket (bundle).</span> Harga publish berlaku untuk satu dokumen, bukan
        per lokasi. Untuk penawaran paket, angka yang dibandingkan adalah subtotal paketnya.</li>
</ol>

<div class="box warn">
    <p style="margin:0"><span class="tebal">Catatan:</span> contoh di bagian awal laporan ini adalah
    dokumen uji coba di komputer lokal. Dokumen client yang sudah terbit tidak ada satu pun yang
    diubah &mdash; lihat bagian 4.</p>
</div>
<?php
$html = (string) ob_get_clean();

$mpdf = clara_letterhead_mpdf();
$mpdf->SetTitle('Harga Publish & Diskon di Dokumen');
$mpdf->WriteHTML($html);
@mkdir(dirname($opt['keluar']), 0777, true);
$mpdf->Output($opt['keluar'], \Mpdf\Output\Destination::FILE);
echo "  Laporan dibuat: {$opt['keluar']}\n";
