<?php
declare(strict_types=1);

/**
 * Aktivitas & Pipeline PIC — menghitung SEMUA penawaran (deal & tidak) agar
 * terlihat PIC mana yang benar-benar bekerja, plus deteksi penawaran fiktif.
 * Sumber: tabel offers (bukan hanya transaksi yang sudah deal).
 */
function pic_pipeline_page(PDO $pdo): void
{
    require_permission('view_pic_report');
    require_once __DIR__ . '/offers.php'; // offer_fiktif_assess, offer_lost_label

    $pid  = current_property_id();
    $today = date('Y-m');
    $from = getv('from', date('Y-m', strtotime('first day of -5 months')));
    $to   = getv('to', $today);
    if ($to > $today) $to = $today;
    if ($from > $to) $from = $to;
    $fromDate = $from . '-01';
    $toDate   = date('Y-m-t', strtotime($to . '-01'));

    // Semua penawaran dalam rentang (berdasarkan tanggal dibuat) + telepon kontak.
    $st = $pdo->prepare(
        "SELECT o.*, c.company_name, ct.phone AS contact_phone
         FROM offers o
         LEFT JOIN master_clients c ON c.id = o.client_id
         LEFT JOIN master_client_contacts ct ON ct.id = o.contact_id
         WHERE o.property_id = ? AND DATE(o.created_at) BETWEEN ? AND ?
         ORDER BY o.created_at DESC"
    );
    $st->execute([$pid, $fromDate, $toDate]);
    $offers = $st->fetchAll();

    // Hitung duplikat: PIC + client + unit + nilai sama.
    $dupGroups = [];
    foreach ($offers as $o) {
        $k = ($o['pic_name'] ?? '') . '|' . (int)$o['client_id'] . '|' . ($o['master_code'] ?? '') . '|' . round((float)$o['total_calculated']);
        $dupGroups[$k] = ($dupGroups[$k] ?? 0) + 1;
    }

    $pics = []; // agregasi per PIC
    $risky = []; // penawaran perlu ditinjau
    foreach ($offers as $o) {
        $name = $o['pic_name'] ?: '(tanpa PIC)';
        $p = &$pics[$name];
        if (!$p) $p = ['name' => $name, 'total' => 0, 'draft' => 0, 'sent' => 0, 'nego' => 0,
                       'deal' => 0, 'closed' => 0, 'pipeline_val' => 0.0, 'deal_val' => 0.0,
                       'sent_ok' => 0, 'rev_sum' => 0, 'risk_sum' => 0, 'high' => 0];
        $p['total']++;
        $stt = $o['status'];
        if (isset($p[$stt])) $p[$stt]++;
        if ($stt === 'cancelled') $p['closed']++;
        if ($stt === 'deal') $p['deal_val'] += (float)$o['total_calculated'];
        elseif (in_array($stt, ['draft', 'sent', 'nego'], true)) $p['pipeline_val'] += (float)$o['total_calculated'];
        if (!empty($o['sent_at']) || !empty($o['nego_at']) || $stt === 'deal') $p['sent_ok']++;
        $p['rev_sum'] += (int)$o['revision_count'];

        $k = ($o['pic_name'] ?? '') . '|' . (int)$o['client_id'] . '|' . ($o['master_code'] ?? '') . '|' . round((float)$o['total_calculated']);
        $dup = max(0, ($dupGroups[$k] ?? 1) - 1);
        $hasPhone = empty($o['contact_id']) ? true : trim((string)$o['contact_phone']) !== '';
        $fa = offer_fiktif_assess($o, $dup, $hasPhone);
        $p['risk_sum'] += $fa['score'];
        if ($fa['level'] === 'tinggi') { $p['high']++; }
        if ($fa['score'] >= 25) {
            $risky[] = ['o' => $o, 'fa' => $fa];
        }
        unset($p);
    }
    // Derivasi & urutkan (deal value desc).
    foreach ($pics as &$p) {
        $p['conv']      = $p['total'] > 0 ? round($p['deal'] / $p['total'] * 100, 1) : 0.0;
        $p['sent_rate'] = $p['total'] > 0 ? round($p['sent_ok'] / $p['total'] * 100) : 0;
        $p['avg_rev']   = $p['total'] > 0 ? round($p['rev_sum'] / $p['total'], 1) : 0.0;
        $p['avg_risk']  = $p['total'] > 0 ? (int)round($p['risk_sum'] / $p['total']) : 0;
    }
    unset($p);
    usort($pics, fn($a, $b) => $b['deal_val'] <=> $a['deal_val']);
    usort($risky, fn($a, $b) => $b['fa']['score'] <=> $a['fa']['score']);

    $tot = ['offers' => count($offers), 'deal' => 0, 'closed' => 0, 'high' => 0];
    foreach ($offers as $o) { if ($o['status'] === 'deal') $tot['deal']++; if ($o['status'] === 'cancelled') $tot['closed']++; }
    $tot['high'] = count(array_filter($risky, fn($r) => $r['fa']['level'] === 'tinggi'));
    $tot['conv'] = $tot['offers'] > 0 ? round($tot['deal'] / $tot['offers'] * 100, 1) : 0.0;

    // ── Funnel pipeline (cumulative): Dibuat → Dikirim → Nego → Deal ──
    $funnel = ['dibuat' => count($offers), 'dikirim' => 0, 'nego' => 0, 'deal' => 0];
    // ── Analisa nego: alasan tidak deal + hubungan jumlah revisi vs konversi ──
    $lost = [];                       // lost_category => count
    $revBuckets = [];                 // bucket => ['total'=>,'deal'=>]
    foreach (['0' => '0 (tanpa nego)', '1' => '1×', '2' => '2×', '3+' => '3× atau lebih'] as $bk => $bl) {
        $revBuckets[$bk] = ['label' => $bl, 'total' => 0, 'deal' => 0];
    }
    foreach ($offers as $o) {
        $stt = $o['status'];
        $everSent = !empty($o['sent_at']) || !empty($o['nego_at']) || $stt === 'deal';
        $everNego = !empty($o['nego_at']) || $stt === 'nego';
        if ($everSent) $funnel['dikirim']++;
        if ($everNego) $funnel['nego']++;
        if ($stt === 'deal') $funnel['deal']++;
        if ($stt === 'cancelled') { $c = $o['lost_category'] ?: 'lainnya'; $lost[$c] = ($lost[$c] ?? 0) + 1; }
        $rev = (int) $o['revision_count'];
        $bk = $rev >= 3 ? '3+' : (string) $rev;
        $revBuckets[$bk]['total']++;
        if ($stt === 'deal') $revBuckets[$bk]['deal']++;
    }
    arsort($lost);

    $mn = ['01'=>'Jan','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'Mei','06'=>'Jun',
           '07'=>'Jul','08'=>'Ags','09'=>'Sep','10'=>'Okt','11'=>'Nov','12'=>'Des'];

    layout('Aktivitas & Pipeline PIC', function () use ($pics, $risky, $tot, $funnel, $lost, $revBuckets, $from, $to, $mn) {
        $rl = function ($lvl) {
            return ['tinggi' => ['#991b1b', '#fee2e2', 'Tinggi'], 'sedang' => ['#92400e', '#fef3c7', 'Sedang'], 'rendah' => ['#166534', '#dcfce7', 'Rendah']][$lvl];
        };
        ?>
        <form method="get" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-bottom:18px">
            <input type="hidden" name="r" value="pic_pipeline">
            <div><label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px">Dari</label><input type="month" name="from" value="<?= h($from) ?>" max="<?= date('Y-m') ?>"></div>
            <div><label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px">Sampai</label><input type="month" name="to" value="<?= h($to) ?>" max="<?= date('Y-m') ?>"></div>
            <div><button type="submit">Tampilkan</button></div>
            <div style="margin-left:auto;align-self:center;font-size:12px;color:var(--muted)">Berdasarkan tanggal penawaran dibuat</div>
        </form>

        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px">
            <?php foreach ([
                ['Total Penawaran', $tot['offers'], '#0891b2', ''],
                ['Jadi Deal', $tot['deal'], '#0d9488', $tot['conv'] . '% konversi'],
                ['Ditutup (Tidak Deal)', $tot['closed'], '#6366f1', ''],
                ['Berisiko Fiktif (Tinggi)', $tot['high'], $tot['high'] > 0 ? '#dc2626' : '#94a3b8', $tot['high'] > 0 ? '⚠ perlu ditinjau' : 'aman'],
            ] as [$lbl, $val, $clr, $sub]): ?>
            <div class="panel" style="padding:12px 16px">
                <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--muted);letter-spacing:.05em"><?= $lbl ?></div>
                <div style="font-size:24px;font-weight:800;color:<?= $clr ?>;margin-top:4px;line-height:1.1"><?= $val ?></div>
                <?php if ($sub): ?><div style="font-size:11px;color:var(--muted);margin-top:2px"><?= $sub ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Funnel + Analisa Nego -->
        <div style="display:grid;grid-template-columns:1.2fr 1fr;gap:14px;margin-bottom:16px" class="pipe-grid">
            <div class="panel">
                <h3 style="margin:0 0 10px">Funnel Pipeline</h3>
                <?php
                $base = max(1, $funnel['dibuat']);
                $steps = [
                    ['Dibuat',   $funnel['dibuat'],  '#0891b2'],
                    ['Dikirim',  $funnel['dikirim'], '#6366f1'],
                    ['Nego',     $funnel['nego'],    '#d97706'],
                    ['Deal',     $funnel['deal'],    '#0d9488'],
                ];
                $prev = null;
                foreach ($steps as [$lbl, $val, $clr]):
                    $w = round($val / $base * 100);
                    $drop = $prev !== null && $prev > 0 ? round($val / $prev * 100) : null;
                ?>
                <div style="margin-bottom:9px">
                    <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px">
                        <span style="font-weight:600"><?= $lbl ?></span>
                        <span><strong><?= $val ?></strong><?php if ($drop !== null): ?> <span style="color:var(--muted)">· <?= $drop ?>% lanjut</span><?php endif; ?></span>
                    </div>
                    <div style="background:#f1f5f9;border-radius:6px;height:16px;overflow:hidden"><div style="width:<?= max(3,$w) ?>%;height:100%;background:<?= $clr ?>"></div></div>
                </div>
                <?php $prev = $val; endforeach; ?>
                <p style="margin:6px 0 0;font-size:11px;color:var(--muted)">Konversi akhir Dibuat→Deal: <strong><?= $tot['conv'] ?>%</strong></p>
            </div>
            <div class="panel">
                <h3 style="margin:0 0 10px">Alasan Tidak Deal</h3>
                <?php if (!$lost): ?>
                    <div style="color:var(--muted);padding:8px 0">Belum ada penawaran ditutup di rentang ini.</div>
                <?php else: $lostMax = max($lost); foreach ($lost as $cat => $cnt): ?>
                <div style="margin-bottom:7px">
                    <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:2px"><span><?= h(offer_lost_label($cat)) ?></span><strong><?= $cnt ?></strong></div>
                    <div style="background:#f1f5f9;border-radius:6px;height:10px;overflow:hidden"><div style="width:<?= round($cnt / $lostMax * 100) ?>%;height:100%;background:<?= $cat === 'fiktif' ? '#dc2626' : '#94a3b8' ?>"></div></div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="panel" style="margin-bottom:16px">
            <h3 style="margin:0 0 4px">Effort Nego vs Konversi</h3>
            <p style="margin:0 0 10px;font-size:12px;color:var(--muted)">Apakah penawaran yang direvisi/dinego lebih sering deal? (jumlah revisi penawaran)</p>
            <div class="table-wrap"><table style="font-size:12.5px">
                <thead><tr><th>Jumlah Revisi</th><th style="text-align:center">Penawaran</th><th style="text-align:center">Deal</th><th style="text-align:center">Konversi</th></tr></thead>
                <tbody>
                <?php foreach ($revBuckets as $b):
                    $rate = $b['total'] > 0 ? round($b['deal'] / $b['total'] * 100, 1) : null; ?>
                <tr>
                    <td style="font-weight:600"><?= h($b['label']) ?></td>
                    <td style="text-align:center"><?= $b['total'] ?></td>
                    <td style="text-align:center;color:#0d9488;font-weight:600"><?= $b['deal'] ?></td>
                    <td style="text-align:center"><?= $rate === null ? '<span style="color:var(--muted)">—</span>' : '<strong>' . number_format($rate, 1, ',', '.') . '%</strong>' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>

        <div class="panel">
            <h3 style="margin:0 0 4px">Leaderboard Aktivitas PIC</h3>
            <p style="margin:0 0 10px;font-size:12px;color:var(--muted)">Menghitung <strong>semua</strong> penawaran (deal & tidak). “Dikirim” = penawaran yang benar-benar ditandai terkirim/nego ke client — indikator kerja nyata. “Risiko” = rata-rata skor fiktif.</p>
            <div class="table-wrap" style="overflow-x:auto">
                <table style="font-size:12.5px">
                    <thead><tr>
                        <th>PIC</th><th style="text-align:center">Penawaran</th><th style="text-align:center">Dikirim</th>
                        <th style="text-align:center">Nego</th><th style="text-align:center">Deal</th><th style="text-align:center">Tidak Deal</th>
                        <th style="text-align:center">Konversi</th><th style="text-align:right">Nilai Deal</th><th style="text-align:right">Pipeline</th>
                        <th style="text-align:center">Avg Revisi</th><th style="text-align:center">Risiko</th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$pics): ?><tr><td colspan="11" style="text-align:center;color:var(--muted);padding:24px">Belum ada penawaran di rentang ini.</td></tr><?php endif; ?>
                    <?php foreach ($pics as $p):
                        $riskClr = $p['avg_risk'] >= 50 ? '#dc2626' : ($p['avg_risk'] >= 25 ? '#d97706' : '#16a34a');
                        $sentClr = $p['sent_rate'] >= 70 ? '#16a34a' : ($p['sent_rate'] >= 40 ? '#d97706' : '#dc2626');
                    ?>
                    <tr>
                        <td style="font-weight:700;white-space:nowrap"><?= h($p['name']) ?></td>
                        <td style="text-align:center;font-weight:700"><?= $p['total'] ?></td>
                        <td style="text-align:center;color:<?= $sentClr ?>;font-weight:600"><?= $p['sent_ok'] ?> <span style="font-size:10px">(<?= $p['sent_rate'] ?>%)</span></td>
                        <td style="text-align:center"><?= $p['nego'] ?></td>
                        <td style="text-align:center;font-weight:700;color:#0d9488"><?= $p['deal'] ?></td>
                        <td style="text-align:center;color:#991b1b"><?= $p['closed'] ?></td>
                        <td style="text-align:center;font-weight:600"><?= number_format($p['conv'], 1, ',', '.') ?>%</td>
                        <td style="text-align:right;font-weight:700"><?= money($p['deal_val']) ?></td>
                        <td style="text-align:right;color:var(--muted)"><?= money($p['pipeline_val']) ?></td>
                        <td style="text-align:center;color:var(--muted)"><?= number_format($p['avg_rev'], 1, ',', '.') ?>×</td>
                        <td style="text-align:center"><span class="badge" style="color:<?= $riskClr ?>;font-weight:700"><?= $p['avg_risk'] ?><?php if ($p['high'] > 0): ?> · <?= $p['high'] ?>⚠<?php endif; ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="panel" style="margin-top:16px">
            <h3 style="margin:0 0 4px">Penawaran Perlu Ditinjau <span style="font-size:12px;color:var(--muted);font-weight:400">(skor risiko fiktif ≥ 25)</span></h3>
            <p style="margin:0 0 10px;font-size:12px;color:var(--muted)">Indikator penawaran kemungkinan tidak murni (mis. tidak pernah dikirim, ditutup instan tanpa effort, duplikat). Bukan tuduhan — perlu konfirmasi manual.</p>
            <?php if (!$risky): ?>
                <div style="text-align:center;color:var(--muted);padding:18px">✓ Tidak ada penawaran berisiko di rentang ini.</div>
            <?php else: ?>
            <div class="table-wrap" style="overflow-x:auto">
                <table style="font-size:12.5px">
                    <thead><tr><th>No. / Tanggal</th><th>PIC</th><th>Client</th><th>Status</th><th>Sinyal</th><th style="text-align:center">Skor</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($risky as $row): $o = $row['o']; $fa = $row['fa']; [$fc, $fb, $fl] = $rl($fa['level']); ?>
                    <tr>
                        <td style="white-space:nowrap"><?= h($o['offer_no'] ?? '—') ?><div style="font-size:10.5px;color:var(--muted)"><?= h(date('d/m/y', strtotime($o['created_at']))) ?></div></td>
                        <td style="white-space:nowrap"><?= h($o['pic_name'] ?? '—') ?></td>
                        <td><?= h($o['company_name'] ?? '-') ?></td>
                        <td><?= $o['status'] === 'cancelled' ? 'Tidak Deal' : ($o['status'] === 'deal' ? 'Deal' : ucfirst($o['status'])) ?><?php if ($o['status'] === 'cancelled' && !empty($o['lost_category'])): ?><div style="font-size:10px;color:#991b1b"><?= h(offer_lost_label($o['lost_category'])) ?></div><?php endif; ?></td>
                        <td style="font-size:11px;color:#475569"><?= h(implode(' · ', $fa['flags'])) ?></td>
                        <td style="text-align:center"><span class="badge" style="color:<?= $fc ?>;background:<?= $fb ?>;font-weight:700"><?= $fa['score'] ?> · <?= $fl ?></span></td>
                        <td><a class="btn light" href="?r=offer_form&id=<?= (int)$o['id'] ?>">Lihat</a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    });
}

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
        $dbRows = [];
        foreach ($s->fetchAll() as $r) {
            $dbRows[$r['period_key']] = $r;
        }

        // Also fetch targets for months that have no allocations
        $tgtStmt = $pdo->prepare(
            "SELECT tm.period_key, tm.target_amount, mp.target_share
             FROM targets_monthly tm
             JOIN master_pic mp ON mp.property_id = tm.property_id AND mp.name = ?
             WHERE tm.property_id = ? AND tm.period_key >= ? AND tm.period_key <= ?"
        );
        $tgtStmt->execute([$picName, $pid, $fromPeriod, $toPeriod]);
        $tgtMap = [];
        foreach ($tgtStmt->fetchAll() as $t) {
            $tgtMap[$t['period_key']] = $t;
        }

        // Generate all months in range (fills gaps with 0)
        $raw = [];
        $cur = $fromPeriod;
        while ($cur <= $toPeriod) {
            $db  = $dbRows[$cur] ?? null;
            $tgt = $tgtMap[$cur] ?? null;
            $raw[] = [
                'period_key'   => $cur,
                'dealing'      => $db ? (float)$db['dealing']    : 0.0,
                'trx_count'    => $db ? (int)$db['trx_count']    : 0,
                'prop_target'  => $db ? (float)$db['prop_target'] : (float)($tgt['target_amount'] ?? 0),
                'target_share' => $db ? (float)$db['target_share'] : (float)($tgt['target_share'] ?? 0),
            ];
            // advance one month
            [$y, $m] = explode('-', $cur);
            $m = (int)$m + 1;
            if ($m > 12) { $m = 1; $y = (int)$y + 1; }
            $cur = sprintf('%04d-%02d', $y, $m);
        }

        // Compute derived fields in ASC order (oldest first)
        $prevDealing = null;
        foreach ($raw as &$r) {
            $r['pic_target'] = $r['prop_target'] > 0 && $r['target_share'] > 0
                ? round($r['prop_target'] * $r['target_share'])
                : 0;
            $r['pct']      = $r['pic_target'] > 0
                ? round($r['dealing'] / $r['pic_target'] * 100, 1)
                : null;
            $r['achieved'] = $r['pic_target'] > 0 && $r['dealing'] >= $r['pic_target'];
            $r['mom_diff'] = $prevDealing !== null ? $r['dealing'] - $prevDealing : null;
            $prevDealing   = $r['dealing'];
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
                [$mn[substr($fromPeriod,5,2)] . ' – ' . $mn[substr($toPeriod,5,2)] . ' ' . substr($toPeriod,0,4), money($summary['total']), '#0891b2', ''],
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
