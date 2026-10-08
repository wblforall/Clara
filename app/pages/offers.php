<?php
// ─── Surat Penawaran (Quotation) ─────────────────────────────────────────────
// Titik masuk baru CLARA (offer-first). 1 penawaran = 1 unit. Bisa direvisi N
// kali; tiap revisi disimpan → jumlah nego. Saat DEAL → dasar Dokumen Konfirmasi
// (SKP/SKS). Lihat [[project-offer-pipeline]].

function _offer_prop_code(string $key): string
{
    return match ($key) { 'ewalk' => 'e-Walk', 'pentacity' => 'Pentacity', default => ucfirst($key) };
}
function _offer_roman(int $m): string
{
    return ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$m] ?? (string) $m;
}
function _offer_module_label(string $m): string
{
    return ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang', 'bundle' => 'Paket'][$m] ?? strtoupper($m);
}
/** [label, warna teks, warna latar] untuk badge modul (Exhibition/Media/Gudang/Paket). */
function _offer_module_badge(string $m): array
{
    return [
        'cl'     => ['Exhibition', '#0f766e', '#ccfbf1'],
        'media'  => ['Media', '#0369a1', '#e0f2fe'],
        'gudang' => ['Gudang', '#92400e', '#fef3c7'],
        'bundle' => ['Paket', '#7c3aed', '#ede9fe'],
    ][$m] ?? [strtoupper($m), '#374151', '#f1f5f9'];
}

/** Kategori alasan penawaran ditutup tanpa deal (untuk analisa). */
function offer_lost_categories(): array
{
    return [
        'harga'          => 'Harga terlalu tinggi',
        'kompetitor'     => 'Pilih kompetitor / mall lain',
        'budget'         => 'Budget / keputusan internal client batal',
        'tidak_respon'   => 'Client tidak merespon / hilang kontak',
        'jadwal'         => 'Periode / jadwal tidak cocok',
        'lokasi'         => 'Lokasi / unit tidak sesuai',
        'fiktif'         => 'Tidak valid / dibatalkan internal',
        'lainnya'        => 'Lainnya',
    ];
}
function offer_lost_label(?string $k): string
{
    return offer_lost_categories()[$k] ?? '—';
}

/** Ketentuan & persyaratan baku Surat Penawaran (sumber tunggal: PDF & halaman TTD). */
function offer_terms(): array
{
    return [
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
}

/** Fasilitas baku yang ditawarkan (PDF & halaman TTD). */
function offer_facilities(): array
{
    return ['Standar area pameran', 'Stop kontak listrik', 'Media promosi: media sosial mall & pembagian flyer di area event'];
}

/** Decode satu baris offer_templates → struktur isi surat. */
/**
 * Judul tiap bagian surat. Nilai bawaannya SAMA PERSIS dengan yang dulu dipaku
 * di template cetak, jadi surat yang sudah ada tidak berubah bunyinya.
 */
function offer_judul_bawaan(): array
{
    return [
        'rincian_biaya' => 'Rincian Biaya',
        // Judul kolom tabel penawaran. Surat kertas menulisnya lebih pendek:
        // "Lokasi | Luas | Harga Sewa Per Bulan | Keterangan".
        'kolom_lokasi'  => 'Lokasi / Titik',
        'kolom_luas'    => 'Luasan',
        'kolom_harga'   => 'Harga Sewa / Periode',
        'kolom_ket'     => 'Keterangan',
        'fasilitas'     => 'Fasilitas',
        'media'         => 'Media Promosi',
        'pembayaran'    => 'Cara Pembayaran',
        'ketentuan'     => 'Ketentuan & Persyaratan',
    ];
}

/** Blok rekening. Bawaannya persis nomor yang selama ini tercetak. */
function offer_bank_bawaan(): array
{
    return [
        'kalimat'   => 'Pembayaran ditransfer ke rekening:',
        'atas_nama' => 'PT. Wulandari Bangun Laksana',
        'bank'      => 'Bank Rakyat Indonesia (BRI)',
        'rekening'  => '2078-01-000560-30-4',
    ];
}

/**
 * Kalimat penutup. {PIC} {WA} {EMAIL} {KANTOR} diganti saat surat dicetak,
 * sehingga nama dan nomor WhatsApp selalu milik sales yang bersangkutan —
 * bukan satu nomor yang sama untuk semua orang.
 */
function offer_penutup_bawaan(): string
{
    return 'Untuk keterangan lebih lanjut dapat menghubungi **{PIC}** ({WA}) atau kantor kami **{KANTOR}**.';
}

/** Kalimat penutup terakhir. Bawaannya persis yang dulu dipaku di template cetak. */
function offer_penutup_akhir_bawaan(): string
{
    return 'Demikian surat penawaran ini kami buat. Atas perhatian dan kerjasamanya kami ucapkan terima kasih.';
}

function _offer_template_norm(array $t): array
{
    $judul = json_decode((string) ($t['judul_json'] ?? '{}'), true) ?: [];
    $bank  = json_decode((string) ($t['bank_json'] ?? '{}'), true) ?: [];
    return [
        'id'                => (int) ($t['id'] ?? 0),
        'name'              => (string) ($t['name'] ?? ''),
        'module'            => (string) ($t['module'] ?? 'cl'),
        'unit_type'         => (string) ($t['unit_type'] ?? ''),
        'is_default'        => (int) ($t['is_default'] ?? 0),
        // tabel = keluarga Exhibition; rincian = keluarga Foodcourt (daftar bernomor).
        'layout'            => ((string) ($t['layout'] ?? 'tabel')) === 'rincian' ? 'rincian' : 'tabel',
        'gaya_daftar'       => ((string) ($t['gaya_daftar'] ?? 'nomor')) === 'bullet' ? 'bullet' : 'nomor',
        'gaya_judul'        => ((string) ($t['gaya_judul'] ?? 'aplikasi')) === 'romawi' ? 'romawi' : 'aplikasi',
        'gaya_bank'         => ((string) ($t['gaya_bank'] ?? 'kotak')) === 'menyatu' ? 'menyatu' : 'kotak',
        'penutup_akhir'     => trim((string) ($t['penutup_akhir'] ?? '')) !== '' ? (string) $t['penutup_akhir'] : offer_penutup_akhir_bawaan(),
        'ttd_kanan'         => array_key_exists('ttd_kanan', $t) && $t['ttd_kanan'] !== null ? (string) $t['ttd_kanan'] : 'Calon Penyewa',
        'tampil_berlaku'    => (int) ($t['tampil_berlaku'] ?? 1),
        'tampil_qr'         => (int) ($t['tampil_qr'] ?? 1),
        'gaya_uang'         => ((string) ($t['gaya_uang'] ?? 'polos')) === 'kertas' ? 'kertas' : 'polos',
        // Kolom harga di tabel objek: 'bulan' menulis harga per bulan seperti
        // surat kertas, 'periode' menulis total kontrak (bawaan lama).
        'harga_tampil'      => ((string) ($t['harga_tampil'] ?? 'periode')) === 'bulan' ? 'bulan' : 'periode',
        'perihal'           => (string) ($t['perihal'] ?? ''),
        'intro'             => (string) ($t['intro'] ?? ''),
        'fasilitas'         => json_decode((string) ($t['fasilitas_json'] ?? '[]'), true) ?: [],
        'media'             => json_decode((string) ($t['media_json'] ?? '[]'), true) ?: [],
        'ket'               => json_decode((string) ($t['ket_json'] ?? '[]'), true) ?: [],
        'rincian'           => json_decode((string) ($t['rincian_json'] ?? '[]'), true) ?: [],
        'payment'           => json_decode((string) ($t['payment_json'] ?? '[]'), true) ?: [],
        'terms'             => json_decode((string) ($t['terms_json'] ?? '[]'), true) ?: [],
        'notes'             => json_decode((string) ($t['notes_json'] ?? '[]'), true) ?: [],
        'extra'             => json_decode((string) ($t['extra_json'] ?? '{}'), true) ?: [],
        'judul'             => array_merge(offer_judul_bawaan(), is_array($judul) ? $judul : []),
        'bank'              => array_merge(offer_bank_bawaan(), is_array($bank) ? $bank : []),
        'penutup'           => trim((string) ($t['penutup'] ?? '')) !== '' ? (string) $t['penutup'] : offer_penutup_bawaan(),
        // PPN: 12% dengan rumus PMK 131/2024 menghasilkan tarif efektif yang
        // sama persis dengan 11% polos (12% x 11/12 = 11%). Jadi dua template
        // boleh berbeda KALIMATNYA tanpa berbeda sepeser pun uangnya.
        'ppn_persen'        => (float) ($t['ppn_persen'] ?? 12),
        'ppn_rumus'         => (int) ($t['ppn_rumus'] ?? 1),
        'ppn_catatan'       => (string) ($t['ppn_catatan'] ?? ''),
        'rincian_biaya'     => (int) ($t['rincian_biaya'] ?? 1),
        'dp_required'       => (int) ($t['dp_required'] ?? 1),
        'dp_months_default' => (float) ($t['dp_months_default'] ?? 2),
        // Baseline biaya listrik per bulan — dipakai saat membuat penawaran baru.
        'electricity_default' => (float) ($t['electricity_default'] ?? 150000),
    ];
}

/**
 * Daftar bernomor (keluarga Foodcourt) ditulis sebagai teks biasa supaya bisa
 * disunting di satu kotak, bukan lewat belasan isian terpisah:
 *
 *     Lokasi :: BSB Foodcourt, Pentacity Shopping Avenue
 *     @Balikpapan Superblock
 *     Alamat :: Jl Jend. Sudirman, Balikpapan
 *
 * Baris yang memuat " :: " memulai baris baru; baris lain menempel pada baris
 * di atasnya. Dengan begitu isi bertingkat (mis. rincian Service Charge) tetap
 * bisa ditulis apa adanya.
 */
function offer_rincian_parse(string $teks): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $teks) as $baris) {
        if (strpos($baris, '::') !== false && preg_match('/^\s*([^:]{1,60}?)\s*::\s*(.*)$/u', $baris, $m)) {
            $out[] = ['label' => trim($m[1]), 'isi' => $m[2]];
            continue;
        }
        if (!$out) { if (trim($baris) !== '') $out[] = ['label' => '', 'isi' => $baris]; continue; }
        $out[count($out) - 1]['isi'] .= "\n" . $baris;
    }
    foreach ($out as $i => $r) $out[$i]['isi'] = rtrim($r['isi']);
    return array_values(array_filter($out, fn($r) => $r['label'] !== '' || trim($r['isi']) !== ''));
}

/** Kebalikan offer_rincian_parse() — untuk ditampilkan kembali di kotak isian. */
function offer_rincian_text(array $rincian): string
{
    $b = [];
    foreach ($rincian as $r) {
        $b[] = trim((string) ($r['label'] ?? '')) . ' :: ' . (string) ($r['isi'] ?? '');
    }
    return implode("\n", $b);
}

/** Tarif PPN efektif sebuah template, dalam pecahan (0.11 = 11%). */
function offer_ppn_tarif(array $tpl): float
{
    $p = (float) ($tpl['ppn_persen'] ?? 12) / 100;
    return !empty($tpl['ppn_rumus']) ? $p * 11 / 12 : $p;
}

/**
 * Semua template satu modul, berurut ABJAD — untuk dropdown di formulir.
 * Yang ditandai bawaan tetap dikenali lewat kunci 'is_default', bukan lewat
 * urutan, supaya daftarnya enak dibaca sekaligus pilihannya tetap benar.
 */
function offer_template_list(PDO $pdo, int $propertyId, string $module = 'cl'): array
{
    $st = $pdo->prepare("SELECT * FROM offer_templates
                          WHERE property_id = ? AND module = ? AND status = 'active'
                          ORDER BY name ASC, id ASC");
    $st->execute([$propertyId, _tpl_module($module)]);
    return array_map('_offer_template_norm', $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

/** Satu template menurut id — dipakai saat sales memilih sendiri dari dropdown. */
function offer_template_by_id(PDO $pdo, int $propertyId, int $id): ?array
{
    if ($id <= 0) return null;
    $st = $pdo->prepare("SELECT * FROM offer_templates WHERE id = ? AND property_id = ? AND status = 'active' LIMIT 1");
    $st->execute([$id, $propertyId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    return $t ? _offer_template_norm($t) : null;
}

/** Daftar modul yang punya template sendiri. */
function _tpl_modules(): array
{
    return ['cl' => '🏬 Exhibition', 'media' => '📺 Media', 'gudang' => '📦 Gudang'];
}

function _tpl_module(string $m): string
{
    return isset(_tpl_modules()[$m]) ? $m : 'cl';
}

/** Keterangan singkat di atas daftar template, per modul. */
function _tpl_module_help(string $module): string
{
    return match ($module) {
        'gudang' => 'Isi <strong>Surat Konfirmasi Sewa Gudang</strong>: intro, peraturan sewa, catatan kaki (PPN &amp; rekening), dan contoh bullet kolom Keterangan. Semua bisa diubah di sini — dokumen yang sudah terbit tidak ikut berubah.',
        'media'  => 'Isi <strong>Form Utilities</strong>: daftar utilities, daftar media promo, pilihan parkir kendaraan, catatan kaki, dan catatan bawah formulir. Semua bisa diubah di sini — dokumen yang sudah terbit tidak ikut berubah.',
        default  => 'Isi <strong>Surat Penawaran</strong>: bentuk surat, PPN, bagian-bagiannya, rekening, dan kalimat penutup. Sales <strong>memilih sendiri</strong> templatenya lewat dropdown saat membuat penawaran &mdash; yang bertanda <strong>bawaan</strong> terpilih otomatis. Saat penawaran disimpan, isinya di-<em>snapshot</em> sehingga surat yang sudah terbit tak berubah walau templatenya diedit.',
    };
}

/**
 * Resolusi template dokumen: (properti, modul, tipe unit) → (properti, modul,
 * default) → (properti, cl, default) → fallback kode. Tipe unit hanya dipakai
 * modul Exhibition; Media &amp; Gudang cukup template default modulnya.
 */
/**
 * Template yang dipakai sebuah penawaran.
 *
 * Urutannya sengaja: PILIHAN ORANG dulu, baru tebakan mesin.
 *   1. $templateId — template yang dipilih sendiri di formulir; ini mutlak.
 *   2. template yang ditandai BAWAAN untuk modul ini.
 *   3. tebakan lama menurut tipe unit (dipertahankan supaya penawaran yang
 *      dibuat sebelum pembaruan ini tetap mendapat isi surat yang sama).
 *   4. baris default (tipe unit kosong), lalu fallback kode.
 */
function offer_template_for(PDO $pdo, int $propertyId, ?string $unitType, string $module = 'cl', int $templateId = 0): array
{
    $module = _tpl_module($module);
    if ($templateId > 0 && ($t = offer_template_by_id($pdo, $propertyId, $templateId))) {
        if ($t['module'] === $module) return $t;
    }
    $st = $pdo->prepare("SELECT * FROM offer_templates WHERE property_id=? AND module=? AND is_default=1 AND status='active' ORDER BY name ASC LIMIT 1");
    $st->execute([$propertyId, $module]);
    if ($t = $st->fetch()) return _offer_template_norm($t);

    $st = $pdo->prepare("SELECT * FROM offer_templates WHERE property_id=? AND module=? AND unit_type=? AND status='active' ORDER BY name ASC, id ASC LIMIT 1");
    if ($unitType !== null && $unitType !== '') {
        $st->execute([$propertyId, $module, $unitType]);
        if ($t = $st->fetch()) return _offer_template_norm($t);
    }
    $st->execute([$propertyId, $module, '']);
    if ($t = $st->fetch()) return _offer_template_norm($t);
    if ($module !== 'cl') {   // modul belum punya template sendiri → default properti
        $st->execute([$propertyId, 'cl', '']);
        if ($t = $st->fetch()) return _offer_template_norm($t);
    }
    // Fallback kode (praktis tak terpakai krn migrasi seed default per properti).
    return [
        'name' => 'Pameran Umum (default)', 'module' => $module, 'unit_type' => '',
        'perihal' => 'Surat Penawaran Sewa Area Pameran',
        'intro'   => 'Bersama ini kami Management e-Walk dan Pentacity Mall Balikpapan menawarkan space exhibition sebagai berikut:',
        'fasilitas' => offer_facilities(),
        'payment' => [
            'Wajib membayar biaya sewa (DP) senilai {dp} (Exc. PPN 12%) maksimal 1 minggu setelah penawaran disetujui, dan pelunasan paling lambat H-7 sebelum pelaksanaan sewa.',
            'Wajib membayar Security Deposit (uang jaminan) senilai {deposit} sebagai jaminan kerusakan / pengakhiran kontrak sebelum masa sewa berakhir.',
            'Apabila tidak terjadi kerusakan setelah masa sewa berakhir, Security Deposit dikembalikan 100%.',
        ],
        'terms' => offer_terms(), 'notes' => [], 'extra' => [],
        'id' => 0, 'is_default' => 1, 'layout' => 'tabel', 'gaya_daftar' => 'nomor',
        'gaya_judul' => 'aplikasi', 'gaya_bank' => 'kotak', 'media' => [], 'ket' => [], 'rincian' => [],
        'judul' => offer_judul_bawaan(), 'bank' => offer_bank_bawaan(), 'penutup' => offer_penutup_bawaan(),
        'penutup_akhir' => offer_penutup_akhir_bawaan(), 'ttd_kanan' => 'Calon Penyewa',
        'tampil_berlaku' => 1, 'tampil_qr' => 1, 'gaya_uang' => 'polos',
        'ppn_persen' => 12.0, 'ppn_rumus' => 1, 'ppn_catatan' => '', 'rincian_biaya' => 1,
        'dp_required' => 1, 'dp_months_default' => 2, 'electricity_default' => 150000,
    ];
}

/** Ganti placeholder nominal pada teks template: {dp} {deposit} {total} {ppn} {grand}. */
function offer_letter_fill(string $text, array $a): string
{
    $rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $ganti = [
        '{dp}'      => $rp($a['dp'] ?? 0),
        '{deposit}' => $rp($a['deposit'] ?? 0),
        '{total}'   => $rp($a['total'] ?? 0),
        '{ppn}'     => $rp($a['ppn'] ?? 0),
        '{grand}'   => $rp($a['grand'] ?? 0),
    ];
    // Nilai bukan-uang: dipakai bullet kolom Keterangan & kalimat penutup, supaya
    // kalimat baku template bisa menyebut angka penawaran tanpa diketik ulang.
    // Huruf besar maupun kecil sama-sama diterima — orang yang menyunting template
    // menulis {PIC}, dan menolak diam-diam hanya menyisakan kurung kurawal di surat.
    foreach (['hari', 'periode', 'periode_panjang', 'masa_bulan_hari', 'lokasi', 'luas',
              'harga', 'harga_bulan', 'ppn_persen',
              'pic', 'pic_besar', 'wa', 'email', 'kantor'] as $k) {
        if (!array_key_exists($k, $a)) continue;
        $ganti['{' . $k . '}'] = (string) $a[$k];
        $ganti['{' . strtoupper($k) . '}'] = (string) $a[$k];
    }
    return strtr($text, $ganti);
}

/** Rupiah menurut gaya template: 'Rp 7.000.000' atau 'Rp. 7.000.000,-'. */
function offer_rupiah(float $v, string $gaya = 'polos'): string
{
    $n = number_format($v, 0, ',', '.');
    return $gaya === 'kertas' ? 'Rp. ' . $n . ',-' : 'Rp ' . $n;
}

/**
 * Periode gaya surat kertas: "1-31 Mei 2026" bila sebulan, "21 September –
 * 4 Oktober 2026" bila beda bulan, "1 September 2026 – 31 Agustus 2027" bila
 * beda tahun. Format lama (01/05/2026 s/d 31/05/2026) tetap tersedia sebagai
 * {periode}, jadi surat yang sudah terbit tidak berubah.
 */
function offer_periode_panjang(?string $mulai, ?string $akhir): string
{
    if (!$mulai || !$akhir) return '-';
    $bln = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $a = strtotime($mulai); $b = strtotime($akhir);
    $ha = (int) date('j', $a); $hb = (int) date('j', $b);
    $ba = (int) date('n', $a); $bb = (int) date('n', $b);
    $ta = date('Y', $a);       $tb = date('Y', $b);
    if ($ta === $tb && $ba === $bb) return $ha . '-' . $hb . ' ' . $bln[$bb] . ' ' . $tb;
    if ($ta === $tb)              return $ha . ' ' . $bln[$ba] . ' – ' . $hb . ' ' . $bln[$bb] . ' ' . $tb;
    return $ha . ' ' . $bln[$ba] . ' ' . $ta . ' – ' . $hb . ' ' . $bln[$bb] . ' ' . $tb;
}

/**
 * Nomor telepon dirapikan jadi kelompok empat angka — 08xxxxxxxxxx jadi
 * 08xx-xxxx-xxxx, bentuk yang dipakai di surat-surat kertas selama ini.
 * Nomor yang sudah ada pemisahnya dibiarkan apa adanya.
 */
function offer_telp_rapi(?string $no): string
{
    $no = trim((string) $no);
    if ($no === '') return '';
    if (preg_match('/[^0-9]/', $no)) return $no;       // sudah ditulis dengan gaya sendiri
    return trim(implode('-', str_split($no, 4)), '-');
}

/**
 * Isi surat untuk sebuah penawaran: dari snapshot letter_json (terkunci saat
 * simpan) bila ada; jika tidak (penawaran lama), resolve template live.
 */
/**
 * Isi surat yang dipakai satu penawaran.
 *
 * Yang sudah tersimpan di letter_json DIPAKAI APA ADANYA — itulah sebabnya
 * mengubah atau menambah template tidak pernah mengubah surat yang sudah
 * terbit. Kunci baru (tata letak, PPN, judul bagian, rekening, penutup) diberi
 * nilai bawaan yang sama persis dengan perilaku lama, sehingga surat lama yang
 * belum menyimpan kunci itu tetap tercetak seperti semula.
 */
function offer_letter_bawaan(): array
{
    return [
        'perihal' => '', 'intro' => '', 'fasilitas' => [], 'payment' => [], 'terms' => [],
        'notes' => [], 'extra' => [], 'media' => [], 'ket' => [], 'rincian' => [],
        'layout' => 'tabel', 'gaya_daftar' => 'nomor', 'gaya_judul' => 'aplikasi', 'gaya_bank' => 'kotak',
        'judul' => offer_judul_bawaan(), 'bank' => offer_bank_bawaan(),
        'penutup' => offer_penutup_bawaan(), 'penutup_akhir' => offer_penutup_akhir_bawaan(),
        'ttd_kanan' => 'Calon Penyewa', 'tampil_berlaku' => 1, 'tampil_qr' => 1, 'gaya_uang' => 'polos',
        'ppn_persen' => 12.0, 'ppn_rumus' => 1, 'ppn_catatan' => '', 'rincian_biaya' => 1,
    ];
}

function offer_letter(PDO $pdo, array $o): array
{
    if (!empty($o['letter_json'])) {
        $l = json_decode((string) $o['letter_json'], true);
        if (is_array($l)) {
            $l += offer_letter_bawaan();
            $l['judul'] = array_merge(offer_judul_bawaan(), is_array($l['judul'] ?? null) ? $l['judul'] : []);
            $l['bank']  = array_merge(offer_bank_bawaan(),  is_array($l['bank'] ?? null)  ? $l['bank']  : []);
            return $l;
        }
    }
    $mod = _tpl_module((string) ($o['module'] ?? 'cl'));
    $ut  = $mod === 'cl' ? offer_unit_type($pdo, (int) $o['property_id'], $o['master_code'] ?? null) : '';
    $t   = offer_template_for($pdo, (int) $o['property_id'], $ut, $mod, (int) ($o['template_id'] ?? 0));
    return offer_letter_dari_template($t);
}

/** Satu tempat yang mengubah template jadi isi surat — dipakai saat menyimpan & mencetak. */
function offer_letter_dari_template(array $t): array
{
    return [
        'template'   => $t['name'] ?? '',
        'template_id' => (int) ($t['id'] ?? 0),
        'layout'     => $t['layout'] ?? 'tabel',
        'gaya_daftar' => $t['gaya_daftar'] ?? 'nomor',
        'gaya_judul'  => $t['gaya_judul'] ?? 'aplikasi',
        'gaya_bank'   => $t['gaya_bank'] ?? 'kotak',
        'penutup_akhir' => $t['penutup_akhir'] ?? offer_penutup_akhir_bawaan(),
        'ttd_kanan'   => $t['ttd_kanan'] ?? 'Calon Penyewa',
        'tampil_berlaku' => (int) ($t['tampil_berlaku'] ?? 1),
        'tampil_qr'   => (int) ($t['tampil_qr'] ?? 1),
        'gaya_uang'   => $t['gaya_uang'] ?? 'polos',
        // Kolom harga di tabel objek: total periode, atau harga per bulan
        // seperti surat kertas ("Harga Sewa / Bulan  Rp 8.500.000").
        'harga_tampil' => $t['harga_tampil'] ?? 'periode',
        'perihal'    => $t['perihal'] ?? '', 'intro' => $t['intro'] ?? '',
        'fasilitas'  => $t['fasilitas'] ?? [], 'media' => $t['media'] ?? [],
        'ket'        => $t['ket'] ?? [], 'rincian' => $t['rincian'] ?? [],
        'payment'    => $t['payment'] ?? [], 'terms' => $t['terms'] ?? [],
        'notes'      => $t['notes'] ?? [], 'extra' => $t['extra'] ?? [],
        'judul'      => $t['judul'] ?? offer_judul_bawaan(),
        'bank'       => $t['bank'] ?? offer_bank_bawaan(),
        'penutup'    => $t['penutup'] ?? offer_penutup_bawaan(),
        'ppn_persen' => (float) ($t['ppn_persen'] ?? 12),
        'ppn_rumus'  => (int) ($t['ppn_rumus'] ?? 1),
        'ppn_catatan' => (string) ($t['ppn_catatan'] ?? ''),
        'rincian_biaya' => (int) ($t['rincian_biaya'] ?? 1),
        'dp_required' => (int) ($t['dp_required'] ?? 1),
    ];
}

/** unit_type sebuah unit CL (utk resolusi template). */
function offer_unit_type(PDO $pdo, int $propertyId, ?string $masterCode): string
{
    if (!$masterCode) return '';
    $st = $pdo->prepare("SELECT unit_type FROM master_cl_units WHERE code=? AND property_id=? LIMIT 1");
    $st->execute([$masterCode, $propertyId]);
    return (string) ($st->fetchColumn() ?: '');
}

/**
 * Skor risiko "fiktif" sebuah penawaran (0–100) + daftar sinyal.
 * Heuristik aktivitas: penawaran asli umumnya benar-benar DIKIRIM ke client,
 * punya effort (revisi/nego), data kontak lengkap, & tidak ditutup instan.
 * $o = baris offers. $dupCount = jumlah penawaran lain identik oleh PIC sama.
 * $clientHasPhone = apakah client punya nomor telepon.
 */
function offer_fiktif_assess(array $o, int $dupCount = 0, bool $clientHasPhone = true): array
{
    $score = 0; $flags = [];
    $status   = $o['status'] ?? 'draft';
    $closed   = $status === 'cancelled';
    $everSent = !empty($o['sent_at']) || !empty($o['nego_at']) || in_array($status, ['deal'], true);
    $revs     = (int) ($o['revision_count'] ?? 0);

    // 1) Tidak pernah benar-benar dikirim ke client, tapi sudah closed/deal.
    if (!$everSent && in_array($status, ['cancelled', 'deal'], true)) {
        $score += 30; $flags[] = 'Tidak pernah ditandai terkirim ke client';
    }
    // 2) Ditutup tanpa effort sama sekali (0 revisi).
    if ($closed && $revs === 0) {
        $score += 15; $flags[] = 'Ditutup tanpa revisi/nego';
    }
    // 3) Ditutup sangat cepat setelah dibuat (< 1 jam).
    if ($closed && !empty($o['created_at']) && !empty($o['cancelled_at'])) {
        $secs = strtotime($o['cancelled_at']) - strtotime($o['created_at']);
        if ($secs >= 0 && $secs < 3600) { $score += 20; $flags[] = 'Ditutup < 1 jam sejak dibuat'; }
    }
    // 4) Kategori tutup = fiktif/internal (diakui sendiri).
    if ($closed && ($o['lost_category'] ?? '') === 'fiktif') {
        $score += 25; $flags[] = 'Ditandai tidak valid / dibatalkan internal';
    }
    // 5) Tanpa contact person.
    if (empty($o['contact_id'])) { $score += 10; $flags[] = 'Tanpa contact person'; }
    // 6) Client tanpa nomor telepon.
    if (!$clientHasPhone) { $score += 10; $flags[] = 'Client tanpa nomor telepon'; }
    // 7) Duplikat (client+unit+nilai sama oleh PIC sama).
    if ($dupCount > 0) { $score += 20; $flags[] = 'Duplikat penawaran identik (' . $dupCount . '×)'; }

    $score = min(100, $score);
    $level = $score >= 50 ? 'tinggi' : ($score >= 25 ? 'sedang' : 'rendah');
    return ['score' => $score, 'level' => $level, 'flags' => $flags];
}

/** Hitung masa kontrak (bulan) dari rentang tanggal. Min 1. */
function _offer_months(?string $start, ?string $end): int
{
    if (!$start || !$end) return 1;
    $s = strtotime($start); $e = strtotime($end);
    if ($s === false || $e === false || $e < $s) return 1;
    $months = ((int) date('Y', $e) - (int) date('Y', $s)) * 12
            + ((int) date('n', $e) - (int) date('n', $s));
    if ((int) date('j', $e) >= (int) date('j', $s)) $months++; // hari akhir ≥ hari awal → bulan penuh
    return max(1, $months);
}

/** Jumlah hari inklusif. */
function _offer_days(?string $start, ?string $end): int
{
    if (!$start || !$end) return 0;
    $s = strtotime($start); $e = strtotime($end);
    if ($s === false || $e === false || $e < $s) return 0;
    return (int) floor(($e - $s) / 86400) + 1;
}

/**
 * Masa sewa dalam gaya surat kertas: "13 Bulan 20 Hari".
 *
 * Dihitung dengan patokan bulan kalender, sama dengan prorata di
 * AllocationService: bulan yang tercakup penuh dihitung sebagai bulan, sisanya
 * dijumlahkan sebagai hari. Jadi 11 Nov 2026 s/d 31 Des 2027 terbaca
 * "13 Bulan 20 Hari" — 20 hari di November, lalu 13 bulan penuh.
 */
function offer_masa_bulan_hari(?string $mulai, ?string $akhir): string
{
    if (!$mulai || !$akhir) return '';
    try {
        $s = new DateTimeImmutable($mulai);
        $e = new DateTimeImmutable($akhir);
    } catch (Throwable $x) { return ''; }
    if ($e < $s) return '';

    $bulan = 0; $hari = 0;
    $kursor = $s->modify('first day of this month');
    while ($kursor <= $e) {
        $awalBulan  = $kursor;
        $akhirBulan = $kursor->modify('last day of this month');
        $a = $awalBulan  < $s ? $s : $awalBulan;
        $b = $akhirBulan > $e ? $e : $akhirBulan;
        if ($a <= $b) {
            $penuh = $a->format('Y-m-d') === $awalBulan->format('Y-m-d')
                  && $b->format('Y-m-d') === $akhirBulan->format('Y-m-d');
            if ($penuh) $bulan++;
            else        $hari += (int) $a->diff($b)->format('%a') + 1;
        }
        $kursor = $kursor->modify('+1 month');
    }
    // Sisa hari yang kebetulan genap sebulan (mis. 1-28 Feb) tetap disebut hari,
    // karena itu memang bukan bulan kalender penuh di mata surat.
    $bagian = [];
    if ($bulan > 0) $bagian[] = $bulan . ' Bulan';
    if ($hari  > 0) $bagian[] = $hari . ' Hari';
    return $bagian ? implode(' ', $bagian) : '0 Hari';
}

/**
 * Mesin pricing — SAMA dengan kalkulasiTotal() di form transaksi agar nilai
 * penawaran konsisten dengan transaksi yang terbit nanti.
 */
function _offer_calc_total(string $pricing, float $rate, float $area, float $slots, int $days, int $months = 1): float
{
    return match ($pricing) {
        'daily_point' => $rate * $days,
        'daily_slot'  => $rate * max(1, $slots) * $days,
        'daily_area'  => $rate * max(1, $area) * $days,
        'monthly'     => $rate * max(1, $months), // gudang: harga/bulan × jumlah bulan
        'fixed'       => $rate,                    // nilai tetap sekali kontrak
        default       => 0.0,
    };
}

/** Field ekonomi yang di-snapshot tiap revisi. */
function _offer_fields(): array
{
    return ['module', 'template_id', 'client_id', 'contact_id', 'pic_name', 'referrer_name', 'master_code', 'keterangan',
            'pricing_type', 'unit_rate', 'area_sqm', 'ukuran', 'quantity', 'slots',
            'start_date', 'end_date', 'contract_months', 'monthly_amount', 'total_calculated', 'override_amount',
            'billing_method', 'recurring_flag', 'cycle_recognition',
            'dp_months', 'dp_amount', 'deposit_months', 'deposit_amount', 'deposit_paid', 'ppn_flag', 'sc_flag', 'sc_monthly',
            'electricity_flag', 'electricity_monthly', 'electricity_units', 'electricity_amount',
            'perihal', 'offer_date', 'is_bundle', 'prorata_kalender'];
}

/**
 * Biaya listrik penawaran = tarif per bulan × jumlah bulan kontrak.
 * Nol bila tidak dicentang (tenant tidak dikenakan listrik) — dan nol juga untuk
 * penawaran lama yang terbit sebelum fitur ini ada, supaya cetakannya tidak
 * berubah di belakang hari.
 */
function offer_listrik(array $o): float
{
    if (empty($o['electricity_flag'])) return 0.0;
    // Nilai yang diketik sendiri menang atas hitungan otomatis.
    $manual = (float) ($o['electricity_amount'] ?? 0);
    if ($manual > 0) return round($manual, 2);
    return round((float) ($o['electricity_monthly'] ?? 0) * offer_listrik_bulan($o), 2);
}

/**
 * Jumlah bulan yang dipakai menghitung biaya listrik — dihitung dari LAMA HARI
 * sewa (30 hari = 1 bulan, dibulatkan ke bulan terdekat, minimal 1).
 *
 * Sengaja TIDAK memakai contract_months. Rumus itu dipakai untuk sewa/DP dan
 * menghitung 7 Okt–11 Nov (36 hari) sebagai 2 bulan karena tanggal akhirnya
 * melewati tanggal mulai — untuk listrik hasilnya janggal: sewa sebulan lewat
 * lima hari jadi ditagih listrik dua bulan.
 */
function offer_listrik_bulan(array $o): int
{
    // Jumlah satuan yang dipilih sendiri menang atas hitungan otomatis.
    $pilih = (int) ($o['electricity_units'] ?? 0);
    if ($pilih > 0) return $pilih;
    $hari = _offer_days($o['start_date'] ?? null, $o['end_date'] ?? null);
    if ($hari <= 0) return max(1, (int) ($o['contract_months'] ?? 1));
    return max(1, (int) round($hari / 30));
}

// ─── Paket Bundling (offer multi-komponen) ───────────────────────────────────
// Paket = penawaran dengan >=2 komponen (offer_items). Periode SAMA (level offer).
// Lihat [[project-bundling-package]].

/** Parse item paket dari POST (array sejajar item_*[]). Return list komponen ternormalisasi. */
function _offer_parse_bundle_items(int $months): array
{
    $segs = (array) post('item_segment', []);
    $mcs  = (array) post('item_master_code', []);
    $nms  = (array) post('item_name', []);
    $mon  = (array) post('item_monthly', []);
    $dps  = (array) post('item_dp', []);
    $deps = (array) post('item_deposit', []);
    $ars  = (array) ($_POST['item_area'] ?? []);
    $out = [];
    foreach ($segs as $i => $seg) {
        $seg = in_array($seg, ['cl', 'media', 'gudang'], true) ? $seg : 'cl';
        $mc  = trim((string) ($mcs[$i] ?? '')) ?: null;
        $nm  = trim((string) ($nms[$i] ?? ''));
        if ($mc === null && $nm === '') continue;            // lewati baris kosong
        $mAmt = parse_rupiah($mon[$i] ?? '0');
        // Luas komponen: dipakai sebagai dasar pembagian nilai paket saat
        // harganya bertingkat per m², dan diteruskan ke transaksinya supaya
        // laporan luas/occupancy per unit tidak lagi nol.
        $area = _offer_parse_luas((string) ($ars[$i] ?? '0'));
        $out[] = [
            'segment'        => $seg,
            'master_code'    => $mc,
            'name_snapshot'  => $nm ?: $mc,
            'pricing_type'   => null,
            'unit_rate'      => 0.0,
            'area_sqm'       => round(max(0, $area), 2),
            'slots'          => 1.0,
            'monthly_amount' => $mAmt,
            'dp_amount'      => parse_rupiah($dps[$i] ?? '0'),
            'deposit_amount' => parse_rupiah($deps[$i] ?? '0'),
            'total_amount'   => $mAmt * max(1, $months),
        ];
    }
    return $out;
}

/**
 * Tarif bertingkat per m² untuk SATU penawaran (kasus Efata/Mitsubishi).
 *
 * Ada kesepakatan yang mengunci beberapa lokasi sekaligus, tetapi harganya
 * ditetapkan atas LUAS GABUNGAN dengan tarif bertingkat — mis. 48 m² dengan
 * tarif Rp 130.000/m²/hari dan 32 m² sisanya Rp 100.000/m²/hari. Pembagian itu
 * tidak jatuh di batas lokasi, jadi tingkatan harga memang milik PAKET.
 *
 * Nilai paket = Σ(luas × tarif × lama hari). Angka itu lalu dibagi ke tiap
 * komponen lokasi menurut luasnya masing-masing, supaya transaksinya tetap
 * terbit per lokasi dan occupancy tiap unit tetap benar.
 */
function _offer_parse_area_tiers(): array
{
    $luas  = (array) ($_POST['tier_luas'] ?? []);
    $tarif = (array) ($_POST['tier_tarif'] ?? []);
    $label = (array) ($_POST['tier_label'] ?? []);
    $out = [];
    $separuh = [];
    foreach ($luas as $i => $m) {
        $m = _offer_parse_luas((string) $m);
        $r = parse_rupiah((string) ($tarif[$i] ?? '0'));
        // Baris yang terisi SEPARUH (luas ada tarif kosong, atau sebaliknya)
        // dulu dibuang tanpa pesan — paketnya tersimpan lebih murah dari maksud
        // sales dan tidak ada yang tahu. Sekarang disebutkan baris ke berapa.
        if (($m > 0) !== ($r > 0)) $separuh[] = $i + 1;
        if ($m <= 0 || $r <= 0) continue;
        $out[] = [
            'area_sqm'     => round($m, 2),
            'rate_per_sqm' => round($r, 2),
            'label'        => trim((string) ($label[$i] ?? '')) ?: null,
        ];
    }
    if ($separuh && function_exists('flash')) {
        flash('Baris tingkatan harga ke-' . implode(', ', $separuh) . ' diabaikan karena luas atau tarifnya belum terisi.');
    }
    return $out;
}

/**
 * Baca angka luas seperti orang menuliskannya di Indonesia — dan persis sama
 * dengan cara layar membacanya: titik = pemisah ribuan, koma = desimal.
 *
 * Tanpa ini "1.500" terbaca 1,5 m² oleh PHP tetapi 1.500 m² oleh JS di layar,
 * sehingga angka yang dilihat sales dan angka yang tersimpan bisa berbeda
 * seribu kali lipat.
 */
function _offer_parse_luas(string $v): float
{
    // Ambil angka di DEPAN saja. Membuang semua huruf akan mengubah "20 m2"
    // menjadi "202" karena angka 2 pada satuannya ikut terbawa.
    $v = ltrim($v);
    if (!preg_match('/^[0-9][0-9.,]*/', $v, $m)) return 0.0;
    $v = rtrim($m[0], '.,');
    if ($v === '') return 0.0;
    $v = str_replace('.', '', $v);          // titik = ribuan
    $v = str_replace(',', '.', $v);         // koma  = desimal
    return round(max(0, (float) $v), 2);
}

/** Tingkatan tarif milik satu penawaran (kosong = tidak memakai cara ini). */
function offer_area_tiers(PDO $pdo, int $offerId): array
{
    if ($offerId <= 0) return [];
    try {
        $st = $pdo->prepare('SELECT area_sqm, rate_per_sqm, label FROM offer_area_tiers
                              WHERE offer_id = ? ORDER BY sort_order ASC, id ASC');
        $st->execute([$offerId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];                            // tabel belum ada → perilaku lama
    }
}

/** Nilai kontrak menurut tingkatan tarif: Σ(luas × tarif × lama hari). */
function offer_tier_total(array $tiers, int $days): float
{
    if (!$tiers || $days <= 0) return 0.0;
    $t = 0.0;
    foreach ($tiers as $r) $t += (float) $r['area_sqm'] * (float) $r['rate_per_sqm'] * $days;
    return round($t);
}

/** Tulis ulang tingkatan tarif sebuah penawaran (replace-all). */
function _offer_write_tiers(PDO $pdo, int $pid, int $offerId, array $tiers, string $uname): void
{
    try {
        $pdo->prepare('DELETE FROM offer_area_tiers WHERE offer_id = ?')->execute([$offerId]);
        if (!$tiers) return;
        $ins = $pdo->prepare('INSERT INTO offer_area_tiers
                              (property_id, offer_id, area_sqm, rate_per_sqm, label, sort_order, created_by)
                              VALUES (?,?,?,?,?,?,?)');
        foreach ($tiers as $i => $t) {
            $ins->execute([$pid, $offerId, $t['area_sqm'], $t['rate_per_sqm'], $t['label'], $i, $uname]);
        }
    } catch (Throwable $e) {
        error_log('offer_area_tiers gagal disimpan (offer=' . $offerId . '): ' . $e->getMessage());
    }
}

/**
 * Bagi nilai paket ke tiap komponen menurut LUAS masing-masing.
 * Sisa pembulatan jatuh ke komponen terakhir supaya jumlahnya selalu pas.
 * Komponen tanpa luas dibagi rata, agar tidak ada yang bernilai nol diam-diam.
 */
function _offer_bagi_paket(array $items, float $total, int $months = 1): array
{
    $n = count($items);
    if ($n === 0 || $total <= 0) return $items;
    // Pro-rata hanya dipakai bila SELURUH komponen punya luas. Bila ada satu
    // saja yang kosong, bagian komponen itu akan jadi Rp 0 dan sisanya menumpuk
    // di komponen terakhir — diam-diam salah. Lebih jujur dibagi rata, dan
    // pemanggilnya memberi tahu user supaya luasnya dilengkapi.
    $semuaBerluas = true;
    foreach ($items as $it) if ((float) ($it['area_sqm'] ?? 0) <= 0) { $semuaBerluas = false; break; }
    $luas = array_sum(array_map(fn($i) => (float) ($i['area_sqm'] ?? 0), $items));
    if (!$semuaBerluas || $luas <= 0) $luas = 0.0;

    $bulan = max(1, $months);
    $pakai = 0.0;
    foreach ($items as $i => &$it) {
        $bagian = $i === $n - 1
            ? round($total - $pakai)
            : ($luas > 0 ? round($total * ((float) $it['area_sqm'] / $luas)) : round($total / $n));
        $pakai += $bagian;
        $it['total_amount'] = $bagian;
        // monthly_amount harus tetap berarti "per bulan". Kalau diisi nilai
        // seluruh periode, melepas centang tarif bertingkat akan membuat
        // nilainya berlipat (total = monthly × jumlah bulan).
        $it['monthly_amount'] = round($bagian / $bulan);
    }
    unset($it);
    return $items;
}

/** Apakah ada komponen paket yang luasnya belum diisi? */
function _offer_ada_tanpa_luas(array $items): bool
{
    foreach ($items as $it) if ((float) ($it['area_sqm'] ?? 0) <= 0) return true;
    return false;
}

/** Tulis ulang komponen paket (replace-all) untuk sebuah offer. */
function _offer_write_items(PDO $pdo, int $offerId, array $items): void
{
    $pdo->prepare('DELETE FROM offer_items WHERE offer_id = ?')->execute([$offerId]);
    if (!$items) return;
    $st = $pdo->prepare(
        'INSERT INTO offer_items
         (offer_id, segment, master_code, name_snapshot, pricing_type, unit_rate, area_sqm, slots,
          monthly_amount, dp_amount, deposit_amount, total_amount, sort_order)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    foreach ($items as $i => $it) {
        $st->execute([
            $offerId, $it['segment'], $it['master_code'], $it['name_snapshot'], $it['pricing_type'],
            $it['unit_rate'], $it['area_sqm'], $it['slots'], $it['monthly_amount'],
            $it['dp_amount'], $it['deposit_amount'], $it['total_amount'], $i,
        ]);
    }
}

/** Ambil komponen paket sebuah offer (urut). */
function offer_items(PDO $pdo, int $offerId): array
{
    $st = $pdo->prepare('SELECT * FROM offer_items WHERE offer_id = ? ORDER BY sort_order ASC, id ASC');
    $st->execute([$offerId]);
    return $st->fetchAll();
}

/**
 * Cek bentrok slot: master_code yang sama dipakai PENAWARAN aktif lain (status
 * <> cancelled, termasuk deal — karena offer deal tidak di-cancel) pada periode
 * tumpang-tindih, baik di offers.master_code maupun komponen offer_items.
 * Return daftar pesan bentrok (kosong = aman). Karena pipeline ini offer-first,
 * cek di level offers sudah mencakup deal; booking transaksi-langsung legacy di
 * luar cakupan. $excludeOffer = id offer yg sedang disimpan (jangan bentrok diri).
 */
function _offer_slot_conflicts(PDO $pdo, int $pid, array $items, ?string $start, ?string $end, int $excludeOffer = 0): array
{
    if (!$start || !$end) return [];
    $msgs = [];
    foreach ($items as $it) {
        $mc = $it['master_code'] ?? null;
        if (!$mc) continue;
        // Penawaran aktif lain (belum batal) dgn unit/titik sama, periode tumpang-tindih.
        $q = $pdo->prepare(
            "SELECT o.offer_no FROM offers o
             WHERE o.property_id = ? AND o.id <> ? AND o.status <> 'cancelled'
               AND o.start_date IS NOT NULL AND o.end_date IS NOT NULL
               AND o.start_date <= ? AND o.end_date >= ?
               AND (o.master_code = ? OR EXISTS (
                   SELECT 1 FROM offer_items oi WHERE oi.offer_id = o.id AND oi.master_code = ?))
             LIMIT 1"
        );
        $q->execute([$pid, $excludeOffer, $end, $start, $mc, $mc]);
        if ($no = $q->fetchColumn()) {
            $msgs[] = ($it['name_snapshot'] ?: $mc) . ' bentrok dgn penawaran ' . $no;
        }
    }
    return $msgs;
}

// ─── Daftar ──────────────────────────────────────────────────────────────────
function offers_list_page(PDO $pdo): void
{
    require_permission('manage_offers');
    $pid = current_property_id();
    // Tab grup: on_going (proses/tunggu client) · deal · closed (tidak deal).
    $tab = getv('tab', 'on_going');
    $tabStatuses = [
        'on_going' => ['draft', 'sent', 'nego'],
        'deal'     => ['deal'],
        'closed'   => ['cancelled'],
    ];
    if (!isset($tabStatuses[$tab])) $tab = 'on_going';
    // Filter modul (Exhibition/Media/Gudang).
    $module = getv('module', '');
    if (!in_array($module, ['cl', 'media', 'gudang'], true)) $module = '';
    // Pembatasan per-sales: role 'sales' hanya lihat miliknya sendiri.
    $scope = current_sales_scope($pdo, $pid);
    $scopeSql = $scope ? ' AND (o.pic_name = ? OR o.created_by = ?)' : '';
    $scopeSqlC = $scope ? ' AND (pic_name = ? OR created_by = ?)' : '';
    $scopeP = $scope ? [$scope['pic'], $scope['uname']] : [];

    // Hitung jumlah per tab (badge) — ikut filter modul + scope sales.
    $counts = ['on_going' => 0, 'deal' => 0, 'closed' => 0];
    $cq = 'SELECT status, COUNT(*) c FROM offers WHERE property_id = ?' . ($module ? ' AND module = ?' : '') . $scopeSqlC . ' GROUP BY status';
    $cs = $pdo->prepare($cq);
    $cs->execute(array_merge([$pid], $module ? [$module] : [], $scopeP));
    foreach ($cs->fetchAll() as $r) {
        foreach ($tabStatuses as $t => $sts) if (in_array($r['status'], $sts, true)) $counts[$t] += (int)$r['c'];
    }

    $in = implode(',', array_fill(0, count($tabStatuses[$tab]), '?'));
    $stmt = $pdo->prepare(
        "SELECT o.*, c.company_name FROM offers o LEFT JOIN master_clients c ON c.id = o.client_id
         WHERE o.property_id = ? AND o.status IN ($in)" . ($module ? ' AND o.module = ?' : '') . $scopeSql . " ORDER BY o.id DESC"
    );
    $stmt->execute(array_merge([$pid], $tabStatuses[$tab], $module ? [$module] : [], $scopeP));
    $rows = $stmt->fetchAll();

    layout('Surat Penawaran', function () use ($rows, $tab, $counts, $module) {
        $badge = [
            'draft'     => ['Draft', '#64748b', '#f1f5f9'],
            'sent'      => ['Terkirim', '#0369a1', '#e0f2fe'],
            'nego'      => ['Negosiasi', '#92400e', '#fef3c7'],
            'deal'      => ['DEAL', '#166534', '#dcfce7'],
            'cancelled' => ['Tidak Deal', '#991b1b', '#fee2e2'],
        ];
        ?>
        <div class="toolbar" style="gap:8px;flex-wrap:wrap">
            <?php /* Penawaran sekarang khusus Exhibition. Gudang & Media tidak
                     lewat surat penawaran — dokumennya langsung dibuat di menu
                     SKP (SKS Gudang / Form Utilities). */ ?>
            <a class="btn" href="?r=offer_form&module=cl">+ Buat Penawaran Exhibition</a>
            <a class="btn light" href="?r=skp" title="Gudang &amp; Media tidak lewat Surat Penawaran">📦📺 Gudang / Media → buat di SKP</a>
            <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap">
                <?php
                $mq = $module ? '&module=' . $module : '';
                $tabs = [
                    'on_going' => ['On Going', '#0d9488'],
                    'deal'     => ['Deal', '#166534'],
                    'closed'   => ['Tidak Deal', '#991b1b'],
                ];
                foreach ($tabs as $k => [$lbl, $clr]): $active = $tab === $k; ?>
                    <a class="btn light" style="<?= $active ? 'background:' . $clr . ';color:#fff' : '' ?>" href="?r=offers&tab=<?= $k . $mq ?>">
                        <?= $lbl ?> <span class="badge" style="<?= $active ? 'background:rgba(255,255,255,.25);color:#fff' : '' ?>"><?= (int)$counts[$k] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:10px">
            <span style="font-size:12px;color:var(--muted);margin-right:2px">Modul:</span>
            <?php
            $modFilters = ['' => 'Semua', 'cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
            foreach ($modFilters as $mk => $mlbl): $mactive = $module === $mk;
                [$ml, $mc, $mbg] = $mk ? _offer_module_badge($mk) : ['Semua', '#fff', '#0d9488']; ?>
                <a class="btn light" style="padding:5px 12px;font-size:12.5px;<?= $mactive ? 'background:' . ($mk ? $mbg : '#0d9488') . ';color:' . ($mk ? $mc : '#fff') . ';font-weight:700' : '' ?>" href="?r=offers&tab=<?= $tab ?><?= $mk ? '&module=' . $mk : '' ?>"><?= h($mlbl) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="panel" style="margin-top:12px">
            <div class="table-wrap">
                <table style="font-size:12.5px">
                    <thead><tr><th>No. Penawaran</th><th>Modul</th><th>Client</th><th>Periode</th><th>Harga/bln</th><th>Revisi</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?><tr><td colspan="8" style="text-align:center;color:var(--muted);padding:24px">Belum ada penawaran.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $r): $b = $badge[$r['status']] ?? $badge['draft']; $href = '?r=offer_view&id=' . (int)$r['id']; ?>
                        <tr style="cursor:pointer" onclick="if(!event.target.closest('a'))location.href='<?= $href ?>'">
                            <td style="white-space:nowrap;font-weight:600"><a href="<?= $href ?>" style="color:#0369a1;text-decoration:none"><?= h($r['offer_no'] ?? '—') ?></a></td>
                            <td><?php [$ml, $mc, $mbg] = _offer_module_badge($r['module']); ?><span class="badge" style="color:<?= $mc ?>;background:<?= $mbg ?>"><?= h($ml) ?></span></td>
                            <td><?= h($r['company_name'] ?? '-') ?></td>
                            <td style="white-space:nowrap;font-size:11.5px"><?= $r['start_date'] ? h(date('d/m/y', strtotime($r['start_date'])) . '–' . date('d/m/y', strtotime($r['end_date']))) : '—' ?></td>
                            <td style="white-space:nowrap"><?= money($r['monthly_amount']) ?></td>
                            <td style="text-align:center"><?= (int)$r['revision_count'] ?>×</td>
                            <td><span class="badge" style="color:<?= $b[1] ?>;background:<?= $b[2] ?>"><?= $b[0] ?></span><?php if ($r['status'] === 'cancelled' && !empty($r['lost_category'])): ?><div style="font-size:10.5px;color:#991b1b;margin-top:2px"><?= h(offer_lost_label($r['lost_category'])) ?></div><?php endif; ?></td>
                            <td style="white-space:nowrap">
                                <?php if ($r['offer_no']): ?><a class="btn light" href="?r=offer_print&id=<?= (int)$r['id'] ?>" target="_blank">PDF</a><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    });
}

// ─── Preview (detail read-only + tombol aksi) ────────────────────────────────
function offer_view(PDO $pdo): void
{
    require_permission('manage_offers');
    $pid = current_property_id();
    $id  = (int) getv('id');
    $st = $pdo->prepare(
        "SELECT o.*, c.company_name, c.brand_name,
                ct.name cp_name,
                u.location_name, u.floor
         FROM offers o
         LEFT JOIN master_clients c ON c.id = o.client_id
         LEFT JOIN master_client_contacts ct ON ct.id = o.contact_id
         LEFT JOIN master_cl_units u ON u.code = o.master_code AND u.property_id = o.property_id
         WHERE o.id = ? AND o.property_id = ?"
    );
    $st->execute([$id, $pid]);
    $offer = $st->fetch();
    if (!$offer) { flash('Penawaran tidak ditemukan.'); redirect_to('offers'); }
    if (($sc = current_sales_scope($pdo, $pid)) && $offer['pic_name'] !== $sc['pic'] && $offer['created_by'] !== $sc['uname']) { flash('Penawaran ini bukan milik Anda.'); redirect_to('offers'); }

    $editable = !in_array($offer['status'], ['deal', 'cancelled'], true);
    $days  = _offer_days($offer['start_date'] ?? null, $offer['end_date'] ?? null);
    $rp    = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

    // Panel kirim TTD customer: hanya bila nomor sudah terbit & belum ditutup.
    // sign_token diterbitkan sekali (lazy) — dipakai bersama QR validasi.
    $signUrl = $waMsg = '';
    if (!empty($offer['offer_no']) && $offer['status'] !== 'cancelled') {
        if (empty($offer['sign_token'])) {
            $offer['sign_token'] = bin2hex(random_bytes(20));
            $pdo->prepare('UPDATE offers SET sign_token=?, sign_token_expires_at=' . sign_token_expiry_sql() . ' WHERE id=? AND property_id=?')->execute([$offer['sign_token'], $id, $pid]);
        }
        // Catatan #7: status TIDAK dipromosikan di sini — membuka detail untuk
        // ditinjau tidak boleh mengubah status. Promosi draft→sent terjadi saat
        // customer benar-benar MEMBUKA link TTD (lihat offer_sign_page).
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        $signUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/?r=offer_sign&token=' . $offer['sign_token'];
        $waMsg = "Yth. " . ($offer['cp_name'] ?: 'Bapak/Ibu') . ",\n\n"
            . "Berikut Surat Penawaran No. " . $offer['offer_no'] . " untuk " . ($offer['company_name'] ?? '-') . " dari Management e-Walk & Pentacity Mall Balikpapan.\n\n"
            . "Mohon dapat ditinjau dan ditandatangani secara online melalui tautan berikut:\n" . $signUrl . "\n\n"
            . "Tautan ini aman dan khusus untuk Anda. Terima kasih.";
    }

    layout('Penawaran ' . ($offer['offer_no'] ?: ''), function () use ($pdo, $offer, $editable, $days, $rp, $signUrl, $waMsg) {
        $badge = [
            'draft'     => ['Draft', '#64748b', '#f1f5f9'],
            'sent'      => ['Terkirim', '#0369a1', '#e0f2fe'],
            'nego'      => ['Negosiasi', '#92400e', '#fef3c7'],
            'deal'      => ['DEAL', '#166534', '#dcfce7'],
            'cancelled' => ['Tidak Deal', '#991b1b', '#fee2e2'],
        ];
        $b = $badge[$offer['status']] ?? $badge['draft'];
        $periode = $offer['start_date'] ? (date('d/m/Y', strtotime($offer['start_date'])) . ' s/d ' . date('d/m/Y', strtotime($offer['end_date']))) : '—';
        $row = function (string $label, string $val) { ?>
            <div style="display:flex;gap:10px;padding:7px 0;border-bottom:1px solid #f1f5f9">
                <div style="width:170px;color:var(--muted);flex-shrink:0"><?= h($label) ?></div>
                <div style="font-weight:600"><?= $val ?></div>
            </div>
        <?php };
        ?>
        <div class="toolbar" style="gap:8px;flex-wrap:wrap">
            <a class="btn light" href="?r=offers">← Daftar Penawaran</a>
            <?php if ($offer['offer_no']): ?><a class="btn light" href="?r=offer_print&id=<?= (int)$offer['id'] ?>" target="_blank">🖨 PDF</a><?php endif; ?>
            <a class="btn light" href="?r=offer_form&id=<?= (int)$offer['id'] ?>"><?= $editable ? '✎ Edit' : '👁 Lihat Detail' ?></a>
            <?php if ($offer['status'] === 'deal' && can('manage_skp')): ?>
            <a class="btn" style="background:#0369a1;margin-left:auto" href="?r=skp_form&offer_id=<?= (int)$offer['id'] ?>">→ Buat <?= $offer['module'] === 'cl' ? 'SKP' : 'SKS' ?> (Konfirmasi)</a>
            <?php endif; ?>
        </div>

        <div class="panel" style="margin-top:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
            <div>
                <strong style="font-size:16px"><?= h($offer['offer_no'] ?: '(no. terbit saat disimpan)') ?></strong>
                · <span class="badge"><?= h(_offer_module_label($offer['module'])) ?></span>
                · <span class="badge" style="color:<?= $b[1] ?>;background:<?= $b[2] ?>"><?= $b[0] ?></span>
                · Revisi/nego: <strong><?= (int)$offer['revision_count'] ?>×</strong>
            </div>
            <?php if ($editable): ?>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                <?php foreach (['sent' => 'Tandai Terkirim', 'nego' => 'Tandai Nego', 'deal' => 'Tandai DEAL'] as $s => $lbl): if ($offer['status'] === $s) continue; ?>
                <form method="post" action="?r=offer_status" style="display:inline" onsubmit="return confirm('Ubah status ke <?= $lbl ?>?')">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$offer['id'] ?>"><input type="hidden" name="status" value="<?= $s ?>">
                    <button class="btn light" style="<?= $s === 'deal' ? 'background:#16a34a;color:#fff' : '' ?>"><?= $lbl ?></button>
                </form>
                <?php endforeach; ?>
                <button type="button" class="btn light" style="background:#fee2e2;color:#991b1b" onclick="document.getElementById('closeModal').style.display='flex'">Tutup (Tidak Deal)</button>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($editable): ?>
        <div id="closeModal" onclick="if(event.target===this)this.style.display='none'"
             style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(17,24,39,.55);align-items:center;justify-content:center;padding:16px">
            <form method="post" action="?r=offer_close" style="background:#fff;border-radius:14px;padding:20px 22px;box-shadow:0 20px 60px rgba(0,0,0,.3);width:100%;max-width:420px">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$offer['id'] ?>">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                    <strong style="font-size:15px;color:#991b1b">Tutup Penawaran (Tidak Deal)</strong>
                    <span style="cursor:pointer;font-size:20px;color:#9ca3af;line-height:1" onclick="document.getElementById('closeModal').style.display='none'">&times;</span>
                </div>
                <label style="font-size:12px;font-weight:700">Alasan tidak deal</label>
                <select name="lost_category" required style="width:100%;margin:4px 0 10px">
                    <option value="">- Pilih alasan -</option>
                    <?php foreach (offer_lost_categories() as $k => $lbl): ?><option value="<?= h($k) ?>"><?= h($lbl) ?></option><?php endforeach; ?>
                </select>
                <label style="font-size:12px;font-weight:700">Catatan (wajib)</label>
                <textarea name="status_note" required rows="3" placeholder="Jelaskan kronologi singkat kenapa tidak deal…" style="width:100%;margin-top:4px"></textarea>
                <div style="display:flex;gap:8px;margin-top:14px">
                    <button type="button" class="btn secondary" style="flex:1" onclick="document.getElementById('closeModal').style.display='none'">Batal</button>
                    <button class="btn" style="background:#991b1b;flex:1" onclick="return confirm('Tutup penawaran ini sebagai TIDAK DEAL? Tidak bisa diubah lagi.')">Tutup Penawaran</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($offer['status'] === 'cancelled'): ?>
        <div class="panel" style="margin-top:10px;background:#fef2f2;border-color:#fecaca">
            <strong style="color:#991b1b">Ditutup — Tidak Deal</strong>
            · Alasan: <strong><?= h(offer_lost_label($offer['lost_category'] ?? null)) ?></strong>
            <?php if (!empty($offer['cancelled_at'])): ?><span style="color:var(--muted)"> · <?= h(date('d/m/Y H:i', strtotime($offer['cancelled_at']))) ?></span><?php endif; ?>
            <?php if (!empty($offer['status_note'])): ?><div style="margin-top:6px;font-size:13px">“<?= h($offer['status_note']) ?>”</div><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($signUrl !== ''):
            $isSigned = !empty($offer['signed_at']);
            $waText = rawurlencode($waMsg); ?>
        <div class="panel" style="margin-top:12px;background:#f0f9ff;border-color:#bae6fd">
            <h3 style="margin-top:0;color:#0369a1">📝 Persetujuan & TTD Customer</h3>
            <?php if ($isSigned && ($offer['sign_method'] ?? 'online') === 'wet'): ?>
                <p style="margin:0;color:#166534">✓ <strong>Ditandatangani</strong> (dari dokumen terunggah) oleh <strong><?= h($offer['sign_name']) ?></strong> pada <?= h(substr($offer['signed_at'], 0, 16)) ?>. Penawaran menjadi <strong>DEAL</strong> &amp; nilai terkunci.
                <?php if (!empty($offer['signed_doc_path'])): ?> <a class="btn light" href="<?= h(upload_url($offer['signed_doc_path'])) ?>" target="_blank">Lihat Dokumen ber-TTD</a><?php endif; ?></p>
            <?php elseif ($isSigned): ?>
                <p style="margin:0;color:#166534">✓ <strong>Disetujui &amp; ditandatangani online</strong> oleh <strong><?= h($offer['sign_name']) ?></strong> pada <?= h(substr($offer['signed_at'], 0, 16)) ?> (IP <?= h($offer['sign_ip']) ?>). Penawaran menjadi <strong>DEAL</strong> &amp; nilai terkunci.</p>
            <?php else: ?>
                <p style="margin:0 0 8px;color:#374151"><strong>Opsi A — customer tanda tangan langsung.</strong> Kirim tautan ini ke customer untuk meninjau &amp; menandatangani penawaran. Saat customer TTD, penawaran otomatis menjadi <strong>DEAL</strong> dan nilainya dikunci. <strong>Sebelum customer TTD, Anda masih bisa merevisi penawaran.</strong></p>
                <textarea id="of-wa-msg" style="position:absolute;left:-9999px" readonly><?= h($waMsg) ?></textarea>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <input id="of-sign-url" value="<?= h($signUrl) ?>" readonly style="flex:1;min-width:260px;font-size:12px" onclick="this.select()">
                    <button type="button" class="btn light" onclick="claraCopyText(document.getElementById('of-sign-url').value,this,'Tersalin ✓')">Salin Link</button>
                    <button type="button" class="btn light" onclick="claraCopyText(document.getElementById('of-wa-msg').value,this,'Pesan tersalin ✓')">Salin Pesan</button>
                    <a class="btn" style="background:#16a34a" target="_blank" href="https://wa.me/?text=<?= $waText ?>">Kirim via WhatsApp</a>
                </div>
                <p style="margin:8px 0 0;font-size:11.5px;color:#64748b">Tautan bersifat rahasia &amp; khusus untuk customer ini. <strong>Jika lewat WhatsApp Desktop hanya link yang terkirim</strong>, gunakan <strong>Salin Pesan</strong> lalu tempel (paste) di chat — teks lengkap akan ikut.</p>
                <hr style="margin:14px 0;border:none;border-top:1px dashed #bae6fd">
                <p style="margin:0 0 8px;color:#374151"><strong>Opsi B — unggah dokumen yang sudah ditandatangani.</strong> Untuk customer yang lebih suka menandatangani di berkasnya sendiri: kirim/cetak suratnya, minta customer menandatangani, lalu unggah kembali <strong>PDF</strong> (atau foto/scan) yang sudah ber-TTD di sini. <strong>Hasilnya sama dengan Opsi A</strong> — penawaran langsung DEAL dan bisa dilanjutkan ke SKP.</p>
                <form method="post" action="?r=offer_sign_upload" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end" onsubmit="return confirm('Tandai penawaran ini sudah ditandatangani sesuai dokumen yang diunggah? Status menjadi DEAL dan nilainya dikunci.')">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$offer['id'] ?>">
                    <div><label style="font-size:12px;font-weight:700;display:block">Nama Penanda Tangan</label><input name="sign_name" required placeholder="Nama customer" value="<?= h($offer['cp_name'] ?? '') ?>" style="min-width:200px"></div>
                    <div><label style="font-size:12px;font-weight:700;display:block">Dokumen sudah ber-TTD (pdf/foto/jpg/png, ≤8MB)</label><input type="file" name="signed_doc" accept="image/*,.pdf" required></div>
                    <button type="submit" class="btn" style="background:#0369a1">Unggah &amp; Tandai TTD</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php $isBundle = !empty($offer['is_bundle']); ?>
        <div class="panel" style="margin-top:12px">
            <h3 style="margin-top:0">Ringkasan Penawaran</h3>
            <?php
            $clientDisp = ($offer['company_name'] ?? '-') . ($offer['brand_name'] ? ' — ' . $offer['brand_name'] : '');
            $unitDisp   = ($offer['location_name'] ?: $offer['master_code']) . ($offer['floor'] ? ' (Lt. ' . $offer['floor'] . ')' : '');
            $row('Client / Perusahaan', h($clientDisp));
            if ($offer['cp_name']) $row('Up. (Contact)', h($offer['cp_name']));
            $row('PIC Sales', h($offer['pic_name'] ?: '-'));
            if (!empty($offer['referrer_name'])) $row('Referral', h($offer['referrer_name']));
            if (!$isBundle) {
                $row('Unit / Lokasi', h($unitDisp));
                $row('Luas', $offer['area_sqm'] ? number_format((float)$offer['area_sqm'], 2, ',', '.') . ' m²' : '-');
            }
            $row('Periode', h($periode) . ($days ? ' · <strong>' . $days . ' hari</strong>' : ''));
            $row('Total Kontrak', $rp($offer['total_calculated']));
            if (!empty($offer['override_amount'])) $row('Harga Nego Final', $rp($offer['override_amount']));
            $row('Harga / Bulan', $rp($offer['monthly_amount']));
            $row('DP', $rp($offer['dp_amount']) . ' <span style="color:var(--muted);font-weight:400">(' . h(rtrim(rtrim(number_format((float)$offer['dp_months'],1,',',''),'0'),',')) . ' bln)</span>');
            $row('Deposit', $rp($offer['deposit_amount']) . ' <span style="color:var(--muted);font-weight:400">(' . h(rtrim(rtrim(number_format((float)$offer['deposit_months'],1,',',''),'0'),',')) . ' bln)</span>');
            $row('Recurring', !empty($offer['recurring_flag']) ? 'Ya' : 'Tidak');
            if (!empty($offer['keterangan'])) $row('Keterangan', h($offer['keterangan']));
            ?>
        </div>

        <?php if ($isBundle):
            $segLbl = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
            $items  = offer_items($pdo, (int)$offer['id']); ?>
        <div class="panel" style="margin-top:12px">
            <h3 style="margin-top:0">Komponen Paket</h3>
            <div style="overflow-x:auto">
            <table class="table" style="width:100%;border-collapse:collapse;font-size:13px">
                <thead>
                    <tr style="text-align:left;border-bottom:2px solid #e2e8f0">
                        <th style="padding:8px 10px">Nama / Titik</th>
                        <th style="padding:8px 10px">Segmen</th>
                        <th style="padding:8px 10px;text-align:right">Harga / periode</th>
                        <th style="padding:8px 10px;text-align:right">DP</th>
                        <th style="padding:8px 10px;text-align:right">Deposit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $it): ?>
                    <tr style="border-bottom:1px solid #f1f5f9">
                        <td style="padding:8px 10px"><strong><?= h($it['name_snapshot'] ?: $it['master_code']) ?></strong><?= $it['name_snapshot'] && $it['master_code'] ? ' <span style="color:var(--muted);font-size:11px">' . h($it['master_code']) . '</span>' : '' ?></td>
                        <td style="padding:8px 10px"><?= h($segLbl[$it['segment']] ?? $it['segment']) ?></td>
                        <td style="padding:8px 10px;text-align:right"><?= $rp($it['total_amount']) ?></td>
                        <td style="padding:8px 10px;text-align:right"><?= $rp($it['dp_amount']) ?></td>
                        <td style="padding:8px 10px;text-align:right"><?= $rp($it['deposit_amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$items): ?>
                    <tr><td colspan="5" style="padding:10px;color:var(--muted)">Tidak ada komponen.</td></tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="border-top:2px solid #e2e8f0;font-weight:700;background:#f8fafc">
                        <td style="padding:9px 10px" colspan="2">Total Paket</td>
                        <td style="padding:9px 10px;text-align:right"><?= $rp($offer['total_calculated'] ?: $offer['monthly_amount']) ?></td>
                        <td style="padding:9px 10px;text-align:right"><?= $rp($offer['dp_amount']) ?></td>
                        <td style="padding:9px 10px;text-align:right"><?= $rp($offer['deposit_amount']) ?></td>
                    </tr>
                </tfoot>
            </table>
            </div>
        </div>
        <?php
            // Transaksi turunan paket (terbit setelah SKP approve) + batal sebagian.
            $btx = $pdo->prepare('SELECT id, module, master_code, final_amount, deleted_at, cancel_reason
                                  FROM transactions WHERE bundle_id = ? ORDER BY id');
            $btx->execute([(int) $offer['id']]);
            $btxRows = $btx->fetchAll();
            $canCancel = function_exists('can') && can('approve_skp');
        ?>
        <?php if ($btxRows): ?>
        <div class="panel" style="margin-top:12px">
            <h3 style="margin-top:0">Transaksi Paket Terbit</h3>
            <p style="color:var(--muted);font-size:12px;margin-top:0">Tiap komponen = transaksi sendiri. Batal sebagian (komponen) butuh <strong>alasan</strong> &amp; hanya bisa oleh manajer (approval).</p>
            <div style="overflow-x:auto">
            <table class="table" style="width:100%;border-collapse:collapse;font-size:13px">
                <thead><tr style="text-align:left;border-bottom:2px solid #e2e8f0">
                    <th style="padding:8px 10px">#</th><th style="padding:8px 10px">Segmen / Titik</th>
                    <th style="padding:8px 10px;text-align:right">Nilai</th><th style="padding:8px 10px">Status</th>
                    <?php if ($canCancel): ?><th style="padding:8px 10px">Batalkan komponen</th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($btxRows as $bt): $cancelled = !empty($bt['deleted_at']); ?>
                    <tr style="border-bottom:1px solid #f1f5f9<?= $cancelled ? ';opacity:.55' : '' ?>">
                        <td style="padding:8px 10px">#<?= (int) $bt['id'] ?></td>
                        <td style="padding:8px 10px"><?= h(($segLbl[$bt['module']] ?? $bt['module']) . ' · ' . ($bt['master_code'] ?: '-')) ?></td>
                        <td style="padding:8px 10px;text-align:right"><?= $rp($bt['final_amount']) ?></td>
                        <td style="padding:8px 10px"><?= $cancelled
                            ? '<span style="color:#b91c1c">Dibatalkan</span>' . ($bt['cancel_reason'] ? '<br><span style="color:var(--muted);font-size:11px">' . h($bt['cancel_reason']) . '</span>' : '')
                            : '<span style="color:#15803d">Aktif</span>' ?></td>
                        <?php if ($canCancel): ?>
                        <td style="padding:8px 10px">
                            <?php if (!$cancelled): ?>
                            <form method="post" action="?r=transaction_cancel" style="display:flex;gap:6px;align-items:center" onsubmit="return confirm('Batalkan komponen ini? Alasan akan tercatat.')">
                                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="id" value="<?= (int) $bt['id'] ?>">
                                <input type="hidden" name="return" value="offer_view">
                                <input type="hidden" name="return_id" value="<?= (int) $offer['id'] ?>">
                                <input type="text" name="reason" placeholder="Alasan (wajib)" required style="font-size:12px;padding:4px 6px;border:1px solid #cbd5e1;border-radius:6px">
                                <button type="submit" class="btn light" style="font-size:12px;color:#b91c1c">Batalkan</button>
                            </form>
                            <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php
    });
}

// ─── Form (buat/edit) ────────────────────────────────────────────────────────
function offer_form(PDO $pdo): void
{
    require_permission('manage_offers');
    $pid = current_property_id();
    $id  = (int) getv('id');
    $offer = null;
    if ($id) {
        $st = $pdo->prepare('SELECT * FROM offers WHERE id = ? AND property_id = ?');
        $st->execute([$id, $pid]);
        $offer = $st->fetch();
        if (!$offer) { flash('Penawaran tidak ditemukan.'); redirect_to('offers'); }
        if (($sc = current_sales_scope($pdo, $pid)) && $offer['pic_name'] !== $sc['pic'] && $offer['created_by'] !== $sc['uname']) { flash('Penawaran ini bukan milik Anda.'); redirect_to('offers'); }
    }
    // Perpanjangan kontrak (dari Papan Renewal): prefill penawaran BARU dari
    // transaksi lama. Tidak menautkan/mengunci apa pun — sekadar isian awal.
    $isRenew = false;
    if (!$id && ($rf = (int) getv('renew_from', 0))) {
        $rfStmt = $pdo->prepare(
            "SELECT t.* FROM transactions t
             WHERE t.id = ? AND t.property_id = ? AND t.deleted_at IS NULL LIMIT 1"
        );
        $rfStmt->execute([$rf, $pid]);
        if ($src = $rfStmt->fetch()) {
            $oldStart = $src['start_date']; $oldEnd = $src['end_date'];
            $dur      = ($oldStart && $oldEnd) ? (int) floor((strtotime($oldEnd) - strtotime($oldStart)) / 86400) : 0;
            $newStart = $oldEnd ? date('Y-m-d', strtotime($oldEnd . ' +1 day')) : '';
            $newEnd   = ($newStart && $dur) ? date('Y-m-d', strtotime($newStart . ' +' . $dur . ' days')) : '';
            $offer = [
                'id' => 0, 'status' => 'draft', 'offer_no' => null, 'revision_count' => 0,
                'module'            => $src['module'],
                'client_id'         => $src['client_id'],
                'contact_id'        => $src['contact_id'],
                'pic_name'          => $src['pic_name'],
                'referrer_name'     => $src['referrer_name'] ?? '',
                'master_code'       => $src['master_code'],
                'area_sqm'          => $src['area_sqm'],
                'slots'             => $src['slots'],
                'pricing_type'      => $src['pricing_type'],
                'unit_rate'         => $src['unit_rate'],
                'keterangan'        => '',
                'start_date'        => $newStart,
                'end_date'          => $newEnd,
                'billing_method'    => $src['billing_method'] ?? '',
                'cycle_recognition' => $src['cycle_recognition'] ?? 'cycle_start',
                'recurring_flag'    => $src['recurring_flag'] ?? 0,
            ];
            $isRenew = true;
        } else {
            flash('Kontrak sumber perpanjangan tidak ditemukan.');
        }
    }

    $existing = $id > 0;   // true hanya untuk penawaran yang sudah tersimpan (bukan prefill renewal)
    $module  = $offer['module'] ?? getv('module', 'cl');
    if (!in_array($module, ['cl', 'media', 'gudang'], true)) $module = 'cl';
    // Penawaran BARU hanya untuk Exhibition. Gudang & Media langsung ke dokumen
    // konfirmasinya (SKS / Form Utilities). Penawaran lama kedua modul itu tetap
    // bisa dibuka & diproses seperti biasa.
    if (!$existing && !$isRenew && $module !== 'cl') {
        flash('Gudang & Media tidak lewat Surat Penawaran — dokumennya dibuat langsung di sini.');
        redirect_to('skp_form', ['module' => $module]);
    }
    $editable = !$existing || !in_array($offer['status'], ['deal', 'cancelled'], true);

    $masters  = masterOptions($pdo, $module);
    // Jadwal harga bertahap: kontrak panjang yang harganya berubah di tahun
    // tertentu harus tercantum sejak surat penawaran — client tidak mau
    // menerima dua surat.
    require_once dirname(__DIR__) . '/AllocationService.php';
    $tahapHarga = $id ? AllocationService::priceSteps($pdo, null, $id) : [];
    // Biaya listrik: penawaran baru tercentang otomatis dengan baseline dari
    // Template Penawaran; penawaran lama memakai apa yang tersimpan.
    $tplBaru    = offer_template_for($pdo, $pid, null, $module);
    $listrikOn  = $existing ? !empty($offer['electricity_flag']) : ($module === 'cl');
    $listrikRp  = $existing
        ? (float) ($offer['electricity_monthly'] ?? 0)
        : (float) ($tplBaru['electricity_default'] ?? 150000);
    if ($existing && $listrikRp <= 0) $listrikRp = (float) ($tplBaru['electricity_default'] ?? 150000);
    // Jaring pengaman: template yang baseline-nya belum diisi jangan sampai
    // membuat kotak tarif kosong — sales bisa lupa mengisinya.
    if ($listrikRp <= 0) $listrikRp = 150000;
    // Total yang pernah diketik sendiri (kosong = ikut hitungan otomatis).
    $listrikTotal = $existing ? (float) ($offer['electricity_amount'] ?? 0) : 0.0;
    $listrikUnit  = $existing ? (int) ($offer['electricity_units'] ?? 0) : 0;
    $clients  = $pdo->query("SELECT id, company_name, brand_name FROM master_clients WHERE status='active' ORDER BY company_name")->fetchAll();
    $contacts = $pdo->query("SELECT id, client_id, name FROM master_client_contacts WHERE status='active' ORDER BY name")->fetchAll();
    // Hanya PIC yang ditandai "tampil di penawaran" (toggle di Master PIC).
    $picsStmt = $pdo->prepare("SELECT name FROM master_pic WHERE status='active' AND property_id=? AND show_in_offer=1 ORDER BY name");
    $picsStmt->execute([$pid]);
    $pics = $picsStmt->fetchAll();
    $referrers = $pdo->query("SELECT name FROM master_referrer WHERE status='active' ORDER BY name")->fetchAll();
    $linkedPic = null;
    if ($uid = $_SESSION['user']['id'] ?? null) {
        $lp = $pdo->prepare("SELECT name FROM master_pic WHERE user_id=? AND status='active' AND property_id=? LIMIT 1");
        $lp->execute([$uid, $pid]);
        $linkedPic = $lp->fetchColumn() ?: null;
    }
    // Pastikan PIC tertaut akun & PIC penawaran lama tetap bisa terpilih walau di-hide.
    $picNames = array_column($pics, 'name');
    foreach ([$linkedPic, $offer['pic_name'] ?? null] as $must) {
        if ($must && !in_array($must, $picNames, true)) { $pics[] = ['name' => $must]; $picNames[] = $must; }
    }
    $v = fn(string $k, $def = '') => h((string) ($offer[$k] ?? $def));

    // ── Paket Bundling: tentukan mode & prefill komponen ─────────────────────
    $isBundle  = $existing ? !empty($offer['is_bundle']) : (bool) getv('bundle');
    $bundleRows = [];
    if ($existing && $isBundle) {
        foreach (offer_items($pdo, (int)$offer['id']) as $it) {
            $bundleRows[] = [
                'segment'     => $it['segment'],
                'master_code' => $it['master_code'],
                'name'        => $it['name_snapshot'],
                'area'        => (float) $it['area_sqm'] > 0 ? rtrim(rtrim(number_format((float) $it['area_sqm'], 2, ',', ''), '0'), ',') : '',
                'monthly'     => (int)$it['monthly_amount'],
                'dp'          => (int)$it['dp_amount'],
                'deposit'     => (int)$it['deposit_amount'],
            ];
        }
    }
    // Tingkatan tarif per m² milik penawaran ini (kosong = tidak memakainya).
    $tierRows = $existing ? offer_area_tiers($pdo, (int) $offer['id']) : [];

    // Daftar template untuk dipilih sendiri — berurut abjad supaya gampang dicari.
    // Yang terpilih: template penawaran ini bila sedang diedit, selain itu yang
    // ditandai bawaan.
    $tplList = offer_template_list($pdo, $pid, $module);
    $tplSel  = (int) ($offer['template_id'] ?? 0);
    if ($tplSel <= 0) {
        foreach ($tplList as $tt) { if (!empty($tt['is_default'])) { $tplSel = (int) $tt['id']; break; } }
    }

    layout(($existing ? ($editable ? 'Edit' : 'Lihat') : 'Buat') . ' Penawaran ' . _offer_module_label($module), function () use ($pdo, $offer, $id, $existing, $isRenew, $module, $editable, $masters, $clients, $contacts, $pics, $referrers, $linkedPic, $v, $isBundle, $bundleRows, $listrikOn, $listrikRp, $listrikTotal, $listrikUnit, $tplBaru, $tahapHarga, $tierRows, $tplList, $tplSel) {
        $picSel = $offer['pic_name'] ?? $linkedPic;
        $disabled = $editable ? '' : 'disabled';
        ?>
        <div class="toolbar" style="gap:8px">
            <a class="btn light" href="<?= $existing ? '?r=offer_view&id=' . (int)$offer['id'] : '?r=offers' ?>">← <?= $existing ? 'Kembali ke Preview' : 'Daftar Penawaran' ?></a>
            <?php if ($existing && $offer['offer_no']): ?><a class="btn light" href="?r=offer_print&id=<?= (int)$offer['id'] ?>" target="_blank">🖨 PDF</a><?php endif; ?>
        </div>

        <?php if ($isRenew): ?>
        <div class="panel" style="margin-top:10px;background:#ecfdf5;border-color:#a7f3d0;color:#065f46">
            <strong>🔄 Perpanjangan kontrak</strong> — data unit, client, PIC, dan rate sudah diisi dari kontrak sebelumnya. Periksa &amp; sesuaikan <strong>Tanggal &amp; Harga</strong>, lalu simpan sebagai penawaran baru. Nomor penawaran terbit saat disimpan.
        </div>
        <?php elseif ($existing): ?>
        <div class="panel" style="margin-top:10px">
            <strong style="font-size:15px"><?= h($offer['offer_no'] ?? '(no. terbit saat disimpan)') ?></strong> · <span class="badge"><?= h(_offer_module_label($offer['module'])) ?></span> · Revisi/nego: <strong><?= (int)$offer['revision_count'] ?>×</strong>
            <span class="muted" style="margin-left:6px">— ubah status / tutup / buat SKP lewat halaman preview.</span>
        </div>
        <?php endif; ?>

        <form class="panel" method="post" action="?r=offer_save" style="margin-top:12px" id="offer-form">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= (int)($offer['id'] ?? 0) ?>">
            <input type="hidden" name="module" value="<?= h($module) ?>">

            <?php
            $cliLabel = '';
            foreach ($clients as $cl) if ((int)$cl['id'] === (int)($offer['client_id'] ?? 0)) { $cliLabel = $cl['company_name'] . ($cl['brand_name'] ? ' (' . $cl['brand_name'] . ')' : ''); break; }
            ?>
            <h3 style="margin-top:0">Penerima</h3>
            <div class="form-grid">
                <div>
                    <label>Client / Perusahaan</label>
                    <?php if ($editable): ?>
                    <div style="position:relative" id="cliPicker">
                        <input type="text" id="cliSearch" autocomplete="off" placeholder="Ketik nama client..." value="<?= h($cliLabel) ?>">
                        <input type="hidden" name="client_id" id="client_id" value="<?= (int)($offer['client_id'] ?? 0) ?: '' ?>">
                        <div id="cliDrop" style="display:none"></div>
                    </div>
                    <div class="help">Ketik nama atau brand untuk mencari, lalu pilih dari daftar.</div>
                    <?php else: ?>
                    <input type="text" value="<?= h($cliLabel) ?>" disabled>
                    <input type="hidden" name="client_id" id="client_id" value="<?= (int)($offer['client_id'] ?? 0) ?>">
                    <?php endif; ?>
                </div>
                <div>
                    <label>Up. (Contact Person)</label>
                    <select name="contact_id" id="contact_id" <?= $disabled ?>><option value="">- Pilih -</option></select>
                </div>
                <?php /* Template menentukan BUNYI surat (bagian, ketentuan, PPN), bukan
                         angkanya. Jadi mengganti pilihan di sini tidak mengubah
                         perhitungan, item, maupun tahap harga penawaran. */ ?>
                <div>
                    <label>Template Surat</label>
                    <select name="template_id" <?= $disabled ?>>
                        <?php if (!$tplList): ?><option value="0">(belum ada template)</option><?php endif; ?>
                        <?php foreach ($tplList as $tt): ?>
                        <option value="<?= (int) $tt['id'] ?>" <?= (int) $tt['id'] === $tplSel ? 'selected' : '' ?>>
                            <?= h($tt['name']) ?><?= !empty($tt['is_default']) ? ' — bawaan' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="help">Menentukan isi baku surat. Isinya dikunci saat penawaran disimpan, jadi
                        mengubah template kemudian tidak mengubah surat ini.</div>
                </div>
                <div>
                    <label>PIC Sales (pembuat)</label>
                    <select name="pic_name" required <?= $disabled ?>>
                        <option value="">- Pilih PIC -</option>
                        <?php foreach ($pics as $p): ?><option <?= ($p['name'] === $picSel) ? 'selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Referral dari <span class="muted" style="font-weight:400">(opsional)</span></label>
                    <select name="referrer_name" <?= $disabled ?>>
                        <option value="">- Tidak ada referral -</option>
                        <?php foreach ($referrers as $ref): ?><option <?= ($offer['referrer_name'] ?? '') === $ref['name'] ? 'selected' : '' ?>><?= h($ref['name']) ?></option><?php endforeach; ?>
                    </select>
                    <div class="help">Karyawan yang mereferensikan — komisi 1% saat deal.</div>
                </div>
            </div>

            <div class="wide" style="display:flex;align-items:flex-start;gap:10px;background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:11px 14px;margin-top:6px">
                <input type="checkbox" name="is_bundle" id="is_bundle" value="1" style="width:18px;height:18px;flex-shrink:0;margin-top:1px" <?= $isBundle ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?>>
                <label for="is_bundle" style="margin:0;cursor:pointer">
                    <span style="font-weight:700;color:#92400e">Penawaran Paket (gabungan beberapa booth/titik media)</span>
                    <span class="help" style="display:block;margin-top:2px;font-weight:400">Centang untuk membuat satu penawaran yang berisi beberapa komponen (multi-titik). Periode di bawah berlaku untuk seluruh paket. Minimal 2 komponen.</span>
                </label>
            </div>

            <?php
            $segOpts = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
            // Baris awal: prefill dari komponen tersimpan, atau 2 baris kosong utk paket baru.
            $rowsToRender = $bundleRows;
            if (!$rowsToRender) $rowsToRender = [[], []];
            $rupFmt = fn($n) => $n ? number_format((int)$n, 0, ',', '.') : '';
            ?>
            <div id="bundle-fields" style="<?= $isBundle ? '' : 'display:none' ?>">
                <h3>Komponen Paket</h3>
                <div style="overflow-x:auto">
                <table id="bundle-table" style="width:100%;border-collapse:collapse;font-size:13px">
                    <thead>
                        <tr style="text-align:left">
                            <th style="padding:4px 6px">Segmen</th>
                            <th style="padding:4px 6px">Kode Unit/Titik</th>
                            <th style="padding:4px 6px">Nama Tampil</th>
                            <th style="padding:4px 6px">Luas (m²)</th>
                            <th style="padding:4px 6px">Harga/Bulan</th>
                            <th style="padding:4px 6px">DP</th>
                            <th style="padding:4px 6px">Deposit</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="bundle-body">
                        <?php foreach ($rowsToRender as $br): ?>
                        <tr class="bundle-row">
                            <td style="padding:3px 6px"><select name="item_segment[]" <?= $editable ? '' : 'disabled' ?>><?php foreach ($segOpts as $sv => $sl): ?><option value="<?= $sv ?>" <?= (($br['segment'] ?? 'cl') === $sv) ? 'selected' : '' ?>><?= $sl ?></option><?php endforeach; ?></select></td>
                            <td style="padding:3px 6px"><input type="text" name="item_master_code[]" placeholder="GF-002" value="<?= h($br['master_code'] ?? '') ?>" <?= $editable ? '' : 'disabled' ?>></td>
                            <td style="padding:3px 6px"><input type="text" name="item_name[]" placeholder="LED Atrium" value="<?= h($br['name'] ?? '') ?>" <?= $editable ? '' : 'disabled' ?>></td>
                            <td style="padding:3px 6px"><input type="text" inputmode="decimal" class="item-area" name="item_area[]" placeholder="0" style="width:72px" value="<?= h((string) ($br['area'] ?? '')) ?>" <?= $editable ? '' : 'disabled' ?>></td>
                            <td style="padding:3px 6px"><input type="text" inputmode="numeric" class="item-harga" name="item_monthly[]" placeholder="0" value="<?= h($rupFmt($br['monthly'] ?? '')) ?>" <?= $editable ? '' : 'disabled' ?>></td>
                            <td style="padding:3px 6px"><input type="text" inputmode="numeric" name="item_dp[]" placeholder="0" value="<?= h($rupFmt($br['dp'] ?? '')) ?>" <?= $editable ? '' : 'disabled' ?>></td>
                            <td style="padding:3px 6px"><input type="text" inputmode="numeric" name="item_deposit[]" placeholder="0" value="<?= h($rupFmt($br['deposit'] ?? '')) ?>" <?= $editable ? '' : 'disabled' ?>></td>
                            <td style="padding:3px 6px"><button type="button" class="btn light bundle-del" style="padding:4px 8px;background:#fee2e2;color:#991b1b" <?= $editable ? '' : 'disabled' ?>>hapus</button></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if ($editable): ?>
                <p style="margin-top:8px"><button type="button" class="btn light" id="bundle-add" style="background:#0ea5e9;color:#fff">+ Tambah komponen</button></p>
                <?php endif; ?>

                <?php /* ── Tarif bertingkat per m² ────────────────────────────
                         Dipakai bila harga melekat ke PAKET, bukan ke lokasinya
                         satu per satu: mis. 48 m² dengan tarif A dan 32 m²
                         sisanya dengan tarif B. Nilai paket dihitung di sini,
                         lalu dibagi ke tiap lokasi menurut luasnya. */ ?>
                <div style="margin-top:14px;border-top:1px dashed var(--border,#e2e8f0);padding-top:12px">
                    <label style="display:flex;gap:8px;align-items:flex-start;cursor:pointer">
                        <input type="checkbox" name="pakai_tier" id="pakai_tier" value="1" style="width:16px;height:16px;flex:none;margin-top:2px" <?= $tierRows ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?>>
                        <span><strong style="color:#0f766e">Harga dihitung per m² bertingkat (untuk seluruh paket)</strong><br>
                        <span class="muted" style="font-size:12px">Centang bila tarifnya berbeda menurut bagian luas &mdash; mis. 48 m² pertama satu tarif, sisanya tarif lain. Harga per komponen akan dihitung otomatis menurut luas tiap lokasi.</span></span>
                    </label>
                    <div id="tier-box" style="margin-top:10px;<?= $tierRows ? '' : 'display:none' ?>">
                        <table class="data" id="tier-tabel" style="width:100%;max-width:780px">
                            <thead><tr>
                                <th style="width:22%">Luas (m²)</th>
                                <th style="width:30%">Tarif / m² / hari</th>
                                <th style="width:38%">Keterangan</th>
                                <th style="width:10%"></th>
                            </tr></thead>
                            <tbody>
                            <?php $barisTier = $tierRows ?: [['area_sqm' => '', 'rate_per_sqm' => '', 'label' => '']]; ?>
                            <?php foreach ($barisTier as $t): ?>
                            <tr>
                                <td><input type="text" inputmode="decimal" class="tier-luas" name="tier_luas[]" placeholder="0" value="<?= h((float) ($t['area_sqm'] ?? 0) > 0 ? rtrim(rtrim(number_format((float) $t['area_sqm'], 2, ',', ''), '0'), ',') : '') ?>" <?= $editable ? '' : 'disabled' ?>></td>
                                <td>
                                    <div style="display:flex;align-items:stretch">
                                        <span style="display:flex;align-items:center;padding:0 9px;background:#f1f5f9;border:1px solid var(--border,#e2e8f0);border-right:none;border-radius:8px 0 0 8px;font-size:12.5px;font-weight:700;color:#475569">Rp</span>
                                        <input type="text" inputmode="numeric" class="tier-tarif" style="border-top-left-radius:0;border-bottom-left-radius:0;flex:1;min-width:0;text-align:right" value="<?= (float) ($t['rate_per_sqm'] ?? 0) > 0 ? number_format((float) $t['rate_per_sqm'], 0, ',', '.') : '' ?>" <?= $editable ? '' : 'disabled' ?>>
                                        <input type="hidden" name="tier_tarif[]" value="<?= (int) ($t['rate_per_sqm'] ?? 0) ?>">
                                    </div>
                                </td>
                                <td><input name="tier_label[]" placeholder="mis. Harga Sewa 75%" value="<?= h($t['label'] ?? '') ?>" <?= $editable ? '' : 'disabled' ?>></td>
                                <td style="text-align:center"><?php if ($editable): ?><button type="button" class="btn warn tier-hapus" style="padding:4px 9px;font-size:12px">&times;</button><?php endif; ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if ($editable): ?>
                        <p style="margin:8px 0 0"><button type="button" class="btn light" id="tier-tambah" style="font-size:12.5px">+ Tambah Tingkatan</button></p>
                        <?php endif; ?>
                        <div id="tier-ringkas" class="help" style="margin-top:7px"></div>
                    </div>
                </div>
            </div>

            <div id="single-fields" style="<?= $isBundle ? 'display:none' : '' ?>">
            <h3>Objek Sewa</h3>
            <div class="form-grid">
                <?php
                $unitLabel = '';
                foreach ($masters as $m) if ($m['code'] === ($offer['master_code'] ?? '')) { $unitLabel = $m['code'] . ' — ' . $m['label']; break; }
                ?>
                <div>
                    <label>Unit / Lokasi</label>
                    <?php if ($editable): ?>
                    <div style="position:relative">
                        <input type="text" id="masterSearch" autocomplete="off" placeholder="Ketik nama unit..." value="<?= h($unitLabel) ?>">
                        <input type="hidden" name="master_code" id="master_code" required value="<?= h($offer['master_code'] ?? '') ?>">
                        <div id="masterDrop"></div>
                    </div>
                    <?php /* Daftarnya dari Master Exhibition saja. Unit Gudang & Media
                             tidak lewat Surat Penawaran, jadi sengaja tidak muncul. */ ?>
                    <div class="help">Hanya unit dari <strong>Master Exhibition</strong>. Unit <strong>Gudang</strong> &amp; <strong>Media</strong> tidak lewat Surat Penawaran &mdash; dokumennya dibuat di <strong>SKP Pameran &rarr; + Buat Dokumen</strong>.</div>
                    <?php else: ?>
                    <input type="text" value="<?= h($unitLabel) ?>" disabled>
                    <input type="hidden" name="master_code" id="master_code" value="<?= h($offer['master_code'] ?? '') ?>">
                    <?php endif; ?>
                </div>
                <div><label>Luas (m²)</label><input type="number" step="0.01" name="area_sqm" id="area_sqm" value="<?= $v('area_sqm') ?>" <?= $disabled ?>></div>
                <?php if ($module === 'media'): ?>
                <div id="slots_wrap" style="display:none">
                    <label>Jumlah Slot</label>
                    <input type="number" name="slots" id="slots_input" min="1" value="<?= $v('slots', '1') ?>" <?= $disabled ?>>
                    <div class="help">1 media = 12 slot video. Isi jumlah slot yang dibeli.</div>
                </div>
                <?php else: ?>
                <input type="hidden" name="slots" value="1">
                <?php endif; ?>
                <?php /* Ukuran apa adanya (mis. 2x3 m2) — surat kertas menulis ukuran
                         unitnya, bukan hasil kali luasnya. */ ?>
                <div><label>Ukuran <span class="muted" style="font-weight:400">(opsional)</span></label>
                    <input name="ukuran" value="<?= h((string) ($offer['ukuran'] ?? '')) ?>" placeholder="mis. 2x3 m2" <?= $disabled ?>>
                    <div class="help">Kalau diisi, inilah yang tercetak di kolom Luas. Kosong = pakai luas m&sup2;.</div>
                </div>
                <div><label>Pricing Type</label>
                    <select name="pricing_type" id="pricing_type" <?= $disabled ?>>
                        <?php foreach (['daily_area', 'daily_slot', 'daily_point', 'monthly', 'fixed'] as $o): ?><option <?= ($offer['pricing_type'] ?? '') === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div><label>Rate</label><input type="number" step="0.01" name="unit_rate" id="unit_rate" value="<?= $v('unit_rate') ?>" <?= $disabled ?>></div>
                <div class="wide"><label>Keterangan</label><input name="keterangan" value="<?= $v('keterangan') ?>" <?= $disabled ?>></div>
            </div>
            </div><!-- /#single-fields (Objek Sewa) -->

            <h3>Periode</h3>
            <div class="form-grid">
                <div><label>Tanggal Mulai</label><input type="date" name="start_date" id="start_date" value="<?= $v('start_date') ?>" required <?= $disabled ?>></div>
                <div><label>Tanggal Selesai</label><input type="date" name="end_date" id="end_date" data-min-dari="start_date" value="<?= $v('end_date') ?>" required <?= $disabled ?>></div>
            </div>

            <h3>Pengakuan & Recurring</h3>
            <div class="form-grid">
                <div>
                    <label>Metode Pengakuan</label>
                    <select name="billing_method" id="billing_method" <?= $disabled ?>>
                        <?php $bm = $offer['billing_method'] ?? ''; ?>
                        <option value="" <?= $bm === '' ? 'selected' : '' ?>>Otomatis (ikut periode)</option>
                        <option value="anchor_cycle" <?= $bm === 'anchor_cycle' ? 'selected' : '' ?>>Sekaligus (anchor) — diakui 1 bulan</option>
                        <option value="spread" <?= $bm === 'spread' ? 'selected' : '' ?>>Spread per Bulan (recurring)</option>
                    </select>
                    <div class="help" id="billing_help">Otomatis: multi-bulan/lintas bulan → Spread (recurring); selainnya → Sekaligus.</div>
                </div>
                <div id="cycle_wrap">
                    <label>Pengakuan per Siklus</label>
                    <select name="cycle_recognition" id="cycle_recognition" <?= $disabled ?>>
                        <option value="cycle_start" <?= ($offer['cycle_recognition'] ?? 'cycle_start') === 'cycle_start' ? 'selected' : '' ?>>Bulan Awal siklus</option>
                        <option value="cycle_end" <?= ($offer['cycle_recognition'] ?? '') === 'cycle_end' ? 'selected' : '' ?>>Bulan Akhir siklus</option>
                    </select>
                </div>
                <div class="wide" style="display:flex;align-items:flex-start;gap:10px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:11px 14px">
                    <input type="checkbox" name="prorata_kalender" id="prorata_kalender" value="1" style="width:18px;height:18px;flex-shrink:0;margin-top:1px" <?= !empty($offer['prorata_kalender']) ? 'checked' : '' ?> <?= $disabled ?>>
                    <label for="prorata_kalender" style="margin:0;cursor:pointer">
                        <span style="font-weight:700;color:#92400e">Prorata ikut bulan kalender</span>
                        <span class="help" style="display:block;margin-top:2px;font-weight:400">
                            Centang bila kontrak tidak mulai di tanggal 1. Bulan yang tidak penuh dihitung
                            <strong>per hari bulan itu</strong> (mis. 11&ndash;30 November = 20/30 bulan), sisanya bulan penuh &mdash;
                            cara yang dipakai surat penawaran kertas. Tanpa centang, siklusnya mengikuti tanggal mulai
                            (11 &rarr; 10) dan totalnya bisa berbeda ratusan ribu. Hanya berlaku untuk Pricing Type <strong>monthly</strong>.
                        </span>
                    </label>
                </div>
                <div class="wide" style="display:flex;align-items:flex-start;gap:10px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:11px 14px">
                    <input type="checkbox" name="recurring_flag" id="recurring_flag" value="1" style="width:18px;height:18px;flex-shrink:0;margin-top:1px" <?= !empty($offer['recurring_flag']) ? 'checked' : '' ?> <?= $disabled ?>>
                    <label for="recurring_flag" style="margin:0;cursor:pointer">
                        <span style="font-weight:700;color:#0369a1">Diakui Recurring</span>
                        <span class="help" style="display:block;margin-top:2px;font-weight:400">Centang bila kontrak ini berulang. Diteruskan ke transaksi saat konfirmasi disetujui.</span>
                    </label>
                </div>
            </div>

            <?php /* Semua yang menyangkut uang dikumpulkan di bagian bawah:
                     harga, biaya listrik, PPN, service charge, DP & deposit. */ ?>
            <h3>Harga</h3>
            <div class="form-grid">
                <div class="single-price"><label>Total Kontrak <span class="muted" style="font-weight:400">(otomatis)</span></label><input type="text" id="total_calc" value="" readonly><input type="hidden" name="total_calculated" id="total_calc_h" value="<?= $v('total_calculated') ?>"></div>
                <div class="single-price"><label>Harga / Bulan <span class="muted" style="font-weight:400">(otomatis)</span></label><input type="text" id="monthly_disp" value="" readonly><input type="hidden" name="monthly_amount" id="monthly_amount" value="<?= $v('monthly_amount') ?>"></div>
                <div class="wide single-price"><label>Harga Nego Final <span class="muted" style="font-weight:400">(opsional — override)</span></label><input type="text" inputmode="numeric" id="override_fmt" placeholder="Kosongkan = pakai hasil kalkulasi di atas"><input type="hidden" name="override_amount" id="override_amount" value="<?= (int)($offer['override_amount'] ?? 0) ?: '' ?>"><div class="help">Override: isi bila nilai final tidak sama dengan hasil kalkulasi.</div></div>
                <?php /* Biaya listrik — kolom biasa seperti isian lain, bukan panel
                         tersendiri. Lebar kotak centang dipatok karena CSS global
                         membuat semua input selebar kolom. */ ?>
                <?php if ($module === 'cl'): ?>
                <div class="listrik-kolom">
                    <label style="display:flex;align-items:center;gap:7px;cursor:pointer">
                        <input type="checkbox" name="electricity_flag" id="listrik_on" value="1" style="width:16px;height:16px;flex:none;margin:0" <?= $listrikOn ? 'checked' : '' ?> <?= $disabled ?>>
                        Biaya Listrik Per Bulan
                    </label>
                    <div id="listrik_box" style="display:flex;align-items:stretch">
                        <span style="display:flex;align-items:center;padding:0 10px;background:#f1f5f9;border:1px solid var(--border,#e2e8f0);border-right:none;border-radius:8px 0 0 8px;font-size:13px;font-weight:700;color:#475569">Rp</span>
                        <input type="text" inputmode="numeric" id="listrik_fmt"
                               value="<?= $listrikRp > 0 ? number_format($listrikRp, 0, ',', '.') : '' ?>"
                               style="border-top-left-radius:0;border-bottom-left-radius:0;flex:1;min-width:0;text-align:right" <?= $disabled ?>>
                        <input type="hidden" name="electricity_monthly" id="listrik_val" value="<?= (int) $listrikRp ?>">
                    </div>
                    <div class="help" id="listrik_info">Standar <?= h(number_format((float) ($tplBaru['electricity_default'] ?? 150000), 0, ',', '.')) ?> per 30 hari — boleh diubah.</div>
                </div>
                <div class="listrik-kolom" id="listrik_unit_wrap">
                    <label>Jumlah Satuan <span class="muted" style="font-weight:400">(1 satuan = 30 hari)</span></label>
                    <select name="electricity_units" id="listrik_unit" <?= $disabled ?>>
                        <option value="0" <?= $listrikUnit <= 0 ? 'selected' : '' ?>>Otomatis — ikut lama sewa</option>
                        <?php for ($u = 1; $u <= 12; $u++): ?>
                        <option value="<?= $u ?>" <?= $listrikUnit === $u ? 'selected' : '' ?>><?= $u ?> ×</option>
                        <?php endfor; ?>
                    </select>
                    <div class="help" id="listrik_unit_info"></div>
                </div>
                <?php /* Angka yang BENAR-BENAR tercetak di surat, ditampilkan sendiri
                         supaya sales tidak lagi mengira yang diketik = yang ditagih. */ ?>
                <div class="listrik-kolom" id="listrik_total_wrap">
                    <label>Total Biaya Listrik <span class="muted" style="font-weight:400">(sebelum PPN)</span></label>
                    <div style="display:flex;align-items:stretch">
                        <span style="display:flex;align-items:center;padding:0 10px;background:#f1f5f9;border:1px solid var(--border,#e2e8f0);border-right:none;border-radius:8px 0 0 8px;font-size:13px;font-weight:700;color:#475569">Rp</span>
                        <input type="text" inputmode="numeric" id="listrik_total"
                               value="<?= $listrikTotal > 0 ? number_format($listrikTotal, 0, ',', '.') : '' ?>"
                               placeholder="otomatis"
                               style="border-top-left-radius:0;border-bottom-left-radius:0;flex:1;min-width:0;text-align:right;font-weight:700" <?= $disabled ?>>
                        <input type="hidden" name="electricity_amount" id="listrik_total_val" value="<?= (int) $listrikTotal ?>">
                    </div>
                    <div class="help" id="listrik_total_info"></div>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($module === 'cl'): ?>

            <script>
            (function () {
                var on = document.getElementById('listrik_on'),
                    fmt = document.getElementById('listrik_fmt'),
                    val = document.getElementById('listrik_val'),
                    box = document.getElementById('listrik_box'),
                    info = document.getElementById('listrik_info'),
                    d1 = document.getElementById('start_date'),
                    d2 = document.getElementById('end_date');
                if (!on || !fmt) return;

                // Bulan listrik dihitung dari LAMA HARI sewa (30 hari = 1 bulan,
                // dibulatkan, minimal 1) — rumusnya sama persis dengan
                // offer_listrik_bulan() di PHP supaya angka di layar dan di surat
                // tidak pernah berbeda.
                function hari() {
                    if (!d1 || !d2 || !d1.value || !d2.value) return 0;
                    var a = new Date(d1.value), b = new Date(d2.value);
                    if (isNaN(a) || isNaN(b) || b < a) return 0;
                    return Math.floor((b - a) / 86400000) + 1;
                }
                var unitSel = document.getElementById('listrik_unit'),
                    unitInfo = document.getElementById('listrik_unit_info'),
                    unitWrap = document.getElementById('listrik_unit_wrap');
                function satuanOtomatis() {
                    var h = hari();
                    return h > 0 ? Math.max(1, Math.round(h / 30)) : 1;
                }
                // Jumlah satuan: pilihan sendiri menang atas hitungan otomatis.
                function bulan() {
                    var pilih = unitSel ? parseInt(unitSel.value, 10) || 0 : 0;
                    return pilih > 0 ? pilih : satuanOtomatis();
                }
                function angka() { return parseInt((fmt.value || '').replace(/\D/g, ''), 10) || 0; }
                var totWrap = document.getElementById('listrik_total_wrap'),
                    totBox  = document.getElementById('listrik_total'),
                    totVal  = document.getElementById('listrik_total_val'),
                    totInfo = document.getElementById('listrik_total_info');
                // Sudah ada isinya saat halaman dibuka = pernah diketik sendiri.
                if (totBox && totBox.value.replace(/\D/g, '') !== '') totBox.dataset.manual = '1';
                function angkaTot() { return parseInt((totBox.value || '').replace(/\D/g, ''), 10) || 0; }
                function gambar() {
                    box.style.display = on.checked ? 'flex' : 'none';
                    if (totWrap) totWrap.style.display = on.checked ? '' : 'none';
                    val.value = on.checked ? angka() : 0;
                    if (info) info.style.display = on.checked ? '' : 'none';
                    if (!on.checked) return;
                    var n = bulan(), t = angka(), h = hari();
                    var rp = function (x) { return 'Rp ' + (x || 0).toLocaleString('id-ID'); };
                    var ppn = Math.round(n * t * 11 / 12 * 0.12);

                    // "Sedang/pernah diketik sendiri" — kotaknya tidak ditimpa,
                    // termasuk saat isinya baru dikosongkan untuk diganti.
                    if (unitWrap) unitWrap.style.display = on.checked ? '' : 'none';
                    var manual = totBox && totBox.dataset.manual === '1';
                    var adaTotal = manual && angkaTot() > 0;
                    if (unitSel) unitSel.disabled = adaTotal;
                    if (unitInfo) {
                        var pilih = unitSel ? parseInt(unitSel.value, 10) || 0 : 0;
                        unitInfo.textContent = adaTotal
                            ? 'Diabaikan — Total diisi sendiri.'
                            : (pilih > 0
                                ? 'Dipilih sendiri. Otomatisnya ' + satuanOtomatis() + ' ×.'
                                : (h ? h + ' hari → ' + satuanOtomatis() + ' ×' : 'Ikut lama sewa.'));
                    }
                    if (totBox && !manual) totBox.value = (n * t).toLocaleString('id-ID');
                    var isiManual = manual ? angkaTot() : 0;
                    var dipakai = isiManual > 0 ? isiManual : n * t;
                    var ppnPakai = Math.round(dipakai * 11 / 12 * 0.12);
                    if (totVal) totVal.value = isiManual > 0 ? isiManual : 0;
                    if (totInfo) {
                        // PPN selalu disebut — pertanyaan "ini sudah sama PPN belum?"
                        // tidak boleh perlu ditebak.
                        var ppnTeks = '<b>belum termasuk PPN</b> · PPN 12% ' + rp(ppnPakai)
                            + ' · dibayar ' + rp(dipakai + ppnPakai);
                        if (isiManual > 0) {
                            totInfo.innerHTML = 'Diisi sendiri, ' + ppnTeks
                                + '<br>Kalau dikosongkan: ' + n + ' × ' + rp(t) + ' = ' + rp(n * t)
                                + ' — <a href="#" id="listrik_auto">pakai hitungan otomatis</a>';
                        } else {
                            totInfo.innerHTML = (h ? h + ' hari → ' : 'Tanggal belum diisi · ')
                                + n + ' × ' + rp(t) + ' · ' + ppnTeks;
                        }
                        var lk = document.getElementById('listrik_auto');
                        if (lk) lk.addEventListener('click', function (e) {
                            e.preventDefault(); totBox.dataset.manual = ''; gambar();
                        });
                    }
                }
                fmt.addEventListener('input', function () {
                    var raw = this.value.replace(/\D/g, '');
                    this.value = raw ? parseInt(raw, 10).toLocaleString('id-ID') : '';
                    fmt.dataset.manual = '1';
                    gambar();
                });
                window.claraSetListrik = function (nilai) {
                    nilai = parseInt(nilai, 10) || 0;
                    if (!nilai || fmt.dataset.manual === '1') return;
                    fmt.value = nilai.toLocaleString('id-ID');
                    gambar();
                };
                on.addEventListener('change', gambar);
                // Ganti jumlah satuan = minta dihitung ulang, bukan pakai angka lama.
                if (unitSel) unitSel.addEventListener('change', gambar);
                if (totBox) {
                    totBox.addEventListener('input', function () {
                        var raw = this.value.replace(/\D/g, '');
                        this.value = raw ? parseInt(raw, 10).toLocaleString('id-ID') : '';
                        this.dataset.manual = '1';   // dikosongkan pun jangan diisi ulang
                        gambar();
                    });
                    // Ditinggal dalam keadaan kosong = minta hitungan otomatis lagi.
                    totBox.addEventListener('blur', function () {
                        if (this.value.replace(/\D/g, '') === '') { this.dataset.manual = ''; gambar(); }
                    });
                }
                if (d1) d1.addEventListener('change', gambar);
                if (d2) d2.addEventListener('change', gambar);
                gambar();
            })();
            </script>
            <?php endif; ?>

            <?php if ($editable): ?>
            <div class="single-price" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:4px">
                <button type="button" class="btn light" id="btn-kalkulasi" style="background:#0ea5e9;color:#fff">Kalkulasi Total</button>
                <div id="kalkulasi-result" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:8px 14px;font-size:13px;color:#166534"></div>
            </div>
            <div id="kalkulasi-spread" class="single-price" style="display:none;margin-top:8px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 16px;font-size:12.5px;line-height:1.7"></div>
            <div id="overlap-warn" class="single-price" style="display:none;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:10px 14px;margin-top:10px;font-size:12.5px;color:#92400e"></div>
            <?php endif; ?>


            <?php /* Jadwal harga bertahap: satu kontrak, harga berbeda mulai
                     tanggal tertentu (mis. 2 tahun pertama diskon 20%, tahun
                     ke-3 diskon 10%). Kosong = satu harga untuk seluruh periode. */ ?>
            <div id="tahap-box" class="single-price" style="margin-top:4px">
                <h3 style="margin-bottom:4px">Jadwal Harga Bertahap <span style="font-weight:400;font-size:12px;color:var(--muted)">(opsional &mdash; isi bila harganya berubah di tengah kontrak)</span></h3>
                <table class="data" id="tahap-tabel" style="width:100%;max-width:720px">
                    <thead><tr>
                        <th style="width:30%">Berlaku Mulai</th>
                        <th style="width:34%">Harga / Bulan</th>
                        <th style="width:28%">Keterangan</th>
                        <th style="width:8%"></th>
                    </tr></thead>
                    <tbody>
                    <?php $barisTahap = $tahapHarga ?: [['from' => '', 'amount' => '', 'label' => '']]; ?>
                    <?php foreach ($barisTahap as $t): ?>
                    <tr>
                        <td><input type="date" name="tahap_mulai[]" value="<?= h($t['from'] ?? '') ?>" <?= $disabled ?>></td>
                        <td>
                            <div style="display:flex;align-items:stretch">
                                <span style="display:flex;align-items:center;padding:0 9px;background:#f1f5f9;border:1px solid var(--border,#e2e8f0);border-right:none;border-radius:8px 0 0 8px;font-size:12.5px;font-weight:700;color:#475569">Rp</span>
                                <input type="text" inputmode="numeric" class="tahap-nilai" value="<?= (float) ($t['amount'] ?? 0) > 0 ? number_format((float) $t['amount'], 0, ',', '.') : '' ?>"
                                       style="border-top-left-radius:0;border-bottom-left-radius:0;flex:1;min-width:0;text-align:right" <?= $disabled ?>>
                                <input type="hidden" name="tahap_nilai[]" value="<?= (int) ($t['amount'] ?? 0) ?>">
                            </div>
                        </td>
                        <td><input name="tahap_label[]" value="<?= h($t['label'] ?? '') ?>" placeholder="mis. Tahun 1-2 (diskon 20%)" <?= $disabled ?>></td>
                        <td style="text-align:center"><?php if ($editable): ?><button type="button" class="btn warn tahap-hapus" style="padding:4px 9px;font-size:12px">×</button><?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($editable): ?>
                <p style="margin:8px 0 0"><button type="button" class="btn light" id="tahap-tambah" style="font-size:12.5px">+ Tambah Tahap</button></p>
                <?php endif; ?>
                <div id="tahap-ringkas" class="help" style="margin-top:7px"></div>
            </div>

            <div id="single-pay" style="<?= $isBundle ? 'display:none' : '' ?>">
            <h3>Pembayaran <span style="font-weight:400;font-size:12px;color:var(--muted)">(DP & deposit dihitung dari harga/bulan; bisa di-override)</span></h3>
            <div id="tpl-note" style="display:none;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:9px 13px;margin-bottom:10px;font-size:12.5px;color:#075985"></div>
            <div class="form-grid">
                <div><label>DP (bulan, min 1)</label><input type="number" step="0.5" min="1" name="dp_months" id="dp_months" value="<?= $v('dp_months', '1') ?>" <?= $disabled ?>></div>
                <div><label>Nominal DP <span class="muted" style="font-weight:400">(otomatis, bisa diubah)</span></label><input type="text" inputmode="numeric" id="dp_fmt" placeholder="0" <?= $disabled ?>><input type="hidden" name="dp_amount" id="dp_amount" value="<?= $existing ? (int)($offer['dp_amount'] ?? 0) : '' ?>"></div>
                <div><label>Deposit (bulan)</label><input type="number" step="0.5" min="0" name="deposit_months" id="deposit_months" value="<?= $v('deposit_months', '1') ?>" <?= $disabled ?>></div>
                <div><label>Nominal Deposit <span class="muted" style="font-weight:400">(otomatis, bisa diubah)</span></label><input type="text" inputmode="numeric" id="dep_fmt" placeholder="0" <?= $disabled ?>><input type="hidden" name="deposit_amount" id="deposit_amount" value="<?= $existing ? (int)($offer['deposit_amount'] ?? 0) : '' ?>"></div>
            </div>
            </div><!-- /#single-pay -->

            <?php /* Berlaku untuk penawaran satuan maupun paket: pada perpanjangan,
                     deposit sudah disetor di kontrak sebelumnya. */ ?>
            <?php /* Bawaannya tercentang: hampir semua penyewa dikenakan PPN.
                     Dilepas hanya untuk penyewa yang harganya memang bersih. */ ?>
            <div style="margin-top:10px">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400">
                    <input type="checkbox" name="ppn_flag" id="ppn_flag" value="1" style="width:16px;height:16px;flex:none;margin:0" <?= (!$existing || !empty($offer['ppn_flag'])) ? 'checked' : '' ?> <?= $disabled ?>>
                    <span><strong>Kenakan PPN 12%</strong> <span class="muted">&mdash; lepas centang bila harganya bersih; baris PPN tidak akan dicetak di surat</span></span>
                </label>
                <?php /* Service Charge: ditagih per bulan, tercetak sebagai blok C
                         di surat. Bawaannya tidak dikenakan. */ ?>
                <label style="display:flex;align-items:center;gap:8px;margin-top:9px;cursor:pointer;font-weight:400">
                    <input type="checkbox" name="sc_flag" id="sc_flag" value="1" style="width:16px;height:16px;flex:none;margin:0" <?= !empty($offer['sc_flag']) ? 'checked' : '' ?> <?= $disabled ?>>
                    <span><strong>Kenakan Service Charge</strong> <span class="muted">&mdash; ditagih per bulan, tercetak sebagai blok tersendiri</span></span>
                </label>
                <div id="sc_box" style="margin-top:8px;max-width:320px">
                    <label>Biaya Service Charge / Bulan</label>
                    <div style="display:flex;align-items:stretch">
                        <span style="display:flex;align-items:center;padding:0 10px;background:#f1f5f9;border:1px solid var(--border,#e2e8f0);border-right:none;border-radius:8px 0 0 8px;font-size:13px;font-weight:700;color:#475569">Rp</span>
                        <input type="text" inputmode="numeric" id="sc_fmt" value="<?= (float) ($offer['sc_monthly'] ?? 0) > 0 ? number_format((float) $offer['sc_monthly'], 0, ',', '.') : '' ?>" placeholder="0"
                               style="border-top-left-radius:0;border-bottom-left-radius:0;flex:1;min-width:0;text-align:right" <?= $disabled ?>>
                        <input type="hidden" name="sc_monthly" id="sc_monthly" value="<?= (int) ($offer['sc_monthly'] ?? 0) ?>">
                    </div>
                    <div class="help" id="sc_info"></div>
                </div>
            </div>
            <div style="margin-top:10px">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400">
                    <input type="checkbox" name="deposit_paid" id="deposit_paid" value="1" style="width:16px;height:16px;flex:none;margin:0" <?= !empty($offer['deposit_paid']) ? 'checked' : '' ?> <?= $disabled ?>>
                    <span><strong style="color:#166534">Security Deposit sudah dibayarkan</strong> <span class="muted">&mdash; tetap tercantum di surat, tapi tidak ditambahkan ke Grand Total</span></span>
                </label>
            </div>

            <?php if ($offer && $editable): ?>
            <h3>Catatan Revisi / Nego</h3>
            <input name="rev_note" placeholder="mis. turun harga jadi 20jt, tambah 1 bulan gratis…" <?= $disabled ?> style="width:100%">
            <?php endif; ?>

            <?php if ($editable): ?>
            <p class="form-actions" style="margin-top:16px"><button type="submit">💾 <?= $existing ? 'Simpan Revisi' : 'Simpan Penawaran' ?></button> <a class="btn secondary" href="?r=offers">Batal</a></p>
            <?php endif; ?>
        </form>

        <script>
        (function () {
            // esc() didefinisikan global di assets/spread-table.js (M2).
            var contacts =<?= json_encode($contacts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            var clientHid = document.getElementById('client_id'), contactSel = document.getElementById('contact_id');
            var curContact = <?= (int)($offer['contact_id'] ?? 0) ?>;
            function fillContacts() {
                var cid = parseInt((clientHid && clientHid.value) || '0', 10);
                if (!contactSel) return;
                contactSel.innerHTML = '<option value="">- Pilih -</option>';
                contacts.filter(function (c) { return parseInt(c.client_id, 10) === cid; }).forEach(function (c) {
                    var o = document.createElement('option'); o.value = c.id; o.textContent = c.name;
                    if (parseInt(c.id, 10) === curContact) o.selected = true;
                    contactSel.appendChild(o);
                });
            }
            fillContacts();

            // ── Paket Bundling: toggle mode + editor komponen ──────────────────
            var bundleChk = document.getElementById('is_bundle');
            function bundleOn() { return !!(bundleChk && bundleChk.checked); }
            function setVis(el, show) { if (el) el.style.display = show ? '' : 'none'; }
            function applyBundleMode() {
                var on = bundleOn();
                setVis(document.getElementById('bundle-fields'), on);
                setVis(document.getElementById('single-fields'), !on);
                setVis(document.getElementById('single-pay'), !on);
                setVis(document.getElementById('ph-price-hd'), !on);
                document.querySelectorAll('.single-price').forEach(function (el) {
                    // hormati elemen yg memang disembunyikan default (spread/overlap)
                    if (on) { el.dataset.prevDisplay = el.style.display; el.style.display = 'none'; }
                    else { el.style.display = el.dataset.prevDisplay || ''; }
                });
                // master_code wajib hanya di mode tunggal (agar paket bisa disubmit)
                var mc = document.getElementById('master_code');
                if (mc) { if (on) mc.removeAttribute('required'); else mc.setAttribute('required', 'required'); }
            }
            // format ribuan utk input nominal komponen
            function bundleFmt(inp) {
                inp.addEventListener('input', function () {
                    var raw = this.value.replace(/\D/g, '');
                    this.value = raw ? parseInt(raw, 10).toLocaleString('id-ID') : '';
                });
            }
            function wireRow(tr) {
                tr.querySelectorAll('input[name="item_monthly[]"],input[name="item_dp[]"],input[name="item_deposit[]"]').forEach(bundleFmt);
                // Luas komponen diambil dari Master begitu kode unitnya diketik,
                // supaya pembagian nilai paket memakai angka resmi, bukan ketikan.
                var kode = tr.querySelector('input[name="item_master_code[]"]');
                var luas = tr.querySelector('.item-area');
                if (kode && luas) kode.addEventListener('change', function () {
                    var m = (window.claraByCode || {})[this.value.trim()];
                    if (m && m.area_sqm && !luas.value) {
                        luas.value = String(m.area_sqm).replace('.', ',').replace(/,00$/, '');
                        if (window.claraHitungTier) window.claraHitungTier();
                    }
                });
                var del = tr.querySelector('.bundle-del');
                if (del) del.addEventListener('click', function () {
                    var body = document.getElementById('bundle-body');
                    if (body && body.querySelectorAll('.bundle-row').length > 1) tr.remove();
                    else { tr.querySelectorAll('input').forEach(function (i) { i.value = ''; }); }
                });
            }
            document.querySelectorAll('#bundle-body .bundle-row').forEach(wireRow);
            var addBtn = document.getElementById('bundle-add');
            if (addBtn) addBtn.addEventListener('click', function () {
                var body = document.getElementById('bundle-body');
                var rows = body.querySelectorAll('.bundle-row');
                var clone = rows[rows.length - 1].cloneNode(true);
                clone.querySelectorAll('input').forEach(function (i) { i.value = ''; });
                var sel = clone.querySelector('select[name="item_segment[]"]'); if (sel) sel.selectedIndex = 0;
                body.appendChild(clone); wireRow(clone);
            });
            if (bundleChk) bundleChk.addEventListener('change', applyBundleMode);
            applyBundleMode();

            // ── Tarif bertingkat per m² (harga melekat ke PAKET) ─────────────
            (function () {
                var chk   = document.getElementById('pakai_tier');
                var box   = document.getElementById('tier-box');
                var tabel = document.getElementById('tier-tabel');
                if (!chk || !box || !tabel) return;
                var ringkas = document.getElementById('tier-ringkas');
                function rp(x) { return 'Rp ' + Math.round(x || 0).toLocaleString('id-ID'); }
                function num(v) { return parseFloat(String(v || '').replace(/\./g, '').replace(',', '.')) || 0; }
                function hari() {
                    var a = (document.getElementById('start_date') || {}).value,
                        b = (document.getElementById('end_date') || {}).value;
                    if (!a || !b) return 0;
                    var d = Math.round((new Date(b) - new Date(a)) / 86400000) + 1;
                    return d > 0 ? d : 0;
                }
                function luasKomponen() {
                    var t = 0;
                    document.querySelectorAll('#bundle-body .item-area').forEach(function (i) { t += num(i.value); });
                    return t;
                }
                function hitung() {
                    var n = hari(), total = 0, luas = 0, rinci = [];
                    tabel.querySelectorAll('tbody tr').forEach(function (tr) {
                        var m = num(tr.querySelector('.tier-luas').value);
                        var f = tr.querySelector('.tier-tarif'), hid = tr.querySelector('input[type=hidden]');
                        var r = parseInt((f.value || '').replace(/\D/g, ''), 10) || 0;
                        if (hid) hid.value = r;
                        if (m > 0 && r > 0) { total += m * r * n; luas += m; rinci.push(m + ' m² × ' + rp(r)); }
                    });
                    if (!chk.checked) { ringkas.textContent = ''; return; }
                    if (!n) { ringkas.innerHTML = '<span style="color:#b45309">Isi tanggal mulai &amp; selesai dulu untuk menghitung totalnya.</span>'; return; }
                    if (!rinci.length) { ringkas.textContent = 'Isi luas dan tarifnya untuk melihat nilai paket.'; return; }
                    var lk = luasKomponen();
                    var pesan = rinci.join(' · ') + ' × ' + n + ' hari &rarr; <b>nilai paket = ' + rp(total) + '</b>';
                    if (lk > 0 && Math.abs(lk - luas) >= 0.01) {
                        pesan += '<div style="margin-top:4px;color:#92400e">Luas tingkatan <b>' + luas + ' m²</b>, jumlah luas lokasi <b>' + lk + ' m²</b>'
                               + ' &mdash; boleh berbeda (mis. area sirkulasi ikut dihitung), nilai paket tetap memakai tingkatan di atas.</div>';
                    }
                    ringkas.innerHTML = pesan;
                }
                chk.addEventListener('change', function () { box.style.display = this.checked ? '' : 'none'; hitung(); });
                tabel.addEventListener('input', function (e) {
                    if (e.target.classList.contains('tier-tarif')) {
                        var raw = e.target.value.replace(/\D/g, '');
                        e.target.value = raw ? parseInt(raw, 10).toLocaleString('id-ID') : '';
                    }
                    hitung();
                });
                tabel.addEventListener('click', function (e) {
                    if (!e.target.classList.contains('tier-hapus')) return;
                    var rows = tabel.querySelectorAll('tbody tr');
                    if (rows.length > 1) e.target.closest('tr').remove();
                    else e.target.closest('tr').querySelectorAll('input').forEach(function (i) { i.value = ''; });
                    hitung();
                });
                var tb = document.getElementById('tier-tambah');
                if (tb) tb.addEventListener('click', function () {
                    var baru = tabel.querySelector('tbody tr').cloneNode(true);
                    baru.querySelectorAll('input').forEach(function (i) { i.value = ''; });
                    tabel.querySelector('tbody').appendChild(baru);
                    hitung();
                });
                ['start_date', 'end_date'].forEach(function (id) {
                    var e = document.getElementById(id);
                    if (e) e.addEventListener('change', hitung);
                });
                document.addEventListener('input', function (e) {
                    if (e.target.classList && e.target.classList.contains('item-area')) hitung();
                });
                window.claraHitungTier = hitung;
                hitung();
            })();

            // ── Picker unit (searchable, sama seperti input transaksi) ──
            var masters = <?= json_encode(array_values($masters), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            var byCode = Object.fromEntries(masters.map(function (m) { return [m.code, m]; }));
            window.claraByCode = byCode;   // dipakai baris komponen paket utk mengisi luas
            function parseSizeM2(size) {
                var m = String(size || '').replace(/[mM²]/g, '').match(/(\d+\.?\d*)\s*[×xX]\s*(\d+\.?\d*)/);
                return m ? parseFloat(m[1]) * parseFloat(m[2]) : 0;
            }
            function fillMaster(code) {
                var m = byCode[code]; if (!m) return;
                var area = document.getElementById('area_sqm'), rate = document.getElementById('unit_rate'), pt = document.getElementById('pricing_type');
                if (m.rate && rate && !rate.value) rate.value = m.rate;
                if (m.pricing_type && pt) pt.value = m.pricing_type;
                if (area && !area.value) area.value = m.area_sqm || 0;
                <?php if ($module === 'media'): ?>
                var a = parseSizeM2(m.size); if (a > 0 && area) area.value = a.toFixed(2);
                var mt = (m.media_type || '').toLowerCase(), isSlot = mt === 'tvc' || mt.indexOf('led') === 0;
                var sw = document.getElementById('slots_wrap');
                if (sw) { sw.style.display = isSlot ? '' : 'none'; var si = document.getElementById('slots_input'); if (isSlot && si && (!si.value || si.value == '1')) si.value = m.slots || 1; }
                <?php endif; ?>
                if (typeof kalkulasi === 'function') kalkulasi();
                applyTemplateRule(code);
            }
            // Template per jenis booth: tampilkan & atur aturan DP saat unit dipilih.
            // Ganti template di dropdown → aturan DP & baseline listrik ikut
            // menyesuaikan, supaya isian formulir tidak bertentangan dengan surat
            // yang akan terbit. Angka yang sudah diketik sales tidak ditimpa.
            (function () {
                var selTpl = document.querySelector('select[name=template_id]');
                if (!selTpl) return;
                selTpl.addEventListener('change', function () {
                    var mc = document.querySelector('[name=master_code]');
                    applyTemplateRule(mc ? (mc.value || '-') : '-');
                });
            })();
            function applyTemplateRule(code) {
                var note = document.getElementById('tpl-note'); if (!note || !code) return;
                var selTpl = document.querySelector('select[name=template_id]');
                var idTpl  = selTpl ? (selTpl.value || 0) : 0;
                fetch('?r=offer_template_rule&master_code=' + encodeURIComponent(code) + '&template_id=' + encodeURIComponent(idTpl), { cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        var dpm = document.getElementById('dp_months');
                        if (d.dp_required) {
                            note.innerHTML = '📄 Tipe <strong>' + esc(d.unit_type || '-') + '</strong> · template <strong>' + esc(d.template) + '</strong> · <strong>DP wajib</strong> (default ' + esc(d.dp_months_default) + ' bln).';
                            if (dpm) { dpm.min = '1'; if (!dpm.value || dpm.value === '0') dpm.value = d.dp_months_default || 1; }
                            // Baseline listrik ikut tipe unit — hanya diperbarui
                            // selama sales belum mengetik nominalnya sendiri.
                            if (typeof claraSetListrik === 'function') claraSetListrik(d.electricity_default);
                        } else {
                            note.innerHTML = '📄 Tipe <strong>' + esc(d.unit_type || '-') + '</strong> · template <strong>' + esc(d.template) + '</strong> · <strong>tanpa DP</strong> (deposit-only). Kosongkan DP.';
                            if (dpm) { dpm.min = '0'; dpm.value = '0'; }
                        }
                        note.style.display = '';
                        if (typeof kalkulasi === 'function') kalkulasi();
                    })
                    .catch(function () {});
            }
            (function () { var mc = (document.getElementById('master_code') || {}).value; if (mc) applyTemplateRule(mc); })();
            (function () {
                var src = document.getElementById('masterSearch'), hid = document.getElementById('master_code'), dd = document.getElementById('masterDrop');
                if (!src || !hid || !dd) return;
                document.body.appendChild(dd);
                dd.style.cssText = 'display:none;position:fixed;background:#fff;border:1px solid var(--line);border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.12);z-index:9000;max-height:260px;overflow-y:auto';
                function pos() { var r = src.getBoundingClientRect(); dd.style.top = (r.bottom + 2) + 'px'; dd.style.left = r.left + 'px'; dd.style.width = r.width + 'px'; }
                function render(q) {
                    pos(); var lq = q.toLowerCase().trim();
                    var list = lq ? masters.filter(function (m) { return m.label.toLowerCase().includes(lq) || m.code.toLowerCase().includes(lq); }) : masters;
                    dd.innerHTML = '';
                    list.slice(0, 80).forEach(function (m) {
                        var d = document.createElement('div');
                        d.style.cssText = 'padding:9px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;gap:8px';
                        d.innerHTML = '<span style="font-weight:600">' + m.label + '</span><span style="color:var(--muted);font-size:11px;flex-shrink:0">' + m.code + '</span>';
                        d.addEventListener('mouseover', function () { this.style.background = '#f0fdf4'; });
                        d.addEventListener('mouseout', function () { this.style.background = ''; });
                        d.addEventListener('mousedown', function (e) { e.preventDefault(); src.value = m.code + ' — ' + m.label; hid.value = m.code; src.style.outline = ''; dd.style.display = 'none'; fillMaster(m.code); });
                        dd.appendChild(d);
                    });
                    if (!list.length) dd.innerHTML = '<div style="padding:10px 14px;font-size:13px;color:var(--muted)">Tidak ditemukan</div>';
                    dd.style.display = '';
                }
                src.addEventListener('input', function () { hid.value = ''; render(this.value); });
                src.addEventListener('focus', function () { render(this.value); });
                src.addEventListener('blur', function () { setTimeout(function () { dd.style.display = 'none'; }, 200); });
                window.addEventListener('scroll', function () { if (dd.style.display !== 'none') pos(); }, true);
                document.querySelectorAll('#offer-form button[type=submit]').forEach(function (btn) {
                    btn.addEventListener('click', function (e) { if (bundleOn()) return; if (!hid.value) { e.preventDefault(); e.stopImmediatePropagation(); src.style.outline = '2px solid #EF4444'; src.focus(); } });
                });
            })();

            // ── Picker client (searchable, sama seperti input transaksi) ──
            (function () {
                var cliData = <?= json_encode(array_values($clients), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                var src = document.getElementById('cliSearch'), hid = document.getElementById('client_id'), dd = document.getElementById('cliDrop');
                if (!src || !hid || !dd) return;
                document.body.appendChild(dd);
                dd.style.cssText = 'display:none;position:fixed;background:#fff;border:1px solid var(--line);border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.12);z-index:9000;max-height:220px;overflow-y:auto';
                function pos() { var r = src.getBoundingClientRect(); dd.style.top = (r.bottom + 2) + 'px'; dd.style.left = r.left + 'px'; dd.style.width = r.width + 'px'; }
                function render(q) {
                    pos(); var lq = q.toLowerCase().trim();
                    var list = lq ? cliData.filter(function (c) { return c.company_name.toLowerCase().includes(lq) || (c.brand_name && c.brand_name.toLowerCase().includes(lq)); }) : cliData;
                    dd.innerHTML = '';
                    list.slice(0, 60).forEach(function (c) {
                        var d = document.createElement('div');
                        d.style.cssText = 'padding:9px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid #f1f5f9';
                        d.innerHTML = '<strong>' + esc(c.company_name) + '</strong>' + (c.brand_name ? ' <span style="color:var(--muted);font-size:11px">(' + esc(c.brand_name) + ')</span>' : '');
                        d.addEventListener('mouseover', function () { this.style.background = '#f0fdf4'; });
                        d.addEventListener('mouseout', function () { this.style.background = ''; });
                        d.addEventListener('mousedown', function (e) { e.preventDefault(); src.value = c.company_name + (c.brand_name ? ' (' + c.brand_name + ')' : ''); hid.value = c.id; src.style.outline = ''; dd.style.display = 'none'; curContact = 0; fillContacts(); });
                        dd.appendChild(d);
                    });
                    if (!list.length) dd.innerHTML = '<div style="padding:10px 14px;font-size:13px;color:var(--muted)">Tidak ditemukan</div>';
                    dd.style.display = '';
                }
                src.addEventListener('input', function () { hid.value = ''; render(this.value); });
                src.addEventListener('focus', function () { render(this.value); });
                src.addEventListener('blur', function () { setTimeout(function () { dd.style.display = 'none'; }, 200); });
                window.addEventListener('scroll', function () { if (dd.style.display !== 'none') pos(); }, true);
                document.querySelectorAll('#offer-form button[type=submit]').forEach(function (btn) {
                    btn.addEventListener('click', function (e) { if (!hid.value) { e.preventDefault(); e.stopImmediatePropagation(); src.style.outline = '2px solid #EF4444'; src.focus(); } });
                });
            })();

            function num(id) { return parseFloat((document.getElementById(id) || {}).value || '0') || 0; }
            function rp(v) { return 'Rp ' + Math.round(v).toLocaleString('id-ID'); }
            function daysBetween(s, e) { if (!s || !e) return 0; var d = Math.round((new Date(e) - new Date(s)) / 86400000) + 1; return d > 0 ? d : 0; }
            // Masa kontrak otomatis dari rentang tanggal (cermin _offer_months di PHP).
            function monthsFromDates() {
                var s = (document.getElementById('start_date') || {}).value, e = (document.getElementById('end_date') || {}).value;
                if (!s || !e) return 1;
                var ds = new Date(s), de = new Date(e);
                if (isNaN(ds) || isNaN(de) || de < ds) return 1;
                var m = (de.getFullYear() - ds.getFullYear()) * 12 + (de.getMonth() - ds.getMonth());
                if (de.getDate() >= ds.getDate()) m++;
                return Math.max(1, m);
            }
            // ── Input nominal ber-format ribuan (titik) → simpan angka ke hidden ──
            function fmtNum(n) { return Math.round(n).toLocaleString('id-ID'); }
            function bindMoney(fmtId, hidId, onChange) {
                var fmt = document.getElementById(fmtId), hid = document.getElementById(hidId);
                if (!fmt || !hid) return;
                // Nilai '0' yang tersimpan HARUS dihitung sudah-diisi. Sebelumnya 0
                // disamakan dgn kosong, sehingga DP yang sengaja dinolkan ketimpa
                // hitungan otomatis begitu penawaran dibuka untuk revisi.
                if (hid.value !== '' && hid.value !== null) { fmt.value = fmtNum(parseInt(hid.value, 10) || 0); fmt.dataset.touched = '1'; }
                fmt.addEventListener('input', function () {
                    var raw = this.value.replace(/\D/g, '');
                    this.value = raw ? fmtNum(parseInt(raw, 10)) : '';
                    hid.value = raw;
                    this.dataset.touched = raw ? '1' : '';
                    if (onChange) onChange();
                });
            }
            // Set nominal otomatis (hidden + tampilan) bila belum diisi manual.
            function setMoneyAuto(fmtId, hidId, val) {
                var fmt = document.getElementById(fmtId), hid = document.getElementById(hidId);
                if (!fmt || !hid || fmt.dataset.touched) return;
                var r = Math.round(val); hid.value = r || ''; fmt.value = r ? fmtNum(r) : '';
            }
            bindMoney('override_fmt', 'override_amount', function () { kalkulasi(); });
            bindMoney('dp_fmt', 'dp_amount');
            bindMoney('dep_fmt', 'deposit_amount');
            bindMoney('sc_fmt', 'sc_monthly', function () { gambarSc(); });
            // ── Jadwal harga bertahap ───────────────────────────────────────
            (function () {
                var tabel = document.getElementById('tahap-tabel');
                if (!tabel) return;
                var ringkas = document.getElementById('tahap-ringkas');
                function rp(x) { return 'Rp ' + Math.round(x || 0).toLocaleString('id-ID'); }
                function hitung() {
                    var tahap = [];
                    tabel.querySelectorAll('tbody tr').forEach(function (tr) {
                        // flatpickr mengubah input tanggal jadi text+readonly,
                        // jadi dicari lewat name — bukan type.
                        var d = tr.querySelector('input[name^="tahap_mulai"]'),
                            n = tr.querySelector('.tahap-nilai'),
                            h = tr.querySelector('input[type=hidden]');
                        var nilai = parseInt((n.value || '').replace(/\D/g, ''), 10) || 0;
                        if (h) h.value = nilai;
                        if (d.value && nilai > 0) tahap.push({ from: d.value, amount: nilai });
                    });
                    if (!tahap.length) { ringkas.textContent = 'Kosong = satu harga untuk seluruh periode kontrak.'; return; }
                    tahap.sort(function (a, b) { return a.from < b.from ? -1 : 1; });
                    var s = (document.getElementById('start_date') || {}).value,
                        e = (document.getElementById('end_date') || {}).value;
                    if (!s || !e) { ringkas.textContent = 'Isi tanggal mulai & selesai dulu untuk melihat total kontraknya.'; return; }
                    // Susun siklus bulanan dari tanggal mulai, lalu ambil tahap yang berlaku.
                    var total = 0, n = 0, cur = new Date(s), akhir = new Date(e), rinci = {};
                    while (cur <= akhir && n < 240) {
                        var iso = cur.toISOString().slice(0, 10);
                        var pakai = tahap[0];
                        tahap.forEach(function (t) { if (t.from <= iso) pakai = t; });
                        total += pakai.amount; n++;
                        rinci[pakai.amount] = (rinci[pakai.amount] || 0) + 1;
                        cur.setMonth(cur.getMonth() + 1);
                    }
                    var bagian = Object.keys(rinci).map(function (k) { return rp(k) + ' × ' + rinci[k] + ' bulan'; });
                    ringkas.innerHTML = bagian.join(' · ') + ' &rarr; <b>total ' + n + ' bulan = ' + rp(total) + '</b>' + syaratPesan();
                }
                // Mesin alokasi hanya mengikuti jadwal pada kontrak bulanan
                // dengan pengakuan Spread per Bulan. Di luar itu jadwalnya tidak
                // akan tersimpan — harus terbaca SEBELUM surat dikirim ke client,
                // bukan setelahnya.
                function syaratKurang() {
                    var pt = (document.getElementById('pricing_type') || {}).value,
                        bm = (document.getElementById('billing_method') || {}).value;
                    var kurang = [];
                    if (pt && pt !== 'monthly') kurang.push('Pricing Type harus <b>monthly</b> (sekarang ' + pt + ')');
                    if (bm && bm !== 'spread') kurang.push('Pengakuan harus <b>Spread per Bulan</b>');
                    return kurang;
                }
                function syaratPesan() {
                    var kurang = syaratKurang();
                    if (!kurang.length) return '';
                    return '<div style="margin-top:6px;color:#b91c1c;font-weight:600">Jadwal ini TIDAK akan tersimpan: '
                         + kurang.join(' &amp; ') + '. Perbaiki dulu, kalau tidak surat menjanjikan harga bertahap '
                         + 'sementara sistem menagih rata setiap bulan.</div>';
                }
                ['pricing_type', 'billing_method'].forEach(function (id) {
                    var el = document.getElementById(id);
                    if (el) el.addEventListener('change', hitung);
                });
                tabel.addEventListener('input', function (e) {
                    if (e.target.classList.contains('tahap-nilai')) {
                        var raw = e.target.value.replace(/\D/g, '');
                        e.target.value = raw ? parseInt(raw, 10).toLocaleString('id-ID') : '';
                    }
                    hitung();
                });
                tabel.addEventListener('change', hitung);
                tabel.addEventListener('click', function (e) {
                    if (!e.target.classList.contains('tahap-hapus')) return;
                    var tr = e.target.closest('tr');
                    if (tabel.querySelectorAll('tbody tr').length > 1) tr.remove();
                    else tr.querySelectorAll('input').forEach(function (i) { i.value = ''; });
                    hitung();
                });
                var tambah = document.getElementById('tahap-tambah');
                if (tambah) tambah.addEventListener('click', function () {
                    var tb = tabel.querySelector('tbody');
                    var baru = tb.rows[0].cloneNode(true);
                    baru.querySelectorAll('input').forEach(function (i) { i.value = ''; });
                    tb.appendChild(baru);
                    hitung();
                });
                ['start_date', 'end_date'].forEach(function (id) {
                    var el = document.getElementById(id);
                    if (el) el.addEventListener('change', hitung);
                });
                hitung();
            })();
            // Ringkasan Service Charge: PPN per bulan & total untuk seluruh masa sewa.
            function gambarSc() {
                var on = document.getElementById('sc_flag'), box = document.getElementById('sc_box'),
                    info = document.getElementById('sc_info'), val = document.getElementById('sc_monthly'),
                    ppnOn = document.getElementById('ppn_flag');
                if (!on || !box) return;
                box.style.display = on.checked ? '' : 'none';
                var rpSc = on.checked ? (parseInt((val || {}).value, 10) || 0) : 0;
                var kena = !ppnOn || ppnOn.checked;
                var ppn = kena ? Math.round(rpSc * 11 / 12 * 0.12) : 0;
                var bulan = bulanSewa();
                if (info) info.innerHTML = rpSc <= 0 ? ''
                    : (kena ? ('PPN Rp ' + ppn.toLocaleString('id-ID') + ' · Rp ' + (rpSc + ppn).toLocaleString('id-ID') + ' per bulan · ')
                            : ('Rp ' + rpSc.toLocaleString('id-ID') + ' per bulan · '))
                      + '<b>' + bulan + ' bulan = Rp ' + ((rpSc + ppn) * bulan).toLocaleString('id-ID') + '</b>';
            }
            // Jumlah bulan sewa — dasar pengali Service Charge.
            function bulanSewa() {
                var s = (document.getElementById('start_date') || {}).value,
                    e = (document.getElementById('end_date') || {}).value;
                if (!s || !e) return 1;
                var a = new Date(s), b = new Date(e);
                var n = (b.getFullYear() - a.getFullYear()) * 12 + (b.getMonth() - a.getMonth());
                if (b.getDate() >= a.getDate()) n += 1;
                return Math.max(1, n);
            }
            ['sc_flag', 'ppn_flag', 'start_date', 'end_date'].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.addEventListener('change', gambarSc);
            });
            gambarSc();
            // ── Mesin pricing (sama dgn input transaksi) ──
            function kalkulasi() {
                var s = (document.getElementById('start_date') || {}).value, e = (document.getElementById('end_date') || {}).value;
                var rate = num('unit_rate'), area = num('area_sqm'), pricing = (document.getElementById('pricing_type') || {}).value;
                var si = document.getElementById('slots_input');
                var slots = (si && si.closest('#slots_wrap') && si.closest('#slots_wrap').style.display !== 'none') ? (parseFloat(si.value) || 1) : 1;
                var days = daysBetween(s, e), months = monthsFromDates();
                var calc = 0;
                switch (pricing) {
                    case 'daily_point': calc = rate * days; break;
                    case 'daily_slot':  calc = rate * Math.max(1, slots) * days; break;
                    case 'daily_area':  calc = rate * Math.max(1, area) * days; break;
                    case 'monthly': calc = rate * Math.max(1, months); break;
                    case 'fixed':   calc = rate; break;
                }
                calc = Math.round(calc);
                var override = parseFloat((document.getElementById('override_amount') || {}).value || '0') || 0;
                var total = override > 0 ? override : calc;
                var monthly = months > 0 ? Math.round(total / months) : total;
                document.getElementById('total_calc').value = rp(total);
                document.getElementById('total_calc_h').value = total;
                document.getElementById('monthly_disp').value = rp(monthly);
                document.getElementById('monthly_amount').value = monthly;
                // DP/deposit auto bila kosong/0 (nego manual tidak ketimpa)
                // DP & deposit = harga/bulan × jumlah bulan (otomatis), kecuali diisi manual.
                setMoneyAuto('dp_fmt', 'dp_amount', monthly * num('dp_months'));
                setMoneyAuto('dep_fmt', 'deposit_amount', monthly * num('deposit_months'));
                // Ringkasan + estimasi spread
                var res = document.getElementById('kalkulasi-result');
                if (res) { res.style.display = ''; res.innerHTML = 'Kalkulasi: <strong>' + rp(calc) + '</strong> · ' + days + ' hari · ' + months + ' bulan' + (override > 0 ? ' · <span style="color:#b45309">override ' + rp(override) + '</span>' : ''); }
                var sp = document.getElementById('kalkulasi-spread');
                if (sp) {
                    if (effectiveBilling() === 'spread' && months > 1 && total > 0 && s) {
                        var mnames = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Ags','Sep','Okt','Nov','Des'];
                        var per = Math.floor(total / months), last = total - per * (months - 1);
                        var d0 = new Date(s), rows = '';
                        for (var i = 0; i < months; i++) {
                            var yy = d0.getFullYear() + Math.floor((d0.getMonth() + i) / 12);
                            var mm = (d0.getMonth() + i) % 12;
                            var amt = (i === months - 1) ? last : per;
                            rows += '<div style="display:flex;justify-content:space-between;gap:12px"><span>' + mnames[mm] + ' ' + yy + '</span><strong>' + rp(amt) + '</strong></div>';
                        }
                        sp.style.display = '';
                        sp.innerHTML = '<div style="font-weight:700;margin-bottom:4px">Preview Spread (recurring) — ' + months + ' bulan</div>' + rows + '<div style="font-size:11px;color:var(--muted);margin-top:4px">Pembagian rata; selisih pembulatan masuk ke bulan terakhir. Alokasi final dihitung saat transaksi terbit.</div>';
                    } else { sp.style.display = 'none'; }
                }
            }
            // ── Pengakuan: hitung metode efektif (Otomatis → ikut periode), toggle siklus ──
            function effectiveBilling() {
                var bm = (document.getElementById('billing_method') || {}).value;
                if (bm === 'spread' || bm === 'anchor_cycle') return bm;
                var s = (document.getElementById('start_date') || {}).value, e = (document.getElementById('end_date') || {}).value;
                var cross = s && e && s.substring(0, 7) !== e.substring(0, 7);
                return (monthsFromDates() > 1 || cross) ? 'spread' : 'anchor_cycle';
            }
            function syncRecognition() {
                var cw = document.getElementById('cycle_wrap');
                if (cw) cw.style.display = effectiveBilling() === 'spread' ? '' : 'none';
            }
            var bmEl = document.getElementById('billing_method');
            if (bmEl) bmEl.addEventListener('change', function () { syncRecognition(); kalkulasi(); });
            ['unit_rate', 'area_sqm', 'slots_input', 'dp_months', 'deposit_months'].forEach(function (id) { var el = document.getElementById(id); if (el) el.addEventListener('input', kalkulasi); });
            var ptEl = document.getElementById('pricing_type'); if (ptEl) ptEl.addEventListener('change', kalkulasi);
            ['start_date', 'end_date'].forEach(function (id) { var el = document.getElementById(id); if (el) el.addEventListener('change', function () { syncRecognition(); kalkulasi(); checkOverlap(); }); });
            var btn = document.getElementById('btn-kalkulasi'); if (btn) btn.addEventListener('click', kalkulasi);

            // ── Cek overlap unit (reuse endpoint transaksi) ──
            var overlapTimer = null;
            function checkOverlap() {
                var warn = document.getElementById('overlap-warn'); if (!warn) return;
                clearTimeout(overlapTimer);
                overlapTimer = setTimeout(function () {
                    var code = (document.getElementById('master_code') || {}).value, s = (document.getElementById('start_date') || {}).value, e = (document.getElementById('end_date') || {}).value;
                    if (!code || !s || !e) { warn.style.display = 'none'; return; }
                    fetch('?r=transaction_overlap_check&master_code=' + encodeURIComponent(code) + '&start_date=' + encodeURIComponent(s) + '&end_date=' + encodeURIComponent(e), { cache: 'no-store' })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (data.overlaps && data.overlaps.length) {
                                var rows = data.overlaps.map(function (o) { return '<li><strong>' + o.company_name + '</strong> · ' + o.start_date + ' s/d ' + o.end_date + ' · PIC: ' + o.pic_name + '</li>'; }).join('');
                                warn.innerHTML = '⚠ Unit ini sudah punya <strong>' + data.overlaps.length + '</strong> transaksi yang overlap tanggal. Tetap bisa lanjut bila memang dibagi slot/luasan.<ul style="margin:6px 0 0 16px">' + rows + '</ul>';
                                warn.style.display = '';
                            } else { warn.style.display = 'none'; }
                        }).catch(function () {});
                }, 400);
            }

            syncRecognition();
            kalkulasi();
            checkOverlap();
        })();
        </script>
        <?php
    });
}

// ─── Simpan (insert/update + revisi) ─────────────────────────────────────────
/**
 * Tulis ulang jadwal harga milik satu penawaran dari isian formulir.
 * Replace-all seperti komponen paket: yang dikirim formulir adalah kebenarannya.
 */
/**
 * Tulis ulang jadwal harga penawaran dari POST. Mengembalikan nilai kontrak
 * yang DIHASILKAN jadwal sebelum ditulis ulang (0 bila sebelumnya tanpa jadwal)
 * — dipakai untuk mengenali harga nego yang asalnya dari jadwal, supaya tidak
 * tertinggal basi saat jadwalnya dihapus.
 */
function _offer_write_steps(PDO $pdo, int $pid, int $offerId, string $uname): float
{
    $tahapLama = _offer_step_total($pdo, $offerId);
    // Paket: nilainya dijumlah dari komponen, jadwal tidak dipakai sama sekali.
    if (!empty($_POST['is_bundle'])) {
        $pdo->prepare('DELETE FROM price_steps WHERE offer_id = ? AND transaction_id IS NULL')->execute([$offerId]);
        return $tahapLama;
    }
    $mulai = (array) ($_POST['tahap_mulai'] ?? []);
    $nilai = (array) ($_POST['tahap_nilai'] ?? []);
    $label = (array) ($_POST['tahap_label'] ?? []);
    $baris = [];
    foreach ($mulai as $i => $tgl) {
        $tgl = trim((string) $tgl);
        $n = (float) preg_replace('/\D/', '', (string) ($nilai[$i] ?? '0'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl) || $n <= 0) continue;
        $baris[$tgl] = ['amount' => $n, 'label' => trim((string) ($label[$i] ?? '')) ?: null];
    }
    $pdo->prepare('DELETE FROM price_steps WHERE offer_id = ? AND transaction_id IS NULL')->execute([$offerId]);
    if (!$baris) return $tahapLama;
    // Mesin alokasi hanya mengikuti jadwal pada kontrak bulanan + Spread per
    // Bulan. Nilainya dibaca dari baris yang SUDAH tersimpan supaya sama dengan
    // yang nanti dipakai saat kontrak terbit.
    $cek = $pdo->prepare('SELECT pricing_type, billing_method FROM offers WHERE id = ?');
    $cek->execute([$offerId]);
    $o = $cek->fetch();
    if (!$o || ($o['pricing_type'] ?? '') !== 'monthly' || ($o['billing_method'] ?? '') !== 'spread') {
        flash('Jadwal Harga diabaikan: hanya berlaku untuk Pricing Type "monthly" dengan pengakuan Spread per Bulan.');
        return $tahapLama;
    }
    ksort($baris);
    $ins = $pdo->prepare('INSERT INTO price_steps (property_id, offer_id, effective_from, monthly_amount, label, created_by) VALUES (?,?,?,?,?,?)');
    foreach ($baris as $tgl => $b) $ins->execute([$pid, $offerId, $tgl, $b['amount'], $b['label'], $uname]);
    return $tahapLama;
}

/** Nilai kontrak menurut jadwal harga penawaran ini (0 bila tanpa jadwal). */
function _offer_step_total(PDO $pdo, int $offerId): float
{
    require_once dirname(__DIR__) . '/AllocationService.php';
    $tahap = AllocationService::priceSteps($pdo, null, $offerId);
    if (!$tahap) return 0.0;
    $st = $pdo->prepare('SELECT start_date, end_date, cycle_recognition FROM offers WHERE id = ?');
    $st->execute([$offerId]);
    $o = $st->fetch();
    if (!$o || !$o['start_date'] || !$o['end_date']) return 0.0;
    return AllocationService::totalDariTahap([
        'start_date' => $o['start_date'], 'end_date' => $o['end_date'],
        'cycle_recognition' => $o['cycle_recognition'] ?: 'cycle_start',
    ], $tahap);
}

/**
 * Bila penawaran memakai jadwal harga, nilai kontraknya adalah JUMLAH seluruh
 * tahap — bukan tarif × lama sewa. Disimpan ke kolomnya supaya surat, SKP,
 * dan transaksi memakai angka yang sama.
 */
function _offer_sync_step_total(PDO $pdo, int $offerId, float $calcMesin = 0.0, float $tahapLama = 0.0): void
{
    $total = _offer_step_total($pdo, $offerId);
    if ($total > 0) {
        $pdo->prepare('UPDATE offers SET total_calculated = ?, override_amount = ? WHERE id = ?')
            ->execute([$total, $total, $offerId]);
        return;
    }
    // Jadwal baru saja dihapus. Harga nego yang nilainya persis sama dengan
    // jadwal lama berarti bukan angka yang sales ketik sendiri — itu turunan
    // jadwal, jadi dilepas supaya nilai kontrak kembali ke hitungan mesin
    // (tarif x lama sewa) dan tidak diam-diam memakai angka jadwal yang sudah
    // tidak ada lagi.
    if ($tahapLama <= 0 || $calcMesin <= 0) return;
    $st = $pdo->prepare('SELECT override_amount, contract_months FROM offers WHERE id = ?');
    $st->execute([$offerId]);
    $o = $st->fetch();
    if (!$o || round((float) $o['override_amount']) !== round($tahapLama)) return;
    $bulan = (int) ($o['contract_months'] ?? 0);
    $pdo->prepare('UPDATE offers SET total_calculated = ?, override_amount = NULL, monthly_amount = ? WHERE id = ?')
        ->execute([$calcMesin, $bulan > 0 ? round($calcMesin / $bulan) : $calcMesin, $offerId]);
}

function offer_save(PDO $pdo): void
{
    require_permission('manage_offers');
    verify_csrf();
    $pid = current_property_id();
    $id  = (int) post('id');
    $uname = $_SESSION['user']['name'] ?? 'system';

    $module   = in_array(post('module'), ['cl', 'media', 'gudang'], true) ? post('module') : 'cl';
    $isBundleAwal = post('is_bundle') === '1';
    $start    = post('start_date') ?: null;
    $end      = post('end_date') ?: null;
    $months   = _offer_months($start, $end);
    $days     = _offer_days($start, $end);
    $pricing  = post('pricing_type') ?: 'daily_area';
    $rate     = (float) post('unit_rate', 0);
    $area     = (float) post('area_sqm', 0);
    $slots    = max(1, (float) post('slots', 1));
    // Prorata bulan kalender: bulan pertama/terakhir yang tidak penuh dihitung
    // per hari bulan itu, sisanya bulan penuh — cara surat kertas. Totalnya
    // harus datang dari mesin alokasi yang sama supaya angka di surat dan angka
    // di laporan tidak mungkin berbeda.
    $prorataKal = post('prorata_kalender') === '1' && $pricing === 'monthly' ? 1 : 0;
    // Total kalkulasi (mesin pricing) → ditimpa harga nego final bila ada.
    if ($prorataKal && $start && $end) {
        require_once dirname(__DIR__) . '/AllocationService.php';
        $calc = round(AllocationService::totalCalculated([
            'pricing_type' => 'monthly', 'unit_rate' => $rate, 'quantity' => 1, 'slots' => $slots,
            'area_sqm' => $area, 'start_date' => $start, 'end_date' => $end,
            'contract_months' => null, 'prorata_kalender' => 1,
        ]));
    } else {
        $calc = round(_offer_calc_total($pricing, $rate, $area, $slots, $days, $months));
    }
    // #3 — parse rupiah aman via helper (titik ribuan / koma desimal), bukan strip digit.
    $override = parse_rupiah(post('override_amount', '')) ?: 0.0;
    $final    = $override > 0 ? $override : $calc;
    $monthly  = $months > 0 ? round($final / $months) : $final;
    // Recurring/pengakuan ditentukan sales; default spread bila multi-bulan/lintas bulan.
    $crossMonth = $start && $end && substr($start, 0, 7) !== substr($end, 0, 7);
    $billing  = post('billing_method') === 'anchor_cycle' ? 'anchor_cycle'
              : (post('billing_method') === 'spread' ? 'spread' : (($months > 1 || $crossMonth) ? 'spread' : 'anchor_cycle'));

    // Template surat per jenis booth (unit_type) → aturan DP + snapshot isi surat.
    // #6 — unit_type CL hanya relevan utk modul 'cl' (Exhibition). Media/Gudang
    // pakai jalur netral (unit_type null) agar istilah booth pameran tidak ikut
    // ter-bake ke surat media/gudang.
    $unitType = $module === 'cl' ? offer_unit_type($pdo, $pid, trim((string) post('master_code')) ?: null) : '';
    // Template yang DIPILIH sales di formulir menang atas tebakan tipe unit.
    // Kosong = biarkan aturan lama yang memilih (bawaan modul, lalu tipe unit).
    $tplId    = (int) post('template_id', 0);
    $tpl      = offer_template_for($pdo, $pid, $module === 'cl' ? $unitType : null, $module, $tplId);
    // #4 — sinyal non-silent bila unit_type ada tapi template jatuh ke default
    // (unit_type hasil kosong) → terbitnya surat dgn istilah salah bisa dilacak.
    if ($tplId <= 0 && $unitType !== '' && ($tpl['unit_type'] ?? '') === '') {
        error_log("offer template miss: property=$pid unit_type='$unitType' -> default");
    }
    $dpReq    = $tpl['dp_required'] == 1;
    // DP wajib → min 1 bln; deposit-only (tak wajib) → boleh 0.
    $dpMonths = $dpReq ? max(1, (float) post('dp_months', $tpl['dp_months_default'])) : max(0, (float) post('dp_months', 0));
    // #5 — DP deposit-only (dp_required=0) WAJIB 0 agar surat tidak kontradiktif
    // (0 bulan tapi nominal DP > 0). Parse via helper rupiah.
    $dpAmount = $dpReq ? parse_rupiah(post('dp_amount', '0')) : 0.0;
    // Isi surat DIBEKUKAN di sini. Sesudah ini, mengubah templatenya tidak lagi
    // menyentuh penawaran ini — itulah jaminan bahwa menambah 5 template baru
    // tidak mengusik satu pun surat yang sudah terbit.
    $letterJson = json_encode(
        offer_letter_dari_template($tpl) + ['unit_type' => $unitType],
        JSON_UNESCAPED_UNICODE
    );

    $data = [
        'module'          => $module,
        'template_id'     => ($tpl['id'] ?? 0) > 0 ? (int) $tpl['id'] : null,
        'client_id'       => (int) post('client_id') ?: null,
        'contact_id'      => (int) post('contact_id') ?: null,
        'pic_name'        => trim((string) post('pic_name')) ?: null,
        'referrer_name'   => trim((string) post('referrer_name')) ?: null,
        'master_code'     => trim((string) post('master_code')) ?: null,
        'keterangan'      => trim((string) post('keterangan')) ?: null,
        'pricing_type'    => $pricing,
        'unit_rate'       => $rate,
        'area_sqm'        => $area,
        'ukuran'          => trim((string) post('ukuran')) ?: null,
        'quantity'        => 1,
        'slots'           => $slots,
        'start_date'      => $start,
        'end_date'        => $end,
        'contract_months' => $months,
        'monthly_amount'  => $monthly,
        'total_calculated' => $final,
        'override_amount' => $override ?: null,
        'billing_method'  => $billing,
        'prorata_kalender' => $prorataKal,
        'recurring_flag'  => post('recurring_flag') ? 1 : 0,
        'cycle_recognition' => post('cycle_recognition') === 'cycle_end' ? 'cycle_end' : 'cycle_start',
        'dp_months'       => $dpMonths,
        'dp_amount'       => $dpAmount, // #5 — 0 utk template deposit-only
        'deposit_months'  => (float) post('deposit_months', 1),
        'deposit_amount'  => (float) post('deposit_amount', 0),
        // Sudah disetor di kontrak sebelumnya → tetap dicetak, tapi di luar Grand Total.
        'deposit_paid'    => post('deposit_paid') ? 1 : 0,
        // Harga bersih tanpa PPN untuk penyewa yang pajaknya diselesaikan di luar sistem.
        'ppn_flag'        => post('ppn_flag') ? 1 : 0,
        // Service Charge bulanan (kosong = tidak dikenakan).
        'sc_flag'         => post('sc_flag') ? 1 : 0,
        'sc_monthly'      => parse_rupiah((string) post('sc_monthly', '0')) ?: null,
        // Listrik: hanya berlaku untuk Exhibition. Nominalnya tarif PER BULAN —
        // dikalikan jumlah bulan saat dicetak & saat nilainya diteruskan ke SKP.
        'electricity_flag'    => ($module === 'cl' && post('electricity_flag')) ? 1 : 0,
        'electricity_monthly' => $module === 'cl' ? parse_rupiah((string) post('electricity_monthly', '0')) : null,
        // Kosong = jumlah satuan ikut lama hari sewa.
        'electricity_units'   => ($module === 'cl' && (int) post('electricity_units', 0) > 0)
            ? min(12, (int) post('electricity_units')) : null,
        // Kosong = ikut hitungan otomatis; terisi = dipakai apa adanya.
        'electricity_amount'  => ($module === 'cl' && parse_rupiah((string) post('electricity_amount', '0')) > 0)
            ? parse_rupiah((string) post('electricity_amount', '0')) : null,
        'perihal'         => $tpl['perihal'] ?: ('Surat Penawaran Sewa Area Pameran' . ($days > 0 ? ' ' . $days . ' Hari' : '')),
        'letter_json'     => $letterJson,
        'offer_date'      => date('Y-m-d'),
        'is_bundle'       => 0,
    ];

    // Paket: bila >=2 komponen dikirim, timpa agregat & tandai is_bundle.
    // Periode SAMA utk semua item; harga eksplisit per item → total = Σ item.
    // Tanggal selesai mendahului tanggal mulai: lama sewa jadi 0, sehingga nilai
    // paket bertingkat TIDAK dihitung ulang dan angka periode LAMA ikut
    // tersimpan — layar, surat, dan SKP lalu memuat tiga angka berbeda.
    if ($start && $end && $end < $start) {
        flash('Tanggal selesai (' . date('d/m/Y', strtotime($end)) . ') lebih awal daripada tanggal mulai ('
            . date('d/m/Y', strtotime($start)) . '). Perbaiki dulu — nilai sewa tidak bisa dihitung.');
        redirect_to('offer_form', $id ? ['id' => $id] : ($isBundleAwal ? ['bundle' => 1] : []));
    }

    $isBundle = post('is_bundle') === '1';
    $bundleItems = $isBundle ? _offer_parse_bundle_items($months) : [];
    // Tarif bertingkat per m²: nilai paket dihitung dari luas × tarif × hari,
    // lalu dibagi ke tiap komponen menurut luasnya. Dipakai saat harga memang
    // melekat ke paket, bukan ke lokasi satu per satu.
    $tierRows = ($isBundle && post('pakai_tier') === '1') ? _offer_parse_area_tiers() : [];
    $tierTotal = offer_tier_total($tierRows, $days);
    if ($isBundle && count($bundleItems) >= 2) {
        $conf = _offer_slot_conflicts($pdo, $pid, $bundleItems, $start, $end, $id);
        if ($conf) { flash('Bentrok slot: ' . implode('; ', $conf) . '. Perbaiki dulu.'); redirect_to('offer_form', $id ? ['id' => $id] : ['bundle' => 1]); }
        if ($tierRows && $tierTotal <= 0) {
            flash('Tarif bertingkat terisi tetapi lama sewanya 0 hari — periksa tanggal mulai & selesai. Penawaran tidak disimpan agar nilainya tidak tertinggal memakai periode lama.');
            redirect_to('offer_form', $id ? ['id' => $id] : ['bundle' => 1]);
        }
        if ($tierRows && $tierTotal > 0) {
            if (_offer_ada_tanpa_luas($bundleItems)) {
                flash('Ada komponen paket yang luasnya belum diisi — nilai paket dibagi RATA, bukan menurut luas. Isi kolom Luas (m²) tiap lokasi agar pembagiannya tepat.');
            }
            $bundleItems = _offer_bagi_paket($bundleItems, $tierTotal, $months);
            $luasTier = array_sum(array_column($tierRows, 'area_sqm'));
            $luasUnit = array_sum(array_map(fn($i) => (float) $i['area_sqm'], $bundleItems));
            // Luas di surat sering berbeda dengan luas master (mis. 80 m² vs
            // 72 m² karena area sirkulasi ikut dihitung). Dibolehkan, tapi
            // harus terbaca — bukan diam-diam.
            if ($luasUnit > 0 && abs($luasTier - $luasUnit) >= 0.01) {
                flash('Catatan: luas pada tingkatan harga ' . rtrim(rtrim(number_format($luasTier, 2, ',', '.'), '0'), ',')
                    . ' m², sedangkan jumlah luas lokasi yang dipilih ' . rtrim(rtrim(number_format($luasUnit, 2, ',', '.'), '0'), ',')
                    . ' m². Nilai paket tetap memakai tingkatan harga, dan dibagi ke lokasi menurut luas masing-masing.');
            }
        }
        $data['module']           = 'bundle';
        $data['is_bundle']        = 1;
        $data['master_code']      = null;
        $data['monthly_amount']   = array_sum(array_column($bundleItems, 'monthly_amount'));
        $data['total_calculated'] = array_sum(array_column($bundleItems, 'total_amount'));
        $data['override_amount']  = $data['total_calculated'];
        if ($tierRows && $tierTotal > 0) {
            // Luas & tarif penawaran diisi dari tingkatan harga. Tanpa ini
            // keduanya tetap 0, dan SKP mencetak "Luas Area : 0,00 m²" tepat di
            // atas blok yang menulis "Total Luas Area 80 m²" — dua angka yang
            // bertentangan di satu halaman yang diserahkan ke client.
            $luasTierTot = array_sum(array_column($tierRows, 'area_sqm'));
            $data['area_sqm'] = $luasTierTot;
            // Tarif rata-rata gabungan, dibuat supaya bisa dijumlah ulang:
            // tarif × luas × hari = nilai paket. Rinciannya tetap yang dicetak.
            $data['unit_rate'] = ($luasTierTot > 0 && $days > 0)
                ? round($tierTotal / $luasTierTot / $days, 2) : 0;
            $data['pricing_type'] = 'daily_area';
        }
        $data['dp_amount']        = array_sum(array_column($bundleItems, 'dp_amount'));
        $data['deposit_amount']   = array_sum(array_column($bundleItems, 'deposit_amount'));
        $data['dp_months']        = 0;
        $data['deposit_months']   = 0;
        $data['perihal']          = 'Surat Penawaran Paket';
        // Biaya listrik paket memakai isian yang sama dengan penawaran satuan
        // (tarif × satuan, atau total yang diketik sendiri). Untuk paket, satu
        // satuan biasanya = satu booth — jadi paket 3 booth diisi 3 ×.
        // Seperti penawaran satuan, listrik adalah beban di surat & dokumen
        // konfirmasi; nilai transaksi tetap sebesar sewa per komponen.
        // Paket memakai isi template yang DIPILIH juga — dulu isinya dikosongkan
        // sehingga surat paket terbit tanpa fasilitas, cara bayar, maupun
        // ketentuan. Yang benar-benar khas paket hanyalah perihal dan tiadanya
        // aturan DP; sisanya tetap milik template.
        $suratPaket = offer_letter_dari_template($tpl);
        $suratPaket['perihal']     = 'Surat Penawaran Paket';
        $suratPaket['dp_required'] = 0;
        $suratPaket['unit_type']   = '';
        $suratPaket['bundle']      = true;
        $data['letter_json']      = json_encode($suratPaket, JSON_UNESCAPED_UNICODE);
    } elseif ($isBundle) {
        flash('Paket minimal 2 komponen.'); redirect_to('offer_form', $id ? ['id' => $id] : ['bundle' => 1]);
    }

    if ($id) {
        $cur = $pdo->prepare('SELECT * FROM offers WHERE id=? AND property_id=?');
        $cur->execute([$id, $pid]);
        $offer = $cur->fetch();
        if (!$offer || in_array($offer['status'], ['deal', 'cancelled'], true)) { flash('Penawaran terkunci / tidak ditemukan.'); redirect_to('offers'); }
        $sets = []; $vals = [];
        foreach ($data as $k => $val) { $sets[] = "$k=:$k"; $vals[":$k"] = $val; }
        $vals[':id'] = $id; $vals[':pid'] = $pid; $vals[':uname'] = $uname;
        $newRev = (int) $offer['revision_count'] + 1;
        $vals[':rev'] = $newRev;
        $pdo->prepare('UPDATE offers SET ' . implode(', ', $sets) . ', revision_count=:rev, updated_at=CURRENT_TIMESTAMP, updated_by=:uname WHERE id=:id AND property_id=:pid')->execute($vals);
        // snapshot revisi
        $snap = array_intersect_key(array_merge($offer, $data), array_flip(_offer_fields()));
        $pdo->prepare('INSERT INTO offer_revisions (offer_id, rev_no, snapshot_json, note, created_by) VALUES (?,?,?,?,?)')
            ->execute([$id, $newRev, json_encode($snap, JSON_UNESCAPED_UNICODE), trim((string) post('rev_note')) ?: null, $uname]);
        // Rekonsiliasi komponen: selalu replace-all. Bila is_bundle di-uncheck,
        // tulis [] agar offer_items lama TERHAPUS (cegah orphan yg bisa meledak
        // jadi transaksi basi saat approve). Lihat review bundling.
        _offer_write_items($pdo, $id, $isBundle ? $bundleItems : []);
        _offer_write_tiers($pdo, $pid, $id, $isBundle ? $tierRows : [], $uname);
        $tahapLama = _offer_write_steps($pdo, $pid, $id, $uname);
        _offer_sync_step_total($pdo, $id, $calc, $tahapLama);
        audit($pdo, 'update', 'offers', (string) $id, $data);
        flash("Revisi #$newRev disimpan.");
        redirect_to('offer_view', ['id' => $id]);
    }

    // INSERT baru + generate No. Penawaran
    $year = (int) date('Y');
    $prop = current_property();
    $pdo->prepare('INSERT INTO offer_counters (property_id, year, last_no) VALUES (?,?,1) ON DUPLICATE KEY UPDATE last_no=last_no+1')->execute([$pid, $year]);
    $seq = (int) $pdo->query("SELECT last_no FROM offer_counters WHERE property_id=$pid AND year=$year")->fetchColumn();
    $offerNo = sprintf('%03d/QT-CL/%s/BSB-BPN/%s/%d', $seq, _offer_prop_code($prop['key'] ?? ''), _offer_roman((int) date('n')), $year);

    $cols = array_keys($data);
    $place = array_map(fn($c) => ':' . $c, $cols);
    $vals = [];
    foreach ($data as $k => $val) $vals[":$k"] = $val;
    $vals[':pid'] = $pid; $vals[':no'] = $offerNo; $vals[':uname'] = $uname;
    $pdo->prepare('INSERT INTO offers (property_id, offer_no, status, created_by, ' . implode(', ', $cols) . ')
                   VALUES (:pid, :no, \'draft\', :uname, ' . implode(', ', $place) . ')')->execute($vals);
    $newId = (int) $pdo->lastInsertId();
    if ($isBundle) _offer_write_items($pdo, $newId, $bundleItems);
    _offer_write_tiers($pdo, $pid, $newId, $isBundle ? $tierRows : [], $uname);
    _offer_write_steps($pdo, $pid, $newId, $uname);
    _offer_sync_step_total($pdo, $newId, $calc);
    $pdo->prepare('INSERT INTO offer_revisions (offer_id, rev_no, snapshot_json, note, created_by) VALUES (?,0,?,?,?)')
        ->execute([$newId, json_encode(array_intersect_key($data, array_flip(_offer_fields())), JSON_UNESCAPED_UNICODE), 'Penawaran awal', $uname]);
    audit($pdo, 'create', 'offers', (string) $newId, $data);
    flash("Penawaran dibuat: $offerNo");
    redirect_to('offer_view', ['id' => $newId]);
}

// ─── Ubah status ─────────────────────────────────────────────────────────────
function offer_status(PDO $pdo): void
{
    require_permission('manage_offers');
    verify_csrf();
    $pid = current_property_id();
    $id  = (int) post('id');
    $to  = post('status');
    // 'cancelled' (tutup/tidak deal) ditangani offer_close (wajib alasan).
    if (!in_array($to, ['sent', 'nego', 'deal'], true)) { redirect_to('offer_view', ['id' => $id]); }
    // #8 — butuh baris lengkap utk membangun snapshot saat transisi ke 'deal'
    // (join client/unit agar bentuk snapshot sama dgn offer_sign_save).
    $cur = $pdo->prepare(
        "SELECT o.*, c.company_name, ct.name cp_name, u.location_name, u.floor
         FROM offers o
         LEFT JOIN master_clients c ON c.id = o.client_id
         LEFT JOIN master_client_contacts ct ON ct.id = o.contact_id
         LEFT JOIN master_cl_units u ON u.code = o.master_code AND u.property_id = o.property_id
         WHERE o.id=? AND o.property_id=?"
    );
    $cur->execute([$id, $pid]);
    $row = $cur->fetch();
    if (!$row || in_array($row['status'], ['deal', 'cancelled'], true)) { flash('Status terkunci.'); redirect_to('offer_view', ['id' => $id]); }
    // Stempel engagement (sekali, tidak ditimpa) untuk analisa aktivitas PIC.
    $extra = '';
    $extraParams = [];
    if ($to === 'sent' && empty($row['sent_at'])) $extra .= ', sent_at=CURRENT_TIMESTAMP';
    if ($to === 'nego') { if (empty($row['sent_at'])) $extra .= ', sent_at=CURRENT_TIMESTAMP'; if (empty($row['nego_at'])) $extra .= ', nego_at=CURRENT_TIMESTAMP'; }
    if ($to === 'deal') {
        $extra .= ', deal_at=CURRENT_TIMESTAMP';
        // #8 — DEAL manual = finalisasi: kunci snapshot & signed_at agar tidak bisa
        // di-TTD ulang via token publik. Bentuk snapshot sama dgn offer_sign_save.
        if (empty($row['signed_at'])) {
            $snap = _offer_sign_view($row);
            $snap['offer_no'] = $row['offer_no'];
            $extra .= ', signed_at=CURRENT_TIMESTAMP, snapshot_json=?';
            $extraParams[] = json_encode($snap, JSON_UNESCAPED_UNICODE);
        }
    }
    $pdo->prepare("UPDATE offers SET status=? $extra WHERE id=? AND property_id=?")
        ->execute(array_merge([$to], $extraParams, [$id, $pid]));
    audit($pdo, 'status_' . $to, 'offers', (string) $id, ['status' => $to]);
    flash('Status penawaran diperbarui: ' . $to);
    redirect_to('offer_view', ['id' => $id]);
}

// ─── Tutup penawaran (tidak deal) — WAJIB alasan, untuk analisa ───────────────
function offer_close(PDO $pdo): void
{
    require_permission('manage_offers');
    verify_csrf();
    $pid = current_property_id();
    $id  = (int) post('id');
    $cat = (string) post('lost_category');
    $note = trim((string) post('status_note'));
    if (!array_key_exists($cat, offer_lost_categories())) { flash('Pilih alasan penawaran ditutup.'); redirect_to('offer_view', ['id' => $id]); }
    if ($note === '') { flash('Catatan alasan wajib diisi agar bisa dianalisa.'); redirect_to('offer_view', ['id' => $id]); }
    $cur = $pdo->prepare('SELECT status FROM offers WHERE id=? AND property_id=?');
    $cur->execute([$id, $pid]);
    $st = $cur->fetchColumn();
    if ($st === false || in_array($st, ['deal', 'cancelled'], true)) { flash('Status terkunci.'); redirect_to('offer_view', ['id' => $id]); }
    $pdo->prepare(
        "UPDATE offers SET status='cancelled', cancelled_at=CURRENT_TIMESTAMP,
                lost_category=?, status_note=?, closed_by=? WHERE id=? AND property_id=?"
    )->execute([$cat, $note, $_SESSION['user']['name'] ?? 'system', $id, $pid]);
    audit($pdo, 'close', 'offers', (string) $id, ['lost_category' => $cat, 'note' => $note]);
    flash('Penawaran ditutup (tidak deal) dengan alasan: ' . offer_lost_label($cat) . '.');
    redirect_to('offer_view', ['id' => $id]);
}

// ─── Cetak / PDF Surat Penawaran ─────────────────────────────────────────────
function offer_print(PDO $pdo): void
{
    require_permission('manage_offers');
    $pid = current_property_id();
    $id  = (int) getv('id');
    $st = $pdo->prepare(
        "SELECT o.*, c.company_name, c.brand_name, c.address, c.city,
                ct.name cp_name,
                u.location_name, u.floor,
                p.email pic_email, p.phone pic_phone, p.signature_path pic_signature, p.role_name pic_role
         FROM offers o
         LEFT JOIN master_clients c ON c.id = o.client_id
         LEFT JOIN master_client_contacts ct ON ct.id = o.contact_id
         LEFT JOIN master_cl_units u ON u.code = o.master_code AND u.property_id = o.property_id
         LEFT JOIN master_pic p ON p.name = o.pic_name AND p.property_id = o.property_id
         WHERE o.id = ? AND o.property_id = ?"
    );
    $st->execute([$id, $pid]);
    $o = $st->fetch();
    if (!$o || empty($o['offer_no'])) { http_response_code(404); exit('Penawaran tidak ditemukan / nomor belum terbit.'); }
    // Token verifikasi (QR "Scan untuk validasi" di TTD sales) — terbit sekali.
    if (empty($o['sign_token'])) {
        $o['sign_token'] = bin2hex(random_bytes(20));
        $pdo->prepare('UPDATE offers SET sign_token=?, sign_token_expires_at=' . sign_token_expiry_sql() . ' WHERE id=? AND property_id=?')->execute([$o['sign_token'], $id, $pid]);
    }
    $prop = current_property();
    $letter = offer_letter($pdo, $o);   // isi surat per jenis booth (snapshot/template)
    $items = !empty($o['is_bundle']) ? offer_items($pdo, (int) $o['id']) : []; // komponen paket
    // Jadwal harga bertahap ikut tercetak supaya perubahan harga di tahun
    // berikutnya sudah tercantum sejak surat pertama.
    require_once dirname(__DIR__) . '/AllocationService.php';
    $tahapHarga = AllocationService::priceSteps($pdo, null, (int) $o['id']);
    // Tarif bertingkat per m² (paket) ikut dicetak supaya client melihat asal
    // angkanya — persis seperti rincian di kertas yang selama ini dipakai.
    $tierHarga = !empty($o['is_bundle']) ? offer_area_tiers($pdo, (int) $o['id']) : [];
    $rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $h  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    // Pratinjau HTML lama (window.print) hanya bila diminta eksplisit (?html=1).
    // Default: PDF server-side (mPDF) — kop berulang tiap halaman, identik di HP & PC.
    if (getv('html') === '1') {
        include __DIR__ . '/offer_print_template.php';
        return;
    }
    require_once dirname(__DIR__) . '/pdf.php';
    $PDF_MODE = true;
    ob_start();
    include __DIR__ . '/offer_print_template.php';
    $html = ob_get_clean();
    clara_render_letterhead_pdf($html, ($o['offer_no'] ?: 'Penawaran') . ' - Surat Penawaran');
}

// ─── Verifikasi penawaran via QR (publik, read-only, akses via sign_token) ────
function offer_verify_page(PDO $pdo): void
{
    $token = (string) getv('token', '');
    $st = $pdo->prepare(
        "SELECT o.*, c.company_name, c.brand_name, p.signature_path pic_signature
         FROM offers o
         LEFT JOIN master_clients c ON c.id = o.client_id
         LEFT JOIN master_pic p ON p.name = o.pic_name AND p.property_id = o.property_id
         WHERE o.sign_token = ? LIMIT 1"
    );
    $st->execute([$token]);
    $o = $token !== '' ? $st->fetch() : false;
    $valid = $o && !empty($o['offer_no']);
    $h  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $salesReg = $valid && !empty($o['pic_signature']);
    $statusLbl = $valid ? (['draft' => 'Draft', 'sent' => 'Terkirim', 'nego' => 'Negosiasi', 'deal' => 'DEAL', 'cancelled' => 'Ditutup'][$o['status']] ?? $o['status']) : '';
    ?>
    <!doctype html>
    <html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Validasi Penawaran</title>
    <style>
    *{box-sizing:border-box} body{font-family:Helvetica, Arial, sans-serif;background:#f3f4f6;color:#111;margin:0;padding:20px;font-size:14px}
    .card{max-width:460px;margin:24px auto;background:#fff;border-radius:14px;box-shadow:0 6px 30px rgba(0,0,0,.08);overflow:hidden}
    .hd{padding:20px 22px;color:#fff;text-align:center}
    .ok{background:#0d9488}.bad{background:#991b1b}
    .hd .ic{font-size:34px;line-height:1}.hd h1{margin:6px 0 0;font-size:18px}
    .bd{padding:20px 22px}
    .row{display:flex;gap:10px;padding:7px 0;border-bottom:1px solid #f1f5f9}
    .row .k{width:120px;color:#6b7280;flex-shrink:0}.row .v{font-weight:600}
    .chip{display:inline-block;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:700;margin-top:10px}
    .muted{color:#6b7280;font-size:12px;margin-top:14px;text-align:center}
    </style></head><body>
    <div class="card">
        <?php if ($valid): ?>
        <div class="hd ok"><div class="ic">✓</div><h1>Dokumen Terverifikasi</h1></div>
        <div class="bd">
            <div class="row"><div class="k">No. Penawaran</div><div class="v"><?= $h($o['offer_no']) ?></div></div>
            <div class="row"><div class="k">Penyewa</div><div class="v"><?= $h(($o['company_name'] ?? '-') . ($o['brand_name'] ? ' — ' . $o['brand_name'] : '')) ?></div></div>
            <div class="row"><div class="k">Nilai</div><div class="v"><?= $rp($o['total_calculated']) ?></div></div>
            <div class="row"><div class="k">Sales</div><div class="v"><?= $h($o['pic_name'] ?: '-') ?></div></div>
            <div class="row"><div class="k">Status</div><div class="v"><?= $h($statusLbl) ?></div></div>
            <?php if ($salesReg): ?><div class="chip">TTD sales terdaftar ✓</div><?php endif; ?>
            <div class="muted">Dokumen ini diterbitkan oleh Management e-Walk &amp; Pentacity Mall Balikpapan.</div>
        </div>
        <?php else: ?>
        <div class="hd bad"><div class="ic">✕</div><h1>Tidak Valid</h1></div>
        <div class="bd"><p style="text-align:center;color:#6b7280">Tautan/QR tidak dikenali atau penawaran belum terbit.</p></div>
        <?php endif; ?>
    </div>
    </body></html>
    <?php
    exit;
}

// ─── Tanda tangan customer Surat Penawaran (PUBLIK, via sign_token) ───────────

/** Ambil penawaran + join tampilan via sign_token (tanpa scope properti). */
function _offer_by_token(PDO $pdo, string $token): ?array
{
    if ($token === '') return null;
    $st = $pdo->prepare(
        "SELECT o.*, c.company_name, c.brand_name,
                ct.name cp_name,
                u.location_name, u.floor,
                p.email pic_email, p.phone pic_phone
         FROM offers o
         LEFT JOIN master_clients c ON c.id = o.client_id
         LEFT JOIN master_client_contacts ct ON ct.id = o.contact_id
         LEFT JOIN master_cl_units u ON u.code = o.master_code AND u.property_id = o.property_id
         LEFT JOIN master_pic p ON p.name = o.pic_name AND p.property_id = o.property_id
         WHERE o.sign_token = ? LIMIT 1"
    );
    $st->execute([$token]);
    $o = $st->fetch();
    return $o ?: null;
}

/** Bangun data tampilan + nominal dari baris penawaran (live atau snapshot). */
function _offer_sign_view(array $o): array
{
    // Angka di halaman TTD customer harus sama persis dengan surat penawaran
    // yang ia terima — termasuk biaya listrik bila penawarannya mencentangnya.
    $sewa     = (float) $o['total_calculated'];
    $listrik  = offer_listrik($o);
    $total    = $sewa + $listrik;
    // PPN per komponen — jumlahnya jadi PPN total, supaya rinciannya bisa
    // ditampilkan dan penjumlahannya tetap pas.
    $kenaPpn    = !isset($o['ppn_flag']) || !empty($o['ppn_flag']);
    // Tarif mengikuti template yang sudah dibekukan di surat ini. Bawaannya
    // 12% + rumus PMK — sama persis dengan angka tetap yang dulu dipakai.
    $lj = !empty($o['letter_json']) ? (json_decode((string) $o['letter_json'], true) ?: []) : [];
    $tarifPpn = ((float) ($lj['ppn_persen'] ?? 12) / 100) * ((!isset($lj['ppn_rumus']) || $lj['ppn_rumus']) ? 11 / 12 : 1);
    $ppnSewa    = $kenaPpn ? round($sewa * $tarifPpn) : 0.0;
    $ppnListrik = $kenaPpn ? round($listrik * $tarifPpn) : 0.0;
    $ppn        = $ppnSewa + $ppnListrik;
    $afterPpn   = $total + $ppn;
    $deposit  = (float) $o['deposit_amount'];
    $days = ($o['start_date'] && $o['end_date'])
        ? ((int) floor((strtotime($o['end_date']) - strtotime($o['start_date'])) / 86400) + 1) : 0;
    $validTs  = strtotime(($o['offer_date'] ?: date('Y-m-d')) . ' +7 days');
    return [
        'company_name' => $o['company_name'] ?? '-',
        'cp_name'      => $o['cp_name'] ?? '',
        'location'     => ($o['location_name'] ?: $o['master_code']) . ($o['floor'] ? ' — Lt. ' . $o['floor'] : ''),
        'area'         => (float) ($o['area_sqm'] ?? 0),
        'start_date'   => $o['start_date'],
        'end_date'     => $o['end_date'],
        'days'         => $days,
        'periode'      => $o['start_date'] ? (date('d/m/Y', strtotime($o['start_date'])) . ' s/d ' . date('d/m/Y', strtotime($o['end_date']))) : '-',
        'berlaku'      => date('d/m/Y', $validTs),
        'amounts'      => [
            'sewa'        => $sewa,
            'listrik'     => $listrik,
            'listrik_hari' => _offer_days($o['start_date'] ?? null, $o['end_date'] ?? null),
            'ppn_sewa'    => $ppnSewa,
            'ppn_listrik' => $ppnListrik,
            'total'    => $total,
            'ppn'      => $ppn,
            'after'    => $afterPpn,
            'dp'       => (float) $o['dp_amount'],
            'dp_bln'   => rtrim(rtrim(number_format((float) $o['dp_months'], 1, ',', ''), '0'), ','),
            'deposit'  => $deposit,
            'dep_bln'  => rtrim(rtrim(number_format((float) $o['deposit_months'], 1, ',', ''), '0'), ','),
            'grand'    => $afterPpn + $deposit,
        ],
    ];
}

/** Halaman publik: customer review Surat Penawaran + tanda tangan. */
/**
 * Opsi B: sales mengunggah berkas surat penawaran yang sudah ditandatangani
 * customer — PDF hasil TTD digital maupun scan/foto dokumen kertas. Setara
 * dengan TTD online lewat tautan: penawaran menjadi DEAL dan nilainya dikunci
 * lewat snapshot, sehingga bisa langsung dilanjutkan ke SKP. Nilai kolom
 * sign_method tetap 'wet' mengikuti skp_sign_upload() supaya datanya seragam.
 */
function offer_sign_upload(PDO $pdo): void
{
    require_permission('manage_offers');
    verify_csrf();
    $pid = current_property_id();
    $id  = (int) post('id');

    $st = $pdo->prepare('SELECT * FROM offers WHERE id=? AND property_id=?');
    $st->execute([$id, $pid]);
    $o = $st->fetch();
    if (!$o) { flash('Penawaran tidak ditemukan.'); redirect_to('offers'); }
    if (!empty($o['signed_at'])) { flash('Penawaran ini sudah ditandatangani.'); redirect_to('offer_view', ['id' => $id]); }
    if (empty($o['offer_no']) || !in_array($o['status'], ['draft', 'nego', 'sent'], true)) {
        flash('Hanya penawaran bernomor yang belum DEAL/batal yang bisa ditandai TTD.');
        redirect_to('offer_view', ['id' => $id]);
    }

    $name = trim((string) post('sign_name'));
    if ($name === '') { flash('Nama penanda tangan wajib diisi.'); redirect_to('offer_view', ['id' => $id]); }
    if (empty($_FILES['signed_doc']['tmp_name']) || !is_uploaded_file($_FILES['signed_doc']['tmp_name'])) {
        flash('Dokumen penawaran yang sudah ber-TTD wajib diunggah.'); redirect_to('offer_view', ['id' => $id]);
    }
    $f = $_FILES['signed_doc'];
    if ($f['size'] <= 0 || $f['size'] > 8 * 1024 * 1024) { flash('Ukuran file maksimal 8MB.'); redirect_to('offer_view', ['id' => $id]); }
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
        flash('Format harus jpg/png/webp/pdf.'); redirect_to('offer_view', ['id' => $id]);
    }

    $dir = dirname(__DIR__, 2) . '/public/uploads/offer';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $fname = 'offer' . $id . '_signed_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) {
        flash('Gagal menyimpan file.'); redirect_to('offer_view', ['id' => $id]);
    }
    $rel = 'uploads/offer/' . $fname;

    // Snapshot dikunci sama seperti jalur TTD online, supaya nilai yang jadi
    // dasar SKP tidak ikut berubah kalau penawaran diutak-atik setelah ini.
    $full = $pdo->prepare(
        "SELECT o.*, c.company_name, c.brand_name, c.address, ct.name cp_name, ct.phone cp_phone,
                p.email pic_email, p.phone pic_phone
         FROM offers o
         LEFT JOIN master_clients c ON c.id=o.client_id
         LEFT JOIN master_client_contacts ct ON ct.id=o.contact_id
         LEFT JOIN master_pic p ON p.name=o.pic_name AND p.property_id=o.property_id
         WHERE o.id=? AND o.property_id=? LIMIT 1"
    );
    $full->execute([$id, $pid]);
    $row  = $full->fetch() ?: $o;
    $snap = _offer_sign_view($row);
    $snap['offer_no'] = $o['offer_no'];

    $ok = $pdo->prepare(
        "UPDATE offers SET status='deal', deal_at=COALESCE(deal_at, CURRENT_TIMESTAMP),
                sent_at=COALESCE(sent_at, CURRENT_TIMESTAMP),
                sign_method='wet', sign_name=?, signed_doc_path=?, signed_at=CURRENT_TIMESTAMP,
                snapshot_json=?
         WHERE id=? AND property_id=? AND signed_at IS NULL AND status IN ('draft','nego','sent')"
    );
    $ok->execute([$name, $rel, json_encode($snap, JSON_UNESCAPED_UNICODE), $id, $pid]);
    if (!$ok->rowCount()) {
        flash('Status penawaran berubah sebelum unggahan selesai. Coba lagi.');
        redirect_to('offer_view', ['id' => $id]);
    }

    audit($pdo, 'customer_sign_wet', 'offers', (string) $id, ['name' => $name, 'file' => $rel], [], 'offer');
    flash('Penawaran ditandai sudah ditandatangani sesuai dokumen terunggah. Status DEAL — bisa dilanjutkan ke SKP.');
    redirect_to('offer_view', ['id' => $id]);
}

function offer_sign_page(PDO $pdo): void
{
    $token = (string) getv('token', '');
    $o = _offer_by_token($pdo, $token);
    if (!$o || empty($o['offer_no']) || $o['status'] === 'cancelled') {
        http_response_code(404);
        exit('Tautan tanda tangan tidak valid atau sudah kedaluwarsa.');
    }
    // H3 — link kedaluwarsa & belum TTD: tutup paparan PII/harga. Penawaran yang
    // sudah TTD tetap bisa diverifikasi lewat halaman validasi (offer_verify).
    if (empty($o['signed_at']) && sign_token_expired($o['sign_token_expires_at'] ?? null)) {
        http_response_code(410);
        exit('Tautan tanda tangan sudah kedaluwarsa. Hubungi sales untuk link baru.');
    }
    // Temuan pentest M3: JANGAN mengubah state pada GET. Sebelumnya membuka link
    // (draft/nego → sent) dieksekusi saat render, sehingga link-prefetcher / crawler
    // WhatsApp / antivirus yang men-fetch URL diam-diam mempromosikan penawaran &
    // merusak metrik (sent_at dipakai deteksi fiktif). Promosi sekarang terjadi
    // ATOMIK di offer_sign_save (POST) bersamaan dengan penandatanganan.
    $signed = !empty($o['signed_at']);
    // Bila sudah TTD, tampilkan dari snapshot terkunci; bila belum, dari data live.
    // #9 — penawaran yg SUDAH TTD wajib punya snapshot valid; jangan diam-diam
    // hitung ulang dari data live (bisa beda dgn yg ditandatangani). Hard-fail.
    if ($signed) {
        $snap = !empty($o['snapshot_json']) ? json_decode($o['snapshot_json'], true) : null;
        if (!is_array($snap)) {
            http_response_code(409);
            exit('Data tanda tangan tidak konsisten. Hubungi admin.');
        }
        $d = $snap;
    } else {
        $d = _offer_sign_view($o);
    }
    $a = $d['amounts'] ?? [];
    $letter = offer_letter($pdo, $o);   // isi surat per jenis booth (snapshot/template)
    $items = !empty($o['is_bundle']) ? offer_items($pdo, (int) $o['id']) : []; // komponen paket
    // Jadwal harga bertahap ikut tercetak supaya perubahan harga di tahun
    // berikutnya sudah tercantum sejak surat pertama.
    require_once dirname(__DIR__) . '/AllocationService.php';
    $tahapHarga = AllocationService::priceSteps($pdo, null, (int) $o['id']);
    $rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $h  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    include __DIR__ . '/offer_sign_template.php';
}

/** Simpan TTD customer → kunci snapshot, status DEAL. */
function offer_sign_save(PDO $pdo): void
{
    $token = (string) post('token', getv('token', ''));
    $o = _offer_by_token($pdo, $token);
    // #7 + M3 — TTD customer boleh dari status 'draft'/'nego'/'sent' (belum TTD,
    // nomor terbit, bukan cancelled). Promosi ke 'sent' kini terjadi di sini (POST),
    // bukan saat GET render, agar tidak ada efek-samping dari fetch otomatis.
    if (!$o || empty($o['offer_no']) || !in_array($o['status'], ['draft', 'nego', 'sent'], true) || !empty($o['signed_at'])) {
        http_response_code(403);
        exit('Tautan tidak valid atau dokumen sudah ditandatangani.');
    }
    // #7 — tolak bila lewat masa berlaku (offer_date + 7 hari), sejalan _offer_sign_view.
    $validTs = strtotime(($o['offer_date'] ?: date('Y-m-d')) . ' +7 days');
    if ($validTs !== false && time() > $validTs) {
        http_response_code(410);
        exit('Penawaran sudah kedaluwarsa.');
    }
    if (sign_token_expired($o['sign_token_expires_at'] ?? null)) {  // H3
        http_response_code(410);
        exit('Tautan tanda tangan sudah kedaluwarsa.');
    }
    $name = trim((string) post('sign_name'));
    $data = (string) post('signature');
    if ($name === '' || !preg_match('#^data:image/png;base64,#', $data)) {
        http_response_code(422); exit('Nama dan tanda tangan wajib diisi.');
    }
    $bin = base64_decode(substr($data, strlen('data:image/png;base64,')), true);
    if ($bin === false || strlen($bin) < 200 || strlen($bin) > 800000) {
        http_response_code(422); exit('Tanda tangan tidak valid.');
    }
    // Snapshot dikunci pada saat TTD (sales boleh revisi sampai detik ini).
    $snap = _offer_sign_view($o);
    $snap['offer_no'] = $o['offer_no'];
    // Promosi sent_at di-stamp di sini (M3) bila belum pernah — menggantikan
    // auto-promote saat GET. Status 'deal' menutup; WHERE membatasi ke dokumen
    // belum-TTD pada status yang sah agar tetap atomik & anti-replay.
    $pdo->prepare(
        "UPDATE offers SET status='deal', deal_at=COALESCE(deal_at, CURRENT_TIMESTAMP),
                sent_at=COALESCE(sent_at, CURRENT_TIMESTAMP),
                sign_name=?, sign_ip=?, sign_ua=?, signature_data=?, signed_at=CURRENT_TIMESTAMP,
                snapshot_json=?
         WHERE id=? AND sign_token=? AND signed_at IS NULL AND status IN ('draft','nego','sent')"
    )->execute([
        $name,
        substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        $data,
        json_encode($snap, JSON_UNESCAPED_UNICODE),
        (int) $o['id'], $token,
    ]);
    audit($pdo, 'customer_sign', 'offers', (string) $o['id'], ['name' => $name], [], 'offer');
    redirect_to('offer_sign', ['token' => $token, 'done' => 1]);
}

// ─── Template Surat Penawaran per jenis booth (CRUD admin) ───────────────────

/** Pecah textarea (1 baris = 1 poin) → array bersih. */
function _tpl_lines(string $raw): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw)), fn($x) => $x !== ''));
}

function offer_templates_page(PDO $pdo): void
{
    require_permission('manage_master');
    $pid    = current_property_id();
    $module = _tpl_module((string) getv('module', 'cl'));
    $rows = $pdo->prepare("SELECT * FROM offer_templates WHERE property_id=? AND module=? ORDER BY is_default DESC, name ASC");
    $rows->execute([$pid, $module]);
    $tpls = $rows->fetchAll();

    layout('Template Dokumen', function () use ($tpls, $module) {
        $n = fn($j) => count(json_decode((string) ($j ?: '[]'), true) ?: []);
        ?>
        <div class="toolbar" style="gap:8px;flex-wrap:wrap;align-items:center">
            <?php foreach (_tpl_modules() as $mk => $ml): ?>
            <a class="btn light" style="<?= $mk === $module ? 'background:#0d9488;color:#fff;font-weight:700;border-color:#0d9488' : '' ?>" href="?r=offer_templates&module=<?= h($mk) ?>"><?= h($ml) ?></a>
            <?php endforeach; ?>
            <a class="btn" style="margin-left:auto" href="?r=offer_template_form&module=<?= h($module) ?>">+ Tambah Template</a>
        </div>
        <div class="panel" style="margin-top:12px">
            <p class="muted" style="margin-top:0"><?= _tpl_module_help($module) ?></p>
            <table class="data" style="width:100%">
                <thead><tr><th>Nama</th><th>Bentuk</th><th>PPN</th><th>Tipe Unit</th><th>DP</th><th>Fasilitas / Media / Bayar / Ketentuan</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($tpls as $t): ?>
                    <tr>
                        <td><strong><?= h($t['name']) ?></strong>
                            <?php if (!empty($t['is_default'])): ?><br><span class="badge" style="background:#ccfbf1;color:#0f766e">bawaan</span><?php endif; ?></td>
                        <td><?= ($t['layout'] ?? 'tabel') === 'rincian' ? 'Daftar bernomor' : 'Tabel harga' ?></td>
                        <td><?= h(rtrim(rtrim(number_format((float) ($t['ppn_persen'] ?? 12), 2, ',', '.'), '0'), ',')) ?>%<?= !empty($t['ppn_rumus']) ? '<br><span class="muted" style="font-size:11px">rumus PMK</span>' : '' ?></td>
                        <td><?php if (($t['unit_type'] ?? '') === ''): ?><span class="muted">semua</span><?php else: ?><?= h($t['unit_type']) ?><?php endif; ?></td>
                        <td><?= $t['dp_required'] ? 'Wajib · ' . h(rtrim(rtrim(number_format((float)$t['dp_months_default'],1,',',''),'0'),',')) . ' bln' : '<span class="muted">Tanpa DP</span>' ?></td>
                        <td class="muted"><?= $n($t['fasilitas_json']) ?> / <?= $n($t['media_json'] ?? '[]') ?> / <?= $n($t['payment_json']) ?> / <?= $n($t['terms_json']) ?></td>
                        <td><?= $t['status'] === 'active' ? '<span style="color:#16a34a">Aktif</span>' : '<span class="muted">Nonaktif</span>' ?></td>
                        <td><a class="btn light" href="?r=offer_template_form&id=<?= (int)$t['id'] ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$tpls): ?><tr><td colspan="8" class="muted">Belum ada template untuk modul ini.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    });
}

function offer_template_form(PDO $pdo): void
{
    require_permission('manage_master');
    $pid = current_property_id();
    $id  = (int) getv('id');
    $t = null;
    if ($id) {
        $st = $pdo->prepare("SELECT * FROM offer_templates WHERE id=? AND property_id=?");
        $st->execute([$id, $pid]);
        $t = $st->fetch();
        if (!$t) { flash('Template tidak ditemukan.'); redirect_to('offer_templates'); }
    }
    $module    = _tpl_module((string) ($t['module'] ?? getv('module', 'cl')));
    $unitTypes = cl_unit_types($pdo, $pid);
    $extra     = json_decode((string) ($t['extra_json'] ?? '{}'), true) ?: [];
    $val   = fn(string $k, $d = '') => h((string) ($t[$k] ?? $d));
    $lines = fn(string $col) => h(implode("\n", json_decode((string) ($t[$col] ?? '[]'), true) ?: []));
    $xl    = fn(string $k) => h(implode("\n", $extra[$k] ?? []));
    $judul = array_merge(offer_judul_bawaan(), json_decode((string) ($t['judul_json'] ?? '{}'), true) ?: []);
    $bank  = array_merge(offer_bank_bawaan(),  json_decode((string) ($t['bank_json'] ?? '{}'), true) ?: []);
    $rinci = h(offer_rincian_text(json_decode((string) ($t['rincian_json'] ?? '[]'), true) ?: []));

    /** Satu select dua-pilihan — dipakai untuk semua setelan gaya. */
    $pilih = function (string $nama, string $nilai, array $opsi) {
        $o = '';
        foreach ($opsi as $k => $label) {
            $o .= '<option value="' . h((string) $k) . '"' . ($nilai === (string) $k ? ' selected' : '') . '>' . h($label) . '</option>';
        }
        return '<select name="' . h($nama) . '">' . $o . '</select>';
    };

    layout(($t ? 'Edit' : 'Tambah') . ' Template Dokumen',
      function () use ($t, $id, $module, $unitTypes, $val, $lines, $xl, $judul, $bank, $rinci, $pilih) {
        $isCl = $module === 'cl';
        $g = fn(string $k, string $d) => (string) ($t[$k] ?? $d);
        ?>
        <style>
        /* Tata letak dua panel: isian di kiri, pratinjau menempel di kanan. */
        .tpl-layout { display:flex; gap:16px; align-items:flex-start; margin-top:12px }
        .tpl-isian  { flex:1; min-width:0 }
        .tpl-pv     { width:430px; flex:none; position:sticky; top:12px }
        @media (max-width:1180px){ .tpl-layout{flex-wrap:wrap} .tpl-pv{width:100%;position:static} }

        .tpl-tab { display:flex; gap:2px; border-bottom:2px solid var(--line,#e2e8f0); margin:0 0 16px }
        .tpl-tab button { appearance:none;border:0;background:none;padding:9px 15px;font-size:13px;font-weight:600;
                          color:#64748b;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px }
        .tpl-tab button.on { color:#0d9488; border-bottom-color:#0d9488 }

        .tpl-sek { font-size:11.5px;font-weight:700;color:#0f766e;letter-spacing:.05em;text-transform:uppercase;
                   margin:20px 0 9px;padding-bottom:4px;border-bottom:1px solid var(--line,#e2e8f0) }
        .tpl-sek:first-child { margin-top:0 }
        .tpl-row { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:11px 14px; margin-bottom:13px }
        .tpl-row.dua { grid-template-columns:repeat(2,minmax(0,1fr)) }
        .tpl-row .lebar { grid-column:1/-1 }
        .tpl-f > label { display:block;font-size:12px;font-weight:600;color:#334155;margin:0 0 4px }
        .tpl-f input, .tpl-f select, .tpl-f textarea { width:100%; margin:0 }
        .tpl-f .ket { font-size:11px;color:#94a3b8;margin-top:4px;line-height:1.4 }
        .tpl-f .ket code { background:#f1f5f9;padding:0 3px;border-radius:3px }
        .tpl-cek { display:flex;gap:8px;align-items:center;font-size:13px;cursor:pointer;
                   border:1px solid var(--line,#e2e8f0);border-radius:7px;padding:9px 11px;background:#f8fafc }
        .tpl-cek input { width:16px;height:16px;flex:none;margin:0 }
        .tpl-aksi { display:flex;gap:9px;align-items:center;margin-top:20px;padding-top:14px;border-top:1px solid var(--line,#e2e8f0) }

        .tpl-pv .kep { display:flex;align-items:center;gap:6px;margin-bottom:7px;flex-wrap:nowrap }
        .tpl-pv .kep b { font-size:12.5px;color:#334155;white-space:nowrap }
        .tpl-pv .kep .st { font-size:10.5px;color:#94a3b8;margin-left:auto;white-space:nowrap }
        .tpl-pv .box { border:1px solid var(--line,#e2e8f0);border-radius:9px;overflow:hidden;background:#eef2f7;
                       height:calc(100vh - 165px); min-height:430px }
        .tpl-pv iframe { width:100%;height:100%;border:0;background:#eef2f7;display:block }
        </style>

        <div class="toolbar" style="gap:8px">
            <a class="btn light" href="?r=offer_templates&module=<?= h($module) ?>">&larr; Daftar Template</a>
            <span class="badge" style="background:#e0f2fe;color:#0369a1"><?= h(_tpl_modules()[$module]) ?></span>
        </div>

        <div class="tpl-layout">
        <div class="tpl-isian">
        <form class="panel" id="tpl-form" method="post" action="?r=offer_template_save" style="margin:0">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <?php if ($t): ?><input type="hidden" name="module" value="<?= h($module) ?>"><?php endif; ?>
            <?php if (!$isCl): ?><input type="hidden" name="unit_type" value="<?= h((string)($t['unit_type'] ?? '')) ?>"><?php endif; ?>

            <nav class="tpl-tab">
                <button type="button" class="on" data-p="dasar">Dasar</button>
                <button type="button" data-p="bentuk">Bentuk &amp; Gaya</button>
                <button type="button" data-p="isi">Isi Surat</button>
                <button type="button" data-p="kata">Judul &amp; Penutup</button>
            </nav>

            <!-- ══ DASAR ══ -->
            <section data-p="dasar">
                <div class="tpl-row">
                    <div class="tpl-f"><label>Nama Template</label>
                        <input name="name" required value="<?= $val('name') ?>" placeholder="mis. Pushcart"></div>
                    <?php if (!$t): ?>
                    <div class="tpl-f"><label>Modul</label>
                        <select name="module"><?php foreach (_tpl_modules() as $mk => $ml): ?>
                            <option value="<?= h($mk) ?>" <?= $mk === $module ? 'selected' : '' ?>><?= h($ml) ?></option>
                        <?php endforeach; ?></select></div>
                    <?php endif; ?>
                    <div class="tpl-f"><label>Status</label>
                        <?= $pilih('status', $g('status', 'active'), ['active' => 'Aktif', 'inactive' => 'Nonaktif']) ?></div>
                    <?php if ($isCl): ?>
                    <div class="tpl-f"><label>Tipe Unit</label>
                        <select name="unit_type">
                            <option value="">Semua tipe</option>
                            <?php foreach ($unitTypes as $ut): ?><option value="<?= h($ut) ?>" <?= ($t['unit_type'] ?? null) === $ut ? 'selected' : '' ?>><?= h($ut) ?></option><?php endforeach; ?>
                            <?php $utc = (string)($t['unit_type'] ?? ''); if ($utc !== '' && !in_array($utc, $unitTypes, true)): ?><option value="<?= h($utc) ?>" selected><?= h($utc) ?></option><?php endif; ?>
                        </select>
                        <div class="ket">Sekadar usulan awal &mdash; sales tetap bisa memilih template mana pun.</div></div>
                    <?php endif; ?>
                    <div class="tpl-f lebar"><label>Judul Dokumen / Perihal</label>
                        <input name="perihal" value="<?= $val('perihal') ?>" placeholder="Penawaran Harga Sewa Pushcart"></div>
                    <div class="tpl-f lebar"><label>Paragraf Pembuka</label>
                        <textarea name="intro" rows="2" placeholder="Bersama ini kami Manajemen e-Walk dan Pentacity Mall Balikpapan menawarkan &hellip;"><?= $val('intro') ?></textarea></div>
                    <div class="lebar">
                        <label class="tpl-cek"><input type="checkbox" name="is_default" value="1" <?= !empty($t['is_default']) ? 'checked' : '' ?>>
                            <span>Jadikan template bawaan &mdash; otomatis terpilih saat sales membuat penawaran</span></label>
                    </div>
                </div>
            </section>

            <!-- ══ BENTUK & GAYA ══ -->
            <section data-p="bentuk" hidden>
                <div class="tpl-sek">Bentuk surat</div>
                <div class="tpl-row">
                    <div class="tpl-f"><label>Bentuk</label>
                        <?= $pilih('layout', $g('layout', 'tabel'), ['tabel' => 'Tabel harga', 'rincian' => 'Daftar bernomor']) ?>
                        <div class="ket">Tabel: Pushcart, Snack Corner, Atrium. Bernomor: Foodcourt.</div></div>
                    <div class="tpl-f"><label>Judul bagian</label>
                        <?= $pilih('gaya_judul', $g('gaya_judul', 'aplikasi'), ['aplikasi' => 'Hijau polos', 'romawi' => 'Hitam I, II, III']) ?></div>
                    <div class="tpl-f"><label>Gaya daftar</label>
                        <?= $pilih('gaya_daftar', $g('gaya_daftar', 'nomor'), ['nomor' => 'Bernomor 1. 2. 3.', 'bullet' => 'Bullet']) ?></div>
                    <div class="tpl-f"><label>Letak rekening</label>
                        <?= $pilih('gaya_bank', $g('gaya_bank', 'kotak'), ['kotak' => 'Kotak tersendiri', 'menyatu' => 'Menyatu di Cara Pembayaran']) ?></div>
                    <div class="tpl-f"><label>Penulisan rupiah</label>
                        <?= $pilih('gaya_uang', $g('gaya_uang', 'polos'), ['polos' => 'Rp 7.000.000', 'kertas' => 'Rp. 7.000.000,-']) ?></div>
                    <div class="tpl-f"><label>Sebutan TTD kanan</label>
                        <input name="ttd_kanan" value="<?= h((string) ($t['ttd_kanan'] ?? 'Calon Penyewa')) ?>" placeholder="Calon Penyewa">
                        <div class="ket">Kosongkan = langsung nama penanggung jawab.</div></div>
                </div>

                <div class="tpl-sek">PPN</div>
                <div class="tpl-row">
                    <div class="tpl-f"><label>Persen</label>
                        <input type="number" step="0.5" min="0" max="100" name="ppn_persen" value="<?= $val('ppn_persen', '12') ?>"></div>
                    <div class="tpl-f"><label>Rumus PMK</label>
                        <?= $pilih('ppn_rumus', (!$t || !empty($t['ppn_rumus'])) ? '1' : '0', ['1' => 'Pakai (Nilai × 11/12 × tarif)', '0' => 'Tidak']) ?></div>
                    <div class="tpl-f"><label>Tabel Rincian Biaya</label>
                        <?= $pilih('rincian_biaya', (!$t || !empty($t['rincian_biaya'])) ? '1' : '0', ['1' => 'Dicetak', '0' => 'Tidak dicetak']) ?></div>
                    <div class="tpl-f lebar"><label>Catatan PPN</label>
                        <input name="ppn_catatan" value="<?= $val('ppn_catatan') ?>" placeholder="*PPN 12% Sesuai PMK Nomor 131 Tahun 2024 dengan perhitungan (NILAI SEWA x 11/12 x 12%)">
                        <div class="ket">Mengubah kalimat saja. 12% + rumus PMK = 11% polos, nilainya sama persis.</div></div>
                </div>

                <div class="tpl-sek">Tambahan buatan aplikasi</div>
                <div class="tpl-row dua">
                    <div class="tpl-f"><label>Kotak "Penawaran berlaku s/d"</label>
                        <?= $pilih('tampil_berlaku', (!$t || !empty($t['tampil_berlaku'])) ? '1' : '0', ['1' => 'Dicetak', '0' => 'Tidak dicetak']) ?></div>
                    <div class="tpl-f"><label>QR "Scan untuk validasi"</label>
                        <?= $pilih('tampil_qr', (!$t || !empty($t['tampil_qr'])) ? '1' : '0', ['1' => 'Dicetak', '0' => 'Tidak dicetak']) ?>
                        <div class="ket">Dipakai client memastikan surat asli &mdash; sebaiknya tetap dicetak.</div></div>
                </div>

                <?php if ($isCl): ?>
                <div class="tpl-sek">Isian awal penawaran</div>
                <div class="tpl-row">
                    <div class="tpl-f"><label>DP</label>
                        <?= $pilih('dp_required', (!$t || !empty($t['dp_required'])) ? '1' : '0', ['1' => 'Wajib', '0' => 'Tanpa DP (deposit saja)']) ?></div>
                    <div class="tpl-f"><label>Default DP (bulan)</label>
                        <input type="number" step="0.5" min="0" name="dp_months_default" value="<?= $val('dp_months_default', '2') ?>"></div>
                    <div class="tpl-f"><label>Listrik per bulan (Rp)</label>
                        <input type="number" min="0" step="1000" name="electricity_default" value="<?= $val('electricity_default', '150000') ?>"></div>
                </div>
                <?php endif; ?>
            </section>

            <!-- ══ ISI SURAT ══ -->
            <section data-p="isi" hidden>
                <?php if ($isCl): ?>
                <div class="tpl-row">
                    <div class="tpl-f lebar"><label>Bullet kolom "Keterangan"</label>
                        <textarea name="ket" rows="4" placeholder="Masa sewa {hari} hari&#10;Periode sewa {periode_panjang}&#10;Harga belum termasuk PPN {ppn_persen}"><?= $lines('ket_json') ?></textarea>
                        <div class="ket">1 baris = 1 bullet &middot; <code>{hari}</code> <code>{periode_panjang}</code> <code>{ppn_persen}</code> <code>{luas}</code> <code>{harga}</code></div></div>
                    <div class="tpl-f lebar" id="blok-rincian"><label>Daftar Bernomor</label>
                        <textarea name="rincian" rows="9" placeholder="Lokasi :: BSB Foodcourt, Pentacity Shopping Avenue&#10;Alamat :: Jl Jend. Sudirman, Balikpapan&#10;Area &amp; Ukuran :: {luas}"><?= $rinci ?></textarea>
                        <div class="ket">Tulis <code>Label :: isi</code>. Baris lanjutan menempel ke baris di atasnya.</div></div>
                </div>
                <div class="tpl-row dua">
                    <div class="tpl-f"><label>Fasilitas</label>
                        <textarea name="fasilitas" rows="4"><?= $lines('fasilitas_json') ?></textarea></div>
                    <div class="tpl-f"><label>Media Promosi</label>
                        <textarea name="media" rows="4"><?= $lines('media_json') ?></textarea></div>
                    <div class="tpl-f lebar"><label>Cara Pembayaran</label>
                        <textarea name="payment" rows="5"><?= $lines('payment_json') ?></textarea>
                        <div class="ket">1 baris = 1 poin &middot; <code>{dp}</code> <code>{deposit}</code> <code>{total}</code> <code>{grand}</code></div></div>
                </div>
                <?php endif; ?>

                <?php if ($module === 'gudang'): ?>
                <div class="tpl-row"><div class="tpl-f lebar"><label>Bullet Kolom "Keterangan"</label>
                    <textarea name="x_keterangan" rows="3"><?= $xl('keterangan_default') ?></textarea>
                    <div class="ket"><code>{periode}</code> <code>{deposit}</code></div></div></div>
                <?php elseif ($module === 'media'): ?>
                <div class="tpl-row dua">
                    <div class="tpl-f"><label>Daftar Utilities</label><textarea name="x_utilities" rows="4"><?= $xl('utilities') ?></textarea></div>
                    <div class="tpl-f"><label>Daftar Media Promo</label><textarea name="x_media_promo" rows="4"><?= $xl('media_promo') ?></textarea></div>
                    <div class="tpl-f lebar"><label>Pilihan Parkir Kendaraan</label><textarea name="x_parkir" rows="2"><?= $xl('parkir') ?></textarea></div>
                </div>
                <?php endif; ?>

                <div class="tpl-row">
                    <div class="tpl-f lebar"><label><?= $module === 'gudang' ? 'Peraturan Sewa' : ($module === 'media' ? 'Catatan Formulir' : 'Ketentuan &amp; Persyaratan') ?></label>
                        <textarea name="terms" rows="9"><?= $lines('terms_json') ?></textarea></div>
                    <div class="tpl-f lebar"><label>Catatan Kaki</label>
                        <textarea name="notes" rows="2"><?= $lines('notes_json') ?></textarea></div>
                </div>
            </section>

            <!-- ══ JUDUL & PENUTUP ══ -->
            <section data-p="kata" hidden>
                <div class="tpl-sek">Judul tiap bagian</div>
                <div class="tpl-row">
                    <div class="tpl-f"><label>Rincian Biaya</label><input name="judul_rincian_biaya" value="<?= h($judul['rincian_biaya']) ?>"></div>
                    <div class="tpl-f"><label>Fasilitas</label><input name="judul_fasilitas" value="<?= h($judul['fasilitas']) ?>"></div>
                    <div class="tpl-f"><label>Media Promosi</label><input name="judul_media" value="<?= h($judul['media']) ?>"></div>
                    <div class="tpl-f"><label>Cara Pembayaran</label><input name="judul_pembayaran" value="<?= h($judul['pembayaran']) ?>"></div>
                    <div class="tpl-f"><label>Ketentuan</label><input name="judul_ketentuan" value="<?= h($judul['ketentuan']) ?>"></div>
                </div>

                <div class="tpl-sek">Judul kolom tabel</div>
                <div class="tpl-row">
                    <div class="tpl-f"><label>Kolom 1 &mdash; lokasi</label><input name="judul_kolom_lokasi" value="<?= h($judul['kolom_lokasi']) ?>"></div>
                    <div class="tpl-f"><label>Kolom 2 &mdash; luas</label><input name="judul_kolom_luas" value="<?= h($judul['kolom_luas']) ?>"></div>
                    <div class="tpl-f"><label>Kolom 3 &mdash; harga</label><input name="judul_kolom_harga" value="<?= h($judul['kolom_harga']) ?>"></div>
                    <div class="tpl-f"><label>Kolom 4 &mdash; keterangan</label><input name="judul_kolom_ket" value="<?= h($judul['kolom_ket']) ?>"></div>
                </div>

                <div class="tpl-sek">Rekening pembayaran</div>
                <div class="tpl-row">
                    <div class="tpl-f"><label>Kalimat pembuka</label><input name="bank_kalimat" value="<?= h($bank['kalimat']) ?>"></div>
                    <div class="tpl-f"><label>Atas nama</label><input name="bank_atas_nama" value="<?= h($bank['atas_nama']) ?>"></div>
                    <div class="tpl-f"><label>Bank</label><input name="bank_bank" value="<?= h($bank['bank']) ?>"></div>
                    <div class="tpl-f lebar"><label>Nomor rekening</label><input name="bank_rekening" value="<?= h($bank['rekening']) ?>">
                        <div class="ket">Kosongkan bila blok rekening tidak perlu dicetak.</div></div>
                </div>

                <div class="tpl-sek">Kalimat penutup</div>
                <div class="tpl-row">
                    <div class="tpl-f lebar"><label>Kalimat kontak</label>
                        <textarea name="penutup" rows="2" placeholder="Untuk keterangan lebih lanjut dapat menghubungi kantor kami {KANTOR} atau whatsapp ke {PIC_BESAR} di nomor {WA}."><?= $val('penutup') ?></textarea>
                        <div class="ket"><code>{PIC}</code> <code>{PIC_BESAR}</code> <code>{WA}</code> <code>{EMAIL}</code> <code>{KANTOR}</code> &mdash; diisi dari Master PIC sales pembuat.</div></div>
                    <div class="tpl-f lebar"><label>Kalimat terakhir</label>
                        <input name="penutup_akhir" value="<?= $val('penutup_akhir') ?>" placeholder="<?= h(offer_penutup_akhir_bawaan()) ?>"></div>
                </div>
            </section>

            <div class="tpl-aksi">
                <button type="submit">&#128190; Simpan Template</button>
                <a class="btn secondary" href="?r=offer_templates&module=<?= h($module) ?>">Batal</a>
                <span class="help" style="margin-left:auto">Ctrl+B di kotak isian = <strong>tebal</strong></span>
            </div>
        </form>
        </div>

        <aside class="tpl-pv">
            <div class="kep"><b>Pratinjau</b>
                <button type="button" class="btn light" id="pv-segar" style="padding:3px 8px;font-size:11px">Segarkan</button>
                <select id="pv-zoom" style="width:auto;padding:3px 5px;font-size:11px;margin:0">
                    <option value="page-width" selected>Lebar penuh</option>
                    <option value="50">50%</option>
                    <option value="75">75%</option>
                    <option value="100">100%</option>
                    <option value="125">125%</option>
                    <option value="150">150%</option>
                    <option value="200">200%</option>
                </select>
                <button type="button" class="btn light" id="pv-tab" style="padding:3px 8px;font-size:11px" title="Buka di tab baru">&#8599;</button>
                <span class="st" id="pv-st"></span></div>
            <div class="box" id="pv-box"><iframe id="pv-bingkai" name="pv_bingkai" title="Pratinjau surat"></iframe></div>
        </aside>
        <?php /* Pratinjau dikirim lewat formulir biasa yang menembak ke bingkai di
                 atas, BUKAN fetch+blob. Aturan keamanan halaman (CSP) memakai
                 default-src 'self' sehingga alamat blob: ditolak peramban; cara
                 ini tetap satu asal, jadi lolos tanpa melonggarkan CSP. */ ?>
        <form id="pv-form" method="post" action="?r=offer_template_preview" target="pv_bingkai" style="display:none"></form>
        </div>

        <script>
        (function () {
            var form = document.getElementById('tpl-form');

            // ── Tab ───────────────────────────────────────────────────────────
            var tombol = form.querySelectorAll('.tpl-tab button');
            tombol.forEach(function (b) {
                b.addEventListener('click', function () {
                    tombol.forEach(function (x) { x.classList.toggle('on', x === b); });
                    form.querySelectorAll('section[data-p]').forEach(function (s) {
                        s.hidden = s.getAttribute('data-p') !== b.getAttribute('data-p');
                    });
                });
            });

            // Daftar bernomor hanya relevan untuk bentuk "Daftar bernomor".
            var selLayout = form.querySelector('select[name=layout]');
            var blokRin = document.getElementById('blok-rincian');
            function aturRincian() {
                if (blokRin && selLayout) blokRin.style.display = selLayout.value === 'rincian' ? '' : 'none';
            }
            if (selLayout) selLayout.addEventListener('change', aturRincian);
            aturRincian();

            // ── Ctrl+B = tebal ────────────────────────────────────────────────
            form.querySelectorAll('textarea').forEach(function (tx) {
                tx.addEventListener('keydown', function (e) {
                    if (!(e.ctrlKey || e.metaKey) || (e.key !== 'b' && e.key !== 'B')) return;
                    e.preventDefault();
                    var a = this.selectionStart, b = this.selectionEnd, v = this.value, p = v.substring(a, b);
                    this.value = v.substring(0, a) + '**' + p + '**' + v.substring(b);
                    this.selectionStart = a + 2; this.selectionEnd = a + 2 + p.length;
                    this.dispatchEvent(new Event('input', { bubbles: true }));
                });
            });

            // ── Pratinjau: PDF sungguhan, halamannya terpisah seperti nanti dicetak ──
            var bingkai = document.getElementById('pv-bingkai'), st = document.getElementById('pv-st');
            var selZoom = document.getElementById('pv-zoom'), pvForm = document.getElementById('pv-form');

            /* Zoom ditentukan lewat penunjuk di belakang alamat (#zoom= / #view=),
               yang dibaca pembaca PDF bawaan peramban. Nilai yang sama juga
               dikirim ke server sebagai cadangan untuk pembaca lain. */
            function alamat() {
                var v = selZoom.value;
                return '?r=offer_template_preview#' + (v === 'page-width' ? 'view=FitH' : 'zoom=' + v);
            }

            /** Salin seluruh isian formulir utama ke formulir pratinjau. */
            function isiUlang() {
                pvForm.action = alamat();
                pvForm.innerHTML = '';
                var data = new FormData(form);
                data.append('zoom', selZoom.value === 'page-width' ? 'fullwidth' : selZoom.value);
                data.forEach(function (nilai, nama) {
                    if (typeof nilai !== 'string') return;      // lewati berkas
                    var i = document.createElement('input');
                    i.type = 'hidden'; i.name = nama; i.value = nilai;
                    pvForm.appendChild(i);
                });
            }

            var jeda = null;
            function gambar() {
                st.textContent = 'menggambar…';
                isiUlang();
                pvForm.submit();
            }
            bingkai.addEventListener('load', function () {
                if (st.textContent === 'menggambar…') {
                    st.textContent = 'diperbarui ' + new Date().toLocaleTimeString('id-ID');
                }
            });
            // Jeda sedikit lebih panjang: tiap gambar berarti satu PDF dibuat ulang.
            function nanti() { clearTimeout(jeda); jeda = setTimeout(gambar, 900); }
            form.addEventListener('input', nanti);
            form.addEventListener('change', nanti);
            selZoom.addEventListener('change', gambar);
            document.getElementById('pv-segar').addEventListener('click', gambar);
            document.getElementById('pv-tab').addEventListener('click', function () {
                // Tab baru: formulir yang sama, hanya sasarannya diganti.
                isiUlang();
                pvForm.target = '_blank'; pvForm.submit(); pvForm.target = 'pv_bingkai';
            });
            gambar();
        })();
        </script>
        <?php
    });
}

/**
 * Satu baris template dari isian formulir — TANPA menyimpan apa pun.
 *
 * Dipakai dua kali: oleh penyimpan, dan oleh pratinjau yang menggambar surat
 * dari isian yang belum disimpan. Karena keduanya memakai fungsi yang sama,
 * apa yang terlihat di pratinjau pasti sama dengan yang nanti tersimpan.
 */
function _offer_template_dari_post(int $pid, string $module, string $unitType, string $name): array
{
    // Blok khusus modul — daftar yang bisa disusun sendiri oleh user.
    $extra = [];
    if ($module === 'media') {
        $extra = [
            'utilities'   => _tpl_lines((string) post('x_utilities', '')),
            'media_promo' => _tpl_lines((string) post('x_media_promo', '')),
            'parkir'      => _tpl_lines((string) post('x_parkir', '')),
        ];
    } elseif ($module === 'gudang') {
        $extra = ['keterangan_default' => _tpl_lines((string) post('x_keterangan', ''))];
    }

    $J = fn($a) => json_encode($a, JSON_UNESCAPED_UNICODE);
    return [
        'property_id'       => $pid,
        'module'            => $module,
        'unit_type'         => $unitType,
        'name'              => $name,
        'perihal'           => trim((string) post('perihal', '')),
        'intro'             => trim((string) post('intro', '')),
        'is_default'        => post('is_default') ? 1 : 0,
        'layout'            => post('layout') === 'rincian' ? 'rincian' : 'tabel',
        'gaya_daftar'       => post('gaya_daftar') === 'bullet' ? 'bullet' : 'nomor',
        'gaya_judul'        => post('gaya_judul') === 'romawi' ? 'romawi' : 'aplikasi',
        'gaya_bank'         => post('gaya_bank') === 'menyatu' ? 'menyatu' : 'kotak',
        'penutup_akhir'     => trim((string) post('penutup_akhir', '')),
        'ttd_kanan'         => trim((string) post('ttd_kanan', '')),
        'tampil_berlaku'    => post('tampil_berlaku') ? 1 : 0,
        'tampil_qr'         => post('tampil_qr') ? 1 : 0,
        'gaya_uang'         => post('gaya_uang') === 'kertas' ? 'kertas' : 'polos',
        'ppn_persen'        => max(0, min(100, (float) post('ppn_persen', 12))),
        'ppn_rumus'         => post('ppn_rumus') ? 1 : 0,
        'ppn_catatan'       => trim((string) post('ppn_catatan', '')),
        'rincian_biaya'     => post('rincian_biaya') ? 1 : 0,
        'fasilitas_json'    => $J(_tpl_lines((string) post('fasilitas', ''))),
        'media_json'        => $J(_tpl_lines((string) post('media', ''))),
        'ket_json'          => $J(_tpl_lines((string) post('ket', ''))),
        'rincian_json'      => $J(offer_rincian_parse((string) post('rincian', ''))),
        'judul_json'        => $J(array_filter([
            'rincian_biaya' => trim((string) post('judul_rincian_biaya', '')),
            'kolom_lokasi'  => trim((string) post('judul_kolom_lokasi', '')),
            'kolom_luas'    => trim((string) post('judul_kolom_luas', '')),
            'kolom_harga'   => trim((string) post('judul_kolom_harga', '')),
            'kolom_ket'     => trim((string) post('judul_kolom_ket', '')),
            'fasilitas'     => trim((string) post('judul_fasilitas', '')),
            'media'         => trim((string) post('judul_media', '')),
            'pembayaran'    => trim((string) post('judul_pembayaran', '')),
            'ketentuan'     => trim((string) post('judul_ketentuan', '')),
        ], fn($v) => $v !== '')),
        'bank_json'         => $J([
            'kalimat'   => trim((string) post('bank_kalimat', '')),
            'atas_nama' => trim((string) post('bank_atas_nama', '')),
            'bank'      => trim((string) post('bank_bank', '')),
            'rekening'  => trim((string) post('bank_rekening', '')),
        ]),
        'penutup'           => trim((string) post('penutup', '')),
        'payment_json'      => $J(_tpl_lines((string) post('payment', ''))),
        'terms_json'        => $J(_tpl_lines((string) post('terms', ''))),
        'notes_json'        => $J(_tpl_lines((string) post('notes', ''))),
        'extra_json'        => $J($extra),
        'dp_required'       => $module === 'cl' ? (post('dp_required') ? 1 : 0) : 0,
        'dp_months_default' => $module === 'cl' ? (float) post('dp_months_default', 2) : 0,
        'electricity_default' => $module === 'cl' ? max(0, (float) post('electricity_default', 150000)) : 0,
        'status'            => post('status') === 'inactive' ? 'inactive' : 'active',
    ];
}

/**
 * Pratinjau surat dari isian formulir yang BELUM disimpan.
 *
 * Menggambar surat memakai template hasil _offer_template_dari_post() dan satu
 * penawaran contoh, lalu mengembalikan HTML-nya untuk ditampilkan di bingkai
 * sebelah formulir. Tidak menyentuh basis data sama sekali.
 */
function offer_template_preview(PDO $pdo): void
{
    require_permission('manage_master');
    verify_csrf();
    $pid    = current_property_id();
    $module = _tpl_module((string) post('module', 'cl'));
    $tplRow = _offer_template_dari_post($pid, $module, (string) post('unit_type', ''), (string) post('name', 'Template'));
    $letter = offer_letter_dari_template(_offer_template_norm($tplRow));

    // PIC asli properti ini supaya {PIC} / {WA} / {EMAIL} terlihat terisi.
    $pic = $pdo->prepare("SELECT name, phone, email, role_name FROM master_pic
                           WHERE property_id = ? AND status = 'active' AND phone IS NOT NULL
                           ORDER BY (role_name LIKE 'Sales%') DESC, name LIMIT 1");
    $pic->execute([$pid]);
    $pic = $pic->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'Nama Sales', 'phone' => '081200000000', 'email' => '', 'role_name' => 'Sales Executive'];

    $prop = current_property();
    $mulai = date('Y-m-01', strtotime('+1 month'));
    $akhir = date('Y-m-t', strtotime('+1 month'));
    $o = [
        'id' => 0, 'property_id' => $pid, 'module' => $module, 'is_bundle' => 0,
        'offer_no'   => '000/QT-CL/' . strtoupper((string) ($prop['key'] ?? 'PSV')) . '/BSB-BPN/' . _offer_roman((int) date('n')) . '/' . date('Y'),
        'offer_date' => date('Y-m-d'), 'perihal' => $letter['perihal'],
        'company_name' => 'PT. CONTOH PENYEWA', 'brand_name' => '', 'cp_name' => 'Bapak/Ibu Penanggung Jawab',
        'city' => '', 'address' => '',
        'location_name' => 'Contoh Lokasi', 'floor' => '', 'master_code' => 'CONTOH-01',
        'area_sqm' => 6, 'ukuran' => '2x3 m2', 'keterangan' => '',
        'pricing_type' => 'monthly', 'unit_rate' => 7000000, 'quantity' => 1, 'slots' => 1,
        'start_date' => $mulai, 'end_date' => $akhir, 'contract_months' => 1,
        'total_calculated' => 7000000, 'monthly_amount' => 7000000, 'override_amount' => null,
        'dp_months' => 1, 'dp_amount' => 2100000, 'deposit_months' => 1, 'deposit_amount' => 1000000,
        'deposit_paid' => 0, 'ppn_flag' => 1, 'sc_flag' => 0, 'sc_monthly' => 0,
        'electricity_flag' => 0, 'electricity_monthly' => 0, 'electricity_units' => 0, 'electricity_amount' => 0,
        'sign_token' => str_repeat('0', 40), 'signed_at' => null, 'signature_data' => null,
        'sign_name' => null, 'sign_method' => 'online',
        'pic_name' => $pic['name'], 'pic_phone' => $pic['phone'], 'pic_email' => $pic['email'],
        'pic_role' => $pic['role_name'], 'pic_signature' => null,
    ];
    $items = []; $tahapHarga = []; $tierHarga = [];
    $rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $h  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $PRATINJAU = true;   // sembunyikan tombol cetak & petunjuk layar kecil

    // PDF SUNGGUHAN, bukan tiruan HTML. Dua alasan: halamannya terpisah persis
    // seperti nanti dicetak (versi HTML menyambung ke bawah tanpa batas kertas),
    // dan yang terlihat benar-benar berkas yang akan diterima client.
    require_once dirname(__DIR__) . '/pdf.php';
    $PDF_MODE = true;
    ob_start();
    include __DIR__ . '/offer_print_template.php';
    $html = ob_get_clean();
    try {
        // Zoom ditanam juga di dalam berkas PDF-nya sebagai cadangan. Chrome
        // mengabaikan ini dan memakai pecahan alamat (#zoom=) yang dipasang di
        // sisi halaman; penampil lain yang membaca /OpenAction tetap kebagian.
        $z = (string) post('zoom', 'fullwidth');
        $zoom = is_numeric($z) ? max(25, min(400, (int) $z)) : 'fullwidth';
        $mpdf = clara_letterhead_mpdf();
        $mpdf->SetDisplayMode($zoom, 'continuous');
        $mpdf->WriteHTML($html);
        $pdf = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="pratinjau-template.pdf"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
    } catch (Throwable $e) {
        // Pratinjau tidak boleh menjatuhkan halaman pengaturan — tampilkan sebabnya.
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><div style="font:13px sans-serif;padding:16px;color:#b91c1c">'
           . 'Pratinjau gagal dibuat: ' . $h($e->getMessage()) . '</div>';
    }
    exit;
}

function offer_template_save(PDO $pdo): void
{
    require_permission('manage_master');
    verify_csrf();
    $pid = current_property_id();
    $id  = (int) post('id');
    $module   = _tpl_module((string) post('module', 'cl'));
    $unitType = $module === 'cl' ? trim((string) post('unit_type', '')) : '';
    $name = trim((string) post('name', ''));
    if ($name === '') { flash('Nama template wajib diisi.'); redirect_to('offer_template_form', $id ? ['id' => $id] : ['module' => $module]); }
    // Nama template harus khas — itu yang dibaca sales di dropdown. Tipe unit
    // tidak lagi dibatasi satu template: sejak template bisa DIPILIH sendiri,
    // beberapa template untuk tipe yang sama justru memang dibutuhkan (mis.
    // Pushcart dan Snack Corner sama-sama Island).
    $dup = $pdo->prepare("SELECT id FROM offer_templates WHERE property_id=? AND module=? AND name=? AND id<>? LIMIT 1");
    $dup->execute([$pid, $module, $name, $id]);
    if ($dup->fetchColumn()) {
        flash('Sudah ada template bernama "' . $name . '" di modul ini. Pakai nama lain supaya tidak tertukar saat dipilih.');
        redirect_to('offer_template_form', $id ? ['id' => $id] : ['module' => $module]);
    }

    $data = _offer_template_dari_post($pid, $module, $unitType, $name);

    if ($id) {
        $updateData = $data;
        unset($updateData['property_id']);
        $sets = implode(', ', array_map(fn($k) => "$k=:$k", array_keys($updateData)));
        $data['id'] = $id;
        $pdo->prepare("UPDATE offer_templates SET $sets, updated_at=NOW() WHERE id=:id AND property_id=:property_id")->execute($data);
        audit($pdo, 'update', 'offer_templates', (string) $id, $data);
        $simpanId = $id;
    } else {
        $cols = implode(',', array_keys($data));
        $ph   = implode(',', array_map(fn($k) => ":$k", array_keys($data)));
        $pdo->prepare("INSERT INTO offer_templates ($cols) VALUES ($ph)")->execute($data);
        $simpanId = (int) $pdo->lastInsertId();
        audit($pdo, 'create', 'offer_templates', (string) $simpanId, $data);
    }
    // Bawaan hanya boleh satu per modul — dua bawaan berarti pilihan awal sales
    // ditentukan urutan baris, dan itu tidak bisa diterka siapa pun.
    if (!empty($data['is_default'])) {
        $pdo->prepare("UPDATE offer_templates SET is_default = 0 WHERE property_id = ? AND module = ? AND id <> ?")
            ->execute([$pid, $module, $simpanId]);
    }
    flash('Template dokumen disimpan.');
    redirect_to('offer_templates', ['module' => $module]);
}

/** AJAX: aturan DP & nama template utk unit terpilih (dipakai form penawaran). */
function offer_template_rule(PDO $pdo): void
{
    require_permission('manage_offers');
    header('Content-Type: application/json');
    $pid = current_property_id();
    $module   = _tpl_module((string) getv('module', 'cl'));
    $unitType = $module === 'cl' ? offer_unit_type($pdo, $pid, (string) getv('master_code', '')) : '';
    // Template yang dipilih di dropdown menang; tanpa itu, tetap ditebak dari unit.
    $tpl = offer_template_for($pdo, $pid, $unitType, $module, (int) getv('template_id', 0));
    echo json_encode([
        'unit_type'         => $unitType,
        'template'          => $tpl['name'],
        'layout'            => $tpl['layout'],
        'ppn_persen'        => $tpl['ppn_persen'],
        'ppn_rumus'         => $tpl['ppn_rumus'],
        'dp_required'       => $tpl['dp_required'],
        'dp_months_default' => $tpl['dp_months_default'],
        'electricity_default' => $tpl['electricity_default'],
    ]);
    exit;
}
