<?php
declare(strict_types=1);

/**
 * Monitoring Transaksi — satu baris per DOKUMEN (SKP / SKS Gudang / Form
 * Utilities), dengan satu pertanyaan yang dijawab di tiap baris: dokumen ini
 * berhenti di mana, dan sekarang giliran siapa.
 *
 * Kenapa dokumen, bukan penawaran: penawaran yang belum jadi dokumen belum
 * menuntut pekerjaan siapa pun — yang perlu dikejar baru lahir setelah ada
 * SKP/SKS/FU. Kenapa bukan transaksi: satu dokumen paket menerbitkan beberapa
 * transaksi untuk SATU kesepakatan, dan menampilkannya sebagai tiga baris
 * membuat satu kesepakatan terbaca seperti tiga pekerjaan.
 *
 * Yang dihitung di sini TIDAK disimpan: tahap selalu diturunkan dari keadaan
 * dokumen, permintaan kontrak, invoice, dan pembayaran pada saat halaman
 * dibuka. Tidak ada kolom status yang bisa basi.
 *
 * Satu-satunya yang disimpan adalah jejak tindak lanjut (tabel
 * monitoring_tindak): siapa mengejar apa, kapan, dan apa katanya. Itulah yang
 * membedakan daftar ini dari sekadar pengingat yang dilihat lalu ditinggalkan —
 * "sudah dikejar 3×, PIC belum bergerak" adalah fakta yang bisa dibaca
 * Asisten Manager tanpa ada yang perlu melapor.
 */

/** Tahap perjalanan dokumen: [label, warna teks, warna latar, urutan]. */
function mon_tahap_daftar(): array
{
    return [
        'draft'           => ['Draft — belum diajukan',          '#475569', '#E2E8F0'],
        'revisi'          => ['Sedang direvisi',                 '#B45309', '#FEF3C7'],
        'approval'        => ['Menunggu persetujuan',            '#6D28D9', '#EDE9FE'],
        'ditolak'         => ['Ditolak',                         '#B91C1C', '#FEE2E2'],
        'ttd_klien'       => ['Menunggu tanda tangan klien',     '#0369A1', '#E0F2FE'],
        'transaksi_belum' => ['Transaksi belum terbit',          '#B91C1C', '#FEE2E2'],
        'kontrak_draft'   => ['Permintaan kontrak masih draft',  '#B45309', '#FEF3C7'],
        'kontrak_legal'   => ['Menunggu Legal',                  '#9A3412', '#FFEDD5'],
        'kontrak_nomor'   => ['Menunggu nomor kontrak',          '#9A3412', '#FFEDD5'],
        'invoice_belum'   => ['Invoice belum diterbitkan',       '#1D4ED8', '#DBEAFE'],
        'bayar_belum'     => ['Belum dibayar',                   '#B91C1C', '#FEE2E2'],
        'bayar_sebagian'  => ['Dibayar sebagian',                '#B45309', '#FEF3C7'],
        'tanpa_tagihan'   => ['Tanpa tagihan',                   '#475569', '#E2E8F0'],
        'tuntas'          => ['Tuntas',                          '#047857', '#D1FAE5'],
    ];
}

/** Siapa yang ditunggu. Kunci ini juga dipakai saringan "Giliran". */
function mon_giliran_daftar(): array
{
    return [
        'pembuat'  => 'Pembuat dokumen',
        'approver' => 'Approver',
        'pic'      => 'PIC',
        'legal'    => 'Legal',
        'admin'    => 'Admin Sales',
        'selesai'  => 'Selesai',
    ];
}

/** Keadaan permintaan kontrak: [label, warna teks, warna latar]. */
function mon_kontrak_daftar(): array
{
    return [
        'belum'   => ['Belum diajukan', '#64748B', '#F1F5F9'],
        'draft'   => ['Draft',          '#B45309', '#FEF3C7'],
        'legal'   => ['Di Legal',       '#9A3412', '#FFEDD5'],
        'nomor'   => ['Menunggu nomor', '#9A3412', '#FFEDD5'],
        'selesai' => ['Ada nomor',      '#047857', '#D1FAE5'],
    ];
}

/**
 * Sub-kueri atas SELURUH transaksi milik satu dokumen.
 *
 * Dokumen paket menerbitkan beberapa transaksi yang berbagi satu bundle_id,
 * sementara skp_documents.transaction_id hanya menunjuk SALAH SATUNYA. Membaca
 * transaksi yang ditunjuk saja membuat tagihan dokumen tiga lokasi terbaca
 * sebesar satu lokasi — persis cacat yang pernah terjadi di surat perpanjangan.
 */
function _mon_sub(string $pilih): string
{
    return "(SELECT $pilih FROM transactions tx
              WHERE tx.property_id = s.property_id AND tx.deleted_at IS NULL
                AND (tx.id = s.transaction_id
                     OR (t.bundle_id IS NOT NULL AND tx.bundle_id = t.bundle_id)))";
}

/** Daftar pilihan saringan yang sah, sekali tempat. */
function _mon_saringan(): array
{
    $f = [
        'q'       => trim((string) getv('q', '')),
        'tahap'   => (string) getv('tahap', ''),
        'giliran' => (string) getv('giliran', ''),
        'pic'     => trim((string) getv('pic', '')),
        'module'  => (string) getv('module', ''),
        'doc'     => (string) getv('doc', ''),
        'kontrak' => (string) getv('kontrak', ''),
        'period'  => trim((string) getv('period', 'semua')),
        // Bawaannya "sewa berjalan di bulan itu" — itu dasar yang dipakai
        // Dashboard untuk mengakui income, jadi angka di dua layar langsung
        // sebanding tanpa orang perlu tahu ada pilihan ini.
        'mode'    => (string) getv('mode', 'jalan'),
        'ringkas' => (string) getv('ringkas', 'macet'),
        'umur'    => (int) getv('umur', 0),
        'saya'    => getv('saya', '') === '1' ? '1' : '',
        'sort'    => (string) getv('sort', 'macet'),
        'dir'     => strtolower((string) getv('dir', 'desc')) === 'asc' ? 'asc' : 'desc',
        'page'    => max(1, (int) getv('page', 1)),
    ];
    if (!isset(mon_tahap_daftar()[$f['tahap']]))     $f['tahap'] = '';
    if (!isset(mon_giliran_daftar()[$f['giliran']])) $f['giliran'] = '';
    if (!isset(mon_kontrak_daftar()[$f['kontrak']])) $f['kontrak'] = '';
    if (!in_array($f['module'], ['cl', 'media', 'gudang'], true)) $f['module'] = '';
    if (!in_array($f['doc'], ['skp', 'sks', 'fu'], true))         $f['doc'] = '';
    if (!in_array($f['mode'], ['mulai', 'jalan', 'dibuat'], true)) $f['mode'] = 'jalan';
    if (!in_array($f['ringkas'], ['macet', 'tuntas', 'lewat', 'diam', ''], true)) $f['ringkas'] = '';
    if (!in_array($f['umur'], [0, 7, 14, 30], true)) $f['umur'] = 0;
    if ($f['period'] !== 'semua' && !preg_match('/^\d{4}-\d{2}$/', $f['period'])) $f['period'] = 'semua';
    $urut = ['macet', 'dokumen', 'penyewa', 'tahap', 'pic', 'nilai', 'mulai', 'kejar'];
    if (!in_array($f['sort'], $urut, true)) $f['sort'] = 'macet';
    return $f;
}

/**
 * Ambil dokumen + semua bahan untuk menghitung tahapnya.
 *
 * Batas 3000 baris adalah pagar, bukan saringan: satu properti kini punya
 * ratusan dokumen, dan kalau suatu hari menembus batas ini halaman akan
 * mengatakannya terang-terangan daripada diam-diam memotong daftar.
 */
function _mon_ambil(PDO $pdo, int $pid, array $f): array
{
    $tglMulai = 'COALESCE(s.start_date, t.start_date, o.start_date)';
    $tglAkhir = 'COALESCE(s.end_date, t.end_date, o.end_date)';

    $where  = ['s.property_id = ?', 's.deleted_at IS NULL'];
    $params = [$pid];

    if ($f['doc'] !== '') { $where[] = 's.doc_type = ?'; $params[] = $f['doc']; }

    if ($f['module'] !== '') {
        // s.module bercollation utf8mb4_bin sementara t/o.module utf8_general_ci —
        // jangan di-COALESCE lalu dibandingkan, MySQL menolaknya dan daftarnya
        // jadi kosong tanpa pesan apa pun.
        $where[] = '(s.module = ? OR (s.module IS NULL AND (COALESCE(t.module, o.module) = ?
                     OR EXISTS (SELECT 1 FROM offer_items oi WHERE oi.offer_id = s.offer_id AND oi.segment = ?))))';
        array_push($params, $f['module'], $f['module'], $f['module']);
    }

    if ($f['period'] !== 'semua') {
        $awal  = $f['period'] . '-01';
        $akhir = date('Y-m-t', strtotime($awal));
        if ($f['mode'] === 'dibuat') {
            $where[] = 'DATE(s.created_at) BETWEEN ? AND ?';
        } elseif ($f['mode'] === 'jalan') {
            $where[] = "$tglMulai <= ? AND $tglAkhir >= ?";
        } else {
            $where[] = "$tglMulai BETWEEN ? AND ?";
        }
        if ($f['mode'] === 'jalan') { array_push($params, $akhir, $awal); }
        else                        { array_push($params, $awal, $akhir); }
    }

    if ($f['pic'] !== '') {
        $where[] = "(NULLIF(s.pic_name,'') = ? OR (NULLIF(s.pic_name,'') IS NULL
                     AND COALESCE(NULLIF(t.pic_name,''), NULLIF(o.pic_name,'')) = ?))";
        array_push($params, $f['pic'], $f['pic']);
    }

    if ($f['q'] !== '') {
        $where[] = '(s.skp_no COLLATE utf8mb4_general_ci LIKE ?
                     OR s.master_code COLLATE utf8mb4_general_ci LIKE ?
                     OR t.master_code LIKE ? OR o.master_code LIKE ?
                     OR o.offer_no LIKE ?
                     OR sc.company_name LIKE ? OR tc.company_name LIKE ? OR oc.company_name LIKE ?
                     OR sc.brand_name LIKE ? OR tc.brand_name LIKE ? OR oc.brand_name LIKE ?
                     OR s.cp_name COLLATE utf8mb4_general_ci LIKE ?
                     OR cr.req_no LIKE ? OR cr.contract_no LIKE ?
                     OR EXISTS (SELECT 1 FROM offer_items oi9 WHERE oi9.offer_id = s.offer_id AND oi9.master_code LIKE ?)
                     OR EXISTS (SELECT 1 FROM transactions tq
                                 WHERE tq.property_id = s.property_id AND tq.deleted_at IS NULL
                                   AND (tq.id = s.transaction_id
                                        OR (t.bundle_id IS NOT NULL AND tq.bundle_id = t.bundle_id))
                                   AND (tq.invoice_no LIKE ? OR tq.contract_no LIKE ?)))';
        for ($i = 0; $i < 17; $i++) $params[] = '%' . $f['q'] . '%';
    }

    // Sales hanya melihat miliknya: PIC dokumen / penawaran / transaksi, atau
    // yang ia buat sendiri. Inilah yang membuat PIC membuka menu yang sama
    // dengan admin tetapi hanya melihat pekerjaannya.
    [$scopeSql, $scopeP] = current_sales_scope_sql(
        $pdo, $pid, ['s.pic_name', 'o.pic_name', 't.pic_name'], 's.created_by'
    );
    if ($scopeSql !== '') {
        $where[] = substr($scopeSql, 5);                 // buang prefiks ' AND '
        $params  = array_merge($params, $scopeP);
    }

    $bayar = "(SELECT COALESCE(SUM(py.amount),0) FROM transaction_payments py
                JOIN transactions tx2 ON tx2.id = py.transaction_id AND tx2.deleted_at IS NULL
               WHERE py.deleted_at IS NULL AND tx2.property_id = s.property_id
                 AND (tx2.id = s.transaction_id
                      OR (t.bundle_id IS NOT NULL AND tx2.bundle_id = t.bundle_id)))";

    $sql = 'SELECT s.id, s.doc_type, s.skp_no, s.status, s.approval_level, s.revisi_ke,
                   s.created_at, s.created_by, s.submitted_at, s.approved_at, s.approved_by,
                   s.signed_at, s.sign_name, s.reject_note, s.updated_at, s.total_amount,
                   s.offer_id, s.transaction_id, s.cp_name, s.produk,
                   s.admin_ktp, s.admin_npwp, s.admin_siup, s.note,
                   COALESCE(s.master_code, t.master_code, o.master_code)            AS master_code,
                   ' . $tglMulai . '                                                AS start_date,
                   ' . $tglAkhir . '                                                AS end_date,
                   COALESCE(s.module, t.module, o.module)                           AS module,
                   COALESCE(sc.company_name, tc.company_name, oc.company_name)      AS company_name,
                   COALESCE(sc.brand_name, tc.brand_name, oc.brand_name)            AS brand_name,
                   COALESCE(NULLIF(s.pic_name,\'\'), NULLIF(t.pic_name,\'\'),
                            NULLIF(o.pic_name,\'\'))                                AS pic_name,
                   o.offer_no, o.status AS offer_status, o.deal_at,
                   (SELECT COUNT(*) FROM offer_items oi1 WHERE oi1.offer_id = s.offer_id) AS paket_n,
                   (SELECT GROUP_CONCAT(oi2.master_code ORDER BY oi2.sort_order, oi2.id SEPARATOR \' · \')
                      FROM offer_items oi2 WHERE oi2.offer_id = s.offer_id)         AS paket_kode,
                   cr.id AS cr_id, cr.req_no, cr.status AS cr_status, cr.created_at AS cr_dibuat,
                   cr.sent_at AS cr_kirim, cr.legal_approved_at AS cr_setuju, cr.legal_by,
                   cr.requester_name,
                   cr.contract_no AS cr_no, cr.contract_date AS cr_tgl, cr.contract_type,
                   cr.doc_ktp, cr.doc_npwp, cr.doc_akta, cr.doc_surat_kuasa, cr.legal_note,
                   ' . _mon_sub('COUNT(*)') . '                                      AS trx_n,
                   ' . _mon_sub('COALESCE(SUM(tx.final_amount),0)') . '              AS trx_nilai,
                   ' . _mon_sub("COALESCE(SUM(tx.invoice_no IS NOT NULL AND tx.invoice_no <> ''
                                            OR tx.invoice_date IS NOT NULL
                                            OR tx.invoice_amount IS NOT NULL),0)") . ' AS inv_n,
                   ' . _mon_sub('COALESCE(SUM(COALESCE(tx.invoice_amount, tx.final_amount)),0)') . ' AS tagihan,
                   ' . _mon_sub("GROUP_CONCAT(DISTINCT NULLIF(tx.invoice_no,'') SEPARATOR ', ')") . ' AS inv_no,
                   ' . _mon_sub('MIN(tx.invoice_date)') . '                          AS inv_tgl,
                   ' . _mon_sub('MIN(tx.invoice_due)') . '                           AS inv_tempo,
                   ' . _mon_sub("MAX(NULLIF(tx.contract_no,''))") . '                AS trx_kontrak,
                   ' . _mon_sub("GROUP_CONCAT(DISTINCT tx.master_code ORDER BY tx.master_code SEPARATOR ' · ')") . ' AS trx_kode,
                   ' . $bayar . '                                                    AS terbayar,
                   t.bundle_id                                                      AS bundle_set
            FROM skp_documents s
            LEFT JOIN transactions t   ON t.id  = s.transaction_id
            LEFT JOIN master_clients tc ON tc.id = t.client_id
            LEFT JOIN offers o         ON o.id  = s.offer_id
            LEFT JOIN master_clients oc ON oc.id = o.client_id
            LEFT JOIN master_clients sc ON sc.id = s.client_id
            LEFT JOIN contract_requests cr ON cr.id = (SELECT MAX(cr2.id) FROM contract_requests cr2
                                                        WHERE cr2.skp_id = s.id)
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY s.id DESC
            LIMIT 3001';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Income tiap dokumen menurut `transaction_allocations` — dasar yang SAMA
 * dengan Dashboard, Laporan PIC, dan Executive Summary.
 *
 * Nilai kontrak (final_amount) bukan income: ia belum dikurangi listrik &
 * deposit, dan ia tetap ada walau dokumennya ditolak atau belum diteken klien.
 * Selama dua halaman memakai dua dasar berbeda, angkanya tidak akan pernah
 * bertemu dan tidak ada yang tahu mana yang benar.
 *
 * Satu kueri untuk SELURUH daftar, bukan satu subkueri per baris: bentuk
 * per-baris membuat halaman ini dua kali lebih lambat, dan lencana di menu
 * samping ikut menanggungnya di setiap halaman.
 *
 * @param string $periode 'semua' atau YYYY-MM (porsi bulan itu saja)
 * @return array<int,float> transaction_id => jumlah
 */
function mon_income_peta(PDO $pdo, int $pid, array $rows, string $periode): array
{
    $trx = $bun = [];
    foreach ($rows as $r) {
        if (!empty($r['transaction_id'])) $trx[(int) $r['transaction_id']] = true;
        if (!empty($r['bundle_set']))     $bun[(int) $r['bundle_set']]     = true;
    }
    if (!$trx && !$bun) return ['trx' => [], 'bundle' => []];

    $syarat = []; $par = [$pid];
    if ($trx) { $syarat[] = 'tx.id IN (' . implode(',', array_fill(0, count($trx), '?')) . ')';
                $par = array_merge($par, array_keys($trx)); }
    if ($bun) { $syarat[] = 'tx.bundle_id IN (' . implode(',', array_fill(0, count($bun), '?')) . ')';
                $par = array_merge($par, array_keys($bun)); }
    $bulan = $periode !== 'semua' ? ' AND a.period_key = ?' : '';
    if ($bulan !== '') $par[] = $periode;

    // Saringan bulan ditaruh di WHERE, bukan di ON: penanda '?' dibaca menurut
    // URUTANNYA di teks SQL, dan satu di klausa JOIN akan menggeser SELURUH
    // parameter berikutnya — hasilnya nol tanpa satu pun pesan galat.
    $st = $pdo->prepare('SELECT tx.id, tx.bundle_id, COALESCE(SUM(a.amount),0) jml
                           FROM transactions tx
                           JOIN transaction_allocations a ON a.transaction_id = tx.id
                          WHERE tx.property_id = ? AND tx.deleted_at IS NULL
                            AND (' . implode(' OR ', $syarat) . ')' . $bulan . '
                          GROUP BY tx.id');
    $st->execute($par);
    $peta = ['trx' => [], 'bundle' => []];
    foreach ($st->fetchAll() as $a) {
        $peta['trx'][(int) $a['id']] = (float) $a['jml'];
        if (!empty($a['bundle_id'])) {
            $b = (int) $a['bundle_id'];
            $peta['bundle'][$b] = ($peta['bundle'][$b] ?? 0.0) + (float) $a['jml'];
        }
    }
    return $peta;
}

/**
 * Tahap, giliran, dan langkah berikutnya untuk satu dokumen.
 *
 * Urutannya sengaja sama dengan urutan kerja di lapangan, dan yang pertama
 * cocok itulah tahapnya — sebuah dokumen tidak bisa "menunggu pembayaran"
 * selagi masih menunggu persetujuan.
 */
function _mon_tahap(array $r, array $penunggu): array
{
    $status   = (string) ($r['status'] ?? '');
    $signed   = !empty($r['signed_at']) || $status === 'signed';
    $pic      = trim((string) ($r['pic_name'] ?? ''));
    $pembuat  = trim((string) ($r['created_by'] ?? ''));
    $tagihan  = (float) ($r['tagihan'] ?? 0);
    $terbayar = (float) ($r['terbayar'] ?? 0);
    $crStatus = (string) ($r['cr_status'] ?? '');
    $crNo     = trim((string) ($r['cr_no'] ?? ''));

    $buat = function (string $kode, string $giliran, string $siapa, string $sejak,
                      string $aksi, string $url) use ($r): array {
        return [
            'kode'    => $kode,
            'giliran' => $giliran,
            'siapa'   => $siapa !== '' ? $siapa : mon_giliran_daftar()[$giliran] ?? '—',
            'sejak'   => $sejak,
            'aksi'    => $aksi,
            'url'     => $url,
        ];
    };

    $doc = '?r=skp_form&id=' . (int) $r['id'];

    if ($status === 'rejected') {
        return $buat('ditolak', 'pembuat', $pembuat, (string) ($r['updated_at'] ?: $r['created_at']),
            'Perbaiki dokumen lalu ajukan ulang', $doc);
    }
    if ($status === 'draft' && (int) ($r['revisi_ke'] ?? 0) > 0) {
        return $buat('revisi', 'pembuat', $pembuat, (string) ($r['updated_at'] ?: $r['created_at']),
            'Selesaikan revisi lalu ajukan ulang', $doc);
    }
    if ($status === 'draft') {
        return $buat('draft', 'pembuat', $pembuat, (string) $r['created_at'],
            'Ajukan dokumen untuk persetujuan', $doc);
    }
    if ($status === 'submitted') {
        return $buat('approval', 'approver', $penunggu[(int) $r['id']] ?? '',
            (string) ($r['submitted_at'] ?: $r['created_at']),
            'Setujui / paraf dokumen', $doc);
    }
    if (!$signed) {
        return $buat('ttd_klien', 'pic', $pic, (string) ($r['approved_at'] ?: $r['created_at']),
            'Minta tanda tangan klien', $doc);
    }

    // ── Sudah diteken klien. Mulai dari sini uangnya yang dikejar. ──
    $sejakTtd = (string) ($r['signed_at'] ?: $r['approved_at'] ?: $r['created_at']);

    if ((int) ($r['trx_n'] ?? 0) === 0) {
        return $buat('transaksi_belum', 'admin', '', $sejakTtd,
            'Transaksinya belum terbit — periksa dokumennya', $doc);
    }

    // Permintaan kontrak hanya menahan dokumen bila permintaannya MEMANG sudah
    // ada. CLARA tidak memutuskan sendiri kesepakatan mana yang wajib berkontrak
    // — kolom "Kontrak" menunjukkan yang belum diajukan supaya bisa dikerjakan
    // sengaja, tanpa menuduh ratusan dokumen tersangkut di PIC.
    if ($crStatus === 'draft') {
        return $buat('kontrak_draft', 'pembuat', trim((string) ($r['requester_name'] ?? '')) ?: $pembuat,
            (string) ($r['cr_dibuat'] ?: $sejakTtd),
            'Kirim permintaan kontrak ke Legal', '?r=contract_request_form&id=' . (int) $r['cr_id']);
    }
    if ($crStatus === 'sent') {
        return $buat('kontrak_legal', 'legal', 'Legal', (string) ($r['cr_kirim'] ?: $sejakTtd),
            'Menunggu Legal menyetujui & memberi nomor', '?r=contract_request_form&id=' . (int) $r['cr_id']);
    }
    if ($crStatus === 'approved' && $crNo === '') {
        return $buat('kontrak_nomor', 'legal', 'Legal', (string) ($r['cr_setuju'] ?: $sejakTtd),
            'Nomor kontrak belum diisi Legal', '?r=contract_request_form&id=' . (int) $r['cr_id']);
    }

    $sejakUang = (string) ($r['cr_setuju'] ?: $sejakTtd);

    if ((int) ($r['inv_n'] ?? 0) === 0) {
        return $buat('invoice_belum', 'admin', '', $sejakUang,
            'Terbitkan invoice & isi jumlah tagihan', '?r=payments&q=' . urlencode((string) ($r['company_name'] ?? '')));
    }
    if ($tagihan <= 0 && $terbayar <= 0) {
        return $buat('tanpa_tagihan', 'admin', '', $sejakUang,
            'Jumlah tagihan masih nol — periksa invoicenya', '?r=payments&q=' . urlencode((string) ($r['company_name'] ?? '')));
    }
    if ($terbayar >= $tagihan - 0.5) {
        return $buat('tuntas', 'selesai', '—', (string) ($r['inv_tgl'] ?: $sejakUang), '', '');
    }
    $bayarUrl = '?r=payments&q=' . urlencode((string) ($r['company_name'] ?? ''));
    if ($terbayar > 0) {
        return $buat('bayar_sebagian', 'pic', $pic, (string) ($r['inv_tgl'] ?: $sejakUang),
            'Tagih sisa pembayaran ke klien', $bayarUrl);
    }
    return $buat('bayar_belum', 'pic', $pic, (string) ($r['inv_tgl'] ?: $sejakUang),
        'Tagih pembayaran ke klien', $bayarUrl);
}

/** Keadaan permintaan kontrak satu dokumen. */
function _mon_kontrak(array $r): string
{
    $st = (string) ($r['cr_status'] ?? '');
    if ($st === '')      return 'belum';
    if ($st === 'draft') return 'draft';
    if ($st === 'sent')  return 'legal';
    return trim((string) ($r['cr_no'] ?? '')) !== '' ? 'selesai' : 'nomor';
}

/**
 * Seluruh baris monitoring yang sudah lengkap: tahap, giliran, umur macet,
 * jejak tindak lanjut, dan apakah ia menunggu ORANG YANG SEDANG MASUK.
 */
function mon_baris(PDO $pdo, int $pid, array $f): array
{
    require_once dirname(__DIR__) . '/ApprovalLine.php';
    $rows = _mon_ambil($pdo, $pid, $f);
    $lebih = count($rows) > 3000;
    if ($lebih) $rows = array_slice($rows, 0, 3000);

    // Siapa yang ditunggu parafnya, dan apakah salah satunya saya.
    $penunggu = [];
    $langkah  = [];
    foreach (['skp', 'sks', 'fu'] as $jenis) $langkah[$jenis] = ApprovalLine::steps($pdo, $pid, $jenis);
    foreach ($rows as $r) {
        if (($r['status'] ?? '') !== 'submitted') continue;
        $jenis = (string) ($r['doc_type'] ?? 'skp');
        $penunggu[(int) $r['id']] = ApprovalLine::penungguRingkas(
            $pdo, $pid, $r, $langkah[$jenis] ?? $langkah['skp']
        );
    }
    $antrean = [];
    try {
        $antrean = array_flip(array_map('intval', array_merge(
            ApprovalLine::antreanSaya($pdo, $pid), ApprovalLine::antreanRevisiSaya($pdo, $pid)
        )));
    } catch (Throwable $e) { /* antrean kosong — jangan menjatuhkan halaman */ }

    // Jejak tindak lanjut untuk semua baris sekaligus, bukan satu kueri per baris.
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $jejak = $ids ? mon_tindak_peta($pdo, $pid, $ids) : [];
    $incomePeta = mon_income_peta($pdo, $pid, $rows, (string) $f['period']);

    $akuNama = trim((string) ($_SESSION['user']['name'] ?? ''));
    $akuPic  = '';
    try {
        $q = $pdo->prepare("SELECT name FROM master_pic WHERE user_id = ? AND property_id = ? AND status='active' LIMIT 1");
        $q->execute([(int) ($_SESSION['user']['id'] ?? 0), $pid]);
        $akuPic = (string) ($q->fetchColumn() ?: '');
    } catch (Throwable $e) {}
    // Tahap "invoice belum terbit" memang ditangani Admin Sales. Sales juga
    // boleh mengisi tagihan miliknya, tapi kalau tahap itu ikut dihitung
    // sebagai "giliran saya" miliknya, lencana PIC membengkak jadi puluhan dan
    // kehilangan artinya — persis yang dikhawatirkan admin: dilihat, lalu
    // diabaikan. Jadi bagi yang lingkupnya per-sales, giliran Admin bukan
    // giliran dia.
    $akuAdmin = can('manage_payments') && current_sales_scope($pdo, $pid) === null;
    $hariIni  = new DateTimeImmutable('today');

    $out = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $th = _mon_tahap($r, $penunggu);
        $r['tahap']       = $th['kode'];
        $r['giliran']     = $th['giliran'];
        $r['giliran_nama']= $th['siapa'];
        $r['aksi']        = $th['aksi'];
        $r['aksi_url']    = $th['url'];
        $r['kontrak_st']  = _mon_kontrak($r);

        // Umur macet: berapa hari dokumen ini berdiri di tahap sekarang.
        $sejak = $th['sejak'] !== '' ? substr($th['sejak'], 0, 10) : '';
        $r['sejak'] = $sejak;
        $r['macet'] = 0;
        if ($sejak !== '' && $th['kode'] !== 'tuntas') {
            // Arahnya dari tanggal tahap MENUJU hari ini — dibalik, seluruh
            // kolom "Macet" akan berbunyi 0 hari untuk dokumen paling tua.
            try { $r['macet'] = max(0, (int) (new DateTimeImmutable($sejak))->diff($hariIni)->format('%r%a')); }
            catch (Throwable $e) { $r['macet'] = 0; }
        }

        // Nilai kesepakatan. Dokumen paket mengambil jumlah SELURUH transaksinya;
        // total_amount dokumen dipakai bila transaksinya belum terbit.
        //
        // Dokumen DITOLAK tidak dihitung nilainya. Transaksinya memang masih
        // ada di basis data, tapi seluruh CLARA lain sudah menyembunyikannya
        // (lihat transaksi_sah_sql & transaksi_tampil_kondisi) — ikut
        // menjumlahkannya di sini membuat papan PIC lebih besar daripada
        // Dashboard tanpa ada yang tahu sebabnya. Barisnya tetap tampil,
        // karena memang masih harus dibereskan; cuma uangnya yang tidak ikut.
        $nilai = (float) ($r['trx_nilai'] ?? 0);
        if ($nilai <= 0) $nilai = (float) ($r['total_amount'] ?? 0);
        $r['nilai'] = $th['kode'] === 'ditolak' ? 0.0 : $nilai;
        // Dokumen paket memakai jumlah SELURUH rombongannya; yang tunggal
        // memakai transaksinya sendiri.
        $inc = !empty($r['bundle_set'])
            ? ($incomePeta['bundle'][(int) $r['bundle_set']] ?? 0.0)
            : ($incomePeta['trx'][(int) ($r['transaction_id'] ?? 0)] ?? 0.0);
        $r['income'] = $th['kode'] === 'ditolak' ? 0.0 : $inc;

        $r['sisa']  = max(0.0, (float) $r['tagihan'] - (float) $r['terbayar']);
        $r['lewat'] = !empty($r['inv_tempo']) && $r['inv_tempo'] < date('Y-m-d')
                      && (float) $r['terbayar'] < (float) $r['tagihan'] - 0.5;

        $j = $jejak[$id] ?? ['n' => 0, 'akhir' => null, 'oleh' => '', 'tunda' => null,
                             'abai' => [], 'baris' => []];
        $r['kejar_n']     = (int) $j['n'];
        $r['kejar_akhir'] = $j['akhir'];
        $r['kejar_oleh']  = $j['oleh'];
        $r['tunda']       = $j['tunda'];
        $r['jejak']       = $j['baris'];
        // Didiamkan = sengaja disingkirkan dari daftar kejaran, entah karena
        // ditunda sampai tanggal tertentu atau dikecualikan di tahap ini.
        // Tetap bisa dibuka lewat saringan sendiri, jadi tidak ada yang hilang.
        $r['abai']    = !empty($j['abai'][$th['kode']]);
        $r['ditunda'] = !empty($j['tunda']) && $j['tunda'] >= date('Y-m-d');
        $r['diamkan'] = ($r['abai'] || $r['ditunda']) && $th['kode'] !== 'tuntas';

        // Giliran saya? Inilah yang dihitung lencana di menu samping.
        $giliranSaya = match ($th['giliran']) {
            'approver' => isset($antrean[$id]),
            'pembuat'  => $akuNama !== '' && str_starts_with((string) $r['created_by'], $akuNama),
            'pic'      => $akuPic !== '' && (string) $r['pic_name'] === $akuPic,
            'admin'    => $akuAdmin,
            default    => false,
        };
        $r['saya'] = $giliranSaya && empty($r['diamkan']);

        $out[] = $r;
    }
    return ['rows' => $out, 'lebih' => $lebih];
}

/** Ringkasan jejak tindak lanjut per dokumen. */
function mon_tindak_peta(PDO $pdo, int $pid, array $ids): array
{
    $peta = [];
    if (!$ids) return $peta;
    try {
        $tanda = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id, skp_id, tahap, jenis, note, tunda_sampai, created_by, created_at
                               FROM monitoring_tindak
                              WHERE property_id = ? AND skp_id IN ($tanda)
                              ORDER BY id DESC");
        $st->execute(array_merge([$pid], $ids));
        foreach ($st->fetchAll() as $t) {
            $k = (int) $t['skp_id'];
            if (!isset($peta[$k])) $peta[$k] = ['n' => 0, 'akhir' => null, 'oleh' => '',
                                                'tunda' => null, 'abai' => [], 'baris' => []];
            $peta[$k]['baris'][] = $t;
            if ($t['jenis'] === 'kejar') {
                $peta[$k]['n']++;
                if ($peta[$k]['akhir'] === null) {
                    $peta[$k]['akhir'] = (string) $t['created_at'];
                    $peta[$k]['oleh']  = (string) $t['created_by'];
                }
            }
            if ($t['jenis'] === 'tunda' && $peta[$k]['tunda'] === null && !empty($t['tunda_sampai'])) {
                $peta[$k]['tunda'] = (string) $t['tunda_sampai'];
            }
            // Pengecualian berlaku untuk SATU tahap, bukan untuk dokumennya.
            // Begitu dokumen bergerak ke tahap berikutnya ia muncul kembali —
            // "tidak perlu dikejar sekarang" tidak boleh berarti "jangan
            // pernah tampilkan lagi".
            if ($t['jenis'] === 'abai') $peta[$k]['abai'][(string) $t['tahap']] = true;
        }
    } catch (Throwable $e) { /* tabel belum ada → halaman tetap jalan tanpa jejak */ }
    return $peta;
}

/** Saringan yang dikerjakan setelah tahap dihitung (tahap tidak ada di SQL). */
function _mon_sisir(array $rows, array $f): array
{
    return array_values(array_filter($rows, function (array $r) use ($f) {
        if ($f['tahap'] !== '' && $r['tahap'] !== $f['tahap']) return false;
        if ($f['giliran'] !== '' && $r['giliran'] !== $f['giliran']) return false;
        if ($f['kontrak'] !== '' && $r['kontrak_st'] !== $f['kontrak']) return false;
        if ($f['saya'] === '1' && empty($r['saya'])) return false;
        if ($f['umur'] > 0 && (int) $r['macet'] < $f['umur']) return false;
        if ($f['ringkas'] === 'macet'  && ($r['tahap'] === 'tuntas' || !empty($r['diamkan']))) return false;
        if ($f['ringkas'] === 'tuntas' && $r['tahap'] !== 'tuntas') return false;
        if ($f['ringkas'] === 'lewat'  && empty($r['lewat'])) return false;
        if ($f['ringkas'] === 'diam'   && empty($r['diamkan'])) return false;
        return true;
    }));
}

/** Urutkan daftar menurut kolom yang dipilih. */
function _mon_urut(array $rows, array $f): array
{
    $urutTahap = array_flip(array_keys(mon_tahap_daftar()));
    $ambil = fn(array $r) => match ($f['sort']) {
        'dokumen' => (string) ($r['skp_no'] ?? ''),
        'penyewa' => mb_strtolower((string) ($r['company_name'] ?? '')),
        'tahap'   => $urutTahap[$r['tahap']] ?? 99,
        'pic'     => mb_strtolower((string) ($r['pic_name'] ?? '')),
        'nilai'   => (float) ($r['nilai'] ?? 0),
        'mulai'   => (string) ($r['start_date'] ?? ''),
        'kejar'   => (int) ($r['kejar_n'] ?? 0),
        default   => (int) ($r['macet'] ?? 0),
    };
    usort($rows, function ($a, $b) use ($ambil, $f) {
        $x = $ambil($a); $y = $ambil($b);
        $c = is_string($x) ? strcmp($x, (string) $y) : ($x <=> $y);
        if ($c === 0) $c = (int) $b['id'] <=> (int) $a['id'];
        return $f['dir'] === 'asc' ? $c : -$c;
    });
    return $rows;
}

/** Papan pencapaian per PIC — tuntas, menggantung, dan yang paling lama macet. */
function _mon_papan(array $rows): array
{
    $pics = [];
    foreach ($rows as $r) {
        $n = trim((string) ($r['pic_name'] ?? '')) ?: '(tanpa PIC)';
        if (!isset($pics[$n])) {
            $pics[$n] = ['nama' => $n, 'n' => 0, 'tuntas' => 0, 'macet' => 0, 'giliran' => 0,
                         'lewat' => 0, 'terlama' => 0, 'nilai' => 0.0, 'nilai_tuntas' => 0.0,
                         'nilai_gantung' => 0.0, 'income' => 0.0, 'tagihan' => 0.0, 'terbayar' => 0.0];
        }
        $p = &$pics[$n];
        $p['n']++;
        $p['nilai']    += (float) $r['nilai'];
        $p['income']   += (float) ($r['income'] ?? 0);
        $p['tagihan']  += (float) $r['tagihan'];
        $p['terbayar'] += (float) $r['terbayar'];
        if ($r['tahap'] === 'tuntas') { $p['tuntas']++; $p['nilai_tuntas'] += (float) $r['nilai']; }
        else {
            // "Belum tuntas" = yang MASIH harus dikejar. Dulu kolom ini berisi
            // nilai SELURUH berkas (tuntas ikut di dalamnya) tetapi diberi nama
            // "dipegang" — terbaca seperti tunggakan padahal bukan.
            $p['nilai_gantung'] += (float) $r['nilai'];
            $p['macet']++;
            $p['terlama'] = max($p['terlama'], (int) $r['macet']);
            if ($r['giliran'] === 'pic') $p['giliran']++;
        }
        if (!empty($r['lewat'])) $p['lewat']++;
        unset($p);
    }
    foreach ($pics as &$p) $p['persen'] = $p['n'] > 0 ? round($p['tuntas'] / $p['n'] * 100) : 0;
    unset($p);
    usort($pics, fn($a, $b) => [$b['persen'], -$b['macet']] <=> [$a['persen'], -$a['macet']]);
    return $pics;
}

/**
 * Berapa dokumen yang menunggu tindakan orang yang sedang masuk — angka di
 * lencana menu samping. Inilah pengingatnya: terlihat tiap kali CLARA dibuka,
 * tanpa perlu membuka menunya dan tanpa menyentuh Dashboard.
 *
 * Diingat 3 menit di sesi supaya tidak dihitung ulang di setiap halaman.
 */
function mon_badge(PDO $pdo, int $pid): int
{
    if (!can('view_monitoring')) return 0;
    $kunci = '_mon_badge_' . $pid;
    if (isset($_SESSION[$kunci]) && (time() - (int) ($_SESSION[$kunci]['at'] ?? 0)) < 180) {
        return (int) $_SESSION[$kunci]['n'];
    }
    $n = 0;
    try {
        // Dibangun dari nol, BUKAN dari saringan halaman yang sedang dibuka:
        // lencana harus berbunyi sama di halaman mana pun.
        $f = ['q' => '', 'tahap' => '', 'giliran' => '', 'pic' => '', 'module' => '', 'doc' => '',
              'kontrak' => '', 'period' => 'semua', 'mode' => 'jalan', 'ringkas' => 'macet',
              'umur' => 0, 'saya' => '1', 'sort' => 'macet', 'dir' => 'desc', 'page' => 1];
        $d = mon_baris($pdo, $pid, $f);
        $n = count(_mon_sisir($d['rows'], $f));
    } catch (Throwable $e) { $n = 0; }
    $_SESSION[$kunci] = ['n' => $n, 'at' => time()];
    return $n;
}

/** Hapus ingatan lencana — dipanggil setelah ada tindak lanjut baru. */
function mon_badge_lupakan(): void
{
    foreach (array_keys($_SESSION) as $k) {
        if (is_string($k) && str_starts_with($k, '_mon_badge_')) unset($_SESSION[$k]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Halaman
// ─────────────────────────────────────────────────────────────────────────────

function monitoring_page(PDO $pdo): void
{
    require_permission('view_monitoring');
    $pid = current_property_id();
    $f   = _mon_saringan();

    // Akun PIC membuka layar yang sama, tapi kotak PIC-nya langsung berisi
    // namanya sendiri — bukan "Semua", yang membuat orang mengira ia sedang
    // melihat data satu mal padahal sudah dibatasi ke miliknya.
    $scopeSaya = current_sales_scope($pdo, $pid);
    $picSaya   = $scopeSaya ? (string) $scopeSaya['pic'] : null;

    $d       = mon_baris($pdo, $pid, $f);
    $semua   = $d['rows'];
    $tersisa = _mon_sisir($semua, $f);

    // Lencana saringan dihitung dari daftar SEBELUM saringan tahap/giliran
    // dipakai — kalau tidak, memilih satu tahap membuat semua angka lain nol.
    $dasar = _mon_sisir($semua, array_merge($f, ['tahap' => '', 'giliran' => '', 'kontrak' => '', 'ringkas' => '']));
    $nTahap = $nGiliran = $nKontrak = [];
    foreach ($dasar as $r) {
        $nTahap[$r['tahap']]       = ($nTahap[$r['tahap']] ?? 0) + 1;
        $nGiliran[$r['giliran']]   = ($nGiliran[$r['giliran']] ?? 0) + 1;
        $nKontrak[$r['kontrak_st']]= ($nKontrak[$r['kontrak_st']] ?? 0) + 1;
    }

    // Papan pencapaian juga dari $dasar: "sudah berapa yang tuntas" tidak
    // boleh ikut hilang hanya karena daftar sedang disaring ke yang macet.
    // Pencocokan dengan Dashboard. Monitoring hanya memuat kesepakatan yang
    // lahir dari dokumen; income yang dicatat langsung sebagai transaksi (data
    // lama sebelum CLARA, atau input manual) tidak punya dokumen dan karena itu
    // tidak akan pernah muncul di sini. Selisihnya dipajang terang-terangan,
    // supaya tidak ada lagi yang menebak-nebak kenapa dua angka tidak sama.
    //
    // Hanya ditampilkan bila yang disaring memang cuma periode — kalau daftar
    // sedang dipersempit ke satu PIC atau satu tahap, membandingkannya dengan
    // total Dashboard justru menyesatkan.
    $banding = null;
    $saringanLain = ($f['q'] !== '' || $f['pic'] !== '' || $f['module'] !== '' || $f['doc'] !== ''
                     || $f['tahap'] !== '' || $f['giliran'] !== '' || $f['kontrak'] !== '');
    // Hanya mode "sewa berjalan" yang sebanding: Dashboard menjumlah income
    // SETIAP kontrak yang jalan di bulan itu, bukan hanya yang mulai di bulan itu.
    if ($f['period'] !== 'semua' && $f['mode'] === 'jalan' && !$saringanLain && $picSaya === null) {
        try {
            $bq = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM transaction_allocations
                                  WHERE property_id = ? AND period_key = ?');
            $bq->execute([$pid, $f['period']]);
            $banding = ['dashboard' => (float) $bq->fetchColumn(), 'dokumen' => 0.0];
        } catch (Throwable $e) { $banding = null; }
    }

    $papan = _mon_papan($dasar);
    // Kartu ringkasan memakai $dasar: ia JAWABAN ATAS SELURUH saringan keras
    // (pencarian, periode, PIC, modul, jenis) dan tidak berubah ketika orang
    // mengklik satu tahap untuk melihat isinya. Tabel di bawahnya yang
    // mengikuti pilihan itu.
    $ring  = ['n' => count($dasar), 'tuntas' => 0, 'macet' => 0, 'lewat' => 0, 'saya' => 0,
              'diam' => 0, 'terlama' => 0, 'nilai' => 0.0, 'income' => 0.0,
              'tagihan' => 0.0, 'terbayar' => 0.0];
    foreach ($dasar as $r) {
        $ring['nilai']    += (float) $r['nilai'];
        $ring['income']   += (float) ($r['income'] ?? 0);
        $ring['tagihan']  += (float) $r['tagihan'];
        $ring['terbayar'] += (float) $r['terbayar'];
        if ($r['tahap'] === 'tuntas') $ring['tuntas']++; else {
            $ring['macet']++;
            $ring['terlama'] = max($ring['terlama'], (int) $r['macet']);
        }
        if (!empty($r['lewat']))   $ring['lewat']++;
        if (!empty($r['saya']))    $ring['saya']++;
        if (!empty($r['diamkan'])) $ring['diam']++;
        if ($banding !== null) $banding['dokumen'] += (float) ($r['income'] ?? 0);
    }

    $tersisa = _mon_urut($tersisa, $f);
    $per     = 100;
    $totalHal= max(1, (int) ceil(count($tersisa) / $per));
    $hal     = min($f['page'], $totalHal);
    $baris   = array_slice($tersisa, ($hal - 1) * $per, $per);

    $pics = $pdo->prepare("SELECT name FROM master_pic WHERE status='active' AND property_id = ? ORDER BY name");
    $pics->execute([$pid]);
    $pics = $pics->fetchAll(PDO::FETCH_COLUMN);

    $qs = function (array $ubah) use ($f) {
        $p = array_merge(['r' => 'monitoring'], $f, $ubah);
        unset($p['page']);
        if (!empty($ubah['page'])) $p['page'] = $ubah['page'];
        return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null && $v !== 0));
    };

    layout('Monitoring Transaksi', function () use (
        $f, $baris, $tersisa, $papan, $ring, $nTahap, $nGiliran, $nKontrak,
        $pics, $qs, $hal, $totalHal, $per, $d, $picSaya, $banding, $saringanLain
    ) {
        $tahapD   = mon_tahap_daftar();
        $giliranD = mon_giliran_daftar();
        $kontrakD = mon_kontrak_daftar();
        $modLbl   = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
        $docLbl   = ['skp' => 'SKP', 'sks' => 'SKS', 'fu' => 'FU'];
        $lencana  = function (string $teks, string $warna, string $latar, string $judul = '') {
            echo '<span class="badge" style="color:' . $warna . ';background:' . $latar
               . ';border-color:transparent;white-space:nowrap"'
               . ($judul !== '' ? ' title="' . h($judul) . '"' : '') . '>' . h($teks) . '</span>';
        };
        $tgl = fn($v) => $v ? date('d/m/y', strtotime((string) $v)) : '—';
        $tglJam = fn($v) => $v ? date('d/m/Y H:i', strtotime((string) $v)) : '—';
        ?>
        <?php
        // Semua saringan berupa DROPDOWN berlabel yang kelihatan. Bentuk
        // sebelumnya — lima baris tombol berwarna — memaksa orang membaca
        // puluhan kata untuk tahu apa yang bisa disaring; bentuk ini cukup
        // dibaca sekali, dan tiap pilihan membawa angkanya sendiri.
        $pilih = function (string $nama, string $label, array $opsi, $nilai, array $hitung = [], string $judul = '') {
            echo '<div' . ($judul !== '' ? ' title="' . h($judul) . '"' : '') . '>'
               . '<label class="muted" style="display:block;margin-bottom:3px">' . h($label) . '</label>'
               . '<select name="' . h($nama) . '" style="width:100%">';
            foreach ($opsi as $k => $v) {
                $n = array_key_exists((string) $k, $hitung) ? ' (' . (int) $hitung[(string) $k] . ')' : '';
                echo '<option value="' . h((string) $k) . '"' . ((string) $nilai === (string) $k ? ' selected' : '')
                   . '>' . h($v) . $n . '</option>';
            }
            echo '</select></div>';
        };
        $blnIni  = date('Y-m');
        $blnLalu = date('Y-m', strtotime('first day of last month'));
        $hitungRingkas = ['macet' => $ring['macet'], 'tuntas' => $ring['tuntas'], 'lewat' => $ring['lewat'],
                          'diam' => $ring['diam'], '' => $ring['n']];
        ?>
        <form method="get" class="panel" style="padding:14px 16px;margin-bottom:14px">
            <input type="hidden" name="r" value="monitoring">
            <input type="hidden" name="sort" value="<?= h($f['sort']) ?>">
            <input type="hidden" name="dir" value="<?= h($f['dir']) ?>">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(172px,1fr));gap:10px 12px;align-items:end">
                <div style="grid-column:span 2;min-width:220px">
                    <label class="muted" style="display:block;margin-bottom:3px">Cari</label>
                    <input type="text" name="q" value="<?= h($f['q']) ?>" style="width:100%"
                           placeholder="Penyewa, brand, no. dokumen, unit, no. kontrak, no. invoice">
                </div>

                <?php if ($picSaya !== null): ?>
                    <div>
                        <label class="muted" style="display:block;margin-bottom:3px">PIC</label>
                        <div style="padding:9px 12px;border:1px solid rgba(100,116,139,.25);border-radius:8px;
                                    font-weight:700;background:var(--soft,#F8FAFC);white-space:nowrap;overflow:hidden;
                                    text-overflow:ellipsis" title="Akun Anda hanya melihat dokumen sendiri.">
                            <?= h($picSaya !== '' ? $picSaya : 'Dokumen yang Anda buat') ?>
                        </div>
                        <input type="hidden" name="pic" value="<?= h($f['pic']) ?>">
                    </div>
                <?php else: $pilih('pic', 'PIC', ['' => 'Semua PIC'] + array_combine($pics, $pics), $f['pic']); endif; ?>

                <div>
                    <label class="muted" style="display:block;margin-bottom:3px">Bulan</label>
                    <input type="month" name="period" style="width:100%"
                           value="<?= h($f['period'] === 'semua' ? '' : $f['period']) ?>">
                </div>
                <?php
                $pilih('mode', 'Bulan dihitung dari', [
                    'jalan'  => 'Sewa berjalan di bulan itu',
                    'mulai'  => 'Sewa mulai di bulan itu',
                    'dibuat' => 'Dokumen dibuat di bulan itu',
                ], $f['mode'], [], 'Dasar yang dipakai Dashboard adalah "sewa berjalan di bulan itu".');

                $pilih('ringkas', 'Tampilkan', [
                    'macet'  => 'Belum tuntas', 'tuntas' => 'Tuntas', 'lewat' => 'Lewat jatuh tempo',
                    'diam'   => 'Ditunda / dikecualikan', '' => 'Semua',
                ], $f['ringkas'], $hitungRingkas);

                $pilih('tahap', 'Tahap sekarang', ['' => 'Semua tahap'] + array_map(fn($x) => $x[0], $tahapD),
                       $f['tahap'], $nTahap);
                $pilih('giliran', 'Giliran siapa', ['' => 'Semua'] + $giliranD, $f['giliran'], $nGiliran);
                $pilih('kontrak', 'Kontrak', ['' => 'Semua'] + array_map(fn($x) => $x[0], $kontrakD),
                       $f['kontrak'], $nKontrak);
                $pilih('doc', 'Jenis dokumen',
                       ['' => 'Semua', 'skp' => 'SKP Exhibition', 'sks' => 'SKS Gudang', 'fu' => 'Form Utilities'], $f['doc']);
                $pilih('module', 'Modul', ['' => 'Semua'] + $modLbl, $f['module']);
                $pilih('umur', 'Macet minimal', [0 => 'Berapa pun', 7 => '7 hari', 14 => '14 hari', 30 => '30 hari'], $f['umur']);
                ?>
            </div>

            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px">
                <button type="submit">Terapkan</button>
                <a class="btn light" href="?r=monitoring">Reset</a>
                <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;margin-left:4px">
                    <input type="checkbox" name="saya" value="1" <?= $f['saya'] === '1' ? 'checked' : '' ?>
                           onchange="this.form.submit()">
                    Hanya giliran saya (<?= (int) $ring['saya'] ?>)
                </label>
                <span style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap">
                    <a class="btn <?= $f['period'] === $blnIni ? '' : 'light' ?>"
                       href="<?= h($qs(['period' => $blnIni, 'page' => 1])) ?>"><?= h(pembayaran_nama_bulan($blnIni)) ?></a>
                    <a class="btn <?= $f['period'] === $blnLalu ? '' : 'light' ?>"
                       href="<?= h($qs(['period' => $blnLalu, 'page' => 1])) ?>"><?= h(pembayaran_nama_bulan($blnLalu)) ?></a>
                    <a class="btn <?= $f['period'] === 'semua' ? '' : 'light' ?>"
                       href="<?= h($qs(['period' => 'semua', 'page' => 1])) ?>">Semua bulan</a>
                </span>
            </div>
        </form>

        <?php if (!empty($d['lebih'])): ?>
            <div class="panel" style="padding:10px 14px;margin-bottom:14px;border-left:3px solid #B45309;color:#92400E">
                Daftar dipotong di 3.000 dokumen. Pakai saringan bulan atau PIC supaya angkanya utuh.
            </div>
        <?php endif; ?>

        <?php
        // Kartu income sengaja dipajang bersama kartu jumlah berkas: pertanyaan
        // "bulan ini saya sudah memasukkan apa saja, dan berapa incomenya"
        // dijawab di satu layar, bukan dengan membuka Dashboard lalu menebak
        // bagian mana yang miliknya.
        $satuBulan = $f['period'] !== 'semua';
        $incLabel  = $satuBulan ? 'Income ' . pembayaran_nama_bulan($f['period']) : 'Income diakui';
        $incArti   = $satuBulan
            ? 'Porsi income bulan ' . pembayaran_nama_bulan($f['period'], true) . ' dari dokumen yang sedang tampil. '
              . 'Dasarnya sama dengan Dashboard: transaction_allocations.'
              . ($f['mode'] === 'jalan' ? '' : "\n" . 'Catatan: bulan dihitung dari "'
                 . ['mulai' => 'sewa mulai di bulan itu', 'dibuat' => 'dokumen dibuat di bulan itu'][$f['mode']]
                 . '", jadi belum sebanding dengan Dashboard.')
            : 'Seluruh income yang diakui dari dokumen yang sedang tampil, dijumlah untuk semua bulan kontraknya. '
              . 'Pilih satu bulan kalau ingin dibandingkan dengan Dashboard.';
        $kartu = [
            ['Dokumen', (int) $ring['n'] . ' berkas', 'var(--primary)',
             'Jumlah dokumen pada saringan sekarang. Satu dokumen = satu kesepakatan, walau isinya beberapa lokasi.'],
            [$incLabel, money($ring['income']), '#7C3AED', $incArti],
            ['Tuntas', (int) $ring['tuntas'] . ' berkas', '#047857',
             'Sudah diteken, sudah di-invoice, dan pembayarannya lunas.'],
            ['Masih menggantung', (int) $ring['macet'] . ' berkas', $ring['macet'] > 0 ? '#B45309' : '#047857',
             'Belum tuntas. Kolom "Macet" menunjukkan sudah berapa hari berdiri di tahap yang sekarang.'],
            ['Giliran saya', (int) $ring['saya'] . ' berkas', $ring['saya'] > 0 ? '#B91C1C' : 'var(--muted)',
             'Dokumen yang menunggu tindakan Anda sendiri — angka yang sama dengan lencana di menu samping.'],
            ['Terlama macet', (int) $ring['terlama'] . ' hari', $ring['terlama'] >= 14 ? '#B91C1C' : 'var(--muted)',
             'Umur dokumen yang paling lama berdiri di satu tahap.'],
            ['Lewat jatuh tempo', (int) $ring['lewat'] . ' berkas', $ring['lewat'] > 0 ? '#B91C1C' : 'var(--muted)',
             'Invoice yang tanggal jatuh temponya sudah lewat dan belum lunas.'],
        ];
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(168px,1fr));gap:12px;margin-bottom:14px">
            <?php foreach ($kartu as [$lbl, $val, $clr, $arti]): ?>
                <div class="card" style="padding:12px 14px;border-top:3px solid <?= $clr ?>" title="<?= h($arti) ?>">
                    <div class="muted" style="font-size:12px"><?= h($lbl) ?></div>
                    <div style="font-size:18px;font-weight:800;color:<?= $clr ?>"><?= h($val) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($banding === null && $f['period'] !== 'semua' && $f['mode'] !== 'jalan'
                  && $picSaya === null && !$saringanLain): ?>
            <div class="panel" style="padding:10px 15px;margin-bottom:14px;font-size:12.5px;
                                      border-left:3px solid var(--primary)">
                Mau mencocokkan dengan income Dashboard <?= h(pembayaran_nama_bulan($f['period'], true)) ?>?
                <a href="<?= h($qs(['mode' => 'jalan', 'page' => 1])) ?>"><b>Hitung dari kontrak yang berjalan di bulan itu</b></a>
                — itu dasar yang dipakai Dashboard. Dengan "sewa mulai di bulan itu", kontrak yang mulai bulan lalu
                tapi masih berjalan tidak ikut terhitung, jadi angkanya memang tidak akan sama.
            </div>
        <?php endif; ?>

        <?php if ($banding !== null):
            $selisih = (float) $banding['dashboard'] - (float) $banding['dokumen']; ?>
            <div class="panel" style="padding:11px 15px;margin-bottom:14px;font-size:12.5px;line-height:1.8;
                                      border-left:3px solid var(--primary)">
                <b>Cocok dengan Dashboard <?= h(pembayaran_nama_bulan($f['period'], true)) ?>?</b>
                Income di Dashboard <b><?= h(money($banding['dashboard'])) ?></b> ·
                yang berasal dari dokumen di daftar ini <b><?= h(money($banding['dokumen'])) ?></b>
                <?php if (abs($selisih) > 0.5): ?>
                    · selisih <b style="color:#B45309"><?= h(money($selisih)) ?></b> berasal dari transaksi yang
                    <u>tidak punya dokumen SKP/SKS/FU</u> — data lama sebelum CLARA atau transaksi yang diinput langsung,
                    sehingga memang tidak bisa muncul di Monitoring.
                <?php else: ?>
                    · <span style="color:#047857;font-weight:700">cocok seluruhnya.</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($papan): ?>
        <details class="panel" style="padding:0;margin-bottom:16px" <?= count($papan) <= 12 ? 'open' : '' ?>>
            <summary style="cursor:pointer;padding:12px 16px;font-weight:700">
                Pencapaian per PIC &middot; <span class="muted" style="font-weight:400"><?= count($papan) ?> PIC pada saringan ini</span>
            </summary>
            <div class="table-wrap" style="margin:0"><table>
                <thead><tr>
                    <th>PIC</th><th style="text-align:right">Berkas</th><th style="text-align:right">Tuntas</th>
                    <th style="width:150px">Pencapaian</th><th style="text-align:right">Menggantung</th>
                    <th style="text-align:right">Giliran dia</th><th style="text-align:right">Terlama</th>
                    <th style="text-align:right">Lewat tempo</th>
                    <th style="text-align:right" title="Nilai kontrak berkas yang pembayarannya sudah lunas.">Nilai tuntas</th>
                    <th style="text-align:right" title="Nilai kontrak berkas yang BELUM tuntas — inilah yang masih harus dikejar.">Belum tuntas</th>
                    <th style="text-align:right" title="Income menurut transaction_allocations — dasar yang sama dengan Dashboard, Laporan PIC, dan Executive Summary.">Income diakui</th>
                </tr></thead>
                <tbody>
                <?php foreach ($papan as $p): ?>
                    <tr>
                        <td style="font-weight:600"><?= h($p['nama']) ?></td>
                        <td style="text-align:right"><?= (int) $p['n'] ?></td>
                        <td style="text-align:right;color:#047857;font-weight:700"><?= (int) $p['tuntas'] ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px">
                                <div style="flex:1;height:7px;border-radius:4px;background:#E2E8F0;overflow:hidden">
                                    <div style="width:<?= (int) $p['persen'] ?>%;height:100%;background:<?= $p['persen'] >= 80 ? '#047857' : ($p['persen'] >= 50 ? '#B45309' : '#B91C1C') ?>"></div>
                                </div>
                                <span style="font-size:12px;font-weight:700;min-width:34px;text-align:right"><?= (int) $p['persen'] ?>%</span>
                            </div>
                        </td>
                        <td style="text-align:right;<?= $p['macet'] > 0 ? 'color:#B45309;font-weight:700' : '' ?>"><?= (int) $p['macet'] ?></td>
                        <td style="text-align:right;<?= $p['giliran'] > 0 ? 'color:#B91C1C;font-weight:700' : 'color:var(--muted)' ?>"><?= (int) $p['giliran'] ?></td>
                        <td style="text-align:right;<?= $p['terlama'] >= 14 ? 'color:#B91C1C;font-weight:700' : '' ?>"><?= (int) $p['terlama'] ?> hr</td>
                        <td style="text-align:right;<?= $p['lewat'] > 0 ? 'color:#B91C1C' : 'color:var(--muted)' ?>"><?= (int) $p['lewat'] ?></td>
                        <td style="text-align:right"><?= h(money($p['nilai_tuntas'])) ?></td>
                        <td style="text-align:right;<?= $p['nilai_gantung'] > 0 ? 'font-weight:700' : '' ?>"><?= h(money($p['nilai_gantung'])) ?></td>
                        <td style="text-align:right;color:var(--muted)"><?= h(money($p['income'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="muted" style="padding:8px 16px 12px;font-size:12px;line-height:1.75">
                <b>Giliran dia</b> = dokumen yang berhentinya memang di PIC (minta tanda tangan klien, atau menagih pembayaran).
                Kolom itulah yang perlu dikejar; sisanya sedang di Approver, Legal, atau Admin.<br>
                <b>Nilai tuntas &amp; Belum tuntas</b> memakai nilai kontrak. <b>Income diakui</b> memakai dasar yang sama
                dengan Dashboard dan Laporan PIC<?= $f['period'] !== 'semua'
                    ? ' — pada periode ' . h(pembayaran_nama_bulan($f['period'])) . ', angkanya sama persis dengan income bulan itu di Dashboard.'
                    : ', dijumlah untuk seluruh bulan kontraknya. Pilih satu periode kalau ingin dibandingkan dengan Dashboard.' ?>
                Dua kolom itu memang berbeda: nilai kontrak belum dikurangi listrik &amp; deposit, dan dokumen yang
                <b>ditolak</b> atau <b>belum diteken klien</b> belum menjadi income di mana pun.
            </div>
        </details>
        <?php endif; ?>

        <?php
        $th = function (string $label, string $key, string $rata = 'left') use ($f, $qs) {
            $aktif = $f['sort'] === $key;
            $balik = ($aktif && $f['dir'] === 'asc') ? 'desc' : 'asc';
            $panah = $aktif ? ($f['dir'] === 'asc' ? ' ▲' : ' ▼') : '';
            echo '<th style="text-align:' . $rata . ';white-space:nowrap"><a href="'
               . h($qs(['sort' => $key, 'dir' => $balik, 'page' => 1]))
               . '" style="color:inherit;text-decoration:none;border-bottom:1px dotted #CBD5E1'
               . ($aktif ? ';font-weight:800;border-bottom-color:#0F766E' : '') . '">' . h($label) . $panah . '</a></th>';
        };
        ?>
        <div class="table-wrap"><table style="font-size:13px">
            <thead><tr>
                <th style="width:26px"></th>
                <?php $th('Dokumen', 'dokumen'); $th('Penyewa', 'penyewa'); ?>
                <th>Unit / lokasi</th>
                <?php $th('Periode sewa', 'mulai'); $th('Nilai', 'nilai', 'right'); $th('PIC', 'pic'); ?>
                <?php $th('Tahap sekarang', 'tahap'); ?>
                <th>Giliran</th>
                <?php $th('Macet', 'macet', 'right'); ?>
                <th>Kontrak</th><th>Invoice</th><th>Bayar</th>
                <?php $th('Dikejar', 'kejar', 'right'); ?>
            </tr></thead>
            <tbody>
            <?php if (!$baris): ?>
                <tr><td colspan="14" style="text-align:center;padding:28px;color:var(--muted)">
                    Tidak ada dokumen pada saringan ini.</td></tr>
            <?php endif; ?>
            <?php foreach ($baris as $r):
                [$tLbl, $tClr, $tBg] = $tahapD[$r['tahap']] ?? ['—', '#475569', '#E2E8F0'];
                [$kLbl, $kClr, $kBg] = $kontrakD[$r['kontrak_st']] ?? ['—', '#475569', '#E2E8F0'];
                $kode = trim((string) ($r['master_code'] ?? ''));
                if ($kode === '') $kode = trim((string) ($r['trx_kode'] ?? ''));
                $paketN = (int) ($r['paket_n'] ?? 0);
                $trxN   = (int) ($r['trx_n'] ?? 0);
                $nLok   = max($paketN, $trxN);
                $tunda  = !empty($r['tunda']) && $r['tunda'] >= date('Y-m-d');
            ?>
                <tr<?= !empty($r['saya']) ? ' style="background:rgba(185,28,28,.035)"' : '' ?>>
                    <td style="text-align:center">
                        <button type="button" class="mon-buka btn light" style="padding:2px 7px;font-size:12px;line-height:1.3"
                                title="Lihat rincian perjalanan dokumen ini">+</button>
                    </td>
                    <td style="white-space:nowrap">
                        <a href="?r=skp_form&id=<?= (int) $r['id'] ?>" style="font-weight:700"><?= h($r['skp_no'] ?: '(belum bernomor)') ?></a>
                        <div class="muted" style="font-size:11px">
                            <?= h($docLbl[$r['doc_type']] ?? strtoupper((string) $r['doc_type'])) ?>
                            <?php if (!empty($r['module'])): ?> · <?= h($modLbl[$r['module']] ?? $r['module']) ?><?php endif; ?>
                            · <?= h($tgl($r['created_at'])) ?>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight:600"><?= h($r['company_name'] ?: '—') ?></div>
                        <?php if (!empty($r['brand_name'])): ?>
                            <div class="muted" style="font-size:11px"><?= h($r['brand_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="max-width:170px">
                        <?= h($kode ?: '—') ?>
                        <?php if ($nLok >= 2): ?>
                            <div class="muted" style="font-size:11px"><?= $nLok ?> lokasi (paket)</div>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap"><?= h($tgl($r['start_date'])) ?> – <?= h($tgl($r['end_date'])) ?></td>
                    <td style="text-align:right;white-space:nowrap">
                        <?= h(money($r['nilai'])) ?>
                        <?php if ($f['period'] !== 'semua'): ?>
                            <div class="muted" style="font-size:11px"
                                 title="Porsi income bulan ini dari dokumen ini — dasar yang sama dengan Dashboard.">
                                income <?= h(pembayaran_nama_bulan($f['period'])) ?>: <?= h(money($r['income'] ?? 0)) ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><?= h($r['pic_name'] ?: '—') ?></td>
                    <td><?php $lencana($tLbl, $tClr, $tBg); ?>
                        <?php if (!empty($r['aksi'])): ?>
                            <div class="muted" style="font-size:11px;margin-top:2px"><?= h($r['aksi']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <?php if ($r['giliran'] === 'selesai'): ?>
                            <span class="muted">—</span>
                        <?php else: ?>
                            <div style="font-weight:600;font-size:12px"><?= h($giliranD[$r['giliran']] ?? '—') ?></div>
                            <div class="muted" style="font-size:11px;max-width:150px;overflow:hidden;text-overflow:ellipsis">
                                <?= h($r['giliran_nama'] ?: '—') ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;white-space:nowrap">
                        <?php if ($r['tahap'] === 'tuntas'): ?><span class="muted">—</span>
                        <?php else: ?>
                            <span style="font-weight:700;color:<?= (int) $r['macet'] >= 14 ? '#B91C1C' : ((int) $r['macet'] >= 7 ? '#B45309' : 'inherit') ?>">
                                <?= (int) $r['macet'] ?> hr</span>
                            <?php if ($tunda): ?>
                                <div class="muted" style="font-size:11px">ditunda s/d <?= h($tgl($r['tunda'])) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($r['abai'])): ?>
                                <div class="muted" style="font-size:11px">dikecualikan</div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><?php $lencana($kLbl, $kClr, $kBg, $r['cr_no'] ? 'No. ' . $r['cr_no'] : ''); ?>
                        <?php if (!empty($r['cr_no'])): ?>
                            <div class="muted" style="font-size:11px"><?= h($r['cr_no']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <?php if ((int) $r['inv_n'] > 0): ?>
                            <?= h($r['inv_no'] ?: '✓ ada') ?>
                            <div class="muted" style="font-size:11px">
                                <?= h($tgl($r['inv_tgl'])) ?><?php if (!empty($r['inv_tempo'])): ?> · tempo <?= h($tgl($r['inv_tempo'])) ?><?php endif; ?>
                            </div>
                        <?php else: ?><span class="muted">belum</span><?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <?php if ((float) $r['tagihan'] > 0): ?>
                            <?= h(money($r['terbayar'])) ?>
                            <div class="muted" style="font-size:11px">
                                dari <?= h(money($r['tagihan'])) ?><?php if ((float) $r['sisa'] > 0.5): ?> · sisa <?= h(money($r['sisa'])) ?><?php endif; ?>
                            </div>
                            <?php if (!empty($r['lewat'])): ?>
                                <div style="font-size:11px;color:#B91C1C;font-weight:700">lewat tempo</div>
                            <?php endif; ?>
                        <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </td>
                    <td style="text-align:right">
                        <?php if ((int) $r['kejar_n'] > 0): ?>
                            <span style="font-weight:700"><?= (int) $r['kejar_n'] ?>×</span>
                            <div class="muted" style="font-size:11px"><?= h($tgl($r['kejar_akhir'])) ?></div>
                        <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </td>
                </tr>
                <tr class="mon-rinci" style="display:none;background:var(--soft,#F8FAFC)">
                    <td colspan="14" style="padding:14px 18px">
                        <?php monitoring_rinci($r, $tahapD, $giliranD, $kontrakD, $tglJam, $tgl); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>

        <?php if ($totalHal > 1): ?>
            <div style="display:flex;gap:6px;align-items:center;margin-top:14px;flex-wrap:wrap">
                <span class="muted">Halaman <?= $hal ?> dari <?= $totalHal ?> &middot; <?= count($tersisa) ?> dokumen</span>
                <?php if ($hal > 1): ?><a class="btn light" href="<?= h($qs(['page' => $hal - 1])) ?>">‹ Sebelumnya</a><?php endif; ?>
                <?php if ($hal < $totalHal): ?><a class="btn light" href="<?= h($qs(['page' => $hal + 1])) ?>">Berikutnya ›</a><?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="muted" style="margin-top:18px;font-size:12px;line-height:1.7">
            <b>Cara membaca daftar ini.</b> Tahap selalu dihitung ulang dari keadaan dokumen, permintaan kontrak,
            invoice, dan pembayaran — tidak ada status yang disimpan, jadi tidak ada yang bisa basi.
            <b>Macet</b> = sudah berapa hari dokumen berdiri di tahap yang sekarang, bukan umur dokumennya.
            <b>Kontrak</b> hanya menahan tahap bila permintaannya sudah diajukan; yang "Belum diajukan"
            tidak dianggap menyumbat, karena CLARA tidak memutuskan sendiri kesepakatan mana yang wajib berkontrak.
            Tombol <b>+</b> di tiap baris membuka rincian lengkap beserta jejak siapa sudah mengejar apa.
        </div>

        <script>
        document.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('.mon-buka') : null;
            if (!b) return;
            var baris = b.closest('tr'), rinci = baris && baris.nextElementSibling;
            if (!rinci || !rinci.classList.contains('mon-rinci')) return;
            var buka = rinci.style.display === 'none';
            rinci.style.display = buka ? 'table-row' : 'none';
            b.textContent = buka ? '−' : '+';
        });
        </script>
        <?php
    });
}

/** Rincian satu dokumen: perjalanan, kelengkapan berkas, dan jejak pengejaran. */
function monitoring_rinci(array $r, array $tahapD, array $giliranD, array $kontrakD, callable $tglJam, callable $tgl): void
{
    $ya  = '<span style="color:#047857;font-weight:700">✓</span>';
    $tdk = '<span style="color:#B91C1C;font-weight:700">✗</span>';
    $signed = !empty($r['signed_at']) || ($r['status'] ?? '') === 'signed';
    $crSt   = (string) ($r['cr_status'] ?? '');

    // Perjalanan: tiap langkah [nama, sudah?, keterangan]
    $jalan = [
        ['Dokumen dibuat', true, $tglJam($r['created_at']) . ' · ' . ($r['created_by'] ?: '—')],
        ['Diajukan untuk persetujuan', !empty($r['submitted_at']), $tglJam($r['submitted_at'])],
    ];
    if (($r['status'] ?? '') === 'rejected') {
        $jalan[] = ['Ditolak', false, trim((string) ($r['reject_note'] ?? '')) ?: 'tanpa catatan'];
    } else {
        $jalan[] = ['Disetujui', !empty($r['approved_at']),
                    $tglJam($r['approved_at']) . (!empty($r['approved_by']) ? ' · ' . $r['approved_by'] : '')];
    }
    $jalan[] = ['Diteken klien', $signed,
                $tglJam($r['signed_at']) . (!empty($r['sign_name']) ? ' · ' . $r['sign_name'] : '')];
    $jalan[] = ['Transaksi terbit', (int) $r['trx_n'] > 0,
                (int) $r['trx_n'] > 0 ? (int) $r['trx_n'] . ' transaksi · ' . money($r['trx_nilai']) : 'belum'];
    $jalan[] = ['Permintaan kontrak', $crSt !== '',
                $crSt === '' ? 'belum diajukan'
                : (($r['req_no'] ?: 'draft') . ' · dibuat ' . $tgl($r['cr_dibuat'])
                   . (!empty($r['cr_kirim']) ? ' · dikirim ' . $tgl($r['cr_kirim']) : ''))];
    $jalan[] = ['Disetujui Legal', $crSt === 'approved',
                $crSt === 'approved'
                    ? ($tglJam($r['cr_setuju']) . (!empty($r['legal_by']) ? ' · ' . $r['legal_by'] : ''))
                    : ($crSt === 'sent' ? 'masih di Legal' : '—')];
    $jalan[] = ['Nomor kontrak', trim((string) ($r['cr_no'] ?? $r['trx_kontrak'] ?? '')) !== '',
                trim((string) ($r['cr_no'] ?: $r['trx_kontrak'] ?: '')) !== ''
                    ? (($r['cr_no'] ?: $r['trx_kontrak']) . ' · ' . $tgl($r['cr_tgl'])) : '—'];
    $jalan[] = ['Invoice diterbitkan', (int) $r['inv_n'] > 0,
                (int) $r['inv_n'] > 0
                    ? (($r['inv_no'] ?: '(tanpa nomor)') . ' · ' . $tgl($r['inv_tgl'])
                       . (!empty($r['inv_tempo']) ? ' · jatuh tempo ' . $tgl($r['inv_tempo']) : ''))
                    : 'belum'];
    $jalan[] = ['Pembayaran lunas', (float) $r['terbayar'] >= (float) $r['tagihan'] - 0.5 && (float) $r['tagihan'] > 0,
                money($r['terbayar']) . ' dari ' . money($r['tagihan'])
                . ((float) $r['sisa'] > 0.5 ? ' · sisa ' . money($r['sisa']) : '')];

    $berkas = [
        'KTP penanggung jawab' => (int) ($r['admin_ktp'] ?? 0) === 1,
        'NPWP'                 => (int) ($r['admin_npwp'] ?? 0) === 1,
        'SIUP'                 => (int) ($r['admin_siup'] ?? 0) === 1,
    ];
    if ($crSt !== '') {
        $berkas['KTP (lampiran kontrak)']   = (int) ($r['doc_ktp'] ?? 0) === 1;
        $berkas['NPWP (lampiran kontrak)']  = (int) ($r['doc_npwp'] ?? 0) === 1;
        $berkas['Akta']                     = (int) ($r['doc_akta'] ?? 0) === 1;
        $berkas['Surat kuasa']              = (int) ($r['doc_surat_kuasa'] ?? 0) === 1;
    }
    $jenisLbl = ['kejar' => 'Dikejar', 'catat' => 'Catatan', 'tunda' => 'Ditunda', 'abai' => 'Dikecualikan'];
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px">
        <div>
            <div style="font-weight:700;margin-bottom:8px;font-size:12.5px">Perjalanan dokumen</div>
            <table style="font-size:12px;width:100%">
                <?php foreach ($jalan as [$nama, $ok, $ket]): ?>
                    <tr>
                        <td style="width:18px;padding:2px 6px 2px 0;vertical-align:top"><?= $ok ? $ya : $tdk ?></td>
                        <td style="padding:2px 8px 2px 0;white-space:nowrap;<?= $ok ? '' : 'color:var(--muted)' ?>"><?= h($nama) ?></td>
                        <td style="padding:2px 0;color:var(--muted)"><?= h($ket) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div>
            <div style="font-weight:700;margin-bottom:8px;font-size:12.5px">Kelengkapan berkas</div>
            <table style="font-size:12px;width:100%">
                <?php foreach ($berkas as $nama => $ok): ?>
                    <tr><td style="width:18px;padding:2px 6px 2px 0"><?= $ok ? $ya : $tdk ?></td>
                        <td style="padding:2px 0;<?= $ok ? '' : 'color:var(--muted)' ?>"><?= h($nama) ?></td></tr>
                <?php endforeach; ?>
            </table>
            <?php if (!empty($r['cp_name']) || !empty($r['produk']) || !empty($r['offer_no'])): ?>
                <div class="muted" style="font-size:12px;margin-top:10px;line-height:1.7">
                    <?php if (!empty($r['offer_no'])): ?>Penawaran: <b><?= h($r['offer_no']) ?></b><br><?php endif; ?>
                    <?php if (!empty($r['cp_name'])): ?>CP: <?= h($r['cp_name']) ?><br><?php endif; ?>
                    <?php if (!empty($r['produk'])): ?>Produk: <?= h($r['produk']) ?><?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($r['paket_kode'])): ?>
                <div class="muted" style="font-size:12px;margin-top:8px">Lokasi: <?= h($r['paket_kode']) ?></div>
            <?php endif; ?>
        </div>

        <div>
            <div style="font-weight:700;margin-bottom:8px;font-size:12.5px">Tindak lanjut</div>
            <?php if (!empty($r['aksi'])): ?>
                <div style="font-size:12px;margin-bottom:8px">
                    Langkah berikutnya: <b><?= h($r['aksi']) ?></b><br>
                    <span class="muted">Giliran <?= h($giliranD[$r['giliran']] ?? '—') ?><?= $r['giliran_nama'] ? ' — ' . h($r['giliran_nama']) : '' ?>,
                    sudah <?= (int) $r['macet'] ?> hari.</span>
                </div>
            <?php else: ?>
                <div style="font-size:12px;margin-bottom:8px;color:#047857;font-weight:700">Sudah tuntas — tidak ada yang perlu dikejar.</div>
            <?php endif; ?>

            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px">
                <a class="btn light" style="font-size:12px;padding:4px 9px" href="?r=skp_form&id=<?= (int) $r['id'] ?>">Buka dokumen</a>
                <?php if (!empty($r['aksi_url']) && $r['aksi_url'] !== '?r=skp_form&id=' . (int) $r['id']): ?>
                    <a class="btn" style="font-size:12px;padding:4px 9px" href="<?= h($r['aksi_url']) ?>">Kerjakan langkahnya</a>
                <?php endif; ?>
                <?php if ($crSt === '' && (!empty($r['signed_at']) || ($r['status'] ?? '') === 'signed') && can('manage_skp')): ?>
                    <a class="btn light" style="font-size:12px;padding:4px 9px"
                       href="?r=contract_request_form&skp_id=<?= (int) $r['id'] ?>">Ajukan permintaan kontrak</a>
                <?php endif; ?>
            </div>

            <?php if ($r['tahap'] !== 'tuntas'): ?>
            <form method="post" action="?r=monitoring_tindak" style="display:grid;gap:6px">
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="skp_id" value="<?= (int) $r['id'] ?>">
                <input type="hidden" name="tahap" value="<?= h($r['tahap']) ?>">
                <input type="hidden" name="balik" value="<?= h('?' . (string) ($_SERVER['QUERY_STRING'] ?? 'r=monitoring')) ?>">
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <select name="jenis" style="font-size:12px">
                        <option value="kejar">Sudah saya kejar</option>
                        <option value="catat">Catatan saja</option>
                        <option value="tunda">Tunda sampai…</option>
                        <?php if (can('manage_payments') || can('manage_skp')): ?>
                            <option value="abai">Kecualikan dari daftar</option>
                        <?php endif; ?>
                    </select>
                    <input type="date" name="tunda_sampai" style="font-size:12px" title="Hanya dipakai bila memilih Tunda">
                </div>
                <input type="text" name="note" maxlength="500" style="font-size:12px"
                       placeholder="Siapa yang dihubungi, lewat apa, jawabannya apa">
                <button type="submit" style="font-size:12px;padding:5px 10px;justify-self:start">Catat</button>
            </form>
            <?php endif; ?>

            <?php if (!empty($r['jejak'])): ?>
                <div style="margin-top:10px;font-size:12px">
                    <?php foreach (array_slice($r['jejak'], 0, 8) as $t): ?>
                        <div style="padding:4px 0;border-top:1px solid rgba(100,116,139,.18)">
                            <b><?= h($jenisLbl[$t['jenis']] ?? $t['jenis']) ?></b>
                            <span class="muted">· <?= h($tglJam($t['created_at'])) ?> · <?= h($t['created_by'] ?: '—') ?></span>
                            <?php if (!empty($t['tunda_sampai'])): ?>
                                <span class="muted">· s/d <?= h($tgl($t['tunda_sampai'])) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($t['note'])): ?><div><?= h($t['note']) ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (count($r['jejak']) > 8): ?>
                        <div class="muted" style="padding-top:4px"><?= count($r['jejak']) - 8 ?> jejak lebih lama tidak ditampilkan.</div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Catat satu tindak lanjut. Yang dicatat bukan status dokumen — dokumen tidak
 * berubah sedikit pun — melainkan FAKTA bahwa seseorang sudah mengejarnya.
 */
function monitoring_tindak(PDO $pdo): void
{
    require_permission('view_monitoring');
    verify_csrf();
    $pid   = current_property_id();
    $skpId = (int) post('skp_id', 0);
    $jenis = (string) post('jenis', 'kejar');
    // Tujuan kembali datang dari formulir, jadi diperlakukan sebagai masukan
    // asing: hanya alamat di dalam CLARA sendiri yang diterima.
    $balik = (string) post('balik', '?r=monitoring');
    // Formulirnya selalu mengirim alamat halaman ini sendiri, jadi hanya itu
    // yang diterima — tidak ada celah untuk dialihkan ke luar CLARA.
    if (!str_starts_with($balik, '?r=monitoring')) $balik = '?r=monitoring';
    if (!in_array($jenis, ['kejar', 'catat', 'tunda', 'abai'], true)) $jenis = 'catat';
    if ($jenis === 'abai' && !can('manage_payments') && !can('manage_skp')) {
        flash('Hanya admin atau pengelola dokumen yang boleh mengecualikan satu berkas.');
        header('Location: ' . $balik); exit;
    }

    // Dokumen harus ada di properti ini, dan harus boleh dilihat oleh orang ini.
    $st = $pdo->prepare('SELECT s.id, s.pic_name, s.created_by, t.pic_name AS t_pic, o.pic_name AS o_pic
                           FROM skp_documents s
                           LEFT JOIN transactions t ON t.id = s.transaction_id
                           LEFT JOIN offers o ON o.id = s.offer_id
                          WHERE s.id = ? AND s.property_id = ? AND s.deleted_at IS NULL LIMIT 1');
    $st->execute([$skpId, $pid]);
    $doc = $st->fetch();
    if (!$doc) { flash('Dokumen tidak ditemukan.'); header('Location: ' . $balik); exit; }

    $scope = current_sales_scope($pdo, $pid);
    if ($scope) {
        $milik = ($scope['pic'] !== '' && in_array($scope['pic'],
                    [(string) $doc['pic_name'], (string) $doc['t_pic'], (string) $doc['o_pic']], true))
               || (string) $doc['created_by'] === $scope['uname'];
        if (!$milik) { flash('Dokumen ini bukan milik Anda.'); header('Location: ' . $balik); exit; }
    }

    $tunda = trim((string) post('tunda_sampai', ''));
    if ($jenis !== 'tunda' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tunda)) $tunda = '';
    if ($jenis === 'tunda' && $tunda === '') {
        flash('Pilih dulu tanggalnya kalau mau ditunda.');
        header('Location: ' . $balik); exit;
    }

    try {
        $pdo->prepare('INSERT INTO monitoring_tindak
                       (property_id, skp_id, tahap, jenis, note, tunda_sampai, created_by, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$pid, $skpId, substr((string) post('tahap', ''), 0, 30), $jenis,
                       substr(trim((string) post('note', '')), 0, 500) ?: null,
                       $tunda ?: null, clara_pelaku()]);
        audit($pdo, 'monitoring_tindak', 'skp_documents', (string) $skpId,
              ['jenis' => $jenis, 'tahap' => (string) post('tahap', '')], [], 'monitoring');
        mon_badge_lupakan();
        flash(match ($jenis) {
            'kejar' => 'Tercatat: Anda sudah mengejar berkas ini.',
            'tunda' => 'Tercatat: berkas ini ditunda sampai ' . date('d/m/Y', strtotime($tunda)) . '.',
            'abai'  => 'Tercatat: berkas ini dikecualikan.',
            default => 'Catatan tersimpan.',
        });
    } catch (Throwable $e) {
        flash('Gagal menyimpan — jalankan migrasi database dulu (109_monitoring_transaksi).');
    }
    header('Location: ' . $balik);
    exit;
}
