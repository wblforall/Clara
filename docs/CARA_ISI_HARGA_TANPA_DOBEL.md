# Cara mengisi harga supaya surat tidak memuat baris dobel

Berlaku untuk **Surat Penawaran** dan **SKP / Surat Konfirmasi**.
Ditulis untuk tim Casual Leasing (sales & admin sales) yang mengisi formulirnya.

---

## Apa yang dulu terjadi

Surat memuat satu nominal **dua sampai tiga kali** dengan nama berbeda:

```
a. Biaya Sewa / m² / bulan                           Rp  3.600.000
b. Biaya Sewa / Bulan (4 m² × Rp 3.600.000)          Rp 14.400.000
c. Nilai Sewa                                        Rp 14.400.000   ← sama dengan b
d. PPN 12% Sewa                                      Rp  1.584.000
```

Baris **b** dan **c** isinya persis sama. Itu bukan dua tagihan — itu satu
angka yang ditulis dua kali, karena kontraknya cuma **1 bulan**: harga sebulan
memang sama dengan harga seluruh periode.

Client yang membacanya menghitung ulang mencari bedanya, dan tidak ada bedanya.

---

## Yang sekarang dilakukan sistem sendiri

CLARA melebur baris yang nilainya sama dengan baris di bawahnya. Surat yang
sama kini tercetak:

```
a. Biaya Sewa / m² / bulan                           Rp  3.600.000
b. Nilai Sewa (4 m² × Rp 3.600.000)                  Rp 14.400.000
c. PPN 12% Sewa                                      Rp  1.584.000
```

Keterangan dalam kurung **tidak hilang** — ia pindah ke baris yang bertahan,
jadi client tetap bisa menghitung ulang sendiri. Huruf urutnya dibagikan
setelah peleburan, sehingga tidak pernah meloncat (a, b, d).

**Nominalnya tidak ada yang berubah.** Yang hilang barisnya, bukan uangnya.
Total, PPN, deposit, dan Grand Total sama persis seperti sebelumnya — termasuk
pada surat lama yang dicetak ulang.

---

## Yang perlu kamu lakukan saat mengisi

### 1. Kotak **Harga / Bulan** — kosongkan untuk kontrak 1 bulan

| Lama kontrak | Kotak Harga / Bulan | Alasan |
|---|---|---|
| 1 bulan | **kosongkan** | angkanya sama dengan Harga Sewa / Periode |
| lebih dari 1 bulan | isi harga sebulan | client perlu tahu cicilan bulanannya |
| setahun sekaligus (tidak ditagih bulanan) | **kosongkan** | surat tidak menyebut harga bulanan yang tak pernah disepakati |

Kalau terlanjur diisi pada kontrak 1 bulan, suratnya tetap benar — sistem
meleburnya. Mengosongkannya hanya membuat niatmu terbaca jelas oleh pemeriksa.

### 2. Pilih **Dasar Harga** yang benar, satu saja

`Per m² / hari` · `Per m² / bulan` · `Per bulan` · `Per slot / hari` ·
`Per titik / hari` · `Lump sum`.

Dasar harga menentukan tulisan satuan di surat. Memilih **Per bulan** lalu juga
mengisi Harga / Bulan menghasilkan dua baris bernama hampir sama — sekarang
dilebur, tapi lebih baik tidak dibuat sejak awal.

### 3. **Luas** diisi angka saja

Tulis `4`, bukan `4,00` atau `4.00`. Desimal hanya ditulis kalau memang diukur,
mis. `14,62`. Surat & daftar master sekarang mencetak `4 m²`, bukan `4,00 m²`.

### 4. **Harga Nego Final** hanya diisi bila beda dari kalkulasi

Mengisinya dengan angka yang sama persis dengan hasil kalkulasi tidak menambah
apa-apa di surat, dan membuat kolom tarif tidak lagi mencerminkan harga asli.

---

## Memeriksa hasilnya

```bash
php scripts/cek_rincian_double.php --sejak=2026-10 --rinci
```

Skrip ini **membaca saja** — tidak menulis satu baris pun ke database. Ia
menyebut berapa surat yang sebelumnya memuat baris kembar, berapa baris yang
dilebur, dan membuktikan nilai tertagih sebelum & sesudah sama persis
(`Selisih Rp 0`).

---

## Kalau masih terlihat dobel

1. **Hard refresh sekali** (Cmd/Ctrl + Shift + R) setelah deploy.
2. Pastikan yang dilihat bukan **dua tingkatan tarif** yang kebetulan bernilai
   sama — itu memang harus tercetak dua-duanya, dan sengaja tidak dilebur.
3. Pada **Detail Alokasi**, satu bulan yang muncul dua kali dengan catatan
   "(2 potongan siklus)" sudah digabung jadi satu baris. Kalau masih dua baris,
   periksa kolom **PIC**: income yang dibagi ke dua PIC memang punya satu baris
   per PIC, dan subtotal bulannya dicetak di bawahnya.
