<?php
/** Template cetak Surat Penawaran. Vars: $o (offer+join), $prop, $rp, $h. */
if (!isset($o)) { http_response_code(400); exit('Konteks tidak valid.'); }
$OFFICE_PHONE = '0542-8520555';   // nomor kantor (satu untuk semua properti)
$propShort = ($prop['key'] ?? '') === 'pentacity' ? 'Pentacity' : 'e-Walk';
$months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$od = $o['offer_date'] ? strtotime($o['offer_date']) : time();
$tanggal = (int) date('d', $od) . ' ' . $months[(int) date('n', $od)] . ' ' . date('Y', $od);
$periode = $o['start_date'] ? (date('d/m/Y', strtotime($o['start_date'])) . ' s/d ' . date('d/m/Y', strtotime($o['end_date']))) : '-';
// Durasi nyata: pakai "hari" bila < 28 hari, selain itu "bulan" (+ hari).
$contractMonths = (int) ($o['contract_months'] ?: 1);
$days = ($o['start_date'] && $o['end_date']) ? ((int) floor((strtotime($o['end_date']) - strtotime($o['start_date'])) / 86400) + 1) : 0;
$durasi = ($days > 0 && $days < 28) ? ($days . ' hari') : ($contractMonths . ' bulan' . ($days ? ' · ' . $days . ' hari' : ''));
// Perihal selalu general (basis hari) — periode tanggal sudah tampil terpisah.
$perihal = 'Surat Penawaran Sewa Area Pameran' . ($days > 0 ? ' ' . $days . ' Hari' : '');
// Rincian biaya
$total    = (float) $o['total_calculated'];
$ppn      = round($total * 11 / 12 * 0.12);
$afterPpn = $total + $ppn;
$deposit  = (float) $o['deposit_amount'];
$grand    = $afterPpn + $deposit;
$dpBulan  = rtrim(rtrim(number_format((float) $o['dp_months'], 1, ',', ''), '0'), ',');
$depBulan = rtrim(rtrim(number_format((float) $o['deposit_months'], 1, ',', ''), '0'), ',');
// Masa berlaku penawaran: 7 hari sejak tanggal penawaran
$validTs  = strtotime(($o['offer_date'] ?: date('Y-m-d')) . ' +7 days');
$berlaku  = (int) date('d', $validTs) . ' ' . $months[(int) date('n', $validTs)] . ' ' . date('Y', $validTs);
// Kontak pembayaran: fallback ke kantor bila PIC kosong
$payWa    = $o['pic_phone'] ?: $OFFICE_PHONE;
$ketentuan = offer_terms();
?>
<?php $PDF_MODE = !empty($PDF_MODE); /* diset oleh offer_print() utk jalur mPDF */ ?>
<?php if (!$PDF_MODE): ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($o['offer_no']) ?> — Surat Penawaran</title>
<link rel="icon" type="image/png" href="assets/clara-logo.png">
<?php endif; ?>
<style>
<?php if (!$PDF_MODE): ?>
/* ── Mode LAYAR/print-browser: kop via thead/tfoot (tetap utk pratinjau & fallback). ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',Arial,sans-serif;font-size:11px;color:#111;background:#fff}
@page{size:A4 portrait;margin:0}
table.paper{width:100%;border-collapse:collapse}
table.paper>thead>tr>td,table.paper>tfoot>tr>td,table.paper>tbody>tr>td{padding:0}
.sp-top{height:30mm;background:url('assets/letterhead-a4.jpg') no-repeat top center;background-size:100% auto}
.sp-bot{height:36mm;background:url('assets/letterhead-a4.jpg') no-repeat bottom center;background-size:100% auto}
.sheet{padding:0 16mm}
.no-print{position:fixed;top:14px;right:14px;display:flex;gap:8px;z-index:9}
.no-print button{padding:9px 18px;border:none;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer}
.btn-print{background:#0D9488;color:#fff}.btn-close{background:#e5e7eb;color:#374151}
@media screen{
  body{background:#f3f4f6}
  table.paper{width:210mm;margin:16px auto;box-shadow:0 4px 24px rgba(0,0,0,.12);background:#fff}
}
@media print{.no-print{display:none}}
@media screen and (max-width:820px){
  body{overflow-x:hidden}
  table.paper{margin:0 !important;transform-origin:top left}
}
.pdf-hint{display:none}
@media screen and (max-width:820px){
  .pdf-hint{display:block;position:fixed;left:10px;right:10px;bottom:12px;z-index:9;
    background:#fffbeb;border:1px solid #fcd34d;color:#92400e;font-size:12px;line-height:1.45;
    padding:9px 13px;border-radius:10px;text-align:center;box-shadow:0 4px 16px rgba(0,0,0,.12)}
}
@media print{.pdf-hint{display:none}}
@media print{ table.paper{transform:none !important} body{height:auto !important} }
*{-webkit-print-color-adjust:exact;print-color-adjust:exact}
<?php else: ?>
/* ── Mode PDF (mPDF): kop dipasang via SetHTMLHeader/Footer; di sini CSS KONTEN saja
   (TANPA @page agar tak menimpa margin mPDF; tanpa padding .sheet krn margin sudah diatur). ── */
*{box-sizing:border-box}
body{font-family:'Inter',Arial,sans-serif;font-size:11px;color:#111}
<?php endif; ?>
.sign{page-break-inside:avoid}
.meta{margin-bottom:10px;line-height:1.7}
.meta b{display:inline-block;min-width:70px}
table.obj{width:100%;border-collapse:collapse;margin:10px 0}
table.obj th,table.obj td{border:1px solid #cbd5e1;padding:6px 8px;text-align:left;font-size:11px}
table.obj th{background:#f1f5f9}
table.cost{width:100%;border-collapse:collapse;margin:8px 0}
table.cost td{border:1px solid #e5e7eb;padding:5px 10px;font-size:11px}
table.cost td.lbl{width:62%;color:#374151}
table.cost td.amt{text-align:right;font-weight:600;white-space:nowrap}
table.cost tr.sub td{background:#f8fafc}
table.cost tr.tot td{background:#f1f5f9;font-weight:700}
table.cost tr.grand td{background:#f0fdfa;color:#0f766e;font-weight:800;font-size:11px}
.validbox{display:inline-block;background:#fffbeb;border:1px solid #fcd34d;color:#92400e;border-radius:6px;padding:3px 10px;font-size:11px;font-weight:600;margin-top:4px}
.sec{font-weight:800;margin:14px 0 5px;color:#0D9488;text-transform:uppercase;font-size:11px;letter-spacing:.03em}
ul,ol{margin:0 0 0 18px}
li{margin-bottom:3px;line-height:1.45;text-align:justify}
.intro,.closing{text-align:justify}
.pay li{font-size:11px}
.rek{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;margin-top:6px;font-size:11px;line-height:1.7}
.tnc li{font-size:11px;color:#374151}
.sign{width:100%;border-collapse:collapse;margin-top:22px;page-break-inside:avoid;table-layout:fixed}
.sign td.col{width:50%;vertical-align:top;padding:0 6px}
.sign .sigarea{height:78px}
.sign .ttd-img{height:58px;width:auto;margin:2px 0}
.sign .nm{font-weight:700;border-top:1px solid #111;display:inline-block;padding-top:3px;min-width:170px}
.qrbox{width:70px;height:70px;margin:6px 0 3px}
.qrbox img,.qrbox svg{width:70px!important;height:70px!important;display:block}
.qrhint{font-size:7.5px;color:#6b7280;margin-bottom:3px}
.muted{color:#6b7280}
</style>
<?php if (!$PDF_MODE): ?>
</head>
<body>
<div class="no-print">
    <button class="btn-print" onclick="window.print()" title="Di HP: pilih 'Simpan sebagai PDF' di dialog cetak">🖨 Simpan PDF / Cetak</button>
    <button class="btn-close" onclick="window.close()">✕ Tutup</button>
</div>
<div class="pdf-hint">📄 Ketuk <b>Simpan PDF / Cetak</b> di atas, lalu pilih <b>“Simpan sebagai PDF”</b> sebagai tujuan pada dialog cetak.</div>
<table class="paper">
<thead><tr><td><div class="sp-top"></div></td></tr></thead>
<tfoot><tr><td><div class="sp-bot"></div></td></tr></tfoot>
<tbody><tr><td>
<?php endif; ?>
<div class="sheet">
    <div style="text-align:right;margin-bottom:8px">Balikpapan, <?= $h($tanggal) ?></div>
    <div class="meta">
        <div><b>Nomor</b>: <?= $h($o['offer_no']) ?></div>
        <div><b>Perihal</b>: <?= $h($perihal) ?></div>
    </div>
    <?php
        $addrName  = trim((string)($o['company_name'] ?? '')) ?: '-';
        $addrBrand = trim((string)($o['brand_name'] ?? ''));
        $addrCp    = trim((string)($o['cp_name'] ?? ''));
        // "Up." hanya bila nama kontak berbeda dari nama/brand yang sudah tampil di atas.
        $showUp = $addrCp !== '' && strcasecmp($addrCp, $addrName) !== 0 && strcasecmp($addrCp, $addrBrand) !== 0;
    ?>
    <div class="meta">
        Kepada Yth,<br>
        <strong><?= $h($addrName) ?></strong><?= $addrBrand && strcasecmp($addrBrand, $addrName) !== 0 ? ' — ' . $h($addrBrand) : '' ?><br>
        <?= $showUp ? 'Up. ' . $h($addrCp) . '<br>' : '' ?>
        Di Tempat
    </div>

    <p class="intro" style="margin:8px 0">Dengan hormat,<br>Bersama ini kami Management e-Walk dan Pentacity Mall Balikpapan menawarkan space exhibition sebagai berikut:</p>

    <table class="obj">
        <thead><tr><th>Lokasi</th><th>Luasan</th><th>Harga Sewa / Periode</th><th>Keterangan</th></tr></thead>
        <tbody><tr>
            <td><?= $h(($o['location_name'] ?: $o['master_code']) . ($o['floor'] ? ' (Lt. ' . $o['floor'] . ')' : '')) ?></td>
            <td><?= $o['area_sqm'] ? number_format((float)$o['area_sqm'], 2, ',', '.') . ' m²' : '-' ?></td>
            <td><?= $rp($total) ?></td>
            <td><?= $h($o['keterangan'] ?? '-') ?></td>
        </tr></tbody>
    </table>
    <div style="line-height:1.7;margin-top:4px">
        Masa sewa: <strong><?= $h($durasi) ?></strong> &nbsp;·&nbsp; Periode: <strong><?= $h($periode) ?></strong>
    </div>

    <div class="sec">Rincian Biaya</div>
    <table class="cost">
        <tr><td class="lbl">Harga Sewa / Periode</td><td class="amt"><?= $rp($total) ?></td></tr>
        <tr><td class="lbl">Masa sewa</td><td class="amt"><?= $h($durasi) ?></td></tr>
        <tr class="sub"><td class="lbl">Subtotal sewa</td><td class="amt"><?= $rp($total) ?></td></tr>
        <tr><td class="lbl">PPN 12% <span class="muted" style="font-weight:400">(Nilai × 11/12 × 12%)</span></td><td class="amt"><?= $rp($ppn) ?></td></tr>
        <tr class="tot"><td class="lbl">Total setelah PPN</td><td class="amt"><?= $rp($afterPpn) ?></td></tr>
        <tr><td class="lbl">Security Deposit (dikembalikan 100%)</td><td class="amt"><?= $rp($deposit) ?></td></tr>
        <tr class="grand"><td class="lbl">Grand Total (pembayaran awal + deposit)</td><td class="amt"><?= $rp($grand) ?></td></tr>
    </table>

    <div class="sec">Fasilitas</div>
    <ul><?php foreach (offer_facilities() as $f): ?><li><?= $h($f) ?></li><?php endforeach; ?></ul>

    <div class="sec">Cara Pembayaran</div>
    <ol class="pay">
        <li>Wajib membayar <strong>biaya sewa</strong> senilai <strong><?= $rp($o['dp_amount'] ?: $total) ?></strong> (Exc. PPN 12%) maksimal 1 minggu setelah penawaran disetujui, dan pelunasan paling lambat H-7 sebelum pelaksanaan sewa.</li>
        <li>Wajib membayar <strong>Security Deposit</strong> (uang jaminan) senilai <strong><?= $rp($o['deposit_amount']) ?></strong> sebagai jaminan kerusakan / pengakhiran kontrak sebelum masa sewa berakhir.</li>
        <li>Apabila tidak terjadi kerusakan setelah masa sewa berakhir, Security Deposit dikembalikan 100%.</li>
    </ol>
    <div class="rek">
        Pembayaran ditransfer ke rekening:<br>
        <strong>PT. Wulandari Bangun Laksana</strong> · Bank Rakyat Indonesia (BRI) · No. Rek <strong>2078-01-000560-30-4</strong><br>
        Bukti pembayaran dikirim via WhatsApp ke <strong><?= $h($payWa) ?></strong><?= $o['pic_email'] ? ' atau email <strong>' . $h($o['pic_email']) . '</strong>' : '' ?>.
    </div>

    <div class="sec">Ketentuan &amp; Persyaratan</div>
    <ol class="tnc"><?php foreach ($ketentuan as $t): ?><li><?= $h($t) ?></li><?php endforeach; ?></ol>
    <div class="validbox" style="margin-top:8px">Penawaran ini berlaku s/d <?= $h($berlaku) ?></div>

    <p style="margin-top:12px">Untuk keterangan lebih lanjut dapat menghubungi <strong><?= $h($o['pic_name'] ?: 'tim Casual Leasing') ?></strong><?= $o['pic_phone'] ? ' (' . $h($o['pic_phone']) . ')' : '' ?> atau kantor kami <strong><?= $h($OFFICE_PHONE) ?></strong>.</p>
    <p class="closing" style="margin-top:6px">Demikian surat penawaran ini kami buat. Atas perhatian dan kerjasamanya kami ucapkan terima kasih.</p>

    <?php
    // QR "Scan untuk validasi" pada TTD sales — sama seperti SKP (via sign_token).
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $vdir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $verifyUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $vdir . '/?r=offer_verify&token=' . ($o['sign_token'] ?? '');
    $hasQr = !empty($o['sign_token']);
    ?>
    <?php $custSigned = !empty($o['signed_at']); ?>
    <table class="sign">
    <tr>
        <td class="col">
            <div>Hormat kami,</div>
            <div style="font-weight:600">PT. Wulandari Bangun Laksana, Tbk.</div>
            <div class="sigarea">
                <?php if ($hasQr): ?>
                    <?php if ($PDF_MODE): ?>
                    <div class="qrbox"><?= clara_qr_img($verifyUrl, 18) ?></div><div class="qrhint">Scan untuk validasi</div>
                    <?php else: ?>
                    <div class="qrbox" data-qr="<?= $h($verifyUrl) ?>"></div><div class="qrhint">Scan untuk validasi</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="nm"<?= $hasQr ? ' style="border-top:none;padding-top:0"' : '' ?>><?= $h($o['pic_name'] ?: '-') ?><br><span class="muted" style="font-weight:400">Sales <?= $h($propShort) ?></span></div>
        </td>
        <td class="col">
            <div>Menyetujui,</div>
            <div style="font-weight:600">Calon Penyewa</div>
            <div class="sigarea"><?php if ($custSigned && !empty($o['signature_data'])): ?><img class="ttd-img" height="58" src="<?= $h($o['signature_data']) ?>" alt="TTD"><?php endif; ?></div>
            <?php if ($custSigned): ?>
            <div class="nm" style="border-top:none;padding-top:0"><?= $h($o['sign_name'] ?: ($o['cp_name'] ?? '-')) ?><br><span class="muted" style="font-weight:400">Penanggung Jawab</span><br><span class="muted" style="font-weight:400;font-size:8px"><span style="color:#16a34a">■</span> Ditandatangani elektronik <?= $h(substr($o['signed_at'], 0, 16)) ?></span></div>
            <?php else: ?>
            <div class="nm"><?= $h($o['cp_name'] ?? '') ?: '&nbsp;' ?><br><span class="muted" style="font-weight:400">Penanggung Jawab</span></div>
            <?php endif; ?>
        </td>
    </tr>
    </table>
</div>
<?php if ($PDF_MODE) { return; } /* mode PDF: berhenti di sini, HTML konten ditangkap offer_print() */ ?>
</td></tr></tbody>
</table>
<script src="assets/qrcode.min.js"></script>
<script>
(function () {
    if (typeof qrcode !== 'function') return;
    document.querySelectorAll('.qrbox[data-qr]').forEach(function (box) {
        try {
            var qr = qrcode(0, 'M');
            qr.addData(box.getAttribute('data-qr'));
            qr.make();
            box.innerHTML = qr.createSvgTag({ cellSize: 2, margin: 0, scalable: true });
        } catch (e) {}
    });
})();
</script>
<script>
/* Fit-to-width A4 di layar HP saja (≤820px). Hasil cetak/PDF tak terpengaruh. */
(function () {
    var p = document.querySelector('table.paper');
    if (!p) return;
    function clear() { p.style.transform = ''; document.body.style.height = ''; }
    function fit() {
        clear();
        if (window.innerWidth >= 820) return;
        var w = p.offsetWidth; if (!w) return;
        var s = window.innerWidth / w;
        p.style.transform = 'scale(' + s + ')';
        document.body.style.height = (p.offsetHeight * s) + 'px';
    }
    window.addEventListener('resize', fit);
    window.addEventListener('load', fit);
    // PENTING: hapus skala saat cetak/Simpan PDF agar A4 PENUH (tak mengecil),
    // lalu pulihkan tampilan layar setelah dialog ditutup.
    window.addEventListener('beforeprint', clear);
    window.addEventListener('afterprint', fit);
    if (window.matchMedia) {
        var mq = window.matchMedia('print');
        var onmq = function (e) { if (e.matches) clear(); else fit(); };
        if (mq.addEventListener) mq.addEventListener('change', onmq);
        else if (mq.addListener) mq.addListener(onmq);
    }
    fit();
})();
</script>
</body>
</html>
<?php exit; ?>
