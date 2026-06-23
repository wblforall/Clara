<?php
declare(strict_types=1);

/**
 * Tampilan khusus mobile — halaman konten.
 *
 * Chrome (top bar + bottom nav) ditangani oleh layout() di bootstrap.php saat
 * mobile_view_active() benar, jadi halaman ini cukup memanggil layout() biasa.
 *
 * Beranda menyesuaikan peran:
 *  - PIC sales            → beranda personal (achievement & progress pribadi).
 *  - Akses multi-properti → ringkasan per-properti + agregat.
 *  - Properti tunggal     → ringkasan properti (segmen, top PIC, transaksi).
 */

// ─── Komponen UI bersama ──────────────────────────────────────────────────────

/** Hero gelap dengan ring achievement + actual/target/gap. */
function _m_hero(string $kicker, string $sub, int $achPct, float $actual, float $target): void
{
    $color   = $achPct >= 100 ? 'var(--green)' : ($achPct >= 80 ? 'var(--amber)' : 'var(--accent)');
    $ringPct = min(100, max(0, $achPct));
    $gap     = $actual - $target;
    ?>
    <div class="m-card m-hero">
        <div class="kick"><?= h($kicker) ?></div>
        <div class="month"><?= h($sub) ?></div>
        <div class="m-ring-wrap">
            <div class="m-ring" style="--p:<?= $ringPct ?>%;--rc:<?= $color ?>"><b><?= $achPct ?><small>% TARGET</small></b></div>
            <div style="flex:1;min-width:0">
                <div class="stat">Realisasi<b><?= money($actual) ?></b></div>
                <div class="stat" style="margin-top:9px">Target<b style="opacity:.85"><?= money($target) ?></b></div>
                <div class="stat" style="margin-top:9px"><?= $gap >= 0 ? 'Surplus' : 'Kurang' ?>
                    <b style="color:<?= $gap >= 0 ? '#5EEAD4' : '#FCA5A5' ?>"><?= money(abs($gap)) ?></b></div>
            </div>
        </div>
    </div>
    <?php
}

/** Daftar bar per item: rows = [['label','actual','base'(opsional)]]. base=pembanding (proyeksi/total). */
function _m_bars(array $rows): void
{
    foreach ($rows as $r) {
        $base = (float)($r['base'] ?? 0);
        $act  = (float)$r['actual'];
        $p    = $base > 0 ? min(100, round($act / $base * 100)) : ($act > 0 ? 100 : 0);
        $c    = $p >= 100 ? 'var(--green)' : ($p >= 80 ? 'var(--amber)' : 'var(--accent)');
        ?>
        <div class="m-seg">
            <div class="row"><span class="nm"><?= h($r['label']) ?></span><span class="vl" style="color:<?= $c ?>"><?= $p ?>%</span></div>
            <div class="m-bar"><i style="width:<?= $p ?>%;background:<?= $c ?>"></i></div>
            <div class="row sub2"><span><?= money($act) ?></span><?php if ($base > 0): ?><span>dari <?= money($base) ?></span><?php endif; ?></div>
        </div>
        <?php
    }
}

function _m_recent_list(array $rows): void
{
    foreach (array_slice($rows, 0, 6) as $t): ?>
    <div class="it">
        <div class="m-it-main">
            <div class="nm"><?= h($t['company_name'] ?? $t['master_code']) ?></div>
            <div class="sub"><?= h($t['master_code']) ?><?= !empty($t['pic_name']) ? ' &middot; ' . h($t['pic_name']) : '' ?></div>
        </div>
        <div class="m-it-val"><?= money($t['final_amount']) ?></div>
    </div>
    <?php endforeach;
}

/**
 * Achievement per PIC pada periode + daftar properti tertentu.
 * Target individu = target bulanan properti × target_share PIC.
 * @return array<int,array{pic_name:string,role_name:string,prop_name:?string,actual:float,target:float,ach:?int}>
 */
function _m_pic_ach_rows(PDO $pdo, string $period, array $pids): array
{
    $pids = array_values(array_filter(array_map('intval', $pids)));
    if (empty($pids)) return [];
    $ph  = implode(',', array_fill(0, count($pids), '?'));
    // target_share disimpan sebagai PECAHAN (0.35 = 35%). Target individu =
    // target bulanan properti × target_share (TANPA /100) — sama dgn exec/pic_performance/reward.
    $sql =
        "SELECT p.name AS pic_name, COALESCE(p.role_name,'-') AS role_name,
                COALESCE(p.target_share,0) AS share, pr.name AS prop_name,
                COALESCE(SUM(a.amount),0) AS actual,
                COALESCE(SUM(CASE WHEN " . recurring_match_sql('t') . " THEN a.amount ELSE 0 END),0) AS actual_recurring,
                COALESCE(MAX(tm.target_amount),0) AS prop_target
         FROM master_pic p
         LEFT JOIN transaction_allocations a
                ON a.pic_name=p.name AND a.period_key=? AND a.property_id=p.property_id
         LEFT JOIN transactions t ON t.id=a.transaction_id AND t.deleted_at IS NULL
         LEFT JOIN targets_monthly tm
                ON tm.period_key=? AND tm.property_id=p.property_id
         LEFT JOIN properties pr ON pr.id=p.property_id
         WHERE p.status='active' AND p.show_achievement=1 AND p.target_share > 0
           AND p.property_id IN ($ph)
         GROUP BY p.id
         ORDER BY actual DESC";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$period, $period], $pids));
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $tgt = (float) $r['prop_target'] * (float) $r['share'];
        $act = (float) $r['actual'];
        $rows[] = [
            'pic_name'  => $r['pic_name'],
            'role_name' => $r['role_name'],
            'prop_name' => $r['prop_name'] ?? null,
            'actual'    => $act,
            'target'    => $tgt,
            'ach'       => $tgt > 0 ? (int) round($act / $tgt * 100) : null,
            'rec'       => $act > 0 ? (int) round((float) $r['actual_recurring'] / $act * 100) : null,
        ];
    }
    return $rows;
}

/** Render daftar achievement PIC (persentase prominen + actual/target). */
function _m_pic_ach_list(array $rows, bool $showProp): void
{
    $i = 0;
    foreach (array_slice($rows, 0, 8) as $r):
        $i++;
        $ach = $r['ach'];
        $c   = $ach === null ? 'var(--muted)' : ($ach >= 100 ? 'var(--green)' : ($ach >= 80 ? 'var(--amber)' : 'var(--accent)'));
        ?>
        <div class="it">
            <div class="m-rank r<?= $i ?>"><?= $i ?></div>
            <div class="m-it-main">
                <div class="nm"><?= h($r['pic_name']) ?></div>
                <div class="sub"><?= $showProp && !empty($r['prop_name']) ? h($r['prop_name']) . ' &middot; ' : '' ?><?= money($r['actual']) ?><?= $r['target'] > 0 ? ' / ' . money($r['target']) : '' ?><?= $r['rec'] !== null ? ' &middot; <span style="color:#0369a1;font-weight:700">Rec ' . $r['rec'] . '%</span>' : '' ?></div>
            </div>
            <div class="m-it-val" style="color:<?= $c ?>;font-size:15px"><?= $ach === null ? '—' : $ach . '%' ?></div>
        </div>
        <?php
    endforeach;
}

/** Daftar occupancy per grup (lantai/jenis/lokasi); rows dari _exec_fetch_prop_data. */
function _m_occ_list(array $rows, int $periodDays): void
{
    foreach ($rows as $r) {
        $units = (int) $r['unit_count'];
        $max   = $units * $periodDays;
        $occ   = $max > 0 ? (float) $r['days_total'] / $max : 0.0;
        $p     = (int) round($occ * 100);
        $c     = $occ >= 0.8 ? 'var(--green)' : ($occ >= 0.5 ? 'var(--amber)' : 'var(--accent)');
        ?>
        <div class="m-seg">
            <div class="row">
                <span class="nm"><?= h($r['group_key'] ?? '—') ?> <span style="font-weight:500;color:var(--muted);font-size:11px">(<?= $units ?> unit)</span></span>
                <span class="vl" style="color:<?= $c ?>"><?= $p ?>%</span>
            </div>
            <div class="m-bar"><i style="width:<?= min(100, $p) ?>%;background:<?= $c ?>"></i></div>
        </div>
        <?php
    }
}

/** Style khusus halaman beranda (dipakai semua mode). */
function _m_home_styles(): void
{
    ?>
    <style>
    .m-hero { background: linear-gradient(135deg,#0F1623,#1A2436); color:#fff; border:none; }
    .m-hero .kick { font-size:12.5px; opacity:.7; }
    .m-hero .month { font-size:12.5px; font-weight:700; color:#5EEAD4; margin-top:2px; }
    .m-ring-wrap { display:flex; align-items:center; gap:18px; margin-top:14px; }
    .m-ring { --p:0%; width:104px; height:104px; flex-shrink:0; border-radius:50%; background: conic-gradient(var(--rc,#14B8A6) var(--p), rgba(255,255,255,.10) 0); position:relative; }
    .m-ring::before { content:''; position:absolute; inset:11px; border-radius:50%; background:#141d2e; }
    .m-ring b { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; font-size:24px; font-weight:900; }
    .m-ring b small { font-size:9.5px; font-weight:800; opacity:.6; letter-spacing:.06em; margin-top:2px; }
    .m-hero .stat { font-size:12px; opacity:.7; }
    .m-hero .stat b { display:block; font-size:18px; font-weight:800; opacity:1; margin-top:1px; }
    .m-badge-rank { display:inline-flex; align-items:center; gap:7px; margin-top:14px; background:rgba(94,234,212,.14); color:#5EEAD4; font-weight:800; font-size:12.5px; padding:7px 13px; border-radius:999px; }
    .m-qa { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:13px; }
    .m-qa a { display:flex; align-items:center; justify-content:center; gap:8px; padding:14px; border-radius:12px; font-weight:800; font-size:14px; text-decoration:none; }
    .m-qa a.p { background:linear-gradient(135deg,var(--primary),var(--primary2)); color:#fff; box-shadow:0 2px 8px rgba(13,148,136,.3); }
    .m-qa a.s { background:#fff; color:var(--primary-dark); border:1px solid rgba(13,148,136,.25); }
    .m-seg { margin-bottom:13px; } .m-seg:last-child { margin-bottom:0; }
    .m-seg .row { display:flex; justify-content:space-between; font-size:13px; margin-bottom:5px; }
    .m-seg .row .nm { font-weight:700; color:var(--ink); } .m-seg .row .vl { font-weight:800; }
    .m-seg .row.sub2 { font-size:11.5px; color:var(--muted); margin-top:5px; margin-bottom:0; }
    .m-bar { height:8px; border-radius:999px; background:#EEF2F7; overflow:hidden; }
    .m-bar > i { display:block; height:100%; border-radius:inherit; }
    .m-list .it { display:flex; align-items:center; gap:11px; padding:10px 0; border-bottom:1px solid var(--line); }
    .m-list .it:last-child { border-bottom:none; }
    .m-rank { width:26px; height:26px; border-radius:50%; background:#EEF2F7; display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:900; color:#64748b; flex-shrink:0; }
    .m-rank.r1{background:rgba(251,191,36,.18);color:#B45309} .m-rank.r2{background:#E2E8F0;color:#475569} .m-rank.r3{background:rgba(217,119,6,.15);color:#B45309}
    .m-it-main { flex:1; min-width:0; }
    .m-it-main .nm { font-size:13.5px; font-weight:700; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .m-it-main .sub { font-size:11.5px; color:var(--muted); }
    .m-it-val { font-size:13px; font-weight:800; color:var(--ink); white-space:nowrap; }
    .m-prop { display:flex; align-items:center; gap:12px; padding:13px 0; border-bottom:1px solid var(--line); }
    .m-prop:last-child { border-bottom:none; }
    .m-prop .pn { flex:1; min-width:0; } .m-prop .pn .nm { font-size:14px; font-weight:800; color:var(--ink); }
    .m-prop .pn .sub { font-size:11.5px; color:var(--muted); margin-top:2px; }
    .m-prop .pp { font-size:22px; font-weight:900; letter-spacing:-.5px; }
    .m-prop-h { font-size:13px; font-weight:800; color:var(--primary-dark); margin-bottom:8px; padding-bottom:8px; border-bottom:1px solid var(--line); }
    </style>
    <?php
}

function _m_quick_actions(): void
{
    // Offer-first (default aktif): titik masuk sales adalah Penawaran, bukan
    // input transaksi langsung (transaksi kini OUTPUT dari penawaran yang DEAL).
    $offerFirst = get_setting(Database::connect(), 'offer_first', '1') === '1';
    ?>
    <div class="m-qa">
        <?php if ($offerFirst && can('manage_offers')): ?>
            <a class="p" href="?r=offer_form&module=cl"><?= _m_icon('plus') ?> Buat Penawaran</a>
        <?php elseif (!$offerFirst && can('manage_transactions')): ?>
            <a class="p" href="?r=transaction_form&module=cl"><?= _m_icon('plus') ?> Transaksi</a>
        <?php endif; ?>
        <a class="s" href="?r=renewals"><?= _m_icon('renew') ?> Renewal</a>
    </div>
    <?php
}

// ─── BERANDA ──────────────────────────────────────────────────────────────────
function mobile_home_page(PDO $pdo): void
{
    require_permission('view_dashboard');
    $period  = getv('period', date('Y-m'));
    $user    = $_SESSION['user'] ?? [];
    $uname   = $user['name'] ?? 'User';
    $allowed = allowed_properties();
    $isMulti = count($allowed) > 1;

    // Apakah user ini seorang PIC sales? (cari di properti yang diakses)
    $pic = null;
    if (!empty($user['id'])) {
        $s = $pdo->prepare(
            'SELECT p.name AS pic_name, p.target_share, p.property_id,
                    COALESCE(SUM(a.amount),0) AS actual
             FROM master_pic p
             LEFT JOIN transaction_allocations a
                    ON a.pic_name=p.name AND a.period_key=? AND a.property_id=p.property_id
             WHERE p.user_id=? AND p.status="active"
             GROUP BY p.id LIMIT 1'
        );
        $s->execute([$period, (int) $user['id']]);
        $pic = $s->fetch() ?: null;
    }

    // ══ GUARD per-sales: role 'sales' tak boleh lihat agregat lintas-sales ══
    // Selalu mode personal (MODE A). Jika belum tertaut PIC, tampilkan beranda
    // minimal — bukan agregat MODE B/C — agar metrik tidak bocor.
    if (current_role() === 'sales' && !$pic) {
        layout('Beranda', function () use ($uname, $period) {
            _m_home_styles();
            _m_hero('Halo, ' . $uname . ' 👋', 'Beranda Pribadi · ' . period_label($period), 0, 0, 0);
            _m_quick_actions();
            ?>
            <div class="m-card" style="text-align:center;padding:30px 20px;color:var(--muted);font-size:13.5px;line-height:1.6">
                Akun Anda belum tertaut sebagai PIC sales.<br>Hubungi admin agar metrik &amp; data penawaran pribadi Anda muncul di sini.
            </div>
            <?php
        });
        return;
    }

    // ══ MODE A: PIC SALES — beranda personal ══
    if ($pic) {
        $ppid = (int) $pic['property_id'];
        $sT = $pdo->prepare('SELECT COALESCE(target_amount,0) FROM targets_monthly WHERE period_key=? AND property_id=?');
        $sT->execute([$period, $ppid]);
        $propTarget = (float) $sT->fetchColumn();
        $myTarget   = $propTarget * (float) $pic['target_share']; // target_share = pecahan (0.25 = 25%)
        $actual     = (float) $pic['actual'];
        $achPct     = $myTarget > 0 ? (int) round($actual / $myTarget * 100) : 0;

        // Kontribusi per segmen (actual per module, dibandingkan total pribadi)
        $sm = $pdo->prepare('SELECT module, COALESCE(SUM(amount),0) amt FROM transaction_allocations WHERE pic_name=? AND period_key=? AND property_id=? GROUP BY module');
        $sm->execute([$pic['pic_name'], $period, $ppid]);
        $byMod = ['cl' => 0.0, 'media' => 0.0, 'gudang' => 0.0];
        foreach ($sm->fetchAll() as $r) $byMod[$r['module']] = (float) $r['amt'];

        // Peringkat di antara PIC pada properti & periode ini
        $rk = $pdo->prepare('SELECT pic_name, COALESCE(SUM(amount),0) amt FROM transaction_allocations WHERE period_key=? AND property_id=? AND pic_name IS NOT NULL GROUP BY pic_name ORDER BY amt DESC');
        $rk->execute([$period, $ppid]);
        $rankRows = $rk->fetchAll();
        $rank = 0; $rankTotal = count($rankRows);
        foreach ($rankRows as $i => $rr) { if ($rr['pic_name'] === $pic['pic_name']) { $rank = $i + 1; break; } }

        // Transaksi terbaru milik PIC
        $sr = $pdo->prepare('SELECT t.master_code, t.final_amount, c.company_name FROM transactions t LEFT JOIN master_clients c ON c.id=t.client_id WHERE t.pic_name=? AND t.property_id=? AND t.deleted_at IS NULL ORDER BY t.id DESC LIMIT 6');
        $sr->execute([$pic['pic_name'], $ppid]);
        $recent = $sr->fetchAll();

        layout('Beranda', function () use ($uname, $period, $pic, $achPct, $actual, $myTarget, $byMod, $rank, $rankTotal, $recent) {
            _m_home_styles();
            _m_hero('Halo, ' . $uname . ' 👋', 'Achievement Pribadi · ' . period_label($period), $achPct, $actual, $myTarget);
            if ($rank > 0): ?>
                <div style="margin-top:-6px;margin-bottom:13px;text-align:center">
                    <span class="m-badge-rank">🏆 Peringkat #<?= $rank ?> dari <?= $rankTotal ?> PIC</span>
                </div>
            <?php endif;
            _m_quick_actions();
            $total = array_sum($byMod);
            if ($total > 0): ?>
                <div class="m-sec-title">Kontribusimu per Segmen</div>
                <div class="m-card">
                    <?php _m_bars([
                        ['label' => 'Exhibition', 'actual' => $byMod['cl'],     'base' => $total],
                        ['label' => 'Media',      'actual' => $byMod['media'],  'base' => $total],
                        ['label' => 'Gudang',     'actual' => $byMod['gudang'], 'base' => $total],
                    ]); ?>
                </div>
            <?php endif;
            if (!empty($recent)): ?>
                <div class="m-sec-title">Transaksi Terbaru Kamu</div>
                <div class="m-card m-list"><?php _m_recent_list($recent); ?></div>
            <?php endif;
        });
        return;
    }

    // ══ MODE B: MULTI-PROPERTI — ringkasan per properti + agregat ══
    if ($isMulti) {
        $props = [];
        $pids  = [];
        $sumA = 0.0; $sumT = 0.0;
        foreach ($allowed as $ap) {
            $apId = (int) $ap['id'];
            $pids[] = $apId;
            $tS = $pdo->prepare('SELECT COALESCE(target_amount,0) FROM targets_monthly WHERE period_key=? AND property_id=?');
            $tS->execute([$period, $apId]);
            $t = (float) $tS->fetchColumn();
            $aS = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM transaction_allocations WHERE period_key=? AND property_id=?');
            $aS->execute([$period, $apId]);
            $a = (float) $aS->fetchColumn();
            $ach = $t > 0 ? (int) round($a / $t * 100) : 0;
            $props[] = ['name' => $ap['name'], 'actual' => $a, 'target' => $t, 'achieve' => $ach];
            $sumA += $a; $sumT += $t;
        }
        $aggPct = $sumT > 0 ? (int) round($sumA / $sumT * 100) : 0;
        // Achievement PIC dikelompokkan PER PROPERTI
        $picByProp = [];
        foreach ($allowed as $ap) {
            $rows = _m_pic_ach_rows($pdo, $period, [(int) $ap['id']]);
            if (!empty($rows)) $picByProp[] = ['name' => $ap['name'], 'rows' => $rows];
        }

        layout('Beranda', function () use ($uname, $period, $props, $aggPct, $sumA, $sumT, $picByProp) {
            _m_home_styles();
            _m_hero('Halo, ' . $uname . ' 👋', 'Semua Properti · ' . period_label($period), $aggPct, $sumA, $sumT);
            _m_quick_actions();
            ?>
            <div class="m-sec-title">Per Properti</div>
            <div class="m-card">
                <?php foreach ($props as $p):
                    $c = $p['achieve'] >= 100 ? 'var(--green)' : ($p['achieve'] >= 80 ? 'var(--amber)' : 'var(--accent)'); ?>
                <div class="m-prop">
                    <div class="pn">
                        <div class="nm"><?= h($p['name']) ?></div>
                        <div class="sub"><?= money($p['actual']) ?> / <?= money($p['target']) ?></div>
                    </div>
                    <div class="pp" style="color:<?= $c ?>"><?= $p['achieve'] ?><small style="font-size:13px;font-weight:700;opacity:.6">%</small></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($picByProp)): ?>
            <div class="m-sec-title">Achievement PIC per Properti</div>
            <?php foreach ($picByProp as $grp): ?>
            <div class="m-card">
                <div class="m-prop-h"><?= h($grp['name']) ?></div>
                <div class="m-list"><?php _m_pic_ach_list($grp['rows'], false); ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
            <?php
        }, ['prop_label' => 'Semua Properti']);
        return;
    }

    // ══ MODE C: PROPERTI TUNGGAL — ringkasan lengkap ══
    $pid = current_property_id();
    $d   = DashboardService::data($pdo, $period, $pid);
    $achPct = $d['target'] > 0 ? (int) round((float) $d['achievement_target'] * 100) : 0;
    $picAch = _m_pic_ach_rows($pdo, $period, [$pid]);

    layout('Beranda', function () use ($uname, $period, $d, $achPct, $picAch) {
        _m_home_styles();
        _m_hero('Halo, ' . $uname . ' 👋', 'Ringkasan · ' . period_label($period), $achPct, (float) $d['total_actual'], (float) $d['target']);
        _m_quick_actions();
        $segLabel = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
        ?>
        <div class="m-sec-title">Per Segmen</div>
        <div class="m-card">
            <?php _m_bars(array_map(fn($s) => [
                'label'  => $segLabel[$s['key']] ?? $s['label'],
                'actual' => (float) $s['actual'],
                'base'   => (float) $s['projection'],
            ], $d['segments'])); ?>
        </div>
        <?php if (!empty($picAch)): ?>
        <div class="m-sec-title">Achievement PIC</div>
        <div class="m-card m-list"><?php _m_pic_ach_list($picAch, false); ?></div>
        <?php endif; ?>
        <?php if (!empty($d['latest_transactions'])): ?>
        <div class="m-sec-title">Transaksi Terbaru</div>
        <div class="m-card m-list"><?php _m_recent_list($d['latest_transactions']); ?></div>
        <?php endif;
    });
}

// ─── EXECUTIVE SUMMARY (mobile) ───────────────────────────────────────────────
function mobile_exec_page(PDO $pdo): void
{
    require_permission('view_exec_summary');
    // Helper data exec dipakai ulang dari halaman desktop (tidak menggandakan query).
    require_once APP_ROOT . '/app/pages/exec_dashboard.php';

    $period     = getv('period', date('Y-m'));
    $periodDays = (int) date('t', strtotime($period . '-01'));
    $properties = $pdo->query("SELECT id, name FROM properties WHERE status='active' ORDER BY id")->fetchAll();

    $propData = [];
    foreach ($properties as $prop) {
        $pid = (int) $prop['id'];
        $propData[$pid] = _exec_fetch_prop_data($pdo, $pid, $period, $periodDays);
        $propData[$pid]['name'] = $prop['name'];
    }
    $combined = _exec_combine($propData);

    $segAct = ['cl' => 0.0, 'media' => 0.0, 'gudang' => 0.0];
    $segProj = ['cl' => 0.0, 'media' => 0.0, 'gudang' => 0.0];
    $newClients = 0;
    foreach ($propData as $d) {
        foreach (['cl', 'media', 'gudang'] as $m) {
            $segAct[$m]  += (float) ($d['actual_seg'][$m] ?? 0);
            $segProj[$m] += (float) ($d['projection'][$m] ?? 0);
        }
        $newClients += (int) $d['new_clients'];
    }
    $achPct = $combined['target'] > 0 ? (int) round($combined['actual'] / $combined['target'] * 100) : 0;
    $recPct = $combined['actual'] > 0 ? (int) round($combined['recurring'] / $combined['actual'] * 100) : 0;
    $gap    = $combined['actual'] - $combined['target'];

    layout('Executive Summary', function () use ($period, $periodDays, $combined, $propData, $segAct, $segProj, $newClients, $achPct, $recPct, $gap) {
        _m_home_styles();
        ?>
        <style>
        .m-kpis { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:13px; }
        .m-kpi { background:#fff; border:1px solid var(--line); border-radius:12px; padding:13px 14px; }
        .m-kpi .l { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
        .m-kpi .v { font-size:16px; font-weight:800; color:var(--ink); margin-top:4px; line-height:1.15; }
        .m-kpi .s { font-size:11px; color:var(--muted); margin-top:1px; }
        </style>
        <?php
        _m_hero('Executive Summary', 'Semua Properti · ' . period_label($period), $achPct, (float) $combined['actual'], (float) $combined['target']);
        ?>
        <div class="m-kpis">
            <div class="m-kpi"><div class="l">Proyeksi</div><div class="v"><?= money($combined['projection']) ?></div></div>
            <div class="m-kpi" style="background:#f0f9ff;border-color:#bae6fd"><div class="l" style="color:#0369a1">Recurring</div><div class="v" style="color:#0369a1"><?= money($combined['recurring']) ?></div><div class="s" style="color:#0369a1"><?= $recPct ?>% dari realisasi</div></div>
            <div class="m-kpi"><div class="l">Klien Baru</div><div class="v"><?= $newClients ?> klien</div></div>
            <div class="m-kpi"><div class="l"><?= $gap >= 0 ? 'Surplus' : 'Kurang Target' ?></div><div class="v" style="color:<?= $gap >= 0 ? 'var(--green)' : 'var(--accent)' ?>"><?= money(abs($gap)) ?></div></div>
        </div>

        <div class="m-sec-title">Per Segmen (gabungan)</div>
        <div class="m-card">
            <?php _m_bars([
                ['label' => 'Exhibition', 'actual' => $segAct['cl'],     'base' => $segProj['cl']],
                ['label' => 'Media',      'actual' => $segAct['media'],  'base' => $segProj['media']],
                ['label' => 'Gudang',     'actual' => $segAct['gudang'], 'base' => $segProj['gudang']],
            ]); ?>
        </div>

        <?php if (count($propData) > 1): ?>
        <div class="m-sec-title">Per Properti</div>
        <div class="m-card">
            <?php foreach ($propData as $d):
                $ap = $d['target'] > 0 ? (int) round($d['actual'] / $d['target'] * 100) : 0;
                $rp = $d['actual'] > 0 ? (int) round($d['recurring'] / $d['actual'] * 100) : 0;
                $c  = $ap >= 100 ? 'var(--green)' : ($ap >= 80 ? 'var(--amber)' : 'var(--accent)'); ?>
            <div class="m-prop">
                <div class="pn">
                    <div class="nm"><?= h($d['name']) ?></div>
                    <div class="sub"><?= money($d['actual']) ?> / <?= money($d['target']) ?> &middot; <span style="color:#0369a1;font-weight:700">Rec <?= $rp ?>%</span></div>
                </div>
                <div class="pp" style="color:<?= $c ?>"><?= $ap ?><small style="font-size:13px;font-weight:700;opacity:.6">%</small></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php
        $multiProp   = count($propData) > 1;
        // Urutan lantai DISAMAKAN dengan desktop (exec_dashboard.php $floorOrder).
        $floorOrder  = ['LG' => 1, 'GF' => 2, 'UG' => 3, 'FF' => 4, 'SF' => 5];
        $occSections = [
            ['floor_occ',  'Occupancy Exhibition per Lantai'],
            ['media_occ',  'Occupancy Media per Jenis'],
            ['gudang_occ', 'Occupancy Gudang per Lokasi'],
        ];
        foreach ($occSections as [$occKey, $occTitle]):
            $has = false;
            foreach ($propData as $d) { if (!empty($d[$occKey])) { $has = true; break; } }
            if (!$has) continue; ?>
            <div class="m-sec-title"><?= h($occTitle) ?></div>
            <?php foreach ($propData as $d):
                if (empty($d[$occKey])) continue;
                $occRows = $d[$occKey];
                // Exhibition & Gudang diurut pakai map lantai (sama dgn desktop); Media tetap urut nama.
                if ($occKey === 'floor_occ' || $occKey === 'gudang_occ') {
                    usort($occRows, fn($a, $b) =>
                        ($floorOrder[$a['group_key']] ?? 99) <=> ($floorOrder[$b['group_key']] ?? 99));
                }
            ?>
            <div class="m-card">
                <?php if ($multiProp): ?><div class="m-prop-h"><?= h($d['name']) ?></div><?php endif; ?>
                <?php _m_occ_list($occRows, $periodDays); ?>
            </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <?php
    }, ['prop_label' => 'Semua Properti']);
}

// ─── TRANSAKSI (list ringkas) ─────────────────────────────────────────────────
function mobile_transactions_page(PDO $pdo): void
{
    require_permission('view_transactions');
    $module  = getv('module', 'cl');
    if (!in_array($module, ['cl', 'media', 'gudang'], true)) $module = 'cl';
    $search  = trim((string) getv('search', ''));
    $page    = max(1, (int) getv('page', 1));
    $perPage = 20;
    $pid     = current_property_id();

    $where  = ['t.module = :module', 't.deleted_at IS NULL', 't.property_id = :pid'];
    $params = [':module' => $module, ':pid' => $pid];
    // Pembatasan per-sales: role 'sales' hanya lihat transaksi miliknya.
    // Query ini pakai named params; replikasi logika helper (#14): bila PIC
    // tertaut kosong, batasi HANYA ke created_by (jangan bocorkan baris ber-PIC kosong).
    if ($scope = current_sales_scope($pdo, $pid)) {
        if ($scope['pic'] === '') {
            $where[] = 't.created_by = :sc_uname';
            $params[':sc_uname'] = $scope['uname'];
        } else {
            $where[] = '(t.pic_name = :sc_pic OR t.created_by = :sc_uname)';
            $params[':sc_pic']   = $scope['pic'];
            $params[':sc_uname'] = $scope['uname'];
        }
    }
    if ($search !== '') {
        $where[] = '(c.company_name LIKE :s1 OR t.master_code LIKE :s2)';
        $params[':s1'] = '%' . $search . '%';
        $params[':s2'] = '%' . $search . '%';
    }
    $whereStr = implode(' AND ', $where);

    $cnt = $pdo->prepare('SELECT COUNT(*) FROM transactions t LEFT JOIN master_clients c ON c.id=t.client_id WHERE ' . $whereStr);
    $cnt->execute($params);
    $total      = (int) $cnt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;

    $sql = 'SELECT t.id, t.module, t.master_code, t.start_date, t.end_date, t.final_amount, t.pic_name,
                   t.billing_method, t.recurring_flag, (' . recurring_match_sql('t') . ') AS is_recurring,
                   c.company_name, c.brand_name
            FROM transactions t LEFT JOIN master_clients c ON c.id=t.client_id
            WHERE ' . $whereStr . ' ORDER BY t.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $offerFirst = get_setting($pdo, 'offer_first', '1') === '1';
    layout('Transaksi', function () use ($rows, $module, $search, $page, $totalPages, $offerFirst) {
        $modules = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
        $fmt = function (?string $d): string {
            $ts = $d ? strtotime($d) : false;
            return $ts ? date('d/m/y', $ts) : '—';
        };
        ?>
        <style>
        .m-tabs { display:flex; gap:7px; margin-bottom:12px; }
        .m-tabs a { flex:1; text-align:center; padding:9px; border-radius:10px; font-size:13px; font-weight:700; text-decoration:none; color:var(--muted); background:#fff; border:1px solid var(--line); }
        .m-tabs a.active { background:var(--primary); color:#fff; border-color:var(--primary); }
        .m-srch { display:flex; gap:8px; margin-bottom:13px; }
        .m-srch input { flex:1; } .m-srch button { flex-shrink:0; padding:0 16px; }
        .m-tx { background:#fff; border:1px solid var(--line); border-radius:13px; padding:13px 15px; margin-bottom:10px; display:block; box-shadow:0 1px 3px rgba(16,24,40,.05); }
        .m-tx .top { display:flex; justify-content:space-between; gap:10px; align-items:flex-start; }
        .m-tx .code { font-size:12px; font-weight:800; color:var(--primary-dark); }
        .m-tx .rec { display:inline-block; margin-left:6px; font-size:9.5px; font-weight:800; padding:1px 6px; border-radius:6px; vertical-align:middle; }
        .m-tx .rec.spread { background:#dbeafe; color:#0369a1; }
        .m-tx .rec.auto { background:#fef3c7; color:#92400e; }
        .m-tx .amt { font-size:14px; font-weight:900; color:var(--ink); white-space:nowrap; }
        .m-tx .nm { font-size:14px; font-weight:700; color:var(--ink); margin-top:4px; }
        .m-tx .meta { display:flex; justify-content:space-between; gap:10px; font-size:11.5px; color:var(--muted); margin-top:7px; }
        .m-empty { text-align:center; padding:46px 20px; color:var(--muted); }
        .m-page { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-top:6px; }
        .m-page a, .m-page span { font-size:13px; font-weight:700; }
        .m-page a { color:var(--primary); padding:8px 14px; border:1px solid var(--line); border-radius:9px; text-decoration:none; background:#fff; }
        .m-page a.disabled { color:#cbd5e1; pointer-events:none; }
        .m-fab { position:fixed; right:16px; bottom:calc(var(--m-nav-h) + env(safe-area-inset-bottom,0px) + 14px); z-index:55; width:54px; height:54px; border-radius:50%; background:linear-gradient(135deg,var(--primary),var(--primary2)); color:#fff; display:flex; align-items:center; justify-content:center; box-shadow:0 6px 18px rgba(13,148,136,.4); }
        </style>

        <div class="m-tabs">
            <?php foreach ($modules as $k => $lbl): ?>
                <a href="?r=m_transactions&module=<?= $k ?>" class="<?= $module === $k ? 'active' : '' ?>"><?= $lbl ?></a>
            <?php endforeach; ?>
        </div>

        <form class="m-srch" method="get">
            <input type="hidden" name="r" value="m_transactions">
            <input type="hidden" name="module" value="<?= h($module) ?>">
            <input type="search" name="search" value="<?= h($search) ?>" placeholder="Cari kode / client...">
            <button type="submit"><?= _m_icon('search') ?></button>
        </form>

        <?php if (empty($rows)): ?>
            <div class="m-card m-empty">Belum ada transaksi<?= $search !== '' ? ' untuk "' . h($search) . '"' : '' ?>.</div>
        <?php else: ?>
            <?php foreach ($rows as $t): ?>
            <a class="m-tx" href="?r=allocation_detail&id=<?= (int)$t['id'] ?>&module=<?= h($t['module']) ?>">
                <div class="top">
                    <span class="code"><?= h($t['master_code']) ?><?php
                        if (($t['billing_method'] ?? '') === 'spread' || !empty($t['recurring_flag'])): ?><span class="rec spread">Recurring</span><?php
                        elseif (!empty($t['is_recurring'])): ?><span class="rec auto">Recurring otomatis</span><?php
                        endif; ?></span>
                    <span class="amt"><?= money($t['final_amount']) ?></span>
                </div>
                <div class="nm"><?= h($t['company_name'] ?? '—') ?></div>
                <div class="meta">
                    <span><?= $fmt($t['start_date']) ?> – <?= $fmt($t['end_date']) ?></span>
                    <span><?= h($t['pic_name'] ?? '—') ?></span>
                </div>
            </a>
            <?php endforeach; ?>

            <?php if ($totalPages > 1): ?>
            <div class="m-page">
                <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="?r=m_transactions&module=<?= h($module) ?>&search=<?= urlencode($search) ?>&page=<?= $page - 1 ?>">← Sebelumnya</a>
                <span style="color:var(--muted)"><?= $page ?> / <?= $totalPages ?></span>
                <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="?r=m_transactions&module=<?= h($module) ?>&search=<?= urlencode($search) ?>&page=<?= $page + 1 ?>">Berikutnya →</a>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($offerFirst): ?>
            <?php if (can('manage_offers')): ?>
            <a class="m-fab" href="?r=offer_form&module=<?= h($module) ?>" title="Buat penawaran"><?= _m_icon('plus') ?></a>
            <?php endif; ?>
        <?php elseif (can('manage_transactions')): ?>
        <a class="m-fab" href="?r=transaction_form&module=<?= h($module) ?>" title="Tambah transaksi"><?= _m_icon('plus') ?></a>
        <?php endif; ?>
        <?php
    });
}

// ─── DAFTAR PENAWARAN (mobile) ────────────────────────────────────────────────
/** Segmen toggle Penawaran ↔ SKP/SKS (akses SKP tanpa tab nav tambahan). */
function _m_offer_seg(string $active): void
{
    $seg = ['m_offers' => 'Penawaran', 'm_skp' => 'SKP / SKS'];
    if (!can('manage_skp')) unset($seg['m_skp']);
    if (count($seg) < 2) return;
    ?>
    <div class="m-seg">
        <?php foreach ($seg as $rt => $lbl): ?>
            <a href="?r=<?= $rt ?>" class="<?= $active === $rt ? 'active' : '' ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
    </div>
    <style>
    .m-seg { display:flex; gap:0; margin-bottom:13px; background:#eef2f7; border-radius:11px; padding:3px; }
    .m-seg a { flex:1; text-align:center; padding:8px 4px; border-radius:9px; font-size:13px; font-weight:800; text-decoration:none; color:var(--muted); }
    .m-seg a.active { background:#fff; color:var(--primary-dark); box-shadow:0 1px 3px rgba(16,24,40,.12); }
    </style>
    <?php
}

/** Badge modul untuk kartu mobile (mandiri, tak bergantung offers.php). */
function _m_offer_mod(string $m): array
{
    return [
        'cl'     => ['Exhibition', '#0f766e', '#ccfbf1'],
        'media'  => ['Media', '#0369a1', '#e0f2fe'],
        'gudang' => ['Gudang', '#92400e', '#fef3c7'],
    ][$m] ?? [strtoupper($m), '#374151', '#f1f5f9'];
}

function mobile_offers_page(PDO $pdo): void
{
    require_permission('manage_offers');
    $pid = current_property_id();

    $tab = getv('tab', 'on_going');
    $tabStatuses = [
        'on_going' => ['draft', 'sent', 'nego'],
        'deal'     => ['deal'],
        'closed'   => ['cancelled'],
    ];
    if (!isset($tabStatuses[$tab])) $tab = 'on_going';

    $module = getv('module', '');
    if (!in_array($module, ['cl', 'media', 'gudang'], true)) $module = '';

    // Pembatasan per-sales: role 'sales' hanya lihat miliknya sendiri (#14).
    // Helper menutup kebocoran baris ber-PIC kosong: saat PIC tertaut kosong,
    // fragmen hanya membatasi ke created_by.
    [$scopeSql,  $scopePList]  = current_sales_scope_sql($pdo, $pid, 'o.pic_name', 'o.created_by');
    [$scopeSqlC, $scopePCount] = current_sales_scope_sql($pdo, $pid, 'pic_name', 'created_by');

    // Hitung badge per tab (ikut filter modul + scope).
    $counts = ['on_going' => 0, 'deal' => 0, 'closed' => 0];
    $cq = 'SELECT status, COUNT(*) c FROM offers WHERE property_id = ?' . ($module ? ' AND module = ?' : '') . $scopeSqlC . ' GROUP BY status';
    $cs = $pdo->prepare($cq);
    $cs->execute(array_merge([$pid], $module ? [$module] : [], $scopePCount));
    foreach ($cs->fetchAll() as $r) {
        foreach ($tabStatuses as $t => $sts) if (in_array($r['status'], $sts, true)) $counts[$t] += (int) $r['c'];
    }

    $in   = implode(',', array_fill(0, count($tabStatuses[$tab]), '?'));
    $stmt = $pdo->prepare(
        "SELECT o.*, c.company_name FROM offers o LEFT JOIN master_clients c ON c.id = o.client_id
         WHERE o.property_id = ? AND o.status IN ($in)" . ($module ? ' AND o.module = ?' : '') . $scopeSql . ' ORDER BY o.id DESC'
    );
    $stmt->execute(array_merge([$pid], $tabStatuses[$tab], $module ? [$module] : [], $scopePList));
    $rows = $stmt->fetchAll();

    layout('Penawaran', function () use ($rows, $tab, $counts, $module) {
        $badge = [
            'draft'     => ['Draft', '#475569', '#f1f5f9'],
            'sent'      => ['Terkirim', '#0369a1', '#e0f2fe'],
            'nego'      => ['Negosiasi', '#92400e', '#fef3c7'],
            'deal'      => ['DEAL', '#166534', '#dcfce7'],
            'cancelled' => ['Tidak Deal', '#991b1b', '#fee2e2'],
        ];
        $fmt = function (?string $d): string {
            $ts = $d ? strtotime($d) : false;
            return $ts ? date('d/m/y', $ts) : '—';
        };
        $tabs = ['on_going' => 'On Going', 'deal' => 'Deal', 'closed' => 'Tidak Deal'];
        $mods = ['' => 'Semua', 'cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
        ?>
        <style>
        .m-tabs { display:flex; gap:7px; margin-bottom:11px; }
        .m-tabs a { flex:1; text-align:center; padding:8px 4px; border-radius:10px; font-size:12.5px; font-weight:700; text-decoration:none; color:var(--muted); background:#fff; border:1px solid var(--line); }
        .m-tabs a.active { background:var(--primary); color:#fff; border-color:var(--primary); }
        .m-tabs .b { display:inline-block; min-width:17px; padding:0 5px; margin-left:3px; border-radius:9px; font-size:10.5px; background:#eef2f7; color:#475569; }
        .m-tabs a.active .b { background:rgba(255,255,255,.28); color:#fff; }
        .m-mfil { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:13px; }
        .m-mfil a { padding:5px 11px; border-radius:999px; font-size:12px; font-weight:700; text-decoration:none; color:var(--muted); background:#fff; border:1px solid var(--line); }
        .m-ofr { display:block; background:#fff; border:1px solid var(--line); border-radius:13px; padding:13px 15px; margin-bottom:10px; box-shadow:0 1px 3px rgba(16,24,40,.05); }
        .m-ofr .top { display:flex; justify-content:space-between; gap:10px; align-items:flex-start; }
        .m-ofr .no { font-size:12px; font-weight:800; color:var(--primary-dark); }
        .m-ofr .st { font-size:10px; font-weight:800; padding:2px 8px; border-radius:7px; white-space:nowrap; }
        .m-ofr .nm { font-size:14px; font-weight:700; color:var(--ink); margin-top:6px; }
        .m-ofr .meta { display:flex; justify-content:space-between; gap:10px; font-size:11.5px; color:var(--muted); margin-top:8px; align-items:center; }
        .m-ofr .mb { font-size:9.5px; font-weight:800; padding:2px 7px; border-radius:6px; }
        .m-ofr .amt { font-weight:900; color:var(--ink); white-space:nowrap; }
        .m-ofr-main { display:block; text-decoration:none; color:inherit; }
        .m-ofr .acts { display:flex; flex-wrap:wrap; gap:8px; margin-top:11px; }
        .m-ofr .acts a { flex:1 1 calc(50% - 4px); text-align:center; padding:8px 6px; border-radius:9px; font-size:12.5px; font-weight:700; text-decoration:none; border:1px solid var(--line); color:var(--primary-dark); background:#f8fafc; white-space:nowrap; }
        .m-empty { text-align:center; padding:46px 20px; color:var(--muted); }
        .m-fab { position:fixed; right:16px; bottom:calc(var(--m-nav-h) + env(safe-area-inset-bottom,0px) + 14px); z-index:55; width:54px; height:54px; border-radius:50%; background:linear-gradient(135deg,var(--primary),var(--primary2)); color:#fff; display:flex; align-items:center; justify-content:center; box-shadow:0 6px 18px rgba(13,148,136,.4); }
        </style>

        <?php _m_offer_seg('m_offers'); ?>
        <div class="m-tabs">
            <?php $mq = $module ? '&module=' . $module : ''; foreach ($tabs as $k => $lbl): ?>
                <a href="?r=m_offers&tab=<?= $k . $mq ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= $lbl ?><span class="b"><?= (int) $counts[$k] ?></span></a>
            <?php endforeach; ?>
        </div>

        <div class="m-mfil">
            <?php foreach ($mods as $mk => $mlbl): $act = $module === $mk;
                [$ml, $mc, $mbg] = $mk ? _m_offer_mod($mk) : ['', '#fff', 'var(--primary)']; ?>
                <a href="?r=m_offers&tab=<?= $tab ?><?= $mk ? '&module=' . $mk : '' ?>"
                   style="<?= $act ? 'background:' . ($mk ? $mbg : 'var(--primary)') . ';color:' . ($mk ? $mc : '#fff') . ';border-color:transparent' : '' ?>"><?= h($mlbl) ?></a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($rows)): ?>
            <div class="m-card m-empty">Belum ada penawaran di tab ini.</div>
        <?php else: foreach ($rows as $o):
            $st = $badge[$o['status']] ?? $badge['draft'];
            [$ml, $mc, $mbg] = _m_offer_mod($o['module']);
            $amt = !empty($o['override_amount']) ? $o['override_amount'] : ($o['monthly_amount'] ?? 0); ?>
            <div class="m-ofr">
                <a class="m-ofr-main" href="?r=offer_view&id=<?= (int) $o['id'] ?>">
                    <div class="top">
                        <span class="no"><?= h($o['offer_no'] ?: '(draft)') ?></span>
                        <span class="st" style="color:<?= $st[1] ?>;background:<?= $st[2] ?>"><?= $st[0] ?></span>
                    </div>
                    <div class="nm"><?= h($o['company_name'] ?? '—') ?></div>
                    <div class="meta">
                        <span class="mb" style="color:<?= $mc ?>;background:<?= $mbg ?>"><?= h($ml) ?></span>
                        <span><?= $fmt($o['start_date']) ?> – <?= $fmt($o['end_date']) ?></span>
                        <span class="amt"><?= money($amt) ?>/bln</span>
                    </div>
                </a>
                <?php if (!empty($o['offer_no'])): ?>
                <div class="acts">
                    <a href="?r=offer_print&id=<?= (int) $o['id'] ?>" target="_blank">🖨 PDF</a>
                    <a href="#" onclick="claraSharePdf('?r=offer_print&id=<?= (int) $o['id'] ?>','<?= h($o['offer_no']) ?>',this);return false">📤 Bagikan</a>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; endif; ?>

        <a class="m-fab" href="?r=offer_form<?= $module ? '&module=' . h($module) : '' ?>" title="Buat penawaran"><?= _m_icon('plus') ?></a>
        <?php
    });
}

// ─── DAFTAR SKP / SKS (mobile) ────────────────────────────────────────────────
function mobile_skp_page(PDO $pdo): void
{
    require_permission('manage_skp');
    $pid    = current_property_id();
    $status = getv('status', '');
    $where  = ['s.property_id = ?']; $params = [$pid];
    if (in_array($status, ['draft', 'submitted', 'approved', 'signed', 'rejected'], true)) {
        $where[] = 's.status = ?'; $params[] = $status;
    }
    $module = getv('module', '');
    if (!in_array($module, ['cl', 'media', 'gudang'], true)) $module = '';
    if ($module) { $where[] = 'COALESCE(t.module, o.module) = ?'; $params[] = $module; }
    // Pembatasan per-sales: hanya SKP dari penawaran miliknya / yang ia buat (#14).
    // Helper memakai PIC dari offer (o.pic_name) & pembuat SKP (s.created_by);
    // saat PIC tertaut kosong, fragmen hanya membatasi ke s.created_by.
    [$scopeSkpSql, $scopeSkpP] = current_sales_scope_sql($pdo, $pid, 'o.pic_name', 's.created_by');
    if ($scopeSkpSql !== '') {
        $where[] = substr($scopeSkpSql, 5); // buang prefiks ' AND ' (di-join ulang oleh implode)
        $params  = array_merge($params, $scopeSkpP);
    }
    $stmt = $pdo->prepare(
        'SELECT s.*,
                COALESCE(t.master_code, o.master_code) master_code,
                COALESCE(t.start_date, o.start_date)   start_date,
                COALESCE(t.end_date, o.end_date)       end_date,
                COALESCE(t.module, o.module)           module,
                COALESCE(tc.company_name, oc.company_name) company_name
         FROM skp_documents s
         LEFT JOIN transactions t ON t.id = s.transaction_id
         LEFT JOIN master_clients tc ON tc.id = t.client_id
         LEFT JOIN offers o ON o.id = s.offer_id
         LEFT JOIN master_clients oc ON oc.id = o.client_id
         WHERE ' . implode(' AND ', $where) . ' ORDER BY s.id DESC'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    layout('SKP / SKS', function () use ($rows, $status, $module) {
        $badge = [
            'draft'     => ['Draft', '#475569', '#f1f5f9'],
            'submitted' => ['Menunggu Approval', '#92400e', '#fef3c7'],
            'approved'  => ['Disetujui · perlu TTD', '#166534', '#dcfce7'],
            'signed'    => ['Ditandatangani', '#0369a1', '#e0f2fe'],
            'rejected'  => ['Ditolak', '#991b1b', '#fee2e2'],
        ];
        $fmt = function (?string $d): string {
            $ts = $d ? strtotime($d) : false;
            return $ts ? date('d/m/y', $ts) : '—';
        };
        $sts  = ['' => 'Semua', 'draft' => 'Draft', 'submitted' => 'Menunggu', 'approved' => 'Perlu TTD', 'signed' => 'TTD', 'rejected' => 'Ditolak'];
        $mods = ['' => 'Semua', 'cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
        ?>
        <style>
        .m-tabs { display:flex; gap:6px; margin-bottom:11px; overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .m-tabs a { flex:0 0 auto; text-align:center; padding:7px 12px; border-radius:10px; font-size:12.5px; font-weight:700; text-decoration:none; color:var(--muted); background:#fff; border:1px solid var(--line); white-space:nowrap; }
        .m-tabs a.active { background:var(--primary); color:#fff; border-color:var(--primary); }
        .m-mfil { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:13px; }
        .m-mfil a { padding:5px 11px; border-radius:999px; font-size:12px; font-weight:700; text-decoration:none; color:var(--muted); background:#fff; border:1px solid var(--line); }
        .m-ofr { display:block; background:#fff; border:1px solid var(--line); border-radius:13px; padding:13px 15px; margin-bottom:10px; box-shadow:0 1px 3px rgba(16,24,40,.05); }
        .m-ofr .top { display:flex; justify-content:space-between; gap:10px; align-items:flex-start; }
        .m-ofr .no { font-size:12px; font-weight:800; color:var(--primary-dark); }
        .m-ofr .st { font-size:9.5px; font-weight:800; padding:2px 8px; border-radius:7px; white-space:nowrap; }
        .m-ofr .nm { font-size:14px; font-weight:700; color:var(--ink); margin-top:6px; }
        .m-ofr .meta { display:flex; justify-content:space-between; gap:10px; font-size:11.5px; color:var(--muted); margin-top:8px; align-items:center; }
        .m-ofr .mb { font-size:9.5px; font-weight:800; padding:2px 7px; border-radius:6px; }
        .m-ofr .acts { display:flex; flex-wrap:wrap; gap:8px; margin-top:11px; }
        .m-ofr .acts a { flex:1 1 calc(50% - 4px); text-align:center; padding:8px 6px; border-radius:9px; font-size:12.5px; font-weight:700; text-decoration:none; border:1px solid var(--line); color:var(--primary-dark); background:#f8fafc; white-space:nowrap; }
        .m-empty { text-align:center; padding:46px 20px; color:var(--muted); }
        </style>

        <?php _m_offer_seg('m_skp'); ?>
        <div class="m-tabs">
            <?php $mq = $module ? '&module=' . $module : ''; foreach ($sts as $k => $lbl): ?>
                <a href="?r=m_skp<?= $k ? '&status=' . $k : '' ?><?= $mq ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= $lbl ?></a>
            <?php endforeach; ?>
        </div>
        <div class="m-mfil">
            <?php $sq = $status ? '&status=' . $status : ''; foreach ($mods as $mk => $mlbl): $act = $module === $mk;
                [$ml, $mc, $mbg] = $mk ? _m_offer_mod($mk) : ['', '#fff', 'var(--primary)']; ?>
                <a href="?r=m_skp<?= $sq ?><?= $mk ? '&module=' . $mk : '' ?>"
                   style="<?= $act ? 'background:' . ($mk ? $mbg : 'var(--primary)') . ';color:' . ($mk ? $mc : '#fff') . ';border-color:transparent' : '' ?>"><?= h($mlbl) ?></a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($rows)): ?>
            <div class="m-card m-empty">Belum ada SKP/SKS.<br><span style="font-size:12px">Dibuat dari Preview Penawaran yang sudah DEAL.</span></div>
        <?php else: foreach ($rows as $s):
            $st = $badge[$s['status']] ?? $badge['draft'];
            [$ml, $mc, $mbg] = _m_offer_mod($s['module'] ?? '');
            $edit = in_array($s['status'], ['draft', 'rejected'], true); ?>
            <div class="m-ofr">
                <div class="top">
                    <span class="no"><?= h($s['skp_no'] ?: '(draft)') ?></span>
                    <span class="st" style="color:<?= $st[1] ?>;background:<?= $st[2] ?>"><?= $st[0] ?></span>
                </div>
                <div class="nm"><?= h($s['company_name'] ?? '—') ?></div>
                <div class="meta">
                    <span class="mb" style="color:<?= $mc ?>;background:<?= $mbg ?>"><?= h($ml) ?></span>
                    <span><?= $fmt($s['start_date']) ?> – <?= $fmt($s['end_date']) ?></span>
                    <span><?= h($s['master_code'] ?? '—') ?></span>
                </div>
                <div class="acts">
                    <a href="?r=skp_form&id=<?= (int) $s['id'] ?>"><?= $edit ? '✏️ Edit' : '👁 Lihat' ?></a>
                    <?php if (in_array($s['status'], ['approved', 'signed'], true)): ?>
                        <a href="?r=skp_print&id=<?= (int) $s['id'] ?>" target="_blank">🖨 PDF</a>
                        <a href="#" onclick="claraSharePdf('?r=skp_print&id=<?= (int) $s['id'] ?>','<?= h(($s['skp_no'] ?: 'SKP')) ?>',this);return false">📤 Bagikan</a>
                    <?php endif; ?>
                    <?php if (($s['sign_method'] ?? '') === 'wet' && !empty($s['signed_doc_path'])): ?>
                        <a href="<?= h($s['signed_doc_path']) ?>" target="_blank">📄 Scan</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
        <?php
    });
}
