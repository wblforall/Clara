# Panduan CLARA — Tagihan & Pembayaran

**Untuk:** Admin Sales dan Sales (yang **mengisi**), serta Manager (yang **memeriksa**)
**Menu baru:** Input → **Tagihan & Pembayaran** · Analisa → **Kalender Pameran**

> Panduan ini hanya membahas menu yang baru. Alur Penawaran, SKP/SKS/FU, dan persetujuan tidak berubah sama sekali — tetap seperti yang selama ini dipakai.

---

## 1. Kapan sebuah kontrak muncul di Tagihan

Ini pertanyaan yang paling sering muncul, jadi dijawab lebih dulu.

Barisnya **tidak** muncul begitu SKP disetujui Manager. Ia muncul saat **client menandatangani** dokumennya.

1. Surat Penawaran → client tanda tangan → status **DEAL**
2. Buat SKP / SKS / Form Utilities → diparaf Asst. Manager → disetujui Manager
   → nomor dokumen terbit dan **transaksinya lahir**, tetapi **belum masuk Tagihan**
3. **Client menandatangani dokumen** → barisnya **otomatis muncul** di Tagihan & Pembayaran, dengan status awal *Belum ada invoice*
4. Admin Sales mengisi invoice → status jadi *Belum dibayar*
5. Pembayaran dicatat → *Sebagian* → *Lunas*

> **Patokan singkat:** kalau barisnya ada di menu **Exhibition / Media / Gudang**, ia pasti ada juga di **Tagihan & Pembayaran**. Kalau tidak ada di sana, berarti dokumennya belum diteken client — yang perlu diurus tanda tangannya, bukan invoicenya.

> **Tidak ada tombol "Buat Invoice".** Barisnya sudah tersedia sendiri; tugas Anda hanya **mengisi** kolom yang masih kosong.

Kontrak lama yang dibuat **sebelum 8 Oktober 2026** mengikuti aturan lama: langsung tampil tanpa menunggu tanda tangan. Karena itu daftarnya saat pertama dibuka berisi banyak baris berstatus *Belum ada invoice* — itu wajar, bukan kerusakan.

@@PB@@

## 2. Membaca daftarnya

@@SHOT:tg-daftar|Tagihan & Pembayaran — empat kartu ringkasan, tab status, lalu tabelnya@@

**Empat kartu di atas** merangkum periode yang sedang dipilih: total **Tagihan**, **Sudah dibayar**, **Belum dibayar**, dan berapa transaksi yang **Lewat jatuh tempo**.

**Tab status** di bawahnya berfungsi sebagai saringan cepat sekaligus penghitung. Angka dalam kurung adalah jumlah barisnya.

| Status | Artinya |
|---|---|
| **Belum ada invoice** | Belum satu pun dari nomor, tanggal, atau jumlah tagihan diisi |
| **Belum dibayar** | Invoice sudah diisi, tetapi belum ada pembayaran yang dicatat |
| **Sebagian** | Sudah ada pembayaran, tetapi belum menutup seluruh tagihan |
| **Lunas** | Jumlah terbayar sudah sama dengan atau melebihi tagihan |
| **Lewat tempo** | Penanda tambahan: jatuh tempo sudah lewat dan belum lunas |

**Saringan baris atas:** **Periode** (bulan), **Dihitung dari** (tanggal mulai atau tanggal selesai sewa yang jatuh di bulan itu), **Modul**, **PIC**, dan kotak **Cari** untuk nama klien, kode unit, atau nomor invoice. Tombol **Semua periode** membuka seluruh data tanpa batas bulan.

**Unduh CSV** mengambil persis baris yang sedang tampil — ikut saringannya. Berkasnya langsung bisa dibuka di Excel.

Tombol **Kelola** di ujung kanan tiap baris membuka halaman pengisian.

@@PB@@

## 3. Mengisi invoice

@@SHOT:tg-form-kosong|Halaman Invoice & Pembayaran pada transaksi yang belum ditagih@@

Dari daftar, tekan **Kelola**. Bagian atas memperlihatkan ringkasan kontraknya — unit, penyewa, periode sewa, PIC, nilai transaksi, tagihan, terbayar, dan sisa. Panel **Invoice** ada di kiri.

| Nama Field | Wajib | Penjelasan |
|---|---|---|
| **No. Invoice** | Tidak | Nomor invoice yang Anda terbitkan, mis. `INV/CL-EWALK/26/X/014` |
| **Tanggal invoice** | Tidak | Tanggal invoice diterbitkan |
| **Jatuh tempo** | Tidak, **tapi isi** | Tanpa ini, penanda *Lewat tempo* tidak akan pernah muncul walau sudah telat berbulan-bulan |
| **Jumlah tagihan** | Tidak | Dikosongkan = dianggap sama dengan nilai transaksi. Diisi hanya bila invoicenya memang berbeda |

Lalu tekan **Simpan invoice**.

> **Isi ketiganya sekaligus** — nomor, tanggal, dan jatuh tempo. Mengisi salah satu saja memang sudah mengubah status dari *Belum ada invoice*, tetapi pelacakan jatuh temponya jadi pincang.

> **Dua tombol pintas** di bawah kolom jumlah — *Nilai + PPN 11%* dan *Sama dengan nilai transaksi* — hanya mengisikan angka ke kotaknya, tidak langsung menyimpan. Tarif PPN di CLARA mengikuti template penawaran masing-masing, jadi **cocokkan dulu angkanya dengan surat penawaran** sebelum disimpan.

@@PB@@

## 4. Mencatat pembayaran

@@SHOT:tg-form-isi|Invoice sudah terisi dan satu pembayaran DP tercatat — status berubah jadi "Sebagian"@@

Panel **Pembayaran** ada di kanan. Riwayat pembayaran tampil di atas, formulir pencatatannya di bawah.

| Nama Field | Wajib | Penjelasan |
|---|---|---|
| **Tanggal bayar** | **Ya** | Tanggal uangnya diterima, bukan tanggal Anda mencatat |
| **Jumlah dibayar** | **Ya** | Harus lebih dari nol. Terisi otomatis sebesar sisa tagihan — ubah bila yang masuk hanya sebagian |
| Cara bayar | Tidak | Transfer, Tunai, Giro, atau Lainnya |
| No. referensi / bukti | Tidak | Nomor rujukan transfer atau bukti setor |
| Catatan | Tidak | Keterangan singkat, mis. `DP 30%`, `Termin 2`, `Pelunasan` |

Tekan **Catat pembayaran**. Statusnya langsung menyesuaikan.

> **Satu pembayaran, satu baris.** DP, termin, dan pelunasan dicatat terpisah — jangan dijumlahkan jadi satu baris. Riwayatnya yang membuat selisih bisa ditelusuri belakangan.

> **Lebih bayar diperbolehkan.** Bila jumlah yang masuk melebihi tagihan, kolom *Sisa* berganti jadi **Lebih bayar**. Tidak ada yang ditolak sistem.

Pembayaran yang salah catat bisa dihapus lewat tombol **Hapus** di baris riwayatnya.

@@PB@@

## 5. Status bayar ikut tampil di daftar transaksi

@@SHOT:tg-badge-trx|Daftar Exhibition — lencana status bayar muncul di tiap baris dan bisa diklik@@

Anda tidak harus selalu lewat menu Tagihan. Di menu **Exhibition**, **Media**, dan **Gudang**, tiap baris kini memperlihatkan lencana status bayarnya. Lencana itu bisa diklik dan langsung membuka halaman pengisian transaksi tersebut.

---

## 6. Untuk Sales — hanya milik Anda sendiri

@@SHOT:tg-sales|Daftar yang sama dibuka oleh Sales — hanya transaksi miliknya yang tampil@@

Bila Anda masuk sebagai **Sales**, menu ini hanya memperlihatkan transaksi yang **PIC-nya Anda** atau yang **Anda buat sendiri**. Jumlah di kartu ringkasan dan di tab status juga ikut menyesuaikan, jadi angkanya adalah angka Anda sendiri.

Membuka transaksi milik rekan lain lewat tautan langsung akan ditolak dengan pesan *"Transaksi tidak ditemukan atau bukan milik Anda."*

> Saringan **PIC** tetap menampilkan nama rekan-rekan Anda, tetapi memilihnya tidak akan memunculkan data mereka. Itu bukan kebocoran — datanya memang tidak diambil.

---

## 7. Untuk Manager — memeriksa, tidak mengubah

@@SHOT:tg-manager|Halaman yang sama dibuka Manager — ada keterangan "Hanya melihat", dan tombol simpan tidak ada@@

Manager dan Asst. Manager bisa membuka menu ini dan melihat seluruh tagihan di propertinya, termasuk mengunduh CSV. Yang **tidak** bisa dilakukan: mengisi invoice, mencatat pembayaran, atau menghapus pembayaran.

Di halaman pengisian akan muncul keterangan **"Hanya melihat"**, seluruh kotak isian dimatikan, dan tombol simpan tidak ditampilkan. Lencana status bayar tetap muncul di daftar Exhibition, Media, dan Gudang — jadi sebelum menyetujui sesuatu, keadaan tagihannya bisa dicek sekilas.

> Pembagian ini diatur di **Users → Roles**: izin **Lihat Invoice & Pembayaran** untuk memeriksa, **Isi Invoice & Pembayaran** untuk mengisi. Superadmin bisa mengubahnya kapan saja tanpa menyentuh kode.



## 8. Kalender Pameran — sekilas

@@SHOT:tg-kalender|Kalender Pameran — unit di baris, tanggal 1–31 di kolom@@

Menu **Analisa → Kalender Pameran** memperlihatkan unit sebagai baris dan tanggal 1–31 sebagai kolom, dengan nama merek di tiap selot yang terisi. Tersedia untuk Exhibition, Media, dan Gudang.

- Saringan **lantai/jenis/lokasi** dan **PIC**, kotak **Cari merek**, persentase keterisian, dan penanda hari ini.
- **Klik satu selot** → rinciannya muncul beserta tautan ke **Invoice & bayar**, **rincian transaksi**, dan **perpanjangan**.
- **Klik dua tanggal kosong** di satu unit → tombol **Buat Surat Penawaran**; unit, tanggal, tarif, dan luas langsung terisi.
- Sales hanya melihat nilai dan status bayar pada selot miliknya sendiri.

> **Khusus Exhibition.** Pengisian otomatis dari klik dua tanggal hanya berlaku di modul Exhibition. Di Media dan Gudang formulir penawarannya tetap terbuka, tetapi kosong — isi manual seperti biasa.

---

@@PB@@

## 9. Rutinitas yang perlu dijalankan

CLARA **tidak** mengirim pengingat jatuh tempo — tidak ada notifikasi, tidak ada email. Jadi harus ada yang membukanya secara rutin.

1. **Setiap Senin**, Admin Sales membuka **Tagihan & Pembayaran** → tekan tab **Lewat tempo** → tindak lanjuti satu per satu.
2. Catat pembayaran yang masuk **di hari yang sama**, supaya tab *Lewat tempo* bisa dipercaya.
3. Sekali sebulan, buka tab **Belum ada invoice** untuk periode berjalan — isinya adalah kontrak yang sudah diteken client tetapi belum ditagih.

> **Tanda menu ini benar-benar dipakai:** tab *Belum ada invoice* bulan berjalan mengecil tiap minggu, dan *Lewat tempo* tidak menumpuk.
