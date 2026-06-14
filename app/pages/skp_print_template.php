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
body { font-family: 'Inter', Arial, sans-serif; font-size: 11px; color: #111; background: #fff; }
/* Header: thead tabel → berulang di ATAS tiap halaman.
   Footer: elemen FIXED → menempel di DASAR tiap halaman (ruang via margin bawah). */
@page { size: A4 portrait; margin: 0; }
table.paper { width: 100%; border-collapse: collapse; }
table.paper > thead > tr > td, table.paper > tfoot > tr > td, table.paper > tbody > tr > td { padding: 0; }
.sp-top { height: 30mm; background: url('assets/letterhead-a4.jpg') no-repeat top center; background-size: 100% auto; }
.sp-bot { height: 36mm; } /* tfoot kosong: pesan ruang footer tiap halaman (anti-tabrak) */
.lh-footer { position: fixed; left: 0; bottom: 0; width: 100%; height: 36mm; background: url('assets/letterhead-a4.jpg') no-repeat bottom center; background-size: 100% auto; }
.sheet { padding: 0 17mm; }
.no-print { position: fixed; top: 14px; right: 14px; display: flex; gap: 8px; z-index: 99; }
.no-print button { padding: 9px 18px; border: none; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; }
.btn-print { background: #0D9488; color: #fff; } .btn-close { background: #e5e7eb; color: #374151; }
@media screen {
  body { background: #fff; }
  .lh-footer { display: none; }
  .sp-bot { background: url('assets/letterhead-a4.jpg') no-repeat bottom center; background-size: 100% auto; }
  table.paper { width: 210mm; margin: 16px auto; box-shadow: 0 4px 24px rgba(0,0,0,.12); background: #fff; }
}
@media print { .no-print { display: none; } }
* { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
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
<div class="sheet"><?php include __DIR__ . '/skp_print_body.php'; ?></div>
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
