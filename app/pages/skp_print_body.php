<?php
/**
 * Partial isi dokumen SKP (tanpa <html>/<head>/letterhead) — dipakai oleh
 * skp_print_template.php DAN sebagai lampiran "SKP Final" di berkas Legal.
 * CSS di-scope ke .skpdoc agar tidak bentrok dgn template induk.
 * Vars wajib: $skp, $d (snapshot), $a (amounts), $rp, $chk. Opsional $h.
 */
$h = $h ?? fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
// Partial ini juga dipakai lampiran Legal (contract_legal_print) yang tak memuat
// skp.php → pastikan helper ketentuan tersedia.
if (!function_exists('skp_terms')) require_once __DIR__ . '/skp.php';
if (!function_exists('skp_doc_title')) require_once __DIR__ . '/skp_modules.php';
require_once dirname(__DIR__) . '/Paraf.php';

/**
 * Paraf bergambar yang ditempel di posisi tetap pada halaman.
 *
 * Bentuk & koordinatnya TIDAK dibaca dari pengaturan yang berlaku sekarang,
 * melainkan dari yang sudah dikunci ke dokumen ini saat orangnya memaraf —
 * supaya dokumen yang sudah diserahkan ke client tidak ikut berubah ketika
 * pemeriksanya menggeser parafnya untuk dokumen berikutnya.
 *
 * Dipakai dua kali dengan cara berbeda: pratinjau HTML menempelkannya sebagai
 * elemen absolut di dalam kertas, sedangkan PDF memakai WriteFixedPosHTML
 * milik mPDF. Keduanya membaca milimeter yang sama persis.
 */
$PARAF_OVERLAY = [];   // mode manual — ditempel pada koordinat halaman
$PARAF_BARIS = [];     // mode otomatis — dicetak di baris pemeriksaan, per pemeriksa
foreach (($d['paraf'] ?? []) as $iPf => $pf) {
    $set = $pf['paraf'] ?? null;
    if (!$set || !in_array($pf['action'] ?? '', ['paraf', 'approve'], true)) continue;
    $kapan = ($pf['waktu'] ?? '') !== '' ? date('d/m/Y H:i', strtotime((string) $pf['waktu'])) : '';

    // Mode otomatis tidak memakai koordinat sama sekali: parafnya dicetak pada
    // baris "Diperiksa sebelum disetujui" di bawah blok tanda tangan, tepat di
    // sebelah nama pemeriksanya. Berlaku di jenis surat mana pun tanpa perlu
    // diatur ulang.
    //
    // Snapshot lama belum punya kunci 'mode' — dan pada masanya mode otomatis
    // memang belum ada, jadi semuanya manual. Default 'manual' di sini membuat
    // dokumen yang sudah terbit tetap tercetak persis seperti aslinya.
    $sumberGbr = !empty($PDF_MODE) ? 'file' : 'web';
    $otomatis  = ($set['mode'] ?? 'manual') !== 'manual';
    if ($otomatis) {
        // Dokumen lama bisa menyimpan lebar yang melebihi batas sekarang, jadi
        // dijepit di sini juga supaya barisnya tidak melar.
        $set['lebar'] = min((float) $set['lebar'], Paraf::LEBAR_OTOMATIS_MAKS);
        // Coretannya saja, tanpa pembungkus: nama, jabatan, dan jamnya dicetak
        // oleh baris pemeriksaan tepat di sebelahnya.
        $isi = Paraf::sebaris($set, $sumberGbr, Paraf::maksTinggiOtomatis((float) $set['lebar']));
        if ($isi !== '') $PARAF_BARIS[$iPf] = $isi;
        continue;
    }
    $isi = Paraf::html($set, (string) ($pf['nama'] ?? ''), (string) ($pf['role_name'] ?? ''), $kapan, $sumberGbr);
    if ($isi === '') continue;
    $PARAF_OVERLAY[] = [
        'html' => $isi,
        'x'    => (float) $set['pos_x'],
        'y'    => (float) $set['pos_y'],
        'w'    => (float) $set['lebar'],
    ];
}
$skpNotes = skp_terms();
// Nama di blok "Mengetahui": manajer yang tercatat di pengaturan
// (doc_manager_name). Tanpa itu, dipakai nama yang menekan tombol Setujui —
// yang bisa saja superadmin yang menyetujui atas nama manajer.
$skpManager = '';
if (function_exists('get_setting') && class_exists('Database')) {
    try { $skpManager = trim((string) get_setting(Database::connect(), 'doc_manager_name', '')); }
    catch (Throwable $e) { $skpManager = ''; }
}
if ($skpManager === '') $skpManager = (string) ($skp['approved_by'] ?? '-');
// Gudang (sks) & Media (fu) memakai badan dokumen sendiri; teksnya dari snapshot
// template yang terkunci saat approve.
$skpDocType = (string) ($skp['doc_type'] ?? 'skp');
$skpTpl     = $d['tpl'] ?? [];
// Jabatan di blok TTD: dokumen gudang memakai gaya kertas (tebal miring, hitam).
$skpJab = $skpDocType === 'sks'
    ? 'style="font-weight:bold;font-style:italic;color:#000"'
    : 'class="muted" style="font-weight:normal"';
$skpToday = date('d') . ' ' . ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][(int) date('n')] . ' ' . date('Y');
$skpScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$skpDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$skpVerifyUrl = $skpScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $skpDir . '/?r=doc_verify&token=' . ($skp['sign_token'] ?? '');
// Di PRATINJAU kotak QR tetap dirender meski tokennya belum terbit. Tanpa itu
// kedua kolom berdiri kosong dan pemeriksanya tidak bisa menilai apakah surat
// ini sudah benar — padahal di surat asli keduanya memang berisi QR, bukan
// tanda tangan.
$skpHasQr = !empty($skp['sign_token']) || !empty($PRATINJAU);
?>
<style>
.skpdoc .doc-title { text-align: center; font-size: 16px; font-weight: bold; letter-spacing: .5px; margin: 0 0 2px; text-transform: uppercase; color: #111; }
.skpdoc .doc-no { text-align: center; font-size: 11px; color: #555; margin-bottom: 14px; }
.skpdoc .sec { font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: .04em; color: #0D9488; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; margin: 14px 0 7px; page-break-after: avoid; }
.skpdoc table.kv { width: 100%; border-collapse: collapse; }
.skpdoc table.kv td { padding: 2.5px 0; vertical-align: top; border: none; font-size: 11px; }
.skpdoc table.kv td.l { width: 38%; color: #374151; }
.skpdoc table.kv td.c { width: 3%; }
.skpdoc table.kv td.v { font-weight: bold; }
/* Form Utilities: kolom keterangan ikut tebal, seperti formulir kertasnya. */
.skpdoc table.kv.tebal td.l { font-weight: bold; color: #111; }
.skpdoc table.pay { width: 100%; border-collapse: collapse; margin-top: 3px; }
.skpdoc table.pay td { padding: 4px 6px; border: 1px solid #e5e7eb; font-size: 11px; }
.skpdoc table.pay td.lbl { width: 60%; } .skpdoc table.pay td.amt { text-align: right; font-weight: bold; white-space: nowrap; }
.skpdoc table.pay tr.grand td { background: #f0fdfa; font-weight: bold; color: #0f766e; }
.skpdoc .chk { font-size: 13px; }
.skpdoc table.notes { width: 100%; border-collapse: collapse; margin-top: 4px; }
.skpdoc table.notes tr { page-break-inside: avoid; }
.skpdoc table.notes td.nn { width: 20px; vertical-align: top; font-weight: bold; font-size: 11px; color: #374151; }
.skpdoc table.notes td.nt { font-size: 11px; color: #374151; line-height: 1.45; padding-bottom: 3px; text-align: justify; }
.skpdoc table.sign { width: 100%; border-collapse: collapse; margin-top: 22px; page-break-inside: avoid; table-layout: fixed; }
.skpdoc table.sign td.col { width: 33.33%; font-size: 10.5px; text-align: center; vertical-align: top; padding: 0 6px; }
.skpdoc table.sign td.col img { max-width: 100%; height: auto; }
.skpdoc .sign .role { color: #6b7280; margin-bottom: 4px; }
.skpdoc .sign .sigarea { height: 78px; }  /* tinggi seragam (muat QR/coretan) → nama SEJAJAR tiap kolom */
.skpdoc .sign .name { font-weight: bold; border-top: 1px solid #111; padding-top: 3px; display: inline-block; min-width: 150px; }
.skpdoc .qrbox { width: 70px; height: 70px; margin: 0 auto 3px; }
.skpdoc .qrbox img, .skpdoc .qrbox svg { width: 70px !important; height: 70px !important; display: block; }
.skpdoc .qrhint { font-size: 7.5px; color: #6b7280; margin-bottom: 3px; }
/* Keterangan dalam kurung tetap ramping walau induknya tebal — mPDF tidak
   mengenal font-weight angka, jadi "normal" ditulis eksplisit di sini. */
.skpdoc .muted { color: #6b7280; font-weight: normal; }

/* Gudang (SKS) & Media (FU) mengikuti bentuk dokumen kertas: Calibri (dipakai
   penggantinya yang metriknya sama, Carlito), judul tebal bergaris bawah,
   paragraf rata kiri-kanan, tabel harga berkepala biru tua. */
.skpdoc.kertas { font-family: carlito, sans-serif; font-size: 11pt; color: #000; line-height: 1.32; }
.skpdoc.kertas .doc-title { font-size: 12pt; font-weight: bold; text-decoration: underline; letter-spacing: 0; margin-bottom: 1px; }
.skpdoc.kertas .doc-sub { text-align: center; font-weight: bold; font-size: 12pt; margin-bottom: 9px; }
.skpdoc.kertas p { margin: 0 0 3px; }
.skpdoc.kertas .just { text-align: justify; margin: 7px 0; }
.skpdoc.kertas table.idn { width: 100%; border-collapse: collapse; margin: 0 0 2px; font-family: carlito, sans-serif; }
.skpdoc.kertas table.idn td { padding: 0 0 1px; font-size: 11pt; vertical-align: top; font-family: carlito, sans-serif; }
.skpdoc.kertas table.idn td.l { width: 56mm; padding-left: 6mm; }
.skpdoc.kertas table.idn td.c { width: 4mm; }
.skpdoc.kertas table.harga { width: 100%; border-collapse: collapse; margin: 4px 0 3px; }
.skpdoc.kertas table.harga th { background: #1F3864; color: #fff; font-weight: bold; font-size: 11pt; text-align: center; padding: 4px 5px; border: 0.6pt solid #000; }
.skpdoc.kertas table.harga { font-family: carlito, sans-serif; }
.skpdoc.kertas table.harga th { font-size: 9.5pt; padding: 4px 3px; }
.skpdoc.kertas table.harga td { background: #D9E2F3; border: 0.6pt solid #000; padding: 6px 7px; font-size: 9.5pt; line-height: 1.45; vertical-align: middle; font-family: carlito, sans-serif; }
.skpdoc.kertas table.harga td.n { text-align: center; }
.skpdoc.kertas table.harga td.ket { text-align: left; }
.skpdoc.kertas .kaki { font-style: italic; font-weight: bold; font-size: 10pt; margin: 4px 0 12px; }
.skpdoc.kertas .bayar { font-weight: bold; margin: 8px 0 0; }
.skpdoc.kertas ol.aturan { margin: 2px 0 0 6mm; padding: 0; }
.skpdoc.kertas ol.aturan li { text-align: justify; margin-bottom: 2px; }
.skpdoc.kertas .sign .role { color: #000; }
.skpdoc.kertas .sign .name { font-weight: 400; text-decoration: underline; border-top: none; padding-top: 0; }
.skpdoc.kertas .sign .name .muted { color: #000; font-style: italic; font-weight: bold; font-size: 10pt; }
</style>
<div class="skpdoc<?= $skpDocType === 'sks' ? ' kertas' : '' ?>">
    <div class="doc-title"<?= $skpDocType === 'sks' ? ' style="font-weight:bold;text-decoration:underline"' : '' ?>><?= $h(skp_doc_heading($skpDocType, $skpTpl)) ?></div>
    <?php if ($skpDocType === 'sks'): ?>
    <div class="doc-sub" style="font-weight:bold"><?= $h($d['property_name'] ?? '') ?></div>
    <div class="doc-no" style="text-align:right;margin-top:-6px">No. <?= $h($skp['skp_no']) ?></div>
    <?php else: ?>
    <div class="doc-no">No. <?= $h($skp['skp_no']) ?></div>
    <?php endif; ?>

    <?php if ($skpDocType !== 'skp'): ?>
    <?php /* Form Utilities langsung masuk ke Data Permohonan — tanggalnya sudah
             jadi baris pertama di sana, seperti formulir kertasnya. */ ?>
    <?php if ($skpDocType === 'sks'): ?>
    <p>Pada hari ini, <?= $h(skp_hari($d['doc_date'] ?? date('Y-m-d'))) ?> <?= $h(skp_tgl($d['doc_date'] ?? date('Y-m-d'))) ?> dibuat dan ditandatangani <strong><?= $h(skp_doc_heading($skpDocType, $skpTpl)) ?></strong> oleh dan antara:</p>
    <p>Kami yang bertanda tangan dibawah ini :</p>
    <?php endif; ?>
    <?= skp_detail_print($skpDocType, $d, $skpTpl) ?>
    <?php else: ?>

    <div class="sec">Identitas Penyewa</div>
    <table class="kv">
        <tr><td class="l">Nama Perusahaan</td><td class="c">:</td><td class="v"><?= $h($d['company_name'] ?? '-') ?></td></tr>
        <tr><td class="l">Nama Penanggung Jawab</td><td class="c">:</td><td class="v"><?= $h($d['cp_name'] ?? '-') ?></td></tr>
        <tr><td class="l">Alamat Kantor / PJ</td><td class="c">:</td><td class="v"><?= $h($d['address'] ?? '-') ?></td></tr>
        <tr><td class="l">Nomor KTP Penanggung Jawab</td><td class="c">:</td><td class="v"><?= $h($d['ktp_pj'] ?? '-') ?></td></tr>
        <tr><td class="l">Nomor Telepon</td><td class="c">:</td><td class="v"><?= $h($d['phone'] ?? '-') ?></td></tr>
    </table>

    <?php
    $att = $d['attachments'] ?? [];
    $hasOffer = !empty($d['offer_no']);
    if ($hasOffer || $att):
        $fn = fn($k) => isset($att[$k]) ? ' <span class="muted" style="font-weight:normal">(' . $h($att[$k]) . ')</span>' : '';
    ?>
    <div class="sec">Lampiran Dokumen</div>
    <table class="kv">
        <?php if ($hasOffer): ?><tr><td class="l">Surat Penawaran Final</td><td class="c">:</td><td class="v chk"><span style="color:#0D9488">■</span> <span class="muted" style="font-weight:normal">No. <?= $h($d['offer_no']) ?></span></td></tr><?php endif; ?>
        <tr><td class="l">Copy KTP Penanggung Jawab</td><td class="c">:</td><td class="v chk"><?= $chk(isset($att['ktp']) || ($d['admin_ktp'] ?? 0)) ?> <span class="muted" style="font-weight:normal"><?= !empty($d['ktp_pj']) ? 'No. ' . $h($d['ktp_pj']) : '' ?></span><?= $fn('ktp') ?></td></tr>
        <tr><td class="l">Copy NPWP</td><td class="c">:</td><td class="v chk"><?= $chk(isset($att['npwp']) || ($d['admin_npwp'] ?? 0)) ?> <span class="muted" style="font-weight:normal"><?= !empty($d['npwp']) ? 'No. ' . $h($d['npwp']) : '' ?></span><?= $fn('npwp') ?></td></tr>
        <tr><td class="l">Copy SIUP</td><td class="c">:</td><td class="v chk"><?= $chk(isset($att['siup']) || ($d['admin_siup'] ?? 0)) ?> <span class="muted" style="font-weight:normal"><?= !empty($d['siup']) ? 'No. ' . $h($d['siup']) : '' ?></span><?= $fn('siup') ?></td></tr>
        <tr><td class="l">Bukti Transfer</td><td class="c">:</td><td class="v chk"><?= $chk(isset($att['bukti_transfer'])) ?><?= $fn('bukti_transfer') ?></td></tr>
        <?php if (isset($att['pengajuan'])): ?><tr><td class="l">Dokumen Pengajuan</td><td class="c">:</td><td class="v chk"><span style="color:#0D9488">■</span><?= $fn('pengajuan') ?></td></tr><?php endif; ?>
    </table>
    <?php endif; ?>

    <?php
    /* Dokumen paket menyebut SELURUH lokasinya di kepala dokumen. Tanpa ini,
       dokumen gabungan 4 lokasi tercetak "1 lokasi, 20 m²" — dan pemeriksa
       (asst. manager & manager) tidak punya cara tahu sisanya ada. Dokumen yang
       sudah disetujui sebelum perbaikan ini rinciannya diambil dari Surat
       Penawaran saat dicetak; angka pembayarannya tetap dari snapshot. */
    $kmpSkp    = array_values((array) ($d['bundle_items'] ?? []));
    $adaKmp    = count($kmpSkp) >= 2;
    $luasKmp   = array_sum(array_map(fn($x) => (float) ($x['luas'] ?? 0), $kmpSkp));
    $lantaiKmp = implode(', ', array_values(array_unique(array_filter(
        array_map(fn($x) => trim((string) ($x['lantai'] ?? '')), $kmpSkp),
        fn($x) => $x !== '' && $x !== '-'))));
    $lokSkp = $adaKmp ? 'Paket — ' . count($kmpSkp) . ' lokasi' : (string) ($d['location'] ?? '-');
    ?>
    <div class="sec">Spesifikasi Tempat &amp; Periode Sewa</div>
    <table class="kv">
        <tr><td class="l">Lokasi</td><td class="c">:</td><td class="v"><?= $h($lokSkp) ?></td></tr>
        <tr><td class="l">Lantai</td><td class="c">:</td><td class="v"><?= $h($adaKmp && $lantaiKmp !== '' ? $lantaiKmp : ($d['floor'] ?? '-')) ?></td></tr>
        <tr><td class="l">Luas Area</td><td class="c">:</td><td class="v"><?= angka($adaKmp && $luasKmp > 0 ? $luasKmp : (float) ($d['area'] ?? 0)) ?> m²</td></tr>
        <?php if (!empty($d['seating_area'])): ?><tr><td class="l">Luas Seating Area</td><td class="c">:</td><td class="v"><?= angka($d['seating_area']) ?> m²</td></tr><?php endif; ?>
        <tr><td class="l">Masa Sewa</td><td class="c">:</td><td class="v"><?= $h(date('d/m/Y', strtotime($d['start_date'])) . ' s/d ' . date('d/m/Y', strtotime($d['end_date']))) ?> (<?= (int) ($d['days'] ?? 0) ?> hari)</td></tr>
        <tr><td class="l">Status Sewa</td><td class="c">:</td><td class="v"><?= $h($d['status_sewa'] ?? '-') ?></td></tr>
        <tr><td class="l">Jenis Usaha / Kegiatan</td><td class="c">:</td><td class="v"><?= $h($d['business_type'] ?? '-') ?></td></tr>
        <tr><td class="l">Produk</td><td class="c">:</td><td class="v"><?= $h(($d['produk'] ?? '') ?: ($d['brand_name'] ?? '-')) ?></td></tr>
    </table>

    <?php /* Patokannya ada tidaknya komponen, bukan penanda is_bundle: ada
             dokumen paket yang penandanya tidak tersimpan. */ ?>
    <?php if ($adaKmp): $segL = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang']; ?>
    <div class="sec">Komponen Paket &mdash; <?= count($kmpSkp) ?> lokasi</div>
    <table class="pay">
        <tr>
            <td style="width:10%"><strong>Kode</strong></td>
            <td class="lbl" style="width:34%"><strong>Titik / Unit</strong></td>
            <td style="width:16%"><strong>Jenis</strong></td>
            <td style="width:10%"><strong>Lantai</strong></td>
            <td class="amt" style="width:12%"><strong>Luas</strong></td>
            <td class="amt" style="width:18%"><strong>Harga / Periode</strong></td>
        </tr>
        <?php foreach ($kmpSkp as $bi): ?>
        <tr>
            <td><?= $h($bi['master_code'] ?? '-') ?></td>
            <td class="lbl"><?= $h(($bi['name'] ?? '') ?: ($bi['master_code'] ?? '-')) ?></td>
            <td><?= $h($segL[$bi['segment'] ?? ''] ?? ($bi['segment'] ?? '-')) ?></td>
            <td><?= $h(($bi['lantai'] ?? '') !== '' ? $bi['lantai'] : '-') ?></td>
            <td class="amt"><?= angka_satuan($bi['luas'] ?? 0) ?></td>
            <td class="amt"><?= $rp($bi['total'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="grand">
            <td colspan="4">TOTAL <?= count($kmpSkp) ?> lokasi</td>
            <td class="amt"><?= angka_satuan($luasKmp) ?></td>
            <td class="amt"><?= $rp(array_sum(array_map(fn($x) => (float) ($x['total'] ?? 0), $kmpSkp))) ?></td>
        </tr>
    </table>
    <?php /* Nilai per lokasi adalah daftar harga penawaran; yang ditagih adalah
             Total di Rincian Pembayaran Sewa. Disebut supaya pemeriksa tidak
             mengira ada dua harga yang bertentangan. */ ?>
    <div style="font-size:9px;font-style:italic;color:#475569;margin-top:3px">
        Nilai tiap lokasi di atas mengikuti rincian Surat Penawaran<?= !empty($d['bundle_susulan']) ? ' (diambil saat dokumen ini dicetak)' : '' ?>.
        Nilai yang ditagih adalah Total pada Rincian Pembayaran Sewa.
    </div>
    <?php endif; ?>

    <?php /* Dokumen berharga bersih: seluruh baris PPN tidak dicetak. */ ?>
    <?php
    $kenaPpn = !isset($a['kena_ppn']) || !empty($a['kena_ppn']);
    // Huruf urut dibuat berjalan supaya tidak meloncat (a, b, d) saat baris PPN
    // tidak dicetak.
    $abjad = 0;
    $ab = function () use (&$abjad) { return chr(97 + $abjad++) . '. '; };
    /*
     * Harga publish & diskon. Ditulis SEKALI sebagai closure karena baris
     * "Total Biaya Sewa" ada di DUA cabang (ada listrik / tidak) — kalau
     * HTML-nya disalin dua kali, cepat atau lambat salah satunya tertinggal
     * dan separuh dokumen kehilangan keterangan diskonnya.
     *
     * Penjaganya ketat: dokumen lama tidak punya kunci ini sama sekali, dan
     * tanpa penjaga ini ratusan dokumen yang sudah ditandatangani akan
     * mendadak memuat baris "Harga Publish Rp 0 / Diskon 0%".
     *
     * Tanda minus ditulis sebagai teks di luar angka, bukan dikirim ke $rp():
     * "Rp -15.000.000" sulit dibaca, dan tanda minus tipografis tidak ada
     * glyph-nya di font PDF yang dipakai.
     */
    $barisDiskon = function () use ($a, $rp, $h) {
        if (empty($a['publish_on']) || (float) ($a['diskon'] ?? 0) <= 0) return [];
        $pctTxt = angka($a['diskon_pct'] ?? 0);
        // Dokumen yang dibekukan SEBELUM harga publish berbasis tarif tidak punya
        // kunci tarifnya. Dokumen itu harus tercetak persis seperti saat
        // ditandatangani — kalau dipaksa memakai kata-kata baru, yang muncul
        // "tarif Rp 0" di dokumen client.
        $pakaiTarif = (float) ($a['tarif_publish'] ?? 0) > 0 && (float) ($a['tarif_pakai'] ?? 0) > 0;
        if (!$pakaiTarif) {
            return [
                ['label' => 'Harga Publish', 'note' => 'sebelum diskon', 'amount' => (float) $a['publish']],
                ['label' => 'Diskon', 'note' => $h($pctTxt) . '%', 'amount' => (float) $a['diskon'], 'minus' => true],
            ];
        }
        // Nilai sewa SEBELUM diskon, lalu potongannya — berikut tarifnya, supaya
        // client melihat tarif turun dari berapa ke berapa sekaligus berapa
        // rupiah yang dipotong dari nilai sewanya.
        return [
            ['label' => 'Nilai Sewa sebelum diskon', 'note' => 'tarif ' . $rp($a['tarif_publish']), 'amount' => (float) $a['publish']],
            ['label' => 'Diskon', 'note' => $h($pctTxt) . '% &mdash; tarif jadi ' . $rp($a['tarif_pakai']),
             'amount' => (float) $a['diskon'], 'minus' => true],
        ];
    };
    ?>
    <div class="sec">Rincian Pembayaran Sewa</div>
    <table class="pay">
        <tr><td class="lbl">A. Biaya Sewa Area</td><td class="amt"></td></tr>
        <?php
        /*
         * Barisnya DISUSUN DULU sebagai daftar, baru dicetak.
         *
         * Dulu tiap baris ditulis langsung sebagai HTML, dan tidak ada tempat
         * untuk melihat bahwa dua baris bersebelahan ternyata berisi angka yang
         * sama. Pada kontrak 1 bulan hal itu selalu terjadi: tarif per m² per
         * bulan dikalikan luas menghasilkan angka yang sama persis dengan Nilai
         * Sewa, dan surat memuat Rp 14.400.000 dua kali dengan dua nama. Client
         * membacanya sebagai dua tagihan.
         *
         * clara_rincian_rapi() melebur pasangan seperti itu: barisnya tinggal
         * satu, keterangan "(4 m² × Rp 3.600.000)" pindah ke baris yang
         * bertahan. Huruf urut (a, b, c) dibagikan SETELAH peleburan sehingga
         * tidak pernah meloncat.
         *
         * Tidak ada nominal yang diubah — hanya baris mana yang dicetak.
         */
        $barisA  = [];
        $tierSkp = $d['tier_harga'] ?? [];
        $hariSkp = (int) ($d['days'] ?? 0);
        $ptSkp   = (string) ($d['pricing_type'] ?? '');

        if ($tierSkp && $hariSkp > 0) {
            // Tarif bertingkat per m²: dirinci apa adanya seperti di kertas —
            // tiap tingkatan dengan luas, tarif, dan lama harinya sendiri.
            // TIDAK ikut dilebur: dua tingkatan boleh saja bernilai sama persis,
            // dan keduanya memang harus tercetak.
            $luasSkp = 0.0;
            foreach ($tierSkp as $t) {
                $luasSkp += (float) $t['area_sqm'];
                $barisA[] = [
                    'label'  => $h(($t['label'] ?? '') ?: 'Harga Sewa'),
                    'note'   => angka($t['area_sqm']) . ' m² × ' . $rp($t['rate_per_sqm']) . ' × ' . $hariSkp . ' Hari',
                    'amount' => (float) $t['area_sqm'] * (float) $t['rate_per_sqm'] * $hariSkp,
                ];
            }
            $barisA[] = ['label' => 'Total Luas Area', 'note' => 'dasar perhitungan',
                         'teks' => angka($luasSkp) . ' m²', 'amount' => 0.0];
        } else {
            /* Satuannya mengikuti jenis harga kontrak. Sewa bulanan selama ini
               tetap berlabel "per m² per hari" padahal isinya tarif PER BULAN —
               salah baca yang sudah lama dikeluhkan. Dokumen lama tidak
               menyimpan pricing_type, jadi labelnya dibiarkan seperti dulu. */
            $satuanSkp = $ptSkp !== '' ? clara_satuan_harga($ptSkp) : 'Biaya Sewa / m² / hari';
            /* Tarif boleh dikosongkan. Kosong berarti barisnya memang tidak ada
               di surat — bukan "Rp 0", yang terbaca sebagai gratis. */
            if ((float) ($a['rate_m_day'] ?? 0) > 0) {
                $barisA[] = ['label' => $h($satuanSkp), 'amount' => (float) $a['rate_m_day'], 'lebur' => true];
            }
            /* Biaya sewa per BULAN. Hanya untuk kontrak yang memang DIHARGAI per
               bulan. Dokumen yang SUDAH TERBIT dicetak dari snapshotnya; yang
               terbit sebelum baris ini ada tidak punya kunci 'sewa_per_bulan',
               dan cetakan ulangnya harus tetap sama persis seperti saat
               diserahkan ke client. */
            $perBulanSkp = 0.0;
            if (array_key_exists('sewa_per_bulan', (array) $d)) {
                $perBulanSkp = (float) $d['sewa_per_bulan'];
            } elseif (empty($skp['skp_no']) && in_array($ptSkp, ['monthly', 'monthly_area'], true)) {
                $tarifSkp = (float) ($a['rate_m_day'] ?? 0);
                $perBulanSkp = $ptSkp === 'monthly_area'
                    ? $tarifSkp * (float) ($d['area'] ?? 0)
                    : $tarifSkp;
            }
            if ($perBulanSkp > 0) {
                $barisA[] = [
                    'label'  => 'Biaya Sewa / Bulan',
                    'note'   => $ptSkp === 'monthly_area'
                        ? angka($d['area'] ?? 0) . ' m² × ' . $rp($a['rate_m_day'] ?? 0) : '',
                    'amount' => $perBulanSkp,
                    'lebur'  => true,
                ];
            }
        }

        if (($a['listrik'] ?? 0) > 0) {
            $barisA[] = ['label' => 'Nilai Sewa', 'amount' => (float) ($a['sewa'] ?? 0), 'lebur' => true];
            if ($kenaPpn) $barisA[] = ['label' => 'PPN 12% Sewa', 'note' => 'nilai × 11/12 × 12%',
                                       'amount' => (float) ($a['ppn_sewa'] ?? 0)];
            $barisA[] = ['label' => 'Biaya Listrik', 'note' => 'sesuai penawaran', 'amount' => (float) $a['listrik']];
            if ($kenaPpn) $barisA[] = ['label' => 'PPN 12% Listrik', 'note' => 'nilai × 11/12 × 12%',
                                       'amount' => (float) ($a['ppn_listrik'] ?? 0)];
            foreach ($barisDiskon() as $bd) $barisA[] = $bd;
            $barisA[] = ['label' => 'Total Biaya Sewa', 'amount' => (float) ($a['total'] ?? 0), 'lebur' => true];
            if ($kenaPpn) $barisA[] = ['label' => 'Total Biaya Sewa Setelah PPN', 'amount' => (float) ($a['after_ppn'] ?? 0)];
        } else {
            foreach ($barisDiskon() as $bd) $barisA[] = $bd;
            $barisA[] = ['label' => 'Total Biaya Sewa', 'amount' => (float) ($a['total'] ?? 0), 'lebur' => true];
            if ($kenaPpn) {
                $barisA[] = ['label' => 'PPN 12%', 'note' => 'nilai × 11/12 × 12%', 'amount' => (float) ($a['ppn'] ?? 0)];
                $barisA[] = ['label' => 'Total Biaya Sewa Setelah PPN', 'amount' => (float) ($a['after_ppn'] ?? 0)];
            }
        }

        foreach (clara_rincian_rapi($barisA) as $b) {
            $gaya  = !empty($b['minus']) ? ' style="color:#b91c1c"' : '';
            $nilai = array_key_exists('teks', $b)
                ? $b['teks']
                : (!empty($b['minus']) ? '- ' : '') . $rp($b['amount']);
            echo '<tr><td class="lbl"' . $gaya . '>&nbsp;&nbsp;&nbsp;' . $ab() . $b['label']
               . ($b['note'] !== '' ? ' <span class="muted">(' . $b['note'] . ')</span>' : '')
               . '</td><td class="amt"' . $gaya . '>' . $nilai . '</td></tr>';
        }
        ?>
        <?php /* Deposit yang sudah disetor di kontrak sebelumnya tetap dicantumkan
                 sebagai catatan (hijau), tapi tidak ditagih ulang di Grand Total. */ ?>
        <?php $depLunas = !empty($a['deposit_paid']); ?>
        <tr><td class="lbl"<?= $depLunas ? ' style="color:#166534"' : '' ?>>B. Jaminan Area (Security Deposit)</td>
            <td class="amt"<?= $depLunas ? ' style="color:#166534;font-weight:bold"' : '' ?>><?= $rp($a['deposit'] ?? 0) ?><?= $depLunas ? ' (Sudah Dibayarkan)' : '' ?></td></tr>
        <?php /* Service Charge ditagih per bulan — tercetak sebagai blok sendiri
                 (poin C) mengikuti format surat yang dipakai selama ini. */ ?>
        <?php /* Jadwal harga bertahap: tiap tahap tercetak agar perubahan harga
                 di tahun berikutnya tercantum sejak dokumen pertama. */ ?>
        <?php $tahapDoc = $d['tahap_harga'] ?? []; ?>
        <?php if ($tahapDoc): ?>
        <tr><td class="lbl"><strong>Jadwal Harga</strong></td><td class="amt"></td></tr>
        <?php foreach ($tahapDoc as $t): ?>
        <tr><td class="lbl">&nbsp;&nbsp;&nbsp;Mulai <?= $h(date('d/m/Y', strtotime($t['from']))) ?><?= !empty($t['label']) ? ' <span class="muted">(' . $h($t['label']) . ')</span>' : '' ?></td>
            <td class="amt"><?= $rp($t['amount']) ?> <span class="muted">/ bulan</span></td></tr>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php $adaSc = (float) ($a['sc_bulanan'] ?? 0) > 0; ?>
        <?php if ($adaSc): $abjad = 0; ?>
        <tr><td class="lbl">C. Service Charge</td><td class="amt"></td></tr>
        <tr><td class="lbl">&nbsp;&nbsp;&nbsp;<?= $ab() ?>Biaya SC / bulan</td><td class="amt"><?= $rp($a['sc_bulanan']) ?></td></tr>
        <?php if ($kenaPpn): ?>
        <tr><td class="lbl">&nbsp;&nbsp;&nbsp;<?= $ab() ?>PPN 12% <span class="muted">(nilai × 11/12 × 12%)</span></td><td class="amt"><?= $rp($a['sc_ppn_bulan'] ?? 0) ?></td></tr>
        <tr><td class="lbl">&nbsp;&nbsp;&nbsp;<?= $ab() ?>Total SC + PPN / bulan</td><td class="amt"><?= $rp($a['sc_total_bulan'] ?? 0) ?></td></tr>
        <?php endif; ?>
        <tr><td class="lbl">&nbsp;&nbsp;&nbsp;<?= $ab() ?>Total Biaya SC <?= (int) ($a['sc_bulan'] ?? 1) ?> bulan<?= $kenaPpn ? ' + PPN' : '' ?></td><td class="amt"><?= $rp($a['sc_total'] ?? 0) ?></td></tr>
        <?php endif; ?>
        <tr class="grand"><td class="lbl"><?= $adaSc ? 'D. Total Biaya Sewa + SC' . ($kenaPpn ? ' Inc. PPN' : '') : 'C. Grand Total Biaya Area' ?><?= $depLunas ? ' <span class="muted">(di luar Security Deposit)</span>' : '' ?></td><td class="amt"><?= $rp($a['grand_total'] ?? 0) ?></td></tr>
    </table>
    <?php if ($kenaPpn): ?><div class="muted" style="font-size:9px;margin-top:3px">*PPN 12% sesuai PMK Nomor 131 Tahun 2024.</div><?php endif; ?>

    <div class="sec">Note</div>
    <table class="notes">
        <?php foreach ($skpNotes as $i => $n): ?><tr><td class="nn"><?= $i + 1 ?>.</td><td class="nt"><?= $h($n) ?></td></tr><?php endforeach; ?>
    </table>

    <?php endif; ?>

    <div style="text-align:right;margin-top:16px;font-size:10.5px">Balikpapan, <?= $h($skpToday) ?></div>
    <table class="sign"><tr>
        <td class="col"><div class="role"><?= $skpDocType === 'sks' ? 'Hormat Kami,<br><strong>PT. Wulandari Bangun Laksana, Tbk.</strong>' : 'Dibuat Oleh,' ?></div>
            <div class="sigarea"><?php if ($skpHasQr): ?><?php if (empty($skp['sign_token'])): ?><?php /* Pratinjau: kotaknya ada supaya letaknya persis seperti surat asli, tetapi TIDAK memuat QR sungguhan — tokennya memang belum terbit, dan QR yang dipindai lalu gagal lebih buruk daripada tidak ada QR sama sekali. */ ?><table style="width:70px;margin:0 auto 3px;border-collapse:collapse"><tr><td style="width:70px;height:70px;border:1px dashed #cbd5e1;text-align:center;vertical-align:middle;font-size:6.5px;color:#94a3b8;line-height:1.4">QR validasi<br>terbit saat<br>disetujui</td></tr></table><?php elseif (!empty($PDF_MODE)): ?><div class="qrbox"><?= clara_qr_img($skpVerifyUrl, 18) ?></div><?php else: ?><div class="qrbox" data-qr="<?= $h($skpVerifyUrl) ?>"></div><?php endif; ?><div class="qrhint">Scan untuk validasi</div><?php endif; ?></div>
            <div class="name"<?= $skpHasQr ? ' style="border-top:none;padding-top:0"' : '' ?>><?= $h(trim((string) ($d['sales'] ?? '')) ?: '-') ?><br><span <?= $skpJab ?>><?= $h(trim((string) ($d['sales_jabatan'] ?? '')) ?: 'Sales Executive') ?></span></div>
        </td>
        <td class="col"><div class="role"><?= $skpDocType === 'sks' ? 'Menyetujui,' : 'Mengetahui,' ?></div>
            <div class="sigarea"><?php if ($skpHasQr): ?><?php if (empty($skp['sign_token'])): ?><?php /* Pratinjau: kotaknya ada supaya letaknya persis seperti surat asli, tetapi TIDAK memuat QR sungguhan — tokennya memang belum terbit, dan QR yang dipindai lalu gagal lebih buruk daripada tidak ada QR sama sekali. */ ?><table style="width:70px;margin:0 auto 3px;border-collapse:collapse"><tr><td style="width:70px;height:70px;border:1px dashed #cbd5e1;text-align:center;vertical-align:middle;font-size:6.5px;color:#94a3b8;line-height:1.4">QR validasi<br>terbit saat<br>disetujui</td></tr></table><?php elseif (!empty($PDF_MODE)): ?><div class="qrbox"><?= clara_qr_img($skpVerifyUrl, 18) ?></div><?php else: ?><div class="qrbox" data-qr="<?= $h($skpVerifyUrl) ?>"></div><?php endif; ?><div class="qrhint">Scan untuk validasi</div><?php endif; ?></div>
            <div class="name"<?= $skpHasQr ? ' style="border-top:none;padding-top:0"' : '' ?>><?= $h($skpManager) ?><br><span <?= $skpJab ?>>Casual Leasing Manager</span></div>
        </td>
        <td class="col"><div class="role"><?= $skpDocType === 'sks' ? 'Pihak Penyewa,' : ($skpDocType === 'fu' ? 'Pemohon,' : 'Menyetujui,') ?></div>
            <?php if (($skp['status'] ?? '') === 'signed' && !empty($skp['signature_data'])): ?>
            <div class="sigarea"><img src="<?= $h($skp['signature_data']) ?>" alt="TTD" style="max-height:60px;max-width:100%"></div>
            <?php else: ?>
            <?php /* Ruang kosong untuk tanda tangan saat dicetak. mPDF mengabaikan
                     height pada div tapi menghormatinya pada sel tabel, jadi
                     dipakai tabel 1 sel bergaris bawah. Sama seperti di Surat
                     Penawaran (offer_print_template.php). */ ?>
            <table style="width:26mm;border-collapse:collapse;margin-bottom:2mm"><tr>
                <td style="height:20mm;border-bottom:1px solid #111;font-size:1pt;color:#ffffff">&nbsp;</td>
            </tr></table>
            <?php endif; ?>
            <?php if (($skp['status'] ?? '') === 'signed' && !empty($skp['signature_data'])): ?>
                <div class="name"<?= ' style="border-top:none;padding-top:0"' ?>><?= $h($skp['sign_name'] ?: ($d['cp_name'] ?? '-')) ?><br><span <?= $skpJab ?>>Penanggung Jawab</span><br><span class="muted" style="font-weight:normal;font-size:8px"><span style="color:#16a34a">■</span> Ditandatangani elektronik <?= $h(substr($skp['signed_at'] ?? '', 0, 16)) ?></span></div>
            <?php elseif (($skp['sign_method'] ?? '') === 'wet' && ($skp['status'] ?? '') === 'signed'): ?>
                <div class="name"><?= $h($skp['sign_name'] ?: ($d['cp_name'] ?? '-')) ?><br><span <?= $skpJab ?>>Penanggung Jawab</span><br><span class="muted" style="font-weight:normal;font-size:8px"><span style="color:#16a34a">■</span> Ditandatangani basah (scan terlampir)</span></div>
            <?php else: ?>
                <div class="name"><?= $h($d['cp_name'] ?? '-') ?><br><span <?= $skpJab ?>>Penanggung Jawab</span></div>
            <?php endif; ?>
        </td>
    </tr></table>

    <?php /* Jejak pemeriksaan berjenjang: siapa memaraf sebelum disetujui.
             Dicetak di bawah blok tanda tangan — BUKAN kolom keempat — supaya
             lebar tabel tanda tangan dokumen yang sudah terbit tidak berubah.

             GAMBAR parafnya ikut tercetak di sini, bersebelahan dengan nama
             pemiliknya. Dulu gambar itu ditempel menindih ruang kosong di kanan
             QR Manager; di surat ini kolom "Dibuat Oleh" dan "Mengetahui" tidak
             berisi tanda tangan sama sekali — keduanya divalidasi lewat QR —
             sehingga coretan yang menempel di sebelah QR terbaca sebagai TANDA
             TANGAN Manager. Satu-satunya tanda tangan di surat ini milik client
             di kolom "Menyetujui". Dokumen tanpa tahap paraf tidak mencetak
             apa pun. */ ?>
    <?php
    $parafBeku = $d['paraf'] ?? [];
    // Paraf pemeriksa dan paraf penyetuju dipisah: label keduanya berbeda, dan
    // menyebut penyetuju sebagai "pemeriksa" akan keliru di surat yang terbit.
    $barisPeriksa = $barisSetuju = [];
    foreach ($parafBeku as $iPf => $pf) {
        $aksi = (string) ($pf['action'] ?? '');
        $gbr  = (string) ($PARAF_BARIS[$iPf] ?? '');
        if ($aksi !== 'paraf' && !($aksi === 'approve' && $gbr !== '')) continue;
        $baris = ['gambar' => $gbr, 'teks' => $h($pf['nama'] ?: '-')
            . (($pf['role_name'] ?? '') !== '' ? ' (' . $h($pf['role_name']) . ')' : '')
            . (($pf['waktu'] ?? '') !== '' ? ' — ' . $h(date('d/m/Y H:i', strtotime((string) $pf['waktu']))) : '')];
        if ($aksi === 'paraf') $barisPeriksa[] = $baris; else $barisSetuju[] = $baris;
    }
    $cetakParaf = static function (string $judul, array $rows): void {
        if (!$rows) return;
        $adaGambar = false;
        foreach ($rows as $r) if ($r['gambar'] !== '') { $adaGambar = true; break; }
        // page-break-inside:avoid menjaga judul dan seluruh pemeriksanya tetap
        // satu blok. Terbelah halaman, pemeriksa kedua berdiri sendiri di
        // halaman berikutnya tanpa keterangan apa pun tentang dirinya.
        echo '<div style="margin-top:4mm;font-size:8.5px;color:#334155;line-height:1.5;'
           . 'page-break-inside:avoid"><strong>' . $judul . '</strong>';
        if (!$adaGambar) {
            // Tidak seorang pun memasang gambar paraf — cetak sebaris saja,
            // persis seperti sebelum paraf bergambar ada.
            echo ' ' . implode('; ', array_column($rows, 'teks')) . '</div>';
            return;
        }
        // Coretannya SEBARIS dengan judul dan nama pemiliknya — bukan tabel.
        // Tabel di sini pernah dicoba dan salah dua kali: mPDF menciutkan tabel
        // yang hampir tidak muat sampai hurufnya tinggal 4,7pt, dan sel tabel
        // tidak mewarisi huruf surat sehingga baris ini berganti serif sendiri
        // di surat Gudang.
        foreach ($rows as $i => $r) {
            echo ($i > 0 ? '<br>' : ' ')
               . ($r['gambar'] !== '' ? $r['gambar'] . '&nbsp;&nbsp;' : '')
               . $r['teks'];
        }
        echo '</div>';
    };
    // Ada tahap persetujuan tetapi tidak ada satu pun yang memaraf: barisnya
    // tetap dicetak dengan "—", persis seperti sebelumnya, supaya tidak terlihat
    // seolah dokumen ini memang tanpa pemeriksaan. Tidak bergantung pada ada
    // tidaknya paraf penyetuju — itu keterangan yang berbeda.
    if ($parafBeku && !$barisPeriksa) {
        echo '<div style="margin-top:4mm;font-size:8.5px;color:#334155;line-height:1.5">'
           . '<strong>Diperiksa sebelum disetujui:</strong> —</div>';
    }
    $cetakParaf('Diperiksa sebelum disetujui:', $barisPeriksa);
    $cetakParaf('Disetujui dengan paraf:', $barisSetuju);
    ?>

    <?php /* Form Utilities: blok CATATAN berada di bawah tanda tangan, mengikuti
             susunan formulir kertasnya. */ ?>
    <?php if ($skpDocType === 'fu' && !empty($skpTpl['terms'])): ?>
    <div class="sec" style="margin-top:14px">Catatan</div>
    <table class="notes">
        <?php foreach ($skpTpl['terms'] as $i => $t): ?><tr><td class="nn"><?= $i + 1 ?>.</td><td class="nt"><?= $h($t) ?></td></tr><?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>
