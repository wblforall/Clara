<?php
/** Template cetak SKP. Variabel: $skp, $d (snapshot), $a (amounts), $rp, $chk. */
if (!isset($skp)) { http_response_code(400); exit('Konteks tidak valid.'); }
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$notes = [
    'Jika penyewa melakukan pengunduran jadwal dari tanggal masa sewa yang tertulis di kontrak maka akan dikenakan biaya Rp 1.000.000,- di luar total harga sewa pameran.',
    'Batas pengunduran jadwal pameran maksimal 1 bulan dari masa sewa yang tertulis di kontrak awal.',
    'Apabila melebihi batas pengunduran pameran maka pameran dianggap batal dan biaya yang telah dibayarkan penyewa tidak dapat ditarik kembali.',
    'Data peserta pameran (pribadi / perusahaan) harus sesuai dengan yang diberikan kepada pihak Manajemen Mall. Apabila kontrak, invoice dan faktur pajak telah terbit maka data tidak dapat dirubah dengan alasan apapun (kecuali kesalahan penginputan data dari pihak manajemen e-Walk dan Pentacity Mall Balikpapan).',
    'Apabila terdapat perubahan data untuk pameran selanjutnya, peserta pameran wajib memberitahukan perubahan data tersebut kepada pihak manajemen e-Walk dan Pentacity Mall Balikpapan.',
    'Surat Pemesanan ini bersifat mengikat para pihak sebelum dan sesudah diterbitkannya Kontrak Kerjasama.',
    'Wajib mengikuti jam operasional e-Walk dan Pentacity Mall Balikpapan : Hari Senin s.d Minggu — Jam 10.00 s.d 22.00 WITA.',
    'Jam Operasional Mall adalah 10.00 WITA s.d 22.00 WITA yang artinya jam 10.00 WITA tenant sudah diwajibkan beroperasi (bukan persiapan) dan jam 22.00 WITA tenant baru diperbolehkan untuk bersiap-siap menutup toko. Setiap pelanggaran dikenakan denda sebesar Rp 250.000,-. Denda wajib dibayarkan tenant secara tunai pada setiap akhir bulan berjalan (jatuh tempo tidak berlaku bagi tenant yang masa sewanya kurang dari 30 hari kalender).',
];
$today = date('d') . ' ' . ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][(int) date('n')] . ' ' . date('Y');
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<?php $docTitle = ($skp['doc_type'] ?? 'skp') === 'sks' ? 'Surat Konfirmasi Sewa' : 'Surat Konfirmasi Pameran'; ?>
<title><?= $h($skp['skp_no']) ?> — <?= $docTitle ?></title>
<link rel="icon" type="image/png" href="assets/clara-logo.png">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', Arial, sans-serif; font-size: 11px; color: #111; background: #f3f4f6; }
@page { size: A4 portrait; margin: 0; }
/* Kop di dalam thead/tfoot tabel → otomatis berulang & posisinya benar
   (header tiap atas halaman, footer tiap bawah) baik di layar maupun cetak. */
table.paper { width: 100%; border-collapse: collapse; }
table.paper > thead > tr > td, table.paper > tfoot > tr > td, table.paper > tbody > tr > td { padding: 0; }
.sp-top { height: 30mm; background: url('assets/letterhead-a4.jpg') no-repeat top center; background-size: 210mm auto; }
.sp-bot { height: 32mm; background: url('assets/letterhead-a4.jpg') no-repeat bottom center; background-size: 210mm auto; }
.sheet { padding: 0 17mm; }
.no-print { position: fixed; top: 14px; right: 14px; display: flex; gap: 8px; z-index: 99; }
.no-print button { padding: 9px 18px; border: none; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; }
.btn-print { background: #0D9488; color: #fff; } .btn-close { background: #e5e7eb; color: #374151; }
@media screen {
  body { background: #9ca3af; }
  table.paper { width: 210mm; margin: 16px auto; box-shadow: 0 4px 24px rgba(0,0,0,.12); background: #fff; }
}
@media print { .no-print { display: none; } }
* { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.sign { page-break-inside: avoid; }

.doc-title { text-align: center; font-size: 16px; font-weight: 800; letter-spacing: .5px; margin: 0 0 2px; text-transform: uppercase; }
.doc-no { text-align: center; font-size: 11px; color: #555; margin-bottom: 14px; }
.sec { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: #0D9488; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; margin: 14px 0 7px; }
table.kv { width: 100%; border-collapse: collapse; }
table.kv td { padding: 2.5px 0; vertical-align: top; }
table.kv td.l { width: 38%; color: #374151; }
table.kv td.c { width: 3%; }
table.kv td.v { font-weight: 600; }
table.pay { width: 100%; border-collapse: collapse; margin-top: 3px; }
table.pay td { padding: 4px 6px; border: 1px solid #e5e7eb; }
table.pay td.lbl { width: 60%; } table.pay td.amt { text-align: right; font-weight: 600; white-space: nowrap; }
table.pay tr.grand td { background: #f0fdfa; font-weight: 800; color: #0f766e; }
.chk { font-size: 13px; }
.notes { margin-top: 4px; padding-left: 0; }
.notes li { font-size: 9.5px; color: #374151; margin-bottom: 3px; line-height: 1.45; list-style: none; padding-left: 18px; position: relative; }
.notes li span.n { position: absolute; left: 0; font-weight: 700; }
.sign { display: flex; justify-content: space-between; margin-top: 22px; text-align: center; gap: 10px; }
.sign .col { flex: 1; font-size: 10.5px; }
.sign .role { color: #6b7280; margin-bottom: 52px; }
.sign .name { font-weight: 700; border-top: 1px solid #111; padding-top: 3px; display: inline-block; min-width: 150px; }
.qrbox { width: 70px; height: 70px; margin: 0 auto 3px; }
.qrbox img, .qrbox svg { width: 70px !important; height: 70px !important; display: block; }
.qrhint { font-size: 7.5px; color: #6b7280; margin-bottom: 3px; }
.muted { color: #6b7280; }
</style>
</head>
<body>
<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨 Cetak / Simpan PDF</button>
    <button class="btn-close" onclick="window.close()">✕ Tutup</button>
</div>
<table class="paper">
<thead><tr><td><div class="sp-top"></div></td></tr></thead>
<tfoot><tr><td><div class="sp-bot"></div></td></tr></tfoot>
<tbody><tr><td>
<div class="sheet">
    <div class="doc-title"><?= ($skp['doc_type'] ?? 'skp') === 'sks' ? 'Surat Konfirmasi Sewa' : 'Surat Konfirmasi Pameran' ?></div>
    <div class="doc-no">No. <?= $h($skp['skp_no']) ?></div>

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
        $fn = fn($k) => isset($att[$k]) ? ' <span class="muted" style="font-weight:400">(' . $h($att[$k]) . ')</span>' : '';
    ?>
    <div class="sec">Lampiran Dokumen</div>
    <table class="kv">
        <?php if ($hasOffer): ?><tr><td class="l">Surat Penawaran Final</td><td class="c">:</td><td class="v chk">☑ <span class="muted" style="font-weight:400">No. <?= $h($d['offer_no']) ?></span></td></tr><?php endif; ?>
        <tr><td class="l">Copy KTP Penanggung Jawab</td><td class="c">:</td><td class="v chk"><?= $chk(isset($att['ktp']) || ($d['admin_ktp'] ?? 0)) ?><?= $fn('ktp') ?></td></tr>
        <tr><td class="l">Copy NPWP</td><td class="c">:</td><td class="v chk"><?= $chk(isset($att['npwp']) || ($d['admin_npwp'] ?? 0)) ?> <span class="muted" style="font-weight:400"><?= $d['npwp'] ? '(' . $h($d['npwp']) . ')' : '' ?></span><?= $fn('npwp') ?></td></tr>
        <tr><td class="l">Bukti Transfer</td><td class="c">:</td><td class="v chk"><?= $chk(isset($att['bukti_transfer'])) ?><?= $fn('bukti_transfer') ?></td></tr>
        <?php if (isset($att['pengajuan'])): ?><tr><td class="l">Dokumen Pengajuan</td><td class="c">:</td><td class="v chk">☑<?= $fn('pengajuan') ?></td></tr><?php endif; ?>
    </table>
    <?php else: ?>
    <div class="sec">Kelengkapan Administrasi</div>
    <table class="kv">
        <tr><td class="l">Copy SIUP</td><td class="c">:</td><td class="v chk"><?= $chk($d['admin_siup'] ?? 0) ?></td></tr>
        <tr><td class="l">Copy NPWP</td><td class="c">:</td><td class="v chk"><?= $chk($d['admin_npwp'] ?? 0) ?> <span class="muted" style="font-weight:400"><?= $d['npwp'] ? '(' . $h($d['npwp']) . ')' : '' ?></span></td></tr>
        <tr><td class="l">Copy KTP Penanggung Jawab</td><td class="c">:</td><td class="v chk"><?= $chk($d['admin_ktp'] ?? 0) ?></td></tr>
    </table>
    <?php endif; ?>

    <div class="sec">Spesifikasi Tempat & Periode Sewa</div>
    <table class="kv">
        <tr><td class="l">Lokasi</td><td class="c">:</td><td class="v"><?= $h($d['location'] ?? '-') ?></td></tr>
        <tr><td class="l">Lantai</td><td class="c">:</td><td class="v"><?= $h($d['floor'] ?? '-') ?></td></tr>
        <tr><td class="l">Luas Area</td><td class="c">:</td><td class="v"><?= number_format((float)($d['area'] ?? 0), 2, ',', '.') ?> m²</td></tr>
        <?php if (!empty($d['seating_area'])): ?><tr><td class="l">Luas Seating Area</td><td class="c">:</td><td class="v"><?= number_format((float)$d['seating_area'], 2, ',', '.') ?> m²</td></tr><?php endif; ?>
        <tr><td class="l">Masa Sewa</td><td class="c">:</td><td class="v"><?= $h(date('d/m/Y', strtotime($d['start_date'])) . ' s/d ' . date('d/m/Y', strtotime($d['end_date']))) ?> (<?= (int)($d['days'] ?? 0) ?> hari)</td></tr>
        <tr><td class="l">Status Sewa</td><td class="c">:</td><td class="v"><?= $h($d['status_sewa'] ?? '-') ?></td></tr>
        <tr><td class="l">Jenis Usaha / Kegiatan</td><td class="c">:</td><td class="v"><?= $h($d['business_type'] ?? '-') ?></td></tr>
        <tr><td class="l">Produk</td><td class="c">:</td><td class="v"><?= $h($d['produk'] ?? '-') ?></td></tr>
    </table>

    <div class="sec">Rincian Pembayaran Sewa</div>
    <table class="pay">
        <tr><td class="lbl">A. Biaya Sewa Area</td><td class="amt"></td></tr>
        <tr><td class="lbl">&nbsp;&nbsp;&nbsp;a. Biaya Sewa / m² / hari</td><td class="amt"><?= $rp($a['rate_m_day'] ?? 0) ?></td></tr>
        <tr><td class="lbl">&nbsp;&nbsp;&nbsp;b. Total Biaya Sewa</td><td class="amt"><?= $rp($a['total'] ?? 0) ?></td></tr>
        <tr><td class="lbl">&nbsp;&nbsp;&nbsp;c. PPN 12% <span class="muted">(nilai × 11/12 × 12%)</span></td><td class="amt"><?= $rp($a['ppn'] ?? 0) ?></td></tr>
        <tr><td class="lbl">&nbsp;&nbsp;&nbsp;d. Total Biaya Sewa Setelah PPN</td><td class="amt"><?= $rp($a['after_ppn'] ?? 0) ?></td></tr>
        <tr><td class="lbl">B. Jaminan Area (Security Deposit)</td><td class="amt"><?= $rp($a['deposit'] ?? 0) ?></td></tr>
        <tr class="grand"><td class="lbl">C. Grand Total Biaya Area</td><td class="amt"><?= $rp($a['grand_total'] ?? 0) ?></td></tr>
    </table>
    <div class="muted" style="font-size:9px;margin-top:3px">*PPN 12% sesuai PMK Nomor 131 Tahun 2024.</div>

    <div class="sec">Note</div>
    <ol class="notes">
        <?php foreach ($notes as $i => $n): ?><li><span class="n"><?= $i + 1 ?>.</span><?= $h($n) ?></li><?php endforeach; ?>
    </ol>

    <?php
    // URL validasi untuk QR (dibuka saat di-scan). Read-only via sign_token.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $verifyUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/?r=doc_verify&token=' . ($skp['sign_token'] ?? '');
    $hasQr = !empty($skp['sign_token']);
    ?>
    <div style="text-align:right;margin-top:16px;font-size:10.5px">Balikpapan, <?= $h($today) ?></div>
    <div class="sign">
        <div class="col"><div class="role">Dibuat Oleh,</div>
            <?php if ($hasQr): ?><div class="qrbox" data-qr="<?= $h($verifyUrl) ?>"></div><div class="qrhint">Scan untuk validasi</div><?php endif; ?>
            <div class="name"<?= $hasQr ? ' style="border-top:none;padding-top:0"' : '' ?>><?= $h($d['sales'] ?? '-') ?><br><span class="muted" style="font-weight:400">Sales Executive</span></div>
        </div>
        <div class="col"><div class="role">Mengetahui,</div>
            <?php if ($hasQr): ?><div class="qrbox" data-qr="<?= $h($verifyUrl) ?>"></div><div class="qrhint">Scan untuk validasi</div><?php endif; ?>
            <div class="name"<?= $hasQr ? ' style="border-top:none;padding-top:0"' : '' ?>><?= $h($skp['approved_by'] ?? '-') ?><br><span class="muted" style="font-weight:400">Casual Leasing Manager</span></div>
        </div>
        <div class="col"><div class="role">Menyetujui,</div>
            <?php if (($skp['status'] ?? '') === 'signed' && !empty($skp['signature_data'])): ?>
                <div style="margin-bottom:2px"><img src="<?= $h($skp['signature_data']) ?>" alt="TTD" style="max-height:48px;max-width:150px;object-fit:contain"></div>
                <div class="name"><?= $h($skp['sign_name'] ?: ($d['cp_name'] ?? '-')) ?><br><span class="muted" style="font-weight:400">Penanggung Jawab</span><br><span class="muted" style="font-weight:400;font-size:8px">✓ Ditandatangani elektronik <?= $h(substr($skp['signed_at'] ?? '', 0, 16)) ?></span></div>
            <?php else: ?>
                <div class="name"><?= $h($d['cp_name'] ?? '-') ?><br><span class="muted" style="font-weight:400">Penanggung Jawab</span></div>
            <?php endif; ?>
        </div>
    </div>
</div>
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
</body>
</html>
<?php exit; ?>
