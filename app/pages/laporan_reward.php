<?php
declare(strict_types=1);

function _reward_tier(int $streak): array
{
    if ($streak >= 12) return ['bonus' => 1_000_000, 'label' => 'Tier IV',  'color' => '#7c3aed'];
    if ($streak >= 9)  return ['bonus' =>   750_000, 'label' => 'Tier III', 'color' => '#0369a1'];
    if ($streak >= 6)  return ['bonus' =>   500_000, 'label' => 'Tier II',  'color' => '#0891b2'];
    if ($streak >= 3)  return ['bonus' =>   250_000, 'label' => 'Tier I',   'color' => '#16a34a'];
    return ['bonus' => 0, 'label' => '—', 'color' => '#94a3b8'];
}

function _reward_periods(string $start, string $end): array
{
    $periods = [];
    $cur     = $start;
    while ($cur <= $end) {
        $periods[] = $cur;
        [$y, $m]   = explode('-', $cur);
        $m         = (int)$m + 1;
        if ($m > 12) { $m = 1; $y++; }
        $cur = sprintf('%04d-%02d', (int)$y, $m);
    }
    return $periods;
}

function pic_reward_page(PDO $pdo): void
{
    require_permission('view_pic_report');

    $pid        = current_property_id();
    $monthNames = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni',
                   '07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];

    $startPeriod = (string)($pdo->query("SELECT value FROM settings WHERE `key`='reward_start_period'")->fetchColumn() ?: '');
    $period      = getv('period', date('Y-m'));
    $periodLabel = ($monthNames[substr($period, 5, 2)] ?? substr($period, 5, 2)) . ' ' . substr($period, 0, 4);

    $availPeriods = $pdo->query(
        "SELECT DISTINCT period_key FROM transaction_allocations WHERE property_id=$pid ORDER BY period_key DESC"
    )->fetchAll(PDO::FETCH_COLUMN);

    $pics = $pdo->query(
        "SELECT id, name, COALESCE(role_name,'-') role_name, COALESCE(target_share,0) target_share
         FROM master_pic WHERE status='active' AND property_id=$pid ORDER BY name"
    )->fetchAll();

    $picData = [];
    $periods = [];

    if ($startPeriod && $startPeriod <= $period) {
        $periods = _reward_periods($startPeriod, $period);

        $tgtStmt = $pdo->prepare(
            "SELECT period_key, target_amount FROM targets_monthly WHERE property_id=? AND period_key BETWEEN ? AND ?"
        );
        $tgtStmt->execute([$pid, $startPeriod, $period]);
        $targets = [];
        foreach ($tgtStmt->fetchAll() as $r) {
            $targets[$r['period_key']] = (float)$r['target_amount'];
        }

        $actStmt = $pdo->prepare(
            "SELECT period_key, pic_name, COALESCE(SUM(amount),0) actual
             FROM transaction_allocations
             WHERE property_id=? AND period_key BETWEEN ? AND ?
             GROUP BY period_key, pic_name"
        );
        $actStmt->execute([$pid, $startPeriod, $period]);
        $actuals = [];
        foreach ($actStmt->fetchAll() as $r) {
            $actuals[$r['period_key']][$r['pic_name']] = (float)$r['actual'];
        }

        foreach ($pics as $pic) {
            $history    = [];
            $streak     = 0;
            $totalBonus = 0;
            foreach ($periods as $p) {
                $tgt     = (float)$pic['target_share'] * ($targets[$p] ?? 0);
                $act     = $actuals[$p][$pic['name']] ?? 0.0;
                $achieve = $tgt > 0 && $act >= $tgt;
                $streak  = $achieve ? $streak + 1 : 0;
                $tier    = _reward_tier($streak);
                $totalBonus += $tier['bonus'];
                $history[$p] = ['achieve' => $achieve, 'streak' => $streak, 'bonus' => $tier['bonus'],
                                'actual' => $act, 'target' => $tgt, 'tier' => $tier];
            }
            $cur      = $history[$period] ?? ['streak' => 0, 'bonus' => 0, 'tier' => _reward_tier(0)];
            $picData[] = ['pic' => $pic, 'history' => $history,
                          'streak'      => $cur['streak'],
                          'tier'        => $cur['tier'],
                          'bonus_month' => $cur['bonus'],
                          'bonus_total' => $totalBonus];
        }
        usort($picData, fn($a, $b) => $b['streak'] <=> $a['streak']);
    } else {
        foreach ($pics as $pic) {
            $picData[] = ['pic' => $pic, 'history' => [], 'streak' => 0,
                          'tier' => _reward_tier(0), 'bonus_month' => 0, 'bonus_total' => 0];
        }
    }

    $totalBonusMonth = array_sum(array_column($picData, 'bonus_month'));
    $totalBonusAll   = array_sum(array_column($picData, 'bonus_total'));
    $isAdmin         = in_array(current_role(), ['superadmin', 'admin'], true);

    layout('Rewarding PIC', function () use (
        $picData, $periods, $period, $periodLabel, $startPeriod,
        $monthNames, $availPeriods, $totalBonusMonth, $totalBonusAll, $isAdmin
    ) { ?>

    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap">
        <form method="get" style="display:flex;gap:6px;align-items:center">
            <input type="hidden" name="r" value="pic_reward">
            <label style="font-size:12px;color:var(--muted)">Periode:</label>
            <select name="period" onchange="this.form.submit()" style="font-size:12px">
                <?php foreach ($availPeriods as $ap): ?>
                <option value="<?= h($ap) ?>"<?= $ap === $period ? ' selected' : '' ?>>
                    <?= ($monthNames[substr($ap, 5, 2)] ?? substr($ap, 5, 2)) . ' ' . substr($ap, 0, 4) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
        <a class="btn light" href="?r=pic_report" style="margin-left:auto">← Laporan PIC</a>
    </div>

    <?php if ($isAdmin): ?>
    <div class="panel" style="margin-bottom:16px;border-left:4px solid #f59e0b">
        <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
            <div>
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#92400e;margin-bottom:2px">Periode Mulai Rewarding</div>
                <div style="font-size:13px;color:#78350f">
                    <?php if ($startPeriod): ?>
                        <?= ($monthNames[substr($startPeriod, 5, 2)] ?? substr($startPeriod, 5, 2)) . ' ' . substr($startPeriod, 0, 4) ?>
                    <?php else: ?>
                        <em style="color:#94a3b8">Belum diatur</em>
                    <?php endif; ?>
                </div>
            </div>
            <form method="post" action="?r=pic_reward_save" style="display:flex;gap:8px;align-items:center">
                <input type="month" name="reward_start_period" value="<?= h($startPeriod) ?>"
                       class="form-control" style="width:auto" required>
                <button type="submit" class="btn btn-sm">Simpan</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$startPeriod): ?>
    <div class="panel" style="text-align:center;padding:48px 20px;color:var(--muted)">
        <div style="font-size:40px;margin-bottom:10px">🏆</div>
        <div style="font-weight:600;font-size:15px;margin-bottom:4px">Periode Rewarding belum diatur</div>
        <div style="font-size:13px">Admin perlu mengatur periode mulai tracking di atas.</div>
    </div>
    <?php else: ?>

    <!-- KPI Strip -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:16px">
        <?php
        $kpis = [
            ['Tracking Sejak', ($monthNames[substr($startPeriod, 5, 2)] ?? substr($startPeriod, 5, 2)) . ' ' . substr($startPeriod, 0, 4), ''],
            ['Total Bulan',    count($periods) . ' bulan', ''],
            ['Bonus Bulan Ini', money($totalBonusMonth), $totalBonusMonth > 0 ? '#f0fdf4' : ''],
            ['Total Akumulasi', money($totalBonusAll), ''],
        ];
        foreach ($kpis as [$lbl, $val, $bg]): ?>
        <div class="panel" style="padding:14px 16px;margin:0<?= $bg ? ';background:' . $bg : '' ?>">
            <div class="kpi-label"><?= $lbl ?></div>
            <div class="kpi-value" style="font-size:18px"><?= $val ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Summary Table -->
    <div class="panel" style="margin-bottom:16px">
        <div class="panel-title" style="margin-bottom:12px">Ringkasan per PIC — <?= h($periodLabel) ?></div>
        <div style="font-size:12px;color:var(--muted);margin-bottom:10px">Klik baris untuk lihat riwayat bulanan.</div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>PIC</th>
                        <th>Role</th>
                        <th class="r">Streak</th>
                        <th class="r">Tier</th>
                        <th class="r">Bonus Bln Ini</th>
                        <th class="r">Total Akumulasi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($picData as $i => $d):
                    $tier = $d['tier'];
                    $hid  = 'rh_' . $i; ?>
                <tr style="cursor:pointer" onclick="var e=document.getElementById('<?= $hid ?>');e.style.display=e.style.display==='none'?'table-row':'none'">
                    <td style="font-weight:600"><?= h($d['pic']['name']) ?></td>
                    <td style="color:var(--muted);font-size:12px"><?= h($d['pic']['role_name']) ?></td>
                    <td class="r">
                        <?php if ($d['streak'] >= 1): ?>
                        <span style="font-weight:700;color:<?= $tier['color'] ?>">🔥 <?= $d['streak'] ?> bln</span>
                        <?php else: ?><span style="color:#94a3b8">—</span><?php endif; ?>
                    </td>
                    <td class="r">
                        <?php if ($d['bonus_month'] > 0): ?>
                        <span style="padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;background:<?= $tier['color'] ?>22;color:<?= $tier['color'] ?>"><?= $tier['label'] ?></span>
                        <?php else: ?><span style="color:#94a3b8">—</span><?php endif; ?>
                    </td>
                    <td class="r" style="font-weight:700;color:<?= $d['bonus_month'] > 0 ? '#16a34a' : '#94a3b8' ?>">
                        <?= $d['bonus_month'] > 0 ? money($d['bonus_month']) : '—' ?>
                    </td>
                    <td class="r" style="font-weight:700">
                        <?= $d['bonus_total'] > 0 ? money($d['bonus_total']) : '—' ?>
                    </td>
                </tr>
                <tr id="<?= $hid ?>" style="display:none;background:#f8fafc">
                    <td colspan="6" style="padding:0">
                        <div style="padding:12px 16px">
                            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:8px">
                                Riwayat Bulanan — <?= h($d['pic']['name']) ?>
                            </div>
                            <div style="overflow-x:auto">
                            <table style="font-size:12px;min-width:560px;width:100%">
                                <thead>
                                    <tr style="background:#f1f5f9">
                                        <th style="padding:6px 10px;text-align:left">Periode</th>
                                        <th style="padding:6px 10px;text-align:right">Target Posisi</th>
                                        <th style="padding:6px 10px;text-align:right">Aktual</th>
                                        <th style="padding:6px 10px;text-align:center">Achieve?</th>
                                        <th style="padding:6px 10px;text-align:right">Streak</th>
                                        <th style="padding:6px 10px;text-align:left">Tier</th>
                                        <th style="padding:6px 10px;text-align:right">Bonus</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach (array_reverse($periods) as $p):
                                    $h = $d['history'][$p] ?? null;
                                    if (!$h) continue;
                                    $pLabel = ($monthNames[substr($p, 5, 2)] ?? substr($p, 5, 2)) . ' ' . substr($p, 0, 4);
                                    $ht     = $h['tier'];
                                    $rowBg  = $h['achieve'] ? '#dcfce7' : ($h['target'] > 0 ? '#fef2f2' : '');
                                ?>
                                <tr style="border-top:1px solid var(--line)<?= $rowBg ? ';background:' . $rowBg : '' ?>">
                                    <td style="padding:5px 10px;font-weight:<?= $p === $period ? '700' : '400' ?>">
                                        <?= $pLabel ?><?= $p === $period ? ' ◀' : '' ?>
                                    </td>
                                    <td style="padding:5px 10px;text-align:right">
                                        <?= $h['target'] > 0 ? money($h['target']) : '<span style="color:#94a3b8">—</span>' ?>
                                    </td>
                                    <td style="padding:5px 10px;text-align:right"><?= money($h['actual']) ?></td>
                                    <td style="padding:5px 10px;text-align:center">
                                        <?= $h['achieve'] ? '✅' : ($h['target'] > 0 ? '❌' : '—') ?>
                                    </td>
                                    <td style="padding:5px 10px;text-align:right;font-weight:700;color:<?= $h['streak'] >= 3 ? $ht['color'] : '#94a3b8' ?>">
                                        <?= $h['streak'] > 0 ? $h['streak'] : '—' ?>
                                    </td>
                                    <td style="padding:5px 10px">
                                        <?php if ($h['bonus'] > 0): ?>
                                        <span style="padding:2px 6px;border-radius:4px;font-size:11px;font-weight:700;background:<?= $ht['color'] ?>22;color:<?= $ht['color'] ?>"><?= $ht['label'] ?></span>
                                        <?php else: ?><span style="color:#94a3b8">—</span><?php endif; ?>
                                    </td>
                                    <td style="padding:5px 10px;text-align:right;font-weight:700;color:<?= $h['bonus'] > 0 ? '#16a34a' : '#94a3b8' ?>">
                                        <?= $h['bonus'] > 0 ? money($h['bonus']) : '—' ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <tr style="border-top:2px solid var(--line);font-weight:700;background:#f1f5f9">
                                    <td colspan="6" style="padding:6px 10px;text-align:right">Total Akumulasi</td>
                                    <td style="padding:6px 10px;text-align:right;color:<?= $d['bonus_total'] > 0 ? '#16a34a' : '#94a3b8' ?>">
                                        <?= $d['bonus_total'] > 0 ? money($d['bonus_total']) : '—' ?>
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr style="font-weight:700;background:#f8fafc;border-top:2px solid var(--line)">
                    <td colspan="4" style="padding:10px 12px">Total</td>
                    <td class="r" style="color:<?= $totalBonusMonth > 0 ? '#16a34a' : '#94a3b8' ?>">
                        <?= $totalBonusMonth > 0 ? money($totalBonusMonth) : '—' ?>
                    </td>
                    <td class="r"><?= $totalBonusAll > 0 ? money($totalBonusAll) : '—' ?></td>
                </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Tier Reference -->
    <div class="panel">
        <div class="panel-title" style="margin-bottom:12px">Referensi Tier Reward</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px">
        <?php foreach ([
            [3, 5,    250_000, 'Tier I',   '#16a34a'],
            [6, 8,    500_000, 'Tier II',  '#0891b2'],
            [9, 11,   750_000, 'Tier III', '#0369a1'],
            [12, null, 1_000_000, 'Tier IV', '#7c3aed'],
        ] as [$min, $max, $bonus, $label, $color]): ?>
        <div style="border:1px solid <?= $color ?>44;border-radius:8px;padding:12px 14px;background:<?= $color ?>11">
            <div style="font-weight:700;color:<?= $color ?>;font-size:13px;margin-bottom:2px"><?= $label ?></div>
            <div style="font-size:12px;color:var(--muted);margin-bottom:6px">
                Streak <?= $min ?><?= $max ? '–' . $max : '+' ?> bulan
            </div>
            <div style="font-size:17px;font-weight:800;color:<?= $color ?>"><?= money($bonus) ?>/bln</div>
        </div>
        <?php endforeach; ?>
        </div>
        <div style="margin-top:10px;font-size:12px;color:var(--muted)">
            ⚠ Jika satu bulan tidak achieve target posisi (100%), streak reset ke 0 dan bonus berhenti.
            Bonus mulai dihitung sejak streak mencapai 3 bulan.
        </div>
    </div>

    <?php endif; // startPeriod
    });
}

function pic_reward_save(PDO $pdo): void
{
    require_permission('view_pic_report');
    if (!in_array(current_role(), ['superadmin', 'admin'], true)) {
        http_response_code(403);
        exit('Akses ditolak.');
    }

    $val = post('reward_start_period', '');
    if (preg_match('/^\d{4}-\d{2}$/', $val)) {
        $stmt = $pdo->prepare(
            "INSERT INTO settings (`key`, value) VALUES ('reward_start_period', ?)
             ON DUPLICATE KEY UPDATE value=?, updated_at=NOW()"
        );
        $stmt->execute([$val, $val]);
    }
    header('Location: ?r=pic_reward');
    exit;
}
