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
    return ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'][$m] ?? strtoupper($m);
}

/** Field ekonomi yang di-snapshot tiap revisi. */
function _offer_fields(): array
{
    return ['module', 'client_id', 'contact_id', 'pic_name', 'master_code', 'keterangan',
            'pricing_type', 'unit_rate', 'area_sqm', 'quantity', 'slots',
            'start_date', 'end_date', 'contract_months', 'monthly_amount', 'total_calculated',
            'dp_months', 'dp_amount', 'deposit_months', 'deposit_amount', 'perihal', 'offer_date'];
}

// ─── Daftar ──────────────────────────────────────────────────────────────────
function offers_list_page(PDO $pdo): void
{
    require_permission('manage_offers');
    $pid = current_property_id();
    $status = getv('status', '');
    $where = ['o.property_id = ?']; $params = [$pid];
    if (in_array($status, ['draft', 'sent', 'nego', 'deal', 'cancelled'], true)) { $where[] = 'o.status = ?'; $params[] = $status; }
    $stmt = $pdo->prepare(
        'SELECT o.*, c.company_name FROM offers o LEFT JOIN master_clients c ON c.id = o.client_id
         WHERE ' . implode(' AND ', $where) . ' ORDER BY o.id DESC'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    layout('Surat Penawaran', function () use ($rows, $status) {
        $badge = [
            'draft'     => ['Draft', '#64748b', '#f1f5f9'],
            'sent'      => ['Terkirim', '#0369a1', '#e0f2fe'],
            'nego'      => ['Negosiasi', '#92400e', '#fef3c7'],
            'deal'      => ['DEAL', '#166534', '#dcfce7'],
            'cancelled' => ['Batal', '#991b1b', '#fee2e2'],
        ];
        ?>
        <div class="toolbar" style="gap:8px;flex-wrap:wrap">
            <a class="btn" href="?r=offer_form">+ Buat Penawaran</a>
            <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap">
                <?php foreach (['' => 'Semua', 'draft' => 'Draft', 'sent' => 'Terkirim', 'nego' => 'Nego', 'deal' => 'Deal', 'cancelled' => 'Batal'] as $k => $lbl): ?>
                    <a class="btn light" style="<?= $status === $k ? 'background:var(--primary,#0d9488);color:#fff' : '' ?>" href="?r=offers<?= $k ? '&status=' . $k : '' ?>"><?= $lbl ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="panel" style="margin-top:12px">
            <div class="table-wrap">
                <table style="font-size:12.5px">
                    <thead><tr><th>No. Penawaran</th><th>Modul</th><th>Client</th><th>Periode</th><th>Harga/bln</th><th>Revisi</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?><tr><td colspan="8" style="text-align:center;color:var(--muted);padding:24px">Belum ada penawaran.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $r): $b = $badge[$r['status']] ?? $badge['draft']; ?>
                        <tr>
                            <td style="white-space:nowrap;font-weight:600"><?= h($r['offer_no'] ?? '—') ?></td>
                            <td><?= h(_offer_module_label($r['module'])) ?></td>
                            <td><?= h($r['company_name'] ?? '-') ?></td>
                            <td style="white-space:nowrap;font-size:11.5px"><?= $r['start_date'] ? h(date('d/m/y', strtotime($r['start_date'])) . '–' . date('d/m/y', strtotime($r['end_date']))) : '—' ?></td>
                            <td style="white-space:nowrap"><?= money($r['monthly_amount']) ?></td>
                            <td style="text-align:center"><?= (int)$r['revision_count'] ?>×</td>
                            <td><span class="badge" style="color:<?= $b[1] ?>;background:<?= $b[2] ?>"><?= $b[0] ?></span></td>
                            <td style="white-space:nowrap">
                                <a class="btn light" href="?r=offer_form&id=<?= (int)$r['id'] ?>"><?= in_array($r['status'], ['deal', 'cancelled'], true) ? 'Lihat' : 'Edit' ?></a>
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
    }
    $module  = $offer['module'] ?? getv('module', 'cl');
    if (!in_array($module, ['cl', 'media', 'gudang'], true)) $module = 'cl';
    $editable = !$offer || !in_array($offer['status'], ['deal', 'cancelled'], true);

    $masters  = masterOptions($pdo, $module);
    $clients  = $pdo->query("SELECT id, company_name, brand_name FROM master_clients WHERE status='active' ORDER BY company_name")->fetchAll();
    $contacts = $pdo->query("SELECT id, client_id, name FROM master_client_contacts WHERE status='active' ORDER BY name")->fetchAll();
    $picsStmt = $pdo->prepare("SELECT name FROM master_pic WHERE status='active' AND property_id=? ORDER BY name");
    $picsStmt->execute([$pid]);
    $pics = $picsStmt->fetchAll();
    $linkedPic = null;
    if ($uid = $_SESSION['user']['id'] ?? null) {
        $lp = $pdo->prepare("SELECT name FROM master_pic WHERE user_id=? AND status='active' AND property_id=? LIMIT 1");
        $lp->execute([$uid, $pid]);
        $linkedPic = $lp->fetchColumn() ?: null;
    }
    $v = fn(string $k, $def = '') => h((string) ($offer[$k] ?? $def));

    layout(($offer ? ($editable ? 'Edit' : 'Lihat') : 'Buat') . ' Penawaran', function () use ($pdo, $offer, $id, $module, $editable, $masters, $clients, $contacts, $pics, $linkedPic, $v) {
        $picSel = $offer['pic_name'] ?? $linkedPic;
        $disabled = $editable ? '' : 'disabled';
        ?>
        <div class="toolbar" style="gap:8px"><a class="btn light" href="?r=offers">← Daftar Penawaran</a><?php if ($offer && $offer['offer_no']): ?><a class="btn light" href="?r=offer_print&id=<?= (int)$offer['id'] ?>" target="_blank">🖨 PDF</a><?php endif; ?></div>

        <?php if ($offer): ?>
        <div class="panel" style="margin-top:10px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
            <div><strong style="font-size:15px"><?= h($offer['offer_no'] ?? '(no. terbit saat disimpan)') ?></strong> · <span class="badge"><?= h(_offer_module_label($offer['module'])) ?></span> · Revisi/nego: <strong><?= (int)$offer['revision_count'] ?>×</strong></div>
            <?php if ($editable): ?>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <?php foreach (['sent' => 'Tandai Terkirim', 'nego' => 'Tandai Nego', 'deal' => 'Tandai DEAL', 'cancelled' => 'Batalkan'] as $s => $lbl): if ($offer['status'] === $s) continue; ?>
                <form method="post" action="?r=offer_status" style="display:inline" onsubmit="return confirm('Ubah status ke <?= $lbl ?>?')">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$offer['id'] ?>"><input type="hidden" name="status" value="<?= $s ?>">
                    <button class="btn light" style="<?= $s === 'deal' ? 'background:#16a34a;color:#fff' : ($s === 'cancelled' ? 'background:#fee2e2;color:#991b1b' : '') ?>"><?= $lbl ?></button>
                </form>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <form class="panel" method="post" action="?r=offer_save" style="margin-top:12px" id="offer-form">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= (int)($offer['id'] ?? 0) ?>">
            <input type="hidden" name="module" value="<?= h($module) ?>">

            <h3 style="margin-top:0">Penerima</h3>
            <div class="form-grid">
                <div>
                    <label>Client / Perusahaan</label>
                    <select name="client_id" id="client_id" required <?= $disabled ?>>
                        <option value="">- Pilih Client -</option>
                        <?php foreach ($clients as $cl): ?>
                            <option value="<?= (int)$cl['id'] ?>" <?= (int)($offer['client_id'] ?? 0) === (int)$cl['id'] ? 'selected' : '' ?>><?= h($cl['company_name']) ?><?= $cl['brand_name'] ? ' (' . h($cl['brand_name']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
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
            </div>

            <h3>Objek Sewa</h3>
            <div class="form-grid">
                <div>
                    <label>Unit / Lokasi</label>
                    <select name="master_code" id="master_code" required <?= $disabled ?>>
                        <option value="">- Pilih Unit -</option>
                        <?php foreach ($masters as $m): ?>
                            <option value="<?= h($m['code']) ?>" data-rate="<?= h($m['rate']) ?>" data-area="<?= h($m['area_sqm']) ?>" data-pricing="<?= h($m['pricing_type']) ?>" <?= ($offer['master_code'] ?? '') === $m['code'] ? 'selected' : '' ?>><?= h($m['code'] . ' — ' . $m['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Luas (m²)</label><input type="number" step="0.01" name="area_sqm" id="area_sqm" value="<?= $v('area_sqm') ?>" <?= $disabled ?>></div>
                <div><label>Pricing Type</label>
                    <select name="pricing_type" id="pricing_type" <?= $disabled ?>>
                        <?php foreach (['daily_area', 'daily_slot', 'daily_point', 'monthly', 'fixed'] as $o): ?><option <?= ($offer['pricing_type'] ?? '') === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div><label>Rate (referensi /m/bln)</label><input type="number" step="0.01" name="unit_rate" id="unit_rate" value="<?= $v('unit_rate') ?>" <?= $disabled ?>></div>
                <div class="wide"><label>Keterangan</label><input name="keterangan" value="<?= $v('keterangan') ?>" <?= $disabled ?>></div>
            </div>

            <h3>Periode & Harga</h3>
            <div class="form-grid">
                <div><label>Tanggal Mulai</label><input type="date" name="start_date" id="start_date" value="<?= $v('start_date') ?>" required <?= $disabled ?>></div>
                <div><label>Tanggal Selesai</label><input type="date" name="end_date" id="end_date" value="<?= $v('end_date') ?>" required <?= $disabled ?>></div>
                <div><label>Masa Kontrak (bulan)</label><input type="number" step="1" min="1" name="contract_months" id="contract_months" value="<?= $v('contract_months', '1') ?>" <?= $disabled ?>></div>
                <div><label>Harga / Bulan (nego)</label><input type="number" step="1" name="monthly_amount" id="monthly_amount" value="<?= $v('monthly_amount') ?>" required <?= $disabled ?>></div>
                <div><label>Total Kontrak</label><input type="number" id="total_calc" value="<?= $v('total_calculated') ?>" readonly><input type="hidden" name="total_calculated" id="total_calc_h" value="<?= $v('total_calculated') ?>"></div>
            </div>

            <h3>Pembayaran <span style="font-weight:400;font-size:12px;color:var(--muted)">(DP minimal 2 bulan; deposit adjustable)</span></h3>
            <div class="form-grid">
                <div><label>DP (bulan, min 2)</label><input type="number" step="0.5" min="2" name="dp_months" id="dp_months" value="<?= $v('dp_months', '2') ?>" <?= $disabled ?>></div>
                <div><label>Nominal DP</label><input type="number" step="1" name="dp_amount" id="dp_amount" value="<?= $v('dp_amount') ?>" <?= $disabled ?>></div>
                <div><label>Deposit (bulan)</label><input type="number" step="0.5" min="0" name="deposit_months" id="deposit_months" value="<?= $v('deposit_months', '1') ?>" <?= $disabled ?>></div>
                <div><label>Nominal Deposit</label><input type="number" step="1" name="deposit_amount" id="deposit_amount" value="<?= $v('deposit_amount') ?>" <?= $disabled ?>></div>
            </div>

            <?php if ($offer && $editable): ?>
            <h3>Catatan Revisi / Nego</h3>
            <input name="rev_note" placeholder="mis. turun harga jadi 20jt, tambah 1 bulan gratis…" <?= $disabled ?> style="width:100%">
            <?php endif; ?>

            <?php if ($editable): ?>
            <p style="margin-top:16px"><button type="submit">💾 <?= $offer ? 'Simpan Revisi' : 'Simpan Penawaran' ?></button> <a class="btn secondary" href="?r=offers">Batal</a></p>
            <?php endif; ?>
        </form>

        <script>
        (function () {
            var contacts = <?= json_encode($contacts) ?>;
            var clientSel = document.getElementById('client_id'), contactSel = document.getElementById('contact_id');
            var curContact = <?= (int)($offer['contact_id'] ?? 0) ?>;
            function fillContacts() {
                var cid = parseInt(clientSel.value || '0', 10);
                contactSel.innerHTML = '<option value="">- Pilih -</option>';
                contacts.filter(function (c) { return parseInt(c.client_id, 10) === cid; }).forEach(function (c) {
                    var o = document.createElement('option'); o.value = c.id; o.textContent = c.name;
                    if (parseInt(c.id, 10) === curContact) o.selected = true;
                    contactSel.appendChild(o);
                });
            }
            if (clientSel) { clientSel.addEventListener('change', function () { curContact = 0; fillContacts(); }); fillContacts(); }

            var unit = document.getElementById('master_code');
            if (unit) unit.addEventListener('change', function () {
                var o = this.options[this.selectedIndex];
                if (!o.value) return;
                if (o.dataset.area && !document.getElementById('area_sqm').value) document.getElementById('area_sqm').value = o.dataset.area;
                if (o.dataset.rate && !document.getElementById('unit_rate').value) document.getElementById('unit_rate').value = o.dataset.rate;
                if (o.dataset.pricing) document.getElementById('pricing_type').value = o.dataset.pricing;
            });

            function num(id) { return parseFloat((document.getElementById(id) || {}).value || '0') || 0; }
            function recalc() {
                var monthly = num('monthly_amount'), months = num('contract_months');
                var total = Math.round(monthly * months);
                document.getElementById('total_calc').value = total;
                document.getElementById('total_calc_h').value = total;
                var dp = document.getElementById('dp_amount'), dep = document.getElementById('deposit_amount');
                // auto-isi DP/deposit hanya bila kosong/0 (biar nego manual tidak ketimpa)
                if (dp && (!dp.value || dp.value === '0')) dp.value = Math.round(monthly * num('dp_months'));
                if (dep && (!dep.value || dep.value === '0')) dep.value = Math.round(monthly * num('deposit_months'));
            }
            ['monthly_amount', 'contract_months', 'dp_months', 'deposit_months'].forEach(function (id) {
                var el = document.getElementById(id); if (el) el.addEventListener('input', recalc);
            });
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

    $data = [
        'module'          => in_array(post('module'), ['cl', 'media', 'gudang'], true) ? post('module') : 'cl',
        'client_id'       => (int) post('client_id') ?: null,
        'contact_id'      => (int) post('contact_id') ?: null,
        'pic_name'        => trim((string) post('pic_name')) ?: null,
        'master_code'     => trim((string) post('master_code')) ?: null,
        'keterangan'      => trim((string) post('keterangan')) ?: null,
        'pricing_type'    => post('pricing_type') ?: null,
        'unit_rate'       => (float) post('unit_rate', 0),
        'area_sqm'        => (float) post('area_sqm', 0),
        'quantity'        => 1,
        'slots'           => 1,
        'start_date'      => post('start_date') ?: null,
        'end_date'        => post('end_date') ?: null,
        'contract_months' => (int) post('contract_months', 1) ?: 1,
        'monthly_amount'  => (float) post('monthly_amount', 0),
        'total_calculated' => (float) post('total_calculated', 0),
        'dp_months'       => max(2, (float) post('dp_months', 2)),
        'dp_amount'       => (float) post('dp_amount', 0),
        'deposit_months'  => (float) post('deposit_months', 1),
        'deposit_amount'  => (float) post('deposit_amount', 0),
        'perihal'         => 'Surat Penawaran Sewa Kontrak ' . ((int) post('contract_months', 1)) . ' bulan',
        'offer_date'      => date('Y-m-d'),
    ];

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
        audit($pdo, 'update', 'offers', (string) $id, $data);
        flash("Revisi #$newRev disimpan.");
        redirect_to('offer_form', ['id' => $id]);
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
    $pdo->prepare('INSERT INTO offer_revisions (offer_id, rev_no, snapshot_json, note, created_by) VALUES (?,0,?,?,?)')
        ->execute([$newId, json_encode(array_intersect_key($data, array_flip(_offer_fields())), JSON_UNESCAPED_UNICODE), 'Penawaran awal', $uname]);
    audit($pdo, 'create', 'offers', (string) $newId, $data);
    flash("Penawaran dibuat: $offerNo");
    redirect_to('offer_form', ['id' => $newId]);
}

// ─── Ubah status ─────────────────────────────────────────────────────────────
function offer_status(PDO $pdo): void
{
    require_permission('manage_offers');
    verify_csrf();
    $pid = current_property_id();
    $id  = (int) post('id');
    $to  = post('status');
    if (!in_array($to, ['sent', 'nego', 'deal', 'cancelled'], true)) { redirect_to('offer_form', ['id' => $id]); }
    $cur = $pdo->prepare('SELECT status FROM offers WHERE id=? AND property_id=?');
    $cur->execute([$id, $pid]);
    $st = $cur->fetchColumn();
    if ($st === false || in_array($st, ['deal', 'cancelled'], true)) { flash('Status terkunci.'); redirect_to('offer_form', ['id' => $id]); }
    $extra = $to === 'deal' ? ', deal_at=CURRENT_TIMESTAMP' : ($to === 'cancelled' ? ', cancelled_at=CURRENT_TIMESTAMP' : '');
    $pdo->prepare("UPDATE offers SET status=? $extra WHERE id=? AND property_id=?")->execute([$to, $id, $pid]);
    audit($pdo, 'status_' . $to, 'offers', (string) $id, ['status' => $to]);
    flash('Status penawaran diperbarui: ' . $to);
    redirect_to('offer_form', ['id' => $id]);
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
                p.email pic_email, p.phone pic_phone
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
    $prop = current_property();
    $rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $h  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    include __DIR__ . '/offer_print_template.php';
}
