<?php
declare(strict_types=1);

function _pic_fetch_section(PDO $pdo, string $period, int $pid): array
{
    $tgtStmt = $pdo->prepare("SELECT target_amount FROM targets_monthly WHERE period_key=? AND property_id=?");
    $tgtStmt->execute([$period, $pid]);
    $target = (float)($tgtStmt->fetchColumn() ?: 0);

    $picStmt = $pdo->prepare(
        "SELECT p.name, COALESCE(p.role_name,'-') role_name, COALESCE(p.target_share,0) target_share,
                COALESCE(SUM(CASE WHEN a.module='cl'     THEN a.amount ELSE 0 END),0) actual_cl,
                COALESCE(SUM(CASE WHEN a.module='media'  THEN a.amount ELSE 0 END),0) actual_media,
                COALESCE(SUM(CASE WHEN a.module='gudang' THEN a.amount ELSE 0 END),0) actual_gudang,
                COALESCE(SUM(a.amount),0) actual_total,
                COALESCE(SUM(CASE WHEN t.billing_method='spread' THEN a.amount ELSE 0 END),0) actual_recurring,
                COUNT(DISTINCT t.id) trx_count
         FROM master_pic p
         LEFT JOIN transaction_allocations a ON a.pic_name=p.name AND a.period_key=? AND a.property_id=?
         LEFT JOIN transactions t ON t.id=a.transaction_id AND t.deleted_at IS NULL AND t.property_id=?
         WHERE p.status='active' AND p.property_id=?
         GROUP BY p.id, p.name, p.role_name, p.target_share
         ORDER BY actual_total DESC"
    );
    $picStmt->execute([$period, $pid, $pid, $pid]);
    $pics = $picStmt->fetchAll();

    $trxStmt = $pdo->prepare(
        "SELECT t.id, t.module, t.master_code, t.billing_method,
                COALESCE(c.company_name,'-') company_name,
                t.start_date, t.end_date, COALESCE(t.pic_name,'Tanpa PIC') pic_name,
                t.invoice_no, COALESCE(SUM(a.amount),0) period_amount
         FROM transactions t
         LEFT JOIN master_clients c ON c.id=t.client_id
         JOIN transaction_allocations a ON a.transaction_id=t.id AND a.period_key=? AND a.property_id=?
         WHERE t.deleted_at IS NULL AND t.property_id=?
         GROUP BY t.id, t.module, t.master_code, t.billing_method, c.company_name,
                  t.start_date, t.end_date, t.pic_name, t.invoice_no
         ORDER BY t.pic_name, period_amount DESC"
    );
    $trxStmt->execute([$period, $pid, $pid]);
    $trxByPic = [];
    foreach ($trxStmt->fetchAll() as $trx) {
        $trxByPic[$trx['pic_name']][] = $trx;
    }

    return [
        'target'   => $target,
        'pics'     => $pics,
        'trxByPic' => $trxByPic,
        'total'    => array_sum(array_column($pics, 'actual_total')),
    ];
}

function _pic_render_section(array $sec, string $period, array $moduleLabel, string $idPrefix): void
{
    $pics      = $sec['pics'];
    $trxByPic        = $sec['trxByPic'];
    $target          = $sec['target'];
    $total           = $sec['total'];
    $totalRecurring  = array_sum(array_column($pics, 'actual_recurring'));
    $totalRegular    = $total - $totalRecurring;
    $ach             = $target > 0 ? $total / $target : 0;
    $achColor        = fn($v) => $v >= 1 ? '#16a34a' : ($v >= 0.8 ? '#d97706' : '#dc2626');
    ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:12px;margin-bottom:16px">
        <?php foreach ([
            ['Target Bulan Ini', money($target), ''],
            ['Regular',          money($totalRegular), ''],
            ['Recurring',        money($totalRecurring), '#e0f2fe'],
            ['Total Actual',     money($total), ''],
            ['Achievement',      $target > 0 ? pct($ach) : '—', ''],
        ] as [$lbl, $val, $bg]): ?>
        <div class="panel" style="padding:14px 16px;margin:0<?= $bg ? ';background:' . $bg : '' ?>">
            <div class="kpi-label"><?= $lbl ?></div>
            <div class="kpi-value" style="font-size:20px"><?= $val ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="panel" style="margin-bottom:8px">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Nama PIC</th><th>Role</th>
                        <th class="r">Target Posisi</th>
                        <th class="r">Exhibition</th><th class="r">Media</th><th class="r">Gudang</th>
                        <th class="r">Regular</th><th class="r">Recurring</th>
                        <th class="r">Total Actual</th><th class="r">Achievement</th><th class="r">Trx</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pics as $i => $p):
                    $tp  = (float)$p['target_share'] * $target;
                    $a   = $tp > 0 ? $p['actual_total'] / $tp : 0;
                    $uid = $idPrefix . $i;
                    $rec = (float)$p['actual_recurring'];
                    $reg = (float)$p['actual_total'] - $rec;
                ?>
                <tr>
                    <td>
                        <a href="#<?= $uid ?>" onclick="var s=document.getElementById('<?= $uid ?>');s.style.display=s.style.display==='none'?'table-row-group':'none';return false"
                           style="font-weight:600;color:var(--primary);cursor:pointer;text-decoration:none"><?= h($p['name']) ?></a>
                    </td>
                    <td style="font-size:12px;color:var(--muted)"><?= h($p['role_name']) ?></td>
                    <td class="r"><?= money($tp) ?></td>
                    <td class="r"><?= money($p['actual_cl']) ?></td>
                    <td class="r"><?= money($p['actual_media']) ?></td>
                    <td class="r"><?= money($p['actual_gudang']) ?></td>
                    <td class="r"><?= money($reg) ?></td>
                    <td class="r"><?= $rec > 0 ? '<span style="color:#0369a1;font-weight:600">' . money($rec) . '</span>' : '—' ?></td>
                    <td class="r" style="font-weight:700"><?= money($p['actual_total']) ?></td>
                    <td class="r"><span style="font-weight:700;color:<?= $achColor($a) ?>"><?= pct($a) ?></span></td>
                    <td class="r"><?= $p['trx_count'] ?></td>
                </tr>
                <?php if (!empty($trxByPic[$p['name']])): ?>
                <tr id="<?= $uid ?>" style="display:none">
                    <td colspan="11" style="padding:0;background:#F8FAFC">
                        <table style="width:100%;font-size:12px">
                            <thead><tr style="background:#F1F5F9">
                                <th style="padding:6px 10px;text-align:left">Kode</th>
                                <th style="padding:6px 10px;text-align:left">Client</th>
                                <th style="padding:6px 10px;text-align:left">Modul</th>
                                <th style="padding:6px 10px;text-align:left">Periode Kontrak</th>
                                <th style="padding:6px 10px;text-align:left">No. Invoice</th>
                                <th style="padding:6px 10px;text-align:right">Aktual Bulan Ini</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($trxByPic[$p['name']] as $trx): ?>
                            <tr style="border-top:1px solid var(--line)">
                                <td style="padding:5px 10px"><?= h($trx['master_code']) ?></td>
                                <td style="padding:5px 10px">
                                    <?= h($trx['company_name']) ?>
                                    <?= ($trx['billing_method'] ?? '') === 'spread' ? ' <span class="badge" style="font-size:10px;background:#e0f2fe;color:#0369a1">Recurring</span>' : '' ?>
                                </td>
                                <td style="padding:5px 10px"><?= h($moduleLabel[$trx['module']] ?? $trx['module']) ?></td>
                                <td style="padding:5px 10px;white-space:nowrap"><?= h($trx['start_date'] . ' s/d ' . $trx['end_date']) ?></td>
                                <td style="padding:5px 10px;color:var(--muted)"><?= h($trx['invoice_no'] ?? '-') ?></td>
                                <td style="padding:5px 10px;text-align:right;font-weight:600"><?= money($trx['period_amount']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
                <tr style="font-weight:700;border-top:2px solid var(--line);background:#F8FAFC">
                    <td colspan="2">Total</td>
                    <td class="r"><?= money(array_sum(array_map(fn($p) => (float)$p['target_share'] * $target, $pics))) ?></td>
                    <td class="r"><?= money(array_sum(array_column($pics,'actual_cl'))) ?></td>
                    <td class="r"><?= money(array_sum(array_column($pics,'actual_media'))) ?></td>
                    <td class="r"><?= money(array_sum(array_column($pics,'actual_gudang'))) ?></td>
                    <td class="r"><?= money($totalRegular) ?></td>
                    <td class="r" style="color:#0369a1"><?= money($totalRecurring) ?></td>
                    <td class="r"><?= money($total) ?></td>
                    <td class="r"><span style="font-weight:700;color:<?= $achColor($ach) ?>"><?= $target > 0 ? pct($ach) : '—' ?></span></td>
                    <td class="r"><?= array_sum(array_column($pics,'trx_count')) ?></td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}

function pic_report_page(PDO $pdo): void
{
    require_permission('view_pic_report');

    $period  = getv('period', date('Y-m'));
    $periods = $pdo->query("SELECT period_key, MAX(label) label FROM periods GROUP BY period_key ORDER BY period_key DESC")->fetchAll();
    $props   = allowed_properties();
    $moduleLabel = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];

    $sections = [];
    foreach ($props as $prop) {
        $sections[] = ['prop' => $prop] + _pic_fetch_section($pdo, $period, (int)$prop['id']);
    }

    layout('Laporan PIC', function () use ($sections, $periods, $period, $moduleLabel) {
        ?>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap">
            <form method="get" style="display:flex;gap:6px;align-items:center">
                <input type="hidden" name="r" value="pic_report">
                <label style="font-size:12px;color:var(--muted)">Periode:</label>
                <select name="period" onchange="this.form.submit()" style="font-size:12px">
                    <?php foreach ($periods as $p): ?>
                        <option value="<?= h($p['period_key']) ?>" <?= $p['period_key'] === $period ? 'selected' : '' ?>><?= h($p['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <a class="btn light" href="?r=pic_report_print&period=<?= h($period) ?>" target="_blank" style="margin-left:auto">🖨 Cetak / PDF</a>
            <a class="btn light" href="?r=export_pic_report_xlsx&period=<?= h($period) ?>">⬇ Export Excel</a>
        </div>

        <?php foreach ($sections as $si => $sec): ?>
        <?php if (count($sections) > 1): ?>
        <div style="background:var(--primary);color:#fff;border-radius:8px 8px 0 0;padding:8px 16px;font-weight:700;font-size:13px;margin-top:<?= $si > 0 ? '28px' : '0' ?>">
            <?= h($sec['prop']['name']) ?>
        </div>
        <?php endif; ?>
        <?php _pic_render_section($sec, $period, $moduleLabel, 'pic-' . $si . '-'); ?>
        <?php endforeach; ?>

        <p style="font-size:12px;color:var(--muted);margin-top:4px">Klik nama PIC untuk melihat daftar transaksi di periode ini.</p>
        <?php
    });
}

function pic_report_print(PDO $pdo): void
{
    require_permission('view_pic_report');

    $period  = getv('period', date('Y-m'));
    $pid     = current_property_id();
    $monthNames = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
    $periodLabel = ($monthNames[substr($period,5,2)] ?? substr($period,5,2)) . ' ' . substr($period,0,4);
    $tgtPrintStmt = $pdo->prepare("SELECT target_amount FROM targets_monthly WHERE period_key=? AND property_id=?");
    $tgtPrintStmt->execute([$period, $pid]);
    $target = (float)($tgtPrintStmt->fetchColumn() ?: 0);
    $appName = env_value('APP_NAME', 'CLARA');

    $picStmt = $pdo->prepare(
        "SELECT p.name, COALESCE(p.role_name,'-') role_name, COALESCE(p.target_share,0) target_share,
                COALESCE(SUM(CASE WHEN a.module='cl'     THEN a.amount ELSE 0 END),0) actual_cl,
                COALESCE(SUM(CASE WHEN a.module='media'  THEN a.amount ELSE 0 END),0) actual_media,
                COALESCE(SUM(CASE WHEN a.module='gudang' THEN a.amount ELSE 0 END),0) actual_gudang,
                COALESCE(SUM(a.amount),0) actual_total,
                COALESCE(SUM(CASE WHEN t.billing_method='spread' THEN a.amount ELSE 0 END),0) actual_recurring,
                COUNT(DISTINCT t.id) trx_count
         FROM master_pic p
         LEFT JOIN transaction_allocations a ON a.pic_name=p.name AND a.period_key=? AND a.property_id=?
         LEFT JOIN transactions t ON t.id=a.transaction_id AND t.deleted_at IS NULL AND t.property_id=?
         WHERE p.status='active' AND p.property_id=?
         GROUP BY p.id, p.name, p.role_name, p.target_share
         ORDER BY actual_total DESC"
    );
    $picStmt->execute([$period, $pid, $pid, $pid]);
    $pics = $picStmt->fetchAll();

    $trxStmt = $pdo->prepare(
        "SELECT t.id, t.module, t.master_code, t.billing_method,
                COALESCE(c.company_name,'-') company_name,
                t.start_date, t.end_date, COALESCE(t.pic_name,'Tanpa PIC') pic_name,
                t.invoice_no, COALESCE(SUM(a.amount),0) period_amount
         FROM transactions t
         LEFT JOIN master_clients c ON c.id=t.client_id
         JOIN transaction_allocations a ON a.transaction_id=t.id AND a.period_key=? AND a.property_id=?
         WHERE t.deleted_at IS NULL AND t.property_id=?
         GROUP BY t.id, t.module, t.master_code, t.billing_method, c.company_name,
                  t.start_date, t.end_date, t.pic_name, t.invoice_no
         ORDER BY t.pic_name, period_amount DESC"
    );
    $trxStmt->execute([$period, $pid, $pid]);
    $trxByPic = [];
    foreach ($trxStmt->fetchAll() as $trx) {
        $trxByPic[$trx['pic_name']][] = $trx;
    }

    $totalActual    = array_sum(array_column($pics, 'actual_total'));
    $totalRecurring = array_sum(array_column($pics, 'actual_recurring'));
    $totalRegular   = $totalActual - $totalRecurring;
    $moduleLabel = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
    audit($pdo, 'print', 'pic_report', $period, ['period' => $period], [], 'reporting');
    ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Laporan PIC — <?= h($appName) ?> — <?= h($periodLabel) ?></title>
<link rel="icon" type="image/png" href="assets/clara-logo.png">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;font-size:11px;color:#0F1623;background:#fff}
@page{size:A4 landscape;margin:14mm 12mm}
.no-print{position:fixed;top:16px;right:16px;display:flex;gap:8px;z-index:99}
.no-print button{padding:9px 18px;border:none;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer}
.btn-print{background:#0D9488;color:#fff}
.btn-close{background:#f1f5f9;color:#334155}
@media print{.no-print{display:none}}
.rpt-header{display:flex;justify-content:space-between;align-items:flex-start;padding-bottom:10px;border-bottom:2px solid #0D9488;margin-bottom:14px}
.rpt-logo{width:160px;height:auto;display:block;object-fit:contain}
.rpt-brand{font-size:22px;font-weight:900;color:#0D9488;letter-spacing:-.5px}
.rpt-brand small{display:block;font-size:10px;font-weight:500;color:#7B8A9C;margin-top:2px;letter-spacing:0}
.rpt-meta{text-align:right;font-size:12px;color:#7B8A9C;line-height:1.8}
.rpt-period{font-size:18px;font-weight:800;color:#0F1623}
table{width:100%;border-collapse:collapse;font-size:10px}
th{background:#F8FAFC;color:#344054;font-weight:700;text-transform:uppercase;font-size:9px;letter-spacing:.04em;padding:6px 8px;border:1px solid #E4E9F0;text-align:left;white-space:nowrap}
th.r,td.r{text-align:right}
td{padding:5px 8px;border:1px solid #E4E9F0;vertical-align:middle}
.total-row td{font-weight:700;border-top:2px solid #0D9488;background:#F0FDF9}
.section-header{background:#1E3A5F;color:#fff;padding:5px 8px;font-weight:700;font-size:10px;margin-top:14px;margin-bottom:6px}
.ach-good{color:#16a34a;font-weight:700}
.ach-warn{color:#d97706;font-weight:700}
.ach-bad{color:#dc2626;font-weight:700}
.trx-table th{background:#334155;color:#fff}
.pic-section{margin-top:14px;page-break-inside:avoid}
.kpi-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:14px}
.kpi-box{border:1px solid #E4E9F0;border-radius:6px;padding:8px 12px}
.kpi-box:nth-child(1){border-top:3px solid #0D9488}
.kpi-box:nth-child(2){border-top:3px solid #10B981}
.kpi-box:nth-child(3){border-top:3px solid #0369a1}
.kpi-box:nth-child(4){border-top:3px solid #F59E0B}
.kpi-box:nth-child(5){border-top:3px solid #8B5CF6}
.kpi-label{font-size:9px;color:#64748B;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px}
.kpi-val{font-size:16px;font-weight:800;color:#0F1623}
.rpt-footer{margin-top:14px;padding-top:8px;border-top:1px solid #E4E9F0;display:flex;justify-content:space-between;font-size:9px;color:#7B8A9C}
*{-webkit-print-color-adjust:exact;print-color-adjust:exact}
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
        <div class="rpt-brand" style="display:none"><?= h($appName) ?><small>Casual Leasing Achievement &amp; Revenue Analytics</small></div>
    </div>
    <div class="rpt-meta">
        <div class="rpt-period"><?= h($periodLabel) ?></div>
        <div style="font-size:16px;font-weight:800;color:#0D9488;margin-bottom:2px">Laporan Pencapaian PIC</div>
        <div><?= h(current_property()['name'] ?? '') ?></div>
        <div>Dicetak: <?= date('d/m/Y H:i:s') ?></div>
        <div>Oleh: <?= h($_SESSION['user']['name'] ?? '-') ?> (<?= h($_SESSION['user']['role'] ?? '-') ?>)</div>
    </div>
</div>

<div class="kpi-grid">
    <?php
    $ach    = $target > 0 ? $totalActual / $target : 0;
    $achCls = $ach >= 1 ? 'ach-good' : ($ach >= 0.8 ? 'ach-warn' : 'ach-bad');
    foreach ([
        ['Target Bulan Ini', money($target)],
        ['Regular',          money($totalRegular)],
        ['Recurring',        money($totalRecurring)],
        ['Total Actual',     money($totalActual)],
        ['Achievement',      $target > 0 ? pct($ach) : '—'],
    ] as [$lbl, $val]): ?>
    <div class="kpi-box">
        <div class="kpi-label"><?= $lbl ?></div>
        <div class="kpi-val"><?= $val ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Summary Table -->
<table>
    <thead>
        <tr>
            <th>Nama PIC</th><th>Role</th>
            <th class="r">Target Posisi</th>
            <th class="r">Exhibition</th><th class="r">Media</th><th class="r">Gudang</th>
            <th class="r">Regular</th><th class="r">Recurring</th>
            <th class="r">Total Actual</th><th class="r">Achievement</th><th class="r">Trx</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($pics as $p):
        $tp  = (float)$p['target_share'] * $target;
        $a   = $tp > 0 ? $p['actual_total'] / $tp : 0;
        $cls = $a >= 1 ? 'ach-good' : ($a >= 0.8 ? 'ach-warn' : 'ach-bad');
        $rec = (float)$p['actual_recurring'];
        $reg = (float)$p['actual_total'] - $rec;
    ?>
    <tr>
        <td style="font-weight:700"><?= h($p['name']) ?></td>
        <td style="color:#64748B"><?= h($p['role_name']) ?></td>
        <td class="r"><?= money($tp) ?></td>
        <td class="r"><?= money($p['actual_cl']) ?></td>
        <td class="r"><?= money($p['actual_media']) ?></td>
        <td class="r"><?= money($p['actual_gudang']) ?></td>
        <td class="r"><?= money($reg) ?></td>
        <td class="r" style="color:#0369a1;font-weight:600"><?= $rec > 0 ? money($rec) : '—' ?></td>
        <td class="r" style="font-weight:700"><?= money($p['actual_total']) ?></td>
        <td class="r"><span class="<?= $cls ?>"><?= pct($a) ?></span></td>
        <td class="r"><?= $p['trx_count'] ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total-row">
        <td colspan="2">TOTAL</td>
        <td class="r"><?= money(array_sum(array_map(fn($p) => (float)$p['target_share'] * $target, $pics))) ?></td>
        <td class="r"><?= money(array_sum(array_column($pics,'actual_cl'))) ?></td>
        <td class="r"><?= money(array_sum(array_column($pics,'actual_media'))) ?></td>
        <td class="r"><?= money(array_sum(array_column($pics,'actual_gudang'))) ?></td>
        <td class="r"><?= money($totalRegular) ?></td>
        <td class="r" style="color:#0369a1"><?= money($totalRecurring) ?></td>
        <td class="r"><?= money($totalActual) ?></td>
        <td class="r"><span class="<?= $achCls ?>"><?= $target > 0 ? pct($ach) : '—' ?></span></td>
        <td class="r"><?= array_sum(array_column($pics,'trx_count')) ?></td>
    </tr>
    </tbody>
</table>

<!-- Per-PIC Transaction Detail -->
<?php foreach ($pics as $p):
    if (empty($trxByPic[$p['name']])) continue;
    $tp  = (float)$p['target_share'] * $target;
    $a   = $tp > 0 ? $p['actual_total'] / $tp : 0;
    $cls = $a >= 1 ? 'ach-good' : ($a >= 0.8 ? 'ach-warn' : 'ach-bad');
    $rec = (float)$p['actual_recurring'];
    $reg = (float)$p['actual_total'] - $rec;
?>
<div class="pic-section">
    <div class="section-header">
        <?= h($p['name']) ?> — <?= h($p['role_name']) ?>
        &nbsp;|&nbsp; Regular: <?= money($reg) ?>
        <?= $rec > 0 ? ' &nbsp;|&nbsp; <span style="color:#93c5fd">Recurring: ' . money($rec) . '</span>' : '' ?>
        &nbsp;|&nbsp; Total: <?= money($p['actual_total']) ?>
        &nbsp;|&nbsp; Achievement: <span class="<?= $cls ?>"><?= pct($a) ?></span>
    </div>
    <table class="trx-table">
        <thead><tr>
            <th>Kode</th><th>Client</th><th>Tipe</th><th>Modul</th><th>Periode Kontrak</th><th>No. Invoice</th><th class="r">Aktual Bulan Ini</th>
        </tr></thead>
        <tbody>
        <?php foreach ($trxByPic[$p['name']] as $trx):
            $isRecurring = ($trx['billing_method'] ?? '') === 'spread';
        ?>
        <tr<?= $isRecurring ? ' style="background:#eff6ff"' : '' ?>>
            <td><?= h($trx['master_code']) ?></td>
            <td><?= h($trx['company_name']) ?></td>
            <td style="color:<?= $isRecurring ? '#0369a1' : '#64748b' ?>;font-weight:<?= $isRecurring ? '700' : '400' ?>">
                <?= $isRecurring ? 'Recurring' : 'Regular' ?>
            </td>
            <td><?= h($moduleLabel[$trx['module']] ?? $trx['module']) ?></td>
            <td style="white-space:nowrap"><?= h($trx['start_date'] . ' s/d ' . $trx['end_date']) ?></td>
            <td style="color:#64748B"><?= h($trx['invoice_no'] ?? '-') ?></td>
            <td class="r" style="font-weight:700<?= $isRecurring ? ';color:#0369a1' : '' ?>"><?= money($trx['period_amount']) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="font-weight:700;background:#F0FDF9">
            <td colspan="6" style="text-align:right">Total <?= h($p['name']) ?></td>
            <td class="r"><?= money($p['actual_total']) ?></td>
        </tr>
        </tbody>
    </table>
</div>
<?php endforeach; ?>

<div class="rpt-footer">
    <span>Laporan PIC — <?= h($periodLabel) ?> &nbsp;|&nbsp; <?= h(current_property()['name'] ?? '') ?></span>
    <span>Dicetak <?= date('d/m/Y H:i:s') ?> &nbsp;|&nbsp; <?= h($_SESSION['user']['name'] ?? '-') ?></span>
</div>
</body>
</html>
<?php
}
