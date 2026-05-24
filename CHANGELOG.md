# CLARA — Release Note

**Program:** CLARA (Casual Leasing Achievement & Revenue Analytics)
**Pengembang:** IT Dept. PT. Wulandari Bangun Laksana Tbk.

| Peran | Nama |
|-------|------|
| Head Developer | Ahmad Affan Ridha |
| Developer | Mochamad Sa'adillah Effendi |
| Implementor | Riky Akbar |

---

## Version 3.9 — 24 Mei 2026

### Laporan PIC — Perbaikan & Fitur

- **Multi-property layout** — Jika user punya akses ke 2 properti, laporan PIC menampilkan E-Walk di atas dan Pentacity di bawah dalam satu halaman, masing-masing dengan KPI dan tabel PIC-nya sendiri.
- **Fix dropdown periode double** — Periode tidak lagi muncul duplikat ketika 2 properti punya period_key yang sama (`GROUP BY period_key`).
- **Kolom Regular & Recurring** — Tabel PIC kini menampilkan pemisahan revenue Regular vs Recurring per PIC, plus total masing-masing di baris Total. KPI card juga menampilkan keduanya secara terpisah.
- **Badge Recurring di detail transaksi PIC** — Transaksi recurring ditandai badge biru saat klik expand nama PIC.

---

## Version 3.8 — 24 Mei 2026

### Fitur Baru — Transaksi Recurring (Spread per Bulan)

- **Opsi "Spread per Bulan (Recurring)"** di field *Nilai Diakui di Bulan* pada form tambah dan edit transaksi. Pilih opsi ini untuk kontrak yang nilainya dibagi rata ke setiap bulan selama durasi kontrak.
- **Distribusi Bagi Rata** — Saat disimpan, sistem menghitung total bulan kontrak dan membagi `final_amount` secara merata ke setiap bulan. Sisa pembulatan diakumulasikan ke bulan terakhir.
- **Badge "Recurring"** — Transaksi dengan `billing_method='spread'` ditandai badge biru "Recurring" di daftar transaksi dan halaman detail alokasi.
- **Preview Estimasi Spread** — Setelah klik Kalkulasi dengan mode Recurring dipilih, muncul panel breakdown nilai per bulan. Jika Override Aktual diisi, estimasi otomatis menggunakan nilai override tersebut.
- **Panduan Input Recurring** — Instruksi 6 langkah di bagian bawah form tambah dan edit transaksi.
- `billing_method` kini ikut diperbarui saat edit transaksi (sebelumnya tidak tersimpan saat update).
- Migration 004: kolom `billing_method` di tabel `transactions`.

---

## Version 3.7 — 24 Mei 2026

### Fitur Baru — Historis Potensi

- **Snapshot Potensi per Periode** — Setiap kali data master CL, Media, atau Gudang disimpan (edit rate, luasan, atau potensi bulanan), sistem otomatis menyimpan snapshot potensi slot tersebut untuk bulan berjalan ke tabel `period_potentials`. Dashboard, laporan cetak, Exec Summary, dan TV Display kini membaca potensi dari snapshot ini — bukan lagi langsung dari master — sehingga potensi bulan yang sudah lewat tidak ikut berubah ketika ada perubahan di master.

- **Freeze Bulan Lalu** — Saat slot pertama kali di-snapshot, 12 bulan ke belakang yang belum punya snapshot otomatis dibekukan dengan nilai sebelum perubahan (nilai lama master). Slot baru yang ditambahkan di bulan berjalan membekukan bulan-bulan sebelumnya dengan nilai 0 (slot belum ada).

- **Histori Perubahan Potensi** — Setiap perubahan nilai potensi dicatat di tabel `potential_history`: siapa yang mengubah, kapan, dari berapa, ke berapa, dan sumbernya (manual edit atau import). Histori ini bisa dilihat di halaman Master → Target.

- **Import Ikut Trigger Snapshot** — Import CSV Media dan Import Template Excel (semua segmen) juga men-trigger snapshot dan merekam histori perubahan, sama seperti edit manual.

- **Migration Runner** — Script `db_migrate.php` untuk menjalankan perubahan skema database secara terkontrol. Migrasi: `001_create_period_potentials.php`, `002_create_potential_history.php`.

### Perbaikan Internal

- `snapshot_potential()` dipindah dari `master.php` ke `helpers.php` agar bisa diakses dari semua modul.
- `DashboardService`, `exec_dashboard`, `print_export` (semua section GROUP BY subtotal dan occ per lantai/jenis/lokasi) diperbarui menggunakan COALESCE JOIN ke `period_potentials`.
- Dead code `$dashboard = DashboardService::data()` di `dashboard.php` dihapus.

---

## Version 3.6 — 24 Mei 2026

### UX — Input Transaksi

- **Datepicker Modern (Flatpickr)** — Semua field tanggal di seluruh halaman kini menggunakan Flatpickr menggantikan datepicker bawaan browser. Tampilan lebih rapi dan konsisten: tema teal, kalender kompak (252px), ikon kalender di dalam field, minggu dimulai dari Senin.

- **Tanggal Merah Otomatis** — Sabtu dan Minggu tampil merah di kalender. Hari libur nasional Indonesia ditandai merah dengan dot indikator di bawah tanggal. Cuti bersama ditandai oranye dengan dot indikator. Data diambil otomatis dari API `libur.deno.dev` yang mencakup semua jenis libur nasional termasuk Idul Fitri, Nyepi, dan Waisak.

- **Tooltip Keterangan Libur** — Saat kursor diarahkan ke tanggal merah atau oranye, muncul popup keterangan nama hari libur (misal: "Hari Raya Idul Fitri 1447 Hijriyah").

- **Urutan Field Form** — Urutan field di form input transaksi diubah menjadi: Luas m² → Tanggal Mulai → Tanggal Selesai, sesuai alur pengisian yang lebih natural.

- **Autocomplete Client** — Dropdown client diganti dengan field autocomplete yang bisa dicari berdasarkan nama perusahaan atau brand. Hasil muncul sebagai daftar floating yang tidak terhalang elemen lain di form.

### UX — Daftar Transaksi

- **Pagination** — Daftar transaksi dibatasi 50 baris per halaman dengan navigasi Prev/Next. Filter aktif tetap terjaga saat pindah halaman.

- **Tabel Lebih Ringkas** — Kolom dikurangi dari 11 menjadi 9: No. Invoice ditampilkan di bawah nama client (font kecil), kolom Waktu Input digabung ke kolom Input. Menghilangkan scroll horizontal di layar standar.

### Perbaikan Bug

- **Analisa Market Client per-Properti** — Query daftar periode sebelumnya tidak difilter per properti, menyebabkan periode dari properti lain ikut muncul. Sudah diperbaiki.

- **Sidebar Scroll Reset** — Posisi scroll sidebar kembali ke atas setiap kali navigasi halaman. Diperbaiki dengan menyimpan posisi scroll ke `sessionStorage` dan memulihkannya saat halaman dimuat.

---

## Version 3.5 — 24 Mei 2026

### Manajemen User

- **Password Default & Paksa Ganti** — Saat admin membuat akun baru, password otomatis diset ke `123456` dan user diwajibkan mengganti password sebelum bisa mengakses sistem. Seluruh route diblokir hingga password baru disimpan. Halaman ganti password tidak bisa dilewati.

- **Reset Password oleh Admin** — Di form edit user, admin dapat mencentang "Reset password ke default 123456" untuk mereset akun yang lupa password. User akan diminta ganti saat login berikutnya.

- **Template WA Setelah Buat Akun** — Setelah admin membuat user baru, muncul halaman sukses dengan template pesan WhatsApp siap salin, berisi link akses, email, dan password default. Tombol "Salin Teks WA" mengcopy ke clipboard.

- **Indikator Status Password** — Kolom "Password" di daftar user menampilkan badge kuning "Harus Ganti" untuk akun yang belum mengganti password default.

- **Form Edit User Didesain Ulang** — Dibagi menjadi 3 panel terpisah: Informasi Dasar, Password, dan Akses Property. Lebih rapi dan mudah dibaca.

### Keamanan

- **Password Complexity Policy** — Password baru wajib memenuhi: minimal 8 karakter, ada huruf besar, huruf kecil, angka, dan karakter spesial. Berlaku saat ganti password pertama kali maupun setelah reset.

- **Session Timeout** — Dipersingkat dari 2 jam menjadi 30 menit tidak aktif.

### Konfigurasi

- **APP_URL** — Tambah variabel `APP_URL` di `.env` untuk URL publik aplikasi (digunakan di template WA dan notifikasi).

---

## Version 3.4 — 23 Mei 2026

### Executive Summary (Dashboard & Print)

- **KPI Pemenuhan Occupancy Rate per Properti** — Setiap property card di Executive Summary Dashboard dan Print kini menampilkan 3 KPI box OCC: Exhibition, Media Promo, dan Gudang. Masing-masing dilengkapi label "Occupancy Rate" agar tidak terbaca sebagai angka achievement. Color-coding hijau (≥100%), kuning (≥80%), merah (<80%).

- **Tabel Achievement PIC dipisah per Properti** — Sebelumnya satu tabel gabungan semua properti. Sekarang masing-masing properti memiliki tabelnya sendiri, baik di dashboard maupun di print. Kolom "Properti" dihapus karena sudah terwakili oleh judul tabel.

- **Kolom HARI diganti Avg Hari** — Pada tabel occupancy (Exhibition per Lantai, Media per Jenis, Gudang per Lokasi) di print Executive Summary, kolom "Hari" kini menampilkan rata-rata hari terisi per unit (`days_total ÷ unit_count`), lebih mudah dibaca dan nyambung logis ke kolom OCC%.

- **Kolom Avg Rate per Segmen** — Tabel occupancy Exhibition, Media Promo, dan Gudang kini memiliki kolom rate rata-rata dari transaksi yang sudah ter-deal di bulan berjalan. Formula berbasis `amount` aktual alokasi (bukan master rate), sehingga mencerminkan harga dealing yang sebenarnya:
  - Exhibition → `Avg Rate/Hari/m²` (`amount ÷ allocated_days ÷ area_sqm`)
  - Media Promo → `Avg Rate/Hari` (`amount ÷ allocated_days`)
  - Gudang → `Avg Rate/m²/Bln` (`amount ÷ area_sqm`)

### Display TV

- **OCC% per Segmen** — Di setiap panel properti, bawah angka achievement per segmen kini muncul "Occ X%" dengan color-coding yang sama (hijau/kuning/merah). Data diambil dari `allocated_days ÷ (unit_count × hari bulan)`.

### Transaksi

- **Warning Overlap Tanggal** — Form tambah dan edit transaksi kini mendeteksi secara real-time apakah unit yang dipilih sudah memiliki transaksi lain dengan tanggal yang overlap di periode yang sama. Muncul kotak peringatan kuning dengan detail transaksi yang bentrok (nama client, tanggal, PIC). Input tetap bisa disimpan — peringatan hanya informatif, karena satu unit bisa sah diisi dua client sekaligus jika dibagi per slot atau luasan.

### Lain-lain

- **Nama aplikasi dipersingkat** — Dari "CLARA Unified" menjadi "CLARA" di konfigurasi default, title bar, dan session name.

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
