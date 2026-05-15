# CHANGELOG — CLARA Unified

Semua perubahan signifikan dicatat di sini.
Format: `[versi] YYYY-MM-DD — ringkasan`

---

## [1.1] — 2026-05-15

### Baru
- **Executive Summary** — halaman lintas properti menampilkan KPI gabungan, per-properti, perbandingan segment, occupancy Exhibition/Media/Gudang, dan achievement PIC dari semua properti dalam satu halaman
- **Print-out Executive Summary** — cetak/ekspor PDF format A4 landscape (`?r=print_exec_summary`) dengan layout lengkap: combined KPI, per-properti cards, tabel segment, occupancy, dan PIC
- **Permission `view_exec_summary`** — akses Executive Summary dibatasi per role; default aktif untuk: Administrasi, Finance, Viewer (dapat diubah di Admin → Role & Permission)
- **Tombol Cetak/PDF** di toolbar Executive Summary, membuka print-out di tab baru sesuai periode yang sedang dipilih

### Perubahan
- **Nama halaman** "Executive Dashboard" → "Executive Summary" (nav, title, badge)
- **Urutan lantai** seragam LG → GF → UG → FF → SF di semua tabel occupancy
- **Occupancy Media & Gudang** — tiap properti hanya menampilkan datanya sendiri (tidak ada baris "—" dari properti lain)
- **Tab properti** di topbar tampil dengan border dan teks gelap agar terlihat jelas di background terang
- **Nama properti** di tab di-refresh dari database setiap request — perubahan nama langsung efektif tanpa perlu logout/login ulang
- **schema.sql** — nama Pentacity diperbarui menjadi "Pentacity Shopping Venue"

### Infrastruktur
- Inisialisasi Git repository
- `.gitignore` — exclude `.env`, `tmp/`, log files
- Push ke GitHub: `github.com/wblforall/Clara`

---

## [1.0] — 2026-05-15

### Rilis Awal — Penggabungan E-Walk + Pentacity

**Arsitektur:**
- 1 codebase, 1 database (`clara_unified`), multi-property dengan kolom `property_id` di semua tabel data
- Tabel `properties` + `user_properties` untuk manajemen akses per properti
- User dapat akses 1 atau 2 properti; property selector otomatis muncul untuk user multi-properti

**Modul:**
- Dashboard bulanan (KPI, segment, PIC, detail unit) — filter per properti
- Trend Revenue & Perbandingan Periode — filter per properti
- Input Transaksi (Exhibition / Media / Gudang) — simpan `property_id` dari sesi
- Master Data (CL Units, Media, Gudang, PIC, Target, Lookup) — filter per properti
- Master Client — SHARED antar properti
- Import CSV — include `property_id`
- Export: XLSX transaksi, laporan PIC, analisa client
- Print: Dashboard Bulanan, Ringkasan Direksi, Trend Revenue
- Laporan PIC, Analisa Market Client
- Admin: Users, Role & Permission (global), Activity Log, Transaksi Dihapus

**Auth & Session:**
- Login unified satu entry point
- Session name `CLARA_UNIFIED`, path `clara-sessions-unified`
- Property selector setelah login untuk user multi-properti
- Session timeout 2 jam dengan peringatan 2 menit sebelumnya

**Migrasi Data:**
- E-Walk: 14 users, 169 clients, 339 transaksi, 761 alokasi — Revenue Rp 2.120.800.915 ✓
- Pentacity: data lengkap — Revenue Rp 1.925.064.760 ✓

---

*Dikelola oleh IT Dept. PT. Wulandari Bangun Laksana Tbk.*
