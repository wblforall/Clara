<?php
// ─── Formulir Permintaan Pembuatan/Review Kontrak ke Departemen Legal ─────────
// Langkah SETELAH SKP/SKS ditandatangani customer. Mode "form generator":
// sales isi (auto dari SKP) → simpan → cetak PDF utk dikirim ke Legal.
// Lihat [[project-offer-pipeline]].

function _cr_prop_code(string $key): string
{
    return match ($key) { 'ewalk' => 'EW', 'pentacity' => 'PC', default => strtoupper(substr($key, 0, 2)) };
}

function _cr_contract_types(): array
{
    return ['spk' => 'SPK', 'sewa_menyewa' => 'Sewa Menyewa', 'kerja_sama' => 'Kerja Sama'];
}

/** Semua lampiran terkait permintaan: dari SKP (KTP/NPWP/Bukti/Pengajuan) + form (Akta/Surat Kuasa). */
function _cr_all_attachments(PDO $pdo, array $cr): array
{
    $at = $pdo->prepare('SELECT kind, file_path FROM skp_attachments WHERE skp_id = ?');
    $at->execute([(int) ($cr['skp_id'] ?? 0)]);
    $m = [];
    foreach ($at->fetchAll() as $a) $m[$a['kind']] = $a['file_path'];
    $out = [];
    foreach (['ktp' => 'KTP Penanggung Jawab/Direktur', 'npwp' => 'NPWP', 'bukti_transfer' => 'Bukti Transfer', 'pengajuan' => 'Pengajuan'] as $k => $l) {
        if (!empty($m[$k])) $out[] = [$l, $m[$k]];
    }
    if (!empty($cr['akta_path']))        $out[] = ['Akta Pendirian dan/atau Akta Perubahan', $cr['akta_path']];
    if (!empty($cr['surat_kuasa_path'])) $out[] = ['Surat Kuasa', $cr['surat_kuasa_path']];
    return $out;
}

/** Data SKP final (utk dilampirkan ke berkas Legal). */
function _cr_skp_final(PDO $pdo, int $skpId): ?array
{
    $st = $pdo->prepare('SELECT skp_no, doc_type, status, approved_by, sign_method, signed_doc_path, sign_token, sign_name, signed_at, signature_data, snapshot_json FROM skp_documents WHERE id = ?');
    $st->execute([$skpId]);
    $r = $st->fetch();
    if (!$r || !in_array($r['status'], ['approved', 'signed'], true)) return null;
    $r['snap'] = json_decode($r['snapshot_json'] ?? '', true) ?: [];
    return $r;
}

/** Ambil SKP (harus signed) + data ringkas utk autofill formulir. */
function _cr_skp_context(PDO $pdo, int $skpId, int $pid): ?array
{
    $st = $pdo->prepare('SELECT * FROM skp_documents WHERE id = ? AND property_id = ?');
    $st->execute([$skpId, $pid]);
    $skp = $st->fetch();
    if (!$skp) return null;
    $d = json_decode($skp['snapshot_json'] ?? '', true) ?: [];
    // Deteksi lampiran KTP/NPWP yang sudah terunggah di SKP.
    $at = $pdo->prepare('SELECT kind FROM skp_attachments WHERE skp_id = ?');
    $at->execute([$skpId]);
    $kinds = array_column($at->fetchAll(), 'kind');
    return [
        'skp'          => $skp,
        'skp_no'       => $skp['skp_no'] ?? '',
        'company_name' => $d['company_name'] ?? '',
        'cp_name'      => $d['cp_name'] ?? '',
        'sales'        => $d['sales'] ?? '',
        'has_ktp'      => in_array('ktp', $kinds, true),
        'has_npwp'     => in_array('npwp', $kinds, true),
    ];
}

// ─── Daftar ──────────────────────────────────────────────────────────────────
function contract_request_list(PDO $pdo): void
{
    require_permission('manage_skp');
    $pid = current_property_id();
    $stmt = $pdo->prepare(
        'SELECT cr.*, s.skp_no, s.doc_type
         FROM contract_requests cr
         JOIN skp_documents s ON s.id = cr.skp_id
         WHERE cr.property_id = ?
         ORDER BY cr.id DESC'
    );
    $stmt->execute([$pid]);
    $rows = $stmt->fetchAll();

    layout('Permintaan Kontrak ke Legal', function () use ($rows) {
        $badge = [
            'draft'    => ['Draft', '#64748b', '#f1f5f9'],
            'sent'     => ['Terkirim ke Legal', '#92400e', '#fef3c7'],
            'approved' => ['Disetujui Legal', '#166534', '#dcfce7'],
        ];
        $types = _cr_contract_types();
        ?>
        <div class="toolbar" style="gap:8px;flex-wrap:wrap">
            <strong style="font-size:16px">Permintaan Pembuatan/Review Kontrak</strong>
        </div>
        <div class="panel" style="margin-top:12px">
            <p style="margin:0 0 10px;color:var(--muted);font-size:13px">Dibuat dari halaman <strong>SKP</strong> yang sudah ditandatangani customer (tombol "Ajukan Kontrak ke Legal"). Cetak PDF lalu kirim ke Departemen Legal beserta SKP &amp; Surat Penawaran.</p>
            <div class="table-wrap">
                <table class="mobile-cards" style="font-size:12.5px">
                    <thead><tr><th>No. Formulir</th><th>SKP</th><th>Jenis Kontrak</th><th>Status</th><th>Dibuat</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?><tr class="mc-full"><td colspan="6" style="text-align:center;color:var(--muted);padding:24px">Belum ada permintaan kontrak.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $r): $b = $badge[$r['status']] ?? $badge['draft']; ?>
                        <tr>
                            <td data-label="No. Formulir" style="white-space:nowrap;font-weight:600"><?= h($r['req_no'] ?? '—') ?></td>
                            <td data-label="SKP" style="white-space:nowrap"><?= h($r['skp_no'] ?? '—') ?></td>
                            <td data-label="Jenis Kontrak"><?= h($types[$r['contract_type']] ?? 'Sewa Menyewa') ?></td>
                            <td data-label="Status"><span class="badge" style="color:<?= $b[1] ?>;background:<?= $b[2] ?>"><?= $b[0] ?></span></td>
                            <td data-label="Dibuat" style="font-size:11.5px;color:var(--muted)"><?= h($r['created_by'] ?? '-') ?><br><?= h(substr($r['created_at'] ?? '', 0, 16)) ?></td>
                            <td style="white-space:nowrap">
                                <a class="btn light" href="?r=contract_request_form&id=<?= (int)$r['id'] ?>"><?= $r['status'] === 'draft' ? 'Edit' : 'Lihat' ?></a>
                                <a class="btn light" href="?r=contract_request_print&id=<?= (int)$r['id'] ?>" target="_blank">PDF</a>
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

// ─── Form (buat dari SKP / edit) ─────────────────────────────────────────────
function contract_request_form(PDO $pdo): void
{
    require_permission('manage_skp');
    $pid = current_property_id();
    $id  = (int) getv('id');
    $cr = null; $ctx = null;

    if ($id) {
        $st = $pdo->prepare('SELECT * FROM contract_requests WHERE id = ? AND property_id = ?');
        $st->execute([$id, $pid]);
        $cr = $st->fetch();
        if (!$cr) { flash('Permintaan kontrak tidak ditemukan.'); redirect_to('contract_requests'); }
        $ctx = _cr_skp_context($pdo, (int) $cr['skp_id'], $pid);
    } else {
        $skpId = (int) getv('skp_id');
        $ctx = _cr_skp_context($pdo, $skpId, $pid);
        if (!$ctx) { flash('SKP tidak ditemukan.'); redirect_to('skp'); }
        if ($ctx['skp']['status'] !== 'signed') { flash('Permintaan kontrak hanya bisa dibuat setelah SKP ditandatangani customer.'); redirect_to('skp_form', ['id' => $skpId]); }
        // Cegah duplikat: bila sudah ada formulir utk SKP ini → buka yang ada.
        $ex = $pdo->prepare('SELECT id FROM contract_requests WHERE skp_id = ? AND property_id = ? LIMIT 1');
        $ex->execute([$skpId, $pid]);
        if ($exId = $ex->fetchColumn()) { redirect_to('contract_request_form', ['id' => (int) $exId]); }
    }

    // Bisa diedit/dilengkapi selama belum disetujui Legal (draft & terkirim).
    $editable = !$cr || in_array($cr['status'], ['draft', 'sent'], true);
    $sent     = $cr && $cr['status'] === 'sent';
    $skpId    = (int) ($cr['skp_id'] ?? getv('skp_id'));
    $me       = $_SESSION['user']['name'] ?? '';
    $v = fn(string $k, $def = '') => h((string) ($cr[$k] ?? $def));

    layout(($cr ? ($editable ? 'Edit' : 'Lihat') : 'Buat') . ' Permintaan Kontrak', function () use ($pdo, $cr, $id, $skpId, $ctx, $editable, $sent, $me, $v) {
        $dis = $editable ? '' : 'disabled';
        // Tercentang bila dokumennya MEMANG ADA: KTP/NPWP dari lampiran SKP,
        // Akta/Surat Kuasa dari file yang sudah diunggah (atau flag tersimpan).
        $ktp  = !empty($cr['doc_ktp'])  || !empty($ctx['has_ktp']);
        $npwp = !empty($cr['doc_npwp']) || !empty($ctx['has_npwp']);
        $akta = !empty($cr['doc_akta']) || !empty($cr['akta_path']);
        $sk   = !empty($cr['doc_surat_kuasa']) || !empty($cr['surat_kuasa_path']);
        ?>
        <div class="toolbar" style="gap:8px;flex-wrap:wrap">
            <a class="btn light" href="?r=skp_form&id=<?= (int)$skpId ?>">← SKP</a>
            <a class="btn light" href="?r=contract_requests">Daftar Permintaan</a>
            <?php if ($cr): ?><a class="btn light" href="?r=contract_request_print&id=<?= (int)$cr['id'] ?>" target="_blank">🖨 PDF Formulir</a><?php endif; ?>
        </div>

        <div class="panel" style="margin-top:12px">
            <strong>Formulir Permintaan Pembuatan/Review Kontrak</strong>
            · SKP: <strong><?= h($ctx['skp_no'] ?: '-') ?></strong>
            · Penyewa: <strong><?= h($ctx['company_name'] ?: '-') ?></strong>
            <?php if ($cr && $cr['req_no']): ?> · No: <strong><?= h($cr['req_no']) ?></strong><?php endif; ?>
            <?php if ($cr && $cr['status'] === 'sent'): ?> · <span class="badge" style="color:#92400e;background:#fef3c7">Terkirim ke Legal</span><?php endif; ?>
            <?php if ($cr && $cr['status'] === 'approved'): ?> · <span class="badge" style="color:#166534;background:#dcfce7">✓ Disetujui Legal</span><?php endif; ?>
        </div>

        <?php if ($cr && $cr['status'] === 'approved'): ?>
        <div class="panel" style="margin-top:10px;background:#ecfdf5;border-color:#a7f3d0">
            <strong style="color:#047857">✓ Disetujui Departemen Legal</strong>
            <?php if (!empty($cr['legal_by'])): ?> oleh <strong><?= h($cr['legal_by']) ?></strong><?php endif; ?>
            <?php if (!empty($cr['legal_approved_at'])): ?> pada <?= h(date('d/m/Y H:i', strtotime($cr['legal_approved_at']))) ?><?php endif; ?>.
            <?php if (!empty($cr['legal_note'])): ?><div style="margin-top:6px;font-size:13px">Catatan Legal: “<?= h($cr['legal_note']) ?>”</div><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($cr && in_array($cr['status'], ['sent', 'approved'], true) && !empty($cr['share_token'])):
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
            $legalUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/?r=contract_legal&token=' . $cr['share_token'];
            $waMsg = "Yth. Departemen Legal,\n\n"
                . "Mohon dibantu pembuatan/review kontrak atas Permintaan No. " . ($cr['req_no'] ?: '-') . " (" . ($ctx['company_name'] ?: '-') . ").\n\n"
                . "Formulir beserta lampiran (Akta / Surat Kuasa) dapat diakses pada tautan berikut:\n" . $legalUrl . "\n\n"
                . "SKP & Surat Penawaran final menyusul terpisah. Terima kasih.";
            $waText = rawurlencode($waMsg);
        ?>
        <div class="panel" style="margin-top:12px;border:1px solid #ddd6fe;background:#f5f3ff">
            <h3 style="margin-top:0;color:#6d28d9">Kirim ke Legal (link)</h3>
            <p style="margin:0 0 8px;color:#374151">Bagikan tautan ini ke Departemen Legal — mereka bisa membuka formulir &amp; mengunduh lampiran (Akta / Surat Kuasa) tanpa login.</p>
            <textarea id="cr-wa-msg" style="position:absolute;left:-9999px" readonly><?= h($waMsg) ?></textarea>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <input id="cr-legal-url" value="<?= h($legalUrl) ?>" readonly style="flex:1;min-width:260px;font-size:12px" onclick="this.select()">
                <button type="button" class="btn light" onclick="navigator.clipboard.writeText(document.getElementById('cr-legal-url').value);this.textContent='Tersalin ✓'">Salin Link</button>
                <button type="button" class="btn light" onclick="navigator.clipboard.writeText(document.getElementById('cr-wa-msg').value);this.textContent='Pesan tersalin ✓'">Salin Pesan</button>
                <a class="btn" style="background:#16a34a" target="_blank" href="https://wa.me/?text=<?= $waText ?>">Kirim via WhatsApp</a>
            </div>
            <p style="margin:8px 0 0;font-size:11.5px;color:#64748b"><strong>Jika lewat WhatsApp Desktop hanya link yang terkirim</strong>, gunakan <strong>Salin Pesan</strong> lalu tempel (paste) di chat.</p>
        </div>
        <?php endif; ?>

        <form class="panel" method="post" action="?r=contract_request_save" style="margin-top:12px" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= (int)($cr['id'] ?? 0) ?>">
            <input type="hidden" name="skp_id" value="<?= (int)$skpId ?>">
            <input type="hidden" name="action" id="cr-action" value="save">

            <h3 style="margin-top:0">Informasi Umum</h3>
            <div class="form-grid">
                <div><label>Departemen Pemohon</label><input name="department" value="<?= $v('department', 'Casual Leasing') ?>" <?= $dis ?>></div>
                <div><label>Tanggal Pengajuan</label><input type="date" name="request_date" value="<?= $v('request_date', date('Y-m-d')) ?>" <?= $dis ?>></div>
                <div><label>Nama Penanggung Jawab</label><input name="requester_name" value="<?= $v('requester_name', $me) ?>" <?= $dis ?>></div>
                <div><label>Jabatan</label><input name="requester_position" value="<?= $v('requester_position') ?>" placeholder="mis. Staff Marketing" <?= $dis ?>></div>
            </div>

            <h3>Kelengkapan Dokumen Legalitas</h3>
            <p class="help" style="margin-top:0">KTP &amp; NPWP otomatis tercentang bila lampirannya sudah ada di SKP. <strong>Akta &amp; Surat Kuasa</strong> bisa diunggah di sini (untuk PT/CV/Yayasan/Koperasi/BUMN-D) agar ikut terlampir saat dikirim ke Legal.</p>
            <div style="display:flex;flex-direction:column;gap:8px;max-width:820px">
                <?php
                $checks = [
                    'doc_ktp'  => ['Kartu Identitas/KTP Penanggung Jawab/Direktur/Kuasa Direksi', $ktp],
                    'doc_npwp' => ['NPWP (Pribadi / Perusahaan bila berbadan hukum)', $npwp],
                ];
                foreach ($checks as $name => [$lbl, $on]): ?>
                <label style="display:flex;align-items:flex-start;gap:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;cursor:pointer">
                    <input type="checkbox" name="<?= $name ?>" value="1" style="width:18px;height:18px;flex-shrink:0;margin-top:1px" <?= $on ? 'checked' : '' ?> <?= $dis ?>>
                    <span style="font-size:13px"><?= h($lbl) ?></span>
                </label>
                <?php endforeach; ?>
                <?php
                $uploads = [
                    'akta'        => ['doc_akta', 'Akta Pendirian dan/atau Akta Perubahan (PT/Yayasan/Koperasi/BUMN-D)', $akta, $cr['akta_path'] ?? null],
                    'surat_kuasa' => ['doc_surat_kuasa', 'Surat Kuasa (bila penanda tangan bukan direktur)', $sk, $cr['surat_kuasa_path'] ?? null],
                ];
                foreach ($uploads as $key => [$flag, $lbl, $on, $path]): ?>
                <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px">
                    <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin:0">
                        <input type="checkbox" name="<?= $flag ?>" value="1" style="width:18px;height:18px;flex-shrink:0;margin-top:1px" <?= $on ? 'checked' : '' ?> <?= $dis ?>>
                        <span style="font-size:13px"><?= h($lbl) ?></span>
                    </label>
                    <div style="margin-top:8px;margin-left:28px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                        <?php if ($path): ?>
                            <a class="btn light" href="<?= h($path) ?>" target="_blank">📎 Lihat file</a>
                        <?php endif; ?>
                        <?php if ($editable): ?>
                            <input type="file" name="file_<?= $key ?>" accept="image/*,.pdf">
                            <span class="help" style="font-size:11px"><?= $path ? 'Pilih file untuk mengganti.' : 'jpg/png/pdf, ≤8MB. Centang otomatis bila diunggah.' ?></span>
                        <?php elseif (!$path): ?>
                            <span class="help" style="font-size:11px;color:#991b1b">Tidak ada file.</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <h3>Poin-Poin Penting untuk Kontrak</h3>
            <p class="help" style="margin-top:0">Selain yang sudah tercantum di SKP / Surat Penawaran, atau hal lain yang perlu diperjelas.</p>
            <textarea name="important_points" rows="4" style="width:100%" placeholder="mis. denda keterlambatan, klausul perpanjangan, dll." <?= $dis ?>><?= $v('important_points') ?></textarea>

            <?php if ($editable && $sent): ?>
            <p class="help" style="margin-top:16px;color:#92400e">Sudah terkirim ke Legal — masih bisa <strong>melengkapi/mengganti dokumen</strong> selama Legal belum menyetujui.</p>
            <p class="form-actions" style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
                <button type="submit" onclick="document.getElementById('cr-action').value='save'">💾 Simpan Perubahan</button>
                <a class="btn secondary" href="?r=contract_requests">Selesai</a>
            </p>
            <?php elseif ($editable): ?>
            <p class="form-actions" style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">
                <button type="submit" onclick="document.getElementById('cr-action').value='save'">💾 Simpan Draft</button>
                <button type="submit" class="btn" style="background:#166534" onclick="document.getElementById('cr-action').value='send';return confirm('Tandai formulir TERKIRIM ke Legal? Nomor formulir akan terbit.')">📤 Simpan &amp; Tandai Terkirim</button>
                <a class="btn secondary" href="?r=contract_requests">Batal</a>
            </p>
            <?php endif; ?>
        </form>
        <?php
    });
}

/** Simpan upload Akta / Surat Kuasa → kolom path + auto-centang doc flag. */
function _cr_handle_uploads(PDO $pdo, int $crId): void
{
    $dir = dirname(__DIR__, 2) . '/public/uploads/contract';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $map = ['file_akta' => ['akta_path', 'doc_akta'], 'file_surat_kuasa' => ['surat_kuasa_path', 'doc_surat_kuasa']];
    foreach ($map as $field => [$pathCol, $flagCol]) {
        if (empty($_FILES[$field]['tmp_name']) || !is_uploaded_file($_FILES[$field]['tmp_name'])) continue;
        $f = $_FILES[$field];
        if ($f['size'] <= 0 || $f['size'] > 8 * 1024 * 1024) continue;
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) continue;
        $fname = 'cr' . $crId . '_' . substr($field, 5) . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $fname)) continue;
        $pdo->prepare("UPDATE contract_requests SET $pathCol=?, $flagCol=1 WHERE id=?")
            ->execute(['uploads/contract/' . $fname, $crId]);
    }
}

// ─── Simpan ──────────────────────────────────────────────────────────────────
function contract_request_save(PDO $pdo): void
{
    require_permission('manage_skp');
    verify_csrf();
    $pid   = current_property_id();
    $id    = (int) post('id');
    $skpId = (int) post('skp_id');
    $uname = $_SESSION['user']['name'] ?? 'system';
    $action = post('action') === 'send' ? 'send' : 'save';

    // Validasi SKP signed (untuk pembuatan baru).
    $ctx = _cr_skp_context($pdo, $skpId, $pid);
    if (!$ctx) { flash('SKP tidak ditemukan.'); redirect_to('skp'); }

    $data = [
        'department'         => trim((string) post('department')) ?: 'Casual Leasing',
        'requester_name'     => trim((string) post('requester_name')) ?: null,
        'requester_position' => trim((string) post('requester_position')) ?: null,
        'request_date'       => post('request_date') ?: date('Y-m-d'),
        'contract_type'      => 'sewa_menyewa',  // CLARA hanya menangani sewa — default tetap.
        // KTP/NPWP otoritatif dari lampiran SKP — selalu tercentang bila ada.
        'doc_ktp'            => (post('doc_ktp') || !empty($ctx['has_ktp']))  ? 1 : 0,
        'doc_npwp'           => (post('doc_npwp') || !empty($ctx['has_npwp'])) ? 1 : 0,
        'doc_akta'           => post('doc_akta') ? 1 : 0,
        'doc_surat_kuasa'    => post('doc_surat_kuasa') ? 1 : 0,
        'important_points'   => trim((string) post('important_points')) ?: null,
    ];

    if ($id) {
        $cur = $pdo->prepare('SELECT * FROM contract_requests WHERE id=? AND property_id=?');
        $cur->execute([$id, $pid]);
        $cr = $cur->fetch();
        if (!$cr) { flash('Permintaan tidak ditemukan.'); redirect_to('contract_requests'); }
        if ($cr['status'] === 'approved') { flash('Sudah disetujui Legal — tidak bisa diubah.'); redirect_to('contract_request_form', ['id' => $id]); }
        $sets = []; $vals = [];
        foreach ($data as $k => $val) { $sets[] = "$k=:$k"; $vals[":$k"] = $val; }
        $vals[':id'] = $id; $vals[':pid'] = $pid;
        $pdo->prepare('UPDATE contract_requests SET ' . implode(', ', $sets) . ' WHERE id=:id AND property_id=:pid')->execute($vals);
    } else {
        if ($ctx['skp']['status'] !== 'signed') { flash('SKP belum ditandatangani customer.'); redirect_to('skp_form', ['id' => $skpId]); }
        $cols = array_keys($data);
        $place = array_map(fn($c) => ':' . $c, $cols);
        $vals = [];
        foreach ($data as $k => $val) $vals[":$k"] = $val;
        $vals[':pid'] = $pid; $vals[':skp'] = $skpId; $vals[':by'] = $uname;
        $pdo->prepare('INSERT INTO contract_requests (property_id, skp_id, created_by, ' . implode(', ', $cols) . ')
                       VALUES (:pid, :skp, :by, ' . implode(', ', $place) . ')')->execute($vals);
        $id = (int) $pdo->lastInsertId();
    }

    // Upload Akta / Surat Kuasa (bila ada file baru) → set path + centang otomatis.
    _cr_handle_uploads($pdo, $id);

    if ($action === 'send') {
        // Terbitkan nomor formulir bila belum ada, set status terkirim.
        $cur = $pdo->prepare('SELECT req_no FROM contract_requests WHERE id=?');
        $cur->execute([$id]);
        $reqNo = $cur->fetchColumn();
        if (!$reqNo) {
            $year = (int) date('Y');
            $prop = current_property();
            $pdo->prepare('INSERT INTO contract_request_counters (property_id, year, last_no) VALUES (?,?,1)
                           ON DUPLICATE KEY UPDATE last_no=last_no+1')->execute([$pid, $year]);
            $seq = (int) $pdo->query("SELECT last_no FROM contract_request_counters WHERE property_id=$pid AND year=$year")->fetchColumn();
            $reqNo = sprintf('FPK/%s/%d/%03d', _cr_prop_code($prop['key'] ?? ''), $year, $seq);
        }
        // Token share utk link ke Legal (sekali terbit, dipakai ulang).
        $tok = $pdo->prepare('SELECT share_token FROM contract_requests WHERE id=?');
        $tok->execute([$id]);
        $shareToken = $tok->fetchColumn() ?: bin2hex(random_bytes(20));
        $pdo->prepare("UPDATE contract_requests SET status='sent', req_no=?, share_token=?, sent_at=CURRENT_TIMESTAMP WHERE id=? AND property_id=?")
            ->execute([$reqNo, $shareToken, $id, $pid]);
        flash("Formulir terkirim. No: $reqNo. Salin link / cetak PDF untuk Legal.");
    } elseif (($cr['status'] ?? '') === 'sent') {
        flash('Perubahan disimpan. Dokumen terbaru langsung terlihat oleh Legal melalui link.');
    } else {
        flash('Draft permintaan kontrak disimpan.');
    }
    redirect_to('contract_request_form', ['id' => $id]);
}

// ─── Cetak / PDF ─────────────────────────────────────────────────────────────
function contract_request_print(PDO $pdo): void
{
    require_permission('manage_skp');
    $pid = current_property_id();
    $id  = (int) getv('id');
    $st = $pdo->prepare('SELECT * FROM contract_requests WHERE id=? AND property_id=?');
    $st->execute([$id, $pid]);
    $cr = $st->fetch();
    if (!$cr) { http_response_code(404); exit('Formulir tidak ditemukan.'); }
    // Token utk QR validasi (terbit sekali, dipakai ulang).
    if (empty($cr['share_token'])) {
        $cr['share_token'] = bin2hex(random_bytes(20));
        $pdo->prepare('UPDATE contract_requests SET share_token=? WHERE id=? AND property_id=?')->execute([$cr['share_token'], $id, $pid]);
    }
    $ctx     = _cr_skp_context($pdo, (int) $cr['skp_id'], $pid);
    $allAtts = _cr_all_attachments($pdo, $cr);
    $skpFinal = _cr_skp_final($pdo, (int) $cr['skp_id']);
    $prop = current_property();
    $types = _cr_contract_types();
    $h  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $chk = fn($b) => !empty($b) ? '☑' : '☐';
    include __DIR__ . '/contract_request_template.php';
}

// ─── Halaman publik untuk Legal (via link, tanpa login) ──────────────────────
function contract_legal_page(PDO $pdo): void
{
    $token = (string) getv('token', '');
    $st = $pdo->prepare('SELECT * FROM contract_requests WHERE share_token = ? AND status IN ("sent","approved") LIMIT 1');
    $st->execute([$token]);
    $cr = $token !== '' ? $st->fetch() : false;
    if (!$cr) { http_response_code(404); exit('Tautan tidak valid atau formulir belum dikirim.'); }

    // Konteks SKP (lintas-properti aman: token rahasia).
    $sk = $pdo->prepare('SELECT skp_no, snapshot_json FROM skp_documents WHERE id = ?');
    $sk->execute([(int) $cr['skp_id']]);
    $skp = $sk->fetch() ?: [];
    $d = json_decode($skp['snapshot_json'] ?? '', true) ?: [];
    // Lampiran SKP (KTP/NPWP/Bukti Transfer/Pengajuan) — Legal butuh ini juga.
    $at = $pdo->prepare('SELECT kind, file_path, original_name FROM skp_attachments WHERE skp_id = ?');
    $at->execute([(int) $cr['skp_id']]);
    $skpAtts = [];
    foreach ($at->fetchAll() as $a) $skpAtts[$a['kind']] = $a;
    $skpFinal = _cr_skp_final($pdo, (int) $cr['skp_id']);
    $types = _cr_contract_types();
    $h  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $chk = fn($b) => !empty($b) ? '☑' : '☐';
    $months = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $rd = $cr['request_date'] ? strtotime($cr['request_date']) : time();
    $tglAju = (int) date('d', $rd) . ' ' . $months[(int) date('n', $rd)] . ' ' . date('Y', $rd);
    // Base URL utk unduh lampiran (relatif ke /public).
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $asset = fn($p) => $dir . '/' . ltrim((string) $p, '/');
    ?>
    <!doctype html>
    <html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Permintaan Kontrak <?= $h($cr['req_no']) ?></title>
    <style>
    *{box-sizing:border-box} body{font-family:'Inter',Arial,sans-serif;background:#f3f4f6;color:#111;margin:0;padding:18px;font-size:14px}
    .card{max-width:760px;margin:0 auto;background:#fff;border-radius:14px;box-shadow:0 6px 30px rgba(0,0,0,.08);overflow:hidden}
    .hd{background:#6d28d9;color:#fff;padding:18px 22px}
    .hd h1{margin:0;font-size:17px} .hd p{margin:4px 0 0;opacity:.9;font-size:13px}
    .bd{padding:20px 22px}
    .sec{font-weight:800;color:#6d28d9;text-transform:uppercase;font-size:12px;letter-spacing:.03em;margin:18px 0 8px}
    table{width:100%;border-collapse:collapse} td{padding:6px 8px;border:1px solid #e5e7eb;vertical-align:top}
    td.k{width:38%;background:#f8fafc;color:#374151}
    .chips span{display:inline-block;margin-right:18px;font-weight:600}
    .intro{font-size:12.5px;color:#374151;line-height:1.55;text-align:justify;margin-bottom:10px}
    table.leg td{vertical-align:middle} table.leg th{background:#f3f4f6;text-align:center;font-size:12.5px} table.leg th.j{text-align:left}
    table.leg td.c{text-align:center;width:84px;font-size:16px}
    .note{display:block;font-size:11px;font-style:italic;color:#6b7280;margin-top:2px}
    .sign{display:flex;gap:20px;margin-top:6px;text-align:center} .sign .col{flex:1} .sign .paren{margin-top:44px}
    .sign .qrbox{width:84px;height:84px;margin:8px auto 2px} .sign .qrbox svg,.sign .qrbox img{width:84px!important;height:84px!important;display:block}
    .sign .qrhint{font-size:10px;color:#6b7280} .sign .pnm{margin-top:4px;font-weight:700}
    .att a{display:inline-flex;align-items:center;gap:6px;background:#ede9fe;color:#5b21b6;text-decoration:none;border-radius:8px;padding:9px 14px;font-weight:700;margin:4px 8px 0 0}
    .lampimg{display:block;max-width:100%;border:1px solid #e5e7eb;border-radius:8px;margin:8px 0}
    .pts{border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;white-space:pre-wrap;line-height:1.6;background:#fafafa}
    .muted{color:#6b7280;font-size:12px}
    </style></head><body>
    <div class="card">
        <div class="hd" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
            <div>
                <h1>Formulir Permintaan Pembuatan/Review Kontrak</h1>
                <p>Kepada Departemen Legal · No. <?= $h($cr['req_no']) ?></p>
            </div>
            <a href="?r=contract_legal_print&token=<?= $h($cr['share_token']) ?>" target="_blank" style="background:#fff;color:#6d28d9;text-decoration:none;border-radius:8px;padding:9px 16px;font-weight:700;white-space:nowrap">🖨 Cetak (formulir + lampiran)</a>
        </div>
        <div class="bd">
            <div class="sec">Informasi Umum</div>
            <div class="intro">Formulir ini digunakan sebagai dasar bagi Departemen Legal untuk membuat dan/atau mereview kontrak yang diajukan oleh Departemen lain yang dilampirkan bersamaan dengan Surat Konfirmasi Pameran dan/atau Surat Penawaran.</div>
            <table>
                <tr><td class="k">Departemen Pemohon</td><td><?= $h($cr['department']) ?></td></tr>
                <tr><td class="k">Tanggal Pengajuan</td><td><?= $h($tglAju) ?></td></tr>
                <tr><td class="k">Nama Penanggung Jawab</td><td><?= $h($cr['requester_name'] ?: '-') ?></td></tr>
                <tr><td class="k">Jabatan</td><td><?= $h($cr['requester_position'] ?: '-') ?></td></tr>
            </table>

            <div class="sec">Jenis Kontrak</div>
            <div class="chips">
                <span><?= $chk($cr['contract_type'] === 'spk') ?> SPK</span>
                <span><?= $chk($cr['contract_type'] === 'sewa_menyewa') ?> Sewa Menyewa</span>
                <span><?= $chk($cr['contract_type'] === 'kerja_sama') ?> Kerja Sama</span>
            </div>

            <div class="sec">Kelengkapan Dokumen Legalitas</div>
            <table class="leg">
                <thead><tr><th class="j">Jenis Dokumen</th><th>Ada</th><th>Tidak Ada</th></tr></thead>
                <tbody>
                <?php
                $legal = [
                    ['Hardcopy Salinan Kartu Identitas/KTP Penanggung Jawab/Direktur/Kuasa Direksi', '*Melampirkan Kartu Identitas apabila bukan Warga Negara Indonesia', $cr['doc_ktp']],
                    ['Hardcopy NPWP', '*Melampirkan NPWP Perusahaan apabila Pihak Kedua berbentuk CV, PT, Yayasan, Koperasi, BUMN/BUMD', $cr['doc_npwp']],
                    ['Softcopy Akta Pendirian dan/atau Akta Perubahan', '*Dilampirkan apabila Pihak Kedua berbentuk PT, Yayasan, Koperasi, BUMN/BUMD', $cr['doc_akta']],
                    ['Softcopy Surat Kuasa', '*Dilampirkan hanya apabila Pihak Kedua berbentuk PT, Yayasan, Koperasi, BUMN/BUMD, tetapi yang bertanda tangan bukan direktur', $cr['doc_surat_kuasa']],
                ];
                foreach ($legal as [$lbl, $note, $on]): ?>
                    <tr><td><?= $h($lbl) ?><span class="note"><?= $h($note) ?></span></td><td class="c"><?= $chk($on) ?></td><td class="c"><?= $chk(!$on) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="sec">Poin-Poin Penting yang Perlu Dimasukkan ke Dalam Kontrak</div>
            <div class="muted" style="margin:0 0 4px">*selain yang tercantum di Surat Konfirmasi Pameran atau hal lain yang perlu diperjelas.</div>
            <div class="pts"><?= $h($cr['important_points'] ?: '-') ?></div>

            <p style="margin-top:16px">Dengan ini saya menyatakan bahwa informasi yang diberikan adalah benar dan lengkap.</p>
            <div style="text-align:right;font-size:13px">Disetujui oleh,</div>
            <?php $crVerifyUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/?r=contract_legal&token=' . $cr['share_token']; ?>
            <div class="sign">
                <div class="col">Departemen Pemohon,
                    <div class="qrbox" data-qr="<?= $h($crVerifyUrl) ?>"></div>
                    <div class="qrhint">Scan untuk validasi</div>
                    <div class="pnm">( <?= $h($cr['requester_name'] ?: '________') ?> )</div>
                </div>
                <div class="col">Departemen Legal
                    <?php if (($cr['status'] ?? '') === 'approved'): ?>
                        <div class="paren" style="margin-top:30px">( <?= $h($cr['legal_by'] ?: 'Disetujui') ?> )</div>
                        <div class="qrhint" style="color:#047857">✓ Disetujui <?= $h(substr((string) $cr['legal_approved_at'], 0, 16)) ?></div>
                    <?php else: ?>
                        <div class="paren">( ________ )</div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($skpFinal)): $sf = $skpFinal; $sd = $sf['snap']; $sa = $sd['amounts'] ?? [];
                $rpf = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
                $skpUrl = $asset('') === '/' ? '?r=skp_sign&token=' . $sf['sign_token'] : ($dir . '/?r=skp_sign&token=' . $sf['sign_token']);
            ?>
            <div class="sec">SKP Final</div>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 14px">
                <div><strong>No. <?= $h($sf['skp_no']) ?></strong> · <?= $h($sd['company_name'] ?? '-') ?> · <?= $h($sd['location'] ?? '-') ?></div>
                <div class="muted" style="margin-top:2px">Periode <?= $h(($sd['start_date'] ?? '') . ' s/d ' . ($sd['end_date'] ?? '')) ?> · Grand Total <?= $rpf($sa['grand_total'] ?? 0) ?> · <?= ($sf['sign_method'] ?? '') === 'wet' ? 'TTD basah' : 'TTD elektronik' ?></div>
                <div class="att" style="margin-top:8px">
                    <a href="<?= $h($skpUrl) ?>" target="_blank">📄 Buka SKP Final</a>
                    <?php if (($sf['sign_method'] ?? '') === 'wet' && !empty($sf['signed_doc_path'])): ?>
                        <a href="<?= $h($asset($sf['signed_doc_path'])) ?>" target="_blank">🖋️ Scan SKP ber-TTD</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="sec">Lampiran Dokumen</div>
            <?php
            $lampiran = [];
            foreach (['ktp' => 'KTP Penanggung Jawab/Direktur', 'npwp' => 'NPWP', 'bukti_transfer' => 'Bukti Transfer', 'pengajuan' => 'Pengajuan'] as $k => $lbl) {
                if (!empty($skpAtts[$k]['file_path'])) $lampiran[] = [$lbl, $skpAtts[$k]['file_path']];
            }
            if (!empty($cr['akta_path']))        $lampiran[] = ['Akta Pendirian dan/atau Akta Perubahan', $cr['akta_path']];
            if (!empty($cr['surat_kuasa_path'])) $lampiran[] = ['Surat Kuasa', $cr['surat_kuasa_path']];
            if (!$lampiran): ?>
                <span class="muted">Belum ada lampiran dokumen.</span>
            <?php else: foreach ($lampiran as [$lbl, $path]):
                $ext = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
                $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
            ?>
                <div style="margin-top:10px">
                    <div style="font-weight:700;margin-bottom:4px"><?= $h($lbl) ?></div>
                    <?php if ($isImg): ?>
                        <img class="lampimg" src="<?= $h($asset($path)) ?>" alt="<?= $h($lbl) ?>">
                    <?php else: ?>
                        <div class="att"><a href="<?= $h($asset($path)) ?>" target="_blank">📎 Buka <?= $h(strtoupper($ext)) ?> — <?= $h($lbl) ?></a></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; endif; ?>

            <div class="sec">Persetujuan Legal</div>
            <?php if ($cr['status'] === 'approved'): ?>
                <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:10px;padding:14px 16px">
                    <strong style="color:#047857">✓ Sudah disetujui Legal</strong>
                    <?php if (!empty($cr['legal_by'])): ?> oleh <strong><?= $h($cr['legal_by']) ?></strong><?php endif; ?>
                    <?php if (!empty($cr['legal_approved_at'])): ?> pada <?= $h(date('d/m/Y H:i', strtotime($cr['legal_approved_at']))) ?><?php endif; ?>.
                    <?php if (!empty($cr['legal_note'])): ?><div style="margin-top:6px">Catatan: <?= $h($cr['legal_note']) ?></div><?php endif; ?>
                </div>
            <?php else: ?>
                <form method="post" action="?r=contract_legal_approve&token=<?= $h($cr['share_token']) ?>" style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:10px;padding:14px 16px" onsubmit="return confirm('Setujui permintaan kontrak ini? Pemohon akan melihat status disetujui.')">
                    <p style="margin:0 0 8px;color:#374151">Setelah dokumen ditinjau, klik tombol di bawah untuk menyetujui. Pemohon (sales) akan melihat status <strong>Disetujui Legal</strong>.</p>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
                        <div><label style="font-size:12px;font-weight:700;display:block">Nama Petugas Legal</label><input name="legal_by" placeholder="Nama Anda" style="min-width:200px;padding:8px"></div>
                        <div style="flex:1;min-width:220px"><label style="font-size:12px;font-weight:700;display:block">Catatan (opsional)</label><input name="legal_note" placeholder="mis. lanjut buat draft kontrak" style="width:100%;padding:8px"></div>
                        <button type="submit" style="background:#6d28d9;color:#fff;border:none;border-radius:8px;padding:10px 20px;font-weight:700;cursor:pointer">✓ Setujui Permintaan</button>
                    </div>
                </form>
            <?php endif; ?>

            <p class="muted" style="margin-top:18px">Dokumen ini dibagikan oleh Departemen Pemohon untuk keperluan pembuatan/review kontrak. SKP &amp; Surat Penawaran final disertakan terpisah.</p>
        </div>
    </div>
    <script src="<?= $h($asset('assets/qrcode.min.js')) ?>"></script>
    <script>
    (function () {
        if (typeof qrcode !== 'function') return;
        document.querySelectorAll('.qrbox[data-qr]').forEach(function (box) {
            try { var qr = qrcode(0, 'M'); qr.addData(box.getAttribute('data-qr')); qr.make();
                box.innerHTML = qr.createSvgTag({ cellSize: 2, margin: 0, scalable: true }); } catch (e) {}
        });
    })();
    </script>
    </body></html>
    <?php
    exit;
}

// ─── Legal menyetujui via link publik (tanpa login, validasi share_token) ─────
function contract_legal_approve(PDO $pdo): void
{
    $token = (string) (getv('token', '') ?: post('token', ''));
    $st = $pdo->prepare('SELECT id, status FROM contract_requests WHERE share_token = ? LIMIT 1');
    $st->execute([$token]);
    $cr = $token !== '' ? $st->fetch() : false;
    if (!$cr) { http_response_code(404); exit('Tautan tidak valid.'); }
    if ($cr['status'] === 'sent') {
        $by   = trim((string) post('legal_by')) ?: 'Departemen Legal';
        $note = trim((string) post('legal_note')) ?: null;
        $pdo->prepare("UPDATE contract_requests SET status='approved', legal_by=?, legal_note=?, legal_approved_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([substr($by, 0, 120), $note ? substr($note, 0, 500) : null, (int) $cr['id']]);
    }
    // Kembali ke halaman legal (kini menampilkan status disetujui).
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    header('Location: ' . $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/?r=contract_legal&token=' . urlencode($token));
    exit;
}

// ─── Print publik untuk Legal (via token): formulir + SEMUA lampiran ──────────
function contract_legal_print(PDO $pdo): void
{
    $token = (string) getv('token', '');
    $st = $pdo->prepare('SELECT * FROM contract_requests WHERE share_token = ? AND status IN ("sent","approved") LIMIT 1');
    $st->execute([$token]);
    $cr = $token !== '' ? $st->fetch() : false;
    if (!$cr) { http_response_code(404); exit('Tautan tidak valid.'); }
    $ctx     = _cr_skp_context($pdo, (int) $cr['skp_id'], (int) $cr['property_id']);
    $allAtts = _cr_all_attachments($pdo, $cr);
    $skpFinal = _cr_skp_final($pdo, (int) $cr['skp_id']);
    $h  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $chk = fn($b) => !empty($b) ? '☑' : '☐';
    include __DIR__ . '/contract_request_template.php';
}
