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
// Masa berlaku penawaran: 14 hari sejak tanggal penawaran
$validTs  = strtotime(($o['offer_date'] ?: date('Y-m-d')) . ' +14 days');
$berlaku  = (int) date('d', $validTs) . ' ' . $months[(int) date('n', $validTs)] . ' ' . date('Y', $validTs);
// Kontak pembayaran: fallback ke kantor bila PIC kosong
$payWa    = $o['pic_phone'] ?: $OFFICE_PHONE;
$ketentuan = [
    'Penyewa / peserta pameran dilarang menjual produk yang melanggar Hak Cipta, seperti produk bajakan atau barang palsu.',
    'Wajib menyerahkan design (gambar) booth yang akan digunakan ke pihak Manajemen.',
    'Untuk pemakaian listrik dikenakan sesuai pemakaian dengan harga Rp 3.150/Kwh.',
    'Pemakaian partisi / booth dengan ketinggian max. 1,8 meter (see through / tidak full block).',
    'Pameran wajib menggunakan level kayu dan karpet (disediakan oleh peserta pameran).',
    'Jika penyewa mengundurkan jadwal dari tanggal masa sewa di kontrak, dikenakan biaya Rp 1.000.000,- di luar total harga sewa.',
    'Batas pengunduran jadwal pameran maksimal 1 bulan dari masa sewa di kontrak awal.',
    'Apabila melebihi batas pengunduran, pameran dianggap batal dan pembayaran tidak dapat ditarik kembali.',
    'PPN 11% ditanggung penyewa jika terjadi pembatalan kontrak pameran.',
    'Pengurusan surat keluar masuk di jam operasional kantor (10.00–16.00 WITA).',
    'Data peserta pameran harus sesuai dengan yang diberikan ke manajemen; setelah kontrak/invoice/faktur pajak terbit, data tidak dapat dirubah (kecuali kesalahan input dari manajemen).',
    'Perubahan data untuk pameran selanjutnya wajib diinfokan ke manajemen e-Walk dan Pentacity Mall Balikpapan.',
    'Pemakaian listrik penyambungan wajib memakai kabel NYM 3 x 2,5 mm.',
    'Bersedia mengikuti segala ketentuan dan tata tertib yang berlaku.',
];
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title><?= $h($o['offer_no']) ?> — Surat Penawaran</title>
<link rel="icon" type="image/png" href="assets/clara-logo.png">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',Arial,sans-serif;font-size:11px;color:#111;background:#fff}
/* Kop surat dibelah jadi header & footer (potongan dari 1 gambar A4), masing-masing
   position:fixed → BERULANG tiap halaman saat cetak. Ruang konten via margin halaman. */
/* Header: thead tabel → berulang di ATAS tiap halaman.
   Footer: elemen FIXED → menempel di DASAR tiap halaman (ruang dipesan
   via margin bawah @page). Inilah satu-satunya cara footer selalu di dasar. */
@page{size:A4 portrait;margin:0}
table.paper{width:100%;border-collapse:collapse}
table.paper>thead>tr>td,table.paper>tfoot>tr>td,table.paper>tbody>tr>td{padding:0}
.sp-top{height:30mm;background:url('assets/letterhead-a4.jpg') no-repeat top center;background-size:100% auto}
.sp-bot{height:36mm}/* tfoot kosong: pesan ruang footer tiap halaman (anti-tabrak) */
.lh-footer{position:fixed;left:0;bottom:0;width:100%;height:36mm;background:url('assets/letterhead-a4.jpg') no-repeat bottom center;background-size:100% auto}
.sheet{padding:0 16mm}
.no-print{position:fixed;top:14px;right:14px;display:flex;gap:8px;z-index:9}
.no-print button{padding:9px 18px;border:none;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer}
.btn-print{background:#0D9488;color:#fff}.btn-close{background:#e5e7eb;color:#374151}
@media screen{
  body{background:#fff}
  table.paper{width:210mm;margin:16px auto;box-shadow:0 4px 24px rgba(0,0,0,.12);background:#fff}
}
@media print{.no-print{display:none}}
*{-webkit-print-color-adjust:exact;print-color-adjust:exact}
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
table.cost tr.grand td{background:#f0fdfa;color:#0f766e;font-weight:800;font-size:11.5px}
.validbox{display:inline-block;background:#fffbeb;border:1px solid #fcd34d;color:#92400e;border-radius:6px;padding:3px 10px;font-size:10.5px;font-weight:600;margin-top:4px}
.sec{font-weight:800;margin:14px 0 5px;color:#0D9488;text-transform:uppercase;font-size:11px;letter-spacing:.03em}
ul,ol{margin:0 0 0 18px}
li{margin-bottom:3px;line-height:1.45}
.pay li{font-size:10.5px}
.rek{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;margin-top:6px;font-size:11px;line-height:1.7}
.tnc li{font-size:9.7px;color:#374151}
.sign{margin-top:22px;page-break-inside:avoid}
.sign .ttd-img{display:block;max-height:64px;max-width:200px;object-fit:contain;margin:4px 0}
.sign .nm{font-weight:700;border-top:1px solid #111;display:inline-block;padding-top:3px;min-width:200px}
.muted{color:#6b7280}
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

    <p style="margin:8px 0">Dengan hormat,<br>Bersama ini kami Management e-Walk dan Pentacity Mall Balikpapan menawarkan space exhibition sebagai berikut:</p>

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
    <div class="muted" style="font-size:9.5px">Harga belum termasuk biaya listrik. PPN 12% sesuai PMK No. 131/2024.</div>

    <div class="sec">Fasilitas</div>
    <ul><li>Standar area pameran</li><li>Stop kontak listrik</li><li>Media promosi: media sosial mall &amp; pembagian flyer di area event</li></ul>

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
    <p style="margin-top:6px">Demikian surat penawaran ini kami buat. Atas perhatian dan kerjasamanya kami ucapkan terima kasih.</p>

    <div class="sign">
        <div>Hormat kami,</div>
        <div style="font-weight:600">PT. Wulandari Bangun Laksana, Tbk.</div>
        <?php if (!empty($o['pic_signature'])): ?>
            <img class="ttd-img" src="<?= $h($o['pic_signature']) ?>" alt="Tanda tangan">
        <?php else: ?>
            <div style="height:46px"></div>
        <?php endif; ?>
        <div class="nm"><?= $h($o['pic_name'] ?: '-') ?><br><span class="muted" style="font-weight:400">Sales <?= $h($propShort) ?></span></div>
    </div>
</div>
</td></tr></tbody>
</table>
</body>
</html>
<?php exit; ?>
