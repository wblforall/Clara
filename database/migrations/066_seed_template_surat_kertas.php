<?php
// Lima template Surat Penawaran, disalin dari surat kertas yang selama ini
// dipakai: Pushcart, Snack Corner, Atrium, FuniFun! (sewa 1 tahun), dan
// Foodcourt. Isinya diambil langsung dari dokumen Word aslinya, bukan diketik
// ulang, supaya bunyi suratnya sama.
//
// Dua hal yang sengaja TIDAK ikut disalin:
//
// 1) NOMOR REKENING. Dokumen Word menuliskan nomor yang berbeda dari yang
//    selama ini tercetak aplikasi. Nomor rekening terlalu berisiko untuk
//    ditebak, jadi dipakai nomor yang sudah dipakai aplikasi; silakan dibetulkan
//    di halaman Template bila ternyata nomor di kertas yang benar.
//
// 2) NAMA & NOMOR WHATSAPP. Di kertas tertulis satu nama dan satu nomor. Di
//    sini diganti {PIC_BESAR} dan {WA} supaya mengikuti sales yang membuat
//    penawaran — itu memang yang diminta.
//
// Template ditambahkan untuk KEDUA properti dan tidak ada yang dijadikan
// bawaan: template bawaan tetap yang selama ini dipakai, supaya kebiasaan kerja
// tidak berubah mendadak. Penyemaian ini aman diulang — nama yang sudah ada
// dilewati.

$TEMPLATE = [
    [
        'name' => 'Pushcart — contoh surat kertas',
        'layout' => 'tabel',
        'gaya_daftar' => 'bullet',
        'perihal' => 'Penawaran Harga Sewa Pushcart',
        'intro' => 'Bersama ini kami Manajemen e-Walk dan Pentacity Mall Balikpapan menawarkan space Exhibition yang ada di area gedung. Space exhibition yang kami tawarkan adalah sebagai berikut :',
        'ppn_persen' => 12.0,
        'ppn_rumus' => 1,
        'ppn_catatan' => '*PPN 12% Sesuai PMK Nomor 131 Tahun 2024 dengan perhitungan (NILAI SEWA x 11/12 x 12%)',
        'rincian_biaya' => 0,
        'ket' => [
            'Masa sewa {hari} hari',
            'Periode sewa {periode}',
            'Harga belum termasuk PPN {ppn_persen}',
            'Harga belum termasuk biaya Listrik',
        ],
        'rincian' => [],
        'fasilitas' => [
            'Standar pushcart',
            'Power listrik',
        ],
        'media' => [
            'Media sosial e-Walk dan Pentacity Mall Balikpapan',
            'Ijin pembagian flyers di area pameran selama periode pameran',
        ],
        'payment' => [
            'Wajib melakukan pembayaran 30% dari harga di atas sebagai tanda jadi, maksimal satu minggu setelah penawaran disetujui dan pelunasan H -7 sebelum pelaksanaan pameran.',
            'Pembayaran Security Deposit (uang jaminan) senilai Rp. 1.000.000,- sebagai jaminan apabila ada kerusakan setelah masa sewa berakhir. Security Deposit dibayarkan sekaligus beserta dengan seluruh tagihan sewa.',
            'Apabila tidak terjadi kerusakan setelah masa sewa berakhir maka Security Deposit dikembalikan ke penyewa 100%',
        ],
        'terms' => [
            'Penyewa/peserta pameran dilarang menjual produk pameran yang melanggar Hak Cipta, seperti produk bajakan atau barang palsu.',
            'Wajib memberikan atau menyerahkan desain (gambar) booth pameran ke Manajemen sebelum masuk pameran.',
            'Wajib menggunakan tripod banner untuk media promo.',
            'Bersedia mengikuti segala ketentuan dan tata tertib yang berlaku.',
            'Dilarang melakukan tindakan yang melanggar hukum (seperti penipuan, gesek tunai, menjual barang-barang diluar produk yang di ajukan, judi/hutang online dan MLM).',
            'Untuk display barang jualan hanya diletakkan didalam area pushcart, tidak meletakkan/menyimpan barang - barang jualan/produk diluar area pushcart (seperti stok barang, meja tambahan etalase, tempat makan, kipas angin, tikar, banner, sandal/sepatu, dll).',
            'Karyawan pushcart wajib menggunakan pakaian yang rapi, sopan, bersih, dan menggunakan sepatu (tidak menggunakan sandal jepit) dan wajib menggunakan ID card karyawan.',
            'Untuk produk skincare wajib memberikan bukti BPOM produk tersebut.',
            'Jam buka pushcart mengikuti jam operasional Mall, 10.00 - 22.00 Wita.',
            'Jika penyewa melakukan pengunduran jadwal dari tanggal masa sewa yang tertulis di kontrak maka akan dikenakan biaya Rp 1.000.000,- (Satu Juta Rupiah) di luar dari total harga sewa pameran.',
            'Batas pengunduran jadwal pameran maksimal 1 bulan dari masa sewa yang tertulis di kontrak awal.',
            'Apabila melebihi batas pengunduran pameran maka pameran dianggap batal dan pembayaran yang telah dibayarkan penyewa tidak dapat ditarik kembali.',
            'PPN 12% di tanggung penyewa jika terjadi pembatalan kontrak pameran.',
            'Pengurusan surat keluar masuk di jam operasional kantor (10.00-16.00 Wita), apabila pengurusan diluar jam kerja kantor tidak dilayani dengan alasan apapun.',
            'Data peserta pameran (pribadi/perusahaan) harus sesuai dengan yang diberikan kepada pihak Manajemen Mall, dan apabila kontrak, invoice dan faktur pajak telah terbit, maka data tidak dapat dirubah dengan alasan apapun (kecuali kesalahan penginputan data dari pihak Manajemen e-Walk dan Pentacity Mall Balikpapan).',
            'Apabila terdapat perubahan data untuk pameran selanjutnya, peserta pameran wajib menginfokan perubahan data tersebut kepada pihak Manajemen e-Walk dan Pentacity Mall Balikpapan.',
            'PT. Wulandari Bangun Laksana, Tbk tidak bertanggung jawab atas segala kerusakan maupun kehilangan barang milik penyewa ataupun peserta event, sebelum–sesaat–sesudah berlangsungnya event.',
            'Terhadap kerusakan dan atau kehilangan barang milik PT. Wulandari Bangun Laksana, Tbk yang dapat dibuktikan akibat kelalaian maupun kesengajaan Penyewa atau peserta event maka Penyewa diwajibkan untuk mengganti kerugian yang ditimbulkan.',
            'Untuk pemakaian listrik penyambungan, peserta pameran diwajibkan memakai ukuran kabel NYM 3 x 2,5 mm.',
            'Pemakaian listrik akan dikenakan biaya sesuai pemakaian, dengan tarif Rp 3.150,-/Kwh',
        ],
        'judul' => [
            'fasilitas' => 'Fasilitas yang disediakan e-Walk dan Pentacity Mall Balikpapan',
            'media' => 'Media promosi yang dapat digunakan',
            'pembayaran' => 'Cara Pembayaran',
            'ketentuan' => 'Ketentuan & Persyaratan',
        ],
        'penutup' => 'Untuk keterangan lebih lanjut dapat menghubungi kantor kami {KANTOR} atau whatsapp ke {PIC_BESAR} di nomor {WA}.',
    ],
    [
        'name' => 'Snack Corner — contoh surat kertas',
        'layout' => 'tabel',
        'gaya_daftar' => 'bullet',
        'perihal' => 'Penawaran Harga Snack Corner',
        'intro' => 'Bersama ini kami Manajemen e-Walk dan Pentacity Mall Balikpapan menawarkan space snack corner yang ada di area gedung. Space snack corner yang kami tawarkan adalah sebagai berikut :',
        'ppn_persen' => 12.0,
        'ppn_rumus' => 0,
        'ppn_catatan' => '',
        'rincian_biaya' => 0,
        'ket' => [
            'Periode sewa {periode}',
            'Masa sewa {hari} hari',
            'Harga belum termasuk PPN {ppn_persen}',
            'Harga belum termasuk biaya listrik',
        ],
        'rincian' => [],
        'fasilitas' => [
            'Standar area pameran',
            'Power listrik',
        ],
        'media' => [
            'Media sosial e-Walk dan Pentacity Mall Balikpapan',
        ],
        'payment' => [
            'Wajib melakukan pembayaran 30% dari harga di atas sebagai tanda jadi, maksimal satu minggu setelah penawaran disetujui dan pelunasan H -2 sebelum pelaksanaan pameran.',
            'Pembayaran Security Deposit (uang jaminan) senilai Rp. 1.000.000,- sebagai jaminan apabila ada kerusakan setelah masa sewa berakhir. Security Deposit dibayarkan sekaligus beserta dengan seluruh tagihan sewa.',
            'Apabila tidak terjadi kerusakan setelah masa sewa berakhir maka Security Deposit dikembalikan ke penyewa 100%',
        ],
        'terms' => [
            'Untuk kelebihan pemakaian listrik akan dikenakan biaya tambahan Rp. 3.150,- /kwh.',
            'Pencatatan pemakaian listrik akan dilakukan di awal dan akhir periode pameran.',
            'Invoice akan diterbitkan setiap akhir periode pameran.',
            'Untuk pembersihan peralatan dapat dilakukan di Janitor yang sudah disediakan / ditentukan di Area First Floor Pentacity Mall Balikpapan.',
            'Penyewa/peserta pameran dilarang menjual produk pameran yang melanggar Hak Cipta, seperti produk bajakan atau barang palsu.',
            'Wajib memberikan atau menyerahkan desain (gambar) booth pameran ke Manajemen sebelum masuk pameran.',
            'Bersedia mengikuti segala ketentuan dan tata tertib yang berlaku.',
            'Wajib mengikuti jam oprasional e-Walk dan Pentacity Mall Balikpapan:',
            'Hari : Senin s/d Minggu',
            'Jam : 10.00 s/d 22.00 WITA',
            'Wajib memperhatikan display barang pameran agar tidak keluar dari area/space pameran.',
            'Wajib memberikan daftar nama karyawan / karyawati yang bertugas, dan apabila ada perubahan daftar nama maka segera dilaporkan kepada pihak Manajemen e-Walk dan Pentacity Mall Balikpapan.',
            'Pemakaian partisi / booth dengan ketinggian max. 2,4 meter (tidak full block).',
            'Pameran wajib menggunakan level kayu dan karpet (disediakan oleh peserta pameran).',
            'Wajib menggunakan tripod banner untuk media promosi.',
            'Jika penyewa melakukan pengunduran jadwal dari tanggal masa sewa yang tertulis di kontrak maka akan dikenakan biaya Rp 1.000.000,-(Satu Juta Rupiah) di luar dari total harga sewa pameran.',
            'Batas pengunduran jadwal pameran maksimal 1 bulan dari masa sewa yang tertulis di kontrak awal.',
            'Apabila melebihi batas pengunduran pameran maka pameran dianggap batal dan pembayaran yang telah dibayarkan penyewa tidak dapat ditarik kembali.',
            'PPN 10% di tanggung penyewa jika terjadi pembatalan kontrak pameran.',
            'Pengurusan surat keluar masuk di jam operasional kantor (10.00-16.00 WITA), apabila pengurusan diluar jam kerja kantor tidak dilayani dengan alasan apapun.',
            'Data peserta pameran (pribadi/perusahaan) harus sesuai dengan yang diberikan kepada pihak Manajemen Mall, dan apabila kontrak, invoice dan faktur pajak telah terbit, maka data tidak dapat dirubah dengan alasan apapun (kecuali kesalahan penginputan data dari pihak Manajemen e-Walk dan Pentacity Mall Balikpapan).',
            'Apabila terdapat perubahan data untuk pameran selanjutnya, peserta pameran wajib menginfokan perubahan data tersebut kepada pihak Manajemen e-Walk dan Pentacity Mall Balikpapan.',
            'Untuk pemakaian listrik penyambungan, peserta pameran diwajibkan memakai ukuran kabel NYM 3 x 2,5 mm.',
            'PT. Wulandari Bangun Laksana, Tbk tidak bertanggung jawab atas segala kerusakan maupun kehilangan barang milik penyewa ataupun peserta event, sebelum – sesaat – sesudah berlangsungnya event.',
            'Terhadap kerusakan dan atau kehilangan barang milik PT. Wulandari Bangun Laksana, Tbk yang dapat dibuktikan akibat kelalaian maupun kesengajaan Penyewa atau peserta event maka Penyewa diwajibkan untuk mengganti kerugian yang ditimbulkan.',
        ],
        'judul' => [
            'fasilitas' => 'Fasilitas yang disediakan e-Walk dan Pentacity Mall Balikpapan',
            'media' => 'Media promosi yang dapat digunakan',
            'pembayaran' => 'Cara Pembayaran',
            'ketentuan' => 'Ketentuan & Persyaratan',
        ],
        'penutup' => 'Untuk keterangan lebih lanjut dapat menghubungi kantor kami {KANTOR} atau whatsapp ke {PIC_BESAR} di nomor {WA}.',
    ],
    [
        'name' => 'Atrium — contoh surat kertas',
        'layout' => 'tabel',
        'gaya_daftar' => 'bullet',
        'perihal' => 'Penawaran Harga Sewa Atrium Utama GF',
        'intro' => 'Bersama ini kami Manajemen e-Walk dan Pentacity Mall Balikpapan menawarkan space exhibition yang ada di area gedung. Space exhibition yang kami tawarkan adalah sebagai berikut :',
        'ppn_persen' => 12.0,
        'ppn_rumus' => 1,
        'ppn_catatan' => '*PPN 12% Sesuai PMK Nomor 131 Tahun 2024 dengan perhitungan (NILAI SEWA x 11/12 x 12%)',
        'rincian_biaya' => 0,
        'ket' => [
            'Masa sewa {hari} hari',
            'Periode sewa {periode}',
            'Harga sudah termasuk listrik',
            'Harga sudah termasuk loading in dan out',
            'Harga belum termasuk PPN {ppn_persen}',
        ],
        'rincian' => [],
        'fasilitas' => [
            'Standar area pameran',
            'Standar karpet dan power listrik',
        ],
        'media' => [
            'Media sosial mall',
            'Pembagian flyers di area event',
        ],
        'payment' => [
            'Wajib melakukan pembayaran Uang Tanda Jadi sebesar Rp. 10.000.000,- (sepuluh juta rupiah) H +7 setelah surat penawaran harga disetujui.',
            'Wajib melakukan Down Payment sebesar 50% dari harga diatas maksimal 30 hari sebelum event berjalan dan pelunasan H -10 sebelum event berjalan.',
            'Wajib melakukan pembayaran Security Deposit (uang jaminan) senilai Rp. 5.000.000,- sebagai jaminan apabila ada kerusakan setelah masa sewa berakhir. Security Deposit dibayarkan sekaligus beserta dengan seluruh tagihan sewa.',
            'Apabila tidak terjadi kerusakan setelah masa sewa berakhir maka Security Deposit dikembalikan ke penyewa 100%.',
            'Pembayaran uang sewa dinyatakan sah diterima PT. Wulandari Bangun Laksana, Tbk. setelah penyewa menunjukan bukti pembayaran/transfer dan masuk dalam rekening koran PT. Wulandari Bangun Laksana, Tbk.',
            'PT. Wulandari Bangun Laksana',
            'Bukti pembayaran di email ke {EMAIL} atau whatsapp ke {PIC_BESAR} di nomor {WA}.',
        ],
        'terms' => [
            'Apabila Penyewa telah membayar Uang Tanda Jadi namun belum melakukan Down Payment, kemudian penyewa membatalkan sewa area maka PT. Wulandari Bangun Laksana, Tbk tidak berkewajiban mengembalikan Uang Tanda Jadi yang telah diterima dari pihak penyewa.',
            'Apabila Penyewa telah melakukan Down Payment, kemudian Penyewa membatalkan sewa area, maka PT. Wulandari Bangun Laksana, Tbk hanya dibebankan untuk mengembalikan 50% dari Uang Tanda Jadi dan Down Payment yang telah diterima dari Penyewa.',
            'Apabila Penyewa telah melunasi harga sewa area, kemudian Penyewa membatalkan sewa area, maka PT. Wulandari Bangun Laksana, Tbk hanya dibebankan untuk mengembalikan 50% dari keseluruhan pembayaran yang telah diterima dari Penyewa.',
            'Jika Penyewa melakukan pengunduran jadwal dari tanggal masa sewa yang tertulis di kontrak maka akan dikenakan biaya Rp 1.000.000,-(Satu Juta Rupiah) di luar dari total harga sewa pameran.',
            'Batas pengunduran jadwal pameran maksimal 1 bulan dari masa sewa yang tertulis di kontrak awal.',
            'Apabila melebihi batas pengunduran pameran maka pameran dianggap batal dan pembayaran yang telah dibayarkan Penyewa tidak dapat ditarik kembali.',
            'PPN 12% di tanggung penyewa jika terjadi pembatalan kontrak pameran.',
            'Data peserta pameran (pribadi/perusahaan) harus sesuai dengan yang diberikan kepada pihak manajemen e-Walk dan Pentacity Mall Balikpapan, dan apabila kontrak, invoice dan faktur pajak telah terbit, maka data tidak dapat dirubah dengan alasan apapun (kecuali kesalahan penginputan data dari pihak manajemen e-Walk dan Pentacity Mall Balikpapan).',
            'Apabila terdapat perubahan data untuk pameran selanjutnya, peserta pameran wajib menginfokan perubahan data tersebut kepada pihak manajemen e-Walk dan Pentacity Mall Balikpapan.',
            'PT. Wulandari Bangun Laksana, Tbk tidak bertanggung jawab atas segala kerusakan maupun kehilangan barang milik penyewa ataupun peserta event, sebelum–sesaat–sesudah berlangsungnya event.',
            'Terhadap kerusakan dan atau kehilangan barang milik PT. Wulandari Bangun Laksana, Tbk yang dapat dibuktikan akibat kelalaian maupun kesengajaan Penyewa atau peserta event maka Penyewa diwajibkan untuk mengganti kerugian yang ditimbulkan.',
            'Penyewa/peserta pameran dilarang menjual produk pameran yang melanggar Hak Cipta, seperti produk bajakan atau barang palsu.',
            'Penyewa wajib memiliki dokumen perizinan atas kegiatan yang diselenggarakan, dengan biaya pengurusan yang dibebankan kepada penyewa.',
            'Wajib memberikan atau menyerahkan desain (gambar) layout ke Manajemen sebelum masuk event.',
            'Wajib memperhatikan display barang pameran agar tidak keluar dari area event.',
            'Bersedia mengikuti segala ketentuan dan tata tertib yang berlaku.',
            'Pemakaian partisi/booth dengan ketinggian max. 2,4 meter (tidak full block).',
            'Wajib menggunakan tripod banner untuk media promosi.',
            'Masa sewa sudah termasuk loading in dan out.',
            'Pemakaian listrik akan dikenakan biaya sesuai pemakaian, dengan tarif Rp 3.150,- /Kwh',
            'Area wajib menggunakan full karpet (disediakan oleh pihak Penyewa).',
            'Pengurusan surat keluar masuk di jam operasional kantor (10.00-16.00 WITA), apabila pengurusan diluar jam kerja kantor tidak dilayani dengan alasan apapun.',
            'Untuk pemakaian listrik penyambungan, peserta pameran diwajibkan memakai ukuran kabel NYM 3x2,5 mm.',
        ],
        'judul' => [
            'fasilitas' => 'Fasilitas yang disediakan Pentacity Mall Balikpapan',
            'media' => 'Media promosi yang dapat digunakan',
            'pembayaran' => 'Cara Pembayaran',
            'ketentuan' => 'Ketentuan & Persyaratan',
        ],
        'penutup' => 'Untuk keterangan lebih lanjut dapat menghubungi kantor kami {KANTOR} atau whatsapp ke {PIC_BESAR} di nomor {WA}.',
    ],
    [
        'name' => 'FuniFun! — contoh surat kertas',
        'layout' => 'tabel',
        'gaya_daftar' => 'bullet',
        'perihal' => 'Penawaran Harga Sewa 1 Tahun',
        'intro' => 'Bersama ini kami Manajemen e-Walk dan Pentacity Mall Balikpapan menawarkan space exhibition yang ada di area gedung. Space exhibition yang kami tawarkan adalah sebagai berikut :',
        'ppn_persen' => 12.0,
        'ppn_rumus' => 1,
        'ppn_catatan' => '*PPN 12% Sesuai PMK Nomor 131 Tahun 2024 dengan perhitungan (NILAI SEWA x 11/12 x 12%)',
        'rincian_biaya' => 0,
        'ket' => [
            'Masa sewa selama 1 tahun',
            'Periode {periode}',
            'Harga sewa Rp. 2.800.000 /meter/bulan',
            'Harga belum termasuk biaya listrik',
            'Harga belum termasuk PPN {ppn_persen}',
        ],
        'rincian' => [],
        'fasilitas' => [
            'Standar area pameran',
            'Power listrik',
        ],
        'media' => [
            'Media sosial e-Walk dan Pentacity Mall Balikpapan',
            'Ijin pembagian flyers di area pameran selama periode pameran',
        ],
        'payment' => [
            'Wajib melakukan pembayaran 2 bulan sewa senilai Rp. 60.928.000,- maksimal satu minggu setelah penawaran disetujui dan pelunasan H -7 sebelum pelaksanaan pameran.',
            'Pembayaran 2 bulan sewa tersebut di atas akan digunakan untuk pembayaran sewa 1 bulan pertama dan 1 bulan terakhir.',
            'Wajib melakukan pembayaran Security Deposit (uang jaminan) senilai 1 bulan sewa Rp. 30.464.000,- sebagai jaminan apabila ada kerusakan setelah masa sewa berakhir.',
            'Apabila Penyewa mengakhiri kontrak kerjasama sebelum masa sewa berkahir maka penyewa tetap dibebani dan membayar biaya sewa sejumlah sisa waktu yang belum digunakan dan/atau bersamaan dengan ditandatanganinya Surat Penawaran dan Kontrak, Penyewa menyatakan dengan tegas untuk menyerahkan Security Deposit dimaksud untuk dimiliki Pihak Manajemen Mall.',
            'Security Deposit dibayarkan sekaligus beserta dengan seluruh tagihan sewa dan apabila tidak terjadi kerusakan setelah masa sewa berakhir maka akan dikembalikan ke penyewa 100%.',
            'PT. Wulandari Bangun Laksana',
            'Bukti pembayaran di email ke {EMAIL} atau whatsapp ke {WA}',
        ],
        'terms' => [
            'Penyewa/peserta pameran dilarang menjual produk pameran yang melanggar Hak Cipta, seperti produk bajakan atau barang palsu.',
            'Wajib memberikan atau menyerahkan desain (gambar) booth pameran ke Manajemen sebelum masuk pameran.',
            'Wajib memperhatikan display barang pameran agar tidak keluar dari area/space pameran.',
            'Pemakaian partisi/booth dengan ketinggian max. 2,4 meter (tidak full block/see trough).',
            'Pameran wajib menggunakan alas karpet sebagai alas level atau flooring, sebelum dan sesudah level (disediakan oleh peserta pameran).',
            'Wajib menggunakan tripod banner untuk media promo.',
            'Bersedia mengikuti segala ketentuan dan tata tertib yang berlaku.',
            'Jika penyewa melakukan pengunduran jadwal dari tanggal masa sewa yang tertulis di kontrak maka akan dikenakan biaya Rp 1.000.000,-(Satu Juta Rupiah) di luar dari total harga sewa pameran.',
            'Batas pengunduran jadwal pameran maksimal 1 bulan dari masa sewa yang tertulis di kontrak awal.',
            'Apabila melebihi batas pengunduran pameran maka pameran dianggap batal dan pembayaran yang telah dibayarkan penyewa tidak dapat ditarik kembali.',
            'PPN 12% di tanggung penyewa jika terjadi pembatalan kontrak pameran.',
            'Pengurusan surat keluar masuk di jam operasional kantor (10.00-16.00 Wita), apabila pengurusan diluar jam kerja kantor tidak dilayani dengan alasan apapun.',
            'Data peserta pameran (pribadi/perusahaan) harus sesuai dengan yang diberikan kepada pihak Manajemen Mall, dan apabila kontrak, invoice dan faktur pajak telah terbit, maka data tidak dapat dirubah dengan alasan apapun (kecuali kesalahan penginputan data dari pihak Manajemen e-Walk dan Pentacity Mall Balikpapan).',
            'Apabila terdapat perubahan data untuk pameran selanjutnya, peserta pameran wajib menginfokan perubahan data tersebut kepada pihak Manajemen e-Walk dan Pentacity Mall Balikpapan.',
            'PT. Wulandari Bangun Laksana, Tbk tidak bertanggung jawab atas segala kerusakan maupun kehilangan barang milik penyewa ataupun peserta event, sebelum – sesaat – sesudah berlangsungnya event.',
            'Terhadap kerusakan dan atau kehilangan barang milik PT. Wulandari Bangun Laksana, Tbk yang dapat dibuktikan akibat kelalaian maupun kesengajaan Penyewa atau peserta event maka Penyewa diwajibkan untuk mengganti kerugian yang ditimbulkan.',
            'Untuk pemakaian listrik penyambungan, peserta pameran diwajibkan memakai ukuran kabel NYM 3 x 2,5 mm.',
            'Pemakaian listrik akan dikenakan biaya sesuai pemakaian, dengan tarif Rp 3.150,- /Kwh',
        ],
        'judul' => [
            'fasilitas' => 'Fasilitas yang disediakan e-Walk dan Pentacity Mall Balikpapan',
            'media' => 'Media promosi yang dapat digunakan',
            'pembayaran' => 'Cara Pembayaran',
            'ketentuan' => 'Ketentuan & Persyaratan',
        ],
        'penutup' => 'Untuk keterangan lebih lanjut dapat menghubungi kantor kami {KANTOR} atau whatsapp ke {WA}.',
    ],
    [
        'name' => 'Foodcourt — contoh surat kertas',
        'layout' => 'rincian',
        'gaya_daftar' => 'bullet',
        'perihal' => 'Penawaran Harga Area BSB Foodcourt',
        'intro' => 'Bersama ini kami Manajemen e-Walk dan Pentacity Mall Balikpapan menawarkan Outlet Tenant Foodcourt yang ada di BSB Foodcourt dengan detail sebagai berikut:',
        'ppn_persen' => 11.0,
        'ppn_rumus' => 0,
        'ppn_catatan' => '',
        'rincian_biaya' => 0,
        'ket' => [],
        'rincian' => [
            [
                'label' => 'Lokasi',
                'isi' => 'BSB Foodcourt, Pentacity Shopping Avenue 
@Balikpapan Superblock',
            ],
            [
                'label' => 'Alamat',
                'isi' => 'Jl Jend. Sudirman, Balikapapan, Kalimantan Timur',
            ],
            [
                'label' => 'Area & Ukuran',
                'isi' => 'First Floor, 15 m² (Stand No. 5)',
            ],
            [
                'label' => 'Periode Sewa',
                'isi' => '1 Bulan (1-31 Juli 2026)',
            ],
            [
                'label' => 'Biaya Sewa',
                'isi' => 'Rp. 6.000.000,- /bulan',
            ],
            [
                'label' => 'Service Charge',
                'isi' => 'Rp. 1.732.500,- /bulan 
Service Charge termasuk :
• maintenance gedung
• kebersihan selama jam operational
• keamanan 24 jam
• AC gedung
(biaya Service Charge akan disesuaikan sesuai dengan ketentuan yang berlaku setiap tahunnya)',
            ],
            [
                'label' => 'Biaya Utilities',
                'isi' => 'Biaya Utilities akan diperhitungkan sesuai dengan pemakaian per bulannya. Biaya Utilities termasuk : 
• Air  : Rp 3.500,- /m³
• Listrik : Rp  2.940,- /Kwh',
            ],
            [
                'label' => 'Security Deposit',
                'isi' => 'Rp 10.000.000,- (Sepuluh Juta Rupiah)
• dibayarkan setelah penandatanganan  Surat Konfirmasi, dan pembayaran diselesaikan sebelum stand/booth beroperasi
• akan dikembalikan satu bulan setelah periode kontrak berakhir',
            ],
            [
                'label' => 'Term of Payment',
                'isi' => 'Pembayaran biaya sewa periode kontrak dibayarkan setiap bulannya selama bulan berjalan',
            ],
            [
                'label' => 'Jam Operasional',
                'isi' => 'Senin – Minggu, jam 10.00 – 22.00 WITA 
(mengikuti Jam Operational yang berlaku)',
            ],
            [
                'label' => 'Serah Terima',
                'isi' => '-     Floor 	             : FF – Stand BSB Foodcourt No. 5  
• Electricity 	             : sesuai kebutuhan yang diajukan (tbc)
• Air Conditioning	: central(mengikuti jam operational Mall)
• Sprinkler		: 1 unit 
• Smoke Alarm 	: tbc
• Equipment 	: Working table, Dish Wash Sink, Hood 
                                   Cooker',
            ],
            [
                'label' => 'Facilities',
                'isi' => '• Loading Area 	: Area Loading menggunakan area yang                       
                                   telah disediakan
• Back Up Power 	: Chiller dan Penerangan akan beroperasi  
sesuai dengan jam operational yang telah ditentukan. AC akan beroperasi 60% sesuai dengan jam  operational yang telah ditentukan.',
            ],
            [
                'label' => 'Fit Out Periode',
                'isi' => '• 1 (satu) bulan setelah serah terima area.
• Persetujuan design, perubahan dan schedule kerja harus melalui persetujuan Management Pentacity Shopping Venue, dan berdasarkan standard operational prosedur Interior Fit Out yang berlaku.',
            ],
            [
                'label' => 'Schedule',
                'isi' => '• Serah Terima – Setiap waktu 
• Opening Schedule – 1 (satu) bulan setelah serah terima',
            ],
        ],
        'fasilitas' => [],
        'media' => [],
        'payment' => [],
        'terms' => [],
        'judul' => [],
        'penutup' => 'Untuk keterangan lebih lanjut dapat menghubungi kantor kami {KANTOR} atau {PIC_BESAR} di No. {WA}.',
    ],
];

$J = fn($a) => json_encode($a, JSON_UNESCAPED_UNICODE);
$prop = $pdo->query("SELECT id FROM properties")->fetchAll(PDO::FETCH_COLUMN) ?: [];
$cek  = $pdo->prepare("SELECT id FROM offer_templates WHERE property_id=? AND module='cl' AND name=? LIMIT 1");
$ins  = $pdo->prepare(
    "INSERT INTO offer_templates
      (property_id, module, unit_type, name, is_default, layout, gaya_daftar, ppn_persen, ppn_rumus, ppn_catatan,
       rincian_biaya, perihal, intro, fasilitas_json, media_json, ket_json, rincian_json, judul_json,
       bank_json, penutup, payment_json, terms_json, notes_json, extra_json,
       dp_required, dp_months_default, electricity_default, sort_order, status)
     VALUES (?, 'cl', '', ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '[]', '{}', 1, 2, 150000, 0, 'active')");

// Rekening: dipakai nomor yang selama ini tercetak aplikasi (lihat catatan di atas).
$bank = ['kalimat' => 'Untuk pembayaran dapat ditransfer ke rekening',
         'atas_nama' => 'PT. Wulandari Bangun Laksana',
         'bank' => 'BRI (Bank Rakyat Indonesia)',
         'rekening' => '2078-01-000560-30-4'];

$dibuat = 0;
foreach ($prop as $pid) {
    foreach ($TEMPLATE as $t) {
        $cek->execute([$pid, $t['name']]);
        if ($cek->fetchColumn()) continue;
        $ins->execute([
            $pid, $t['name'], $t['layout'], $t['gaya_daftar'], $t['ppn_persen'], $t['ppn_rumus'], $t['ppn_catatan'],
            $t['rincian_biaya'], $t['perihal'], $t['intro'],
            $J($t['fasilitas']), $J($t['media']), $J($t['ket']), $J($t['rincian']), $J($t['judul']),
            $J($bank), $t['penutup'], $J($t['payment']), $J($t['terms']),
        ]);
        $dibuat++;
    }
}
echo "  template contoh ditambahkan: $dibuat\n";
