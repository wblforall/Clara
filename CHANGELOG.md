# CLARA — Release Note

**Program:** CLARA (Casual Leasing Achievement & Revenue Analytics)
**Pengembang:** IT Dept. PT. Wulandari Bangun Laksana Tbk.

| Peran | Nama |
|-------|------|
| Head Developer | Ahmad Affan Ridha |
| Developer | Mochamad Sa'adillah Effendi |
| Implementor | Riky Akbar |

---

## Version 4.19 — 13 Juni 2026

### Isi Surat Penawaran lebih rapi & jelas

- **Tabel Rincian Biaya** — biaya kini disajikan dalam tabel: sewa/bulan, masa sewa, subtotal, **PPN 12%**, total setelah PPN, security deposit, dan **Grand Total** — tidak lagi berserak di paragraf.
- **Durasi nyata** — "Masa sewa" menampilkan durasi sebenarnya (mis. *7 hari*, atau *6 bulan · 184 hari*), tidak lagi memaksa "1 bulan" untuk sewa harian.
- **Masa berlaku penawaran** — ditambah label *"Penawaran berlaku s/d <tanggal>"* (14 hari sejak tanggal penawaran).
- **Kontak rapi** — bila email/WA PIC kosong, otomatis pakai kontak kantor; tidak ada lagi tanda "‑" kosong di bagian pembayaran/penutup.
- Bagian tanda tangan dijaga tidak terpotong antar-halaman.

## Version 4.18 — 13 Juni 2026

### Tampilan — Kop Surat (Letterhead) di semua surat

- **Background kop surat A4** — Surat Penawaran dan SKP/SKS kini memakai **kop surat resmi e-Walk × Pentacity** (header logo + footer alamat) sebagai latar A4 penuh. Logo & garis lama dihapus karena sudah menyatu di kop; isi surat ditempatkan rapi di area tengah kosong. Tampil sama di layar maupun saat dicetak/PDF.

## Version 4.17 — 13 Juni 2026

### Fitur Baru — Tanda Tangan QR & Validasi Dokumen

- **Tanda Tangan Saya** (menu *Akun*) — sales & manager mengunggah gambar tanda tangan (PNG/JPG, maks 2 MB) sekali di akun masing-masing.
- **QR di PDF, bukan gambar TTD** — pada PDF SKP/SKS, blok **"Dibuat Oleh" (sales)** dan **"Mengetahui" (manager)** kini tampil sebagai **QR code**, sehingga tanda tangan tidak mudah dijiplak dari dokumen.
- **Validasi via scan** — memindai QR membuka halaman publik yang menampilkan **"✅ DOKUMEN SAH"** beserta nomor dokumen, penyewa, nilai, siapa membuat & menyetujui + waktunya, status TTD customer, dan penanda **"TTD terdaftar ✓"**. Token tak dikenal → **"✕ DOKUMEN TIDAK VALID"**. TTD customer tetap berupa tanda tangan tangan (kanvas) seperti sebelumnya.

## Version 4.16 — 13 Juni 2026

### Penyempurnaan — Surat Penawaran setara Input Transaksi

Form Surat Penawaran kini memakai **mesin perhitungan & kontrol recurring yang sama** dengan form Input Transaksi, agar nilai penawaran konsisten dengan transaksi yang terbit nanti.

- **Mesin pricing** — total dihitung otomatis dari **Pricing Type** (`daily_area` = rate × luas × hari, `daily_slot` = rate × slot × hari, `daily_point` = rate × hari, `monthly`/`fixed` = rate), dengan tombol **Kalkulasi Total** + ringkasan hari/bulan. **Harga / Bulan** kini turunan otomatis (total ÷ masa kontrak), tidak diinput manual.
- **Harga Nego Final (override)** — bila ada diskon/nego, isi nominal final yang menimpa hasil kalkulasi (format ribuan otomatis).
- **Pengakuan & Recurring** — sales menentukan **Metode Pengakuan** (Sekaligus/anchor atau **Spread per Bulan**), **Pengakuan per Siklus**, dan menandai **Diakui Recurring** langsung di penawaran. Nilai ini **diteruskan ke transaksi** saat konfirmasi disetujui — sebelumnya recurring selalu ditebak sistem & flag recurring tidak pernah ikut tertandai.
- **Jumlah Slot** (khusus Media) muncul otomatis untuk media berbasis slot (TVC/LED).
- **Referral** — field referral (komisi 1%) ikut di penawaran dan diteruskan ke transaksi.
- **Cek overlap unit** — peringatan otomatis bila unit sudah punya transaksi yang bertabrakan tanggal, sejak tahap penawaran.
- **Preview spread per bulan** — saat metode Spread, klik *Kalkulasi Total* menampilkan rincian nilai **tiap bulan** (mis. Ags 2026 … Jan 2027) sebelum disimpan. Field harga nego diberi keterangan **(override)** agar jelas fungsinya menimpa hasil kalkulasi.
- **Pilih PIC mana yang tampil di penawaran** — di **Master PIC** ada opsi *Tampil di pilihan Penawaran*. Hanya PIC yang di-set "Ya" yang muncul di dropdown PIC saat membuat penawaran (awalnya otomatis: hanya kategori Sales, sisanya disembunyikan). PIC penawaran lama & PIC yang tertaut akun login tetap bisa dipilih.
- **Penawaran Media & Gudang** — tombol *Buat Penawaran* kini punya pilihan modul **Exhibition (SKP) · Media (SKS) · Gudang (SKS)**. Media menampilkan **Jumlah Slot** (TVC/LED) dengan pricing per-slot; Gudang memakai pricing **bulanan** sehingga total = harga/bulan × jumlah bulan. Saat deal, Media/Gudang otomatis membuat **Surat Konfirmasi Sewa (SKS)**.
- **DP & Deposit** — DP minimal **1 bulan** (bukan 2). Nominal DP & deposit kini **dihitung otomatis dari harga/bulan** (yang sudah mengikuti override bila diisi) × jumlah bulan, dan **tetap bisa di-override manual** — bila diisi sendiri tidak akan ditimpa, bila dikosongkan kembali mengikuti hitungan otomatis.

## Version 4.15 — 13 Juni 2026

### Fitur Baru — Tata Kelola Penawaran & Analisa Aktivitas PIC

- **Penawaran wajib di-*close* dengan alasan** — penawaran yang tidak jadi deal **tidak boleh digantung**. Tombol "Batalkan" diganti **"Tutup (Tidak Deal)"** yang mewajibkan **kategori alasan** (harga, pilih kompetitor, budget client batal, tidak respon, jadwal, lokasi, tidak valid/internal, lainnya) **+ catatan kronologi**. Alasan tampil di daftar & detail penawaran sehingga bisa dianalisa.
- **Laporan baru: Aktivitas & Pipeline PIC** (menu *Analisa*) — menghitung **semua** penawaran yang dibuat tiap PIC (deal **maupun** tidak deal), bukan hanya yang berhasil. Leaderboard per PIC: jumlah penawaran, berapa yang **benar-benar dikirim** ke client (indikator kerja nyata), nego, deal, tidak deal, **konversi %**, nilai deal & nilai pipeline, rata-rata revisi, dan skor risiko. Sehingga terlihat PIC mana yang aktif sungguhan.
- **Deteksi penawaran murni vs fiktif** — tiap penawaran dinilai **skor risiko fiktif (0–100)** dari sinyal aktivitas: tidak pernah ditandai terkirim ke client, ditutup instan (< 1 jam) tanpa revisi, ditandai tidak valid/internal, tanpa contact person, client tanpa nomor telepon, dan **duplikat** (client+unit+nilai sama). Penawaran berisiko (skor ≥ 25) muncul di daftar **"Perlu Ditinjau"** lengkap dengan sinyal-nya untuk konfirmasi manual — bukan tuduhan, tapi alat bantu pengawasan.
- **Funnel & analisa nego** (di laporan yang sama) — corong pipeline **Dibuat → Dikirim → Nego → Deal** dengan % lanjut tiap tahap, **breakdown alasan tidak deal** (kategori mana paling sering), dan tabel **effort nego vs konversi** (apakah penawaran yang lebih banyak direvisi lebih sering deal).
- **Pakai ulang lampiran** — saat membuat SKP/SKS untuk client yang **sudah pernah** punya dokumen, scan **KTP/NPWP** lama otomatis ditawarkan untuk dipakai ulang (centang) sehingga tidak perlu unggah scan yang sama berkali-kali. Hapus centang bila ingin unggah baru.

### Peningkatan UX — Surat Penawaran

- **Dropdown Client & Unit pakai pencarian ketik** (sama seperti form input transaksi) — tinggal ketik nama client/unit lalu pilih dari daftar, tidak perlu scroll panjang.
- **Tab di daftar penawaran**: **On Going** (draft/terkirim/nego — masih proses & menunggu konfirmasi client), **Deal**, dan **Tidak Deal**. Penawaran yang ditutup otomatis pindah ke tab "Tidak Deal" sehingga tab "On Going" hanya berisi yang benar-benar masih berjalan. Tiap tab menampilkan jumlahnya.
- **Masa kontrak otomatis** — field "Masa Kontrak (bulan)" tidak perlu diisi manual lagi; **dihitung otomatis dari periode tanggal mulai–selesai** dan langsung dipakai untuk menghitung total kontrak.

## Version 4.14 — 13 Juni 2026

### Fitur Baru — Pipeline Penawaran (alur baru input transaksi)

Titik masuk transaksi baru kini berbasis **Surat Penawaran**, bukan input transaksi manual. Alur lengkap: **Surat Penawaran → Dokumen Konfirmasi (SKP/SKS) → Transaksi terbit otomatis**.

- **Surat Penawaran** (menu baru, grup *Input*) — buat penawaran ke calon penyewa, **revisi berkali-kali** (tiap revisi tersimpan untuk catatan nego), cetak PDF, lalu tandai **DEAL** saat disepakati. Penomoran otomatis (mis. `001/QT-CL/e-Walk/BSB-BPN/VI/2026`). DP minimal 2 bulan, deposit dapat disesuaikan, total/DP/deposit terhitung otomatis.
- **Dokumen Konfirmasi dari penawaran** — dari penawaran DEAL, tekan **Buat SKP/SKS**. Nilai mengikuti penawaran final. Jenis dokumen otomatis: **SKP** (Pameran/Casual Leasing) atau **SKS** (Media/Gudang).
- **Lampiran dokumen** — unggah **KTP, NPWP, bukti transfer, dan dokumen pengajuan** (gambar/PDF, maks 5 MB) langsung pada konfirmasi. **Surat penawaran final otomatis menjadi rujukan lampiran**. KTP & NPWP otomatis tersimpan ke **Master Client** sehingga otomatis terisi pada dokumen berikutnya.
- **Lampiran tampil di PDF & halaman tanda tangan** — daftar lampiran (penawaran final + dokumen yang diunggah) kini muncul di PDF konfirmasi dan di halaman TTD customer, agar penyewa tahu persis dokumen apa saja yang menyertai.
- **Transaksi terbit saat manajer menyetujui** — saat konfirmasi di-approve, **transaksi + alokasi bulanan terbit otomatis** dan langsung masuk Dashboard / Achievement / laporan recurring. Inilah satu-satunya titik deal masuk analitik.
- **Tanda tangan customer online** — penyewa menandatangani konfirmasi lewat tautan (judul & teks persetujuan menyesuaikan SKP/SKS), hasil tertanam di PDF final.
- **Offer-first** — input transaksi manual untuk transaksi baru dinonaktifkan (diarahkan ke Surat Penawaran). **Perpanjangan** tetap bisa lewat jalur lama.

## Version 4.13 — 9 Juni 2026

### Fitur Baru / Peningkatan

- **Profil Client** (halaman baru) — klik nama perusahaan di **Master Client** untuk membuka profil per-client. Berisi:
  - **Header:** status hubungan (Aktif / Lapsed X bulan), chip industri/skala/segmen/kota, kontak & account manager, "client sejak".
  - **6 KPI** (basis `start_date`): jumlah kontrak tahun ini + total sepanjang waktu, rata-rata durasi (all-time & tahun ini), rata-rata nilai, lifetime value, terakhir aktif s/d, dan pola (rasio recurring + jeda rata-rata antar kontrak). Durasi dihitung dari `start_date`→`end_date` (kolom `contract_months` tidak terisi di data).
  - **Pola waktu:** breakdown produk per modul + PIC penangan, nilai per tahun, **heatmap bulan favorit berkontrak** (12 bulan, seluruh riwayat), dan **riwayat transaksi** dengan badge status (Aktif / Berakhir <30hr / Selesai) + link ke Detail Alokasi.
  - **Recurring konsisten:** rasio & badge "R" memakai definisi recurring kanonik (`recurring_match_sql`) yang sama dengan dashboard/exec/laporan — termasuk `anchor_cycle` berulang (master_code+klien sama, bulan bersebelahan), bukan hanya `spread`/`recurring_flag`.
  - **Scope per properti aktif** (agar konsisten dengan seluruh aplikasi & link Detail Alokasi tetap berfungsi); muncul banner bila client juga punya transaksi di properti lain yang boleh diakses. Read-only (`view_master`).

- **PWA — klik transaksi membuka preview dulu:** di tampilan mobile, mengetuk kartu transaksi kini membuka **Detail Alokasi** (preview transaksi + breakdown bulanan), bukan langsung form edit. Tombol **Edit Transaksi** tersedia di halaman itu untuk yang ingin mengubah.

## Version 4.12 — 8 Juni 2026

### Performa / Hosting

- **Pengurangan beban proses TV Display** (penyebab proses PHP menumpuk di shared hosting):
  - Interval auto-refresh diperlambat **30 dtk → 3 menit**; retry saat gagal **10 dtk → 1 menit**.
  - **Cache server-side** untuk `display_data` (TTL 120 dtk) — banyak panel/TV berbagi 1 hasil, query berat tidak dijalankan ulang tiap polling.
  - Audit log **dihapus dari tiap refresh** (dulu menulis 1 baris ke DB tiap polling → beban + bloat log).

### Fitur Baru / Peningkatan

- **PWA (Progressive Web App)** — CLARA kini bisa **di-install ke home screen HP** (Android/iOS) dan dijalankan layaknya aplikasi (fullscreen, tanpa address bar):
  - `manifest.webmanifest` + ikon 192/512/maskable, warna tema teal CLARA.
  - Service worker dengan strategi aman untuk aplikasi multi-user: **HTML hasil render tidak pernah di-cache** (tidak ada data basi / bocor antar user); hanya aset statis (CSS/JS/ikon) yang di-cache (cache-first).
  - Halaman **offline** ber-branding saat tidak ada koneksi, dengan tombol "Coba lagi".
  - Service worker & manifest di-set `no-cache` agar pembaruan PWA langsung diterima browser (tidak ikut aturan cache 1 tahun aset statis).
  - Tidak mengubah logika apa pun — murni lapisan instalasi/offline di atas aplikasi yang sudah responsive.

- **Tampilan khusus mobile (app-like)** — saat dibuka dari HP, CLARA otomatis tampil sebagai aplikasi: **top bar** ringkas + **bottom navigation** (Beranda · Transaksi · Renewal), tanpa sidebar. Bisa dipaksa lewat `?view=mobile` / `?view=desktop` (tersimpan di cookie); ada link "📱 Tampilan HP" di sidebar desktop saat diakses dari HP.
  - **Beranda menyesuaikan peran:**
    - *PIC sales* → beranda personal: achievement & target pribadi (ring %), peringkat di antara PIC, kontribusi per segmen, transaksi terbaru sendiri.
    - *Akses multi-properti* → ringkasan agregat + rincian pencapaian per properti.
    - *Properti tunggal* → ringkasan properti (segmen, Top PIC, transaksi terbaru).
  - **Transaksi** → daftar kartu ringkas (kode/client/nilai/tanggal/PIC) + tab modul + pencarian + tombol tambah (FAB).
  - **Executive Summary versi mobile** (tab "Eksekutif") — hanya muncul untuk user dengan hak `view_exec_summary`: pencapaian gabungan, KPI (proyeksi, recurring, klien baru, gap), per segmen, rincian per properti, dan **occupancy per properti** (Exhibition per lantai, Media per jenis, Gudang per lokasi). Memakai ulang query exec dashboard desktop.
  - **Achievement PIC di beranda** untuk user non-sales: persentase pencapaian vs target individu, **dikelompokkan per properti** (untuk akses multi-properti).
  - Form transaksi & Renewal ikut tampil di dalam shell mobile (bottom-nav konsisten). Halaman lain (master data, audit, dll) tetap memakai layout desktop. Bottom-nav menyesuaikan hak akses tiap user.
  - **Header kontekstual:** halaman agregat (Beranda multi-properti & Eksekutif) menampilkan "Semua Properti"; halaman per-properti (Transaksi/form) menampilkan **pemilih properti** (dropdown) bagi user multi-properti untuk ganti properti aktif langsung dari HP.
  - **Konsistensi dengan desktop:** warna Recurring biru (`#0369a1`/latar biru muda) sama seperti dashboard/exec; urutan occupancy lantai Exhibition & lokasi Gudang memakai map lantai yang sama (LG→GF→UG→FF→SF, lalu lainnya).
  - Bukan aplikasi/native terpisah — semua tetap 1 codebase PHP. Layout desktop tidak berubah sama sekali.

- **Perbaikan bug perhitungan achievement PIC:** target individu PIC dihitung `target_properti × target_share` (target_share tersimpan sebagai pecahan, mis. 0,25 = 25%). Sebelumnya welcome-modal membagi lagi dengan 100 sehingga target 100× lebih kecil & persentase pencapaian 100× lebih besar. Kini konsisten dengan halaman Performa PIC / Executive Summary / Rewarding.

- **Perbaikan tampilan mobile menyeluruh:**
  - **Tabel data → kartu bertumpuk** di layar HP (≤768px): tiap baris jadi kartu dengan label kolom di kiri & nilai di kanan, otomatis untuk semua halaman (judul kolom diambil dari header tabel). Baris grup/subtotal & total tetap tampil sebagai banner full-width. Tabel yang belum bisa dipetakan tetap aman di-scroll samping.
  - Cegah scroll horizontal halaman yang tidak diinginkan; gambar/chart dibatasi agar tidak meluber.
  - Target sentuh tombol diperbesar; field input pakai font ≥16px agar tidak auto-zoom di iOS.
  - Toolbar/filter & tab properti dirapikan untuk layar sempit.

---

## Version 4.11 — 8 Juni 2026

### Fitur Baru / Peningkatan

- **Renewal Kontrak** (`Analisa → Renewal Kontrak`) — papan baru untuk mencegah revenue bocor karena kontrak habis tanpa tindak lanjut:
  - Menampilkan kontrak sewa & recurring yang berakhir dalam 30 hari ke depan (termasuk yang sudah lewat dan belum ditindaklanjuti).
  - Cakupan: recurring (`billing_method=spread`) + modul Exhibition (CL) & Gudang. Booking media pendek diabaikan. Ada filter modul.
  - Dua tingkat urgensi: 🔴 kritis (≤15 hari) · 🟠 perlu tindak lanjut (16–30 hari).
  - Kartu ringkasan: total nilai at-risk per bulan, jumlah kontrak per tingkat urgensi.
  - Status renewal dikelola manual oleh PIC: Belum dihubungi → Sudah dihubungi → Sedang nego → Akan perpanjang → Diperpanjang → Tidak lanjut. Kontrak yang ditandai "Diperpanjang" / "Tidak lanjut" otomatis hilang dari papan.
  - **Visibilitas per-sales:** role `sales` hanya melihat kontrak milik PIC yang terhubung ke akunnya (`master_pic.user_id`); supervisor/finance/administrasi/admin/superadmin melihat semua. Sales juga tidak bisa mengubah status kontrak PIC lain (proteksi IDOR di handler).
  - Tombol **+ Perpanjang** membuka form transaksi baru yang **sudah terisi** dari kontrak lama (unit, client, contact, PIC, rate, pricing, luas, materi) — tanggal mulai otomatis disarankan sehari setelah kontrak lama berakhir; tinggal sesuaikan tanggal selesai lalu simpan.
  - Desain **mobile-first** (kartu, bukan tabel lebar) — dapat digunakan sales langsung dari HP di lapangan.
  - Tidak mengubah logika transaksi/alokasi/komisi — hanya membaca `end_date` yang sudah ada + menambah kolom status renewal.
  - Permission baru: `view_renewals` (lihat) & `manage_renewals` (ubah status) — perlu di-set di Role & Permission setelah deploy.

- **Rewarding PIC — penyesuaian besaran tier:** Tier I Rp 250.000 · Tier II Rp 500.000 · Tier III Rp 750.000 · Tier IV Rp 1.000.000 (sebelumnya 500rb/750rb/1jt/1,5jt).

### Database

- Migration `011_add_renewal_tracking` — menambah kolom `renewal_status`, `renewal_note`, `renewal_updated_at`, `renewal_updated_by` ke tabel `transactions`.

---

## Version 4.10 — 7 Juni 2026

### Fitur Baru / Peningkatan

- **Performa PIC** (`Analisa → Performa PIC`) — halaman baru untuk melihat performa historis per individu PIC:
  - Filter PIC + rentang dari–sampai bulan (picker YYYY-MM).
  - Kartu ringkasan: total dealing, rata-rata per bulan, bulan terbaik, streak bulan tercapai berturut-turut.
  - Tabel historis per bulan: dealing, target individu (`target_share × property target`), % pencapaian ✅/❌, vs bulan lalu (↑/↓), TRX count, rata-rata per TRX.
  - Semua bulan dalam rentang selalu tampil — bulan tanpa transaksi ditampilkan sebagai Rp 0 (tidak hilang dari tabel).

- **Master Referrer — diperluas:**
  - Tambah field: Jabatan, No. Rekening, Nama Bank (dropdown: BCA, BNI, BRI, Mandiri, CIMB Niaga, BSI, BTN, Maybank, Bankaltimtara, Mega).
  - Dipindah dari section Admin ke **Master Data** dengan permission `view_master` — sales dapat input referrer sendiri tanpa perlu akses Admin.
  - Print Komisi Tabel B kini menampilkan jabatan, departemen, no. rekening, dan nama bank referrer.

- **Konversi Recurring — redesign besar:**
  - Nominal per bulan dari transaksi `anchor_cycle` lama kini dipertahankan **persis** (tidak dihitung ulang rata-rata) saat dikonversi ke spread — penting untuk kontrak dengan diskon berbeda tiap bulan.
  - Tombol **Kalkulasi & Preview Spread** menampilkan tabel preview per siklus beserta nominal masing-masing sebelum submit.
  - Jika rentang tanggal diperluas (siklus bertambah dari jumlah transaksi lama), submit **diblokir** sampai user mengisi nominal siklus tambahan secara manual — mencegah angka 0 masuk ke data.
  - Filter properti di halaman list kini mengikuti `current_property_id()` dari session; tab properti di header sekarang ikut memfilter data.
  - Urutan list: Property → Nama PIC (A–Z) → Lantai (LG→GF→UG→FF→SF) → Nomor Unit (numerik).

### Perbaikan

- **Laporan PIC** — kolom TRX terpotong di kanan pada hosting karena wrapper `.panel` (padding 18px) mempersempit lebar efektif; wrapper dihapus dan `overflow-x:auto` diaplikasikan langsung di `.table-wrap`.

---

## Version 4.9 — 7 Juni 2026

### Fitur Baru

- **Sistem Referrer** — karyawan non-CL yang mereferensikan klien mendapat komisi 1% per dealing:
  - **Master Referrer** (`Admin → Master Referrer`) — CRUD untuk mengelola daftar referrer (nama, departemen, status aktif/nonaktif).
  - **Dropdown referrer** di form input & edit transaksi — opsional, bebas dikosongkan.
  - Migration `009`: tabel `master_referrer` + kolom `referrer_name` di tabel `transactions`.

- **Simulasi Komisi — update signifikan:**
  - Referrer masuk ke perhitungan: komisi referrer = 1% × total dealing yang ia referensikan di periode itu.
  - Komisi 1% referrer di-deduct langsung dari komisi sales PIC yang memegang deal tersebut.
  - Jika rate tidak tercapai (0,65%) dan semua dealing PIC ada referrer, hasil komisi PIC bisa negatif — ini disengaja (PIC bertanggung jawab perform tanpa bergantung referrer). Ditampilkan merah dengan label "potongan melebihi komisi".
  - Kolom **Potongan Referrer** di tabel PIC menampilkan nominal deduction + nama referrer yang menyebabkan potongan.
  - **Tabel Komisi Referrer** baru — detail per referrer: membantu sales siapa, dealing direferensikan berapa, komisi 1%.
  - Urutan tabel PIC: Manager → Asst. Manager → Sales Executive → Sales → Admin → Other.

- **Form Pengajuan Komisi** (print) — tombol "Cetak / Ajukan" membuka dokumen siap cetak (`?r=commission_sim&action=print&period=...`):
  - Letterhead PT. Wulandari Bangun Laksana Tbk., nomor dokumen otomatis, tanggal cetak.
  - Info periode: target, revenue, % pencapaian, rate berlaku.
  - Tabel A: Komisi PIC (lengkap dengan potongan referrer).
  - Tabel B: Komisi Referrer (muncul hanya jika ada).
  - Grand total gabungan PIC + Referrer.
  - Blok tanda tangan: Dibuat oleh / Diperiksa oleh / Disetujui oleh.

### Perbaikan

- **`db_check.php`** diperbarui dengan skema terkini: kolom `commission_cat`, `show_achievement` (master_pic), `billing_method`, `cycle_recognition`, `referrer_name` (transactions), serta tabel `settings` dan `master_referrer` — sehingga schema drift ke hosting dapat terdeteksi lebih awal.

---

## Version 4.8 — 4 Juni 2026

### Fitur Baru

- **Special price per bulan untuk transaksi recurring** — kontrak spread kini mendukung override amount di bulan tertentu (misal promo, negosiasi khusus):
  - Tersedia di **form input baru**, **form edit**, dan **halaman Detail Alokasi** (tanpa perlu buka form edit).
  - Bulan yang di-override ditandai badge **KHUSUS** (biru) + tombol **Reset** untuk mengembalikan ke distribusi rata.
  - Saat base amount diubah dan Kalkulasi dijalankan ulang, bulan KHUSUS tetap terkunci — hanya bulan normal yang ikut update.
  - `final_amount` transaksi otomatis diperbarui ke total aktual (jumlah semua bulan setelah override).
  - **Perpanjang periode recurring**: buka Edit → ganti `end_date` → Kalkulasi → bulan lama tetap terkunci, bulan baru dapat base amount secara otomatis.
  - Form edit recurring langsung menampilkan spread table dengan semua bulan tersimpan sudah ditandai KHUSUS saat halaman dibuka.

---

## Version 4.7 — 3 Juni 2026

### Fitur Baru

- **Simulasi Komisi PIC** (`Analisa → Simulasi Komisi`) — halaman preview komisi bulanan per PIC berdasarkan struktur komisi yang disepakati:
  - Sales: komisi = rate × dealing sendiri per PIC (individual).
  - Non-Sales (Manager/Asst. Manager/Admin/Other): komisi = rate × total revenue properti.
  - Rate berbeda antara periode tercapai (3,7% total) dan tidak tercapai (1,36% total).
  - Tabel breakdown per PIC: basis komisi, rate berlaku, dan nominal komisi.
- **Drag-drop reorder Master** — Master Exhibition, Media, dan Gudang kini mendukung pengurutan baris dengan drag handle (⠿). Urutan tersimpan otomatis via AJAX tanpa reload halaman.
- **Auto-generate kode** di form tambah Master — tombol ⚡ Generate mengisi kode otomatis (`LG-NNN` / `GF-NNN` berdasarkan lantai untuk Exhibition, `Guda-NNN` untuk Gudang, `Medi-NNN` untuk Media).

### Perbaikan

- **Filter transaksi — recurring tidak tampil**: logika filter tanggal diubah dari *contains* (`start >= from AND end <= to`) menjadi *overlap* (`start <= to AND end >= from`), sehingga transaksi recurring dengan durasi panjang ikut tampil saat filter bulan tertentu.
- **Dropdown unit di form transaksi**: ganti `<select>` menjadi searchable picker bergaya client picker — ketik nama unit, hasil filter muncul real-time dengan nama (bold) dan kode (abu-abu kecil).
- **Dropdown kode master**: kode unit tidak lagi ditampilkan di label opsi, hanya nama/lokasi.
- **countUp dashboard — Aktual PIC tampil "Rp 0"**: animasi `requestAnimationFrame` diganti dengan direct set sehingga nilai selalu tampil di semua kondisi navigasi antar periode.

### Perubahan Master PIC

- Dua field baru di Master PIC:
  - **Kategori Komisi**: dropdown (Tidak Dapat Komisi / Sales / Manager / Asst. Manager / Admin / Other) — menentukan apakah PIC masuk perhitungan komisi dan di kategori mana.
  - **Tampil di Achievement PIC**: toggle Ya/Tidak — kontrol eksplisit apakah PIC ditampilkan di tabel Achievement di Dashboard, Exec Summary, dan Laporan PIC.
- PIC dengan `target_share = 0` otomatis dikecualikan dari semua tabel Achievement.
- Migration `008`: kolom `commission_cat` dan `show_achievement` di tabel `master_pic`.
- Migration `007`: kolom `sort_order` di tabel `master_cl_units`, `master_gudang`, `master_media`.

---

## Version 4.6 — 2 Juni 2026

### Fitur Baru

- **Recurring di Trend Revenue** — halaman Trend Revenue kini menampilkan pemisahan Regular dan Recurring:
  - 5 KPI card: Total Aktual, Regular, Recurring, Target, Achievement.
  - Chart Aktual vs Target: bar dipecah jadi Regular (hijau) + Recurring (biru), stacked.
  - Tabel bulanan: kolom Regular dan Recurring ditambahkan.
- **Brand name di Konversi Recurring** — nama brand ditampilkan di bawah nama perusahaan pada daftar kandidat dan form review.

---

## Version 4.5 — 1 Juni 2026

### Fitur Baru

- **Pengakuan per Siklus (Cycle Recognition)** — untuk transaksi recurring/spread dengan `pricing_type=monthly` yang tanggal kontraknya lintas bulan kalender (misal 9 Jan – 8 Feb), user kini bisa memilih revenue tiap siklus diakui di **Bulan Awal** atau **Bulan Akhir** siklus tersebut, alih-alih dipecah proporsional per hari.
  - Tersedia di form **Tambah Transaksi** dan **Edit Transaksi** — muncul otomatis saat "Spread per Bulan (Recurring)" dipilih.
  - Tersedia di halaman **Konversi Recurring** — pilihan sama di form review per grup.
  - Kolom baru `cycle_recognition` di tabel `transactions` (migration `006`).
  - `AllocationService` diperbarui: jika `billing_method=spread`, `pricing_type=monthly`, dan `cycle_recognition` di-set, alokasi dibuat per siklus bulanan (1 siklus = 1 baris alokasi di 1 period_key) tanpa split kalender.

### Perbaikan

- **Konversi Recurring — filter properti**: tambah dropdown filter properti di halaman daftar kandidat.
- **Konversi Recurring — urutan**: daftar diurutkan per properti → CL → Media → Gudang → high confidence → bulan terbanyak.
- **Konversi Recurring — lompat bulan**: grup dengan bulan tidak berurutan (ada gap) otomatis dikeluarkan dari daftar kandidat menggunakan `PERIOD_DIFF`.
- **Konversi Recurring — end_date pre-fill**: menggunakan tanggal asli kontrak, bukan akhir bulan period_key.

---

## Version 4.4 — 1 Juni 2026

### Fitur Baru

- **Konversi Recurring** (`Admin → Konversi Recurring`) — halaman deteksi dan konversi transaksi yang diinput manual per bulan menjadi satu transaksi recurring (spread).
  - **Deteksi otomatis** 121 grup kandidat (467 transaksi) berdasarkan pola `master_code + client` yang muncul di 2+ bulan dengan `billing_method = anchor_cycle`.
  - **Confidence level**: High (amount identik, 90 grup) dan Medium (amount bervariasi, 31 grup).
  - **Filter** per modul dan confidence level.
  - **Halaman review per grup**: tampilkan daftar transaksi lama yang akan di-soft-delete, form editable start_date/end_date (bisa diperpanjang), nilai/bulan, override total, dan preview breakdown spread per bulan sebelum konfirmasi.
  - **Eksekusi merge**: soft-delete transaksi lama + alokasi, buat 1 transaksi baru `billing_method=spread`, hitung ulang alokasi via `AllocationService`, catat ke audit log. Hanya bisa diakses `superadmin`.

---

## Version 4.3 — 1 Juni 2026

### Fitur Baru

- **Occupancy Harian** (`Analisa → Occupancy Harian`) — lihat snapshot occupancy pada tanggal tertentu untuk segmen Exhibition dan Media.
  - **Summary card** per segmen: jumlah unit terisi, total unit, dan persentase occupancy dengan progress bar.
  - **Exhibition — Per Lantai**: breakdown occupancy per lantai (LG/GF/UG/FF/SF) lengkap dengan jumlah unit occupied, kosong, dan %. Warna % mengikuti threshold: hijau ≥80%, kuning ≥50%, merah <50%.
  - **Media — Per Tipe**: breakdown occupancy per tipe media.
  - **Detail unit terisi**: di bawah setiap lantai/tipe, tabel menampilkan kode unit, lokasi, client, durasi kontrak, PIC, dan nilai — dengan link ke Detail Alokasi.
  - **Date picker**: pilih tanggal bebas; default hari ini. Tombol "Hari Ini" muncul bila tanggal berbeda.
  - **Edge case**: tanggal tanpa transaksi menampilkan pesan kosong yang tepat per lantai/tipe.

---

## Version 4.2 — 26 Mei 2026

### Fitur Baru

- **Cek Overlap Transaksi** — halaman baru di `Input Transaksi` (tombol ⚠ Cek Overlap di toolbar) yang menampilkan semua pasang transaksi dengan tanggal tumpang tindih pada unit yang sama. Dilengkapi filter per bulan dan per modul. Link langsung ke Detail Alokasi masing-masing transaksi.

### Perbaikan Bug

- **Executive Summary — avg rate per lantai/jenis/lokasi tidak akurat**: Query SQL untuk tabel occupancy (CL, Media, Gudang) kini menggunakan subquery yang mengagregasi `SUM(amount)` dan `SUM(days)` per unit (`master_code`) terlebih dahulu sebelum menghitung `AVG(rate)`. Sebelumnya `AVG()` dihitung langsung atas baris alokasi sehingga unit dengan lebih dari satu alokasi dalam sebulan menimbang rata-rata lebih banyak dari seharusnya.
- **Dashboard — rate rata-rata tidak termasuk unit tanpa revenue**: Filter `actual > 0` ditambahkan pada kalkulasi avg rate per unit di segmen CL, Media, dan Gudang agar unit dengan hari alokasi tapi nilai Rp 0 (anchor cycle) tidak masuk perhitungan rata-rata harian.
- **Cek Overlap — transaksi terhapus ikut muncul**: Kondisi `deleted_at IS NULL` dipindah ke `WHERE` clause (eksplisit untuk kedua sisi join) sehingga transaksi yang sudah dihapus (soft delete) tidak lagi muncul di laporan overlap.

---

## Version 4.1 — 24 Mei 2026

### Fitur Baru — Rewarding PIC

- **Halaman Rewarding PIC** (`Analisa → Rewarding PIC`) — tracking streak dan bonus achievement bulanan per PIC.
- **Skema Tier Bonus**: streak 3–5 bulan → Rp 500.000/bln | 6–8 bulan → Rp 750.000/bln | 9–11 bulan → Rp 1.000.000/bln | 12+ bulan → Rp 1.500.000/bln.
- **Streak Reset Otomatis** — jika satu bulan tidak achieve target posisi (100%), streak kembali ke 0 dan bonus berhenti.
- **Riwayat Bulanan per PIC** — klik baris PIC untuk expand timeline bulan per bulan: achieve/tidak, streak, tier, bonus.
- **KPI Strip** — ringkasan tracking sejak kapan, total bulan berjalan, bonus bulan ini, dan total akumulasi sejak awal.
- **Set Periode Mulai** — admin dapat menentukan dari bulan berapa tracking dimulai (tersimpan di tabel `settings`). Global untuk semua properti.
- **Badge Streak di Laporan PIC** — nama PIC di tabel Laporan PIC menampilkan badge 🔥 NNbln jika streak ≥ 3.
- **Tombol Rewarding PIC** — di toolbar Laporan PIC tersedia shortcut ke halaman Rewarding.
- Hak akses mengikuti `view_pic_report` (sama dengan Laporan PIC).
- Migration `005_create_settings.php` — tabel `settings` (key-value) untuk konfigurasi aplikasi.

---

## Version 4.0 — 24 Mei 2026

### Recurring — Integrasi Menyeluruh ke Seluruh Modul

- **Dashboard**: KPI card Recurring ditambahkan sebagai kartu kelima di samping Potensi, Target, Aktual, dan % Achievement vs Target.
- **Executive Summary — KPI Strip gabungan**: Card "Achievement vs Potensi" dihapus, diganti card Recurring. Strip kini menampilkan Potensi, Target, Regular, Recurring, dan Aktual.
- **Executive Summary — Tabel Occupancy**: Progress bar dihapus, diganti tiga kolom Regular / Recurring / Total di setiap segmen (Exhibition, Media, Gudang).
- **Executive Summary — Card per Properti**: Layout KPI dipecah dua baris — Potensi/Target di atas; Regular/Recurring/Aktual+% di bawah.
- **Executive Summary — Tabel PIC**: Kolom TRX ditambahkan (format `recurring/total`, angka recurring ditampilkan biru).
- **Laporan PIC**: Kolom TRX (format `recurring/total`) di tabel summary. Brand name ditampilkan dalam tanda kurung di tabel dealing. `<colgroup>` untuk lebar kolom konsisten. Font 12 px agar angka tidak wrap.
- **Print Dashboard & Print Exec**: Semua perubahan layar disinkronkan ke tampilan cetak — tile Recurring di KPI strip, kolom TRX di tabel PIC, layout Regular/Recurring/Total di occupancy.

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
