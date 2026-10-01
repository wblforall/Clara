# Temuan: Tarif Gudang bermakna ganda, Potensi diketik manual

Dicatat 2 Okt 2026. Data dibaca dari salinan basis data **produksi** (ditarik 1 Okt 2026).
Status: **diputuskan dan dikerjakan 2 Okt 2026 (lokal) — belum dipasang di produksi.**

## Yang sudah dikerjakan

- `master_projection()` (`app/helpers.php`) — satu-satunya rumus potensi; `master_save`
  menghitung potensi di server, kolom Potensi di form hanya-baca dan terhitung langsung.
  Tombol "Hitung Potensi" dihapus.
- Migrasi `056_gudang_rate_per_m2.php` — 33 tarif Gudang berisi total dikonversi ke per m²
  (dibulatkan ke rupiah), potensi gudang dihitung ulang lewat `snapshot_potential()` (bulan
  lalu tetap beku, perubahan tercatat di `potential_history`). Uji di salinan produksi:
  potensi gudang aktif hanya bergeser beberapa rupiah, kecuali Guda-UG (0 → 4.600.000) dan
  P402B (0 → 2.647.700).
- Isian otomatis transaksi & SKP Gudang = luas × tarif/m² (tetap boleh diubah).
- Label: "Tarif per m² / Bulan", "Tarif per Hari / m²", "Slot per Hari"; daftar master
  memakai label form, bukan nama kolom basis data.
- Notifikasi dashboard "data master perlu diperbaiki" mencakup: tarif kosong, tarif berbeda
  dari acuan (modus per properti — Exhibition & Gudang), luas kosong, slot/qty Media keliru,
  potensi tak sesuai rumus, lantai/lokasi/tipe di luar daftar baku. Hasil pada salinan
  produksi: E-Walk 25, Pentacity 64.
- Exhibition & Media **tidak** dihitung ulang massal: unit yang menyimpang adalah unit
  dengan tarif salah isi (mis. Rp100 juta/hari/m² → potensi ratusan miliar). Potensinya
  terhitung ulang saat tim membetulkan tarif lewat form, dipandu notifikasi.
- `app/pages/import.php` masih menulis potensi dari CSV apa adanya — ditangkap notifikasi.
- Batas wajar tarif (`master_rate_limit()`): Gudang > Rp500.000/m²/bulan, Exhibition
  > Rp1.000.000/hari/m² dianggap kemungkinan total sewa. **Tidak menolak simpan** (keputusan
  2 Okt: cukup peringatan) — peringatan langsung di form, pesan setelah simpan, dan masuk
  notifikasi dashboard "Tarif … tidak wajar".

## Keputusan — 2 Okt 2026

- **Master = angka proyeksi, aturan hitungnya per m².** Tarif Gudang di master adalah
  **tarif per m² per bulan**; Potensi = luas × tarif/m², dihitung sistem, tidak diketik.
- **Realisasi = dari transaksi.** Harga yang benar-benar disepakati penyewa hidup di
  transaksi/SKP, bukan di master. Isian otomatis transaksi/SKP dari master (luas × tarif/m²)
  hanya titik awal yang boleh diubah.
- Karena master hanya proyeksi, unit yang tarif ÷ luas-nya menyimpang dari Rp103.630
  **dikonversi apa adanya** — tidak perlu dicocokkan ke daftar harga dulu. Penyeragaman tarif
  bisa dilakukan di master kapan saja.
- Yang diketik cukup **satu** (tarif/m²); sewa per bulan dan potensi diturunkan darinya. Dua
  kolom yang sama-sama bisa diketik akan kembali saling menyimpang.

## Ringkasan

1. Kolom tarif Master Gudang (`monthly_rate`) diisi dengan **dua arti berbeda**: sebagian
   unit berisi **tarif per m² per bulan**, sebagian berisi **total sewa sebulan**.
2. Penyebab utamanya **nama kolom**: di daftar tampil `MONTHLY_RATE`, di form "Rate Bulanan" —
   tidak pernah disebut per m² atau total, sehingga tim mengisi sesuai tafsiran masing-masing.
3. Karena artinya ganda, **Potensi Bulanan tidak bisa dihitung dengan satu rumus**, sehingga
   selama ini diketik manual — dan ikut tidak konsisten.

## 1. Arti tarif Gudang — buktinya

54 unit gudang aktif:

| Arti isian tarif | E-Walk | Pentacity | Ciri |
|---|---|---|---|
| Per m² per bulan | 17 | 1 (UG-10) | Potensi = luas × tarif |
| Total sewa sebulan | 2 | 30 | Potensi = tarif |
| Tak bisa dipastikan | — | 2 (Guda-UG, P402B) | Potensi 0 |
| Tarif kosong | — | 2 (LG03, P402D) | Tarif dan potensi 0 |

**Harga dasarnya sebenarnya per m².** Total sebulan Pentacity bila dibagi luasnya hampir
semuanya **Rp103.630/m²** — sama dengan tarif per m² yang diisi di E-Walk. Contoh:

| Kode | Luas | Tarif tercatat | Tarif ÷ luas |
|---|---|---|---|
| GF01 (Pentacity) | 50,61 | 5.244.714 | **103.630** |
| UG03 (Pentacity) | 27,00 | 2.798.010 | **103.630** |
| Guda-008 (E-Walk) | 60,42 | **103.630** | — (sudah per m²) |
| UG-10 (Pentacity) | 40,00 | **115.000** | — (sudah per m²) |

Rincian lengkap per unit ada di **Lampiran A**.

## 2. Kenapa tim bingung — nama kolom

| Tempat | Yang tertulis | Masalah |
|---|---|---|
| Daftar Master Gudang | `MONTHLY_RATE`, `PROJECTION_MONTHLY`, `AREA_SQM` | Nama kolom basis data tampil apa adanya — konfigurasi master tak punya `column_labels`, jadi semua daftar master menampilkan nama mentah |
| Form Master Gudang | "Rate Bulanan" | Tidak menyebut per m² atau total |
| Form Master Exhibition | "Rate Harian/m2" | Jelas — pembanding: di Exhibition tak ada kebingungan serupa (204 dari 209 unit cocok rumus) |
| Form SKP Gudang | "Tarif (per bulan)" | Diisi otomatis dari kolom ini sebagai total sebulan |

Kode juga menafsirkannya sebagai **total**: tombol "Hitung Potensi"
(`app/pages/master.php`, komentar *"gudang: monthly_rate sudah per bulan"*), isian otomatis
form transaksi (`app/pages/bootstrap.php:737`), dan isian otomatis SKP Gudang
(`app/pages/skp_modules.php:599`).

## 3. Dampak

- **Potensi Gudang tidak konsisten** — dua unit (Guda-UG, P402B) bertarif tapi berpotensi 0,
  jadi potensi Pentacity di dashboard dan Executive Summary lebih kecil dari seharusnya.
- **SKP dan transaksi Gudang E-Walk terisi salah** — tarif per m² (Rp103.630) masuk sebagai
  sewa sebulan; sales harus membetulkan manual setiap kali.
- **Rumus potensi tak bisa dipasang** sebelum artinya satu: rumus luas × tarif akan membuat
  GF01 berpotensi ≈ Rp265 juta (seharusnya Rp5,2 juta).

## 4. Potensi di master lain (sekalian diperiksa)

Potensi di ketiga master diketik manual; rumus hanya ada di tombol "Hitung Potensi" yang
hasilnya boleh ditimpa.

| Master | Rumus | Cocok | Menyimpang |
|---|---|---|---|
| Exhibition | tarif/hari/m² × luas × 30 | 204 / 209 | GF-001 (E-Walk); LG-001, GF-001, SF-005, SF - Island 2 (Pentacity) — tarif tak wajar (mis. Rp100 juta/hari/m²), potensinya diketik agar masuk akal |
| Media | menurut jenis harga | 85 / 94 | LED/TVC E-Walk (Medi-012..015) dihitung 12 slot/hari padahal master tercatat 1 slot; Medi-017 qty 2 tak ikut rumus; Medi-024..026 potensi Rp2.541.000 vs tarif Rp5 juta; Parkir GF Park 01 (Pentacity) Rp90 juta berpotensi 0 |

## 5. Usulan perbaikan

1. **Ganti nama dan arti kolom tarif Gudang menjadi "Tarif per m² / bulan"** — sesuai harga
   dasar yang sebenarnya dipakai (Rp103.630/m²).
2. **Konversi sekali** unit yang berisi total: tarif baru = total ÷ luas (32 unit, Lampiran A).
   Daftar hasil konversi diperiksa dulu sebelum dijalankan di produksi.
3. **Potensi dihitung server dari rumus**, kolomnya tidak bisa diketik:
   Gudang = luas × tarif/m²; Exhibition = tarif/hari/m² × luas × 30; Media = menurut jenis harga
   (termasuk qty dan slot).
4. **Form transaksi dan SKP Gudang** mengisi otomatis luas × tarif/m².
5. **Beri label manusiawi di semua daftar master** (`column_labels`), bukan nama kolom basis data.
6. Unit yang tarifnya kosong atau tak wajar masuk **notifikasi dashboard "data master perlu
   diperbaiki"** yang sudah ada.

Alternatif yang ditolak — tarif tetap "total sewa sebulan" dan unit per m² dikonversi
(tarif × luas). Ditolak karena aturan hitung proyeksi memang per m², dan menyimpan angka
turunan sebagai data induk berarti setiap perubahan luas menuntut hitung ulang manual.

## Lampiran A — tarif Gudang aktif per unit

| Properti | Kode | Lantai | Luas | Tarif tercatat | Potensi tercatat | Arti isian | Tarif ÷ luas |
|---|---|---|---|---|---|---|---|
| E-Walk | GF 02A | GF | 13,00 | 103.630 | 1.347.190 | per m² | |
| E-Walk | Guda-001 | LG | 13,01 | 103.630 | 1.348.226 | per m² | |
| E-Walk | Guda-008 | LG | 60,42 | 103.630 | 6.261.325 | per m² | |
| E-Walk | Guda-013 | GF | 16,50 | 103.630 | 1.709.895 | per m² | |
| E-Walk | Guda-014 | UG | 14,74 | 103.630 | 1.527.506 | per m² | |
| E-Walk | Guda-015 | UG | 20,00 | 103.630 | 2.072.600 | per m² | |
| E-Walk | Guda-016 | UG | 40,39 | 103.630 | 4.185.616 | per m² | |
| E-Walk | Guda-017 | UG | 9,21 | 82.904 | 763.546 | per m² | |
| E-Walk | Guda-018 | UG | 15,09 | 103.630 | 1.563.777 | per m² | |
| E-Walk | Guda-019 | UG | 10,50 | 103.630 | 1.088.115 | per m² | |
| E-Walk | Guda-020 | UG | 6,65 | 98.695 | 656.322 | per m² | |
| E-Walk | Guda-021 | UG | 15,51 | 72.541 | 1.125.111 | per m² | |
| E-Walk | Guda-023 | UG | 12,00 | 103.630 | 1.243.560 | per m² | |
| E-Walk | Guda-024 | UG | 23,76 | 103.630 | 2.462.249 | per m² | |
| E-Walk | Guda-025 | UG | 31,07 | 103.630 | 3.219.784 | per m² | |
| E-Walk | P403 | UG | 9,82 | 72.541 | 712.353 | per m² | |
| E-Walk | P501 | GF | 7,73 | 115.000 | 888.950 | per m² | |
| E-Walk | LG03 | LG | 42,39 | 3.514.301 | 3.514.301 | total | 82.904 |
| E-Walk | UG 04A | UG | 8,97 | 1.031.550 | 1.031.550 | total | 115.000 |
| Pentacity | UG-10 | UG (samping tenant Oh some) | 40,00 | 115.000 | 4.600.000 | per m² | |
| Pentacity | Guda-UG | UG | 40,00 | 115.000 | 0 | tak jelas | 2.875 |
| Pentacity | P402B | P4 | 24,07 | 2.647.700 | 0 | tak jelas | 110.000 |
| Pentacity | LG03 | LG | 9,90 | 0 | 0 | kosong | |
| Pentacity | P402D | P4 | 26,00 | 0 | 0 | kosong | |
| Pentacity | GF01 | GF | 50,61 | 5.244.714 | 5.244.714 | total | 103.630 |
| Pentacity | LG01 | LG | 5,10 | 528.513 | 528.513 | total | 103.630 |
| Pentacity | P301 | FF | 22,20 | 3.354.503 | 3.354.503 | total | 151.104 |
| Pentacity | P302 | P3 | 13,01 | 1.348.226 | 1.348.226 | total | 103.630 |
| Pentacity | P303 | P3 | 64,36 | 6.669.627 | 6.669.627 | total | 103.630 |
| Pentacity | P304 | P3 | 25,84 | 2.677.799 | 2.677.799 | total | 103.630 |
| Pentacity | P305 | P3 | 12,63 | 1.308.847 | 1.308.847 | total | 103.630 |
| Pentacity | P306 | P3 | 12,24 | 1.268.742 | 1.268.742 | total | 103.655 |
| Pentacity | P307 | P3 | 17,86 | 1.850.832 | 1.850.832 | total | 103.630 |
| Pentacity | P308 | P3 | 10,00 | 1.100.000 | 1.100.000 | total | 110.000 |
| Pentacity | P309 | P3 | 10,00 | 1.036.300 | 1.036.300 | total | 103.630 |
| Pentacity | P310 | P3 | 10,00 | 1.036.300 | 1.036.300 | total | 103.630 |
| Pentacity | P311 | P3 | 14,62 | 1.515.071 | 1.515.071 | total | 103.630 |
| Pentacity | P312 | P3 | 9,90 | 1.025.937 | 1.025.937 | total | 103.630 |
| Pentacity | P401 | P4 | 63,00 | 6.528.690 | 6.528.690 | total | 103.630 |
| Pentacity | P402A | P4 | 35,64 | 7.848.936 | 7.848.936 | total | 220.228 |
| Pentacity | P402C | P4 | 12,00 | 1.407.945 | 1.407.945 | total | 117.329 |
| Pentacity | P403 | P4 | 22,20 | 2.300.586 | 2.300.586 | total | 103.630 |
| Pentacity | P404 | P4 | 22,40 | 2.321.312 | 2.321.312 | total | 103.630 |
| Pentacity | P501 | P5 | 14,62 | 1.608.200 | 1.608.200 | total | 110.000 |
| Pentacity | P502 | P5 | 38,64 | 4.004.263 | 4.004.263 | total | 103.630 |
| Pentacity | P503 | P5 | 11,47 | 1.188.222 | 1.188.222 | total | 103.594 |
| Pentacity | P504 | P5 | 6,00 | 621.780 | 621.780 | total | 103.630 |
| Pentacity | P505 | P5 | 10,00 | 1.036.300 | 1.036.300 | total | 103.630 |
| Pentacity | UG01 | UG | 6,00 | 621.780 | 621.780 | total | 103.630 |
| Pentacity | UG02 | UG | 4,20 | 435.246 | 435.246 | total | 103.630 |
| Pentacity | UG03 | UG | 27,00 | 2.798.010 | 2.798.010 | total | 103.630 |
| Pentacity | UG04 | UG | 64,51 | 6.685.171 | 6.685.171 | total | 103.630 |
| Pentacity | XGuda-008 | FF | 23,34 | 2.418.724 | 2.418.724 | total | 103.630 |
| Pentacity | XGuda-017 | P3 | 22,20 | 2.300.586 | 2.300.586 | total | 103.630 |

Yang menyimpang dari Rp103.630/m² (dikonversi apa adanya, lihat Keputusan):
P301 (151.104), P402A (220.228), P402C (117.329), P308/P501/P402B (110.000),
E-Walk LG03 (82.904), UG 04A (115.000). Guda-UG dan UG-10 sama-sama 40 m² × Rp115.000 —
nama Guda-UG sama dengan Guda-026 E-Walk yang nonaktif; kemungkinan unit yang sama pernah
dibuat di properti yang salah.

## Lampiran B — field lain yang seharusnya dihitung (penelusuran 2 Okt 2026)

> **Keputusan 2 Okt 2026: nilai transaksi/penjualan TIDAK disentuh.** Harga transaksi memang
> tidak otomatis karena ada negosiasi — itu hak sales. Perbaikan difokuskan pada **master
> data**, karena master adalah acuan manajemen (proyeksi). Dari tabel di bawah, yang masuk
> cakupan hanya #9 (potensi via impor) dan #10 (porsi target PIC). Sisanya dicatat sebagai
> pengetahuan, bukan rencana kerja.
>
> Tindak lanjut: impor **tidak** diubah (tak dipakai; salah isi hasil impor tertangkap
> notifikasi). #10 kini masuk notifikasi dashboard bila Σ porsi PIC Achievement ≠ 100%.

Diperiksa di kode dan di salinan data produksi (transaksi aktif). Gambaran besar: nilai
pendapatan hampir seluruhnya diketik — porsi nilai final dari `override_amount`: Exhibition
92,1%, Gudang 82,0%, Media 95,5%; hanya ±4% override Exhibition yang sama dengan tarif × luas
× hari. Akibatnya tarif/luas di transaksi tidak menggambarkan harga yang ditagih.

| # | Field | Masalah | Data menyimpang | Usulan |
|---|---|---|---|---|
| 1 | Total hitung transaksi Exhibition berpricing `monthly` (`AllocationService.php:32-34`, JS `transactions.php:882-904`) | Tarif master per m²/hari (165.000) dipakai apa adanya sebagai sewa sebulan, luas tak ikut; pratinjau JS beda dari server | 835 dari 884 transaksi CL monthly bertarif 165.000; 979/983 di-override | Otomatis: CL monthly = tarif × luas × 30; samakan JS & server |
| 2 | `unit_rate`, `area_sqm` transaksi ber-override | Diketik bebas, tak pernah diperiksa; override 0 dianggap kosong | 47 transaksi CL tarif ≥ Rp1 juta/m²/hari (mis. #238 → total hitung Rp125 triliun); 93 luas > luas master (menggembungkan kapasitas/occupancy) | Override boleh, beri penanda: tampilkan tarif efektif, alasan wajib bila selisih besar, tolak angka di luar akal |
| 3 | Penanda override (`total_calculated`/`override_amount`) dari SKP, merge recurring, paket | Override selalu "menyala" dari SKP; merge recurring tampak terhitung padahal tidak, tanpa jejak audit | 94 override = calc; 11/13 transaksi asal SKP calc ≠ mesin | Simpan hasil mesin sungguhan + kolom sumber nilai (calc/nego/skp/per_bulan) |
| 4 | `final_amount` dari edit per bulan (spread) | Final = Σ alokasi (benar) tapi override lama tertinggal, tanpa keterangan | 23 transaksi, selisih +Rp211,6 juta | Penanda "final = edit per bulan", bersihkan override basi |
| 5 | Listrik di nilai transaksi (`skp.php:59,78-83`, `offers.php:1820-1824`) | Jalur penawaran tunggal memasukkan listrik ke pendapatan, jalur lain tidak | data campuran | Putuskan satu aturan, lalu hitung ulang |
| 6 | PIC di alokasi (`transactions.php:1757-1767`) | PIC transaksi terkunci SKP bisa diganti tapi alokasi tak ikut → laporan PIC/komisi salah | 0 saat ini (risiko kode) | Otomatis: perbarui PIC alokasi |
| 7 | DP & deposit penawaran (`offers.php`) | Nominal bisa diketik lepas dari "n bulan", tetap dicetak "(n bln)" | deposit 13, DP 5 dari 24 penawaran | Penanda "nominal khusus"; pertimbangkan hapus deposit_months |
| 8 | PPN/Grand Form Utilities, harga/bulan baris SKS (`skp_modules.php`) | Diketik, hanya peringatan di layar | FU 0/8; SKS 1/2 baris | Otomatis PPN & grand; SKS = luas × harga/m² |
| 9 | Potensi via impor CSV (`import.php`) | Potensi dari spreadsheet; nilai "sebelum" dibaca setelah simpan | belum pernah dipakai | Pakai `master_projection()` atau matikan impor |
| 10 | Σ porsi target PIC (`master_pic.target_share`) | Tanpa pemeriksaan jumlah | E-Walk 85%, Pentacity 110% | Penanda bila Σ ≠ 100% |

Sudah konsisten (diperiksa): `period_key` transaksi, alokasi anchor & rentang tanggal,
kode/modul/PIC alokasi, `capacity_days` (sesuai rumus, tapi ikut salah bila luas salah),
`contract_months`, `monthly_amount` & total penawaran.
