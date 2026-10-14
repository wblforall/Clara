<?php
/** Halaman publik tanda tangan customer (Surat Penawaran).
 *  Vars: $o, $d, $a, $signed, $rp, $h, $token. */
if (!isset($o)) { http_response_code(400); exit('Konteks tidak valid.'); }
// PPN mengikuti template yang dibekukan di surat ini, supaya halaman tanda
// tangan berbunyi sama dengan PDF suratnya. Bawaan = perilaku lama (12% + PMK).
$letter = $letter ?? [];
$PPN_PERSEN = (float) ($letter['ppn_persen'] ?? 12);
$PPN_RUMUS  = !isset($letter['ppn_rumus']) || !empty($letter['ppn_rumus']);
$PPN_TARIF  = $PPN_RUMUS ? ($PPN_PERSEN / 100) * 11 / 12 : ($PPN_PERSEN / 100);
$PPN_LABEL  = 'PPN ' . rtrim(rtrim(number_format($PPN_PERSEN, 2, ',', '.'), '0'), ',') . '%';
$PPN_HINT   = $PPN_RUMUS ? ' <span class="muted" style="font-weight:400">(Nilai × 11/12 × ' . $PPN_PERSEN . '%)</span>' : '';
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Tanda Tangan — <?= $h($o['offer_no']) ?></title>
<link rel="icon" type="image/png" href="assets/clara-logo.png">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:Helvetica, Arial, sans-serif;background:#eef2f6;color:#111;font-size:14px;padding:16px}
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
.valid{font-size:12px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:8px 10px;margin-top:8px}
ul.lst,ol.lst{margin:4px 0 0 18px;padding:0}
.lst li{font-size:12.5px;color:#374151;margin-bottom:4px;line-height:1.5;text-align:justify}
.rek{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;margin-top:8px;font-size:12px;line-height:1.7;color:#374151}
/* ── Isi surat (dirender dari template cetak yang sama dengan PDF) ─────────
   Gaya cetaknya sengaja tidak ikut dibawa: ukurannya untuk kertas A4, tidak
   terbaca di HP. Kelas-kelas di bawah ini menata isi yang sama untuk layar. */
.surat{font-size:12.5px;line-height:1.55;color:#111;margin-top:10px}
.surat .meta{margin:10px 0;line-height:1.7}
.surat p{margin:8px 0}
.surat .intro,.surat .closing{text-align:justify}
.surat table.obj{width:100%;border-collapse:collapse;margin:10px 0}
.surat table.obj th,.surat table.obj td{border:1px solid #cbd5e1;padding:6px 8px;text-align:left;font-size:12px;vertical-align:top}
.surat table.obj th{background:#f1f5f9}
.surat table.rinci{width:100%;border-collapse:collapse;margin:10px 0}
.surat table.rinci td{border:1px solid #334155;padding:5px 7px;font-size:12px;vertical-align:top;line-height:1.45}
.surat table.rinci td.no{width:7%;text-align:center;font-weight:700}
.surat table.rinci td.lbl{width:26%}
.surat table.rinci td.sep{width:3%;text-align:center}
.surat table.cost{width:100%;border-collapse:collapse;margin:8px 0}
.surat table.cost td{border:1px solid #e5e7eb;padding:6px 10px;font-size:12px}
.surat table.cost td.lbl{width:58%;color:#374151}
.surat table.cost td.amt{text-align:right;font-weight:700;white-space:nowrap}
.surat table.cost tr.sub td{background:#f8fafc}
.surat table.cost tr.tot td{background:#f1f5f9;font-weight:700}
.surat table.cost tr.grand td{background:#f0fdfa;color:#0f766e;font-weight:800}
.surat .validbox{display:inline-block;background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:8px;padding:5px 10px;font-size:12px;font-weight:600;margin-top:6px}
.surat .secrom{font-weight:800;color:#111;font-size:12.5px;margin:14px 0 5px}
.surat ul,.surat ol{margin:4px 0 0 18px;padding:0}
.surat li{margin-bottom:4px;line-height:1.5;text-align:justify;font-size:12.5px}
.surat .tnc li{color:#374151}
.surat table.sign{width:100%;border-collapse:collapse;margin-top:18px;table-layout:fixed}
.surat table.sign td.col{width:50%;vertical-align:top;padding:0 6px;font-size:12px}
.surat .sigarea{min-height:70px}
.surat .ttd-img{height:58px;width:auto}
.surat .nm{font-weight:700;border-top:1px solid #111;display:inline-block;padding-top:3px;min-width:140px;margin-top:4px}
.surat .qrbox{width:70px;height:70px;margin:6px 0 3px}
.surat .qrbox img,.surat .qrbox svg{width:70px!important;height:70px!important;display:block}
.surat .qrhint{font-size:9px;color:#6b7280}
@media (max-width:560px){
  .surat{font-size:12px}
  .surat table.obj th,.surat table.obj td{padding:5px 6px;font-size:11.5px}
  .surat table.sign td.col{display:block;width:100%;padding:0 0 14px}
}
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="hdr"><img src="assets/skp/logo2.png" alt="e-Walk"><img src="assets/skp/logo1.png" alt="Pentacity"></div>
        <div class="title">Surat Penawaran</div>
        <div class="no">No. <?= $h($o['offer_no']) ?></div>

        <?php /* Isi surat di bawah ini DIRENDER dari berkas yang sama dengan PDF
                 milik PIC (offer_print_template.php). Sebelumnya halaman ini
                 menyusun ulang versinya sendiri yang lebih ringkas, sehingga
                 client menandatangani surat yang isinya berbeda — kolom
                 Keterangan, Media Promosi, jadwal harga, tarif bertingkat, dan
                 nomor rekening milik template semuanya tidak ikut tampil. */ ?>
        <div class="surat"><?= $SURAT_HTML ?? "" ?></div>
    </div>

    <div class="card">
        <div class="sec" style="margin-top:0">Persetujuan & Tanda Tangan</div>
        <?php if ($signed): ?>
            <div class="ok">✓ Penawaran ini sudah disetujui &amp; ditandatangani oleh <strong><?= $h($o['sign_name']) ?></strong><br>pada <?= $h(substr($o['signed_at'], 0, 16)) ?>. Terima kasih.</div>
            <?php if (!empty($o['signature_data'])): ?><div style="text-align:center;margin-top:12px"><img src="<?= $h($o['signature_data']) ?>" alt="TTD" style="max-width:240px;border:1px solid #e5e7eb;border-radius:8px"></div><?php endif; ?>
        <?php else: ?>
            <p class="muted">Mohon bubuhkan tanda tangan Anda di kotak bawah ini (gunakan jari di HP atau mouse di komputer).</p>
            <form method="post" action="?r=offer_sign_save" id="sign-form" style="margin-top:10px">
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
                <div class="consent">Dengan menekan tombol di bawah, saya menyatakan <strong>menyetujui</strong> isi Surat Penawaran ini. Tanda tangan elektronik ini sah dan mengikat sesuai UU ITE. Sistem mencatat nama, waktu, dan alamat IP Anda.</div>
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
    // Kanvas diekspor UTUH akan membawa serta ruang kosong di sekelilingnya, dan
    // ukuran kanvas itu sendiri mengikuti lebar layar penandatangan. Akibatnya
    // tanda tangan yang sama tampil besar di satu dokumen dan kecil di dokumen
    // lain, tergantung alat yang dipakai saat menandatangani. Dipangkas sampai
    // batas coretannya supaya tingginya seragam di semua dokumen.
    function potong(){
        var w=canvas.width,h=canvas.height;
        var d;
        try{ d=ctx.getImageData(0,0,w,h).data; }catch(err){ return canvas.toDataURL('image/png'); }
        var x1=w,y1=h,x2=0,y2=0,ada=false;
        for(var y=0;y<h;y++)for(var x=0;x<w;x++){
            if(d[(y*w+x)*4+3]>8){ ada=true;
                if(x<x1)x1=x; if(x>x2)x2=x; if(y<y1)y1=y; if(y>y2)y2=y; }
        }
        if(!ada) return canvas.toDataURL('image/png');
        var m=Math.round(Math.max(6,h*0.04));
        x1=Math.max(0,x1-m); y1=Math.max(0,y1-m);
        x2=Math.min(w-1,x2+m); y2=Math.min(h-1,y2+m);
        var c=document.createElement('canvas');
        c.width=x2-x1+1; c.height=y2-y1+1;
        c.getContext('2d').drawImage(canvas,x1,y1,c.width,c.height,0,0,c.width,c.height);
        return c.toDataURL('image/png');
    }
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
        document.getElementById('sig-data').value=potong();
    });
})();
</script>
<?php endif; ?>
</body>
</html>
<?php exit; ?>
