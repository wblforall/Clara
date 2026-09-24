<?php
// Template dokumen dipisah per MODUL: Exhibition (cl), Media, Gudang.
//
// Sebelumnya satu template hanya dibedakan per tipe unit Exhibition, sehingga
// Gudang & Media ikut memakai template "Pameran Umum" — perihalnya pun "Sewa
// Area Pameran". Sekarang resolusinya: (properti, modul, tipe unit) → (properti,
// modul, default) → (properti, cl, default).
//
// Dua kolom isi baru supaya format Gudang & Media bisa dipenuhi TANPA koding:
//   notes_json  — catatan kaki surat (catatan PPN, nomor rekening, tembusan)
//   extra_json  — blok khusus modul (daftar utilities & media promo utk Media,
//                 contoh bullet kolom Keterangan utk Gudang)
//
// Isi seed Gudang diambil dari "Surat Konfirmasi Sewa Gudang Iforte.docx" dan
// Media dari form "FORM UTILITIES CASUAL LEASING" — semuanya bisa diedit user
// lewat menu Template Penawaran.

$cols = array_column($pdo->query('SHOW COLUMNS FROM offer_templates')->fetchAll(), 'Field');

if (!in_array('module', $cols, true)) {
    $pdo->exec("ALTER TABLE offer_templates ADD COLUMN module VARCHAR(10) NOT NULL DEFAULT 'cl' AFTER property_id");
}
if (!in_array('notes_json', $cols, true)) {
    $pdo->exec('ALTER TABLE offer_templates ADD COLUMN notes_json TEXT NULL AFTER terms_json');
}
if (!in_array('extra_json', $cols, true)) {
    $pdo->exec('ALTER TABLE offer_templates ADD COLUMN extra_json TEXT NULL AFTER notes_json');
}

// Kunci unik ikut modul — satu tipe unit boleh punya template di tiap modul.
$idx = $pdo->query('SHOW INDEX FROM offer_templates')->fetchAll(PDO::FETCH_ASSOC);
$names = array_column($idx, 'Key_name');
if (in_array('uq_prop_type', $names, true)) {
    $pdo->exec('ALTER TABLE offer_templates DROP INDEX uq_prop_type');
}
if (!in_array('uq_prop_mod_type', $names, true)) {
    $pdo->exec('ALTER TABLE offer_templates ADD UNIQUE KEY uq_prop_mod_type (property_id, module, unit_type)');
}

// ── Seed: Gudang ─────────────────────────────────────────────────────────────
$gudangTerms = [
    'Luas area sewa akan diukur ulang bersama di lapangan dengan pihak Pengelola Mall.',
    'Biaya Sewa wajib dibayarkan tepat waktu berdasarkan invoice dengan periode serta harga sebagaimana tercantum di atas.',
    'Apabila terdapat kerusakan pada area yang disewa maupun bagian daripadanya, maka menjadi tanggung jawab tenant yang bersangkutan untuk memperbaikinya, termasuk namun tidak terbatas pada tanggung jawab tenant atas kebersihan dan keamanan area gudang dan sekitarnya.',
    'Tenant wajib mengasuransikan sendiri barang dagangan dan perlengkapan miliknya yang berada di Gudang atau Gedung Mall terhadap bahaya kebakaran, kehilangan atau kerusakan oleh sebab apapun atas biaya sendiri dan Pihak Pertama tidak bertanggung jawab terhadap risiko apapun atas barang dagangan dan perlengkapan Pihak Kedua di Gudang atau di dalam Gedung Mall.',
    'Penggunaan daya listrik akan dikenakan biaya sesuai dengan pemakaian, dengan tarif Rp 3.150/Kwh.',
    'Penyewa wajib menyediakan Alat Pemadam Api Ringan (APAR) di dalam area Gudang yang disewa.',
    'Barang-barang yang mudah terbakar atau yang dapat menyebabkan kebakaran tidak diperuntukkan untuk ditempatkan di dalam Gudang.',
    'Syarat dan ketentuan lainnya akan dituangkan di dalam Perjanjian Sewa Menyewa Gudang.',
];
$gudangNotes = [
    '*PPN 12% sesuai PMK Nomor 131 Tahun 2024 dengan perhitungan (Nilai Sewa X 11/12 X 12%)',
    'Pembayaran dapat di transfer ke Bank Rakyat Indonesia No. Rek. 2078-01-999999-56-3 a/n PT Wulandari Bangun Laksana.',
];
$gudangExtra = [
    // Contoh bullet untuk kolom "Keterangan" pada tabel harga — bisa diubah per dokumen.
    'keterangan_default' => [
        'Masa Sewa {hari} Hari',
        'Periode sewa {periode}',
        'Harga sudah termasuk harga paket',
        'Harga untuk 1 ruangan gudang kering',
        'Harga sewa belum termasuk biaya listrik',
        'Harga sewa belum termasuk PPN 12%',
    ],
];

// ── Seed: Media (Form Utilities) ─────────────────────────────────────────────
$mediaTerms = [
    'Tembusan File : Tenant/Perusahaan, Arsip Marketing, Operation, Finance, Arsip Promosi, TR',
    'Pemasangan dan pelepasan media promosi oleh pihak penyewa/tenant',
    'Mohon untuk pemasangan dan pelepasan media promosi sesuai dengan tanggal pelaksanaan',
];
$mediaNotes = [
    '*PPN 12% Sesuai PMK Nomor 131 Tahun 2024 dengan perhitungan (nilai sewa x 11/12 x 12%)',
    'Pembayaran ditransfer ke PT. WULANDARI BANGUN LAKSANA — BRI (Bank Rakyat Indonesia), Nomor Rekening 2078-01-999999-56-3. (Bukti Transfer Pembayaran Dilampirkan)',
];
$mediaExtra = [
    'utilities'   => ['Listrik', 'Sound & Lighting', 'LED Screen', 'Stage', 'Kebersihan', 'Air'],
    'media_promo' => ['T Banner', 'Hanging Banner', 'Sticker Lift', 'Wrapping Coloum', 'Neon Box', 'Wall Sign', 'LED', 'TVC', 'Lainnya'],
    'parkir'      => ['Mobil', 'Motor'],
];

$J = fn($a) => json_encode($a, JSON_UNESCAPED_UNICODE);

$ins = $pdo->prepare(
    "INSERT IGNORE INTO offer_templates
        (property_id, module, unit_type, name, perihal, intro, fasilitas_json, payment_json,
         terms_json, notes_json, extra_json, dp_required, dp_months_default, sort_order)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
);

$pids = $pdo->query("SELECT id FROM properties WHERE status='active' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
if (!$pids) $pids = $pdo->query('SELECT id FROM properties ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

foreach ($pids as $pid) {
    $pid = (int) $pid;

    $ins->execute([
        $pid, 'gudang', '', 'Sewa Gudang (default)',
        'Surat Konfirmasi Sewa Gudang',
        'Sebagai penyewa, mengetahui dan menyetujui Peraturan Sewa Gudang serta Rincian Harga Sewa Gudang yang akan dibebankan selama dan untuk keperluan penyimpanan barang-barang tenant kami sesuai dengan ketentuan Managemen Mall dan kebutuhan yang kami ajukan.',
        $J([]), $J([]), $J($gudangTerms), $J($gudangNotes), $J($gudangExtra), 0, 0, 0,
    ]);

    $ins->execute([
        $pid, 'media', '', 'Form Utilities (default)',
        'Form Utilities Casual Leasing',
        '',
        $J([]), $J([]), $J($mediaTerms), $J($mediaNotes), $J($mediaExtra), 0, 0, 0,
    ]);
}
