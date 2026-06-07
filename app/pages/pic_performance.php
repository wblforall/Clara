<?php
declare(strict_types=1);

function pic_performance_page(PDO $pdo): void
{
    require_permission('view_pic_report');

    $pid      = current_property_id();
    $picName  = getv('pic', '');
    $today    = date('Y-m');
    $fromPeriod = getv('from', date('Y-m', strtotime('first day of -5 months')));
    $toPeriod   = getv('to',   $today);
    // clamp to not exceed current month
    if ($toPeriod > $today) $toPeriod = $today;
    if ($fromPeriod > $toPeriod) $fromPeriod = $toPeriod;

    $picList = $pdo->prepare(
        "SELECT name, role_name FROM master_pic
         WHERE property_id=? AND status='active' AND target_share > 0
         ORDER BY name ASC"
    );
    $picList->execute([$pid]);
    $picList = $picList->fetchAll();

    $rows    = [];
    $picMeta = null;
    $summary = null;

    if ($picName) {
        $s = $pdo->prepare("SELECT * FROM master_pic WHERE name=? AND property_id=? LIMIT 1");
        $s->execute([$picName, $pid]);
        $picMeta = $s->fetch() ?: null;

        // Monthly dealing per PIC + property target for that period
        $s = $pdo->prepare(
            "SELECT
                a.period_key,
                COALESCE(SUM(a.amount), 0)          AS dealing,
                COUNT(DISTINCT a.transaction_id)     AS trx_count,
                COALESCE(MAX(tm.target_amount), 0)   AS prop_target,
                COALESCE(MAX(mp.target_share), 0)    AS target_share
             FROM transaction_allocations a
             LEFT JOIN targets_monthly tm
                    ON tm.period_key = a.period_key AND tm.property_id = a.property_id
             LEFT JOIN master_pic mp
                    ON mp.name = a.pic_name AND mp.property_id = a.property_id
             WHERE a.pic_name = ? AND a.property_id = ?
               AND a.period_key >= ? AND a.period_key <= ?
             GROUP BY a.period_key
             ORDER BY a.period_key ASC"
        );
        $s->execute([$picName, $pid, $fromPeriod, $toPeriod]);
        $raw = $s->fetchAll();

        // Compute derived fields in ASC order (oldest first)
        $prevDealing = null;
        foreach ($raw as &$r) {
            $r['pic_target'] = $r['prop_target'] > 0 && $r['target_share'] > 0
                ? round((float)$r['prop_target'] * (float)$r['target_share'])
                : 0;
            $r['pct']      = $r['pic_target'] > 0
                ? round((float)$r['dealing'] / $r['pic_target'] * 100, 1)
                : null;
            $r['achieved'] = $r['pic_target'] > 0 && (float)$r['dealing'] >= $r['pic_target'];
            $r['mom_diff'] = $prevDealing !== null ? (float)$r['dealing'] - $prevDealing : null;
            $prevDealing   = (float)$r['dealing'];
        }
        unset($r);

        // Streak from most recent month backwards
        $streak = 0;
        foreach (array_reverse($raw) as $r) {
            if ($r['achieved']) $streak++;
            else break;
        }

        // Summary
        $deals = array_map(fn($r) => (float)$r['dealing'], $raw);
        $bestIdx = count($deals) ? array_search(max($deals), $deals) : false;
        $summary = [
            'avg'         => count($deals) ? round(array_sum($deals) / count($deals)) : 0,
            'best'        => count($deals) ? (int) max($deals) : 0,
            'best_period' => $bestIdx !== false ? $raw[$bestIdx]['period_key'] : '',
            'streak'      => $streak,
            'total_trx'   => array_sum(array_column($raw, 'trx_count')),
            'total'       => array_sum($deals),
        ];

        // Display newest first
        $rows = array_reverse($raw);
    }

    $mn = ['01'=>'Jan','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'Mei','06'=>'Jun',
           '07'=>'Jul','08'=>'Ags','09'=>'Sep','10'=>'Okt','11'=>'Nov','12'=>'Des'];

    layout('Performa PIC', function () use (
        $picList, $picName, $picMeta, $rows, $summary,
        $fromPeriod, $toPeriod, $mn
    ) {
        ?>

        <!-- Filter -->
        <form method="get" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-bottom:20px">
            <input type="hidden" name="r" value="pic_performance">
            <div>
                <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px">PIC</label>
                <select name="pic" style="min-width:200px">
                    <option value="">— Pilih PIC —</option>
                    <?php foreach ($picList as $p): ?>
                        <option value="<?= h($p['name']) ?>" <?= $picName === $p['name'] ? 'selected' : '' ?>>
                            <?= h($p['name']) ?><?= $p['role_name'] ? ' · ' . h($p['role_name']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px">Dari</label>
                <input type="month" name="from" value="<?= h($fromPeriod) ?>" max="<?= date('Y-m') ?>">
            </div>
            <div>
                <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px">Sampai</label>
                <input type="month" name="to" value="<?= h($toPeriod) ?>" max="<?= date('Y-m') ?>">
            </div>
            <div style="align-self:flex-end">
                <button type="submit">Tampilkan</button>
            </div>
        </form>

        <?php if (!$picName): ?>
            <div class="panel" style="text-align:center;padding:56px 32px;color:var(--muted)">
                Pilih PIC untuk melihat performa historis.
            </div>

        <?php else: ?>

        <!-- PIC Identity -->
        <div style="margin-bottom:16px;display:flex;align-items:center;gap:14px">
            <div style="width:44px;height:44px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;flex-shrink:0">
                <?= mb_strtoupper(mb_substr($picName, 0, 1)) ?>
            </div>
            <div>
                <div style="font-size:20px;font-weight:800"><?= h($picName) ?></div>
                <div style="font-size:13px;color:var(--muted)">
                    <?= h($picMeta['role_name'] ?? '—') ?>
                    <?php if (!empty($picMeta['commission_cat'])): ?>
                        &middot; <span style="text-transform:capitalize"><?= h($picMeta['commission_cat']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($summary): ?>
        <!-- Summary Cards -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px">
            <?php
            $bestLabel = $summary['best_period']
                ? $mn[substr($summary['best_period'],5,2)] . ' ' . substr($summary['best_period'],0,4)
                : '';
            $cards = [
                ['Total ' . $months . ' Bulan',    money($summary['total']),  '#0891b2', ''],
                ['Rata-rata / Bulan',               money($summary['avg']),   '#6366f1', ''],
                ['Bulan Terbaik',                   money($summary['best']),  '#0d9488', $bestLabel],
                ['Streak Tercapai',                 $summary['streak'] . ' bulan berturut', $summary['streak'] >= 3 ? '#d97706' : '#94a3b8', $summary['streak'] >= 3 ? '🔥' : ''],
            ];
            foreach ($cards as [$lbl, $val, $clr, $sub]): ?>
            <div class="panel" style="padding:12px 16px">
                <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--muted);letter-spacing:.05em"><?= $lbl ?></div>
                <div style="font-size:20px;font-weight:800;color:<?= $clr ?>;margin-top:4px;line-height:1.2"><?= $val ?></div>
                <?php if ($sub): ?>
                    <div style="font-size:11px;color:var(--muted);margin-top:2px"><?= $sub ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Historical Table -->
        <?php if (empty($rows)): ?>
            <div class="panel" style="text-align:center;padding:32px;color:var(--muted)">
                Tidak ada data untuk PIC ini di rentang yang dipilih.
            </div>
        <?php else: ?>
        <div class="panel">
            <div class="table-wrap" style="overflow-x:auto">
                <table>
                    <thead>
                        <tr>
                            <th>Periode</th>
                            <th style="text-align:right">Dealing</th>
                            <th style="text-align:right">Target Individu</th>
                            <th style="text-align:center">% Capai</th>
                            <th style="text-align:right">vs Bulan Lalu</th>
                            <th style="text-align:center">TRX</th>
                            <th style="text-align:right">Rata-rata / TRX</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        [$yr, $mo] = explode('-', $r['period_key']);
                        $avgTrx = $r['trx_count'] > 0 ? round((float)$r['dealing'] / (int)$r['trx_count']) : 0;
                    ?>
                    <tr>
                        <td style="font-weight:600;white-space:nowrap"><?= $mn[$mo] ?> <?= $yr ?></td>
                        <td style="text-align:right;font-weight:700"><?= money($r['dealing']) ?></td>
                        <td style="text-align:right;color:var(--muted)">
                            <?= $r['pic_target'] > 0 ? money($r['pic_target']) : '<span style="color:var(--muted)">—</span>' ?>
                        </td>
                        <td style="text-align:center">
                            <?php if ($r['pct'] !== null): ?>
                                <span style="font-weight:700;color:<?= $r['achieved'] ? '#0d9488' : '#dc2626' ?>">
                                    <?= number_format($r['pct'], 1, ',', '.') ?>%
                                </span>
                                <?= $r['achieved']
                                    ? '<span style="font-size:11px">✅</span>'
                                    : '<span style="font-size:11px">❌</span>' ?>
                            <?php else: ?>
                                <span style="color:var(--muted)">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <?php if ($r['mom_diff'] !== null): ?>
                                <?php $up = $r['mom_diff'] >= 0; ?>
                                <span style="font-weight:600;color:<?= $up ? '#0d9488' : '#dc2626' ?>">
                                    <?= $up ? '↑' : '↓' ?> <?= money(abs($r['mom_diff'])) ?>
                                </span>
                            <?php else: ?>
                                <span style="color:var(--muted)">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;color:var(--muted)"><?= $r['trx_count'] ?></td>
                        <td style="text-align:right;color:var(--muted)"><?= $avgTrx > 0 ? money($avgTrx) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid var(--line)">
                            <td style="font-weight:700">Total</td>
                            <td style="text-align:right;font-weight:700"><?= money($summary['total']) ?></td>
                            <td colspan="3"></td>
                            <td style="text-align:center;font-weight:700"><?= $summary['total_trx'] ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php
    });
}
