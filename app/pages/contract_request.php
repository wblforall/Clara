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
        $badge = ['draft' => ['Draft', '#64748b', '#f1f5f9'], 'sent' => ['Terkirim ke Legal', '#166534', '#dcfce7']];
        $types = _cr_contract_types();
        ?>
        <div class="toolbar" style="gap:8px;flex-wrap:wrap">
            <strong style="font-size:16px">Permintaan Pembuatan/Review Kontrak</strong>
        </div>
        <div class="panel" style="margin-top:12px">
            <p style="margin:0 0 10px;color:var(--muted);font-size:13px">Dibuat dari halaman <strong>SKP</strong> yang sudah ditandatangani customer (tombol "Ajukan Kontrak ke Legal"). Cetak PDF lalu kirim ke Departemen Legal beserta SKP &amp; Surat Penawaran.</p>
            <div class="table-wrap">
                <table style="font-size:12.5px">
                    <thead><tr><th>No. Formulir</th><th>SKP</th><th>Jenis Kontrak</th><th>Status</th><th>Dibuat</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px">Belum ada permintaan kontrak.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $r): $b = $badge[$r['status']] ?? $badge['draft']; ?>
                        <tr>
                            <td style="white-space:nowrap;font-weight:600"><?= h($r['req_no'] ?? '—') ?></td>
                            <td style="white-space:nowrap"><?= h($r['skp_no'] ?? '—') ?></td>
                            <td><?= h($types[$r['contract_type']] ?? '-') ?></td>
                            <td><span class="badge" style="color:<?= $b[1] ?>;background:<?= $b[2] ?>"><?= $b[0] ?></span></td>
                            <td style="font-size:11.5px;color:var(--muted)"><?= h($r['created_by'] ?? '-') ?><br><?= h(substr($r['created_at'] ?? '', 0, 16)) ?></td>
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

    $editable = !$cr || $cr['status'] === 'draft';
    $skpId    = (int) ($cr['skp_id'] ?? getv('skp_id'));
    $me       = $_SESSION['user']['name'] ?? '';
    $v = fn(string $k, $def = '') => h((string) ($cr[$k] ?? $def));

    layout(($cr ? ($editable ? 'Edit' : 'Lihat') : 'Buat') . ' Permintaan Kontrak', function () use ($pdo, $cr, $id, $skpId, $ctx, $editable, $me, $v) {
        $types = _cr_contract_types();
        $dis = $editable ? '' : 'disabled';
        // Default checklist: KTP/NPWP ikut lampiran SKP.
        $ktp  = $cr ? !empty($cr['doc_ktp'])  : $ctx['has_ktp'];
        $npwp = $cr ? !empty($cr['doc_npwp']) : $ctx['has_npwp'];
        $akta = !empty($cr['doc_akta']);
        $sk   = !empty($cr['doc_surat_kuasa']);
        $ctype = $cr['contract_type'] ?? '';
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
            <?php if ($cr && $cr['status'] === 'sent'): ?> · <span class="badge" style="color:#166534;background:#dcfce7">Terkirim ke Legal</span><?php endif; ?>
        </div>

        <form class="panel" method="post" action="?r=contract_request_save" style="margin-top:12px">
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

            <h3>Jenis Kontrak</h3>
            <div style="display:flex;gap:18px;flex-wrap:wrap">
                <?php foreach ($types as $k => $lbl): ?>
                <label style="display:flex;align-items:center;gap:6px;font-weight:600;cursor:pointer">
                    <input type="radio" name="contract_type" value="<?= h($k) ?>" <?= $ctype === $k ? 'checked' : '' ?> <?= $dis ?>> <?= h($lbl) ?>
                </label>
                <?php endforeach; ?>
            </div>

            <h3>Kelengkapan Dokumen Legalitas</h3>
            <p class="help" style="margin-top:0">KTP &amp; NPWP otomatis tercentang bila lampirannya sudah ada di SKP. Akta &amp; Surat Kuasa dicentang bila dilampirkan (untuk PT/CV/Yayasan/Koperasi/BUMN-D).</p>
            <div style="display:flex;flex-direction:column;gap:8px;max-width:760px">
                <?php
                $checks = [
                    'doc_ktp'         => ['Kartu Identitas/KTP Penanggung Jawab/Direktur/Kuasa Direksi', $ktp],
                    'doc_npwp'        => ['NPWP (Pribadi / Perusahaan bila berbadan hukum)', $npwp],
                    'doc_akta'        => ['Akta Pendirian dan/atau Akta Perubahan (PT/Yayasan/Koperasi/BUMN-D)', $akta],
                    'doc_surat_kuasa' => ['Surat Kuasa (bila penanda tangan bukan direktur)', $sk],
                ];
                foreach ($checks as $name => [$lbl, $on]): ?>
                <label style="display:flex;align-items:flex-start;gap:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;cursor:pointer">
                    <input type="checkbox" name="<?= $name ?>" value="1" style="width:18px;height:18px;flex-shrink:0;margin-top:1px" <?= $on ? 'checked' : '' ?> <?= $dis ?>>
                    <span style="font-size:13px"><?= h($lbl) ?></span>
                </label>
                <?php endforeach; ?>
            </div>

            <h3>Poin-Poin Penting untuk Kontrak</h3>
            <p class="help" style="margin-top:0">Selain yang sudah tercantum di SKP / Surat Penawaran, atau hal lain yang perlu diperjelas.</p>
            <textarea name="important_points" rows="4" style="width:100%" placeholder="mis. denda keterlambatan, klausul perpanjangan, dll." <?= $dis ?>><?= $v('important_points') ?></textarea>

            <?php if ($editable): ?>
            <p style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">
                <button type="submit" onclick="document.getElementById('cr-action').value='save'">💾 Simpan Draft</button>
                <button type="submit" class="btn" style="background:#166534" onclick="document.getElementById('cr-action').value='send';return confirm('Tandai formulir TERKIRIM ke Legal? Nomor formulir akan terbit & tidak bisa diubah.')">📤 Simpan &amp; Tandai Terkirim</button>
                <a class="btn secondary" href="?r=contract_requests">Batal</a>
            </p>
            <?php endif; ?>
        </form>
        <?php
    });
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
        'contract_type'      => in_array(post('contract_type'), ['spk', 'sewa_menyewa', 'kerja_sama'], true) ? post('contract_type') : null,
        'doc_ktp'            => post('doc_ktp') ? 1 : 0,
        'doc_npwp'           => post('doc_npwp') ? 1 : 0,
        'doc_akta'           => post('doc_akta') ? 1 : 0,
        'doc_surat_kuasa'    => post('doc_surat_kuasa') ? 1 : 0,
        'important_points'   => trim((string) post('important_points')) ?: null,
    ];

    if ($id) {
        $cur = $pdo->prepare('SELECT * FROM contract_requests WHERE id=? AND property_id=?');
        $cur->execute([$id, $pid]);
        $cr = $cur->fetch();
        if (!$cr) { flash('Permintaan tidak ditemukan.'); redirect_to('contract_requests'); }
        if ($cr['status'] !== 'draft') { flash('Sudah terkirim — tidak bisa diubah.'); redirect_to('contract_request_form', ['id' => $id]); }
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
        $pdo->prepare("UPDATE contract_requests SET status='sent', req_no=?, sent_at=CURRENT_TIMESTAMP WHERE id=? AND property_id=?")
            ->execute([$reqNo, $id, $pid]);
        flash("Formulir terkirim. No: $reqNo. Cetak PDF & kirim ke Legal.");
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
    $ctx  = _cr_skp_context($pdo, (int) $cr['skp_id'], $pid);
    $prop = current_property();
    $types = _cr_contract_types();
    $h  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $chk = fn($b) => !empty($b) ? '☑' : '☐';
    include __DIR__ . '/contract_request_template.php';
}
