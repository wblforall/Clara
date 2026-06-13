<?php
/** Template cetak Surat Penawaran. Vars: $o (offer+join), $prop, $rp, $h. */
if (!isset($o)) { http_response_code(400); exit('Konteks tidak valid.'); }
$OFFICE_PHONE = '0542-8520555';   // nomor kantor (satu untuk semua properti)
$propShort = ($prop['key'] ?? '') === 'pentacity' ? 'Pentacity' : 'e-Walk';
$months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$od = $o['offer_date'] ? strtotime($o['offer_date']) : time();
$tanggal = (int) date('d', $od) . ' ' . $months[(int) date('n', $od)] . ' ' . date('Y', $od);
$periode = $o['start_date'] ? (date('d/m/Y', strtotime($o['start_date'])) . ' s/d ' . date('d/m/Y', strtotime($o['end_date']))) : '-';
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
body{font-family:'Inter',Arial,sans-serif;font-size:11px;color:#111;background:#f3f4f6}
/* Kop surat: header ~28mm, footer ~32mm. Ruang aman via margin halaman. */
@page{size:A4 portrait;margin:28mm 17mm 32mm}
/* Cetak: kop MELAYANG (fixed) → otomatis berulang di tiap halaman. */
.letterhead{position:fixed;top:0;left:0;width:100%;height:100%;
       background:url('assets/letterhead-a4.jpg') top center no-repeat;background-size:100% 100%;z-index:-1}
.sheet{position:relative;z-index:1}
.no-print{position:fixed;top:14px;right:14px;display:flex;gap:8px;z-index:9}
.no-print button{padding:9px 18px;border:none;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer}
.btn-print{background:#0D9488;color:#fff}.btn-close{background:#e5e7eb;color:#374151}
/* Layar: tampilkan sebagai 1 lembar A4 dengan kop sebagai background. */
@media screen{
  body{background:#9ca3af}
  .letterhead{display:none}
  .sheet{width:210mm;min-height:297mm;margin:16px auto;padding:28mm 17mm 32mm;
         background:#fff url('assets/letterhead-a4.jpg') top center no-repeat;background-size:210mm 297mm;
         box-shadow:0 4px 24px rgba(0,0,0,.12)}
}
@media print{.no-print{display:none}}
*{-webkit-print-color-adjust:exact;print-color-adjust:exact}
.sign{page-break-inside:avoid}
.meta{margin-bottom:10px;line-height:1.7}
.meta b{display:inline-block;min-width:70px}
table.obj{width:100%;border-collapse:collapse;margin:10px 0}
table.obj th,table.obj td{border:1px solid #cbd5e1;padding:6px 8px;text-align:left;font-size:11px}
table.obj th{background:#f1f5f9}
.sec{font-weight:800;margin:14px 0 5px;color:#0D9488;text-transform:uppercase;font-size:11px;letter-spacing:.03em}
ul,ol{margin:0 0 0 18px}
li{margin-bottom:3px;line-height:1.45}
.pay li{font-size:10.5px}
.rek{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;margin-top:6px;font-size:11px;line-height:1.7}
.tnc li{font-size:9.7px;color:#374151}
.sign{margin-top:26px}
.sign .nm{font-weight:700;border-top:1px solid #111;display:inline-block;padding-top:3px;margin-top:46px;min-width:170px}
.muted{color:#6b7280}
</style>
</head>
<body>
<div class="letterhead"></div>
<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨 Cetak / Simpan PDF</button>
    <button class="btn-close" onclick="window.close()">✕ Tutup</button>
</div>
<div class="sheet">
    <div style="text-align:right;margin-bottom:8px">Balikpapan, <?= $h($tanggal) ?></div>
    <div class="meta">
        <div><b>Nomor</b>: <?= $h($o['offer_no']) ?></div>
        <div><b>Perihal</b>: <?= $h($o['perihal'] ?: 'Surat Penawaran Sewa') ?></div>
    </div>
    <div class="meta">
        Kepada Yth,<br>
        <strong><?= $h($o['company_name'] ?? '-') ?></strong><?= $o['brand_name'] ? ' — ' . $h($o['brand_name']) : '' ?><br>
        <?= $o['cp_name'] ? 'Up. ' . $h($o['cp_name']) . '<br>' : '' ?>
        Di Tempat
    </div>

    <p style="margin:8px 0">Dengan hormat,<br>Bersama ini kami Management e-Walk dan Pentacity Mall Balikpapan menawarkan space exhibition sebagai berikut:</p>

    <table class="obj">
        <thead><tr><th>Lokasi</th><th>Luasan</th><th>Harga/bulan</th><th>Keterangan</th></tr></thead>
        <tbody><tr>
            <td><?= $h(($o['location_name'] ?: $o['master_code']) . ($o['floor'] ? ' (Lt. ' . $o['floor'] . ')' : '')) ?></td>
            <td><?= $o['area_sqm'] ? number_format((float)$o['area_sqm'], 2, ',', '.') . ' m²' : '-' ?></td>
            <td><?= $rp($o['monthly_amount']) ?></td>
            <td><?= $h($o['keterangan'] ?? '-') ?></td>
        </tr></tbody>
    </table>
    <div style="line-height:1.7">
        Masa sewa: <strong><?= (int)$o['contract_months'] ?> bulan</strong> &nbsp;·&nbsp; Periode: <strong><?= $h($periode) ?></strong><br>
        <?= $o['unit_rate'] ? 'Harga sewa Rp ' . number_format((float)$o['unit_rate'], 0, ',', '.') . '/m/bulan<br>' : '' ?>
        Harga belum termasuk biaya listrik · Harga belum termasuk PPN 12%<br>
        <span class="muted" style="font-size:10px">(PPN 12% sesuai PMK No. 131/2024 dengan perhitungan Nilai Sewa × 11/12 × 12%)</span>
    </div>

    <div class="sec">Fasilitas</div>
    <ul><li>Standar area pameran</li><li>Stop kontak listrik</li><li>Media promosi: media sosial mall &amp; pembagian flyer di area event</li></ul>

    <div class="sec">Cara Pembayaran</div>
    <ol class="pay">
        <li>Wajib membayar <strong><?= rtrim(rtrim(number_format((float)$o['dp_months'],2,',','.'),'0'),',') ?> bulan sewa</strong> senilai <strong><?= $rp($o['dp_amount']) ?></strong> (Exc. PPN 12%) maksimal 1 minggu setelah penawaran disetujui, dan pelunasan H-7 sebelum pelaksanaan sewa.</li>
        <li>Pembayaran tersebut digunakan untuk sewa 1 bulan pertama dan 1 bulan terakhir.</li>
        <li>Wajib membayar Security Deposit (uang jaminan) <strong><?= rtrim(rtrim(number_format((float)$o['deposit_months'],2,',','.'),'0'),',') ?> bulan sewa</strong> senilai <strong><?= $rp($o['deposit_amount']) ?></strong> sebagai jaminan kerusakan / pengakhiran kontrak sebelum masa sewa berakhir.</li>
        <li>Apabila tidak terjadi kerusakan setelah masa sewa berakhir, Security Deposit dikembalikan 100%.</li>
    </ol>
    <div class="rek">
        Pembayaran ditransfer ke rekening:<br>
        <strong>PT. Wulandari Bangun Laksana</strong> · Bank Rakyat Indonesia (BRI) · No. Rek <strong>2078-01-000560-30-4</strong><br>
        Bukti pembayaran di-email ke <strong><?= $h($o['pic_email'] ?: '-') ?></strong> atau WhatsApp ke <strong><?= $h($o['pic_phone'] ?: '-') ?></strong>
    </div>

    <div class="sec">Ketentuan &amp; Persyaratan</div>
    <ol class="tnc"><?php foreach ($ketentuan as $t): ?><li><?= $h($t) ?></li><?php endforeach; ?></ol>

    <p style="margin-top:12px">Untuk keterangan lebih lanjut dapat menghubungi kantor kami <strong><?= $h($OFFICE_PHONE) ?></strong> atau <strong><?= $h($o['pic_name'] ?: '-') ?> <?= $h($o['pic_phone'] ?: '') ?></strong>.</p>
    <p style="margin-top:6px">Demikian surat penawaran ini kami buat. Atas perhatian dan kerjasamanya kami ucapkan terima kasih.</p>

    <div class="sign">
        Hormat kami,<br>PT. Wulandari Bangun Laksana, Tbk.
        <div class="nm"><?= $h($o['pic_name'] ?: '-') ?><br><span class="muted" style="font-weight:400">Sales <?= $h($propShort) ?></span></div>
    </div>
</div>
</body>
</html>
<?php exit; ?>
