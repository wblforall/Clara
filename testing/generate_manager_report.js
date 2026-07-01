const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

const SS = path.join(__dirname, 'screenshots', 'manager');
const OUT = path.join(__dirname, 'Manager_Testing_Report.pdf');

function img(name) {
  const p = path.join(SS, name);
  if (!fs.existsSync(p)) return '';
  const b64 = fs.readFileSync(p).toString('base64');
  return `data:image/png;base64,${b64}`;
}

const html = `<!DOCTYPE html><html><head><meta charset="utf-8">
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;color:#1e293b;font-size:11px;line-height:1.55}
.cover{width:210mm;height:297mm;display:flex;flex-direction:column;justify-content:center;align-items:center;background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 50%,#0d9488 100%);color:#fff;page-break-after:always;text-align:center;padding:40px}
.cover h1{font-size:38px;font-weight:900;letter-spacing:-1px;margin-bottom:12px}
.cover h2{font-size:18px;font-weight:400;opacity:.85;margin-bottom:30px}
.cover .badge{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);border-radius:12px;padding:14px 28px;font-size:13px;margin-bottom:24px}
.cover .meta{font-size:11px;opacity:.6;margin-top:40px}
@page{margin:12mm 18mm 16mm 18mm}
h2.section{font-size:16px;font-weight:800;color:#0f172a;border-bottom:3px solid #0d9488;padding-bottom:6px;margin:20px 0 12px}
h3.sub{font-size:13px;font-weight:700;color:#1e3a5f;margin:14px 0 8px}
p{margin-bottom:8px;text-align:justify}
.screenshot{width:100%;border:1px solid #e2e8f0;border-radius:8px;margin:8px 0 12px;box-shadow:0 2px 8px rgba(0,0,0,.08)}
.result-table{width:100%;border-collapse:collapse;margin:10px 0 16px;font-size:10px}
.result-table th{background:#0f172a;color:#fff;padding:7px 10px;text-align:left;font-weight:600}
.result-table td{padding:6px 10px;border-bottom:1px solid #e2e8f0}
.result-table tr:nth-child(even){background:#f8fafc}
.pass{color:#059669;font-weight:700}.fail{color:#dc2626;font-weight:700}
.bug-table{width:100%;border-collapse:collapse;margin:10px 0;font-size:10px}
.bug-table th{background:#dc2626;color:#fff;padding:6px 8px;text-align:left}
.bug-table td{padding:6px 8px;border-bottom:1px solid #e2e8f0;vertical-align:top}
.sug-table{width:100%;border-collapse:collapse;margin:10px 0;font-size:10px}
.sug-table th{background:#0369a1;color:#fff;padding:6px 8px;text-align:left}
.sug-table td{padding:6px 8px;border-bottom:1px solid #e2e8f0;vertical-align:top}
.stat-box{display:inline-block;padding:12px 20px;border-radius:10px;text-align:center;margin:6px 8px 6px 0;min-width:100px}
.stat-box .num{font-size:28px;font-weight:900}.stat-box .lbl{font-size:9px;text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
.urg-high{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.urg-med{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
.urg-low{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.pb{page-break-before:always}
</style></head><body>

<!-- COVER -->
<div class="cover">
  <div style="font-size:60px;margin-bottom:20px">📋</div>
  <h1>Laporan Pengujian E2E</h1>
  <h2>Peran Manajer (Supervisor)</h2>
  <div class="badge">CLARA — Casual Leasing Achievement & Revenue Analytics<br>Versi 4.21</div>
  <div style="margin-top:20px">
    <div style="font-size:14px;font-weight:600">Properti: E-Walk Simply FUNtastic & Pentacity Shopping Venue</div>
    <div style="font-size:12px;opacity:.7;margin-top:8px">Akun Pengujian: adil@gmail.com (Supervisor)</div>
    <div style="font-size:12px;opacity:.7;margin-top:4px">Tanggal: 29 Juni 2026</div>
  </div>
  <div class="meta">Dokumen ini dihasilkan secara otomatis menggunakan Puppeteer E2E Automation</div>
</div>

<!-- DAFTAR ISI -->
<h2 class="section">Daftar Isi</h2>
<ol style="font-size:12px;line-height:2.2;padding-left:20px">
  <li>Ringkasan Eksekutif & Statistik Pengujian</li>
  <li>Detail Pengujian per Skenario</li>
  <li>Analisis Keberhasilan & Kegagalan</li>
  <li>Audit Temuan: Kerusakan (Bugs)</li>
  <li>Audit Temuan: Saran Peningkatan (Improvements)</li>
  <li>Kesimpulan</li>
</ol>

<!-- RINGKASAN -->
<h2 class="section pb">1. Ringkasan Eksekutif</h2>
<p>Pengujian End-to-End (E2E) ini dilakukan untuk memvalidasi seluruh fungsionalitas yang tersedia bagi peran <strong>Manajer (Supervisor)</strong> pada sistem CLARA. Total <strong>15 skenario</strong> diuji secara otomatis menggunakan Puppeteer headless browser.</p>

<div style="margin:16px 0">
  <div class="stat-box" style="background:#ecfdf5;color:#059669;border:1px solid #a7f3d0"><div class="num">11</div><div class="lbl">Berhasil (Pass)</div></div>
  <div class="stat-box" style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca"><div class="num">2</div><div class="lbl">Gagal (Fail)</div></div>
  <div class="stat-box" style="background:#fffbeb;color:#92400e;border:1px solid #fde68a"><div class="num">2</div><div class="lbl">Temuan Bug</div></div>
  <div class="stat-box" style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe"><div class="num">73%</div><div class="lbl">Tingkat Keberhasilan</div></div>
</div>

<table class="result-table">
<tr><th>#</th><th>Skenario</th><th>Status</th><th>Keterangan</th></tr>
<tr><td>1</td><td>Login Manajer</td><td class="pass">✓ PASS</td><td>Login berhasil, dashboard tampil</td></tr>
<tr><td>2</td><td>SKP Reject (ID 19)</td><td class="pass">✓ PASS</td><td>SKP berhasil ditolak dengan catatan</td></tr>
<tr><td>3</td><td>SKP Approve (ID 23)</td><td class="fail">✗ FAIL</td><td>Error SQL: kolom sign_token_expires_at tidak ditemukan</td></tr>
<tr><td>4a</td><td>Akses Form Kontrak</td><td class="pass">✓ PASS</td><td>URL Legal diperoleh</td></tr>
<tr><td>4b</td><td>Legal Approve Kontrak</td><td class="pass">✓ PASS</td><td>Kontrak disetujui Legal</td></tr>
<tr><td>5</td><td>Batalkan Transaksi</td><td class="pass">✓ PASS</td><td>Transaksi dibatalkan dengan alasan</td></tr>
<tr><td>6</td><td>Audit Log</td><td class="fail">✗ FAIL</td><td>HTTP 403 — Akses ditolak untuk role Manajer</td></tr>
<tr><td>7a</td><td>Laporan PIC</td><td class="pass">✓ PASS</td><td>Halaman tampil dengan data lengkap</td></tr>
<tr><td>7b</td><td>Performa PIC</td><td class="pass">✓ PASS</td><td>Grafik performa tampil</td></tr>
<tr><td>7c</td><td>Pipeline PIC</td><td class="pass">✓ PASS</td><td>Pipeline funnel tampil</td></tr>
<tr><td>7d</td><td>Rewarding PIC</td><td class="pass">✓ PASS</td><td>Halaman tampil, form simpan tidak tersedia (sesuai permission)</td></tr>
<tr><td>8a</td><td>Executive Dashboard</td><td class="pass">✓ PASS</td><td>Revenue, target, occupancy tampil</td></tr>
<tr><td>8b</td><td>TV Display</td><td class="pass">✓ PASS</td><td>Tampilan TV tanpa login</td></tr>
<tr><td>9</td><td>Renewal Kontrak</td><td class="pass">✓ PASS</td><td>71 kartu renewal tampil (read-only untuk Manajer)</td></tr>
<tr><td>10</td><td>Simulasi Komisi</td><td class="pass">✓ PASS</td><td>Kalkulator tampil dengan data komisi per PIC</td></tr>
</table>

<!-- DETAIL PENGUJIAN -->
<h2 class="section pb">2. Detail Pengujian per Skenario</h2>

<h3 class="sub">2.1 Login Manajer</h3>
<p>Pengguna berhasil login menggunakan akun <code>adil@gmail.com</code> dengan peran <strong>Supervisor</strong>. Setelah login, pengguna diarahkan ke halaman pemilihan properti, kemudian ke dashboard utama.</p>
<img class="screenshot" src="${img('01_login_dashboard.png')}" alt="Dashboard">

<h3 class="sub">2.2 SKP Reject (ID 19)</h3>
<p>Manajer membuka halaman detail SKP ID 19 yang berstatus <em>Submitted</em>. Panel "Approval Manager" tampil dengan tombol Setujui dan Tolak. Catatan penolakan diisi, lalu form dikirim. Setelah submit, status SKP berubah menjadi <em>Ditolak</em>.</p>
<img class="screenshot" src="${img('02_skp_reject_before.png')}" alt="Before Reject">
<img class="screenshot" src="${img('03_skp_reject_after.png')}" alt="After Reject">

<h3 class="sub pb">2.3 SKP Approve (ID 23) — GAGAL</h3>
<p>Manajer membuka SKP ID 23 dan mencoba menyetujui. Sistem menampilkan pesan error SQL: <em>"Column not found: 1054 Unknown column 'sign_token_expires_at' in 'field list'"</em>. Ini menunjukkan kolom database <code>sign_token_expires_at</code> belum ditambahkan ke tabel <code>skp_documents</code>.</p>
<img class="screenshot" src="${img('04_skp_approve_before.png')}" alt="Before Approve">
<img class="screenshot" src="${img('05_skp_approve_after.png')}" alt="After Approve Error">

<h3 class="sub">2.4 Permintaan Kontrak & Legal Approve</h3>
<p>Manajer membuka form permintaan kontrak ID 2 dan memperoleh URL Legal Share. Halaman legal terbuka dengan form persetujuan. Setelah disetujui, status kontrak berubah menjadi <em>Disetujui Legal</em>.</p>
<img class="screenshot" src="${img('06_contract_request.png')}" alt="Contract Request">
<img class="screenshot" src="${img('08_legal_approved.png')}" alt="Legal Approved">

<h3 class="sub pb">2.5 Pembatalan Transaksi</h3>
<p>Manajer membuka detail penawaran dan mengklik tombol pembatalan transaksi. Alasan pembatalan diisi. Proses pembatalan berhasil dilakukan.</p>
<img class="screenshot" src="${img('09_cancel_before.png')}" alt="Cancel Before">
<img class="screenshot" src="${img('10_cancel_after.png')}" alt="Cancel After">

<h3 class="sub">2.6 Audit Log — GAGAL</h3>
<p>Ketika Manajer mengakses halaman Audit Log (<code>?r=audit</code>), sistem mengembalikan HTTP 403 dengan pesan <em>"Akses ditolak untuk role Anda"</em>. Manajer tidak memiliki permission <code>view_audit</code>.</p>
<img class="screenshot" src="${img('11_audit_log.png')}" alt="Audit Log 403">

<h3 class="sub">2.7 Laporan & Pengawasan Tim</h3>
<p>Semua halaman laporan PIC dapat diakses oleh Manajer: Laporan PIC, Performa PIC, Pipeline PIC, dan Rewarding PIC. Pada halaman Rewarding PIC, form penyimpanan target/reward tidak tersedia — sesuai dengan konfigurasi permission yang membatasi aksi simpan hanya untuk Superadmin dan Admin.</p>
<img class="screenshot" src="${img('12_pic_report.png')}" alt="PIC Report">
<img class="screenshot" src="${img('14_pic_pipeline.png')}" alt="PIC Pipeline">
<img class="screenshot" src="${img('15_pic_reward.png')}" alt="PIC Reward">

<h3 class="sub pb">2.8 Executive Dashboard & TV Display</h3>
<p>Dashboard eksekutif menampilkan data revenue, target, dan occupancy. Halaman TV Display berhasil dibuka tanpa memerlukan login ulang.</p>
<img class="screenshot" src="${img('16_exec_dashboard.png')}" alt="Exec Dashboard">
<img class="screenshot" src="${img('17_tv_display.png')}" alt="TV Display">

<h3 class="sub">2.9 Renewal Kontrak</h3>
<p>Halaman Renewal Kontrak menampilkan 71 kartu kontrak yang mendekati jatuh tempo. Namun, Manajer tidak dapat mengubah status renewal karena tidak memiliki permission <code>manage_renewals</code>. Form select dan tombol submit tidak ditampilkan.</p>
<img class="screenshot" src="${img('18_renewals.png')}" alt="Renewals">

<h3 class="sub">2.10 Simulasi Komisi</h3>
<p>Halaman Simulasi Komisi PIC tampil dengan data komisi lengkap per role (Sales, Manager, Asst. Manager, Admin). Total revenue, target, achievement, dan breakdown per PIC ditampilkan secara akurat.</p>
<img class="screenshot" src="${img('20_commission_sim.png')}" alt="Commission Sim">

<!-- ANALISIS -->
<h2 class="section pb">3. Analisis Keberhasilan & Kegagalan</h2>
<p>Dari 15 skenario yang diuji, <strong>11 skenario (73%) berhasil</strong> dan <strong>2 skenario gagal</strong>. Dua temuan lainnya merupakan keterbatasan permission yang <em>by design</em> (Rewarding PIC read-only, Renewal Kontrak read-only).</p>

<h3 class="sub">Skenario Gagal #1: SKP Approve (ID 23)</h3>
<table class="bug-table">
<tr><th style="width:25%">Item</th><th>Detail</th></tr>
<tr><td><strong>Ekspektasi</strong></td><td>Manajer mengklik Setujui → nomor SKP terbit otomatis, transaksi & alokasi dibuat</td></tr>
<tr><td><strong>Realita</strong></td><td>Sistem menampilkan error SQL: <em>Unknown column 'sign_token_expires_at'</em>. SKP tidak disetujui.</td></tr>
<tr><td><strong>Akar Masalah</strong></td><td>Kolom <code>sign_token_expires_at</code> direferensikan oleh fungsi <code>skp_approve()</code> di <code>skp.php</code> tetapi belum ada di tabel <code>skp_documents</code> pada database yang digunakan.</td></tr>
<tr><td><strong>Dampak</strong></td><td>Seluruh alur persetujuan SKP tidak dapat berjalan. Ini memblokir penerbitan nomor SKP, pembuatan transaksi otomatis, dan alur downstream lainnya.</td></tr>
</table>

<h3 class="sub">Skenario Gagal #2: Audit Log</h3>
<table class="bug-table">
<tr><th style="width:25%">Item</th><th>Detail</th></tr>
<tr><td><strong>Ekspektasi</strong></td><td>Manajer dapat melihat log audit untuk memantau aktivitas tim</td></tr>
<tr><td><strong>Realita</strong></td><td>HTTP 403 — "Akses ditolak untuk role Anda"</td></tr>
<tr><td><strong>Akar Masalah</strong></td><td>Permission <code>view_audit</code> tidak diberikan ke role <code>supervisor</code> di <code>permission_matrix()</code></td></tr>
<tr><td><strong>Dampak</strong></td><td>Manajer tidak dapat melakukan pengawasan melalui log aktivitas sistem</td></tr>
</table>

<!-- AUDIT: BUGS -->
<h2 class="section pb">4. Audit Temuan: Kerusakan Fungsional (Bugs)</h2>
<table class="bug-table">
<tr><th>#</th><th>Deskripsi</th><th>Urgensi</th><th>Dampak</th><th>Rekomendasi</th></tr>
<tr>
  <td>BUG-01</td>
  <td><strong>SKP Approve gagal — kolom sign_token_expires_at tidak ditemukan</strong><br>Fungsi <code>skp_approve()</code> mereferensikan kolom yang belum ada di database dump saat ini.</td>
  <td><span class="urg-high" style="padding:2px 8px;border-radius:4px;font-size:9px;font-weight:700">KRITIS</span></td>
  <td>Seluruh alur approval SKP terblokir. Tidak ada nomor SKP yang bisa terbit.</td>
  <td>Jalankan migrasi: <code>ALTER TABLE skp_documents ADD COLUMN sign_token_expires_at DATETIME NULL;</code></td>
</tr>
<tr>
  <td>BUG-02</td>
  <td><strong>Audit Log tidak dapat diakses oleh Manajer</strong><br>Route <code>?r=audit</code> membutuhkan permission yang tidak dimiliki role supervisor.</td>
  <td><span class="urg-med" style="padding:2px 8px;border-radius:4px;font-size:9px;font-weight:700">SEDANG</span></td>
  <td>Manajer tidak bisa memantau aktivitas sistem dan tim melalui audit trail.</td>
  <td>Tambahkan <code>'view_audit'</code> ke array permission role <code>supervisor</code> di <code>helpers.php</code></td>
</tr>
</table>

<!-- AUDIT: SUGGESTIONS -->
<h2 class="section pb">5. Audit Temuan: Saran Peningkatan (Improvements)</h2>
<table class="sug-table">
<tr><th>#</th><th>Deskripsi</th><th>Urgensi</th><th>Alasan</th></tr>
<tr>
  <td>SUG-01</td>
  <td><strong>Renewal Kontrak — Manajer tidak bisa mengubah status tindak lanjut</strong><br>Manajer hanya bisa melihat papan renewal tanpa bisa memperbarui status (contacted, nego, dll).</td>
  <td><span class="urg-med" style="padding:2px 8px;border-radius:4px;font-size:9px;font-weight:700">SEDANG</span></td>
  <td>Manajer perlu memantau dan menindaklanjuti renewal aktif sebagai bagian dari tugas operasional harian. Pertimbangkan menambahkan permission <code>manage_renewals</code> untuk role supervisor.</td>
</tr>
<tr>
  <td>SUG-02</td>
  <td><strong>Rewarding PIC — Manajer tidak bisa menyimpan target/reward</strong><br>Form input target disembunyikan dari role supervisor, hanya tersedia untuk superadmin/admin.</td>
  <td><span class="urg-low" style="padding:2px 8px;border-radius:4px;font-size:9px;font-weight:700">RENDAH</span></td>
  <td>Jika kebijakan bisnis mengizinkan Manajer mengatur target PIC, tambahkan permission yang sesuai. Jika hanya admin yang berwenang, ini sudah benar.</td>
</tr>
<tr>
  <td>SUG-03</td>
  <td><strong>Tombol "Cetak/Ajukan" pada Simulasi Komisi tidak memiliki ID unik</strong><br>Tidak ada elemen <code>#calc-btn</code> di halaman; tombol menggunakan class atau inline event.</td>
  <td><span class="urg-low" style="padding:2px 8px;border-radius:4px;font-size:9px;font-weight:700">RENDAH</span></td>
  <td>Untuk mendukung automated testing dan aksesibilitas, tambahkan ID unik pada elemen interaktif kunci.</td>
</tr>
</table>

<!-- KESIMPULAN -->
<h2 class="section pb">6. Kesimpulan</h2>
<p>Pengujian E2E peran Manajer menunjukkan bahwa <strong>sebagian besar fungsionalitas inti berjalan dengan baik</strong> (73% skenario berhasil). Fitur-fitur seperti penolakan SKP, persetujuan kontrak legal, pembatalan transaksi, laporan PIC, executive dashboard, TV display, renewal kontrak, dan simulasi komisi semuanya dapat diakses dan berfungsi sesuai harapan.</p>

<p>Terdapat <strong>2 kerusakan fungsional</strong> yang perlu diperbaiki:</p>
<ol style="padding-left:20px;margin:8px 0">
  <li><strong>BUG-01 (KRITIS):</strong> SKP Approve gagal akibat kolom database <code>sign_token_expires_at</code> yang belum ada. Ini memblokir seluruh alur persetujuan SKP.</li>
  <li><strong>BUG-02 (SEDANG):</strong> Audit Log tidak bisa diakses oleh Manajer karena ketiadaan permission.</li>
</ol>

<p>Terdapat <strong>3 saran peningkatan</strong> terkait permission dan aksesibilitas yang dapat dipertimbangkan sesuai kebijakan bisnis.</p>

<div style="margin-top:30px;padding:16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px">
  <strong style="color:#166534">Rekomendasi Prioritas:</strong>
  <ol style="padding-left:20px;margin-top:8px">
    <li>Segera jalankan migrasi database untuk menambahkan kolom <code>sign_token_expires_at</code></li>
    <li>Evaluasi apakah permission audit log perlu diberikan ke Manajer</li>
    <li>Uji ulang skenario SKP Approve setelah migrasi diterapkan</li>
  </ol>
</div>

</body></html>`;

(async () => {
  const browser = await puppeteer.launch({ headless: true, args: ['--no-sandbox'] });
  const page = await browser.newPage();
  await page.setContent(html, { waitUntil: 'networkidle0', timeout: 30000 });
  await page.pdf({
    path: OUT,
    format: 'A4',
    printBackground: true,
    displayHeaderFooter: true,
    headerTemplate: '<div></div>',
    footerTemplate: '<div style="width:100%;font-size:8px;color:#94a3b8;display:flex;justify-content:space-between;padding:0 18mm"><span>Laporan Pengujian Manajer — CLARA v4.21</span><span>Halaman <span class="pageNumber"></span> dari <span class="totalPages"></span></span></div>',
    margin: { top: '12mm', right: '18mm', bottom: '16mm', left: '18mm' }
  });
  console.log('PDF generated:', OUT);
  await browser.close();
})();
