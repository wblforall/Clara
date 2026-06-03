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

function commission_sim(PDO $pdo): void
{
    require_permission('view_pic_report');

    $period     = getv('period', date('Y-m'));
    $pid        = current_property_id();
    $periodDays = (int) date('t', strtotime($period . '-01'));

    // ─── Rates ───────────────────────────────────────────────────────────────
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

    // ─── Data ─────────────────────────────────────────────────────────────────
    $s = $pdo->prepare("SELECT target_amount FROM targets_monthly WHERE period_key=? AND property_id=?");
    $s->execute([$period, $pid]);
    $target = (float)($s->fetchColumn() ?: 0);

    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transaction_allocations WHERE period_key=? AND property_id=?");
    $s->execute([$period, $pid]);
    $totalRevenue = (float)$s->fetchColumn();

    $achieved  = $target > 0 && $totalRevenue >= $target;
    $rateKey   = $achieved ? 'achieved' : 'not';
    $totalPool = $totalRevenue * ($achieved ? 0.037 : 0.0136);

    // PIC + dealing — hanya yang punya commission_cat (tidak NULL)
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

    // Hitung komisi per PIC
    // Sales: rate × dealing sendiri (individual, bukan pool)
    // Non-Sales: rate × total revenue properti
    foreach ($pics as &$p) {
        $cat = $p['cat'];
        $p['commission'] = $cat === 'sales'
            ? (float)$p['dealing'] * $rateTable['sales'][$rateKey]
            : $totalRevenue * $rateTable[$cat][$rateKey];
        $p['pool_pct'] = null;
    }
    unset($p);

    $totalCommission = array_sum(array_column($pics, 'commission'));

    // Period list
    $allPeriods = $pdo->query(
        "SELECT DISTINCT period_key FROM transaction_allocations ORDER BY period_key DESC LIMIT 36"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($period, $allPeriods, true)) array_unshift($allPeriods, $period);
    $monthNames = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni',
                   '07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];

    layout('Simulasi Komisi PIC', function () use (
        $pics, $period, $target, $totalRevenue, $achieved, $rateKey, $rateTable,
        $catLabel, $totalCommission, $allPeriods, $monthNames
    ) {
        $achPct = $target > 0 ? $totalRevenue / $target * 100 : 0;
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
                    $noteStr = $isSales ? ' <span style="font-size:10px;color:#0369a1">(pool, dibagi proporsional)</span>' : '';
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
                        <th style="text-align:left">Role</th>
                        <th style="text-align:left">Kategori</th>
                        <th style="text-align:right">Basis Komisi</th>
                        <th style="text-align:right">Rate</th>
                        <th style="text-align:right">Komisi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pics as $i => $p):
                    $isSales = $p['cat'] === 'sales';
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
                    <td style="font-weight:600"><?= h($p['name']) ?></td>
                    <td style="color:var(--muted)"><?= h($p['role_name'] ?: '—') ?></td>
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
                    <td style="text-align:right;font-weight:800;color:<?= $p['commission'] > 0 ? '#15803d' : '#94a3b8' ?>">
                        <?= $p['commission'] > 0 ? money($p['commission']) : '—' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f0fdf4;font-weight:800;border-top:2px solid #86efac">
                        <td colspan="4" style="padding:10px 14px">Total</td>
                        <td style="padding:10px 14px;text-align:right"><?= money(array_sum(array_column($pics,'dealing'))) ?></td>
                        <td></td>
                        <td style="padding:10px 14px;text-align:right;color:#15803d"><?= money($totalCommission) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Sales pool note -->
        <div style="margin-top:12px;padding:10px 14px;background:#f0fdf4;border-radius:8px;border:1px solid #bbf7d0;font-size:11px;color:#15803d">
            <strong>Sales</strong>: komisi = rate × dealing sendiri per PIC (individual, bukan pool). &nbsp;
            <strong>Non-Sales</strong>: komisi = rate × total revenue properti.
        </div>
        <?php
    });
}
