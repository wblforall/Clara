<?php
declare(strict_types=1);

function dashboard(PDO $pdo): void
{
    $period = getv('period', date('Y-m'));
    $pid = current_property_id();
    $tgtStmt = $pdo->prepare("SELECT target_amount FROM targets_monthly WHERE period_key = ? AND property_id = ?");
    $tgtStmt->execute([$period, $pid]);
    $target = (float) ($tgtStmt->fetchColumn() ?: 0);
    $projection = get_projection($pdo, $period, $pid);
    $actualStmt = $pdo->prepare('SELECT module, COALESCE(SUM(amount),0) actual, COALESCE(SUM(capacity_days),0) capacity_days, COALESCE(SUM(allocated_days),0) allocated_days FROM transaction_allocations WHERE period_key = ? AND property_id = ? GROUP BY module');
    $actualStmt->execute([$period, $pid]);
    $actual = ['cl' => 0, 'media' => 0, 'gudang' => 0];
    $capacity = ['cl' => 0, 'media' => 0, 'gudang' => 0];
    $allocatedDays = ['cl' => 0, 'media' => 0, 'gudang' => 0];
    foreach ($actualStmt->fetchAll() as $row) {
        $actual[$row['module']] = (float) $row['actual'];
        $capacity[$row['module']] = (float) $row['capacity_days'];
        $allocatedDays[$row['module']] = (float) $row['allocated_days'];
    }
    $periodDays = (int) date('t', strtotime($period . '-01'));
    $ucClStmt = $pdo->prepare("SELECT COUNT(*) FROM master_cl_units WHERE status='active' AND property_id = ?");
    $ucClStmt->execute([$pid]);
    $ucMediaStmt = $pdo->prepare("SELECT COUNT(*) FROM master_media WHERE status='active' AND property_id = ?");
    $ucMediaStmt->execute([$pid]);
    $ucGudangStmt = $pdo->prepare("SELECT COUNT(*) FROM master_gudang WHERE status='active' AND property_id = ?");
    $ucGudangStmt->execute([$pid]);
    $unitCount = [
        'cl'     => (int) $ucClStmt->fetchColumn(),
        'media'  => (int) $ucMediaStmt->fetchColumn(),
        'gudang' => (int) $ucGudangStmt->fetchColumn(),
    ];
    $occupancy = [];
    foreach (['cl', 'media', 'gudang'] as $mod) {
        $maxDays = $unitCount[$mod] * $periodDays;
        $occupancy[$mod] = $maxDays > 0 ? $allocatedDays[$mod] / $maxDays : 0;
    }
    $totalProjection = array_sum($projection);
    $totalActual = array_sum($actual);
    $recurringStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(a.amount),0)
         FROM transaction_allocations a
         JOIN transactions t ON t.id = a.transaction_id AND t.billing_method = 'spread' AND t.deleted_at IS NULL
         WHERE a.period_key = ? AND a.property_id = ?"
    );
    $recurringStmt->execute([$period, $pid]);
    $totalRecurring = (float) $recurringStmt->fetchColumn();
    $detail = $pdo->prepare(
        "SELECT m.code, m.media_type, m.location, m.point,
                COALESCE(pp.potential_value, m.projection_monthly) AS projection_monthly,
                COALESCE(SUM(a.amount),0) actual, COALESCE(SUM(a.allocated_days),0) days
         FROM master_media m
         LEFT JOIN period_potentials pp ON pp.slot_id = m.id AND pp.segment = 'media'
             AND pp.period_key = ? AND pp.property_id = m.property_id
         LEFT JOIN transaction_allocations a ON a.master_code=m.code AND a.module='media' AND a.period_key=? AND a.property_id=?
         WHERE m.property_id = ?
         GROUP BY m.id
         ORDER BY m.code"
    );
    $detail->execute([$period, $period, $pid, $pid]);

    $detailCl = $pdo->prepare(
        "SELECT m.code, m.floor, m.location_name, m.unit_type, m.area_sqm,
                COALESCE(pp.potential_value, m.projection_monthly) AS projection_monthly,
                COALESCE(SUM(a.amount),0) actual, COALESCE(SUM(a.allocated_days),0) days
         FROM master_cl_units m
         LEFT JOIN period_potentials pp ON pp.slot_id = m.id AND pp.segment = 'exhibition'
             AND pp.period_key = ? AND pp.property_id = m.property_id
         LEFT JOIN transaction_allocations a ON a.master_code=m.code AND a.module='cl' AND a.period_key=? AND a.property_id=?
         WHERE m.property_id = ?
         GROUP BY m.id
         ORDER BY CASE m.floor WHEN 'LG' THEN 1 WHEN 'GF' THEN 2 WHEN 'UG' THEN 3 WHEN 'FF' THEN 4 WHEN 'SF' THEN 5 ELSE 6 END, m.code"
    );
    $detailCl->execute([$period, $period, $pid, $pid]);

    $detailGudang = $pdo->prepare(
        "SELECT m.code, m.location, m.name, m.area_sqm,
                COALESCE(pp.potential_value, m.projection_monthly) AS projection_monthly,
                COALESCE(SUM(a.amount),0) actual, COALESCE(SUM(a.allocated_days),0) days
         FROM master_gudang m
         LEFT JOIN period_potentials pp ON pp.slot_id = m.id AND pp.segment = 'gudang'
             AND pp.period_key = ? AND pp.property_id = m.property_id
         LEFT JOIN transaction_allocations a ON a.master_code=m.code AND a.module='gudang' AND a.period_key=? AND a.property_id=?
         WHERE m.property_id = ?
         GROUP BY m.id
         ORDER BY m.code"
    );
    $detailGudang->execute([$period, $period, $pid, $pid]);

    $pic = $pdo->prepare(
        "SELECT p.name pic_name,
                COALESCE(p.role_name,'-') role_name,
                COALESCE(p.target_share,0) target_share,
                COALESCE(SUM(a.amount),0) actual,
                COUNT(DISTINCT CASE WHEN t.client_id IS NOT NULL AND prev.client_id IS NULL THEN t.client_id END) AS new_clients
         FROM master_pic p
         LEFT JOIN transaction_allocations a ON a.pic_name = p.name AND a.period_key = ? AND a.property_id = ?
         LEFT JOIN transactions t ON t.id = a.transaction_id AND t.deleted_at IS NULL AND t.property_id = ?
         LEFT JOIN (
             SELECT DISTINCT t2.client_id
             FROM transaction_allocations ta2
             JOIN transactions t2 ON t2.id = ta2.transaction_id
             WHERE ta2.period_key < ?
               AND t2.client_id IS NOT NULL
               AND t2.deleted_at IS NULL
               AND ta2.property_id = ?
         ) prev ON prev.client_id = t.client_id
         WHERE p.status='active' AND p.property_id = ?
         GROUP BY p.id
         ORDER BY actual DESC, p.name ASC"
    );
    $pic->execute([$period, $pid, $pid, $period, $pid, $pid]);

    $ncStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT t.client_id)
         FROM transaction_allocations a
         JOIN transactions t ON t.id = a.transaction_id
         WHERE a.period_key = ?
           AND a.property_id = ?
           AND t.client_id IS NOT NULL
           AND t.deleted_at IS NULL
           AND t.client_id NOT IN (
               SELECT DISTINCT t2.client_id
               FROM transaction_allocations ta2
               JOIN transactions t2 ON t2.id = ta2.transaction_id
               WHERE ta2.period_key < ?
                 AND t2.client_id IS NOT NULL
                 AND t2.deleted_at IS NULL
                 AND ta2.property_id = ?
           )"
    );
    $ncStmt->execute([$period, $pid, $period, $pid]);
    $totalNewClients = (int) $ncStmt->fetchColumn();

    $mediaRatesStmt = $pdo->prepare(
        "SELECT code, media_type, location, point, size, quantity, slots, rate, pricing_type
         FROM master_media WHERE status='active' AND property_id = ? ORDER BY media_type, code"
    );
    $mediaRatesStmt->execute([$pid]);
    $mediaRates = $mediaRatesStmt->fetchAll();

    $allPeriods = periods($pdo);
    $periodYear  = substr($period, 0, 4);
    $periodMonth = substr($period, 5, 2);
    $years = [];
    $monthsByYear = [];
    $monthNames = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
    foreach ($allPeriods as $p) {
        $y = substr($p['period_key'], 0, 4);
        $m = substr($p['period_key'], 5, 2);
        if (!in_array($y, $years)) $years[] = $y;
        $monthsByYear[$y][] = $m;
    }

    layout('Dashboard Bulanan', function () use ($pdo, $period, $target, $projection, $actual, $capacity, $occupancy, $totalProjection, $totalActual, $totalRecurring, $detail, $detailCl, $detailGudang, $pic, $periodDays, $years, $monthsByYear, $periodYear, $periodMonth, $monthNames, $mediaRates, $totalNewClients) {
        $achPct   = $totalProjection > 0 ? $totalActual / $totalProjection : 0;
        $achClass = $achPct >= 1.0 ? 'kpi-good' : ($achPct >= 0.8 ? 'kpi-warn' : 'kpi-bad');
        $gi       = 0;
        ?>
        <style>
            #period-form { position:sticky;top:0;z-index:50;background:var(--bg,#f8fafc);box-shadow:0 1px 0 var(--line,#e2e8f0); }
            .kpi-good .kpi-value { color:#16a34a; }
            .kpi-warn .kpi-value { color:#d97706; }
            .kpi-bad  .kpi-value { color:#dc2626; }
            .group-hdr { cursor:pointer;user-select:none; }
            .group-hdr:hover td { filter:brightness(.97); }
            .group-hdr td:first-child::before { content:'▾  ';font-size:11px;color:#64748b; }
            .group-hdr.is-collapsed td:first-child::before { content:'▸  '; }
            .dash-anchors { display:flex;gap:6px;align-self:flex-end; }
            .dash-anchors a { font-size:12px;padding:3px 10px;border-radius:4px;background:var(--bg2,#f1f5f9);color:var(--ink2,#475569);text-decoration:none;border:1px solid var(--line,#e2e8f0);white-space:nowrap; }
            .dash-anchors a:hover { background:var(--line,#e2e8f0); }
            .pic-rank { font-weight:800;font-size:15px;color:#cbd5e1;width:36px;text-align:center;vertical-align:middle; }
            .pic-rank.r1 { color:#f59e0b; }
            .pic-rank.r2 { color:#94a3b8; }
            .pic-rank.r3 { color:#b87333; }
            .pic-bar { height:3px;border-radius:999px;background:#e5e7eb;margin-top:5px;overflow:hidden; }
            .pic-bar-fill { height:100%;border-radius:999px;transition:width .7s ease; }
            .ach-badge { display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:700;line-height:1.6; }
            .ach-badge.good { background:#dcfce7;color:#15803d; }
            .ach-badge.warn { background:#fef3c7;color:#92400e; }
            .ach-badge.bad  { background:#fee2e2;color:#b91c1c; }
        </style>
        <form class="toolbar" method="get" id="period-form">
            <input type="hidden" name="r" value="dashboard">
            <input type="hidden" name="period" id="period-value" value="<?= h($period) ?>">
            <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                <div>
                    <label>Tahun</label>
                    <select id="sel-year" onchange="dashYearChange()">
                        <?php foreach ($years as $y): ?>
                            <option value="<?= h($y) ?>" <?= $periodYear === $y ? 'selected' : '' ?>><?= h($y) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Bulan</label>
                    <select id="sel-month" onchange="dashMonthChange()">
                        <?php foreach ($monthsByYear[$periodYear] ?? [] as $m): ?>
                            <option value="<?= h($m) ?>" <?= $periodMonth === $m ? 'selected' : '' ?>><?= h($monthNames[$m] ?? $m) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="dash-anchors">
                    <a href="#section-cl">Exhibition ↓</a>
                    <a href="#section-media">Media ↓</a>
                    <a href="#section-gudang">Gudang ↓</a>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:12px">
                <?php if (can('export_reports')): ?><a class="btn light" href="?r=export_summary&period=<?= h($period) ?>">Export CSV</a><?php endif; ?>
                <a class="btn light" href="?r=print_dashboard&period=<?= h($period) ?>" target="_blank" style="background:#f1f5f9;color:var(--ink2);border:1px solid var(--line)">🖨 Print / PDF</a>
                <div style="text-align:right;line-height:1.35">
                    <div style="font-weight:700;color:var(--ink);font-size:14px"><?= date('l, d F Y') ?></div>
                    <div style="font-size:12px;color:var(--muted)" id="dash-time"></div>
                </div>
            </div>
        </form>
        <script>
        (function() {
            function tick() {
                const now = new Date();
                const hh = String(now.getHours()).padStart(2,'0');
                const mm = String(now.getMinutes()).padStart(2,'0');
                const ss = String(now.getSeconds()).padStart(2,'0');
                const el = document.getElementById('dash-time');
                if (el) el.textContent = hh + ':' + mm + ':' + ss;
            }
            tick();
            setInterval(tick, 1000);
        })();
        </script>
        <script>
        const _dashMonthsByYear = <?= json_encode($monthsByYear) ?>;
        const _dashMonthNames = <?= json_encode($monthNames) ?>;
        function dashYearChange() {
            const year = document.getElementById('sel-year').value;
            const monthSel = document.getElementById('sel-month');
            const current = monthSel.value;
            const months = _dashMonthsByYear[year] || [];
            monthSel.innerHTML = months.map(m =>
                `<option value="${m}"${m === current ? ' selected' : ''}>${_dashMonthNames[m] || m}</option>`
            ).join('');
            document.getElementById('period-value').value = year + '-' + monthSel.value;
            document.getElementById('period-form').submit();
        }
        function dashMonthChange() {
            const year = document.getElementById('sel-year').value;
            const month = document.getElementById('sel-month').value;
            document.getElementById('period-value').value = year + '-' + month;
            document.getElementById('period-form').submit();
        }
        </script>

        <div class="grid" style="grid-template-columns:repeat(5,minmax(0,1fr))">
            <div class="card" style="border-top:3px solid var(--primary)"><div class="kpi-label">Potensi</div><div class="kpi-value" data-countup="<?= (int)$totalProjection ?>">Rp 0</div></div>
            <div class="card" style="border-top:3px solid var(--green)"><div class="kpi-label">Target</div><div class="kpi-value" data-countup="<?= (int)$target ?>">Rp 0</div></div>
            <div class="card" style="border-top:3px solid #3B82F6"><div class="kpi-label">Aktual</div><div class="kpi-value"<?= $target > 0 && $totalActual < $target ? ' style="color:#dc2626"' : '' ?> data-countup="<?= (int)$totalActual ?>">Rp 0</div></div>
            <div class="card" style="border-top:3px solid #0369a1;background:<?= $totalRecurring > 0 ? '#f0f9ff' : '' ?>"><div class="kpi-label">Recurring</div><div class="kpi-value" style="color:<?= $totalRecurring > 0 ? '#0369a1' : 'var(--muted)' ?>" data-countup="<?= (int)$totalRecurring ?>"><?= $totalRecurring > 0 ? 'Rp 0' : '—' ?></div></div>
            <div class="card <?= $achClass ?>" style="border-top:3px solid var(--amber)"><div class="kpi-label">% Aktual vs Potensi</div><div class="kpi-value"><?= pct($achPct) ?></div></div>
        </div>

        <script src="assets/chart.umd.min.js"></script>

        <div class="panel" style="margin-top:14px">
            <div style="display:grid;grid-template-columns:auto 1fr;gap:32px;align-items:center">

                <!-- Doughnut chart -->
                <div style="display:flex;flex-direction:column;align-items:center;gap:12px">
                    <div style="position:relative;width:180px;height:180px">
                        <canvas id="chartAktualTarget" width="180" height="180"></canvas>
                        <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none">
                            <span style="font-size:24px;font-weight:800;color:var(--ink);line-height:1"><?= $target > 0 ? number_format(min($totalActual / $target * 100, 100), 1, ',', '.') : '0,0' ?>%</span>
                            <span style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-top:3px">vs Target</span>
                        </div>
                    </div>
                    <div style="display:flex;gap:16px;font-size:12px;color:var(--muted)">
                        <span><span id="chart-legend-dot" style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#0f766e;margin-right:4px;vertical-align:middle"></span>Aktual</span>
                        <span><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#e5e7eb;margin-right:4px;vertical-align:middle"></span>Sisa</span>
                    </div>
                </div>

                <!-- Breakdown per modul -->
                <div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                        <span style="font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em">Aktual</span>
                        <span style="font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em">Total: <?= money($totalActual) ?></span>
                    </div>
                    <?php
                    $moduleColors = ['cl' => '#0f766e', 'media' => '#0ea5e9', 'gudang' => '#f59e0b'];
                    $moduleMax = max($totalActual, $target, 1);
                    foreach (['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'] as $key => $label):
                        $pct_bar = min($actual[$key] / $moduleMax * 100, 100);
                    ?>
                    <div style="margin-bottom:14px">
                        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:5px">
                            <span style="font-size:13px;font-weight:700"><?= h($label) ?></span>
                            <span style="font-size:13px"><?= money($actual[$key]) ?> <span style="color:var(--muted);font-size:11px">(<?= pct($projection[$key] > 0 ? $actual[$key] / $projection[$key] : 0) ?> vs Potensi)</span></span>
                        </div>
                        <div style="height:10px;border-radius:999px;background:#f1f5f9;overflow:hidden">
                            <div style="height:100%;width:<?= number_format($pct_bar, 2, '.', '') ?>%;background:<?= $moduleColors[$key] ?>;border-radius:999px;transition:width .4s ease"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div style="border-top:1px solid var(--line);padding-top:10px;display:flex;justify-content:space-between;font-size:13px">
                        <span>Target Bulanan</span>
                        <strong><?= money($target) ?></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:13px;margin-top:4px">
                        <span><?= $totalActual >= $target ? 'Surplus' : 'Sisa Target' ?></span>
                        <strong style="color:<?= $totalActual >= $target ? '#16a34a' : '#c2410c' ?>"><?= money(abs($target - $totalActual)) ?></strong>
                    </div>
                </div>

            </div>
        </div>
        <script>
        (function() {
            const aktual = <?= (int) $totalActual ?>;
            const target = <?= (int) $target ?>;
            const sisa   = Math.max(target - aktual, 0);
            const pct    = target > 0 ? aktual / target * 100 : 0;
            const color  = pct >= 100 ? '#16a34a' : pct >= 80 ? '#d97706' : '#dc2626';
            const dot = document.getElementById('chart-legend-dot');
            if (dot) dot.style.background = color;
            new Chart(document.getElementById('chartAktualTarget'), {
                type: 'doughnut',
                data: {
                    labels: ['Aktual', 'Sisa Target'],
                    datasets: [{
                        data: target > 0 ? [aktual, sisa] : [0, 1],
                        backgroundColor: [color, '#e5e7eb'],
                        borderWidth: 0,
                        hoverOffset: 6,
                    }],
                },
                options: {
                    responsive: false,
                    cutout: '70%',
                    animation: { duration: 600 },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: ctx => ' ' + ctx.label + ': Rp ' + ctx.raw.toLocaleString('id-ID'),
                            },
                        },
                    },
                },
            });
        })();
        </script>

        <div class="panel" style="margin-top:14px">
            <h2>Achievement PIC</h2>
            <?php $ncTarget = 5; $ncClass = $totalNewClients >= $ncTarget ? '#16a34a' : '#dc2626'; ?>
            <div style="display:inline-flex;align-items:center;gap:8px;margin-bottom:12px;padding:8px 14px;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0">
                <span style="font-size:12px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em">Client Baru Bulan Ini</span>
                <span style="font-size:20px;font-weight:800;color:<?= $ncClass ?>"><?= $totalNewClients ?></span>
                <span style="font-size:13px;color:var(--muted)">/ <?= $ncTarget ?> target</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>#</th><th>PIC</th><th>Posisi</th><th>Target Posisi</th><th>Aktual</th><th>% vs Target Posisi</th><th>% thd Target Bulanan</th><th>Client Baru</th></tr></thead>
                    <tbody>
                    <?php
                    $picRows   = $pic->fetchAll();
                    $topPic    = $picRows[0]['pic_name'] ?? null;
                    $bottomPic = count($picRows) > 1 ? ($picRows[count($picRows)-1]['pic_name'] ?? null) : null;
                    $rank      = 0;
                    foreach ($picRows as $row):
                        $rank++;
                        $picTarget   = (float) $row['target_share'] * $target;
                        $achieved    = $picTarget > 0 && (float)$row['actual'] >= $picTarget;
                        $achPicPct   = $picTarget > 0 ? (float)$row['actual'] / $picTarget : 0;
                        $achPicClass = $achPicPct >= 1.0 ? 'good' : ($achPicPct >= 0.8 ? 'warn' : 'bad');
                        $barWidth    = min(round($achPicPct * 100, 1), 100);
                        $barColor    = $achPicPct >= 1.0 ? '#16a34a' : ($achPicPct >= 0.8 ? '#d97706' : '#dc2626');
                        $rankClass   = $rank === 1 ? 'r1' : ($rank === 2 ? 'r2' : ($rank === 3 ? 'r3' : ''));
                    ?>
                        <tr<?= $achieved ? ' style="background:#dcfce7"' : '' ?>>
                            <td class="pic-rank <?= $rankClass ?>"><?= $rank ?></td>
                            <td>
                                <div style="font-weight:600"><?= h($row['pic_name']) ?><?= ($row['pic_name'] === $topPic && (float)$row['actual'] > 0) ? ' <span title="Top Achiever" style="font-size:15px">👑</span>' : '' ?><?= ($row['pic_name'] === $bottomPic) ? ' <span title="Semangat!" style="font-size:15px">😢</span>' : '' ?></div>
                                <?php if ($picTarget > 0): ?>
                                <div class="pic-bar"><div class="pic-bar-fill" style="width:<?= $barWidth ?>%;background:<?= $barColor ?>"></div></div>
                                <?php endif; ?>
                            </td>
                            <td><?= h($row['role_name']) ?></td>
                            <td><?= money($picTarget) ?></td>
                            <td data-countup="<?= (int)$row['actual'] ?>">Rp 0</td>
                            <td><span class="ach-badge <?= $achPicClass ?>"><?= pct($achPicPct) ?></span></td>
                            <td><?= pct($target > 0 ? $row['actual'] / $target : 0) ?></td>
                            <td style="font-weight:700"><?= (int)$row['new_clients'] ?></td>
                        </tr>
                    <?php endforeach; unset($picRows, $topPic, $bottomPic); ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel" style="margin-top:14px" id="section-cl">
            <h2>Detail Exhibition Per Unit</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Kode</th><th>Lantai</th><th>Lokasi</th><th>Tipe</th><th>Area (m²)</th><th>Potensi</th><th>Hari</th><th>% Occupancy</th><th>Aktual</th><th>Rate Rata-rata/Hari/m²</th><th>Rate Rata-rata/Bulan/m²</th><th>% Aktual vs Potensi</th></tr></thead>
                    <tbody>
                    <?php
                    $clRows = $detailCl->fetchAll();
                    $clByFloor = [];
                    foreach ($clRows as $r) {
                        $clByFloor[$r['floor']][] = $r;
                    }
                    foreach ($clByFloor as $floor => $floorRows):
                        $gid = 'g' . $gi++;
                        $fPotensi = array_sum(array_column($floorRows, 'projection_monthly'));
                        $fActual  = array_sum(array_column($floorRows, 'actual'));
                        $fDays    = array_sum(array_column($floorRows, 'days'));
                        $fArea    = array_sum(array_column($floorRows, 'area_sqm'));
                        $fUnits   = count($floorRows);
                    ?>
                        <tr class="group-hdr" data-gid="<?= $gid ?>"><td colspan="12" style="background:#f1f5f9;font-weight:700;color:#374151;padding:8px 11px;border-top:2px solid #cbd5e1"><?= h($floor) ?></td></tr>
                        <?php foreach ($floorRows as $row): ?>
                        <?php $area = max((float) $row['area_sqm'], 1); ?>
                        <tr data-gbody="<?= $gid ?>">
                            <td><?= h($row['code']) ?></td>
                            <td><?= h($row['floor']) ?></td>
                            <td><?= h($row['location_name']) ?></td>
                            <td><?= h($row['unit_type'] ?? '-') ?></td>
                            <td><?= number_format((float) $row['area_sqm'], 1, ',', '.') ?></td>
                            <td><?= money($row['projection_monthly']) ?></td>
                            <td><?= h((string) $row['days']) ?></td>
                            <?php $_occ = $periodDays > 0 ? (float)$row['days'] / $periodDays : 0; ?><td<?= $_occ > 1 ? ' style="color:#dc2626;font-weight:700"' : '' ?>><?= pct($_occ) ?></td>
                            <td><?= money($row['actual']) ?></td>
                            <td><?= $row['days'] > 0 ? money($row['actual'] / $row['days'] / $area) : '-' ?></td>
                            <td><?= money($row['actual'] / $periodDays / $area) ?></td>
                            <td><?= pct($row['projection_monthly'] ? $row['actual'] / $row['projection_monthly'] : 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php
                        $fAreaSafe = max($fArea, 1);
                        $fRateHariList  = [];
                        $fRateBulanList = [];
                        foreach ($floorRows as $_r) {
                            $_a = max((float)$_r['area_sqm'], 1);
                            if ($_r['days'] > 0) $fRateHariList[] = $_r['actual'] / $_r['days'] / $_a;
                            $fRateBulanList[] = $_r['actual'] / $periodDays / $_a;
                        }
                        $fAvgRateHari  = count($fRateHariList)  > 0 ? array_sum($fRateHariList)  / count($fRateHariList)  : 0;
                        $fAvgRateBulan = count($fRateBulanList) > 0 ? array_sum($fRateBulanList) / count($fRateBulanList) : 0;
                        ?>
                        <tr data-gbody="<?= $gid ?>" style="background:#e0f2fe;font-weight:700;border-top:1px solid #7dd3fc">
                            <td colspan="4" style="padding:8px 11px">Subtotal <?= h($floor) ?> (<?= $fUnits ?> unit)</td>
                            <td><?= number_format($fArea, 1, ',', '.') ?></td>
                            <td><?= money($fPotensi) ?></td>
                            <td><?= $fDays ?></td>
                            <?php $_occ = $periodDays * $fUnits > 0 ? $fDays / ($periodDays * $fUnits) : 0; ?><td<?= $_occ > 1 ? ' style="color:#dc2626;font-weight:700"' : '' ?>><?= pct($_occ) ?></td>
                            <td><?= money($fActual) ?></td>
                            <td><?= count($fRateHariList) > 0 ? money($fAvgRateHari) : '-' ?></td>
                            <td><?= money($fAvgRateBulan) ?></td>
                            <td><?= pct($fPotensi > 0 ? $fActual / $fPotensi : 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel" style="margin-top:14px" id="section-media">
            <h2>Detail Media Per Item</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Kode</th><th>Jenis</th><th>Lokasi</th><th>Potensi</th><th>Hari</th><th>% Occupancy</th><th>Aktual</th><th>Rate Rata-rata/Hari</th><th>Rate Rata-rata/Bulan</th><th>% Aktual vs Potensi</th></tr></thead>
                    <tbody>
                    <?php
                    $mediaRows = $detail->fetchAll();
                    $mediaByType = [];
                    foreach ($mediaRows as $r) {
                        $mediaByType[$r['media_type']][] = $r;
                    }
                    foreach ($mediaByType as $mediaType => $typeRows):
                        $gid = 'g' . $gi++;
                        $tPotensi = array_sum(array_column($typeRows, 'projection_monthly'));
                        $tActual  = array_sum(array_column($typeRows, 'actual'));
                        $tDays    = array_sum(array_column($typeRows, 'days'));
                        $tUnits   = count($typeRows);
                    ?>
                        <tr class="group-hdr" data-gid="<?= $gid ?>"><td colspan="10" style="background:#f1f5f9;font-weight:700;color:#374151;padding:8px 11px;border-top:2px solid #cbd5e1"><?= h($mediaType) ?></td></tr>
                        <?php foreach ($typeRows as $row): ?>
                        <tr data-gbody="<?= $gid ?>">
                            <td><?= h($row['code']) ?></td>
                            <td><?= h($row['media_type']) ?></td>
                            <td><?= h($row['location'] . ' - ' . $row['point']) ?></td>
                            <td><?= money($row['projection_monthly']) ?></td>
                            <td><?= h((string) $row['days']) ?></td>
                            <?php $_occ = $periodDays > 0 ? (float)$row['days'] / $periodDays : 0; ?><td<?= $_occ > 1 ? ' style="color:#dc2626;font-weight:700"' : '' ?>><?= pct($_occ) ?></td>
                            <td><?= money($row['actual']) ?></td>
                            <td><?= $row['days'] > 0 ? money($row['actual'] / $row['days']) : '-' ?></td>
                            <td><?= money($row['actual'] / $periodDays) ?></td>
                            <td><?= pct($row['projection_monthly'] ? $row['actual'] / $row['projection_monthly'] : 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php
                        $tRateHariList  = [];
                        $tRateBulanList = [];
                        foreach ($typeRows as $_r) {
                            if ($_r['days'] > 0) $tRateHariList[] = $_r['actual'] / $_r['days'];
                            $tRateBulanList[] = $_r['actual'] / $periodDays;
                        }
                        $tAvgRateHari  = count($tRateHariList)  > 0 ? array_sum($tRateHariList)  / count($tRateHariList)  : 0;
                        $tAvgRateBulan = count($tRateBulanList) > 0 ? array_sum($tRateBulanList) / count($tRateBulanList) : 0;
                        ?>
                        <tr data-gbody="<?= $gid ?>" style="background:#e0f2fe;font-weight:700;border-top:1px solid #7dd3fc">
                            <td colspan="3" style="padding:8px 11px">Subtotal <?= h($mediaType) ?> (<?= $tUnits ?> item)</td>
                            <td><?= money($tPotensi) ?></td>
                            <td><?= $tDays ?></td>
                            <?php $_occ = $periodDays * $tUnits > 0 ? $tDays / ($periodDays * $tUnits) : 0; ?><td<?= $_occ > 1 ? ' style="color:#dc2626;font-weight:700"' : '' ?>><?= pct($_occ) ?></td>
                            <td><?= money($tActual) ?></td>
                            <td><?= count($tRateHariList) > 0 ? money($tAvgRateHari) : '-' ?></td>
                            <td><?= money($tAvgRateBulan) ?></td>
                            <td><?= pct($tPotensi > 0 ? $tActual / $tPotensi : 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel" style="margin-top:14px" id="section-gudang">
            <h2>Detail Gudang Per Unit</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Kode</th><th>Lokasi</th><th>Nama</th><th>Area (m²)</th><th>Potensi</th><th>Hari</th><th>% Occupancy</th><th>Aktual</th><th>Rate Rata-rata/Hari/m²</th><th>Rate Rata-rata/Bulan/m²</th><th>% Aktual vs Potensi</th></tr></thead>
                    <tbody>
                    <?php
                    $gudangRows = $detailGudang->fetchAll();
                    $gudangByLoc = [];
                    foreach ($gudangRows as $r) {
                        $gudangByLoc[$r['location']][] = $r;
                    }
                    foreach ($gudangByLoc as $loc => $locRows):
                        $gid = 'g' . $gi++;
                        $lPotensi = array_sum(array_column($locRows, 'projection_monthly'));
                        $lActual  = array_sum(array_column($locRows, 'actual'));
                        $lDays    = array_sum(array_column($locRows, 'days'));
                        $lArea    = array_sum(array_column($locRows, 'area_sqm'));
                        $lUnits   = count($locRows);
                        $lAreaSafe = max($lArea, 1);
                    ?>
                        <tr class="group-hdr" data-gid="<?= $gid ?>"><td colspan="11" style="background:#f1f5f9;font-weight:700;color:#374151;padding:8px 11px;border-top:2px solid #cbd5e1"><?= h($loc) ?></td></tr>
                        <?php foreach ($locRows as $row): ?>
                        <?php $area = max((float) $row['area_sqm'], 1); ?>
                        <tr data-gbody="<?= $gid ?>">
                            <td><?= h($row['code']) ?></td>
                            <td><?= h($row['location']) ?></td>
                            <td><?= h($row['name']) ?></td>
                            <td><?= number_format((float) $row['area_sqm'], 1, ',', '.') ?></td>
                            <td><?= money($row['projection_monthly']) ?></td>
                            <td><?= h((string) $row['days']) ?></td>
                            <?php $_occ = $periodDays > 0 ? (float)$row['days'] / $periodDays : 0; ?><td<?= $_occ > 1 ? ' style="color:#dc2626;font-weight:700"' : '' ?>><?= pct($_occ) ?></td>
                            <td><?= money($row['actual']) ?></td>
                            <td><?= $row['days'] > 0 ? money($row['actual'] / $row['days'] / $area) : '-' ?></td>
                            <td><?= money($row['actual'] / $periodDays / $area) ?></td>
                            <td><?= pct($row['projection_monthly'] ? $row['actual'] / $row['projection_monthly'] : 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php
                        $lRateHariList  = [];
                        $lRateBulanList = [];
                        foreach ($locRows as $_r) {
                            $_a = max((float)$_r['area_sqm'], 1);
                            if ($_r['days'] > 0) $lRateHariList[] = $_r['actual'] / $_r['days'] / $_a;
                            $lRateBulanList[] = $_r['actual'] / $periodDays / $_a;
                        }
                        $lAvgRateHari  = count($lRateHariList)  > 0 ? array_sum($lRateHariList)  / count($lRateHariList)  : 0;
                        $lAvgRateBulan = count($lRateBulanList) > 0 ? array_sum($lRateBulanList) / count($lRateBulanList) : 0;
                        ?>
                        <tr data-gbody="<?= $gid ?>" style="background:#e0f2fe;font-weight:700;border-top:1px solid #7dd3fc">
                            <td colspan="3" style="padding:8px 11px">Subtotal <?= h($loc) ?> (<?= $lUnits ?> unit)</td>
                            <td><?= number_format($lArea, 1, ',', '.') ?></td>
                            <td><?= money($lPotensi) ?></td>
                            <td><?= $lDays ?></td>
                            <?php $_occ = $periodDays * $lUnits > 0 ? $lDays / ($periodDays * $lUnits) : 0; ?><td<?= $_occ > 1 ? ' style="color:#dc2626;font-weight:700"' : '' ?>><?= pct($_occ) ?></td>
                            <td><?= money($lActual) ?></td>
                            <td><?= count($lRateHariList) > 0 ? money($lAvgRateHari) : '-' ?></td>
                            <td><?= money($lAvgRateBulan) ?></td>
                            <td><?= pct($lPotensi > 0 ? $lActual / $lPotensi : 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
        // 1. Count-up animation untuk KPI cards
        (function () {
            function countUp(el, target, duration) {
                var start = performance.now();
                function step(now) {
                    var p = Math.min((now - start) / duration, 1);
                    var eased = 1 - Math.pow(1 - p, 3);
                    el.textContent = 'Rp ' + Math.round(target * eased).toLocaleString('id-ID');
                    if (p < 1) requestAnimationFrame(step);
                }
                requestAnimationFrame(step);
            }
            document.querySelectorAll('[data-countup]').forEach(function (el) {
                var v = parseInt(el.dataset.countup, 10);
                if (!isNaN(v)) countUp(el, v, 900);
            });
        })();

        // 2. Collapsible group headers
        (function () {
            document.querySelectorAll('.group-hdr').forEach(function (hdr) {
                hdr.addEventListener('click', function () {
                    var gid = this.dataset.gid;
                    var rows = document.querySelectorAll('[data-gbody="' + gid + '"]');
                    var collapsed = this.classList.toggle('is-collapsed');
                    rows.forEach(function (r) { r.style.display = collapsed ? 'none' : ''; });
                });
            });
        })();

        // 3. Smooth scroll untuk section anchors
        (function () {
            document.querySelectorAll('.dash-anchors a').forEach(function (a) {
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    var target = document.querySelector(this.getAttribute('href'));
                    if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            });
        })();
        </script>
        <?php
    });
}

function display_json(PDO $pdo): void
{
    $period = getv('period', date('Y-m'));
    $pid    = (int) getv('pid', 0) ?: null;
    $data   = DashboardService::jsonReady(DashboardService::data($pdo, $period, $pid));
    audit($pdo, 'display_ajax_refresh', 'dashboard_display', $period, ['period' => $period, 'pid' => $pid], [], 'display_tv');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data);
    exit;
}

function display_page(PDO $pdo, array $config): void
{
    $period     = getv('period', date('Y-m'));
    $token      = getv('token', '');
    $periods    = periods($pdo);
    $properties = $pdo->query("SELECT id, name FROM properties WHERE status='active' ORDER BY id")->fetchAll();
    audit($pdo, 'display_open', 'dashboard_display', $period, ['period' => $period], [], 'display_tv');
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=1920">
        <title>Display TV — <?= h($config['app_name'] ?? 'CLARA') ?></title>
        <link rel="icon" type="image/png" href="assets/clara-logo.png">
        <link rel="stylesheet" href="assets/app.css?v=<?= CSS_VER ?>">
    </head>
    <body class="tv-body">
    <main class="tv-shell tv-shell--split">

        <!-- HEADER -->
        <header class="tv-hdr">
            <div class="tv-brand">
                <img class="tv-logo" src="assets/clara-logo.png" alt="CLARA" onerror="this.style.display='none'">
                <div>
                    <div class="tv-kicker">Casual Leasing Achievement &amp; Revenue Analytics</div>
                    <h1 id="tv-period">Loading...</h1>
                </div>
            </div>
            <div class="tv-hdr-right">
                <select id="period-select">
                    <?php foreach ($periods as $p): ?>
                        <option value="<?= h($p['period_key']) ?>" <?= $period === $p['period_key'] ? 'selected' : '' ?>><?= h($p['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span id="tv-status" class="tv-dot">Memuat...</span>
                <div class="tv-clock">
                    <b id="clock-now">--:--:--</b>
                    <small>Update <span id="last-update">—</span></small>
                </div>
            </div>
        </header>

        <!-- SPLIT: dua panel berdampingan -->
        <div class="tv-split">
            <?php foreach ($properties as $prop): ?>
            <div class="tv-panel" data-pid="<?= (int)$prop['id'] ?>">
                <div class="tv-panel-hdr"><?= h($prop['name']) ?></div>
                <div class="tv-panel-strip">
                    <div class="tv-kpi k-target"><span>Target</span><strong class="p-target">Rp 0</strong></div>
                    <div class="tv-kpi k-actual"><span>Aktual</span><strong class="p-actual">Rp 0</strong></div>
                </div>
                <div class="tv-panel-main">
                    <div class="tv-card tv-ach">
                        <div class="tv-card-title">Achievement</div>
                        <div class="tv-ring-wrap">
                            <div class="tv-ring p-target-ring">
                                <div class="tv-ring-inner">
                                    <strong class="p-achievement-target">0%</strong>
                                    <small>vs Target</small>
                                </div>
                            </div>
                        </div>
                        <div class="tv-ach-foot">
                            <div class="tv-bar"><div class="p-target-progress"></div></div>
                            <div class="tv-gap p-gap-target">Gap Rp 0</div>
                        </div>
                    </div>
                    <div class="tv-card tv-segs">
                        <div class="tv-card-title">Segment</div>
                        <div class="tv-seg-list p-segments"></div>
                    </div>
                    <div class="tv-card tv-pics">
                        <div class="tv-card-title">Ranking PIC</div>
                        <div class="tv-pic-list p-pics"></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

    </main>
    <script>
        const token    = <?= json_encode($token) ?>;
        const baseUrl  = '?r=display_data&token=' + encodeURIComponent(token);
        const sel      = document.getElementById('period-select');
        const statusEl = document.getElementById('tv-status');

        function esc(v) {
            return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
        }
        function cls(w) { return w >= 100 ? 'good' : w >= 80 ? 'warn' : 'bad'; }
        function setClock() {
            document.getElementById('clock-now').textContent =
                new Date().toLocaleTimeString('id-ID', { hour12: false });
        }

        function renderPanel(panel, d) {
            const q = s => panel.querySelector(s);

            q('.p-target').textContent = d.target_formatted;
            const elActual = q('.p-actual');
            elActual.textContent  = d.total_actual_formatted;
            elActual.style.color  = (d.target > 0 && d.total_actual < d.target) ? '#dc2626' : '';
            const elAchKpi = q('.p-achievement-target-kpi'); if (elAchKpi) elAchKpi.textContent = d.achievement_target_formatted;
            q('.p-achievement-target').textContent     = d.achievement_target_formatted;
            q('.p-gap-target').textContent             = 'Gap target ' + d.gap_target_formatted;

            document.getElementById('tv-period').textContent    = d.period.label;
            document.getElementById('last-update').textContent  = (d.updated_at.split(' ')[1] || d.updated_at).substring(0, 5);

            const pct = Math.max(0, Math.min(100, d.achievement_target * 100));
            const ringColor = pct >= 100 ? '#16a34a' : pct >= 80 ? '#d97706' : '#dc2626';
            const bar = q('.p-target-progress');
            bar.style.width = pct + '%';
            bar.className   = cls(pct);
            const ring = q('.p-target-ring');
            ring.style.setProperty('--p', pct + '%');
            ring.style.setProperty('--ring-color', ringColor);

            q('.p-segments').innerHTML = d.segments.map(s => {
                const w   = Math.max(0, Math.min(100, Number(s.achievement || 0) * 100));
                const c   = cls(w);
                const occ = Number(s.occ || 0) * 100;
                const co  = occ >= 100 ? 'good' : occ >= 80 ? 'warn' : 'bad';
                return `<div class="tv-seg">
                    <div class="tv-seg-side s-${esc(s.key)}"></div>
                    <div class="tv-seg-body">
                        <div class="tv-seg-name">${esc(s.label)}</div>
                        <div class="tv-seg-sub">${esc(s.actual_formatted)} <em>/ ${esc(s.projection_formatted)}</em></div>
                        <div class="tv-seg-bar"><div class="tv-seg-fill ${c}" style="width:${w}%"></div></div>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:2px">
                        <div class="tv-seg-pct ${c}">${esc(s.achievement_formatted)}</div>
                        <div style="font-size:.65em;font-weight:600;color:var(--tv-${co},${co==='good'?'#16a34a':co==='warn'?'#d97706':'#dc2626'})">Occ ${esc(s.occ_formatted)}</div>
                    </div>
                </div>`;
            }).join('');

            q('.p-pics').innerHTML = d.pics.length
                ? d.pics.slice(0, 5).map((p, i, arr) => {
                    const c = cls(Number(p.achievement || 0) * 100);
                    const hasTarget = p.target_posisi > 0;
                    const ranked = arr.filter(x => x.pic_name !== 'WBL Unit');
                    const badge = p.pic_name === 'WBL Unit' ? ''
                        : (ranked.length > 1 && p.pic_name === ranked[0].pic_name) ? ' 👑'
                        : (ranked.length > 1 && p.pic_name === ranked[ranked.length - 1].pic_name) ? ' 😢'
                        : '';
                    return `<div class="tv-pic r${i + 1}">
                        <div class="tv-pic-num">${i + 1}</div>
                        <div class="tv-pic-info">
                            <div class="tv-pic-name">${esc(p.pic_name)}${badge}</div>
                            <div class="tv-pic-sub">${esc(p.role_name !== '-' ? p.role_name : 'PIC')}</div>
                        </div>
                        <div class="tv-pic-right">
                            <div class="tv-pic-row-top">
                                <div class="tv-pic-amt">${esc(p.actual_formatted)}</div>
                                ${hasTarget ? `<div class="tv-pic-ach ${c}">${esc(p.achievement_formatted)}</div>` : ''}
                            </div>
                            ${hasTarget ? `<div class="tv-pic-col-val--target">${esc(p.target_posisi_formatted)}</div>` : ''}
                        </div>
                    </div>`;
                }).join('')
                : '<div class="tv-empty">Belum ada data PIC.</div>';
        }

        let failCount = 0;
        let retryTimer = null;

        async function fetchPanel(panel, period) {
            const r = await fetch(baseUrl + '&period=' + encodeURIComponent(period) + '&pid=' + panel.dataset.pid, { cache: 'no-store' });
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return { panel, d: await r.json() };
        }

        async function refresh() {
            clearTimeout(retryTimer);
            statusEl.className   = 'tv-dot';
            statusEl.textContent = 'Memperbarui...';
            const period = sel.value;
            const panels = [...document.querySelectorAll('.tv-panel')];
            const results = await Promise.allSettled(panels.map(panel => fetchPanel(panel, period)));
            let anyOk = false;
            results.forEach(res => {
                if (res.status === 'fulfilled') { renderPanel(res.value.panel, res.value.d); anyOk = true; }
            });
            if (anyOk) {
                failCount = 0;
                statusEl.className   = 'tv-dot is-online';
                statusEl.textContent = 'Online';
            } else {
                failCount++;
                if (failCount >= 3) {
                    statusEl.className   = 'tv-dot is-error';
                    statusEl.textContent = 'Koneksi terputus';
                } else {
                    statusEl.className   = 'tv-dot';
                    statusEl.textContent = 'Mencoba ulang...';
                    retryTimer = setTimeout(refresh, 10000);
                }
            }
        }

        sel.addEventListener('change', () => { failCount = 0; refresh(); });
        setClock();
        refresh();
        setInterval(setClock, 1000);
        setInterval(refresh, 30000);
    </script>
    </body>
    </html>
    <?php
}
