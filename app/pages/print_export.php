<?php
declare(strict_types=1);

function print_dashboard(PDO $pdo): void
{
    $period = getv('period', date('Y-m'));

    // — ambil semua data sama seperti dashboard —
    $pid = current_property_id();
    $monthNames = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
    $periodLabel = ($monthNames[substr($period,5,2)] ?? substr($period,5,2)) . ' ' . substr($period,0,4);
    $target = (float)($pdo->query("SELECT target_amount FROM targets_monthly WHERE period_key=".$pdo->quote($period)." AND property_id=$pid")->fetchColumn() ?: 0);
    $projection = [
        'cl'     => (float)$pdo->query("SELECT COALESCE(SUM(projection_monthly),0) FROM master_cl_units WHERE status='active' AND property_id=$pid")->fetchColumn(),
        'media'  => (float)$pdo->query("SELECT COALESCE(SUM(projection_monthly),0) FROM master_media WHERE status='active' AND property_id=$pid")->fetchColumn(),
        'gudang' => (float)$pdo->query("SELECT COALESCE(SUM(projection_monthly),0) FROM master_gudang WHERE status='active' AND property_id=$pid")->fetchColumn(),
    ];
    $actual = $capacity = $allocatedDays = ['cl'=>0,'media'=>0,'gudang'=>0];
    $aStmt = $pdo->prepare('SELECT module, COALESCE(SUM(amount),0) actual, COALESCE(SUM(capacity_days),0) cap, COALESCE(SUM(allocated_days),0) adays FROM transaction_allocations WHERE period_key=? AND property_id=? GROUP BY module');
    $aStmt->execute([$period, $pid]);
    foreach ($aStmt->fetchAll() as $r) {
        $actual[$r['module']]       = (float)$r['actual'];
        $capacity[$r['module']]     = (float)$r['cap'];
        $allocatedDays[$r['module']]= (float)$r['adays'];
    }
    $periodDays     = (int)date('t', strtotime($period.'-01'));
    $totalProjection= array_sum($projection);
    $totalActual    = array_sum($actual);
    $unitCount      = [
        'cl'    =>(int)$pdo->query("SELECT COUNT(*) FROM master_cl_units WHERE status='active' AND property_id=$pid")->fetchColumn(),
        'media' =>(int)$pdo->query("SELECT COUNT(*) FROM master_media WHERE status='active' AND property_id=$pid")->fetchColumn(),
        'gudang'=>(int)$pdo->query("SELECT COUNT(*) FROM master_gudang WHERE status='active' AND property_id=$pid")->fetchColumn(),
    ];

    // PIC
    $picStmt = $pdo->prepare(
        "SELECT p.name pic_name, COALESCE(p.role_name,'-') role_name, COALESCE(p.target_share,0) target_share,
                COALESCE(SUM(a.amount),0) actual,
                COUNT(DISTINCT CASE WHEN t.client_id IS NOT NULL AND prev.client_id IS NULL THEN t.client_id END) AS new_clients
         FROM master_pic p
         LEFT JOIN transaction_allocations a ON a.pic_name=p.name AND a.period_key=? AND a.property_id=?
         LEFT JOIN transactions t ON t.id=a.transaction_id AND t.deleted_at IS NULL
         LEFT JOIN (
             SELECT DISTINCT t2.client_id FROM transaction_allocations ta2
             JOIN transactions t2 ON t2.id=ta2.transaction_id
             WHERE ta2.period_key < ? AND ta2.property_id=? AND t2.client_id IS NOT NULL AND t2.deleted_at IS NULL
         ) prev ON prev.client_id=t.client_id
         WHERE p.status='active' AND p.property_id=? GROUP BY p.id ORDER BY actual DESC"
    );
    $picStmt->execute([$period, $pid, $period, $pid, $pid]);
    $ncPrintStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT t.client_id)
         FROM transaction_allocations a
         JOIN transactions t ON t.id = a.transaction_id
         WHERE a.period_key = ? AND a.property_id=? AND t.client_id IS NOT NULL AND t.deleted_at IS NULL
           AND t.client_id NOT IN (
               SELECT DISTINCT t2.client_id FROM transaction_allocations ta2
               JOIN transactions t2 ON t2.id = ta2.transaction_id
               WHERE ta2.period_key < ? AND ta2.property_id=? AND t2.client_id IS NOT NULL AND t2.deleted_at IS NULL
           )"
    );
    $ncPrintStmt->execute([$period, $pid, $period, $pid]);
    $totalNewClients = (int) $ncPrintStmt->fetchColumn();
    $picRows = $picStmt->fetchAll();

    // Detail CL
    $clStmt = $pdo->prepare("SELECT m.code, m.floor, m.location_name, m.unit_type, m.area_sqm, m.projection_monthly, COALESCE(SUM(a.amount),0) actual, COALESCE(SUM(a.allocated_days),0) days FROM master_cl_units m LEFT JOIN transaction_allocations a ON a.master_code=m.code AND a.module='cl' AND a.period_key=? AND a.property_id=? WHERE m.property_id=? GROUP BY m.id ORDER BY CASE m.floor WHEN 'LG' THEN 1 WHEN 'GF' THEN 2 WHEN 'UG' THEN 3 WHEN 'FF' THEN 4 WHEN 'SF' THEN 5 ELSE 6 END, m.code");
    $clStmt->execute([$period, $pid, $pid]);
    $clByFloor = [];
    foreach ($clStmt->fetchAll() as $r) { $clByFloor[$r['floor']][] = $r; }

    // Detail Media
    $mediaStmt = $pdo->prepare("SELECT m.code, m.media_type, m.location, m.point, m.projection_monthly, COALESCE(SUM(a.amount),0) actual, COALESCE(SUM(a.allocated_days),0) days FROM master_media m LEFT JOIN transaction_allocations a ON a.master_code=m.code AND a.module='media' AND a.period_key=? AND a.property_id=? WHERE m.property_id=? GROUP BY m.id ORDER BY m.code");
    $mediaStmt->execute([$period, $pid, $pid]);
    $mediaByType = [];
    foreach ($mediaStmt->fetchAll() as $r) { $mediaByType[$r['media_type']][] = $r; }

    // Detail Gudang
    $gudangStmt = $pdo->prepare("SELECT m.code, m.location, m.name, m.area_sqm, m.projection_monthly, COALESCE(SUM(a.amount),0) actual, COALESCE(SUM(a.allocated_days),0) days FROM master_gudang m LEFT JOIN transaction_allocations a ON a.master_code=m.code AND a.module='gudang' AND a.period_key=? AND a.property_id=? WHERE m.property_id=? GROUP BY m.id ORDER BY m.code");
    $gudangStmt->execute([$period, $pid, $pid]);
    $gudangByLoc = [];
    foreach ($gudangStmt->fetchAll() as $r) { $gudangByLoc[$r['location']][] = $r; }

    audit($pdo, 'print_dashboard', 'dashboard', $period, ['period'=>$period], [], 'reporting');
    ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Laporan <?= h(env_value('APP_NAME', 'CLARA')) ?> — <?= h($periodLabel) ?></title>
<link rel="icon" type="image/png" href="assets/clara-logo.png">
<style>
@import url() /* removed - using system font */;
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; font-size: 11px; color: #0F1623; background: #fff; }
@page { size: A4 landscape; margin: 14mm 12mm; }

/* print button — tidak ikut tercetak */
.no-print { position: fixed; top: 16px; right: 16px; display: flex; gap: 8px; z-index: 99; }
.no-print button { padding: 9px 18px; border: none; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; }
.btn-print { background: #0D9488; color: #fff; }
.btn-close { background: #f1f5f9; color: #374151; }
@media print { .no-print { display: none; } }

/* header */
.rpt-header { display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 10px; border-bottom: 2px solid #0D9488; margin-bottom: 14px; }
.rpt-brand { font-size: 22px; font-weight: 900; color: #0D9488; letter-spacing: -.5px; }
.rpt-brand small { display: block; font-size: 10px; font-weight: 500; color: #7B8A9C; margin-top: 2px; letter-spacing: 0; }
.rpt-logo { width: 160px; height: auto; display: block; object-fit: contain; }
.rpt-meta { text-align: right; font-size: 12px; color: #7B8A9C; line-height: 1.8; }
.rpt-period { font-size: 18px; font-weight: 800; color: #0F1623; }

/* KPI row */
.kpi-row { display: grid; grid-template-columns: repeat(5,1fr); gap: 10px; margin-bottom: 14px; }
.kpi-box { border: 1px solid #E4E9F0; border-radius: 8px; padding: 10px 12px; }
.kpi-box-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #7B8A9C; }
.kpi-box-value { font-size: 16px; font-weight: 800; margin-top: 4px; color: #0F1623; }
.kpi-box:nth-child(1) { border-top: 3px solid #0D9488; }
.kpi-box:nth-child(2) { border-top: 3px solid #10B981; }
.kpi-box:nth-child(3) { border-top: 3px solid #3B82F6; }
.kpi-box:nth-child(4) { border-top: 3px solid #F59E0B; }
.kpi-box:nth-child(5) { border-top: 3px solid #8B5CF6; }

/* section */
.section { margin-bottom: 14px; }
.section-title { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: #0D9488; padding-bottom: 5px; border-bottom: 1px solid #E4E9F0; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
.section-title::before { content:''; width:3px; height:12px; background:#0D9488; border-radius:99px; display:block; }

/* tables */
table { width: 100%; border-collapse: collapse; font-size: 10px; }
th { background: #F8FAFC; color: #344054; font-weight: 700; text-transform: uppercase; font-size: 9px; letter-spacing: .04em; padding: 6px 8px; border: 1px solid #E4E9F0; text-align: left; white-space: nowrap; }
td { padding: 5px 8px; border: 1px solid #E4E9F0; vertical-align: middle; }
tr.group-hd td { background: #F1F5F9; font-weight: 700; font-size: 9px; text-transform: uppercase; letter-spacing: .05em; color: #334155; border-top: 2px solid #CBD5E1; }
tr.subtotal td { background: #EFF6FF; font-weight: 700; color: #1E40AF; border-top: 1px solid #BFDBFE; }
td.r, th.r { text-align: right; }
td.money { text-align: right; font-weight: 600; }

/* segment inline */
.seg-row { display: grid; grid-template-columns: repeat(3,1fr); gap: 10px; margin-bottom: 14px; }
.seg-box { border: 1px solid #E4E9F0; border-radius: 8px; padding: 10px 12px; }
.seg-label { font-size: 9px; font-weight: 700; text-transform: uppercase; color: #7B8A9C; }
.seg-value { font-size: 13px; font-weight: 800; margin: 3px 0 2px; }
.seg-sub { font-size: 9px; color: #7B8A9C; }
.bar { height: 5px; background: #E4E9F0; border-radius: 99px; margin-top: 5px; overflow: hidden; }
.bar-fill { height: 100%; border-radius: 99px; }

/* page break */
.page-break { page-break-before: always; padding-top: 10px; }

/* footer */
.rpt-footer { margin-top: 14px; padding-top: 8px; border-top: 1px solid #E4E9F0; display: flex; justify-content: space-between; font-size: 9px; color: #7B8A9C; }

/* preserve background colors when printing */
* { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
</style>
</head>
<body>

<!-- PRINT BUTTON -->
<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨 Cetak / Simpan PDF</button>
    <button class="btn-close" onclick="window.close()">✕ Tutup</button>
</div>

<!-- HEADER -->
<div class="rpt-header">
    <div>
        <img class="rpt-logo" src="assets/clara-logo.png" alt="CLARA" onerror="this.hidden=true;this.nextElementSibling.style.display='block'">
        <div class="rpt-brand" style="display:none"><?= h(env_value('APP_NAME', 'CLARA')) ?><small>Casual Leasing Achievement & Revenue Analytics</small></div>
    </div>
    <div class="rpt-meta">
        <div class="rpt-period"><?= h($periodLabel) ?></div>
        <div style="font-size:20px;font-weight:800;color:#0D9488;margin-bottom:2px"><?= h(current_property()['name'] ?? '') ?></div>
        <div>Dicetak: <?= date('d/m/Y H:i:s') ?></div>
        <div>Oleh: <?= h($_SESSION['user']['name'] ?? '-') ?> (<?= h($_SESSION['user']['role'] ?? '-') ?>)</div>
    </div>
</div>

<!-- KPI -->
<div class="kpi-row">
    <div class="kpi-box">
        <div class="kpi-box-label">Potensi</div>
        <div class="kpi-box-value"><?= money($totalProjection) ?></div>
    </div>
    <div class="kpi-box">
        <div class="kpi-box-label">Target</div>
        <div class="kpi-box-value"><?= money($target) ?></div>
    </div>
    <div class="kpi-box">
        <div class="kpi-box-label">Aktual</div>
        <div class="kpi-box-value"<?= $target > 0 && $totalActual < $target ? ' style="color:#dc2626"' : '' ?>><?= money($totalActual) ?></div>
    </div>
    <div class="kpi-box">
        <div class="kpi-box-label">% Achievement vs Target</div>
        <?php $_ach = $target > 0 ? $totalActual / $target : 0; ?>
        <div class="kpi-box-value"<?= $_ach < 1 && $target > 0 ? ' style="color:#dc2626"' : '' ?>><?= pct($_ach) ?></div>
    </div>
    <div class="kpi-box">
        <div class="kpi-box-label">% Aktual vs Potensi</div>
        <div class="kpi-box-value"><?= pct($totalProjection > 0 ? $totalActual / $totalProjection : 0) ?></div>
    </div>
</div>

<!-- SEGMENT -->
<div class="seg-row">
    <?php foreach (['cl'=>['Exhibition','#0D9488'],'media'=>['Media Promo','#0891B2'],'gudang'=>['Gudang / Storage','#F59E0B']] as $key=>[$label,$color]): ?>
    <?php $pctVal = $projection[$key] > 0 ? $actual[$key]/$projection[$key] : 0; ?>
    <div class="seg-box">
        <div class="seg-label"><?= $label ?></div>
        <div class="seg-value" style="color:<?= $color ?>"><?= money($actual[$key]) ?></div>
        <div class="seg-sub">Potensi: <?= money($projection[$key]) ?> &nbsp;|&nbsp; <?= pct($pctVal) ?></div>
        <div class="bar"><div class="bar-fill" style="width:<?= min(round($pctVal*100),100) ?>%;background:<?= $color ?>"></div></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- PIC ACHIEVEMENT -->
<div class="section">
    <div class="section-title">Achievement PIC</div>
    <?php $ncTarget = 5; ?>
    <div style="display:inline-flex;align-items:center;gap:8px;margin-bottom:8px;padding:6px 12px;border-radius:6px;border:1px solid #E4E9F0;background:#F8FAFC">
        <span style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#7B8A9C">Client Baru Bulan Ini</span>
        <span style="font-size:16px;font-weight:800;color:<?= $totalNewClients >= $ncTarget ? '#16a34a' : '#dc2626' ?>"><?= $totalNewClients ?></span>
        <span style="font-size:10px;color:#7B8A9C">/ <?= $ncTarget ?> target</span>
    </div>
    <table>
        <thead><tr><th>PIC</th><th>Posisi</th><th class="r">Target Posisi</th><th class="r">Aktual</th><th class="r">% vs Target</th><th class="r">% thd Target Bulanan</th><th class="r">Client Baru</th></tr></thead>
        <tbody>
        <?php $bottomIdx = count($picRows) - 1; foreach ($picRows as $i => $row):
            $pt = (float)$row['target_share'] * $target;
            $achieved = $pt > 0 && (float)$row['actual'] >= $pt; ?>
        <tr<?= $achieved ? ' style="background:#dcfce7"' : '' ?>>
            <td><?= h($row['pic_name']) ?><?= $i===0 && (float)$row['actual']>0 ? ' 👑' : '' ?><?= ($i===$bottomIdx && count($picRows)>1) ? ' 😢' : '' ?></td>
            <td><?= h($row['role_name']) ?></td>
            <td class="money"><?= money($pt) ?></td>
            <td class="money"><?= money($row['actual']) ?></td>
            <td class="r"><?= pct($pt>0 ? $row['actual']/$pt : 0) ?></td>
            <td class="r"><?= pct($target>0 ? $row['actual']/$target : 0) ?></td>
            <td class="r" style="font-weight:700"><?= (int)$row['new_clients'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- DETAIL CL -->
<div class="section page-break">
    <div class="section-title">Detail Exhibition Per Unit</div>
    <table>
        <thead><tr><th>Kode</th><th>Lantai</th><th>Lokasi</th><th>Tipe</th><th class="r">Area m²</th><th class="r">Potensi</th><th class="r">Hari</th><th class="r">% Occ</th><th class="r">Aktual</th><th class="r">Rate/Hari/m²</th><th class="r">Rate/Bulan/m²</th><th class="r">% vs Potensi</th></tr></thead>
        <tbody>
        <?php foreach ($clByFloor as $floor => $rows):
            $fP = array_sum(array_column($rows,'projection_monthly'));
            $fA = array_sum(array_column($rows,'actual'));
            $fD = array_sum(array_column($rows,'days'));
            $fArea = array_sum(array_column($rows,'area_sqm'));
        ?>
        <tr class="group-hd"><td colspan="12"><?= h($floor) ?></td></tr>
        <?php foreach ($rows as $r): $area=max((float)$r['area_sqm'],1); ?>
        <tr<?= $periodDays > 0 && (int)$r['days'] >= $periodDays ? ' style="background:#dcfce7"' : '' ?>>
            <td><?= h($r['code']) ?></td><td><?= h($r['floor']) ?></td><td><?= h($r['location_name']) ?></td><td><?= h($r['unit_type']??'-') ?></td>
            <td class="r"><?= number_format((float)$r['area_sqm'],1,',','.') ?></td>
            <td class="money"><?= money($r['projection_monthly']) ?></td>
            <td class="r"><?= $r['days'] ?></td>
            <?php $_occ = $periodDays>0 ? (float)$r['days']/$periodDays : 0; ?><td class="r"<?= $_occ > 1 ? ' style="color:#dc2626;font-weight:700"' : '' ?>><?= pct($_occ) ?></td>
            <td class="money"><?= money($r['actual']) ?></td>
            <td class="r"><?= $r['days']>0 ? money($r['actual']/$r['days']/$area) : '-' ?></td>
            <td class="r"><?= money($r['actual']/$periodDays/$area) ?></td>
            <td class="r"><?= pct($r['projection_monthly']>0 ? $r['actual']/$r['projection_monthly'] : 0) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php
        $pRateHariList  = [];
        $pRateBulanList = [];
        foreach ($rows as $_r) {
            $_a = max((float)$_r['area_sqm'], 1);
            if ($_r['days'] > 0) $pRateHariList[] = $_r['actual'] / $_r['days'] / $_a;
            $pRateBulanList[] = $_r['actual'] / $periodDays / $_a;
        }
        $pAvgRateHari  = count($pRateHariList)  > 0 ? array_sum($pRateHariList)  / count($pRateHariList)  : 0;
        $pAvgRateBulan = count($pRateBulanList) > 0 ? array_sum($pRateBulanList) / count($pRateBulanList) : 0;
        ?>
        <tr class="subtotal">
            <td colspan="4">Subtotal <?= h($floor) ?> (<?= count($rows) ?> unit)</td>
            <td class="r"><?= number_format($fArea,1,',','.') ?></td>
            <td class="money"><?= money($fP) ?></td>
            <td class="r"><?= $fD ?></td>
            <?php $_occ = $periodDays*count($rows)>0 ? $fD/($periodDays*count($rows)) : 0; ?><td class="r"<?= $_occ > 1 ? ' style="color:#dc2626;font-weight:700"' : '' ?>><?= pct($_occ) ?></td>
            <td class="money"><?= money($fA) ?></td>
            <td class="r"><?= count($pRateHariList) > 0 ? money($pAvgRateHari) : '-' ?></td>
            <td class="r"><?= money($pAvgRateBulan) ?></td>
            <td class="r"><?= pct($fP>0 ? $fA/$fP : 0) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- DETAIL MEDIA -->
<div class="section page-break">
    <div class="section-title">Detail Media Per Item</div>
    <table>
        <thead><tr><th>Kode</th><th>Jenis</th><th>Lokasi / Titik</th><th class="r">Potensi</th><th class="r">Hari</th><th class="r">% Occ</th><th class="r">Aktual</th><th class="r">Rate/Hari</th><th class="r">Rate/Bulan</th><th class="r">% vs Potensi</th></tr></thead>
        <tbody>
        <?php foreach ($mediaByType as $type => $rows):
            $tP=$tA=$tD=0;
            foreach ($rows as $r){ $tP+=$r['projection_monthly']; $tA+=$r['actual']; $tD+=$r['days']; }
        ?>
        <tr class="group-hd"><td colspan="10"><?= h($type) ?></td></tr>
        <?php foreach ($rows as $r): ?>
        <tr<?= $periodDays > 0 && (int)$r['days'] >= $periodDays ? ' style="background:#dcfce7"' : '' ?>>
            <td><?= h($r['code']) ?></td><td><?= h($r['media_type']) ?></td><td><?= h($r['location'].' - '.$r['point']) ?></td>
            <td class="money"><?= money($r['projection_monthly']) ?></td>
            <td class="r"><?= $r['days'] ?></td>
            <?php $_occ = $periodDays>0 ? (float)$r['days']/$periodDays : 0; ?><td class="r"<?= $_occ > 1 ? ' style="color:#dc2626;font-weight:700"' : '' ?>><?= pct($_occ) ?></td>
            <td class="money"><?= money($r['actual']) ?></td>
            <td class="r"><?= $r['days']>0 ? money($r['actual']/$r['days']) : '-' ?></td>
            <td class="r"><?= money($r['actual']/$periodDays) ?></td>
            <td class="r"><?= pct($r['projection_monthly']>0 ? $r['actual']/$r['projection_monthly'] : 0) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php
        $mRateHariList  = [];
        $mRateBulanList = [];
        foreach ($rows as $_r) {
            if ($_r['days'] > 0) $mRateHariList[] = $_r['actual'] / $_r['days'];
            $mRateBulanList[] = $_r['actual'] / $periodDays;
        }
        $mAvgRateHari  = count($mRateHariList)  > 0 ? array_sum($mRateHariList)  / count($mRateHariList)  : 0;
        $mAvgRateBulan = count($mRateBulanList) > 0 ? array_sum($mRateBulanList) / count($mRateBulanList) : 0;
        ?>
        <tr class="subtotal">
            <td colspan="3">Subtotal <?= h($type) ?> (<?= count($rows) ?> item)</td>
            <td class="money"><?= money($tP) ?></td>
            <td class="r"><?= $tD ?></td>
            <?php $_occ = $periodDays*count($rows)>0 ? $tD/($periodDays*count($rows)) : 0; ?><td class="r"<?= $_occ > 1 ? ' style="color:#dc2626;font-weight:700"' : '' ?>><?= pct($_occ) ?></td>
            <td class="money"><?= money($tA) ?></td>
            <td class="r"><?= count($mRateHariList) > 0 ? money($mAvgRateHari) : '-' ?></td>
            <td class="r"><?= money($mAvgRateBulan) ?></td>
            <td class="r"><?= pct($tP>0 ? $tA/$tP : 0) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- DETAIL GUDANG -->
<div class="section page-break">
    <div class="section-title">Detail Gudang Per Unit</div>
    <table>
        <thead><tr><th>Kode</th><th>Lokasi</th><th>Nama</th><th class="r">Area m²</th><th class="r">Potensi</th><th class="r">Hari</th><th class="r">% Occ</th><th class="r">Aktual</th><th class="r">Rate/Hari/m²</th><th class="r">Rate/Bulan/m²</th><th class="r">% vs Potensi</th></tr></thead>
        <tbody>
        <?php foreach ($gudangByLoc as $loc => $rows):
            $lP=$lA=$lD=$lArea=0;
            foreach ($rows as $r){ $lP+=$r['projection_monthly']; $lA+=$r['actual']; $lD+=$r['days']; $lArea+=$r['area_sqm']; }
            $lAreaSafe=max($lArea,1);
        ?>
        <tr class="group-hd"><td colspan="11"><?= h($loc) ?></td></tr>
        <?php foreach ($rows as $r): $area=max((float)$r['area_sqm'],1); ?>
        <tr<?= $periodDays > 0 && (int)$r['days'] >= $periodDays ? ' style="background:#dcfce7"' : '' ?>>
            <td><?= h($r['code']) ?></td><td><?= h($r['location']) ?></td><td><?= h($r['name']) ?></td>
            <td class="r"><?= number_format((float)$r['area_sqm'],1,',','.') ?></td>
            <td class="money"><?= money($r['projection_monthly']) ?></td>
            <td class="r"><?= $r['days'] ?></td>
            <?php $_occ = $periodDays>0 ? (float)$r['days']/$periodDays : 0; ?><td class="r"<?= $_occ > 1 ? ' style="color:#dc2626;font-weight:700"' : '' ?>><?= pct($_occ) ?></td>
            <td class="money"><?= money($r['actual']) ?></td>
            <td class="r"><?= $r['days']>0 ? money($r['actual']/$r['days']/$area) : '-' ?></td>
            <td class="r"><?= money($r['actual']/$periodDays/$area) ?></td>
            <td class="r"><?= pct($r['projection_monthly']>0 ? $r['actual']/$r['projection_monthly'] : 0) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php
        $gRateHariList  = [];
        $gRateBulanList = [];
        foreach ($rows as $_r) {
            $_a = max((float)$_r['area_sqm'], 1);
            if ($_r['days'] > 0) $gRateHariList[] = $_r['actual'] / $_r['days'] / $_a;
            $gRateBulanList[] = $_r['actual'] / $periodDays / $_a;
        }
        $gAvgRateHari  = count($gRateHariList)  > 0 ? array_sum($gRateHariList)  / count($gRateHariList)  : 0;
        $gAvgRateBulan = count($gRateBulanList) > 0 ? array_sum($gRateBulanList) / count($gRateBulanList) : 0;
        ?>
        <tr class="subtotal">
            <td colspan="3">Subtotal <?= h($loc) ?> (<?= count($rows) ?> unit)</td>
            <td class="r"><?= number_format($lArea,1,',','.') ?></td>
            <td class="money"><?= money($lP) ?></td>
            <td class="r"><?= $lD ?></td>
            <?php $_occ = $periodDays*count($rows)>0 ? $lD/($periodDays*count($rows)) : 0; ?><td class="r"<?= $_occ > 1 ? ' style="color:#dc2626;font-weight:700"' : '' ?>><?= pct($_occ) ?></td>
            <td class="money"><?= money($lA) ?></td>
            <td class="r"><?= count($gRateHariList) > 0 ? money($gAvgRateHari) : '-' ?></td>
            <td class="r"><?= money($gAvgRateBulan) ?></td>
            <td class="r"><?= pct($lP>0 ? $lA/$lP : 0) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- FOOTER -->
<div class="rpt-footer">
    <span><?= h(env_value('APP_NAME', 'CLARA')) ?> — Casual Leasing Achievement & Revenue Analytics</span>
    <span>Laporan Periode <?= h($periodLabel) ?> &nbsp;|&nbsp; Dicetak <?= date('d/m/Y H:i:s') ?></span>
</div>

</body>
</html>
<?php
    exit;
}

function print_exec(PDO $pdo): void
{
    $period = getv('period', date('Y-m'));

    $pid         = current_property_id();
    $monthNames  = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
    $periodLabel = ($monthNames[substr($period,5,2)] ?? substr($period,5,2)) . ' ' . substr($period,0,4);
    $target      = (float)($pdo->query("SELECT target_amount FROM targets_monthly WHERE period_key=".$pdo->quote($period)." AND property_id=$pid")->fetchColumn() ?: 0);
    $projection  = [
        'cl'     => (float)$pdo->query("SELECT COALESCE(SUM(projection_monthly),0) FROM master_cl_units WHERE status='active' AND property_id=$pid")->fetchColumn(),
        'media'  => (float)$pdo->query("SELECT COALESCE(SUM(projection_monthly),0) FROM master_media WHERE status='active' AND property_id=$pid")->fetchColumn(),
        'gudang' => (float)$pdo->query("SELECT COALESCE(SUM(projection_monthly),0) FROM master_gudang WHERE status='active' AND property_id=$pid")->fetchColumn(),
    ];
    $actual = ['cl'=>0,'media'=>0,'gudang'=>0];
    $aStmt  = $pdo->prepare('SELECT module, COALESCE(SUM(amount),0) actual FROM transaction_allocations WHERE period_key=? AND property_id=? GROUP BY module');
    $aStmt->execute([$period, $pid]);
    foreach ($aStmt->fetchAll() as $r) { $actual[$r['module']] = (float)$r['actual']; }
    $periodDays      = (int)date('t', strtotime($period.'-01'));
    $totalProjection = array_sum($projection);
    $totalActual     = array_sum($actual);

    // PIC
    $picStmt = $pdo->prepare(
        "SELECT p.name pic_name, COALESCE(p.role_name,'-') role_name, COALESCE(p.target_share,0) target_share,
                COALESCE(SUM(a.amount),0) actual,
                COUNT(DISTINCT CASE WHEN t.client_id IS NOT NULL AND prev.client_id IS NULL THEN t.client_id END) AS new_clients
         FROM master_pic p
         LEFT JOIN transaction_allocations a ON a.pic_name=p.name AND a.period_key=? AND a.property_id=?
         LEFT JOIN transactions t ON t.id=a.transaction_id AND t.deleted_at IS NULL
         LEFT JOIN (
             SELECT DISTINCT t2.client_id FROM transaction_allocations ta2
             JOIN transactions t2 ON t2.id=ta2.transaction_id
             WHERE ta2.period_key < ? AND ta2.property_id=? AND t2.client_id IS NOT NULL AND t2.deleted_at IS NULL
         ) prev ON prev.client_id=t.client_id
         WHERE p.status='active' AND p.property_id=? GROUP BY p.id ORDER BY actual DESC"
    );
    $picStmt->execute([$period, $pid, $period, $pid, $pid]);
    $ncStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT t.client_id)
         FROM transaction_allocations a
         JOIN transactions t ON t.id = a.transaction_id
         WHERE a.period_key = ? AND a.property_id=? AND t.client_id IS NOT NULL AND t.deleted_at IS NULL
           AND t.client_id NOT IN (
               SELECT DISTINCT t2.client_id FROM transaction_allocations ta2
               JOIN transactions t2 ON t2.id = ta2.transaction_id
               WHERE ta2.period_key < ? AND ta2.property_id=? AND t2.client_id IS NOT NULL AND t2.deleted_at IS NULL
           )"
    );
    $ncStmt->execute([$period, $pid, $period, $pid]);
    $totalNewClients = (int) $ncStmt->fetchColumn();
    $picRows = $picStmt->fetchAll();

    // CL subtotals by floor
    $clStmt = $pdo->prepare(
        "SELECT m.floor, COUNT(*) unit_count, COALESCE(SUM(m.area_sqm),0) area_total,
                COALESCE(SUM(m.projection_monthly),0) proj_total,
                COALESCE(SUM(a.amount),0) actual_total,
                COALESCE(SUM(a.allocated_days),0) days_total
         FROM master_cl_units m
         LEFT JOIN transaction_allocations a ON a.master_code=m.code AND a.module='cl' AND a.period_key=? AND a.property_id=?
         WHERE m.property_id=?
         GROUP BY m.floor
         ORDER BY CASE m.floor WHEN 'LG' THEN 1 WHEN 'GF' THEN 2 WHEN 'UG' THEN 3 WHEN 'FF' THEN 4 WHEN 'SF' THEN 5 ELSE 6 END"
    );
    $clStmt->execute([$period, $pid, $pid]);
    $clFloors = $clStmt->fetchAll();

    // Media subtotals by type
    $mediaStmt = $pdo->prepare(
        "SELECT m.media_type, COUNT(*) item_count,
                COALESCE(SUM(m.projection_monthly),0) proj_total,
                COALESCE(SUM(a.amount),0) actual_total,
                COALESCE(SUM(a.allocated_days),0) days_total
         FROM master_media m
         LEFT JOIN transaction_allocations a ON a.master_code=m.code AND a.module='media' AND a.period_key=? AND a.property_id=?
         WHERE m.property_id=?
         GROUP BY m.media_type ORDER BY m.media_type"
    );
    $mediaStmt->execute([$period, $pid, $pid]);
    $mediaTypes = $mediaStmt->fetchAll();

    // Gudang subtotals by location
    $gudangStmt = $pdo->prepare(
        "SELECT m.location, COUNT(*) unit_count, COALESCE(SUM(m.area_sqm),0) area_total,
                COALESCE(SUM(m.projection_monthly),0) proj_total,
                COALESCE(SUM(a.amount),0) actual_total,
                COALESCE(SUM(a.allocated_days),0) days_total
         FROM master_gudang m
         LEFT JOIN transaction_allocations a ON a.master_code=m.code AND a.module='gudang' AND a.period_key=? AND a.property_id=?
         WHERE m.property_id=?
         GROUP BY m.location ORDER BY m.location"
    );
    $gudangStmt->execute([$period, $pid, $pid]);
    $gudangLocs = $gudangStmt->fetchAll();

    audit($pdo, 'print_exec', 'dashboard', $period, ['period'=>$period], [], 'reporting');

    $occStyle = function(float $occ): string {
        if ($occ > 1.0) return ' style="color:#dc2626;font-weight:700"';
        if ($occ < 0.8) return ' style="color:#dc2626"';
        return '';
    };
    ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Ringkasan Direksi <?= h(env_value('APP_NAME', 'CLARA')) ?> — <?= h($periodLabel) ?></title>
<link rel="icon" type="image/png" href="assets/clara-logo.png">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; font-size: 11px; color: #0F1623; background: #fff; }
@page { size: A4 landscape; margin: 14mm 12mm; }
.no-print { position: fixed; top: 16px; right: 16px; display: flex; gap: 8px; z-index: 99; }
.no-print button { padding: 9px 18px; border: none; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; }
.btn-print { background: #0D9488; color: #fff; }
.btn-close { background: #f1f5f9; color: #374151; }
@media print { .no-print { display: none; } }
.rpt-header { display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 10px; border-bottom: 2px solid #0D9488; margin-bottom: 14px; }
.rpt-brand { font-size: 22px; font-weight: 900; color: #0D9488; letter-spacing: -.5px; }
.rpt-brand small { display: block; font-size: 10px; font-weight: 500; color: #7B8A9C; margin-top: 2px; letter-spacing: 0; }
.rpt-logo { width: 160px; height: auto; display: block; object-fit: contain; }
.rpt-meta { text-align: right; font-size: 12px; color: #7B8A9C; line-height: 1.8; }
.rpt-period { font-size: 18px; font-weight: 800; color: #0F1623; }
.exec-badge { display: inline-block; background: #F59E0B; color: #fff; font-size: 9px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; padding: 2px 8px; border-radius: 4px; margin-bottom: 4px; }
.kpi-row { display: grid; grid-template-columns: repeat(5,1fr); gap: 10px; margin-bottom: 14px; }
.kpi-box { border: 1px solid #E4E9F0; border-radius: 8px; padding: 10px 12px; }
.kpi-box-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #7B8A9C; }
.kpi-box-value { font-size: 16px; font-weight: 800; margin-top: 4px; color: #0F1623; }
.kpi-box:nth-child(1) { border-top: 3px solid #0D9488; }
.kpi-box:nth-child(2) { border-top: 3px solid #10B981; }
.kpi-box:nth-child(3) { border-top: 3px solid #3B82F6; }
.kpi-box:nth-child(4) { border-top: 3px solid #F59E0B; }
.kpi-box:nth-child(5) { border-top: 3px solid #8B5CF6; }
.section { margin-bottom: 14px; }
.section-title { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: #0D9488; padding-bottom: 5px; border-bottom: 1px solid #E4E9F0; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
.section-title::before { content:''; width:3px; height:12px; background:#0D9488; border-radius:99px; display:block; }
.seg-row { display: grid; grid-template-columns: repeat(3,1fr); gap: 10px; margin-bottom: 14px; }
.seg-box { border: 1px solid #E4E9F0; border-radius: 8px; padding: 10px 12px; }
.seg-label { font-size: 9px; font-weight: 700; text-transform: uppercase; color: #7B8A9C; }
.seg-value { font-size: 13px; font-weight: 800; margin: 3px 0 2px; }
.seg-sub { font-size: 9px; color: #7B8A9C; }
.bar { height: 5px; background: #E4E9F0; border-radius: 99px; margin-top: 5px; overflow: hidden; }
.bar-fill { height: 100%; border-radius: 99px; }
table { width: 100%; border-collapse: collapse; font-size: 10px; }
th { background: #F8FAFC; color: #344054; font-weight: 700; text-transform: uppercase; font-size: 9px; letter-spacing: .04em; padding: 6px 8px; border: 1px solid #E4E9F0; text-align: left; white-space: nowrap; }
td { padding: 5px 8px; border: 1px solid #E4E9F0; vertical-align: middle; }
td.r, th.r { text-align: right; }
td.money { text-align: right; font-weight: 600; }
tr.subtotal td { background: #EFF6FF; font-weight: 700; color: #1E40AF; border-top: 1px solid #BFDBFE; }
tr.grand-total td { background: #F0FDFA; font-weight: 800; color: #0F1623; border-top: 2px solid #0D9488; }
.rpt-footer { margin-top: 14px; padding-top: 8px; border-top: 1px solid #E4E9F0; display: flex; justify-content: space-between; font-size: 9px; color: #7B8A9C; }
* { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
</style>
</head>
<body>

<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨 Cetak / Simpan PDF</button>
    <button class="btn-close" onclick="window.close()">✕ Tutup</button>
</div>

<div class="rpt-header">
    <div>
        <img class="rpt-logo" src="assets/clara-logo.png" alt="CLARA" onerror="this.hidden=true;this.nextElementSibling.style.display='block'">
        <div class="rpt-brand" style="display:none"><?= h(env_value('APP_NAME', 'CLARA')) ?><small>Casual Leasing Achievement & Revenue Analytics</small></div>
    </div>
    <div class="rpt-meta">
        <div class="exec-badge">Ringkasan Direksi</div>
        <div class="rpt-period"><?= h($periodLabel) ?></div>
        <div style="font-size:20px;font-weight:800;color:#0D9488;margin-bottom:2px"><?= h(current_property()['name'] ?? '') ?></div>
        <div>Dicetak: <?= date('d/m/Y H:i:s') ?></div>
        <div>Oleh: <?= h($_SESSION['user']['name'] ?? '-') ?> (<?= h($_SESSION['user']['role'] ?? '-') ?>)</div>
    </div>
</div>

<!-- KPI -->
<div class="kpi-row">
    <div class="kpi-box">
        <div class="kpi-box-label">Potensi</div>
        <div class="kpi-box-value"><?= money($totalProjection) ?></div>
    </div>
    <div class="kpi-box">
        <div class="kpi-box-label">Target</div>
        <div class="kpi-box-value"><?= money($target) ?></div>
    </div>
    <div class="kpi-box">
        <div class="kpi-box-label">Aktual</div>
        <div class="kpi-box-value"<?= $target > 0 && $totalActual < $target ? ' style="color:#dc2626"' : '' ?>><?= money($totalActual) ?></div>
    </div>
    <div class="kpi-box">
        <div class="kpi-box-label">% Achievement vs Target</div>
        <?php $_ach = $target > 0 ? $totalActual / $target : 0; ?>
        <div class="kpi-box-value"<?= $_ach < 1 && $target > 0 ? ' style="color:#dc2626"' : '' ?>><?= pct($_ach) ?></div>
    </div>
    <div class="kpi-box">
        <div class="kpi-box-label">% Aktual vs Potensi</div>
        <div class="kpi-box-value"><?= pct($totalProjection > 0 ? $totalActual / $totalProjection : 0) ?></div>
    </div>
</div>

<!-- SEGMENT -->
<div class="seg-row">
    <?php foreach (['cl'=>['Exhibition','#0D9488'],'media'=>['Media Promo','#0891B2'],'gudang'=>['Gudang / Storage','#F59E0B']] as $key=>[$label,$color]): ?>
    <?php $pctVal = $projection[$key] > 0 ? $actual[$key]/$projection[$key] : 0; ?>
    <div class="seg-box">
        <div class="seg-label"><?= $label ?></div>
        <div class="seg-value" style="color:<?= $color ?>"><?= money($actual[$key]) ?></div>
        <div class="seg-sub">Potensi: <?= money($projection[$key]) ?> &nbsp;|&nbsp; <?= pct($pctVal) ?></div>
        <div class="bar"><div class="bar-fill" style="width:<?= min(round($pctVal*100),100) ?>%;background:<?= $color ?>"></div></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- CL SUMMARY BY FLOOR -->
<div class="section">
    <div class="section-title">Exhibition per Lantai</div>
    <table>
        <thead><tr><th>Lantai</th><th class="r">Unit</th><th class="r">Area m²</th><th class="r">Potensi</th><th class="r">Hari</th><th class="r">% Occ</th><th class="r">Aktual</th><th class="r">% vs Potensi</th></tr></thead>
        <tbody>
        <?php $gtCU=$gtCA=$gtCP=$gtCAct=$gtCD=0; foreach ($clFloors as $r):
            $gtCU+=(int)$r['unit_count']; $gtCA+=(float)$r['area_total']; $gtCP+=(float)$r['proj_total']; $gtCAct+=(float)$r['actual_total']; $gtCD+=(float)$r['days_total'];
            $_occ=$periodDays*(int)$r['unit_count']>0?(float)$r['days_total']/($periodDays*(int)$r['unit_count']):0; ?>
        <tr>
            <td><?= h($r['floor']) ?></td>
            <td class="r"><?= (int)$r['unit_count'] ?></td>
            <td class="r"><?= number_format((float)$r['area_total'],1,',','.') ?></td>
            <td class="money"><?= money($r['proj_total']) ?></td>
            <td class="r"><?= (int)$r['days_total'] ?></td>
            <td class="r"<?= $occStyle($_occ) ?>><?= pct($_occ) ?></td>
            <td class="money"><?= money($r['actual_total']) ?></td>
            <td class="r"><?= pct((float)$r['proj_total']>0?(float)$r['actual_total']/(float)$r['proj_total']:0) ?></td>
        </tr>
        <?php endforeach; $_occ=$periodDays*$gtCU>0?$gtCD/($periodDays*$gtCU):0; ?>
        <tr class="grand-total">
            <td>TOTAL</td><td class="r"><?= $gtCU ?></td><td class="r"><?= number_format($gtCA,1,',','.') ?></td>
            <td class="money"><?= money($gtCP) ?></td><td class="r"><?= (int)$gtCD ?></td>
            <td class="r"<?= $occStyle($_occ) ?>><?= pct($_occ) ?></td>
            <td class="money"><?= money($gtCAct) ?></td>
            <td class="r"><?= pct($gtCP>0?$gtCAct/$gtCP:0) ?></td>
        </tr>
        </tbody>
    </table>
</div>

<!-- MEDIA SUMMARY BY TYPE -->
<div class="section">
    <div class="section-title">Media Promo per Jenis</div>
    <table>
        <thead><tr><th>Jenis Media</th><th class="r">Item</th><th class="r">Potensi</th><th class="r">Hari</th><th class="r">% Occ</th><th class="r">Aktual</th><th class="r">% vs Potensi</th></tr></thead>
        <tbody>
        <?php $gtMI=$gtMP=$gtMAct=$gtMD=0; foreach ($mediaTypes as $r):
            $gtMI+=(int)$r['item_count']; $gtMP+=(float)$r['proj_total']; $gtMAct+=(float)$r['actual_total']; $gtMD+=(float)$r['days_total'];
            $_occ=$periodDays*(int)$r['item_count']>0?(float)$r['days_total']/($periodDays*(int)$r['item_count']):0; ?>
        <tr>
            <td><?= h($r['media_type']) ?></td>
            <td class="r"><?= (int)$r['item_count'] ?></td>
            <td class="money"><?= money($r['proj_total']) ?></td>
            <td class="r"><?= (int)$r['days_total'] ?></td>
            <td class="r"<?= $occStyle($_occ) ?>><?= pct($_occ) ?></td>
            <td class="money"><?= money($r['actual_total']) ?></td>
            <td class="r"><?= pct((float)$r['proj_total']>0?(float)$r['actual_total']/(float)$r['proj_total']:0) ?></td>
        </tr>
        <?php endforeach; $_occ=$periodDays*$gtMI>0?$gtMD/($periodDays*$gtMI):0; ?>
        <tr class="grand-total">
            <td>TOTAL</td><td class="r"><?= $gtMI ?></td>
            <td class="money"><?= money($gtMP) ?></td><td class="r"><?= (int)$gtMD ?></td>
            <td class="r"<?= $occStyle($_occ) ?>><?= pct($_occ) ?></td>
            <td class="money"><?= money($gtMAct) ?></td>
            <td class="r"><?= pct($gtMP>0?$gtMAct/$gtMP:0) ?></td>
        </tr>
        </tbody>
    </table>
</div>

<!-- GUDANG SUMMARY BY LOCATION -->
<div class="section">
    <div class="section-title">Gudang / Storage per Lokasi</div>
    <table>
        <thead><tr><th>Lokasi</th><th class="r">Unit</th><th class="r">Area m²</th><th class="r">Potensi</th><th class="r">Hari</th><th class="r">% Occ</th><th class="r">Aktual</th><th class="r">% vs Potensi</th></tr></thead>
        <tbody>
        <?php $gtGU=$gtGA=$gtGP=$gtGAct=$gtGD=0; foreach ($gudangLocs as $r):
            $gtGU+=(int)$r['unit_count']; $gtGA+=(float)$r['area_total']; $gtGP+=(float)$r['proj_total']; $gtGAct+=(float)$r['actual_total']; $gtGD+=(float)$r['days_total'];
            $_occ=$periodDays*(int)$r['unit_count']>0?(float)$r['days_total']/($periodDays*(int)$r['unit_count']):0; ?>
        <tr>
            <td><?= h($r['location']) ?></td>
            <td class="r"><?= (int)$r['unit_count'] ?></td>
            <td class="r"><?= number_format((float)$r['area_total'],1,',','.') ?></td>
            <td class="money"><?= money($r['proj_total']) ?></td>
            <td class="r"><?= (int)$r['days_total'] ?></td>
            <td class="r"<?= $occStyle($_occ) ?>><?= pct($_occ) ?></td>
            <td class="money"><?= money($r['actual_total']) ?></td>
            <td class="r"><?= pct((float)$r['proj_total']>0?(float)$r['actual_total']/(float)$r['proj_total']:0) ?></td>
        </tr>
        <?php endforeach; $_occ=$periodDays*$gtGU>0?$gtGD/($periodDays*$gtGU):0; ?>
        <tr class="grand-total">
            <td>TOTAL</td><td class="r"><?= $gtGU ?></td><td class="r"><?= number_format($gtGA,1,',','.') ?></td>
            <td class="money"><?= money($gtGP) ?></td><td class="r"><?= (int)$gtGD ?></td>
            <td class="r"<?= $occStyle($_occ) ?>><?= pct($_occ) ?></td>
            <td class="money"><?= money($gtGAct) ?></td>
            <td class="r"><?= pct($gtGP>0?$gtGAct/$gtGP:0) ?></td>
        </tr>
        </tbody>
    </table>
</div>

<!-- PIC ACHIEVEMENT -->
<div class="section">
    <div class="section-title">Achievement PIC</div>
    <?php $ncTarget = 5; ?>
    <div style="display:inline-flex;align-items:center;gap:8px;margin-bottom:8px;padding:6px 12px;border-radius:6px;border:1px solid #E4E9F0;background:#F8FAFC">
        <span style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#7B8A9C">Client Baru Bulan Ini</span>
        <span style="font-size:16px;font-weight:800;color:<?= $totalNewClients >= $ncTarget ? '#16a34a' : '#dc2626' ?>"><?= $totalNewClients ?></span>
        <span style="font-size:10px;color:#7B8A9C">/ <?= $ncTarget ?> target</span>
    </div>
    <table>
        <thead><tr><th>PIC</th><th>Posisi</th><th class="r">Target Posisi</th><th class="r">Aktual</th><th class="r">% vs Target</th><th class="r">% thd Target Bulanan</th><th class="r">Client Baru</th></tr></thead>
        <tbody>
        <?php $bottomIdx = count($picRows) - 1; foreach ($picRows as $i => $row):
            $pt = (float)$row['target_share'] * $target;
            $achieved = $pt > 0 && (float)$row['actual'] >= $pt; ?>
        <tr<?= $achieved ? ' style="background:#dcfce7"' : '' ?>>
            <td><?= h($row['pic_name']) ?><?= $i===0 && (float)$row['actual']>0 ? ' 👑' : '' ?><?= ($i===$bottomIdx && count($picRows)>1) ? ' 😢' : '' ?></td>
            <td><?= h($row['role_name']) ?></td>
            <td class="money"><?= money($pt) ?></td>
            <td class="money"><?= money($row['actual']) ?></td>
            <td class="r"><?= pct($pt>0 ? $row['actual']/$pt : 0) ?></td>
            <td class="r"><?= pct($target>0 ? $row['actual']/$target : 0) ?></td>
            <td class="r" style="font-weight:700"><?= (int)$row['new_clients'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="rpt-footer">
    <span><?= h(env_value('APP_NAME', 'CLARA')) ?> — Ringkasan Direksi</span>
    <span>Laporan Periode <?= h($periodLabel) ?> &nbsp;|&nbsp; Dicetak <?= date('d/m/Y H:i:s') ?></span>
</div>

</body>
</html>
<?php
    exit;
}

function print_exec_summary(PDO $pdo): void
{
    require_permission('view_exec_summary');

    $period = getv('period', date('Y-m'));
    $monthNames = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni',
                   '07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
    $periodLabel = ($monthNames[substr($period,5,2)] ?? substr($period,5,2)) . ' ' . substr($period,0,4);
    $periodDays  = (int)date('t', strtotime($period.'-01'));

    // Load all active properties
    $properties = $pdo->query("SELECT id, name FROM properties WHERE status='active' ORDER BY id")->fetchAll();
    if (empty($properties)) { http_response_code(404); exit('Tidak ada properti aktif.'); }

    // Per-property data (reuse same logic as exec_dashboard)
    $propData = [];
    foreach ($properties as $prop) {
        $pid = (int)$prop['id'];
        $d = [];

        // Target
        $s = $pdo->prepare("SELECT target_amount FROM targets_monthly WHERE period_key=? AND property_id=?");
        $s->execute([$period, $pid]);
        $d['target'] = (float)($s->fetchColumn() ?: 0);

        // Projection per segment
        $d['projection'] = [];
        foreach (['cl'=>'master_cl_units','media'=>'master_media','gudang'=>'master_gudang'] as $seg=>$tbl) {
            $s = $pdo->prepare("SELECT COALESCE(SUM(projection_monthly),0) FROM $tbl WHERE status='active' AND property_id=?");
            $s->execute([$pid]);
            $d['projection'][$seg] = (float)$s->fetchColumn();
        }

        // Actual per segment
        $s = $pdo->prepare("SELECT module, COALESCE(SUM(amount),0) actual FROM transaction_allocations WHERE period_key=? AND property_id=? GROUP BY module");
        $s->execute([$period, $pid]);
        $d['actual_seg'] = ['cl'=>0,'media'=>0,'gudang'=>0];
        foreach ($s->fetchAll() as $r) { $d['actual_seg'][$r['module']] = (float)$r['actual']; }
        $d['actual'] = array_sum($d['actual_seg']);

        // New clients
        $s = $pdo->prepare(
            "SELECT COUNT(DISTINCT t.client_id) FROM transaction_allocations a
             JOIN transactions t ON t.id=a.transaction_id
             WHERE a.period_key=? AND a.property_id=? AND t.client_id IS NOT NULL AND t.deleted_at IS NULL
               AND t.client_id NOT IN (
                   SELECT DISTINCT t2.client_id FROM transaction_allocations ta2
                   JOIN transactions t2 ON t2.id=ta2.transaction_id
                   WHERE ta2.period_key<? AND ta2.property_id=? AND t2.client_id IS NOT NULL AND t2.deleted_at IS NULL)"
        );
        $s->execute([$period, $pid, $period, $pid]);
        $d['new_clients'] = (int)$s->fetchColumn();

        // PIC
        $s = $pdo->prepare(
            "SELECT p.name pic_name, COALESCE(p.role_name,'-') role_name, COALESCE(p.target_share,0) target_share,
                    COALESCE(SUM(a.amount),0) actual,
                    COUNT(DISTINCT CASE WHEN t.client_id IS NOT NULL AND prev.client_id IS NULL THEN t.client_id END) AS new_clients
             FROM master_pic p
             LEFT JOIN transaction_allocations a ON a.pic_name=p.name AND a.period_key=? AND a.property_id=?
             LEFT JOIN transactions t ON t.id=a.transaction_id AND t.deleted_at IS NULL
             LEFT JOIN (
                 SELECT DISTINCT t2.client_id FROM transaction_allocations ta2
                 JOIN transactions t2 ON t2.id=ta2.transaction_id
                 WHERE ta2.period_key<? AND ta2.property_id=? AND t2.client_id IS NOT NULL AND t2.deleted_at IS NULL
             ) prev ON prev.client_id=t.client_id
             WHERE p.status='active' AND p.property_id=? GROUP BY p.id ORDER BY actual DESC"
        );
        $s->execute([$period, $pid, $period, $pid, $pid]);
        $d['pics'] = $s->fetchAll();

        // Floor occ (CL)
        $s = $pdo->prepare(
            "SELECT m.floor AS group_key, COUNT(*) unit_count,
                    COALESCE(SUM(a.allocated_days),0) days_total,
                    COALESCE(SUM(CASE WHEN COALESCE(a.allocated_days,0)>0 THEN m.area_sqm ELSE 0 END),0) area_total,
                    COALESCE(SUM(m.projection_monthly),0) proj_total,
                    COALESCE(SUM(a.amount),0) actual_total,
                    AVG(CASE WHEN COALESCE(a.allocated_days,0)>0 AND COALESCE(a.amount,0)>0 AND t.id IS NOT NULL
                             THEN a.amount/a.allocated_days/m.area_sqm ELSE NULL END) avg_rate
             FROM master_cl_units m
             LEFT JOIN transaction_allocations a ON a.master_code=m.code AND a.module='cl' AND a.period_key=? AND a.property_id=?
             LEFT JOIN transactions t ON t.id=a.transaction_id AND t.deleted_at IS NULL
             WHERE m.property_id=? AND m.status='active' GROUP BY m.floor"
        );
        $s->execute([$period, $pid, $pid]);
        $d['floor_occ'] = $s->fetchAll();

        // Media occ
        $s = $pdo->prepare(
            "SELECT m.media_type AS group_key, COUNT(*) unit_count,
                    COALESCE(SUM(a.allocated_days),0) days_total,
                    0 AS area_total,
                    COALESCE(SUM(m.projection_monthly),0) proj_total,
                    COALESCE(SUM(a.amount),0) actual_total,
                    AVG(CASE WHEN COALESCE(a.allocated_days,0)>0 AND COALESCE(a.amount,0)>0 AND t.id IS NOT NULL
                             THEN a.amount/a.allocated_days ELSE NULL END) avg_rate
             FROM master_media m
             LEFT JOIN transaction_allocations a ON a.master_code=m.code AND a.module='media' AND a.period_key=? AND a.property_id=?
             LEFT JOIN transactions t ON t.id=a.transaction_id AND t.deleted_at IS NULL
             WHERE m.property_id=? AND m.status='active' GROUP BY m.media_type ORDER BY m.media_type"
        );
        $s->execute([$period, $pid, $pid]);
        $d['media_occ'] = $s->fetchAll();

        // Gudang occ
        $s = $pdo->prepare(
            "SELECT m.location AS group_key, COUNT(*) unit_count,
                    COALESCE(SUM(a.allocated_days),0) days_total,
                    COALESCE(SUM(CASE WHEN COALESCE(a.allocated_days,0)>0 THEN m.area_sqm ELSE 0 END),0) area_total,
                    COALESCE(SUM(m.projection_monthly),0) proj_total,
                    COALESCE(SUM(a.amount),0) actual_total,
                    AVG(CASE WHEN COALESCE(a.allocated_days,0)>0 AND COALESCE(a.amount,0)>0 AND t.id IS NOT NULL
                             THEN a.amount/m.area_sqm ELSE NULL END) avg_rate
             FROM master_gudang m
             LEFT JOIN transaction_allocations a ON a.master_code=m.code AND a.module='gudang' AND a.period_key=? AND a.property_id=?
             LEFT JOIN transactions t ON t.id=a.transaction_id AND t.deleted_at IS NULL
             WHERE m.property_id=? AND m.status='active' GROUP BY m.location ORDER BY m.location"
        );
        $s->execute([$period, $pid, $pid]);
        $d['gudang_occ'] = $s->fetchAll();

        $d['name'] = $prop['name'];
        $d['id']   = $pid;
        $propData[$pid] = $d;
    }

    // Combined totals
    $combTarget = $combProjection = $combActual = 0;
    foreach ($propData as $d) {
        $combTarget     += $d['target'];
        $combProjection += array_sum($d['projection']);
        $combActual     += $d['actual'];
    }

    // Floor canonical sort
    $floorOrder = ['LG'=>1,'GF'=>2,'UG'=>3,'FF'=>4,'SF'=>5];
    $floorSorter = fn($a,$b) => ($floorOrder[$a]??99) <=> ($floorOrder[$b]??99);

    $occStyle = function(float $occ): string {
        if ($occ >= 1.0) return 'color:#16a34a;font-weight:800';
        if ($occ >= 0.8) return 'color:#d97706;font-weight:700';
        if ($occ < 0.5)  return 'color:#dc2626;font-weight:700';
        return 'color:#ca8a04';
    };

    $propColors = ['#0d9488','#7c3aed','#0891b2','#d97706'];

    audit($pdo, 'print_exec_summary', 'exec_dashboard', $period, ['period'=>$period], [], 'reporting');
    ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Executive Summary <?= h(env_value('APP_NAME','CLARA')) ?> — <?= h($periodLabel) ?></title>
<link rel="icon" type="image/png" href="assets/clara-logo.png">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;font-size:10px;color:#0f1623;background:#fff}
@page{size:A4 landscape;margin:12mm 10mm}
.no-print{position:fixed;top:12px;right:12px;display:flex;gap:8px;z-index:99}
.no-print button{padding:8px 16px;border:none;border-radius:7px;font-weight:700;font-size:12px;cursor:pointer}
.btn-print{background:#0d9488;color:#fff}
.btn-close{background:#f1f5f9;color:#374151}
@media print{.no-print{display:none}}
.rpt-header{display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:10px;border-bottom:3px solid #0d9488;margin-bottom:14px}
.rpt-brand{font-size:20px;font-weight:900;color:#0d9488;letter-spacing:-.5px}
.rpt-brand small{display:block;font-size:9px;font-weight:500;color:#7b8a9c;margin-top:1px;letter-spacing:0}
.rpt-logo{width:140px;height:auto;display:block;object-fit:contain}
.rpt-meta{text-align:right;font-size:11px;color:#7b8a9c;line-height:1.8}
.rpt-period{font-size:16px;font-weight:800;color:#0f1623}
.exec-badge{display:inline-block;background:#f59e0b;color:#fff;font-size:8px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;padding:2px 7px;border-radius:4px;margin-bottom:3px}
/* Combined KPI */
.kpi-row{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:12px}
.kpi-box{border:1px solid #e4e9f0;border-radius:7px;padding:8px 10px}
.kpi-box-label{font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#7b8a9c}
.kpi-box-value{font-size:15px;font-weight:800;margin-top:3px;color:#0f1623}
.kpi-box:nth-child(1){border-top:3px solid #0d9488}
.kpi-box:nth-child(2){border-top:3px solid #3b82f6}
.kpi-box:nth-child(3){border-top:3px solid #10b981}
.kpi-box:nth-child(4){border-top:3px solid #f59e0b}
.kpi-box:nth-child(5){border-top:3px solid #8b5cf6}
/* Per-property KPI grid */
.prop-grid{display:grid;grid-template-columns:repeat(<?= count($propData) ?>,1fr);gap:10px;margin-bottom:12px}
.prop-card{border:1px solid #e4e9f0;border-radius:8px;padding:10px 12px;position:relative;overflow:hidden}
.prop-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px}
<?php foreach ($propData as $i=>$d): $ci=array_search($i,array_keys($propData))+1; ?>
.prop-card.pc<?= $ci ?>::before{background:<?= $propColors[$ci-1]??'#64748b' ?>}
<?php endforeach; ?>
.prop-name{font-size:10px;font-weight:800;text-transform:uppercase;color:#7b8a9c;letter-spacing:.04em;margin-bottom:6px}
.prop-kpi-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:5px;margin-bottom:6px}
.prop-kpi{background:#f8fafc;border-radius:5px;padding:6px 8px}
.prop-kpi-label{font-size:8px;font-weight:700;text-transform:uppercase;color:#94a3b8;letter-spacing:.04em}
.prop-kpi-value{font-size:13px;font-weight:800;margin-top:2px}
.ach-pill{display:inline-block;padding:1px 6px;border-radius:999px;font-size:9px;font-weight:700}
.ach-good{background:#dcfce7;color:#15803d}
.ach-warn{background:#fef3c7;color:#92400e}
.ach-bad{background:#fee2e2;color:#b91c1c}
/* Segment comparison */
.section{margin-bottom:12px}
.section-title{font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#0d9488;padding-bottom:4px;border-bottom:1px solid #e4e9f0;margin-bottom:7px;display:flex;align-items:center;gap:5px}
.section-title::before{content:'';width:2px;height:10px;background:#0d9488;border-radius:99px;display:block}
/* Tables */
table{width:100%;border-collapse:collapse;font-size:9px}
th{background:#f8fafc;color:#344054;font-weight:700;text-transform:uppercase;font-size:8px;letter-spacing:.03em;padding:5px 7px;border:1px solid #e4e9f0;text-align:left;white-space:nowrap}
td{padding:4px 7px;border:1px solid #e4e9f0;vertical-align:middle}
td.r,th.r{text-align:right}
td.money{text-align:right;font-weight:600}
tr.grand-total td{background:#f0fdfa;font-weight:800;color:#0f1623;border-top:2px solid #0d9488}
tr.row-dimmed td{color:#94a3b8}
/* Occ comparison — per-property columns side by side */
.occ-wrap{display:grid;grid-template-columns:repeat(<?= count($propData) ?>,1fr);gap:10px;margin-bottom:12px}
.occ-card{border:1px solid #e4e9f0;border-radius:7px;padding:8px 10px}
.occ-prop-name{font-size:9px;font-weight:800;text-transform:uppercase;color:#7b8a9c;margin-bottom:5px;letter-spacing:.04em}
.occ-card table{table-layout:fixed;font-size:8px}
.rpt-footer{margin-top:12px;padding-top:6px;border-top:1px solid #e4e9f0;display:flex;justify-content:space-between;font-size:8px;color:#7b8a9c}
*{-webkit-print-color-adjust:exact;print-color-adjust:exact}
</style>
</head>
<body>

<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨 Cetak / Simpan PDF</button>
    <button class="btn-close" onclick="window.close()">✕ Tutup</button>
</div>

<!-- HEADER -->
<div class="rpt-header">
    <div>
        <img class="rpt-logo" src="assets/clara-logo.png" alt="CLARA" onerror="this.hidden=true;this.nextElementSibling.style.display='block'">
        <div class="rpt-brand" style="display:none"><?= h(env_value('APP_NAME','CLARA')) ?><small>Casual Leasing Achievement & Revenue Analytics</small></div>
    </div>
    <div class="rpt-meta">
        <div class="exec-badge">Executive Summary</div>
        <div class="rpt-period"><?= h($periodLabel) ?></div>
        <div style="font-size:11px;font-weight:700;color:#0d9488;margin-bottom:1px">
            <?= implode(' &amp; ', array_map(fn($d)=>h($d['name']), $propData)) ?>
        </div>
        <div>Dicetak: <?= date('d/m/Y H:i:s') ?></div>
        <div>Oleh: <?= h($_SESSION['user']['name'] ?? '-') ?> (<?= h($_SESSION['user']['role'] ?? '-') ?>)</div>
    </div>
</div>

<!-- COMBINED KPI -->
<?php
$combAch    = $combTarget > 0 ? $combActual / $combTarget : 0;
$combPotAch = $combProjection > 0 ? $combActual / $combProjection : 0;

?>
<div class="kpi-row">
    <div class="kpi-box"><div class="kpi-box-label">Total Potensi</div><div class="kpi-box-value"><?= money($combProjection) ?></div></div>
    <div class="kpi-box"><div class="kpi-box-label">Total Target</div><div class="kpi-box-value"><?= money($combTarget) ?></div></div>
    <div class="kpi-box"><div class="kpi-box-label">Total Aktual</div>
        <div class="kpi-box-value" style="<?= $combAch < 1 && $combTarget > 0 ? 'color:#dc2626' : '' ?>"><?= money($combActual) ?></div>
    </div>
    <div class="kpi-box"><div class="kpi-box-label">Achievement vs Target</div>
        <div class="kpi-box-value"><?php $cls=$combAch>=1?'ach-good':($combAch>=.8?'ach-warn':'ach-bad'); ?>
            <span class="ach-pill <?= $cls ?>" style="font-size:14px"><?= pct($combAch) ?></span>
        </div>
    </div>
    <div class="kpi-box"><div class="kpi-box-label">Achievement vs Potensi</div>
        <div class="kpi-box-value"><?= pct($combPotAch) ?></div>
    </div>
</div>
<!-- PER-PROPERTY CARDS -->
<div class="section">
    <div class="section-title">Per Properti</div>
    <div class="prop-grid">
    <?php foreach ($propData as $idx => $d):
        $ci   = array_search($idx, array_keys($propData)) + 1;
        $ach  = $d['target'] > 0 ? $d['actual'] / $d['target'] : 0;
        $achC = $ach >= 1 ? '#16a34a' : ($ach >= .8 ? '#d97706' : '#dc2626');
        $achPill = $ach >= 1 ? 'ach-good' : ($ach >= .8 ? 'ach-warn' : 'ach-bad');
    ?>
    <div class="prop-card pc<?= $ci ?>">
        <div class="prop-name"><?= h($d['name']) ?></div>
        <div class="prop-kpi-grid">
            <div class="prop-kpi"><div class="prop-kpi-label">Potensi</div><div class="prop-kpi-value"><?= money(array_sum($d['projection'])) ?></div></div>
            <div class="prop-kpi"><div class="prop-kpi-label">Target</div><div class="prop-kpi-value"><?= money($d['target']) ?></div></div>
            <div class="prop-kpi"><div class="prop-kpi-label">Aktual</div><div class="prop-kpi-value" style="color:<?= $achC ?>"><?= money($d['actual']) ?></div></div>
            <div class="prop-kpi"><div class="prop-kpi-label">Achievement</div>
                <div class="prop-kpi-value"><span class="ach-pill <?= $achPill ?>"><?= pct($ach) ?></span></div>
            </div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:9px;color:#7b8a9c">
            <span>Sisa: <strong style="color:<?= $d['actual']>=$d['target']?'#16a34a':'#c2410c' ?>"><?= money(abs($d['target']-$d['actual'])) ?></strong></span>
            <span>Client Baru: <strong style="color:#0f1623"><?= $d['new_clients'] ?></strong></span>
        </div>
        <!-- OCC KPI per segmen -->
        <?php
        $segOccMap = ['cl'=>['floor_occ','Exhibition','#0d9488'],'media'=>['media_occ','Media Promo','#0891b2'],'gudang'=>['gudang_occ','Gudang','#f59e0b']];
        ?>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:4px;margin-top:7px;margin-bottom:4px">
        <?php foreach($segOccMap as $seg=>[$occKey,$lbl,$col]):
            $segDays = $segUnits = 0;
            foreach ($d[$occKey] as $r) { $segDays += $r['days_total']; $segUnits += $r['unit_count']; }
            $segOcc = $segUnits*$periodDays > 0 ? $segDays/($segUnits*$periodDays) : 0;
            $occClr = $segOcc>=1?'#16a34a':($segOcc>=.8?'#d97706':'#dc2626');
        ?>
            <div style="background:#f8fafc;border-radius:5px;padding:5px 6px;border-top:2px solid <?= $col ?>">
                <div style="font-size:7px;font-weight:700;text-transform:uppercase;color:#94a3b8;letter-spacing:.04em"><?= $lbl ?></div>
                <div style="font-size:12px;font-weight:800;color:<?= $occClr ?>;margin-top:2px"><?= number_format($segOcc*100,1,',','.').'%' ?></div>
                <div style="font-size:7px;color:#94a3b8;margin-top:1px">Occupancy Rate</div>
            </div>
        <?php endforeach; ?>
        </div>
        <!-- Segment bars -->
        <?php foreach(['cl'=>['Exhibition','#0d9488'],'media'=>['Media','#0891b2'],'gudang'=>['Gudang','#f59e0b']] as $seg=>[$lbl,$col]):
            $sp = $d['projection'][$seg] > 0 ? min($d['actual_seg'][$seg]/$d['projection'][$seg],1) : 0;
        ?>
        <div style="margin-top:5px">
            <div style="display:flex;justify-content:space-between;font-size:8px;margin-bottom:2px">
                <span style="font-weight:700;color:#344054"><?= $lbl ?></span>
                <span style="color:#7b8a9c"><?= money($d['actual_seg'][$seg]) ?> <?= pct($sp) ?></span>
            </div>
            <div style="height:4px;background:#f1f5f9;border-radius:999px;overflow:hidden">
                <div style="height:100%;width:<?= number_format($sp*100,1,'.','.') ?>%;background:<?= $col ?>;border-radius:999px"></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<!-- SEGMENT COMPARISON -->
<div class="section">
    <div class="section-title">Perbandingan Segment</div>
    <table>
        <thead>
            <tr>
                <th style="width:100px">Segment</th>
                <?php foreach($propData as $d): ?>
                <th class="r"><?= h($d['name']) ?><br><span style="font-weight:400;text-transform:none">Potensi / Aktual / %</span></th>
                <?php endforeach; ?>
                <th class="r" style="background:#f0fdf4">Gabungan<br><span style="font-weight:400;text-transform:none">Aktual / %</span></th>
            </tr>
        </thead>
        <tbody>
        <?php
        $segRows = ['cl'=>'Exhibition','media'=>'Media Promo','gudang'=>'Gudang / Storage'];
        foreach($segRows as $seg=>$segLabel):
            $cA = array_sum(array_map(fn($d)=>$d['actual_seg'][$seg],$propData));
            $cP = array_sum(array_map(fn($d)=>$d['projection'][$seg],$propData));
            $cPct = $cP>0?$cA/$cP:0;
        ?>
        <tr>
            <td style="font-weight:700"><?= $segLabel ?></td>
            <?php foreach($propData as $d):
                $sp = $d['projection'][$seg]>0 ? $d['actual_seg'][$seg]/$d['projection'][$seg] : 0;
                $sCls = $sp>=1?'ach-good':($sp>=.8?'ach-warn':'ach-bad');
            ?>
            <td class="r"><span style="color:#7b8a9c"><?= money($d['projection'][$seg]) ?></span><br>
                <strong><?= money($d['actual_seg'][$seg]) ?></strong>
                <span class="ach-pill <?= $sCls ?>" style="margin-left:3px"><?= pct($sp) ?></span>
            </td>
            <?php endforeach; ?>
            <td class="r" style="background:#f0fdf4">
                <strong><?= money($cA) ?></strong>
                <?php $cCls=$cPct>=1?'ach-good':($cPct>=.8?'ach-warn':'ach-bad'); ?>
                <span class="ach-pill <?= $cCls ?>" style="margin-left:3px"><?= pct($cPct) ?></span>
            </td>
        </tr>
        <?php endforeach; ?>
        <tr class="grand-total">
            <td>Total</td>
            <?php foreach($propData as $d):
                $dp=$d['target']>0?$d['actual']/$d['target']:0;
                $dCls=$dp>=1?'ach-good':($dp>=.8?'ach-warn':'ach-bad');
            ?>
            <td class="r"><span style="color:#7b8a9c"><?= money($d['target']) ?></span><br>
                <strong><?= money($d['actual']) ?></strong>
                <span class="ach-pill <?= $dCls ?>" style="margin-left:3px"><?= pct($dp) ?></span>
            </td>
            <?php endforeach; ?>
            <td class="r" style="background:#f0fdf4">
                <?php $tp=$combTarget>0?$combActual/$combTarget:0; $tCls=$tp>=1?'ach-good':($tp>=.8?'ach-warn':'ach-bad'); ?>
                <strong><?= money($combActual) ?></strong>
                <span class="ach-pill <?= $tCls ?>" style="margin-left:3px"><?= pct($tp) ?></span>
            </td>
        </tr>
        </tbody>
    </table>
</div>

<?php
// Helper: build union of keys, sorted, for a given occ field
$buildOccKeys = function(string $occKey, ?callable $sorter=null) use ($propData): array {
    $keys = [];
    foreach($propData as $d) {
        foreach($d[$occKey] as $row) {
            $k = (string)($row['group_key']??'');
            if(!in_array($k,$keys,true)) $keys[]=$k;
        }
    }
    if($sorter) usort($keys,$sorter); else sort($keys);
    return $keys;
};
// useUnion=true → baris seragam lintas properti; false → tiap properti hanya datanya sendiri
$renderOccPrint = function(string $title, string $occKey, string $groupLabel, ?callable $sorter=null, bool $useUnion=false, string $rateLabel='Avg Rate')
    use ($propData, $periodDays, $buildOccKeys, $occStyle): void {
    $unionKeys = $useUnion ? $buildOccKeys($occKey, $sorter) : [];
    // Index per property
    $indexed=[];
    foreach($propData as $d) {
        $indexed[$d['id']]=[];
        foreach($d[$occKey] as $row) $indexed[$d['id']][(string)($row['group_key']??'')] = $row;
    }
    if($useUnion && empty($unionKeys)) return;
    ?>
<div class="section">
    <div class="section-title"><?= $title ?></div>
    <div class="occ-wrap">
    <?php foreach($propData as $ci=>$d):
        $pcN = array_search($ci,array_keys($propData))+1;
        if($useUnion) {
            $displayKeys = $unionKeys;
        } else {
            $displayKeys = array_map(fn($r)=>(string)($r['group_key']??''), $d[$occKey]);
            if($sorter) usort($displayKeys,$sorter); else sort($displayKeys);
        }
        if(empty($displayKeys)) continue;
    ?>
    <div class="occ-card">
        <div class="occ-prop-name"><?= h($d['name']) ?></div>
        <table>
            <thead>
                <tr>
                    <th><?= $groupLabel ?></th>
                    <th class="r">Unit</th>
                    <th class="r">Avg Hari</th>
                    <th class="r">Occ%</th>
                    <th class="r"><?= $rateLabel ?></th>
                    <th class="r">Aktual</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $tU=$tD=$tAreaSum=0; $tA=0;
            foreach($displayKeys as $key):
                $row = $indexed[$d['id']][$key]??null;
                $units = $row?(int)$row['unit_count']:0;
                $days  = $row?(float)$row['days_total']:0;
                $area  = $row?(float)($row['area_total']??0):0;
                $act   = $row?(float)$row['actual_total']:0;
                $max   = $units*$periodDays;
                $occ   = $max>0?$days/$max:0;
                $avgRate = $row ? ($row['avg_rate'] !== null ? (float)$row['avg_rate'] : null) : null;
                $tU+=$units;$tD+=$days;$tAreaSum+=$area;$tA+=$act;
                $avgDays = $units>0 ? $days/$units : 0;
            ?>
            <tr class="<?= $row?'':'row-dimmed' ?>">
                <td><?= h($key?:'—') ?></td>
                <td class="r"><?= $units?:'' ?></td>
                <td class="r"><?= $row?number_format($avgDays,1,',','.'):'' ?></td>
                <td class="r" style="<?= $row?$occStyle($occ):'' ?>"><?= $row?number_format($occ*100,1,',','.').'%':'—' ?></td>
                <td class="r" style="color:#7b8a9c"><?= $avgRate!==null?money($avgRate):'—' ?></td>
                <td class="money"><?= $row?money($act):'—' ?></td>
            </tr>
            <?php endforeach;
            $totOcc = $tU*$periodDays>0?$tD/($tU*$periodDays):0;
            $totAvg = $tU>0 ? $tD/$tU : 0; ?>
            <tr class="grand-total">
                <td>Total</td>
                <td class="r"><?= $tU ?></td>
                <td class="r"><?= number_format($totAvg,1,',','.') ?></td>
                <td class="r" style="<?= $occStyle($totOcc) ?>"><?= number_format($totOcc*100,1,',','.').'%' ?></td>
                <td class="r" style="color:#7b8a9c">—</td>
                <td class="money"><?= money($tA) ?></td>
            </tr>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>
    </div>
</div>
    <?php
};

$renderOccPrint('Occupancy Exhibition per Lantai', 'floor_occ', 'Lantai',
    fn($a,$b)=>($floorOrder[$a]??99)<=>($floorOrder[$b]??99), true, 'Avg Rate/Hari/m²');
$renderOccPrint('Occupancy Media Promo per Jenis',       'media_occ',  'Jenis', null, false, 'Avg Rate/Hari');
$renderOccPrint('Occupancy Gudang / Storage per Lokasi', 'gudang_occ', 'Lokasi',
    fn($a,$b)=>($floorOrder[$a]??99)<=>($floorOrder[$b]??99), false, 'Avg Rate/m²/Bln');
?>

<!-- PIC ACHIEVEMENT — PER PROPERTI -->
<div class="section">
    <div class="section-title">Achievement PIC</div>
    <div style="display:grid;grid-template-columns:repeat(<?= count($propData) ?>,1fr);gap:10px">
    <?php foreach($propData as $d): ?>
    <div>
        <div style="font-size:8px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#0d9488;margin-bottom:5px"><?= h($d['name']) ?></div>
        <table>
            <thead>
                <tr>
                    <th style="width:18px;text-align:center">#</th>
                    <th>PIC</th>
                    <th>Jabatan</th>
                    <th class="r">Target</th>
                    <th class="r">Aktual</th>
                    <th class="r">Ach%</th>
                    <th class="r">Baru</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($d['pics'] as $i=>$row):
                $pt   = (float)$row['target_share']*(float)$d['target'];
                $ach  = $pt>0?(float)$row['actual']/$pt:0;
                $aCls = $ach>=1?'ach-good':($ach>=.8?'ach-warn':'ach-bad');
                $bg   = $ach>=1?' style="background:#f0fdf4"':'';
                $rankColor = $i===0?'#f59e0b':($i===1?'#94a3b8':($i===2?'#b87333':'#cbd5e1'));
                $rankEmoji = $i===0&&(float)$row['actual']>0?' 👑':'';
                $rankEmoji.= $i===count($d['pics'])-1&&count($d['pics'])>1?' 😢':'';
            ?>
            <tr<?= $bg ?>>
                <td style="text-align:center;font-weight:800;color:<?= $rankColor ?>"><?= $i+1 ?></td>
                <td style="font-weight:600"><?= h($row['pic_name']) ?><?= $rankEmoji ?></td>
                <td style="color:#7b8a9c"><?= h($row['role_name']) ?></td>
                <td class="money"><?= $pt>0?money($pt):'<span style="color:#94a3b8">—</span>' ?></td>
                <td class="money"><?= money($row['actual']) ?></td>
                <td class="r"><?= $pt>0?'<span class="ach-pill '.$aCls.'">'.pct($ach).'</span>':'<span style="color:#94a3b8">—</span>' ?></td>
                <td class="r" style="font-weight:700"><?= (int)$row['new_clients'] ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($d['pics'])): ?>
            <tr><td colspan="7" style="padding:8px;text-align:center;color:#94a3b8">Belum ada data PIC.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<div class="rpt-footer">
    <span><?= h(env_value('APP_NAME','CLARA')) ?> — Executive Summary</span>
    <span>Periode <?= h($periodLabel) ?> &nbsp;|&nbsp; Dicetak <?= date('d/m/Y H:i:s') ?></span>
</div>

</body>
</html>
<?php
    exit;
}

function print_trend(PDO $pdo): void
{
    $pid = current_property_id();
    $mn = ['01'=>'Jan','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'Mei','06'=>'Jun',
           '07'=>'Jul','08'=>'Agt','09'=>'Sep','10'=>'Okt','11'=>'Nov','12'=>'Des'];

    $y1 = $pdo->prepare("SELECT DISTINCT LEFT(period_key,4) FROM transaction_allocations WHERE property_id=?");
    $y1->execute([$pid]); $y1 = $y1->fetchAll(PDO::FETCH_COLUMN);
    $y2 = $pdo->prepare("SELECT DISTINCT LEFT(period_key,4) FROM targets_monthly WHERE property_id=?");
    $y2->execute([$pid]); $y2 = $y2->fetchAll(PDO::FETCH_COLUMN);
    $allYears = array_values(array_unique(array_merge($y1, $y2)));
    rsort($allYears);
    if (empty($allYears)) $allYears = [date('Y')];
    $defaultYear  = in_array(date('Y'), $allYears, true) ? date('Y') : ($allYears[0] ?? date('Y'));
    $selectedYear = getv('year', $defaultYear);
    if (!in_array($selectedYear, $allYears, true)) $selectedYear = $defaultYear;

    $allocStmt = $pdo->prepare(
        "SELECT period_key, module, COALESCE(SUM(amount),0) actual
         FROM transaction_allocations WHERE property_id=? GROUP BY period_key, module ORDER BY period_key ASC"
    );
    $allocStmt->execute([$pid]);
    $alloc = $allocStmt->fetchAll();
    $targetsStmt = $pdo->prepare("SELECT period_key, target_amount FROM targets_monthly WHERE property_id=?");
    $targetsStmt->execute([$pid]);
    $targets = $targetsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $byPeriod = [];
    foreach ($alloc as $row) { $byPeriod[$row['period_key']][$row['module']] = (float)$row['actual']; }

    $months = ['01','02','03','04','05','06','07','08','09','10','11','12'];
    $labels = $cActual = $cTarget = $cCl = $cMedia = $cGudang = [];
    $yearActual = $yearTarget = 0;
    $bestAmt = 0; $bestLabel = '—';
    foreach ($months as $m) {
        $pk = $selectedYear . '-' . $m;
        $cl     = $byPeriod[$pk]['cl']     ?? 0;
        $media  = $byPeriod[$pk]['media']  ?? 0;
        $gudang = $byPeriod[$pk]['gudang'] ?? 0;
        $total  = $cl + $media + $gudang;
        $tgt    = (float)($targets[$pk] ?? 0);
        $labels[]  = $mn[$m];
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

    audit($pdo, 'print_trend', 'trend', $selectedYear, ['year'=>$selectedYear], [], 'reporting');
    ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Trend Revenue <?= h($selectedYear) ?> — <?= h(env_value('APP_NAME','CLARA')) ?></title>
<link rel="icon" type="image/png" href="assets/clara-logo.png">
<script src="assets/chart.umd.min.js"></script>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; font-size: 11px; color: #0F1623; background: #fff; }
@page { size: A4 landscape; margin: 14mm 12mm; }
.no-print { position: fixed; top: 16px; right: 16px; display: flex; gap: 8px; z-index: 99; }
.no-print button { padding: 9px 18px; border: none; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; }
.btn-print { background: #0D9488; color: #fff; }
.btn-close { background: #f1f5f9; color: #374151; }
@media print { .no-print { display: none; } }
.rpt-header { display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 10px; border-bottom: 2px solid #0D9488; margin-bottom: 14px; }
.rpt-logo { width: 160px; height: auto; display: block; object-fit: contain; }
.rpt-brand { font-size: 22px; font-weight: 900; color: #0D9488; letter-spacing: -.5px; }
.rpt-brand small { display: block; font-size: 10px; font-weight: 500; color: #7B8A9C; margin-top: 2px; }
.rpt-meta { text-align: right; font-size: 12px; color: #7B8A9C; line-height: 1.8; }
.rpt-period { font-size: 24px; font-weight: 900; color: #0F1623; }
.kpi-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 10px; margin-bottom: 14px; }
.kpi-box { border: 1px solid #E4E9F0; border-radius: 8px; padding: 10px 12px; }
.kpi-box-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #7B8A9C; }
.kpi-box-value { font-size: 18px; font-weight: 800; margin-top: 4px; color: #0F1623; }
.kpi-box:nth-child(1) { border-top: 3px solid #0D9488; }
.kpi-box:nth-child(2) { border-top: 3px solid #10B981; }
.kpi-box:nth-child(3) { border-top: 3px solid #3B82F6; }
.kpi-box:nth-child(4) { border-top: 3px solid #F59E0B; }
.chart-wrap { border: 1px solid #E4E9F0; border-radius: 8px; padding: 10px 12px; margin-bottom: 12px; page-break-inside: avoid; break-inside: avoid; }
.chart-title { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: #0D9488; margin-bottom: 8px; }
.chart-box { position: relative; height: 200px; }
.charts-row { display: flex; flex-direction: column; gap: 12px; margin-bottom: 14px; }
.tbl-section { }
.charts-below { page-break-before: always; break-before: page; }
table { width: 100%; border-collapse: collapse; font-size: 10px; }
th { background: #F8FAFC; color: #344054; font-weight: 700; text-transform: uppercase; font-size: 9px; letter-spacing: .04em; padding: 6px 8px; border: 1px solid #E4E9F0; text-align: left; white-space: nowrap; }
td { padding: 5px 8px; border: 1px solid #E4E9F0; vertical-align: middle; }
td.r, th.r { text-align: right; }
td.money { text-align: right; font-weight: 600; }
tfoot td { background: #F0FDFA; font-weight: 800; border-top: 2px solid #0D9488; }
.rpt-footer { margin-top: 14px; padding-top: 8px; border-top: 1px solid #E4E9F0; display: flex; justify-content: space-between; font-size: 9px; color: #7B8A9C; }
* { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
</style>
</head>
<body>

<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨 Cetak / Simpan PDF</button>
    <button class="btn-close" onclick="window.close()">✕ Tutup</button>
</div>

<div class="rpt-header">
    <div>
        <img class="rpt-logo" src="assets/clara-logo.png" alt="CLARA" onerror="this.hidden=true;this.nextElementSibling.style.display='block'">
        <div class="rpt-brand" style="display:none"><?= h(env_value('APP_NAME','CLARA')) ?><small>Casual Leasing Achievement & Revenue Analytics</small></div>
    </div>
    <div class="rpt-meta">
        <div class="rpt-period">Trend Revenue <?= h($selectedYear) ?></div>
        <div style="font-size:20px;font-weight:800;color:#0D9488;margin-bottom:2px"><?= h(current_property()['name'] ?? '') ?></div>
        <div>Dicetak: <?= date('d/m/Y H:i:s') ?></div>
        <div>Oleh: <?= h($_SESSION['user']['name'] ?? '-') ?> (<?= h($_SESSION['user']['role'] ?? '-') ?>)</div>
    </div>
</div>

<div class="kpi-row">
    <div class="kpi-box">
        <div class="kpi-box-label">Total Aktual <?= h($selectedYear) ?></div>
        <div class="kpi-box-value"><?= money($yearActual) ?></div>
    </div>
    <div class="kpi-box">
        <div class="kpi-box-label">Total Target <?= h($selectedYear) ?></div>
        <div class="kpi-box-value"><?= money($yearTarget) ?></div>
    </div>
    <div class="kpi-box">
        <div class="kpi-box-label">Achievement <?= h($selectedYear) ?></div>
        <?php $achColor = $yearAch >= 1 ? '#16a34a' : ($yearAch >= .8 ? '#d97706' : '#dc2626'); ?>
        <div class="kpi-box-value" style="color:<?= $achColor ?>"><?= pct($yearAch) ?></div>
    </div>
    <div class="kpi-box">
        <div class="kpi-box-label">Bulan Terbaik</div>
        <div class="kpi-box-value" style="font-size:16px"><?= h($bestLabel) ?></div>
        <div style="font-size:10px;color:#7B8A9C;margin-top:2px"><?= money($bestAmt) ?></div>
    </div>
</div>

<div class="tbl-section">
<table>
    <thead>
        <tr>
            <th>Bulan</th>
            <th class="r">Exhibition</th>
            <th class="r">Media</th>
            <th class="r">Gudang</th>
            <th class="r">Total Aktual</th>
            <th class="r">Target</th>
            <th class="r">Achievement</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($months as $m):
        $pk  = $selectedYear . '-' . $m;
        $cl  = $byPeriod[$pk]['cl']     ?? 0;
        $med = $byPeriod[$pk]['media']  ?? 0;
        $gd  = $byPeriod[$pk]['gudang'] ?? 0;
        $tot = $cl + $med + $gd;
        $tgt = (float)($targets[$pk] ?? 0);
        $ach = $tgt > 0 ? $tot / $tgt : null;
        $achCol = $ach === null ? '' : ($ach >= 1 ? '#16a34a' : ($ach >= .8 ? '#d97706' : '#dc2626'));
        $isBest = ($mn[$m] === $bestLabel && $tot > 0);
    ?>
    <tr<?= $isBest ? ' style="background:#fefce8"' : '' ?>>
        <td style="font-weight:600"><?= h($mn[$m]) ?><?= $isBest ? ' ⭐' : '' ?></td>
        <td class="money"><?= $cl  > 0 ? money($cl)  : '<span style="color:#CBD5E1">—</span>' ?></td>
        <td class="money"><?= $med > 0 ? money($med) : '<span style="color:#CBD5E1">—</span>' ?></td>
        <td class="money"><?= $gd  > 0 ? money($gd)  : '<span style="color:#CBD5E1">—</span>' ?></td>
        <td class="money"><?= $tot > 0 ? money($tot) : '<span style="color:#CBD5E1">—</span>' ?></td>
        <td class="money"><?= $tgt > 0 ? money($tgt) : '<span style="color:#CBD5E1">—</span>' ?></td>
        <td class="r" style="font-weight:700;<?= $achCol ? "color:$achCol" : '' ?>">
            <?= $ach !== null ? pct($ach) : '—' ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <?php
        $tTotCl  = array_sum(array_map(fn($m) => $byPeriod[$selectedYear.'-'.$m]['cl']     ?? 0, $months));
        $tTotMed = array_sum(array_map(fn($m) => $byPeriod[$selectedYear.'-'.$m]['media']  ?? 0, $months));
        $tTotGd  = array_sum(array_map(fn($m) => $byPeriod[$selectedYear.'-'.$m]['gudang'] ?? 0, $months));
        $tTotTgt = array_sum(array_map(fn($m) => (float)($targets[$selectedYear.'-'.$m] ?? 0), $months));
        $tTotAll = $tTotCl + $tTotMed + $tTotGd;
        $tAch    = $tTotTgt > 0 ? $tTotAll / $tTotTgt : null;
        $tAchCol = $tAch === null ? '' : ($tAch >= 1 ? '#16a34a' : ($tAch >= .8 ? '#d97706' : '#dc2626'));
        ?>
        <tr>
            <td>Total <?= h($selectedYear) ?></td>
            <td class="money"><?= money($tTotCl) ?></td>
            <td class="money"><?= money($tTotMed) ?></td>
            <td class="money"><?= money($tTotGd) ?></td>
            <td class="money"><?= money($tTotAll) ?></td>
            <td class="money"><?= $tTotTgt > 0 ? money($tTotTgt) : '—' ?></td>
            <td class="r" style="font-weight:800;<?= $tAchCol ? "color:$tAchCol" : '' ?>"><?= $tAch !== null ? pct($tAch) : '—' ?></td>
        </tr>
    </tfoot>
</table>
</div>

<div class="charts-below">
    <div class="chart-wrap">
        <div class="chart-title">Aktual vs Target — <?= h($selectedYear) ?></div>
        <div class="chart-box"><canvas id="chartTrend"></canvas></div>
    </div>
    <div class="chart-wrap">
        <div class="chart-title">Revenue per Segment — <?= h($selectedYear) ?></div>
        <div class="chart-box"><canvas id="chartSeg"></canvas></div>
    </div>
</div>

<div class="rpt-footer">
    <span><?= h(env_value('APP_NAME','CLARA')) ?> — Trend Revenue</span>
    <span>Tahun <?= h($selectedYear) ?> &nbsp;|&nbsp; Dicetak <?= date('d/m/Y H:i:s') ?></span>
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
    responsive: true, maintainAspectRatio: false,
    plugins: {
        legend: { position: 'top', labels: { usePointStyle: true, pointStyleWidth: 8, font: { size: 10 } } },
        tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + jFmt(c.raw) } }
    },
    scales: {
        x: { grid: { color: 'rgba(0,0,0,.04)' }, ticks: { font: { size: 10 } } },
        y: { grid: { color: 'rgba(0,0,0,.04)' }, ticks: { font: { size: 10 }, callback: v => 'Rp ' + (v/1e6).toFixed(0) + ' jt' } }
    }
};
new Chart(document.getElementById('chartTrend'), {
    type: 'bar',
    data: { labels: jLbl, datasets: [
        { type:'line', label:'Target', data:jTgt, borderColor:'#EF4444', backgroundColor:'transparent', borderWidth:2, borderDash:[6,4], pointRadius:3, pointBackgroundColor:'#EF4444', tension:0.3, order:0 },
        { type:'bar',  label:'Aktual', data:jAct, backgroundColor:'rgba(13,148,136,.75)', borderRadius:4, order:1 }
    ]},
    options: baseOpts
});
new Chart(document.getElementById('chartSeg'), {
    type: 'bar',
    data: { labels: jLbl, datasets: [
        { label:'Exhibition', data:jCl,  backgroundColor:'rgba(13,148,136,.82)', borderRadius:0 },
        { label:'Media',      data:jMed, backgroundColor:'rgba(8,145,178,.82)',  borderRadius:0 },
        { label:'Gudang',     data:jGd,  backgroundColor:'rgba(245,158,11,.82)', borderRadius:4 },
    ]},
    options: { ...baseOpts, scales: { ...baseOpts.scales, x:{...baseOpts.scales.x,stacked:true}, y:{...baseOpts.scales.y,stacked:true} } }
});
</script>
</body>
</html>
<?php
    exit;
}

function export_summary(PDO $pdo): void
{
    $period = getv('period', date('Y-m'));
    audit($pdo, 'export_summary_csv', 'transaction_allocations', $period, ['period' => $period], [], 'reporting');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="summary-' . $period . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['period', 'module', 'master_code', 'days', 'capacity_days', 'amount', 'pic']);
    $stmt = $pdo->prepare('SELECT * FROM transaction_allocations WHERE period_key = ? AND property_id = ? ORDER BY module, master_code');
    $stmt->execute([$period, current_property_id()]);
    foreach ($stmt->fetchAll() as $row) {
        fputcsv($out, [$row['period_key'], $row['module'], $row['master_code'], $row['allocated_days'], $row['capacity_days'], $row['amount'], $row['pic_name']]);
    }
    exit;
}

function export_transactions_xlsx(PDO $pdo): void
{
    $module    = getv('module', 'media');
    $search    = trim(getv('search', ''));
    $filterPic = getv('pic', '');
    $dateFrom  = getv('date_from', '');
    $dateTo    = getv('date_to', '');

    $where  = ['t.module = :module', 't.deleted_at IS NULL', 't.property_id = :property_id'];
    $params = [':module' => $module, ':property_id' => current_property_id()];
    if ($search !== '')    { $where[] = '(c.company_name LIKE :search OR t.master_code LIKE :search)'; $params[':search'] = '%' . $search . '%'; }
    if ($filterPic !== '') { $where[] = 't.pic_name = :pic'; $params[':pic'] = $filterPic; }
    if ($dateFrom !== '')  { $where[] = 't.start_date >= :date_from'; $params[':date_from'] = $dateFrom; }
    if ($dateTo !== '')    { $where[] = 't.end_date <= :date_to'; $params[':date_to'] = $dateTo; }

    $stmt = $pdo->prepare(
        'SELECT t.id, t.created_at, t.module, t.master_code, c.company_name,
                cc.name cp_name, cc.phone cp_phone, t.pic_name,
                t.start_date, t.end_date, t.total_calculated, t.final_amount, t.invoice_no
         FROM transactions t
         LEFT JOIN master_clients c ON c.id = t.client_id
         LEFT JOIN master_client_contacts cc ON cc.id = t.contact_id
         WHERE ' . implode(' AND ', $where) . ' ORDER BY t.id DESC'
    );
    $stmt->execute($params);

    $moduleLabel = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
    $data = [];
    foreach ($stmt->fetchAll() as $i => $r) {
        $data[] = [
            $i + 1,
            $r['created_at'],
            $moduleLabel[$r['module']] ?? $r['module'],
            $r['master_code'],
            $r['company_name'] ?? '-',
            $r['cp_name'] ?? '-',
            $r['cp_phone'] ?? '',
            $r['pic_name'] ?? '-',
            $r['start_date'],
            $r['end_date'],
            (float) $r['total_calculated'],
            (float) $r['final_amount'],
            $r['invoice_no'] ?? '',
        ];
    }
    xlsx_download('transaksi_' . $module . '_' . date('Ymd') . '.xlsx', [
        'No', 'Tgl Input', 'Modul', 'Kode Unit', 'Client/Perusahaan', 'Contact Person', 'No. Telepon',
        'PIC', 'Tgl Mulai', 'Tgl Selesai', 'Total Hitung', 'Final Amount', 'No. Invoice',
    ], $data);
}

function export_pic_report_xlsx(PDO $pdo): void
{
    require_permission('view_pic_report');
    $period = getv('period', date('Y-m'));
    $pid    = current_property_id();
    $target = (float) ($pdo->query('SELECT target_amount FROM targets_monthly WHERE period_key=' . $pdo->quote($period) . " AND property_id=$pid")->fetchColumn() ?: 0);

    $stmt = $pdo->prepare(
        "SELECT p.name, COALESCE(p.role_name,'-') role_name, COALESCE(p.target_share,0) target_share,
                COALESCE(SUM(CASE WHEN a.module='cl'     THEN a.amount ELSE 0 END),0) actual_cl,
                COALESCE(SUM(CASE WHEN a.module='media'  THEN a.amount ELSE 0 END),0) actual_media,
                COALESCE(SUM(CASE WHEN a.module='gudang' THEN a.amount ELSE 0 END),0) actual_gudang,
                COALESCE(SUM(a.amount),0) actual_total,
                COUNT(DISTINCT t.id) trx_count
         FROM master_pic p
         LEFT JOIN transaction_allocations a ON a.pic_name=p.name AND a.period_key=? AND a.property_id=?
         LEFT JOIN transactions t ON t.id=a.transaction_id AND t.deleted_at IS NULL
         WHERE p.status='active' AND p.property_id=?
         GROUP BY p.id, p.name, p.role_name, p.target_share
         ORDER BY actual_total DESC"
    );
    $stmt->execute([$period, $pid, $pid]);

    $data = [];
    foreach ($stmt->fetchAll() as $i => $p) {
        $targetPosisi = (float) $p['target_share'] * $target;
        $achievement  = $targetPosisi > 0 ? round((float) $p['actual_total'] / $targetPosisi * 100, 1) : 0;
        $data[] = [
            $i + 1,
            $p['name'],
            $p['role_name'],
            $targetPosisi,
            (float) $p['actual_cl'],
            (float) $p['actual_media'],
            (float) $p['actual_gudang'],
            (float) $p['actual_total'],
            $achievement,
            (int) $p['trx_count'],
        ];
    }
    xlsx_download('laporan_pic_' . $period . '_' . date('Ymd') . '.xlsx', [
        'No', 'Nama PIC', 'Jabatan', 'Target Posisi', 'Exhibition', 'Media', 'Gudang',
        'Total Actual', 'Achievement (%)', 'Jml Transaksi',
    ], $data);
}

function export_client_analysis_xlsx(PDO $pdo): void
{
    require_permission('view_master');
    $filterType    = getv('business_type', '');
    $filterScale   = getv('business_scale', '');
    $filterSegment = getv('target_segment', '');

    $sw = ["status='active'"]; $sp = [];
    if ($filterType)    { $sw[] = 'business_type = ?';     $sp[] = $filterType; }
    if ($filterScale)   { $sw[] = 'business_scale = ?';    $sp[] = $filterScale; }
    if ($filterSegment) { $sw[] = 'target_segment LIKE ?'; $sp[] = '%' . $filterSegment . '%'; }

    $stmt = $pdo->prepare(
        'SELECT company_name, business_type, business_scale, brand_origin, target_segment, channel, tags
         FROM master_clients WHERE ' . implode(' AND ', $sw) . ' ORDER BY company_name'
    );
    $stmt->execute($sp);

    $data = [];
    foreach ($stmt->fetchAll() as $i => $c) {
        $data[] = [
            $i + 1,
            $c['company_name'],
            $c['business_type'] ?? '',
            $c['business_scale'] ?? '',
            $c['brand_origin'] ?? '',
            $c['target_segment'] ?? '',
            $c['channel'] ?? '',
            $c['tags'] ?? '',
        ];
    }
    xlsx_download('client_analysis_' . date('Ymd') . '.xlsx', [
        'No', 'Perusahaan', 'Jenis Usaha', 'Skala', 'Asal Brand', 'Segmen', 'Channel', 'Tags',
    ], $data);
}
