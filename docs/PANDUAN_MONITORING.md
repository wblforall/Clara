# Panduan CLARA — Monitoring Transaksi

**Untuk:** PIC / Sales, Admin Sales, dan Asisten Manager
**Letak menu:** Kontrak & Tagihan → **Monitoring Transaksi**

> Menu ini tidak mengubah satu pun alur yang sudah berjalan. Penawaran, SKP/SKS/Form Utilities, persetujuan, permintaan kontrak, dan pencatatan pembayaran tetap dikerjakan di tempatnya masing-masing. Monitoring Transaksi hanya **membaca** semuanya dan menyusunnya jadi satu daftar.

---

## 1. Untuk apa menu ini ada

### 1.1 Dua pertanyaan yang dijawab tiap baris

Sebelum menu ini ada, untuk tahu sebuah kesepakatan sudah sampai mana orang harus membuka empat layar berbeda: dokumennya di SKP, permintaan kontraknya di Legal, invoicenya di Tagihan, lalu pembayarannya. Kalau yang ditanya "punya si A ada berapa yang belum kelar", jawabannya hanya bisa didapat dengan bertanya langsung ke orangnya.

Satu baris di Monitoring Transaksi menjawab dua hal sekaligus:

1. **Berkas ini berhenti di mana?** — dari dokumen baru dibuat sampai pembayaran lunas, ada empat belas kemungkinan tahap, dan tiap baris menunjukkan satu.
2. **Sekarang giliran siapa?** — Pembuat dokumen, Approver, PIC, Legal, atau Admin Sales.

Dan karena pertanyaan itu dijawab untuk **semua** berkas sekaligus, muncul jawaban ketiga yang tidak kalah penting: **pencapaian** — sudah berapa yang tuntas, berapa yang masih menggantung, dan sudah berapa lama.

### 1.2 Yang masuk daftar — dan yang tidak

Satu baris = **satu dokumen**: SKP Exhibition, SKS Gudang, atau Form Utilities.

| Pertanyaan | Jawaban |
|---|---|
| Kenapa bukan penawaran? | Penawaran yang belum jadi dokumen belum menuntut pekerjaan siapa pun. Yang perlu dikejar baru lahir setelah ada SKP/SKS/FU. |
| Kenapa bukan transaksi? | Satu dokumen paket menerbitkan beberapa transaksi untuk **satu** kesepakatan. Ditampilkan sebagai tiga baris, satu kesepakatan jadi terbaca seperti tiga pekerjaan. |
| Dokumen paket nilainya bagaimana? | Nilai dan tagihannya dijumlah dari **seluruh** transaksi rombongannya, bukan satu saja. Kolom periode sewa menyebut "· 3 lokasi" bila memang tiga. |

> **Transaksi tanpa dokumen tidak muncul di sini.** Data lama sebelum CLARA dan transaksi yang diinput langsung tidak punya SKP/SKS/FU, jadi tidak punya perjalanan yang bisa dilacak. Angkanya tetap dihitung Dashboard — lihat [bagian 5.2](#52-mencocokkan-dengan-income-dashboard) yang menampilkan selisihnya terang-terangan.

### 1.3 Siapa yang boleh membuka

Izinnya bernama `view_monitoring` dan sudah menyala untuk **Sales, Administrasi, Asisten Manager, Supervisor, dan Finance** — ditambah Admin dan Superadmin yang memang selalu bisa.

Peran **Sales/PIC** membuka layar yang sama persis, tetapi datanya otomatis dibatasi ke miliknya sendiri: dokumen yang ia buat, atau yang PIC-nya dirinya. Kotak PIC pada saringan ikut terkunci ke namanya — bukan "Semua PIC", yang membuat orang mengira sedang melihat data satu mal padahal sudah dibatasi.

### 1.4 Tidak ada status yang disimpan

Tahap sebuah dokumen **tidak pernah disimpan** di basis data. Ia dihitung ulang dari keadaan dokumen, permintaan kontrak, invoice, dan pembayaran **setiap kali halaman dibuka**. Konsekuensinya: tidak ada kolom status yang bisa basi, dan tidak ada yang perlu "memperbarui status" secara manual. Begitu invoice diisi di menu Tagihan, baris di sini langsung pindah tahap.

Satu-satunya yang disimpan adalah **jejak tindak lanjut** — siapa mengejar apa, kapan, dan apa jawabannya (bagian 8).


## 2. Lencana pengingat di menu samping

@@FIG:mn-badge|Angka merah di sebelah nama menu = berkas yang menunggu tindakan Anda@@

Angka pada lencana adalah jumlah berkas yang **giliran Anda**, bukan jumlah seluruh berkas. Inilah pengingatnya: terlihat tiap kali CLARA dibuka, di halaman mana pun, tanpa perlu membuka menunya dan tanpa menambah apa pun di Dashboard.

Cara angka itu ditentukan berbeda menurut peran Anda:

| Tahap berkas | Dihitung sebagai giliran siapa |
|---|---|
| Menunggu persetujuan | Orang yang parafnya sedang ditunggu |
| Draft / Sedang direvisi / Ditolak | Pembuat dokumennya |
| Menunggu tanda tangan klien · Belum dibayar · Dibayar sebagian | PIC dokumennya |
| Transaksi belum terbit · Invoice belum diterbitkan · Tanpa tagihan | Admin Sales |
| Permintaan kontrak masih draft | Yang mengajukan permintaannya |
| Menunggu Legal · Menunggu nomor kontrak | Legal — **tidak** dihitung ke siapa pun di sini |

Lencananya diingat selama **3 menit**, jadi tidak dihitung ulang di setiap halaman yang Anda buka. Angkanya langsung disegarkan begitu Anda mencatat satu tindak lanjut. Berkas yang **ditunda** atau **dikecualikan** tidak ikut dihitung.


## 3. Sekali lihat: isi layarnya

@@FIGL:mn-penuh|Monitoring Transaksi dibuka dengan akun Admin Sales — saringan, enam ubin, lalu papan PIC dan tabelnya di bawah@@

Dari atas ke bawah ada lima bagian, dan tiap bagian dibahas di babnya sendiri:

| | Bagian | Isinya | Bab |
|---|---|---|---|
| 1 | **Saringan** | Dua belas pilihan + tiga tombol bulan | 4 |
| 2 | **Enam ubin ringkasan** | Dokumen, Income, Tuntas, Menggantung, Giliran saya, Lewat tempo | 5 |
| 3 | **Panel pencocokan** | Muncul bila satu bulan dipilih — membandingkan dengan Dashboard | 5.2 |
| 4 | **Pencapaian per PIC** | Satu baris per PIC, dengan batang persentase | 6 |
| 5 | **Tabel dokumen** | Satu baris per berkas, 100 baris per halaman | 7 |


## 4. Kotak Saringan

@@FIG:mn-saringan|Kotak Saringan — tiga tombol bulan di kanan atas, dua belas pilihan di bawahnya@@

### 4.1 Tiga tombol bulan

Di kanan atas ada **bulan ini**, **bulan lalu**, dan **Semua bulan** — jalan pintas untuk pertanyaan yang paling sering ditanyakan. Untuk bulan lain, pakai kotak **Bulan** di baris pertama.

Bawaannya **Semua bulan**, supaya berkas lama yang masih menggantung tidak ikut hilang dari pandangan hanya karena sewanya sudah lewat.

### 4.2 Dua belas pilihan

| Saringan | Isinya |
|---|---|
| **Cari** | Nama penyewa, brand, nomor dokumen, kode unit, nomor kontrak, atau nomor invoice |
| **PIC** | Daftar PIC aktif properti ini. Pada akun Sales kotak ini **terkunci** ke nama Anda |
| **Bulan** | Satu bulan tertentu; kosong = semua bulan |
| **Bulan dihitung dari** | Sewa berjalan di bulan itu · Sewa mulai di bulan itu · Dokumen dibuat di bulan itu |
| **Tampilkan** | Belum tuntas · Tuntas · Lewat jatuh tempo · Ditunda/dikecualikan · Semua |
| **Tahap sekarang** | Salah satu dari empat belas tahap (bab 7.3) |
| **Giliran siapa** | Pembuat dokumen · Approver · PIC · Legal · Admin Sales · Selesai |
| **Kontrak** | Belum diajukan · Draft · Di Legal · Menunggu nomor · Ada nomor |
| **Jenis dokumen** | SKP Exhibition · SKS Gudang · Form Utilities |
| **Modul** | Exhibition · Media · Gudang |
| **Macet minimal** | Berapa pun · 7 hari · 14 hari · 30 hari |
| **Hanya giliran saya** | Centang di kaki kotak — langsung menyaring begitu diklik |

> **"Bulan dihitung dari" menentukan apakah angkanya bisa disandingkan dengan Dashboard.** Bawaannya **Sewa berjalan di bulan itu**, karena itulah dasar yang dipakai Dashboard untuk mengakui income. Dua pilihan lainnya berguna untuk pertanyaan lain ("berapa yang mulai bulan ini?"), tetapi angkanya memang tidak akan sama dengan Dashboard — dan CLARA akan mengingatkan Anda bila memilihnya.

### 4.3 Angka dalam kurung

Pilihan **Tampilkan**, **Tahap sekarang**, **Giliran siapa**, dan **Kontrak** memuat angka dalam kurung, misalnya *Belum tuntas (156)*. Angka itu dihitung dari daftar **sebelum** ketiga saringan tersebut dipakai — kalau tidak, memilih satu tahap akan membuat semua angka lainnya jadi nol dan tidak ada lagi yang bisa dibandingkan.

Tombol **Terapkan** menjalankan saringan; **Reset** mengembalikan semuanya ke bawaan. Bila ada saringan yang sedang aktif, muncul lencana *"N aktif"* di sebelah judul SARINGAN.


## 5. Enam ubin ringkasan

@@FIG:mn-ubin|Enam ubin — warna hanya dipakai pada angka yang memang perlu diwaspadai@@

| Ubin | Artinya |
|---|---|
| **Dokumen** | Jumlah berkas pada saringan ini. Satu dokumen = satu kesepakatan, walau isinya beberapa lokasi |
| **Income diakui** | Income dari dokumen yang sedang tampil. Bila satu bulan dipilih, judulnya ikut berubah jadi *Income Oktober 2026* dan isinya hanya porsi bulan itu |
| **Tuntas** | Sudah diteken, sudah di-invoice, dan pembayarannya lunas — lengkap dengan persentasenya |
| **Masih menggantung** | Belum tuntas, dengan baris kecil *"terlama N hari"* di bawahnya |
| **Giliran saya** | Angka yang sama persis dengan lencana di menu samping |
| **Lewat jatuh tempo** | Invoice yang tanggal jatuh temponya sudah lewat dan belum lunas |

### 5.1 Ubin mengikuti saringan keras, bukan saringan tampilan

Enam ubin dan papan PIC di bawahnya dihitung dari saringan **keras** — Cari, PIC, Bulan, Modul, Jenis dokumen, Macet minimal, dan Hanya giliran saya. Ketiga saringan **tampilan** (Tampilkan, Tahap sekarang, Giliran siapa, Kontrak) hanya mempengaruhi tabel di bawahnya.

Itu disengaja: "sudah berapa yang tuntas" tidak boleh ikut hilang hanya karena daftarnya sedang dipersempit ke yang macet.

### 5.2 Mencocokkan dengan income Dashboard

@@FIG:mn-banding|Panel pencocokan — muncul sendiri begitu satu bulan dipilih@@

Begitu Anda memilih **satu bulan** dengan dasar *sewa berjalan di bulan itu*, dan tidak sedang menyaring yang lain, muncul panel yang membandingkan dua angka: income di **Dashboard** dan income yang berasal dari **dokumen di daftar ini**.

Kalau keduanya sama, panelnya menulis *"cocok seluruhnya"*. Kalau berbeda, selisihnya disebutkan beserta alasannya: transaksi yang **tidak punya dokumen** SKP/SKS/FU — data lama sebelum CLARA atau transaksi yang diinput langsung. Transaksi semacam itu memang tidak bisa muncul di Monitoring, dan sekarang tidak perlu lagi ditebak-tebak kenapa dua angka tidak sama.

Panel ini sengaja **tidak** ditampilkan bila daftar sedang dipersempit ke satu PIC atau satu tahap, karena membandingkan sebagian daftar dengan total Dashboard justru menyesatkan.


## 6. Pencapaian per PIC

@@FIG:mn-papan|Papan pencapaian — satu baris per PIC, diurutkan dari persentase tuntas tertinggi@@

Papan ini bisa dibuka-tutup, dan terbuka sendiri bila PIC-nya dua belas orang atau kurang. Isinya sebelas kolom:

| Kolom | Artinya |
|---|---|
| **Berkas** | Jumlah dokumen PIC itu pada saringan ini |
| **Tuntas** | Yang pembayarannya sudah lunas |
| **Pencapaian** | Batang persentase tuntas. Hijau ≥ 80%, jingga ≥ 50%, merah di bawahnya |
| **Menggantung** | Yang belum tuntas |
| **Giliran dia** | Berkas yang berhentinya **memang di PIC** — hanya ini yang bisa ia kejar sendiri |
| **Terlama** | Berapa hari berkas yang paling lama berdiri di tahapnya |
| **Lewat tempo** | Invoice yang sudah lewat jatuh tempo dan belum lunas |
| **Nilai tuntas** | Nilai kontrak berkas yang sudah lunas |
| **Belum tuntas** | Nilai kontrak berkas yang belum lunas — inilah yang masih harus dikejar |
| **Income diakui** | Dasar yang sama dengan Dashboard, Laporan PIC, dan Executive Summary |

> **Bedakan "Menggantung" dari "Giliran dia".** Sebuah berkas bisa menggantung berminggu-minggu padahal PIC-nya tidak bisa berbuat apa-apa — misalnya karena masih di Legal atau menunggu Admin menerbitkan invoice. Kolom **Giliran dia** itulah ukuran yang adil untuk menilai seseorang; **Menggantung** adalah ukuran beban, bukan kelalaian.

> **Kenapa "Belum tuntas" lebih besar dari "Income diakui"?** Dua kolom nilai memakai **nilai kontrak**, sedangkan Income memakai dasar Dashboard yang sudah dikurangi listrik dan deposit. Keduanya memang berbeda arti, dan bukan salah satunya yang salah.


## 7. Tabel dokumen

@@FIG:mn-tabel|Tabel dokumen — dua belas kolom, seratus baris per halaman@@

### 7.1 Dua belas kolomnya

| Kolom | Isinya |
|---|---|
| **+** | Membuka rincian berkas itu (bab 8) |
| **Dokumen** | Nomor dokumen — jadi tautan ke dokumennya. Di bawahnya jenis, modul, dan tanggal dibuat |
| **Penyewa** | Nama perusahaan, dengan brand di bawahnya |
| **Unit & periode sewa** | Kode unit, lalu tanggal mulai–selesai. Paket menyebut "· N lokasi" |
| **Nilai** | Nilai kontrak. Bila satu bulan dipilih, income bulan itu ikut di bawahnya |
| **PIC** | Penanggung jawab dokumennya |
| **Tahap sekarang** | Lencana berwarna + langkah berikutnya dalam satu baris |
| **Giliran** | Peran yang ditunggu, dan namanya bila berbeda dari perannya |
| **Macet** | Sudah berapa hari berdiri di tahap sekarang |
| **Kontrak** | Keadaan permintaan kontraknya, dengan nomornya bila sudah ada |
| **Tagihan & pembayaran** | Nomor invoice, lalu "terbayar dari tagihan · sisa" |
| **Dikejar** | Sudah berapa kali berkas ini ditindaklanjuti |

Hampir semua judul kolom bisa diklik untuk **mengurutkan**; panah ▲▼ menunjukkan arah yang sedang dipakai. Bawaannya: yang paling lama macet di atas.

### 7.2 Penanda baris

- **Latar kemerahan tipis** — berkas ini giliran Anda.
- **Angka Macet jingga** pada 7 hari ke atas, **merah** pada 14 hari ke atas.
- **"ditunda s/d …"** atau **"dikecualikan"** di bawah angka Macet — berkas ini sengaja disingkirkan dari daftar kejaran (bab 8.3).
- **"lewat tempo"** merah di kolom Tagihan.

> Kalau datanya melebihi 3.000 dokumen, daftarnya dipotong dan muncul peringatan jingga. Pakai saringan bulan atau PIC supaya angkanya kembali utuh.

### 7.3 Empat belas tahap dan langkah berikutnya

Urutan di bawah ini sama dengan urutan kerja di lapangan, dan **yang pertama cocok itulah tahapnya** — sebuah berkas tidak bisa "menunggu pembayaran" selagi masih menunggu persetujuan.

| # | Tahap | Giliran | Langkah berikutnya |
|---|---|---|---|
| 1 | Draft — belum diajukan | Pembuat | Ajukan dokumen untuk persetujuan |
| 2 | Sedang direvisi | Pembuat | Selesaikan revisi lalu ajukan ulang |
| 3 | Menunggu persetujuan | Approver | Setujui / paraf dokumen |
| 4 | Ditolak | Pembuat | Perbaiki dokumen lalu ajukan ulang |
| 5 | Menunggu tanda tangan klien | PIC | Minta tanda tangan klien |
| 6 | Transaksi belum terbit | Admin Sales | Transaksinya belum terbit — periksa dokumennya |
| 7 | Permintaan kontrak masih draft | Pengaju | Kirim permintaan kontrak ke Legal |
| 8 | Menunggu Legal | Legal | Menunggu Legal menyetujui & memberi nomor |
| 9 | Menunggu nomor kontrak | Legal | Nomor kontrak belum diisi Legal |
| 10 | Invoice belum diterbitkan | Admin Sales | Terbitkan invoice & isi jumlah tagihan |
| 11 | Tanpa tagihan | Admin Sales | Jumlah tagihan masih nol — periksa invoicenya |
| 12 | Belum dibayar | PIC | Tagih pembayaran ke klien |
| 13 | Dibayar sebagian | PIC | Tagih sisa pembayaran ke klien |
| 14 | **Tuntas** | Selesai | — |

Tahap 4 (Ditolak) nilainya dihitung **nol**, karena kesepakatan yang ditolak belum tentu jadi uang dan tidak boleh ikut menggemukkan pencapaian siapa pun.

### 7.4 Kolom Kontrak — kenapa "Belum diajukan" tidak menahan

Permintaan kontrak hanya menahan sebuah berkas kalau permintaannya **memang sudah ada** (tahap 7–9). Berkas yang permintaan kontraknya *belum diajukan sama sekali* tetap berjalan ke tahap invoice.

Itu disengaja: CLARA tidak memutuskan sendiri kesepakatan mana yang wajib berkontrak. Kolom **Kontrak** menunjukkan yang belum diajukan supaya bisa dikerjakan dengan sengaja — tanpa menuduh ratusan dokumen tersangkut di PIC. Bila nanti ada aturan resmi (misalnya "sewa di atas 3 bulan wajib kontrak"), aturan itu bisa dipasang dan tahapnya ikut menahan.


## 8. Rincian satu dokumen

Tombol **+** di ujung kiri tiap baris membuka tiga panel sekaligus.

@@FIG:mn-rinci-kiri|Dua panel kiri — perjalanan dokumen dan kelengkapan berkas@@

### 8.1 Perjalanan dokumen

Sepuluh langkah dengan tanda ✓ atau ✗, lengkap dengan tanggal, jam, dan pelakunya: *Dokumen dibuat · Diajukan untuk persetujuan · Disetujui* (atau *Ditolak* beserta catatan penolakannya) *· Diteken klien · Transaksi terbit · Permintaan kontrak · Disetujui Legal · Nomor kontrak · Invoice diterbitkan · Pembayaran lunas*.

Inilah jawaban atas "kok lama sekali?" yang tidak perlu ditanyakan ke siapa pun — tanggalnya kelihatan, jadi jeda terpanjang langsung ketahuan ada di langkah mana.

### 8.2 Kelengkapan berkas

Tiga berkas administrasi penyewa (**KTP penanggung jawab, NPWP, SIUP**) dan — hanya bila permintaan kontraknya sudah ada — empat lampiran kontrak (**KTP, NPWP, Akta, Surat kuasa**). Di bawahnya nomor penawaran asalnya, nama CP, produk, dan daftar lokasi bila berkas itu paket.

### 8.3 Tindak lanjut

@@FIG:mn-rinci-kanan|Panel kanan — langkah berikutnya, tombol pintas, dan formulir pencatatan@@

Panel ketiga menyebutkan **langkah berikutnya**, **giliran siapa**, dan **sudah berapa hari**, lalu memberi tombol untuk langsung mengerjakannya:

| Tombol | Ke mana |
|---|---|
| **Buka dokumen** | Halaman SKP/SKS/FU-nya |
| **Kerjakan langkahnya** | Langsung ke tempat langkah berikutnya dikerjakan — halaman Tagihan, formulir permintaan kontrak, atau dokumennya |
| **Ajukan permintaan kontrak** | Hanya muncul bila dokumennya sudah diteken klien, permintaannya belum ada, dan Anda berhak membuatnya |


## 9. Mencatat tindak lanjut

Di bawah tombol-tombol itu ada formulir kecil. **Yang dicatat bukan status dokumennya** — dokumen tidak berubah sedikit pun — melainkan fakta bahwa seseorang sudah mengejarnya.

Tanpa catatan ini, daftar macet hanya jadi pengingat yang dilihat lalu ditinggalkan: tidak ada bukti siapa sudah berusaha dan siapa belum, dan Asisten Manager tetap harus bertanya satu-satu.

### 9.1 Empat jenis catatan

| Jenis | Kapan dipakai | Akibatnya pada daftar |
|---|---|---|
| **Sudah saya kejar** | Anda menghubungi klien / PIC / Legal | Kolom *Dikejar* bertambah; barisnya tetap di daftar |
| **Catatan saja** | Mencatat informasi tanpa mengaku mengejar | Tersimpan di jejak; barisnya tetap |
| **Tunda sampai…** | Klien minta ditagih bulan depan, dll. | Barisnya **keluar** dari daftar "Belum tuntas" sampai tanggal itu |
| **Kecualikan dari daftar** | Berkas yang memang tidak akan berubah di tahap ini | Barisnya **keluar** dari daftar "Belum tuntas" |

Kotak teksnya diisi **siapa yang dihubungi, lewat apa, dan apa jawabannya** — maksimal 500 karakter. Itu yang membuat jejaknya berguna; "sudah dikejar" tanpa keterangan tidak memberi tahu apa pun kepada orang berikutnya.

### 9.2 Yang perlu diperhatikan

> **"Tunda" wajib diisi tanggalnya.** Tanpa tanggal, catatannya ditolak dan muncul peringatan.

> **"Kecualikan dari daftar" hanya untuk Admin Sales dan pengelola dokumen.** PIC biasa tidak melihat pilihan itu — kalau bisa, daftar kejaran bisa dikosongkan sendiri oleh orang yang sedang dikejar.

> **Tidak ada yang hilang.** Berkas yang ditunda atau dikecualikan tetap bisa dibuka lewat saringan **Tampilkan → Ditunda / dikecualikan**, dan tetap diberi tanda di kolom Macet.

Pengecualian berlaku **per tahap**. Begitu berkasnya pindah tahap, ia kembali muncul di daftar kejaran — karena yang dulu Anda putuskan "tidak akan berubah" sudah berubah.

### 9.3 Jejaknya

Delapan catatan terakhir tampil langsung di panel, masing-masing dengan jenis, tanggal-jam, nama pencatatnya, dan isi catatannya. Setiap pencatatan juga masuk ke Audit Log. Berkas yang sudah **Tuntas** tidak lagi menampilkan formulirnya — tidak ada yang perlu dikejar.


## 10. Tiga cara pakai sehari-hari

### 10.1 PIC / Sales — "mana punya saya yang belum kelar?"

@@FIG:mn-sales-saring|Dibuka dengan akun Sales — kotak PIC terkunci ke namanya sendiri@@

Buka menunya; daftarnya **sudah** berisi berkas Anda saja. Tidak ada yang perlu disetel.

@@FIG:mn-sales-ubin|Ubin yang sama, tetapi angkanya sudah hanya berkas PIC tersebut@@

1. Lihat ubin **Tuntas** dan **Masih menggantung** — itu pencapaian Anda.
2. Centang **Hanya giliran saya** untuk menyisakan yang benar-benar bisa Anda kerjakan sendiri. Sisanya sedang di Approver, Legal, atau Admin, dan mengejar diri sendiri untuk itu hanya buang waktu.
3. Urutkan dari **Macet** terbesar, kerjakan dari atas.
4. Tekan **+** → **Kerjakan langkahnya**, lalu catat **Sudah saya kejar** beserta jawaban kliennya.

### 10.2 Admin Sales — "mana yang menunggu saya?"

Saringan **Giliran siapa → Admin Sales**, atau cukup centang **Hanya giliran saya**. Yang muncul hampir selalu dua tahap: *Invoice belum diterbitkan* dan *Tanpa tagihan*.

Tombol **Kerjakan langkahnya** membawa Anda langsung ke halaman Tagihan & Pembayaran dengan nama penyewanya sudah terisi di kotak cari — tidak perlu mencarinya lagi.

Untuk berkas yang tertahan karena kontraknya: saringan **Kontrak → Belum diajukan** memperlihatkan berkas mana yang permintaan kontraknya memang belum pernah dibuat, dan tombol **Ajukan permintaan kontrak** ada di rinciannya.

### 10.3 Asisten Manager — "siapa yang jalan, siapa yang diam?"

@@FIG:mn-asmen-papan|Papan pencapaian dibuka dengan akun Asisten Manager@@

1. Buka **Pencapaian per PIC**. Urutannya sudah dari persentase tuntas tertinggi.
2. Baca kolom **Giliran dia**, bukan **Menggantung** — itu yang benar-benar ada di tangan PIC tersebut.
3. Kolom **Dikejar** di tabel bawah memperlihatkan siapa yang sudah berusaha: berkas yang macet 40 hari dengan *Dikejar 0* beda masalahnya dengan yang *Dikejar 5×*.
4. Saringan **Macet minimal → 30 hari** menyisakan yang betul-betul perlu ditanyakan di rapat.


## 11. Pertanyaan yang sering muncul

**Kenapa berkas saya tidak muncul?**
Tiga kemungkinan, berurutan: (a) belum jadi dokumen — masih penawaran; (b) sudah **Tuntas**, sementara saringan **Tampilkan** masih di *Belum tuntas*; (c) sedang **ditunda** atau **dikecualikan**.

**Kenapa angka Income di sini beda dengan Dashboard?**
Pilih satu bulan dengan dasar *sewa berjalan di bulan itu*, lalu baca panel pencocokan (bagian 5.2). Selisihnya hampir selalu transaksi yang tidak punya dokumen SKP/SKS/FU.

**Saya sudah mengejar, kenapa barisnya masih di situ?**
Mencatat tindak lanjut tidak memindahkan tahap — memang bukan itu gunanya. Barisnya pindah sendiri begitu pekerjaan sebenarnya dikerjakan: dokumen diteken, invoice diisi, pembayaran dicatat.

**Kolom Macet saya 50 hari padahal baru kemarin saya urus.**
Macet menghitung sejak berkas **masuk ke tahap sekarang**, bukan sejak terakhir disentuh. Yang mencatat usaha Anda adalah kolom **Dikejar** dan jejak di panel rincian.

**Nilai di baris berbeda dengan nilai transaksinya.**
Dokumen paket menjumlah **seluruh** transaksi rombongannya. Periksa kolom periode sewa — bila tertulis "· 3 lokasi", nilainya memang untuk tiga lokasi.

**Lencananya masih menunjukkan angka lama.**
Lencana diingat 3 menit. Muat ulang halaman setelah itu, atau catat satu tindak lanjut — angkanya langsung disegarkan.
