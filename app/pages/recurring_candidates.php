<?php
declare(strict_types=1);

function recurring_candidates_page(PDO $pdo): void
{
    if (current_role() !== 'superadmin') {
        http_response_code(403); exit('Akses ditolak.');
    }

    $action = getv('action', 'list');

    if ($action === 'review') {
        _recurring_review_page($pdo);
    } else {
        _recurring_list_page($pdo);
    }
}

// ─── LIST PAGE ────────────────────────────────────────────────────────────────

function _recurring_list_page(PDO $pdo): void
{
    $moduleFilter     = getv('module', '');
    $confidenceFilter = getv('confidence', '');
    $propertyFilter   = (int) getv('property_id', 0);

    $properties = $pdo->query("SELECT id, name FROM properties WHERE status='active' ORDER BY id")->fetchAll();

    // jumlah_bulan harus = rentang bulan (tidak ada lompatan)
    $having = 'HAVING jumlah_bulan >= 2
         AND COUNT(*) = PERIOD_DIFF(
             REPLACE(MAX(t.period_key),\'-\',\'\'),
             REPLACE(MIN(t.period_key),\'-\',\'\')
         ) + 1';
    if ($moduleFilter)                  $having .= ' AND t.module = ' . $pdo->quote($moduleFilter);
    if ($confidenceFilter === 'high')   $having .= ' AND ROUND(STDDEV(t.final_amount)) = 0';
    if ($confidenceFilter === 'medium') $having .= ' AND ROUND(STDDEV(t.final_amount)) > 0';
    if ($propertyFilter)                $having .= ' AND t.property_id = ' . $propertyFilter;

    $rows = $pdo->query(
        "SELECT
            t.master_code, t.module, t.client_id, t.property_id,
            c.company_name,
            c.brand_name,
            p.name AS property_name,
            COUNT(*) jumlah_bulan,
            MIN(t.start_date) start_min,
            MAX(t.end_date) end_max,
            MIN(t.period_key) period_awal,
            MAX(t.period_key) period_akhir,
            ROUND(AVG(t.final_amount)) avg_amount,
            ROUND(STDDEV(t.final_amount)) stddev_amount,
            ROUND(SUM(t.final_amount)) total_amount,
            t.pricing_type,
            t.pic_name,
            t.area_sqm,
            t.unit_rate,
            t.slots
         FROM transactions t
         LEFT JOIN master_clients c ON c.id = t.client_id
         LEFT JOIN properties p ON p.id = t.property_id
         WHERE t.deleted_at IS NULL
           AND t.billing_method = 'anchor_cycle'
           AND t.client_id IS NOT NULL
         GROUP BY t.master_code, t.client_id, t.property_id, t.pricing_type
         $having
         ORDER BY
             t.property_id ASC,
             CASE t.module WHEN 'cl' THEN 1 WHEN 'media' THEN 2 WHEN 'gudang' THEN 3 ELSE 4 END,
             ROUND(STDDEV(t.final_amount)) ASC,
             jumlah_bulan DESC"
    )->fetchAll();

    $totalGroups = count($rows);
    $totalTrx    = array_sum(array_column($rows, 'jumlah_bulan'));
    $highCount   = count(array_filter($rows, fn($r) => (int)$r['stddev_amount'] === 0));
    $medCount    = $totalGroups - $highCount;

    layout('Konversi Recurring', function () use ($rows, $totalGroups, $totalTrx, $highCount, $medCount, $moduleFilter, $confidenceFilter, $propertyFilter, $properties) {
        ?>
        <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:10px;padding:14px 18px;margin-bottom:16px;font-size:13px;color:#92400e">
            <strong>Halaman ini hanya untuk superadmin.</strong>
            Proses merge akan soft-delete transaksi lama dan membuat 1 transaksi baru <code>billing_method=spread</code>.
            Pastikan review tiap grup sebelum konfirmasi.
        </div>

        <!-- STATS -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px">
            <?php foreach ([
                ['Total Grup', $totalGroups, '#6366f1'],
                ['Total Transaksi', $totalTrx, '#0891b2'],
                ['High Confidence', $highCount, '#0d9488'],
                ['Medium Confidence', $medCount, '#d97706'],
            ] as [$lbl, $val, $clr]): ?>
            <div class="panel" style="padding:12px 16px">
                <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--muted);letter-spacing:.05em"><?= $lbl ?></div>
                <div style="font-size:24px;font-weight:800;color:<?= $clr ?>;margin-top:4px"><?= number_format($val, 0, ',', '.') ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- FILTER -->
        <form method="get" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-bottom:14px">
            <input type="hidden" name="r" value="recurring_candidates">
            <div>
                <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px">Properti</label>
                <select name="property_id" style="width:auto">
                    <option value="">Semua Properti</option>
                    <?php foreach ($properties as $prop): ?>
                        <option value="<?= $prop['id'] ?>" <?= $propertyFilter===(int)$prop['id']?'selected':'' ?>><?= h($prop['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px">Modul</label>
                <select name="module" style="width:auto">
                    <option value="">Semua Modul</option>
                    <?php foreach (['cl'=>'Exhibition','media'=>'Media','gudang'=>'Gudang'] as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= $moduleFilter===$k?'selected':'' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px">Confidence</label>
                <select name="confidence" style="width:auto">
                    <option value="">Semua</option>
                    <option value="high"   <?= $confidenceFilter==='high'?'selected':'' ?>>High (amount identik)</option>
                    <option value="medium" <?= $confidenceFilter==='medium'?'selected':'' ?>>Medium (amount bervariasi)</option>
                </select>
            </div>
            <div style="display:flex;gap:6px;align-self:flex-end">
                <button type="submit">Filter</button>
                <?php if ($moduleFilter || $confidenceFilter || $propertyFilter): ?>
                    <a class="btn secondary" href="?r=recurring_candidates">Reset</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if (empty($rows)): ?>
            <div class="panel" style="text-align:center;padding:40px;color:var(--muted)">
                <div style="font-size:28px;margin-bottom:8px">✅</div>
                <div style="font-weight:600;color:var(--ink)">Tidak ada kandidat ditemukan</div>
            </div>
        <?php else: ?>
        <div class="table-wrap">
            <table style="font-size:12px">
                <thead>
                    <tr>
                        <th>Unit</th>
                        <th>Modul</th>
                        <th>Properti</th>
                        <th>Client</th>
                        <th>PIC</th>
                        <th style="text-align:center">Bulan</th>
                        <th>Periode</th>
                        <th style="text-align:right">Nilai/Bln</th>
                        <th style="text-align:right">Total</th>
                        <th style="text-align:center">Confidence</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row):
                    $isHigh = (int)$row['stddev_amount'] === 0;
                ?>
                    <tr>
                        <td style="font-weight:600"><?= h($row['master_code']) ?></td>
                        <td><span style="font-size:11px;font-weight:700;text-transform:uppercase"><?= h($row['module']) ?></span></td>
                        <td style="color:var(--muted)"><?= h($row['property_name']) ?></td>
                        <td>
                            <?= h($row['company_name'] ?? '-') ?>
                            <?php if (!empty($row['brand_name'])): ?>
                                <br><span style="font-size:11px;color:var(--muted)"><?= h($row['brand_name']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="color:var(--muted)"><?= h($row['pic_name'] ?? '-') ?></td>
                        <td style="text-align:center;font-weight:700"><?= $row['jumlah_bulan'] ?></td>
                        <td style="white-space:nowrap;color:var(--muted)"><?= h($row['period_awal']) ?> – <?= h($row['period_akhir']) ?></td>
                        <td style="text-align:right"><?= money($row['avg_amount']) ?></td>
                        <td style="text-align:right;font-weight:600"><?= money($row['total_amount']) ?></td>
                        <td style="text-align:center">
                            <?php if ($isHigh): ?>
                                <span style="background:#d1fae5;color:#065f46;font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px">High</span>
                            <?php else: ?>
                                <span style="background:#fef3c7;color:#92400e;font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px">Medium</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="btn light" style="font-size:11px;white-space:nowrap" href="?r=recurring_candidates&action=review&master_code=<?= urlencode($row['master_code']) ?>&client_id=<?= (int)$row['client_id'] ?>&property_id=<?= (int)$row['property_id'] ?>">Review →</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php
    });
}

// ─── REVIEW PAGE ──────────────────────────────────────────────────────────────

function _recurring_review_page(PDO $pdo): void
{
    $masterCode = (string) getv('master_code', '');
    $clientId   = (int)   getv('client_id', 0);
    $propertyId = (int)   getv('property_id', 0);

    if (!$masterCode || !$clientId || !$propertyId) {
        flash('Parameter tidak lengkap.');
        redirect_to('recurring_candidates');
    }

    // Ambil semua transaksi kandidat
    $s = $pdo->prepare(
        "SELECT t.*, c.company_name, c.brand_name
         FROM transactions t
         LEFT JOIN master_clients c ON c.id = t.client_id
         WHERE t.deleted_at IS NULL
           AND t.billing_method = 'anchor_cycle'
           AND t.master_code = ? AND t.client_id = ? AND t.property_id = ?
         ORDER BY t.period_key ASC"
    );
    $s->execute([$masterCode, $clientId, $propertyId]);
    $trxList = $s->fetchAll();

    if (empty($trxList)) {
        flash('Grup tidak ditemukan atau sudah dikonversi.');
        redirect_to('recurring_candidates');
    }

    $first       = $trxList[0];
    $last        = end($trxList);
    $avgAmount   = round(array_sum(array_column($trxList, 'final_amount')) / count($trxList));
    $totalAmount = array_sum(array_column($trxList, 'final_amount'));
    $prefillStartDate = $first['start_date'];
    $prefillEndDate   = $last['end_date'];
    $isCrossMonth     = substr($prefillStartDate, 8, 2) !== '01';
    $monthNames  = ['01'=>'Jan','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'Mei','06'=>'Jun',
                    '07'=>'Jul','08'=>'Ags','09'=>'Sep','10'=>'Okt','11'=>'Nov','12'=>'Des'];

    layout('Review Konversi Recurring — ' . h($masterCode), function () use (
        $trxList, $first, $last, $avgAmount, $totalAmount, $masterCode, $clientId, $propertyId, $monthNames, $prefillStartDate, $prefillEndDate, $isCrossMonth
    ) {
        ?>
        <div style="margin-bottom:12px">
            <a class="btn secondary" href="?r=recurring_candidates">← Kembali ke Daftar</a>
        </div>

        <!-- TRANSAKSI YANG AKAN DI-SOFT-DELETE -->
        <div class="section-title">Transaksi Lama (akan di-soft-delete)</div>
        <div class="panel" style="margin-bottom:16px">
            <div class="table-wrap" style="margin:0">
                <table style="font-size:12px">
                    <thead>
                        <tr><th>#ID</th><th>Periode</th><th>Tanggal</th><th>Pricing</th><th style="text-align:right">Nilai</th><th>PIC</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($trxList as $t): ?>
                        <tr>
                            <td><a href="?r=allocation_detail&id=<?= (int)$t['id'] ?>">#<?= (int)$t['id'] ?></a></td>
                            <td><?= h($t['period_key']) ?></td>
                            <td style="color:var(--muted);white-space:nowrap"><?= h($t['start_date']) ?> s/d <?= h($t['end_date']) ?></td>
                            <td><?= h($t['pricing_type']) ?></td>
                            <td style="text-align:right"><?= money($t['final_amount']) ?></td>
                            <td><?= h($t['pic_name'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:700;border-top:2px solid var(--line)">
                            <td colspan="4">Total (<?= count($trxList) ?> transaksi)</td>
                            <td style="text-align:right"><?= money($totalAmount) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- FORM TRANSAKSI BARU -->
        <div class="section-title">Transaksi Baru (Recurring / Spread)</div>
        <form class="panel" method="post" action="?r=recurring_merge_execute" id="merge-form">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="master_code"  value="<?= h($masterCode) ?>">
            <input type="hidden" name="client_id"    value="<?= $clientId ?>">
            <input type="hidden" name="property_id"  value="<?= $propertyId ?>">
            <input type="hidden" name="module"        value="<?= h($first['module']) ?>">
            <input type="hidden" name="pricing_type"  value="<?= h($first['pricing_type']) ?>">
            <input type="hidden" name="area_sqm"      value="<?= h((string)$first['area_sqm']) ?>">
            <input type="hidden" name="slots"         value="<?= h((string)($first['slots'] ?? 1)) ?>">
            <input type="hidden" name="contact_id"    value="<?= (int)($first['contact_id'] ?? 0) ?>">

            <div class="form-grid">
                <div>
                    <label>Client</label>
                    <input type="text" value="<?= h($first['company_name'] ?? '-') ?><?= !empty($first['brand_name']) ? ' (' . h($first['brand_name']) . ')' : '' ?>" disabled style="background:#f8fafc">
                </div>
                <div>
                    <label>Unit / Kode Master</label>
                    <input type="text" value="<?= h($masterCode) ?>" disabled style="background:#f8fafc">
                </div>
                <div>
                    <label>Tanggal Mulai</label>
                    <input type="date" name="start_date" id="start_date" value="<?= h($prefillStartDate) ?>" required>
                    <div class="help">Tanggal kontrak asli. Bisa diubah.</div>
                </div>
                <div>
                    <label>Tanggal Selesai</label>
                    <input type="date" name="end_date" id="end_date" value="<?= h($prefillEndDate) ?>" required>
                    <div class="help">Tanggal kontrak asli. Perpanjang jika perlu.</div>
                </div>
                <div>
                    <label>Pengakuan per Siklus</label>
                    <select name="cycle_recognition" id="cycle_recognition">
                        <option value="cycle_start">Bulan Awal siklus (default)</option>
                        <option value="cycle_end">Bulan Akhir siklus</option>
                    </select>
                    <div class="help">
                        <?php if ($isCrossMonth): ?>
                            Kontrak lintas bulan — tentukan revenue tiap siklus diakui di bulan awal atau akhirnya.
                        <?php else: ?>
                            Kontrak mulai tanggal 1 — siklus dan bulan kalender sudah sejajar.
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <label>Nilai per Bulan (Rp)</label>
                    <input type="text" inputmode="numeric" id="amount_fmt" value="<?= number_format($avgAmount, 0, ',', '.') ?>" placeholder="Nilai per bulan...">
                    <input type="hidden" name="amount_per_month" id="amount_per_month" value="<?= $avgAmount ?>">
                    <div class="help">Pre-fill: rata-rata nilai <?= count($trxList) ?> transaksi lama.</div>
                </div>
                <div>
                    <label>Override Total <span class="muted" style="font-weight:400">(opsional)</span></label>
                    <input type="text" inputmode="numeric" id="override_fmt" placeholder="Kosongkan jika pakai nilai/bulan">
                    <input type="hidden" name="override_amount" id="override_amount" value="">
                    <div class="help">Isi jika total final berbeda dari perkalian nilai/bulan × jumlah bulan.</div>
                </div>
                <div>
                    <label>PIC Dealing</label>
                    <input type="text" name="pic_name" value="<?= h($first['pic_name'] ?? '') ?>" required>
                </div>
                <div>
                    <label>No. Invoice <span class="muted" style="font-weight:400">(opsional)</span></label>
                    <input type="text" name="invoice_no" value="<?= h($first['invoice_no'] ?? '') ?>" placeholder="cth. INV-2026/01/001">
                </div>
            </div>

            <!-- PREVIEW SPREAD -->
            <div style="margin-top:16px">
                <button type="button" class="btn light" onclick="previewSpread()" style="background:#0ea5e9;color:#fff">Kalkulasi & Preview Spread</button>
            </div>
            <div id="preview-box" style="display:none;margin-top:12px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:14px 18px;font-size:13px"></div>
            <input type="hidden" name="total_calculated" id="total_calculated" value="">

            <div style="margin-top:16px;padding:14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;font-size:13px;color:#991b1b">
                ⚠ <strong>Konfirmasi:</strong> Tindakan ini akan <strong>soft-delete <?= count($trxList) ?> transaksi lama</strong>
                dan membuat 1 transaksi recurring baru. Tidak bisa dibatalkan otomatis — pastikan preview sudah benar.
            </div>
            <p style="margin-top:14px">
                <button type="submit" onclick="return confirm('Yakin merge <?= count($trxList) ?> transaksi ini menjadi 1 recurring?')">
                    Konfirmasi & Merge Sekarang
                </button>
                <a class="btn secondary" href="?r=recurring_candidates">Batal</a>
            </p>
        </form>

        <script>
        const BULAN = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

        document.getElementById('amount_fmt').addEventListener('input', function() {
            var raw = this.value.replace(/\D/g,'');
            this.value = raw ? parseInt(raw,10).toLocaleString('id-ID') : '';
            document.getElementById('amount_per_month').value = raw;
        });
        document.getElementById('override_fmt').addEventListener('input', function() {
            var raw = this.value.replace(/\D/g,'');
            this.value = raw ? parseInt(raw,10).toLocaleString('id-ID') : '';
            document.getElementById('override_amount').value = raw;
        });

        // Hitung siklus bulanan dari start_date (mengikuti tanggal kontrak, bukan awal kalender)
        function buildCycles(startStr, endStr, recognition) {
            var cycles = [];
            var cursor = new Date(startStr);
            var endDate = new Date(endStr);
            var limit = 120;
            while (cursor <= endDate && limit-- > 0) {
                var cycleEnd = new Date(cursor.getFullYear(), cursor.getMonth() + 1, cursor.getDate() - 1);
                if (cycleEnd > endDate) cycleEnd = new Date(endDate);
                var periodDate = recognition === 'cycle_end' ? cycleEnd : cursor;
                cycles.push({
                    label: BULAN[periodDate.getMonth()] + ' ' + periodDate.getFullYear(),
                    start: new Date(cursor),
                    end:   new Date(cycleEnd),
                });
                cursor = new Date(cycleEnd);
                cursor.setDate(cursor.getDate() + 1);
            }
            return cycles;
        }

        function previewSpread() {
            var startVal    = document.getElementById('start_date').value;
            var endVal      = document.getElementById('end_date').value;
            var perMonth    = parseInt(document.getElementById('amount_per_month').value) || 0;
            var overrideV   = parseInt(document.getElementById('override_amount').value) || 0;
            var recognition = document.getElementById('cycle_recognition').value;

            if (!startVal || !endVal) { alert('Isi tanggal mulai dan selesai dulu.'); return; }

            var cycles = buildCycles(startVal, endVal, recognition);
            if (!cycles.length) { alert('Range tanggal tidak valid.'); return; }

            var total  = overrideV || (perMonth * cycles.length);
            var perC   = Math.floor(total / cycles.length);
            var rows   = '', running = 0;
            cycles.forEach(function(c, i) {
                var amt = (i === cycles.length-1) ? Math.round(total - running) : perC;
                running += amt;
                rows += '<tr><td style="padding:3px 16px 3px 0;color:#374151">'+c.label+'</td>'
                      + '<td style="text-align:right;font-weight:600;color:#0369a1">Rp '+amt.toLocaleString('id-ID')+'</td></tr>';
            });

            document.getElementById('total_calculated').value = total;
            document.getElementById('preview-box').innerHTML =
                '<div style="font-weight:700;color:#0369a1;margin-bottom:8px">Estimasi Spread — '+cycles.length+' siklus | Total: Rp '+total.toLocaleString('id-ID')+'</div>'
                + '<table style="border-collapse:collapse;width:100%;background:transparent">'+rows+'</table>';
            document.getElementById('preview-box').style.display = 'block';
        }
        </script>
        <?php
    });
}

// ─── EXECUTE MERGE ────────────────────────────────────────────────────────────

function recurring_merge_execute(PDO $pdo): void
{
    if (current_role() !== 'superadmin') {
        http_response_code(403); exit('Akses ditolak.');
    }
    verify_csrf();

    $masterCode  = (string) post('master_code');
    $clientId    = (int)    post('client_id');
    $propertyId  = (int)    post('property_id');
    $module      = (string) post('module');
    $startDate   = (string) post('start_date');
    $endDate     = (string) post('end_date');
    $pricingType = (string) post('pricing_type');
    $areaSqm     = (float)  post('area_sqm', 0);
    $slots       = (float)  post('slots', 1);
    $contactId   = (int)    post('contact_id', 0);
    $picName     = (string) post('pic_name');
    $invoiceNo   = trim((string) post('invoice_no')) ?: null;
    $amountPerMonth   = (float) post('amount_per_month', 0);
    $overrideAmount   = post('override_amount') !== '' ? (float) post('override_amount') : null;
    $totalCalculated  = (float) post('total_calculated', 0);
    $cycleRecognition = post('cycle_recognition', 'cycle_start'); // cycle_start | cycle_end

    if (!$masterCode || !$clientId || !$propertyId || !$startDate || !$endDate) {
        flash('Data tidak lengkap.');
        redirect_to('recurring_candidates');
    }

    // Ambil transaksi lama
    $s = $pdo->prepare(
        "SELECT id FROM transactions
         WHERE deleted_at IS NULL AND billing_method='anchor_cycle'
           AND master_code=? AND client_id=? AND property_id=?
         ORDER BY period_key ASC"
    );
    $s->execute([$masterCode, $clientId, $propertyId]);
    $oldIds = $s->fetchAll(PDO::FETCH_COLUMN);

    if (empty($oldIds)) {
        flash('Grup sudah dikonversi atau tidak ditemukan.');
        redirect_to('recurring_candidates');
    }

    // Hitung total final
    $finalAmount = $overrideAmount ?: ($amountPerMonth * _count_months($startDate, $endDate));

    $trx = [
        'property_id'      => $propertyId,
        'module'           => $module,
        'master_code'      => $masterCode,
        'start_date'       => $startDate,
        'end_date'         => $endDate,
        'pricing_type'     => $pricingType,
        'area_sqm'         => $areaSqm,
        'slots'            => $slots,
        'quantity'         => 1,
        'unit_rate'        => $amountPerMonth,
        'billing_method'   => 'spread',
        'period_key'       => substr($startDate, 0, 7),
        'total_calculated' => $totalCalculated ?: $finalAmount,
        'override_amount'  => $overrideAmount,
        'final_amount'     => $finalAmount,
        'pic_name'         => $picName,
        'invoice_no'       => $invoiceNo,
        'contract_months'  => null,
        'remarks'          => null,
        'content_note'     => null,
    ];

    $pdo->beginTransaction();
    try {
        $now = date('Y-m-d H:i:s');
        $actor = $_SESSION['user']['name'] ?? 'system';

        // Soft-delete transaksi lama + hapus alokasinya
        foreach ($oldIds as $oldId) {
            $pdo->prepare('UPDATE transactions SET deleted_at=?, deleted_by=? WHERE id=?')
                ->execute([$now, $actor, $oldId]);
            $pdo->prepare('DELETE FROM transaction_allocations WHERE transaction_id=? AND property_id=?')
                ->execute([$oldId, $propertyId]);
        }

        // Buat transaksi baru
        $ins = $pdo->prepare(
            'INSERT INTO transactions
             (property_id, module, master_code, period_key, client_id, contact_id,
              start_date, end_date, quantity, slots, area_sqm, pricing_type, unit_rate,
              billing_method, total_calculated, override_amount, final_amount,
              pic_name, invoice_no, created_by)
             VALUES
             (:property_id, :module, :master_code, :period_key, :client_id, :contact_id,
              :start_date, :end_date, :quantity, :slots, :area_sqm, :pricing_type, :unit_rate,
              :billing_method, :total_calculated, :override_amount, :final_amount,
              :pic_name, :invoice_no, :created_by)'
        );
        $ins->execute([
            ':property_id'      => $propertyId,
            ':module'           => $trx['module'],
            ':master_code'      => $trx['master_code'],
            ':period_key'       => $trx['period_key'],
            ':client_id'        => $clientId,
            ':contact_id'       => $contactId ?: null,
            ':start_date'       => $trx['start_date'],
            ':end_date'         => $trx['end_date'],
            ':quantity'         => $trx['quantity'],
            ':slots'            => $trx['slots'],
            ':area_sqm'         => $trx['area_sqm'],
            ':pricing_type'     => $trx['pricing_type'],
            ':unit_rate'        => $trx['unit_rate'],
            ':billing_method'   => 'spread',
            ':total_calculated' => $trx['total_calculated'],
            ':override_amount'  => $trx['override_amount'],
            ':final_amount'     => $trx['final_amount'],
            ':pic_name'         => $trx['pic_name'],
            ':invoice_no'       => $trx['invoice_no'],
            ':created_by'       => $actor,
        ]);
        $newId = (int) $pdo->lastInsertId();

        // Hitung alokasi — cycle-aware untuk pricing monthly
        if ($pricingType === 'monthly' && in_array($cycleRecognition, ['cycle_start', 'cycle_end'])) {
            _recurring_save_cycle_allocations($pdo, $newId, $trx, $cycleRecognition);
        } else {
            AllocationService::saveAllocations($pdo, $newId, $trx);
        }

        // Audit
        audit($pdo, 'recurring_merge', 'transactions', (string) $newId, [
            'merged_from'  => $oldIds,
            'master_code'  => $masterCode,
            'client_id'    => $clientId,
            'final_amount' => $finalAmount,
            'start_date'   => $startDate,
            'end_date'     => $endDate,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('Gagal merge: ' . $e->getMessage());
        redirect_to('recurring_candidates');
    }

    flash('Berhasil merge ' . count($oldIds) . ' transaksi → #' . $newId . ' (Recurring). Alokasi sudah dihitung ulang.');
    redirect_to('allocation_detail', ['id' => $newId]);
}

function _recurring_save_cycle_allocations(PDO $pdo, int $trxId, array $trx, string $recognition): void
{
    $cursor  = new DateTimeImmutable($trx['start_date']);
    $endDt   = new DateTimeImmutable($trx['end_date']);
    $final   = (float) $trx['final_amount'];
    $pid     = (int) $trx['property_id'];

    // Bangun siklus bulanan dari start_date
    $cycles = [];
    $limit  = 120;
    while ($cursor <= $endDt && $limit-- > 0) {
        $nextAnchor = $cursor->modify('+1 month');
        $cycleEnd   = $nextAnchor->modify('-1 day');
        if ($cycleEnd > $endDt) $cycleEnd = $endDt;

        $days      = (int) $cursor->diff($cycleEnd)->format('%a') + 1;
        $periodKey = ($recognition === 'cycle_end' ? $cycleEnd : $cursor)->format('Y-m');

        $cycles[] = [
            'period_key'       => $periodKey,
            'allocation_start' => $cursor->format('Y-m-d'),
            'allocation_end'   => $cycleEnd->format('Y-m-d'),
            'allocated_days'   => $days,
            'capacity_days'    => $days,
            'amount'           => 0,
        ];
        $cursor = $cycleEnd->modify('+1 day');
    }

    // Distribusi amount merata, selisih pembulatan ke siklus terakhir
    $n       = count($cycles);
    $perC    = (int) floor($final / $n);
    $running = 0;
    foreach ($cycles as $i => &$c) {
        $c['amount'] = ($i === $n - 1) ? (int) round($final - $running) : $perC;
        $running    += $c['amount'];
    }
    unset($c);

    $pdo->prepare('DELETE FROM transaction_allocations WHERE transaction_id=?')->execute([$trxId]);

    $stmt = $pdo->prepare(
        'INSERT INTO transaction_allocations
         (property_id, transaction_id, module, master_code, period_key,
          allocation_start, allocation_end, allocated_days, amount, capacity_days, pic_name)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    );
    foreach ($cycles as $c) {
        $stmt->execute([
            $pid, $trxId, $trx['module'], $trx['master_code'],
            $c['period_key'], $c['allocation_start'], $c['allocation_end'],
            $c['allocated_days'], $c['amount'], $c['capacity_days'],
            $trx['pic_name'] ?? null,
        ]);
    }
}

function _count_months(string $startDate, string $endDate): int
{
    $s    = new DateTimeImmutable(substr($startDate, 0, 7) . '-01');
    $e    = new DateTimeImmutable(substr($endDate,   0, 7) . '-01');
    $diff = $s->diff($e);
    return max(1, $diff->y * 12 + $diff->m + 1);
}
