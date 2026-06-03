<?php
declare(strict_types=1);

function exec_dashboard(PDO $pdo): void
{
    require_permission('view_exec_summary');

    $period = getv('period', date('Y-m'));

    // Load all active properties
    $properties = $pdo->query("SELECT id, name FROM properties WHERE status='active' ORDER BY id")->fetchAll();
    if (count($properties) < 1) {
        flash('Tidak ada properti aktif.');
        redirect_to('dashboard');
    }

    $monthNames = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April',
                   '05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus',
                   '09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
    $periodLabel = ($monthNames[substr($period,5,2)] ?? substr($period,5,2)) . ' ' . substr($period,0,4);
    $periodDays  = (int) date('t', strtotime($period.'-01'));

    // Collect per-property data
    $propData = [];
    foreach ($properties as $prop) {
        $pid = (int) $prop['id'];
        $propData[$pid] = _exec_fetch_prop_data($pdo, $pid, $period, $periodDays);
        $propData[$pid]['name'] = $prop['name'];
        $propData[$pid]['id']   = $pid;
    }

    // Combined totals
    $combined = _exec_combine($propData);

    // Period list for selector
    $allPeriods = $pdo->query(
        "SELECT DISTINCT period_key FROM transaction_allocations ORDER BY period_key DESC LIMIT 36"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($period, $allPeriods, true)) array_unshift($allPeriods, $period);

    audit($pdo, 'view', 'exec_dashboard', $period, ['period' => $period], [], 'reporting');

    // Daily occupancy chart data — per property
    $firstDay = $period . '-01';
    $lastDay  = $period . '-' . $periodDays;

    $clTotalByProp  = [];
    $medTotalByProp = [];
    $stCl  = $pdo->prepare("SELECT COUNT(*) FROM master_cl_units WHERE status='active' AND property_id=?");
    $stMed = $pdo->prepare("SELECT COUNT(*) FROM master_media   WHERE status='active' AND property_id=?");
    foreach ($properties as $prop) {
        $pid = (int) $prop['id'];
        $stCl->execute([$pid]);  $clTotalByProp[$pid]  = (int) $stCl->fetchColumn();
        $stMed->execute([$pid]); $medTotalByProp[$pid] = (int) $stMed->fetchColumn();
    }

    $trxStmt = $pdo->prepare(
        "SELECT property_id, master_code, module, start_date, end_date
         FROM transactions
         WHERE deleted_at IS NULL AND module IN ('cl','media')
           AND start_date <= ? AND end_date >= ?"
    );
    $trxStmt->execute([$lastDay, $firstDay]);
    $monthTrx = $trxStmt->fetchAll();

    $dailyOccByProp = [];
    foreach ($properties as $prop) {
        $pid  = (int) $prop['id'];
        $clT  = $clTotalByProp[$pid]  ?? 0;
        $medT = $medTotalByProp[$pid] ?? 0;
        $cl = $med = [];
        for ($d = 1; $d <= $periodDays; $d++) {
            $day = sprintf('%s-%02d', $period, $d);
            $clCodes = $medCodes = [];
            foreach ($monthTrx as $t) {
                if ((int)$t['property_id'] === $pid && $t['start_date'] <= $day && $t['end_date'] >= $day) {
                    if ($t['module'] === 'cl')    $clCodes[$t['master_code']]  = true;
                    else                          $medCodes[$t['master_code']] = true;
                }
            }
            $cl[]  = $clT  > 0 ? round(count($clCodes)  / $clT  * 100, 1) : 0;
            $med[] = $medT > 0 ? round(count($medCodes) / $medT * 100, 1) : 0;
        }
        $dailyOccByProp[$pid] = ['name' => $prop['name'], 'cl' => $cl, 'media' => $med];
    }

    layout('Executive Summary', function () use ($period, $periodLabel, $periodDays, $propData, $combined, $allPeriods, $monthNames, $dailyOccByProp) {
        ?>
        <style>
            .exec-toolbar { position:sticky;top:0;z-index:50;background:var(--bg,#f8fafc);box-shadow:0 1px 0 var(--line,#e2e8f0);padding:10px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap; }
            .exec-badge-label { display:inline-block;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;background:#fef3c7;color:#92400e;margin-bottom:12px; }
            .prop-col-grid { display:grid;grid-template-columns:repeat(<?= count($propData) ?>,1fr);gap:16px;margin-bottom:16px; }
            .prop-card { background:#fff;border:1px solid var(--line,#e2e8f0);border-radius:12px;padding:18px 20px;position:relative;overflow-x:auto; }
            .prop-card::before { content:'';position:absolute;top:0;left:0;right:0;height:4px; }
            .prop-card.p1::before { background:#0d9488; }
            .prop-card.p2::before { background:#7c3aed; }
            .prop-card.p3::before { background:#0891b2; }
            .prop-card.p4::before { background:#d97706; }
            .prop-name { font-size:13px;font-weight:700;color:var(--muted,#64748b);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px; }
            .prop-kpi-row { display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:12px; }
            .prop-kpi { background:#f8fafc;border-radius:8px;padding:10px 12px; }
            .prop-kpi-label { font-size:10px;font-weight:700;text-transform:uppercase;color:var(--muted,#64748b);letter-spacing:.05em; }
            .prop-kpi-value { font-size:16px;font-weight:800;margin-top:3px;color:var(--ink,#0f172a); }
            .prop-kpi-value.red { color:#dc2626; }
            .prop-kpi-value.green { color:#16a34a; }
            .seg-bars { margin-top:8px; }
            .seg-bar-row { margin-bottom:8px; }
            .seg-bar-label { display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px; }
            .seg-bar-track { height:8px;border-radius:999px;background:#f1f5f9;overflow:hidden; }
            .seg-bar-fill { height:100%;border-radius:999px;transition:width .5s ease; }
            .combined-strip { display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:16px; }
            .combined-kpi { background:#fff;border:1px solid var(--line,#e2e8f0);border-radius:10px;padding:14px 16px; }
            .combined-kpi:first-child { border-top:3px solid #0d9488; }
            .combined-kpi:nth-child(2) { border-top:3px solid #3b82f6; }
            .combined-kpi:nth-child(3) { border-top:3px solid #10b981; }
            .combined-kpi:nth-child(4) { border-top:3px solid #0369a1; }
            .combined-kpi:nth-child(5) { border-top:3px solid #f59e0b; }
            .combined-kpi:nth-child(6) { border-top:3px solid #8b5cf6; }
            .combined-kpi-label { font-size:10px;font-weight:700;text-transform:uppercase;color:var(--muted,#64748b);letter-spacing:.05em; }
            .combined-kpi-value { font-size:20px;font-weight:800;margin-top:4px;color:var(--ink,#0f172a); }
            .section-title { font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--muted,#64748b);margin-bottom:12px;padding-bottom:6px;border-bottom:1px solid var(--line,#e2e8f0); }
            .pic-table th, .pic-table td { font-size:10px; }
            .occ-table td, .occ-table th { font-size:10px; }
            .prop-tag { display:inline-block;padding:1px 7px;border-radius:4px;font-size:10px;font-weight:700; }
            .prop-tag-1 { background:#ccfbf1;color:#0f766e; }
            .prop-tag-2 { background:#ede9fe;color:#6d28d9; }
            .prop-tag-3 { background:#dbeafe;color:#1d4ed8; }
            .prop-tag-4 { background:#fef3c7;color:#92400e; }
            .ach-pill { display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700; }
            .ach-good { background:#dcfce7;color:#15803d; }
            .ach-warn { background:#fef3c7;color:#92400e; }
            .ach-bad  { background:#fee2e2;color:#b91c1c; }
            .seg-compare-grid { display:grid;grid-template-columns:auto <?= implode(' ', array_fill(0, count($propData), '1fr')) ?> 1fr;gap:0; }
            @keyframes exSlideUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
            @keyframes exFadeIn{from{opacity:0}to{opacity:1}}
            .combined-strip>.combined-kpi{animation:exSlideUp .4s ease both}
            .combined-strip>.combined-kpi:nth-child(1){animation-delay:.05s}
            .combined-strip>.combined-kpi:nth-child(2){animation-delay:.11s}
            .combined-strip>.combined-kpi:nth-child(3){animation-delay:.17s}
            .combined-strip>.combined-kpi:nth-child(4){animation-delay:.23s}
            .combined-strip>.combined-kpi:nth-child(5){animation-delay:.29s}
            .combined-strip>.combined-kpi:nth-child(6){animation-delay:.35s}
            .section-title{animation:exFadeIn .5s ease both}
        </style>

        <!-- Toolbar -->
        <div class="exec-toolbar">
            <form method="get" style="display:flex;align-items:center;gap:10px">
                <input type="hidden" name="r" value="exec_dashboard">
                <div><label style="font-size:11px;font-weight:600;color:var(--muted)">Periode</label>
                <select name="period" onchange="this.form.submit()" style="min-width:160px">
                    <?php foreach ($allPeriods as $pk):
                        $py = substr($pk,0,4); $pm = substr($pk,5,2);
                        $lbl = ($monthNames[$pm] ?? $pm) . ' ' . $py;
                    ?>
                    <option value="<?= h($pk) ?>" <?= $pk === $period ? 'selected' : '' ?>><?= h($lbl) ?></option>
                    <?php endforeach; ?>
                </select></div>
            </form>
            <div style="margin-left:auto">
                <a href="?r=print_exec_summary&period=<?= urlencode($period) ?>" target="_blank"
                   style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:#0d9488;color:#fff;border-radius:7px;font-size:12px;font-weight:700;text-decoration:none">
                    🖨 Cetak / PDF
                </a>
            </div>
        </div>

        <!-- Combined KPI Strip -->
        <div style="padding:16px 20px 0">
            <div class="section-title">Ringkasan Gabungan — <?= h($periodLabel) ?></div>
            <?php
            $totalAch = $combined['target'] > 0 ? $combined['actual'] / $combined['target'] : 0;
            $totalPotAch = $combined['projection'] > 0 ? $combined['actual'] / $combined['projection'] : 0;
            $achColor = $totalAch >= 1 ? 'green' : ($totalAch >= 0.8 ? '' : 'red');
            ?>
            <div class="combined-strip">
                <div class="combined-kpi">
                    <div class="combined-kpi-label">Total Potensi</div>
                    <div class="combined-kpi-value"><?= money($combined['projection']) ?></div>
                </div>
                <div class="combined-kpi">
                    <div class="combined-kpi-label">Total Target</div>
                    <div class="combined-kpi-value"><?= money($combined['target']) ?></div>
                </div>
                <div class="combined-kpi">
                    <div class="combined-kpi-label">Total Aktual</div>
                    <div class="combined-kpi-value <?= $achColor ?>"><?= money($combined['actual']) ?></div>
                </div>
                <div class="combined-kpi" style="background:<?= $combined['recurring'] > 0 ? '#f0f9ff' : '' ?>">
                    <div class="combined-kpi-label" style="color:#0369a1">Recurring</div>
                    <div class="combined-kpi-value" style="color:<?= $combined['recurring'] > 0 ? '#0369a1' : 'var(--muted)' ?>"><?= $combined['recurring'] > 0 ? money($combined['recurring']) : '—' ?></div>
                </div>
                <div class="combined-kpi">
                    <div class="combined-kpi-label">Achievement vs Target</div>
                    <?php $a=$totalAch; $cls=$a>=1?'ach-good':($a>=.8?'ach-warn':'ach-bad'); ?>
                    <div class="combined-kpi-value"><span class="ach-pill <?= $cls ?>" style="font-size:18px"><?= pct($totalAch) ?></span></div>
                </div>
            </div>

            <!-- Per-Property Cards -->
            <div class="section-title" style="margin-top:4px">Per Properti</div>
            <div class="prop-col-grid">
            <?php foreach ($propData as $idx => $d):
                $cardClass = 'p' . (array_search($idx, array_keys($propData)) + 1);
                $ach = $d['target'] > 0 ? $d['actual'] / $d['target'] : 0;
                $achClass = $ach >= 1 ? 'green' : ($ach >= 0.8 ? '' : 'red');
                $achPillClass = $ach >= 1 ? 'ach-good' : ($ach >= 0.8 ? 'ach-warn' : 'ach-bad');
                $moduleColors = ['cl'=>'#0d9488','media'=>'#0891b2','gudang'=>'#f59e0b'];
                $moduleLabels = ['cl'=>'Exhibition','media'=>'Media','gudang'=>'Gudang'];
            ?>
            <?php $cardPos = array_search($idx, array_keys($propData)); ?>
            <div class="prop-card <?= $cardClass ?>" style="animation:exSlideUp .4s <?= number_format(0.15 + $cardPos * 0.1, 2, '.', '') ?>s ease both">
                <div class="prop-name"><?= h($d['name']) ?></div>
                <div class="prop-kpi-row">
                    <div class="prop-kpi">
                        <div class="prop-kpi-label">Potensi</div>
                        <div class="prop-kpi-value"><?= money(array_sum($d['projection'])) ?></div>
                    </div>
                    <div class="prop-kpi">
                        <div class="prop-kpi-label">Target</div>
                        <div class="prop-kpi-value"><?= money($d['target']) ?></div>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:12px">
                    <div class="prop-kpi">
                        <div class="prop-kpi-label">Regular</div>
                        <div class="prop-kpi-value" style="font-size:14px"><?= money($d['actual'] - $d['recurring']) ?></div>
                    </div>
                    <div class="prop-kpi" style="background:#f0f9ff">
                        <div class="prop-kpi-label" style="color:#0369a1">Recurring</div>
                        <div class="prop-kpi-value" style="font-size:14px;color:#0369a1"><?= $d['recurring'] > 0 ? money($d['recurring']) : '—' ?></div>
                    </div>
                    <div class="prop-kpi">
                        <div class="prop-kpi-label">Aktual</div>
                        <div class="prop-kpi-value <?= $achClass ?>" style="font-size:14px"><?= money($d['actual']) ?></div>
                        <div style="margin-top:4px"><span class="ach-pill <?= $achPillClass ?>" style="font-size:10px"><?= pct($ach) ?></span></div>
                    </div>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-bottom:6px">
                    <span>Sisa: <strong style="color:<?= $d['actual'] >= $d['target'] ? '#16a34a' : '#c2410c' ?>"><?= money(abs($d['target'] - $d['actual'])) ?></strong></span>
                    <span>Client Baru: <strong><?= $d['new_clients'] ?></strong></span>
                </div>
                <!-- OCC KPI per segmen -->
                <?php
                $segOccMap = ['cl'=>['floor_occ','Exhibition','#0d9488'],'media'=>['media_occ','Media Promo','#0891b2'],'gudang'=>['gudang_occ','Gudang','#f59e0b']];
                ?>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:10px">
                <?php foreach($segOccMap as $seg=>[$occKey,$lbl,$col]):
                    $segDays = $segUnits = 0;
                    foreach ($d[$occKey] as $r) { $segDays += $r['days_total']; $segUnits += $r['unit_count']; }
                    $segOcc = $segUnits*$periodDays > 0 ? $segDays/($segUnits*$periodDays) : 0;
                    $occClr = $segOcc>=1?'#16a34a':($segOcc>=.8?'#d97706':'#dc2626');
                ?>
                    <div style="background:#f8fafc;border-radius:7px;padding:8px 10px;border-top:2px solid <?= $col ?>">
                        <div class="prop-kpi-label"><?= $lbl ?></div>
                        <div style="font-size:15px;font-weight:800;color:<?= $occClr ?>;margin-top:3px"><?= number_format($segOcc*100,1,',','.').'%' ?></div>
                        <div style="font-size:9px;color:var(--muted,#64748b);margin-top:1px">Occupancy Rate</div>
                    </div>
                <?php endforeach; ?>
                </div>
                <div class="seg-bars">
                    <?php foreach (['cl','media','gudang'] as $seg):
                        $segPct = $d['projection'][$seg] > 0 ? min($d['actual_seg'][$seg] / $d['projection'][$seg], 1) : 0;
                    ?>
                    <div class="seg-bar-row">
                        <div class="seg-bar-label">
                            <span style="font-weight:600"><?= $moduleLabels[$seg] ?></span>
                            <span><?= money($d['actual_seg'][$seg]) ?> <span style="color:var(--muted);font-size:10px"><?= pct($segPct) ?></span></span>
                        </div>
                        <div class="seg-bar-track">
                            <div class="seg-bar-fill" data-w="<?= number_format(min($segPct*100,100),1,'.','.') ?>" style="width:0;background:<?= $moduleColors[$seg] ?>"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            </div>

            <!-- Segment Comparison Table -->
            <div class="section-title" style="margin-top:4px">Perbandingan Segment</div>
            <div class="panel" style="padding:0;overflow:hidden;margin-bottom:16px">
                <table class="pic-table" style="width:100%;border-collapse:collapse;font-size:10px">
                    <thead>
                        <tr style="background:#f8fafc">
                            <th style="padding:10px 14px;text-align:left;border-bottom:1px solid var(--line,#e2e8f0);width:120px">Segment</th>
                            <?php foreach ($propData as $d): ?>
                            <th style="padding:10px 14px;text-align:right;border-bottom:1px solid var(--line,#e2e8f0)">
                                <?= h($d['name']) ?><br>
                                <span style="font-size:10px;font-weight:400;color:var(--muted)">Potensi / Aktual / %</span>
                            </th>
                            <?php endforeach; ?>
                            <th style="padding:10px 14px;text-align:right;border-bottom:1px solid var(--line,#e2e8f0);background:#f0fdf4">
                                Gabungan<br>
                                <span style="font-size:10px;font-weight:400;color:var(--muted)">Aktual / %</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $segRows = [
                        'cl'     => 'Exhibition',
                        'media'  => 'Media Promo',
                        'gudang' => 'Gudang / Storage',
                    ];
                    foreach ($segRows as $seg => $segLabel):
                        $combSegAct  = array_sum(array_map(fn($d) => $d['actual_seg'][$seg], $propData));
                        $combSegProj = array_sum(array_map(fn($d) => $d['projection'][$seg], $propData));
                        $combSegPct  = $combSegProj > 0 ? $combSegAct / $combSegProj : 0;
                    ?>
                    <tr style="border-bottom:1px solid var(--line,#f1f5f9)">
                        <td style="padding:10px 14px;font-weight:700"><?= $segLabel ?></td>
                        <?php foreach ($propData as $d):
                            $sp = $d['projection'][$seg] > 0 ? $d['actual_seg'][$seg] / $d['projection'][$seg] : 0;
                            $spCls = $sp >= 1 ? 'ach-good' : ($sp >= .8 ? 'ach-warn' : 'ach-bad');
                        ?>
                        <td style="padding:10px 14px;text-align:right">
                            <span style="color:var(--muted)"><?= money($d['projection'][$seg]) ?></span><br>
                            <strong><?= money($d['actual_seg'][$seg]) ?></strong>
                            <span class="ach-pill <?= $spCls ?>" style="margin-left:4px"><?= pct($sp) ?></span>
                        </td>
                        <?php endforeach; ?>
                        <td style="padding:10px 14px;text-align:right;background:#f0fdf4">
                            <strong><?= money($combSegAct) ?></strong>
                            <?php $cCls = $combSegPct>=1?'ach-good':($combSegPct>=.8?'ach-warn':'ach-bad'); ?>
                            <span class="ach-pill <?= $cCls ?>" style="margin-left:4px"><?= pct($combSegPct) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="background:#f8fafc;font-weight:800;border-top:2px solid var(--line,#e2e8f0)">
                        <td style="padding:10px 14px">Total</td>
                        <?php foreach ($propData as $d):
                            $dp = $d['target'] > 0 ? $d['actual'] / $d['target'] : 0;
                            $dpCls = $dp>=1?'ach-good':($dp>=.8?'ach-warn':'ach-bad');
                        ?>
                        <td style="padding:10px 14px;text-align:right">
                            <span style="color:var(--muted)"><?= money($d['target']) ?></span><br>
                            <strong><?= money($d['actual']) ?></strong>
                            <span class="ach-pill <?= $dpCls ?>" style="margin-left:4px"><?= pct($dp) ?></span>
                        </td>
                        <?php endforeach; ?>
                        <td style="padding:10px 14px;text-align:right;background:#f0fdf4">
                            <?php $tp = $combined['target']>0?$combined['actual']/$combined['target']:0; $tCls=$tp>=1?'ach-good':($tp>=.8?'ach-warn':'ach-bad'); ?>
                            <strong><?= money($combined['actual']) ?></strong>
                            <span class="ach-pill <?= $tCls ?>" style="margin-left:4px"><?= pct($tp) ?></span>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <?php
            $floorOrder = ['LG'=>1,'GF'=>2,'UG'=>3,'FF'=>4,'SF'=>5];

            // useUnion=true → baris seragam lintas properti (Exhibition); false → tiap properti hanya tampilkan datanya sendiri
            $renderOccSection = function(string $title, string $occKey, string $groupLabel, ?callable $sorter = null, bool $useUnion = false, string $rateLabel = 'Avg Rate') use ($propData, $periodDays) {
                // Untuk union mode: hitung semua key dari semua properti
                $unionKeys = [];
                if ($useUnion) {
                    foreach ($propData as $d) {
                        foreach ($d[$occKey] as $row) {
                            $k = (string)($row['group_key'] ?? '');
                            if (!in_array($k, $unionKeys, true)) $unionKeys[] = $k;
                        }
                    }
                    if ($sorter) usort($unionKeys, $sorter); else sort($unionKeys);
                }
                // Index data per properti: group_key → row
                $indexed = [];
                foreach ($propData as $d) {
                    $indexed[$d['id']] = [];
                    foreach ($d[$occKey] as $row) {
                        $indexed[$d['id']][(string)($row['group_key'] ?? '')] = $row;
                    }
                }
            ?>
            <div class="section-title" style="margin-top:4px"><?= $title ?></div>
            <div class="prop-col-grid" style="margin-bottom:16px">
            <?php foreach ($propData as $cardIdx => $d):
                $ci = array_search($d['id'], array_column(array_values($propData), 'id')) + 1;
                // Tentukan baris yang ditampilkan untuk properti ini
                if ($useUnion) {
                    $displayKeys = $unionKeys;
                } else {
                    $displayKeys = array_map(fn($r) => (string)($r['group_key'] ?? ''), $d[$occKey]);
                    if ($sorter) usort($displayKeys, $sorter); else sort($displayKeys);
                }
            ?>
            <div class="prop-card p<?= $ci ?>">
                <div class="prop-name"><?= h($d['name']) ?></div>
                <?php if (empty($displayKeys)): ?>
                    <div style="color:var(--muted);font-size:12px;padding:8px 0">Tidak ada data.</div>
                <?php else: ?>
                <table class="occ-table" style="width:100%;border-collapse:collapse">
                    <thead>
                        <tr style="border-bottom:1px solid var(--line,#e2e8f0)">
                            <th style="padding:3px 5px;text-align:left;font-weight:700;color:var(--muted);text-transform:uppercase;font-size:8px"><?= $groupLabel ?></th>
                            <th style="padding:3px 5px;text-align:center;font-weight:700;color:var(--muted);text-transform:uppercase;font-size:8px">Unit</th>
                            <th style="padding:3px 5px;text-align:right;font-weight:700;color:var(--muted);text-transform:uppercase;font-size:8px">Occ %</th>
                            <th style="padding:3px 5px;text-align:right;font-weight:700;color:var(--muted);text-transform:uppercase;font-size:8px"><?= h($rateLabel) ?></th>
                            <th style="padding:3px 5px;text-align:right;font-weight:700;color:var(--muted);text-transform:uppercase;font-size:8px">Regular</th>
                            <th style="padding:3px 5px;text-align:right;font-weight:700;color:#0369a1;text-transform:uppercase;font-size:8px">Recurring</th>
                            <th style="padding:3px 5px;text-align:right;font-weight:700;color:var(--muted);text-transform:uppercase;font-size:8px">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $tU = $tD = $tA = $tRec = 0;
                    foreach ($displayKeys as $key):
                        $row     = $indexed[$d['id']][$key] ?? null;
                        $units   = $row ? (int)$row['unit_count']       : 0;
                        $daysTot = $row ? (float)$row['days_total']     : 0.0;
                        $actTot  = $row ? (float)$row['actual_total']   : 0.0;
                        $recTot  = $row ? (float)$row['recurring_total']: 0.0;
                        $regTot  = $actTot - $recTot;
                        $avgRate = $row ? ($row['avg_rate'] !== null ? (float)$row['avg_rate'] : null) : null;
                        $maxDays  = $units * $periodDays;
                        $occ      = $maxDays > 0 ? $daysTot / $maxDays : 0;
                        $occColor = $occ >= 0.8 ? '#16a34a' : ($occ >= 0.5 ? '#d97706' : '#dc2626');
                        $dimmed   = $row ? '' : 'color:var(--muted)';
                        $tU += $units; $tD += $daysTot; $tA += $actTot; $tRec += $recTot;
                    ?>
                    <tr style="border-bottom:1px solid #f1f5f9;<?= $dimmed ?>">
                        <td style="padding:3px 5px;font-weight:600;<?= $dimmed ?>"><?= h($key ?: '—') ?></td>
                        <td style="padding:3px 5px;text-align:center"><?= $units ?: '<span style="color:var(--muted)">—</span>' ?></td>
                        <td style="padding:3px 5px;text-align:right;font-weight:800;color:<?= $row ? $occColor : 'var(--muted)' ?>"><?= $row ? number_format($occ*100,1,',','.').'%' : '—' ?></td>
                        <td style="padding:3px 5px;text-align:right;color:var(--muted);white-space:nowrap"><?= $avgRate !== null ? money($avgRate) : '<span style="color:var(--muted)">—</span>' ?></td>
                        <td style="padding:3px 5px;text-align:right;white-space:nowrap"><?= $row ? money($regTot) : '<span style="color:var(--muted)">—</span>' ?></td>
                        <td style="padding:3px 5px;text-align:right;white-space:nowrap;color:<?= $row && $recTot > 0 ? '#0369a1' : 'var(--muted)' ?>;font-weight:<?= $row && $recTot > 0 ? '700' : '400' ?>"><?= $row ? ($recTot > 0 ? money($recTot) : '—') : '<span style="color:var(--muted)">—</span>' ?></td>
                        <td style="padding:3px 5px;text-align:right;white-space:nowrap;font-weight:700"><?= $row ? money($actTot) : '<span style="color:var(--muted)">—</span>' ?></td>
                    </tr>
                    <?php endforeach;
                    $totalMaxDays  = $tU * $periodDays;
                    $totalOcc      = $totalMaxDays > 0 ? $tD / $totalMaxDays : 0;
                    $totalOccColor = $totalOcc >= 0.8 ? '#16a34a' : ($totalOcc >= 0.5 ? '#d97706' : '#dc2626');
                    $tReg = $tA - $tRec;
                    ?>
                    <tr style="background:#f8fafc;font-weight:800;border-top:2px solid var(--line,#e2e8f0)">
                        <td style="padding:3px 5px">Total</td>
                        <td style="padding:3px 5px;text-align:center"><?= $tU ?></td>
                        <td style="padding:3px 5px;text-align:right;color:<?= $totalOccColor ?>"><?= number_format($totalOcc*100,1,',','.') ?>%</td>
                        <td style="padding:3px 5px;text-align:right;color:var(--muted)">—</td>
                        <td style="padding:3px 5px;text-align:right;white-space:nowrap"><?= money($tReg) ?></td>
                        <td style="padding:3px 5px;text-align:right;white-space:nowrap;color:<?= $tRec > 0 ? '#0369a1' : 'var(--muted)' ?>"><?= $tRec > 0 ? money($tRec) : '—' ?></td>
                        <td style="padding:3px 5px;text-align:right;white-space:nowrap"><?= money($tA) ?></td>
                    </tr>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>
            <?php };

            $renderOccSection('Occupancy Exhibition per Lantai', 'floor_occ', 'Lantai',
                fn($a,$b) => ($floorOrder[$a] ?? 99) <=> ($floorOrder[$b] ?? 99), true, 'Avg Rate/Hari/m²'
            );
            $renderOccSection('Occupancy Media Promo per Jenis',       'media_occ',  'Jenis', null, false, 'Avg Rate/Hari');
            $renderOccSection('Occupancy Gudang / Storage per Lokasi', 'gudang_occ', 'Lokasi',
                fn($a,$b) => ($floorOrder[$a] ?? 99) <=> ($floorOrder[$b] ?? 99), false, 'Avg Rate/m²/Bln'
            );
            ?>

            <!-- PIC Achievement — Per Properti -->
            <div class="section-title">Achievement PIC</div>
            <?php foreach ($propData as $d): ?>
            <div style="margin-bottom:16px">
                <div style="font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:var(--brand,#0d9488);margin-bottom:6px"><?= h($d['name']) ?></div>
                <div class="panel" style="padding:0;overflow:hidden">
                    <table class="pic-table" style="width:100%;border-collapse:collapse">
                        <thead>
                            <tr style="background:#f8fafc">
                                <th style="padding:10px 14px;text-align:center;width:40px;border-bottom:1px solid var(--line,#e2e8f0)">#</th>
                                <th style="padding:10px 14px;border-bottom:1px solid var(--line,#e2e8f0)">PIC</th>
                                <th style="padding:10px 14px;border-bottom:1px solid var(--line,#e2e8f0)">Jabatan</th>
                                <th style="padding:10px 14px;text-align:right;border-bottom:1px solid var(--line,#e2e8f0)">Target Posisi</th>
                                <th style="padding:10px 14px;text-align:right;border-bottom:1px solid var(--line,#e2e8f0)">Aktual</th>
                                <th style="padding:10px 14px;text-align:right;border-bottom:1px solid var(--line,#e2e8f0)">Achievement</th>
                                <th style="padding:10px 14px;text-align:right;border-bottom:1px solid var(--line,#e2e8f0)">TRX</th>
                                <th style="padding:10px 14px;text-align:right;border-bottom:1px solid var(--line,#e2e8f0)">Client Baru</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($d['pics'] as $i => $row):
                            $picTarget  = (float)$row['target_share'] * (float)$d['target'];
                            $ach        = $picTarget > 0 ? (float)$row['actual'] / $picTarget : 0;
                            $achPillCls = $ach >= 1 ? 'ach-good' : ($ach >= .8 ? 'ach-warn' : 'ach-bad');
                            $rankEmoji  = $i === 0 ? ' 👑' : ($i === count($d['pics'])-1 && count($d['pics']) > 1 ? ' 😢' : '');
                            $highlight  = $ach >= 1 ? ' style="background:#f0fdf4"' : '';
                            $rankColor  = $i===0?'#f59e0b':($i===1?'#94a3b8':($i===2?'#b87333':'#cbd5e1'));
                        ?>
                        <tr<?= $highlight ?> style="border-bottom:1px solid var(--line,#f1f5f9)">
                            <td style="padding:8px 14px;text-align:center;font-weight:800;font-size:14px;color:<?= $rankColor ?>"><?= $i+1 ?></td>
                            <td style="padding:8px 14px;font-weight:600"><?= h($row['pic_name']) ?><?= $rankEmoji ?></td>
                            <td style="padding:8px 14px;color:var(--muted)"><?= h($row['role_name']) ?></td>
                            <td style="padding:8px 14px;text-align:right"><?= $picTarget > 0 ? money($picTarget) : '<span style="color:var(--muted)">—</span>' ?></td>
                            <td style="padding:8px 14px;text-align:right;font-weight:700"><?= money($row['actual']) ?></td>
                            <td style="padding:8px 14px;text-align:right"><?= $picTarget > 0 ? '<span class="ach-pill '.$achPillCls.'">'.pct($ach).'</span>' : '<span style="color:var(--muted)">—</span>' ?></td>
                            <td style="padding:8px 14px;text-align:right;font-size:12px"><span style="color:#0369a1;font-weight:700"><?= (int)$row['trx_recurring'] ?></span>/<?= (int)$row['trx_count'] ?></td>
                            <td style="padding:8px 14px;text-align:right;font-weight:700"><?= (int)$row['new_clients'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($d['pics'])): ?>
                        <tr><td colspan="8" style="padding:24px;text-align:center;color:var(--muted)">Belum ada data PIC untuk periode ini.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
        <!-- DAILY OCCUPANCY CHART -->
        <div class="section-title" style="margin-top:4px">Tren Occupancy Harian — <?= h($periodLabel) ?></div>
        <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-bottom:10px;font-size:12px">
            <span style="display:flex;align-items:center;gap:6px"><span style="width:24px;height:3px;background:#0D9488;border-radius:2px;display:inline-block"></span>Exhibition (CL)</span>
            <span style="display:flex;align-items:center;gap:6px"><span style="width:24px;height:3px;background:#0891B2;border-radius:2px;display:inline-block"></span>Media</span>
        </div>
        <div class="prop-col-grid" style="margin-bottom:16px">
        <?php foreach ($dailyOccByProp as $pid => $occ): ?>
            <div class="panel" style="padding:14px 16px">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:10px"><?= h($occ['name']) ?></div>
                <div style="position:relative;height:180px">
                    <canvas id="chartDailyOcc<?= $pid ?>"></canvas>
                </div>
            </div>
        <?php endforeach; ?>
        </div>

        <script src="assets/chart.umd.min.js"></script>
        <script>
        setTimeout(function(){
            document.querySelectorAll('.seg-bar-fill[data-w]').forEach(function(b){
                b.style.transition='width .7s ease';
                b.style.width=b.getAttribute('data-w')+'%';
            });
        },300);

        (function(){
            var days = <?= $periodDays ?>;
            var labels = [];
            for (var i = 1; i <= days; i++) labels.push(i);
            var period = '<?= substr($period, 0, 7) ?>';

            var chartCfg = function(clData, medData) {
                return {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: 'Exhibition (CL)',
                                data: clData,
                                borderColor: '#0D9488',
                                backgroundColor: 'rgba(13,148,136,.07)',
                                borderWidth: 2,
                                pointRadius: 2,
                                pointHoverRadius: 5,
                                tension: 0.3,
                                fill: true,
                            },
                            {
                                label: 'Media',
                                data: medData,
                                borderColor: '#0891B2',
                                backgroundColor: 'rgba(8,145,178,.05)',
                                borderWidth: 2,
                                pointRadius: 2,
                                pointHoverRadius: 5,
                                tension: 0.3,
                                fill: true,
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    title: function(ctx) { return period + '-' + String(ctx[0].label).padStart(2,'0'); },
                                    label: function(ctx) { return ctx.dataset.label + ': ' + ctx.parsed.y + '%'; }
                                }
                            }
                        },
                        scales: {
                            x: { grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, color: '#94A3B8' } },
                            y: {
                                min: 0, max: 100,
                                grid: { color: '#f1f5f9' },
                                ticks: { font: { size: 10 }, color: '#94A3B8', callback: function(v){ return v+'%'; }, stepSize: 25 }
                            }
                        }
                    }
                };
            };

            <?php foreach ($dailyOccByProp as $pid => $occ): ?>
            new Chart(document.getElementById('chartDailyOcc<?= $pid ?>'), chartCfg(
                <?= json_encode($occ['cl']) ?>,
                <?= json_encode($occ['media']) ?>
            ));
            <?php endforeach; ?>
        })();
        </script>
        </div>
        <?php
    }, ['hide_prop_tabs' => true]);
}

function _exec_fetch_prop_data(PDO $pdo, int $pid, string $period, int $periodDays): array
{
    // Target
    $s = $pdo->prepare("SELECT target_amount FROM targets_monthly WHERE period_key=? AND property_id=?");
    $s->execute([$period, $pid]);
    $target = (float)($s->fetchColumn() ?: 0);

    // Projection per segment (reads from period_potentials snapshot, falls back to master)
    $projection = get_projection($pdo, $period, $pid);

    // Actual per segment
    $s = $pdo->prepare("SELECT module, COALESCE(SUM(amount),0) actual FROM transaction_allocations WHERE period_key=? AND property_id=? GROUP BY module");
    $s->execute([$period, $pid]);
    $actualSeg = ['cl'=>0,'media'=>0,'gudang'=>0];
    foreach ($s->fetchAll() as $r) { $actualSeg[$r['module']] = (float)$r['actual']; }
    $actual = array_sum($actualSeg);

    // Recurring
    $s = $pdo->prepare(
        "SELECT COALESCE(SUM(a.amount),0) FROM transaction_allocations a
         JOIN transactions t ON t.id=a.transaction_id AND t.billing_method='spread' AND t.deleted_at IS NULL
         WHERE a.period_key=? AND a.property_id=?"
    );
    $s->execute([$period, $pid]);
    $recurring = (float)$s->fetchColumn();

    // New clients
    $s = $pdo->prepare(
        "SELECT COUNT(DISTINCT t.client_id) FROM transaction_allocations a
         JOIN transactions t ON t.id=a.transaction_id
         WHERE a.period_key=? AND a.property_id=? AND t.client_id IS NOT NULL AND t.deleted_at IS NULL
           AND t.client_id NOT IN (
               SELECT DISTINCT t2.client_id FROM transaction_allocations ta2
               JOIN transactions t2 ON t2.id=ta2.transaction_id
               WHERE ta2.period_key < ? AND ta2.property_id=? AND t2.client_id IS NOT NULL AND t2.deleted_at IS NULL
           )"
    );
    $s->execute([$period, $pid, $period, $pid]);
    $newClients = (int)$s->fetchColumn();

    // PIC
    $s = $pdo->prepare(
        "SELECT p.name pic_name, COALESCE(p.role_name,'-') role_name, COALESCE(p.target_share,0) target_share,
                COALESCE(SUM(a.amount),0) actual,
                COUNT(DISTINCT t.id) trx_count,
                COUNT(DISTINCT CASE WHEN t.billing_method='spread' THEN t.id END) trx_recurring,
                COUNT(DISTINCT CASE WHEN t.client_id IS NOT NULL AND prev.client_id IS NULL THEN t.client_id END) AS new_clients
         FROM master_pic p
         LEFT JOIN transaction_allocations a ON a.pic_name=p.name AND a.period_key=? AND a.property_id=?
         LEFT JOIN transactions t ON t.id=a.transaction_id AND t.deleted_at IS NULL
         LEFT JOIN (
             SELECT DISTINCT t2.client_id FROM transaction_allocations ta2
             JOIN transactions t2 ON t2.id=ta2.transaction_id
             WHERE ta2.period_key < ? AND ta2.property_id=? AND t2.client_id IS NOT NULL AND t2.deleted_at IS NULL
         ) prev ON prev.client_id=t.client_id
         WHERE p.status='active' AND p.property_id=? AND p.show_achievement = 1 AND p.target_share > 0
         GROUP BY p.id ORDER BY actual DESC"
    );
    $s->execute([$period, $pid, $period, $pid, $pid]);
    $pics = $s->fetchAll();

    // Occupancy per lantai (CL)
    $s = $pdo->prepare(
        "SELECT m.floor AS group_key,
                COUNT(*) unit_count,
                COALESCE(SUM(agg.days_total),0) days_total,
                COALESCE(SUM(COALESCE(pp.potential_value, m.projection_monthly)),0) proj_total,
                COALESCE(SUM(agg.actual_total),0) actual_total,
                COALESCE(SUM(agg.recurring_total),0) recurring_total,
                AVG(CASE WHEN agg.days_total>0 AND agg.actual_total>0
                         THEN agg.actual_total/agg.days_total/m.area_sqm ELSE NULL END) avg_rate
         FROM master_cl_units m
         LEFT JOIN period_potentials pp ON pp.slot_id=m.id AND pp.segment='exhibition' AND pp.period_key=? AND pp.property_id=?
         LEFT JOIN (
             SELECT a.master_code,
                    SUM(a.allocated_days) days_total,
                    SUM(a.amount) actual_total,
                    SUM(CASE WHEN t.billing_method='spread' THEN a.amount ELSE 0 END) recurring_total
             FROM transaction_allocations a
             JOIN transactions t ON t.id=a.transaction_id AND t.deleted_at IS NULL
             WHERE a.module='cl' AND a.period_key=? AND a.property_id=?
             GROUP BY a.master_code
         ) agg ON agg.master_code=m.code
         WHERE m.property_id=? AND m.status='active'
         GROUP BY m.floor
         ORDER BY CASE m.floor WHEN 'LG' THEN 1 WHEN 'GF' THEN 2 WHEN 'UG' THEN 3 WHEN 'FF' THEN 4 WHEN 'SF' THEN 5 ELSE 6 END"
    );
    $s->execute([$period, $pid, $period, $pid, $pid]);
    $floorOcc = $s->fetchAll();

    // Occupancy per jenis (Media)
    $s = $pdo->prepare(
        "SELECT m.media_type AS group_key,
                COUNT(*) unit_count,
                COALESCE(SUM(agg.days_total),0) days_total,
                COALESCE(SUM(COALESCE(pp.potential_value, m.projection_monthly)),0) proj_total,
                COALESCE(SUM(agg.actual_total),0) actual_total,
                COALESCE(SUM(agg.recurring_total),0) recurring_total,
                AVG(CASE WHEN agg.days_total>0 AND agg.actual_total>0
                         THEN agg.actual_total/agg.days_total ELSE NULL END) avg_rate
         FROM master_media m
         LEFT JOIN period_potentials pp ON pp.slot_id=m.id AND pp.segment='media' AND pp.period_key=? AND pp.property_id=?
         LEFT JOIN (
             SELECT a.master_code,
                    SUM(a.allocated_days) days_total,
                    SUM(a.amount) actual_total,
                    SUM(CASE WHEN t.billing_method='spread' THEN a.amount ELSE 0 END) recurring_total
             FROM transaction_allocations a
             JOIN transactions t ON t.id=a.transaction_id AND t.deleted_at IS NULL
             WHERE a.module='media' AND a.period_key=? AND a.property_id=?
             GROUP BY a.master_code
         ) agg ON agg.master_code=m.code
         WHERE m.property_id=? AND m.status='active'
         GROUP BY m.media_type ORDER BY m.media_type"
    );
    $s->execute([$period, $pid, $period, $pid, $pid]);
    $mediaOcc = $s->fetchAll();

    // Occupancy per lokasi (Gudang)
    $s = $pdo->prepare(
        "SELECT m.location AS group_key,
                COUNT(*) unit_count,
                COALESCE(SUM(agg.days_total),0) days_total,
                COALESCE(SUM(COALESCE(pp.potential_value, m.projection_monthly)),0) proj_total,
                COALESCE(SUM(agg.actual_total),0) actual_total,
                COALESCE(SUM(agg.recurring_total),0) recurring_total,
                AVG(CASE WHEN agg.days_total>0 AND agg.actual_total>0
                         THEN agg.actual_total/agg.days_total/m.area_sqm ELSE NULL END) avg_rate
         FROM master_gudang m
         LEFT JOIN period_potentials pp ON pp.slot_id=m.id AND pp.segment='gudang' AND pp.period_key=? AND pp.property_id=?
         LEFT JOIN (
             SELECT a.master_code,
                    SUM(a.allocated_days) days_total,
                    SUM(a.amount) actual_total,
                    SUM(CASE WHEN t.billing_method='spread' THEN a.amount ELSE 0 END) recurring_total
             FROM transaction_allocations a
             JOIN transactions t ON t.id=a.transaction_id AND t.deleted_at IS NULL
             WHERE a.module='gudang' AND a.period_key=? AND a.property_id=?
             GROUP BY a.master_code
         ) agg ON agg.master_code=m.code
         WHERE m.property_id=? AND m.status='active'
         GROUP BY m.location ORDER BY m.location"
    );
    $s->execute([$period, $pid, $period, $pid, $pid]);
    $gudangOcc = $s->fetchAll();

    return [
        'target'      => $target,
        'projection'  => $projection,
        'actual_seg'  => $actualSeg,
        'actual'      => $actual,
        'recurring'   => $recurring,
        'new_clients' => $newClients,
        'pics'        => $pics,
        'floor_occ'   => $floorOcc,
        'media_occ'   => $mediaOcc,
        'gudang_occ'  => $gudangOcc,
    ];
}

function _exec_combine(array $propData): array
{
    $target = $projection = $actual = $recurring = 0;
    foreach ($propData as $d) {
        $target     += $d['target'];
        $projection += array_sum($d['projection']);
        $actual     += $d['actual'];
        $recurring  += $d['recurring'];
    }
    return compact('target', 'projection', 'actual', 'recurring');
}
