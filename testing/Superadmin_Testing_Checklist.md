# Checklist Pengujian (Testing) Modul Superadmin CLARA

Berikut adalah daftar periksa (*checklist*) pengujian *End-to-End* khusus untuk peran **Superadmin**. Anda bisa mencentang kotak-kotak ini saat melakukan simulasi langsung di aplikasi.

> [!IMPORTANT]
> Pastikan Anda *login* menggunakan akun yang sudah di-set sebagai Superadmin sebelum memulai pengujian ini.

## 1. Pengujian Manajemen Pengguna & Hak Akses (Users & Role)
- [ ] Buka menu **Users & Role**.
- [ ] Tambahkan *User* baru (misalnya akun untuk "Sales Budi"). Pastikan Anda mengisi nama, email, dan memilih role *Sales*.
- [ ] Uji fungsi non-aktifkan *User* (jangan dihapus). Pastikan *user* yang dinonaktifkan tidak bisa *login* lagi.
- [ ] Buka menu **Role & Permission**.
- [ ] Buat *Role* baru bernama "Manajer Khusus" (opsional, jika opsi B digunakan). 
- [ ] Centang *permission* penting untuk Manajer pada role tersebut: `approve_skp`, `manage_offers/skp`, `view_exec_summary`, `view_renewals`, `view_commission_sim`, `export_reports`, dan `view_pic_report`.
- [ ] Pastikan *permission* administratif seperti `manage_users`, `view_logs`, dan `manage_deleted` **TIDAK** tercentang untuk akun Manajer (hanya untuk Superadmin).
- [ ] Tautkan akun *Sales* percobaan Anda ke *Master PIC* agar laporannya bisa akurat.

## 2. Pengujian Pengelolaan Master Data
- [ ] Buka menu **Master Data**.
- [ ] Masuk ke sub-menu **Exhibition** / **Media** / **Gudang**. Coba tambahkan 1 unit/titik sewa fiktif beserta tarif (*rate*) dan tipenya.
- [ ] Masuk ke sub-menu **Template Penawaran**. Edit atau tambahkan template baru untuk memastikan teks peraturannya berubah.
- [ ] Masuk ke sub-menu **PIC** & **Target Bulanan**. Setel target fiktif untuk akun Sales yang sudah Anda buat tadi.
- [ ] Masuk ke **Master Client** & **Referrer**. Uji coba menambah satu *Client* baru, pastikan data seperti komisi/rekening (jika ada) bisa diisi.
- [ ] Buka **Kelola Opsi Dropdown** dan pastikan Anda bisa menambah atau mengubah pilihan baku (seperti *Tipe Unit*).

## 3. Pengujian Audit & Pemulihan (Log & Trash)
- [ ] Buka menu **Activity Log**.
- [ ] Lakukan tindakan apa saja (misal edit Master Data atau ubah nama User).
- [ ] Kembali ke **Activity Log**, pastikan tindakan Anda tadi **tercatat** (Siapa *user*-nya, IP Address-nya, dari modul mana, dan apa data *Before/After*-nya).
- [ ] Buka menu **Transaksi Dihapus**.
- [ ] (Butuh transaksi fiktif) Hapus sebuah transaksi, lalu cek di menu ini apakah transaksi tersebut muncul. Coba tekan tombol "Pulihkan/Restore". Pastikan data kembali dengan selamat.
- [ ] Buka fitur **Konversi Recurring** dan coba gabungkan transaksi utama (*anchor*) menjadi cicilan/berulang (jika Anda memiliki data simulasi transaksi *recurring*).

## 4. Pengujian Fitur Khusus & Lintas-Properti
- [ ] Perhatikan **Top Bar** (menu atas). Jika Anda adalah Superadmin multi-properti, pastikan ada tombol (tab) untuk berpindah-pindah lokasi properti (misalnya dari e-Walk ke Pentacity).
- [ ] Klik tab properti tersebut, pastikan halaman daftar Transaksi dan Laporan langsung berubah menyesuaikan properti yang sedang aktif.
- [ ] Buka menu **Executive Summary**. Pastikan halaman ini menampilkan gabungan/agregat dari seluruh properti yang ada.
- [ ] Uji coba **Display TV**. Tekan tombol bergambar TV `Display TV` di Dashboard. Pastikan halaman baru terbuka dan tertampil dengan baik.

## 5. Pengujian Sisi Keamanan (Pengecekan Server/Teknis)
> [!NOTE]
> Poin ini lebih diperuntukkan jika Anda adalah staf IT yang memiliki akses ke *hosting* / server.

- [ ] Cek *file* `.env`. Pastikan `DISPLAY_TOKEN` terisi dengan *string* yang kuat.
- [ ] Ganti token di `.env` (misal dengan memisahkan token lama dan baru memakai koma). Pastikan layar *Display TV* yang sedang menyala di kantor tidak mati/error (*downtime*). Lalu hapus token yang lama.
- [ ] Uji coba tautan *URL bypass*. Salin *link* *file* gambar rahasia (seperti Bukti Transfer/KTP dari suatu transaksi) lalu buka di *browser* lain/mode *Incognito* tanpa *login*. Pastikan sistem **menolak** dan melindungi dokumen tersebut.
- [ ] Via *Terminal/CLI* server, tes jalankan `php db_check.php` untuk memastikan tidak ada tabel *database* yang melenceng dari skema (*schema drift*).
