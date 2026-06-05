<?php
declare(strict_types=1);

function transactions_page(PDO $pdo): void
{
    $module     = getv('module', 'media');
    $search     = trim(getv('search', ''));
    $filterPic  = getv('pic', '');
    $dateFrom   = getv('date_from', '');
    $dateTo     = getv('date_to', '');

    $page    = max(1, (int) getv('page', 1));
    $perPage = 50;

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
    if ($dateFrom !== '' && $dateTo !== '') {
        // Overlap: transaksi yang menyentuh range ini (termasuk recurring panjang)
        $where[]              = 't.start_date <= :date_to AND t.end_date >= :date_from';
        $params[':date_from'] = $dateFrom;
        $params[':date_to']   = $dateTo;
    } elseif ($dateFrom !== '') {
        $where[]              = 't.end_date >= :date_from';
        $params[':date_from'] = $dateFrom;
    } elseif ($dateTo !== '') {
        $where[]            = 't.start_date <= :date_to';
        $params[':date_to'] = $dateTo;
    }

    $whereStr = implode(' AND ', $where);

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM transactions t LEFT JOIN master_clients c ON c.id = t.client_id WHERE ' . $whereStr);
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $sql  = 'SELECT t.*, c.company_name, c.brand_name FROM transactions t
             LEFT JOIN master_clients c ON c.id = t.client_id
             WHERE ' . $whereStr . '
             ORDER BY t.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $picsStmt = $pdo->prepare("SELECT name FROM master_pic WHERE status='active' AND property_id = ? ORDER BY name");
    $picsStmt->execute([current_property_id()]);
    $pics = $picsStmt->fetchAll();
    $moduleLabel = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
    $isFiltered  = $search !== '' || $filterPic !== '' || $dateFrom !== '' || $dateTo !== '';

    $paginationBase = '?r=transactions&module=' . urlencode($module)
        . ($search !== '' ? '&search=' . urlencode($search) : '')
        . ($filterPic !== '' ? '&pic=' . urlencode($filterPic) : '')
        . ($dateFrom !== '' ? '&date_from=' . urlencode($dateFrom) : '')
        . ($dateTo !== '' ? '&date_to=' . urlencode($dateTo) : '');

    layout('Input Transaksi ' . ($moduleLabel[$module] ?? strtoupper($module)), function () use ($module, $rows, $pics, $search, $filterPic, $dateFrom, $dateTo, $isFiltered, $page, $totalPages, $totalRows, $perPage, $paginationBase) {
        ?>
        <div class="toolbar">
            <?php if (can('manage_transactions')): ?>
                <a class="btn" href="?r=transaction_form&module=<?= h($module) ?>">+ Tambah Transaksi</a>
            <?php endif; ?>
            <div style="display:flex;gap:8px">
                <a class="btn light" href="?r=transaction_overlaps" style="color:#92400e;border-color:#fcd34d">⚠ Cek Overlap</a>
                <a class="btn light" href="?r=export_transactions_xlsx&module=<?= h($module) ?>&search=<?= urlencode($search) ?>&pic=<?= urlencode($filterPic) ?>&date_from=<?= h($dateFrom) ?>&date_to=<?= h($dateTo) ?>">⬇ Export Excel</a>
            </div>
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
                Menampilkan <strong style="color:var(--ink)"><?= $totalRows ?></strong> hasil filter
            <?php else: ?>
                Total <strong style="color:var(--ink)"><?= $totalRows ?></strong> transaksi
            <?php endif; ?>
            <?php if ($totalPages > 1): ?>
                &nbsp;&middot;&nbsp; Halaman <strong style="color:var(--ink)"><?= $page ?></strong> dari <strong style="color:var(--ink)"><?= $totalPages ?></strong>
            <?php endif; ?>
        </div>

        <div class="table-wrap">
            <table style="font-size:12px">
                <thead>
                    <tr>
                        <th>ID</th><th>Kode</th><th>Client</th><th>Tanggal</th>
                        <th>Pricing</th><th>Total</th><th>PIC</th>
                        <th>Input</th><th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:24px">Tidak ada data yang sesuai filter.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>#<?= h((string) $row['id']) ?></td>
                        <td><?= h($row['master_code']) ?></td>
                        <td>
                            <?= h($row['company_name'] ?? '-') ?>
                            <?= !empty($row['brand_name']) ? '<br><span style="font-size:11px;color:var(--muted)">' . h($row['brand_name']) . '</span>' : '' ?>
                            <?= !empty($row['invoice_no']) ? '<br><span style="font-size:10px;color:var(--muted)">' . h($row['invoice_no']) . '</span>' : '' ?>
                        </td>
                        <td style="white-space:nowrap"><?= h($row['start_date'] . ' s/d ' . $row['end_date']) ?></td>
                        <td>
                            <?= h($row['pricing_type']) ?>
                            <?= ($row['billing_method'] ?? '') === 'spread' ? '<br><span class="badge" style="font-size:10px;background:var(--accent-light,#e8f4ff);color:var(--accent,#2563eb)">Recurring</span>' : '' ?>
                        </td>
                        <td><?= money($row['final_amount']) ?></td>
                        <td><?= h($row['pic_name'] ?? '-') ?></td>
                        <td style="font-size:11px;color:var(--muted)">
                            <?= h($row['created_by'] ?? '-') ?>
                            <?= $row['updated_by'] ? '<br><span title="Terakhir diedit">↻ ' . h($row['updated_by']) . '</span>' : '' ?>
                            <br><?= h(substr($row['created_at'] ?? '', 0, 16)) ?>
                        </td>
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

        <?php if ($totalPages > 1): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:16px;flex-wrap:wrap;gap:10px">
            <div>
                <?php if ($page > 1): ?>
                    <a class="btn light" href="<?= $paginationBase ?>&page=<?= $page - 1 ?>">← Sebelumnya</a>
                <?php else: ?>
                    <span class="btn light" style="opacity:.4;cursor:default">← Sebelumnya</span>
                <?php endif; ?>
            </div>
            <div style="font-size:13px;color:var(--muted);text-align:center">
                Halaman <strong style="color:var(--ink)"><?= $page ?></strong> dari <strong style="color:var(--ink)"><?= $totalPages ?></strong>
                &nbsp;&middot;&nbsp; <span style="color:var(--ink)"><?= number_format($totalRows, 0, ',', '.') ?></span> data
            </div>
            <div>
                <?php if ($page < $totalPages): ?>
                    <a class="btn light" href="<?= $paginationBase ?>&page=<?= $page + 1 ?>">Selanjutnya →</a>
                <?php else: ?>
                    <span class="btn light" style="opacity:.4;cursor:default">Selanjutnya →</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
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
                    <label>Unit / Lokasi</label>
                    <div style="position:relative">
                        <input type="text" id="masterSearch" autocomplete="off" placeholder="Ketik nama unit...">
                        <input type="hidden" name="master_code" id="master_code" required>
                        <div id="masterDrop"></div>
                    </div>
                </div>
                <div>
                    <label>Client / Perusahaan</label>
                    <div style="position:relative" id="cliPicker">
                        <input type="text" id="cliSearch" autocomplete="off" placeholder="Ketik nama client...">
                        <input type="hidden" name="client_id" id="client_id">
                        <div id="cliDrop" style="display:none;position:absolute;left:0;right:0;top:calc(100% + 2px);background:#fff;border:1px solid var(--line);border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.12);z-index:500;max-height:220px;overflow-y:auto"></div>
                    </div>
                    <div class="help">Ketik nama atau brand untuk mencari, lalu pilih dari daftar.</div>
                </div>
                <div>
                    <label>Contact Person</label>
                    <select name="contact_id" id="contact_id" required>
                        <option value="">- Pilih Client dulu -</option>
                    </select>
                </div>
                <div><label>Luas m2</label><input type="number" step="0.01" name="area_sqm" id="area_sqm" value="0"></div>
                <div><label>Tanggal Mulai</label><input type="date" name="start_date" required></div>
                <div><label>Tanggal Selesai</label><input type="date" name="end_date" <?= $module === 'cl' ? 'required' : '' ?>></div>
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
                    <select name="recognition_month" id="recognition_month">
                        <option value="start">Bulan Awal (bulan mulai)</option>
                        <option value="end">Bulan Akhir (bulan selesai)</option>
                        <option value="spread">Spread per Bulan (Recurring)</option>
                    </select>
                    <div class="help" id="recognition_help">Transaksi lintas bulan — pilih bulan mana yang dicatat sebagai periode utama.</div>
                </div>
                <div id="cycle_recognition_wrap" style="display:none">
                    <label>Pengakuan per Siklus</label>
                    <select name="cycle_recognition" id="cycle_recognition">
                        <option value="cycle_start">Bulan Awal siklus</option>
                        <option value="cycle_end">Bulan Akhir siklus</option>
                    </select>
                    <div class="help">Revenue tiap siklus diakui di bulan awal atau akhir siklus tersebut.</div>
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
            <div id="kalkulasi-spread" style="display:none;margin-top:8px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 18px;font-size:13px;line-height:1.8"></div>
            <div id="overlap-warn" style="display:none;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:10px 14px;margin-top:12px;font-size:13px;color:#92400e"></div>
            <p style="margin-top:14px;animation:_fadeUp .35s cubic-bezier(.22,.68,0,1.2) both;animation-delay:.72s"><button type="submit">Simpan & Hitung Alokasi</button> <a class="btn secondary" href="?r=transactions&module=<?= h($module) ?>">Kembali</a></p>
        </form>
        <div style="margin-top:24px;background:#f8fafc;border:1px solid var(--line);border-radius:10px;padding:18px 22px;font-size:13px;color:#374151">
            <p style="font-weight:700;margin:0 0 10px;font-size:14px;color:#1e293b">Panduan Input Transaksi Recurring (Spread per Bulan)</p>
            <p style="margin:0 0 8px;color:#64748b">Gunakan mode ini untuk kontrak yang revenue-nya dibagi rata ke setiap bulan yang dicakup, bukan diakui sekaligus di satu bulan.</p>
            <ol style="margin:0 0 12px;padding-left:18px;line-height:2">
                <li>Isi <strong>Tanggal Mulai</strong> dan <strong>Tanggal Selesai</strong> — pastikan beda bulan agar opsi muncul.</li>
                <li>Di dropdown <strong>Nilai Diakui di Bulan</strong>, pilih <strong>Spread per Bulan (Recurring)</strong>.</li>
                <li>Isi <strong>Pricing Type</strong> dan <strong>Rate</strong> sesuai kontrak, lalu klik <strong>Kalkulasi Total</strong>.</li>
                <li>Panel estimasi akan tampil — menunjukkan nilai per bulan hasil pembagian rata.</li>
                <li>Jika nilai final berbeda dari kalkulasi (misal: ada diskon atau negosiasi), isi <strong>Override Aktual</strong> lalu klik Kalkulasi lagi untuk memperbarui estimasi.</li>
                <li>Klik <strong>Simpan</strong> — sistem akan membuat alokasi bulanan secara otomatis.</li>
            </ol>
            <p style="margin:0;color:#64748b"><strong>Catatan:</strong> Jika nilai tidak habis dibagi rata, selisih pembulatan dialokasikan ke bulan terakhir. Hasil alokasi final bisa dicek di halaman <em>Detail Alokasi</em> setelah disimpan.</p>
        </div>
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

            // Searchable master picker
            (function(){
                const src = document.getElementById('masterSearch');
                const hid = document.getElementById('master_code');
                const dd  = document.getElementById('masterDrop');
                document.body.appendChild(dd);
                dd.style.cssText = 'display:none;position:fixed;background:#fff;border:1px solid var(--line);border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.12);z-index:9000;max-height:260px;overflow-y:auto';
                function pos() { const r = src.getBoundingClientRect(); dd.style.top=(r.bottom+2)+'px'; dd.style.left=r.left+'px'; dd.style.width=r.width+'px'; }
                function render(q) {
                    pos();
                    const lq = q.toLowerCase().trim();
                    const list = lq ? masters.filter(m => m.label.toLowerCase().includes(lq) || m.code.toLowerCase().includes(lq)) : masters;
                    dd.innerHTML = '';
                    list.forEach(function(m) {
                        const d = document.createElement('div');
                        d.style.cssText = 'padding:9px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;gap:8px';
                        d.innerHTML = '<span style="font-weight:600">' + m.label + '</span><span style="color:var(--muted);font-size:11px;flex-shrink:0">' + m.code + '</span>';
                        d.addEventListener('mouseover', function(){ this.style.background='#f0fdf4'; });
                        d.addEventListener('mouseout',  function(){ this.style.background=''; });
                        d.addEventListener('mousedown', function(e){
                            e.preventDefault();
                            src.value = m.label; hid.value = m.code; src.style.outline = '';
                            dd.style.display = 'none';
                            hid.dispatchEvent(new Event('change'));
                        });
                        dd.appendChild(d);
                    });
                    if (!list.length) dd.innerHTML = '<div style="padding:10px 14px;font-size:13px;color:var(--muted)">Tidak ditemukan</div>';
                    dd.style.display = '';
                }
                src.addEventListener('input',  function(){ hid.value = ''; render(this.value); });
                src.addEventListener('focus',  function(){ render(this.value); });
                src.addEventListener('blur',   function(){ setTimeout(function(){ dd.style.display='none'; }, 200); });
                window.addEventListener('scroll', function(){ if (dd.style.display !== 'none') pos(); }, true);
                document.querySelectorAll('form button[type=submit]').forEach(function(btn){
                    btn.addEventListener('click', function(e){
                        if (!hid.value){ e.preventDefault(); e.stopImmediatePropagation(); src.style.outline='2px solid #EF4444'; src.focus(); }
                    });
                });
            })();

            // Searchable client picker
            (function(){
                const cliData = <?= json_encode(array_values($clients)) ?>;
                const src = document.getElementById('cliSearch');
                const hid = document.getElementById('client_id');
                const dd  = document.getElementById('cliDrop');
                document.body.appendChild(dd);
                dd.style.cssText = 'display:none;position:fixed;background:#fff;border:1px solid var(--line);border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.12);z-index:9000;max-height:220px;overflow-y:auto';
                function pos() {
                    const r = src.getBoundingClientRect();
                    dd.style.top = (r.bottom + 2) + 'px';
                    dd.style.left = r.left + 'px';
                    dd.style.width = r.width + 'px';
                }
                function render(q) {
                    pos();
                    const lq = q.toLowerCase().trim();
                    const list = lq ? cliData.filter(c =>
                        c.company_name.toLowerCase().includes(lq) ||
                        (c.brand_name && c.brand_name.toLowerCase().includes(lq))
                    ) : cliData;
                    dd.innerHTML = '';
                    list.slice(0, 60).forEach(function(c) {
                        const d = document.createElement('div');
                        d.style.cssText = 'padding:9px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid #f1f5f9';
                        d.innerHTML = '<strong>' + c.company_name + '</strong>' + (c.brand_name ? ' <span style="color:var(--muted);font-size:11px">(' + c.brand_name + ')</span>' : '');
                        d.addEventListener('mouseover', function(){ this.style.background='#f0fdf4'; });
                        d.addEventListener('mouseout',  function(){ this.style.background=''; });
                        d.addEventListener('mousedown', function(e){
                            e.preventDefault();
                            src.value = c.company_name + (c.brand_name ? ' (' + c.brand_name + ')' : '');
                            hid.value = c.id;
                            src.style.outline = '';
                            dd.style.display = 'none';
                            filterContacts();
                        });
                        dd.appendChild(d);
                    });
                    if (!list.length) dd.innerHTML = '<div style="padding:10px 14px;font-size:13px;color:var(--muted)">Tidak ditemukan</div>';
                    dd.style.display = '';
                }
                src.addEventListener('input', function(){ hid.value = ''; render(this.value); });
                src.addEventListener('focus', function(){ render(this.value); });
                src.addEventListener('blur',  function(){ setTimeout(function(){ dd.style.display='none'; }, 200); });
                window.addEventListener('scroll', function(){ if (dd.style.display !== 'none') pos(); }, true);
                document.querySelectorAll('form button[type=submit]').forEach(function(btn){
                    btn.addEventListener('click', function(e){
                        if (!hid.value) {
                            e.preventDefault(); e.stopImmediatePropagation();
                            src.style.outline = '2px solid #EF4444';
                            src.focus();
                        }
                    });
                });
            })();

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
            function updateRecognitionHelp() {
                const val  = document.getElementById('recognition_month').value;
                const help = document.getElementById('recognition_help');
                const cycleWrap = document.getElementById('cycle_recognition_wrap');
                help.textContent = val === 'spread'
                    ? 'Revenue dibagi rata ke setiap bulan yang dicakup kontrak.'
                    : 'Transaksi lintas bulan — pilih bulan mana yang dicatat sebagai periode utama.';
                if (cycleWrap) cycleWrap.style.display = val === 'spread' ? '' : 'none';
            }
            document.getElementById('recognition_month').addEventListener('change', updateRecognitionHelp);
            document.querySelector('[name=start_date]').addEventListener('change', checkRecognitionMonth);
            document.querySelector('[name=end_date]').addEventListener('change', checkRecognitionMonth);

            const overlapWarn = document.getElementById('overlap-warn');
            let overlapTimer = null;
            function checkOverlap() {
                clearTimeout(overlapTimer);
                overlapTimer = setTimeout(async () => {
                    const code  = document.getElementById('master_code').value;
                    const start = document.querySelector('[name=start_date]').value;
                    const end   = document.querySelector('[name=end_date]').value;
                    if (!code || !start || !end) { overlapWarn.style.display = 'none'; return; }
                    try {
                        const r = await fetch(`?r=transaction_overlap_check&master_code=${encodeURIComponent(code)}&start_date=${encodeURIComponent(start)}&end_date=${encodeURIComponent(end)}`, { cache: 'no-store' });
                        const data = await r.json();
                        if (data.overlaps && data.overlaps.length) {
                            const rows = data.overlaps.map(o =>
                                `<li><strong>${o.company_name}</strong> · ${o.start_date} s/d ${o.end_date} · PIC: ${o.pic_name}</li>`
                            ).join('');
                            overlapWarn.innerHTML = `⚠ Unit ini sudah memiliki <strong>${data.overlaps.length}</strong> transaksi dengan tanggal yang overlap. Tetap bisa disimpan jika memang dibagi per slot/luasan.<ul style="margin:6px 0 0 16px">${rows}</ul>`;
                            overlapWarn.style.display = '';
                        } else {
                            overlapWarn.style.display = 'none';
                        }
                    } catch (_) {}
                }, 400);
            }
            document.getElementById('master_code').addEventListener('change', checkOverlap);
            document.querySelector('[name=start_date]').addEventListener('change', checkOverlap);
            document.querySelector('[name=end_date]').addEventListener('change', checkOverlap);

            // ── Spread table helpers ─────────────────────────────────────────
            var spreadOverrides = {};
            var spreadBaseTotal = 0, spreadBaseStart = '', spreadBaseEnd = '', spreadBasePricing = '', spreadBaseCycle = 'cycle_start';

            function parseLocalDate(s) {
                var p = s.split('-'); return new Date(parseInt(p[0]), parseInt(p[1])-1, parseInt(p[2]));
            }

            function spreadMonths(startVal, endVal, pricingType, cycleRecognition) {
                var BULAN = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
                var months = [];
                if (pricingType === 'monthly') {
                    // Cycle-based: anchor ke start_date, cocok dengan PHP monthlyCycleAllocations
                    var cursor = parseLocalDate(startVal);
                    var endDate = parseLocalDate(endVal);
                    var limit = 120;
                    while (cursor <= endDate && limit-- > 0) {
                        var nextAnchor = new Date(cursor.getFullYear(), cursor.getMonth()+1, cursor.getDate());
                        var cycleEnd = new Date(nextAnchor.getFullYear(), nextAnchor.getMonth(), nextAnchor.getDate()-1);
                        if (cycleEnd > endDate) cycleEnd = new Date(endDate);
                        var periodDate = (cycleRecognition === 'cycle_end') ? cycleEnd : cursor;
                        var y = periodDate.getFullYear(), mo = periodDate.getMonth();
                        months.push({ key: y+'-'+String(mo+1).padStart(2,'0'), label: BULAN[mo]+' '+y });
                        cursor = new Date(cycleEnd.getFullYear(), cycleEnd.getMonth(), cycleEnd.getDate()+1);
                    }
                } else {
                    // Calendar-month based (untuk daily_*, fixed)
                    var cur = new Date(startVal.substring(0,7)+'-01'), e = new Date(endVal.substring(0,7)+'-01');
                    while (cur <= e) {
                        var y = cur.getFullYear(), mo = cur.getMonth();
                        months.push({ key: y+'-'+String(mo+1).padStart(2,'0'), label: BULAN[mo]+' '+y });
                        cur = new Date(y, mo+1, 1);
                    }
                }
                return months;
            }

            function spreadAmounts(total, months) {
                var overSum = 0, overKeys = {};
                months.forEach(function(m){ if (spreadOverrides[m.key]!==undefined){ overSum+=spreadOverrides[m.key]; overKeys[m.key]=1; } });
                var free = months.filter(function(m){ return !overKeys[m.key]; });
                var rem = total - overSum, n2 = free.length, base = n2>0 ? Math.floor(rem/n2) : 0;
                var out = {}, run = 0;
                free.forEach(function(m,i){ var a=(i===n2-1)?Math.round(rem-run):base; out[m.key]=a; run+=a; });
                months.forEach(function(m){ if(overKeys[m.key]) out[m.key]=spreadOverrides[m.key]; });
                return out;
            }

            function renderSpreadTable() {
                var spreadDiv = document.getElementById('kalkulasi-spread');
                if (!spreadDiv || !spreadBaseStart || !spreadBaseEnd) return;
                var months = spreadMonths(spreadBaseStart, spreadBaseEnd, spreadBasePricing, spreadBaseCycle);
                if (!months.length) { spreadDiv.style.display='none'; return; }
                var amts = spreadAmounts(spreadBaseTotal, months);
                var grand = months.reduce(function(s,m){ return s+(amts[m.key]||0); }, 0);
                var rows = '';
                months.forEach(function(m) {
                    var locked = spreadOverrides[m.key]!==undefined;
                    var amt = amts[m.key]||0;
                    var badge = locked ? '<span style="font-size:10px;background:#dbeafe;color:#1d4ed8;padding:1px 5px;border-radius:3px;margin-left:4px;font-weight:600">KHUSUS</span>' : '';
                    var rst   = locked ? '<button type="button" onclick="clearSpreadOvr(\''+m.key+'\')" style="font-size:10px;padding:1px 5px;margin-left:4px;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:3px;cursor:pointer">Reset</button>' : '';
                    var ibg   = locked ? 'background:#dbeafe;font-weight:700;' : '';
                    rows += '<tr><td style="padding:3px 8px 3px 0;color:#374151;white-space:nowrap">'+m.label+badge+rst+'</td>'
                          + '<td style="padding:3px 0;text-align:right"><input type="text" inputmode="numeric" data-period="'+m.key+'" value="'+amt.toLocaleString('id-ID')+'" '
                          + 'style="text-align:right;width:140px;font-size:13px;'+ibg+'" '
                          + 'onchange="setSpreadOvr(\''+m.key+'\',this.value)" oninput="fmtSpreadInp(this)"></td></tr>';
                });
                spreadDiv.innerHTML = '<div style="font-weight:700;color:#0369a1;margin-bottom:8px">Spread ('+months.length+' bulan) | Total: Rp '+grand.toLocaleString('id-ID')+'</div>'
                    +'<table style="border-collapse:collapse;background:transparent;width:100%">'+rows+'</table>';
                spreadDiv.style.display = 'block';
                syncOvrInputs();
            }

            function fmtSpreadInp(inp) { var r=inp.value.replace(/\D/g,''); inp.value=r?parseInt(r,10).toLocaleString('id-ID'):''; }
            function setSpreadOvr(k,v) { var r=String(v).replace(/\D/g,''); if(!r){clearSpreadOvr(k);return;} spreadOverrides[k]=parseInt(r,10); renderSpreadTable(); }
            function clearSpreadOvr(k) { delete spreadOverrides[k]; renderSpreadTable(); }
            function flushSpreadInputs() {
                // Baca langsung dari DOM, handle kasus user ganti angka lalu langsung Save tanpa blur
                var spreadDiv = document.getElementById('kalkulasi-spread');
                if (!spreadDiv) return;
                spreadDiv.querySelectorAll('input[data-period]').forEach(function(inp) {
                    var k = inp.getAttribute('data-period');
                    var r = inp.value.replace(/\D/g,'');
                    if (r) spreadOverrides[k] = parseInt(r, 10);
                });
            }
            function syncOvrInputs() {
                flushSpreadInputs();
                var form=document.querySelector('form');
                if(!form) return;
                form.querySelectorAll('input[name^="month_overrides["]').forEach(function(el){el.remove();});
                Object.keys(spreadOverrides).forEach(function(k){
                    var inp=document.createElement('input'); inp.type='hidden';
                    inp.name='month_overrides['+k+']'; inp.value=spreadOverrides[k]; form.appendChild(inp);
                });
            }
            document.querySelector('form').addEventListener('submit', syncOvrInputs);
            // ─────────────────────────────────────────────────────────────────

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

                const recogEl   = document.getElementById('recognition_month');
                const spreadDiv = document.getElementById('kalkulasi-spread');
                if (spreadDiv && recogEl && recogEl.value === 'spread' && startVal && endVal) {
                    const overrideRaw = document.querySelector('.override-val');
                    const finalAmount = (overrideRaw && overrideRaw.value) ? parseFloat(overrideRaw.value) : total;
                    spreadBaseTotal   = finalAmount;
                    spreadBaseStart   = startVal;
                    spreadBaseEnd     = endVal;
                    spreadBasePricing = pricing;
                    spreadBaseCycle   = (document.getElementById('cycle_recognition') || {value:'cycle_start'}).value;
                    renderSpreadTable();
                } else if (spreadDiv) {
                    spreadDiv.style.display = 'none';
                }
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
        'billing_method'     => 'anchor_cycle',
        'cycle_recognition'  => post('cycle_recognition', 'cycle_start'),
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
    if ($recognitionMonth === 'spread') {
        $trx['billing_method'] = 'spread';
        $trx['period_key']     = substr($start, 0, 7);
    } else {
        $trx['period_key'] = $recognitionMonth === 'end' ? substr($end, 0, 7) : substr($start, 0, 7);
        if (substr($start, 0, 7) !== substr($end, 0, 7)) {
            $trx['recognition_period'] = $trx['period_key'];
        }
    }

    $createdBy = $_SESSION['user']['name'] ?? 'system';
    $stmt = $pdo->prepare(
        'INSERT INTO transactions
        (property_id, module, master_code, period_key, client_id, contact_id, content_note, start_date, end_date, quantity, slots, area_sqm,
         pricing_type, unit_rate, contract_months, billing_method, cycle_recognition, total_calculated, override_amount, final_amount, pic_name, remarks, invoice_no, created_by)
         VALUES
        (:property_id, :module, :master_code, :period_key, :client_id, :contact_id, :content_note, :start_date, :end_date, :quantity, :slots, :area_sqm,
         :pricing_type, :unit_rate, :contract_months, :billing_method, :cycle_recognition, :total_calculated, :override_amount, :final_amount, :pic_name, :remarks, :invoice_no, :created_by)'
    );
    $stmt->execute([
        ':property_id'       => current_property_id(),
        ':module'            => $trx['module'],
        ':master_code'       => $trx['master_code'],
        ':period_key'        => $trx['period_key'],
        ':client_id'         => $clientId ?: null,
        ':contact_id'        => $contactId ?: null,
        ':content_note'      => $trx['content_note'],
        ':start_date'        => $trx['start_date'],
        ':end_date'          => $trx['end_date'],
        ':quantity'          => $trx['quantity'],
        ':slots'             => $trx['slots'],
        ':area_sqm'          => $trx['area_sqm'],
        ':pricing_type'      => $trx['pricing_type'],
        ':unit_rate'         => $trx['unit_rate'],
        ':contract_months'   => $trx['contract_months'],
        ':billing_method'    => $trx['billing_method'],
        ':cycle_recognition' => $trx['cycle_recognition'],
        ':total_calculated'  => $trx['total_calculated'],
        ':override_amount'   => $trx['override_amount'],
        ':final_amount'      => $trx['final_amount'],
        ':pic_name'          => $trx['pic_name'],
        ':remarks'           => $trx['remarks'],
        ':invoice_no'        => $trx['invoice_no'],
        ':created_by'        => $createdBy,
    ]);
    $id = (int) $pdo->lastInsertId();
    $monthOverrides = [];
    foreach (($_POST['month_overrides'] ?? []) as $k => $v) {
        if (preg_match('/^\d{4}-\d{2}$/', (string) $k) && $v !== '') {
            $monthOverrides[(string) $k] = (float) $v;
        }
    }
    AllocationService::saveAllocations($pdo, $id, $trx, $monthOverrides);
    if ($monthOverrides) {
        $s = $pdo->prepare('SELECT SUM(amount) FROM transaction_allocations WHERE transaction_id=? AND property_id=?');
        $s->execute([$id, current_property_id()]);
        $newFinal = (float) ($s->fetchColumn() ?: $trx['final_amount']);
        $pdo->prepare('UPDATE transactions SET final_amount=? WHERE id=?')->execute([$newFinal, $id]);
    }
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

    // Detect recognition_month from billing_method or existing allocations
    $startMonth = substr($trx['start_date'], 0, 7);
    $endMonth   = substr($trx['end_date'], 0, 7);
    $recognitionMonth = 'start';
    $existingAllocations = []; // period_key => amount, untuk pre-load spread overrides
    if (($trx['billing_method'] ?? '') === 'spread') {
        $recognitionMonth = 'spread';
        $allocRows = $pdo->prepare('SELECT period_key, SUM(amount) amt FROM transaction_allocations WHERE transaction_id = ? AND property_id = ? GROUP BY period_key ORDER BY period_key');
        $allocRows->execute([$id, current_property_id()]);
        foreach ($allocRows->fetchAll() as $a) {
            $existingAllocations[$a['period_key']] = (int) round((float) $a['amt']);
        }
    } elseif ($startMonth !== $endMonth) {
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

    layout('Edit Transaksi #' . $id . ' — ' . ($moduleLabel[$trx['module']] ?? strtoupper($trx['module'])), function () use ($id, $trx, $masters, $clients, $allContacts, $pics, $recognitionMonth, $startMonth, $endMonth, $existingAllocations) {
        ?>
        <form class="panel" method="post" action="?r=transaction_update">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= h((string) $id) ?>">
            <input type="hidden" name="module" value="<?= h($trx['module']) ?>">
            <div class="form-grid">
                <div class="wide">
                    <?php
                    $currentMasterLabel = '';
                    foreach ($masters as $_m) {
                        if ($_m['code'] === $trx['master_code']) { $currentMasterLabel = $_m['label']; break; }
                    }
                    ?>
                    <label>Unit / Lokasi</label>
                    <div style="position:relative">
                        <input type="text" id="masterSearch" autocomplete="off" placeholder="Ketik nama unit..." value="<?= h($currentMasterLabel) ?>">
                        <input type="hidden" name="master_code" id="master_code" value="<?= h($trx['master_code']) ?>">
                        <div id="masterDrop"></div>
                    </div>
                </div>
                <div>
                    <label>Client / Perusahaan</label>
                    <?php
                    $editCli = array_values(array_filter($clients, fn($c) => (int)$c['id'] === (int)$trx['client_id']));
                    $editCli = $editCli[0] ?? [];
                    $editCliLabel = $editCli ? ($editCli['company_name'] . ($editCli['brand_name'] ? ' (' . $editCli['brand_name'] . ')' : '')) : '';
                    ?>
                    <div style="position:relative" id="cliPicker">
                        <input type="text" id="cliSearch" autocomplete="off" placeholder="Ketik nama client..." value="<?= h($editCliLabel) ?>">
                        <input type="hidden" name="client_id" id="client_id" value="<?= h((string)($trx['client_id'] ?? '')) ?>">
                        <div id="cliDrop" style="display:none;position:absolute;left:0;right:0;top:calc(100% + 2px);background:#fff;border:1px solid var(--line);border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.12);z-index:500;max-height:220px;overflow-y:auto"></div>
                    </div>
                    <div class="help">Ketik nama atau brand untuk mencari, lalu pilih dari daftar.</div>
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
                    <select name="recognition_month" id="recognition_month">
                        <option value="start"  <?= $recognitionMonth === 'start'  ? 'selected' : '' ?>>Bulan Awal (bulan mulai)</option>
                        <option value="end"    <?= $recognitionMonth === 'end'    ? 'selected' : '' ?>>Bulan Akhir (bulan selesai)</option>
                        <option value="spread" <?= $recognitionMonth === 'spread' ? 'selected' : '' ?>>Spread per Bulan (Recurring)</option>
                    </select>
                    <div class="help" id="recognition_help">Transaksi lintas bulan — pilih bulan mana yang dicatat sebagai periode utama.</div>
                </div>
                <div id="cycle_recognition_wrap" style="<?= $recognitionMonth === 'spread' ? '' : 'display:none' ?>">
                    <label>Pengakuan per Siklus</label>
                    <select name="cycle_recognition" id="cycle_recognition">
                        <?php $cr = $trx['cycle_recognition'] ?? 'cycle_start'; ?>
                        <option value="cycle_start" <?= $cr === 'cycle_start' ? 'selected' : '' ?>>Bulan Awal siklus</option>
                        <option value="cycle_end"   <?= $cr === 'cycle_end'   ? 'selected' : '' ?>>Bulan Akhir siklus</option>
                    </select>
                    <div class="help">Revenue tiap siklus diakui di bulan awal atau akhir siklus tersebut.</div>
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
            <div id="kalkulasi-spread" style="display:none;margin-top:8px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:10px 18px;font-size:13px;line-height:1.8"></div>
            <div id="overlap-warn" style="display:none;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:10px 14px;margin-top:12px;font-size:13px;color:#92400e"></div>
            <p style="margin-top:14px"><button type="submit">Simpan & Hitung Ulang Alokasi</button> <a class="btn secondary" href="?r=allocation_detail&id=<?= h((string) $id) ?>">Kembali</a></p>
        </form>
        <div style="margin-top:24px;background:#f8fafc;border:1px solid var(--line);border-radius:10px;padding:18px 22px;font-size:13px;color:#374151">
            <p style="font-weight:700;margin:0 0 10px;font-size:14px;color:#1e293b">Panduan Input Transaksi Recurring (Spread per Bulan)</p>
            <p style="margin:0 0 8px;color:#64748b">Gunakan mode ini untuk kontrak yang revenue-nya dibagi rata ke setiap bulan yang dicakup, bukan diakui sekaligus di satu bulan.</p>
            <ol style="margin:0 0 12px;padding-left:18px;line-height:2">
                <li>Isi <strong>Tanggal Mulai</strong> dan <strong>Tanggal Selesai</strong> — pastikan beda bulan agar opsi muncul.</li>
                <li>Di dropdown <strong>Nilai Diakui di Bulan</strong>, pilih <strong>Spread per Bulan (Recurring)</strong>.</li>
                <li>Isi <strong>Pricing Type</strong> dan <strong>Rate</strong> sesuai kontrak, lalu klik <strong>Kalkulasi Total</strong>.</li>
                <li>Panel estimasi akan tampil — menunjukkan nilai per bulan hasil pembagian rata.</li>
                <li>Jika nilai final berbeda dari kalkulasi (misal: ada diskon atau negosiasi), isi <strong>Override Aktual</strong> lalu klik Kalkulasi lagi untuk memperbarui estimasi.</li>
                <li>Klik <strong>Simpan</strong> — sistem akan membuat alokasi bulanan secara otomatis.</li>
            </ol>
            <p style="margin:0;color:#64748b"><strong>Catatan:</strong> Jika nilai tidak habis dibagi rata, selisih pembulatan dialokasikan ke bulan terakhir. Hasil alokasi final bisa dicek di halaman <em>Detail Alokasi</em> setelah disimpan.</p>
        </div>
        <script>
            const masters = <?= json_encode($masters) ?>;
            const byCode = Object.fromEntries(masters.map(m => [m.code, m]));
            const allContacts = <?= json_encode($allContacts) ?>;
            const currentContactId = <?= (int) ($trx['contact_id'] ?? 0) ?>;

            // Searchable master picker (edit form)
            (function(){
                const src = document.getElementById('masterSearch');
                const hid = document.getElementById('master_code');
                const dd  = document.getElementById('masterDrop');
                document.body.appendChild(dd);
                dd.style.cssText = 'display:none;position:fixed;background:#fff;border:1px solid var(--line);border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.12);z-index:9000;max-height:260px;overflow-y:auto';
                function pos() { const r = src.getBoundingClientRect(); dd.style.top=(r.bottom+2)+'px'; dd.style.left=r.left+'px'; dd.style.width=r.width+'px'; }
                function render(q) {
                    pos();
                    const lq = q.toLowerCase().trim();
                    const list = lq ? masters.filter(m => m.label.toLowerCase().includes(lq) || m.code.toLowerCase().includes(lq)) : masters;
                    dd.innerHTML = '';
                    list.forEach(function(m) {
                        const d = document.createElement('div');
                        d.style.cssText = 'padding:9px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;gap:8px';
                        d.innerHTML = '<span style="font-weight:600">' + m.label + '</span><span style="color:var(--muted);font-size:11px;flex-shrink:0">' + m.code + '</span>';
                        d.addEventListener('mouseover', function(){ this.style.background='#f0fdf4'; });
                        d.addEventListener('mouseout',  function(){ this.style.background=''; });
                        d.addEventListener('mousedown', function(e){
                            e.preventDefault();
                            src.value = m.label; hid.value = m.code; src.style.outline = '';
                            dd.style.display = 'none';
                            hid.dispatchEvent(new Event('change'));
                        });
                        dd.appendChild(d);
                    });
                    if (!list.length) dd.innerHTML = '<div style="padding:10px 14px;font-size:13px;color:var(--muted)">Tidak ditemukan</div>';
                    dd.style.display = '';
                }
                src.addEventListener('input',  function(){ hid.value = ''; render(this.value); });
                src.addEventListener('focus',  function(){ render(this.value); });
                src.addEventListener('blur',   function(){ setTimeout(function(){ dd.style.display='none'; }, 200); });
                window.addEventListener('scroll', function(){ if (dd.style.display !== 'none') pos(); }, true);
            })();

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

            // Searchable client picker
            (function(){
                const cliData = <?= json_encode(array_values($clients)) ?>;
                const src = document.getElementById('cliSearch');
                const hid = document.getElementById('client_id');
                const dd  = document.getElementById('cliDrop');
                document.body.appendChild(dd);
                dd.style.cssText = 'display:none;position:fixed;background:#fff;border:1px solid var(--line);border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.12);z-index:9000;max-height:220px;overflow-y:auto';
                function pos() {
                    const r = src.getBoundingClientRect();
                    dd.style.top = (r.bottom + 2) + 'px';
                    dd.style.left = r.left + 'px';
                    dd.style.width = r.width + 'px';
                }
                function render(q) {
                    pos();
                    const lq = q.toLowerCase().trim();
                    const list = lq ? cliData.filter(c =>
                        c.company_name.toLowerCase().includes(lq) ||
                        (c.brand_name && c.brand_name.toLowerCase().includes(lq))
                    ) : cliData;
                    dd.innerHTML = '';
                    list.slice(0, 60).forEach(function(c) {
                        const d = document.createElement('div');
                        d.style.cssText = 'padding:9px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid #f1f5f9';
                        d.innerHTML = '<strong>' + c.company_name + '</strong>' + (c.brand_name ? ' <span style="color:var(--muted);font-size:11px">(' + c.brand_name + ')</span>' : '');
                        d.addEventListener('mouseover', function(){ this.style.background='#f0fdf4'; });
                        d.addEventListener('mouseout',  function(){ this.style.background=''; });
                        d.addEventListener('mousedown', function(e){
                            e.preventDefault();
                            src.value = c.company_name + (c.brand_name ? ' (' + c.brand_name + ')' : '');
                            hid.value = c.id;
                            src.style.outline = '';
                            dd.style.display = 'none';
                            filterContacts();
                        });
                        dd.appendChild(d);
                    });
                    if (!list.length) dd.innerHTML = '<div style="padding:10px 14px;font-size:13px;color:var(--muted)">Tidak ditemukan</div>';
                    dd.style.display = '';
                }
                src.addEventListener('input', function(){ hid.value = ''; render(this.value); });
                src.addEventListener('focus', function(){ render(this.value); });
                src.addEventListener('blur',  function(){ setTimeout(function(){ dd.style.display='none'; }, 200); });
                window.addEventListener('scroll', function(){ if (dd.style.display !== 'none') pos(); }, true);
                document.querySelectorAll('form button[type=submit]').forEach(function(btn){
                    btn.addEventListener('click', function(e){
                        if (!hid.value) {
                            e.preventDefault(); e.stopImmediatePropagation();
                            src.style.outline = '2px solid #EF4444';
                            src.focus();
                        }
                    });
                });
            })();

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
            function updateRecognitionHelp() {
                const val  = document.getElementById('recognition_month').value;
                const help = document.getElementById('recognition_help');
                const cycleWrap = document.getElementById('cycle_recognition_wrap');
                help.textContent = val === 'spread'
                    ? 'Revenue dibagi rata ke setiap bulan yang dicakup kontrak.'
                    : 'Transaksi lintas bulan — pilih bulan mana yang dicatat sebagai periode utama.';
                if (cycleWrap) cycleWrap.style.display = val === 'spread' ? '' : 'none';
            }
            document.getElementById('recognition_month').addEventListener('change', updateRecognitionHelp);
            updateRecognitionHelp();
            document.getElementById('start_date').addEventListener('change', checkRecognitionMonth);
            document.getElementById('end_date').addEventListener('change', checkRecognitionMonth);

            const overlapWarn = document.getElementById('overlap-warn');
            let overlapTimer = null;
            function checkOverlap() {
                clearTimeout(overlapTimer);
                overlapTimer = setTimeout(async () => {
                    const code  = document.getElementById('master_code').value;
                    const start = document.getElementById('start_date').value;
                    const end   = document.getElementById('end_date').value;
                    if (!code || !start || !end) { overlapWarn.style.display = 'none'; return; }
                    try {
                        const r = await fetch(`?r=transaction_overlap_check&master_code=${encodeURIComponent(code)}&start_date=${encodeURIComponent(start)}&end_date=${encodeURIComponent(end)}&exclude_id=<?= $id ?>`, { cache: 'no-store' });
                        const data = await r.json();
                        if (data.overlaps && data.overlaps.length) {
                            const rows = data.overlaps.map(o =>
                                `<li><strong>${o.company_name}</strong> · ${o.start_date} s/d ${o.end_date} · PIC: ${o.pic_name}</li>`
                            ).join('');
                            overlapWarn.innerHTML = `⚠ Unit ini sudah memiliki <strong>${data.overlaps.length}</strong> transaksi dengan tanggal yang overlap. Tetap bisa disimpan jika memang dibagi per slot/luasan.<ul style="margin:6px 0 0 16px">${rows}</ul>`;
                            overlapWarn.style.display = '';
                        } else {
                            overlapWarn.style.display = 'none';
                        }
                    } catch (_) {}
                }, 400);
            }
            document.getElementById('master_code').addEventListener('change', checkOverlap);
            document.getElementById('start_date').addEventListener('change', checkOverlap);
            document.getElementById('end_date').addEventListener('change', checkOverlap);
            checkOverlap();

            // ── Spread table helpers ─────────────────────────────────────────
            // Pre-load existing allocations sebagai initial overrides (untuk transaksi spread)
            var spreadOverrides = <?= json_encode($existingAllocations) ?>;
            var spreadBaseTotal = 0, spreadBaseStart = '', spreadBaseEnd = '';

            function spreadMonths(startVal, endVal) {
                var BULAN = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
                var months = [], cur = new Date(startVal.substring(0,7)+'-01'), e = new Date(endVal.substring(0,7)+'-01');
                while (cur <= e) {
                    var y = cur.getFullYear(), m = cur.getMonth();
                    months.push({ key: y+'-'+String(m+1).padStart(2,'0'), label: BULAN[m]+' '+y });
                    cur = new Date(y, m+1, 1);
                }
                return months;
            }

            function spreadAmounts(total, months) {
                var overSum = 0, overKeys = {};
                months.forEach(function(m){ if (spreadOverrides[m.key]!==undefined){ overSum+=spreadOverrides[m.key]; overKeys[m.key]=1; } });
                var free = months.filter(function(m){ return !overKeys[m.key]; });
                var rem = total - overSum, n2 = free.length, base = n2>0 ? Math.floor(rem/n2) : 0;
                var out = {}, run = 0;
                free.forEach(function(m,i){ var a=(i===n2-1)?Math.round(rem-run):base; out[m.key]=a; run+=a; });
                months.forEach(function(m){ if(overKeys[m.key]) out[m.key]=spreadOverrides[m.key]; });
                return out;
            }

            function renderSpreadTable() {
                var spreadDiv = document.getElementById('kalkulasi-spread');
                if (!spreadDiv || !spreadBaseStart || !spreadBaseEnd) return;
                var months = spreadMonths(spreadBaseStart, spreadBaseEnd, spreadBasePricing, spreadBaseCycle);
                if (!months.length) { spreadDiv.style.display='none'; return; }
                var amts = spreadAmounts(spreadBaseTotal, months);
                var grand = months.reduce(function(s,m){ return s+(amts[m.key]||0); }, 0);
                var rows = '';
                months.forEach(function(m) {
                    var locked = spreadOverrides[m.key]!==undefined;
                    var amt = amts[m.key]||0;
                    var badge = locked ? '<span style="font-size:10px;background:#dbeafe;color:#1d4ed8;padding:1px 5px;border-radius:3px;margin-left:4px;font-weight:600">KHUSUS</span>' : '';
                    var rst   = locked ? '<button type="button" onclick="clearSpreadOvr(\''+m.key+'\')" style="font-size:10px;padding:1px 5px;margin-left:4px;background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:3px;cursor:pointer">Reset</button>' : '';
                    var ibg   = locked ? 'background:#dbeafe;font-weight:700;' : '';
                    rows += '<tr><td style="padding:3px 8px 3px 0;color:#374151;white-space:nowrap">'+m.label+badge+rst+'</td>'
                          + '<td style="padding:3px 0;text-align:right"><input type="text" inputmode="numeric" data-period="'+m.key+'" value="'+amt.toLocaleString('id-ID')+'" '
                          + 'style="text-align:right;width:140px;font-size:13px;'+ibg+'" '
                          + 'onchange="setSpreadOvr(\''+m.key+'\',this.value)" oninput="fmtSpreadInp(this)"></td></tr>';
                });
                spreadDiv.innerHTML = '<div style="font-weight:700;color:#0369a1;margin-bottom:8px">Spread ('+months.length+' bulan) | Total: Rp '+grand.toLocaleString('id-ID')+'</div>'
                    +'<table style="border-collapse:collapse;background:transparent;width:100%">'+rows+'</table>';
                spreadDiv.style.display = 'block';
                syncOvrInputs();
            }

            function fmtSpreadInp(inp) { var r=inp.value.replace(/\D/g,''); inp.value=r?parseInt(r,10).toLocaleString('id-ID'):''; }
            function setSpreadOvr(k,v) { var r=String(v).replace(/\D/g,''); if(!r){clearSpreadOvr(k);return;} spreadOverrides[k]=parseInt(r,10); renderSpreadTable(); }
            function clearSpreadOvr(k) { delete spreadOverrides[k]; renderSpreadTable(); }
            function flushSpreadInputs() {
                // Baca langsung dari DOM, handle kasus user ganti angka lalu langsung Save tanpa blur
                var spreadDiv = document.getElementById('kalkulasi-spread');
                if (!spreadDiv) return;
                spreadDiv.querySelectorAll('input[data-period]').forEach(function(inp) {
                    var k = inp.getAttribute('data-period');
                    var r = inp.value.replace(/\D/g,'');
                    if (r) spreadOverrides[k] = parseInt(r, 10);
                });
            }
            function syncOvrInputs() {
                flushSpreadInputs();
                var form=document.querySelector('form');
                if(!form) return;
                form.querySelectorAll('input[name^="month_overrides["]').forEach(function(el){el.remove();});
                Object.keys(spreadOverrides).forEach(function(k){
                    var inp=document.createElement('input'); inp.type='hidden';
                    inp.name='month_overrides['+k+']'; inp.value=spreadOverrides[k]; form.appendChild(inp);
                });
            }
            document.querySelector('form').addEventListener('submit', syncOvrInputs);
            // ─────────────────────────────────────────────────────────────────

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

                const recogEl   = document.getElementById('recognition_month');
                const spreadDiv = document.getElementById('kalkulasi-spread');
                if (spreadDiv && recogEl && recogEl.value === 'spread' && startVal && endVal) {
                    const overrideRaw = document.querySelector('.override-val');
                    const finalAmount = (overrideRaw && overrideRaw.value) ? parseFloat(overrideRaw.value) : total;
                    spreadBaseTotal   = finalAmount;
                    spreadBaseStart   = startVal;
                    spreadBaseEnd     = endVal;
                    spreadBasePricing = pricing;
                    spreadBaseCycle   = (document.getElementById('cycle_recognition') || {value:'cycle_start'}).value;
                    renderSpreadTable();
                } else if (spreadDiv) {
                    spreadDiv.style.display = 'none';
                }
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

            // Auto-tampilkan spread table saat buka edit form transaksi recurring
            <?php if (($trx['billing_method'] ?? '') === 'spread'): ?>
            kalkulasiTotal();
            <?php endif; ?>
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
        'billing_method'     => 'anchor_cycle',
        'cycle_recognition'  => post('cycle_recognition', 'cycle_start'),
        'pic_name'           => post('pic_name'),
        'remarks'            => post('remarks'),
        'invoice_no'         => trim((string) post('invoice_no')) ?: null,
    ];
    $calculated = AllocationService::totalCalculated($trx);
    $override = post('override_amount') !== '' ? (float) post('override_amount') : null;
    $trx['total_calculated'] = $calculated;
    $trx['override_amount']  = $override;
    $trx['final_amount']     = $override ?: $calculated;
    $recognitionMonth = post('recognition_month', 'start');
    if ($recognitionMonth === 'spread') {
        $trx['billing_method'] = 'spread';
        $trx['period_key']     = substr($start, 0, 7);
    } else {
        $trx['period_key'] = $recognitionMonth === 'end' ? substr($end, 0, 7) : substr($start, 0, 7);
        if (substr($start, 0, 7) !== substr($end, 0, 7)) {
            $trx['recognition_period'] = $trx['period_key'];
        }
    }

    $pdo->prepare(
        'UPDATE transactions SET master_code=:master_code, period_key=:period_key,
         client_id=:client_id, contact_id=:contact_id,
         content_note=:content_note, start_date=:start_date, end_date=:end_date, quantity=:quantity, slots=:slots,
         area_sqm=:area_sqm, pricing_type=:pricing_type, unit_rate=:unit_rate, contract_months=:contract_months,
         billing_method=:billing_method, cycle_recognition=:cycle_recognition,
         total_calculated=:total_calculated, override_amount=:override_amount, final_amount=:final_amount,
         pic_name=:pic_name, remarks=:remarks, invoice_no=:invoice_no,
         updated_at=CURRENT_TIMESTAMP, updated_by=:updated_by WHERE id=:id AND property_id=:property_id'
    )->execute([
        ':master_code'       => $trx['master_code'],
        ':period_key'        => $trx['period_key'],
        ':client_id'         => $clientId ?: null,
        ':contact_id'        => $contactId ?: null,
        ':content_note'      => $trx['content_note'],
        ':start_date'        => $trx['start_date'],
        ':end_date'          => $trx['end_date'],
        ':quantity'          => $trx['quantity'],
        ':slots'             => $trx['slots'],
        ':area_sqm'          => $trx['area_sqm'],
        ':pricing_type'      => $trx['pricing_type'],
        ':unit_rate'         => $trx['unit_rate'],
        ':contract_months'   => $trx['contract_months'],
        ':billing_method'    => $trx['billing_method'],
        ':cycle_recognition' => $trx['cycle_recognition'],
        ':total_calculated'  => $trx['total_calculated'],
        ':override_amount'   => $trx['override_amount'],
        ':final_amount'      => $trx['final_amount'],
        ':pic_name'         => $trx['pic_name'],
        ':remarks'          => $trx['remarks'],
        ':invoice_no'       => $trx['invoice_no'],
        ':updated_by'       => $_SESSION['user']['name'] ?? 'system',
        ':id'               => $id,
        ':property_id'      => current_property_id(),
    ]);

    $monthOverrides = [];
    foreach (($_POST['month_overrides'] ?? []) as $k => $v) {
        if (preg_match('/^\d{4}-\d{2}$/', (string) $k) && $v !== '') {
            $monthOverrides[(string) $k] = (float) $v;
        }
    }
    AllocationService::saveAllocations($pdo, $id, $trx, $monthOverrides);
    if ($monthOverrides) {
        $s = $pdo->prepare('SELECT SUM(amount) FROM transaction_allocations WHERE transaction_id=? AND property_id=?');
        $s->execute([$id, current_property_id()]);
        $newFinal = (float) ($s->fetchColumn() ?: $trx['final_amount']);
        $pdo->prepare('UPDATE transactions SET final_amount=? WHERE id=?')->execute([$newFinal, $id]);
    }
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
                    <h2><?= h($trx['company_name'] ?? '-') ?> <span class="badge"><?= h($moduleLabel[$trx['module']] ?? $trx['module']) ?></span><?= ($trx['billing_method'] ?? '') === 'spread' ? ' <span class="badge" style="background:var(--accent-light,#e8f4ff);color:var(--accent,#2563eb)">Recurring</span>' : '' ?></h2>
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
                    <thead><tr>
                        <th>Bulan</th><th>Periode Alokasi</th><th>Hari</th><th>Capacity-days</th><th>Aktual</th>
                        <?php if (($trx['billing_method'] ?? '') === 'spread' && can('manage_transactions')): ?><th></th><?php endif; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($alloc->fetchAll() as $row):
                        $isSpreadEditable = ($trx['billing_method'] ?? '') === 'spread' && can('manage_transactions');
                    ?>
                        <tr id="row-<?= (int)$row['id'] ?>">
                            <td><?= h(period_label($row['period_key'])) ?></td>
                            <td><?= h($row['allocation_start'] . ' s/d ' . $row['allocation_end']) ?></td>
                            <td><?= h((string) $row['allocated_days']) ?></td>
                            <td><?= h((string) $row['capacity_days']) ?></td>
                            <td id="amt-<?= (int)$row['id'] ?>"><?= money($row['amount']) ?></td>
                            <?php if ($isSpreadEditable): ?>
                            <td style="white-space:nowrap">
                                <button type="button" class="btn light" style="font-size:11px;padding:2px 8px" onclick="toggleEditRow(<?= (int)$row['id'] ?>, <?= (int)$row['amount'] ?>)">Edit</button>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php if ($isSpreadEditable): ?>
                        <tr id="edit-<?= (int)$row['id'] ?>" style="display:none;background:#f0f9ff">
                            <td colspan="6" style="padding:8px 12px">
                                <form method="post" action="?r=allocation_amount_override" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                    <input type="hidden" name="alloc_id" value="<?= (int)$row['id'] ?>">
                                    <input type="hidden" name="transaction_id" value="<?= (int)$trx['id'] ?>">
                                    <label style="font-size:12px;font-weight:600;color:#0369a1"><?= h(period_label($row['period_key'])) ?> — Special Price:</label>
                                    <input type="text" inputmode="numeric" id="edit-fmt-<?= (int)$row['id'] ?>"
                                        style="width:150px;font-size:13px"
                                        value="<?= number_format((int)$row['amount'], 0, ',', '.') ?>"
                                        oninput="syncAmt(this, 'edit-raw-<?= (int)$row['id'] ?>')">
                                    <input type="hidden" name="new_amount" id="edit-raw-<?= (int)$row['id'] ?>" value="<?= (int)$row['amount'] ?>">
                                    <button type="submit" style="font-size:12px">Simpan</button>
                                    <button type="button" class="btn secondary" style="font-size:12px" onclick="toggleEditRow(<?= (int)$row['id'] ?>)">Batal</button>
                                    <span style="font-size:11px;color:#6b7280">Total transaksi akan dihitung ulang dari jumlah semua bulan.</span>
                                </form>
                            </td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if (($trx['billing_method'] ?? '') === 'spread' && can('manage_transactions')): ?>
        <script>
        function toggleEditRow(id, currentAmt) {
            var editRow = document.getElementById('edit-' + id);
            var isHidden = editRow.style.display === 'none';
            // tutup semua edit row lain
            document.querySelectorAll('tr[id^="edit-"]').forEach(function(r) { r.style.display = 'none'; });
            if (isHidden) {
                editRow.style.display = '';
                if (currentAmt !== undefined) {
                    var fmt = document.getElementById('edit-fmt-' + id);
                    var raw = document.getElementById('edit-raw-' + id);
                    fmt.value = currentAmt.toLocaleString('id-ID');
                    raw.value = currentAmt;
                }
            }
        }
        function syncAmt(input, rawId) {
            var raw = input.value.replace(/\D/g, '');
            input.value = raw ? parseInt(raw, 10).toLocaleString('id-ID') : '';
            document.getElementById(rawId).value = raw;
        }
        </script>
        <?php endif; ?>
        <?php
    });
}

function allocation_amount_override(PDO $pdo): void
{
    if (!can('manage_transactions')) {
        http_response_code(403); exit('Akses ditolak.');
    }
    verify_csrf();

    $allocId  = (int)   post('alloc_id');
    $trxId    = (int)   post('transaction_id');
    $newAmt   = (float) post('new_amount');
    $pid      = current_property_id();

    // Verifikasi alokasi milik properti & transaksi yang tepat, dan transaksinya adalah spread
    $alloc = $pdo->prepare(
        'SELECT ta.id, ta.period_key FROM transaction_allocations ta
         JOIN transactions t ON t.id = ta.transaction_id
         WHERE ta.id = ? AND ta.transaction_id = ? AND ta.property_id = ? AND t.billing_method = "spread"'
    );
    $alloc->execute([$allocId, $trxId, $pid]);
    $row = $alloc->fetch();

    if (!$row) {
        flash('Alokasi tidak ditemukan atau bukan transaksi recurring.');
        redirect_to('allocation_detail', ['id' => $trxId]);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE transaction_allocations SET amount = ? WHERE id = ?')
            ->execute([$newAmt, $allocId]);

        // Recalculate final_amount transaksi = SUM semua alokasi
        $sumStmt = $pdo->prepare('SELECT SUM(amount) FROM transaction_allocations WHERE transaction_id = ? AND property_id = ?');
        $sumStmt->execute([$trxId, $pid]);
        $newFinal = (float) ($sumStmt->fetchColumn() ?: 0);

        $pdo->prepare('UPDATE transactions SET final_amount = ?, updated_by = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$newFinal, $_SESSION['user']['name'] ?? 'system', $trxId]);

        audit($pdo, 'allocation_override', 'transaction_allocations', (string) $allocId, [
            'transaction_id' => $trxId,
            'period_key'     => $row['period_key'],
            'new_amount'     => $newAmt,
            'new_final'      => $newFinal,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('Gagal simpan: ' . $e->getMessage());
        redirect_to('allocation_detail', ['id' => $trxId]);
    }

    flash('Amount ' . $row['period_key'] . ' diperbarui. Total recurring diperbarui ke ' . number_format($newFinal, 0, ',', '.') . '.');
    redirect_to('allocation_detail', ['id' => $trxId]);
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

function transaction_overlaps_page(PDO $pdo): void
{
    $pid          = current_property_id();
    $moduleFilter = getv('module', '');
    $periodFilter = getv('period', '');  // YYYY-MM
    $moduleLabel  = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];

    $params = [$pid];
    $extraWhere = '';
    if ($moduleFilter) {
        $extraWhere .= ' AND t1.module = ?';
        $params[] = $moduleFilter;
    }
    if ($periodFilter) {
        // overlap window [GREATEST(start1,start2), LEAST(end1,end2)] harus menyentuh bulan yang dipilih
        $extraWhere .= ' AND GREATEST(t1.start_date, t2.start_date) <= LAST_DAY(?)
                         AND LEAST(t1.end_date, t2.end_date) >= ?';
        $params[] = $periodFilter . '-01';
        $params[] = $periodFilter . '-01';
    }

    $stmt = $pdo->prepare(
        "SELECT t1.id id1, t1.module, t1.master_code,
                t1.start_date start1, t1.end_date end1,
                COALESCE(c1.company_name, '-') client1, COALESCE(t1.pic_name, '-') pic1,
                t2.id id2,
                t2.start_date start2, t2.end_date end2,
                COALESCE(c2.company_name, '-') client2, COALESCE(t2.pic_name, '-') pic2
         FROM transactions t1
         JOIN transactions t2 ON t2.master_code = t1.master_code
             AND t2.property_id = t1.property_id
             AND t2.id > t1.id
             AND t1.start_date <= t2.end_date
             AND t1.end_date   >= t2.start_date
         LEFT JOIN master_clients c1 ON c1.id = t1.client_id
         LEFT JOIN master_clients c2 ON c2.id = t2.client_id
         WHERE t1.deleted_at IS NULL
           AND t2.deleted_at IS NULL
           AND t1.property_id = ?
           $extraWhere
         ORDER BY t1.master_code, t1.start_date
         LIMIT 500"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    layout('Cek Overlap Transaksi', function () use ($rows, $moduleFilter, $periodFilter, $moduleLabel) {
        ?>
        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:14px">
            <a class="btn secondary" href="?r=transactions">← Kembali</a>
            <form method="get" style="display:inline-flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0">
                <input type="hidden" name="r" value="transaction_overlaps">
                <div>
                    <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px">Bulan</label>
                    <input type="month" name="period" value="<?= h($periodFilter) ?>" style="width:160px">
                </div>
                <div>
                    <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px">Modul</label>
                    <select name="module" style="width:auto">
                        <option value="">Semua Modul</option>
                        <?php foreach (['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'] as $k => $v): ?>
                            <option value="<?= h($k) ?>" <?= $moduleFilter === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="align-self:flex-end;display:flex;gap:6px">
                    <button type="submit">Cari</button>
                    <?php if ($moduleFilter || $periodFilter): ?>
                        <a class="btn secondary" href="?r=transaction_overlaps">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if (empty($rows)): ?>
            <div class="panel" style="text-align:center;padding:40px;color:var(--muted)">
                <div style="font-size:32px;margin-bottom:10px">✅</div>
                <div style="font-weight:600;color:var(--ink)">Tidak ada overlap ditemukan</div>
                <div style="margin-top:6px;font-size:13px">
                    Semua transaksi<?= $moduleFilter ? ' (' . h($moduleLabel[$moduleFilter]) . ')' : '' ?>
                    <?= $periodFilter ? ' di bulan ' . h(period_label($periodFilter)) : '' ?>
                    tidak saling tumpang tindih tanggalnya.
                </div>
            </div>
        <?php else: ?>
            <div style="margin-bottom:12px;padding:10px 14px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;font-size:13px;color:#92400e">
                Ditemukan <strong><?= count($rows) ?></strong> pasang transaksi dengan tanggal yang overlap
                <?= $periodFilter ? 'di bulan <strong>' . h(period_label($periodFilter)) . '</strong>' : '' ?>.
                Ini bisa wajar jika unit memang bisa dibagi per slot/luasan — periksa apakah memang disengaja.
            </div>
            <div class="table-wrap">
                <table style="font-size:12px">
                    <thead>
                        <tr>
                            <th>Kode Unit</th>
                            <th>Modul</th>
                            <th>Transaksi A</th>
                            <th>Periode A</th>
                            <th>PIC A</th>
                            <th>Transaksi B</th>
                            <th>Periode B</th>
                            <th>PIC B</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td style="font-weight:600"><?= h($row['master_code']) ?></td>
                            <td><span style="font-size:11px;font-weight:700;text-transform:uppercase"><?= h($row['module']) ?></span></td>
                            <td>
                                <a href="?r=allocation_detail&id=<?= (int)$row['id1'] ?>">#<?= (int)$row['id1'] ?></a><br>
                                <span style="color:var(--muted)"><?= h($row['client1']) ?></span>
                            </td>
                            <td style="white-space:nowrap"><?= h($row['start1']) ?><br><?= h($row['end1']) ?></td>
                            <td><?= h($row['pic1']) ?></td>
                            <td>
                                <a href="?r=allocation_detail&id=<?= (int)$row['id2'] ?>">#<?= (int)$row['id2'] ?></a><br>
                                <span style="color:var(--muted)"><?= h($row['client2']) ?></span>
                            </td>
                            <td style="white-space:nowrap"><?= h($row['start2']) ?><br><?= h($row['end2']) ?></td>
                            <td><?= h($row['pic2']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?php
    });
}

function transaction_overlap_check(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    $masterCode = (string) getv('master_code', '');
    $startDate  = (string) getv('start_date', '');
    $endDate    = (string) getv('end_date', '');
    $excludeId  = (int)   getv('exclude_id', 0);
    $pid        = current_property_id();

    if (!$masterCode || !$startDate || !$endDate) {
        echo json_encode(['overlaps' => []]);
        exit;
    }

    $stmt = $pdo->prepare(
        "SELECT t.id, t.start_date, t.end_date, COALESCE(c.company_name, '-') company_name, COALESCE(t.pic_name, '-') pic_name
         FROM transactions t
         LEFT JOIN master_clients c ON c.id = t.client_id
         WHERE t.master_code = ?
           AND t.property_id = ?
           AND t.deleted_at IS NULL
           AND t.start_date <= ?
           AND t.end_date   >= ?
           AND (? = 0 OR t.id != ?)
         ORDER BY t.start_date
         LIMIT 10"
    );
    $stmt->execute([$masterCode, $pid, $endDate, $startDate, $excludeId, $excludeId]);
    echo json_encode(['overlaps' => $stmt->fetchAll()]);
    exit;
}
