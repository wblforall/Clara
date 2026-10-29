# Cara Mengisi Harga di CLARA

Tutorial langkah demi langkah supaya tidak ada satu angka pun yang tercetak dua
kali di surat. Untuk tim Casual Leasing (sales & admin sales) yang mengisi
formulirnya.

> **Aturan satu kalimat:** setiap angka diketik **di satu kotak saja**. Kalau
> sebuah kotak isinya akan sama persis dengan kotak lain, **kosongkan** — yang
> kosong tidak tercetak.

Versi PDF berkop surat dibangun dari skrip, lengkap dengan **potongan layar
sungguhan** dan angka hasil pemeriksaan yang dibaca langsung dari database saat
dicetak:

```bash
# 1) ambil screenshot dari CLARA yang jalan di lokal (butuh puppeteer-core)
CLARA_TEST_PASS='...' NODE_PATH=<...>/node_modules node scripts/tutorial_screenshot.js

# 2) bangun PDF-nya
php scripts/build_tutorial_dobel.php
php scripts/build_tutorial_dobel.php --sejak=2026-10 --keluar=/jalur/Tutorial.pdf
```

Langkah 1 memerlukan dua dokumen Form Utilities contoh (id 99950 & 99951) — satu
menampilkan harga item, satu menyembunyikannya. Tanpa screenshot, PDF-nya tetap
terbangun: tempat gambarnya diisi keterangan cara mengambilnya.

PDF dan screenshot-nya **tidak** ikut di-commit (`docs/**/*.pdf` dan
`scripts/tutorial_assets/` ada di `.gitignore`) karena memuat nama penyewa dan
nilai kontrak, sementara repo ini publik.

---

## Kartu contekan

| Kotak di layar | Diisi kapan | Dikosongkan kapan | Kalau kosong |
|---|---|---|---|
| **Rate** <br>*Surat Penawaran* | selalu — ini tarif dasarnya | harga borongan (Lump sum) | baris tarif tidak tercetak |
| **Harga / Bulan** <br>*Surat Penawaran* | kontrak > 1 bulan yang ditagih bulanan | **kontrak 1 bulan**, atau dibayar sekaligus di muka | baris "Harga Sewa / Bulan" tidak tercetak |
| **Harga Nego Final** <br>*Surat Penawaran* | nilai akhirnya beda dari hasil kalkulasi | nilainya sama dengan kalkulasi | dipakai Total Kontrak hasil kalkulasi |
| **Total Biaya Sewa** <br>*SKP* | nilainya memang beda dari tarif × periode | sama dengan hitungan otomatis | dihitung sendiri dari tarif × periode |
| **Harga Sewa /Bulan** <br>*Gudang (SKS)* | sewa > 1 bulan | **sewa 1 bulan** | kolomnya hilang dari tabel harga |
| **Luas (m²)** <br>*semua formulir* | tulis angkanya saja: `4`, atau `14,62` bila memang diukur | — | tercetak "4 m²", bukan "4,00 m²" |

---

## Bagian 1 — Surat Penawaran (Pameran)

Contoh yang dipakai: Atrium GF, **4 m²**, **1 bulan**, tarif
**Rp 3.600.000 / m² / bulan**.

1. **Unit / Lokasi** — pilih unitnya; **Luas (m²)** dan **Rate** terisi sendiri
   dari Master. Luas ditulis angka saja: `4`, bukan `4,00`.
2. **Dasar Harga Sewa** — pilih **satu**. Pilihan ini menentukan arti kotak
   Rate sekaligus satuan yang tercetak:

   | Pilihan | Artinya kotak Rate | Tercetak di surat |
   |---|---|---|
   | Per m² / hari *(bawaan)* | harga 1 m² sehari | Biaya Sewa / m² / hari |
   | Per m² / bulan | harga 1 m² sebulan | Biaya Sewa / m² / bulan |
   | Per slot / hari | harga 1 slot sehari | Biaya Sewa / slot / hari |
   | Per titik / hari | harga 1 titik sehari | Biaya Sewa / titik / hari |
   | Per bulan *(tanpa hitungan luas)* | harga sebulan, luas tidak dikali | Biaya Sewa / bulan |
   | Lump sum *(satu nilai)* | — tidak dipakai | Nilai Kontrak (lump sum) |

3. **Rate** — isi tarifnya. Boleh dikosongkan; kalau kosong barisnya tidak
   tercetak di mana pun.
4. **Tanggal Mulai & Tanggal Selesai** — **Total Kontrak** terhitung sendiri
   dan tidak bisa diketik. Jangan mengetik ulang angka itu di kotak lain.
5. **Harga / Bulan** — kotak yang paling sering bikin dobel:

   | Lama kontrak | Kotak Harga / Bulan | Alasan |
   |---|---|---|
   | **1 bulan** | **kosongkan** | angkanya sama persis dengan Harga Sewa / Periode |
   | > 1 bulan, ditagih bulanan | **isi** harga sebulan | client perlu tahu cicilan tiap bulannya |
   | setahun sekaligus, tidak ditagih bulanan | **kosongkan** | surat tidak menyebut angka bulanan yang tak pernah disepakati |

   ```
   Kontrak 1 bulan — Harga / Bulan DIISI (dulu)
   a. Biaya Sewa / m² / bulan                   Rp  3.600.000
   b. Harga Sewa / Bulan                        Rp 14.400.000
   c. Harga Sewa / Periode                      Rp 14.400.000   ← sama dengan b

   Kontrak 1 bulan — Harga / Bulan DIKOSONGKAN (benar)
   a. Biaya Sewa / m² / bulan                   Rp  3.600.000
   b. Harga Sewa / Periode                      Rp 14.400.000

   Kontrak 6 bulan — Harga / Bulan DIISI (benar)
   a. Biaya Sewa / m² / bulan                   Rp  3.600.000
   b. Harga Sewa / Bulan                        Rp 14.400.000
   c. Harga Sewa / Periode (6 bulan)            Rp 86.400.000
   ```

6. **Harga Nego Final** — hanya bila nilai akhirnya beda dari Total Kontrak.
   Kalau yang turun adalah **tarifnya**, pakai **Harga Diskon**: Rate diisi
   tarif publish, **Tarif setelah diskon** diisi tarif nego.
7. **Lihat Contoh Surat** sebelum Simpan — baca blok Rincian Biaya sekali.
   Ada dua baris bernominal sama? Berarti ada kotak yang terisi dua kali.

---

## Bagian 2 — SKP (Surat Konfirmasi Pemesanan)

Harganya sudah terbawa dari penawaran. Yang masih boleh disentuh:

| Kotak | Kapan diubah |
|---|---|
| **Dasar Harga Sewa** | hanya bila di penawaran memang salah pilih |
| **Jumlah Bulan Kontrak** | kosongkan — terhitung sendiri dari tanggalnya |
| **Total Biaya Sewa** *(kosongkan = hitung otomatis)* | **kosongkan** bila sama dengan hitungan otomatis |
| **Jaminan / Security Deposit** | isi nominalnya; centang **Sudah dibayarkan** bila sudah disetor di kontrak sebelumnya |

Huruf urut (a, b, c …) dibagikan **setelah** baris kembar dilebur, jadi tidak
pernah meloncat. Keterangan dalam kurung ikut pindah ke baris yang bertahan.

---

## Bagian 3 — Gudang (Surat Konfirmasi Sewa)

| Kolom | Sewa 1 bulan | Sewa > 1 bulan |
|---|---|---|
| Harga Sewa /m²/Bulan | isi | isi |
| Harga Sewa /Bulan | **kosongkan** | **isi** |
| Total Harga Sewa | isi | isi |

Kalau **semua** barisnya punya "Harga Sewa /Bulan" sama dengan "Total Harga
Sewa", kolom tengahnya disembunyikan sendiri saat dicetak. Yang hilang
kolomnya — angkanya tidak diubah.

---

## Bagian 4 — Media (Form Utilities)

1. **Tarif** diisi harga per hari/titik; **Total Nilai Sewa** terhitung sendiri.
2. **Daftar Utilities / Media Promo / Parkir** — centang yang dipakai, isi
   **Biaya** dan **Satuan**. Biaya item yang dicentang **ikut menambah** Total
   Nilai Sewa, jadi jangan ditambahkan sekali lagi dengan tangan. Item boleh
   dicentang **tanpa biaya**.
3. **Sembunyikan harga di surat** (saklar di tiap judul kelompok) — kolom biaya
   kelompok itu tidak dicetak *dan* tidak ikut menambah Total Nilai Sewa. Harga
   yang sudah diketik tetap tersimpan.
4. **Rincian Biaya** yang tercetak:

   ```
   Biaya / Hari                        Rp.  25.000.000
   Total Biaya Sewa                    Rp. 100.000.000
   PPN 12%                             Rp.  11.000.000
   Jaminan Area (Security Deposit)     Rp.  10.000.000
   Grand Total                         Rp. 121.000.000
   ```

   PPN dihitung nilai × 11/12 × 12% (= 11% dari nilai sewa), sesuai PMK No. 131
   Tahun 2024. Grand Total sudah termasuk jaminan; jaminan yang sudah disetor
   tetap tercetak tapi tidak ditagih ulang.

---

## Tiga kesalahan yang paling sering

| Kesalahan | Yang terjadi di surat | Benarnya |
|---|---|---|
| Harga / Bulan diisi pada kontrak 1 bulan | dua baris bernominal sama persis | kosongkan kotaknya |
| Harga Nego Final diisi angka yang sama dengan Total Kontrak | kolom tarif tidak lagi mencerminkan harga asli | kosongkan kotaknya |
| Luas ditulis `4,00` | tercetak "4,00 m²" | tulis `4` |

**Kalau terlanjur salah, suratnya tetap benar.** Baris yang nilainya sama
dengan baris di bawahnya dilebur sendiri oleh sistem saat dicetak, termasuk
pada surat lama yang dicetak ulang. **Nominalnya tidak ada yang berubah** —
yang hilang barisnya, bukan uangnya.

---

## Memeriksa sendiri

```bash
php scripts/cek_rincian_double.php --sejak=2026-10 --rinci
```

Skrip ini **membaca saja** — tidak menulis satu baris pun ke database. Ia
menyebut berapa surat yang memuat baris kembar, berapa baris yang dilebur, dan
membuktikan nilai tertagih sebelum & sesudah sama persis (**Selisih Rp 0**).

### Kalau masih terlihat dobel

1. **Hard refresh sekali** (Cmd/Ctrl + Shift + R) setelah deploy.
2. Pastikan yang dilihat bukan **dua tingkatan tarif** yang kebetulan bernilai
   sama — itu memang harus tercetak dua-duanya.
3. Pada **Detail Alokasi**, satu bulan yang dulu muncul dua kali kini digabung
   jadi satu baris, ditandai "(2 potongan siklus)". Kalau masih dua baris,
   periksa kolom **PIC**.
4. Kalau tetap ada dua baris bernominal sama yang bukan dua tingkatan tarif,
   kirimkan nomor suratnya — itu pola baru yang belum dikenali.

---

## Daftar periksa sebelum menekan Simpan

- [ ] **Luas** ditulis angka saja — `4`, bukan `4,00`
- [ ] **Dasar Harga Sewa** sudah benar, dan cuma satu yang dipilih
- [ ] **Rate** terisi sesuai Dasar Harga tadi
- [ ] **Harga / Bulan** kosong kalau kontraknya 1 bulan, atau dibayar sekaligus di muka
- [ ] **Harga Nego Final** kosong kalau nilainya sama dengan Total Kontrak
- [ ] SKP: **Total Biaya Sewa** kosong kalau sama dengan hitungan otomatis
- [ ] Gudang: **Harga Sewa /Bulan** kosong kalau sewanya 1 bulan
- [ ] Media: **Total Nilai Sewa** tidak diketik ulang setelah biaya item dicentang
- [ ] Sudah menekan **Lihat Contoh Surat** dan membaca blok **Rincian Biaya**-nya
- [ ] Di contoh surat itu **tidak ada dua baris bernominal sama**
