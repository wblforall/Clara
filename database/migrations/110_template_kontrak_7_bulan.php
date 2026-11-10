<?php
/**
 * Template Surat Penawaran "Kontrak 7 Bulan" — e-Walk.
 *
 * Disalin dari surat kertas yang dipakai sehari-hari (sewa Juni–Desember 2026,
 * LG e-Walk Pintu Timur). Bentuk & aturannya mengikuti lima template surat
 * kertas yang sudah ada di migrasi 066 — bullet, judul bagian romawi, rekening
 * menyatu di bawah Cara Pembayaran, rupiah gaya kertas, tanpa kotak "berlaku
 * s/d", tanpa tabel Rincian Biaya. Yang berbeda hanya isinya.
 *
 * Empat hal yang SENGAJA tidak disalin mentah-mentah:
 *
 * 1) NAMA CLIENT, NOMINAL, DAN LOKASI. Surat aslinya memuat satu penyewa
 *    beserta angka sewanya. Template dipakai berulang untuk penyewa lain, jadi
 *    nominalnya memakai {dp} dan {deposit} — keduanya diisi dari formulir
 *    penawaran saat surat dicetak. dp_months_default = 2 supaya "2 bulan sewa"
 *    pada surat memang benar 2 bulan.
 *
 * 2) NAMA & NOMOR PENGIRIM. Di kertas tertulis satu nama sales dengan nomor
 *    dan emailnya. Di sini ikut sales pembuat penawaran: {PIC_BESAR} {WA}
 *    {KANTOR}, dan baris "Bukti pembayaran di email ke ..." sudah dicetak
 *    sendiri oleh blok rekening gaya 'menyatu' — tidak perlu diketik lagi
 *    sebagai butir Cara Pembayaran.
 *
 * 3) ANGKA PPN. Kertasnya menulis "PPn 11%", tetapi kolom Keterangan surat itu
 *    sendiri berbunyi "PPn akan menyesuaikan Pemerintah". Jadi dipakai setelan
 *    yang sama dengan template e-Walk lainnya: 12% dengan rumus PMK 131/2024
 *    (nilai x 11/12 x 12%) — rupiahnya PERSIS sama dengan 11% polos, yang
 *    berbeda hanya kalimatnya. Teksnya memakai {ppn_persen} supaya ikut berubah
 *    kalau tarifnya disetel ulang lewat menu Template Penawaran.
 *
 * 4) KOLOM "Harga /M /Bulan" DAN "Periode". Tabel objek surat di CLARA punya
 *    empat kolom (Lokasi, Luas, Harga, Keterangan), sementara kertasnya enam.
 *    Periode pindah jadi bullet Keterangan; tarif per m² per bulan tidak punya
 *    tempat dan memang tidak ada di kolom Keterangan surat aslinya.
 *
 * Hanya untuk e-Walk, dan TIDAK dijadikan template bawaan: yang terpilih
 * otomatis tetap seperti sekarang, supaya kebiasaan kerja sales tidak berubah.
 * Aman diulang — kalau namanya sudah ada, dilewati.
 */

$NAMA = 'Kontrak 7 Bulan';

$pid = (int) ($pdo->query("SELECT id FROM properties WHERE `key` = 'ewalk' LIMIT 1")->fetchColumn() ?: 0);
if ($pid === 0) {
    echo "     ! properti e-Walk tidak ditemukan — template $NAMA dilewati\n";
    return;
}

$cek = $pdo->prepare("SELECT id FROM offer_templates WHERE property_id = ? AND module = 'cl' AND name = ? LIMIT 1");
$cek->execute([$pid, $NAMA]);
if ($cek->fetchColumn()) {
    echo "     template \"$NAMA\" sudah ada — dilewati\n";
    return;
}

$ket = [
    'Masa sewa selama 7 bulan',
    'Periode sewa {periode_panjang}',
    'Harga belum termasuk PPn',
    'Harga belum termasuk biaya listrik',
    'PPn akan menyesuaikan Pemerintah',
];

$fasilitas = [
    'Standar area pameran',
    'Stop kontak listrik',
];

$media = [
    'Media sosial mall',
    'Pembagian flyer di area event',
];

// Butir terakhir ("Untuk pembayaran dapat ditransfer ke rekening") sengaja
// tidak ditulis di sini: blok rekening gaya 'menyatu' mencetaknya sendiri,
// lengkap dengan nama bank, nomor rekening, dan baris bukti pembayaran.
$payment = [
    'Wajib melakukan pembayaran 2 bulan sewa senilai {dp} (Exclude PPn {ppn_persen}) maksimal 1 minggu setelah penawaran di setujui dan pelunasan H-7 sebelum pelaksanaan sewa.',
    'Wajib melakukan pembayaran Security Deposit (uang jaminan) 1 bulan sewa senilai {deposit} sebagai jaminan apabila ada kerusakan dan mengakhiri kontrak kerjasama sebelum masa sewa berakhir.',
    'Apabila Penyewa mengakhiri kontrak sebelum masa sewa, maka Penyewa tetap dibebani membayar biaya sewa sejumlah sisa waktu yang belum digunakannya dan/atau bersamaan dengan ditandatanganinya Surat Penawaran dan Kontrak, Penyewa menyatakan dengan tegas untuk menyerahkan Security Deposit dimaksud untuk dimiliki Pihak Manajemen Mall.',
    'Pembayaran 2 bulan sewa senilai {dp} akan digunakan untuk pembayaran sewa 1 bulan pertama dan sewa 1 bulan terakhir.',
    'Apabila tidak terjadi kerusakan setelah masa sewa berakhir maka Security Deposit dikembalikan ke penyewa 100%.',
];

$terms = [
    'Penyewa / peserta pameran dilarang menjual produk pameran yang melanggar Hak Cipta, seperti produk bajakan atau barang palsu.',
    'Untuk pembersihan peralatan dapat dilakukan di Janitor yang sudah disediakan / ditentukan di area.',
    'Wajib memberikan / menyerahkan design (gambar) booth yang akan digunakan ke pihak Manajemen.',
    'Untuk pemakaian listrik akan dikenakan biaya tambahan sesuai pemakaian.',
    'Bersedia mengikuti segala ketentuan dan tata tertib yang berlaku.',
    'Pemakaian partisi / booth dengan ketinggian max. 1,8 meter (tidak full block).',
    'Pameran wajib menggunakan level kayu (disediakan oleh peserta pameran).',
    'Jika penyewa melakukan pengunduran jadwal dari tanggal masa sewa yang tertulis di kontrak maka akan dikenakan biaya Rp 1.000.000,- (Satu Juta Rupiah) di luar dari total harga sewa pameran.',
    'Batas pengunduran jadwal pameran maksimal 1 bulan dari masa sewa yang tertulis di kontrak awal.',
    'Apabila melebihi batas pengunduran pameran maka pameran dianggap batal dan pembayaran yang telah dibayarkan penyewa tidak dapat ditarik kembali.',
    'PPn {ppn_persen} di tanggung penyewa jika terjadi pembatalan kontrak pameran.',
    'Pengurusan surat keluar masuk di jam operasional kantor (10.00-16.00 WITA), apabila pengurusan diluar jam kerja kantor tidak dilayani dengan alasan apapun.',
    'Data peserta pameran (pribadi/perusahaan) harus sesuai dengan yang diberikan kepada pihak manajemen e-Walk dan Pentacity Mall Balikpapan, dan apabila kontrak, invoice dan faktur pajak telah terbit, maka data tidak dapat dirubah dengan alasan apapun (kecuali kesalahan penginputan data dari pihak manajemen e-Walk dan Pentacity Mall Balikpapan).',
    'Apabila terdapat perubahan data untuk pameran selanjutnya, peserta pameran wajib menginfokan perubahan data tersebut kepada pihak manajemen e-Walk dan Pentacity Mall Balikpapan.',
    'Untuk pemakaian listrik penyambungan, peserta pameran diwajibkan memakai ukuran kabel NYM 3 x 2,5 mm.',
    'Untuk pemakaian listrik akan dikenakan biaya Rp 3.150,- /kwh.',
];

// Judul kolom mengikuti kertasnya: kolom harganya berisi sewa PER BULAN
// (harga_tampil = 'bulan'), bukan total periode.
$judul = [
    'kolom_lokasi' => 'Lokasi',
    'kolom_luas'   => 'Luas',
    'kolom_harga'  => 'Total Sewa /Bulan',
    'kolom_ket'    => 'Keterangan',
    'fasilitas'    => 'Fasilitas yang disediakan e-Walk Mall Balikpapan',
    'media'        => 'Media promosi yang dapat digunakan',
    'pembayaran'   => 'Cara Pembayaran',
    'ketentuan'    => 'Ketentuan & Persyaratan',
];

// Nomor rekening e-Walk dengan penulisan baku (lihat migrasi 104).
$bank = [
    'kalimat'   => 'Untuk pembayaran dapat ditransfer ke rekening',
    'atas_nama' => 'PT. Wulandari Bangun Laksana',
    'bank'      => 'Bank Rakyat Indonesia (BRI)',
    'rekening'  => '2078-01-0005600304',
];

$J = fn($a) => json_encode($a, JSON_UNESCAPED_UNICODE);

$ins = $pdo->prepare(
    "INSERT INTO offer_templates
      (property_id, module, unit_type, name, is_default, layout, gaya_daftar, gaya_judul, gaya_bank, gaya_uang,
       harga_tampil, penutup_akhir, ttd_kanan, tampil_berlaku, tampil_qr, ppn_persen, ppn_rumus, ppn_catatan,
       rincian_biaya, perihal, intro, fasilitas_json, media_json, ket_json, rincian_json, judul_json,
       bank_json, penutup, payment_json, terms_json, notes_json, extra_json,
       dp_required, dp_months_default, electricity_default, sort_order, status)
     VALUES (?, 'cl', '', ?, 0, 'tabel', 'bullet', 'romawi', 'menyatu', 'kertas',
             'bulan', ?, '', 0, 1, 12.00, 1, ?,
             0, ?, ?, ?, ?, ?, '[]', ?,
             ?, ?, ?, ?, '[]', '{}',
             1, 2, 150000, 0, 'active')"
);

$ins->execute([
    $pid,
    $NAMA,
    'Demikian surat penawaran ini kami buat, atas perhatian dan kerjasamanya kami ucapkan terimakasih.',
    '*PPN 12% Sesuai PMK Nomor 131 Tahun 2024 dengan perhitungan (NILAI SEWA x 11/12 x 12%)',
    'Surat Penawaran Sewa Kontrak 7 Bulan',
    'Bersama ini kami Management e-Walk dan Pentacity Mall Balikpapan menawarkan space exhibition yang ada di area Gedung. Space exhibition yang kami tawarkan adalah sebagai berikut :',
    $J($fasilitas),
    $J($media),
    $J($ket),
    $J($judul),
    $J($bank),
    'Untuk keterangan lebih lanjut dapat menghubungi kantor kami {KANTOR} atau {PIC_BESAR} di nomor {WA}.',
    $J($payment),
    $J($terms),
]);

echo "     template \"$NAMA\" ditambahkan untuk e-Walk (#" . $pdo->lastInsertId() . ")\n";
