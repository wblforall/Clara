<?php
declare(strict_types=1);

function pic_report_page(PDO $pdo): void
{
    require_permission('view_pic_report');

    $period  = getv('period', date('Y-m'));
    $pid     = current_property_id();
    $periods = $pdo->query("SELECT period_key, label FROM periods ORDER BY period_key DESC")->fetchAll();
    $tgtStmt = $pdo->prepare("SELECT target_amount FROM targets_monthly WHERE period_key=? AND property_id=?");
    $tgtStmt->execute([$period, $pid]);
    $target  = (float)($tgtStmt->fetchColumn() ?: 0);

    $picStmt = $pdo->prepare(
        "SELECT p.name, COALESCE(p.role_name,'-') role_name, COALESCE(p.target_share,0) target_share,
                COALESCE(SUM(CASE WHEN a.module='cl'     THEN a.amount ELSE 0 END),0) actual_cl,
                COALESCE(SUM(CASE WHEN a.module='media'  THEN a.amount ELSE 0 END),0) actual_media,
                COALESCE(SUM(CASE WHEN a.module='gudang' THEN a.amount ELSE 0 END),0) actual_gudang,
                COALESCE(SUM(a.amount),0) actual_total,
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
        "SELECT t.id, t.module, t.master_code, COALESCE(c.company_name,'-') company_name,
                t.start_date, t.end_date, COALESCE(t.pic_name,'Tanpa PIC') pic_name,
                t.invoice_no, COALESCE(SUM(a.amount),0) period_amount
         FROM transactions t
         LEFT JOIN master_clients c ON c.id=t.client_id
         JOIN transaction_allocations a ON a.transaction_id=t.id AND a.period_key=? AND a.property_id=?
         WHERE t.deleted_at IS NULL AND t.property_id=?
         GROUP BY t.id, t.module, t.master_code, c.company_name, t.start_date, t.end_date, t.pic_name, t.invoice_no
         ORDER BY t.pic_name, period_amount DESC"
    );
    $trxStmt->execute([$period, $pid, $pid]);
    $trxByPic = [];
    foreach ($trxStmt->fetchAll() as $trx) {
        $trxByPic[$trx['pic_name']][] = $trx;
    }

    $moduleLabel = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
    $totalActual = array_sum(array_column($pics, 'actual_total'));

    layout('Laporan PIC', function () use ($pics, $trxByPic, $target, $totalActual, $period, $periods, $moduleLabel) {
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

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px">
            <?php foreach ([
                ['Target Bulan Ini', money($target)],
                ['Total Actual', money($totalActual)],
                ['Achievement', $target > 0 ? pct($totalActual / $target) : '—'],
                ['Jumlah PIC Aktif', count($pics)],
            ] as [$lbl, $val]): ?>
            <div class="panel" style="padding:14px 16px;margin:0">
                <div class="kpi-label"><?= $lbl ?></div>
                <div class="kpi-value" style="font-size:20px"><?= $val ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="panel" style="margin-bottom:16px">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Nama PIC</th><th>Role</th>
                            <th class="r">Target Posisi</th>
                            <th class="r">Exhibition</th><th class="r">Media</th><th class="r">Gudang</th>
                            <th class="r">Total Actual</th><th class="r">Achievement</th><th class="r">Trx</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pics as $i => $p):
                        $targetPosisi = (float)$p['target_share'] * $target;
                        $ach = $targetPosisi > 0 ? $p['actual_total'] / $targetPosisi : 0;
                        $achColor = $ach >= 1 ? '#16a34a' : ($ach >= 0.8 ? '#d97706' : '#dc2626');
                    ?>
                    <tr>
                        <td>
                            <a href="#pic-<?= $i ?>" onclick="var s=document.getElementById('pic-<?= $i ?>');s.style.display=s.style.display==='none'?'table-row-group':'none';return false"
                               style="font-weight:600;color:var(--primary);cursor:pointer;text-decoration:none"><?= h($p['name']) ?></a>
                        </td>
                        <td style="font-size:12px;color:var(--muted)"><?= h($p['role_name']) ?></td>
                        <td class="r"><?= money($targetPosisi) ?></td>
                        <td class="r"><?= money($p['actual_cl']) ?></td>
                        <td class="r"><?= money($p['actual_media']) ?></td>
                        <td class="r"><?= money($p['actual_gudang']) ?></td>
                        <td class="r" style="font-weight:700"><?= money($p['actual_total']) ?></td>
                        <td class="r"><span style="font-weight:700;color:<?= $achColor ?>"><?= pct($ach) ?></span></td>
                        <td class="r"><?= $p['trx_count'] ?></td>
                    </tr>
                    <?php if (!empty($trxByPic[$p['name']])): ?>
                    <tr id="pic-<?= $i ?>" style="display:none">
                        <td colspan="9" style="padding:0;background:#F8FAFC">
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
                                    <td style="padding:5px 10px"><?= h($trx['company_name']) ?></td>
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
                        <td class="r"><?= money($totalActual) ?></td>
                        <td class="r"><span style="font-weight:700;color:<?= $target>0 ? ($totalActual/$target>=1?'#16a34a':($totalActual/$target>=0.8?'#d97706':'#dc2626')) : 'inherit' ?>"><?= $target > 0 ? pct($totalActual/$target) : '—' ?></span></td>
                        <td class="r"><?= array_sum(array_column($pics,'trx_count')) ?></td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <p style="font-size:12px;color:var(--muted)">Klik nama PIC untuk melihat daftar transaksi di periode ini.</p>
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
        "SELECT t.id, t.module, t.master_code, COALESCE(c.company_name,'-') company_name,
                t.start_date, t.end_date, COALESCE(t.pic_name,'Tanpa PIC') pic_name,
                t.invoice_no, COALESCE(SUM(a.amount),0) period_amount
         FROM transactions t
         LEFT JOIN master_clients c ON c.id=t.client_id
         JOIN transaction_allocations a ON a.transaction_id=t.id AND a.period_key=? AND a.property_id=?
         WHERE t.deleted_at IS NULL AND t.property_id=?
         GROUP BY t.id, t.module, t.master_code, c.company_name, t.start_date, t.end_date, t.pic_name, t.invoice_no
         ORDER BY t.pic_name, period_amount DESC"
    );
    $trxStmt->execute([$period, $pid, $pid]);
    $trxByPic = [];
    foreach ($trxStmt->fetchAll() as $trx) {
        $trxByPic[$trx['pic_name']][] = $trx;
    }

    $totalActual = array_sum(array_column($pics, 'actual_total'));
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
@import url() /* removed - using system font */;
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;font-size:11px;color:#0F1623;background:#fff}
@page{size:A4 landscape;margin:14mm 12mm}
.no-print{position:fixed;top:16px;right:16px;display:flex;gap:8px;z-index:99}
.no-print button{padding:9px 18px;border:none;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer}
.btn-print{background:#0D9488;color:#fff}
.btn-close{background:#f1f5f9;color:#334155}
@media print{.no-print{display:none}}
h1{font-size:16px;font-weight:800;margin-bottom:2px}
.sub{font-size:11px;color:#64748B;margin-bottom:14px}
table{width:100%;border-collapse:collapse;font-size:10px}
th{background:#0D9488;color:#fff;padding:5px 7px;text-align:left;font-weight:700}
th.r,td.r{text-align:right}
td{padding:4px 7px;border-bottom:1px solid #E2E8F0;vertical-align:top}
tr:nth-child(even){background:#F8FAFC}
.total-row td{font-weight:700;border-top:2px solid #0D9488;background:#F0FDF9}
.section-header{background:#1E3A5F;color:#fff;padding:5px 7px;font-weight:700;font-size:10px;margin-top:12px}
.ach-good{color:#16a34a;font-weight:700}
.ach-warn{color:#d97706;font-weight:700}
.ach-bad{color:#dc2626;font-weight:700}
.trx-table th{background:#334155}
.pic-section{margin-top:14px;page-break-inside:avoid}
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
.kpi-box{border:1px solid #E2E8F0;border-radius:6px;padding:8px 12px}
.kpi-label{font-size:9px;color:#64748B;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px}
.kpi-val{font-size:16px;font-weight:800;color:#0D9488}
</style>
</head>
<body>
<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨 Cetak / Simpan PDF</button>
    <button class="btn-close" onclick="window.close()">✕ Tutup</button>
</div>

<h1>Laporan Pencapaian PIC — <?= h($periodLabel) ?></h1>
<div class="sub"><?= h($appName) ?> &middot; Dicetak: <?= date('d/m/Y H:i') ?></div>

<div class="kpi-grid">
    <?php
    $ach = $target > 0 ? $totalActual / $target : 0;
    $achCls = $ach >= 1 ? 'ach-good' : ($ach >= 0.8 ? 'ach-warn' : 'ach-bad');
    foreach ([
        ['Target Bulan Ini', money($target)],
        ['Total Actual', money($totalActual)],
        ['Achievement', pct($ach)],
        ['Jumlah PIC', count($pics)],
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
            <th class="r">Total Actual</th><th class="r">Achievement</th><th class="r">Trx</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($pics as $p):
        $tp  = (float)$p['target_share'] * $target;
        $a   = $tp > 0 ? $p['actual_total'] / $tp : 0;
        $cls = $a >= 1 ? 'ach-good' : ($a >= 0.8 ? 'ach-warn' : 'ach-bad');
    ?>
    <tr>
        <td style="font-weight:700"><?= h($p['name']) ?></td>
        <td style="color:#64748B"><?= h($p['role_name']) ?></td>
        <td class="r"><?= money($tp) ?></td>
        <td class="r"><?= money($p['actual_cl']) ?></td>
        <td class="r"><?= money($p['actual_media']) ?></td>
        <td class="r"><?= money($p['actual_gudang']) ?></td>
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
        <td class="r"><?= money($totalActual) ?></td>
        <td class="r"><span class="<?= $achCls ?>"><?= pct($ach) ?></span></td>
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
?>
<div class="pic-section">
    <div class="section-header"><?= h($p['name']) ?> — <?= h($p['role_name']) ?> &nbsp;|&nbsp; Actual: <?= money($p['actual_total']) ?> &nbsp;|&nbsp; Achievement: <span class="<?= $cls ?>"><?= pct($a) ?></span></div>
    <table class="trx-table">
        <thead><tr>
            <th>Kode</th><th>Client</th><th>Modul</th><th>Periode Kontrak</th><th>No. Invoice</th><th class="r">Aktual Bulan Ini</th>
        </tr></thead>
        <tbody>
        <?php foreach ($trxByPic[$p['name']] as $trx): ?>
        <tr>
            <td><?= h($trx['master_code']) ?></td>
            <td><?= h($trx['company_name']) ?></td>
            <td><?= h($moduleLabel[$trx['module']] ?? $trx['module']) ?></td>
            <td style="white-space:nowrap"><?= h($trx['start_date'] . ' s/d ' . $trx['end_date']) ?></td>
            <td style="color:#64748B"><?= h($trx['invoice_no'] ?? '-') ?></td>
            <td class="r" style="font-weight:700"><?= money($trx['period_amount']) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="font-weight:700;background:#F0FDF9">
            <td colspan="5" style="text-align:right">Total <?= h($p['name']) ?></td>
            <td class="r"><?= money($p['actual_total']) ?></td>
        </tr>
        </tbody>
    </table>
</div>
<?php endforeach; ?>
</body>
</html>
<?php
}
