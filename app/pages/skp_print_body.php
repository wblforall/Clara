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
$skpNotes = skp_terms();
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
$skpHasQr = !empty($skp['sign_token']);
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
    <div class="doc-title"<?= $skpDocType === 'sks' ? ' style="font-weight:bold;text-decoration:underline"' : '' ?>><?= $h($skpTpl['perihal'] ?? '') ?: skp_doc_title($skpDocType) ?></div>
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
    <p>Pada hari ini, <?= $h(skp_hari($d['doc_date'] ?? date('Y-m-d'))) ?> <?= $h(skp_tgl($d['doc_date'] ?? date('Y-m-d'))) ?> dibuat dan ditandatangani <strong><?= $h($skpTpl['perihal'] ?? '') ?: skp_doc_title($skpDocType) ?></strong> oleh dan antara:</p>
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

    <div class="sec">Spesifikasi Tempat &amp; Periode Sewa</div>
    <table class="kv">
        <tr><td class="l">Lokasi</td><td class="c">:</td><td class="v"><?= $h($d['location'] ?? '-') ?></td></tr>
        <tr><td class="l">Lantai</td><td class="c">:</td><td class="v"><?= $h($d['floor'] ?? '-') ?></td></tr>
        <tr><td class="l">Luas Area</td><td class="c">:</td><td class="v"><?= number_format((float) ($d['area'] ?? 0), 2, ',', '.') ?> m²</td></tr>
        <?php if (!empty($d['seating_area'])): ?><tr><td class="l">Luas Seating Area</td><td class="c">:</td><td class="v"><?= number_format((float) $d['seating_area'], 2, ',', '.') ?> m²</td></tr><?php endif; ?>
        <tr><td class="l">Masa Sewa</td><td class="c">:</td><td class="v"><?= $h(date('d/m/Y', strtotime($d['start_date'])) . ' s/d ' . date('d/m/Y', strtotime($d['end_date']))) ?> (<?= (int) ($d['days'] ?? 0) ?> hari)</td></tr>
        <tr><td class="l">Status Sewa</td><td class="c">:</td><td class="v"><?= $h($d['status_sewa'] ?? '-') ?></td></tr>
        <tr><td class="l">Jenis Usaha / Kegiatan</td><td class="c">:</td><td class="v"><?= $h($d['business_type'] ?? '-') ?></td></tr>
        <tr><td class="l">Produk</td><td class="c">:</td><td class="v"><?= $h(($d['produk'] ?? '') ?: ($d['brand_name'] ?? '-')) ?></td></tr>
    </table>

    <?php if (!empty($d['is_bundle']) && !empty($d['bundle_items'])): $segL = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang']; ?>
    <div class="sec">Komponen Paket</div>
    <table class="pay">
        <tr><td class="lbl"><strong>Titik / Unit</strong></td><td class="amt"><strong>Harga / Periode</strong></td></tr>
        <?php foreach ($d['bundle_items'] as $bi): ?>
        <tr><td class="lbl"><?= $h(($bi['name'] ?: $bi['master_code']) . ' · ' . ($segL[$bi['segment']] ?? $bi['segment'])) ?></td><td class="amt"><?= $rp($bi['total'] ?? 0) ?></td></tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <div class="sec">Rincian Pembayaran Sewa</div>
    <table class="pay">
        <tr><td class="lbl">A. Biaya Sewa Area</td><td class="amt"></td></tr>
        <tr><td class="lbl">&nbsp;&nbsp;&nbsp;a. Biaya Sewa / m² / hari</td><td class="amt"><?= $rp($a['rate_m_day'] ?? 0) ?></td></tr>
        <?php if (($a['listrik'] ?? 0) > 0): ?>
        <tr><td class="lbl">&nbsp;&nbsp;&nbsp;b. Nilai Sewa</td><td class="amt"><?= $rp($a['sewa'] ?? 0) ?></td></tr>
        <tr><td class="lbl">&nbsp;&nbsp;&nbsp;c. Biaya Listrik <span class="muted">(sesuai penawaran)</span></td><td class="amt"><?= $rp($a['listrik']) ?></td></tr>
        <tr><td class="lbl">&nbsp;&nbsp;&nbsp;d. Total Biaya Sewa</td><td class="amt"><?= $rp($a['total'] ?? 0) ?></td></tr>
        <?php else: ?>
        <tr><td class="lbl">&nbsp;&nbsp;&nbsp;b. Total Biaya Sewa</td><td class="amt"><?= $rp($a['total'] ?? 0) ?></td></tr>
        <?php endif; ?>
        <?php $_hrf = ($a['listrik'] ?? 0) > 0 ? ['e', 'f'] : ['c', 'd']; ?>
        <tr><td class="lbl">&nbsp;&nbsp;&nbsp;<?= $_hrf[0] ?>. PPN 12% <span class="muted">(nilai × 11/12 × 12%)</span></td><td class="amt"><?= $rp($a['ppn'] ?? 0) ?></td></tr>
        <tr><td class="lbl">&nbsp;&nbsp;&nbsp;<?= $_hrf[1] ?>. Total Biaya Sewa Setelah PPN</td><td class="amt"><?= $rp($a['after_ppn'] ?? 0) ?></td></tr>
        <tr><td class="lbl">B. Jaminan Area (Security Deposit)</td><td class="amt"><?= $rp($a['deposit'] ?? 0) ?></td></tr>
        <tr class="grand"><td class="lbl">C. Grand Total Biaya Area</td><td class="amt"><?= $rp($a['grand_total'] ?? 0) ?></td></tr>
    </table>
    <div class="muted" style="font-size:9px;margin-top:3px">*PPN 12% sesuai PMK Nomor 131 Tahun 2024.</div>

    <div class="sec">Note</div>
    <table class="notes">
        <?php foreach ($skpNotes as $i => $n): ?><tr><td class="nn"><?= $i + 1 ?>.</td><td class="nt"><?= $h($n) ?></td></tr><?php endforeach; ?>
    </table>

    <?php endif; ?>

    <div style="text-align:right;margin-top:16px;font-size:10.5px">Balikpapan, <?= $h($skpToday) ?></div>
    <table class="sign"><tr>
        <td class="col"><div class="role"><?= $skpDocType === 'sks' ? 'Hormat Kami,<br><strong>PT. Wulandari Bangun Laksana, Tbk.</strong>' : 'Dibuat Oleh,' ?></div>
            <div class="sigarea"><?php if ($skpHasQr): ?><?php if (!empty($PDF_MODE)): ?><div class="qrbox"><?= clara_qr_img($skpVerifyUrl, 18) ?></div><?php else: ?><div class="qrbox" data-qr="<?= $h($skpVerifyUrl) ?>"></div><?php endif; ?><div class="qrhint">Scan untuk validasi</div><?php endif; ?></div>
            <div class="name"<?= $skpHasQr ? ' style="border-top:none;padding-top:0"' : '' ?>><?= $h($d['sales'] ?? '-') ?><br><span <?= $skpJab ?>>Sales Executive</span></div>
        </td>
        <td class="col"><div class="role"><?= $skpDocType === 'sks' ? 'Menyetujui,' : 'Mengetahui,' ?></div>
            <div class="sigarea"><?php if ($skpHasQr): ?><?php if (!empty($PDF_MODE)): ?><div class="qrbox"><?= clara_qr_img($skpVerifyUrl, 18) ?></div><?php else: ?><div class="qrbox" data-qr="<?= $h($skpVerifyUrl) ?>"></div><?php endif; ?><div class="qrhint">Scan untuk validasi</div><?php endif; ?></div>
            <div class="name"<?= $skpHasQr ? ' style="border-top:none;padding-top:0"' : '' ?>><?= $h($skp['approved_by'] ?? '-') ?><br><span <?= $skpJab ?>>Casual Leasing Manager</span></div>
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

    <?php /* Form Utilities: blok CATATAN berada di bawah tanda tangan, mengikuti
             susunan formulir kertasnya. */ ?>
    <?php if ($skpDocType === 'fu' && !empty($skpTpl['terms'])): ?>
    <div class="sec" style="margin-top:14px">Catatan</div>
    <table class="notes">
        <?php foreach ($skpTpl['terms'] as $i => $t): ?><tr><td class="nn"><?= $i + 1 ?>.</td><td class="nt"><?= $h($t) ?></td></tr><?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>
