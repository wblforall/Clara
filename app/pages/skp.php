<?php
// ─── SKP — Surat Konfirmasi Pameran ──────────────────────────────────────────
// Dokumen konfirmasi untuk transaksi Exhibition yang sudah deal.
// Alur: sales buat (draft) → submit → manager approve (No. SKP terbit + snapshot)
//       → cetak/PDF. Lihat [[project-skp]] / migration 013.

require_once __DIR__ . '/skp_modules.php';

/** Ketentuan/Note baku dokumen SKP/SKS (sumber tunggal: cetakan & halaman TTD). */
function skp_terms(): array
{
    return [
        'Jika penyewa melakukan pengunduran jadwal dari tanggal masa sewa yang tertulis di kontrak maka akan dikenakan biaya Rp 1.000.000,- di luar total harga sewa pameran.',
        'Batas pengunduran jadwal pameran maksimal 1 bulan dari masa sewa yang tertulis di kontrak awal.',
        'Apabila melebihi batas pengunduran pameran maka pameran dianggap batal dan biaya yang telah dibayarkan penyewa tidak dapat ditarik kembali.',
        'Data peserta pameran (pribadi / perusahaan) harus sesuai dengan yang diberikan kepada pihak Manajemen Mall. Apabila kontrak, invoice dan faktur pajak telah terbit maka data tidak dapat dirubah dengan alasan apapun (kecuali kesalahan penginputan data dari pihak manajemen e-Walk dan Pentacity Mall Balikpapan).',
        'Apabila terdapat perubahan data untuk pameran selanjutnya, peserta pameran wajib memberitahukan perubahan data tersebut kepada pihak manajemen e-Walk dan Pentacity Mall Balikpapan.',
        'Surat Pemesanan ini bersifat mengikat para pihak sebelum dan sesudah diterbitkannya Kontrak Kerjasama.',
        'Wajib mengikuti jam operasional e-Walk dan Pentacity Mall Balikpapan : Hari Senin s.d Minggu — Jam 10.00 s.d 22.00 WITA.',
        'Jam Operasional Mall adalah 10.00 WITA s.d 22.00 WITA yang artinya jam 10.00 WITA tenant sudah diwajibkan beroperasi (bukan persiapan) dan jam 22.00 WITA tenant baru diperbolehkan untuk bersiap-siap menutup toko. Setiap pelanggaran dikenakan denda sebesar Rp 250.000,-. Denda wajib dibayarkan tenant secara tunai pada setiap akhir bulan berjalan (jatuh tempo tidak berlaku bagi tenant yang masa sewanya kurang dari 30 hari kalender).',
    ];
}

/** Map key properti → kode singkat untuk Nomor SKP. */
function _skp_prop_code(string $key): string
{
    return match ($key) {
        'ewalk'     => 'EW',
        'pentacity' => 'PC',
        default     => strtoupper(substr($key, 0, 2)),
    };
}

/** Ambil data transaksi + client + kontak + unit untuk prefill / snapshot. */
function _skp_source(PDO $pdo, int $trxId, int $pid): ?array
{
    $stmt = $pdo->prepare(
        "SELECT t.*, c.company_name, c.brand_name, c.npwp, c.ktp AS client_ktp, c.siup, c.address, c.business_type,
                ct.name cp_name, ct.phone cp_phone,
                COALESCE(u.location_name, g.name, CONCAT_WS(' - ', m.media_type, m.location, NULLIF(m.point, ''))) AS location_name,
                COALESCE(u.floor, g.location, m.location) AS floor,
                COALESCE(u.area_sqm, g.area_sqm) AS unit_area
         FROM transactions t
         LEFT JOIN master_clients c ON c.id = t.client_id
         LEFT JOIN master_client_contacts ct ON ct.id = t.contact_id
         LEFT JOIN master_cl_units u ON u.code = t.master_code AND u.property_id = t.property_id
         LEFT JOIN master_gudang   g ON g.code = t.master_code AND g.property_id = t.property_id
         LEFT JOIN master_media    m ON m.code = t.master_code AND m.property_id = t.property_id
         WHERE t.id = ? AND t.property_id = ? AND t.deleted_at IS NULL"
    );
    $stmt->execute([$trxId, $pid]);
    return $stmt->fetch() ?: null;
}

/** Ambil data dari Surat Penawaran (DEAL) untuk konfirmasi — shape sama dgn _skp_source. */
function _skp_source_from_offer(PDO $pdo, int $offerId, int $pid): ?array
{
    $stmt = $pdo->prepare(
        "SELECT o.*, o.keterangan AS content_note, o.total_calculated AS final_amount,
                c.company_name, c.brand_name, c.npwp, c.ktp AS client_ktp, c.siup, c.address, c.business_type,
                ct.name cp_name, ct.phone cp_phone,
                COALESCE(u.location_name, g.name, CONCAT_WS(' - ', m.media_type, m.location, NULLIF(m.point, ''))) AS location_name,
                COALESCE(u.floor, g.location, m.location) AS floor,
                COALESCE(u.area_sqm, g.area_sqm) AS unit_area
         FROM offers o
         LEFT JOIN master_clients c ON c.id = o.client_id
         LEFT JOIN master_client_contacts ct ON ct.id = o.contact_id
         LEFT JOIN master_gudang   g ON g.code = o.master_code AND g.property_id = o.property_id
         LEFT JOIN master_media    m ON m.code = o.master_code AND m.property_id = o.property_id
         LEFT JOIN master_cl_units u ON u.code = o.master_code AND u.property_id = o.property_id
         WHERE o.id = ? AND o.property_id = ? AND o.status = 'deal'"
    );
    $stmt->execute([$offerId, $pid]);
    $o = $stmt->fetch();
    if (!$o) return null;
    $o['renewal_status'] = null;
    $o['doc_type'] = $o['module'] === 'cl' ? 'skp' : 'sks';
    // Biaya listrik yang dicentang di penawaran ikut terbawa: nilainya masuk ke
    // total yang nanti jadi nilai transaksi & alokasi bulanan, dan rinciannya
    // tetap ditampilkan terpisah di SKP.
    if (!function_exists('offer_listrik')) require_once __DIR__ . '/offers.php';
    $o['electricity'] = offer_listrik($o);
    $o['final_amount'] = (float) $o['final_amount'] + $o['electricity'];
    return $o;
}

/**
 * Biaya listrik yang dicatat pada dokumen ini sendiri (dipakai SKP perpanjangan
 * & dokumen mandiri yang tidak lewat Surat Penawaran).
 * Kosong = tidak menagih listrik.
 */
function skp_listrik(array $skp, string $start = '', string $end = ''): float
{
    if (empty($skp['electricity_flag'])) return 0.0;
    $manual = (float) ($skp['electricity_amount'] ?? 0);
    if ($manual > 0) return round($manual, 2);
    return round((float) ($skp['electricity_monthly'] ?? 0) * skp_listrik_satuan($skp, $start, $end), 2);
}

/** Jumlah satuan listrik: pilihan sendiri, atau 30 hari = 1 satuan (dibulatkan). */
function skp_listrik_satuan(array $skp, string $start = '', string $end = ''): int
{
    $pilih = (int) ($skp['electricity_units'] ?? 0);
    if ($pilih > 0) return $pilih;
    $hari = _skp_days($start ?: null, $end ?: null);
    return $hari > 0 ? max(1, (int) round($hari / 30)) : 1;
}

/**
 * Hitung rincian biaya. PPN sesuai PMK 131/2024: nilai × 11/12 × 12%.
 * $listrik = bagian biaya listrik yang SUDAH termasuk di dalam $total — dipakai
 * hanya untuk menampilkannya sebagai baris terpisah.
 */
function _skp_amounts(float $total, float $ratePerM, float $deposit, float $listrik = 0.0, bool $depositLunas = false, bool $kenaPpn = true, float $scPerBulan = 0.0, int $scBulan = 1): array
{
    // PPN dihitung per komponen (sewa & listrik) supaya bisa dirinci; jumlahnya
    // dipakai sebagai PPN total agar penjumlahan di dokumen selalu pas.
    $sewa       = $total - $listrik;
    // Penyewa berharga bersih: PPN tidak dikenakan & barisnya tidak dicetak.
    $ppnSewa    = $kenaPpn ? round($sewa * 11 / 12 * 0.12) : 0.0;
    $ppnListrik = $kenaPpn ? round($listrik * 11 / 12 * 0.12) : 0.0;
    $ppn        = $ppnSewa + $ppnListrik;
    $afterPpn   = $total + $ppn;
    // Service Charge ditagih per bulan: PPN-nya dihitung per bulan lalu
    // dikalikan jumlah bulan, supaya angka di kertas persis bisa dijumlah ulang.
    $scBulan    = max(1, $scBulan);
    $scPpnBulan = $kenaPpn ? round($scPerBulan * 11 / 12 * 0.12) : 0.0;
    $scPerBulanPpn = $scPerBulan + $scPpnBulan;
    $scTotal    = $scPerBulan > 0 ? $scPerBulanPpn * $scBulan : 0.0;
    return [
        'rate_m_day'  => $ratePerM,
        'kena_ppn'    => $kenaPpn ? 1 : 0,
        'sewa'        => $sewa,
        'listrik'     => $listrik,
        'ppn_sewa'    => $ppnSewa,
        'ppn_listrik' => $ppnListrik,
        'total'       => $total,
        'ppn'         => $ppn,
        'after_ppn'   => $afterPpn,
        'sc_bulanan'    => $scPerBulan,
        'sc_ppn_bulan'  => $scPpnBulan,
        'sc_total_bulan'=> $scPerBulanPpn,
        'sc_bulan'      => $scBulan,
        'sc_total'      => $scTotal,
        'deposit'      => $deposit,
        // Deposit yang sudah disetor di kontrak sebelumnya tetap dicetak sebagai
        // catatan, tapi tidak ditagih ulang → tidak masuk Grand Total.
        'deposit_paid' => $depositLunas ? 1 : 0,
        'grand_total'  => $afterPpn + $scTotal + ($depositLunas ? 0 : $deposit),
    ];
}

/**
 * Apakah client ini pernah menyetor deposit di dokumen SKP sebelumnya?
 * Dipakai untuk mencentang otomatis "sudah dibayarkan" pada perpanjangan.
 */
function _skp_deposit_pernah(PDO $pdo, int $clientId, int $kecuali = 0): bool
{
    if ($clientId <= 0) return false;
    // client_id di skp_documents hanya terisi untuk dokumen Gudang/Media,
    // jadi client-nya ditelusuri lewat transaksi / penawaran juga.
    $st = $pdo->prepare(
        "SELECT 1 FROM skp_documents s
           LEFT JOIN transactions t ON t.id = s.transaction_id
           LEFT JOIN offers o ON o.id = s.offer_id
          WHERE COALESCE(s.client_id, t.client_id, o.client_id) = ?
            AND s.id <> ? AND s.deposit_amount > 0
            AND s.status IN ('approved','signed') LIMIT 1"
    );
    $st->execute([$clientId, $kecuali]);
    return (bool) $st->fetchColumn();
}

/**
 * Jumlah bulan sewa (siklus bulanan), minimal 1 — dasar perhitungan Service
 * Charge yang ditagih per bulan.
 */
function _skp_bulan(?string $a, ?string $b): int
{
    if (!$a || !$b) return 1;
    require_once dirname(__DIR__) . '/AllocationService.php';
    $n = (int) AllocationService::totalCalculated([
        'pricing_type' => 'monthly', 'unit_rate' => 1, 'quantity' => 1, 'slots' => 1,
        'area_sqm' => 0, 'start_date' => $a, 'end_date' => $b, 'contract_months' => null,
    ]);
    return max(1, $n);
}

/** Selisih hari inklusif. */
function _skp_days(?string $a, ?string $b): int
{
    if (!$a || !$b) return 0;
    try { return (int)(new DateTimeImmutable($a))->diff(new DateTimeImmutable($b))->days + 1; }
    catch (Exception $e) { return 0; }
}

// ─── Daftar SKP ──────────────────────────────────────────────────────────────
function skp_list_page(PDO $pdo): void
{
    require_permission('manage_skp');
    $pid    = current_property_id();
    $status = getv('status', '');
    $where  = ['s.property_id = ?']; $params = [$pid];
    if (in_array($status, ['draft', 'submitted', 'approved', 'signed', 'rejected'], true)) {
        $where[] = 's.status = ?'; $params[] = $status;
    }
    $module = getv('module', '');
    if (!in_array($module, ['cl', 'media', 'gudang'], true)) $module = '';
    if ($module) {
        // s.module (utf8mb4_bin) beda collation dengan t/o.module (utf8_general_ci),
        // jadi jangan di-COALESCE lalu dibandingkan — MySQL menolaknya
        // ("illegal mix of collations") dan daftarnya jadi kosong.
        $where[] = '(s.module = ? OR (s.module IS NULL AND COALESCE(t.module, o.module) = ?))';
        $params[] = $module; $params[] = $module;
    }
    // Pembatasan per-sales: SKP miliknya, yaitu yang PIC-nya dia (di dokumen,
    // di penawaran, atau di transaksi) ATAU yang ia buat sendiri. PIC transaksi
    // & PIC dokumen ikut dicek supaya dokumen tetap terlihat walau dibuatkan
    // admin/manager, dan supaya SKS/Form Utilities (tanpa penawaran) muncul.
    [$scopeSql, $scopeP] = current_sales_scope_sql(
        $pdo, $pid, ['s.pic_name', 'o.pic_name', 't.pic_name'], 's.created_by'
    );
    if ($scopeSql !== '') {
        $where[] = substr($scopeSql, 5);            // buang prefiks ' AND '
        $params  = array_merge($params, $scopeP);
    }
    // SKP bisa berasal dari penawaran (offer-first, transaksi belum terbit) ATAU
    // dari transaksi lama. LEFT JOIN keduanya + fallback datanya.
    $stmt = $pdo->prepare(
        'SELECT s.*,
                -- Dokumen Gudang/Media berdiri sendiri: kode unit, periode &
                -- client-nya tersimpan di dokumen itu sendiri, bukan di
                -- transaksi/penawaran (yang memang belum terbit).
                COALESCE(s.master_code, t.master_code, o.master_code) master_code,
                COALESCE(s.start_date, t.start_date, o.start_date)    start_date,
                COALESCE(s.end_date, t.end_date, o.end_date)          end_date,
                COALESCE(s.module, t.module, o.module)                module,
                COALESCE(sc.company_name, tc.company_name, oc.company_name) company_name
         FROM skp_documents s
         LEFT JOIN transactions t ON t.id = s.transaction_id
         LEFT JOIN master_clients tc ON tc.id = t.client_id
         LEFT JOIN offers o ON o.id = s.offer_id
         LEFT JOIN master_clients oc ON oc.id = o.client_id
         LEFT JOIN master_clients sc ON sc.id = s.client_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY s.id DESC'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    layout('Surat Konfirmasi Pameran (SKP)', function () use ($rows, $status, $module) {
        $modBadge = [
            'cl'     => ['Exhibition', '#0f766e', '#ccfbf1'],
            'media'  => ['Media', '#0369a1', '#e0f2fe'],
            'gudang' => ['Gudang', '#92400e', '#fef3c7'],
        ];
        $badge = [
            'draft'     => ['Draft', '#64748b', '#f1f5f9'],
            'submitted' => ['Menunggu Approval', '#92400e', '#fef3c7'],
            'approved'  => ['Disetujui · menunggu TTD', '#166534', '#dcfce7'],
            'signed'    => ['Ditandatangani', '#0369a1', '#e0f2fe'],
            'rejected'  => ['Ditolak', '#991b1b', '#fee2e2'],
        ];
        ?>
        <?php $mq = $module ? '&module=' . $module : ''; $sq = $status ? '&status=' . $status : ''; ?>
        <div class="toolbar" style="gap:8px;flex-wrap:wrap">
            <strong style="font-size:16px">Surat Konfirmasi SKP / SKS / Form Utilities</strong>
            <?php /* Gudang & Media tidak lewat Surat Penawaran — dokumennya dibuat
                     langsung dari transaksi lewat tombol ini. */ ?>
            <div class="dd" style="position:relative">
                <button type="button" class="btn" onclick="var m=this.nextElementSibling;m.style.display=m.style.display==='block'?'none':'block'">+ Buat Dokumen ▾</button>
                <div class="dd-menu" style="display:none;position:absolute;z-index:30;right:0;top:calc(100% + 4px);min-width:290px;background:#fff;border:1px solid var(--border,#e2e8f0);border-radius:10px;box-shadow:0 8px 24px rgba(15,23,42,.12);overflow:hidden">
                    <?php /* Hanya Gudang & Media yang dibuat dari sini. Exhibition
                             tetap lahir dari Surat Penawaran yang sudah DEAL,
                             jadi pilihannya mengarah ke halaman itu. */ ?>
                    <a class="dd-item" href="?r=skp_form&module=gudang" style="display:block;padding:9px 14px;font-size:13px">📦 SKS Gudang <span style="color:var(--muted,#64748b);font-size:11px">— formulir kosong</span></a>
                    <a class="dd-item" href="?r=skp_form&module=media" style="display:block;padding:9px 14px;font-size:13px;border-top:1px solid #f1f5f9">📺 Form Utilities <span style="color:var(--muted,#64748b);font-size:11px">— formulir kosong</span></a>
                    <a class="dd-item" href="?r=offers&tab=deal" style="display:block;padding:9px 14px;font-size:13px;border-top:1px solid #f1f5f9">🏬 SKP Exhibition <span style="color:var(--muted,#64748b);font-size:11px">— buka Surat Penawaran DEAL</span></a>
                    <a class="dd-item" href="?r=skp_pick&module=gudang" style="display:block;padding:9px 14px;font-size:12px;border-top:1px solid #f1f5f9;color:var(--muted,#64748b)">↗ Dari transaksi yang sudah ada</a>
                </div>
            </div>
            <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap">
                <?php foreach (['' => 'Semua', 'draft' => 'Draft', 'submitted' => 'Menunggu', 'approved' => 'Perlu TTD', 'signed' => 'Ditandatangani', 'rejected' => 'Ditolak'] as $k => $lbl): ?>
                    <a class="btn light" style="<?= $status === $k ? 'background:var(--primary,#0d9488);color:#fff' : '' ?>" href="?r=skp<?= $k ? '&status=' . $k : '' ?><?= $mq ?>"><?= $lbl ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:10px">
            <span style="font-size:12px;color:var(--muted);margin-right:2px">Modul:</span>
            <?php foreach (['' => 'Semua', 'cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'] as $mk => $mlbl):
                $mactive = $module === $mk; $mbg = $mk && isset($modBadge[$mk]) ? $modBadge[$mk][2] : '#0d9488'; $mc = $mk && isset($modBadge[$mk]) ? $modBadge[$mk][1] : '#fff'; ?>
                <a class="btn light" style="padding:5px 12px;font-size:12.5px;<?= $mactive ? 'background:' . $mbg . ';color:' . $mc . ';font-weight:700' : '' ?>" href="?r=skp<?= $sq ?><?= $mk ? '&module=' . $mk : '' ?>"><?= h($mlbl) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="panel" style="margin-top:12px">
            <p style="margin:0 0 10px;color:var(--muted);font-size:13px"><strong>Exhibition:</strong> SKP dibuat dari <strong>Preview Penawaran</strong> yang sudah DEAL. <strong>Gudang &amp; Media:</strong> tidak lewat Surat Penawaran — tekan <strong>+ Buat Dokumen</strong>, isi formulirnya langsung. Transaksi &amp; alokasi bulanan terbit otomatis saat manager menyetujui.</p>
            <div class="table-wrap">
                <table style="font-size:12.5px">
                    <thead><tr><th>No. SKP/SKS</th><th>Modul</th><th>Kode</th><th>Client</th><th>Periode</th><th>Status</th><th>Dibuat</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?><tr><td colspan="8" style="text-align:center;color:var(--muted);padding:24px">Belum ada SKP/SKS.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $r): $b = $badge[$r['status']] ?? $badge['draft']; $mb = $modBadge[$r['module']] ?? ['—', '#374151', '#f1f5f9']; ?>
                        <tr>
                            <?php /* Nomor baru terbit saat manager menyetujui — sebelum itu
                                     memang kosong, bukan kelewat. */ ?>
                            <td style="white-space:nowrap;font-weight:600"><?= $r['skp_no']
                                ? h($r['skp_no'])
                                : '<span class="muted" style="font-weight:400;font-size:12px">belum terbit<br>(nomor keluar saat disetujui)</span>' ?></td>
                            <td><span class="badge" style="color:<?= $mb[1] ?>;background:<?= $mb[2] ?>"><?= h($mb[0]) ?></span></td>
                            <td><?= h($r['master_code']) ?></td>
                            <td><?= h($r['company_name'] ?? '-') ?></td>
                            <td style="white-space:nowrap;font-size:11.5px"><?= $r['start_date'] ? h(date('d/m/y', strtotime($r['start_date'])) . '–' . date('d/m/y', strtotime($r['end_date']))) : '—' ?></td>
                            <td><span class="badge" style="color:<?= $b[1] ?>;background:<?= $b[2] ?>"><?= $b[0] ?></span></td>
                            <td style="font-size:11.5px;color:var(--muted)"><?= h($r['created_by'] ?? '-') ?><br><?= h(substr($r['created_at'] ?? '', 0, 16)) ?></td>
                            <td style="white-space:nowrap">
                                <a class="btn light" href="?r=skp_form&id=<?= (int)$r['id'] ?>"><?= $r['status'] === 'draft' || $r['status'] === 'rejected' ? 'Edit' : 'Lihat' ?></a>
                                <?php if (in_array($r['status'], ['approved', 'signed'], true)): ?><a class="btn light" href="?r=skp_print&id=<?= (int)$r['id'] ?>" target="_blank">PDF</a><?php endif; ?>
                                <?php if (($r['sign_method'] ?? '') === 'wet' && !empty($r['signed_doc_path'])): ?><a class="btn light" href="<?= h(upload_url($r['signed_doc_path'])) ?>" target="_blank">Dokumen ber-TTD</a><?php endif; ?>
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

/**
 * Pilih transaksi Gudang / Media yang akan dibuatkan dokumen konfirmasi.
 * Kedua modul ini tidak melewati Surat Penawaran, jadi sumber dokumennya
 * langsung transaksi yang sudah tercatat.
 */
function skp_pick(PDO $pdo): void
{
    require_permission('manage_skp');
    $pid    = current_property_id();
    $module = in_array(getv('module'), ['gudang', 'media'], true) ? (string) getv('module') : 'gudang';
    $q      = trim((string) getv('q', ''));
    $docLbl = $module === 'gudang' ? 'SKS Gudang' : 'Form Utilities';

    $sql = "SELECT t.id, t.master_code, t.start_date, t.end_date, t.final_amount,
                   c.company_name, c.brand_name, s.id AS skp_id, s.skp_no, s.status AS skp_status
            FROM transactions t
            LEFT JOIN master_clients c ON c.id = t.client_id
            LEFT JOIN skp_documents s  ON s.transaction_id = t.id AND s.property_id = t.property_id
            WHERE t.property_id = ? AND t.module = ? AND t.deleted_at IS NULL";
    $par = [$pid, $module];
    if ($q !== '') {
        $sql .= " AND (t.master_code LIKE ? OR c.company_name LIKE ? OR c.brand_name LIKE ?)";
        array_push($par, "%$q%", "%$q%", "%$q%");
    }
    $sql .= ' ORDER BY t.start_date DESC, t.id DESC LIMIT 200';
    $st = $pdo->prepare($sql);
    $st->execute($par);
    $rows = $st->fetchAll();

    layout('Buat ' . $docLbl, function () use ($rows, $module, $q, $docLbl) {
        ?>
        <div class="toolbar" style="gap:8px;flex-wrap:wrap">
            <a class="btn light" href="?r=skp">← Daftar Dokumen</a>
            <a class="btn light" style="<?= $module === 'gudang' ? 'background:#fef3c7;color:#92400e;font-weight:700' : '' ?>" href="?r=skp_pick&module=gudang">📦 SKS Gudang</a>
            <a class="btn light" style="<?= $module === 'media' ? 'background:#e0f2fe;color:#0369a1;font-weight:700' : '' ?>" href="?r=skp_pick&module=media">📺 Form Utilities</a>
        </div>
        <div class="panel" style="margin-top:12px">
            <p style="margin:0 0 10px;color:var(--muted);font-size:13px">Pilih transaksi yang akan dibuatkan <strong><?= h($docLbl) ?></strong>. Satu transaksi hanya boleh punya satu dokumen — yang sudah punya ditandai dan tombolnya membuka dokumen itu.</p>
            <form method="get" style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap">
                <input type="hidden" name="r" value="skp_pick"><input type="hidden" name="module" value="<?= h($module) ?>">
                <input name="q" value="<?= h($q) ?>" placeholder="Cari kode unit / client…" style="min-width:240px">
                <button type="submit" class="btn light">Cari</button>
                <?php if ($q !== ''): ?><a class="btn light" href="?r=skp_pick&module=<?= h($module) ?>">Reset</a><?php endif; ?>
            </form>
            <div class="table-wrap">
                <table style="font-size:12.5px">
                    <thead><tr><th>Kode</th><th>Client</th><th>Periode</th><th style="text-align:right">Nilai</th><th>Dokumen</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:22px">Tidak ada transaksi <?= h($module === 'gudang' ? 'gudang' : 'media') ?><?= $q !== '' ? ' yang cocok dengan pencarian' : '' ?>.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><strong><?= h($r['master_code']) ?></strong></td>
                            <td><?= h($r['company_name'] ?: '-') ?><?= $r['brand_name'] ? ' <span class="muted">· ' . h($r['brand_name']) . '</span>' : '' ?></td>
                            <td style="white-space:nowrap"><?= h(date('d/m/y', strtotime($r['start_date'])) . '–' . date('d/m/y', strtotime($r['end_date']))) ?></td>
                            <td style="text-align:right;white-space:nowrap"><?= money((float) $r['final_amount']) ?></td>
                            <td><?= $r['skp_id'] ? '<span class="badge" style="background:#dcfce7;color:#166534">' . h($r['skp_no'] ?: strtoupper((string) $r['skp_status'])) . '</span>' : '<span class="muted">belum ada</span>' ?></td>
                            <td style="white-space:nowrap">
                                <a class="btn <?= $r['skp_id'] ? 'light' : '' ?>" href="?r=skp_form&transaction_id=<?= (int) $r['id'] ?>"><?= $r['skp_id'] ? 'Buka Dokumen' : 'Buat ' . h($module === 'gudang' ? 'SKS' : 'Form') ?></a>
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

// ─── Form SKP (buat/edit) ────────────────────────────────────────────────────
function skp_form(PDO $pdo): void
{
    require_permission('manage_skp');
    $pid = current_property_id();
    $id  = (int) getv('id');
    $trxId   = (int) getv('transaction_id');
    $offerId = (int) getv('offer_id');
    // Datang dari tombol "Perpanjang" di daftar transaksi → Status Sewa default
    // "Perpanjangan". Transaksinya memang baru, jadi renewal_status-nya masih
    // 'none' dan tidak bisa dipakai sebagai penanda.
    $isRenew = getv('renew') === '1';
    $skp = null;

    // Dokumen dari transaksi (Gudang/Media) — cegah duplikat: bila transaksi ini
    // sudah punya dokumen, buka yang itu, jangan bikin yang kedua.
    if (!$id && $trxId) {
        $ex = $pdo->prepare('SELECT id FROM skp_documents WHERE transaction_id = ? AND property_id = ? LIMIT 1');
        $ex->execute([$trxId, $pid]);
        if ($exId = $ex->fetchColumn()) redirect_to('skp_form', ['id' => (int) $exId]);
    }

    if ($id) {
        $st = $pdo->prepare('SELECT * FROM skp_documents WHERE id = ? AND property_id = ?');
        $st->execute([$id, $pid]);
        $skp = $st->fetch();
        if (!$skp) { flash('Dokumen tidak ditemukan.'); redirect_to('skp'); }
        $trxId   = (int) $skp['transaction_id'];
        $offerId = (int) $skp['offer_id'];
    } elseif ($offerId) {
        $ex = $pdo->prepare('SELECT id FROM skp_documents WHERE offer_id = ? AND property_id = ?');
        $ex->execute([$offerId, $pid]);
        if ($exId = $ex->fetchColumn()) { redirect_to('skp_form', ['id' => (int)$exId]); }
    } elseif ($trxId) {
        $ex = $pdo->prepare('SELECT id FROM skp_documents WHERE transaction_id = ? AND property_id = ?');
        $ex->execute([$trxId, $pid]);
        if ($exId = $ex->fetchColumn()) { redirect_to('skp_form', ['id' => (int)$exId]); }
    }

    // Gudang & Media: dokumen berdiri sendiri — tidak lewat Surat Penawaran dan
    // tidak menumpang transaksi. Datanya diisi di formulir ini, transaksinya
    // terbit saat manager menyetujui.
    $standalone = !$offerId && !$trxId;
    $modKind    = $standalone
        ? (in_array($skp['module'] ?? getv('module'), ['gudang', 'media'], true) ? (string) ($skp['module'] ?? getv('module')) : '')
        : '';
    if ($standalone && $modKind === '') {
        flash('Pilih jenis dokumen yang mau dibuat.');
        redirect_to('skp');
    }

    // Sumber data: dokumen itu sendiri (Gudang/Media), Penawaran, atau Transaksi.
    $src = $standalone
        ? skp_standalone_src($pdo, $pid, $modKind, $skp)
        : ($offerId ? _skp_source_from_offer($pdo, $offerId, $pid) : _skp_source($pdo, $trxId, $pid));
    if (!$src) { flash('Sumber (penawaran/transaksi) tidak ditemukan / belum DEAL.'); redirect_to($offerId ? 'offers' : 'transactions'); }
    // Pembatasan per-sales: hanya boleh akses SKP miliknya (PIC sumber / PIC
    // yang tercatat di dokumen) atau yang ia buat sendiri.
    //
    // Dokumen Gudang/Media yang BARU belum punya pemilik sama sekali — sales
    // justru sedang membuatnya, jadi jangan dihadang. Yang tetap dijaga:
    // membuka dokumen yang sudah ada, dan membuatkan SKP di atas transaksi /
    // penawaran milik sales lain.
    $dokumenBaruMandiri = !$skp && $standalone;
    if (!$dokumenBaruMandiri && ($sc = current_sales_scope($pdo, $pid))) {
        $ownPic = $sc['pic'] !== '' && in_array($sc['pic'], [
            (string) ($src['pic_name'] ?? ''), (string) ($skp['pic_name'] ?? ''),
        ], true);
        $ownBy  = ($skp['created_by'] ?? '') === $sc['uname'];
        if (!$ownPic && !$ownBy) { flash('SKP ini bukan milik Anda.'); redirect_to('skp'); }
    }
    $docType  = (string) ($skp['doc_type'] ?? skp_doc_type($src['module'] ?? 'cl'));
    $docLabel = skp_doc_label($docType);

    $editable = !$skp || in_array($skp['status'], ['draft', 'rejected'], true);
    $days     = _skp_days($src['start_date'], $src['end_date']);
    $total    = (float) ($src['final_amount'] ?: $src['total_calculated']);
    $defDeposit = (float) ($skp['deposit_amount'] ?? $src['deposit_amount'] ?? 0);
    // Deposit sudah dibayar? Dokumen lama pakai nilainya sendiri; dokumen baru
    // dicentang otomatis bila client ini pernah menyetor deposit sebelumnya
    // (kasus perpanjangan) — tetap bisa dibatalkan manual.
    // Bawaannya dikenakan PPN; dokumen lama memakai nilainya sendiri, dokumen
    // baru ikut penawarannya (bila ada).
    $defPpn = $skp
        ? !empty($skp['ppn_flag'])
        : (!isset($src['ppn_flag']) || !empty($src['ppn_flag']));
    // Service Charge: dokumen lama pakai nilainya sendiri, dokumen baru ikut
    // penawarannya (bila ada). Bawaannya TIDAK dikenakan.
    $defSc    = $skp ? !empty($skp['sc_flag']) : !empty($src['sc_flag']);
    $defScRp  = (float) ($skp['sc_monthly'] ?? $src['sc_monthly'] ?? 0);
    $scBulan  = _skp_bulan($src['start_date'] ?? null, $src['end_date'] ?? null);
    $defDepPaid = $skp
        ? !empty($skp['deposit_paid'])
        : (!empty($src['deposit_paid'])                      // ikut dari Surat Penawaran
            || ($defDeposit > 0 && _skp_deposit_pernah($pdo, (int) ($src['client_id'] ?? 0))));
    // Listrik: dari penawaran (bila ada) atau yang dicatat di dokumen ini.
    $listrikSkp = $skp ? skp_listrik($skp, (string) $src['start_date'], (string) $src['end_date']) : 0.0;
    $listrikAll = (float) ($src['electricity'] ?? 0) + $listrikSkp;
    $amt      = _skp_amounts($total + $listrikSkp, (float) $src['unit_rate'], $defDeposit, $listrikAll, $defDepPaid, $defPpn,
        $defSc ? $defScRp : 0.0, $scBulan);
    $area     = (float) ($src['area_sqm'] ?: $src['unit_area']);
    // Isi khusus modul: tabel harga Gudang / daftar centang Form Utilities Media.
    // Teksnya (intro, peraturan, catatan kaki, daftar item) datang dari Template
    // Dokumen sehingga bisa diubah user tanpa koding.
    $tplDoc = skp_template($pdo, $pid, skp_doc_module($docType));
    $detail = ($skp && !empty($skp['detail_json']))
        ? (json_decode((string) $skp['detail_json'], true) ?: [])
        : skp_detail_defaults($tplDoc, $docType, $src, $amt);
    // Lampiran tersimpan (untuk edit)
    $atts = [];
    if ($skp) {
        $as = $pdo->prepare('SELECT kind, file_path, original_name FROM skp_attachments WHERE skp_id = ?');
        $as->execute([(int)$skp['id']]);
        foreach ($as->fetchAll() as $a) $atts[$a['kind']] = $a;
    }
    // Scan KTP/NPWP dari dokumen client yang sama sebelumnya → bisa dipakai ulang.
    $reuse = $editable ? _skp_reusable_attachments($pdo, (int) ($src['client_id'] ?? 0), (int) ($skp['id'] ?? 0)) : [];
    $val = fn(string $k, $def = '') => h((string) ($skp[$k] ?? $def));
    // Pembagian income ke beberapa PIC. Pilihan PIC dibatasi master_pic aktif
    // properti ini — nama di luar itu tidak akan muncul di tabel achievement.
    // show_achievement=0 / target_share=0 dikecualikan dari Laporan PIC, jadi
    // income yang dibagikan ke sana tidak akan muncul di tabel achievement.
    // Tetap boleh dipilih (mis. akun unit), tapi diberi penanda terang.
    $picAktif = $pdo->prepare("SELECT name, (show_achievement = 1 AND target_share > 0) AS di_laporan
                               FROM master_pic WHERE property_id = ? AND status = 'active' ORDER BY name");
    $picAktif->execute([$pid]);
    $picAktif = $picAktif->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $bagi = [];
    if ($skp) {
        $bq = $pdo->prepare('SELECT pic_name, amount FROM transaction_pic_splits WHERE skp_id = ? ORDER BY id');
        $bq->execute([(int) $skp['id']]);
        $bagi = $bq->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    // Acuan jumlah: nilai kontrak dokumen ini, bukan angka yang diketik ulang.
    $nilaiAcuan = (float) ($src['final_amount'] ?: $src['total_calculated']);

    layout(($skp ? ($editable ? 'Edit' : 'Lihat') : 'Buat') . ' ' . skp_doc_short($docType), function () use ($pdo, $skp, $src, $trxId, $offerId, $docType, $docLabel, $editable, $days, $total, $amt, $area, $defDeposit, $defDepPaid, $defPpn, $defSc, $defScRp, $scBulan, $picAktif, $bagi, $nilaiAcuan, $atts, $reuse, $val, $pid, $isRenew, $detail, $standalone, $modKind) {
        $statusSewaDefault = ($isRenew || (!empty($src['renewal_status']) && $src['renewal_status'] !== 'none')) ? 'Perpanjangan' : 'Baru';
        ?>
        <div class="toolbar" style="gap:8px"><a class="btn light" href="?r=<?= $standalone ? 'skp' : ($offerId ? 'offer_form&id=' . (int)$offerId : 'allocation_detail&id=' . (int)$trxId) ?>">← <?= $standalone ? 'Daftar Dokumen' : ($offerId ? 'Penawaran' : 'Detail Alokasi') ?></a><a class="btn light" href="?r=skp">Daftar Dokumen</a> <span class="badge" style="background:#e0f2fe;color:#0369a1"><?= h($docLabel) ?></span></div>

        <?php if ($skp && $skp['status'] === 'rejected'): ?>
        <div class="panel" style="margin-top:10px;background:#fef2f2;border:1px solid #fecaca">
            <strong style="color:#991b1b">Ditolak manager.</strong> Catatan: <?= h($skp['reject_note'] ?? '-') ?>. Perbaiki lalu submit ulang.
        </div>
        <?php endif; ?>

        <form class="panel" method="post" action="?r=skp_save" style="margin-top:12px" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= (int)($skp['id'] ?? 0) ?>">
            <input type="hidden" name="transaction_id" value="<?= (int)$trxId ?>">
            <input type="hidden" name="offer_id" value="<?= (int)$offerId ?>">
            <input type="hidden" name="doc_type" value="<?= h($docType) ?>">
            <?php if ($standalone): ?><input type="hidden" name="standalone" value="1"><input type="hidden" name="module" value="<?= h($modKind) ?>"><?php endif; ?>
            <!-- action via hidden field (di-set onclick) — handler anti-double-submit
                 global men-disable tombol saat submit, jadi name/value tombol hilang. -->
            <input type="hidden" name="action" id="skp-action" value="save">

            <?php if ($standalone) skp_standalone_form($pdo, $pid, $modKind, $src, $editable); ?>

            <h3<?= $standalone ? '' : ' style="margin-top:0"' ?>>Identitas Penyewa</h3>
            <div class="form-grid">
                <div><label>Nama Perusahaan</label><input id="idn-company" value="<?= h($src['company_name'] ?? '-') ?>" disabled></div>
                <div><label>Nama Penanggung Jawab</label><input name="cp_name" value="<?= $val('cp_name', $src['cp_name'] ?? '') ?>" <?= $editable ? '' : 'disabled' ?>></div>
                <div class="wide"><label>Alamat</label><input id="idn-address" value="<?= h($src['address'] ?? '-') ?>" disabled></div>
                <?php
                // Sudah ada di Master Client atau belum — supaya tidak perlu mengetik ulang.
                $adaKtp  = trim((string) ($src['client_ktp'] ?? '')) !== '';
                $adaNpwp = trim((string) ($src['npwp'] ?? '')) !== '';
                $nota = fn(bool $ada) => $ada
                    ? '<span class="help" style="font-size:11px;color:#166534">✓ Sudah tersimpan di Master Client — tidak perlu diketik ulang</span>'
                    : '<span class="help" style="font-size:11px;color:#92400e">Belum ada di Master Client — isi sekali, otomatis tersimpan</span>';
                ?>
                <div><label>Nomor KTP Penanggung Jawab <span style="color:#dc2626">*</span></label><input name="ktp_pj" value="<?= $val('ktp_pj', $src['client_ktp'] ?? '') ?>" inputmode="numeric" <?= $editable ? '' : 'disabled' ?>><?= $nota($adaKtp) ?></div>
                <div><label>Nomor NPWP <span style="color:#dc2626">*</span></label><input name="npwp_no" value="<?= $val('npwp_no', $src['npwp'] ?? '') ?>" inputmode="numeric" <?= $editable ? '' : 'disabled' ?>><?= $nota($adaNpwp) ?></div>
                <div><label>Nomor SIUP <span class="muted" style="font-weight:400;font-size:11px">(opsional)</span></label><input name="siup_no" value="<?= $val('siup_no', $src['siup'] ?? '') ?>" <?= $editable ? '' : 'disabled' ?>><span class="help" style="font-size:11px">Tersimpan ke Master Client (auto next)</span></div>
                <div><label>Nomor Telepon</label><input name="phone_pj" value="<?= $val('phone_pj', $src['cp_phone'] ?? '') ?>" <?= $editable ? '' : 'disabled' ?>></div>
            </div>

            <h3>Lampiran <span style="font-weight:400;font-size:12px;color:var(--muted)">(<span style="color:#dc2626">*</span> wajib sebelum submit; scan lama client ini dipakai ulang otomatis)</span></h3>
            <div class="form-grid">
                <?php
                // Bukti Transfer SENGAJA tidak ada di sini. Urutan nyata di lapangan:
                // klien butuh SKP/invoice dulu sebagai dasar membayar. Unggahnya
                // pindah ke Permintaan Kontrak (wajib sebelum berkas ke Legal).
                $reqLbl = ['ktp' => 'Scan KTP', 'npwp' => 'Scan NPWP', 'siup' => 'Scan SIUP (opsional)', 'pengajuan' => 'Pengajuan (opsional)'];
                $wajib  = ['ktp', 'npwp'];
                foreach ($reqLbl as $kind => $lbl):
                    $has = $atts[$kind] ?? null;
                ?>
                <?php
                $prev = (!$has && $editable) ? ($reuse[$kind] ?? null) : null;
                // Status ringkas per berkas — supaya jelas mana yang sudah ada dan
                // mana yang benar-benar harus diunggah.
                if ($has)        $st = ['✓ Sudah ada di dokumen ini', '#166534', '#f0fdf4', '#bbf7d0'];
                elseif ($prev)   $st = ['✓ Sudah ada — dipakai ulang, tidak perlu unggah', '#166534', '#f0fdf4', '#bbf7d0'];
                elseif (in_array($kind, $wajib, true)) $st = ['⚠ Belum ada — wajib diunggah', '#92400e', '#fffbeb', '#fde68a'];
                else             $st = ['Belum ada (opsional)', '#64748b', '#f8fafc', '#e2e8f0'];
                ?>
                <div>
                    <label><?= $lbl ?><?= in_array($kind, $wajib, true) ? ' <span style="color:#dc2626">*</span>' : '' ?></label>
                    <div style="font-size:11.5px;font-weight:700;color:<?= $st[1] ?>;background:<?= $st[2] ?>;border:1px solid <?= $st[3] ?>;border-radius:7px;padding:4px 8px;margin-bottom:5px"><?= $st[0] ?></div>
                    <?php if ($has): ?>
                        <div style="font-size:12px"><a href="<?= h(upload_url($has['file_path'])) ?>" target="_blank">📎 <?= h($has['original_name'] ?: 'lihat') ?></a></div>
                    <?php endif; ?>
                    <?php if ($prev): ?>
                        <label style="font-size:12px;display:flex;align-items:center;gap:6px;background:#f0fdfa;border:1px solid #99f6e4;border-radius:7px;padding:5px 8px;margin-bottom:5px">
                            <input type="checkbox" name="reuse_<?= $kind ?>" value="1" checked>
                            Pakai ulang: 📎 <?= h($prev['original_name'] ?: 'scan sebelumnya') ?>
                        </label>
                        <span class="help" style="font-size:11px;color:var(--muted)">Hapus centang bila ingin unggah baru.</span>
                    <?php endif; ?>
                    <?php if ($editable): ?><input type="file" name="att_<?= $kind ?>" accept="image/*,application/pdf"><?php endif; ?>
                </div>
                <?php endforeach; ?>
                <div><label>Lampiran Surat Penawaran</label><div style="font-size:12px;color:var(--muted)"><?= $offerId ? '📎 otomatis dari penawaran (PDF)' : '— (sumber transaksi, tanpa penawaran)' ?></div></div>
            </div>

            <h3>Spesifikasi Tempat & Periode</h3>
            <div class="form-grid">
                <div><label>Lokasi</label><input id="spec-lokasi" value="<?= h($src['location_name'] ?? $src['master_code']) ?>" disabled></div>
                <div><label>Lantai</label><input id="spec-lantai" value="<?= h($src['floor'] ?? '-') ?>" disabled></div>
                <?php /* Titik media dijual per hari/titik — luas area & seating
                         tidak dipakai, jadi kolomnya tidak ditampilkan di Media. */ ?>
                <?php if ($docType !== 'fu'): ?>
                <div><label>Luas Area (m²)</label><input id="spec-luas" value="<?= number_format($area, 2, ',', '.') ?>" disabled></div>
                <div><label>Luas Seating Area (m²)</label><input name="seating_area" value="<?= $val('seating_area') ?>" inputmode="decimal" placeholder="opsional" <?= $editable ? '' : 'disabled' ?>></div>
                <?php endif; ?>
                <div><label>Masa Sewa</label><input id="spec-masa" value="<?= h($src['start_date'] . ' s/d ' . $src['end_date']) ?> (<?= $days ?> hari)" disabled></div>
                <div>
                    <label>Status Sewa</label>
                    <select name="status_sewa" <?= $editable ? '' : 'disabled' ?>>
                        <?php $cur = $skp['status_sewa'] ?? $statusSewaDefault; foreach (['Baru', 'Perpanjangan'] as $o): ?>
                        <option value="<?= $o ?>" <?= $cur === $o ? 'selected' : '' ?>><?= $o ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Jenis Usaha / Kegiatan</label><input id="spec-usaha" value="<?= h($src['business_type'] ?? '-') ?>" disabled></div>
                <div><label>Produk <span class="muted" style="font-weight:400">(nama brand client)</span></label><input name="produk" value="<?= $val('produk', $src['brand_name'] ?? '') ?>" <?= $editable ? '' : 'disabled' ?>></div>
            </div>

            <?php /* Harga sewa disisipkan di dalam rincian modul, tepat setelah
                     data client/kegiatan — supaya angka uang berkumpul di bawah. */ ?>
            <?php if ($docType !== 'skp') skp_detail_form($docType, $detail, $editable, $standalone ? $src : []); ?>

            <?php /* Gudang punya tabel harganya sendiri (per m²/bulan), jadi rincian
                     gaya pameran tidak dipakai — cukup Security Deposit yang memang
                     disebut di Peraturan Sewa Gudang. */ ?>
            <?php if ($docType === 'sks'): ?>
            <h3>Security Deposit</h3>
            <div class="form-grid">
                <div><label>Jaminan / Security Deposit</label><input name="deposit_amount" class="skp-dep-fmt" value="<?= $defDeposit > 0 ? number_format($defDeposit, 0, ',', '.') : '' ?>" inputmode="numeric" placeholder="0" <?= $editable ? '' : 'disabled' ?>><input type="hidden" name="deposit_raw" class="skp-dep-val" value="<?= (int)$defDeposit ?>"><div class="help">Muncul di butir Peraturan Sewa Gudang. Rincian harganya ambil dari tabel di atas.</div></div>
            </div>
            <?php elseif ($docType === 'fu'): ?>
            <?php
            // Susunannya mengikuti blok "RINCIAN BIAYA" di formulir kertas:
            // satu baris "sewa + ppn = Rp. total,-". Ketiga angkanya terisi
            // otomatis tapi boleh diketik sendiri — yang diketik dipakai apa
            // adanya, termasuk saat dicetak.
            $fuPpn   = (float) ($detail['ppn'] ?? 0) ?: (float) $amt['ppn'];
            $fuGrand = (float) ($detail['grand'] ?? 0) ?: (float) $amt['total'] + $fuPpn;
            $nf      = fn($v) => number_format((float) $v, 0, ',', '.');
            ?>
            <h3 id="fu-rekap">Rincian Biaya <span style="font-weight:400;font-size:12px;color:var(--muted)">(terisi otomatis — semua boleh diketik sendiri)</span></h3>
            <div class="form-grid">
                <div>
                    <label>Total Biaya Sewa <span style="color:#dc2626">*</span></label>
                    <?php skp_input_rp('s_total_amount', $amt['total'], $editable ? '' : 'disabled', true); ?>
                    <div class="help" id="s-total-info" style="margin-top:3px">Dihitung otomatis dari tarif × periode — boleh diubah manual.</div>
                </div>
                <div><label>PPN 12% <span class="muted" style="font-weight:400">(nilai × 11/12 × 12%)</span></label><?php skp_input_rp('d_ppn', $fuPpn, $editable ? '' : 'disabled'); ?></div>
                <div><label>Total Biaya Sewa + PPN 12%</label><?php skp_input_rp('d_grand', $fuGrand, $editable ? '' : 'disabled'); ?></div>
                <div class="wide"><label>Terbilang</label><input name="d_terbilang" id="fu-terbilang" value="<?= h($detail['terbilang'] ?? '') ?>" placeholder="terisi otomatis dari total di atas" <?= $editable ? '' : 'disabled' ?>></div>
            </div>
            <p class="help" id="fu-cek-hitung" style="margin-top:6px"></p>
            <p class="help" style="margin-top:6px">Tercetak di surat: <strong id="fu-baris"><?= h($nf($amt['total']) . ' + ' . $nf($fuPpn) . ' = Rp. ' . $nf($fuGrand) . ',-') ?></strong></p>
            <?php else: ?>
            <h3>Rincian Pembayaran</h3>
            <div class="form-grid">
                <div><label>Biaya Sewa / m² / hari</label><input value="<?= money($amt['rate_m_day']) ?>" disabled></div>
                <?php /* Biaya listrik ikut dari Surat Penawaran — ditampilkan terpisah
                         supaya jelas dari mana angka totalnya. */ ?>
                <?php if (($amt['listrik'] ?? 0) > 0): ?>
                <div><label>Nilai Sewa</label><input value="<?= money($amt['sewa']) ?>" disabled></div>
                <div><label>PPN 12% Sewa</label><input value="<?= money($amt['ppn_sewa'] ?? 0) ?>" disabled></div>
                <div><label>Biaya Listrik <span class="muted" style="font-weight:400">(<?= (float) ($src['electricity'] ?? 0) > 0 ? 'dari penawaran' : 'dicatat di dokumen ini' ?>)</span></label><input value="<?= money($amt['listrik']) ?>" disabled></div>
                <div><label>PPN 12% Listrik</label><input value="<?= money($amt['ppn_listrik'] ?? 0) ?>" disabled></div>
                <?php endif; ?>
                <div><label>Total Biaya Sewa</label><input id="skp-total" value="<?= money($amt['total']) ?>" disabled></div>
                <div><label>PPN 12% (×11/12)</label><input id="skp-ppn" value="<?= money($amt['ppn']) ?>" disabled></div>
                <div><label>Total Setelah PPN</label><input id="skp-after" value="<?= money($amt['after_ppn']) ?>" disabled></div>
                <?php /* Bawaannya tercentang. Dilepas hanya untuk penyewa yang
                         harganya memang bersih tanpa PPN. */ ?>
                <div class="wide" style="border-top:1px dashed var(--border,#e2e8f0);padding-top:10px;margin-top:2px">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400">
                        <input type="checkbox" name="ppn_flag" id="skp_ppn" value="1" style="width:16px;height:16px;flex:none;margin:0" <?= $defPpn ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?>>
                        <span><strong>Kenakan PPN 12%</strong> <span class="muted">&mdash; lepas centang bila harganya bersih; baris PPN tidak dicetak di dokumen</span></span>
                    </label>
                    <?php /* Service Charge: ditagih per bulan, tercetak sebagai blok
                             tersendiri di dokumen. Bawaannya tidak dikenakan. */ ?>
                    <label style="display:flex;align-items:center;gap:8px;margin-top:9px;cursor:pointer;font-weight:400">
                        <input type="checkbox" name="sc_flag" id="skp_sc" value="1" style="width:16px;height:16px;flex:none;margin:0" <?= $defSc ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?>>
                        <span><strong>Kenakan Service Charge</strong> <span class="muted">&mdash; ditagih per bulan, tercetak sebagai blok C di dokumen</span></span>
                    </label>
                    <div id="skp_sc_box" class="form-grid" style="margin-top:8px">
                        <div>
                            <label>Biaya Service Charge / Bulan</label>
                            <div style="display:flex;align-items:stretch">
                                <span style="display:flex;align-items:center;padding:0 10px;background:#f1f5f9;border:1px solid var(--border,#e2e8f0);border-right:none;border-radius:8px 0 0 8px;font-size:13px;font-weight:700;color:#475569">Rp</span>
                                <input type="text" inputmode="numeric" id="skp_sc_rp" value="<?= $defScRp > 0 ? number_format($defScRp, 0, ',', '.') : '' ?>" placeholder="0"
                                       style="border-top-left-radius:0;border-bottom-left-radius:0;flex:1;min-width:0;text-align:right" <?= $editable ? '' : 'disabled' ?>>
                                <input type="hidden" name="sc_monthly" id="skp_sc_val" value="<?= (int) $defScRp ?>">
                            </div>
                            <div class="help" id="skp_sc_info"></div>
                        </div>
                        <div>
                            <label>Masa Sewa</label>
                            <input value="<?= (int) $scBulan ?> bulan" disabled>
                            <div class="help">Dihitung dari periode sewa &mdash; dipakai sebagai pengali Service Charge.</div>
                        </div>
                    </div>
                </div>
                <div>
                    <label>Jaminan Area / Security Deposit</label>
                    <input name="deposit_amount" class="skp-dep-fmt" value="<?= $defDeposit > 0 ? number_format($defDeposit, 0, ',', '.') : '' ?>" inputmode="numeric" placeholder="0" <?= $editable ? '' : 'disabled' ?>>
                    <input type="hidden" name="deposit_raw" class="skp-dep-val" value="<?= (int)$defDeposit ?>">
                    <?php /* Perpanjangan: deposit sudah disetor di kontrak sebelumnya, jadi
                             tetap tercetak di surat tapi tidak ditagih ulang. */ ?>
                    <label style="display:flex;align-items:center;gap:7px;margin-top:7px;cursor:pointer;font-weight:400">
                        <input type="checkbox" name="deposit_paid" id="skp_dep_paid" value="1" style="width:15px;height:15px;flex:none;margin:0" <?= $defDepPaid ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?>>
                        <span style="color:#166534;font-weight:600">Sudah dibayarkan</span>
                    </label>
                    <div class="help" id="skp_dep_help"></div>
                </div>
                <?php
                /* Biaya listrik milik dokumen ini. Untuk SKP dari Surat Penawaran
                   listriknya sudah ikut dari sana (ditampilkan di atas, read-only);
                   blok ini untuk SKP perpanjangan yang tidak lewat penawaran. */
                $lDari  = (float) ($src['electricity'] ?? 0);
                $lOn    = $skp ? !empty($skp['electricity_flag']) : false;
                $lTarif = (float) ($skp['electricity_monthly'] ?? 0) ?: 150000;
                $lUnit  = (int) ($skp['electricity_units'] ?? 0);
                $lTotal = (float) ($skp['electricity_amount'] ?? 0);
                ?>
                <?php if ($lDari <= 0): ?>
                <div class="wide" style="border-top:1px dashed var(--border,#e2e8f0);padding-top:10px;margin-top:2px">
                    <label style="display:flex;align-items:center;gap:7px;cursor:pointer">
                        <input type="checkbox" name="electricity_flag" id="skp_listrik_on" value="1" style="width:16px;height:16px;flex:none;margin:0" <?= $lOn ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?>>
                        Kenakan <strong>Biaya Listrik</strong> pada dokumen ini
                    </label>
                    <div id="skp_listrik_box" class="form-grid" style="margin-top:8px">
                        <div>
                            <label>Biaya Listrik Per Bulan</label>
                            <div style="display:flex;align-items:stretch">
                                <span style="display:flex;align-items:center;padding:0 10px;background:#f1f5f9;border:1px solid var(--border,#e2e8f0);border-right:none;border-radius:8px 0 0 8px;font-size:13px;font-weight:700;color:#475569">Rp</span>
                                <input type="text" inputmode="numeric" id="skp_listrik_tarif" value="<?= number_format($lTarif, 0, ',', '.') ?>"
                                       style="border-top-left-radius:0;border-bottom-left-radius:0;flex:1;min-width:0;text-align:right" <?= $editable ? '' : 'disabled' ?>>
                                <input type="hidden" name="electricity_monthly" id="skp_listrik_tarif_val" value="<?= (int) $lTarif ?>">
                            </div>
                        </div>
                        <div>
                            <label>Jumlah Satuan <span class="muted" style="font-weight:400">(1 satuan = 30 hari)</span></label>
                            <select name="electricity_units" id="skp_listrik_unit" <?= $editable ? '' : 'disabled' ?>>
                                <option value="0" <?= $lUnit <= 0 ? 'selected' : '' ?>>Otomatis — ikut lama sewa</option>
                                <?php for ($u = 1; $u <= 12; $u++): ?>
                                <option value="<?= $u ?>" <?= $lUnit === $u ? 'selected' : '' ?>><?= $u ?> ×</option>
                                <?php endfor; ?>
                            </select>
                            <div class="help" id="skp_listrik_unit_info"></div>
                        </div>
                        <div>
                            <label>Total Biaya Listrik <span class="muted" style="font-weight:400">(sebelum PPN)</span></label>
                            <div style="display:flex;align-items:stretch">
                                <span style="display:flex;align-items:center;padding:0 10px;background:#f1f5f9;border:1px solid var(--border,#e2e8f0);border-right:none;border-radius:8px 0 0 8px;font-size:13px;font-weight:700;color:#475569">Rp</span>
                                <input type="text" inputmode="numeric" id="skp_listrik_total" value="<?= $lTotal > 0 ? number_format($lTotal, 0, ',', '.') : '' ?>" placeholder="otomatis"
                                       style="border-top-left-radius:0;border-bottom-left-radius:0;flex:1;min-width:0;text-align:right;font-weight:700" <?= $editable ? '' : 'disabled' ?>>
                                <input type="hidden" name="electricity_amount" id="skp_listrik_total_val" value="<?= (int) $lTotal ?>">
                            </div>
                            <div class="help" id="skp_listrik_info"></div>
                        </div>
                    </div>
                    <p class="help" style="margin-top:4px">Angka di Rincian Pembayaran di atas langsung ikut berubah.</p>
                </div>
                <?php endif; ?>
                <div><label>Grand Total (estimasi)</label><input id="skp-grand" value="<?= money($amt['grand_total']) ?>" disabled></div>
            </div>
            <?php endif; ?>

            <?php /* Pembagian income: satu dokumen sering dikerjakan beberapa sales.
                     Jumlah pembagian WAJIB pas dengan nilai kontrak — kurang atau
                     lebih sedikit pun membuat laporan per PIC tidak lagi berjumlah
                     sama dengan pendapatan properti. */ ?>
            <?php $paketSrc = !empty($src['is_bundle']); ?>
            <?php if ($paketSrc): ?>
            <h3>Pembagian Income per PIC</h3>
            <p class="help">Dokumen ini berasal dari <strong>Penawaran Paket</strong> — nilainya terpecah ke beberapa transaksi komponen, sehingga pembagian per PIC diisi di halaman <strong>Alokasi</strong> masing-masing komponen setelah dokumen disetujui.</p>
            <?php else: ?>
            <h3>Pembagian Income per PIC <span style="font-weight:400;font-size:12px;color:var(--muted)">(opsional — kosongkan bila seluruhnya milik <?= h($src['pic_name'] ?: 'PIC dokumen') ?>)</span></h3>
            <div id="bagi-box" data-acuan="<?= (int) round($nilaiAcuan) ?>">
                <table class="data" id="bagi-tabel" style="width:100%;max-width:720px">
                    <thead><tr>
                        <th style="width:52%">PIC Penerima</th>
                        <th style="width:38%">Nominal</th>
                        <th style="width:10%"></th>
                    </tr></thead>
                    <tbody>
                    <?php $barisBagi = $bagi ?: [['pic_name' => '', 'amount' => '']]; ?>
                    <?php foreach ($barisBagi as $i => $b): ?>
                    <tr>
                        <td>
                            <select name="bagi_pic[]" <?= $editable ? '' : 'disabled' ?>>
                                <option value="">— pilih PIC —</option>
                                <?php foreach ($picAktif as $pRow): $pn = $pRow['name']; ?>
                                <option value="<?= h($pn) ?>" data-lapor="<?= $pRow['di_laporan'] ? '1' : '' ?>" <?= ($b['pic_name'] ?? '') === $pn ? 'selected' : '' ?>><?= h($pn) ?><?= $pRow['di_laporan'] ? '' : ' — tidak tampil di Laporan PIC' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <div style="display:flex;align-items:stretch">
                                <span style="display:flex;align-items:center;padding:0 9px;background:#f1f5f9;border:1px solid var(--border,#e2e8f0);border-right:none;border-radius:8px 0 0 8px;font-size:12.5px;font-weight:700;color:#475569">Rp</span>
                                <input type="text" inputmode="numeric" class="bagi-nilai" value="<?= (float) ($b['amount'] ?? 0) > 0 ? number_format((float) $b['amount'], 0, ',', '.') : '' ?>"
                                       style="border-top-left-radius:0;border-bottom-left-radius:0;flex:1;min-width:0;text-align:right" <?= $editable ? '' : 'disabled' ?>>
                                <input type="hidden" name="bagi_nominal[]" value="<?= (int) ($b['amount'] ?? 0) ?>">
                            </div>
                        </td>
                        <td style="text-align:center"><?php if ($editable): ?><button type="button" class="btn warn bagi-hapus" style="padding:4px 9px;font-size:12px">×</button><?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($editable): ?>
                <p style="margin:8px 0 0"><button type="button" class="btn light" id="bagi-tambah" style="font-size:12.5px">+ Tambah PIC</button></p>
                <?php endif; ?>
                <div id="bagi-ringkas" style="margin-top:9px;font-size:13px"></div>
            </div>
            <?php endif; ?>

            <h3>Catatan Internal</h3>
            <textarea name="note" rows="2" <?= $editable ? '' : 'disabled' ?>><?= h($skp['note'] ?? '') ?></textarea>

            <?php if ($editable): ?>
            <p class="help" style="margin-top:16px;color:#92400e">Submit untuk approval hanya bisa setelah <strong>Scan KTP</strong> dan <strong>Scan NPWP</strong> ada. Untuk client yang pernah dibuatkan dokumen, scan lamanya otomatis dipakai ulang (centang <strong>Pakai ulang</strong> sudah aktif) &mdash; tidak perlu unggah lagi.<br>
                <strong>Bukti Transfer</strong> tidak diunggah di sini &mdash; tempatnya di <strong>Permintaan Kontrak</strong>, sebelum berkas dikirim ke Legal.</p>
            <p class="help" style="margin-top:6px">Tombol <strong>Cetak / Simpan PDF</strong> baru muncul setelah manager menyetujui &mdash; saat itulah nomor dokumen terbit dan isinya dikunci. Sebelum disetujui, isian di halaman ini yang jadi acuannya.</p>
            <p class="form-actions" style="margin-top:8px;display:flex;gap:10px;flex-wrap:wrap">
                <button type="submit" onclick="document.getElementById('skp-action').value='save'" class="btn secondary">Simpan Draft</button>
                <button type="submit" onclick="document.getElementById('skp-action').value='submit'" style="background:#0369a1">Simpan & Submit untuk Approval</button>
                <a class="btn secondary" href="?r=skp">Batal</a>
            </p>
            <?php endif; ?>
        </form>

        <?php /* Ganti berkas tanpa menolak dokumen. Formulir di atas terkunci
                 begitu disubmit, padahal scan yang salah cukup diganti — tidak
                 perlu menolak yang membatalkan transaksinya. Form terpisah
                 supaya tidak tersarang di dalam form utama. */ ?>
        <?php if ($skp && !$editable && can('manage_skp')): ?>
        <div class="panel" style="margin-top:12px;border:1px solid #fde68a;background:#fffbeb">
            <h3 style="margin-top:0;color:#92400e">Ganti Berkas Lampiran</h3>
            <p class="help" style="margin-top:0">Dokumen sudah terkunci, tapi <strong>scan</strong>-nya masih boleh diperbaiki &mdash; misalnya KTP yang salah unggah. Nilai, periode dan nomor dokumen tidak ikut berubah.</p>
            <div class="form-grid">
                <?php foreach (['ktp' => 'Scan KTP', 'npwp' => 'Scan NPWP', 'siup' => 'Scan SIUP', 'pengajuan' => 'Pengajuan'] as $gk => $gl):
                    $gAda = $atts[$gk] ?? null; ?>
                <div>
                    <label><?= $gl ?></label>
                    <div style="font-size:12px;margin-bottom:5px">
                        <?= $gAda
                            ? '📎 <a href="' . h(upload_url($gAda['file_path'])) . '" target="_blank">' . h($gAda['original_name'] ?: 'lihat') . '</a>'
                            : '<span class="muted">belum ada</span>' ?>
                    </div>
                    <form method="post" action="?r=skp_attachment_replace" enctype="multipart/form-data" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="id" value="<?= (int) $skp['id'] ?>">
                        <input type="hidden" name="kind" value="<?= h($gk) ?>">
                        <input type="file" name="berkas" accept="image/*,application/pdf" required style="flex:1;min-width:0">
                        <button type="submit" class="btn secondary" style="flex:none">Ganti</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($skp && $skp['status'] === 'submitted' && can('approve_skp')): ?>
        <div class="panel" style="margin-top:12px;border:1px solid #bae6fd;background:#f0f9ff">
            <h3 style="margin-top:0;color:#0369a1">Approval Manager</h3>
            <form method="post" action="?r=skp_approve" style="display:inline">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$skp['id'] ?>">
                <button type="submit" onclick="return confirm('Setujui SKP ini? Nomor SKP akan terbit dan nilai dikunci.')">✓ Setujui</button>
            </form>
            <form method="post" action="?r=skp_reject" style="display:inline-flex;gap:8px;align-items:center;margin-left:10px;flex-wrap:wrap">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$skp['id'] ?>">
                <input name="reject_note" placeholder="Alasan penolakan" style="width:240px;max-width:100%" required>
                <button type="submit" class="btn warn" onclick="return confirm('Tolak dokumen ini?\n\nTransaksinya ikut DIBATALKAN dan alokasi bulanannya dihapus dari laporan.\n\nKalau hanya salah scan KTP/NPWP, jangan ditolak — pakai Ganti Berkas Lampiran.')">✗ Tolak</button>
            </form>
        </div>
        <?php elseif ($skp && $skp['status'] === 'submitted'): ?>
        <div class="panel" style="margin-top:12px;color:var(--muted)">Menunggu persetujuan manager.</div>
        <?php elseif ($skp && in_array($skp['status'], ['approved', 'signed'], true)):
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
            $signUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/?r=skp_sign&token=' . ($skp['sign_token'] ?? '');
            // Nama dokumen di pesan WhatsApp ikut jenisnya — Media memakai
            // "Form Utilities", bukan "Surat Konfirmasi Pameran".
            $dt = (string) ($skp['doc_type'] ?? 'skp');
            $docShort = skp_doc_title($dt) . ' (' . skp_doc_short($dt) . ')';
            $waMsg = "Yth. " . ($skp['cp_name'] ?: 'Bapak/Ibu') . ",\n\n"
                . "Berikut " . $docShort . " No. " . $skp['skp_no'] . " untuk " . ($src['company_name'] ?? '-') . " dari Management e-Walk & Pentacity Mall Balikpapan.\n\n"
                . "Mohon dapat ditinjau dan ditandatangani secara online melalui tautan berikut:\n" . $signUrl . "\n\n"
                . "Tautan ini aman dan khusus untuk Anda. Terima kasih.";
            $waText = rawurlencode($waMsg);
        ?>
        <div class="panel" style="margin-top:12px;background:#f0fdf4;border:1px solid #bbf7d0;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <div><strong style="color:#166534">Disetujui</strong> — No. <strong><?= h($skp['skp_no']) ?></strong> oleh <?= h($skp['approved_by']) ?> (<?= h(substr($skp['approved_at'], 0, 16)) ?>).</div>
            <a class="btn" href="?r=skp_print&id=<?= (int)$skp['id'] ?>" target="_blank">🖨 Cetak / Simpan PDF</a>
            <?php if ($skp['status'] === 'signed' && can('manage_skp')): ?>
            <a class="btn" style="background:#7c3aed;margin-left:auto" href="?r=contract_request_form&skp_id=<?= (int)$skp['id'] ?>">→ Ajukan Kontrak ke Legal</a>
            <?php endif; ?>
        </div>
        <div class="panel" style="margin-top:12px;border:1px solid #bae6fd;background:#f0f9ff">
            <h3 style="margin-top:0;color:#0369a1">Tanda Tangan Customer</h3>
            <?php if ($skp['status'] === 'signed'): ?>
                <?php if (($skp['sign_method'] ?? 'online') === 'wet'): ?>
                <p style="margin:0;color:#166534">✓ <strong>Ditandatangani</strong> (dari dokumen terunggah) a.n. <strong><?= h($skp['sign_name']) ?></strong> pada <?= h(substr($skp['signed_at'], 0, 16)) ?>.
                    <?php if (!empty($skp['signed_doc_path'])): ?> <a class="btn light" href="<?= h(upload_url($skp['signed_doc_path'])) ?>" target="_blank">Lihat Dokumen ber-TTD</a><?php endif; ?></p>
                <?php else: ?>
                <p style="margin:0;color:#166534">✓ <strong>Ditandatangani online</strong> oleh <strong><?= h($skp['sign_name']) ?></strong> pada <?= h(substr($skp['signed_at'], 0, 16)) ?> (IP <?= h($skp['sign_ip']) ?>).</p>
                <?php endif; ?>
            <?php else: ?>
                <p style="margin:0 0 8px;color:#374151"><strong>Opsi A — TTD online.</strong> Kirim tautan ini ke customer. Setelah ditandatangani, dokumen final lengkap dengan TTD.</p>
                <textarea id="skp-wa-msg" style="position:absolute;left:-9999px" readonly><?= h($waMsg) ?></textarea>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <input id="skp-sign-url" value="<?= h($signUrl) ?>" readonly style="flex:1;min-width:260px;font-size:12px" onclick="this.select()">
                    <button type="button" class="btn light" onclick="claraCopyText(document.getElementById('skp-sign-url').value,this,'Tersalin ✓')">Salin Link</button>
                    <button type="button" class="btn light" onclick="claraCopyText(document.getElementById('skp-wa-msg').value,this,'Pesan tersalin ✓')">Salin Pesan</button>
                    <a class="btn" style="background:#16a34a" target="_blank" href="https://wa.me/?text=<?= $waText ?>">Kirim via WhatsApp</a>
                </div>
                <p style="margin:8px 0 0;font-size:11.5px;color:#64748b">Tautan bersifat rahasia &amp; berlaku sampai dokumen ditandatangani. <strong>Jika lewat WhatsApp Desktop hanya link yang terkirim</strong>, gunakan <strong>Salin Pesan</strong> lalu tempel (paste) di chat — teks lengkap akan ikut.</p>
                <hr style="margin:14px 0;border:none;border-top:1px dashed #bae6fd">
                <p style="margin:0 0 8px;color:#374151"><strong>Opsi B — unggah dokumen yang sudah ditandatangani.</strong> Kirim/cetak dokumennya, minta customer menandatangani, lalu unggah kembali <strong>PDF</strong> (atau foto/scan) yang sudah ber-TTD di sini. Hasilnya sama dengan Opsi A.</p>
                <form method="post" action="?r=skp_sign_upload" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end" onsubmit="return confirm('Tandai dokumen ini sudah ditandatangani sesuai berkas yang diunggah? Status menjadi final.')">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$skp['id'] ?>">
                    <div><label style="font-size:12px;font-weight:700;display:block">Nama Penanda Tangan</label><input name="sign_name" required placeholder="Nama customer" value="<?= h($skp['cp_name'] ?? '') ?>" style="min-width:200px"></div>
                    <div><label style="font-size:12px;font-weight:700;display:block">Dokumen sudah ber-TTD (pdf/foto/jpg/png, ≤8MB)</label><input type="file" name="signed_doc" accept="image/*,.pdf" required></div>
                    <button type="submit" class="btn" style="background:#0369a1">Unggah &amp; Tandai TTD</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <script>
        // ── Biaya listrik pada dokumen ini ──────────────────────────────────
        // Pola sama dengan Surat Penawaran: tarif × satuan, boleh dipilih
        // satuannya, dan Total yang diketik sendiri menang atas hitungan.
        (function () {
            var on = document.getElementById('skp_listrik_on');
            if (!on) return;
            var box = document.getElementById('skp_listrik_box'),
                tarif = document.getElementById('skp_listrik_tarif'), tarifVal = document.getElementById('skp_listrik_tarif_val'),
                unit = document.getElementById('skp_listrik_unit'), unitInfo = document.getElementById('skp_listrik_unit_info'),
                tot = document.getElementById('skp_listrik_total'), totVal = document.getElementById('skp_listrik_total_val'),
                info = document.getElementById('skp_listrik_info'),
                HARI = <?= (int) $days ?>;
            if (tot && tot.value.replace(/\D/g, '') !== '') tot.dataset.manual = '1';
            function ang(el) { return el ? parseInt((el.value || '').replace(/\D/g, ''), 10) || 0 : 0; }
            function rp(x) { return 'Rp ' + (x || 0).toLocaleString('id-ID'); }
            function satuanOtomatis() { return HARI > 0 ? Math.max(1, Math.round(HARI / 30)) : 1; }
            function gambar() {
                box.style.display = on.checked ? '' : 'none';
                if (!on.checked) { tarifVal.value = 0; totVal.value = 0; return; }
                var pilih = parseInt(unit.value, 10) || 0;
                var n = pilih > 0 ? pilih : satuanOtomatis();
                var t = ang(tarif);
                tarifVal.value = t;
                var manual = tot.dataset.manual === '1', isiManual = manual ? ang(tot) : 0;
                if (!manual) tot.value = (n * t).toLocaleString('id-ID');
                var dipakai = isiManual > 0 ? isiManual : n * t;
                totVal.value = isiManual > 0 ? isiManual : 0;
                unit.disabled = isiManual > 0;
                var ppn = Math.round(dipakai * 11 / 12 * 0.12);
                if (unitInfo) unitInfo.textContent = isiManual > 0 ? 'Diabaikan — Total diisi sendiri.'
                    : (pilih > 0 ? 'Dipilih sendiri. Otomatisnya ' + satuanOtomatis() + ' ×.' : HARI + ' hari → ' + n + ' ×');
                if (info) {
                    info.innerHTML = (isiManual > 0 ? 'Diisi sendiri, ' : n + ' × ' + rp(t) + ' · ')
                        + '<b>belum termasuk PPN</b> · PPN 12% ' + rp(ppn) + ' · dibayar ' + rp(dipakai + ppn)
                        + (isiManual > 0 ? ' — <a href="#" id="skp_listrik_auto">pakai hitungan otomatis</a>' : '');
                    var lk = document.getElementById('skp_listrik_auto');
                    if (lk) lk.addEventListener('click', function (e) { e.preventDefault(); tot.dataset.manual = ''; gambar(); });
                }
            }
            [tarif, tot].forEach(function (el) {
                if (!el) return;
                el.addEventListener('input', function () {
                    var raw = this.value.replace(/\D/g, '');
                    this.value = raw ? parseInt(raw, 10).toLocaleString('id-ID') : '';
                    if (this === tot) this.dataset.manual = '1';
                    gambar();
                });
            });
            if (tot) tot.addEventListener('blur', function () {
                if (this.value.replace(/\D/g, '') === '') { this.dataset.manual = ''; gambar(); }
            });
            on.addEventListener('change', gambar);
            unit.addEventListener('change', gambar);
            gambar();

            var _gambar = gambar;
            gambar = function () { _gambar(); if (window.claraHitungRincian) window.claraHitungRincian(); };
            [on, unit, tarif, tot].forEach(function (el) {
                if (!el) return;
                function ulang() { if (window.claraHitungRincian) window.claraHitungRincian(); }
                el.addEventListener('change', ulang); el.addEventListener('input', ulang);
            });
        })();

        // ── Rincian Pembayaran ikut berubah tanpa perlu simpan dulu ─────────
        // Blok ini berdiri sendiri (tidak menempel ke blok listrik) supaya
        // Grand Total tetap hidup walau listriknya ikut dari Surat Penawaran.
        // DASAR sudah termasuk listrik dari penawaran (bila ada); yang
        // ditambahkan di sini hanya listrik milik dokumen ini.
        (function () {
            var elTot = document.getElementById('skp-total');
            if (!elTot) return;
            var DASAR = <?= (int) round((float) $total) ?>,
                L_OFFER = <?= (int) round((float) ($src['electricity'] ?? 0)) ?>;
            function rupiah(x) { return 'Rp ' + Math.round(x || 0).toLocaleString('id-ID'); }
            function listrikDokumen() {
                var on = document.getElementById('skp_listrik_on');
                if (!on || !on.checked) return 0;
                var hid = document.getElementById('skp_listrik_total_val'),
                    tx = document.getElementById('skp_listrik_total');
                return (hid && parseInt(hid.value, 10)) ||
                       (tx ? parseInt((tx.value || '').replace(/\D/g, ''), 10) || 0 : 0);
            }
            function hitungRincian() {
                var elPpn = document.getElementById('skp-ppn'),
                    elAft = document.getElementById('skp-after'),
                    elGr = document.getElementById('skp-grand'),
                    lunas = document.getElementById('skp_dep_paid'),
                    bantu = document.getElementById('skp_dep_help');
                var lSkp = listrikDokumen(), total = DASAR + lSkp, lAll = L_OFFER + lSkp, sewa = total - lAll;
                var elPpnOn = document.getElementById('skp_ppn');
                var kenaPpn = !elPpnOn || elPpnOn.checked;
                var ppn = kenaPpn ? (Math.round(sewa * 11 / 12 * 0.12) + Math.round(lAll * 11 / 12 * 0.12)) : 0;
                var dep = parseInt((document.querySelector('.skp-dep-val') || {}).value || 0, 10) || 0;
                var scOn = document.getElementById('skp_sc'), scTx = document.getElementById('skp_sc_rp'),
                    scVal = document.getElementById('skp_sc_val'), scBox = document.getElementById('skp_sc_box'),
                    scInfo = document.getElementById('skp_sc_info'), SC_BULAN = <?= (int) $scBulan ?>;
                var scPakai = 0, scPpn = 0, scTotal = 0;
                if (scOn) {
                    if (scBox) scBox.style.display = scOn.checked ? '' : 'none';
                    scPakai = scOn.checked ? (parseInt((scTx.value || '').replace(/\D/g, ''), 10) || 0) : 0;
                    if (scVal) scVal.value = scPakai;
                    scPpn = kenaPpn ? Math.round(scPakai * 11 / 12 * 0.12) : 0;
                    scTotal = (scPakai + scPpn) * SC_BULAN;
                    if (scInfo) scInfo.innerHTML = scPakai <= 0 ? ''
                        : (kenaPpn ? ('PPN ' + rupiah(scPpn) + ' · ' + rupiah(scPakai + scPpn) + ' per bulan · ')
                                   : (rupiah(scPakai) + ' per bulan · '))
                          + '<b>' + SC_BULAN + ' bulan = ' + rupiah(scTotal) + '</b>';
                }
                var sudah = !!(lunas && lunas.checked);
                elTot.value = rupiah(total);
                if (elPpn) elPpn.value = rupiah(ppn);
                if (elAft) elAft.value = rupiah(total + ppn);
                if (elGr) elGr.value = rupiah(total + ppn + scTotal + (sudah ? 0 : dep));
                if (bantu) {
                    bantu.innerHTML = dep <= 0 ? ''
                        : (sudah
                            ? '<span style="color:#166534">Tercetak di SKP sebagai <b>' + rupiah(dep) + ' (Sudah Dibayarkan)</b> — tidak menambah Grand Total.</span>'
                            : 'Ditagih pada kontrak ini — ikut menambah Grand Total.');
                }
            }
            window.claraHitungRincian = hitungRincian;
            var cb = document.getElementById('skp_dep_paid');
            if (cb) cb.addEventListener('change', hitungRincian);
            var cbPpn = document.getElementById('skp_ppn');
            if (cbPpn) cbPpn.addEventListener('change', hitungRincian);
            var cbSc = document.getElementById('skp_sc'), txSc = document.getElementById('skp_sc_rp');
            if (cbSc) cbSc.addEventListener('change', hitungRincian);
            if (txSc) txSc.addEventListener('input', function () {
                var raw = this.value.replace(/\D/g, '');
                this.value = raw ? parseInt(raw, 10).toLocaleString('id-ID') : '';
                hitungRincian();
            });
            hitungRincian();
        })();
        (function () {
            var dep = document.querySelector('.skp-dep-fmt'), hid = document.querySelector('.skp-dep-val');
            if (dep) {
                dep.addEventListener('input', function () {
                    var raw = this.value.replace(/\D/g, '');
                    this.value = raw ? parseInt(raw, 10).toLocaleString('id-ID') : '';
                    if (hid) hid.value = raw;
                    if (typeof claraHitungRincian === 'function') claraHitungRincian();
                });
            }
            var f = dep ? dep.closest('form') : null;
            if (f) f.addEventListener('submit', function () { if (dep && hid) hid.value = (dep.value || '').replace(/\D/g, ''); });
        })();
        </script>
        <script>
        // ── Pembagian income per PIC ────────────────────────────────────────
        // Jumlahnya WAJIB pas dengan nilai kontrak. Tombol "Submit untuk
        // Approval" ditahan bila belum pas; "Simpan Draft" tetap boleh lewat
        // supaya pekerjaan setengah jadi tidak hilang.
        (function () {
            var box = document.getElementById('bagi-box');
            if (!box) return;
            var ACUAN = parseInt(box.dataset.acuan, 10) || 0;
            var tabel = document.getElementById('bagi-tabel');
            var ringkas = document.getElementById('bagi-ringkas');
            function rp(x) { return 'Rp ' + Math.round(x || 0).toLocaleString('id-ID'); }
            function angka(el) { return parseInt((el.value || '').replace(/\D/g, ''), 10) || 0; }

            function hitung() {
                var total = 0, terisi = 0, pakai = [], luarLaporan = [];
                tabel.querySelectorAll('tbody tr').forEach(function (tr) {
                    var pic = tr.querySelector('select'), nilai = tr.querySelector('.bagi-nilai'),
                        hid = tr.querySelector('input[type=hidden]');
                    var n = angka(nilai);
                    if (hid) hid.value = n;
                    if (pic && pic.value && n > 0) {
                        total += n; terisi++; pakai.push(pic.value);
                        var op = pic.options[pic.selectedIndex];
                        if (op && !op.dataset.lapor) luarLaporan.push(pic.value);
                    }
                });
                var selisih = ACUAN - total;
                var ganda = pakai.length !== new Set(pakai).size;
                var pesan = '';
                if (terisi === 0) {
                    pesan = '<span class="muted">Belum dibagi — seluruh nilai tercatat atas nama PIC dokumen.</span>';
                } else if (ganda) {
                    pesan = '<span style="color:#b91c1c;font-weight:700">Ada PIC yang dipilih dua kali.</span>';
                } else if (selisih === 0) {
                    pesan = '<span style="color:#15803d;font-weight:700">✓ Pas: ' + rp(total) + ' dari ' + rp(ACUAN) + '</span>';
                } else if (selisih > 0) {
                    pesan = '<span style="color:#b45309;font-weight:700">Kurang ' + rp(selisih) + '</span>'
                          + ' <span class="muted">— terbagi ' + rp(total) + ' dari ' + rp(ACUAN) + '</span>';
                } else {
                    pesan = '<span style="color:#b91c1c;font-weight:700">Lebih ' + rp(-selisih) + '</span>'
                          + ' <span class="muted">— terbagi ' + rp(total) + ' dari ' + rp(ACUAN) + '</span>';
                }
                // Bukan penghalang submit: PIC-nya sah, hanya memang tidak
                // dihitung di tabel achievement. Tapi harus terlihat.
                if (luarLaporan.length) {
                    pesan += '<div style="margin-top:5px;color:#92400e">Catatan: <strong>' + luarLaporan.join(', ')
                           + '</strong> tidak muncul di Laporan PIC (pengaturan master PIC). Nilainya tetap masuk pendapatan properti.</div>';
                }
                ringkas.innerHTML = pesan;
                box.dataset.sah = (terisi === 0 || (selisih === 0 && !ganda)) ? '1' : '';
                return box.dataset.sah === '1';
            }

            tabel.addEventListener('input', function (e) {
                if (e.target.classList.contains('bagi-nilai')) {
                    var raw = e.target.value.replace(/\D/g, '');
                    e.target.value = raw ? parseInt(raw, 10).toLocaleString('id-ID') : '';
                }
                hitung();
            });
            tabel.addEventListener('change', hitung);
            tabel.addEventListener('click', function (e) {
                if (!e.target.classList.contains('bagi-hapus')) return;
                var tr = e.target.closest('tr');
                if (tabel.querySelectorAll('tbody tr').length > 1) tr.remove();
                else { tr.querySelector('select').value = ''; tr.querySelector('.bagi-nilai').value = ''; }
                hitung();
            });
            var tambah = document.getElementById('bagi-tambah');
            if (tambah) tambah.addEventListener('click', function () {
                var tb = tabel.querySelector('tbody');
                var baru = tb.rows[0].cloneNode(true);
                baru.querySelector('select').value = '';
                baru.querySelector('.bagi-nilai').value = '';
                baru.querySelector('input[type=hidden]').value = '';
                tb.appendChild(baru);
                hitung();
            });

            // Penjaga submit: hanya menahan tombol Submit untuk Approval.
            var form = box.closest('form');
            if (form) form.addEventListener('submit', function (e) {
                var aksi = document.getElementById('skp-action');
                if (!aksi || aksi.value !== 'submit') return;
                if (hitung()) return;
                e.preventDefault();
                e.stopImmediatePropagation();
                ringkas.scrollIntoView({ behavior: 'smooth', block: 'center' });
                alert('Pembagian income belum pas dengan nilai kontrak.\n\n'
                    + ringkas.textContent.trim()
                    + '\n\nPerbaiki dulu, atau simpan sebagai draft.');
            }, true);

            hitung();
        })();
        </script>
        <?php
    });
}

// ─── Simpan (insert/update draft) ────────────────────────────────────────────
/** Simpan lampiran upload (folder) → skp_attachments. Ganti file kind yg sama. */
function _skp_handle_uploads(PDO $pdo, int $skpId, string $uname, int $clientId = 0): void
{
    $dir = dirname(__DIR__, 2) . '/public/uploads/skp';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $kinds = ['att_ktp' => 'ktp', 'att_npwp' => 'npwp', 'att_siup' => 'siup', 'att_bukti_transfer' => 'bukti_transfer', 'att_pengajuan' => 'pengajuan'];
    $uploaded = [];
    foreach ($kinds as $field => $kind) {
        if (empty($_FILES[$field]['tmp_name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) continue;
        $f = $_FILES[$field];
        if ($f['size'] <= 0 || $f['size'] > 5 * 1024 * 1024) continue;
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) continue;
        $fname = 'skp' . $skpId . '_' . $kind . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) continue;
        $pdo->prepare('DELETE FROM skp_attachments WHERE skp_id=? AND kind=?')->execute([$skpId, $kind]);
        $pdo->prepare('INSERT INTO skp_attachments (skp_id, kind, file_path, original_name, uploaded_by) VALUES (?,?,?,?,?)')
            ->execute([$skpId, $kind, 'uploads/skp/' . $fname, substr((string) $f['name'], 0, 190), $uname]);
        $uploaded[$kind] = true;
    }
    // Pakai-ulang scan KTP/NPWP dari dokumen client sebelumnya (referensi file sama).
    $reuse = $clientId > 0 ? _skp_reusable_attachments($pdo, $clientId, $skpId) : [];
    foreach (['ktp', 'npwp', 'siup'] as $kind) {
        if (!empty($uploaded[$kind]) || empty($_POST['reuse_' . $kind]) || empty($reuse[$kind])) continue;
        $exists = $pdo->prepare('SELECT 1 FROM skp_attachments WHERE skp_id=? AND kind=?');
        $exists->execute([$skpId, $kind]);
        if ($exists->fetchColumn()) continue;
        $pdo->prepare('INSERT INTO skp_attachments (skp_id, kind, file_path, original_name, uploaded_by) VALUES (?,?,?,?,?)')
            ->execute([$skpId, $kind, $reuse[$kind]['file_path'], $reuse[$kind]['original_name'], $uname . ' (reuse)']);
    }
}

/**
 * Lampiran KTP/NPWP yang bisa dipakai-ulang dari dokumen client yang sama
 * sebelumnya (hemat: tak perlu scan ulang). Return [kind => {file_path, original_name}].
 */
function _skp_reusable_attachments(PDO $pdo, int $clientId, int $excludeSkpId = 0): array
{
    if ($clientId <= 0) return [];
    $st = $pdo->prepare(
        "SELECT a.kind, a.file_path, a.original_name
         FROM skp_attachments a
         JOIN skp_documents d ON d.id = a.skp_id
         LEFT JOIN offers o       ON o.id = d.offer_id
         LEFT JOIN transactions t ON t.id = d.transaction_id
         WHERE a.kind IN ('ktp','npwp','siup')
           AND COALESCE(o.client_id, t.client_id, d.client_id) = ?
           AND d.id <> ?
         ORDER BY a.id DESC"
    );
    $st->execute([$clientId, $excludeSkpId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        if (!isset($out[$r['kind']])) $out[$r['kind']] = $r; // ambil terbaru per kind
    }
    return $out;
}

/** Daftar lampiran terunggah utk snapshot (kind + nama file). */
function _skp_attachment_list(PDO $pdo, int $skpId): array
{
    $st = $pdo->prepare('SELECT kind, original_name FROM skp_attachments WHERE skp_id = ? ORDER BY id');
    $st->execute([$skpId]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['kind']] = $r['original_name'];
    return $out;
}

/** Auto-update KTP/NPWP/SIUP ke master client (reusable berikutnya). */
function _skp_update_master(PDO $pdo, int $clientId, ?string $ktp, ?string $npwp, ?string $siup = null): void
{
    if (!$clientId) return;
    $sets = []; $vals = [];
    if ($ktp !== null)  { $sets[] = 'ktp=?';  $vals[] = $ktp; }
    if ($npwp !== null) { $sets[] = 'npwp=?'; $vals[] = $npwp; }
    if ($siup !== null) { $sets[] = 'siup=?'; $vals[] = $siup; }
    if (!$sets) return;
    $vals[] = $clientId;
    $pdo->prepare('UPDATE master_clients SET ' . implode(',', $sets) . ' WHERE id=?')->execute($vals);
}

/** Lampiran wajib sebelum submit approval. Return label yang BELUM ada. */
/**
 * Pembagian income dari formulir: [['pic' => ..., 'amount' => ...], ...].
 * Baris tanpa PIC atau bernilai 0 dibuang; PIC ganda digabung jadi satu.
 */
function _skp_bagi_dari_post(PDO $pdo = null, int $pid = 0): array
{
    $pic = (array) ($_POST['bagi_pic'] ?? []);
    $rp  = (array) ($_POST['bagi_nominal'] ?? []);
    $out = [];
    foreach ($pic as $i => $nama) {
        $nama = trim((string) $nama);
        $n = (float) preg_replace('/\D/', '', (string) ($rp[$i] ?? '0'));
        if ($nama === '' || $n <= 0) continue;
        $out[$nama] = ($out[$nama] ?? 0) + $n;
    }
    $hasil = [];
    foreach ($out as $nama => $n) $hasil[] = ['pic' => $nama, 'amount' => $n];
    if ($pdo && $pid && $hasil) {
        $st = $pdo->prepare("SELECT name FROM master_pic WHERE property_id = ? AND status = 'active'");
        $st->execute([$pid]);
        $sah = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($hasil as &$b) $b['valid'] = in_array($b['pic'], $sah, true);
        unset($b);
    }
    return $hasil;
}

/**
 * Simpan pembagian milik satu dokumen, lalu terapkan ke alokasi transaksinya
 * (bila transaksinya sudah ada) tanpa menghitung ulang nominal per bulan.
 */
function _skp_simpan_bagi(PDO $pdo, int $pid, int $skpId, ?int $trxId, array $bagi, string $uname): void
{
    // Transaksi yang disentuh HARUS transaksi milik dokumen ini di properti ini.
    // Tanpa ini, satu POST bisa menulis ulang alokasi transaksi mana pun.
    if ($trxId) {
        $cek = $pdo->prepare('SELECT t.id FROM transactions t
                               JOIN skp_documents s ON s.transaction_id = t.id
                              WHERE t.id = ? AND t.property_id = ? AND s.id = ? AND s.property_id = ?');
        $cek->execute([$trxId, $pid, $skpId, $pid]);
        if (!$cek->fetchColumn()) $trxId = null;
    }
    // Tanpa pembagian dan sebelumnya juga tidak ada → tidak ada yang perlu
    // disentuh. Penting: menyimpan draft berkali-kali tidak boleh menghapus &
    // menulis ulang alokasi transaksi tanpa alasan.
    $lama = $pdo->prepare('SELECT COUNT(*) FROM transaction_pic_splits WHERE skp_id = ?'
        . ($trxId ? ' OR transaction_id = ?' : ''));
    $lama->execute($trxId ? [$skpId, $trxId] : [$skpId]);
    if (!$bagi && !(int) $lama->fetchColumn()) return;

    // Hapus-lalu-tulis alokasi harus utuh: kalau gagal di tengah, transaksi
    // bisa kehilangan seluruh alokasi bulanannya.
    $sendiri = !$pdo->inTransaction();
    if ($sendiri) $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM transaction_pic_splits WHERE skp_id = ?')->execute([$skpId]);
        if ($trxId) $pdo->prepare('DELETE FROM transaction_pic_splits WHERE transaction_id = ? AND skp_id <> ?')->execute([$trxId, $skpId]);
        if ($bagi) {
            $ins = $pdo->prepare(
                'INSERT INTO transaction_pic_splits (property_id, transaction_id, skp_id, pic_name, amount, created_by)
                 VALUES (?,?,?,?,?,?)'
            );
            foreach ($bagi as $b) $ins->execute([$pid, $trxId ?: null, $skpId, $b['pic'], $b['amount'], $uname]);
        }
        if ($trxId) {
            require_once dirname(__DIR__) . '/AllocationService.php';
            AllocationService::terapkanPembagian($pdo, $trxId);
        }
        if ($sendiri) $pdo->commit();
    } catch (Throwable $e) {
        if ($sendiri && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function _skp_missing_required(PDO $pdo, int $skpId): array
{
    // Bukti Transfer sengaja tidak di sini — lihat catatan di form lampiran.
    $need = ['ktp' => 'Scan KTP', 'npwp' => 'Scan NPWP'];
    $st = $pdo->prepare('SELECT DISTINCT kind FROM skp_attachments WHERE skp_id=?');
    $st->execute([$skpId]);
    $have = $st->fetchAll(PDO::FETCH_COLUMN);
    $missing = [];
    foreach ($need as $k => $lbl) if (!in_array($k, $have, true)) $missing[] = $lbl;
    return $missing;
}

function skp_save(PDO $pdo): void
{
    require_permission('manage_skp');
    verify_csrf();
    $pid     = current_property_id();
    $id      = (int) post('id');
    $trxId   = (int) post('transaction_id');
    $offerId = (int) post('offer_id');
    $docType = in_array(post('doc_type'), ['sks', 'fu'], true) ? (string) post('doc_type') : 'skp';
    // Dokumen Gudang/Media yang berdiri sendiri: sumbernya kolom dokumen ini.
    $standalone = post('standalone') === '1' && $docType !== 'skp';
    $modKind    = $standalone ? skp_doc_module($docType) : '';
    $doSubmit = post('action') === 'submit';

    $sFields = [];
    if ($standalone) {
        $sFields = [
            'module'       => $modKind,
            'client_id'    => (int) post('s_client_id') ?: null,
            'contact_id'   => (int) post('s_contact_id') ?: null,
            'master_code'  => trim((string) post('s_master_code', '')) ?: null,
            'start_date'   => trim((string) post('s_start_date', '')) ?: null,
            'end_date'     => trim((string) post('s_end_date', '')) ?: null,
            'unit_rate'    => parse_rupiah((string) post('s_unit_rate', '0')),
            'total_amount' => parse_rupiah((string) post('s_total_amount', '0')),
            'pic_name'     => trim((string) post('s_pic_name', '')) ?: null,
        ];
        $src = skp_standalone_src($pdo, $pid, $modKind, array_merge($sFields, ['deposit_amount' => post('deposit_raw', 0)]));
    } else {
        $src = $offerId ? _skp_source_from_offer($pdo, $offerId, $pid) : _skp_source($pdo, $trxId, $pid);
    }
    if (!$src) { flash('Sumber (penawaran/transaksi) tidak valid.'); redirect_to($offerId ? 'offers' : 'skp'); }
    $clientId = (int) ($src['client_id'] ?? 0);

    $ktp  = trim((string) post('ktp_pj')) ?: null;
    $npwp = trim((string) post('npwp_no')) ?: null;
    $siup = trim((string) post('siup_no')) ?: null;
    $fields = [
        'cp_name'       => trim((string) post('cp_name')) ?: null,
        'ktp_pj'        => $ktp,
        'phone_pj'      => trim((string) post('phone_pj')) ?: null,
        'seating_area'  => trim((string) post('seating_area', '')) !== '' ? (float) str_replace(',', '.', (string) post('seating_area')) : null,
        'produk'        => trim((string) post('produk')) ?: null,
        'status_sewa'   => post('status_sewa') ?: 'Baru',
        'deposit_amount'=> (float) post('deposit_raw', 0),
        // Sudah disetor di kontrak sebelumnya → dicetak di surat, tapi di luar Grand Total.
        'deposit_paid'  => post('deposit_paid') ? 1 : 0,
        // Harga bersih tanpa PPN untuk penyewa yang pajaknya di luar sistem.
        'ppn_flag'      => post('ppn_flag') ? 1 : 0,
        // Service Charge bulanan (kosong = tidak dikenakan).
        'sc_flag'       => post('sc_flag') ? 1 : 0,
        'sc_monthly'    => parse_rupiah((string) post('sc_monthly', '0')) ?: null,
        // Listrik yang dicatat di dokumen ini (kosong = tidak menagih listrik).
        'electricity_flag'    => post('electricity_flag') ? 1 : 0,
        'electricity_monthly' => parse_rupiah((string) post('electricity_monthly', '0')) ?: null,
        'electricity_units'   => (int) post('electricity_units', 0) > 0 ? min(12, (int) post('electricity_units')) : null,
        'electricity_amount'  => parse_rupiah((string) post('electricity_amount', '0')) ?: null,
        'admin_siup'    => $siup ? 1 : 0,
        'admin_npwp'    => $npwp ? 1 : 0,
        'admin_ktp'     => $ktp ? 1 : 0,
        'note'          => trim((string) post('note')) ?: null,
        'detail_json'   => $docType === 'skp' ? null : json_encode(skp_detail_from_post($docType), JSON_UNESCAPED_UNICODE),
    ];
    $fields = array_merge($fields, $sFields);
    $uname = $_SESSION['user']['name'] ?? 'system';
    // Nomor identitas wajib sebelum submit approval (KTP/NPWP/SIUP).
    $missNum = [];
    if (!$ktp)  $missNum[] = 'Nomor KTP';
    if (!$npwp) $missNum[] = 'Nomor NPWP';
    // Pembagian income harus PAS dengan nilai kontrak — kurang atau lebih
    // sedikit pun membuat laporan per PIC tidak lagi berjumlah sama dengan
    // pendapatan properti. Acuannya nilai sumber, bukan angka yang diketik.
    $bagi = _skp_bagi_dari_post($pdo, $pid);
    // Nama di luar master_pic aktif tidak akan muncul di laporan per PIC —
    // ditolak terang-terangan, bukan diam-diam hilang.
    $picSalah = array_column(array_filter($bagi, fn($b) => isset($b['valid']) && !$b['valid']), 'pic');
    if ($picSalah) $missNum[] = 'PIC tidak dikenal / tidak aktif: ' . implode(', ', $picSalah);
    // Paket: satu dokumen melahirkan beberapa transaksi, pembagian tunggal tidak
    // punya arti — dibuang supaya tidak tersimpan lalu terabaikan diam-diam.
    if ($bagi && !empty($src['is_bundle'])) {
        $bagi = [];
        flash('Pembagian income tidak dipakai pada dokumen Paket — isi di halaman Alokasi tiap komponen.');
    }
    if ($bagi) {
        $acuanBagi = round((float) ($src['final_amount'] ?: $src['total_calculated']));
        $jumlahBagi = round(array_sum(array_column($bagi, 'amount')));
        if ($jumlahBagi !== $acuanBagi) {
            $selisih = $acuanBagi - $jumlahBagi;
            $missNum[] = 'Pembagian income ' . ($selisih > 0 ? 'kurang ' : 'lebih ') . money(abs($selisih))
                . ' (terbagi ' . money($jumlahBagi) . ' dari ' . money($acuanBagi) . ')';
        }
    }

    if ($id) {
        $cur = $pdo->prepare('SELECT status FROM skp_documents WHERE id = ? AND property_id = ?');
        $cur->execute([$id, $pid]);
        $st = $cur->fetchColumn();
        if (!in_array($st, ['draft', 'rejected'], true)) { flash('Sudah disubmit/disetujui — tidak bisa diubah.'); redirect_to('skp_form', ['id' => $id]); }
        // Proses upload dulu agar validasi lampiran wajib akurat.
        _skp_handle_uploads($pdo, $id, $uname, $clientId);
        _skp_update_master($pdo, $clientId, $ktp, $npwp, $siup);
        $blockMsg = '';
        if ($doSubmit && ($miss = array_merge($missNum, _skp_missing_required($pdo, $id)))) {
            $doSubmit = false;
            $blockMsg = 'Belum bisa submit — lengkapi dulu: ' . implode(', ', $miss) . '. Disimpan sebagai draft.';
        }
        $newStatus = $doSubmit ? 'submitted' : 'draft';
        // Kolom yang diperbarui mengikuti $fields — dokumen mandiri otomatis ikut
        // menyimpan client / unit / periode / nilai miliknya sendiri.
        $setCols = implode(', ', array_map(fn($c) => "$c=:$c", array_keys($fields)));
        $sql = 'UPDATE skp_documents SET ' . $setCols . ', status=:status, reject_note=NULL,
                submitted_at=' . ($doSubmit ? 'CURRENT_TIMESTAMP' : 'submitted_at') . ',
                updated_at=CURRENT_TIMESTAMP, updated_by=:uname
                WHERE id=:id AND property_id=:pid';
        $pdo->prepare($sql)->execute(array_merge($fields, [':status' => $newStatus, ':uname' => $uname, ':id' => $id, ':pid' => $pid]));
        _skp_simpan_bagi($pdo, $pid, $id, $trxId ?: null, $bagi, $uname);
        audit($pdo, $doSubmit ? 'submit' : 'update', 'skp_documents', (string) $id, $fields);
        flash($blockMsg ?: ($doSubmit ? 'Dokumen disubmit untuk approval.' : 'Draft disimpan.'));
        redirect_to('skp_form', ['id' => $id]);
    }

    // Idempotensi (anti double-submit): bila penawaran ini sudah punya SKP,
    // jangan buat duplikat — arahkan ke yang sudah ada. Mirror guard di
    // skp_form() (render-path) + dilindungi UNIQUE index (migrasi 036).
    if ($offerId) {
        $dup = $pdo->prepare('SELECT id FROM skp_documents WHERE offer_id = ? AND property_id = ? LIMIT 1');
        $dup->execute([$offerId, $pid]);
        if ($dupId = $dup->fetchColumn()) { redirect_to('skp_form', ['id' => (int) $dupId]); }
    }

    // INSERT baru (offer-based atau legacy transaksi). Selalu draft dulu →
    // setelah lampiran terproses & lolos validasi, baru dipromosikan ke submitted.
    $cols = array_keys($fields);
    $place = array_map(fn($c) => ':' . $c, $cols);
    $sql = 'INSERT INTO skp_documents (property_id, doc_type, offer_id, transaction_id, status, created_by, '
         . implode(', ', $cols) . ')
         VALUES (:pid, :doc, :offer, :trx, \'draft\', :uname, '
         . implode(', ', $place) . ')';
    try {
        $pdo->prepare($sql)->execute(array_merge($fields, [
            ':pid' => $pid, ':doc' => $docType, ':offer' => $offerId ?: null,
            ':trx' => $offerId ? null : ($trxId ?: null), ':uname' => $uname,
        ]));
    } catch (PDOException $e) {
        // Race double-submit yang lolos guard di atas → UNIQUE uniq_skp_offer
        // (migrasi 036) menolak. Tangani anggun: arahkan ke SKP yang sudah ada,
        // jangan biarkan jadi fatal 500.
        if ($offerId && ($e->errorInfo[1] ?? 0) === 1062) {
            $dup = $pdo->prepare('SELECT id FROM skp_documents WHERE offer_id = ? AND property_id = ? LIMIT 1');
            $dup->execute([$offerId, $pid]);
            if ($dupId = $dup->fetchColumn()) { redirect_to('skp_form', ['id' => (int) $dupId]); }
        }
        throw $e;
    }
    $newId = (int) $pdo->lastInsertId();
    _skp_simpan_bagi($pdo, $pid, $newId, $trxId ?: null, $bagi, $uname);
    _skp_handle_uploads($pdo, $newId, $uname, $clientId);
    _skp_update_master($pdo, $clientId, $ktp, $npwp, $siup);
    audit($pdo, 'create', 'skp_documents', (string) $newId, $fields);

    if ($doSubmit && ($miss = array_merge($missNum, _skp_missing_required($pdo, $newId)))) {
        flash('Dibuat sebagai draft. Belum bisa submit — lengkapi dulu: ' . implode(', ', $miss) . '.');
    } elseif ($doSubmit) {
        $pdo->prepare("UPDATE skp_documents SET status='submitted', submitted_at=CURRENT_TIMESTAMP WHERE id=? AND property_id=?")->execute([$newId, $pid]);
        flash('Dokumen dibuat & disubmit untuk approval.');
    } else {
        flash('Draft dibuat.');
    }
    redirect_to('skp_form', ['id' => $newId]);
}

/**
 * Buat transaksi + alokasi dari konfirmasi yang di-approve (offer-based).
 * Ini titik di mana deal masuk ke analitik CLARA (Dashboard/Achievement/Recurring).
 */
// $item != null → mode PAKET: transaksi untuk satu komponen offer_items
// (segmen/titik/harga sendiri), periode & client tetap dari $src (level offer).
/**
 * Tautkan pembagian income milik dokumen ke transaksi yang baru terbit saat
 * approve, lalu terapkan ke alokasinya. Dipakai hanya untuk dokumen yang
 * melahirkan SATU transaksi — pada paket, nilainya terpecah per komponen
 * sehingga pembagian per PIC tidak punya arti tunggal.
 */
function _skp_tautkan_bagi(PDO $pdo, int $skpId, int $trxId): void
{
    $st = $pdo->prepare('UPDATE transaction_pic_splits SET transaction_id = ? WHERE skp_id = ?');
    $st->execute([$trxId, $skpId]);
    if ($st->rowCount() > 0) {
        require_once dirname(__DIR__) . '/AllocationService.php';
        AllocationService::terapkanPembagian($pdo, $trxId);
    }
}

/**
 * Salin jadwal harga dari penawaran ke transaksi yang baru terbit, lalu hitung
 * ulang alokasinya supaya tiap bulan memakai nominal tahapnya. Dipanggil
 * SEBELUM transaksi dipakai laporan.
 */
function _skp_salin_tahap(PDO $pdo, int $pid, ?int $offerId, int $trxId): int
{
    if (!$offerId || !$trxId) return 0;
    require_once dirname(__DIR__) . '/AllocationService.php';
    $tahap = AllocationService::priceSteps($pdo, null, $offerId);
    if (!$tahap) return 0;
    $pdo->prepare('DELETE FROM price_steps WHERE transaction_id = ?')->execute([$trxId]);
    $ins = $pdo->prepare('INSERT INTO price_steps (property_id, offer_id, transaction_id, effective_from, monthly_amount, label, created_by) VALUES (?,?,?,?,?,?,?)');
    foreach ($tahap as $t) $ins->execute([$pid, $offerId, $trxId, $t['from'], $t['amount'], $t['label'] ?: null, 'skp_approve']);
    return count($tahap);
}

function _skp_create_transaction(PDO $pdo, array $skp, array $src, int $pid, ?array $item = null): int
{
    $start  = (string) $src['start_date'];
    $end    = (string) $src['end_date'];
    $months = (int) ($src['contract_months'] ?? 1);
    // Nilai per-komponen bila paket, else nilai offer tunggal.
    $module     = $item ? $item['segment']                 : $src['module'];
    $masterCode = $item ? $item['master_code']             : $src['master_code'];
    $total      = $item ? (float) $item['total_amount']    : (float) ($src['final_amount'] ?: $src['total_calculated']);
    $unitRate   = $item ? (float) $item['unit_rate']       : (float) ($src['unit_rate'] ?? 0);
    $area       = $item ? (float) $item['area_sqm']        : (float) ($src['area_sqm'] ?? 0);
    $slots      = $item ? (float) $item['slots']           : (float) ($src['slots'] ?? 1);
    $pricing    = $item ? ($item['pricing_type'] ?: $src['pricing_type']) : $src['pricing_type'];
    $noteAdd    = $item ? trim((string) ($item['name_snapshot'] ?? '')) : '';
    $crossMonth = substr($start, 0, 7) !== substr($end, 0, 7);
    // Pengakuan ditentukan di penawaran (billing_method). Bila tak ada (legacy),
    // jatuh ke tebakan: multi-bulan/lintas bulan → spread.
    $bm = $src['billing_method'] ?? '';
    $spread = in_array($bm, ['spread', 'anchor_cycle'], true)
        ? $bm === 'spread'
        : ($months > 1 || $crossMonth);
    $cycleRec = ($src['cycle_recognition'] ?? '') === 'cycle_end' ? 'cycle_end' : 'cycle_start';

    $trx = [
        'property_id'      => $pid,
        'module'           => $module,
        'client_id'        => $src['client_id'] ?: null,
        'contact_id'       => $src['contact_id'] ?: null,
        'master_code'      => $masterCode,
        'period_key'       => substr($start, 0, 7),
        'content_note'     => $noteAdd ?: ($src['content_note'] ?? null),
        'start_date'       => $start,
        'end_date'         => $end,
        'quantity'         => (float) ($src['quantity'] ?? 1),
        'slots'            => $slots,
        'area_sqm'         => $area,
        'pricing_type'     => $pricing,
        'unit_rate'        => $unitRate,
        'contract_months'  => $months ?: null,
        'billing_method'   => $spread ? 'spread' : 'anchor_cycle',
        'recurring_flag'   => (int) ($src['recurring_flag'] ?? 0),
        'cycle_recognition'=> $cycleRec,
        'total_calculated' => $total,
        'override_amount'  => $total,
        'final_amount'     => $total,
        'pic_name'         => $src['pic_name'] ?? null,
        'referrer_name'    => $src['referrer_name'] ?? null,
        'remarks'          => 'Dari ' . skp_doc_short((string) ($skp['doc_type'] ?? 'skp')) . ' ' . ($skp['skp_no'] ?? '') . ($noteAdd ? ' · ' . $noteAdd : ''),
        'invoice_no'       => null,
        'created_by'       => $_SESSION['user']['name'] ?? 'system',
    ];
    $cols = array_keys($trx);
    $ph   = array_map(fn($c) => ':' . $c, $cols);
    $vals = [];
    foreach ($trx as $k => $v) $vals[':' . $k] = $v;
    $pdo->prepare('INSERT INTO transactions (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')')->execute($vals);
    $tid = (int) $pdo->lastInsertId();
    // Alokasi bulanan (spread membagi final_amount; anchor_cycle 1 bulan)
    $trx['id'] = $tid;
    if (!$spread) $trx['recognition_period'] = $trx['period_key'];
    AllocationService::saveAllocations($pdo, $tid, $trx, []);
    return $tid;
}

// ─── Approve (manager) ───────────────────────────────────────────────────────
function skp_approve(PDO $pdo): void
{
    require_permission('approve_skp');
    verify_csrf();
    $pid = current_property_id();
    $id  = (int) post('id');

    $st = $pdo->prepare('SELECT * FROM skp_documents WHERE id = ? AND property_id = ?');
    $st->execute([$id, $pid]);
    $skp = $st->fetch();
    if (!$skp || $skp['status'] !== 'submitted') { flash('SKP tidak dalam status menunggu approval.'); redirect_to('skp_form', ['id' => $id]); }

    // Dokumen Gudang/Media berdiri sendiri: sumbernya kolom dokumen itu sendiri.
    $standalone = empty($skp['offer_id']) && empty($skp['transaction_id']);
    $src = $standalone
        ? skp_standalone_src($pdo, $pid, skp_doc_module((string) $skp['doc_type']), $skp)
        : ((int) ($skp['offer_id'] ?? 0)
            ? _skp_source_from_offer($pdo, (int) $skp['offer_id'], $pid)
            : _skp_source($pdo, (int) $skp['transaction_id'], $pid));
    if (!$src) { flash('Sumber (penawaran/transaksi) tidak ditemukan.'); redirect_to('skp'); }
    if ($standalone && (empty($src['client_id']) || empty($src['start_date']) || empty($src['end_date']))) {
        flash('Data sewa belum lengkap (client &amp; periode wajib) — minta sales melengkapi dulu.');
        redirect_to('skp_form', ['id' => $id]);
    }

    // Nomor dokumen: {SKP|SKS}/{EW|PC}/{tahun}/{urut}
    $year  = (int) date('Y');
    $prop  = current_property();
    $code  = _skp_prop_code($prop['key'] ?? '');
    $prefix = skp_doc_short((string) ($skp['doc_type'] ?? 'skp'));

    // Paket: ambil komponen lebih dulu (dipakai utk snapshot itemize + loop transaksi).
    $isBundleSrc = !empty($src['is_bundle']);
    $bundleRows = [];
    if ($isBundleSrc && !empty($skp['offer_id'])) {
        $bq = $pdo->prepare('SELECT * FROM offer_items WHERE offer_id = ? ORDER BY sort_order ASC, id ASC');
        $bq->execute([(int) $skp['offer_id']]);
        $bundleRows = $bq->fetchAll();
    }

    // Snapshot nilai cetak
    $days  = _skp_days($src['start_date'], $src['end_date']);
    $total = (float) ($src['final_amount'] ?: $src['total_calculated']);
    $listrikSkp = skp_listrik($skp, (string) $src['start_date'], (string) $src['end_date']);
    $amt   = _skp_amounts($total + $listrikSkp, (float) $src['unit_rate'], (float) $skp['deposit_amount'],
        (float) ($src['electricity'] ?? 0) + $listrikSkp, !empty($skp['deposit_paid']), !empty($skp['ppn_flag']),
        !empty($skp['sc_flag']) ? (float) $skp['sc_monthly'] : 0.0,
        _skp_bulan($src['start_date'] ?? null, $src['end_date'] ?? null));
    // Paket: lokasi & komponen utk ditampilkan di dokumen SKP.
    $bundleLoc = $bundleRows ? ('Paket (' . count($bundleRows) . ' komponen)') : '';
    $bundleItemsSnap = array_map(fn($r) => [
        'segment' => $r['segment'], 'master_code' => $r['master_code'],
        'name' => $r['name_snapshot'], 'total' => (float) $r['total_amount'],
    ], $bundleRows);
    $snapshot = [
        'company_name' => $src['company_name'], 'npwp' => $src['npwp'], 'siup' => $src['siup'] ?? null, 'address' => $src['address'],
        'cp_name' => $skp['cp_name'] ?: $src['cp_name'], 'phone' => $skp['phone_pj'] ?: $src['cp_phone'],
        'ktp_pj' => $skp['ktp_pj'], 'business_type' => $src['business_type'],
        'produk' => $skp['produk'] ?: ($src['brand_name'] ?? null), 'brand_name' => $src['brand_name'] ?? null,
        'location' => ($src['location_name'] ?: $src['master_code']) ?: $bundleLoc, 'floor' => $src['floor'],
        'is_bundle' => $isBundleSrc ? 1 : 0, 'bundle_items' => $bundleItemsSnap,
        'area' => (float) ($src['area_sqm'] ?: $src['unit_area']), 'seating_area' => $skp['seating_area'],
        'start_date' => $src['start_date'], 'end_date' => $src['end_date'], 'days' => $days,
        'status_sewa' => $skp['status_sewa'],
        'admin_siup' => (int)$skp['admin_siup'], 'admin_npwp' => (int)$skp['admin_npwp'], 'admin_ktp' => (int)$skp['admin_ktp'],
        'amounts' => $amt, 'sales' => $src['pic_name'], 'property_name' => $prop['name'] ?? '',
        // Jadwal harga bertahap ikut dibekukan supaya cetakan ulang tidak berubah.
        'tahap_harga' => AllocationService::priceSteps($pdo, (int) ($skp['transaction_id'] ?? 0), (int) ($skp['offer_id'] ?? 0)),
        // Referensi penawaran (offer-based) + daftar lampiran terunggah → tampil di PDF & TTD.
        'offer_no' => $src['offer_no'] ?? null,
        'attachments' => _skp_attachment_list($pdo, $id),
        // Dokumen Gudang/Media: isi formulir + teks template ikut dikunci di sini
        // supaya cetakannya tidak berubah walau template diedit setelahnya.
        'doc_type' => (string) ($skp['doc_type'] ?? 'skp'),
        'detail'   => json_decode((string) ($skp['detail_json'] ?? ''), true) ?: [],
        'tpl'      => skp_template($pdo, $pid, skp_doc_module((string) ($skp['doc_type'] ?? 'skp'))),
        'npwp_no'  => $src['npwp'] ?? null,
        'doc_date' => date('Y-m-d'),
    ];

    $signToken = bin2hex(random_bytes(20));

    // ATOMIK: nomor SKP + status approved + transaksi/alokasi harus terbit
    // bersama. Bila pembuatan transaksi gagal, SEMUA di-rollback (termasuk
    // increment counter) sehingga tidak ada nomor "terbakar" dan SKP tetap
    // 'submitted' agar bisa di-approve ulang. next_seq_no dipanggil DI DALAM
    // transaksi agar rollback juga mengembalikan counter.
    $trxMsg = '';
    try {
        $pdo->beginTransaction();

        $seq   = next_seq_no($pdo, 'skp_counters', $pid, $year);
        $skpNo = sprintf('%s/%s/%d/%03d', $prefix, $code, $year, $seq);

        $pdo->prepare(
            'UPDATE skp_documents SET status=\'approved\', skp_no=?, approved_by=?, approved_at=CURRENT_TIMESTAMP,
             snapshot_json=?, sign_token=?, sign_token_expires_at=' . sign_token_expiry_sql() . ' WHERE id=? AND property_id=?'
        )->execute([$skpNo, $_SESSION['user']['name'] ?? 'manager', json_encode($snapshot, JSON_UNESCAPED_UNICODE), $signToken, $id, $pid]);

        // Transaksi + alokasi terbit saat approve (offer-based, bila belum ada).
        // Inilah titik deal masuk ke Dashboard/Achievement/Recurring.
        if ($standalone) {
            // Gudang/Media: transaksi + alokasi terbit dari data dokumen ini.
            $tid = _skp_create_transaction($pdo, array_merge($skp, ['skp_no' => $skpNo]), $src, $pid);
            $pdo->prepare('UPDATE skp_documents SET transaction_id=? WHERE id=? AND property_id=?')->execute([$tid, $id, $pid]);
            _skp_tautkan_bagi($pdo, $id, $tid);
            $trxMsg = ' Transaksi #' . $tid . ' terbit otomatis.';
        } elseif (empty($skp['transaction_id']) && !empty($skp['offer_id'])) {
            $offerId = (int) $skp['offer_id'];
            // PAKET: hanya bila offer memang is_bundle DAN punya komponen. Gating
            // ganda (is_bundle + $bundleRows) mencegah offer yg dikembalikan ke
            // single tapi masih punya offer_items basi ikut meledak jadi N transaksi.
            // 1 transaksi per komponen, diikat bundle_id = offer.id (dedupe COUNT).
            $rows = $isBundleSrc ? $bundleRows : [];
            $skpArg = array_merge($skp, ['skp_no' => $skpNo]);
            if ($rows) {
                $repId = 0; $ids = [];
                foreach ($rows as $it) {
                    $tid = _skp_create_transaction($pdo, $skpArg, $src, $pid, $it);
                    $pdo->prepare('UPDATE transactions SET bundle_id=?, skp_id=?, offer_item_id=? WHERE id=?')
                        ->execute([$offerId, $id, (int) $it['id'], $tid]);
                    if (!$repId) $repId = $tid;
                    $ids[] = '#' . $tid;
                }
                // transaction_id SKP = transaksi perwakilan (komponen pertama);
                // tautan lengkap via transactions.skp_id.
                $pdo->prepare('UPDATE skp_documents SET transaction_id=? WHERE id=? AND property_id=?')->execute([$repId, $id, $pid]);
                audit($pdo, 'create', 'transactions', (string) $repId, ['from_skp' => $id, 'skp_no' => $skpNo, 'bundle' => $offerId, 'count' => count($rows)]);
                $trxMsg = ' ' . count($rows) . ' transaksi paket terbit (' . implode(', ', $ids) . ') & masuk laporan.';
            } else {
                $newTrxId = _skp_create_transaction($pdo, $skpArg, $src, $pid);
                $pdo->prepare('UPDATE transactions SET skp_id=? WHERE id=?')->execute([$id, $newTrxId]);
                // Jadwal harga penawaran ikut turun; alokasi dihitung ulang
                // supaya tiap bulan memakai nominal tahapnya.
                if (_skp_salin_tahap($pdo, $pid, (int) $skp['offer_id'], $newTrxId)) {
                    $tq = $pdo->prepare('SELECT * FROM transactions WHERE id = ?');
                    $tq->execute([$newTrxId]);
                    $trxBaru = $tq->fetch();
                    $totalTahap = AllocationService::totalDariTahap($trxBaru, AllocationService::priceSteps($pdo, $newTrxId));
                    if ($totalTahap > 0) {
                        // Jadwal harga mengatur SEWA saja. Biaya listrik dari
                        // penawaran tetap bagian nilai kontrak, jadi ikut
                        // ditambahkan — kalau tidak, nilainya hilang dari income.
                        $totalTahap += (float) ($src['electricity'] ?? 0);
                        $pdo->prepare('UPDATE transactions SET final_amount = ?, override_amount = ? WHERE id = ?')
                            ->execute([$totalTahap, $totalTahap, $newTrxId]);
                        $trxBaru['final_amount'] = $totalTahap;
                    }
                    AllocationService::saveAllocations($pdo, $newTrxId, $trxBaru);
                }
                $pdo->prepare('UPDATE skp_documents SET transaction_id=? WHERE id=? AND property_id=?')->execute([$newTrxId, $id, $pid]);
                _skp_tautkan_bagi($pdo, $id, $newTrxId);
                audit($pdo, 'create', 'transactions', (string) $newTrxId, ['from_skp' => $id, 'skp_no' => $skpNo]);
                $trxMsg = ' Transaksi #' . $newTrxId . ' terbit & masuk laporan.';
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('skp_approve gagal (id=' . $id . '): ' . $e->getMessage());
        // Tidak ada nomor terbakar — SKP tetap menunggu approval, bisa diulang.
        flash('Gagal menyetujui SKP — transaksi tidak dapat dibuat: ' . $e->getMessage() . '. Tidak ada perubahan disimpan, silakan coba lagi.');
        redirect_to('skp_form', ['id' => $id]);
    }

    audit($pdo, 'approve', 'skp_documents', (string) $id, ['skp_no' => $skpNo]);
    flash("Disetujui. Nomor terbit: $skpNo." . $trxMsg);
    redirect_to('skp_form', ['id' => $id]);
}

// ─── Reject (manager) ────────────────────────────────────────────────────────
function skp_reject(PDO $pdo): void
{
    require_permission('approve_skp');
    verify_csrf();
    $pid = current_property_id();
    $id  = (int) post('id');
    $note = trim((string) post('reject_note')) ?: 'Tidak ada catatan.';
    $st = $pdo->prepare('SELECT status, transaction_id FROM skp_documents WHERE id = ? AND property_id = ?');
    $st->execute([$id, $pid]);
    $cur = $st->fetch();
    if (!$cur || $cur['status'] !== 'submitted') { flash('SKP tidak dalam status menunggu approval.'); redirect_to('skp_form', ['id' => $id]); }

    // Penolakan = kesepakatannya batal, bukan sekadar dokumen dikembalikan.
    // Transaksi yang sudah terbit (Exhibition: lahir saat penawaran DEAL) ikut
    // dibatalkan berikut alokasi bulanannya, supaya tidak terus masuk laporan.
    // Salah scan TIDAK perlu ditolak — pakai "Ganti Berkas Lampiran".
    $trxId = (int) ($cur['transaction_id'] ?? 0);
    $trx   = null;
    if ($trxId) {
        $q = $pdo->prepare('SELECT * FROM transactions WHERE id = ? AND property_id = ? AND deleted_at IS NULL');
        $q->execute([$trxId, $pid]);
        $trx = $q->fetch() ?: null;
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE skp_documents SET status=\'rejected\', reject_note=? WHERE id=? AND property_id=?')
            ->execute([$note, $id, $pid]);
        if ($trx) {
            $pdo->prepare('UPDATE transactions SET deleted_at = ?, deleted_by = ?, cancel_reason = ? WHERE id = ? AND property_id = ?')
                ->execute([date('Y-m-d H:i:s'), $_SESSION['user']['email'] ?? 'system',
                           'Dokumen ditolak: ' . $note, $trxId, $pid]);
            $pdo->prepare('DELETE FROM transaction_allocations WHERE transaction_id = ? AND property_id = ?')
                ->execute([$trxId, $pid]);
            // Pembagian income & jadwal harga milik transaksi yang dibatalkan
            // ikut dibuang supaya tidak tertinggal yatim di tabel.
            $pdo->prepare('DELETE FROM transaction_pic_splits WHERE transaction_id = ?')->execute([$trxId]);
            $pdo->prepare('DELETE FROM price_steps WHERE transaction_id = ?')->execute([$trxId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit($pdo, 'reject', 'skp_documents', (string) $id, ['note' => $note, 'transaksi_dibatalkan' => $trx ? $trxId : null], (array) ($trx ?: []));
    flash($trx
        ? 'SKP ditolak. Transaksi #' . $trxId . ' ikut dibatalkan & alokasi bulanannya dihapus.'
        : 'SKP ditolak & dikembalikan ke sales.');
    redirect_to('skp_form', ['id' => $id]);
}

/**
 * Ganti satu berkas lampiran pada dokumen yang sudah terkunci (submitted /
 * approved / signed). Hanya berkasnya yang berubah — nilai, periode dan nomor
 * dokumen tetap. Snapshot ikut disegarkan supaya PDF menunjuk berkas yang baru.
 */
function skp_attachment_replace(PDO $pdo): void
{
    require_permission('manage_skp');
    verify_csrf();
    $pid  = current_property_id();
    $id   = (int) post('id');
    $kind = strtolower(trim((string) post('kind')));
    $kembali = ['skp_form', ['id' => $id]];

    if (!in_array($kind, ['ktp', 'npwp', 'siup', 'pengajuan'], true)) {
        flash('Jenis lampiran tidak dikenal.');
        redirect_to(...$kembali);
    }
    $st = $pdo->prepare('SELECT * FROM skp_documents WHERE id = ? AND property_id = ?');
    $st->execute([$id, $pid]);
    $skp = $st->fetch();
    if (!$skp) { flash('Dokumen tidak ditemukan.'); redirect_to('skp'); }

    // Pembatasan per-sales sama seperti halaman dokumennya.
    if ($sc = current_sales_scope($pdo, $pid)) {
        $milik = ($sc['pic'] !== '' && (string) ($skp['pic_name'] ?? '') === $sc['pic'])
            || ($skp['created_by'] ?? '') === $sc['uname'];
        if (!$milik && $skp['transaction_id']) {
            $q = $pdo->prepare('SELECT pic_name FROM transactions WHERE id = ?');
            $q->execute([(int) $skp['transaction_id']]);
            $milik = $sc['pic'] !== '' && (string) $q->fetchColumn() === $sc['pic'];
        }
        if (!$milik && $skp['offer_id']) {
            $q = $pdo->prepare('SELECT pic_name FROM offers WHERE id = ?');
            $q->execute([(int) $skp['offer_id']]);
            $milik = $sc['pic'] !== '' && (string) $q->fetchColumn() === $sc['pic'];
        }
        if (!$milik) { flash('SKP ini bukan milik Anda.'); redirect_to('skp'); }
    }

    $f = $_FILES['berkas'] ?? null;
    if (!$f || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
        flash('Berkas belum dipilih.');
        redirect_to(...$kembali);
    }
    if ($f['size'] <= 0 || $f['size'] > 5 * 1024 * 1024) {
        flash('Ukuran berkas maksimal 5 MB.');
        redirect_to(...$kembali);
    }
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
        flash('Format berkas harus JPG, PNG, WEBP atau PDF.');
        redirect_to(...$kembali);
    }

    $dir = dirname(__DIR__, 2) . '/public/uploads/skp';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $nama = 'skp' . $id . '_' . $kind . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $nama)) {
        flash('Gagal menyimpan berkas.');
        redirect_to(...$kembali);
    }

    $lamaQ = $pdo->prepare('SELECT file_path FROM skp_attachments WHERE skp_id = ? AND kind = ?');
    $lamaQ->execute([$id, $kind]);
    $lama = (string) ($lamaQ->fetchColumn() ?: '');

    $uname = $_SESSION['user']['name'] ?? 'system';
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM skp_attachments WHERE skp_id = ? AND kind = ?')->execute([$id, $kind]);
        $pdo->prepare('INSERT INTO skp_attachments (skp_id, kind, file_path, original_name, uploaded_by) VALUES (?,?,?,?,?)')
            ->execute([$id, $kind, 'uploads/skp/' . $nama, substr((string) $f['name'], 0, 190), $uname . ' (ganti)']);
        // Snapshot dokumen menyimpan daftar lampiran — ikut disegarkan agar PDF
        // & halaman TTD menunjuk berkas yang benar. Angka di dalamnya tak disentuh.
        if (!empty($skp['snapshot_json'])) {
            $snap = json_decode((string) $skp['snapshot_json'], true);
            if (is_array($snap)) {
                $snap['attachments'] = _skp_attachment_list($pdo, $id);
                $pdo->prepare('UPDATE skp_documents SET snapshot_json = ? WHERE id = ? AND property_id = ?')
                    ->execute([json_encode($snap, JSON_UNESCAPED_UNICODE), $id, $pid]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        @unlink($dir . '/' . $nama);
        throw $e;
    }

    // Berkas lama dibuang hanya bila tidak dipakai dokumen lain (scan bisa dipakai ulang).
    if ($lama !== '') {
        $pakai = $pdo->prepare('SELECT COUNT(*) FROM skp_attachments WHERE file_path = ?');
        $pakai->execute([$lama]);
        if ((int) $pakai->fetchColumn() === 0) {
            $abs = dirname(__DIR__, 2) . '/public/' . ltrim($lama, '/');
            if (is_file($abs)) @unlink($abs);
        }
    }
    audit($pdo, 'ganti_lampiran', 'skp_documents', (string) $id, ['kind' => $kind, 'file' => 'uploads/skp/' . $nama]);
    flash('Berkas ' . strtoupper($kind) . ' diganti. Nilai & nomor dokumen tidak berubah.');
    redirect_to(...$kembali);
}
// ─── Opsi B: unggah dokumen ber-TTD — setara TTD online lewat tautan ─────────
function skp_sign_upload(PDO $pdo): void
{
    require_permission('manage_skp');
    verify_csrf();
    $pid = current_property_id();
    $id  = (int) post('id');
    $st = $pdo->prepare('SELECT * FROM skp_documents WHERE id=? AND property_id=?');
    $st->execute([$id, $pid]);
    $skp = $st->fetch();
    if (!$skp || $skp['status'] !== 'approved') { flash('SKP harus berstatus Disetujui & belum ditandatangani.'); redirect_to('skp_form', ['id' => $id]); }

    $name = trim((string) post('sign_name'));
    if ($name === '') { flash('Nama penanda tangan wajib diisi.'); redirect_to('skp_form', ['id' => $id]); }
    if (empty($_FILES['signed_doc']['tmp_name']) || !is_uploaded_file($_FILES['signed_doc']['tmp_name'])) {
        flash('Dokumen yang sudah ber-TTD wajib diunggah.'); redirect_to('skp_form', ['id' => $id]);
    }
    $f = $_FILES['signed_doc'];
    if ($f['size'] <= 0 || $f['size'] > 8 * 1024 * 1024) { flash('Ukuran file maksimal 8MB.'); redirect_to('skp_form', ['id' => $id]); }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) { flash('Format harus jpg/png/webp/pdf.'); redirect_to('skp_form', ['id' => $id]); }

    $dir = dirname(__DIR__, 2) . '/public/uploads/skp';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $fname = 'skp' . $id . '_signed_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) { flash('Gagal menyimpan file.'); redirect_to('skp_form', ['id' => $id]); }
    $rel = 'uploads/skp/' . $fname;

    $pdo->prepare(
        "UPDATE skp_documents SET status='signed', sign_method='wet', sign_name=?, signed_doc_path=?, signed_at=CURRENT_TIMESTAMP
         WHERE id=? AND property_id=? AND status='approved'"
    )->execute([$name, $rel, $id, $pid]);
    audit($pdo, 'customer_sign_wet', 'skp_documents', (string) $id, ['name' => $name, 'file' => $rel]);
    flash('Dokumen ditandai sudah ditandatangani sesuai berkas yang diunggah.');
    redirect_to('skp_form', ['id' => $id]);
}

// ─── Cetak / PDF ─────────────────────────────────────────────────────────────
function skp_print(PDO $pdo): void
{
    require_permission('manage_skp');
    $pid = current_property_id();
    $id  = (int) getv('id');
    $st = $pdo->prepare('SELECT * FROM skp_documents WHERE id = ? AND property_id = ?');
    $st->execute([$id, $pid]);
    $skp = $st->fetch();
    if (!$skp || !in_array($skp['status'], ['approved', 'signed'], true) || empty($skp['snapshot_json'])) {
        http_response_code(404); exit('SKP belum disetujui / tidak ditemukan.');
    }
    $d = json_decode($skp['snapshot_json'], true) ?: [];
    $a = $d['amounts'] ?? [];
    $rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    // ■/□ dipakai (bukan ☑/☐) karena font PDF (DejaVu) tak punya glyph ballot-box.
    $chk = fn($b) => !empty($b) ? '<span style="color:#0D9488">■</span>' : '<span style="color:#9ca3af">□</span>';
    $h  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    // Pratinjau HTML lama (window.print) hanya bila ?html=1; default PDF mPDF.
    if (getv('html') === '1') {
        include __DIR__ . '/skp_print_template.php';
        return;
    }
    require_once dirname(__DIR__) . '/pdf.php';
    $docTitle = skp_doc_title((string) ($skp['doc_type'] ?? 'skp'));
    $PDF_MODE = true;
    ob_start();
    include __DIR__ . '/skp_print_body.php';
    $html = ob_get_clean();
    clara_render_letterhead_pdf($html, ($skp['skp_no'] ?: 'SKP') . ' - ' . $docTitle);
}

// ─── Tanda tangan customer (PUBLIK, tanpa login — akses via sign_token) ───────
/** Cari SKP dari token. */
function _skp_by_token(PDO $pdo, string $token): ?array
{
    if ($token === '' || strlen($token) > 64) return null;
    $st = $pdo->prepare('SELECT * FROM skp_documents WHERE sign_token = ? LIMIT 1');
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

/** Simpan tanda tangan customer (POST dari halaman publik). */
function skp_sign_save(PDO $pdo): void
{
    $token = (string) post('token', getv('token', ''));
    $skp = _skp_by_token($pdo, $token);
    if (!$skp || !in_array($skp['status'], ['approved'], true)) {
        http_response_code(403); exit('Tautan tidak valid atau dokumen sudah ditandatangani.');
    }
    if (sign_token_expired($skp['sign_token_expires_at'] ?? null)) {  // H3
        http_response_code(410); exit('Tautan tanda tangan sudah kedaluwarsa. Hubungi sales untuk link baru.');
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
    // Simpan sebagai data URL di DB (tanpa file) — kokoh di hosting apa pun.
    $pdo->prepare(
        "UPDATE skp_documents SET status='signed', sign_name=?, sign_ip=?, sign_ua=?, signature_data=?, signed_at=CURRENT_TIMESTAMP
         WHERE id=? AND sign_token=? AND status='approved'"
    )->execute([
        $name,
        substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        $data,
        (int) $skp['id'], $token,
    ]);
    audit($pdo, 'customer_sign', 'skp_documents', (string) $skp['id'], ['name' => $name], [], 'skp');
    redirect_to('skp_sign', ['token' => $token, 'done' => 1]);
}

/** Halaman publik: customer review SKP + tanda tangan. */
function skp_sign_page(PDO $pdo): void
{
    $token = (string) getv('token', '');
    $skp = _skp_by_token($pdo, $token);
    if (!$skp || !in_array($skp['status'], ['approved', 'signed'], true) || empty($skp['snapshot_json'])) {
        http_response_code(404);
        exit('Tautan tanda tangan tidak valid atau sudah kedaluwarsa.');
    }
    // H3 — link kedaluwarsa & belum TTD: tutup paparan PII. Yang sudah TTD tetap
    // bisa dilihat lewat halaman validasi (doc_verify) sebagai bukti.
    if ($skp['status'] !== 'signed' && sign_token_expired($skp['sign_token_expires_at'] ?? null)) {
        http_response_code(410);
        exit('Tautan tanda tangan sudah kedaluwarsa. Hubungi sales untuk link baru.');
    }
    $d = json_decode($skp['snapshot_json'], true) ?: [];
    $a = $d['amounts'] ?? [];
    $signed = $skp['status'] === 'signed';
    $rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    include __DIR__ . '/skp_sign_template.php';
}

/**
 * Halaman validasi dokumen publik (read-only) yang dibuka saat QR di-scan.
 * Ringkas: konfirmasi keaslian + siapa membuat/menyetujui + waktu (tanpa TTD).
 */
function skp_verify_page(PDO $pdo): void
{
    $token = (string) getv('token', '');
    $skp = _skp_by_token($pdo, $token);
    $valid = $skp && in_array($skp['status'], ['approved', 'signed'], true) && !empty($skp['skp_no']);
    $d = $valid ? (json_decode($skp['snapshot_json'], true) ?: []) : [];
    $a = $d['amounts'] ?? [];
    $h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    // "TTD terdaftar?" → sales (master_pic) & manager (users)
    $salesReg = $mgrReg = false;
    if ($valid) {
        $sp = $pdo->prepare("SELECT signature_path FROM master_pic WHERE name=? AND property_id=? LIMIT 1");
        $sp->execute([$d['sales'] ?? '', (int) $skp['property_id']]);
        $salesReg = !empty($sp->fetchColumn());
        $mp = $pdo->prepare("SELECT signature_path FROM users WHERE name=? LIMIT 1");
        $mp->execute([$skp['approved_by'] ?? '']);
        $mgrReg = !empty($mp->fetchColumn());
    }
    include __DIR__ . '/skp_verify_template.php';
}
