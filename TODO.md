# CLARA Unified — Todo List Penggabungan

**Tujuan:** Menggabungkan `clara-ewalk` dan `clara-pentacity` menjadi satu aplikasi dengan satu database multi-property.

**Keputusan arsitektur:**
- 1 codebase, 1 database (`clara_unified`)
- Semua tabel data ditambah kolom `property_id`
- User bisa punya akses ke 1 atau 2 properti
- UI: tab lokasi (E-Walk | Pentacity) untuk user multi-properti

---

## FASE 1 — Database Schema ✓

### 1.1 Buat database & tabel baru
- [x] Buat database `clara_unified`
- [x] Buat tabel `properties` (id, key, display_name, address, status)
  - Isi awal: `ewalk` = E-Walk Simply FUNtastic, `pentacity` = Pentacity
- [x] Buat tabel `user_properties` (user_id, property_id)

### 1.2 Tambah `property_id` ke semua tabel data
- [x] `transactions`
- [x] `transaction_allocations`
- [x] `master_clients` — SHARED (tidak perlu property_id, keputusan bisnis)
- [x] `master_cl_units`
- [x] `master_media`
- [x] `master_gudang`
- [x] `master_pic`
- [x] `targets_monthly`
- [x] `master_client_contacts` — SHARED (ikut master_clients)
- [x] `periods`
- [x] `audit_logs`
- [x] `master_lookup_options`

### 1.3 Tabel users (tidak perlu property_id, shared)
- [x] Tambah kolom `session_last_active` datetime NULL
- [x] Pastikan email unique across all users

### 1.4 Migrasi data
- [x] Export semua data ewalk → import ke clara_unified dengan `property_id = 1`
- [x] Export semua data pentacity → import ke clara_unified dengan `property_id = 2`
- [x] Verifikasi jumlah record sebelum & sesudah migrasi
  - 14 users, 169 clients, 339 transactions, 761 allocations, 56,321 audit logs
  - Revenue ewalk Rp2,120,800,915 ✓ | pentacity Rp1,925,064,760 ✓

---

## FASE 2 — Auth & Session ✓

- [x] Halaman login unified (satu entry point)
- [x] Setelah login: cek `user_properties`
  - 1 properti → langsung masuk, set `session[current_property_id]`
  - 2 properti → tampil property selector (`select_property` page)
- [x] Session menyimpan `current_property_id` dan `allowed_properties`
- [x] Ganti `session_name` ke `CLARA_UNIFIED`
- [x] Session path: `clara-sessions-unified`

---

## FASE 3 — Struktur Aplikasi ✓

- [x] Buat folder `clara-unified/` dengan struktur baru
- [x] `Database.php` — koneksi ke `clara_unified` (MySQL only)
- [x] `helpers.php` — `current_property_id()`, `allowed_properties()`, `is_multi_property()`, `prop_filter()`
- [x] `AllocationService.php` — include `property_id` di INSERT
- [x] `.env` — `APP_NAME=CLARA Unified`, `DB_DATABASE=clara_unified`
- [x] Apache: VirtualHost port 8002 + Alias /clara-unified

---

## FASE 4 — UI: Tab Lokasi ✓

- [x] Tab lokasi di topbar (hanya muncul untuk user multi-properti)
- [x] Klik tab → `switch_property` route → update session → redirect back
- [x] Semua halaman respek ke `current_property_id` aktif
- [x] Label properti di topbar dinamis (`current_property()['display_name']`)
- [x] Print/export: property name dinamis (bukan hardcoded "eWalk Simply FUNtastic")

---

## FASE 5 — Modul per Halaman ✓

### Dashboard
- [x] KPI cards filter by `property_id` (`DashboardService.php`)
- [x] `dashboard.php` — filter by property

### Input Transaksi
- [x] Form transaksi simpan `property_id` dari session
- [x] Master unit (CL/Media/Gudang) filter by property
- [x] `transactions.php` — 34 property_id refs

### Master Data
- [x] `master.php` — filter by property (PIC, media, cl_units, gudang, periods, targets)
- [x] Master Client — SHARED (tidak per-property)
- [x] `lookup.php` — filter by property

### Import
- [x] `import.php` — CSV + template import include property_id

### Analytics & Laporan
- [x] `analytics.php` — 16 property_id refs
- [x] `laporan.php` — 14 property_id refs
- [x] `clients.php` — 26 property_id refs
- [x] `print_export.php` — 52 property_id refs (print_dashboard, print_exec, print_trend, export_summary, export_transactions_xlsx, export_pic_report_xlsx)

### Admin
- [x] `users.php` — user_properties assignment UI (checkbox per property)
- [x] `users.php` — `audit_page` filter by property
- [x] Role & Permission — GLOBAL (bukan per property)

---

## FASE 6 — Testing & Go Live

- [x] Verifikasi revenue unified = revenue lama (ewalk ✓, pentacity ✓)
- [ ] Test login user 1 properti → data terisolasi benar
- [ ] Test login user 2 properti → tab lokasi muncul, switch berfungsi
- [ ] Test tidak ada data bocor antar properti
- [ ] UAT dengan user lapangan
- [ ] Backup `clara-ewalk` dan `clara-pentacity` sebelum cutover
- [ ] Cutover: arahkan port ke `clara-unified`
- [ ] Monitor 1 minggu, siapkan rollback plan

---

## Keputusan yang Sudah Dikonfirmasi

| # | Topik | Keputusan |
|---|---|---|
| 1 | Master Client | SHARED — tidak per-property |
| 2 | Role & Permission | GLOBAL |
| 3 | Nama database | `clara_unified` |
| 4 | Session name | `CLARA_UNIFIED` |
| 5 | Port unified | 8002 |

---

## Status Pra-Merge (sudah dikerjakan di masing-masing project)

- [x] Bug session sharing antar project sudah diperbaiki (session name berbeda)
- [x] Link user ↔ PIC sudah ada di ewalk (`master_pic.user_id`)
- [x] Auto-select PIC di form transaksi (ewalk)
- [x] UX animasi trend revenue, form transaksi, focus input
- [x] Analisa Market Client komprehensif (kedua project)

---

*Terakhir diperbarui: 2026-05-15 — Semua Fase 1–5 selesai. Fase 6 testing & go-live menunggu UAT.*

---

## Backlog — Pipeline Penawaran (Offer-First)

Pipeline penawaran (Surat Penawaran → Konfirmasi SKP/SKS → Transaksi) sudah lengkap (Phase 1–5b). Peluang lanjutan:

- [ ] **Reminder follow-up penawaran nganggur** — notifikasi/pengingat untuk penawaran yang lama tidak ada pergerakan (mis. status `sent`/`nego` > N hari tanpa update) agar PIC menindaklanjuti atau menutupnya.
- [ ] **Export laporan pipeline** — export Excel/PDF untuk laporan Aktivitas & Pipeline PIC (leaderboard, funnel, alasan tidak deal, penawaran berisiko).
- [ ] **Target aktivitas PIC** — target jumlah penawaran/aktivitas per PIC per bulan (mis. minimal X penawaran dikirim), dibandingkan realisasi di laporan pipeline.

*Ditambahkan: 2026-06-13.*

---

## Backlog — PWA / Tampilan Mobile (menyesuaikan pipeline & fitur baru)

**Konteks:** PWA mobile (`app/pages/mobile.php`: `mobile_home_page`, `mobile_transactions_page`, `mobile_exec_page`; bottom-nav Beranda/Transaksi/Eksekutif; `public/manifest.webmanifest` + `public/service-worker.js`) dibangun **sebelum** pipeline offer-first. Banyak alur kini berubah → mobile perlu disesuaikan agar sales bisa kerja penuh dari HP.

### A. Navigasi & Quick Action
- [x] **Quick action "Transaksi" → "Penawaran"**: `_m_quick_actions` kini "+ Buat Penawaran" saat offer-first aktif (fallback transaksi bila offer-first off).
- [x] **Bottom-nav**: tab **"Penawaran"** (`m_offers`) ditambah; `_mobile_active_tab()` & nav di `bootstrap.php` disesuaikan.

### B. Modul baru di mobile (saat ini desktop-only)
- [x] **Daftar + Preview Penawaran** mobile (`m_offers`): tab On Going/Deal/Tidak Deal + badge hitung, filter modul, kartu ringkas; klik → preview `offer_view` (status, Buat SKP, Tutup, PDF). FAB Buat Penawaran.
- [x] **Buat/Edit Penawaran** mobile — reuse form desktop (sudah responsif: `.form-grid`→1 kolom ≤768px, input 16px anti-zoom) + tombol aksi full-width (`.form-actions`). Tak digandakan agar tak drift dgn `offer_save`.
- [x] **Daftar + aksi SKP/SKS** mobile (`m_skp`) — daftar/filter/aksi + submit-approval (cek lampiran wajib server-side), approve/reject, TTD online link+WA, upload TTD basah, Scan; tombol aksi full-width di HP.
- [x] **Permintaan Kontrak ke Legal** mobile — buat dari SKP signed (entry di panel SKP signed), form responsif + tombol full-width, daftar jadi kartu (`mobile-cards`), upload Akta/Surat Kuasa, kirim link/PDF.
- [x] **Tanda Tangan Saya** mobile — dijangkau via **menu akun** baru di topbar HP; halaman upload TTD sudah ringkas/responsif.

### C. Penyesuaian aturan yang sudah berubah
- [x] **Visibilitas per-sales**: mobile ikut `current_sales_scope()` — `mobile_home_page` tak lagi tampil agregat utk role sales (guard MODE B/C); `m_offers`/`m_skp`/daftar transaksi mobile difilter per-sales.
- [x] **Badge & filter modul** Exhibition/Media/Gudang di daftar mobile (`m_offers`/`m_skp`), warna konsisten dgn desktop.
- [x] **Offer-first enforcement**: FAB/quick-action mobile ke `offer_form` saat offer-first aktif; guard hard tetap di controller `transaction_form` (kecuali renewal).

### D. PWA teknis
- [x] **Service worker / cache**: di-review — desain sudah benar (navigasi network-only → halaman dinamis penawaran/SKP/print tak pernah di-cache; aset cache-first + revalidate; skipWaiting+clients.claim). `VERSION` di-bump `clara-v1`→`clara-v2` agar cache lama di-purge setelah perubahan CSS.
- [x] **Upload dari kamera HP**: input lampiran pakai `accept="image/*,.pdf"` → opsi **kamera** muncul di native picker (tak dipaksa `capture` karena field juga terima PDF; memaksa capture menghilangkan galeri/PDF).
- [x] **Manifest**: shortcut "Buat Penawaran" & "Daftar Penawaran" ditambah; ikon/nama dicek OK.
- [x] **PDF di mobile**: TUNTAS via **server-side mPDF** (v4.21). Awalnya dicoba CSS (viewport+fit-to-width) tapi Chrome Android tak bisa mengulang kop per-halaman dgn cara apa pun (thead/tfoot, position:fixed, @page margin semua gagal). Solusi final: `app/pdf.php` render PDF di server (kop berulang via SetHTMLHeader/Footer dari strip `letterhead-head/foot.jpg`, QR PNG via mpdf/qrcode). offer/SKP/kontrak `*_print` default → PDF 1-ketuk (inline), `&html=1` = pratinjau lama. Checkbox ☑/☐ → ■/□ (font DejaVu tak punya ballot-box). vendor/ di-commit (24MB, ttfonts dipangkas ke DejaVu).
- [ ] **Offline draft** (opsional): simpan draft penawaran/SKP saat sinyal jelek. *Ditunda — berisiko utk app multi-user berbasis sesi (perlu IndexedDB + sync); dikerjakan belakangan bila benar dibutuhkan.*

### E. Verifikasi
- [~] Uji alur penuh sales dari HP: penawaran → DEAL → SKP → lampiran → submit → approve → TTD → kontrak. *HTTP smoke (render 200, tanpa error PHP) tiap halaman OK sbg superadmin; uji E2E penuh perlu browser nyata (JS).* 
- [~] Uji per-sales: 2 akun sales berbeda tidak saling lihat data. *Scoping pakai `current_sales_scope()` (helper sama dgn desktop) di beranda/m_offers/m_skp/transaksi mobile; verifikasi visual final perlu 2 akun sales di browser.*

*Ditambahkan: 2026-06-14. Fase 0–3 selesai 2026-06-14. Sisa: Offline draft (opsional) + verifikasi E2E browser oleh user.*
