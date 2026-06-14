<?php
/** Template cetak Formulir Permintaan Pembuatan/Review Kontrak. Vars: $cr, $ctx, $prop, $types, $h, $chk. */
if (!isset($cr)) { http_response_code(400); exit('Konteks tidak valid.'); }
$months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$rd = $cr['request_date'] ? strtotime($cr['request_date']) : time();
$tglAju = (int) date('d', $rd) . ' ' . $months[(int) date('n', $rd)] . ' ' . date('Y', $rd);
$typeLabel = $types[$cr['contract_type']] ?? '-';
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title><?= $h($cr['req_no'] ?: 'Formulir Permintaan Kontrak') ?></title>
<link rel="icon" type="image/png" href="assets/clara-logo.png">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',Arial,sans-serif;font-size:11px;color:#111;background:#fff}
@page{size:A4 portrait;margin:0}
table.paper{width:100%;border-collapse:collapse}
table.paper>thead>tr>td,table.paper>tfoot>tr>td,table.paper>tbody>tr>td{padding:0}
.sp-top{height:30mm;background:url('assets/letterhead-a4.jpg') no-repeat top center;background-size:100% auto}
.sp-bot{height:36mm}
.lh-footer{position:fixed;left:0;bottom:0;width:100%;height:36mm;background:url('assets/letterhead-a4.jpg') no-repeat bottom center;background-size:100% auto}
.sheet{padding:0 16mm}
.no-print{position:fixed;top:14px;right:14px;display:flex;gap:8px;z-index:9}
.no-print button{padding:9px 18px;border:none;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer}
.btn-print{background:#0D9488;color:#fff}.btn-close{background:#e5e7eb;color:#374151}
@media screen{body{background:#fff}table.paper{width:210mm;margin:16px auto;box-shadow:0 4px 24px rgba(0,0,0,.12);background:#fff}}
@media print{.no-print{display:none}}
*{-webkit-print-color-adjust:exact;print-color-adjust:exact}
h1{font-size:14px;text-align:center;text-transform:uppercase;letter-spacing:.02em;margin-bottom:2px}
.sub{text-align:center;color:#6b7280;font-size:10.5px;margin-bottom:12px}
.sec{font-weight:800;margin:14px 0 6px;color:#0D9488;text-transform:uppercase;font-size:11px;letter-spacing:.03em}
table.kv{width:100%;border-collapse:collapse}
table.kv td{padding:5px 8px;border:1px solid #e5e7eb;font-size:11px;vertical-align:top}
table.kv td.k{width:34%;color:#374151;background:#f8fafc}
.intro{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;font-size:10.5px;line-height:1.6;color:#374151}
.types{display:flex;gap:22px;margin-top:2px}
.types span{font-weight:600}
table.leg{width:100%;border-collapse:collapse}
table.leg th,table.leg td{border:1px solid #e5e7eb;padding:6px 8px;font-size:10.7px;text-align:left}
table.leg th{background:#f1f5f9}
table.leg td.c{text-align:center;width:13%;font-size:13px}
.points{border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;min-height:70px;font-size:11px;white-space:pre-wrap;line-height:1.6}
.stmt{margin-top:14px;font-size:11px}
.sign{margin-top:8px;display:flex;justify-content:space-between;gap:30px}
.sign .box{flex:1;text-align:center}
.sign .nm{margin-top:48px;border-top:1px solid #111;padding-top:3px;display:inline-block;min-width:170px}
</style>
</head>
<body>
<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨 Cetak / Simpan PDF</button>
    <button class="btn-close" onclick="window.close()">✕ Tutup</button>
</div>
<div class="lh-footer"></div>
<table class="paper">
<thead><tr><td><div class="sp-top"></div></td></tr></thead>
<tfoot><tr><td><div class="sp-bot"></div></td></tr></tfoot>
<tbody><tr><td>
<div class="sheet">
    <h1>Formulir Permintaan Pembuatan/Review Kontrak</h1>
    <div class="sub">Kepada Departemen Legal<?= $cr['req_no'] ? ' &nbsp;·&nbsp; No. ' . $h($cr['req_no']) : '' ?></div>

    <div class="intro">Formulir ini digunakan sebagai dasar bagi Departemen Legal untuk membuat dan/atau mereview kontrak yang diajukan oleh Departemen lain, yang dilampirkan bersamaan dengan <strong>Surat Konfirmasi Pameran</strong> dan/atau <strong>Surat Penawaran</strong>.</div>

    <div class="sec">Informasi Umum</div>
    <table class="kv">
        <tr><td class="k">Departemen Pemohon</td><td><?= $h($cr['department']) ?></td></tr>
        <tr><td class="k">Tanggal Pengajuan</td><td><?= $h($tglAju) ?></td></tr>
        <tr><td class="k">Nama Penanggung Jawab</td><td><?= $h($cr['requester_name'] ?: '-') ?></td></tr>
        <tr><td class="k">Jabatan</td><td><?= $h($cr['requester_position'] ?: '-') ?></td></tr>
        <tr><td class="k">Referensi SKP / Penyewa</td><td><?= $h(($ctx['skp_no'] ?? '-') ?: '-') ?> &nbsp;·&nbsp; <?= $h($ctx['company_name'] ?? '-') ?></td></tr>
    </table>

    <div class="sec">Jenis Kontrak</div>
    <div class="types">
        <span><?= $chk($cr['contract_type'] === 'spk') ?> SPK</span>
        <span><?= $chk($cr['contract_type'] === 'sewa_menyewa') ?> Sewa Menyewa</span>
        <span><?= $chk($cr['contract_type'] === 'kerja_sama') ?> Kerja Sama</span>
    </div>

    <div class="sec">Kelengkapan Dokumen Legalitas</div>
    <table class="leg">
        <thead><tr><th>Jenis Dokumen</th><th class="c">Ada</th><th class="c">Tidak Ada</th></tr></thead>
        <tbody>
        <?php
        $legal = [
            ['Hardcopy Salinan Kartu Identitas/KTP Penanggung Jawab/Direktur/Kuasa Direksi', $cr['doc_ktp']],
            ['Hardcopy NPWP (Pribadi / Perusahaan bila CV/PT/Yayasan/Koperasi/BUMN-D)', $cr['doc_npwp']],
            ['Softcopy Akta Pendirian dan/atau Akta Perubahan (PT/Yayasan/Koperasi/BUMN-D)', $cr['doc_akta']],
            ['Softcopy Surat Kuasa (bila penanda tangan bukan direktur)', $cr['doc_surat_kuasa']],
        ];
        foreach ($legal as [$lbl, $on]): ?>
            <tr><td><?= $h($lbl) ?></td><td class="c"><?= $chk($on) ?></td><td class="c"><?= $chk(!$on) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="sec">Poin-Poin Penting yang Perlu Dimasukkan ke Dalam Kontrak</div>
    <div style="font-size:9.5px;color:#6b7280;margin-bottom:4px">*selain yang tercantum di Surat Konfirmasi Pameran atau hal lain yang perlu diperjelas.</div>
    <div class="points"><?= $h($cr['important_points'] ?: '-') ?></div>

    <div class="stmt">Dengan ini saya menyatakan bahwa informasi yang diberikan adalah benar dan lengkap.</div>
    <div class="sign">
        <div class="box">Departemen Pemohon,<div class="nm"><?= $h($cr['requester_name'] ?: '') ?></div></div>
        <div class="box">Departemen Legal,<div class="nm">&nbsp;</div></div>
    </div>
</div>
</td></tr></tbody>
</table>
</body>
</html>
<?php exit; ?>
