# Panduan CLARA — Manajer CL (Persetujuan & Pengawasan)

**Untuk:** Superadmin yang menjalankan peran **manajer** Casual Leasing.
**Prinsip:** menyetujui pekerjaan tim, mengawasi lintas-sales, dan membaca angka eksekutif.

> Catatan: pada konfigurasi saat ini (opsi B), **Manajer = bagian dari akun Superadmin** — secara teknis akun Anda punya akses penuh, tetapi panduan ini fokus ke **tugas manajerial** sehari-hari. Tugas administrasi sistem ada di Panduan Superadmin.

---

## 1. Persetujuan (inti peran Manajer)

### A. Approve / Reject SKP
1. Menu **SKP Pameran** → SKP berstatus *submitted* menunggu Anda.
2. Buka, periksa: kelengkapan **KTP/NPWP**, nilai, periode, dan lampiran.
3. **Approve** → sistem secara **atomik** menerbitkan nomor SKP final + transaksi + alokasi (inilah titik deal masuk ke Dashboard/Achievement/Recurring). **Reject** → kembali ke sales dengan catatan.

> Approve bersifat sekali-jalan & terkunci. Pastikan benar sebelum menyetujui.

@@SHOT:skp|Daftar SKP — yang berstatus submitted menunggu persetujuan@@

@@SHOT:skp_form|Detail SKP — periksa identitas, nilai, lampiran sebelum Approve/Reject@@

### B. Setujui Permintaan Kontrak (peran Legal/approver)
1. Menu **Permintaan Kontrak** → buka permintaan yang masuk.
2. Tinjau formulir + semua lampiran (KTP/NPWP/Akta/Surat Kuasa/SKP ber-TTD).
3. **Setujui** → status jadi *Disetujui Legal*; pemohon (sales) melihat perubahannya. Persetujuan tercatat atas nama akun Anda.

@@SHOT:contract|Permintaan Kontrak — daftar permintaan untuk ditinjau & disetujui@@

### C. Batal Transaksi
1. Dari daftar transaksi → **Batalkan** (termasuk satu komponen paket).
2. **Alasan wajib diisi** — tercatat di Activity Log. Komponen paket lain tetap berjalan.

---

## 2. Pengawasan tim (lintas-sales)

Berbeda dari sales (yang hanya lihat datanya sendiri), Anda melihat **semua sales & properti**.

- **Laporan PIC** — pencapaian setiap sales vs target.
- **Performa PIC & Aktivitas/Pipeline PIC** — produktivitas dan corong penawaran tim.
- **Rewarding PIC** — kelola periode & lihat reward (atur **periode mulai reward** di halaman ini).
- **Export** — unduh CSV / Excel / Print untuk rapat.

@@SHOT:pic|Laporan PIC — pencapaian semua sales vs target@@

---

## 3. Pandangan eksekutif

- **Executive Summary** — ringkasan revenue, target, occupancy seluruh properti.
- **📺 Display TV** — tombol di **Dashboard** membuka tampilan TV di tab baru (token otomatis; tak perlu menyalin apa pun). Cocok untuk layar di kantor.
- **Trend Revenue / Perbandingan Periode / Occupancy Harian** — analisa pergerakan.

@@PAIR:exec|m_exec|Executive Summary — revenue, target, occupancy semua properti (tombol 📺 Display TV di kanan atas)@@

---

## 4. Menjaga revenue

- **Renewal Kontrak** — papan kontrak yang akan habis (window −7 s/d +30 hari, urgensi 🔴≤15h / 🟠16–30h). Update status: *belum dihubungi → nego → akan perpanjang → diperpanjang / tidak lanjut*. Tombol **+ Perpanjang** membuat transaksi baru terisi dari kontrak lama.
- **Simulasi Komisi** — hitung skenario komisi sebelum keputusan.

@@SHOT:renewals|Papan Renewal Kontrak — urgensi 🔴≤15h / 🟠16–30h, tombol + Perpanjang@@

@@SHOT:commission|Simulasi Komisi — hitung skenario sebelum keputusan@@

---

## 5. Batas peran Manajer (tugas Superadmin)
Membuat/menonaktifkan **akun & role**, mengatur **Role & Permission**, membaca **Activity Log**, memulihkan **Transaksi Dihapus**, serta **konfigurasi sistem** (token Display TV, deploy, migrasi DB) — lihat **Panduan Superadmin**.

---

## 6. Ritme harian yang disarankan
1. Cek antrean **SKP** untuk di-approve.
2. Cek **Permintaan Kontrak** menunggu persetujuan.
3. Pantau **Renewal** yang 🔴 mendesak.
4. Lihat **Laporan/Performa PIC** untuk progres tim.
