<?php
// ─── SKP — Surat Konfirmasi Pameran ──────────────────────────────────────────
// Dokumen konfirmasi untuk transaksi Exhibition yang sudah deal.
// Alur: sales buat (draft) → submit → manager approve (No. SKP terbit + snapshot)
//       → cetak/PDF. Lihat [[project-skp]] / migration 013.

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
        "SELECT t.*, c.company_name, c.npwp, c.address, c.business_type,
                ct.name cp_name, ct.phone cp_phone,
                u.location_name, u.floor, u.area_sqm AS unit_area
         FROM transactions t
         LEFT JOIN master_clients c ON c.id = t.client_id
         LEFT JOIN master_client_contacts ct ON ct.id = t.contact_id
         LEFT JOIN master_cl_units u ON u.code = t.master_code AND u.property_id = t.property_id
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
                c.company_name, c.npwp, c.ktp AS client_ktp, c.address, c.business_type,
                ct.name cp_name, ct.phone cp_phone,
                u.location_name, u.floor, u.area_sqm AS unit_area
         FROM offers o
         LEFT JOIN master_clients c ON c.id = o.client_id
         LEFT JOIN master_client_contacts ct ON ct.id = o.contact_id
         LEFT JOIN master_cl_units u ON u.code = o.master_code AND u.property_id = o.property_id
         WHERE o.id = ? AND o.property_id = ? AND o.status = 'deal'"
    );
    $stmt->execute([$offerId, $pid]);
    $o = $stmt->fetch();
    if (!$o) return null;
    $o['renewal_status'] = null;
    $o['doc_type'] = $o['module'] === 'cl' ? 'skp' : 'sks';
    return $o;
}

/** Hitung rincian biaya. PPN sesuai PMK 131/2024: nilai × 11/12 × 12%. */
function _skp_amounts(float $total, float $ratePerM, float $deposit): array
{
    $ppn        = round($total * 11 / 12 * 0.12);
    $afterPpn   = $total + $ppn;
    return [
        'rate_m_day'  => $ratePerM,
        'total'       => $total,
        'ppn'         => $ppn,
        'after_ppn'   => $afterPpn,
        'deposit'     => $deposit,
        'grand_total' => $afterPpn + $deposit,
    ];
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
    if ($module) { $where[] = 'COALESCE(t.module, o.module) = ?'; $params[] = $module; }
    // SKP bisa berasal dari penawaran (offer-first, transaksi belum terbit) ATAU
    // dari transaksi lama. LEFT JOIN keduanya + fallback datanya.
    $stmt = $pdo->prepare(
        'SELECT s.*,
                COALESCE(t.master_code, o.master_code) master_code,
                COALESCE(t.start_date, o.start_date)   start_date,
                COALESCE(t.end_date, o.end_date)       end_date,
                COALESCE(t.module, o.module)           module,
                COALESCE(tc.company_name, oc.company_name) company_name
         FROM skp_documents s
         LEFT JOIN transactions t ON t.id = s.transaction_id
         LEFT JOIN master_clients tc ON tc.id = t.client_id
         LEFT JOIN offers o ON o.id = s.offer_id
         LEFT JOIN master_clients oc ON oc.id = o.client_id
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
            <strong style="font-size:16px">Surat Konfirmasi SKP / SKS</strong>
            <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap">
                <?php foreach (['' => 'Semua', 'draft' => 'Draft', 'submitted' => 'Menunggu', 'approved' => 'Perlu TTD', 'signed' => 'Ditandatangani', 'rejected' => 'Ditolak'] as $k => $lbl): ?>
                    <a class="btn light" style="<?= $status === $k ? 'background:var(--primary,#0d9488);color:#fff' : '' ?>" href="?r=skp<?= $k ? '&status=' . $k : '' ?><?= $mq ?>"><?= $lbl ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="toolbar" style="gap:6px;flex-wrap:wrap;margin-top:8px">
            <span style="font-size:12px;color:var(--muted);align-self:center">Modul:</span>
            <?php foreach (['' => 'Semua', 'cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'] as $mk => $mlbl):
                $mactive = $module === $mk; $mbg = $mk && isset($modBadge[$mk]) ? $modBadge[$mk][2] : '#0d9488'; $mc = $mk && isset($modBadge[$mk]) ? $modBadge[$mk][1] : '#fff'; ?>
                <a class="btn light" style="<?= $mactive ? 'background:' . $mbg . ';color:' . $mc . ';font-weight:700' : '' ?>" href="?r=skp<?= $sq ?><?= $mk ? '&module=' . $mk : '' ?>"><?= h($mlbl) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="panel" style="margin-top:12px">
            <p style="margin:0 0 10px;color:var(--muted);font-size:13px">SKP/SKS dibuat dari halaman <strong>Preview Penawaran</strong> yang sudah DEAL (tombol "Buat SKP/SKS"). Transaksi terbit otomatis saat SKP disetujui.</p>
            <div class="table-wrap">
                <table style="font-size:12.5px">
                    <thead><tr><th>No. SKP/SKS</th><th>Modul</th><th>Kode</th><th>Client</th><th>Periode</th><th>Status</th><th>Dibuat</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?><tr><td colspan="8" style="text-align:center;color:var(--muted);padding:24px">Belum ada SKP/SKS.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $r): $b = $badge[$r['status']] ?? $badge['draft']; $mb = $modBadge[$r['module']] ?? ['—', '#374151', '#f1f5f9']; ?>
                        <tr>
                            <td style="white-space:nowrap;font-weight:600"><?= h($r['skp_no'] ?? '—') ?></td>
                            <td><span class="badge" style="color:<?= $mb[1] ?>;background:<?= $mb[2] ?>"><?= h($mb[0]) ?></span></td>
                            <td><?= h($r['master_code']) ?></td>
                            <td><?= h($r['company_name'] ?? '-') ?></td>
                            <td style="white-space:nowrap;font-size:11.5px"><?= $r['start_date'] ? h(date('d/m/y', strtotime($r['start_date'])) . '–' . date('d/m/y', strtotime($r['end_date']))) : '—' ?></td>
                            <td><span class="badge" style="color:<?= $b[1] ?>;background:<?= $b[2] ?>"><?= $b[0] ?></span></td>
                            <td style="font-size:11.5px;color:var(--muted)"><?= h($r['created_by'] ?? '-') ?><br><?= h(substr($r['created_at'] ?? '', 0, 16)) ?></td>
                            <td style="white-space:nowrap">
                                <a class="btn light" href="?r=skp_form&id=<?= (int)$r['id'] ?>"><?= $r['status'] === 'draft' || $r['status'] === 'rejected' ? 'Edit' : 'Lihat' ?></a>
                                <?php if (in_array($r['status'], ['approved', 'signed'], true)): ?><a class="btn light" href="?r=skp_print&id=<?= (int)$r['id'] ?>" target="_blank">PDF</a><?php endif; ?>
                                <?php if (($r['sign_method'] ?? '') === 'wet' && !empty($r['signed_doc_path'])): ?><a class="btn light" href="<?= h($r['signed_doc_path']) ?>" target="_blank">Scan TTD</a><?php endif; ?>
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
    $skp = null;

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

    // Sumber data: dari Penawaran (alur baru) atau Transaksi (legacy)
    $src = $offerId ? _skp_source_from_offer($pdo, $offerId, $pid) : _skp_source($pdo, $trxId, $pid);
    if (!$src) { flash('Sumber (penawaran/transaksi) tidak ditemukan / belum DEAL.'); redirect_to($offerId ? 'offers' : 'transactions'); }
    $docType = $skp['doc_type'] ?? ($src['doc_type'] ?? (($src['module'] ?? '') === 'cl' ? 'skp' : 'sks'));
    $docLabel = $docType === 'sks' ? 'SKS (Surat Konfirmasi Sewa)' : 'SKP (Surat Konfirmasi Pameran)';

    $editable = !$skp || in_array($skp['status'], ['draft', 'rejected'], true);
    $days     = _skp_days($src['start_date'], $src['end_date']);
    $total    = (float) ($src['final_amount'] ?: $src['total_calculated']);
    $defDeposit = (float) ($skp['deposit_amount'] ?? $src['deposit_amount'] ?? 0);
    $amt      = _skp_amounts($total, (float) $src['unit_rate'], $defDeposit);
    $area     = (float) ($src['area_sqm'] ?: $src['unit_area']);
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

    layout(($skp ? ($editable ? 'Edit' : 'Lihat') : 'Buat') . ' ' . ($docType === 'sks' ? 'SKS' : 'SKP'), function () use ($pdo, $skp, $src, $trxId, $offerId, $docType, $docLabel, $editable, $days, $total, $amt, $area, $defDeposit, $atts, $reuse, $val, $pid) {
        $statusSewaDefault = !empty($src['renewal_status']) && $src['renewal_status'] !== 'none' ? 'Perpanjangan' : 'Baru';
        ?>
        <div class="toolbar" style="gap:8px"><a class="btn light" href="?r=<?= $offerId ? 'offer_form&id=' . (int)$offerId : 'allocation_detail&id=' . (int)$trxId ?>">← <?= $offerId ? 'Penawaran' : 'Detail Alokasi' ?></a><a class="btn light" href="?r=skp">Daftar Dokumen</a> <span class="badge" style="background:#e0f2fe;color:#0369a1"><?= h($docLabel) ?></span></div>

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
            <!-- action via hidden field (di-set onclick) — handler anti-double-submit
                 global men-disable tombol saat submit, jadi name/value tombol hilang. -->
            <input type="hidden" name="action" id="skp-action" value="save">

            <h3 style="margin-top:0">Identitas Penyewa</h3>
            <div class="form-grid">
                <div><label>Nama Perusahaan</label><input value="<?= h($src['company_name'] ?? '-') ?>" disabled></div>
                <div><label>Nama Penanggung Jawab</label><input name="cp_name" value="<?= $val('cp_name', $src['cp_name'] ?? '') ?>" <?= $editable ? '' : 'disabled' ?>></div>
                <div class="wide"><label>Alamat</label><input value="<?= h($src['address'] ?? '-') ?>" disabled></div>
                <div><label>Nomor KTP Penanggung Jawab</label><input name="ktp_pj" value="<?= $val('ktp_pj', $src['client_ktp'] ?? '') ?>" inputmode="numeric" <?= $editable ? '' : 'disabled' ?>><span class="help" style="font-size:11px">Tersimpan ke Master Client (auto next)</span></div>
                <div><label>Nomor NPWP</label><input name="npwp_no" value="<?= $val('npwp_no', $src['npwp'] ?? '') ?>" inputmode="numeric" <?= $editable ? '' : 'disabled' ?>><span class="help" style="font-size:11px">Tersimpan ke Master Client (auto next)</span></div>
                <div><label>Nomor Telepon</label><input name="phone_pj" value="<?= $val('phone_pj', $src['cp_phone'] ?? '') ?>" <?= $editable ? '' : 'disabled' ?>></div>
            </div>

            <h3>Lampiran <span style="font-weight:400;font-size:12px;color:var(--muted)">(<span style="color:#dc2626">*</span> wajib sebelum submit approval; Pengajuan opsional)</span></h3>
            <div class="form-grid">
                <?php
                $reqLbl = ['ktp' => 'Scan KTP', 'npwp' => 'Scan NPWP', 'bukti_transfer' => 'Bukti Transfer', 'pengajuan' => 'Pengajuan (opsional)'];
                $wajib  = ['ktp', 'npwp', 'bukti_transfer'];
                foreach ($reqLbl as $kind => $lbl):
                    $has = $atts[$kind] ?? null;
                ?>
                <div>
                    <label><?= $lbl ?><?= in_array($kind, $wajib, true) ? ' <span style="color:#dc2626">*</span>' : '' ?></label>
                    <?php if ($has): ?>
                        <div style="font-size:12px"><a href="<?= h($has['file_path']) ?>" target="_blank">📎 <?= h($has['original_name'] ?: 'lihat') ?></a></div>
                    <?php endif; ?>
                    <?php
                    $prev = (!$has && $editable) ? ($reuse[$kind] ?? null) : null;
                    if ($prev): ?>
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
                <div><label>Lokasi</label><input value="<?= h($src['location_name'] ?? $src['master_code']) ?>" disabled></div>
                <div><label>Lantai</label><input value="<?= h($src['floor'] ?? '-') ?>" disabled></div>
                <div><label>Luas Area (m²)</label><input value="<?= number_format($area, 2, ',', '.') ?>" disabled></div>
                <div><label>Luas Seating Area (m²)</label><input name="seating_area" value="<?= $val('seating_area') ?>" inputmode="decimal" placeholder="opsional" <?= $editable ? '' : 'disabled' ?>></div>
                <div><label>Masa Sewa</label><input value="<?= h($src['start_date'] . ' s/d ' . $src['end_date']) ?> (<?= $days ?> hari)" disabled></div>
                <div>
                    <label>Status Sewa</label>
                    <select name="status_sewa" <?= $editable ? '' : 'disabled' ?>>
                        <?php $cur = $skp['status_sewa'] ?? $statusSewaDefault; foreach (['Baru', 'Perpanjangan'] as $o): ?>
                        <option value="<?= $o ?>" <?= $cur === $o ? 'selected' : '' ?>><?= $o ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Jenis Usaha / Kegiatan</label><input value="<?= h($src['business_type'] ?? '-') ?>" disabled></div>
                <div><label>Produk</label><input name="produk" value="<?= $val('produk', $src['content_note'] ?? '') ?>" <?= $editable ? '' : 'disabled' ?>></div>
            </div>

            <h3>Rincian Pembayaran</h3>
            <div class="form-grid">
                <div><label>Biaya Sewa / m² / hari</label><input value="<?= money($amt['rate_m_day']) ?>" disabled></div>
                <div><label>Total Biaya Sewa</label><input value="<?= money($amt['total']) ?>" disabled></div>
                <div><label>PPN 12% (×11/12)</label><input value="<?= money($amt['ppn']) ?>" disabled></div>
                <div><label>Total Setelah PPN</label><input value="<?= money($amt['after_ppn']) ?>" disabled></div>
                <div><label>Jaminan Area / Security Deposit</label><input name="deposit_amount" class="skp-dep-fmt" value="<?= $defDeposit > 0 ? number_format($defDeposit, 0, ',', '.') : '' ?>" inputmode="numeric" placeholder="0" <?= $editable ? '' : 'disabled' ?>><input type="hidden" name="deposit_raw" class="skp-dep-val" value="<?= (int)$defDeposit ?>"></div>
                <div><label>Grand Total (estimasi)</label><input id="skp-grand" value="<?= money($amt['grand_total']) ?>" disabled></div>
            </div>

            <h3>Catatan Internal</h3>
            <textarea name="note" rows="2" <?= $editable ? '' : 'disabled' ?>><?= h($skp['note'] ?? '') ?></textarea>

            <?php if ($editable): ?>
            <p class="help" style="margin-top:16px;color:#92400e">Submit untuk approval hanya bisa setelah <strong>Scan KTP</strong>, <strong>Scan NPWP</strong>, dan <strong>Bukti Transfer</strong> terunggah.</p>
            <p style="margin-top:8px;display:flex;gap:10px;flex-wrap:wrap">
                <button type="submit" onclick="document.getElementById('skp-action').value='save'" class="btn secondary">Simpan Draft</button>
                <button type="submit" onclick="document.getElementById('skp-action').value='submit'" style="background:#0369a1">Simpan & Submit untuk Approval</button>
                <a class="btn secondary" href="?r=skp">Batal</a>
            </p>
            <?php endif; ?>
        </form>

        <?php if ($skp && $skp['status'] === 'submitted' && can('approve_skp')): ?>
        <div class="panel" style="margin-top:12px;border:1px solid #bae6fd;background:#f0f9ff">
            <h3 style="margin-top:0;color:#0369a1">Approval Manager</h3>
            <form method="post" action="?r=skp_approve" style="display:inline">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$skp['id'] ?>">
                <button type="submit" onclick="return confirm('Setujui SKP ini? Nomor SKP akan terbit dan nilai dikunci.')">✓ Setujui</button>
            </form>
            <form method="post" action="?r=skp_reject" style="display:inline-flex;gap:8px;align-items:center;margin-left:10px">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$skp['id'] ?>">
                <input name="reject_note" placeholder="Alasan penolakan" style="width:240px">
                <button type="submit" class="btn warn">✗ Tolak</button>
            </form>
        </div>
        <?php elseif ($skp && $skp['status'] === 'submitted'): ?>
        <div class="panel" style="margin-top:12px;color:var(--muted)">Menunggu persetujuan manager.</div>
        <?php elseif ($skp && in_array($skp['status'], ['approved', 'signed'], true)):
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
            $signUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/?r=skp_sign&token=' . ($skp['sign_token'] ?? '');
            $docShort = ($skp['doc_type'] ?? 'skp') === 'sks' ? 'Surat Konfirmasi Sewa (SKS)' : 'Surat Konfirmasi Pameran (SKP)';
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
                <p style="margin:0;color:#166534">✓ <strong>Ditandatangani basah (scan)</strong> a.n. <strong><?= h($skp['sign_name']) ?></strong> pada <?= h(substr($skp['signed_at'], 0, 16)) ?>.
                    <?php if (!empty($skp['signed_doc_path'])): ?> <a class="btn light" href="<?= h($skp['signed_doc_path']) ?>" target="_blank">Lihat Scan TTD</a><?php endif; ?></p>
                <?php else: ?>
                <p style="margin:0;color:#166534">✓ <strong>Ditandatangani online</strong> oleh <strong><?= h($skp['sign_name']) ?></strong> pada <?= h(substr($skp['signed_at'], 0, 16)) ?> (IP <?= h($skp['sign_ip']) ?>).</p>
                <?php endif; ?>
            <?php else: ?>
                <p style="margin:0 0 8px;color:#374151"><strong>Opsi A — TTD online.</strong> Kirim tautan ini ke customer. Setelah ditandatangani, dokumen final lengkap dengan TTD.</p>
                <textarea id="skp-wa-msg" style="position:absolute;left:-9999px" readonly><?= h($waMsg) ?></textarea>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <input id="skp-sign-url" value="<?= h($signUrl) ?>" readonly style="flex:1;min-width:260px;font-size:12px" onclick="this.select()">
                    <button type="button" class="btn light" onclick="navigator.clipboard.writeText(document.getElementById('skp-sign-url').value);this.textContent='Tersalin ✓'">Salin Link</button>
                    <button type="button" class="btn light" onclick="navigator.clipboard.writeText(document.getElementById('skp-wa-msg').value);this.textContent='Pesan tersalin ✓'">Salin Pesan</button>
                    <a class="btn" style="background:#16a34a" target="_blank" href="https://wa.me/?text=<?= $waText ?>">Kirim via WhatsApp</a>
                </div>
                <p style="margin:8px 0 0;font-size:11.5px;color:#64748b">Tautan bersifat rahasia &amp; berlaku sampai dokumen ditandatangani. <strong>Jika lewat WhatsApp Desktop hanya link yang terkirim</strong>, gunakan <strong>Salin Pesan</strong> lalu tempel (paste) di chat — teks lengkap akan ikut.</p>
                <hr style="margin:14px 0;border:none;border-top:1px dashed #bae6fd">
                <p style="margin:0 0 8px;color:#374151"><strong>Opsi B — TTD basah</strong> (untuk customer yang tidak terbiasa online). Cetak SKP, minta customer tanda tangan di atas kertas, lalu unggah hasil scan/fotonya di sini.</p>
                <form method="post" action="?r=skp_sign_upload" enctype="multipart/form-data" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end" onsubmit="return confirm('Tandai SKP ini sudah ditandatangani (TTD basah)? Status menjadi final.')">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$skp['id'] ?>">
                    <div><label style="font-size:12px;font-weight:700;display:block">Nama Penanda Tangan</label><input name="sign_name" required placeholder="Nama customer" value="<?= h($skp['cp_name'] ?? '') ?>" style="min-width:200px"></div>
                    <div><label style="font-size:12px;font-weight:700;display:block">Scan SKP ber-TTD (jpg/png/pdf, ≤8MB)</label><input type="file" name="signed_doc" accept=".jpg,.jpeg,.png,.webp,.pdf" required></div>
                    <button type="submit" class="btn" style="background:#0369a1">Unggah &amp; Tandai TTD</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <script>
        (function () {
            var dep = document.querySelector('.skp-dep-fmt'), hid = document.querySelector('.skp-dep-val');
            if (dep) {
                dep.addEventListener('input', function () {
                    var raw = this.value.replace(/\D/g, '');
                    this.value = raw ? parseInt(raw, 10).toLocaleString('id-ID') : '';
                    if (hid) hid.value = raw;
                });
            }
            var f = dep ? dep.closest('form') : null;
            if (f) f.addEventListener('submit', function () { if (dep && hid) hid.value = (dep.value || '').replace(/\D/g, ''); });
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
    $kinds = ['att_ktp' => 'ktp', 'att_npwp' => 'npwp', 'att_bukti_transfer' => 'bukti_transfer', 'att_pengajuan' => 'pengajuan'];
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
    foreach (['ktp', 'npwp'] as $kind) {
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
         WHERE a.kind IN ('ktp','npwp')
           AND COALESCE(o.client_id, t.client_id) = ?
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

/** Auto-update KTP/NPWP ke master client (reusable berikutnya). */
function _skp_update_master(PDO $pdo, int $clientId, ?string $ktp, ?string $npwp): void
{
    if (!$clientId) return;
    $sets = []; $vals = [];
    if ($ktp !== null)  { $sets[] = 'ktp=?';  $vals[] = $ktp; }
    if ($npwp !== null) { $sets[] = 'npwp=?'; $vals[] = $npwp; }
    if (!$sets) return;
    $vals[] = $clientId;
    $pdo->prepare('UPDATE master_clients SET ' . implode(',', $sets) . ' WHERE id=?')->execute($vals);
}

/** Lampiran wajib sebelum submit approval. Return label yang BELUM ada. */
function _skp_missing_required(PDO $pdo, int $skpId): array
{
    $need = ['ktp' => 'Scan KTP', 'npwp' => 'Scan NPWP', 'bukti_transfer' => 'Bukti Transfer'];
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
    $docType = post('doc_type') === 'sks' ? 'sks' : 'skp';
    $doSubmit = post('action') === 'submit';

    $src = $offerId ? _skp_source_from_offer($pdo, $offerId, $pid) : _skp_source($pdo, $trxId, $pid);
    if (!$src) { flash('Sumber (penawaran/transaksi) tidak valid.'); redirect_to($offerId ? 'offers' : 'skp'); }
    $clientId = (int) ($src['client_id'] ?? 0);

    $ktp  = trim((string) post('ktp_pj')) ?: null;
    $npwp = trim((string) post('npwp_no')) ?: null;
    $fields = [
        'cp_name'       => trim((string) post('cp_name')) ?: null,
        'ktp_pj'        => $ktp,
        'phone_pj'      => trim((string) post('phone_pj')) ?: null,
        'seating_area'  => post('seating_area') !== '' ? (float) str_replace(',', '.', post('seating_area')) : null,
        'produk'        => trim((string) post('produk')) ?: null,
        'status_sewa'   => post('status_sewa') ?: 'Baru',
        'deposit_amount'=> (float) post('deposit_raw', 0),
        'admin_siup'    => 0,
        'admin_npwp'    => $npwp ? 1 : 0,
        'admin_ktp'     => $ktp ? 1 : 0,
        'note'          => trim((string) post('note')) ?: null,
    ];
    $uname = $_SESSION['user']['name'] ?? 'system';

    if ($id) {
        $cur = $pdo->prepare('SELECT status FROM skp_documents WHERE id = ? AND property_id = ?');
        $cur->execute([$id, $pid]);
        $st = $cur->fetchColumn();
        if (!in_array($st, ['draft', 'rejected'], true)) { flash('Sudah disubmit/disetujui — tidak bisa diubah.'); redirect_to('skp_form', ['id' => $id]); }
        // Proses upload dulu agar validasi lampiran wajib akurat.
        _skp_handle_uploads($pdo, $id, $uname, $clientId);
        _skp_update_master($pdo, $clientId, $ktp, $npwp);
        $blockMsg = '';
        if ($doSubmit && ($miss = _skp_missing_required($pdo, $id))) {
            $doSubmit = false;
            $blockMsg = 'Belum bisa submit — lengkapi dulu: ' . implode(', ', $miss) . '. Disimpan sebagai draft.';
        }
        $newStatus = $doSubmit ? 'submitted' : 'draft';
        $sql = 'UPDATE skp_documents SET cp_name=:cp_name, ktp_pj=:ktp_pj, phone_pj=:phone_pj,
                seating_area=:seating_area, produk=:produk, status_sewa=:status_sewa,
                deposit_amount=:deposit_amount, admin_siup=:admin_siup, admin_npwp=:admin_npwp,
                admin_ktp=:admin_ktp, note=:note, status=:status, reject_note=NULL,
                submitted_at=' . ($doSubmit ? 'CURRENT_TIMESTAMP' : 'submitted_at') . ',
                updated_at=CURRENT_TIMESTAMP, updated_by=:uname
                WHERE id=:id AND property_id=:pid';
        $pdo->prepare($sql)->execute(array_merge($fields, [':status' => $newStatus, ':uname' => $uname, ':id' => $id, ':pid' => $pid]));
        audit($pdo, $doSubmit ? 'submit' : 'update', 'skp_documents', (string) $id, $fields);
        flash($blockMsg ?: ($doSubmit ? 'Dokumen disubmit untuk approval.' : 'Draft disimpan.'));
        redirect_to('skp_form', ['id' => $id]);
    }

    // INSERT baru (offer-based atau legacy transaksi). Selalu draft dulu →
    // setelah lampiran terproses & lolos validasi, baru dipromosikan ke submitted.
    $cols = array_keys($fields);
    $place = array_map(fn($c) => ':' . $c, $cols);
    $sql = 'INSERT INTO skp_documents (property_id, doc_type, offer_id, transaction_id, status, created_by, '
         . implode(', ', $cols) . ')
         VALUES (:pid, :doc, :offer, :trx, \'draft\', :uname, '
         . implode(', ', $place) . ')';
    $pdo->prepare($sql)->execute(array_merge($fields, [
        ':pid' => $pid, ':doc' => $docType, ':offer' => $offerId ?: null,
        ':trx' => $offerId ? null : ($trxId ?: null), ':uname' => $uname,
    ]));
    $newId = (int) $pdo->lastInsertId();
    _skp_handle_uploads($pdo, $newId, $uname, $clientId);
    _skp_update_master($pdo, $clientId, $ktp, $npwp);
    audit($pdo, 'create', 'skp_documents', (string) $newId, $fields);

    if ($doSubmit && ($miss = _skp_missing_required($pdo, $newId))) {
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
function _skp_create_transaction(PDO $pdo, array $skp, array $src, int $pid): int
{
    $start  = (string) $src['start_date'];
    $end    = (string) $src['end_date'];
    $months = (int) ($src['contract_months'] ?? 1);
    $total  = (float) ($src['final_amount'] ?: $src['total_calculated']);
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
        'module'           => $src['module'],
        'client_id'        => $src['client_id'] ?: null,
        'contact_id'       => $src['contact_id'] ?: null,
        'master_code'      => $src['master_code'],
        'period_key'       => substr($start, 0, 7),
        'content_note'     => $src['content_note'] ?? null,
        'start_date'       => $start,
        'end_date'         => $end,
        'quantity'         => (float) ($src['quantity'] ?? 1),
        'slots'            => (float) ($src['slots'] ?? 1),
        'area_sqm'         => (float) ($src['area_sqm'] ?? 0),
        'pricing_type'     => $src['pricing_type'],
        'unit_rate'        => (float) ($src['unit_rate'] ?? 0),
        'contract_months'  => $months ?: null,
        'billing_method'   => $spread ? 'spread' : 'anchor_cycle',
        'recurring_flag'   => (int) ($src['recurring_flag'] ?? 0),
        'cycle_recognition'=> $cycleRec,
        'total_calculated' => $total,
        'override_amount'  => $total,
        'final_amount'     => $total,
        'pic_name'         => $src['pic_name'] ?? null,
        'referrer_name'    => $src['referrer_name'] ?? null,
        'remarks'          => 'Dari ' . (($skp['doc_type'] ?? 'skp') === 'sks' ? 'SKS' : 'SKP') . ' ' . ($skp['skp_no'] ?? ''),
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

    $src = (int)($skp['offer_id'] ?? 0)
        ? _skp_source_from_offer($pdo, (int) $skp['offer_id'], $pid)
        : _skp_source($pdo, (int) $skp['transaction_id'], $pid);
    if (!$src) { flash('Sumber (penawaran/transaksi) tidak ditemukan.'); redirect_to('skp'); }

    // Nomor dokumen: {SKP|SKS}/{EW|PC}/{tahun}/{urut}
    $year  = (int) date('Y');
    $prop  = current_property();
    $code  = _skp_prop_code($prop['key'] ?? '');
    $prefix = ($skp['doc_type'] ?? 'skp') === 'sks' ? 'SKS' : 'SKP';
    $pdo->prepare('INSERT INTO skp_counters (property_id, year, last_no) VALUES (?, ?, 1)
                   ON DUPLICATE KEY UPDATE last_no = last_no + 1')->execute([$pid, $year]);
    $seq = (int) $pdo->query("SELECT last_no FROM skp_counters WHERE property_id = $pid AND year = $year")->fetchColumn();
    $skpNo = sprintf('%s/%s/%d/%03d', $prefix, $code, $year, $seq);

    // Snapshot nilai cetak
    $days  = _skp_days($src['start_date'], $src['end_date']);
    $total = (float) ($src['final_amount'] ?: $src['total_calculated']);
    $amt   = _skp_amounts($total, (float) $src['unit_rate'], (float) $skp['deposit_amount']);
    $snapshot = [
        'company_name' => $src['company_name'], 'npwp' => $src['npwp'], 'address' => $src['address'],
        'cp_name' => $skp['cp_name'] ?: $src['cp_name'], 'phone' => $skp['phone_pj'] ?: $src['cp_phone'],
        'ktp_pj' => $skp['ktp_pj'], 'business_type' => $src['business_type'], 'produk' => $skp['produk'],
        'location' => $src['location_name'] ?: $src['master_code'], 'floor' => $src['floor'],
        'area' => (float) ($src['area_sqm'] ?: $src['unit_area']), 'seating_area' => $skp['seating_area'],
        'start_date' => $src['start_date'], 'end_date' => $src['end_date'], 'days' => $days,
        'status_sewa' => $skp['status_sewa'],
        'admin_siup' => (int)$skp['admin_siup'], 'admin_npwp' => (int)$skp['admin_npwp'], 'admin_ktp' => (int)$skp['admin_ktp'],
        'amounts' => $amt, 'sales' => $src['pic_name'], 'property_name' => $prop['name'] ?? '',
        // Referensi penawaran (offer-based) + daftar lampiran terunggah → tampil di PDF & TTD.
        'offer_no' => $src['offer_no'] ?? null,
        'attachments' => _skp_attachment_list($pdo, $id),
    ];

    $signToken = bin2hex(random_bytes(20));
    $pdo->prepare(
        'UPDATE skp_documents SET status=\'approved\', skp_no=?, approved_by=?, approved_at=CURRENT_TIMESTAMP,
         snapshot_json=?, sign_token=? WHERE id=? AND property_id=?'
    )->execute([$skpNo, $_SESSION['user']['name'] ?? 'manager', json_encode($snapshot, JSON_UNESCAPED_UNICODE), $signToken, $id, $pid]);

    // Transaksi + alokasi terbit saat approve (offer-based, bila belum ada).
    // Inilah titik deal masuk ke Dashboard/Achievement/Recurring.
    $trxMsg = '';
    if (empty($skp['transaction_id']) && !empty($skp['offer_id'])) {
        try {
            $newTrxId = _skp_create_transaction($pdo, array_merge($skp, ['skp_no' => $skpNo]), $src, $pid);
            $pdo->prepare('UPDATE skp_documents SET transaction_id=? WHERE id=? AND property_id=?')->execute([$newTrxId, $id, $pid]);
            audit($pdo, 'create', 'transactions', (string) $newTrxId, ['from_skp' => $id, 'skp_no' => $skpNo]);
            $trxMsg = ' Transaksi #' . $newTrxId . ' terbit & masuk laporan.';
        } catch (Throwable $e) {
            $trxMsg = ' (Catatan: transaksi gagal dibuat otomatis — ' . $e->getMessage() . ')';
        }
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
    $st = $pdo->prepare('SELECT status FROM skp_documents WHERE id = ? AND property_id = ?');
    $st->execute([$id, $pid]);
    if ($st->fetchColumn() !== 'submitted') { flash('SKP tidak dalam status menunggu approval.'); redirect_to('skp_form', ['id' => $id]); }
    $pdo->prepare('UPDATE skp_documents SET status=\'rejected\', reject_note=? WHERE id=? AND property_id=?')
        ->execute([$note, $id, $pid]);
    audit($pdo, 'reject', 'skp_documents', (string) $id, ['note' => $note]);
    flash('SKP ditolak & dikembalikan ke sales.');
    redirect_to('skp_form', ['id' => $id]);
}

// ─── TTD basah (upload scan) — alternatif TTD online utk customer gaptek ──────
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
        flash('File scan SKP ber-TTD wajib diunggah.'); redirect_to('skp_form', ['id' => $id]);
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
    flash('SKP ditandai sudah ditandatangani (TTD basah, scan tersimpan).');
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
    $chk = fn($b) => !empty($b) ? '☑' : '☐';
    include __DIR__ . '/skp_print_template.php';
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
