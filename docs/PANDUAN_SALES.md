# Panduan CLARA — Tim Sales (CL)

**Untuk:** Petugas Casual Leasing di lapangan (role `sales`)
**Prinsip:** operasional harian, ramah HP, **hanya melihat data milik Anda sendiri**.

> Anda bisa membuka CLARA dari HP (otomatis tampilan mobile) maupun komputer. Alur di bawah memakai istilah menu yang sama di keduanya.

---

## 1. Masuk & persiapan akun

1. Buka **https://clara.wbl-bsb.com** → masukkan email & password.
2. Login pertama: sistem meminta **ganti password** (min. 8 karakter, ada huruf besar, kecil, angka, simbol).
3. Sekali saja, lengkapi **Tanda Tangan Saya** (menu akun di pojok atas) — unggah gambar TTD Anda. TTD ini muncul sebagai **QR validasi** di dokumen, bukan gambar mentah (anti jiplak).

> Sesi otomatis logout setelah 30 menit menganggur — wajar, login lagi.

---

## 2. Alur kerja inti: Penawaran → SKP → Kontrak

Urutannya **offer-first**: semua berawal dari Surat Penawaran, bukan input transaksi langsung.

### A. Buat Surat Penawaran

@@SHOT:offers|Daftar Surat Penawaran — tab status (On Going / Deal / Tidak Deal) + filter modul@@

1. Menu **Surat Penawaran** → **+ Buat Penawaran**.
2. Pilih **Client** (ketik untuk cari; bila baru, tambahkan di Master Client).
3. Pilih **Unit/Titik** (Exhibition / Media / Gudang) lewat pencarian — rate & tipe otomatis terisi dari master.
4. Isi periode (tanggal mulai–selesai), nominal, dan ketentuan. Sistem hitung total & DP otomatis sesuai template tipe booth.
5. **Paket?** Centang "Penawaran Paket" → **+ Tambah komponen** untuk gabungkan beberapa booth/media dalam 1 surat.
6. **Simpan**. Nomor penawaran terbit.

@@SHOT:offer_form|Form buat penawaran — pilih client, unit/titik, periode, dan nominal@@

### B. Kirim ke customer untuk tanda tangan
1. Buka penawaran → tombol **Bagikan / Link TTD**. Sistem membuat tautan aman.
2. Kirim tautan via WhatsApp (tombol WA tersedia). Customer membuka, meninjau, lalu **tanda tangan online** langsung dari HP mereka.
3. Status berubah jadi **deal** begitu customer TTD. Surat terkunci (snapshot) — nilai tidak berubah lagi.

> **Masa berlaku link:** tautan TTD kedaluwarsa setelah **30 hari** (dan/atau masa berlaku penawaran). Bila kedaluwarsa, terbitkan link baru dari penawaran.

### C. Buat SKP (Surat Konfirmasi Pemesanan)
1. Dari penawaran yang sudah deal → buat **SKP Pameran**.
2. Lengkapi identitas wajib: **No. KTP** & **No. NPWP** penanggung jawab (SIUP opsional), unggah lampiran scan.
3. **Submit untuk approval.** ✋ Anda **tidak** menyetujui sendiri — SKP masuk antrean **Manajer** untuk di-approve.
4. Setelah di-approve manajer, SKP final + transaksi otomatis terbit (masuk ke dashboard/achievement).

@@SHOT:skp|Daftar SKP Pameran — status submitted menunggu approval manajer@@

### D. Permintaan Kontrak ke Legal
1. Menu **Permintaan Kontrak** → buat dari SKP final.
2. Unggah berkas pendukung (Akta, Surat Kuasa bila perlu).
3. Kirim → menghasilkan tautan untuk **Departemen Legal** meninjau & menyetujui. Anda pantau statusnya di sini.

@@SHOT:contract|Permintaan Kontrak — kirim berkas ke Legal & pantau status persetujuan@@

---

## 3. Memantau pekerjaan Anda

- **Transaksi** (Exhibition / Media / Gudang): lihat & input transaksi.
- **Laporan PIC / Performa / Pipeline / Reward**: menampilkan **angka Anda sendiri** — pencapaian vs target, pipeline penawaran, dan reward.
- **Dashboard / Trend / Occupancy**: gambaran umum revenue & keterisian.

@@SHOT:trx|Daftar transaksi Exhibition — input & pantau transaksi@@

@@SHOT:pic|Laporan PIC — pencapaian vs target (data Anda sendiri)@@

> **Penting — visibilitas:** sebagai sales, Anda hanya melihat penawaran/SKP/laporan **milik Anda**. Data rekan lain tidak tampil. Itu memang dirancang begitu.

---

## 4. Tampilan HP (mobile)

Bottom-nav menyesuaikan izin Anda: **Beranda · Penawaran · Transaksi**.
- **Beranda:** ringkasan pencapaian pribadi.
- **Penawaran / SKP:** kartu daftar + filter + aksi (Edit / PDF / Bagikan).
- Tombol **Bagikan** mengirim PDF langsung (butuh HTTPS).

@@SHOTM:m_home|Beranda mobile — pencapaian & menu cepat@@

@@SHOTM:m_offers|Daftar Penawaran versi HP — kartu + aksi@@

---

## 5. Yang TIDAK tersedia untuk Sales
Approve/Reject SKP, setujui kontrak Legal, batalkan transaksi, Executive Summary & Display TV, Export laporan, Renewal Kontrak, Simulasi Komisi, dan menu Admin. Semua itu ada di tier Manajer/Superadmin.

---

## 6. Masalah umum
- **Lupa password** → hubungi Superadmin untuk reset.
- **Link TTD customer kedaluwarsa** → buka penawaran, terbitkan link baru.
- **SKP belum jadi transaksi** → cek apakah sudah di-approve Manajer.
- **Customer tak bisa buka lampiran** → pastikan Anda kirim tautan resmi (jangan salin URL berkas langsung).
