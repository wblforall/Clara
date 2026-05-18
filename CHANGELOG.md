# CLARA — Release Note

**Program:** CLARA (Casual Leasing Achievement & Revenue Analytics)
**Pengembang:** IT Dept. PT. Wulandari Bangun Laksana Tbk.

| Peran | Nama |
|-------|------|
| Head Developer | Ahmad Affan Ridha |
| Developer | Mochamad Sa'adillah Effendi |
| Implementor | Riky Akbar |

---

## Version 3.3 — 18 Mei 2026

### Fitur Baru — Master Client

- **Field Kota & Provinsi** — Ditambahkan dua field baru pada data client: Provinsi (dropdown 38 provinsi Indonesia) dan Kota/Kabupaten (dropdown cascading, isi otomatis mengikuti provinsi yang dipilih). Kedua field wajib diisi saat membuat atau mengedit client.

- **Kolom Kota/Provinsi di list Master Client** — Tabel daftar client kini menampilkan kota dan provinsi pada kolom tersendiri setelah Nama Brand.

- **Filter Provinsi di Analisa Market Client** — Dropdown filter provinsi ditambahkan di toolbar halaman analisa, berfungsi bersama filter Jenis Usaha, Skala, dan Segmen.

- **Grafik Sebaran per Provinsi & Kota** — Dua panel baru ditampilkan berdampingan: distribusi jumlah client per provinsi dan sebaran per kota (bar chart horizontal dengan skala relatif). Hanya muncul jika data tersedia.

- **Kolom Kota di tabel At-Risk Clients & Top 10** — Informasi kota dan provinsi ditambahkan pada kedua tabel tersebut untuk memudahkan identifikasi asal client.

---

## Version 3.2 — 15 Mei 2026

### UX & Animasi

- **Animasi halaman login** — Background login kini menampilkan gradient animasi yang bergeser secara dinamis (teal–biru) dengan tiga floating blob yang bergerak lambat. Panel login tetap tampak bersih dengan efek glassmorphism.

- **Welcome popup setelah login** — Popup muncul 1 kali setelah login berhasil dan menutup otomatis dalam 3 detik. Menampilkan achievement pribadi untuk user yang terdaftar sebagai PIC Sales (Aktual vs Target vs persentase). Untuk user non-PIC, menampilkan achievement per-properti masing-masing secara terpisah (bukan gabungan) sehingga user multi-properti dapat melihat persentase tiap properti sekaligus.

- **Animasi halaman Executive Summary** — KPI cards (Combined Strip) slide-up berurutan dengan stagger 70ms. Per-properti cards muncul dengan delay berdasarkan posisi kartu. Section titles fade-in. Segment bars (Exhibition/Media/Gudang) dan mini bars occupancy table animate dari lebar 0 ke nilai aktual saat halaman dimuat.

### Performa

- **PDO persistent connections** — Koneksi database di-reuse antar request sehingga overhead koneksi MySQL berkurang.

- **Hapus reverse DNS lookup** — `gethostbyaddr()` dihapus dari audit log. Delay 1–10 detik saat simpan transaksi (akibat timeout DNS) teratasi.

- **Logo dikompresi** — `clara-logo.png` diubah ukuran dari 1254×1254 px (416 KB) menjadi 256×256 px (49 KB), menghemat ~370 KB per load.

- **CSS version constant** — `CSS_VER` dihitung sekali saat boot dari `filemtime()`, bukan per-request, menghilangkan overhead file stat pada setiap render halaman.

- **Session optimization** — `session.lazy_write` aktif; `last_activity` hanya di-update tiap 60 detik; refresh nama properti di-cache 5 menit (TTL) di session.

- **Audit log efisien** — Logging GET request (view biasa) dihapus; hanya login, logout, insert, update, delete yang dicatat. Volume tabel `audit_logs` berkurang drastis.

### Perbaikan Bug

- **Tab properti topbar kembali ke Master Media** — Setiap pindah properti via tab, halaman selalu kembali ke Master Media. Diperbaiki dengan menggunakan `$_SERVER['QUERY_STRING']` lengkap sebagai URL balik, bukan hanya `?r=<route>`.

---

## Version 3.1 — 15 Mei 2026

### Fitur Baru

- **Executive Summary** — Halaman lintas properti yang menampilkan KPI gabungan, per-properti, perbandingan segment (Exhibition / Media / Gudang), occupancy per lantai/jenis/lokasi, dan achievement PIC dari semua properti dalam satu halaman. Tidak ada tab properti — data selalu ditampilkan untuk semua properti sekaligus.

- **Print-out Executive Summary** — Cetak/ekspor PDF format A4 landscape (`?r=print_exec_summary`) dengan layout lengkap: combined KPI strip, per-properti cards dengan segment bars, tabel perbandingan segment, occupancy side-by-side, dan tabel PIC semua properti. Tombol cetak tersedia di toolbar halaman Executive Summary.

- **Permission `view_exec_summary`** — Akses Executive Summary dikontrol tersendiri terlepas dari `view_dashboard`. Default aktif untuk role: Administrasi, Finance, Viewer. Dapat diubah kapan saja di Admin → Role & Permission.

### Perubahan

- **Nama "Executive Dashboard" → "Executive Summary"** — Nav sidebar, title halaman, dan badge toolbar diperbarui.

- **Urutan lantai seragam LG → GF → UG → FF → SF** — Berlaku di tabel occupancy Exhibition pada halaman Executive Summary dan print-out.

- **Occupancy Media & Gudang tanpa union** — Tiap properti hanya menampilkan data miliknya sendiri; tidak ada baris "—" yang berasal dari properti lain. Exhibition tetap menggunakan union agar lantai yang sama dapat dibandingkan baris per baris.

- **Tab properti topbar** — Styling diperbarui: inactive tab kini memiliki border, background putih, dan teks gelap sehingga terlihat jelas di topbar terang. Active tab tetap teal.

- **Refresh nama properti tanpa re-login** — Nama properti di session di-refresh dari database setiap request. Perubahan nama properti langsung efektif tanpa harus logout/login ulang.

- **schema.sql** — Nama Pentacity diperbarui menjadi "Pentacity Shopping Venue" pada data awal.

### Infrastruktur

- Inisialisasi Git repository untuk `clara-unified`
- `.gitignore` — exclude `.env`, `tmp/`, log files
- Push ke GitHub: `github.com/wblforall/Clara`

---

## Version 3.0 — 15 Mei 2026

### Rilis Major — Penggabungan E-Walk + Pentacity (CLARA Unified)

**Arsitektur:**
- 1 codebase, 1 database (`clara_unified`), multi-property dengan kolom `property_id` di semua tabel data
- Tabel `properties` + `user_properties` untuk manajemen akses per properti
- User dapat memiliki akses ke 1 atau 2 properti; property selector otomatis muncul untuk user multi-properti
- Tab properti di topbar untuk beralih konteks antar properti

**Modul yang disesuaikan:**
- Dashboard bulanan — filter per properti (`DashboardService.php`)
- Trend Revenue & Perbandingan Periode — filter per properti
- Input Transaksi (Exhibition / Media / Gudang) — simpan `property_id` dari sesi
- Master Data (CL Units, Media, Gudang, PIC, Target, Lookup) — filter per properti
- Master Client — SHARED antar properti (tidak per-property)
- Import CSV — include `property_id`
- Export: XLSX transaksi, laporan PIC, analisa client
- Print: Dashboard Bulanan, Ringkasan Direksi, Trend Revenue — semua dinamis per properti aktif
- Admin: Users + assignment properti per user (checkbox), Role & Permission global, Activity Log

**Auth & Session:**
- Login unified satu entry point
- Session name `CLARA_UNIFIED`, path `clara-sessions-unified`
- Property selector setelah login untuk user multi-properti
- Session timeout 2 jam dengan peringatan 2 menit

**Migrasi Data:**
- E-Walk: 14 users, 169 clients, 339 transaksi, 761 alokasi — Revenue Rp 2.120.800.915 ✓
- Pentacity: data lengkap — Revenue Rp 1.925.064.760 ✓

---

## Version 2.3 — 8 Mei 2026

### Fitur Baru

- **Tracking client baru per bulan** — Dashboard dan laporan cetak kini menampilkan ringkasan "Client Baru Bulan Ini" di atas tabel Achievement PIC. Target kolektif adalah 5 client baru per bulan; angka tampil hijau jika tercapai dan merah jika belum. Kolom "Client Baru" pada tabel PIC menampilkan breakdown per-PIC secara informatif. Definisi client baru: company yang belum pernah muncul di transaksi manapun sebelum periode ini (first-ever), dihitung otomatis dari data transaksi yang ada tanpa perlu input manual.

### UX — Animasi

- **Animasi halaman Trend Revenue** — Semua elemen halaman kini muncul dengan animasi terurut saat load: 4 kartu KPI slide-up berurutan, angka Aktual/Target/Achievement count-up dari nol, panel chart fade-up, baris tabel fade-in satu per satu. Bar chart stagger per kolom (50ms antar bulan). Berlaku di kedua project.

- **Animasi form Input Transaksi** — Halaman Tambah Transaksi (Exhibition, Media, Gudang) kini menampilkan setiap field secara berurutan dengan animasi slide-up stagger. Tombol Kalkulasi dan Simpan muncul paling terakhir setelah semua field tampil. Berlaku di kedua project.

- **Focus animation input global** — Seluruh input, select, dan textarea di semua halaman kini memiliki animasi fokus yang lebih terasa: border hijau, glow shadow, dan naik 1px saat diklik. Transisi smooth cubic-bezier 200ms.

---

## Version 2.2 — 6 Mei 2026

### UX — Dashboard & Laporan

- **KPI Aktual merah saat belum capai target** — Angka Aktual pada kartu KPI atas tampil berwarna merah apabila nilai aktual masih di bawah target bulanan. Berlaku di dashboard, print preview/printout, dan Display TV.

- **Dial target Display TV berwarna dinamis** — Ring/dial di layar Display TV kini mengikuti skema warna achievement: hijau (≥ 100%), amber (≥ 80%), merah (< 80%).

- **Occupancy >100% bold merah di laporan cetak** — Kolom % Occupancy tampil bold merah apabila nilainya melebihi 100%.

- **Nama PIC tidak terpotong di Display TV** — Teks dibungkus ke baris berikutnya sehingga nama selalu terbaca lengkap.

---

## Version 2.1 — 5 Mei 2026

### Keamanan

- **Proteksi brute force login** — Setelah 5 kali login gagal, akun dikunci selama 5 menit. Counter disimpan di sesi dan dicatat di audit log.

- **Perbaikan session fixation** — `session_regenerate_id(true)` dipanggil tepat sebelum `$_SESSION['user']` diisi saat login berhasil.

### Perbaikan Bug

- **Permission `manage_deleted` dapat diatur via Role & Permission.**

- **Highlight occupancy 100% tampil saat dicetak** — Diperbaiki dengan `print-color-adjust: exact`.

### Performa

- **Fix N+1 query di `periods()`** — Sekarang berjalan dengan tepat 2 query menggunakan `IN (...)`.

### UX

- **Format separator ribuan pada input Rate dan Potensi Bulanan.**

---

## Version 2.0 — 5 Mei 2026

### Arsitektur & Performa

- **Refactor struktural** — `index.php` dari ~4.830 baris menjadi 231 baris. Seluruh fungsi halaman dipindahkan ke modul terpisah di `app/pages/`.

- **Lazy loading halaman** — Hanya 1 file halaman dimuat sesuai route aktif. File I/O per request berkurang dari 10 menjadi 1.

- **Eliminasi query sia-sia per request** — Install check dan permission matrix dikache di sesi (refresh 5 menit).

- **OPcache diaktifkan** — Memory 128 MB, max 10.000 file, JIT mode `tracing` 64 MB.

- **Realpath cache** — TTL dinaikkan dari 120 ke 600 detik.

- **Gzip compression** — `mod_deflate` diaktifkan. Ukuran respons berkurang rata-rata ~60%.

- **Browser caching asset** — CSS, JS, gambar dikache 1 tahun via `Cache-Control`.

- **Security headers** — `X-Content-Type-Options: nosniff` dan `X-Frame-Options: SAMEORIGIN`.

- **Index database tambahan** — `transactions`, `transaction_allocations`, `audit_logs`.

---

## Version 1.12 — 30 April 2026

### Fitur Baru

- **Nama Brand di list transaksi.**

- **Achievement per PIC di Perbandingan Periode** — Tabel tambahan dengan delta VS P1↔P2 dan VS P1↔P3.

### Perbaikan

- **Edit Contact Person di Master Client** — Variabel `$pdo` tidak ter-capture di closure layout. Sudah diperbaiki.

- **Rate Rata-rata/Bulan menggunakan hari aktual bulan** (`$periodDays`) sebagai pembagi.

- **Subtotal Rate Rata-rata menggunakan rata-rata sederhana** antar unit dalam satu grup.

- **Highlight baris PIC tercapai** — Hijau muda di dashboard dan laporan cetak.

- **Highlight baris occupancy 100%** di laporan cetak.

---

## Version 1.11.1 — 30 April 2026

- **Anti double-submit** — Tombol submit disabled setelah diklik pertama kali di seluruh form.

---

## Version 1.11 — 30 April 2026

- **Field Nama Brand di Master Client.**

- **Nama Brand tampil di dropdown transaksi.**

---

## Version 1.10 — 30 April 2026

- **Indikator PIC terendah** — Emoji 😢 untuk PIC achievement terendah.

- **Display TV Gabungan** — `display-tv.html` untuk menampilkan eWalk dan Pentacity berdampingan.

---

## Version 1.9 — 29 April 2026

- **Filter status PIC di Master PIC** — Tab Aktif / Tidak Aktif / Arsip / Semua.

- **Role Administrasi** ditambahkan ke sistem.

- **Format angka otomatis pada field Target Amount.**

---

## Version 1.8 — 29 April 2026

- **Format angka otomatis pada field Override Aktual.**

---

## Version 1.7 — 29 April 2026

- **Eliminasi dependency eksternal** — Google Fonts diganti system font; Chart.js dipindahkan ke lokal.

- **Panduan Pengguna diperbarui** dengan ilustrasi UI berbasis HTML/CSS.

---

## Version 1.6 — 27 April 2026

- **Export Excel** — List Transaksi, Laporan PIC, Analisa Market Client (`.xlsx` tanpa library eksternal).

- **Riwayat Perubahan Transaksi** — Timeline audit trail per transaksi dengan diff nilai lama vs baru.

---

## Version 1.5 — 26 April 2026

- **Ikon mata pada field password login.**

- **Optimasi performa database** — Index tambahan pada kolom-kolom yang sering diquery.

- **Rename database** — `cl_achievement` → `clara_ewalk`.

- **Contact Person di detail alokasi** — Nama dan nomor telepon tampil dan dapat diklik.

- **Halaman Laporan PIC** — Ringkasan achievement PIC per periode, cetak A4, permission `view_pic_report`.

---

## Version 1.4 — 26 April 2026

- **No. Invoice Accurate** — Referensi ke sistem akuntansi Accurate.

- **Filter di Analisa Market Client** — Jenis Usaha, Skala, Segmen.

- **Fix edit transaksi** — Client & Contact Person tidak terpilih otomatis akibat type mismatch ID.

---

## Version 1.3 — 25 April 2026

- **Profil bisnis client** — Jenis Usaha, Skala, Asal Brand, Target Segmen, Channel, Tags.

- **Halaman Analisa Market Client** — Distribusi, KPI, revenue per jenis usaha.

- **Kelola Opsi Dropdown** — CRUD opsi pilihan dropdown via `master_lookup_options`.

- **Sidebar Admin group** — Item admin dikelompokkan dan disembunyikan by default.

---

## Version 1.2 — 25 April 2026

- **Nama aplikasi dinamis** dari `APP_NAME` di `.env`.

- **Copyright sidebar**, favicon, session timeout 2 jam dengan countdown peringatan.

---

## Version 1.1 *(perdana internal)*

- Rilis internal perdana: dashboard, input transaksi, master data, TV display.

---

*Dokumen ini diperbarui setiap ada perubahan versi program.*
