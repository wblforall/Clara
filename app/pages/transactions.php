<?php
declare(strict_types=1);

function transactions_page(PDO $pdo): void
{
    $module     = getv('module', 'media');
    $search     = trim(getv('search', ''));
    $filterPic  = getv('pic', '');
    $dateFrom   = getv('date_from', '');
    $dateTo     = getv('date_to', '');

    $where  = ['t.module = :module', 't.deleted_at IS NULL', 't.property_id = :property_id'];
    $params = [':module' => $module, ':property_id' => current_property_id()];

    if ($search !== '') {
        $where[]            = '(c.company_name LIKE :search1 OR t.master_code LIKE :search2)';
        $params[':search1'] = '%' . $search . '%';
        $params[':search2'] = '%' . $search . '%';
    }
    if ($filterPic !== '') {
        $where[]           = 't.pic_name = :pic';
        $params[':pic']    = $filterPic;
    }
    if ($dateFrom !== '') {
        $where[]              = 't.start_date >= :date_from';
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[]            = 't.end_date <= :date_to';
        $params[':date_to'] = $dateTo;
    }

    $sql  = 'SELECT t.*, c.company_name, c.brand_name FROM transactions t
             LEFT JOIN master_clients c ON c.id = t.client_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY t.id DESC LIMIT 500';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $picsStmt = $pdo->prepare("SELECT name FROM master_pic WHERE status='active' AND property_id = ? ORDER BY name");
    $picsStmt->execute([current_property_id()]);
    $pics = $picsStmt->fetchAll();
    $moduleLabel = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
    $isFiltered  = $search !== '' || $filterPic !== '' || $dateFrom !== '' || $dateTo !== '';

    layout('Input Transaksi ' . ($moduleLabel[$module] ?? strtoupper($module)), function () use ($module, $rows, $pics, $search, $filterPic, $dateFrom, $dateTo, $isFiltered) {
        ?>
        <div class="toolbar">
            <?php if (can('manage_transactions')): ?>
                <a class="btn" href="?r=transaction_form&module=<?= h($module) ?>">+ Tambah Transaksi</a>
            <?php endif; ?>
            <a class="btn light" href="?r=export_transactions_xlsx&module=<?= h($module) ?>&search=<?= urlencode($search) ?>&pic=<?= urlencode($filterPic) ?>&date_from=<?= h($dateFrom) ?>&date_to=<?= h($dateTo) ?>">⬇ Export Excel</a>
        </div>

        <!-- FILTER FORM -->
        <form method="get" style="margin-bottom:14px">
            <input type="hidden" name="r" value="transactions">
            <input type="hidden" name="module" value="<?= h($module) ?>">
            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end">
                <div style="flex:1;min-width:180px">
                    <label>Cari Client / Kode</label>
                    <input name="search" value="<?= h($search) ?>" placeholder="Nama client atau kode unit...">
                </div>
                <div style="min-width:150px">
                    <label>PIC Dealing</label>
                    <select name="pic">
                        <option value="">Semua PIC</option>
                        <?php foreach ($pics as $p): ?>
                            <option value="<?= h($p['name']) ?>" <?= $filterPic === $p['name'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="min-width:140px">
                    <label>Tanggal Mulai ≥</label>
                    <input type="date" name="date_from" value="<?= h($dateFrom) ?>">
                </div>
                <div style="min-width:140px">
                    <label>Tanggal Selesai ≤</label>
                    <input type="date" name="date_to" value="<?= h($dateTo) ?>">
                </div>
                <div style="display:flex;gap:8px;flex-shrink:0">
                    <button type="submit">Cari</button>
                    <?php if ($isFiltered): ?>
                        <a class="btn secondary" href="?r=transactions&module=<?= h($module) ?>">Reset</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <!-- RESULT COUNT -->
        <div style="margin-bottom:10px;font-size:13px;color:var(--muted)">
            <?php if ($isFiltered): ?>
                Menampilkan <strong style="color:var(--ink)"><?= count($rows) ?></strong> hasil filter
            <?php else: ?>
                <strong style="color:var(--ink)"><?= count($rows) ?></strong> transaksi terakhir
            <?php endif; ?>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th><th>Kode</th><th>Client</th><th>Tanggal</th>
                        <th>Pricing</th><th>Total</th><th>No. Invoice</th><th>PIC</th>
                        <th>Diinput Oleh</th><th>Waktu Input</th><th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="11" style="text-align:center;color:var(--muted);padding:24px">Tidak ada data yang sesuai filter.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>#<?= h((string) $row['id']) ?></td>
                        <td><?= h($row['master_code']) ?></td>
                        <td><?= h($row['company_name'] ?? '-') ?><?= !empty($row['brand_name']) ? '<br><span style="font-size:11px;color:var(--muted)">' . h($row['brand_name']) . '</span>' : '' ?></td>
                        <td style="white-space:nowrap"><?= h($row['start_date'] . ' s/d ' . $row['end_date']) ?></td>
                        <td><?= h($row['pricing_type']) ?></td>
                        <td><?= money($row['final_amount']) ?></td>
                        <td style="font-size:12px;color:var(--muted)"><?= h($row['invoice_no'] ?? '-') ?></td>
                        <td><?= h($row['pic_name'] ?? '-') ?></td>
                        <td><?= h($row['created_by'] ?? '-') ?><?= $row['updated_by'] ? '<br><span style="font-size:11px;color:var(--muted)">Edit: ' . h($row['updated_by']) . '</span>' : '' ?></td>
                        <td style="white-space:nowrap;font-size:12px;color:var(--muted)"><?= h($row['created_at'] ?? '-') ?><?= $row['updated_at'] ? '<br><span title="Terakhir diedit">↻ ' . h($row['updated_at']) . '</span>' : '' ?></td>
                        <td style="white-space:nowrap">
                            <?php if (can('manage_transactions')): ?><a class="btn light" href="?r=transaction_edit&id=<?= h((string) $row['id']) ?>">Edit</a> <?php endif; ?>
                            <a class="btn light" href="?r=allocation_detail&id=<?= h((string) $row['id']) ?>">Alokasi</a>
                            <?php if (current_role() === 'superadmin'): ?>
                            <form method="post" action="?r=transaction_delete" style="display:inline" onsubmit="return confirm('Hapus transaksi #<?= (int)$row['id'] ?>? Data tidak akan muncul di daftar, tapi tetap tersimpan.')">
                                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                <input type="hidden" name="module" value="<?= h($module) ?>">
                                <button type="submit" class="btn warn">Hapus</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    });
}

function transaction_delete(PDO $pdo): void
{
    if (current_role() !== 'superadmin') {
        http_response_code(403);
        exit('Akses ditolak.');
    }
    verify_csrf();
    $id = (int) post('id');
    $module = post('module', 'cl');
    if (!$id) {
        redirect_to('transactions', ['module' => $module]);
    }
    $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ? AND deleted_at IS NULL AND property_id = ?');
    $stmt->execute([$id, current_property_id()]);
    $trx = $stmt->fetch();
    if (!$trx) {
        flash('Transaksi tidak ditemukan atau sudah dihapus.');
        redirect_to('transactions', ['module' => $module]);
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE transactions SET deleted_at = ?, deleted_by = ? WHERE id = ? AND property_id = ?')
            ->execute([date('Y-m-d H:i:s'), $_SESSION['user']['email'] ?? 'system', $id, current_property_id()]);
        $pdo->prepare('DELETE FROM transaction_allocations WHERE transaction_id = ? AND property_id = ?')->execute([$id, current_property_id()]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit($pdo, 'soft_delete', 'transactions', (string) $id, [], (array) $trx);
    flash('Transaksi #' . $id . ' berhasil dihapus.');
    redirect_to('transactions', ['module' => $trx['module']]);
}

function deleted_transactions_page(PDO $pdo): void
{
    if (current_role() !== 'superadmin') {
        http_response_code(403);
        exit('Akses ditolak.');
    }
    $stmt = $pdo->prepare(
        'SELECT t.*, c.company_name
         FROM transactions t
         LEFT JOIN master_clients c ON c.id = t.client_id
         WHERE t.deleted_at IS NOT NULL AND t.property_id = ?
         ORDER BY t.deleted_at DESC LIMIT 500'
    );
    $stmt->execute([current_property_id()]);
    $rows = $stmt->fetchAll();
    layout('Transaksi Dihapus', function () use ($rows) {
        ?>
        <p class="muted" style="margin-bottom:14px">Data berikut telah dihapus (soft delete). Tidak mempengaruhi dashboard. Hanya superadmin yang dapat melihat halaman ini.</p>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th><th>Modul</th><th>Kode</th><th>Client</th>
                        <th>Tanggal</th><th>Total</th><th>PIC</th>
                        <th>Dihapus Oleh</th><th>Waktu Hapus</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:24px">Belum ada transaksi yang dihapus.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr style="opacity:.7">
                        <td>#<?= h((string) $row['id']) ?></td>
                        <td><span style="font-weight:700;text-transform:uppercase;font-size:11px"><?= h($row['module']) ?></span></td>
                        <td><?= h($row['master_code']) ?></td>
                        <td><?= h($row['company_name'] ?? '-') ?></td>
                        <td><?= h($row['start_date'] . ' s/d ' . $row['end_date']) ?></td>
                        <td><?= money($row['final_amount']) ?></td>
                        <td><?= h($row['pic_name'] ?? '-') ?></td>
                        <td style="color:var(--accent);font-weight:600"><?= h($row['deleted_by'] ?? '-') ?></td>
                        <td><?= h($row['deleted_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    });
}

function transaction_form(PDO $pdo): void
{
    $module = getv('module', 'media');
    $masters = masterOptions($pdo, $module);
    $clients = $pdo->query("SELECT id, company_name, brand_name FROM master_clients WHERE status='active' ORDER BY company_name")->fetchAll();
    $allContacts = $pdo->query("SELECT id, client_id, name FROM master_client_contacts WHERE status='active' ORDER BY name")->fetchAll();
    $picsStmt2 = $pdo->prepare("SELECT name FROM master_pic WHERE status='active' AND property_id = ? ORDER BY name");
    $picsStmt2->execute([current_property_id()]);
    $pics = $picsStmt2->fetchAll();
    $moduleLabel = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
    $linkedPic = null;
    $uid = $_SESSION['user']['id'] ?? null;
    if ($uid) {
        $lpStmt = $pdo->prepare("SELECT name FROM master_pic WHERE user_id = ? AND status = 'active' AND property_id = ? LIMIT 1");
        $lpStmt->execute([$uid, current_property_id()]);
        $linkedPic = $lpStmt->fetchColumn() ?: null;
    }
    layout('Tambah Transaksi ' . ($moduleLabel[$module] ?? strtoupper($module)), function () use ($module, $masters, $clients, $allContacts, $pics, $linkedPic) {
        ?>
        <form class="panel panel-anim" method="post" action="?r=transaction_save" style="animation-delay:.05s">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="module" value="<?= h($module) ?>">
            <div class="form-grid form-anim">
                <div class="wide">
                    <label>Kode Master</label>
                    <select name="master_code" id="master_code" required>
                        <?php foreach ($masters as $m): ?>
                            <option value="<?= h($m['code']) ?>"><?= h($m['code'] . ' - ' . $m['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Client / Perusahaan</label>
                    <select name="client_id" id="client_id" required onchange="filterContacts()">
                        <option value="">- Pilih Client -</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= h((string) $c['id']) ?>"><?= h($c['company_name']) ?><?= $c['brand_name'] ? ' (' . h($c['brand_name']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Contact Person</label>
                    <select name="contact_id" id="contact_id" required>
                        <option value="">- Pilih Client dulu -</option>
                    </select>
                </div>
                <div><label>Tanggal Mulai</label><input type="date" name="start_date" required></div>
                <div><label>Tanggal Selesai</label><input type="date" name="end_date" <?= $module === 'cl' ? 'required' : '' ?>></div>
                <div><label>Luas m2</label><input type="number" step="0.01" name="area_sqm" id="area_sqm" value="0"></div>
                <?php if ($module === 'media'): ?>
                <div id="slots_wrap" style="display:none">
                    <label>Jumlah Slot</label>
                    <input type="number" name="slots" id="slots_input" min="1" value="1">
                    <div class="help">1 media = 12 slot video. Isi jumlah slot yang dibeli.</div>
                </div>
                <?php endif; ?>
                <div>
                    <label>Pricing Type</label>
                    <select name="pricing_type" id="pricing_type">
                        <?php foreach (['daily_point', 'daily_slot', 'daily_area', 'monthly', 'fixed'] as $opt): ?>
                            <option value="<?= h($opt) ?>"><?= h($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Rate</label><input type="number" step="0.01" name="unit_rate" id="unit_rate" value="0"></div>
                <div><label>Override Aktual</label><input type="text" inputmode="numeric" class="override-fmt" placeholder="Opsional"><input type="hidden" name="override_amount" class="override-val"></div>
                <div>
                    <label>PIC Dealing <?php if ($linkedPic): ?><span style="font-size:11px;font-weight:400;color:var(--primary)">● auto</span><?php endif; ?></label>
                    <select name="pic_name" required>
                        <option value="">- Pilih PIC -</option>
                        <?php foreach ($pics as $pic): ?>
                            <option <?= $pic['name'] === $linkedPic ? 'selected' : '' ?>><?= h($pic['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="recognition_month_wrap" style="display:none">
                    <label>Nilai Diakui di Bulan</label>
                    <select name="recognition_month">
                        <option value="start">Bulan Awal (bulan mulai)</option>
                        <option value="end">Bulan Akhir (bulan selesai)</option>
                    </select>
                    <div class="help">Transaksi lintas bulan — pilih bulan mana yang dicatat sebagai periode utama.</div>
                </div>
                <div class="wide"><label>Materi / Keterangan</label><textarea name="content_note"></textarea></div>
                <div><label>No. Invoice Accurate <span class="muted" style="font-weight:400">(opsional)</span></label><input type="text" name="invoice_no" placeholder="cth. INV-2026/04/001"></div>
            </div>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:16px;animation:_fadeUp .35s cubic-bezier(.22,.68,0,1.2) both;animation-delay:.65s">
                <button type="button" class="btn light" onclick="kalkulasiTotal()" style="background:#0ea5e9;color:#fff">Kalkulasi Total</button>
                <div id="kalkulasi-result" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 18px;font-size:15px">
                    <span style="color:#166534">Total: <strong id="kalkulasi-nilai">-</strong> &nbsp;|&nbsp; <span id="kalkulasi-hari">-</span></span>
                </div>
            </div>
            <p style="margin-top:14px;animation:_fadeUp .35s cubic-bezier(.22,.68,0,1.2) both;animation-delay:.72s"><button type="submit">Simpan & Hitung Alokasi</button> <a class="btn secondary" href="?r=transactions&module=<?= h($module) ?>">Kembali</a></p>
        </form>
        <script>
            const masters = <?= json_encode($masters) ?>;
            const byCode = Object.fromEntries(masters.map(m => [m.code, m]));
            const allContacts = <?= json_encode($allContacts) ?>;

            function filterContacts(selectedContactId) {
                const clientId = document.getElementById('client_id').value;
                const sel = document.getElementById('contact_id');
                sel.innerHTML = '<option value="">- Pilih Contact Person -</option>';
                if (!clientId) return;
                allContacts.filter(c => String(c.client_id) === String(clientId)).forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c.id;
                    opt.textContent = c.name;
                    if (selectedContactId && String(c.id) === String(selectedContactId)) opt.selected = true;
                    sel.appendChild(opt);
                });
            }

            <?php if ($module === 'media'): ?>
            function parseSizeM2(size) {
                const m = String(size).replace(/[mM²]/g, '').match(/(\d+\.?\d*)\s*[×xX×]\s*(\d+\.?\d*)/);
                return m ? parseFloat(m[1]) * parseFloat(m[2]) : 0;
            }
            <?php endif; ?>

            function fillMaster() {
                const m = byCode[document.getElementById('master_code').value];
                if (!m) return;
                document.getElementById('unit_rate').value = m.rate || 0;
                document.getElementById('pricing_type').value = m.pricing_type || 'daily_point';
                document.getElementById('area_sqm').value = m.area_sqm || 0;
                <?php if ($module === 'media'): ?>
                const area = parseSizeM2(m.size);
                if (area > 0) document.getElementById('area_sqm').value = area.toFixed(2);
                const mt = (m.media_type || '').toLowerCase();
                const isSlotMedia = mt === 'tvc' || mt.startsWith('led');
                const slotsWrap = document.getElementById('slots_wrap');
                if (slotsWrap) {
                    slotsWrap.style.display = isSlotMedia ? '' : 'none';
                    if (isSlotMedia) {
                        const si = document.getElementById('slots_input');
                        if (!si.value || si.value == '1') si.value = m.slots || 1;
                    }
                }
                <?php endif; ?>
            }
            document.getElementById('master_code').addEventListener('change', fillMaster);
            fillMaster();

            function checkRecognitionMonth() {
                const startVal = document.querySelector('[name=start_date]').value;
                const endVal   = document.querySelector('[name=end_date]').value;
                const wrap = document.getElementById('recognition_month_wrap');
                if (startVal && endVal && startVal.substring(0, 7) !== endVal.substring(0, 7)) {
                    wrap.style.display = '';
                } else {
                    wrap.style.display = 'none';
                }
            }
            document.querySelector('[name=start_date]').addEventListener('change', checkRecognitionMonth);
            document.querySelector('[name=end_date]').addEventListener('change', checkRecognitionMonth);

            function kalkulasiTotal() {
                const startVal = document.querySelector('[name=start_date]').value;
                const endVal   = document.querySelector('[name=end_date]').value;
                const rate     = parseFloat(document.getElementById('unit_rate').value) || 0;
                const area     = parseFloat(document.getElementById('area_sqm').value) || 0;
                const pricing  = document.getElementById('pricing_type').value;
                const slotsEl  = document.getElementById('slots_input');
                const slots    = slotsEl && slotsEl.closest('#slots_wrap') && slotsEl.closest('#slots_wrap').style.display !== 'none'
                    ? (parseFloat(slotsEl.value) || 1) : 1;

                let days = 0;
                if (startVal && endVal) {
                    days = Math.round((new Date(endVal) - new Date(startVal)) / 86400000) + 1;
                }

                let total = 0;
                switch (pricing) {
                    case 'daily_point': total = rate * days; break;
                    case 'daily_slot':  total = rate * slots * days; break;
                    case 'daily_area':  total = rate * Math.max(1, area) * days; break;
                    case 'monthly':     total = rate; break;
                    case 'fixed':       total = rate; break;
                }

                document.getElementById('kalkulasi-result').style.display = 'block';
                document.getElementById('kalkulasi-nilai').textContent = 'Rp ' + Math.round(total).toLocaleString('id-ID');
                document.getElementById('kalkulasi-hari').textContent = days + ' hari';
            }

            document.querySelectorAll('.override-fmt').forEach(function(inp) {
                var hidden = inp.nextElementSibling;
                var init = inp.dataset.init || '';
                if (init) { inp.value = parseFloat(init).toLocaleString('id-ID'); }
                inp.addEventListener('input', function() {
                    var raw = this.value.replace(/\D/g, '');
                    this.value = raw ? parseInt(raw, 10).toLocaleString('id-ID') : '';
                    hidden.value = raw;
                });
            });
        </script>
        <?php
    });
}

function transaction_save(PDO $pdo): void
{
    verify_csrf();
    $start = (string) post('start_date');
    $end = (string) post('end_date');
    $months = (int) post('contract_months', 0);
    if (!$end && $months > 0) {
        $end = (new DateTimeImmutable($start))->modify('+' . $months . ' month')->modify('-1 day')->format('Y-m-d');
    }
    if (!$end) {
        $end = $start;
    }

    $clientId  = (int) post('client_id');
    $contactId = (int) post('contact_id');

    $trx = [
        'module'       => post('module'),
        'master_code'  => post('master_code'),
        'content_note' => post('content_note'),
        'start_date'   => $start,
        'end_date'     => $end,
        'quantity'     => (float) post('quantity', 1),
        'slots'        => (float) post('slots', 1),
        'area_sqm'     => (float) post('area_sqm', 0),
        'pricing_type' => post('pricing_type'),
        'unit_rate'    => (float) post('unit_rate', 0),
        'contract_months' => $months ?: null,
        'billing_method'  => 'anchor_cycle',
        'pic_name'     => post('pic_name'),
        'remarks'      => post('remarks'),
        'invoice_no'   => trim((string) post('invoice_no')) ?: null,
    ];
    $calculated = AllocationService::totalCalculated($trx);
    $override = post('override_amount') !== '' ? (float) post('override_amount') : null;
    $trx['total_calculated'] = $calculated;
    $trx['override_amount']  = $override;
    $trx['final_amount']     = $override ?: $calculated;
    $recognitionMonth = post('recognition_month', 'start');
    $trx['period_key'] = $recognitionMonth === 'end' ? substr($end, 0, 7) : substr($start, 0, 7);
    if (substr($start, 0, 7) !== substr($end, 0, 7)) {
        $trx['recognition_period'] = $trx['period_key'];
    }

    $createdBy = $_SESSION['user']['name'] ?? 'system';
    $stmt = $pdo->prepare(
        'INSERT INTO transactions
        (property_id, module, master_code, period_key, client_id, contact_id, content_note, start_date, end_date, quantity, slots, area_sqm,
         pricing_type, unit_rate, contract_months, billing_method, total_calculated, override_amount, final_amount, pic_name, remarks, invoice_no, created_by)
         VALUES
        (:property_id, :module, :master_code, :period_key, :client_id, :contact_id, :content_note, :start_date, :end_date, :quantity, :slots, :area_sqm,
         :pricing_type, :unit_rate, :contract_months, :billing_method, :total_calculated, :override_amount, :final_amount, :pic_name, :remarks, :invoice_no, :created_by)'
    );
    $stmt->execute([
        ':property_id'     => current_property_id(),
        ':module'          => $trx['module'],
        ':master_code'     => $trx['master_code'],
        ':period_key'      => $trx['period_key'],
        ':client_id'       => $clientId ?: null,
        ':contact_id'      => $contactId ?: null,
        ':content_note'    => $trx['content_note'],
        ':start_date'      => $trx['start_date'],
        ':end_date'        => $trx['end_date'],
        ':quantity'        => $trx['quantity'],
        ':slots'           => $trx['slots'],
        ':area_sqm'        => $trx['area_sqm'],
        ':pricing_type'    => $trx['pricing_type'],
        ':unit_rate'       => $trx['unit_rate'],
        ':contract_months' => $trx['contract_months'],
        ':billing_method'  => $trx['billing_method'],
        ':total_calculated'=> $trx['total_calculated'],
        ':override_amount' => $trx['override_amount'],
        ':final_amount'    => $trx['final_amount'],
        ':pic_name'        => $trx['pic_name'],
        ':remarks'         => $trx['remarks'],
        ':invoice_no'      => $trx['invoice_no'],
        ':created_by'      => $createdBy,
    ]);
    $id = (int) $pdo->lastInsertId();
    AllocationService::saveAllocations($pdo, $id, $trx);
    audit($pdo, 'create', 'transactions', (string) $id, $trx);
    flash('Transaksi tersimpan dan alokasi bulanan sudah dihitung.');
    redirect_to('allocation_detail', ['id' => $id]);
}

function transaction_edit(PDO $pdo): void
{
    $id = (int) getv('id');
    $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ? AND property_id = ?');
    $stmt->execute([$id, current_property_id()]);
    $trx = $stmt->fetch();
    if (!$trx) {
        flash('Transaksi tidak ditemukan.');
        redirect_to('dashboard');
    }

    $masters = masterOptions($pdo, $trx['module']);
    $clients = $pdo->query("SELECT id, company_name, brand_name FROM master_clients WHERE status='active' ORDER BY company_name")->fetchAll();
    $allContacts = $pdo->query("SELECT id, client_id, name FROM master_client_contacts WHERE status='active' ORDER BY name")->fetchAll();
    $picsStmt3 = $pdo->prepare("SELECT name FROM master_pic WHERE status='active' AND property_id = ? ORDER BY name");
    $picsStmt3->execute([current_property_id()]);
    $pics = $picsStmt3->fetchAll();
    $moduleLabel = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];

    // Detect recognition_month from existing allocations
    $startMonth = substr($trx['start_date'], 0, 7);
    $endMonth   = substr($trx['end_date'], 0, 7);
    $recognitionMonth = 'start';
    if ($startMonth !== $endMonth) {
        $allocRows = $pdo->prepare('SELECT period_key, SUM(amount) amt FROM transaction_allocations WHERE transaction_id = ? AND property_id = ? GROUP BY period_key');
        $allocRows->execute([$id, current_property_id()]);
        $amtByPeriod = [];
        foreach ($allocRows->fetchAll() as $a) {
            $amtByPeriod[$a['period_key']] = (float) $a['amt'];
        }
        $endAmt   = $amtByPeriod[$endMonth] ?? 0;
        $otherAmt = array_sum(array_diff_key($amtByPeriod, [$endMonth => 0]));
        if ($endAmt > 0 && $otherAmt == 0) {
            $recognitionMonth = 'end';
        }
    }

    layout('Edit Transaksi #' . $id . ' — ' . ($moduleLabel[$trx['module']] ?? strtoupper($trx['module'])), function () use ($id, $trx, $masters, $clients, $allContacts, $pics, $recognitionMonth, $startMonth, $endMonth) {
        ?>
        <form class="panel" method="post" action="?r=transaction_update">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= h((string) $id) ?>">
            <input type="hidden" name="module" value="<?= h($trx['module']) ?>">
            <div class="form-grid">
                <div class="wide">
                    <label>Kode Master</label>
                    <select name="master_code" id="master_code" required>
                        <?php foreach ($masters as $m): ?>
                            <option value="<?= h($m['code']) ?>" <?= $m['code'] === $trx['master_code'] ? 'selected' : '' ?>><?= h($m['code'] . ' - ' . $m['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Client / Perusahaan</label>
                    <select name="client_id" id="client_id" required onchange="filterContacts()">
                        <option value="">- Pilih Client -</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= h((string) $c['id']) ?>" <?= (int) $trx['client_id'] === (int) $c['id'] ? 'selected' : '' ?>><?= h($c['company_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Contact Person</label>
                    <select name="contact_id" id="contact_id" required>
                        <option value="">- Pilih Contact Person -</option>
                        <?php foreach ($allContacts as $ct): ?>
                            <?php if ((int) $ct['client_id'] === (int) $trx['client_id']): ?>
                            <option value="<?= h((string) $ct['id']) ?>" <?= (int) $trx['contact_id'] === (int) $ct['id'] ? 'selected' : '' ?>><?= h($ct['name']) ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Tanggal Mulai</label><input type="date" name="start_date" id="start_date" required value="<?= h($trx['start_date']) ?>"></div>
                <div><label>Tanggal Selesai</label><input type="date" name="end_date" id="end_date" value="<?= h($trx['end_date']) ?>" <?= $trx['module'] === 'cl' ? 'required' : '' ?>></div>
                <div><label>Luas m2</label><input type="number" step="0.01" name="area_sqm" id="area_sqm" value="<?= h((string) $trx['area_sqm']) ?>"></div>
                <?php if ($trx['module'] === 'media'):
                    $currentMaster = array_filter($masters, fn($m) => $m['code'] === $trx['master_code']);
                    $currentMaster = reset($currentMaster);
                    $mt = strtolower($currentMaster['media_type'] ?? '');
                    $isSlotMedia = $currentMaster && ($mt === 'tvc' || str_starts_with($mt, 'led'));
                ?>
                <div id="slots_wrap" style="<?= $isSlotMedia ? '' : 'display:none' ?>">
                    <label>Jumlah Slot</label>
                    <input type="number" name="slots" id="slots_input" min="1" value="<?= h((string) max(1, (int) ($trx['slots'] ?? 1))) ?>">
                    <div class="help">1 media = 12 slot video. Isi jumlah slot yang dibeli.</div>
                </div>
                <?php endif; ?>
                <div>
                    <label>Pricing Type</label>
                    <select name="pricing_type" id="pricing_type">
                        <?php foreach (['daily_point', 'daily_slot', 'daily_area', 'monthly', 'fixed'] as $opt): ?>
                            <option value="<?= h($opt) ?>" <?= $opt === $trx['pricing_type'] ? 'selected' : '' ?>><?= h($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Rate</label><input type="number" step="0.01" name="unit_rate" id="unit_rate" value="<?= h((string) $trx['unit_rate']) ?>"></div>
                <div><label>Override Aktual</label><input type="text" inputmode="numeric" class="override-fmt" placeholder="Opsional" data-init="<?= h((string) ($trx['override_amount'] ?? '')) ?>"><input type="hidden" name="override_amount" class="override-val" value="<?= h((string) ($trx['override_amount'] ?? '')) ?>"></div>
                <div>
                    <label>PIC Dealing</label>
                    <select name="pic_name" required>
                        <option value="">- Pilih PIC -</option>
                        <?php foreach ($pics as $pic): ?>
                            <option <?= $pic['name'] === $trx['pic_name'] ? 'selected' : '' ?>><?= h($pic['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="recognition_month_wrap" style="<?= $startMonth !== $endMonth ? '' : 'display:none' ?>">
                    <label>Nilai Diakui di Bulan</label>
                    <select name="recognition_month">
                        <option value="start" <?= $recognitionMonth === 'start' ? 'selected' : '' ?>>Bulan Awal (bulan mulai)</option>
                        <option value="end"   <?= $recognitionMonth === 'end'   ? 'selected' : '' ?>>Bulan Akhir (bulan selesai)</option>
                    </select>
                    <div class="help">Transaksi lintas bulan — pilih bulan mana yang dicatat sebagai periode utama.</div>
                </div>
                <div class="wide"><label>Materi / Keterangan</label><textarea name="content_note"><?= h($trx['content_note'] ?? '') ?></textarea></div>
                <div><label>No. Invoice Accurate <span class="muted" style="font-weight:400">(opsional)</span></label><input type="text" name="invoice_no" value="<?= h($trx['invoice_no'] ?? '') ?>" placeholder="cth. INV-2026/04/001"></div>
            </div>
            <p class="help">Simpan akan menghapus dan menghitung ulang seluruh alokasi bulanan transaksi ini.</p>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-top:16px">
                <button type="button" class="btn light" onclick="kalkulasiTotal()" style="background:#0ea5e9;color:#fff">Kalkulasi Total</button>
                <div id="kalkulasi-result" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:10px 18px;font-size:15px">
                    <span style="color:#166534">Total: <strong id="kalkulasi-nilai">-</strong> &nbsp;|&nbsp; <span id="kalkulasi-hari">-</span></span>
                </div>
            </div>
            <p style="margin-top:14px"><button type="submit">Simpan & Hitung Ulang Alokasi</button> <a class="btn secondary" href="?r=allocation_detail&id=<?= h((string) $id) ?>">Kembali</a></p>
        </form>
        <script>
            const masters = <?= json_encode($masters) ?>;
            const byCode = Object.fromEntries(masters.map(m => [m.code, m]));
            const allContacts = <?= json_encode($allContacts) ?>;
            const currentContactId = <?= (int) ($trx['contact_id'] ?? 0) ?>;

            function filterContacts() {
                const clientId = document.getElementById('client_id').value;
                const sel = document.getElementById('contact_id');
                const prev = sel.value || currentContactId;
                sel.innerHTML = '<option value="">- Pilih Contact Person -</option>';
                if (!clientId) return;
                allContacts.filter(c => String(c.client_id) === String(clientId)).forEach(c => {
                    const opt = document.createElement('option');
                    opt.value = c.id;
                    opt.textContent = c.name;
                    if (String(c.id) === String(prev)) opt.selected = true;
                    sel.appendChild(opt);
                });
            }

            <?php if ($trx['module'] === 'media'): ?>
            function parseSizeM2(size) {
                const m = String(size).replace(/[mM²]/g, '').match(/(\d+\.?\d*)\s*[×xX×]\s*(\d+\.?\d*)/);
                return m ? parseFloat(m[1]) * parseFloat(m[2]) : 0;
            }
            <?php endif; ?>

            function fillMaster() {
                const m = byCode[document.getElementById('master_code').value];
                if (!m) return;
                document.getElementById('unit_rate').value = m.rate || 0;
                document.getElementById('pricing_type').value = m.pricing_type || 'daily_point';
                document.getElementById('area_sqm').value = m.area_sqm || 0;
                <?php if ($trx['module'] === 'media'): ?>
                const area = parseSizeM2(m.size);
                if (area > 0) document.getElementById('area_sqm').value = area.toFixed(2);
                const mt = (m.media_type || '').toLowerCase();
                const isSlotMedia = mt === 'tvc' || mt.startsWith('led');
                const slotsWrap = document.getElementById('slots_wrap');
                if (slotsWrap) {
                    slotsWrap.style.display = isSlotMedia ? '' : 'none';
                    if (isSlotMedia) {
                        const si = document.getElementById('slots_input');
                        if (!si.value || si.value == '1') si.value = m.slots || 1;
                    }
                }
                <?php endif; ?>
            }
            document.getElementById('master_code').addEventListener('change', fillMaster);

            function checkRecognitionMonth() {
                const startVal = document.getElementById('start_date').value;
                const endVal   = document.getElementById('end_date').value;
                const wrap = document.getElementById('recognition_month_wrap');
                if (startVal && endVal && startVal.substring(0, 7) !== endVal.substring(0, 7)) {
                    wrap.style.display = '';
                } else {
                    wrap.style.display = 'none';
                }
            }
            document.getElementById('start_date').addEventListener('change', checkRecognitionMonth);
            document.getElementById('end_date').addEventListener('change', checkRecognitionMonth);

            function kalkulasiTotal() {
                const startVal = document.getElementById('start_date').value;
                const endVal   = document.getElementById('end_date').value;
                const rate     = parseFloat(document.getElementById('unit_rate').value) || 0;
                const area     = parseFloat(document.getElementById('area_sqm').value) || 0;
                const pricing  = document.getElementById('pricing_type').value;
                const slotsEl  = document.getElementById('slots_input');
                const slots    = slotsEl && slotsEl.closest('#slots_wrap') && slotsEl.closest('#slots_wrap').style.display !== 'none'
                    ? (parseFloat(slotsEl.value) || 1) : 1;
                let days = 0;
                if (startVal && endVal) {
                    days = Math.round((new Date(endVal) - new Date(startVal)) / 86400000) + 1;
                }
                let total = 0;
                switch (pricing) {
                    case 'daily_point': total = rate * days; break;
                    case 'daily_slot':  total = rate * slots * days; break;
                    case 'daily_area':  total = rate * Math.max(1, area) * days; break;
                    case 'monthly':     total = rate; break;
                    case 'fixed':       total = rate; break;
                }
                document.getElementById('kalkulasi-result').style.display = 'block';
                document.getElementById('kalkulasi-nilai').textContent = 'Rp ' + Math.round(total).toLocaleString('id-ID');
                document.getElementById('kalkulasi-hari').textContent = days + ' hari';
            }

            document.querySelectorAll('.override-fmt').forEach(function(inp) {
                var hidden = inp.nextElementSibling;
                var init = inp.dataset.init || '';
                if (init) { inp.value = parseFloat(init).toLocaleString('id-ID'); }
                inp.addEventListener('input', function() {
                    var raw = this.value.replace(/\D/g, '');
                    this.value = raw ? parseInt(raw, 10).toLocaleString('id-ID') : '';
                    hidden.value = raw;
                });
            });
        </script>
        <?php
    });
}

function transaction_update(PDO $pdo): void
{
    verify_csrf();
    $id = (int) post('id');
    $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ? AND property_id = ?');
    $stmt->execute([$id, current_property_id()]);
    $existing = $stmt->fetch();
    if (!$existing) {
        flash('Transaksi tidak ditemukan.');
        redirect_to('dashboard');
    }

    $start  = (string) post('start_date');
    $end    = (string) post('end_date');
    $months = (int) post('contract_months', 0);
    if (!$end && $months > 0) {
        $end = (new DateTimeImmutable($start))->modify('+' . $months . ' month')->modify('-1 day')->format('Y-m-d');
    }
    if (!$end) {
        $end = $start;
    }

    $clientId  = (int) post('client_id');
    $contactId = (int) post('contact_id');

    $trx = [
        'module'          => $existing['module'],
        'master_code'     => post('master_code'),
        'content_note'    => post('content_note'),
        'start_date'      => $start,
        'end_date'        => $end,
        'quantity'        => (float) post('quantity', 1),
        'slots'           => (float) post('slots', 1),
        'area_sqm'        => (float) post('area_sqm', 0),
        'pricing_type'    => post('pricing_type'),
        'unit_rate'       => (float) post('unit_rate', 0),
        'contract_months' => $months ?: null,
        'billing_method'  => 'anchor_cycle',
        'pic_name'        => post('pic_name'),
        'remarks'         => post('remarks'),
        'invoice_no'      => trim((string) post('invoice_no')) ?: null,
    ];
    $calculated = AllocationService::totalCalculated($trx);
    $override = post('override_amount') !== '' ? (float) post('override_amount') : null;
    $trx['total_calculated'] = $calculated;
    $trx['override_amount']  = $override;
    $trx['final_amount']     = $override ?: $calculated;
    $recognitionMonth = post('recognition_month', 'start');
    $trx['period_key'] = $recognitionMonth === 'end' ? substr($end, 0, 7) : substr($start, 0, 7);
    if (substr($start, 0, 7) !== substr($end, 0, 7)) {
        $trx['recognition_period'] = $trx['period_key'];
    }

    $pdo->prepare(
        'UPDATE transactions SET master_code=:master_code, period_key=:period_key,
         client_id=:client_id, contact_id=:contact_id,
         content_note=:content_note, start_date=:start_date, end_date=:end_date, quantity=:quantity, slots=:slots,
         area_sqm=:area_sqm, pricing_type=:pricing_type, unit_rate=:unit_rate, contract_months=:contract_months,
         total_calculated=:total_calculated, override_amount=:override_amount, final_amount=:final_amount,
         pic_name=:pic_name, remarks=:remarks, invoice_no=:invoice_no,
         updated_at=CURRENT_TIMESTAMP, updated_by=:updated_by WHERE id=:id AND property_id=:property_id'
    )->execute([
        ':master_code'      => $trx['master_code'],
        ':period_key'       => $trx['period_key'],
        ':client_id'        => $clientId ?: null,
        ':contact_id'       => $contactId ?: null,
        ':content_note'     => $trx['content_note'],
        ':start_date'       => $trx['start_date'],
        ':end_date'         => $trx['end_date'],
        ':quantity'         => $trx['quantity'],
        ':slots'            => $trx['slots'],
        ':area_sqm'         => $trx['area_sqm'],
        ':pricing_type'     => $trx['pricing_type'],
        ':unit_rate'        => $trx['unit_rate'],
        ':contract_months'  => $trx['contract_months'],
        ':total_calculated' => $trx['total_calculated'],
        ':override_amount'  => $trx['override_amount'],
        ':final_amount'     => $trx['final_amount'],
        ':pic_name'         => $trx['pic_name'],
        ':remarks'          => $trx['remarks'],
        ':invoice_no'       => $trx['invoice_no'],
        ':updated_by'       => $_SESSION['user']['name'] ?? 'system',
        ':id'               => $id,
        ':property_id'      => current_property_id(),
    ]);

    AllocationService::saveAllocations($pdo, $id, $trx);
    audit($pdo, 'update', 'transactions', (string) $id, $trx, (array) $existing);
    flash('Transaksi diperbarui dan alokasi dihitung ulang.');
    redirect_to('allocation_detail', ['id' => $id]);
}

function allocation_detail(PDO $pdo): void
{
    $id = (int) getv('id');
    $stmt = $pdo->prepare(
        'SELECT t.*, c.company_name, cc.name cp_name, cc.phone cp_phone
         FROM transactions t
         LEFT JOIN master_clients c ON c.id = t.client_id
         LEFT JOIN master_client_contacts cc ON cc.id = t.contact_id
         WHERE t.id = ? AND t.property_id = ?'
    );
    $stmt->execute([$id, current_property_id()]);
    $trx = $stmt->fetch();
    if (!$trx) {
        flash('Transaksi tidak ditemukan.');
        redirect_to('dashboard');
    }
    $alloc = $pdo->prepare('SELECT * FROM transaction_allocations WHERE transaction_id = ? AND property_id = ? ORDER BY period_key, allocation_start');
    $alloc->execute([$id, current_property_id()]);
    layout('Detail Alokasi Transaksi #' . $id, function () use ($trx, $alloc) {
        ?>
        <div class="panel">
            <?php $moduleLabel = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang']; ?>
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
                <div>
                    <h2><?= h($trx['company_name'] ?? '-') ?> <span class="badge"><?= h($moduleLabel[$trx['module']] ?? $trx['module']) ?></span></h2>
                    <p><?= h($trx['master_code']) ?> | <?= h($trx['start_date'] . ' s/d ' . $trx['end_date']) ?> | <?= h($trx['pricing_type']) ?></p>
                    <p>CP: <strong><?= h($trx['cp_name'] ?? '-') ?></strong><?= $trx['cp_phone'] ? ' · <a href="tel:' . h($trx['cp_phone']) . '">' . h($trx['cp_phone']) . '</a>' : '' ?></p>
                    <p>Total hitung: <strong><?= money($trx['total_calculated']) ?></strong> | Final: <strong><?= money($trx['final_amount']) ?></strong><?= $trx['invoice_no'] ? ' | No. Invoice: <strong>' . h($trx['invoice_no']) . '</strong>' : '' ?></p>
                </div>
                <div style="display:flex;gap:8px">
                    <?php if (can('manage_transactions')): ?>
                    <a class="btn light" href="?r=transaction_edit&id=<?= h((string) $trx['id']) ?>">Edit Transaksi</a>
                    <?php endif; ?>
                    <a class="btn light" href="?r=transaction_history&id=<?= h((string) $trx['id']) ?>">Riwayat</a>
                </div>
            </div>
        </div>
        <div class="panel" style="margin-top:14px">
            <h2>Breakdown Bulanan</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Bulan</th><th>Periode Alokasi</th><th>Hari</th><th>Capacity-days</th><th>Aktual</th></tr></thead>
                    <tbody>
                    <?php foreach ($alloc->fetchAll() as $row): ?>
                        <tr>
                            <td><?= h(period_label($row['period_key'])) ?></td>
                            <td><?= h($row['allocation_start'] . ' s/d ' . $row['allocation_end']) ?></td>
                            <td><?= h((string) $row['allocated_days']) ?></td>
                            <td><?= h((string) $row['capacity_days']) ?></td>
                            <td><?= money($row['amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    });
}

function transaction_history_page(PDO $pdo): void
{
    $id = (int) getv('id');
    $stmt = $pdo->prepare(
        'SELECT t.*, c.company_name FROM transactions t
         LEFT JOIN master_clients c ON c.id = t.client_id WHERE t.id = ? AND t.property_id = ?'
    );
    $stmt->execute([$id, current_property_id()]);
    $trx = $stmt->fetch();
    if (!$trx) { flash('Transaksi tidak ditemukan.'); redirect_to('dashboard'); }

    $logs = $pdo->prepare(
        "SELECT * FROM audit_logs WHERE table_name = 'transactions' AND record_id = ? ORDER BY id ASC"
    );
    $logs->execute([(string) $id]);
    $logs = $logs->fetchAll();

    $moduleLabel = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
    $fieldLabel  = [
        'master_code' => 'Kode Unit', 'start_date' => 'Tgl Mulai', 'end_date' => 'Tgl Selesai',
        'pricing_type' => 'Tipe Harga', 'unit_rate' => 'Rate', 'quantity' => 'Qty',
        'slots' => 'Slots', 'area_sqm' => 'Area (m²)', 'final_amount' => 'Final Amount',
        'total_calculated' => 'Total Hitung', 'override_amount' => 'Override',
        'pic_name' => 'PIC', 'invoice_no' => 'No. Invoice', 'remarks' => 'Catatan',
        'content_note' => 'Ket. Konten', 'period_key' => 'Periode', 'contract_months' => 'Durasi (bln)',
        'client_id' => 'Client ID', 'contact_id' => 'Contact ID',
    ];
    $skip = ['id', 'deleted_at', 'created_at', 'updated_at', 'updated_by', 'recognition_period', 'billing_method', 'module'];

    layout('Riwayat Transaksi #' . $id, function () use ($trx, $logs, $moduleLabel, $fieldLabel, $skip, $id) {
        ?>
        <div class="panel" style="margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px">
                <div>
                    <h2><?= h($trx['company_name'] ?? '-') ?> <span class="badge"><?= h($moduleLabel[$trx['module']] ?? $trx['module']) ?></span></h2>
                    <p class="muted"><?= h($trx['master_code']) ?> · <?= h($trx['start_date'] . ' s/d ' . $trx['end_date']) ?></p>
                </div>
                <a class="btn light" href="?r=allocation_detail&id=<?= $id ?>">← Kembali ke Detail</a>
            </div>
        </div>

        <?php if (empty($logs)): ?>
        <div class="panel"><p class="muted">Belum ada riwayat perubahan.</p></div>
        <?php else: ?>
        <div style="position:relative">
            <?php foreach ($logs as $i => $log):
                $after  = $log['after_json']  ? json_decode($log['after_json'],  true) : [];
                $before = $log['before_json'] ? json_decode($log['before_json'], true) : [];
                $actionCfg = match($log['action']) {
                    'create'      => ['Dibuat',    '#0D9488'],
                    'update'      => ['Diperbarui','#0891B2'],
                    'soft_delete' => ['Dihapus',   '#EF4444'],
                    default       => [h($log['action']), '#6B7280'],
                };
                // Compute changed fields for updates
                $diffs = [];
                if ($log['action'] === 'update' && $after) {
                    foreach ($after as $k => $v) {
                        if (in_array($k, $skip)) continue;
                        $old = $before[$k] ?? null;
                        if ((string)$old !== (string)$v) {
                            $diffs[$k] = ['before' => $old, 'after' => $v];
                        }
                    }
                }
            ?>
            <div style="display:flex;gap:16px;margin-bottom:16px">
                <div style="display:flex;flex-direction:column;align-items:center;min-width:40px">
                    <div style="width:12px;height:12px;border-radius:50%;background:<?= $actionCfg[1] ?>;margin-top:4px;flex-shrink:0"></div>
                    <?php if ($i < count($logs) - 1): ?>
                    <div style="width:2px;flex:1;background:#E5E7EB;margin-top:4px"></div>
                    <?php endif; ?>
                </div>
                <div class="panel" style="flex:1;margin:0;padding:14px 16px">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:4px;margin-bottom:8px">
                        <span style="font-weight:700;color:<?= $actionCfg[1] ?>"><?= $actionCfg[0] ?></span>
                        <span class="muted" style="font-size:12px"><?= h($log['created_at']) ?></span>
                    </div>
                    <p style="margin-bottom:<?= ($diffs || ($log['action'] === 'create' && $after)) ? '10px' : '0' ?>">
                        <strong><?= h($log['user_name'] ?? '-') ?></strong>
                        <span class="muted"> (<?= h($log['actor'] ?? '-') ?>)</span>
                        <?php if ($log['ip_address']): ?>
                        <span class="muted"> · IP <?= h($log['ip_address']) ?></span>
                        <?php endif; ?>
                    </p>

                    <?php if ($log['action'] === 'create' && $after): ?>
                    <div class="table-wrap" style="margin:0">
                        <table style="font-size:12px">
                            <thead><tr><th>Field</th><th>Nilai</th></tr></thead>
                            <tbody>
                            <?php foreach ($after as $k => $v):
                                if (in_array($k, $skip) || ($v === null || $v === '')) continue; ?>
                            <tr>
                                <td style="color:var(--muted)"><?= h($fieldLabel[$k] ?? $k) ?></td>
                                <td><?= h((string) $v) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php elseif ($log['action'] === 'update'): ?>
                    <?php if ($diffs): ?>
                    <div class="table-wrap" style="margin:0">
                        <table style="font-size:12px">
                            <thead><tr><th>Field</th><th>Sebelum</th><th>Sesudah</th></tr></thead>
                            <tbody>
                            <?php foreach ($diffs as $k => $d): ?>
                            <tr>
                                <td style="color:var(--muted)"><?= h($fieldLabel[$k] ?? $k) ?></td>
                                <td style="color:#EF4444"><?= h((string) ($d['before'] ?? '-')) ?></td>
                                <td style="color:#0D9488;font-weight:600"><?= h((string) ($d['after'] ?? '-')) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <p class="muted" style="font-size:12px;margin:0">Tidak ada perubahan data yang terdeteksi (data sebelum belum direkam).</p>
                    <?php endif; ?>

                    <?php elseif ($log['action'] === 'soft_delete'): ?>
                    <p class="muted" style="font-size:12px;margin:0">Transaksi dipindahkan ke Transaksi Dihapus.</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php
    });
}
