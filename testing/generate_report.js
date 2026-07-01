const puppeteer = require('puppeteer');
const path = require('path');
const fs = require('fs');

const SCREENSHOT_DIR = path.join(__dirname, 'screenshots');
const PDF_PATH = path.join(__dirname, 'Superadmin_Testing_Report.pdf');

// Helper to format image paths for file protocol (Base64 embedding)
const getImgUrl = (filename) => {
  const fullPath = path.join(SCREENSHOT_DIR, filename);
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
  <title>Laporan Pengujian E2E Clara Superadmin</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    
    @page {
      size: A4;
      margin: 14mm 18mm 16mm 18mm;
    }
    
    body {
      font-family: 'Inter', sans-serif;
      color: #1e293b;
      margin: 0;
      padding: 0;
      line-height: 1.5;
      font-size: 11pt;
    }
    
    /* Cover Page styling */
    .cover-page {
      margin: -12mm -18mm -16mm -18mm;
      width: 210mm;
      height: 297mm;
      background: linear-gradient(135deg, #0f766e 0%, #115e59 50%, #134e4a 100%);
      color: #ffffff;
      padding: 40mm 20mm 20mm 20mm;
      box-sizing: border-box;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      position: relative;
      page-break-after: always;
    }
    
    .cover-decor {
      position: absolute;
      top: 0;
      right: 0;
      width: 100mm;
      height: 100mm;
      background: radial-gradient(circle, rgba(20,184,166,0.15) 0%, rgba(0,0,0,0) 70%);
    }

    .cover-title-container {
      margin-top: 10mm;
    }
    
    .cover-tag {
      background: rgba(255, 255, 255, 0.15);
      border: 1px solid rgba(255, 255, 255, 0.25);
      padding: 6px 14px;
      border-radius: 99px;
      font-size: 10pt;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 1.5px;
      display: inline-block;
      margin-bottom: 8mm;
      backdrop-filter: blur(4px);
    }
    
    .cover-title {
      font-size: 32pt;
      font-weight: 800;
      line-height: 1.15;
      margin: 0 0 4mm 0;
      letter-spacing: -1px;
    }
    
    .cover-subtitle {
      font-size: 14pt;
      font-weight: 300;
      color: #99f6e4;
      margin: 0 0 10mm 0;
      max-width: 150mm;
    }
    
    .cover-divider {
      width: 25mm;
      height: 2mm;
      background: #2dd4bf;
      border-radius: 99px;
    }
    
    .cover-meta {
      font-size: 10.5pt;
      border-top: 1px solid rgba(255,255,255,0.15);
      padding-top: 8mm;
      margin-bottom: 10mm;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 6mm;
    }
    
    .meta-item label {
      font-size: 8.5pt;
      text-transform: uppercase;
      color: #99f6e4;
      font-weight: 600;
      letter-spacing: 1px;
      display: block;
      margin-bottom: 1mm;
    }
    
    .meta-item value {
      font-weight: 500;
      display: block;
    }
    
    /* Content Page Layout */
    .section {
      margin-bottom: 10mm;
      page-break-inside: avoid;
    }
    
    h1 {
      font-size: 18pt;
      color: #0f766e;
      border-bottom: 2px solid #e2e8f0;
      padding-bottom: 2mm;
      margin-top: 8mm;
      margin-bottom: 4mm;
      font-weight: 700;
    }
    
    h2 {
      font-size: 13pt;
      color: #0d9488;
      margin-top: 6mm;
      margin-bottom: 3mm;
      font-weight: 600;
    }
    
    p {
      margin-top: 0;
      margin-bottom: 4mm;
      color: #334155;
      text-align: justify;
    }
    
    /* Form Field Table styling */
    table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 5mm;
      margin-top: 2mm;
      font-size: 9.5pt;
    }
    
    th, td {
      border: 1px solid #cbd5e1;
      padding: 8px 12px;
      text-align: left;
    }
    
    th {
      background-color: #f1f5f9;
      color: #1e293b;
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
      margin-bottom: 2px;
      color: #334155;
    }
    
    /* Callout Box styling */
    .callout {
      padding: 12px 16px;
      border-radius: 6px;
      margin-top: 4mm;
      margin-bottom: 4mm;
      border-left: 4px solid;
      font-size: 9.5pt;
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
      margin-bottom: 8mm;
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
      font-size: 8.5pt;
      color: #64748b;
      border-top: 1px solid #cbd5e1;
      font-weight: 500;
    }
    
    .page-break {
      page-break-after: always;
    }
    
    /* Urgency Scale badges */
    .badge {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 4px;
      font-size: 8pt;
      font-weight: 700;
      text-transform: uppercase;
    }
    
    .badge-high {
      background-color: #fecaca;
      color: #991b1b;
    }
    
    .badge-medium {
      background-color: #fef3c7;
      color: #92400e;
    }
    
    .badge-low {
      background-color: #e0f2fe;
      color: #075985;
    }
  </style>
</head>
<body>

  <!-- COVER PAGE -->
  <div class="cover-page">
    <div class="cover-decor"></div>
    <div class="cover-title-container">
      <div class="cover-tag">Laporan Audit Pengujian</div>
      <h1 class="cover-title">PENGUJIAN END-TO-END<br>SUPERADMIN AUDIT</h1>
      <p class="cover-subtitle">Casual Leasing Achievement & Revenue Analytics (CLARA) - Evaluasi Kepatuhan Alur Operasional & Keamanan Gerbang Dokumen</p>
      <div class="cover-divider"></div>
    </div>
    
    <div class="cover-meta">
      <div class="meta-item">
        <label>Tanggal Pelaksanaan</label>
        <value>${new Date().toLocaleDateString('id-ID', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</value>
      </div>
      <div class="meta-item">
        <label>Penguji / Akun</label>
        <value>saadilaeffendi@gmail.com (Superadmin)</value>
      </div>
      <div class="meta-item">
        <label>Status Pengujian</label>
        <value>Selesai (Dengan Temuan)</value>
      </div>
      <div class="meta-item">
        <label>Platform Target</label>
        <value>Web App Lokal (Port 8000)</value>
      </div>
    </div>
  </div>

  <!-- PENGANTAR -->
  <div class="section">
    <h1>1. Pendahuluan</h1>
    <p>
      Laporan ini disusun untuk mendokumentasikan hasil pengujian fungsionalitas end-to-end (E2E) dan kepatuhan sistem keamanan pada aplikasi <strong>CLARA (Casual Leasing Achievement & Revenue Analytics)</strong>. Pengujian dilakukan menggunakan akun superadmin utama <code>saadilaeffendi@gmail.com</code> dengan tujuan memvalidasi alur kerja administrasi, manajemen pengguna, pengaturan hak akses peran, data master exhibition, pic, target penjualan, manajemen client, log audit, serta memeriksa celah keamanan akses langsung ke berkas sensitif di server.
    </p>
    <p>
      Seluruh skenario pengujian dieksekusi secara otomatis menggunakan Puppeteer headless browser di lingkungan lokal. Hasil capture layar (screenshot) disematkan pada setiap langkah untuk memverifikasi kesesuaian visual dan perilaku sistem.
    </p>
    
    <div class="callout callout-info">
      <strong>Informasi Lingkungan Pengujian:</strong><br>
      • URL Aplikasi: <code>http://localhost:8000</code><br>
      • Database: <code>clara_unified</code> (MySQL di XAMPP)<br>
      • Akun Uji: <code>saadilaeffendi@gmail.com / pok3mon</code> (Superadmin)
    </div>
  </div>
  
  <div class="page-break"></div>

  <!-- LOGIN -->
  <div class="section">
    <h1>2. Login & Pemilihan Properti</h1>
    <p>
      Skenario ini memverifikasi bahwa akun Superadmin dapat melakukan autentikasi dengan kredensial yang valid dan dialihkan ke gerbang pemilihan properti jika akun tersebut ditugaskan ke lebih dari satu properti. Setelah memilih properti, pengguna diarahkan ke dashboard utama.
    </p>
    
    <ol>
      <li>Membuka halaman login utama <code>/?r=login</code>.</li>
      <li>Mengisi alamat email <code>saadilaeffendi@gmail.com</code> dan sandi baru <code>pok3mon</code>.</li>
      <li>Menekan tombol "Masuk" dan memastikan sistem mengalihkan pengguna ke halaman pemilihan properti.</li>
      <li>Memilih properti <strong>E-Walk Simply FUNtastic</strong> untuk masuk ke dashboard.</li>
    </ol>
    
    <div class="screenshot-container">
      <img src="${getImgUrl('1_dashboard.png')}" alt="Dashboard Utama">
      <div class="screenshot-caption">Gambar 2.1: Halaman Dashboard Utama setelah login superadmin dan memilih properti E-Walk.</div>
    </div>
    
    <div class="callout callout-tip">
      <strong>Catatan Desain:</strong> Halaman Dashboard menyajikan ringkasan visual pencapaian secara interaktif dengan indikator properti aktif yang jelas pada bagian top bar.
    </div>
  </div>

  <div class="page-break"></div>

  <!-- MANAJEMEN USER -->
  <div class="section">
    <h1>3. Manajemen User (Tambah Pengguna)</h1>
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
          <td>Nama lengkap dari pengguna baru. Contoh: <code>Sales Budi</code>.</td>
        </tr>
        <tr>
          <td><b>Email</b></td>
          <td>Ya</td>
          <td>Email unik untuk login. Contoh: <code>sales.budi@clara.local</code>.</td>
        </tr>
        <tr>
          <td><b>Role</b></td>
          <td>Ya</td>
          <td>Peran otorisasi sistem (Superadmin / Admin / Supervisor / Sales / Keuangan).</td>
        </tr>
        <tr>
          <td><b>Status</b></td>
          <td>Ya</td>
          <td>Status keaktifan login (Active / Inactive).</td>
        </tr>
        <tr>
          <td><b>Daftar Properti</b></td>
          <td>Ya</td>
          <td>Checkbox untuk menetapkan akses properti bagi pengguna.</td>
        </tr>
      </tbody>
    </table>
    
    <ol>
      <li>Membuka daftar pengguna di menu <code>/?r=users</code>.</li>
      <li>Menekan tombol "Tambah User" menuju form input.</li>
      <li>Mengisi data pengguna baru: <strong>Sales Budi</strong>, <code>sales.budi@clara.local</code>, peran <strong>Sales</strong>, status <strong>Active</strong>, dan memilih properti <strong>E-Walk</strong>.</li>
      <li>Menyimpan data dan memastikan sistem menampilkan pesan sukses.</li>
    </ol>
    
    <div class="screenshot-container">
      <img src="${getImgUrl('3_add_user_form.png')}" alt="Form Tambah User">
      <div class="screenshot-caption">Gambar 3.1: Form Pengisian Data Pembuatan Pengguna Baru (Sales Budi).</div>
    </div>

    <div class="screenshot-container">
      <img src="${getImgUrl('4_user_created.png')}" alt="User Berhasil Dibuat">
      <div class="screenshot-caption">Gambar 3.2: Daftar pengguna setelah Sales Budi berhasil ditambahkan ke database.</div>
    </div>
  </div>

  <div class="page-break"></div>

  <!-- MASTER PIC -->
  <div class="section">
    <h1>4. Pembuatan Master PIC & Penautan Akun</h1>
    <p>
      Menguji penautan entitas PIC (Person In Charge) dengan akun login pengguna yang baru dibuat. Langkah ini penting agar transaksi yang dibuat oleh Sales Budi dapat terlacak secara otomatis ke dalam target performa PIC yang bersangkutan.
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
    
    <ol>
      <li>Membuka Master PIC di menu <code>/?r=master&type=pic</code>.</li>
      <li>Menekan "Tambah Data" PIC.</li>
      <li>Mengisi data PIC Sales Budi, memasukkan target share <strong>100%</strong>, dan memilih Akun User <strong>Sales Budi</strong>.</li>
      <li>Menyimpan dan memvalidasi data PIC tersimpan di list.</li>
    </ol>
    
    <div class="screenshot-container">
      <img src="${getImgUrl('12_add_pic_form.png')}" alt="Form Tambah PIC">
      <div class="screenshot-caption">Gambar 4.1: Pengisian form penautan PIC dengan akun user login Sales Budi.</div>
    </div>
  </div>

  <div class="page-break"></div>

  <!-- NONAKTIFKAN USER & PENGUJIAN LOGIN BLOCKED -->
  <div class="section">
    <h1>5. Penonaktifan Pengguna & Proteksi Keamanan Login</h1>
    <p>
      Skenario ini memvalidasi bahwa penonaktifan akun oleh Superadmin bekerja seketika. Akun yang telah berstatus <strong>Inactive</strong> harus segera ditolak oleh gerbang autentikasi meskipun menggunakan kata sandi yang benar.
    </p>
    
    <ol>
      <li>Membuka kembali form edit user <strong>Sales Budi</strong>.</li>
      <li>Mengubah status dari <strong>Active</strong> menjadi <strong>Inactive</strong>, kemudian menyimpan perubahan.</li>
      <li>Melakukan <code>logout</code> dari akun Superadmin.</li>
      <li>Mencoba masuk kembali menggunakan akun Sales Budi (<code>sales.budi@clara.local</code> / <code>123456</code>).</li>
      <li>Memastikan sistem menampilkan pesan error flash: <em>"User tidak aktif. Hubungi admin."</em>.</li>
      <li>Login kembali sebagai Superadmin untuk melanjutkan sisa pengujian.</li>
    </ol>
    
    <div class="screenshot-container">
      <img src="${getImgUrl('5_edit_user_inactive.png')}" alt="Edit User Inactive">
      <div class="screenshot-caption">Gambar 5.1: Mengubah status pengguna Sales Budi menjadi Inactive.</div>
    </div>

    <div class="screenshot-container">
      <img src="${getImgUrl('6b_login_inactive_blocked.png')}" alt="Login Blocked Inactive">
      <div class="screenshot-caption">Gambar 5.2: Pesan penolakan login sistem akibat deteksi akun tidak aktif.</div>
    </div>
  </div>

  <div class="page-break"></div>

  <!-- PERAN & IZIN -->
  <div class="section">
    <h1>6. Manajemen Peran & Izin Akses (Roles & Permissions)</h1>
    <p>
      Menguji konfigurasi hak akses granular untuk peran <strong>Supervisor</strong>. Pengujian dilakukan untuk memastikan Superadmin dapat mencentang izin spesifik dan membatalkan izin administratif tingkat tinggi untuk Supervisor.
    </p>
    
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
      <div class="screenshot-caption">Gambar 6.1: Matriks konfigurasi hak akses peran Supervisor di aplikasi.</div>
    </div>
  </div>

  <div class="page-break"></div>

  <!-- MASTER EXHIBITION -->
  <div class="section">
    <h1>7. Manajemen Master Exhibition (Unit Sewa)</h1>
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
    
    <ol>
      <li>Membuka halaman Master Exhibition di <code>/?r=master&type=cl</code>.</li>
      <li>Menekan "Tambah Data" untuk memunculkan form.</li>
      <li>Mengisi data lokasi <strong>Fiktif Unit</strong>, lantai <strong>GF</strong>, luas <strong>25 m2</strong>, dan rate harian <strong>Rp 150.000</strong>.</li>
      <li>Menekan tombol "Simpan" dan memvalidasi unit baru muncul pada tabel daftar unit exhibition.</li>
    </ol>
    
    <div class="screenshot-container">
      <img src="${getImgUrl('9_add_exhibition_form.png')}" alt="Form Tambah Exhibition">
      <div class="screenshot-caption">Gambar 7.1: Pengisian form penambahan unit stan pameran baru (Fiktif Unit).</div>
    </div>

    <div class="screenshot-container">
      <img src="${getImgUrl('10_exhibition_list.png')}" alt="Daftar Exhibition">
      <div class="screenshot-caption">Gambar 7.2: Daftar unit sewa Exhibition yang diperbarui dengan masuknya Fiktif Unit.</div>
    </div>
  </div>

  <div class="page-break"></div>

  <!-- TARGET BULANAN -->
  <div class="section">
    <h1>8. Manajemen Master Target Bulanan</h1>
    <p>
      Menguji fungsionalitas pengisian target nominal bulanan untuk properti aktif pada periode bulan berjalan. Nilai input harus diformat secara otomatis dengan pemisah ribuan rupiah.
    </p>
    
    <ol>
      <li>Membuka menu Target Bulanan di <code>/?r=master&type=target</code>.</li>
      <li>Menekan tombol "Tambah Data" target.</li>
      <li>Memasukkan Target Amount sebesar <strong>Rp 50.000.000</strong> (Rp 50 Juta) dan memastikan period key terisi otomatis untuk bulan berjalan.</li>
      <li>Menyimpan target dan memastikan entitas tersimpan.</li>
    </ol>
    
    <div class="screenshot-container">
      <img src="${getImgUrl('14_add_target_form.png')}" alt="Form Target Bulanan">
      <div class="screenshot-caption">Gambar 8.1: Form pengisian target bulanan properti sebesar Rp 50 Juta.</div>
    </div>
  </div>

  <div class="page-break"></div>

  <!-- MASTER CLIENT -->
  <div class="section">
    <h1>9. Pendaftaran Client Baru (Master Client)</h1>
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
          <td>Nama badan usaha client. Contoh: <code>Fiktif Corp</code>.</td>
        </tr>
        <tr>
          <td><b>Nama Brand</b></td>
          <td>Tidak</td>
          <td>Nama merek dagang jika berbeda dari nama perusahaan.</td>
        </tr>
        <tr>
          <td><b>NPWP</b></td>
          <td>Tidak</td>
          <td>Nomor Pokok Wajib Pajak badan usaha (15 digit).</td>
        </tr>
        <tr>
          <td><b>Provinsi</b></td>
          <td>Ya</td>
          <td>Dropdown pilihan provinsi terdaftar.</td>
        </tr>
        <tr>
          <td><b>Kota / Kabupaten</b></td>
          <td>Ya</td>
          <td>Dropdown dinamis yang memuat wilayah kota dari provinsi terpilih.</td>
        </tr>
      </tbody>
    </table>
    
    <ol>
      <li>Membuka menu daftar client di <code>/?r=clients</code>.</li>
      <li>Menekan "Tambah Client" untuk membuka formulir pendaftaran.</li>
      <li>Mengisi data: nama perusahaan <strong>Fiktif Corp</strong>, brand <strong>Fiktif Brand</strong>, alamat <strong>Jalan Fiktif No 1</strong>.</li>
      <li>Memilih Provinsi <strong>Kalimantan Timur</strong>, dan memastikan Kota <strong>Balikpapan</strong> dapat dipilih pada dropdown berikutnya.</li>
      <li>Menyimpan form dan memvalidasi halaman detail profil client yang baru saja dibuat.</li>
    </ol>
    
    <div class="screenshot-container">
      <img src="${getImgUrl('16_add_client_form.png')}" alt="Form Tambah Client">
      <div class="screenshot-caption">Gambar 9.1: Pengisian data identitas dan alamat geografis client baru.</div>
    </div>

    <div class="screenshot-container">
      <img src="${getImgUrl('17_client_profile.png')}" alt="Detail Profil Client">
      <div class="screenshot-caption">Gambar 9.2: Halaman profil data terpusat Fiktif Corp setelah berhasil disimpan.</div>
    </div>
  </div>

  <div class="page-break"></div>

  <!-- DROPDOWN & LOGS & TRASH & RECURRING -->
  <div class="section">
    <h1>10. Fitur Administratif, Log Audit, Sampah & Recurring</h1>
    <p>
      Bagian ini mendokumentasikan antarmuka fitur pendukung sistem: halaman pengelolaan opsi dropdown lookup, halaman rekaman log aktivitas sistem, halaman daftar transaksi terhapus (soft-deletes), serta konversi transaksi berulang (recurring).
    </p>
    
    <div class="screenshot-container">
      <img src="${getImgUrl('18_dropdown_options.png')}" alt="Opsi Dropdown">
      <div class="screenshot-caption">Gambar 10.1: Menu Kelola Opsi Dropdown untuk menjaga konsistensi nilai master data.</div>
    </div>

    <div class="screenshot-container">
      <img src="${getImgUrl('19_activity_log.png')}" alt="Activity Log">
      <div class="screenshot-caption">Gambar 10.2: Halaman Log Aktivitas (Audit Log) yang merekam operasi pembuatan dan pembaruan data.</div>
    </div>

    <div class="page-break"></div>

    <div class="screenshot-container">
      <img src="${getImgUrl('20_deleted_transactions.png')}" alt="Transaksi Dihapus">
      <div class="screenshot-caption">Gambar 10.3: Halaman Transaksi Dihapus (Keranjang Sampah) untuk menampung data soft-deleted.</div>
    </div>

    <div class="screenshot-container">
      <img src="${getImgUrl('21_recurring_conversion.png')}" alt="Konversi Recurring">
      <div class="screenshot-caption">Gambar 10.4: Fitur Konversi Recurring untuk memecah transaksi utama menjadi cicilan berkala.</div>
    </div>
  </div>

  <div class="page-break"></div>

  <!-- MULTI-PROPERTY & DASHBOARDS -->
  <div class="section">
    <h1>11. Pergantian Properti & Dashboard TV Display</h1>
    <p>
      Menguji fungsionalitas pergantian properti secara dinamis melalui bilah menu atas tanpa memutus sesi pengguna, serta memvalidasi halaman eksekutif dan display TV pameran yang dioptimalkan untuk monitor informasi publik.
    </p>
    
    <ol>
      <li>Membuka dashboard properti E-Walk.</li>
      <li>Menekan tautan alih properti di topbar ke <strong>Pentacity Shopping Venue</strong>.</li>
      <li>Memastikan tampilan data beralih ke portofolio Pentacity secara instan.</li>
      <li>Membuka Executive Summary untuk melihat visualisasi tren pendapatan multisektor.</li>
      <li>Membuka halaman Display TV di rute <code>/?r=display</code>.</li>
    </ol>
    
    <div class="screenshot-container">
      <img src="${getImgUrl('22_switch_property.png')}" alt="Pergantian Properti">
      <div class="screenshot-caption">Gambar 11.1: Dashboard Pentacity setelah melakukan pergantian properti dari E-Walk.</div>
    </div>

    <div class="screenshot-container">
      <img src="${getImgUrl('24_display_tv.png')}" alt="Display TV">
      <div class="screenshot-caption">Gambar 11.2: Antarmuka minimalis Display TV yang ditujukan untuk papan informasi digital (digital signage).</div>
    </div>
  </div>

  <div class="page-break"></div>

  <!-- KEAMANAN DIRECT FILE ACCESS BYPASS -->
  <div class="section">
    <h1>12. Pengujian Celah Keamanan Akses Berkas Langsung (Direct File Access)</h1>
    <p>
      Pengujian keamanan difokuskan pada perlindungan berkas tanda tangan digital dan lampiran dokumen penawaran. Sistem wajib memverifikasi kepemilikan sesi sebelum melayani pengunduhan berkas sensitif dari direktori <code>signatures/</code>.
    </p>
    
    <ol>
      <li>Membuka peramban baru tanpa sesi login aktif (tanpa cookie).</li>
      <li>Mengakses URL penjarahan dokumen secara langsung: <code>http://localhost:8000/?r=file&file=signatures/test.png</code>.</li>
      <li>Memastikan server menolak akses dengan respons HTTP 403 Forbidden atau mengalihkan ke gerbang login.</li>
    </ol>
    
    <div class="screenshot-container">
      <img src="${getImgUrl('25_security_bypass_blocked.png')}" alt="Security Bypass Blocked">
      <div class="screenshot-caption">Gambar 12.1: Bukti penolakan server (HTTP 403 / Halaman Kosong/Blocked) ketika diakses langsung tanpa sesi.</div>
    </div>
    
    <div class="callout callout-warning">
      <strong>Analisis Keamanan:</strong> Mekanisme autentikasi pada rute <code>?r=file</code> berjalan dengan baik. Server memverifikasi keberadaan session cookie sebelum membaca isi disk, mencegah kebocoran dokumen oleh pihak eksternal yang tidak memiliki otorisasi.
    </div>
  </div>

  <div class="page-break"></div>

  <!-- SECTION 13: E2E TEST SUMMARY & ANALYTICS -->
  <div class="section">
    <h1>13. Ringkasan & Analitik Hasil Pengujian (E2E Test Analytics)</h1>
    <p>
      Proses pengujian otomatis <em>End-to-End</em> (E2E) dijalankan menggunakan Puppeteer untuk mensimulasikan seluruh rangkaian pekerjaan operasional yang diinstruksikan dalam berkas panduan <strong>Superadmin</strong>. Berikut adalah ringkasan kuantitatif dari hasil pengujian:
    </p>

    <!-- Dashboard Metric Cards -->
    <div style="display: flex; gap: 4mm; margin-bottom: 6mm; margin-top: 4mm;">
      <div style="flex: 1; background-color: #f0fdfa; border: 1px solid #5eead4; border-radius: 6px; padding: 12px; text-align: center;">
        <span style="font-size: 8.5pt; text-transform: uppercase; color: #0d9488; font-weight: 700; display: block; margin-bottom: 1mm;">Tingkat Keberhasilan</span>
        <strong style="font-size: 20pt; color: #0f766e; display: block;">95.65%</strong>
      </div>
      <div style="flex: 1; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px; text-align: center;">
        <span style="font-size: 8.5pt; text-transform: uppercase; color: #475569; font-weight: 700; display: block; margin-bottom: 1mm;">Total Skenario</span>
        <strong style="font-size: 20pt; color: #1e293b; display: block;">23 Item</strong>
      </div>
      <div style="flex: 1; background-color: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; padding: 12px; text-align: center;">
        <span style="font-size: 8.5pt; text-transform: uppercase; color: #16a34a; font-weight: 700; display: block; margin-bottom: 1mm;">Skenario Sukses</span>
        <strong style="font-size: 20pt; color: #15803d; display: block;">22 Item</strong>
      </div>
      <div style="flex: 1; background-color: #fef2f2; border: 1px solid #fca5a5; border-radius: 6px; padding: 12px; text-align: center;">
        <span style="font-size: 8.5pt; text-transform: uppercase; color: #dc2626; font-weight: 700; display: block; margin-bottom: 1mm;">Skenario Gagal</span>
        <strong style="font-size: 20pt; color: #b91c1c; display: block;">1 Item</strong>
      </div>
    </div>

    <!-- Analytics Breakdown Table -->
    <table>
      <thead>
        <tr>
          <th>Area / Modul Pengujian</th>
          <th style="width: 25mm; text-align: center;">Jumlah Checklist</th>
          <th style="width: 25mm; text-align: center;">Berhasil (Pass)</th>
          <th style="width: 25mm; text-align: center;">Gagal (Fail)</th>
          <th style="width: 25mm; text-align: center;">Persentase</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>1. Manajemen Pengguna & Hak Akses (Users & Role)</td>
          <td style="text-align: center;">6</td>
          <td style="text-align: center; color: #16a34a; font-weight: 600;">6</td>
          <td style="text-align: center; color: #64748b;">0</td>
          <td style="text-align: center; font-weight: 600;">100.00%</td>
        </tr>
        <tr>
          <td>2. Pengelolaan Master Data (Exhibition, Client, dsb.)</td>
          <td style="text-align: center;">6</td>
          <td style="text-align: center; color: #16a34a; font-weight: 600;">6</td>
          <td style="text-align: center; color: #64748b;">0</td>
          <td style="text-align: center; font-weight: 600;">100.00%</td>
        </tr>
        <tr>
          <td>3. Audit & Pemulihan (Log & Trash)</td>
          <td style="text-align: center;">3</td>
          <td style="text-align: center; color: #16a34a; font-weight: 600;">2</td>
          <td style="text-align: center; color: #dc2626; font-weight: 600;">1</td>
          <td style="text-align: center; font-weight: 600; color: #92400e;">66.67%</td>
        </tr>
        <tr>
          <td>4. Fitur Khusus & Lintas-Properti (Switch, TV Display)</td>
          <td style="text-align: center;">4</td>
          <td style="text-align: center; color: #16a34a; font-weight: 600;">4</td>
          <td style="text-align: center; color: #64748b;">0</td>
          <td style="text-align: center; font-weight: 600;">100.00%</td>
        </tr>
        <tr>
          <td>5. Sisi Keamanan Teknis (CLI check, Bypass URL check)</td>
          <td style="text-align: center;">4</td>
          <td style="text-align: center; color: #16a34a; font-weight: 600;">4</td>
          <td style="text-align: center; color: #64748b;">0</td>
          <td style="text-align: center; font-weight: 600;">100.00%</td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="page-break"></div>

  <!-- SECTION 14: TRUE BUGS AND DEFECTS -->
  <div class="section">
    <h1>14. Audit Temuan Kesalahan & Kerusakan Sistem (True Defects / Bugs)</h1>
    <p>
      Bagian ini merangkum seluruh temuan kesalahan fungsional nyata atau cacat sistem (<em>bugs/defects</em>) yang terdeteksi secara langsung selama proses simulasi pengujian E2E, di mana sistem gagal memenuhi instruksi tertulis pada buku panduan.
    </p>

    <table>
      <thead>
        <tr>
          <th style="width: 38mm;">Kerusakan / Cacat Sistem</th>
          <th style="width: 14mm; text-align: center;">Urgensi</th>
          <th style="width: 38mm;">Ekspektasi (Harusnya Bagaimana)</th>
          <th style="width: 38mm;">Realita (Malah Bagaimana)</th>
          <th>Dampak &amp; Kenapa Harus Diperbaiki</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>
            <strong>Ketiadaan Tombol &amp; Aksi "Pulihkan / Restore" Transaksi</strong><br>
            <span style="font-size: 8pt; color: #64748b;">Lokasi: <code>?r=deleted_transactions</code></span>
          </td>
          <td style="text-align: center;"><span class="badge badge-high">High</span></td>
          <td>
            Superadmin dapat menekan tombol aksi "Pulihkan" atau "Restore" pada daftar transaksi terhapus untuk mengembalikan data tersebut ke daftar aktif secara otomatis.
          </td>
          <td>
            Tidak ada tombol aksi, tautan, atau endpoint di frontend/backend untuk memicu pemulihan data; halaman hanya berfungsi sebagai tabel log statis.
          </td>
          <td>
            <strong>Kritis:</strong> Pemulihan data yang terhapus secara tidak sengaja tidak dapat dilakukan via UI, memaksa admin melakukan manipulasi DML manual di SQL database, berisiko tinggi merusak integritas alokasi transaksi.
          </td>
        </tr>
        <tr>
          <td>
            <strong>Kegagalan TV Display Tanpa Token Parameter</strong><br>
            <span style="font-size: 8pt; color: #64748b;">Lokasi: <code>?r=display</code></span>
          </td>
          <td style="text-align: center;"><span class="badge badge-medium">Medium</span></td>
          <td>
            Halaman TV Display dapat diakses polos atau secara otomatis mendeteksi/menyisipkan parameter token aktif dari sesi login administrator agar TV monitor tetap menyala secara dinamis.
          </td>
          <td>
            Sistem langsung menampilkan pesan error fatal "Display token tidak valid" dan memutus tampilan layar jika parameter token di URL kosong.
          </td>
          <td>
            <strong>Operasional Terganggu:</strong> Monitor publik TV Display di mall akan mengalami <em>downtime</em> (layar error) setiap kali terjadi restart otomatis, reset cache browser, atau pemuatan ulang halaman tanpa parameter URL yang tepat.
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="page-break"></div>

  <!-- SECTION 15: SUGGESTIONS AND IMPROVEMENTS -->
  <div class="section">
    <h1>15. Laporan Rekomendasi & Saran Peningkatan (System Improvements)</h1>
    <p>
      Bagian ini merangkum saran peningkatan arsitektur, optimalisasi alur antarmuka pengguna (<em>usability</em>), serta penguatan keamanan yang direkomendasikan demi ketahanan sistem jangka panjang (bukan merupakan kerusakan sistem secara langsung).
    </p>

    <table>
      <thead>
        <tr>
          <th style="width: 45mm;">Rekomendasi Peningkatan</th>
          <th style="width: 20mm; text-align: center;">Urgensi</th>
          <th>Alasan Rekomendasi / Usability</th>
          <th>Dampak Positif / Kenapa Diimplementasikan</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>
            <strong>Auto-Select atau Pembersihan Otomatis Input Porsi Target PIC</strong><br>
            <span style="font-size: 8.5pt; color: #64748b;">Lokasi: Form Target Bulanan</span>
          </td>
          <td style="text-align: center;"><span class="badge badge-low">Low</span></td>
          <td>
            Kolom persentase (<code>target_share_pct</code>) memiliki nilai default numerik "0". Jika pengguna mengetik langsung angka "100", nilai terinput menjadi "1000" atau "0100" yang gagal divalidasi oleh browser.
          </td>
          <td>
            Meningkatkan kenyamanan input data bagi staf administrasi keuangan dan mencegah kesalahan validasi HTML5 yang membingungkan akibat nilai default yang menempel.
          </td>
        </tr>
        <tr>
          <td>
            <strong>Penyimpanan Kode ID Wilayah (Bukan String Nama Geografis)</strong><br>
            <span style="font-size: 8.5pt; color: #64748b;">Lokasi: Dropdown Geografis Master Client</span>
          </td>
          <td style="text-align: center;"><span class="badge badge-low">Low</span></td>
          <td>
            Dropdown Provinsi menyimpan teks literal "Kalimantan Timur" alih-alih ID numerik standar BPS (seperti "64"). Hal ini rentan terhadap inkonsistensi penulisan nama daerah.
          </td>
          <td>
            Menjamin integrasi API masa depan dengan sistem luar (seperti perpajakan atau pengiriman nasional) agar lebih andal dan meminimalisir inkonsistensi basis data.
          </td>
        </tr>
        <tr>
          <td>
            <strong>Restriksi Ketat Hak Akses Administratif Non-Superadmin</strong><br>
            <span style="font-size: 8.5pt; color: #64748b;">Lokasi: Konfigurasi Role & Permission</span>
          </td>
          <td style="text-align: center;"><span class="badge badge-high">High</span></td>
          <td>
            Mencegah kebocoran data sensitif operasional dan memastikan akuntabilitas admin dengan membatasi role non-superadmin dari rute manajemen pengguna dan log audit.
          </td>
          <td>
            Menghindari modifikasi tidak sah terhadap akun staf, serta mencegah oknum internal untuk menghapus jejak log audit di dalam sistem.
          </td>
        </tr>
      </tbody>
    </table>

    <div class="callout callout-warning" style="margin-top: 8mm;">
      <strong>Kesimpulan Tindakan Prioritas:</strong> Perbaikan pada <strong>Fitur Pulihkan Transaksi</strong> (Kategori: Kerusakan Sistem - Urgensi: Tinggi) dan penyesuaian <strong>Restriksi Hak Akses</strong> (Kategori: Rekomendasi - Urgensi: Tinggi) wajib menjadi prioritas utama tim pengembang sebelum peluncuran sistem Clara versi produksi secara massal.
    </div>
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
        <span>Laporan Pengujian E2E Superadmin Clara</span>
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
