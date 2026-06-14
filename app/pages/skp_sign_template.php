<?php
/** Halaman publik tanda tangan customer. Vars: $skp, $d, $a, $signed, $rp, $h, $token. */
if (!isset($skp)) { http_response_code(400); exit('Konteks tidak valid.'); }
$docTitle = ($skp['doc_type'] ?? 'skp') === 'sks' ? 'Surat Konfirmasi Sewa' : 'Surat Konfirmasi Pameran';
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Tanda Tangan — <?= $h($skp['skp_no']) ?></title>
<link rel="icon" type="image/png" href="assets/clara-logo.png">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',Arial,sans-serif;background:#eef2f6;color:#111;font-size:14px;padding:16px}
.wrap{max-width:680px;margin:0 auto}
.card{background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(16,24,40,.06);padding:20px 22px;margin-bottom:14px}
.hdr{display:flex;align-items:center;justify-content:space-between;border-bottom:2px solid #0D9488;padding-bottom:10px;margin-bottom:6px}
.hdr img{height:34px;width:auto;object-fit:contain}
.title{text-align:center;font-size:16px;font-weight:800;text-transform:uppercase;margin-top:10px}
.no{text-align:center;color:#64748b;font-size:12px;margin-bottom:4px}
.sec{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#0D9488;margin:14px 0 6px;border-bottom:1px solid #e5e7eb;padding-bottom:3px}
table.kv{width:100%;border-collapse:collapse}
table.kv td{padding:3px 0;vertical-align:top;font-size:13px}
table.kv td.l{width:42%;color:#475569}
table.kv td.v{font-weight:600}
table.pay{width:100%;border-collapse:collapse;margin-top:4px;font-size:13px}
table.pay td{padding:5px 8px;border:1px solid #e5e7eb}
table.pay td.amt{text-align:right;font-weight:600;white-space:nowrap}
table.pay tr.grand td{background:#f0fdfa;font-weight:800;color:#0f766e}
.pad-wrap{border:2px dashed #94a3b8;border-radius:12px;background:#fff;touch-action:none}
canvas{display:block;width:100%;height:200px;border-radius:12px}
.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px}
input[type=text]{flex:1;min-width:200px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:9px;font-size:14px}
.btn{padding:11px 18px;border:none;border-radius:9px;font-weight:700;font-size:14px;cursor:pointer}
.btn-primary{background:#0D9488;color:#fff}.btn-clear{background:#f1f5f9;color:#334155}
.ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:14px;border-radius:10px;text-align:center;font-weight:600}
.muted{color:#64748b;font-size:12px}
.consent{font-size:12px;color:#475569;margin-top:10px;line-height:1.5}
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="hdr"><img src="assets/skp/logo2.png" alt="e-Walk"><img src="assets/skp/logo1.png" alt="Pentacity"></div>
        <div class="title"><?= $h($docTitle) ?></div>
        <div class="no">No. <?= $h($skp['skp_no']) ?></div>

        <div class="sec">Identitas Penyewa</div>
        <table class="kv">
            <tr><td class="l">Nama Perusahaan</td><td class="v"><?= $h($d['company_name'] ?? '-') ?></td></tr>
            <tr><td class="l">Penanggung Jawab</td><td class="v"><?= $h($d['cp_name'] ?? '-') ?></td></tr>
        </table>

        <div class="sec">Tempat & Periode</div>
        <table class="kv">
            <tr><td class="l">Lokasi</td><td class="v"><?= $h($d['location'] ?? '-') ?> — Lt. <?= $h($d['floor'] ?? '-') ?></td></tr>
            <tr><td class="l">Luas Area</td><td class="v"><?= number_format((float)($d['area'] ?? 0), 2, ',', '.') ?> m²</td></tr>
            <tr><td class="l">Masa Sewa</td><td class="v"><?= $h(date('d/m/Y', strtotime($d['start_date'])) . ' s/d ' . date('d/m/Y', strtotime($d['end_date']))) ?> (<?= (int)($d['days'] ?? 0) ?> hari)</td></tr>
            <tr><td class="l">Produk</td><td class="v"><?= $h($d['produk'] ?? '-') ?></td></tr>
        </table>

        <div class="sec">Rincian Pembayaran</div>
        <table class="pay">
            <tr><td>Total Biaya Sewa</td><td class="amt"><?= $rp($a['total'] ?? 0) ?></td></tr>
            <tr><td>PPN 12%</td><td class="amt"><?= $rp($a['ppn'] ?? 0) ?></td></tr>
            <tr><td>Jaminan / Security Deposit</td><td class="amt"><?= $rp($a['deposit'] ?? 0) ?></td></tr>
            <tr class="grand"><td>Grand Total</td><td class="amt"><?= $rp($a['grand_total'] ?? 0) ?></td></tr>
        </table>

        <?php
        $att = $d['attachments'] ?? [];
        $labels = ['ktp' => 'Copy KTP', 'npwp' => 'Copy NPWP', 'siup' => 'Copy SIUP', 'bukti_transfer' => 'Bukti Transfer', 'pengajuan' => 'Dokumen Pengajuan'];
        if (!empty($d['offer_no']) || $att): ?>
        <div class="sec">Lampiran Dokumen</div>
        <table class="kv">
            <?php if (!empty($d['offer_no'])): ?><tr><td class="l">Surat Penawaran Final</td><td class="v">☑ No. <?= $h($d['offer_no']) ?></td></tr><?php endif; ?>
            <?php foreach ($labels as $k => $lbl): if (isset($att[$k])): ?>
            <tr><td class="l"><?= $h($lbl) ?></td><td class="v">☑ <span class="muted"><?= $h($att[$k]) ?></span></td></tr>
            <?php endif; endforeach; ?>
        </table>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="sec" style="margin-top:0">Tanda Tangan Penyewa</div>
        <?php if ($signed): ?>
            <div class="ok">✓ Dokumen ini sudah ditandatangani oleh <strong><?= $h($skp['sign_name']) ?></strong><br>pada <?= $h(substr($skp['signed_at'], 0, 16)) ?>. Terima kasih.</div>
            <?php if (!empty($skp['signature_data'])): ?><div style="text-align:center;margin-top:12px"><img src="<?= $h($skp['signature_data']) ?>" alt="TTD" style="max-width:240px;border:1px solid #e5e7eb;border-radius:8px"></div><?php endif; ?>
        <?php else: ?>
            <p class="muted">Mohon bubuhkan tanda tangan Anda di kotak bawah ini (gunakan jari di HP atau mouse di komputer).</p>
            <form method="post" action="?r=skp_sign_save" id="sign-form" style="margin-top:10px">
                <input type="hidden" name="token" value="<?= $h($token) ?>">
                <input type="hidden" name="signature" id="sig-data">
                <div class="pad-wrap"><canvas id="pad"></canvas></div>
                <div class="row">
                    <button type="button" class="btn btn-clear" id="clear">Hapus</button>
                    <span class="muted" id="hint">Tanda tangan di kotak di atas</span>
                </div>
                <div class="row">
                    <input type="text" name="sign_name" id="sign-name" placeholder="Nama lengkap penanda tangan" required>
                </div>
                <div class="consent">Dengan menekan tombol di bawah, saya menyatakan <strong>menyetujui</strong> isi <?= $h($docTitle) ?> ini. Tanda tangan elektronik ini sah dan mengikat sesuai UU ITE. Sistem mencatat nama, waktu, dan alamat IP Anda.</div>
                <div class="row"><button type="submit" class="btn btn-primary" id="submit" style="width:100%">Setujui & Tanda Tangani</button></div>
            </form>
        <?php endif; ?>
    </div>
    <p style="text-align:center" class="muted">Casual Leasing — e-Walk &amp; Pentacity Mall Balikpapan</p>
</div>

<?php if (!$signed): ?>
<script>
(function(){
    var canvas=document.getElementById('pad'),ctx=canvas.getContext('2d'),drawing=false,dirty=false,last=null;
    function resize(){var r=canvas.getBoundingClientRect(),dpr=window.devicePixelRatio||1;canvas.width=r.width*dpr;canvas.height=r.height*dpr;ctx.scale(dpr,dpr);ctx.lineWidth=2.2;ctx.lineCap='round';ctx.strokeStyle='#0f172a';}
    resize();window.addEventListener('resize',function(){var img=canvas.toDataURL();resize();});
    function pos(e){var r=canvas.getBoundingClientRect();var t=e.touches?e.touches[0]:e;return{x:t.clientX-r.left,y:t.clientY-r.top};}
    function start(e){drawing=true;last=pos(e);e.preventDefault();}
    function move(e){if(!drawing)return;var p=pos(e);ctx.beginPath();ctx.moveTo(last.x,last.y);ctx.lineTo(p.x,p.y);ctx.stroke();last=p;dirty=true;e.preventDefault();}
    function end(){drawing=false;}
    canvas.addEventListener('mousedown',start);canvas.addEventListener('mousemove',move);window.addEventListener('mouseup',end);
    canvas.addEventListener('touchstart',start,{passive:false});canvas.addEventListener('touchmove',move,{passive:false});canvas.addEventListener('touchend',end);
    document.getElementById('clear').addEventListener('click',function(){ctx.clearRect(0,0,canvas.width,canvas.height);dirty=false;});
    document.getElementById('sign-form').addEventListener('submit',function(e){
        if(!dirty){e.preventDefault();alert('Mohon bubuhkan tanda tangan terlebih dahulu.');return;}
        if(!document.getElementById('sign-name').value.trim()){e.preventDefault();alert('Mohon isi nama.');return;}
        document.getElementById('sig-data').value=canvas.toDataURL('image/png');
    });
})();
</script>
<?php endif; ?>
</body>
</html>
<?php exit; ?>
