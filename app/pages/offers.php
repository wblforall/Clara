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
function _offer_template_norm(array $t): array
{
    return [
        'name'              => (string) ($t['name'] ?? ''),
        'unit_type'         => (string) ($t['unit_type'] ?? ''),
        'perihal'           => (string) ($t['perihal'] ?? ''),
        'intro'             => (string) ($t['intro'] ?? ''),
        'fasilitas'         => json_decode((string) ($t['fasilitas_json'] ?? '[]'), true) ?: [],
        'payment'           => json_decode((string) ($t['payment_json'] ?? '[]'), true) ?: [],
        'terms'             => json_decode((string) ($t['terms_json'] ?? '[]'), true) ?: [],
        'dp_required'       => (int) ($t['dp_required'] ?? 1),
        'dp_months_default' => (float) ($t['dp_months_default'] ?? 2),
    ];
}

/**
 * Resolusi template Surat Penawaran: (properti, unit_type) → default properti
 * (unit_type='') → fallback kode (offer_terms/offer_facilities). Mengembalikan
 * struktur isi surat siap render.
 */
function offer_template_for(PDO $pdo, int $propertyId, ?string $unitType): array
{
    $st = $pdo->prepare("SELECT * FROM offer_templates WHERE property_id=? AND unit_type=? AND status='active' LIMIT 1");
    if ($unitType !== null && $unitType !== '') {
        $st->execute([$propertyId, $unitType]);
        if ($t = $st->fetch()) return _offer_template_norm($t);
    }
    $st->execute([$propertyId, '']);
    if ($t = $st->fetch()) return _offer_template_norm($t);
    // Fallback kode (praktis tak terpakai krn migrasi seed default per properti).
    return [
        'name' => 'Pameran Umum (default)', 'unit_type' => '',
        'perihal' => 'Surat Penawaran Sewa Area Pameran',
        'intro'   => 'Bersama ini kami Management e-Walk dan Pentacity Mall Balikpapan menawarkan space exhibition sebagai berikut:',
        'fasilitas' => offer_facilities(),
        'payment' => [
            'Wajib membayar biaya sewa (DP) senilai {dp} (Exc. PPN 12%) maksimal 1 minggu setelah penawaran disetujui, dan pelunasan paling lambat H-7 sebelum pelaksanaan sewa.',
            'Wajib membayar Security Deposit (uang jaminan) senilai {deposit} sebagai jaminan kerusakan / pengakhiran kontrak sebelum masa sewa berakhir.',
            'Apabila tidak terjadi kerusakan setelah masa sewa berakhir, Security Deposit dikembalikan 100%.',
        ],
        'terms' => offer_terms(),
        'dp_required' => 1, 'dp_months_default' => 2,
    ];
}

/** Ganti placeholder nominal pada teks template: {dp} {deposit} {total} {ppn} {grand}. */
function offer_letter_fill(string $text, array $a): string
{
    $rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    return strtr($text, [
        '{dp}'      => $rp($a['dp'] ?? 0),
        '{deposit}' => $rp($a['deposit'] ?? 0),
        '{total}'   => $rp($a['total'] ?? 0),
        '{ppn}'     => $rp($a['ppn'] ?? 0),
        '{grand}'   => $rp($a['grand'] ?? 0),
    ]);
}

/**
 * Isi surat untuk sebuah penawaran: dari snapshot letter_json (terkunci saat
 * simpan) bila ada; jika tidak (penawaran lama), resolve template live.
 */
function offer_letter(PDO $pdo, array $o): array
{
    if (!empty($o['letter_json'])) {
        $l = json_decode((string) $o['letter_json'], true);
        if (is_array($l)) {
            return $l + ['perihal' => '', 'intro' => '', 'fasilitas' => [], 'payment' => [], 'terms' => []];
        }
    }
    $ut  = offer_unit_type($pdo, (int) $o['property_id'], $o['master_code'] ?? null);
    $t   = offer_template_for($pdo, (int) $o['property_id'], $ut);
    return [
        'perihal' => $t['perihal'], 'intro' => $t['intro'],
        'fasilitas' => $t['fasilitas'], 'payment' => $t['payment'], 'terms' => $t['terms'],
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
    return ['module', 'client_id', 'contact_id', 'pic_name', 'referrer_name', 'master_code', 'keterangan',
            'pricing_type', 'unit_rate', 'area_sqm', 'quantity', 'slots',
            'start_date', 'end_date', 'contract_months', 'monthly_amount', 'total_calculated', 'override_amount',
            'billing_method', 'recurring_flag', 'cycle_recognition',
            'dp_months', 'dp_amount', 'deposit_months', 'deposit_amount', 'perihal', 'offer_date', 'is_bundle'];
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
    $out = [];
    foreach ($segs as $i => $seg) {
        $seg = in_array($seg, ['cl', 'media', 'gudang'], true) ? $seg : 'cl';
        $mc  = trim((string) ($mcs[$i] ?? '')) ?: null;
        $nm  = trim((string) ($nms[$i] ?? ''));
        if ($mc === null && $nm === '') continue;            // lewati baris kosong
        $mAmt = parse_rupiah($mon[$i] ?? '0');
        $out[] = [
            'segment'        => $seg,
            'master_code'    => $mc,
            'name_snapshot'  => $nm ?: $mc,
            'pricing_type'   => null,
            'unit_rate'      => 0.0,
            'area_sqm'       => 0.0,
            'slots'          => 1.0,
            'monthly_amount' => $mAmt,
            'dp_amount'      => parse_rupiah($dps[$i] ?? '0'),
            'deposit_amount' => parse_rupiah($deps[$i] ?? '0'),
            'total_amount'   => $mAmt * max(1, $months),
        ];
    }
    return $out;
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
            <details style="position:relative;display:inline-block">
                <summary class="btn" style="list-style:none;cursor:pointer">+ Buat Penawaran ▾</summary>
                <div style="position:absolute;z-index:30;margin-top:4px;background:#fff;border:1px solid var(--line,#e5e7eb);border-radius:10px;box-shadow:0 6px 24px rgba(0,0,0,.12);min-width:220px;overflow:hidden">
                    <a class="dd-item" href="?r=offer_form&module=cl" style="display:block;padding:9px 14px;font-size:13px">🏬 Exhibition <span style="color:var(--muted,#64748b);font-size:11px">— area / booth pameran</span></a>
                    <a class="dd-item" href="?r=offer_form&module=media" style="display:block;padding:9px 14px;font-size:13px;border-top:1px solid #f1f5f9">📺 Media <span style="color:var(--muted,#64748b);font-size:11px">— LED / videotron / TVC</span></a>
                    <a class="dd-item" href="?r=offer_form&module=gudang" style="display:block;padding:9px 14px;font-size:13px;border-top:1px solid #f1f5f9">📦 Gudang <span style="color:var(--muted,#64748b);font-size:11px">— storage</span></a>
                </div>
            </details>
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
            <?php if ($isSigned): ?>
                <p style="margin:0;color:#166534">✓ <strong>Disetujui &amp; ditandatangani online</strong> oleh <strong><?= h($offer['sign_name']) ?></strong> pada <?= h(substr($offer['signed_at'], 0, 16)) ?> (IP <?= h($offer['sign_ip']) ?>). Penawaran menjadi <strong>DEAL</strong> &amp; nilai terkunci.</p>
            <?php else: ?>
                <p style="margin:0 0 8px;color:#374151">Kirim tautan ini ke customer untuk meninjau &amp; menandatangani penawaran. Saat customer TTD, penawaran otomatis menjadi <strong>DEAL</strong> dan nilainya dikunci. <strong>Sebelum customer TTD, Anda masih bisa merevisi penawaran.</strong></p>
                <textarea id="of-wa-msg" style="position:absolute;left:-9999px" readonly><?= h($waMsg) ?></textarea>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <input id="of-sign-url" value="<?= h($signUrl) ?>" readonly style="flex:1;min-width:260px;font-size:12px" onclick="this.select()">
                    <button type="button" class="btn light" onclick="claraCopyText(document.getElementById('of-sign-url').value,this,'Tersalin ✓')">Salin Link</button>
                    <button type="button" class="btn light" onclick="claraCopyText(document.getElementById('of-wa-msg').value,this,'Pesan tersalin ✓')">Salin Pesan</button>
                    <a class="btn" style="background:#16a34a" target="_blank" href="https://wa.me/?text=<?= $waText ?>">Kirim via WhatsApp</a>
                </div>
                <p style="margin:8px 0 0;font-size:11.5px;color:#64748b">Tautan bersifat rahasia &amp; khusus untuk customer ini. <strong>Jika lewat WhatsApp Desktop hanya link yang terkirim</strong>, gunakan <strong>Salin Pesan</strong> lalu tempel (paste) di chat — teks lengkap akan ikut.</p>
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
    $editable = !$existing || !in_array($offer['status'], ['deal', 'cancelled'], true);

    $masters  = masterOptions($pdo, $module);
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
                'monthly'     => (int)$it['monthly_amount'],
                'dp'          => (int)$it['dp_amount'],
                'deposit'     => (int)$it['deposit_amount'],
            ];
        }
    }

    layout(($existing ? ($editable ? 'Edit' : 'Lihat') : 'Buat') . ' Penawaran ' . _offer_module_label($module), function () use ($pdo, $offer, $id, $existing, $isRenew, $module, $editable, $masters, $clients, $contacts, $pics, $referrers, $linkedPic, $v, $isBundle, $bundleRows) {
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
                            <td style="padding:3px 6px"><input type="text" inputmode="numeric" name="item_monthly[]" placeholder="0" value="<?= h($rupFmt($br['monthly'] ?? '')) ?>" <?= $editable ? '' : 'disabled' ?>></td>
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
                <div><label>Pricing Type</label>
                    <select name="pricing_type" id="pricing_type" <?= $disabled ?>>
                        <?php foreach (['daily_area', 'daily_slot', 'daily_point', 'monthly', 'fixed'] as $o): ?><option <?= ($offer['pricing_type'] ?? '') === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div><label>Rate</label><input type="number" step="0.01" name="unit_rate" id="unit_rate" value="<?= $v('unit_rate') ?>" <?= $disabled ?>></div>
                <div class="wide"><label>Keterangan</label><input name="keterangan" value="<?= $v('keterangan') ?>" <?= $disabled ?>></div>
            </div>
            </div><!-- /#single-fields (Objek Sewa) -->

            <h3>Periode<span id="ph-price-hd"> &amp; Harga</span></h3>
            <div class="form-grid">
                <div><label>Tanggal Mulai</label><input type="date" name="start_date" id="start_date" value="<?= $v('start_date') ?>" required <?= $disabled ?>></div>
                <div><label>Tanggal Selesai</label><input type="date" name="end_date" id="end_date" value="<?= $v('end_date') ?>" required <?= $disabled ?>></div>
                <div class="single-price"><label>Total Kontrak <span class="muted" style="font-weight:400">(otomatis)</span></label><input type="text" id="total_calc" value="" readonly><input type="hidden" name="total_calculated" id="total_calc_h" value="<?= $v('total_calculated') ?>"></div>
                <div class="single-price"><label>Harga / Bulan <span class="muted" style="font-weight:400">(otomatis)</span></label><input type="text" id="monthly_disp" value="" readonly><input type="hidden" name="monthly_amount" id="monthly_amount" value="<?= $v('monthly_amount') ?>"></div>
                <div class="wide single-price"><label>Harga Nego Final <span class="muted" style="font-weight:400">(opsional — override)</span></label><input type="text" inputmode="numeric" id="override_fmt" placeholder="Kosongkan = pakai hasil kalkulasi di atas"><input type="hidden" name="override_amount" id="override_amount" value="<?= (int)($offer['override_amount'] ?? 0) ?: '' ?>"><div class="help">Override: isi bila nilai final tidak sama dengan hasil kalkulasi.</div></div>
            </div>
            <?php if ($editable): ?>
            <div class="single-price" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:4px">
                <button type="button" class="btn light" id="btn-kalkulasi" style="background:#0ea5e9;color:#fff">Kalkulasi Total</button>
                <div id="kalkulasi-result" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:8px 14px;font-size:13px;color:#166534"></div>
            </div>
            <div id="kalkulasi-spread" class="single-price" style="display:none;margin-top:8px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 16px;font-size:12.5px;line-height:1.7"></div>
            <div id="overlap-warn" class="single-price" style="display:none;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:10px 14px;margin-top:10px;font-size:12.5px;color:#92400e"></div>
            <?php endif; ?>

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
                <div class="wide" style="display:flex;align-items:flex-start;gap:10px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:11px 14px">
                    <input type="checkbox" name="recurring_flag" id="recurring_flag" value="1" style="width:18px;height:18px;flex-shrink:0;margin-top:1px" <?= !empty($offer['recurring_flag']) ? 'checked' : '' ?> <?= $disabled ?>>
                    <label for="recurring_flag" style="margin:0;cursor:pointer">
                        <span style="font-weight:700;color:#0369a1">Diakui Recurring</span>
                        <span class="help" style="display:block;margin-top:2px;font-weight:400">Centang bila kontrak ini berulang. Diteruskan ke transaksi saat konfirmasi disetujui.</span>
                    </label>
                </div>
            </div>

            <div id="single-pay" style="<?= $isBundle ? 'display:none' : '' ?>">
            <h3>Pembayaran <span style="font-weight:400;font-size:12px;color:var(--muted)">(DP & deposit dihitung dari harga/bulan; bisa di-override)</span></h3>
            <div id="tpl-note" style="display:none;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:9px 13px;margin-bottom:10px;font-size:12.5px;color:#075985"></div>
            <div class="form-grid">
                <div><label>DP (bulan, min 1)</label><input type="number" step="0.5" min="1" name="dp_months" id="dp_months" value="<?= $v('dp_months', '1') ?>" <?= $disabled ?>></div>
                <div><label>Nominal DP <span class="muted" style="font-weight:400">(otomatis, bisa diubah)</span></label><input type="text" inputmode="numeric" id="dp_fmt" placeholder="0" <?= $disabled ?>><input type="hidden" name="dp_amount" id="dp_amount" value="<?= (int)($offer['dp_amount'] ?? 0) ?: '' ?>"></div>
                <div><label>Deposit (bulan)</label><input type="number" step="0.5" min="0" name="deposit_months" id="deposit_months" value="<?= $v('deposit_months', '1') ?>" <?= $disabled ?>></div>
                <div><label>Nominal Deposit <span class="muted" style="font-weight:400">(otomatis, bisa diubah)</span></label><input type="text" inputmode="numeric" id="dep_fmt" placeholder="0" <?= $disabled ?>><input type="hidden" name="deposit_amount" id="deposit_amount" value="<?= (int)($offer['deposit_amount'] ?? 0) ?: '' ?>"></div>
            </div>
            </div><!-- /#single-pay -->

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

            // ── Picker unit (searchable, sama seperti input transaksi) ──
            var masters = <?= json_encode(array_values($masters), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            var byCode = Object.fromEntries(masters.map(function (m) { return [m.code, m]; }));
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
            function applyTemplateRule(code) {
                var note = document.getElementById('tpl-note'); if (!note || !code) return;
                fetch('?r=offer_template_rule&master_code=' + encodeURIComponent(code), { cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        var dpm = document.getElementById('dp_months');
                        if (d.dp_required) {
                            note.innerHTML = '📄 Tipe <strong>' + esc(d.unit_type || '-') + '</strong> · template <strong>' + esc(d.template) + '</strong> · <strong>DP wajib</strong> (default ' + esc(d.dp_months_default) + ' bln).';
                            if (dpm) { dpm.min = '1'; if (!dpm.value || dpm.value === '0') dpm.value = d.dp_months_default || 1; }
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
                if (hid.value && hid.value !== '0') { fmt.value = fmtNum(parseInt(hid.value, 10)); fmt.dataset.touched = '1'; }
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
function offer_save(PDO $pdo): void
{
    require_permission('manage_offers');
    verify_csrf();
    $pid = current_property_id();
    $id  = (int) post('id');
    $uname = $_SESSION['user']['name'] ?? 'system';

    $module   = in_array(post('module'), ['cl', 'media', 'gudang'], true) ? post('module') : 'cl';
    $start    = post('start_date') ?: null;
    $end      = post('end_date') ?: null;
    $months   = _offer_months($start, $end);
    $days     = _offer_days($start, $end);
    $pricing  = post('pricing_type') ?: 'daily_area';
    $rate     = (float) post('unit_rate', 0);
    $area     = (float) post('area_sqm', 0);
    $slots    = max(1, (float) post('slots', 1));
    // Total kalkulasi (mesin pricing) → ditimpa harga nego final bila ada.
    $calc     = round(_offer_calc_total($pricing, $rate, $area, $slots, $days, $months));
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
    $tpl      = offer_template_for($pdo, $pid, $module === 'cl' ? $unitType : null);
    // #4 — sinyal non-silent bila unit_type ada tapi template jatuh ke default
    // (unit_type hasil kosong) → terbitnya surat dgn istilah salah bisa dilacak.
    if ($unitType !== '' && ($tpl['unit_type'] ?? '') === '') {
        error_log("offer template miss: property=$pid unit_type='$unitType' -> default");
    }
    $dpReq    = $tpl['dp_required'] == 1;
    // DP wajib → min 1 bln; deposit-only (tak wajib) → boleh 0.
    $dpMonths = $dpReq ? max(1, (float) post('dp_months', $tpl['dp_months_default'])) : max(0, (float) post('dp_months', 0));
    // #5 — DP deposit-only (dp_required=0) WAJIB 0 agar surat tidak kontradiktif
    // (0 bulan tapi nominal DP > 0). Parse via helper rupiah.
    $dpAmount = $dpReq ? parse_rupiah(post('dp_amount', '0')) : 0.0;
    $letterJson = json_encode([
        'template'    => $tpl['name'],
        'unit_type'   => $unitType,
        'perihal'     => $tpl['perihal'],
        'intro'       => $tpl['intro'],
        'fasilitas'   => $tpl['fasilitas'],
        'payment'     => $tpl['payment'],
        'terms'       => $tpl['terms'],
        'dp_required' => $tpl['dp_required'],
    ], JSON_UNESCAPED_UNICODE);

    $data = [
        'module'          => $module,
        'client_id'       => (int) post('client_id') ?: null,
        'contact_id'      => (int) post('contact_id') ?: null,
        'pic_name'        => trim((string) post('pic_name')) ?: null,
        'referrer_name'   => trim((string) post('referrer_name')) ?: null,
        'master_code'     => trim((string) post('master_code')) ?: null,
        'keterangan'      => trim((string) post('keterangan')) ?: null,
        'pricing_type'    => $pricing,
        'unit_rate'       => $rate,
        'area_sqm'        => $area,
        'quantity'        => 1,
        'slots'           => $slots,
        'start_date'      => $start,
        'end_date'        => $end,
        'contract_months' => $months,
        'monthly_amount'  => $monthly,
        'total_calculated' => $final,
        'override_amount' => $override ?: null,
        'billing_method'  => $billing,
        'recurring_flag'  => post('recurring_flag') ? 1 : 0,
        'cycle_recognition' => post('cycle_recognition') === 'cycle_end' ? 'cycle_end' : 'cycle_start',
        'dp_months'       => $dpMonths,
        'dp_amount'       => $dpAmount, // #5 — 0 utk template deposit-only
        'deposit_months'  => (float) post('deposit_months', 1),
        'deposit_amount'  => (float) post('deposit_amount', 0),
        'perihal'         => $tpl['perihal'] ?: ('Surat Penawaran Sewa Area Pameran' . ($days > 0 ? ' ' . $days . ' Hari' : '')),
        'letter_json'     => $letterJson,
        'offer_date'      => date('Y-m-d'),
        'is_bundle'       => 0,
    ];

    // Paket: bila >=2 komponen dikirim, timpa agregat & tandai is_bundle.
    // Periode SAMA utk semua item; harga eksplisit per item → total = Σ item.
    $isBundle = post('is_bundle') === '1';
    $bundleItems = $isBundle ? _offer_parse_bundle_items($months) : [];
    if ($isBundle && count($bundleItems) >= 2) {
        $conf = _offer_slot_conflicts($pdo, $pid, $bundleItems, $start, $end, $id);
        if ($conf) { flash('Bentrok slot: ' . implode('; ', $conf) . '. Perbaiki dulu.'); redirect_to('offer_form', $id ? ['id' => $id] : ['bundle' => 1]); }
        $data['module']           = 'bundle';
        $data['is_bundle']        = 1;
        $data['master_code']      = null;
        $data['monthly_amount']   = array_sum(array_column($bundleItems, 'monthly_amount'));
        $data['total_calculated'] = array_sum(array_column($bundleItems, 'total_amount'));
        $data['override_amount']  = $data['total_calculated'];
        $data['dp_amount']        = array_sum(array_column($bundleItems, 'dp_amount'));
        $data['deposit_amount']   = array_sum(array_column($bundleItems, 'deposit_amount'));
        $data['dp_months']        = 0;
        $data['deposit_months']   = 0;
        $data['perihal']          = 'Surat Penawaran Paket';
        $data['letter_json']      = json_encode([
            'template' => 'Paket', 'unit_type' => '', 'perihal' => 'Surat Penawaran Paket',
            'intro' => '', 'fasilitas' => [], 'payment' => [], 'terms' => [], 'dp_required' => 0, 'bundle' => true,
        ], JSON_UNESCAPED_UNICODE);
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
        "SELECT o.*, c.company_name, c.brand_name, c.address,
                ct.name cp_name,
                u.location_name, u.floor,
                p.email pic_email, p.phone pic_phone, p.signature_path pic_signature
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
    $total    = (float) $o['total_calculated'];
    $ppn      = round($total * 11 / 12 * 0.12);
    $afterPpn = $total + $ppn;
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
    $pid = current_property_id();
    $rows = $pdo->prepare("SELECT * FROM offer_templates WHERE property_id=? ORDER BY sort_order ASC, unit_type ASC");
    $rows->execute([$pid]);
    $tpls = $rows->fetchAll();
    layout('Template Surat Penawaran', function () use ($tpls) {
        ?>
        <div class="toolbar" style="gap:8px">
            <a class="btn" href="?r=offer_template_form">+ Tambah Template</a>
        </div>
        <div class="panel" style="margin-top:12px">
            <p class="muted" style="margin-top:0">Isi surat (perihal, intro, fasilitas, cara pembayaran, ketentuan) &amp; aturan DP berbeda per <strong>jenis booth (Tipe Unit)</strong>. Template <strong>(default)</strong> dipakai bila tipe unit belum punya template khusus. Saat penawaran disimpan, isi template di-<em>snapshot</em> sehingga surat terbit tak berubah walau template diedit.</p>
            <table class="data" style="width:100%">
                <thead><tr><th>Nama</th><th>Tipe Unit</th><th>DP</th><th>Fasilitas/Bayar/Ketentuan</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($tpls as $t): ?>
                    <tr>
                        <td><strong><?= h($t['name']) ?></strong></td>
                        <td><?= $t['unit_type'] === '' ? '<span class="badge">(default)</span>' : h($t['unit_type']) ?></td>
                        <td><?= $t['dp_required'] ? 'Wajib · ' . h(rtrim(rtrim(number_format((float)$t['dp_months_default'],1,',',''),'0'),',')) . ' bln' : '<span class="muted">Tanpa DP</span>' ?></td>
                        <td class="muted"><?= (int)(json_decode($t['fasilitas_json'] ?: '[]', true) ? count(json_decode($t['fasilitas_json'], true)) : 0) ?> / <?= (int)(json_decode($t['payment_json'] ?: '[]', true) ? count(json_decode($t['payment_json'], true)) : 0) ?> / <?= (int)(json_decode($t['terms_json'] ?: '[]', true) ? count(json_decode($t['terms_json'], true)) : 0) ?></td>
                        <td><?= $t['status'] === 'active' ? '<span style="color:#16a34a">Aktif</span>' : '<span class="muted">Nonaktif</span>' ?></td>
                        <td><a class="btn light" href="?r=offer_template_form&id=<?= (int)$t['id'] ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$tpls): ?><tr><td colspan="6" class="muted">Belum ada template.</td></tr><?php endif; ?>
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
    $unitTypes = cl_unit_types($pdo, $pid);
    $val = fn(string $k, $d = '') => h((string) ($t[$k] ?? $d));
    $lines = fn(string $col) => h(implode("\n", json_decode((string) ($t[$col] ?? '[]'), true) ?: []));
    layout(($t ? 'Edit' : 'Tambah') . ' Template Penawaran', function () use ($t, $id, $unitTypes, $val, $lines) {
        ?>
        <div class="toolbar"><a class="btn light" href="?r=offer_templates">← Daftar Template</a></div>
        <form class="panel" method="post" action="?r=offer_template_save" style="margin-top:12px">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <div class="form-grid">
                <div><label>Nama Template</label><input name="name" required value="<?= $val('name') ?>" placeholder="mis. Fashion Booth"></div>
                <div>
                    <label>Tipe Unit</label>
                    <select name="unit_type">
                        <option value="">(default — semua tipe lain)</option>
                        <?php foreach ($unitTypes as $ut): ?><option value="<?= h($ut) ?>" <?= ($t['unit_type'] ?? null) === $ut ? 'selected' : '' ?>><?= h($ut) ?></option><?php endforeach; ?>
                        <?php $utc = (string)($t['unit_type'] ?? ''); if ($utc !== '' && !in_array($utc, $unitTypes, true)): ?><option value="<?= h($utc) ?>" selected><?= h($utc) ?></option><?php endif; ?>
                    </select>
                    <div class="help">Satu template per tipe unit. "(default)" dipakai utk tipe tanpa template khusus.</div>
                </div>
                <div><label>Perihal</label><input name="perihal" value="<?= $val('perihal') ?>" placeholder="Surat Penawaran Sewa ..."></div>
                <div>
                    <label>Status</label>
                    <select name="status"><option value="active" <?= ($t['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Aktif</option><option value="inactive" <?= ($t['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Nonaktif</option></select>
                </div>
            </div>
            <div style="margin-top:10px"><label>Paragraf Pembuka (intro)</label><textarea name="intro" rows="2" style="width:100%"><?= $val('intro') ?></textarea></div>

            <div style="display:flex;gap:10px;align-items:flex-start;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:11px 14px;margin-top:12px">
                <input type="checkbox" name="dp_required" id="dp_required" value="1" style="width:18px;height:18px;margin-top:1px" <?= !$t || !empty($t['dp_required']) ? 'checked' : '' ?>>
                <div>
                    <label for="dp_required" style="font-weight:700">Wajib DP (Down Payment)</label>
                    <div class="help" style="margin-top:2px">Centang = penawaran tipe ini perlu DP. Kosongkan utk booth <strong>deposit-only</strong> (mis. Fashion/Food Stall) — validasi DP min 2 bln dilewati.</div>
                    <div style="margin-top:6px"><label style="font-size:12px">Default DP (bulan)</label> <input type="number" step="0.5" min="0" name="dp_months_default" value="<?= $val('dp_months_default', '2') ?>" style="width:90px"></div>
                </div>
            </div>

            <div style="margin-top:12px"><label>Fasilitas <span class="muted" style="font-weight:400">(1 baris = 1 poin)</span></label><textarea name="fasilitas" rows="3" style="width:100%"><?= $lines('fasilitas_json') ?></textarea></div>
            <div style="margin-top:10px"><label>Cara Pembayaran <span class="muted" style="font-weight:400">(1 baris = 1 poin · placeholder: <code>{dp}</code> <code>{deposit}</code> <code>{total}</code> <code>{ppn}</code> <code>{grand}</code>)</span></label><textarea name="payment" rows="4" style="width:100%"><?= $lines('payment_json') ?></textarea></div>
            <div style="margin-top:10px"><label>Ketentuan &amp; Persyaratan <span class="muted" style="font-weight:400">(1 baris = 1 poin)</span></label><textarea name="terms" rows="8" style="width:100%"><?= $lines('terms_json') ?></textarea></div>

            <p class="form-actions" style="margin-top:16px"><button type="submit">💾 Simpan Template</button> <a class="btn secondary" href="?r=offer_templates">Batal</a></p>
        </form>
        <script>
        document.querySelectorAll('textarea').forEach(function(tx) {
            tx.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && (e.key === 'b' || e.key === 'B')) {
                    e.preventDefault();
                    var start = this.selectionStart;
                    var end = this.selectionEnd;
                    var val = this.value;
                    var selected = val.substring(start, end);
                    var replacement = '**' + selected + '**';
                    this.value = val.substring(0, start) + replacement + val.substring(end);
                    this.selectionStart = start + 2;
                    this.selectionEnd = start + 2 + selected.length;
                }
            });
        });
        </script>
        <?php
    });
}

function offer_template_save(PDO $pdo): void
{
    require_permission('manage_master');
    verify_csrf();
    $pid = current_property_id();
    $id  = (int) post('id');
    $unitType = trim((string) post('unit_type', ''));
    $name = trim((string) post('name', ''));
    if ($name === '') { flash('Nama template wajib diisi.'); redirect_to('offer_template_form', $id ? ['id' => $id] : []); }
    // Cegah duplikat (property, unit_type)
    $dup = $pdo->prepare("SELECT id FROM offer_templates WHERE property_id=? AND unit_type=? AND id<>? LIMIT 1");
    $dup->execute([$pid, $unitType, $id]);
    if ($dup->fetchColumn()) { flash('Sudah ada template untuk tipe unit tersebut. Edit yang ada atau pilih tipe lain.'); redirect_to('offer_template_form', $id ? ['id' => $id] : []); }

    $J = fn($a) => json_encode($a, JSON_UNESCAPED_UNICODE);
    $data = [
        'property_id'       => $pid,
        'unit_type'         => $unitType,
        'name'              => $name,
        'perihal'           => trim((string) post('perihal', '')),
        'intro'             => trim((string) post('intro', '')),
        'fasilitas_json'    => $J(_tpl_lines((string) post('fasilitas', ''))),
        'payment_json'      => $J(_tpl_lines((string) post('payment', ''))),
        'terms_json'        => $J(_tpl_lines((string) post('terms', ''))),
        'dp_required'       => post('dp_required') ? 1 : 0,
        'dp_months_default' => (float) post('dp_months_default', 2),
        'status'            => post('status') === 'inactive' ? 'inactive' : 'active',
    ];
    if ($id) {
        $updateData = $data;
        unset($updateData['property_id']);
        $sets = implode(', ', array_map(fn($k) => "$k=:$k", array_keys($updateData)));
        $data['id'] = $id;
        $pdo->prepare("UPDATE offer_templates SET $sets, updated_at=NOW() WHERE id=:id AND property_id=:property_id")->execute($data);
        audit($pdo, 'update', 'offer_templates', (string) $id, $data);
    } else {
        $cols = implode(',', array_keys($data));
        $ph   = implode(',', array_map(fn($k) => ":$k", array_keys($data)));
        $pdo->prepare("INSERT INTO offer_templates ($cols) VALUES ($ph)")->execute($data);
        audit($pdo, 'create', 'offer_templates', (string) $pdo->lastInsertId(), $data);
    }
    flash('Template penawaran disimpan.');
    redirect_to('offer_templates');
}

/** AJAX: aturan DP & nama template utk unit terpilih (dipakai form penawaran). */
function offer_template_rule(PDO $pdo): void
{
    require_permission('manage_offers');
    header('Content-Type: application/json');
    $pid = current_property_id();
    $unitType = offer_unit_type($pdo, $pid, (string) getv('master_code', ''));
    $tpl = offer_template_for($pdo, $pid, $unitType);
    echo json_encode([
        'unit_type'         => $unitType,
        'template'          => $tpl['name'],
        'dp_required'       => $tpl['dp_required'],
        'dp_months_default' => $tpl['dp_months_default'],
    ]);
    exit;
}
