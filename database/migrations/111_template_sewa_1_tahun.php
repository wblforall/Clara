<?php
/**
 * Template "Penawaran Harga Sewa 1 Tahun" — e-Walk DAN Pentacity.
 *
 * Menggantikan template sewa setahun yang lama (disemai migrasi 066 dengan nama
 * "FuniFun!"). Isinya disalin dari surat 110/QT-CL/PSV/BSB-BPN/VIII/2026, yang
 * oleh pemilik CLARA ditunjuk sebagai bentuk yang benar. Yang lama salah di tiga
 * tempat sekaligus, dan ketiganya memang tidak bisa dibetulkan sebagian:
 *
 * 1) NOMINAL DIPAKU. Butir Cara Pembayarannya menuliskan Rp 60.928.000 dan
 *    Rp 30.464.000 — angka milik SATU penyewa, tercetak untuk semua penyewa
 *    berikutnya. Sekarang {dp} dan {deposit}, diisi dari formulir penawaran.
 *
 * 2) DUA BUTIR SAMPAH. "PT. Wulandari Bangun Laksana" dan "Bukti pembayaran di
 *    email ke …" ditulis sebagai butir Cara Pembayaran, padahal blok rekening
 *    gaya 'menyatu' sudah mencetak keduanya sendiri — jadi tercetak dua kali.
 *
 * 3) KOLOM HARGA SALAH ARTI. Judul kolomnya "HARGA SEWA PER BULAN" tetapi
 *    isinya total seluruh periode (harga_tampil = 'periode'). Pada kontrak
 *    setahun selisihnya dua belas kali lipat.
 *
 * Yang berbeda antar properti HANYA nomor rekeningnya:
 *
 *   - e-Walk    : 2078-01-0005600304 (lihat migrasi 104 — penulisan baku).
 *   - Pentacity : 2078-01-999999-56-3, nomor yang tertera di surat sumbernya
 *                 dan dinyatakan pemilik CLARA sebagai rekening Pentacity.
 *                 CATATAN: migrasi 104 dulu menganggap nomor ini contoh belaka
 *                 dan sengaja tidak menyentuh Pentacity. Keterangan pemiliklah
 *                 yang dipakai sekarang; tetap layak dicek sekali ke Finance
 *                 sebelum surat pertama terkirim.
 *
 * Dua butir terakhir Cara Pembayaran (pengembalian Security Deposit 14 hari
 * kerja, dan PPh Final Pasal 4 ayat (2)) TIDAK ada di surat sumbernya —
 * diminta langsung oleh pemilik CLARA untuk ditambahkan.
 *
 * Penawaran yang sudah terbit tidak ikut berubah: isi suratnya dibekukan di
 * offers.letter_json saat disimpan.
 */

$NAMA = 'Penawaran Harga Sewa 1 Tahun';

// Nama-nama yang pernah dipakai untuk template sewa setahun. Yang ketemu
// diperbarui; kalau ada lebih dari satu, sisanya dinonaktifkan supaya daftar
// pilihan sales tidak memuat dua template untuk satu hal yang sama.
$NAMA_LAMA = ['Penawaran Harga Sewa 1 Tahun', 'FuniFun!', 'FuniFun! — contoh surat kertas',
              'Kontrak 1 Tahun', 'Sewa 1 Tahun'];

$fasilitas = [
    'Standar area pameran',
    'Power listrik',
];

$media = [
    'Media sosial **e-Walk dan Pentacity Mall Balikpapan**',
    'Ijin pembagian flyers di area pameran selama periode pameran',
];

// {tarif} = tarif per satuan dari formulir (mis. Rp. 2.800.000,- per m²/bulan).
// Di surat kertas angka ini diketik tangan; sekali template dipakai penyewa
// lain, angka ketikan itu jadi salah tanpa ada yang menyadarinya.
$ket = [
    'Masa sewa selama 1 tahun',
    'Periode {periode_panjang}',
    'Harga sewa {tarif} /meter/bulan',
    'Harga belum termasuk biaya listrik',
    'Harga belum termasuk PPN {ppn_persen}',
];

$payment = [
    'Wajib melakukan pembayaran 2 bulan sewa senilai **{dp}** maksimal satu minggu setelah penawaran disetujui dan pelunasan H -7 sebelum pelaksanaan pameran.',
    'Pembayaran 2 bulan sewa tersebut di atas akan digunakan untuk pembayaran sewa 1 bulan pertama dan 1 bulan terakhir.',
    'Wajib melakukan pembayaran **Security Deposit** (uang jaminan) senilai 1 bulan sewa **{deposit}** sebagai jaminan apabila ada kerusakan setelah masa sewa berakhir.',
    'Apabila Penyewa mengakhiri kontrak kerjasama sebelum masa sewa berakhir maka penyewa tetap dibebani dan membayar biaya sewa sejumlah sisa waktu yang belum digunakan dan/atau bersamaan dengan ditandatanganinya Surat Penawaran dan Kontrak, Penyewa menyatakan dengan tegas untuk menyerahkan Security Deposit dimaksud untuk dimiliki Pihak Manajemen Mall.',
    '**Security Deposit** dibayarkan sekaligus beserta dengan seluruh tagihan sewa dan apabila tidak terjadi kerusakan setelah masa sewa berakhir maka akan dikembalikan ke penyewa 100%.',
    'Apabila tidak terjadi kerusakan setelah masa sewa berakhir sesuai jangka waktu yang disepakati di awal, maka Security Deposit dikembalikan ke penyewa 100%. Security Deposit sebagaimana dimaksud akan dikembalikan ke Penyewa dalam waktu minimal **14 (empat belas)** hari kerja.',
    'Apabila Penyewa Pengusaha Kena Pajak wajib melakukan pemotongan PPh Final Pasal 4 ayat (2) dan Penyewa wajib menyerahkan bukti pemotongan tersebut kepada Pihak Manajemen Mall paling lambat 30 (tiga puluh) hari kalender setelah menerima bukti pemotongan dari instansi terkait.',
];

$terms = [
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
    'PPN {ppn_persen} di tanggung penyewa jika terjadi pembatalan kontrak pameran.',
    'Pengurusan surat keluar masuk di jam operasional kantor (10.00-16.00 Wita), apabila pengurusan diluar jam kerja kantor tidak dilayani dengan alasan apapun.',
    'Data peserta pameran (pribadi/perusahaan) harus sesuai dengan yang diberikan kepada pihak Manajemen Mall, dan apabila kontrak, invoice dan faktur pajak telah terbit, maka data tidak dapat dirubah dengan alasan apapun (kecuali kesalahan penginputan data dari pihak Manajemen **e-Walk dan Pentacity Mall Balikpapan**).',
    'Apabila terdapat perubahan data untuk pameran selanjutnya, peserta pameran wajib menginfokan perubahan data tersebut kepada pihak Manajemen **e-Walk dan Pentacity Mall Balikpapan**.',
    'PT. Wulandari Bangun Laksana, Tbk tidak bertanggung jawab atas segala kerusakan maupun kehilangan barang milik penyewa ataupun peserta event, sebelum – sesaat – sesudah berlangsungnya event.',
    'Terhadap kerusakan dan atau kehilangan barang milik PT. Wulandari Bangun Laksana, Tbk yang dapat dibuktikan akibat kelalaian maupun kesengajaan Penyewa atau peserta event maka Penyewa diwajibkan untuk mengganti kerugian yang ditimbulkan.',
    'Untuk pemakaian listrik penyambungan, peserta pameran diwajibkan memakai ukuran kabel NYM 3 x 2,5 mm.',
    'Pemakaian listrik akan dikenakan biaya sesuai pemakaian, dengan tarif Rp 3.150,- /Kwh',
];

$judul = [
    'kolom_lokasi' => 'LOKASI',
    'kolom_luas'   => 'UKURAN',
    'kolom_harga'  => 'HARGA SEWA PER BULAN',
    'kolom_ket'    => 'KETERANGAN',
    'fasilitas'    => 'Fasilitas yang disediakan e-Walk dan Pentacity Mall Balikpapan',
    'media'        => 'Media promosi yang dapat digunakan',
    'pembayaran'   => 'Cara Pembayaran',
    'ketentuan'    => 'Ketentuan & Persyaratan',
];

$REKENING = ['ewalk' => '2078-01-0005600304', 'pentacity' => '2078-01-999999-56-3'];

$ISI = [
    'perihal'        => 'Penawaran Harga Sewa 1 Tahun',
    'intro'          => 'Bersama ini kami Manajemen **e-Walk dan Pentacity Mall Balikpapan** menawarkan space exhibition yang ada di area gedung. Space exhibition yang kami tawarkan adalah sebagai berikut :',
    'layout'         => 'tabel',
    'gaya_daftar'    => 'bullet',
    'gaya_judul'     => 'romawi',
    'gaya_bank'      => 'menyatu',
    'gaya_uang'      => 'kertas',
    'harga_tampil'   => 'bulan',
    'ppn_persen'     => 12.00,
    'ppn_rumus'      => 1,
    'ppn_catatan'    => '*PPN 12% Sesuai PMK Nomor 131 Tahun 2024 dengan perhitungan (NILAI SEWA x 11/12 x 12%)',
    'rincian_biaya'  => 0,
    'tampil_berlaku' => 0,
    'tampil_qr'      => 1,
    'ttd_kanan'      => '',
    'penutup'        => 'Untuk keterangan lebih lanjut dapat menghubungi kantor kami **{KANTOR}** atau whatsapp ke **{WA}**.',
    'penutup_akhir'  => 'Demikian surat penawaran ini kami buat, atas perhatian dan kerjasamanya kami ucapkan terimakasih.',
    'dp_required'    => 1,
    'dp_months_default' => 2,
];

$J = fn($a) => json_encode($a, JSON_UNESCAPED_UNICODE);

$KOLOM = array_keys($ISI);
$SET   = implode(', ', array_map(fn($k) => "`$k` = ?", $KOLOM))
       . ", `name` = ?, `fasilitas_json` = ?, `media_json` = ?, `ket_json` = ?, `judul_json` = ?,"
       . "  `bank_json` = ?, `payment_json` = ?, `terms_json` = ?, `rincian_json` = '[]',"
       . "  `notes_json` = '[]', `extra_json` = '{}', `status` = 'active', `updated_at` = NOW()";

$upd = $pdo->prepare("UPDATE `offer_templates` SET $SET WHERE id = ?");
$ins = $pdo->prepare(
    "INSERT INTO `offer_templates`
       (`property_id`, `module`, `unit_type`, `is_default`, `electricity_default`, `sort_order`,
        " . implode(', ', array_map(fn($k) => "`$k`", $KOLOM)) . ",
        `name`, `fasilitas_json`, `media_json`, `ket_json`, `judul_json`,
        `bank_json`, `payment_json`, `terms_json`, `rincian_json`,
        `notes_json`, `extra_json`, `status`, `updated_at`)
     VALUES (?, 'cl', '', 0, 150000, 0,
        " . implode(', ', array_fill(0, count($KOLOM), '?')) . ",
        ?, ?, ?, ?, ?, ?, ?, ?, '[]', '[]', '{}', 'active', NOW())"
);

$cari = $pdo->prepare(
    "SELECT id, name FROM `offer_templates`
      WHERE property_id = ? AND module = 'cl'
        AND name IN (" . implode(',', array_fill(0, count($NAMA_LAMA), '?')) . ")
      ORDER BY (name = ?) DESC, id ASC"
);
$matikan = $pdo->prepare("UPDATE `offer_templates` SET `status` = 'inactive', `updated_at` = NOW() WHERE id = ?");

$prop = $pdo->query("SELECT id, `key`, name FROM properties ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

foreach ($prop as $p) {
    $pid = (int) $p['id'];
    $rek = $REKENING[(string) $p['key']] ?? '';
    if ($rek === '') {
        echo "     ! properti {$p['name']} belum punya nomor rekening — dilewati\n";
        continue;
    }

    $bank = ['kalimat'   => 'Untuk pembayaran dapat ditransfer ke rekening',
             'atas_nama' => 'PT. Wulandari Bangun Laksana',
             'bank'      => 'BRI (Bank Rakyat Indonesia)',
             'rekening'  => $rek];

    $sisa = array_values($ISI);
    $sisa[] = $NAMA;
    $sisa[] = $J($fasilitas);
    $sisa[] = $J($media);
    $sisa[] = $J($ket);
    $sisa[] = $J($judul);
    $sisa[] = $J($bank);
    $sisa[] = $J($payment);
    $sisa[] = $J($terms);

    $cari->execute(array_merge([$pid], $NAMA_LAMA, [$NAMA]));
    $ada = $cari->fetchAll(PDO::FETCH_ASSOC);

    if ($ada) {
        $pakai = array_shift($ada);
        $upd->execute(array_merge($sisa, [(int) $pakai['id']]));
        echo "     {$p['name']}: #{$pakai['id']} \"{$pakai['name']}\" → \"$NAMA\"\n";
        foreach ($ada as $kembar) {
            $matikan->execute([(int) $kembar['id']]);
            echo "     {$p['name']}: #{$kembar['id']} \"{$kembar['name']}\" dinonaktifkan (kembar)\n";
        }
    } else {
        $ins->execute(array_merge([$pid], $sisa));
        echo "     {$p['name']}: \"$NAMA\" dibuat baru (#" . $pdo->lastInsertId() . ")\n";
    }
}
