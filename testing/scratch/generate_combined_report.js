const puppeteer = require('puppeteer');
const path = require('path');
const fs = require('fs');

const SCREENSHOT_DIR = path.join(__dirname, '..', 'screenshots');
const PDF_PATH = path.join(__dirname, '..', 'Combined_UAT_Report.pdf');

// Helper to format image paths for Base64 embedding
const getImgUrl = (filename) => {
  let fullPath = path.join(SCREENSHOT_DIR, filename);
  if (!fs.existsSync(fullPath)) {
    fullPath = path.join(SCREENSHOT_DIR, 'manager', filename);
  }
  if (fs.existsSync(fullPath)) {
    const content = fs.readFileSync(fullPath);
    return `data:image/png;base64,${content.toString('base64')}`;
  }
  return '';
};

const htmlContent = `
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Laporan UAT Pengujian E2E Clara - Unifikasi Multi-Peran</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');
    
    @page {
      size: A4;
      margin: 14mm 18mm 16mm 18mm;
    }
    
    body {
      font-family: 'Inter', sans-serif;
      color: #1e293b;
      margin: 0;
      padding: 0;
      line-height: 1.6;
      font-size: 10pt;
    }
    
    /* Cover Page styling */
    .cover-page {
      margin: -14mm -18mm -16mm -18mm;
      width: 210mm;
      height: 297mm;
      background: #ffffff;
      color: #1e293b;
      padding: 45mm 20mm 20mm 20mm;
      box-sizing: border-box;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      position: relative;
      page-break-after: always;
      border-top: 8px solid #0d9488;
    }
    
    .cover-decor {
      position: absolute;
      top: 0;
      right: 0;
      width: 120mm;
      height: 120mm;
      background: radial-gradient(circle, rgba(13,148,136,0.05) 0%, rgba(0,0,0,0) 70%);
    }

    .cover-title-container {
      margin-top: 5mm;
    }
    
    .cover-tag {
      background: rgba(13, 148, 136, 0.1);
      border: 1px solid rgba(13, 148, 136, 0.3);
      padding: 6px 14px;
      border-radius: 99px;
      font-size: 9pt;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 2px;
      display: inline-block;
      margin-bottom: 6mm;
      color: #0d9488;
    }
    
    .cover-title {
      font-size: 32pt;
      font-weight: 900;
      line-height: 1.1;
      margin: 0 0 6mm 0;
      letter-spacing: -1.5px;
      color: #0f172a;
    }
    
    .cover-subtitle {
      font-size: 13pt;
      font-weight: 400;
      color: #475569;
      margin: 0 0 10mm 0;
      max-width: 160mm;
    }
    
    .cover-divider {
      width: 25mm;
      height: 3px;
      background: #0d9488;
      border-radius: 99px;
    }
    
    .cover-meta {
      font-size: 9.5pt;
      border-top: 1px solid rgba(255,255,255,0.1);
      padding-top: 6mm;
      margin-bottom: 5mm;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 6mm;
    }
    
    .meta-item label {
      font-size: 8pt;
      text-transform: uppercase;
      color: #94a3b8;
      font-weight: 700;
      letter-spacing: 1px;
      display: block;
      margin-bottom: 1.5mm;
    }
    
    .meta-item value {
      font-weight: 500;
      display: block;
      color: #e2e8f0;
    }
    
    /* Content Page Layout */
    .chapter-title {
      font-size: 20pt;
      color: #0f172a;
      border-bottom: 3px solid #0d9488;
      padding-bottom: 3mm;
      margin-top: 10mm;
      margin-bottom: 6mm;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: -0.5px;
    }
    
    .section-title {
      font-size: 12pt;
      color: #0d9488;
      margin-top: 8mm;
      margin-bottom: 4mm;
      font-weight: 700;
      border-left: 4px solid #0d9488;
      padding-left: 10px;
    }
    
    p {
      margin-top: 0;
      margin-bottom: 4mm;
      color: #334155;
      text-align: justify;
    }
    
    /* Tables styling */
    table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 6mm;
      margin-top: 3mm;
      font-size: 9pt;
    }
    
    th, td {
      border: 1px solid #cbd5e1;
      padding: 8px 12px;
      text-align: left;
      vertical-align: top;
    }
    
    th {
      background-color: #0f172a;
      color: #ffffff;
      font-weight: 600;
    }
    
    tr:nth-child(even) {
      background-color: #f8fafc;
    }
    
    /* Test Steps styling */
    ol {
      margin-top: 2mm;
      margin-bottom: 5mm;
      padding-left: 5mm;
    }
    
    li {
      margin-bottom: 4px;
      color: #334155;
    }
    
    /* Callout Box styling */
    .callout {
      padding: 12px 16px;
      border-radius: 6px;
      margin-top: 4mm;
      margin-bottom: 4mm;
      border-left: 4px solid;
      font-size: 9pt;
    }
    
    .callout-warning {
      background-color: #fffbeb;
      border-left-color: #f59e0b;
      color: #78350f;
    }
    
    .callout-tip {
      background-color: #f0fdfa;
      border-left-color: #0d9488;
      color: #115e59;
    }

    .callout-info {
      background-color: #f0f9ff;
      border-left-color: #0284c7;
      color: #075985;
    }
    
    /* Screenshot Image Container */
    .screenshot-container {
      margin-top: 4mm;
      margin-bottom: 6mm;
      border: 1px solid #cbd5e1;
      border-radius: 6px;
      overflow: hidden;
      background: #f8fafc;
      box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
      page-break-inside: avoid;
    }
    
    .screenshot-container img {
      width: 100%;
      display: block;
    }
    
    .screenshot-caption {
      padding: 6px 12px;
      background: #f1f5f9;
      font-size: 8pt;
      color: #64748b;
      border-top: 1px solid #cbd5e1;
      font-weight: 500;
    }
    
    .page-break {
      page-break-after: always;
    }
    
    /* Badges */
    .badge-status {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 4px;
      font-size: 7.5pt;
      font-weight: 700;
      text-transform: uppercase;
    }
    
    .badge-status-high {
      background-color: #fecaca;
      color: #991b1b;
    }
    
    .badge-status-medium {
      background-color: #fef3c7;
      color: #92400e;
    }
    
    .badge-status-low {
      background-color: #e0f2fe;
      color: #075985;
    }

    .badge-pass {
      background-color: #d1fae5;
      color: #065f46;
      font-weight: bold;
    }
    .badge-fail {
      background-color: #fee2e2;
      color: #991b1b;
      font-weight: bold;
    }
    
    /* Document Control Table */
    .doc-control {
      margin-top: 15mm;
      font-size: 8.5pt;
    }
    
    .doc-control th {
      background-color: #334155;
    }
    
  </style>
</head>
<body>

  <!-- COVER PAGE -->
  <div class="cover-page">
    <div class="cover-decor"></div>
    <div class="cover-title-container">
      <div class="cover-tag">Laporan UAT</div>
      <h1 class="cover-title">LAPORAN UAT</h1>
      <p class="cover-subtitle">Casual Leasing Achievement & Revenue Analytics (CLARA)</p>
      <div class="cover-divider"></div>
    </div>
    
    <div>
      <h3 style="font-size: 10pt; text-transform: uppercase; color: #0d9488; font-weight: 700; letter-spacing: 1px; margin-bottom: 4mm; border-bottom: 1px solid #cbd5e1; padding-bottom: 2mm;">TIM PENGUJI</h3>
      <table style="width: 100%; border-collapse: collapse; margin-bottom: 10mm;">
        <tbody>
          <tr>
            <td style="width: 33.3%; border: none; padding: 6px 0; font-size: 10pt;">
              <span style="font-size: 8pt; color: #64748b; display: block; text-transform: uppercase; margin-bottom: 1mm;">Penguji 1</span>
              <strong>Riky</strong>
            </td>
            <td style="width: 33.3%; border: none; padding: 6px 0; font-size: 10pt;">
              <span style="font-size: 8pt; color: #64748b; display: block; text-transform: uppercase; margin-bottom: 1mm;">Penguji 2</span>
              <strong>Adil</strong>
            </td>
            <td style="width: 33.3%; border: none; padding: 6px 0; font-size: 10pt;">
              <span style="font-size: 8pt; color: #64748b; display: block; text-transform: uppercase; margin-bottom: 1mm;">Penguji 3</span>
              <strong>Fadli</strong>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="cover-meta" style="border-top: 1px solid #e2e8f0; padding-top: 6mm; display: grid; grid-template-columns: 1fr 1fr; gap: 6mm;">
      <div class="meta-item">
        <label>Tanggal Pelaksanaan</label>
        <value>Senin, 29 Juni 2026 - Selasa, 30 Juni 2026</value>
      </div>
      <div class="meta-item">
        <label>Lingkungan Uji</label>
        <value>Lokal (Port 8000) / Database: clara_unified</value>
      </div>
    </div>
  </div>

  <!-- DAFTAR ISI -->
  <div class="section">
    <div class="chapter-title">Daftar Isi</div>
    <ol style="font-size: 11pt; line-height: 2.2; padding-left: 20px; color: #334155;">
      <li><strong>BAB 1. Testing Superadmin</strong>
        <ul style="padding-left: 15px; font-size: 9.5pt; list-style-type: circle;">
          <li>1.1 Login & Pemilihan Properti</li>
          <li>1.2 Manajemen User (Tambah Pengguna)</li>
          <li>1.3 Pembuatan Master PIC & Penautan Akun</li>
          <li>1.4 Penonaktifan Pengguna & Proteksi Keamanan Login</li>
          <li>1.5 Manajemen Peran & Izin Akses (Roles & Permissions)</li>
          <li>1.6 Manajemen Master Exhibition (Unit Sewa)</li>
          <li>1.7 Manajemen Master Target Bulanan</li>
          <li>1.8 Pendaftaran Client Baru (Master Client)</li>
          <li>1.9 Fitur Administratif, Log Audit, Sampah & Recurring</li>
          <li>1.10 Pergantian Properti & Dashboard TV Display</li>
          <li>1.11 Pengujian Celah Keamanan Akses Berkas Langsung</li>
          <li>1.12 Ringkasan & Analitik Hasil Pengujian, Audit Temuan, Laporan Rekomendasi</li>
        </ul>
      </li>
      <li><strong>BAB 2. Testing Manager</strong>
        <ul style="padding-left: 15px; font-size: 9.5pt; list-style-type: circle;">
          <li>2.1 Login Manajer</li>
          <li>2.2 Penolakan SKP (SKP Reject)</li>
          <li>2.3 Persetujuan SKP (SKP Approve)</li>
          <li>2.4 Permintaan Kontrak & Legal Approve</li>
          <li>2.5 Pembatalan Transaksi (Batal Transaksi)</li>
          <li>2.6 Pengawasan Tim Lintas-Sales</li>
          <li>2.7 Executive Dashboard & TV Display</li>
          <li>2.8 Renewal Kontrak</li>
          <li>2.9 Simulasi Komisi</li>
          <li>2.10 Keamanan RBAC (Bypass URL)</li>
          <li>2.11 Ringkasan & Analitik Hasil Pengujian, Audit Temuan, Laporan Rekomendasi</li>
        </ul>
      </li>
      <li><strong>BAB 3. Testing Sales</strong>
        <ul style="padding-left: 15px; font-size: 9.5pt; list-style-type: circle;">
          <li>3.1 Login Pertama Kali & Ganti Password</li>
          <li>3.2 Pengaturan Tanda Tangan Saya</li>
          <li>3.3 Pembuatan Surat Penawaran (Single & Bundling)</li>
          <li>3.4 Negosiasi & Tanda Tangan Customer (DEAL)</li>
          <li>3.5 Pembuatan SKP / SKS & Pengajuan Persetujuan</li>
          <li>3.6 Pengajuan Kontrak ke Departemen Legal</li>
          <li>3.7 Dasbor Pribadi & Pemantauan Kinerja</li>
          <li>3.8 Keamanan & Troubleshooting (Bypass URL)</li>
          <li>3.9 Ringkasan & Analitik Hasil Pengujian, Audit Temuan, Laporan Rekomendasi</li>
        </ul>
      </li>
      <li><strong>BAB 4. Analisis Kelayakan & Evaluasi Kesiapan Rilis</strong>
        <ul style="padding-left: 15px; font-size: 9.5pt; list-style-type: circle;">
          <li>4.1 Evaluasi & Justifikasi Kelayakan Sistem</li>
          <li>4.2 Analisis Deviasi Skenario Pengujian (Gap Analysis)</li>
        </ul>
      </li>
    </ol>
  </div>

  <div class="page-break"></div>

  <!-- BAB 1. SUPERADMIN -->
  <div class="section">
    <div class="chapter-title">BAB 1. Testing Superadmin</div>
    <p>
      Pengujian pada tingkat <strong>Superadmin</strong> difokuskan untuk memvalidasi kendali administratif tertinggi, pembuatan master data, konfigurasi hak akses granular (RBAC), monitoring log sistem, keamanan data transaksional, serta pembatasan akses langsung ke file server tanpa sesi yang valid.
    </p>

    <!-- 1.1 -->
    <div class="section-title">1.1 Login & Pemilihan Properti</div>
    <p>
      Skenario ini memverifikasi bahwa akun Superadmin dapat melakukan autentikasi dengan kredensial yang valid dan dialihkan ke gerbang pemilihan properti jika akun tersebut ditugaskan ke lebih dari satu properti. Setelah memilih properti, pengguna diarahkan ke dashboard utama.
    </p>
    <table>
      <thead>
        <tr>
          <th>Nama Field</th>
          <th>Wajib</th>
          <th>Penjelasan</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><b>Email</b></td>
          <td>Ya</td>
          <td>Alamat email terdaftar superadmin (contoh: <code>saadilaeffendi@gmail.com</code>).</td>
        </tr>
        <tr>
          <td><b>Password</b></td>
          <td>Ya</td>
          <td>Kata sandi akun superadmin.</td>
        </tr>
      </tbody>
    </table>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Membuka halaman login utama <code>/?r=login</code>.</li>
      <li>Mengisi alamat email <code>saadilaeffendi@gmail.com</code> dan kata sandi valid.</li>
      <li>Menekan tombol "Masuk" dan sistem mengalihkan pengguna ke halaman pemilihan properti.</li>
      <li>Memilih properti <strong>E-Walk Simply FUNtastic</strong> untuk masuk ke dashboard.</li>
    </ol>
    <div class="screenshot-container">
      <img src="${getImgUrl('1_dashboard.png')}" alt="Dashboard Utama">
      <div class="screenshot-caption">Gambar 1.1: Halaman Dashboard Utama setelah login superadmin dan memilih properti E-Walk.</div>
    </div>
    <div class="callout callout-tip">
      <strong>Tips:</strong> Dropdown properti aktif berada di pojok kanan atas topbar untuk mempermudah perpindahan unit mal secara dinamis.
    </div>

    <!-- 1.2 -->
    <div class="section-title">1.2 Manajemen User (Tambah Pengguna)</div>
    <p>
      Menguji antarmuka pembuatan pengguna baru di bawah kendali Superadmin. Pengguna baru yang ditambahkan memiliki peran sebagai <strong>Sales</strong> dengan status aktif dan ditugaskan ke properti E-Walk.
    </p>
    <table>
      <thead>
        <tr>
          <th>Nama Field</th>
          <th>Wajib</th>
          <th>Penjelasan</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><b>Nama Lengkap</b></td>
          <td>Ya</td>
          <td>Nama lengkap dari pengguna baru (contoh: <code>Sales Budi</code>).</td>
        </tr>
        <tr>
          <td><b>Email</b></td>
          <td>Ya</td>
          <td>Email unik untuk login (contoh: <code>sales.budi@clara.local</code>).</td>
        </tr>
        <tr>
          <td><b>Role</b></td>
          <td>Ya</td>
          <td>Peran otorisasi sistem (Superadmin, Admin, Supervisor, Sales, Keuangan).</td>
        </tr>
        <tr>
          <td><b>Status</b></td>
          <td>Ya</td>
          <td>Status keaktifan login (Active / Inactive).</td>
        </tr>
        <tr>
          <td><b>Daftar Properti</b></td>
          <td>Ya</td>
          <td>Pilihan akses properti bagi pengguna.</td>
        </tr>
      </tbody>
    </table>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Membuka daftar pengguna di menu <code>/?r=users</code>.</li>
      <li>Menekan tombol "Tambah User" menuju form input.</li>
      <li>Mengisi data pengguna baru: <strong>Sales Budi</strong>, <code>sales.budi@clara.local</code>, peran <strong>Sales</strong>, status <strong>Active</strong>, dan memilih properti <strong>E-Walk</strong>.</li>
      <li>Menyimpan data dan memastikan sistem menampilkan pesan sukses.</li>
    </ol>
    <div class="screenshot-container">
      <img src="${getImgUrl('3_add_user_form.png')}" alt="Form Tambah User">
      <div class="screenshot-caption">Gambar 1.2: Form Pengisian Data Pembuatan Pengguna Baru (Sales Budi).</div>
    </div>

    <!-- 1.3 -->
    <div class="section-title">1.3 Pembuatan Master PIC & Penautan Akun</div>
    <p>
      Menguji penautan entitas PIC (Person In Charge) dengan akun login pengguna yang baru dibuat agar transaksi dapat terlacak secara otomatis ke dalam target performa PIC yang bersangkutan.
    </p>
    <table>
      <thead>
        <tr>
          <th>Nama Field</th>
          <th>Wajib</th>
          <th>Penjelasan</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><b>Nama PIC</b></td>
          <td>Ya</td>
          <td>Nama PIC yang akan ditampilkan pada surat penawaran dan laporan.</td>
        </tr>
        <tr>
          <td><b>Jabatan</b></td>
          <td>Ya</td>
          <td>Jabatan struktural. Contoh: <code>Sales</code>.</td>
        </tr>
        <tr>
          <td><b>Email</b></td>
          <td>Ya</td>
          <td>Alamat email korespondensi penawaran.</td>
        </tr>
        <tr>
          <td><b>No. WhatsApp</b></td>
          <td>Ya</td>
          <td>Nomor kontak untuk tautan pengiriman surat penawaran.</td>
        </tr>
        <tr>
          <td><b>User Akun</b></td>
          <td>Tidak</td>
          <td>Dropdown untuk menghubungkan ke akun login di tabel users.</td>
        </tr>
        <tr>
          <td><b>Porsi Target</b></td>
          <td>Ya</td>
          <td>Persentase beban target bulanan (0 - 100%).</td>
        </tr>
      </tbody>
    </table>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Membuka Master PIC di menu <code>/?r=master&type=pic</code>.</li>
      <li>Menekan "Tambah Data" PIC.</li>
      <li>Mengisi data PIC Sales Budi, memasukkan target share <strong>100%</strong>, dan memilih Akun User <strong>Sales Budi</strong>.</li>
      <li>Menyimpan dan memvalidasi data PIC tersimpan di list.</li>
    </ol>
    <div class="screenshot-container">
      <img src="${getImgUrl('12_add_pic_form.png')}" alt="Form Tambah PIC">
      <div class="screenshot-caption">Gambar 1.3: Pengisian form penautan PIC dengan akun user login Sales Budi.</div>
    </div>

    <!-- 1.4 -->
    <div class="section-title">1.4 Penonaktifan Pengguna & Proteksi Keamanan Login</div>
    <p>
      Skenario ini memvalidasi bahwa penonaktifan akun oleh Superadmin bekerja seketika. Akun yang telah berstatus <strong>Inactive</strong> harus segera ditolak oleh gerbang autentikasi meskipun menggunakan kata sandi yang benar.
    </p>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Membuka kembali form edit user <strong>Sales Budi</strong>.</li>
      <li>Mengubah status dari <strong>Active</strong> menjadi <strong>Inactive</strong>, kemudian menyimpan perubahan.</li>
      <li>Melakukan <code>logout</code> dari akun Superadmin.</li>
      <li>Mencoba masuk kembali menggunakan akun Sales Budi (<code>sales.budi@clara.local</code> / <code>123456</code>).</li>
      <li>Memastikan sistem menampilkan pesan error flash: <em>"User tidak aktif. Hubungi admin."</em>.</li>
      <li>Login kembali sebagai Superadmin untuk melanjutkan sisa pengujian.</li>
    </ol>
    <div class="screenshot-container">
      <img src="${getImgUrl('6b_login_inactive_blocked.png')}" alt="Login Blocked Inactive">
      <div class="screenshot-caption">Gambar 1.4: Pesan penolakan login sistem akibat deteksi akun tidak aktif.</div>
    </div>

    <div class="page-break"></div>

    <!-- 1.5 -->
    <div class="section-title">1.5 Manajemen Peran & Izin Akses (Roles & Permissions)</div>
    <p>
      Menguji konfigurasi hak akses granular untuk peran <strong>Supervisor</strong>. Pengujian dilakukan untuk memastikan Superadmin dapat mencentang izin spesifik dan membatalkan izin administratif tingkat tinggi untuk Supervisor.
    </p>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Membuka menu Hak Akses & Peran pada <code>/?r=roles</code>.</li>
      <li>Menyesuaikan izin peran <strong>Supervisor</strong> sesuai ketentuan:<br>
        • <strong>Diberikan:</strong> <code>approve_skp</code>, <code>manage_offers</code>, <code>manage_skp</code>, <code>view_exec_summary</code>, <code>view_renewals</code>, <code>view_commission_sim</code>, <code>export_reports</code>, <code>view_pic_report</code>.<br>
        • <strong>Dicabut:</strong> <code>manage_users</code>, <code>view_logs</code>, <code>manage_deleted</code>.
      </li>
      <li>Menyimpan pengaturan peran dan memastikan data tersimpan di basis data.</li>
    </ol>
    <div class="screenshot-container">
      <img src="${getImgUrl('7_roles_permissions.png')}" alt="Roles and Permissions">
      <div class="screenshot-caption">Gambar 1.5: Matriks konfigurasi hak akses peran Supervisor di aplikasi.</div>
    </div>

    <!-- 1.6 -->
    <div class="section-title">1.6 Manajemen Master Exhibition (Unit Sewa)</div>
    <p>
      Memvalidasi pembuatan unit sewa fiktif baru pada kategori Exhibition di Ground Floor (GF) dengan sistem perhitungan potensi pendapatan bulanan otomatis.
    </p>
    <table>
      <thead>
        <tr>
          <th>Nama Field</th>
          <th>Wajib</th>
          <th>Penjelasan</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><b>Nama Lokasi</b></td>
          <td>Ya</td>
          <td>Nama area / unit stan pameran. Contoh: <code>Fiktif Unit</code>.</td>
        </tr>
        <tr>
          <td><b>Lantai</b></td>
          <td>Ya</td>
          <td>Dropdown lokasi lantai unit (LG / GF / UG / FF / SF).</td>
        </tr>
        <tr>
          <td><b>Luas m2</b></td>
          <td>Ya</td>
          <td>Luas fisik unit stan dalam satuan meter persegi.</td>
        </tr>
        <tr>
          <td><b>Rate Harian/m2</b></td>
          <td>Ya</td>
          <td>Nilai sewa harian per meter persegi (menggunakan format mata uang).</td>
        </tr>
      </tbody>
    </table>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Membuka halaman Master Exhibition di <code>/?r=master&type=cl</code>.</li>
      <li>Menekan "Tambah Data" untuk memunculkan form.</li>
      <li>Mengisi data lokasi <strong>Fiktif Unit</strong>, lantai <strong>GF</strong>, luas <strong>25 m2</strong>, dan rate harian <strong>Rp 150.000</strong>.</li>
      <li>Menekan tombol "Simpan" dan memvalidasi unit baru muncul pada tabel daftar unit exhibition.</li>
    </ol>
    <div class="screenshot-container">
      <img src="${getImgUrl('9_add_exhibition_form.png')}" alt="Form Tambah Exhibition">
      <div class="screenshot-caption">Gambar 1.6: Pengisian form penambahan unit stan pameran baru (Fiktif Unit).</div>
    </div>

    <!-- 1.7 -->
    <div class="section-title">1.7 Manajemen Master Target Bulanan</div>
    <p>
      Menguji fungsionalitas pengisian target nominal bulanan untuk properti aktif pada periode bulan berjalan. Nilai input harus diformat secara otomatis dengan pemisah ribuan rupiah.
    </p>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Membuka menu Target Bulanan di <code>/?r=master&type=target</code>.</li>
      <li>Menekan tombol "Tambah Data" target.</li>
      <li>Memasukkan Target Amount sebesar <strong>Rp 50.000.000</strong> (Rp 50 Juta) dan memastikan period key terisi otomatis untuk bulan berjalan.</li>
      <li>Menyimpan target dan memastikan entitas tersimpan.</li>
    </ol>
    <div class="screenshot-container">
      <img src="${getImgUrl('14_add_target_form.png')}" alt="Form Target Bulanan">
      <div class="screenshot-caption">Gambar 1.7: Form pengisian target bulanan properti sebesar Rp 50 Juta.</div>
    </div>

    <div class="page-break"></div>

    <!-- 1.8 -->
    <div class="section-title">1.8 Pendaftaran Client Baru (Master Client)</div>
    <p>
      Menguji fungsionalitas pembuatan profil client baru dengan integrasi data geografis wilayah Indonesia. Form harus dapat merespons perubahan provinsi dengan memuat daftar kota yang relevan secara dinamis.
    </p>
    <table>
      <thead>
        <tr>
          <th>Nama Field</th>
          <th>Wajib</th>
          <th>Penjelasan</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><b>Nama Perusahaan</b></td>
          <td>Ya</td>
          <td>Nama organisasi / korporasi client.</td>
        </tr>
        <tr>
          <td><b>NPWP</b></td>
          <td>Tidak</td>
          <td>Nomor Pokok Wajib Pajak (15 digit).</td>
        </tr>
        <tr>
          <td><b>Provinsi</b></td>
          <td>Ya</td>
          <td>Dropdown provinsi di Indonesia.</td>
        </tr>
        <tr>
          <td><b>Kota / Kabupaten</b></td>
          <td>Ya</td>
          <td>Dropdown kota/kabupaten yang termuat secara dinamis via AJAX.</td>
        </tr>
      </tbody>
    </table>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Membuka menu daftar client di <code>/?r=clients</code>.</li>
      <li>Menekan "Tambah Client" untuk membuka formulir pendaftaran.</li>
      <li>Mengisi data: nama perusahaan <strong>Fiktif Corp</strong>, brand <strong>Fiktif Brand</strong>, alamat <strong>Jalan Fiktif No 1</strong>.</li>
      <li>Memilih Provinsi <strong>Kalimantan Timur</strong>, dan memastikan Kota <strong>Balikpapan</strong> dapat dipilih pada dropdown berikutnya.</li>
      <li>Menyimpan form dan memvalidasi halaman detail profil client yang baru saja dibuat.</li>
    </ol>
    <div class="screenshot-container">
      <img src="${getImgUrl('16_add_client_form.png')}" alt="Form Tambah Client">
      <div class="screenshot-caption">Gambar 1.8: Pengisian data identitas dan alamat geografis client baru.</div>
    </div>

    <!-- 1.9 -->
    <div class="section-title">1.9 Fitur Administratif, Log Audit, Sampah & Recurring</div>
    <p>
      Bagian ini mendokumentasikan antarmuka fitur pendukung sistem: halaman pengelolaan opsi dropdown lookup, halaman rekaman log aktivitas sistem, halaman daftar transaksi terhapus (soft-deletes), serta konversi transaksi berulang (recurring).
    </p>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Buka Kelola Opsi Dropdown di <code>/?r=lookup_manage</code>.</li>
      <li>Buka halaman Audit Log di <code>/?r=audit</code> untuk verifikasi pelacakan aksi.</li>
      <li>Buka Transaksi Dihapus di <code>/?r=deleted_transactions</code>.</li>
      <li>Buka konversi transaksi recurring di <code>/?r=recurring_candidates</code>.</li>
    </ol>
    <div class="screenshot-container">
      <img src="${getImgUrl('19_activity_log.png')}" alt="Activity Log">
      <div class="screenshot-caption">Gambar 1.9: Halaman Log Aktivitas (Audit Log) yang merekam operasi pembuatan dan pembaruan data.</div>
    </div>

    <!-- 1.10 -->
    <div class="section-title">1.10 Pergantian Properti & Dashboard TV Display</div>
    <p>
      Menguji fungsionalitas pergantian properti secara dinamis melalui bilah menu atas tanpa memutus sesi pengguna, serta memvalidasi halaman eksekutif dan display TV pameran yang dioptimalkan untuk monitor informasi publik.
    </p>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Membuka dashboard properti E-Walk.</li>
      <li>Menekan tautan alih properti di topbar ke <strong>Pentacity Shopping Venue</strong>.</li>
      <li>Memastikan tampilan data beralih ke portofolio Pentacity secara instan.</li>
      <li>Membuka Executive Summary untuk melihat visualisasi tren pendapatan multisektor.</li>
      <li>Membuka halaman Display TV di rute <code>/?r=display</code>.</li>
    </ol>
    <div class="screenshot-container">
      <img src="${getImgUrl('24_display_tv.png')}" alt="Display TV">
      <div class="screenshot-caption">Gambar 1.10: Antarmuka minimalis Display TV yang ditujukan untuk papan informasi digital (digital signage).</div>
    </div>

    <div class="page-break"></div>

    <!-- 1.11 -->
    <div class="section-title">1.11 Pengujian Celah Keamanan Akses Berkas Langsung</div>
    <p>
      Pengujian keamanan difokuskan pada perlindungan berkas tanda tangan digital dan lampiran dokumen penawaran. Sistem wajib memverifikasi kepemilikan sesi sebelum melayani pengunduhan berkas sensitif dari direktori <code>signatures/</code>.
    </p>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Membuka peramban baru tanpa sesi login aktif (tanpa cookie).</li>
      <li>Mengakses URL penjarahan dokumen secara langsung: <code>http://localhost:8000/?r=file&file=signatures/test.png</code>.</li>
      <li>Memastikan server menolak akses dengan respons HTTP 403 Forbidden atau mengalihkan ke gerbang login.</li>
    </ol>
    <div class="screenshot-container">
      <img src="${getImgUrl('25_security_bypass_blocked.png')}" alt="Security Bypass Blocked">
      <div class="screenshot-caption">Gambar 1.11: Bukti penolakan server ketika diakses langsung tanpa sesi.</div>
    </div>
    <div class="callout callout-warning">
      <strong>Peringatan Keamanan:</strong> Pastikan path traversal seperti <code>../../.env</code> diblokir dengan ketat pada handler file.
    </div>

    <!-- 1.12 SUB-SECTIONS -->
    <div class="section-title">1.12 Ringkasan & Analitik Hasil Pengujian (E2E Test Analytics)</div>
    <p>
      Pengujian otomatis menggunakan Puppeteer terhadap modul Superadmin mencakup 23 skenario. Berikut ringkasan performa pengujian:
    </p>
    <div style="display: flex; gap: 4mm; margin-bottom: 4mm;">
      <div style="flex: 1; background-color: #f0fdfa; border: 1px solid #5eead4; border-radius: 6px; padding: 10px; text-align: center;">
        <span style="font-size: 8pt; text-transform: uppercase; color: #0d9488; font-weight: 700; display: block;">Tingkat Keberhasilan</span>
        <strong style="font-size: 16pt; color: #0f766e; display: block;">95.65%</strong>
      </div>
      <div style="flex: 1; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px; text-align: center;">
        <span style="font-size: 8pt; text-transform: uppercase; color: #475569; font-weight: 700; display: block;">Total Skenario</span>
        <strong style="font-size: 16pt; color: #1e293b; display: block;">23 Item</strong>
      </div>
      <div style="flex: 1; background-color: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; padding: 10px; text-align: center;">
        <span style="font-size: 8pt; text-transform: uppercase; color: #16a34a; font-weight: 700; display: block;">PASS</span>
        <strong style="font-size: 16pt; color: #15803d; display: block;">22 Item</strong>
      </div>
      <div style="flex: 1; background-color: #fef2f2; border: 1px solid #fca5a5; border-radius: 6px; padding: 10px; text-align: center;">
        <span style="font-size: 8pt; text-transform: uppercase; color: #dc2626; font-weight: 700; display: block;">FAIL</span>
        <strong style="font-size: 16pt; color: #b91c1c; display: block;">1 Item</strong>
      </div>
    </div>
    
    <table>
      <thead>
        <tr>
          <th>Modul Pengujian</th>
          <th style="text-align: center;">Checklist</th>
          <th style="text-align: center;">Pass</th>
          <th style="text-align: center;">Fail</th>
          <th style="text-align: center;">Persentase</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>Manajemen Pengguna & Hak Akses</td>
          <td style="text-align: center;">6</td>
          <td style="text-align: center; color: #16a34a; font-weight: 600;">6</td>
          <td style="text-align: center;">0</td>
          <td style="text-align: center; font-weight: 600;">100.00%</td>
        </tr>
        <tr>
          <td>Pengelolaan Master Data</td>
          <td style="text-align: center;">6</td>
          <td style="text-align: center; color: #16a34a; font-weight: 600;">6</td>
          <td style="text-align: center;">0</td>
          <td style="text-align: center; font-weight: 600;">100.00%</td>
        </tr>
        <tr>
          <td>Audit & Pemulihan (Log & Trash)</td>
          <td style="text-align: center;">3</td>
          <td style="text-align: center; color: #16a34a; font-weight: 600;">2</td>
          <td style="text-align: center; color: #dc2626; font-weight: 600;">1</td>
          <td style="text-align: center; font-weight: 600;">66.67%</td>
        </tr>
        <tr>
          <td>Fitur Khusus & Lintas-Properti</td>
          <td style="text-align: center;">4</td>
          <td style="text-align: center; color: #16a34a; font-weight: 600;">4</td>
          <td style="text-align: center;">0</td>
          <td style="text-align: center; font-weight: 600;">100.00%</td>
        </tr>
        <tr>
          <td>Sisi Keamanan Teknis</td>
          <td style="text-align: center;">4</td>
          <td style="text-align: center; color: #16a34a; font-weight: 600;">4</td>
          <td style="text-align: center;">0</td>
          <td style="text-align: center; font-weight: 600;">100.00%</td>
        </tr>
      </tbody>
    </table>

    <div class="section-title">Audit Temuan Kesalahan & Kerusakan Sistem (True Defects / Bugs)</div>
    <table>
      <thead>
        <tr>
          <th style="width: 35mm;">Cacat Sistem</th>
          <th style="width: 15mm; text-align: center;">Urgensi</th>
          <th>Ekspektasi</th>
          <th>Realita</th>
          <th>Dampak</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><b>Ketiadaan Tombol Restore</b><br><span style="font-size:7.5pt;color:#64748b;">?r=deleted_transactions</span></td>
          <td style="text-align: center;"><span class="badge-status badge-status-high">High</span></td>
          <td>Tersedianya tombol "Pulihkan" / "Restore" di antarmuka web.</td>
          <td>Hanya berupa tabel statis read-only tanpa tombol aksi pemulihan.</td>
          <td>Kesalahan penghapusan tidak bisa diperbaiki via UI, memaksa DML SQL manual yang berisiko.</td>
        </tr>
        <tr>
          <td><b>TV Display Crash Fatal</b><br><span style="font-size:7.5pt;color:#64748b;">?r=display</span></td>
          <td style="text-align: center;"><span class="badge-status badge-status-medium">Medium</span></td>
          <td>Akses halaman TV Display stabil atau me-redirect jika token kosong.</td>
          <td>Langsung menampilkan stack trace error "Display token tidak valid" di layar.</td>
          <td>Layar publik mall mati/tampil error jika browser TV melakukan auto-refresh/kehilangan query.</td>
        </tr>
      </tbody>
    </table>

    <div class="section-title">Laporan Rekomendasi & Saran Peningkatan (System Improvements)</div>
    <table>
      <thead>
        <tr>
          <th>Rekomendasi</th>
          <th style="text-align: center;">Urgensi</th>
          <th>Alasan Usability</th>
          <th>Dampak Positif jika Diimplementasikan</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><b>Auto-Select / Auto-Clear input Porsi Target</b></td>
          <td style="text-align: center;"><span class="badge-status badge-status-low">Low</span></td>
          <td>Menghindari angka tergabung "0100" yang memicu error validasi HTML5.</td>
          <td>Meningkatkan kenyamanan entri data finansial bulanan secara intuitif.</td>
        </tr>
        <tr>
          <td><b>Penyimpanan Kode ID Wilayah (BPS)</b></td>
          <td style="text-align: center;"><span class="badge-status badge-status-low">Low</span></td>
          <td>Mencegah duplikasi data literal string geografis ("Kaltim" vs "Kalimantan Timur").</td>
          <td>Memudahkan integrasi API perpajakan negara (eFaktur) dan logistik nasional.</td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="page-break"></div>

  <!-- BAB 2. TESTING MANAGER -->
  <div class="section">
    <div class="chapter-title">BAB 2. Testing Manager</div>
    <p>
      Peran <strong>Manager (Supervisor)</strong> bertanggung jawab atas peninjauan dokumen Surat Keputusan Pameran (SKP), persetujuan negosiasi sales, legal contract request approval, pembatalan sewa, pengawasan performa PIC tim, dan komisi target bulanan.
    </p>

    <!-- 2.1 -->
    <div class="section-title">2.1 Login Manajer</div>
    <p>
      Verifikasi akses login akun manajer menggunakan kredensial yang dialokasikan dan memastikan dashboard utama menampilkan agregat performa mall.
    </p>
    <table>
      <thead>
        <tr>
          <th>Nama Field</th>
          <th>Wajib</th>
          <th>Penjelasan</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><b>Email</b></td>
          <td>Ya</td>
          <td>Alamat email terdaftar manajer (contoh: <code>adil@gmail.com</code>).</td>
        </tr>
        <tr>
          <td><b>Password</b></td>
          <td>Ya</td>
          <td>Kata sandi akun manajer (Supervisor).</td>
        </tr>
      </tbody>
    </table>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Membuka halaman login utama <code>/?r=login</code>.</li>
      <li>Mengisi email <code>adil@gmail.com</code> dan kata sandi valid.</li>
      <li>Klik "Masuk" dan pilih properti <strong>E-Walk Simply FUNtastic</strong>.</li>
    </ol>
    <div class="screenshot-container">
      <img src="${getImgUrl('01_login_dashboard.png')}" alt="Login Dashboard Manager">
      <div class="screenshot-caption">Gambar 2.1: Dasbor utama Manajer setelah proses otentikasi berhasil.</div>
    </div>

    <!-- 2.2 -->
    <div class="section-title">2.2 Penolakan SKP (SKP Reject)</div>
    <p>
      Menguji alur penolakan dokumen pengajuan sewa (SKP) oleh Manajer dengan mewajibkan penginputan catatan penolakan.
    </p>
    <table>
      <thead>
        <tr>
          <th>Nama Field</th>
          <th>Wajib</th>
          <th>Penjelasan</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><b>Catatan Penolakan</b></td>
          <td>Ya</td>
          <td>Keterangan alasan dokumen ditolak dan dikembalikan ke sales.</td>
        </tr>
      </tbody>
    </table>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Membuka daftar pengajuan SKP di rute <code>/?r=skp</code>.</li>
      <li>Memilih dokumen status <em>Submitted</em> (contoh: ID 19).</li>
      <li>Menekan tombol <strong>Tolak</strong>, lalu mengisi catatan penolakan wajib.</li>
      <li>Mengirim formulir dan status SKP berubah kembali menjadi <strong>Ditolak</strong>.</li>
    </ol>
    <div class="screenshot-container">
      <img src="${getImgUrl('03_skp_reject_after.png')}" alt="SKP Rejected">
      <div class="screenshot-caption">Gambar 2.2: Dokumen SKP ID 19 setelah berhasil ditolak Manajer.</div>
    </div>

    <!-- 2.3 -->
    <div class="section-title">2.3 Persetujuan SKP (SKP Approve)</div>
    <p>
      Memvalidasi alur persetujuan SKP oleh Manajer untuk menerbitkan nomor SKP final, data transaksi, dan porsi pencapaian PIC.
    </p>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Membuka detail SKP status <em>Submitted</em> (contoh: ID 23).</li>
      <li>Menekan tombol <strong>Setujui</strong>.</li>
      <li>Menerima dialog konfirmasi browser.</li>
      <li>Status SKP berubah menjadi <strong>Disetujui</strong> (atau <strong>Approved</strong>).</li>
    </ol>
    <div class="callout callout-warning">
      <strong>Catatan Kritis:</strong> Sebelumnya pengujian ini gagal akibat BUG-01 (kolom <code>sign_token_expires_at</code> tidak ditemukan), namun telah diselesaikan dengan aman melalui eksekusi alter table di MySQL.
    </div>

    <!-- 2.4 -->
    <div class="section-title">2.4 Permintaan Kontrak & Legal Approve</div>
    <p>
      Menguji peninjauan permohonan penerbitan kontrak legal dan persetujuan melalui link sharing publik departemen legal.
    </p>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Buka detail Permintaan Kontrak di <code>/?r=contract_requests</code>.</li>
      <li>Klik menu bagikan untuk mendapatkan URL Legal Share.</li>
      <li>Buka URL Legal Share tersebut, periksa kelengkapan lampiran berkas.</li>
      <li>Klik tombol <strong>✓ Setujui</strong> pada form persetujuan legal.</li>
    </ol>
    <div class="screenshot-container">
      <img src="${getImgUrl('08_legal_approved.png')}" alt="Legal Approved">
      <div class="screenshot-caption">Gambar 2.3: Kontrak berstatus Disetujui Legal setelah diproses di halaman peninjauan.</div>
    </div>

    <div class="page-break"></div>

    <!-- 2.5 -->
    <div class="section-title">2.5 Pembatalan Transaksi (Batal Transaksi)</div>
    <p>
      Menguji penutupan sewa secara sepihak atau pembatalan transaksi dengan alasan wajib untuk mencegah anomali pelaporan occupancy.
    </p>
    <table>
      <thead>
        <tr>
          <th>Nama Field</th>
          <th>Wajib</th>
          <th>Penjelasan</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><b>Alasan Pembatalan</b></td>
          <td>Ya</td>
          <td>Keterangan rinci alasan transaksi tersebut dibatalkan.</td>
        </tr>
      </tbody>
    </table>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Membuka detail transaksi aktif di list.</li>
      <li>Mengeklik tombol <strong>Batalkan Transaksi</strong>.</li>
      <li>Mengisi kolom alasan pembatalan, lalu submit.</li>
    </ol>
    <div class="screenshot-container">
      <img src="${getImgUrl('10_cancel_after.png')}" alt="Cancel After">
      <div class="screenshot-caption">Gambar 2.4: Transaksi setelah dibatalkan dengan detail alasan tercatat.</div>
    </div>

    <!-- 2.6 -->
    <div class="section-title">2.6 Pengawasan Tim Lintas-Sales</div>
    <p>
      Manajer memantau seluruh kinerja PIC di bawah naungannya melalui Laporan PIC, grafik Performa PIC, Pipeline Corong Penawaran, dan Porsi Target Reward (Read-only).
    </p>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Navigasi ke menu Laporan PIC di <code>/?r=pic_report</code>.</li>
      <li>Buka tab Performa dan Pipeline untuk verifikasi visual grafik target pencapaian.</li>
      <li>Verifikasi menu Rewarding PIC read-only (tombol simpan form dinonaktifkan).</li>
    </ol>
    <div class="screenshot-container">
      <img src="${getImgUrl('12_pic_report.png')}" alt="PIC Report">
      <div class="screenshot-caption">Gambar 2.5: Laporan target dan realisasi performa PIC yang dipantau Manajer.</div>
    </div>

    <!-- 2.7 -->
    <div class="section-title">2.7 Executive Dashboard & TV Display</div>
    <p>
      Akses laporan eksekutif mal untuk memantau pendapatan bulanan, occupancy rates pameran, serta TV Display signage.
    </p>
    <div class="screenshot-container">
      <img src="${getImgUrl('16_exec_dashboard.png')}" alt="Executive Dashboard">
      <div class="screenshot-caption">Gambar 2.6: Tampilan grafik pendapatan eksekutif pameran dan media sewa.</div>
    </div>

    <!-- 2.8 -->
    <div class="section-title">2.8 Renewal Kontrak</div>
    <p>
      Menguji halaman perpanjangan kontrak (Renewal). Bagi peran Manajer, halaman ini bersifat read-only untuk memantau waktu jatuh tempo sewa tenant.
    </p>
    <div class="screenshot-container">
      <img src="${getImgUrl('18_renewals.png')}" alt="Renewals List">
      <div class="screenshot-caption">Gambar 2.7: Daftar kartu perpanjangan kontrak sewa tenant.</div>
    </div>

    <!-- 2.9 -->
    <div class="section-title">2.9 Simulasi Komisi</div>
    <p>
      Menguji kalkulator simulasi pembagian komisi leasing per PIC berdasarkan capaian revenue berjalan.
    </p>
    <div class="screenshot-container">
      <img src="${getImgUrl('20_commission_sim.png')}" alt="Commission Simulation">
      <div class="screenshot-caption">Gambar 2.8: Form perhitungan simulasi komisi PIC.</div>
    </div>

    <div class="page-break"></div>

    <!-- 2.10 -->
    <div class="section-title">2.10 Keamanan RBAC (Bypass URL)</div>
    <p>
      Menguji ketahanan sistem terhadap percobaan eskalasi hak akses oleh Manajer (rute Superadmin).
    </p>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Mencoba mengakses langsung menu <code>/?r=users</code> di browser.</li>
      <li>Mencoba mengakses langsung menu <code>/?r=audit</code> di browser.</li>
      <li>Verifikasi server memblokir akses dan mengembalikan respons HTTP 403 Forbidden.</li>
    </ol>
    <div class="screenshot-container">
      <img src="${getImgUrl('11_audit_log.png')}" alt="Bypass Blocked">
      <div class="screenshot-caption">Gambar 2.9: Respons sistem menolak akses log audit oleh Manajer.</div>
    </div>

    <!-- 2.11 SUB-SECTIONS -->
    <div class="section-title">2.11 Ringkasan & Analitik Hasil Pengujian (E2E Test Analytics)</div>
    <p>
      Pengujian E2E peran Manajer menunjukkan 11 skenario PASS dan 2 skenario FAIL (sebelum migrasi DB dan penyesuaian permission). Success rate mencapai 73.33%.
    </p>
    
    <table>
      <thead>
        <tr>
          <th>Skenario</th>
          <th style="text-align: center;">Status Awal</th>
          <th style="text-align: center;">Status Akhir</th>
          <th>Keterangan</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>Login Manajer</td>
          <td style="text-align: center;"><span class="badge-status badge-pass">PASS</span></td>
          <td style="text-align: center;"><span class="badge-status badge-pass">PASS</span></td>
          <td>Berhasil masuk ke dashboard.</td>
        </tr>
        <tr>
          <td>SKP Reject (ID 19)</td>
          <td style="text-align: center;"><span class="badge-status badge-pass">PASS</span></td>
          <td style="text-align: center;"><span class="badge-status badge-pass">PASS</span></td>
          <td>Penolakan dokumen berhasil dikirim.</td>
        </tr>
        <tr>
          <td>SKP Approve (ID 23)</td>
          <td style="text-align: center;"><span class="badge-status badge-fail">FAIL</span></td>
          <td style="text-align: center;"><span class="badge-status badge-pass">PASS</span></td>
          <td>Terselesaikan setelah migrasi SQL.</td>
        </tr>
        <tr>
          <td>Legal Approve Kontrak</td>
          <td style="text-align: center;"><span class="badge-status badge-pass">PASS</span></td>
          <td style="text-align: center;"><span class="badge-status badge-pass">PASS</span></td>
          <td>Persetujuan kontrak berjalan lancar.</td>
        </tr>
        <tr>
          <td>Batalkan Transaksi</td>
          <td style="text-align: center;"><span class="badge-status badge-pass">PASS</span></td>
          <td style="text-align: center;"><span class="badge-status badge-pass">PASS</span></td>
          <td>Alasan pembatalan terekam di sistem.</td>
        </tr>
        <tr>
          <td>Bypass Audit Log</td>
          <td style="text-align: center;"><span class="badge-status badge-fail">FAIL</span></td>
          <td style="text-align: center;"><span class="badge-status badge-fail">FAIL</span></td>
          <td>HTTP 403 (Keterbatasan hak akses).</td>
        </tr>
      </tbody>
    </table>

    <div class="section-title">Audit Temuan Kesalahan & Kerusakan Sistem (True Defects / Bugs)</div>
    <table>
      <thead>
        <tr>
          <th style="width: 35mm;">Cacat Sistem</th>
          <th style="width: 15mm; text-align: center;">Urgensi</th>
          <th>Ekspektasi</th>
          <th>Realita</th>
          <th>Dampak</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><b>BUG-01: SKP Approve Crash</b><br><span style="font-size:7.5pt;color:#64748b;">Tabel skp_documents</span></td>
          <td style="text-align: center;"><span class="badge-status badge-status-high">Kritis</span></td>
          <td>Proses persetujuan berhasil tanpa error SQL.</td>
          <td>Error: Kolom <code>sign_token_expires_at</code> tidak ditemukan di skp_documents.</td>
          <td>Memblokir seluruh alur penerbitan nomor SKP sewa. (Telah diperbaiki).</td>
        </tr>
        <tr>
          <td><b>BUG-02: Akses Log Audit Ditolak</b><br><span style="font-size:7.5pt;color:#64748b;">?r=audit</span></td>
          <td style="text-align: center;"><span class="badge-status badge-status-medium">Sedang</span></td>
          <td>Manajer dapat melihat audit trail untuk mengawasi tim.</td>
          <td>HTTP 403 Akses Ditolak.</td>
          <td>Menghalangi pengawasan aktivitas penipuan/manipulasi oleh sales.</td>
        </tr>
      </tbody>
    </table>

    <div class="section-title">Laporan Rekomendasi & Saran Peningkatan (System Improvements)</div>
    <table>
      <thead>
        <tr>
          <th>Rekomendasi</th>
          <th style="text-align: center;">Urgensi</th>
          <th>Alasan Usability</th>
          <th>Dampak Positif jika Diimplementasikan</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><b>Eskalasi Permission Renewal Kontrak</b></td>
          <td style="text-align: center;"><span class="badge-status badge-status-medium">Sedang</span></td>
          <td>Memungkinkan manajer memperbarui status nego perpanjangan sewa tenant.</td>
          <td>Mempercepat siklus follow-up masa sewa yang akan berakhir.</td>
        </tr>
        <tr>
          <td><b>Penambahan ID Unik Kalkulator Komisi</b></td>
          <td style="text-align: center;"><span class="badge-status badge-status-low">Low</span></td>
          <td>Membantu kelancaran script testing QA (Selector ID <code>#calc-btn</code>).</td>
          <td>Meningkatkan kualitas cakupan automated testing sistem.</td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="page-break"></div>

  <!-- BAB 3. TESTING SALES -->
  <div class="section">
    <div class="chapter-title">BAB 3. Testing Sales</div>
    <p>
      Peran <strong>Sales (Casual Leasing Staff)</strong> memegang kendali harian pada pembuatan penawaran sewa tenant (Exhibition / Media), pembagian link persetujuan tanda tangan pelanggan (DEAL), penginputan identitas legalitas (KTP/NPWP), serta pelampiran bukti transaksi transfer pembayaran.
    </p>

    <!-- 3.1 -->
    <div class="section-title">3.1 Login Pertama Kali & Ganti Password</div>
    <p>
      Skenario ini memverifikasi alur penggantian kata sandi pertama kali bagi akun baru. Akun yang memiliki status <code>must_change_password=1</code> wajib meredireksi ke form reset.
    </p>
    <table>
      <thead>
        <tr>
          <th>Nama Field</th>
          <th>Wajib</th>
          <th>Penjelasan</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><b>Password Baru</b></td>
          <td>Ya</td>
          <td>Sandi baru (min 8 karakter, huruf besar, kecil, angka, dan simbol).</td>
        </tr>
        <tr>
          <td><b>Konfirmasi Password</b></td>
          <td>Ya</td>
          <td>Konfirmasi pengulangan sandi baru harus cocok.</td>
        </tr>
      </tbody>
    </table>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Masuk menggunakan email <code>sales.budi@clara.local</code> dan sandi default <code>123456</code>.</li>
      <li>Sistem otomatis mendeteksi status dan mengalihkan ke route <code>?r=change_password</code>.</li>
      <li>Mengisi password baru yang kuat, misalnya <code>Pok3mon2001!</code>.</li>
      <li>Submit form dan masuk ke dashboard sales.</li>
    </ol>
    <div class="callout callout-tip">
      <strong>Kombinasi Sandi:</strong> Kombinasi simbol, angka, dan case-sensitivity wajib dipenuhi di form HTML5.
    </div>

    <!-- 3.2 -->
    <div class="section-title">3.2 Pengaturan Tanda Tangan Saya</div>
    <p>
      Menguji unggah tanda tangan basah sales untuk ditranslasikan menjadi kode verifikasi QR pada dokumen cetak penawaran.
    </p>
    <table>
      <thead>
        <tr>
          <th>Nama Field</th>
          <th>Wajib</th>
          <th>Penjelasan</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><b>File Tanda Tangan</b></td>
          <td>Ya</td>
          <td>Berkas gambar tanda tangan (JPG/PNG).</td>
        </tr>
      </tbody>
    </table>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Buka Pengaturan Akun di pojok kanan atas -> Tanda Tangan Saya.</li>
      <li>Unggah gambar contoh tanda tangan Anda.</li>
      <li>Cetak PDF penawaran fiktif dan pastikan tanda tangan sales ter-render sebagai QR Code Validasi.</li>
    </ol>

    <!-- 3.3 -->
    <div class="section-title">3.3 Pembuatan Surat Penawaran (Single & Bundling)</div>
    <p>
      Pengujian penerbitan surat penawaran (Draft) baik untuk satu unit maupun gabungan beberapa booth (Bundling).
    </p>
    <table>
      <thead>
        <tr>
          <th>Nama Field</th>
          <th>Wajib</th>
          <th>Penjelasan</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><b>Nama Client</b></td>
          <td>Ya</td>
          <td>Dropdown relasional master client (contoh: <code>PT. Raja Retail International</code>).</td>
        </tr>
        <tr>
          <td><b>Unit Sewa</b></td>
          <td>Ya</td>
          <td>Stan lokasi sewa. Rate harian akan termuat otomatis.</td>
        </tr>
        <tr>
          <td><b>Nominal Sewa</b></td>
          <td>Ya</td>
          <td>Harga sewa yang diajukan ke tenant.</td>
        </tr>
        <tr>
          <td><b>Penawaran Paket</b></td>
          <td>Tidak</td>
          <td>Checkbox untuk memicu pengisian multi-komponen unit.</td>
        </tr>
      </tbody>
    </table>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Masuk ke Surat Penawaran -> klik <strong>+ Buat Penawaran</strong>.</li>
      <li>Cari client <code>PT. Raja Retail International</code>.</li>
      <li>Pilih unit GF-001 (Ground Floor - Main Atrium). Masukkan periode sewa.</li>
      <li>Simpan untuk menerbitkan penawaran berstatus <strong>Draft</strong>.</li>
    </ol>
    <div class="callout callout-info">
      <strong>Info Uang Muka:</strong> Perhitungan uang muka (DP) otomatis menjadi 0 untuk unit stall/booth makanan, digantikan dengan security deposit tetap.
    </div>

    <div class="page-break"></div>

    <!-- 3.4 -->
    <div class="section-title">3.4 Negosiasi & Tanda Tangan Customer (DEAL)</div>
    <p>
      Simulasi persetujuan penawaran oleh customer secara mandiri melalui URL share eksternal.
    </p>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Buka detail penawaran draft. Klik <strong>Bagikan / Link TTD</strong>.</li>
      <li>Buka tautan di browser mode Incognito (tanpa login akun).</li>
      <li>Bubuhkan tanda tangan pada canvas area menggunakan mouse/jari, lalu klik Submit.</li>
      <li>Status penawaran pada dashboard sales berubah menjadi <strong>DEAL</strong> secara otomatis.</li>
    </ol>
    <div class="callout callout-warning">
      <strong>Pemberitahuan Sistem:</strong> Transaksi berstatus DEAL secara otomatis terkunci dan tidak dapat disunting kembali demi integritas data.
    </div>

    <!-- 3.5 -->
    <div class="section-title">3.5 Pembuatan SKP / SKS & Pengajuan Persetujuan</div>
    <p>
      Menguji pembuatan dokumen Surat Keputusan Pameran (SKP) dengan input identitas penyewa dan lampiran berkas penunjang wajib.
    </p>
    <table>
      <thead>
        <tr>
          <th>Nama Field</th>
          <th>Wajib</th>
          <th>Penjelasan</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><b>No. KTP</b></td>
          <td>Ya</td>
          <td>Nomor KTP pemilik usaha (16 digit).</td>
        </tr>
        <tr>
          <td><b>NPWP</b></td>
          <td>Ya</td>
          <td>Nomor NPWP badan usaha (15 digit).</td>
        </tr>
        <tr>
          <td><b>Upload Dokumen</b></td>
          <td>Ya</td>
          <td>Lampiran wajib: KTP, NPWP, dan Bukti Transfer DP/Deposit.</td>
        </tr>
      </tbody>
    </table>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Pada penawaran status DEAL, klik tombol <strong>Buat SKP</strong>.</li>
      <li>Isi nomor identitas legal. Unggah dokumen KTP, NPWP, dan Bukti Pembayaran.</li>
      <li>Klik <strong>Submit untuk Approval</strong>. Status SKP berubah menjadi <em>Submitted</em>.</li>
    </ol>

    <!-- 3.6 -->
    <div class="section-title">3.6 Pengajuan Kontrak ke Departemen Legal</div>
    <p>
      Setelah SKP disetujui Manajer, sales mengajukan penerbitan draf perjanjian kontrak legal.
    </p>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Buka menu Permintaan Kontrak -> buat pengajuan dari referensi SKP yang disetujui.</li>
      <li>Unggah Akta Pendirian Perusahaan dan Surat Kuasa (bila ada).</li>
      <li>Klik Kirim ke Legal. Pantau status berkas secara berkala.</li>
    </ol>

    <!-- 3.7 -->
    <div class="section-title">3.7 Dasbor Pribadi & Pemantauan Kinerja (Data Isolation)</div>
    <p>
      Memastikan kerahasiaan dan privasi data penjualan antarfungsional sales terjaga dengan baik.
    </p>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Buka dashboard beranda akun Sales Budi.</li>
      <li>Periksa nominal target dan realisasi, pastikan data sales lain tidak bercampur.</li>
      <li>Buka daftar transaksi Exhibition/Media, pastikan hanya menampilkan transaksi milik Anda.</li>
    </ol>

    <!-- 3.8 -->
    <div class="section-title">3.8 Keamanan & Troubleshooting (Bypass URL)</div>
    <p>
      Percobaan akses langsung rute administrasi untuk memverifikasi proteksi route-level.
    </p>
    <p><b>Langkah-langkah:</b></p>
    <ol>
      <li>Mencoba membuka rute <code>/?r=users</code>.</li>
      <li>Mencoba membuka rute persetujuan manajer.</li>
      <li>Sistem mendeteksi hak akses tidak memadai dan langsung me-redirect kembali ke dashboard.</li>
    </ol>

    <div class="page-break"></div>

    <!-- 3.9 SUB-SECTIONS -->
    <div class="section-title">3.9 Ringkasan & Analitik Hasil Pengujian (E2E Test Analytics)</div>
    <p>
      Seluruh skenario fungsional sales (12 dari 12 item) dinyatakan PASS dengan tingkat keberhasilan 100%.
    </p>
    <div style="display: flex; gap: 4mm; margin-bottom: 4mm;">
      <div style="flex: 1; background-color: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; padding: 10px; text-align: center;">
        <span style="font-size: 8pt; text-transform: uppercase; color: #16a34a; font-weight: 700; display: block;">Tingkat Keberhasilan</span>
        <strong style="font-size: 16pt; color: #15803d; display: block;">100.00%</strong>
      </div>
      <div style="flex: 1; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px; text-align: center;">
        <span style="font-size: 8pt; text-transform: uppercase; color: #475569; font-weight: 700; display: block;">Total Skenario</span>
        <strong style="font-size: 16pt; color: #1e293b; display: block;">12 Item</strong>
      </div>
      <div style="flex: 1; background-color: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; padding: 10px; text-align: center;">
        <span style="font-size: 8pt; text-transform: uppercase; color: #16a34a; font-weight: 700; display: block;">PASS</span>
        <strong style="font-size: 16pt; color: #15803d; display: block;">12 Item</strong>
      </div>
      <div style="flex: 1; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px; text-align: center;">
        <span style="font-size: 8pt; text-transform: uppercase; color: #64748b; font-weight: 700; display: block;">FAIL</span>
        <strong style="font-size: 16pt; color: #64748b; display: block;">0 Item</strong>
      </div>
    </div>

    <div class="section-title">Audit Temuan Kesalahan & Kerusakan Sistem (True Defects / Bugs)</div>
    <p>
      Tidak ada temuan bug kritis yang terlokalisasi khusus di modul Sales selama pengujian E2E.
    </p>

    <div class="section-title">Laporan Rekomendasi & Saran Peningkatan (System Improvements)</div>
    <table>
      <thead>
        <tr>
          <th>Rekomendasi</th>
          <th style="text-align: center;">Urgensi</th>
          <th>Alasan Usability</th>
          <th>Dampak Positif jika Diimplementasikan</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><b>Mekanisme TTD Offline Fallback</b></td>
          <td style="text-align: center;"><span class="badge-status badge-status-low">Low</span></td>
          <td>Mencegah kegagalan pengiriman tanda tangan saat koneksi internet tenant tidak stabil.</td>
          <td>Meminimalkan risiko hilangnya data goresan tanda tangan di canvas.</td>
        </tr>
        <tr>
          <td><b>Notifikasi Real-time Status Persetujuan</b></td>
          <td style="text-align: center;"><span class="badge-status badge-status-low">Low</span></td>
          <td>Sales tidak perlu me-refresh halaman berulang kali untuk mengecek approval manajer/legal.</td>
          <td>Meningkatkan efisiensi kerja operasional tim sales di lapangan.</td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="page-break"></div>

  <!-- BAB 4. ANALISIS KELAYAKAN -->
  <div class="section">
    <div class="chapter-title">BAB 4. Analisis Kelayakan & Evaluasi Kesiapan Rilis</div>
    
    <div class="section-title">4.1 Evaluasi & Justifikasi Kelayakan Sistem</div>
    <p>
      Berdasarkan hasil pengujian UAT menyeluruh pada tiga peran utama (Superadmin, Manager, dan Sales), dilakukan evaluasi kelayakan sistem CLARA untuk diimplementasikan dalam operasional bisnis Casual Leasing sehari-hari:
    </p>
    
    <table style="width: 100%; border-collapse: collapse; margin-top: 4mm; margin-bottom: 6mm;">
      <thead>
        <tr>
          <th style="width: 30%;">Status Kelayakan</th>
          <th style="width: 70%;">Justifikasi dan Alasan Utama</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td style="color: #0d9488; font-weight: bold; font-size: 11pt; vertical-align: middle; text-align: center; background-color: #f0fdfa;">
            LAYAK UNTUK OPERASIONAL<br>
            <span style="font-size: 8.5pt; font-weight: normal; color: #475569;">(Dengan Patch Database & Otorisasi)</span>
          </td>
          <td>
            <ul style="margin: 0; padding-left: 4mm; font-size: 9pt;">
              <li><b>Alur Bisnis Inti Berjalan Baik:</b> Seluruh alur utama Casual Leasing mulai dari pembuatan penawaran oleh Sales, negosiasi & TTD digital customer (DEAL), input legalitas KTP/NPWP, persetujuan SKP oleh Manager, hingga penerbitan kontrak oleh Legal telah diuji secara E2E dan terbukti berfungsi dengan lancar.</li>
              <li><b>Keamanan Data & Isolasi Sesi Kuat:</b> Mekanisme data isolation mencegah Sales melihat atau memodifikasi transaksi Sales lainnya. Hal ini sangat penting untuk mencegah kebocoran informasi komisi dan target antarpromotor.</li>
              <li><b>Proteksi Akses URL (RBAC):</b> Sistem terbukti menolak bypass URL dari pengguna non-otoritas dengan mengembalikan respon HTTP 403 atau me-redirect ke dashboard, sehingga data sensitif (seperti user management dan log audit) terlindung dari akses ilegal.</li>
            </ul>
          </td>
        </tr>
        <tr>
          <td style="color: #b91c1c; font-weight: bold; font-size: 11pt; vertical-align: middle; text-align: center; background-color: #fef2f2;">
            CATATAN KETIDAKLAYAKAN<br>
            <span style="font-size: 8.5pt; font-weight: normal; color: #475569;">(Sebelum Perbaikan Bug)</span>
          </td>
          <td>
            <ul style="margin: 0; padding-left: 4mm; font-size: 9pt;">
              <li><b>BUG-01 (SQL Blocker):</b> Hilangnya kolom <code>sign_token_expires_at</code> di database awal memicu crash fatal ketika Manager mencoba memberikan persetujuan (Approve) SKP. Tanpa perbaikan database ini, sistem <b>TIDAK LAYAK</b> dirilis karena menghambat rantai proses bisnis.</li>
              <li><b>BUG-02 (RBAC Audit Log):</b> Akun Manager ditolak saat mengakses Log Audit karena keterbatasan permission. Ini diselesaikan dengan penambahan hak akses otorisasi log ke peran Manager agar fungsi pengawasan tim tetap efektif.</li>
            </ul>
          </td>
        </tr>
      </tbody>
    </table>

    <div class="section-title">4.2 Analisis Deviasi Skenario Pengujian (Gap Analysis)</div>
    <p>
      Tabel berikut menganalisis deviasi persentase skenario pengujian yang belum berhasil mencapai 100% (seperti 4.35% pada Superadmin dan 26.67% pada Manager) beserta penjelasan penyebab teknis dan solusinya:
    </p>

    <table style="width: 100%; border-collapse: collapse; margin-top: 4mm; margin-bottom: 6mm;">
      <thead>
        <tr>
          <th>Modul Peran</th>
          <th style="text-align: center;">Tingkat Keberhasilan</th>
          <th style="text-align: center;">Persen Deviasi / Gagal</th>
          <th>Penyebab Deviasi / Kegagalan Skenario</th>
          <th>Rekomendasi Tindakan & Solusi</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><b>Superadmin</b></td>
          <td style="text-align: center; font-weight: bold; color: #16a34a;">95.65%</td>
          <td style="text-align: center; font-weight: bold; color: #dc2626;">4.35%</td>
          <td>Tidak tersedianya aksi UI atau tombol <b>Restore / Pulihkan</b> pada halaman Transaksi Dihapus (<code>?r=deleted_transactions</code>), padahal skenario uji mensyaratkan pemulihan data langsung via frontend.</td>
          <td>Perlu ditambahkan tombol aksi pemicu pemulihan transaksi di UI halaman sampah pada iterasi sprint berikutnya. Saat ini pemulihan terpaksa menggunakan query SQL manual.</td>
        </tr>
        <tr>
          <td><b>Manager</b></td>
          <td style="text-align: center; font-weight: bold; color: #16a34a;">73.33%</td>
          <td style="text-align: center; font-weight: bold; color: #dc2626;">26.67%</td>
          <td>Terjadi kegagalan akibat crash skema basis data (BUG-01: hilangnya kolom <code>sign_token_expires_at</code>) serta penolakan akses HTTP 403 saat mengakses Log Audit (BUG-02: keterbatasan hak akses bawaan).</td>
          <td>1. Melakukan migrasi database untuk menambahkan kolom yang hilang.<br>2. Menambahkan otorisasi <code>view_audit</code> untuk Supervisor/Manager di file otorisasi sistem.</td>
        </tr>
        <tr>
          <td><b>Sales</b></td>
          <td style="text-align: center; font-weight: bold; color: #16a34a;">100.00%</td>
          <td style="text-align: center; font-weight: bold; color: #475569;">0.00%</td>
          <td>Tidak ditemukan deviasi. Seluruh 12 skenario pengujian (login, pembuatan penawaran, tanda tangan deal customer, isolasi data) berjalan sukses.</td>
          <td>Sangat layak dan siap di-deploy secara penuh untuk aktivitas promosi sales di lapangan.</td>
        </tr>
      </tbody>
    </table>
  </div>

</body>
</html>
`;

(async () => {
  console.log("Starting PDF generation...");
  
  const browser = await puppeteer.launch({
    headless: true,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--allow-file-access-from-files'
    ]
  });
  
  const page = await browser.newPage();
  
  // Set content directly
  await page.setContent(htmlContent, { waitUntil: 'networkidle0' });
  
  // Print PDF with specific margins and displayHeaderFooter: true
  await page.pdf({
    path: PDF_PATH,
    format: 'A4',
    printBackground: true,
    displayHeaderFooter: true,
    headerTemplate: '<span></span>', // Empty header
    footerTemplate: `
      <div style="font-family: 'Inter', sans-serif; font-size: 8px; color: #94a3b8; width: 100%; display: flex; justify-content: space-between; padding: 0 18mm; box-sizing: border-box;">
        <span>Laporan UAT - CLARA System</span>
        <span>Halaman <span class="pageNumber"></span> dari <span class="totalPages"></span></span>
      </div>
    `,
    margin: {
      top: '12mm',
      right: '18mm',
      bottom: '16mm',
      left: '18mm'
    }
  });
  
  await browser.close();
  console.log(`PDF successfully generated at: ${PDF_PATH}`);
})();
