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
    $stmt = $pdo->prepare(
        'SELECT s.*, t.master_code, t.start_date, t.end_date, c.company_name
         FROM skp_documents s
         JOIN transactions t ON t.id = s.transaction_id
         LEFT JOIN master_clients c ON c.id = t.client_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY s.id DESC'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    layout('Surat Konfirmasi Pameran (SKP)', function () use ($rows, $status) {
        $badge = [
            'draft'     => ['Draft', '#64748b', '#f1f5f9'],
            'submitted' => ['Menunggu Approval', '#92400e', '#fef3c7'],
            'approved'  => ['Disetujui · menunggu TTD', '#166534', '#dcfce7'],
            'signed'    => ['Ditandatangani', '#0369a1', '#e0f2fe'],
            'rejected'  => ['Ditolak', '#991b1b', '#fee2e2'],
        ];
        ?>
        <div class="toolbar" style="gap:8px;flex-wrap:wrap">
            <strong style="font-size:16px">Surat Konfirmasi Pameran</strong>
            <div style="margin-left:auto;display:flex;gap:6px">
                <?php foreach (['' => 'Semua', 'draft' => 'Draft', 'submitted' => 'Menunggu', 'approved' => 'Perlu TTD', 'signed' => 'Ditandatangani', 'rejected' => 'Ditolak'] as $k => $lbl): ?>
                    <a class="btn light" style="<?= $status === $k ? 'background:var(--primary,#0d9488);color:#fff' : '' ?>" href="?r=skp<?= $k ? '&status=' . $k : '' ?>"><?= $lbl ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="panel" style="margin-top:12px">
            <p style="margin:0 0 10px;color:var(--muted);font-size:13px">SKP dibuat dari halaman <strong>Detail Alokasi</strong> sebuah transaksi Exhibition (tombol "Buat SKP").</p>
            <div class="table-wrap">
                <table style="font-size:12.5px">
                    <thead><tr><th>No. SKP</th><th>Kode</th><th>Client</th><th>Periode</th><th>Status</th><th>Dibuat</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?><tr><td colspan="7" style="text-align:center;color:var(--muted);padding:24px">Belum ada SKP.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $r): $b = $badge[$r['status']] ?? $badge['draft']; ?>
                        <tr>
                            <td style="white-space:nowrap;font-weight:600"><?= h($r['skp_no'] ?? '—') ?></td>
                            <td><?= h($r['master_code']) ?></td>
                            <td><?= h($r['company_name'] ?? '-') ?></td>
                            <td style="white-space:nowrap;font-size:11.5px"><?= h(date('d/m/y', strtotime($r['start_date'])) . '–' . date('d/m/y', strtotime($r['end_date']))) ?></td>
                            <td><span class="badge" style="color:<?= $b[1] ?>;background:<?= $b[2] ?>"><?= $b[0] ?></span></td>
                            <td style="font-size:11.5px;color:var(--muted)"><?= h($r['created_by'] ?? '-') ?><br><?= h(substr($r['created_at'] ?? '', 0, 16)) ?></td>
                            <td style="white-space:nowrap">
                                <a class="btn light" href="?r=skp_form&id=<?= (int)$r['id'] ?>"><?= $r['status'] === 'draft' || $r['status'] === 'rejected' ? 'Edit' : 'Lihat' ?></a>
                                <?php if (in_array($r['status'], ['approved', 'signed'], true)): ?><a class="btn light" href="?r=skp_print&id=<?= (int)$r['id'] ?>" target="_blank">PDF</a><?php endif; ?>
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
    $trxId = (int) getv('transaction_id');
    $skp = null;

    if ($id) {
        $st = $pdo->prepare('SELECT * FROM skp_documents WHERE id = ? AND property_id = ?');
        $st->execute([$id, $pid]);
        $skp = $st->fetch();
        if (!$skp) { flash('SKP tidak ditemukan.'); redirect_to('skp'); }
        $trxId = (int) $skp['transaction_id'];
    } elseif ($trxId) {
        // Sudah ada SKP utk transaksi ini? arahkan ke edit.
        $ex = $pdo->prepare('SELECT id FROM skp_documents WHERE transaction_id = ? AND property_id = ?');
        $ex->execute([$trxId, $pid]);
        if ($exId = $ex->fetchColumn()) { redirect_to('skp_form', ['id' => (int)$exId]); }
    }

    $src = _skp_source($pdo, $trxId, $pid);
    if (!$src) { flash('Transaksi tidak ditemukan / bukan milik properti ini.'); redirect_to('transactions', ['module' => 'cl']); }
    if (($src['module'] ?? '') !== 'cl') { flash('SKP hanya untuk transaksi Exhibition.'); redirect_to('allocation_detail', ['id' => $trxId]); }

    $editable = !$skp || in_array($skp['status'], ['draft', 'rejected'], true);
    $days     = _skp_days($src['start_date'], $src['end_date']);
    $total    = (float) ($src['final_amount'] ?: $src['total_calculated']);
    $amt      = _skp_amounts($total, (float) $src['unit_rate'], (float) ($skp['deposit_amount'] ?? 0));
    $area     = (float) ($src['area_sqm'] ?: $src['unit_area']);

    // Default prefill (untuk SKP baru)
    $f = [
        'cp_name'      => $skp['phone_pj'] ?? null, // placeholder; isi di bawah
    ];
    $val = fn(string $k, $def = '') => h((string) ($skp[$k] ?? $def));

    layout(($skp ? ($editable ? 'Edit' : 'Lihat') : 'Buat') . ' SKP', function () use ($pdo, $skp, $src, $trxId, $editable, $days, $total, $amt, $area, $val, $pid) {
        $statusSewaDefault = !empty($src['renewal_status']) && $src['renewal_status'] !== 'none' ? 'Perpanjangan' : 'Baru';
        ?>
        <div class="toolbar" style="gap:8px"><a class="btn light" href="?r=allocation_detail&id=<?= $trxId ?>">← Detail Alokasi</a><a class="btn light" href="?r=skp">Daftar SKP</a></div>

        <?php if ($skp && $skp['status'] === 'rejected'): ?>
        <div class="panel" style="margin-top:10px;background:#fef2f2;border:1px solid #fecaca">
            <strong style="color:#991b1b">Ditolak manager.</strong> Catatan: <?= h($skp['reject_note'] ?? '-') ?>. Perbaiki lalu submit ulang.
        </div>
        <?php endif; ?>

        <form class="panel" method="post" action="?r=skp_save" style="margin-top:12px">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= (int)($skp['id'] ?? 0) ?>">
            <input type="hidden" name="transaction_id" value="<?= (int)$trxId ?>">
            <!-- action via hidden field (di-set onclick) — handler anti-double-submit
                 global men-disable tombol saat submit, jadi name/value tombol hilang. -->
            <input type="hidden" name="action" id="skp-action" value="save">

            <h3 style="margin-top:0">Identitas Penyewa</h3>
            <div class="form-grid">
                <div><label>Nama Perusahaan</label><input value="<?= h($src['company_name'] ?? '-') ?>" disabled></div>
                <div><label>Nama Penanggung Jawab</label><input name="cp_name" value="<?= $val('cp_name', $src['cp_name'] ?? '') ?>" <?= $editable ? '' : 'disabled' ?>></div>
                <div class="wide"><label>Alamat</label><input value="<?= h($src['address'] ?? '-') ?>" disabled></div>
                <div><label>Nomor KTP Penanggung Jawab</label><input name="ktp_pj" value="<?= $val('ktp_pj') ?>" inputmode="numeric" <?= $editable ? '' : 'disabled' ?>></div>
                <div><label>Nomor Telepon</label><input name="phone_pj" value="<?= $val('phone_pj', $src['cp_phone'] ?? '') ?>" <?= $editable ? '' : 'disabled' ?>></div>
            </div>

            <h3>Kelengkapan Administrasi</h3>
            <div style="display:flex;gap:22px;flex-wrap:wrap">
                <?php foreach (['admin_siup' => 'Copy SIUP', 'admin_npwp' => 'Copy NPWP', 'admin_ktp' => 'Copy KTP Penanggung Jawab'] as $k => $lbl): ?>
                <label style="display:flex;align-items:center;gap:7px;font-weight:600"><input type="checkbox" name="<?= $k ?>" value="1" <?= !empty($skp[$k]) ? 'checked' : '' ?> <?= $editable ? '' : 'disabled' ?> style="width:17px;height:17px"> <?= $lbl ?></label>
                <?php endforeach; ?>
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
                <div><label>Jaminan Area / Security Deposit</label><input name="deposit_amount" class="skp-dep-fmt" value="<?= $skp ? number_format((float)$skp['deposit_amount'], 0, ',', '.') : '' ?>" inputmode="numeric" placeholder="0" <?= $editable ? '' : 'disabled' ?>><input type="hidden" name="deposit_raw" class="skp-dep-val" value="<?= (int)($skp['deposit_amount'] ?? 0) ?>"></div>
                <div><label>Grand Total (estimasi)</label><input id="skp-grand" value="<?= money($amt['grand_total']) ?>" disabled></div>
            </div>

            <h3>Catatan Internal</h3>
            <textarea name="note" rows="2" <?= $editable ? '' : 'disabled' ?>><?= h($skp['note'] ?? '') ?></textarea>

            <?php if ($editable): ?>
            <p style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
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
            $waText = rawurlencode("Yth. " . ($skp['cp_name'] ?: 'Bapak/Ibu') . ", mohon tanda tangan SKP No. " . $skp['skp_no'] . " pada tautan berikut: " . $signUrl);
        ?>
        <div class="panel" style="margin-top:12px;background:#f0fdf4;border:1px solid #bbf7d0">
            <strong style="color:#166534">Disetujui</strong> — No. <strong><?= h($skp['skp_no']) ?></strong> oleh <?= h($skp['approved_by']) ?> (<?= h(substr($skp['approved_at'], 0, 16)) ?>).
            <a class="btn" style="margin-left:10px" href="?r=skp_print&id=<?= (int)$skp['id'] ?>" target="_blank">🖨 Cetak / Simpan PDF</a>
        </div>
        <div class="panel" style="margin-top:12px;border:1px solid #bae6fd;background:#f0f9ff">
            <h3 style="margin-top:0;color:#0369a1">Tanda Tangan Customer (online)</h3>
            <?php if ($skp['status'] === 'signed'): ?>
                <p style="margin:0;color:#166534">✓ <strong>Sudah ditandatangani</strong> oleh <strong><?= h($skp['sign_name']) ?></strong> pada <?= h(substr($skp['signed_at'], 0, 16)) ?> (IP <?= h($skp['sign_ip']) ?>).</p>
            <?php else: ?>
                <p style="margin:0 0 8px;color:#374151">Kirim tautan ini ke customer untuk tanda tangan. Setelah ditandatangani, dokumen final lengkap dengan TTD.</p>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <input id="skp-sign-url" value="<?= h($signUrl) ?>" readonly style="flex:1;min-width:260px;font-size:12px" onclick="this.select()">
                    <button type="button" class="btn light" onclick="navigator.clipboard.writeText(document.getElementById('skp-sign-url').value);this.textContent='Tersalin ✓'">Salin Link</button>
                    <a class="btn" style="background:#16a34a" target="_blank" href="https://wa.me/?text=<?= $waText ?>">Kirim via WhatsApp</a>
                </div>
                <p style="margin:8px 0 0;font-size:11.5px;color:#64748b">Tautan bersifat rahasia — siapa pun yang memilikinya dapat menandatangani. Berlaku sampai SKP ditandatangani.</p>
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
function skp_save(PDO $pdo): void
{
    require_permission('manage_skp');
    verify_csrf();
    $pid    = current_property_id();
    $id     = (int) post('id');
    $trxId  = (int) post('transaction_id');
    $doSubmit = post('action') === 'submit';

    $src = _skp_source($pdo, $trxId, $pid);
    if (!$src || ($src['module'] ?? '') !== 'cl') { flash('Transaksi tidak valid untuk SKP.'); redirect_to('skp'); }

    $fields = [
        'cp_name'       => trim((string) post('cp_name')) ?: null,
        'ktp_pj'        => trim((string) post('ktp_pj')) ?: null,
        'phone_pj'      => trim((string) post('phone_pj')) ?: null,
        'seating_area'  => post('seating_area') !== '' ? (float) str_replace(',', '.', post('seating_area')) : null,
        'produk'        => trim((string) post('produk')) ?: null,
        'status_sewa'   => post('status_sewa') ?: 'Baru',
        'deposit_amount'=> (float) post('deposit_raw', 0),
        'admin_siup'    => post('admin_siup') ? 1 : 0,
        'admin_npwp'    => post('admin_npwp') ? 1 : 0,
        'admin_ktp'     => post('admin_ktp') ? 1 : 0,
        'note'          => trim((string) post('note')) ?: null,
    ];
    $uname = $_SESSION['user']['name'] ?? 'system';

    if ($id) {
        $cur = $pdo->prepare('SELECT status FROM skp_documents WHERE id = ? AND property_id = ?');
        $cur->execute([$id, $pid]);
        $st = $cur->fetchColumn();
        if (!in_array($st, ['draft', 'rejected'], true)) { flash('SKP sudah disubmit/disetujui — tidak bisa diubah.'); redirect_to('skp_form', ['id' => $id]); }
        $newStatus = $doSubmit ? 'submitted' : 'draft';
        $sql = 'UPDATE skp_documents SET cp_name=:cp_name, ktp_pj=:ktp_pj, phone_pj=:phone_pj,
                seating_area=:seating_area, produk=:produk, status_sewa=:status_sewa,
                deposit_amount=:deposit_amount, admin_siup=:admin_siup, admin_npwp=:admin_npwp,
                admin_ktp=:admin_ktp, note=:note, status=:status, reject_note=NULL,
                submitted_at=' . ($doSubmit ? 'CURRENT_TIMESTAMP' : 'submitted_at') . ',
                updated_at=CURRENT_TIMESTAMP, updated_by=:uname
                WHERE id=:id AND property_id=:pid';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($fields, [':status' => $newStatus, ':uname' => $uname, ':id' => $id, ':pid' => $pid]));
        audit($pdo, $doSubmit ? 'submit' : 'update', 'skp_documents', (string) $id, $fields);
        flash($doSubmit ? 'SKP disubmit untuk approval.' : 'Draft SKP disimpan.');
        redirect_to('skp_form', ['id' => $id]);
    }

    // INSERT baru
    $newStatus = $doSubmit ? 'submitted' : 'draft';
    $cols = array_keys($fields);
    $place = array_map(fn($c) => ':' . $c, $cols);
    $sql = 'INSERT INTO skp_documents (property_id, transaction_id, status, created_by, '
         . ($doSubmit ? 'submitted_at, ' : '') . implode(', ', $cols) . ')
         VALUES (:pid, :trx, :status, :uname, '
         . ($doSubmit ? 'CURRENT_TIMESTAMP, ' : '') . implode(', ', $place) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($fields, [':pid' => $pid, ':trx' => $trxId, ':status' => $newStatus, ':uname' => $uname]));
    $newId = (int) $pdo->lastInsertId();
    audit($pdo, 'create', 'skp_documents', (string) $newId, $fields);
    flash($doSubmit ? 'SKP dibuat & disubmit untuk approval.' : 'Draft SKP dibuat.');
    redirect_to('skp_form', ['id' => $newId]);
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

    $src = _skp_source($pdo, (int) $skp['transaction_id'], $pid);
    if (!$src) { flash('Transaksi sumber tidak ditemukan.'); redirect_to('skp'); }

    // Nomor SKP: SKP/{EW|PC}/{tahun}/{urut}
    $year  = (int) date('Y');
    $prop  = current_property();
    $code  = _skp_prop_code($prop['key'] ?? '');
    $pdo->prepare('INSERT INTO skp_counters (property_id, year, last_no) VALUES (?, ?, 1)
                   ON DUPLICATE KEY UPDATE last_no = last_no + 1')->execute([$pid, $year]);
    $seq = (int) $pdo->query("SELECT last_no FROM skp_counters WHERE property_id = $pid AND year = $year")->fetchColumn();
    $skpNo = sprintf('SKP/%s/%d/%03d', $code, $year, $seq);

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
    ];

    $signToken = bin2hex(random_bytes(20));
    $pdo->prepare(
        'UPDATE skp_documents SET status=\'approved\', skp_no=?, approved_by=?, approved_at=CURRENT_TIMESTAMP,
         snapshot_json=?, sign_token=? WHERE id=? AND property_id=?'
    )->execute([$skpNo, $_SESSION['user']['name'] ?? 'manager', json_encode($snapshot, JSON_UNESCAPED_UNICODE), $signToken, $id, $pid]);
    audit($pdo, 'approve', 'skp_documents', (string) $id, ['skp_no' => $skpNo]);
    flash("SKP disetujui. Nomor terbit: $skpNo");
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
