<?php
declare(strict_types=1);

// ─── Kategorisasi role → commission group ──────────────────────────────────
function _csim_cat(string $roleName, string $picName): string
{
    if (strtolower($picName) === 'wbl unit') return 'other';
    $r = strtolower($roleName);
    if (str_contains($r, 'asst') || str_contains($r, 'assistant')) return 'asst_manager';
    if (str_contains($r, 'manager'))  return 'manager';
    if (str_contains($r, 'admin'))    return 'admin';
    if (str_contains($r, 'sales') || str_contains($r, 'executive')) return 'sales';
    if ($roleName === '') return 'other';
    return 'other';
}

// ─── Shared data builder ────────────────────────────────────────────────────
function _csim_build_data(PDO $pdo, string $period, int $pid): array
{
    $rateTable = [
        'sales'        => ['achieved' => 0.022,  'not' => 0.0065],
        'manager'      => ['achieved' => 0.007,  'not' => 0.003],
        'asst_manager' => ['achieved' => 0.0055, 'not' => 0.0025],
        'admin'        => ['achieved' => 0.0015, 'not' => 0.0007],
        'other'        => ['achieved' => 0.001,  'not' => 0.0009],
    ];
    $catLabel = [
        'sales'        => 'Sales',
        'manager'      => 'Manager',
        'asst_manager' => 'Asst. Manager',
        'admin'        => 'Admin',
        'other'        => 'Other',
    ];
    $monthNames = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni',
                   '07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];

    $s = $pdo->prepare("SELECT target_amount FROM targets_monthly WHERE period_key=? AND property_id=?");
    $s->execute([$period, $pid]);
    $target = (float)($s->fetchColumn() ?: 0);

    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transaction_allocations WHERE period_key=? AND property_id=?");
    $s->execute([$period, $pid]);
    $totalRevenue = (float)$s->fetchColumn();

    $achieved  = $target > 0 && $totalRevenue >= $target;
    $rateKey   = $achieved ? 'achieved' : 'not';
    $totalPool = $totalRevenue * ($achieved ? 0.037 : 0.0136);

    $s = $pdo->prepare(
        "SELECT p.name, COALESCE(p.role_name,'') role_name, p.commission_cat cat,
                COALESCE(SUM(a.amount),0) dealing
         FROM master_pic p
         LEFT JOIN transaction_allocations a ON a.pic_name=p.name AND a.period_key=? AND a.property_id=?
         WHERE p.status='active' AND p.property_id=? AND p.commission_cat IS NOT NULL AND p.commission_cat != ''
         GROUP BY p.id ORDER BY dealing DESC"
    );
    $s->execute([$period, $pid, $pid]);
    $pics = $s->fetchAll();

    // Referrer detail: per referrer × per PIC yang dibantu
    $s = $pdo->prepare(
        "SELECT t.referrer_name, t.pic_name, COALESCE(SUM(a.amount),0) referred_amount,
                mr.jabatan, mr.no_rekening, mr.nama_bank
         FROM transaction_allocations a
         JOIN transactions t ON t.id = a.transaction_id
         LEFT JOIN master_referrer mr ON mr.name = t.referrer_name
         WHERE a.period_key=? AND a.property_id=? AND t.referrer_name IS NOT NULL AND t.referrer_name != ''
         GROUP BY t.referrer_name, t.pic_name, mr.jabatan, mr.no_rekening, mr.nama_bank
         ORDER BY t.referrer_name ASC, referred_amount DESC"
    );
    $s->execute([$period, $pid]);
    $referrerDetails = $s->fetchAll();

    $referrers          = [];
    $picReferredDealing = [];
    $picReferrerNames   = [];
    foreach ($referrerDetails as $row) {
        $rname = $row['referrer_name'];
        $pname = $row['pic_name'];
        $amt   = (float)$row['referred_amount'];
        $referrers[$rname]                = ($referrers[$rname] ?? 0.0) + $amt;
        $picReferredDealing[$pname]       = ($picReferredDealing[$pname] ?? 0.0) + $amt;
        $picReferrerNames[$pname][$rname] = ($picReferrerNames[$pname][$rname] ?? 0.0) + $amt;
    }

    $referrerRate = 0.01;

    foreach ($pics as &$p) {
        $cat = $p['cat'];
        if ($cat === 'sales') {
            $referred = $picReferredDealing[$p['name']] ?? 0.0;
            $gross    = (float)$p['dealing'] * $rateTable['sales'][$rateKey];
            $deduct   = $referred * $referrerRate;
            $p['commission']       = $gross - $deduct;
            $p['referrer_deduct']  = $deduct;
            $p['referred_dealing'] = $referred;
        } else {
            $p['commission']       = $totalRevenue * $rateTable[$cat][$rateKey];
            $p['referrer_deduct']  = 0.0;
            $p['referred_dealing'] = 0.0;
        }
        $p['pool_pct'] = null;
    }
    unset($p);

    // Urutan: manager → asst_manager → sales executive → sales → admin → other
    $catOrder = ['manager' => 1, 'asst_manager' => 2, 'sales' => 3, 'admin' => 4, 'other' => 5];
    usort($pics, function (array $a, array $b) use ($catOrder): int {
        $oa = $catOrder[$a['cat']] ?? 9;
        $ob = $catOrder[$b['cat']] ?? 9;
        if ($oa !== $ob) return $oa - $ob;
        if ($a['cat'] === 'sales') {
            $aExec = str_contains(strtolower($a['role_name']), 'executive') ? 0 : 1;
            $bExec = str_contains(strtolower($b['role_name']), 'executive') ? 0 : 1;
            if ($aExec !== $bExec) return $aExec - $bExec;
        }
        return (float)$b['dealing'] <=> (float)$a['dealing'];
    });

    $totalReferrerCommission = array_sum($referrers) * $referrerRate;
    $totalCommission         = array_sum(array_column($pics, 'commission'));

    return compact(
        'rateTable', 'catLabel', 'monthNames',
        'target', 'totalRevenue', 'achieved', 'rateKey', 'totalPool',
        'pics', 'referrerDetails', 'referrers', 'picReferredDealing',
        'picReferrerNames', 'referrerRate',
        'totalReferrerCommission', 'totalCommission'
    );
}

// ─── Main entry ────────────────────────────────────────────────────────────
function commission_sim(PDO $pdo): void
{
    require_permission('view_commission_sim');

    $period = getv('period', date('Y-m'));
    $pid    = current_property_id();

    if (getv('action') === 'print') {
        _csim_print_view($pdo, $period, $pid);
        return;
    }

    $d = _csim_build_data($pdo, $period, $pid);
    extract($d);

    $allPeriods = $pdo->query(
        "SELECT DISTINCT period_key FROM transaction_allocations ORDER BY period_key DESC LIMIT 36"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($period, $allPeriods, true)) array_unshift($allPeriods, $period);

    layout('Simulasi Komisi PIC', function () use (
        $pics, $period, $target, $totalRevenue, $achieved, $rateKey, $rateTable,
        $catLabel, $totalCommission, $allPeriods, $monthNames,
        $referrers, $referrerDetails, $totalReferrerCommission, $referrerRate,
        $picReferrerNames, $totalPool
    ) {
        $achPct = $target > 0 ? $totalRevenue / $target * 100 : 0;
        $my = substr($period, 5, 2); $yy = substr($period, 0, 4);
        $periodLabel = ($monthNames[$my] ?? $my) . ' ' . $yy;
        ?>
        <style>
            .csim-card{background:#fff;border:1px solid var(--line,#e2e8f0);border-radius:10px;padding:16px 20px}
            .csim-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted,#64748b);margin-bottom:4px}
            .csim-value{font-size:20px;font-weight:800;color:var(--ink,#0f172a)}
            .rate-tbl th,.rate-tbl td{padding:7px 14px;font-size:12px;border-bottom:1px solid var(--line,#f1f5f9)}
            .rate-tbl th{background:#f8fafc;font-weight:700;color:var(--muted)}
            .rate-active{background:#dcfce7!important;color:#15803d;font-weight:800}
            .rate-inactive{color:#94a3b8}
            .sim-tbl th,.sim-tbl td{padding:9px 14px;font-size:12px;border-bottom:1px solid var(--line,#f1f5f9)}
            .sim-tbl th{background:#f8fafc;font-weight:700;color:var(--muted);text-transform:uppercase;font-size:10px;letter-spacing:.05em}
            .badge-ach{display:inline-block;padding:4px 12px;border-radius:999px;font-size:12px;font-weight:700}
            .badge-yes{background:#dcfce7;color:#15803d}
            .badge-no{background:#fee2e2;color:#b91c1c}
            .note-sim{background:#fefce8;border:1px solid #fef08a;border-radius:8px;padding:10px 14px;font-size:11px;color:#854d0e;margin-bottom:16px}
        </style>

        <!-- Toolbar -->
        <form method="get" style="display:flex;align-items:center;gap:10px;margin-bottom:20px;flex-wrap:wrap">
            <input type="hidden" name="r" value="commission_sim">
            <label style="font-size:11px;font-weight:600;color:var(--muted)">Periode</label>
            <select name="period" onchange="this.form.submit()" style="min-width:160px">
                <?php foreach ($allPeriods as $pk):
                    $y = substr($pk,0,4); $m = substr($pk,5,2);
                    $lbl = ($monthNames[$m] ?? $m) . ' ' . $y;
                ?>
                <option value="<?= h($pk) ?>" <?= $pk === $period ? 'selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
            </select>
            <a class="btn" style="background:#7c3aed;color:#fff;display:inline-flex;align-items:center;gap:6px"
               href="?r=commission_sim&action=print&period=<?= h($period) ?>" target="_blank">
               <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
               Cetak / Ajukan
            </a>
        </form>

        <div class="note-sim">
            ⚠️ <strong>Simulasi</strong> — angka ini belum final. Kategori role PIC menggunakan pemetaan otomatis dari <code>role_name</code>. Basis komisi = total revenue termasuk recurring (sementara).
        </div>

        <!-- Summary cards -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:20px">
            <div class="csim-card" style="border-top:3px solid #0d9488">
                <div class="csim-label">Total Revenue (Basis)</div>
                <div class="csim-value"><?= money($totalRevenue) ?></div>
            </div>
            <div class="csim-card" style="border-top:3px solid #3b82f6">
                <div class="csim-label">Target</div>
                <div class="csim-value"><?= money($target) ?></div>
            </div>
            <div class="csim-card" style="border-top:3px solid <?= $achieved ? '#16a34a' : '#dc2626' ?>">
                <div class="csim-label">Achievement</div>
                <div class="csim-value" style="font-size:16px;margin-bottom:4px"><?= number_format($achPct, 1, ',', '.') ?>%</div>
                <span class="badge-ach <?= $achieved ? 'badge-yes' : 'badge-no' ?>"><?= $achieved ? '✓ Tercapai' : '✗ Tidak Tercapai' ?></span>
            </div>
            <div class="csim-card" style="border-top:3px solid #f59e0b">
                <div class="csim-label">Total Pool Komisi (<?= $achieved ? '3,7%' : '1,36%' ?>)</div>
                <div class="csim-value" style="color:#b45309"><?= money($totalPool) ?></div>
            </div>
        </div>

        <!-- Rate table -->
        <div class="panel" style="padding:0;overflow:hidden;margin-bottom:20px">
            <div style="padding:12px 16px;font-size:12px;font-weight:700;border-bottom:1px solid var(--line,#e2e8f0)">
                Tarif Komisi — kolom <span style="color:<?= $achieved ? '#15803d' : '#b91c1c' ?>;font-weight:800"><?= $achieved ? 'Tercapai' : 'Tidak Tercapai' ?></span> yang berlaku
            </div>
            <table class="rate-tbl" style="width:100%;border-collapse:collapse">
                <thead>
                    <tr>
                        <th style="text-align:left">Role</th>
                        <th style="text-align:right">Tercapai</th>
                        <th style="text-align:right">Tidak Tercapai</th>
                        <th style="text-align:right">Nominal Berlaku</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $catOrder = ['sales','manager','asst_manager','admin','other'];
                foreach ($catOrder as $cat):
                    $r = $rateTable[$cat];
                    $activeVal = $totalRevenue * $r[$rateKey];
                    $isSales = $cat === 'sales';
                    $noteStr = $isSales ? ' <span style="font-size:10px;color:#0369a1">(individual per PIC)</span>' : '';
                ?>
                <tr>
                    <td style="font-weight:600"><?= $catLabel[$cat] . $noteStr ?></td>
                    <td class="<?= $achieved ? 'rate-active' : 'rate-inactive' ?>" style="text-align:right"><?= number_format($r['achieved']*100, 2, ',', '.') ?>%</td>
                    <td class="<?= !$achieved ? 'rate-active' : 'rate-inactive' ?>" style="text-align:right"><?= number_format($r['not']*100, 2, ',', '.') ?>%</td>
                    <td style="text-align:right;font-weight:700"><?= money($activeVal) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f8fafc;font-weight:800;border-top:2px solid var(--line,#e2e8f0)">
                        <td style="padding:8px 14px">Total</td>
                        <td style="padding:8px 14px;text-align:right;color:<?= $achieved ? '#15803d' : '#94a3b8' ?>">3,7%</td>
                        <td style="padding:8px 14px;text-align:right;color:<?= !$achieved ? '#b91c1c' : '#94a3b8' ?>">1,36%</td>
                        <td style="padding:8px 14px;text-align:right"><?= money($totalCommission) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Per-PIC simulation -->
        <div class="panel" style="padding:0;overflow:hidden">
            <div style="padding:12px 16px;font-size:12px;font-weight:700;border-bottom:1px solid var(--line,#e2e8f0)">
                Breakdown per PIC
            </div>
            <table class="sim-tbl" style="width:100%;border-collapse:collapse">
                <thead>
                    <tr>
                        <th style="text-align:left">#</th>
                        <th style="text-align:left">Nama PIC</th>
                        <th style="text-align:left">Kategori</th>
                        <th style="text-align:right">Basis Komisi</th>
                        <th style="text-align:right">Rate</th>
                        <th style="text-align:right">Potongan Referrer</th>
                        <th style="text-align:right">Komisi Bersih</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pics as $i => $p):
                    $isSales  = $p['cat'] === 'sales';
                    $catColor = match($p['cat']) {
                        'sales'        => '#0d9488',
                        'manager'      => '#7c3aed',
                        'asst_manager' => '#0891b2',
                        'admin'        => '#d97706',
                        default        => '#64748b',
                    };
                    $basis = $isSales ? (float)$p['dealing'] : $totalRevenue;
                    $rate  = $rateTable[$p['cat']][$rateKey];
                ?>
                <tr style="<?= $p['cat'] === 'other' && strtolower($p['name']) === 'wbl unit' ? 'color:#94a3b8' : '' ?>">
                    <td style="color:#94a3b8;font-weight:700"><?= $i+1 ?></td>
                    <td>
                        <div style="font-weight:600"><?= h($p['name']) ?></div>
                        <div style="font-size:10px;color:var(--muted)"><?= h($p['role_name'] ?: '') ?></div>
                    </td>
                    <td>
                        <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:700;background:<?= $catColor ?>22;color:<?= $catColor ?>">
                            <?= $catLabel[$p['cat']] ?>
                        </span>
                    </td>
                    <td style="text-align:right">
                        <?= money($basis) ?>
                        <?php if ($isSales): ?><div style="font-size:10px;color:var(--muted)">dealing sendiri</div><?php else: ?><div style="font-size:10px;color:var(--muted)">total revenue</div><?php endif; ?>
                    </td>
                    <td style="text-align:right;font-weight:700;color:<?= $catColor ?>">
                        <?= number_format($rate * 100, 2, ',', '.') ?>%
                    </td>
                    <td style="text-align:right">
                        <?php if ($isSales && $p['referrer_deduct'] > 0):
                            $myRefs = $picReferrerNames[$p['name']] ?? [];
                        ?>
                            <span style="color:#dc2626;font-weight:700">−<?= money($p['referrer_deduct']) ?></span>
                            <div style="font-size:10px;color:var(--muted)"><?= money($p['referred_dealing']) ?> × 1%</div>
                            <?php foreach ($myRefs as $rname => $ramt): ?>
                                <div style="font-size:10px;color:#7c3aed;margin-top:2px">via <?= h($rname) ?> (<?= money($ramt) ?>)</div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span style="color:#94a3b8">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;font-weight:800;color:<?= $p['commission'] > 0 ? '#15803d' : ($p['commission'] < 0 ? '#dc2626' : '#94a3b8') ?>">
                        <?php if ($p['commission'] > 0): ?>
                            <?= money($p['commission']) ?>
                        <?php elseif ($p['commission'] < 0): ?>
                            −<?= money(abs($p['commission'])) ?>
                            <div style="font-size:10px;font-weight:600">potongan melebihi komisi</div>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f0fdf4;font-weight:800;border-top:2px solid #86efac">
                        <td colspan="3" style="padding:10px 14px">Total PIC</td>
                        <td style="padding:10px 14px;text-align:right"><?= money(array_sum(array_column($pics,'dealing'))) ?></td>
                        <td></td>
                        <td style="padding:10px 14px;text-align:right;color:#dc2626">
                            <?= $totalReferrerCommission > 0 ? '−'.money($totalReferrerCommission) : '—' ?>
                        </td>
                        <td style="padding:10px 14px;text-align:right;color:#15803d"><?= money($totalCommission) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Referrer commissions detail -->
        <?php if (!empty($referrerDetails)): ?>
        <div class="panel" style="padding:0;overflow:hidden;margin-top:16px">
            <div style="padding:12px 16px;font-size:12px;font-weight:700;border-bottom:1px solid var(--line,#e2e8f0);display:flex;justify-content:space-between;align-items:center">
                <span>Komisi Referrer — Detail per Sales yang Dibantu</span>
                <span style="color:#7c3aed;font-weight:800"><?= money($totalReferrerCommission) ?></span>
            </div>
            <table class="sim-tbl" style="width:100%;border-collapse:collapse">
                <thead>
                    <tr>
                        <th style="text-align:left">Referrer</th>
                        <th style="text-align:left">Membantu Sales</th>
                        <th style="text-align:right">Dealing Direferensikan</th>
                        <th style="text-align:right">Rate</th>
                        <th style="text-align:right">Komisi</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $prevReferrer = null;
                $detailIdx    = 0;
                foreach ($referrerDetails as $row):
                    $isFirst      = $row['referrer_name'] !== $prevReferrer;
                    $prevReferrer = $row['referrer_name'];
                    $rowTotal     = $referrers[$row['referrer_name']];
                    $detailIdx++;
                ?>
                <tr style="<?= $isFirst && $detailIdx > 1 ? 'border-top:2px solid var(--line,#e2e8f0)' : '' ?>">
                    <td style="font-weight:700;color:#7c3aed;vertical-align:top">
                        <?php if ($isFirst): ?>
                            <?= h($row['referrer_name']) ?>
                            <div style="font-size:10px;font-weight:400;color:var(--muted)">total: <?= money($rowTotal * $referrerRate) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight:600"><?= h($row['pic_name']) ?></td>
                    <td style="text-align:right"><?= money($row['referred_amount']) ?></td>
                    <td style="text-align:right;font-weight:700;color:#7c3aed">1,00%</td>
                    <td style="text-align:right;font-weight:800;color:#7c3aed"><?= money((float)$row['referred_amount'] * $referrerRate) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#faf5ff;font-weight:800;border-top:2px solid #d8b4fe">
                        <td colspan="2" style="padding:10px 14px">Total Referrer</td>
                        <td style="padding:10px 14px;text-align:right"><?= money(array_sum($referrers)) ?></td>
                        <td></td>
                        <td style="padding:10px 14px;text-align:right;color:#7c3aed"><?= money($totalReferrerCommission) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endif; ?>

        <!-- Grand total -->
        <div style="margin-top:12px;display:flex;gap:12px;flex-wrap:wrap">
            <div style="flex:1;min-width:220px;padding:12px 16px;background:#f0fdf4;border-radius:8px;border:1px solid #86efac;font-size:12px">
                <div style="font-weight:700;margin-bottom:6px">Total Komisi PIC + Referrer</div>
                <div style="font-size:18px;font-weight:800;color:#15803d"><?= money($totalCommission + $totalReferrerCommission) ?></div>
            </div>
            <div style="flex:2;min-width:280px;padding:12px 16px;background:#f8fafc;border-radius:8px;border:1px solid var(--line,#e2e8f0);font-size:11px;color:#475569">
                <strong>Sales</strong>: komisi = rate × dealing sendiri − 1% × dealing yang punya referrer.<br>
                <strong>Non-Sales</strong>: komisi = rate × total revenue properti.<br>
                <strong>Referrer</strong>: komisi = 1% × total dealing yang ia referensikan (periode ini).
            </div>
        </div>
        <?php
    });
}

// ─── Print view ─────────────────────────────────────────────────────────────
function _csim_print_view(PDO $pdo, string $period, int $pid): void
{
    $d = _csim_build_data($pdo, $period, $pid);
    extract($d);

    $prop    = current_property();
    $propName = $prop['name'] ?? 'Property';
    $my      = substr($period, 5, 2);
    $yy      = substr($period, 0, 4);
    $periodLabel = ($monthNames[$my] ?? $my) . ' ' . $yy;
    $achPct  = $target > 0 ? $totalRevenue / $target * 100 : 0;
    $grandTotal = $totalCommission + $totalReferrerCommission;
    $printedOn  = date('d/m/Y H:i');

    // Output standalone print page — no layout wrapper
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Form Pengajuan Komisi — <?= h($periodLabel) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;font-size:11px;color:#1e293b;background:#fff}
.page{width:210mm;min-height:297mm;margin:0 auto;padding:14mm 16mm 14mm}

/* Letterhead */
.lh{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2.5px solid #0d9488;padding-bottom:10px;margin-bottom:14px}
.lh-left h1{font-size:16px;font-weight:800;color:#0d9488;letter-spacing:.02em}
.lh-left p{font-size:10px;color:#64748b;margin-top:2px}
.lh-right{text-align:right;font-size:10px;color:#64748b;line-height:1.7}
.doc-title{text-align:center;margin-bottom:14px}
.doc-title h2{font-size:14px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#1e293b}
.doc-title p{font-size:10px;color:#64748b;margin-top:3px}

/* Info grid */
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid #cbd5e1;border-radius:4px;overflow:hidden;margin-bottom:14px}
.info-cell{padding:6px 10px;border-bottom:1px solid #e2e8f0;border-right:1px solid #e2e8f0}
.info-cell:nth-child(even){border-right:none}
.info-cell:nth-last-child(-n+2){border-bottom:none}
.info-label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:2px}
.info-val{font-size:12px;font-weight:700;color:#1e293b}
.badge-ach{display:inline-block;padding:2px 10px;border-radius:99px;font-size:10px;font-weight:700}
.badge-yes{background:#dcfce7;color:#15803d}
.badge-no{background:#fee2e2;color:#b91c1c}

/* Tables */
.tbl{width:100%;border-collapse:collapse;margin-bottom:14px;font-size:10.5px}
.tbl th{background:#f1f5f9;font-weight:700;padding:6px 8px;border:1px solid #cbd5e1;text-align:left;font-size:9.5px;text-transform:uppercase;letter-spacing:.04em;color:#475569}
.tbl td{padding:5px 8px;border:1px solid #e2e8f0;vertical-align:top}
.tbl tfoot td{background:#f8fafc;font-weight:700;border-top:2px solid #94a3b8}
.tbl .num{text-align:right}
.tbl .ctr{text-align:center}
.sec-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#475569;margin:16px 0 6px;border-bottom:1px solid #e2e8f0;padding-bottom:4px}
.total-box{border:2px solid #0d9488;border-radius:6px;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.total-box .tl{font-size:11px;font-weight:700;color:#0f766e}
.total-box .tv{font-size:15px;font-weight:800;color:#0d9488}
.neg{color:#dc2626}
.ref-name{font-weight:700;color:#7c3aed}

/* Signature */
.sig-section{margin-top:24px;border-top:1px solid #e2e8f0;padding-top:14px}
.sig-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.sig-box{text-align:center}
.sig-box .sig-role{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin-bottom:4px}
.sig-box .sig-date{font-size:9px;color:#94a3b8;margin-bottom:48px}
.sig-box .sig-line{border-bottom:1.5px solid #94a3b8;margin:0 10px 4px}
.sig-box .sig-name{font-size:9px;color:#94a3b8}
.disclaimer{font-size:9px;color:#94a3b8;text-align:center;margin-top:16px;border-top:1px solid #f1f5f9;padding-top:8px}

/* Print controls (screen only) */
.print-bar{display:flex;gap:10px;justify-content:flex-end;padding:10px 16mm;background:#f8fafc;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:10}
.btn-print{padding:7px 20px;background:#0d9488;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer}
.btn-back{padding:7px 16px;background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;border-radius:6px;font-size:12px;cursor:pointer;text-decoration:none}

@media print{
    .print-bar{display:none}
    .page{padding:10mm 12mm;width:100%}
    body{font-size:10px}
}
</style>
</head>
<body>

<div class="print-bar">
    <a class="btn-back" href="?r=commission_sim&period=<?= h($period) ?>">← Kembali</a>
    <button class="btn-print" onclick="window.print()">🖨 Cetak Dokumen</button>
</div>

<div class="page">

    <!-- Letterhead -->
    <div class="lh">
        <div class="lh-left">
            <h1>PT. Wulandari Bangun Laksana Tbk.</h1>
            <p><?= h($propName) ?> · Divisi <?= h($propName) ?></p>
        </div>
        <div class="lh-right">
            Dicetak: <?= h($printedOn) ?><br>
            No. Dok: KOM/<?= h($yy) ?>/<?= h($my) ?>/<?= str_pad((string)$pid, 2, '0', STR_PAD_LEFT) ?>
        </div>
    </div>

    <!-- Title -->
    <div class="doc-title">
        <h2>Formulir Pengajuan Komisi</h2>
        <p>Periode <?= h($periodLabel) ?> &mdash; <?= h($propName) ?></p>
    </div>

    <!-- Info -->
    <div class="info-grid">
        <div class="info-cell">
            <div class="info-label">Periode</div>
            <div class="info-val"><?= h($periodLabel) ?></div>
        </div>
        <div class="info-cell">
            <div class="info-label">Status Pencapaian</div>
            <div class="info-val">
                <span class="badge-ach <?= $achieved ? 'badge-yes' : 'badge-no' ?>">
                    <?= $achieved ? '✓ TARGET TERCAPAI' : '✗ TIDAK TERCAPAI' ?>
                </span>
            </div>
        </div>
        <div class="info-cell">
            <div class="info-label">Total Revenue</div>
            <div class="info-val"><?= money($totalRevenue) ?></div>
        </div>
        <div class="info-cell">
            <div class="info-label">Target Bulan Ini</div>
            <div class="info-val"><?= money($target) ?></div>
        </div>
        <div class="info-cell">
            <div class="info-label">Pencapaian</div>
            <div class="info-val"><?= number_format($achPct, 1, ',', '.') ?>%</div>
        </div>
        <div class="info-cell">
            <div class="info-label">Rate yang Berlaku</div>
            <div class="info-val"><?= $achieved ? 'Tercapai' : 'Tidak Tercapai' ?></div>
        </div>
    </div>

    <!-- PIC Commission Table -->
    <div class="sec-title">A. Komisi PIC</div>
    <table class="tbl">
        <thead>
            <tr>
                <th class="ctr" style="width:28px">No</th>
                <th>Nama PIC</th>
                <th>Jabatan / Kategori</th>
                <th class="num">Basis Komisi</th>
                <th class="num" style="width:54px">Rate</th>
                <th class="num">Pot. Referrer</th>
                <th class="num">Komisi Bersih</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($pics as $i => $p):
            $isSales = $p['cat'] === 'sales';
            $basis   = $isSales ? (float)$p['dealing'] : $totalRevenue;
            $rate    = $rateTable[$p['cat']][$rateKey];
            $myRefs  = $picReferrerNames[$p['name']] ?? [];
        ?>
        <tr>
            <td class="ctr" style="color:#94a3b8"><?= $i+1 ?></td>
            <td style="font-weight:700"><?= h($p['name']) ?></td>
            <td>
                <?= h($p['role_name'] ?: '—') ?>
                <span style="color:#64748b"> / <?= h($catLabel[$p['cat']]) ?></span>
            </td>
            <td class="num">
                <?= money($basis) ?>
                <div style="font-size:9px;color:#94a3b8"><?= $isSales ? 'dealing sendiri' : 'total revenue' ?></div>
            </td>
            <td class="num" style="font-weight:700"><?= number_format($rate*100, 2, ',', '.') ?>%</td>
            <td class="num">
                <?php if ($isSales && $p['referrer_deduct'] > 0): ?>
                    <span class="neg" style="font-weight:700">−<?= money($p['referrer_deduct']) ?></span>
                    <?php foreach ($myRefs as $rname => $ramt): ?>
                        <div style="font-size:9px;color:#7c3aed">via <?= h($rname) ?></div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span style="color:#94a3b8">—</span>
                <?php endif; ?>
            </td>
            <td class="num" style="font-weight:800;color:<?= $p['commission'] >= 0 ? '#15803d' : '#dc2626' ?>">
                <?php if ($p['commission'] < 0): ?>
                    −<?= money(abs($p['commission'])) ?>
                <?php else: ?>
                    <?= money($p['commission']) ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" style="font-weight:700">Total Komisi PIC</td>
                <td class="num"><?= money(array_sum(array_column($pics,'dealing'))) ?></td>
                <td></td>
                <td class="num neg"><?= $totalReferrerCommission > 0 ? '−'.money($totalReferrerCommission) : '—' ?></td>
                <td class="num" style="color:#15803d"><?= money($totalCommission) ?></td>
            </tr>
        </tfoot>
    </table>

    <!-- Referrer Table -->
    <?php if (!empty($referrerDetails)): ?>
    <div class="sec-title">B. Komisi Referrer (1% per Dealing)</div>
    <table class="tbl">
        <thead>
            <tr>
                <th class="ctr" style="width:28px">No</th>
                <th>Nama Referrer</th>
                <th>Membantu Sales</th>
                <th class="num">Dealing Direferensikan</th>
                <th class="num" style="width:54px">Rate</th>
                <th class="num">Komisi</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $refNo = 0;
        $prevReferrer = null;
        $detailIdx    = 0;
        foreach ($referrerDetails as $row):
            $isFirst      = $row['referrer_name'] !== $prevReferrer;
            $prevReferrer = $row['referrer_name'];
            $rowTotal     = $referrers[$row['referrer_name']];
            $detailIdx++;
            if ($isFirst) $refNo++;
        ?>
        <tr style="<?= $isFirst && $detailIdx > 1 ? 'border-top:1.5px solid #94a3b8' : '' ?>">
            <td class="ctr" style="color:#94a3b8;vertical-align:top"><?= $isFirst ? $refNo : '' ?></td>
            <td style="vertical-align:top">
                <?php if ($isFirst): ?>
                    <span class="ref-name"><?= h($row['referrer_name']) ?></span>
                    <?php if (!empty($row['jabatan'])): ?>
                        <div style="font-size:9px;color:#64748b"><?= h($row['jabatan']) ?><?= !empty($row['dept']) ? ' · ' . h($row['dept']) : '' ?></div>
                    <?php endif; ?>
                    <?php if (!empty($row['no_rekening'])): ?>
                        <div style="font-size:9px;color:#374151;font-weight:600">Rek: <?= h($row['no_rekening']) ?><?= !empty($row['nama_bank']) ? ' (' . h($row['nama_bank']) . ')' : '' ?></div>
                    <?php endif; ?>
                    <div style="font-size:9px;color:#64748b">Total komisi: <?= money($rowTotal * $referrerRate) ?></div>
                <?php endif; ?>
            </td>
            <td><?= h($row['pic_name']) ?></td>
            <td class="num"><?= money($row['referred_amount']) ?></td>
            <td class="num" style="color:#7c3aed;font-weight:700">1,00%</td>
            <td class="num" style="font-weight:700;color:#7c3aed"><?= money((float)$row['referred_amount'] * $referrerRate) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" style="font-weight:700">Total Komisi Referrer</td>
                <td class="num"><?= money(array_sum($referrers)) ?></td>
                <td></td>
                <td class="num" style="color:#7c3aed"><?= money($totalReferrerCommission) ?></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>

    <!-- Grand Total -->
    <div class="total-box">
        <span class="tl">Total Komisi yang Diajukan<br><span style="font-size:9px;font-weight:400;color:#5eead4">PIC (<?= money($totalCommission) ?>) + Referrer (<?= money($totalReferrerCommission) ?>)</span></span>
        <span class="tv"><?= money($grandTotal) ?></span>
    </div>

    <!-- Signature -->
    <div class="sig-section">
        <div class="sig-grid">
            <div class="sig-box">
                <div class="sig-role">Dibuat oleh</div>
                <div class="sig-date">Tanggal: _____ / _____ / _______</div>
                <div class="sig-line"></div>
                <div class="sig-name">(____________________________)</div>
                <div style="font-size:9px;color:#64748b;margin-top:3px">Admin / HR</div>
            </div>
            <div class="sig-box">
                <div class="sig-role">Diperiksa oleh</div>
                <div class="sig-date">Tanggal: _____ / _____ / _______</div>
                <div class="sig-line"></div>
                <div class="sig-name">(____________________________)</div>
                <div style="font-size:9px;color:#64748b;margin-top:3px">Manager / Kepala Divisi</div>
            </div>
            <div class="sig-box">
                <div class="sig-role">Disetujui oleh</div>
                <div class="sig-date">Tanggal: _____ / _____ / _______</div>
                <div class="sig-line"></div>
                <div class="sig-name">(____________________________)</div>
                <div style="font-size:9px;color:#64748b;margin-top:3px">Direktur / GM</div>
            </div>
        </div>
    </div>

    <div class="disclaimer">
        Dokumen ini digenerate secara otomatis oleh sistem CLARA pada <?= h($printedOn) ?>.
        Angka komisi bersifat simulasi berdasarkan data transaksi yang telah diinput dan dapat berubah sesuai kebijakan manajemen.
    </div>

</div>
</body>
</html>
<?php
}
