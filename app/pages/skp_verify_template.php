<?php
/** Halaman validasi dokumen (scan QR). Vars: $skp,$valid,$d,$a,$h,$rp,$salesReg,$mgrReg. */
$docTitle = ($skp['doc_type'] ?? 'skp') === 'sks' ? 'Surat Konfirmasi Sewa' : 'Surat Konfirmasi Pameran';
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Validasi Dokumen — CLARA</title>
<link rel="icon" type="image/png" href="assets/clara-logo.png">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:Helvetica, Arial, sans-serif;background:#eef2f6;color:#111;font-size:14px;padding:16px;min-height:100vh}
.wrap{max-width:540px;margin:24px auto}
.card{background:#fff;border-radius:16px;box-shadow:0 4px 18px rgba(16,24,40,.08);overflow:hidden}
.bar{padding:18px 22px;color:#fff;text-align:center}
.bar.ok{background:#0d9488}.bar.bad{background:#b91c1c}
.bar .big{font-size:19px;font-weight:800;letter-spacing:.3px}
.bar .sub{font-size:12.5px;opacity:.92;margin-top:2px}
.body{padding:20px 22px}
table.kv{width:100%;border-collapse:collapse}
table.kv td{padding:7px 0;vertical-align:top;font-size:13.5px;border-bottom:1px solid #f1f5f9}
table.kv td.l{width:42%;color:#64748b}
table.kv td.v{font-weight:600;text-align:right}
.chip{display:inline-block;font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px}
.chip.ok{background:#dcfce7;color:#166534}.chip.no{background:#f1f5f9;color:#64748b}
.foot{text-align:center;color:#94a3b8;font-size:11.5px;margin-top:14px}
.logo{display:flex;gap:14px;justify-content:center;align-items:center;padding:14px 0 0}
.logo img{height:30px;object-fit:contain}
</style>
</head>
<body>
<div class="wrap">
    <?php if (!$valid): ?>
    <div class="card">
        <div class="bar bad"><div class="big">✕ DOKUMEN TIDAK VALID</div><div class="sub">Tautan/QR tidak dikenali atau dokumen belum disahkan.</div></div>
        <div class="body" style="text-align:center;color:#64748b">Pastikan Anda memindai QR dari dokumen resmi CLARA.</div>
    </div>
    <?php else: ?>
    <div class="card">
        <div class="bar ok">
            <div class="big">✓ DOKUMEN SAH</div>
            <div class="sub">Terverifikasi sistem CLARA — <?= $h($d['property_name'] ?? '') ?></div>
        </div>
        <div class="logo"><img src="assets/skp/logo2.png" alt="e-Walk"><img src="assets/skp/logo1.png" alt="Pentacity"></div>
        <div class="body">
            <table class="kv">
                <tr><td class="l">Jenis Dokumen</td><td class="v"><?= $h($docTitle) ?></td></tr>
                <tr><td class="l">Nomor</td><td class="v"><?= $h($skp['skp_no']) ?></td></tr>
                <tr><td class="l">Penyewa</td><td class="v"><?= $h($d['company_name'] ?? '-') ?></td></tr>
                <tr><td class="l">Periode</td><td class="v"><?= $h(date('d/m/Y', strtotime($d['start_date'] ?? 'now')) . ' s/d ' . date('d/m/Y', strtotime($d['end_date'] ?? 'now'))) ?></td></tr>
                <tr><td class="l">Nilai (Grand Total)</td><td class="v"><?= $rp($a['grand_total'] ?? 0) ?></td></tr>
                <tr><td class="l">Dibuat oleh (Sales)</td><td class="v"><?= $h($d['sales'] ?? '-') ?> <span class="chip <?= $salesReg ? 'ok' : 'no' ?>"><?= $salesReg ? 'TTD terdaftar ✓' : 'tanpa TTD' ?></span></td></tr>
                <tr><td class="l">Disetujui oleh (Manager)</td><td class="v"><?= $h($skp['approved_by'] ?? '-') ?> <span class="chip <?= $mgrReg ? 'ok' : 'no' ?>"><?= $mgrReg ? 'TTD terdaftar ✓' : 'tanpa TTD' ?></span></td></tr>
                <tr><td class="l">Disetujui pada</td><td class="v"><?= $h(substr($skp['approved_at'] ?? '', 0, 16)) ?></td></tr>
                <tr><td class="l">TTD Customer</td><td class="v"><?= ($skp['status'] === 'signed') ? '<span class="chip ok">Sudah (' . $h($skp['sign_name'] ?? '') . ')</span>' : '<span class="chip no">Belum</span>' ?></td></tr>
            </table>
            <div class="foot">Validasi ini menyatakan dokumen terdaftar &amp; nilainya tidak diubah sejak disahkan.</div>
        </div>
    </div>
    <?php endif; ?>
    <p class="foot">CLARA — Casual Leasing Achievement &amp; Revenue Analytics</p>
</div>
</body>
</html>
<?php exit; ?>
