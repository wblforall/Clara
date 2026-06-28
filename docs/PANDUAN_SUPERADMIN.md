# Panduan CLARA — Superadmin (Administrasi Sistem)

**Untuk:** pengelola penuh CLARA.
**Prinsip:** semua fungsi Sales + Manajer, **plus** administrasi pengguna, audit, dan konfigurasi sistem.

> Untuk tugas operasional (penawaran) lihat **Panduan Sales**; untuk persetujuan & pengawasan lihat **Panduan Manajer**. Dokumen ini fokus ke yang **khusus Superadmin**.

---

## 1. Pengguna & Hak Akses

### A. Users & Role
1. Menu **Users & Role** → **+ Tambah User**.
2. Isi nama, email, role, dan **properti** yang boleh diakses.
   - Superadmin tanpa properti tertentu → otomatis dapat **semua** properti.
   - Akun sales sebaiknya ditautkan ke **Master PIC** (agar laporan per-sales akurat).
3. User baru memakai password default & **wajib ganti** saat login pertama.
4. Nonaktifkan user (status) alih-alih menghapus, agar jejak audit utuh.

@@SHOT:users|Users & Role — kelola akun, properti, dan peran@@

### B. Role & Permission (di sinilah tier "Manajer" dibentuk)
1. Menu **Role & Permission**.
2. Centang izin per role. Acuan izin penting:
   - `manage_offers`, `manage_skp` — buat penawaran/SKP (Sales).
   - **`approve_skp`** — **approve/reject SKP, setujui kontrak Legal, batal transaksi** (kunci peran Manajer).
   - `view_exec_summary` — Executive Summary + tombol Display TV.
   - `view_renewals`, `view_commission_sim` — Renewal & Simulasi Komisi.
   - `export_reports` — export CSV/Excel/Print.
   - `manage_users`, `view_logs`, `manage_deleted` — administrasi (khusus Superadmin).

> **Membuat tier Manajer sebagai role tersendiri (opsional):** beri sebuah role (mis. `supervisor`) izin `approve_skp` + `manage_offers/skp` + `view_exec_summary` + `view_renewals` + `view_commission_sim` + `export_reports` + `view_pic_report`. Lalu pindahkan akun manajer ke role itu. Saat ini (opsi B) manajer dijalankan oleh akun superadmin.

@@SHOT:roles|Role & Permission — centang izin per role (di sini tier Manajer dibentuk)@@

---

## 2. Audit & pemulihan

- **Activity Log** — semua aksi tercatat (user, role, aksi, modul, **IP**, sebelum/sesudah). Untuk investigasi perubahan data.
- **Transaksi Dihapus** — soft-delete; lihat & **pulihkan** bila perlu.
- **Konversi Recurring** — gabungkan transaksi anchor menjadi recurring (`spread`) dengan penjagaan nilai.
- **Kelola Opsi Dropdown** — atur pilihan baku (mis. Tipe Unit) agar konsisten.

@@SHOT:audit|Activity Log — jejak semua aksi (user, aksi, modul, IP, before/after)@@

@@SHOT:recurring|Konversi Recurring — gabungkan transaksi anchor jadi recurring@@

---

## 3. Master Data (penuh)

Exhibition, Media, Gudang, **Tipe Unit**, **Template Penawaran** (per jenis booth), PIC, Target bulanan, Client, dan **Referrer** (data komisi/rekening — tulis wajib `manage_master`). Perubahan nama properti & master langsung efektif tanpa re-login.

@@SHOT:master_cl|Master Exhibition — kelola unit, rate, tipe, status@@

---

## 4. TV Display

- **Token** disimpan di `.env` (`DISPLAY_TOKEN`) — bisa berisi **beberapa token dipisah koma** untuk **rotasi tanpa downtime** (token lama & baru berlaku bersamaan, lalu hapus yang lama).
- Cara termudah membuka di TV: login di browser TV → klik **📺 Display TV** di Dashboard → bookmark tab yang terbuka. Tim tak perlu tahu token.
- Mengganti token: edit `.env` (tambah token baru di samping lama), bagikan URL/tombol baru, lalu hapus token lama.

---

## 5. Konfigurasi & Deploy

### Struktur & keamanan (sudah terpasang)
- Skrip maintenance (`db_migrate.php`, `db_check.php`, `migrate.php`) **hanya jalan dari CLI** (diblok via HTTP).
- Berkas unggahan (KTP/NPWP/akta/TTD) **tidak** bisa diakses URL langsung — disajikan via route ber-gerbang (`?r=file`) yang butuh login atau token sah.
- Link TTD SKP/penawaran **kedaluwarsa 30 hari**.

### Deploy ke hosting (SSH)
```bash
cd ~/public_html/clara
git pull
php db_migrate.php      # menjalankan migrasi DB terbaru
php db_check.php        # verifikasi tidak ada schema drift
```
Lalu pastikan:
- Folder `public/uploads/{skp,contract,signatures,.mpdf}` **mode 777** (web server perlu menulis).
- `.env` produksi: `DISPLAY_TOKEN` kuat, kredensial DB benar, `APP_DEBUG=false`.

### Backup DB
- Otomatis via cron `~/backup-clara.sh` setiap **19.00**, output `clara_YYYY-MM-DD_HH-MM.sql.gz` di `~/backups/clara/`, retensi 30 hari.

> Jangan ALTER database manual — semua perubahan skema lewat file migrasi di `database/migrations/`.

---

## 6. Multi-properti
Akun superadmin dapat berpindah properti via tab di topbar. Halaman agregat (Executive/Display) menggabungkan semua properti; halaman per-properti (Transaksi) mengikuti properti aktif.
