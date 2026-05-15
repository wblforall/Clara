<?php
declare(strict_types=1);

function trend_page(PDO $pdo): void
{
    require_permission('view_dashboard');

    $mn = ['01'=>'Jan','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'Mei','06'=>'Jun',
           '07'=>'Jul','08'=>'Agt','09'=>'Sep','10'=>'Okt','11'=>'Nov','12'=>'Des'];

    $pid = current_property_id();
    // Kumpulkan semua tahun yang tersedia
    $y1Stmt = $pdo->prepare("SELECT DISTINCT LEFT(period_key,4) FROM transaction_allocations WHERE property_id = ?");
    $y1Stmt->execute([$pid]);
    $y1 = $y1Stmt->fetchAll(PDO::FETCH_COLUMN);
    $y2Stmt = $pdo->prepare("SELECT DISTINCT LEFT(period_key,4) FROM targets_monthly WHERE property_id = ?");
    $y2Stmt->execute([$pid]);
    $y2 = $y2Stmt->fetchAll(PDO::FETCH_COLUMN);
    $allYears = array_values(array_unique(array_merge($y1, $y2)));
    rsort($allYears);
    if (empty($allYears)) $allYears = [date('Y')];

    $defaultYear  = in_array(date('Y'), $allYears, true) ? date('Y') : ($allYears[0] ?? date('Y'));
    $selectedYear = getv('year', $defaultYear);
    if (!in_array($selectedYear, $allYears, true)) {
        $selectedYear = $defaultYear;
    }

    // Ambil semua data alokasi & target
    $allocStmt = $pdo->prepare(
        "SELECT period_key, module, COALESCE(SUM(amount),0) actual
         FROM transaction_allocations
         WHERE property_id = ?
         GROUP BY period_key, module
         ORDER BY period_key ASC"
    );
    $allocStmt->execute([$pid]);
    $alloc = $allocStmt->fetchAll();

    $targetsStmt = $pdo->prepare(
        "SELECT period_key, target_amount FROM targets_monthly WHERE property_id = ?"
    );
    $targetsStmt->execute([$pid]);
    $targets = $targetsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Index data per period_key
    $byPeriod = [];
    foreach ($alloc as $row) {
        $byPeriod[$row['period_key']][$row['module']] = (float) $row['actual'];
    }

    // Bangun 12 bulan untuk tahun yang dipilih
    $months = ['01','02','03','04','05','06','07','08','09','10','11','12'];
    $labels = $cActual = $cTarget = $cCl = $cMedia = $cGudang = [];
    $yearActual = $yearTarget = 0;
    $bestAmt = 0; $bestLabel = '—';

    foreach ($months as $m) {
        $pk = $selectedYear . '-' . $m;
        $labels[] = $mn[$m];
        $cl     = $byPeriod[$pk]['cl']     ?? 0;
        $media  = $byPeriod[$pk]['media']  ?? 0;
        $gudang = $byPeriod[$pk]['gudang'] ?? 0;
        $total  = $cl + $media + $gudang;
        $tgt    = (float)($targets[$pk] ?? 0);

        $cCl[]     = round($cl);
        $cMedia[]  = round($media);
        $cGudang[] = round($gudang);
        $cActual[] = round($total);
        $cTarget[] = round($tgt);

        $yearActual += $total;
        $yearTarget += $tgt;

        if ($total > $bestAmt) { $bestAmt = $total; $bestLabel = $mn[$m]; }
    }

    $yearAch = $yearTarget > 0 ? $yearActual / $yearTarget : 0;

    layout('Trend Revenue', function () use ($labels, $cActual, $cTarget, $cCl, $cMedia, $cGudang, $months, $byPeriod, $targets, $yearActual, $yearTarget, $yearAch, $bestAmt, $bestLabel, $selectedYear, $allYears, $mn) {
        ?>
        <script src="assets/chart.umd.min.js"></script>
        <style>
        @keyframes _fadeUp {
            from { opacity:0; transform:translateY(14px); }
            to   { opacity:1; transform:translateY(0); }
        }
        @keyframes _fadeIn { from { opacity:0; } to { opacity:1; } }
        .kpi-anim   { animation: _fadeUp .45s cubic-bezier(.22,.68,0,1.2) both; }
        .panel-anim { animation: _fadeUp .4s ease both; }
        </style>

        <form method="get" class="toolbar panel-anim" style="margin-bottom:20px;animation-delay:.01s">
            <input type="hidden" name="r" value="trend">
            <div style="display:flex;align-items:center;gap:10px">
                <label style="margin:0;font-size:13px;color:var(--muted)">Tahun</label>
                <select name="year" onchange="this.form.submit()" style="width:auto">
                    <?php foreach ($allYears as $y): ?>
                        <option value="<?= h($y) ?>" <?= $y === $selectedYear ? 'selected' : '' ?>><?= h($y) ?></option>
                    <?php endforeach; ?>
                </select>
                <a class="btn light" href="?r=print_trend&year=<?= h($selectedYear) ?>" target="_blank" style="background:#f1f5f9;color:var(--ink2);border:1px solid var(--line);text-decoration:none">🖨 Print / PDF</a>
            </div>
        </form>

        <div class="grid grid-4" style="margin-bottom:20px">
            <div class="card kpi-anim" style="animation-delay:.04s">
                <div class="kpi-label">Total Aktual <?= h($selectedYear) ?></div>
                <div class="kpi-value" data-countup="<?= $yearActual ?>"></div>
            </div>
            <div class="card kpi-anim" style="animation-delay:.1s">
                <div class="kpi-label">Total Target <?= h($selectedYear) ?></div>
                <div class="kpi-value" data-countup="<?= $yearTarget ?>"></div>
            </div>
            <div class="card kpi-anim" style="animation-delay:.16s">
                <div class="kpi-label">Achievement <?= h($selectedYear) ?></div>
                <div class="kpi-value" style="color:<?= $yearAch >= 1 ? 'var(--green)' : ($yearAch >= .8 ? 'var(--amber)' : 'var(--accent)') ?>"
                     data-countup="<?= $yearAch ?>" data-countup-pct="1"></div>
            </div>
            <div class="card kpi-anim" style="animation-delay:.22s">
                <div class="kpi-label">Bulan Terbaik</div>
                <div class="kpi-value" style="font-size:19px"><?= h($bestLabel) ?></div>
                <div style="color:var(--muted);font-size:12px;margin-top:4px"><?= money($bestAmt) ?></div>
            </div>
        </div>

        <div class="panel panel-anim" style="margin-bottom:16px;animation-delay:.3s">
            <h2>Aktual vs Target — <?= h($selectedYear) ?></h2>
            <div style="position:relative;height:300px"><canvas id="chartTrend"></canvas></div>
        </div>

        <div class="panel panel-anim" style="margin-bottom:16px;animation-delay:.4s">
            <h2>Revenue per Segment — <?= h($selectedYear) ?></h2>
            <div style="position:relative;height:260px"><canvas id="chartSeg"></canvas></div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Bulan</th>
                        <th style="text-align:right">Exhibition</th>
                        <th style="text-align:right">Media</th>
                        <th style="text-align:right">Gudang</th>
                        <th style="text-align:right">Total Aktual</th>
                        <th style="text-align:right">Target</th>
                        <th style="text-align:right">Achievement</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $tTot = $tTgt = 0;
                foreach ($months as $i => $m):
                    $pk  = $selectedYear . '-' . $m;
                    $cl  = $byPeriod[$pk]['cl']     ?? 0;
                    $med = $byPeriod[$pk]['media']  ?? 0;
                    $gd  = $byPeriod[$pk]['gudang'] ?? 0;
                    $tot = $cl + $med + $gd;
                    $tgt = (float)($targets[$pk] ?? 0);
                    $ach = $tgt > 0 ? $tot / $tgt : null;
                    $tTot += $tot; $tTgt += $tgt;
                ?>
                <tr style="animation:_fadeIn .3s ease both;animation-delay:<?= round(.45 + $i * .04, 2) ?>s">
                    <td style="font-weight:600"><?= h($mn[$m]) ?></td>
                    <td style="text-align:right"><?= $cl  > 0 ? money($cl)  : '<span style="color:var(--muted)">—</span>' ?></td>
                    <td style="text-align:right"><?= $med > 0 ? money($med) : '<span style="color:var(--muted)">—</span>' ?></td>
                    <td style="text-align:right"><?= $gd  > 0 ? money($gd)  : '<span style="color:var(--muted)">—</span>' ?></td>
                    <td style="text-align:right;font-weight:700"><?= $tot > 0 ? money($tot) : '<span style="color:var(--muted)">—</span>' ?></td>
                    <td style="text-align:right"><?= $tgt > 0 ? money($tgt) : '<span style="color:var(--muted)">—</span>' ?></td>
                    <td style="text-align:right">
                        <?php if ($ach !== null): ?>
                            <span style="font-weight:700;color:<?= $ach>=1?'var(--green)':($ach>=.8?'var(--amber)':'var(--accent)') ?>">
                                <?= pct($ach) ?>
                            </span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#F8FAFC;font-weight:700">
                        <td>Total <?= h($selectedYear) ?></td>
                        <td style="text-align:right"><?= money(array_sum(array_column(array_map(fn($m)=>['v'=>$byPeriod[$selectedYear.'-'.$m]['cl']??0],$months),'v'))) ?></td>
                        <td style="text-align:right"><?= money(array_sum(array_column(array_map(fn($m)=>['v'=>$byPeriod[$selectedYear.'-'.$m]['media']??0],$months),'v'))) ?></td>
                        <td style="text-align:right"><?= money(array_sum(array_column(array_map(fn($m)=>['v'=>$byPeriod[$selectedYear.'-'.$m]['gudang']??0],$months),'v'))) ?></td>
                        <td style="text-align:right"><?= money($tTot) ?></td>
                        <td style="text-align:right"><?= $tTgt > 0 ? money($tTgt) : '—' ?></td>
                        <td style="text-align:right">
                            <?php $tAch = $tTgt > 0 ? $tTot/$tTgt : null; ?>
                            <?php if ($tAch !== null): ?>
                                <span style="color:<?= $tAch>=1?'var(--green)':($tAch>=.8?'var(--amber)':'var(--accent)') ?>">
                                    <?= pct($tAch) ?>
                                </span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <script>
        const jFmt = v => 'Rp ' + Number(v).toLocaleString('id-ID');
        const jLbl = <?= json_encode(array_values($labels)) ?>;
        const jAct = <?= json_encode(array_values($cActual)) ?>;
        const jTgt = <?= json_encode(array_values($cTarget)) ?>;
        const jCl  = <?= json_encode(array_values($cCl)) ?>;
        const jMed = <?= json_encode(array_values($cMedia)) ?>;
        const jGd  = <?= json_encode(array_values($cGudang)) ?>;

        const baseOpts = {
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                duration: 900,
                easing: 'easeOutQuart',
                delay: ctx => ctx.type === 'data' && ctx.mode === 'default' ? ctx.dataIndex * 50 : 0
            },
            plugins: {
                legend: { position: 'top', labels: { usePointStyle: true, pointStyleWidth: 10, font: { size: 12 } } },
                tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + jFmt(c.raw) } }
            },
            scales: {
                x: { grid: { color: 'rgba(0,0,0,.04)' }, ticks: { font: { size: 12 } } },
                y: {
                    grid: { color: 'rgba(0,0,0,.04)' },
                    ticks: { font: { size: 11 }, callback: v => 'Rp ' + (v / 1e6).toFixed(0) + ' jt' }
                }
            }
        };

        new Chart(document.getElementById('chartTrend'), {
            type: 'bar',
            data: {
                labels: jLbl,
                datasets: [
                    {
                        type: 'line', label: 'Target', data: jTgt,
                        borderColor: '#EF4444', backgroundColor: 'transparent',
                        borderWidth: 2, borderDash: [6, 4],
                        pointRadius: 4, pointBackgroundColor: '#EF4444',
                        tension: 0.3, order: 0,
                    },
                    {
                        type: 'bar', label: 'Aktual', data: jAct,
                        backgroundColor: 'rgba(13,148,136,.75)',
                        borderRadius: 5, order: 1,
                    }
                ]
            },
            options: baseOpts
        });

        new Chart(document.getElementById('chartSeg'), {
            type: 'bar',
            data: {
                labels: jLbl,
                datasets: [
                    { label: 'Exhibition', data: jCl,  backgroundColor: 'rgba(13,148,136,.82)', borderRadius: 0 },
                    { label: 'Media',      data: jMed, backgroundColor: 'rgba(8,145,178,.82)',  borderRadius: 0 },
                    { label: 'Gudang',     data: jGd,  backgroundColor: 'rgba(245,158,11,.82)', borderRadius: 4 },
                ]
            },
            options: {
                ...baseOpts,
                scales: {
                    ...baseOpts.scales,
                    x: { ...baseOpts.scales.x, stacked: true },
                    y: { ...baseOpts.scales.y, stacked: true },
                }
            }
        });

        (function(){
            function countUp(el) {
                const target = parseFloat(el.dataset.countup);
                const isPct  = el.dataset.countupPct === '1';
                const dur    = 800;
                const t0     = performance.now();
                const fmt    = isPct
                    ? v => (v * 100).toFixed(1) + '%'
                    : v => 'Rp ' + Math.round(v).toLocaleString('id-ID');
                (function step(now) {
                    const p = Math.min((now - t0) / dur, 1);
                    const e = 1 - Math.pow(1 - p, 3);
                    el.textContent = fmt(target * e);
                    if (p < 1) requestAnimationFrame(step);
                })(t0);
            }
            document.querySelectorAll('[data-countup]').forEach(el => requestAnimationFrame(() => countUp(el)));
        })();
        </script>
        <?php
    });
}

function comparison_page(PDO $pdo): void
{
    require_permission('view_dashboard');

    $allPeriods = periods($pdo);
    $periodKeys = array_column($allPeriods, 'period_key');

    $cur   = date('Y-m');
    $prev1 = date('Y-m', strtotime($cur . '-01 -1 month'));
    $prev2 = date('Y-m', strtotime($cur . '-01 -2 month'));

    $p1 = getv('p1', in_array($cur,   $periodKeys, true) ? $cur   : ($periodKeys[array_key_last($periodKeys)] ?? $cur));
    $p2 = getv('p2', in_array($prev1, $periodKeys, true) ? $prev1 : $p1);
    $p3 = getv('p3', in_array($prev2, $periodKeys, true) ? $prev2 : $p1);

    $cpid = current_property_id();
    // Ambil data aktual & target untuk ketiga periode
    $fetch = function (string $pk) use ($pdo, $cpid): array {
        $rows = $pdo->prepare(
            'SELECT module, COALESCE(SUM(amount),0) actual FROM transaction_allocations WHERE period_key=? AND property_id=? GROUP BY module'
        );
        $rows->execute([$pk, $cpid]);
        $data = ['cl' => 0.0, 'media' => 0.0, 'gudang' => 0.0];
        foreach ($rows->fetchAll() as $r) $data[$r['module']] = (float) $r['actual'];
        $tgtStmt = $pdo->prepare('SELECT COALESCE(target_amount,0) FROM targets_monthly WHERE period_key=? AND property_id=?');
        $tgtStmt->execute([$pk, $cpid]);
        $tgt = (float) ($tgtStmt->fetchColumn() ?: 0);
        $data['total']  = $data['cl'] + $data['media'] + $data['gudang'];
        $data['target'] = $tgt;
        $data['ach']    = $tgt > 0 ? $data['total'] / $tgt : null;

        // Breakdown per lantai (CL)
        $s = $pdo->prepare(
            'SELECT m.floor grp, COALESCE(SUM(a.amount),0) actual
             FROM transaction_allocations a JOIN master_cl_units m ON m.code=a.master_code AND m.property_id=?
             WHERE a.period_key=? AND a.module=\'cl\' AND a.property_id=?
             GROUP BY m.floor
             ORDER BY CASE m.floor WHEN \'LG\' THEN 1 WHEN \'GF\' THEN 2 WHEN \'UG\' THEN 3 WHEN \'FF\' THEN 4 WHEN \'SF\' THEN 5 ELSE 6 END'
        );
        $s->execute([$cpid, $pk, $cpid]);
        $data['by_floor'] = array_column($s->fetchAll(), 'actual', 'grp');

        // Breakdown per tipe media
        $s = $pdo->prepare(
            'SELECT m.media_type grp, COALESCE(SUM(a.amount),0) actual
             FROM transaction_allocations a JOIN master_media m ON m.code=a.master_code AND m.property_id=?
             WHERE a.period_key=? AND a.module=\'media\' AND a.property_id=?
             GROUP BY m.media_type ORDER BY m.media_type'
        );
        $s->execute([$cpid, $pk, $cpid]);
        $data['by_media'] = array_column($s->fetchAll(), 'actual', 'grp');

        // Breakdown per lokasi gudang
        $s = $pdo->prepare(
            'SELECT m.location grp, COALESCE(SUM(a.amount),0) actual
             FROM transaction_allocations a JOIN master_gudang m ON m.code=a.master_code AND m.property_id=?
             WHERE a.period_key=? AND a.module=\'gudang\' AND a.property_id=?
             GROUP BY m.location ORDER BY m.location'
        );
        $s->execute([$cpid, $pk, $cpid]);
        $data['by_gudang'] = array_column($s->fetchAll(), 'actual', 'grp');

        // Achievement per PIC
        $s = $pdo->prepare(
            'SELECT p.name pic_name, COALESCE(p.target_share,0) target_share, COALESCE(SUM(a.amount),0) actual
             FROM master_pic p
             LEFT JOIN transaction_allocations a ON a.pic_name = p.name AND a.period_key=? AND a.property_id=?
             WHERE p.status=\'active\' AND p.property_id=?
             GROUP BY p.name, p.target_share
             ORDER BY actual DESC'
        );
        $s->execute([$pk, $cpid, $cpid]);
        $data['by_pic'] = [];
        foreach ($s->fetchAll() as $r) {
            $picTgt = (float)$r['target_share'] * $tgt;
            $data['by_pic'][$r['pic_name']] = [
                'actual' => (float)$r['actual'],
                'target' => $picTgt,
                'ach'    => $picTgt > 0 ? (float)$r['actual'] / $picTgt : null,
            ];
        }

        return $data;
    };

    $d1 = $fetch($p1);
    $d2 = $fetch($p2);
    $d3 = $fetch($p3);

    $label1 = period_label($p1);
    $label2 = period_label($p2);
    $label3 = period_label($p3);

    $segments = [
        'cl'     => 'Exhibition',
        'media'  => 'Media Promo & Wall Sign',
        'gudang' => 'Gudang / Storage',
        'total'  => 'Total Aktual',
        'target' => 'Target',
    ];

    $delta = function ($a, $b): ?float {
        return ($b > 0) ? (($a - $b) / $b) : null;
    };

    layout('Perbandingan Periode', function () use ($allPeriods, $p1, $p2, $p3, $label1, $label2, $label3, $d1, $d2, $d3, $segments, $delta) {
        ?>
        <!-- Selector -->
        <form method="get" class="panel" style="margin-bottom:20px">
            <input type="hidden" name="r" value="comparison">
            <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end">
                <?php foreach ([['p1',$p1,'Periode 1'],['p2',$p2,'Periode 2'],['p3',$p3,'Periode 3']] as [$name,$val,$lbl]): ?>
                <div style="flex:1;min-width:160px">
                    <label style="font-size:12px;color:var(--muted)"><?= $lbl ?></label>
                    <select name="<?= $name ?>" onchange="this.form.submit()" style="width:100%">
                        <?php foreach ($allPeriods as $p): ?>
                            <option value="<?= h($p['period_key']) ?>" <?= $val === $p['period_key'] ? 'selected' : '' ?>><?= h($p['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>
            </div>
        </form>

        <!-- KPI Cards -->
        <div class="grid grid-4" style="margin-bottom:20px">
            <?php
            foreach ([
                [$label1, $d1['total'], null,            null],
                [$label2, $d2['total'], null,            null],
                [$label3, $d3['total'], null,            null],
                ['VS P1 vs P2', null,  $delta($d1['total'],$d2['total']), $delta($d1['total'],$d3['total'])],
            ] as [$lbl, $amt, $vs12, $vs13]):
            ?>
            <div class="card">
                <div class="kpi-label"><?= h($lbl) ?></div>
                <?php if ($amt !== null): ?>
                    <div class="kpi-value"><?= money($amt) ?></div>
                <?php else: ?>
                    <div style="display:flex;flex-direction:column;gap:4px;margin-top:4px">
                        <?php
                        $badge = function(?float $v, string $lbl) {
                            if ($v === null) { echo "<span style='color:var(--muted);font-size:13px'>$lbl: —</span>"; return; }
                            $col = $v >= 0 ? 'var(--green)' : 'var(--accent)';
                            $arrow = $v >= 0 ? '▲' : '▼';
                            printf('<span style="color:%s;font-weight:700;font-size:15px">%s %s %s</span>',
                                $col, $arrow, $lbl, pct(abs($v)));
                        };
                        $badge($vs12, 'vs P2');
                        $badge($vs13, 'vs P3');
                        ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Tabel Perbandingan -->
        <div class="panel" style="padding:0;overflow:hidden">
            <table style="width:100%;border-collapse:collapse">
                <thead>
                <tr style="background:var(--sidebar-bg);color:#fff">
                    <th style="padding:12px 16px;text-align:left;font-size:13px">Segmen</th>
                    <?php foreach ([[$label1,'#0D9488'],[$label2,'#0891b2'],[$label3,'#7c3aed']] as [$lbl,$col]): ?>
                    <th style="padding:12px 16px;text-align:right;font-size:13px;border-left:1px solid rgba(255,255,255,.1)">
                        <span style="color:<?= $col ?>"><?= h($lbl) ?></span>
                    </th>
                    <?php endforeach; ?>
                    <th style="padding:12px 16px;text-align:right;font-size:12px;border-left:1px solid rgba(255,255,255,.1);color:#94a3b8">VS P1↔P2</th>
                    <th style="padding:12px 16px;text-align:right;font-size:12px;border-left:1px solid rgba(255,255,255,.1);color:#94a3b8">VS P1↔P3</th>
                </tr>
                </thead>
                <tbody>
                <?php
                $i = 0;
                foreach ($segments as $key => $segLabel):
                    $bg = (++$i % 2 === 0) ? 'background:#f8fafc' : '';
                    $isMoney = in_array($key, ['cl','media','gudang','total','target']);
                    $v1 = $d1[$key]; $v2 = $d2[$key]; $v3 = $d3[$key];
                    $dv12 = $delta((float)$v1, (float)$v2);
                    $dv13 = $delta((float)$v1, (float)$v3);
                    $isBold = in_array($key, ['total']);
                ?>
                <tr style="<?= $bg ?>;border-top:1px solid #e2e8f0">
                    <td style="padding:11px 16px;font-size:14px;<?= $isBold ? 'font-weight:700' : '' ?>"><?= h($segLabel) ?></td>
                    <?php foreach ([$v1,$v2,$v3] as $v): ?>
                    <td style="padding:11px 16px;text-align:right;font-size:14px;<?= $isBold ? 'font-weight:700' : '' ?>;border-left:1px solid #e2e8f0">
                        <?php if ($key === 'ach' || ($key !== 'ach' && $isMoney)): ?>
                            <?= $isMoney ? money((float)$v) : pct((float)$v) ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                    <?php foreach ([$dv12,$dv13] as $dv): ?>
                    <td style="padding:11px 16px;text-align:right;font-size:13px;border-left:1px solid #e2e8f0">
                        <?php if ($dv === null): ?>
                            <span style="color:var(--muted)">—</span>
                        <?php else:
                            $col = $dv >= 0 ? 'var(--green)' : 'var(--accent)';
                            $arrow = $dv >= 0 ? '▲' : '▼';
                        ?>
                            <span style="color:<?= $col ?>;font-weight:600"><?= $arrow ?> <?= pct(abs($dv)) ?></span>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                <!-- Baris Achievement -->
                <?php
                $v1 = $d1['ach']; $v2 = $d2['ach']; $v3 = $d3['ach'];
                $dv12 = ($v1 !== null && $v2 !== null && $v2 > 0) ? (($v1 - $v2) / $v2) : null;
                $dv13 = ($v1 !== null && $v3 !== null && $v3 > 0) ? (($v1 - $v3) / $v3) : null;
                ?>
                <tr style="background:#f0fdf4;border-top:2px solid #86efac">
                    <td style="padding:11px 16px;font-size:14px;font-weight:700">Achievement</td>
                    <?php foreach ([$v1,$v2,$v3] as $v): ?>
                    <td style="padding:11px 16px;text-align:right;font-size:14px;font-weight:700;border-left:1px solid #e2e8f0;color:<?= $v === null ? 'var(--muted)' : ($v >= 1 ? 'var(--green)' : ($v >= .8 ? 'var(--amber)' : 'var(--accent)')) ?>">
                        <?= $v !== null ? pct($v) : '—' ?>
                    </td>
                    <?php endforeach; ?>
                    <?php foreach ([$dv12,$dv13] as $dv): ?>
                    <td style="padding:11px 16px;text-align:right;font-size:13px;border-left:1px solid #e2e8f0">
                        <?php if ($dv === null): ?>
                            <span style="color:var(--muted)">—</span>
                        <?php else:
                            $col = $dv >= 0 ? 'var(--green)' : 'var(--accent)';
                            $arrow = $dv >= 0 ? '▲' : '▼';
                        ?>
                            <span style="color:<?= $col ?>;font-weight:600"><?= $arrow ?> <?= pct(abs($dv)) ?></span>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
                </tbody>
            </table>
        </div>

        <?php
        // Helper sub-tabel breakdown
        $subTable = function (string $title, string $key, array $d1, array $d2, array $d3, string $label1, string $label2, string $label3) use ($delta) {
            $keys = array_unique(array_merge(array_keys($d1[$key]), array_keys($d2[$key]), array_keys($d3[$key])));
            if (empty($keys)) return;
            ?>
        <div style="margin-top:20px">
            <h3 style="font-size:14px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin:0 0 8px"><?= h($title) ?></h3>
            <div class="panel" style="padding:0;overflow:hidden">
                <table style="width:100%;border-collapse:collapse">
                    <thead>
                    <tr style="background:var(--sidebar-bg);color:#fff">
                        <th style="padding:10px 16px;text-align:left;font-size:13px">Kategori</th>
                        <?php foreach ([[$label1,'#0D9488'],[$label2,'#0891b2'],[$label3,'#7c3aed']] as [$lbl,$col]): ?>
                        <th style="padding:10px 16px;text-align:right;font-size:12px;border-left:1px solid rgba(255,255,255,.1)"><span style="color:<?= $col ?>"><?= h($lbl) ?></span></th>
                        <?php endforeach; ?>
                        <th style="padding:10px 16px;text-align:right;font-size:11px;border-left:1px solid rgba(255,255,255,.1);color:#94a3b8">VS P1↔P2</th>
                        <th style="padding:10px 16px;text-align:right;font-size:11px;border-left:1px solid rgba(255,255,255,.1);color:#94a3b8">VS P1↔P3</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $i = 0; foreach ($keys as $k):
                        $v1 = (float)($d1[$key][$k] ?? 0);
                        $v2 = (float)($d2[$key][$k] ?? 0);
                        $v3 = (float)($d3[$key][$k] ?? 0);
                        $dv12 = $delta($v1, $v2);
                        $dv13 = $delta($v1, $v3);
                        $bg = (++$i % 2 === 0) ? 'background:#f8fafc' : '';
                    ?>
                    <tr style="<?= $bg ?>;border-top:1px solid #e2e8f0">
                        <td style="padding:10px 16px;font-size:13px"><?= h((string)$k) ?></td>
                        <?php foreach ([$v1,$v2,$v3] as $v): ?>
                        <td style="padding:10px 16px;text-align:right;font-size:13px;border-left:1px solid #e2e8f0"><?= $v > 0 ? money($v) : '<span style="color:var(--muted)">—</span>' ?></td>
                        <?php endforeach; ?>
                        <?php foreach ([$dv12,$dv13] as $dv): ?>
                        <td style="padding:10px 16px;text-align:right;font-size:12px;border-left:1px solid #e2e8f0">
                            <?php if ($dv === null): ?><span style="color:var(--muted)">—</span>
                            <?php else: $col=$dv>=0?'var(--green)':'var(--accent)'; $arrow=$dv>=0?'▲':'▼'; ?>
                                <span style="color:<?= $col ?>;font-weight:600"><?= $arrow ?> <?= pct(abs($dv)) ?></span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php };

        $subTable('Exhibition — Per Lantai',  'by_floor',  $d1, $d2, $d3, $label1, $label2, $label3);
        $subTable('Media — Per Tipe',         'by_media',  $d1, $d2, $d3, $label1, $label2, $label3);
        $subTable('Gudang — Per Lokasi',      'by_gudang', $d1, $d2, $d3, $label1, $label2, $label3);

        // PIC Achievement
        $picNames = array_unique(array_merge(array_keys($d1['by_pic']), array_keys($d2['by_pic']), array_keys($d3['by_pic'])));
        if (!empty($picNames)):
        ?>
        <div style="margin-top:20px">
            <h3 style="font-size:14px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin:0 0 8px">Achievement per PIC</h3>
            <div class="panel" style="padding:0;overflow:hidden">
                <table style="width:100%;border-collapse:collapse">
                    <thead>
                    <tr style="background:var(--sidebar-bg);color:#fff">
                        <th style="padding:10px 16px;text-align:left;font-size:13px">PIC</th>
                        <?php foreach ([[$label1,'#0D9488'],[$label2,'#0891b2'],[$label3,'#7c3aed']] as [$lbl,$col]): ?>
                        <th style="padding:10px 8px;text-align:right;font-size:11px;border-left:1px solid rgba(255,255,255,.1)"><span style="color:<?= $col ?>"><?= h($lbl) ?></span><br><span style="color:#94a3b8;font-weight:400">Aktual</span></th>
                        <th style="padding:10px 8px;text-align:right;font-size:11px;border-left:1px solid rgba(255,255,255,.05)"><span style="color:<?= $col ?>"><?= h($lbl) ?></span><br><span style="color:#94a3b8;font-weight:400">Ach%</span></th>
                        <?php endforeach; ?>
                        <th style="padding:10px 16px;text-align:right;font-size:11px;border-left:1px solid rgba(255,255,255,.1);color:#94a3b8">VS P1↔P2</th>
                        <th style="padding:10px 16px;text-align:right;font-size:11px;border-left:1px solid rgba(255,255,255,.1);color:#94a3b8">VS P1↔P3</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $i = 0; foreach ($picNames as $pname):
                        $r1 = $d1['by_pic'][$pname] ?? ['actual' => 0, 'target' => 0, 'ach' => null];
                        $r2 = $d2['by_pic'][$pname] ?? ['actual' => 0, 'target' => 0, 'ach' => null];
                        $r3 = $d3['by_pic'][$pname] ?? ['actual' => 0, 'target' => 0, 'ach' => null];
                        $a1 = $r1['ach']; $a2 = $r2['ach']; $a3 = $r3['ach'];
                        $dv12 = ($a1 !== null && $a2 !== null && $a2 > 0) ? (($a1 - $a2) / $a2) : null;
                        $dv13 = ($a1 !== null && $a3 !== null && $a3 > 0) ? (($a1 - $a3) / $a3) : null;
                        $bg = (++$i % 2 === 0) ? 'background:#f8fafc' : '';
                    ?>
                    <tr style="<?= $bg ?>;border-top:1px solid #e2e8f0">
                        <td style="padding:10px 16px;font-size:13px;font-weight:600"><?= h($pname) ?></td>
                        <?php foreach ([$r1, $r2, $r3] as $r):
                            $achCol = $r['ach'] === null ? 'var(--muted)' : ($r['ach'] >= 1 ? 'var(--green)' : ($r['ach'] >= .8 ? 'var(--amber)' : 'var(--accent)'));
                        ?>
                        <td style="padding:10px 8px;text-align:right;font-size:12px;border-left:1px solid #e2e8f0;color:var(--muted)"><?= $r['actual'] > 0 ? money($r['actual']) : '<span style="color:var(--muted)">—</span>' ?></td>
                        <td style="padding:10px 8px;text-align:right;font-size:13px;font-weight:700;border-left:1px solid #f1f5f9;color:<?= $achCol ?>"><?= $r['ach'] !== null ? pct($r['ach']) : '—' ?></td>
                        <?php endforeach; ?>
                        <?php foreach ([$dv12, $dv13] as $dv): ?>
                        <td style="padding:10px 16px;text-align:right;font-size:12px;border-left:1px solid #e2e8f0">
                            <?php if ($dv === null): ?><span style="color:var(--muted)">—</span>
                            <?php else: $col = $dv >= 0 ? 'var(--green)' : 'var(--accent)'; $arrow = $dv >= 0 ? '▲' : '▼'; ?>
                                <span style="color:<?= $col ?>;font-weight:600"><?= $arrow ?> <?= pct(abs($dv)) ?></span>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php
    });
}
