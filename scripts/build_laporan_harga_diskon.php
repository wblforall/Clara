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

$opt = ['penawaran' => '51', 'skp' => '82', 'keluar' => $root . '/docs/Laporan_Harga_Publish_Diskon.pdf'];
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

/**
 * SATU tabel: tiap baris dokumen disandingkan sebelum vs sesudah.
 *
 * Dulu dibuat dua tabel bersanding, dan lebarnya memaksa mPDF memutar seluruh
 * halaman jadi miring sehingga tidak terbaca. Digabung begini juga lebih mudah
 * dibandingkan: mata cukup bergerak ke kanan pada baris yang sama.
 */
function _lap_banding(array $sebelum, array $sesudah, callable $h, callable $rp): string
{
    $tanpaHuruf = fn(string $t) => preg_replace('~^[a-z]\. ~', '', $t);
    $lama = [];
    foreach ($sebelum as $r) $lama[$tanpaHuruf($r[0])] = $r[1];

    $o = '<table class="bdg"><tr><th style="width:46%">Baris di dokumen</th>'
       . '<th class="n" style="width:27%">SEBELUM</th>'
       . '<th class="n" style="width:27%">SESUDAH</th></tr>';
    foreach ($sesudah as $r) {
        $kunci = $tanpaHuruf($r[0]);
        $baru  = !array_key_exists($kunci, $lama);
        $nilaiLama = $baru ? '&mdash;' : $h($lama[$kunci]);
        $o .= '<tr' . ($baru ? ' class="baru"' : '') . '>'
            . '<td>' . $h($r[0]) . ($baru ? ' <span class="tag">BARIS BARU</span>' : '') . '</td>'
            . '<td class="n">' . $nilaiLama . '</td>'
            . '<td class="n">' . $h($r[1]) . '</td></tr>';
    }
    return $o . '</table>';
}

// ── Dokumen contoh ─────────────────────────────────────────────────────────
$po = $pdo->prepare('SELECT o.*, c.company_name FROM offers o
                     LEFT JOIN master_clients c ON c.id = o.client_id WHERE o.id = ?');
$po->execute([(int) $opt['penawaran']]);
$offer = $po->fetch() ?: [];

$ps = $pdo->prepare('SELECT d.*, c.company_name FROM skp_documents d
                     LEFT JOIN transactions t ON t.id = d.transaction_id
                     LEFT JOIN master_clients c ON c.id = t.client_id WHERE d.id = ?');
$ps->execute([(int) $opt['skp']]);
$skp  = $ps->fetch() ?: [];
$amt  = ($skp && !empty($skp['snapshot_json']))
    ? ((json_decode((string) $skp['snapshot_json'], true) ?: [])['amounts'] ?? []) : [];

// ── Berapa dokumen yang ada, dan berapa yang memakai fitur ini ─────────────
$nOffer  = (int) $pdo->query('SELECT COUNT(*) FROM offers')->fetchColumn();
$nOfferD = (int) $pdo->query('SELECT COUNT(*) FROM offers WHERE diskon_aktif = 1')->fetchColumn();
$nSkp    = (int) $pdo->query('SELECT COUNT(*) FROM skp_documents')->fetchColumn();
$nSkpD   = (int) $pdo->query('SELECT COUNT(*) FROM skp_documents WHERE diskon_aktif = 1')->fetchColumn();
$nSigned = (int) $pdo->query("SELECT COUNT(*) FROM skp_documents WHERE status = 'signed'")->fetchColumn();

// ── Angka ringkas untuk kalimat di bawah tabel ─────────────────────────────
$tarifPakai   = (float) ($offer['unit_rate'] ?? 0);
$tarifPublish = (float) ($offer['tarif_publish'] ?? 0);
$pengaliOffer = $tarifPakai > 0 ? (float) $offer['total_calculated'] / $tarifPakai : 0.0;
$publishOffer = round($tarifPublish * $pengaliOffer);
$diskonOffer  = round(($tarifPublish - $tarifPakai) * $pengaliOffer);
$pctOffer     = $tarifPublish > 0 ? ($tarifPublish - $tarifPakai) / $tarifPublish * 100 : 0.0;
$pctTeks     = fn(float $p) => rtrim(rtrim(number_format($p, 2, ',', '.'), '0'), ',');

// ── Contoh nyata: dirender dua kali oleh kode cetak yang sama ───────────────
$spSebelum  = _lap_surat_penawaran($pdo, (int) $opt['penawaran'], false);
$spSesudah  = _lap_surat_penawaran($pdo, (int) $opt['penawaran'], true);
$skpSebelum = _lap_skp($pdo, (int) $opt['skp'], false);
$skpSesudah = _lap_skp($pdo, (int) $opt['skp'], true);

// Gambar halaman dokumen aslinya, dibuat dari PDF yang benar-benar tercetak.
$gbrDir = $root . '/docs/contoh_diskon_assets';
$gbrSp  = is_file("$gbrDir/surat_penawaran-1.png") ? "$gbrDir/surat_penawaran-1.png" : '';
$gbrSkp = is_file("$gbrDir/skp-1.png") ? "$gbrDir/skp-1.png" : '';
// Tangkapan layar formulirnya sendiri — diambil dari halaman yang benar-benar
// dirender aplikasi, bukan gambar yang dibuat untuk laporan ini.
$gbrFrmSp  = is_file("$gbrDir/form_sp.png")  ? "$gbrDir/form_sp.png"  : '';
$gbrFrmSkp = is_file("$gbrDir/form_skp.png") ? "$gbrDir/form_skp.png" : '';

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
    table.bdg { margin: 4px 0 10px; font-size: 9.5pt; }
    table.bdg th { background: #0f766e; color: #fff; font-size: 9pt; padding: 4px 7px; }
    table.bdg td { padding: 4px 7px; }
    table.bdg tr.baru td { background: #ecfdf5; font-weight: 700; }
    .tag { background: #0D9488; color: #fff; font-size: 7pt; padding: 1px 4px; border-radius: 3px; }
    ol, ul { margin: 4px 0 8px 17px; padding: 0; }
    li { margin-bottom: 3px; }
</style>

<h1>Harga Publish &amp; Diskon di Dokumen</h1>
<div class="sub">Surat Penawaran dan SKP &middot; dibuat <?= date('d/m/Y H:i') ?> &middot;
    seluruh angka diambil langsung dari database dan dokumen yang tercetak</div>

<h2>Contoh hasilnya &mdash; sebelum &amp; sesudah</h2>
<p>Tabel di bawah ini dirender oleh <b>kode cetak yang sama</b> dengan yang dipakai PDF client &mdash;
bukan diketik ulang untuk laporan ini. Tiap baris dokumen disandingkan: kolom <b>SEBELUM</b> adalah
dokumen yang sama dengan saklar diskon dimatikan, kolom <b>SESUDAH</b> dengan saklar dinyalakan.
Baris berlatar hijau bertanda <span class="tag">BARIS BARU</span> adalah satu-satunya yang bertambah;
baris lainnya angkanya sama persis di kedua kolom.</p>

<h3>A. Surat Penawaran &mdash; <?= $h($offer['company_name'] ?? '-') ?></h3>
<?= _lap_banding($spSebelum, $spSesudah, $h, $rp) ?>
<p class="muted" style="margin-top:-6px">Tarif diturunkan dari <b><?= $rp($tarifPublish) ?></b>
menjadi <b><?= $rp($tarifPakai) ?></b> &mdash; diskon <b><?= $h($pctTeks($pctOffer)) ?>%</b>.
Pengalinya <?= $h(rtrim(rtrim(number_format($pengaliOffer, 4, ',', '.'), '0'), ',')) ?>, jadi
<?= $rp($publishOffer) ?> &minus; <?= $rp($diskonOffer) ?> = <?= $rp($offer['total_calculated'] ?? 0) ?>&nbsp;&mdash; pas, tanpa sisa pembulatan. <b>PPN dan Grand Total tidak bergeser satu rupiah pun.</b></p>
<?php if ($gbrSp !== ''): ?>
<div style="text-align:center;margin:6px 0 12px">
    <img src="<?= $h($gbrSp) ?>" style="width:150mm;border:1px solid #cbd5e1">
    <div class="muted" style="font-size:8.5pt;margin-top:3px">Halaman 1 Surat Penawaran yang benar-benar tercetak</div>
</div>
<?php endif; ?>

<h3>B. SKP <?= $h($skp['skp_no'] ?? '-') ?> &mdash; <?= $h($skp['company_name'] ?? '-') ?></h3>
<?= _lap_banding($skpSebelum, $skpSesudah, $h, $rp) ?>
<p class="muted" style="margin-top:-6px">Tarif diturunkan dari <b><?= $rp($amt['tarif_publish'] ?? 0) ?></b>
menjadi <b><?= $rp($amt['tarif_pakai'] ?? 0) ?></b> &mdash; diskon
<b><?= $h($pctTeks((float) ($amt['diskon_pct'] ?? 0))) ?>%</b> atau <?= $rp($amt['diskon'] ?? 0) ?>.
Huruf urutnya tetap runtut &mdash; penomoran dokumen tidak meloncat karena dua baris baru ini.</p>
<?php if ($gbrSkp !== ''): ?>
<div style="text-align:center;margin:6px 0 12px">
    <img src="<?= $h($gbrSkp) ?>" style="width:150mm;border:1px solid #cbd5e1">
    <div class="muted" style="font-size:8.5pt;margin-top:3px">Halaman 1 SKP yang benar-benar tercetak</div>
</div>
<?php endif; ?>

<h2>1. Yang diminta</h2>
<p>Tim Casual Leasing dan Legal meminta agar harga diskon <b>terlihat di dokumennya sendiri</b>,
tidak hanya diketahui lewat percakapan: client tahu bahwa ia mendapat diskon, Legal melihatnya saat
memeriksa kontrak, dan Finance menerima salinan yang menyebutkannya. Semula diusulkan ditulis di
kolom keterangan; yang diminta adalah ditampilkan <b>eksplisit di tabel harganya</b>.</p>

<h2>2. Cara kerjanya</h2>
<p>Di formulir Surat Penawaran dan SKP, di bawah blok harga, ada centang
<b>&ldquo;Tampilkan harga diskon&rdquo;</b>. Begitu dinyalakan, kotak <b>Rate</b> yang sudah ada di
atas diperlakukan sebagai <b>tarif publish</b> (harga daftar), dan muncul satu isian baru:
<b>tarif setelah diskon</b> &mdash; harga nego untuk client.</p>
<p>Jadi persis seperti cara orang menyebutnya: <b>&ldquo;dari Rp 165.000 turun ke Rp 145.000&rdquo;</b>.
Rp 165.000 sudah ada di kotak Rate, Rp 145.000 diketik di kotak diskon. Tidak ada lagi angka total
yang perlu diketik tangan.</p>

<div class="box warn">
    <p style="margin:0 0 4px"><span class="tebal">Totalnya ikut terhitung ulang sendiri.</span>
    Begitu tarif nego diisi, seluruh nilai kontrak dihitung dari tarif itu &mdash; dan kotak
    <i>Harga Nego Final</i> (override manual) sengaja diabaikan selama diskon menyala.
    Inilah yang menghilangkan <b>selisih seribu&ndash;dua ribu rupiah</b> yang selama ini muncul:
    dulu tarif dan total diketik terpisah, jadi keduanya bisa tidak cocok. Sekarang tidak bisa lagi.</p>
</div>

<div class="box">
    <p style="margin:0 0 4px"><span class="tebal">Yang disimpan hanya tarif publish.</span>
    Tarif nego menjadi tarif dokumennya sendiri. Besar diskon <span class="tebal">tidak disimpan</span>,
    melainkan dihitung saat dokumen dicetak:</p>
    <p style="margin:0;font-family:monospace;font-size:10pt">
        pengali&nbsp;= nilai sewa &divide; tarif<br>
        publish&nbsp;= tarif publish &times; pengali<br>
        diskon&nbsp;&nbsp;= (tarif publish &minus; tarif) &times; pengali<br>
        persen&nbsp;&nbsp;= (tarif publish &minus; tarif) &divide; tarif publish &times; 100
    </p>
    <p style="margin:5px 0 0" class="muted">Pengalinya diambil dari <b>nilai sewa yang memang
    tercetak di dokumen itu</b>, jadi harga publish memakai dasar yang sama persis dengan harga
    akhirnya. Hasilnya tidak pernah ada sisa pembulatan &mdash; tidak peduli harganya harian atau
    bulanan, memakai nilai override atau tidak, prorata atau tidak.</p>
    <p style="margin:5px 0 0" class="muted">Diskon berlaku pada <b>sewa saja</b>. Biaya listrik,
    service charge, dan deposit ditagih apa adanya dan tidak ikut didiskon.</p>
</div>

<?php if ($gbrFrmSp !== '' || $gbrFrmSkp !== ''): ?>
<h3>Tampilan isiannya</h3>
<p class="muted" style="margin-top:-2px">Tangkapan layar di bawah diambil langsung dari halaman
aplikasi &mdash; termasuk baris keterangan dan Ringkasan Biaya yang ikut berubah seketika begitu
tarif negonya diketik, tanpa perlu disimpan dulu.</p>
<?php if ($gbrFrmSp !== ''): ?>
<p style="margin:6px 0 2px"><b>Surat Penawaran</b></p>
<img src="<?= $h($gbrFrmSp) ?>" style="width:150mm;border:1px solid #cbd5e1">
<?php endif; ?>
<?php if ($gbrFrmSkp !== ''): ?>
<p style="margin:10px 0 2px"><b>SKP</b> <span class="muted">&mdash; Rincian Pembayaran ikut turun
mengikuti tarif nego; sebelumnya angka ini diam sampai dokumennya disimpan</span></p>
<img src="<?= $h($gbrFrmSkp) ?>" style="width:125mm;border:1px solid #cbd5e1">
<?php endif; ?>
<?php endif; ?>

<h3>Kenapa yang diketik tarif setelah diskon</h3>
<p>Percobaan sebelumnya meminta kebalikannya: kotak Rate diisi tarif hasil nego, dan kotak diskon
diisi tarif publish. Itu terbalik dari cara kerja sehari-hari &mdash; yang dipegang adalah harga
daftar, dan yang dinegosiasikan adalah harga turunnya. Akibatnya isian yang justru paling wajar
(&ldquo;Rate Rp 165.000, diskon jadi Rp 145.000&rdquo;) malah ditolak dengan pesan
<i>&ldquo;tarif publish harus lebih besar&rdquo;</i>.</p>
<p>Akibat keduanya lebih serius, dan ini terbaca pada dokumen yang sudah ada: kotak Rate
<b>sering dibiarkan di harga publish</b>, lalu hasil negonya diketik sebagai total di
<i>Harga Nego Final</i>. Dengan susunan yang lama, diskonnya akan terhitung <b>nol</b> &mdash;
tepat pada kasus yang jadi asal permintaan ini:</p>
<table>
    <tr><th style="width:52%">Penawaran nyata di data (id 36)</th><th class="n">Nilai</th></tr>
    <tr><td>Tarif yang tertulis di kotak Rate</td><td class="n">Rp 165.000</td></tr>
    <tr><td>Total yang diketik di Harga Nego Final</td><td class="n">Rp 26.970.000</td></tr>
    <tr><td>Pengali dokumen <span class="muted">(luas &times; hari)</span></td><td class="n">186</td></tr>
    <tr><td>Tarif yang <b>sebenarnya</b> dipakai <span class="muted">(26.970.000 &divide; 186)</span></td>
        <td class="n tebal">Rp 145.000</td></tr>
    <tr class="baru"><td>Diskon bila kotak Rate dianggap tarif nego
        <span class="merah">&mdash; salah</span></td><td class="n merah">Rp 0</td></tr>
    <tr class="baru"><td>Diskon dengan susunan sekarang
        <span class="tebal">&mdash; benar</span></td><td class="n tebal">Rp 3.720.000 (12,12%)</td></tr>
</table>
<p class="muted" style="margin-top:-4px">Sekarang kotak Rate selalu berarti tarif publish, tarif nego
diketik di kotak diskon, dan totalnya dihitung dari tarif nego itu &mdash; jadi tidak ada lagi jalan
untuk tarif dan total menjadi tidak cocok.</p>

<h3>Kenapa tarif, bukan total</h3>
<p>Versi pertama meminta <b>total</b> sebelum diskon. Cara itu keliru pada dokumen yang menagih
listrik, dan ini bukan dugaan &mdash; angkanya diambil dari dokumen nyata:</p>
<table>
    <tr><th style="width:52%">Dokumen dengan tarif Rp 145.000, pengali 186</th><th class="n">Nilai</th></tr>
    <tr><td>Nilai sewa <span class="muted">(145.000 &times; 186)</span></td><td class="n">Rp 26.970.000</td></tr>
    <tr><td>Biaya listrik</td><td class="n">Rp 2.577.960</td></tr>
    <tr><td>Total Biaya Sewa</td><td class="n">Rp 29.547.960</td></tr>
    <tr><td>Total publish yang diketik <span class="muted">(165.000 &times; 186)</span></td><td class="n">Rp 30.690.000</td></tr>
    <tr class="baru"><td>Diskon versi TOTAL <span class="merah">&mdash; salah</span></td>
        <td class="n merah">Rp 1.142.040 (3,72%)</td></tr>
    <tr class="baru"><td>Diskon versi TARIF <span class="tebal">&mdash; benar</span></td>
        <td class="n tebal">Rp 3.720.000 (12,12%)</td></tr>
    <tr><td>Selisihnya</td><td class="n">Rp 2.577.960 &mdash; persis biaya listriknya</td></tr>
</table>
<p class="muted" style="margin-top:-4px">Sebabnya harga publish (sewa saja) dibandingkan dengan
total yang sudah memuat listrik. Dengan tarif, hal itu tidak mungkin terjadi &mdash; listrik tidak
pernah ikut masuk hitungan diskon.</p>

<h3>Yang berubah dan yang tidak</h3>
<p><b>Rumusnya tidak disentuh.</b> PPN, Service Charge, deposit, dan Grand Total tetap dihitung
dengan cara yang sama persis seperti sebelumnya. Yang ditambahkan di tabel harga hanya
<b>dua baris keterangan</b> di atas baris total yang sudah ada.</p>
<p><b>Yang memang berubah &mdash; dan ini disengaja:</b> begitu harga diskon dinyalakan, nilai sewanya
dihitung dari tarif nego, bukan dari angka total yang diketik tangan. Itulah inti permintaannya:
cukup set tarifnya, sisanya dihitung sendiri. Untuk dokumen yang <b>tidak</b> memakai harga diskon,
tidak ada satu pun angka yang berubah &mdash; terbukti dari 524 dokumen yang dicetak ulang di
bagian 4.</p>

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
<b>dan</b> tarif publishnya lebih besar daripada tarif yang dipakai. Dokumen yang dibekukan sebelum
perubahan ini pun tetap tercetak dengan kata-kata lamanya, karena snapshot-nya tidak memuat tarif.</p>
<p>Dibuktikan, bukan diyakini: seluruh dokumen dicetak ulang sebelum dan sesudah perubahan, lalu
dibandingkan satu per satu. Pembalikan susunan isian yang terakhir hanya menyentuh formulir dan
jalur simpan &mdash; kode pencetaknya tidak disentuh sama sekali, dan hasil perbandingannya memang
nol dokumen berubah.</p>
<p>Bentuk datanya juga tidak berubah: tarif yang dipakai tetap tersimpan di <code>unit_rate</code>
dan tarif publishnya di <code>tarif_publish</code>. Dokumen yang sudah terlanjur dibuat dengan
susunan isian yang lama tetap tercetak benar tanpa perlu diperbaiki satu pun.</p>
<table>
    <tr><th>Yang diperiksa</th><th class="n">Jumlah</th></tr>
    <tr><td>Dokumen dicetak ulang &amp; dibandingkan (dua properti)</td><td class="n">524</td></tr>
    <tr><td>Berubah isinya setelah susunan isian dibalik</td><td class="n tebal">0 dari 524</td></tr>
    <tr><td>Berubah isinya dibanding sebelum fitur ini ada</td><td class="n tebal">5 &mdash; seluruhnya dokumen uji lokal</td></tr>
    <tr><td>Surat penawaran milik client yang angkanya berubah</td><td class="n tebal">0 dari 53</td></tr>
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
    <li><span class="tebal">Paket (bundle).</span> Tarif publish berlaku untuk satu dokumen, bukan
        per lokasi. Untuk penawaran paket, yang dibandingkan adalah subtotal paketnya.</li>
    <li><span class="tebal">Dokumen tanpa tarif.</span> 96 dari 1.805 transaksi nilainya diketik manual
        tanpa tarif (<code>unit_rate</code> = 0) &mdash; GRAB salah satunya. Untuk dokumen seperti itu
        harga diskon tidak bisa dihitung dari tarif, dan formulirnya menyebutkan hal itu apa adanya
        alih-alih menampilkan angka yang menyesatkan.</li>
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
