<?php
/** Template cetak Formulir Permintaan Pembuatan/Review Kontrak. Vars: $cr, $ctx, $prop, $types, $h, $chk. */
if (!isset($cr)) { http_response_code(400); exit('Konteks tidak valid.'); }
$months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$rd = $cr['request_date'] ? strtotime($cr['request_date']) : time();
$tglAju = (int) date('d', $rd) . ' ' . $months[(int) date('n', $rd)] . ' ' . date('Y', $rd);
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
@media screen{
  body{background:#fff}
  table.paper{width:210mm;margin:16px auto;box-shadow:0 4px 24px rgba(0,0,0,.12);background:#fff}
  /* Di layar: footer ikut aliran (di akhir konten), tidak fixed menimpa konten. */
  .lh-footer{display:none}
  .sp-bot{background:url('assets/letterhead-a4.jpg') no-repeat bottom center;background-size:100% auto}
}
@media print{.no-print{display:none}}
*{-webkit-print-color-adjust:exact;print-color-adjust:exact}
h1{font-size:13.5px;text-align:center;text-transform:uppercase;letter-spacing:.02em;margin-bottom:2px}
.sub{text-align:center;color:#374151;font-size:10.5px;margin-bottom:14px}
.sec{font-weight:700;margin:16px 0 6px;color:#111;text-transform:uppercase;font-size:11px;letter-spacing:.02em;border-bottom:1.5px solid #111;padding-bottom:3px}
.intro{font-size:10.5px;line-height:1.55;color:#374151;text-align:justify;margin-bottom:8px}
table.kv{width:100%;border-collapse:collapse}
table.kv td{padding:6px 8px;border:1px solid #111;font-size:11px;vertical-align:top}
table.kv td.k{width:38%;color:#111}
.types{display:flex;gap:30px;margin-top:4px;font-size:11px}
.types span{font-weight:600}
table.leg{width:100%;border-collapse:collapse}
table.leg th,table.leg td{border:1px solid #111;padding:6px 8px;font-size:10.5px;text-align:left;vertical-align:middle}
table.leg th{background:#f1f5f9;text-align:center}
table.leg th.j{text-align:left}
table.leg td.c{text-align:center;width:13%;font-size:13px}
table.leg .note{display:block;font-size:8.5px;font-style:italic;color:#6b7280;margin-top:2px}
.points{border:1px solid #111;padding:10px 12px;min-height:66px;font-size:11px;white-space:pre-wrap;line-height:1.55}
.stmt{margin-top:16px;font-size:11px}
.appr{text-align:right;margin-top:6px;font-size:11px}
table.sign{width:100%;border-collapse:collapse;margin-top:2px;text-align:center}
table.sign td{width:50%;font-size:11px;padding-top:6px;vertical-align:top}
.sign .paren{margin-top:56px}
.qrbox img,.qrbox svg{width:100%!important;height:100%!important;display:block}
.sign .qrbox{width:64px;height:64px;margin:6px auto 2px}
.sign .qrbox img,.sign .qrbox svg{width:64px!important;height:64px!important;display:block}
.sign .qrhint{font-size:7.5px;color:#6b7280}
.sign .pnm{margin-top:4px;font-weight:700}
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

    <div class="sec">Informasi Umum</div>
    <div class="intro">Formulir ini digunakan sebagai dasar bagi Departemen Legal untuk membuat dan/atau mereview kontrak yang diajukan oleh Departemen lain yang dilampirkan bersamaan dengan Surat Konfirmasi Pameran dan/atau Surat Penawaran.</div>
    <table class="kv">
        <tr><td class="k">Departemen Pemohon</td><td><?= $h($cr['department']) ?></td></tr>
        <tr><td class="k">Tanggal Pengajuan</td><td><?= $h($tglAju) ?></td></tr>
        <tr><td class="k">Nama Penanggung Jawab</td><td><?= $h($cr['requester_name'] ?: '-') ?></td></tr>
        <tr><td class="k">Jabatan</td><td><?= $h($cr['requester_position'] ?: '-') ?></td></tr>
    </table>

    <div class="sec">Jenis Kontrak</div>
    <div class="types">
        <span><?= $chk($cr['contract_type'] === 'spk') ?> SPK</span>
        <span><?= $chk($cr['contract_type'] === 'sewa_menyewa') ?> Sewa Menyewa</span>
        <span><?= $chk($cr['contract_type'] === 'kerja_sama') ?> Kerja Sama</span>
    </div>

    <div class="sec">Kelengkapan Dokumen Legalitas</div>
    <table class="leg">
        <thead><tr><th class="j">Jenis Dokumen</th><th class="c">Ada</th><th class="c">Tidak Ada</th></tr></thead>
        <tbody>
        <?php
        $legal = [
            ['Hardcopy Salinan Kartu Identitas/KTP Penanggung Jawab/Direktur/Kuasa Direksi', '*Melampirkan Kartu Identitas apabila bukan Warga Negara Indonesia', $cr['doc_ktp']],
            ['Hardcopy NPWP', '*Melampirkan NPWP Perusahaan apabila Pihak Kedua berbentuk CV, PT, Yayasan, Koperasi, BUMN/BUMD', $cr['doc_npwp']],
            ['Softcopy Akta Pendirian dan/atau Akta Perubahan', '*Dilampirkan apabila Pihak Kedua berbentuk PT, Yayasan, Koperasi, BUMN/BUMD', $cr['doc_akta']],
            ['Softcopy Surat Kuasa', '*Dilampirkan hanya apabila Pihak Kedua berbentuk PT, Yayasan, Koperasi, BUMN/BUMD, tetapi yang bertanda tangan bukan direktur', $cr['doc_surat_kuasa']],
        ];
        foreach ($legal as [$lbl, $note, $on]): ?>
            <tr><td><?= $h($lbl) ?><span class="note"><?= $h($note) ?></span></td><td class="c"><?= $chk($on) ?></td><td class="c"><?= $chk(!$on) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="sec">Poin-Poin Penting yang Perlu Dimasukkan ke Dalam Kontrak</div>
    <div style="font-size:9px;color:#6b7280;margin-bottom:4px">*selain yang tercantum di Surat Konfirmasi Pameran atau hal lain yang perlu diperjelas.</div>
    <div class="points"><?= $h($cr['important_points'] ?: '-') ?></div>

    <div class="stmt">Dengan ini saya menyatakan bahwa informasi yang diberikan adalah benar dan lengkap.</div>
    <div class="appr">Disetujui oleh,</div>
    <?php
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $cdir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $verifyUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $cdir . '/?r=contract_legal&token=' . ($cr['share_token'] ?? '');
    $hasQr = !empty($cr['share_token']);
    $legalApproved = ($cr['status'] ?? '') === 'approved';
    ?>
    <table class="sign">
        <tr>
            <td>Departemen Pemohon,
                <?php if ($hasQr): ?><div class="qrbox" data-qr="<?= $h($verifyUrl) ?>"></div><div class="qrhint">Scan untuk validasi</div><?php endif; ?>
                <div class="pnm"><?= $h($cr['requester_name'] ?: '________') ?></div>
            </td>
            <td>Departemen Legal
                <?php if ($legalApproved): ?>
                    <div class="paren" style="margin-top:38px">( <?= $h($cr['legal_by'] ?: 'Disetujui') ?> )</div>
                    <div class="qrhint">✓ Disetujui <?= $h(substr((string) $cr['legal_approved_at'], 0, 16)) ?></div>
                <?php else: ?>
                    <div class="paren">(&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)</div>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <?php
    // ── Lampiran: SKP FINAL ──
    // TTD basah → tampilkan HANYA scan ber-TTD. TTD online → tampilkan dokumen SKP digital.
    if (!empty($skpFinal)):
        $sf = $skpFinal;
        $sfWet = ($sf['sign_method'] ?? '') === 'wet' && ($sf['status'] ?? '') === 'signed' && !empty($sf['signed_doc_path']);
        $scanExt = $sfWet ? strtolower(pathinfo((string) $sf['signed_doc_path'], PATHINFO_EXTENSION)) : '';
        $scanImg = in_array($scanExt, ['jpg', 'jpeg', 'png', 'webp'], true);
    ?>
    <div style="page-break-before:always;padding-top:6mm">
        <?php if ($sfWet): ?>
            <div class="sec">Lampiran: SKP Final (TTD basah) — No. <?= $h($sf['skp_no']) ?></div>
            <?php if ($scanImg): ?>
                <img src="<?= $h($sf['signed_doc_path']) ?>" alt="SKP TTD basah" style="display:block;max-width:100%;max-height:235mm;margin:6px auto 0;object-fit:contain">
            <?php else: ?>
                <div style="border:1px solid #111;padding:12px;font-size:10.5px">Scan SKP ber-TTD basah dalam format <?= $h(strtoupper($scanExt) ?: 'PDF') ?> (<?= $h(basename((string) $sf['signed_doc_path'])) ?>). Tidak dapat disisipkan otomatis ke PDF — buka berkas terpisah.</div>
            <?php endif; ?>
        <?php else: ?>
            <div class="sec">Lampiran: SKP Final — No. <?= $h($sf['skp_no']) ?></div>
            <?php
            // Dokumen SKP utuh (TTD digital tampil di blok "Menyetujui").
            $skp = $sf; $d = $sf['snap']; $a = $d['amounts'] ?? [];
            $rp = $rp ?? fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
            include __DIR__ . '/skp_print_body.php';
            ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php
    // Lampiran dokumen — SEMUA (KTP/NPWP/Bukti dari SKP + Akta/Surat Kuasa).
    foreach (($allAtts ?? []) as [$lbl, $path]):
        $ext = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
        $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
    ?>
    <div style="page-break-before:always;padding-top:6mm">
        <div class="sec">Lampiran: <?= $h($lbl) ?></div>
        <?php if ($isImg): ?>
            <img src="<?= $h($path) ?>" alt="<?= $h($lbl) ?>" style="display:block;max-width:100%;max-height:215mm;margin:0 auto;object-fit:contain">
        <?php else: ?>
            <div style="border:1px solid #111;padding:14px;font-size:11px;color:#374151">Berkas dalam format <strong><?= $h(strtoupper($ext)) ?></strong> (<?= $h(basename((string) $path)) ?>). Tidak dapat disisipkan otomatis ke PDF — silakan buka via tautan/berkas terpisah.</div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
</td></tr></tbody>
</table>
<script src="assets/qrcode.min.js"></script>
<script>
(function () {
    if (typeof qrcode !== 'function') return;
    document.querySelectorAll('.qrbox[data-qr]').forEach(function (box) {
        try { var qr = qrcode(0, 'M'); qr.addData(box.getAttribute('data-qr')); qr.make();
            box.innerHTML = qr.createSvgTag({ cellSize: 2, margin: 0, scalable: true }); } catch (e) {}
    });
})();
</script>
</body>
</html>
<?php exit; ?>
