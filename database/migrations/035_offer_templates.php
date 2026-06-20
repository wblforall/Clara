<?php
// Template Surat Penawaran per jenis booth (unit_type). Isi surat (perihal,
// intro, fasilitas, cara pembayaran, ketentuan) + aturan bayar (dp_required,
// dp_months_default) berbeda per tipe unit — lihat 2 sampel "SP FUNtastic
// Market" (Fashion vs Food Stall). Resolusi: (property, unit_type) → default
// properti (unit_type='') → fallback kode. Isi di-snapshot ke offers.letter_json
// saat simpan agar surat terbit tak berubah walau template diedit.
// Placeholder didukung saat render: {dp} {deposit} {total} {ppn} {grand}.

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

if (!in_array('offer_templates', $tables, true)) {
    $pdo->exec(
        "CREATE TABLE offer_templates (
            id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            property_id       TINYINT UNSIGNED NOT NULL,
            unit_type         VARCHAR(80) NOT NULL DEFAULT '',   -- '' = default properti
            name              VARCHAR(120) NOT NULL,
            perihal           VARCHAR(255) NULL,
            intro             TEXT NULL,
            fasilitas_json    TEXT NULL,
            payment_json      TEXT NULL,
            terms_json        TEXT NULL,
            dp_required       TINYINT(1) NOT NULL DEFAULT 1,
            dp_months_default DECIMAL(5,2) NOT NULL DEFAULT 2,
            sort_order        INT NOT NULL DEFAULT 0,
            status            VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        DATETIME NULL,
            UNIQUE KEY uq_prop_type (property_id, unit_type),
            KEY idx_prop (property_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

// offers.letter_json — snapshot isi surat (template ter-resolve) saat simpan.
$offCols = array_column($pdo->query('SHOW COLUMNS FROM offers')->fetchAll(), 'Field');
if (!in_array('letter_json', $offCols, true)) {
    $pdo->exec('ALTER TABLE offers ADD COLUMN letter_json MEDIUMTEXT NULL');
}

// ─── Konten seed ─────────────────────────────────────────────────────────────
$intro = 'Bersama ini kami Management e-Walk dan Pentacity Mall Balikpapan menawarkan space exhibition yang ada di area Gedung sebagai berikut:';

$fasilitasUmum   = ['Standar area pameran', 'Stop kontak listrik', 'Media promosi: media sosial mall & pembagian flyer di area event'];
$fasilitasFood   = ['Standar area food stall', 'Stop kontak listrik', 'Media promosi: media sosial mall & pembagian flyer di area event'];

// Cara pembayaran — {dp}/{deposit} diganti nominal saat render.
$payUmum = [
    'Wajib membayar biaya sewa (DP) senilai {dp} (Exc. PPN 12%) maksimal 1 minggu setelah penawaran disetujui, dan pelunasan paling lambat H-7 sebelum pelaksanaan sewa.',
    'Wajib membayar Security Deposit (uang jaminan) senilai {deposit} sebagai jaminan kerusakan / pengakhiran kontrak sebelum masa sewa berakhir.',
    'Apabila tidak terjadi kerusakan setelah masa sewa berakhir, Security Deposit dikembalikan 100%.',
];
$payDepositOnly = [
    'Wajib melakukan pembayaran Security Deposit (uang jaminan) senilai {deposit} sebagai jaminan apabila ada kerusakan.',
    'Apabila terjadi kerusakan dengan nominal melebihi nominal Security Deposit, maka Manajemen Mall berhak menagihkan kepada pihak penyewa sesuai nominal kekurangan sisanya.',
    'Apabila tidak terjadi kerusakan setelah masa sewa berakhir maka Security Deposit dikembalikan ke penyewa 100%.',
];

// Ketentuan default (sistem lama) — pameran booth terbuka.
$termsUmum = [
    'Penyewa / peserta pameran dilarang menjual produk yang melanggar Hak Cipta, seperti produk bajakan atau barang palsu.',
    'Wajib menyerahkan design (gambar) booth yang akan digunakan ke pihak Manajemen.',
    'Untuk pemakaian listrik dikenakan sesuai pemakaian dengan harga Rp 3.150/Kwh.',
    'Pemakaian partisi / booth dengan ketinggian max. 1,8 meter (see through / tidak full block).',
    'Pameran wajib menggunakan level kayu dan karpet (disediakan oleh peserta pameran).',
    'Jika penyewa mengundurkan jadwal dari tanggal masa sewa di kontrak, dikenakan biaya Rp 1.000.000,- di luar total harga sewa.',
    'Batas pengunduran jadwal pameran maksimal 1 bulan dari masa sewa di kontrak awal.',
    'Apabila melebihi batas pengunduran, pameran dianggap batal dan pembayaran tidak dapat ditarik kembali.',
    'PPN 11% ditanggung penyewa jika terjadi pembatalan kontrak pameran.',
    'Pengurusan surat keluar masuk di jam operasional kantor (10.00–16.00 WITA).',
    'Data peserta pameran harus sesuai dengan yang diberikan ke manajemen; setelah kontrak/invoice/faktur pajak terbit, data tidak dapat dirubah (kecuali kesalahan input dari manajemen).',
    'Perubahan data untuk pameran selanjutnya wajib diinfokan ke manajemen e-Walk dan Pentacity Mall Balikpapan.',
    'Pemakaian listrik penyambungan wajib memakai kabel NYM 3 x 2,5 mm.',
    'Bersedia mengikuti segala ketentuan dan tata tertib yang berlaku.',
];

// Ketentuan Fashion Booth (dari SP FUNtastic – Fashion): listrik flat 150rb/bln.
$termsFashion = [
    'Penyewa / peserta pameran dilarang menjual produk pameran yang melanggar Hak Cipta, seperti produk bajakan atau barang palsu.',
    'Booth untuk penyewa disediakan oleh Manajemen Mall; dengan diserahterimakannya booth tersebut, pihak penyewa bertanggung jawab untuk menjaga booth tersebut.',
    'Wajib memberikan / menyerahkan design (gambar) display yang akan digunakan ke pihak Manajemen.',
    'Untuk pemakaian listrik akan dikenakan biaya sebesar Rp 150.000/bulan belum termasuk PPN 12%.',
    'Jika penyewa melakukan pengunduran jadwal dari tanggal masa sewa yang tertulis di kontrak maka akan dikenakan biaya Rp 1.000.000,- (Satu Juta Rupiah) di luar dari total harga sewa pameran.',
    'Batas pengunduran jadwal pameran maksimal 1 bulan dari masa sewa yang tertulis di kontrak awal.',
    'Apabila melebihi batas pengunduran pameran maka pameran dianggap batal dan pembayaran yang telah dibayarkan penyewa tidak dapat ditarik kembali.',
    'PPN 12% ditanggung penyewa jika terjadi pembatalan kontrak pameran.',
    'Pengurusan surat keluar masuk di jam operasional kantor (10.00–16.00 WITA), apabila pengurusan diluar jam kerja kantor tidak dilayani dengan alasan apapun.',
    'Data peserta pameran (pribadi/perusahaan) harus sesuai dengan yang diberikan kepada pihak manajemen e-Walk dan Pentacity Mall Balikpapan; apabila kontrak, invoice dan faktur pajak telah terbit, maka data tidak dapat dirubah dengan alasan apapun (kecuali kesalahan penginputan data dari pihak manajemen).',
    'Apabila terdapat perubahan data untuk pameran selanjutnya, peserta pameran wajib menginfokan perubahan data tersebut kepada pihak manajemen e-Walk dan Pentacity Mall Balikpapan.',
    'Untuk pemakaian listrik penyambungan, peserta pameran diwajibkan memakai ukuran kabel NYM 3 x 2,5 mm.',
    'Bersedia mengikuti segala ketentuan dan tata tertib yang berlaku.',
];

// Ketentuan Food Stall (dari SP FUNtastic – Food Stall): + APAR, listrik per Kwh.
$termsFood = [
    'Penyewa / peserta pameran dilarang menjual produk pameran yang melanggar Hak Cipta, seperti produk bajakan atau barang palsu.',
    'Booth untuk penyewa disediakan oleh Manajemen Mall; dengan diserahterimakannya booth tersebut, pihak penyewa bertanggung jawab untuk menjaga booth tersebut.',
    'Wajib memberikan / menyerahkan design (gambar) display yang akan digunakan ke pihak Manajemen.',
    'Penyewa wajib menyediakan Alat Pemadam Api Ringan (APAR) di dalam area Food Stall yang disewa.',
    'Penggunaan daya listrik akan dikenakan biaya sesuai pemakaian dengan tarif Rp 3.150/Kwh.',
    'Jika penyewa melakukan pengunduran jadwal dari tanggal masa sewa yang tertulis di kontrak maka akan dikenakan biaya Rp 1.000.000,- (Satu Juta Rupiah) di luar dari total harga sewa pameran.',
    'Batas pengunduran jadwal pameran maksimal 1 bulan dari masa sewa yang tertulis di kontrak awal.',
    'Apabila melebihi batas pengunduran pameran maka pameran dianggap batal dan pembayaran yang telah dibayarkan penyewa tidak dapat ditarik kembali.',
    'PPN 12% ditanggung penyewa jika terjadi pembatalan kontrak pameran.',
    'Pengurusan surat keluar masuk di jam operasional kantor (10.00–16.00 WITA), apabila pengurusan diluar jam kerja kantor tidak dilayani dengan alasan apapun.',
    'Data peserta pameran (pribadi/perusahaan) harus sesuai dengan yang diberikan kepada pihak manajemen e-Walk dan Pentacity Mall Balikpapan; apabila kontrak, invoice dan faktur pajak telah terbit, maka data tidak dapat dirubah dengan alasan apapun (kecuali kesalahan penginputan data dari pihak manajemen).',
    'Apabila terdapat perubahan data untuk pameran selanjutnya, peserta pameran wajib menginfokan perubahan data tersebut kepada pihak manajemen e-Walk dan Pentacity Mall Balikpapan.',
    'Untuk pemakaian listrik penyambungan, peserta pameran diwajibkan memakai ukuran kabel NYM 3 x 2,5 mm.',
    'Bersedia mengikuti segala ketentuan dan tata tertib yang berlaku.',
];

$J = fn($a) => json_encode($a, JSON_UNESCAPED_UNICODE);

$ins = $pdo->prepare(
    "INSERT IGNORE INTO offer_templates
        (property_id, unit_type, name, perihal, intro, fasilitas_json, payment_json, terms_json, dp_required, dp_months_default, sort_order)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)"
);

// Default per properti (yang punya unit CL); fallback semua properti.
$pids = $pdo->query('SELECT DISTINCT property_id FROM master_cl_units')->fetchAll(PDO::FETCH_COLUMN);
if (!$pids) $pids = $pdo->query('SELECT id FROM properties')->fetchAll(PDO::FETCH_COLUMN);

foreach ($pids as $pid) {
    $pid = (int) $pid;
    // Default (unit_type='')
    $ins->execute([$pid, '', 'Pameran Umum (default)', 'Surat Penawaran Sewa Area Pameran', $intro,
        $J($fasilitasUmum), $J($payUmum), $J($termsUmum), 1, 2, 0]);

    // Fashion Booth — hanya bila properti punya unit tipe ini.
    $hasFashion = $pdo->prepare('SELECT 1 FROM master_cl_units WHERE property_id=? AND unit_type=? LIMIT 1');
    $hasFashion->execute([$pid, 'Fashion Booth']);
    if ($hasFashion->fetchColumn()) {
        $ins->execute([$pid, 'Fashion Booth', 'Fashion Booth', 'Surat Penawaran Sewa Fashion Booth', $intro,
            $J($fasilitasUmum), $J($payDepositOnly), $J($termsFashion), 0, 0, 1]);
    }
    // Food Stall
    $hasFood = $pdo->prepare('SELECT 1 FROM master_cl_units WHERE property_id=? AND unit_type=? LIMIT 1');
    $hasFood->execute([$pid, 'Food Stall']);
    if ($hasFood->fetchColumn()) {
        $ins->execute([$pid, 'Food Stall', 'Food Stall', 'Surat Penawaran Sewa Food Stall', $intro,
            $J($fasilitasFood), $J($payDepositOnly), $J($termsFood), 0, 0, 2]);
    }
}
