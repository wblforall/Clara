<?php
declare(strict_types=1);

function display_authorized(array $config): bool
{
    $token = (string) getv('token', '');
    return $token !== ''
        && $config['display_token'] !== 'change-this-display-token'
        && hash_equals($config['display_token'], $token);
}

function xlsx_download(string $filename, array $headers, array $rows): void
{
    $col = static function (int $n): string {
        $r = '';
        do { $r = chr(65 + ($n % 26)) . $r; $n = intdiv($n, 26) - 1; } while ($n >= 0);
        return $r;
    };
    $cell = static function (int $c, int $r, $v) use ($col): string {
        $ref = $col($c) . $r;
        if (is_numeric($v) && $v !== '') {
            return "<c r=\"$ref\"><v>" . htmlspecialchars((string) $v, ENT_XML1) . "</v></c>";
        }
        return "<c r=\"$ref\" t=\"inlineStr\"><is><t>" . htmlspecialchars((string) $v, ENT_XML1) . "</t></is></c>";
    };
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    $xml .= '<row r="1">';
    foreach ($headers as $i => $h) { $xml .= $cell($i, 1, $h); }
    $xml .= '</row>';
    foreach ($rows as $ri => $row) {
        $rn = $ri + 2;
        $xml .= "<row r=\"$rn\">";
        foreach (array_values($row) as $ci => $v) { $xml .= $cell($ci, $rn, $v); }
        $xml .= '</row>';
    }
    $xml .= '</sheetData></worksheet>';
    $tmpDir = sys_get_temp_dir();
    $tmp = tempnam($tmpDir, 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
    $zip->close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

/** Ikon inline (stroke SVG) untuk shell mobile. */
function _m_icon(string $name): string
{
    $p = [
        'home'    => '<path d="M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10"/>',
        'tx'      => '<path d="M4 4h16v16H4zM8 9h8M8 13h8M8 17h5"/>',
        'renew'   => '<path d="M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5"/>',
        'desktop' => '<path d="M3 4h18v12H3zM8 20h8M12 16v4"/>',
        'plus'    => '<path d="M12 5v14M5 12h14"/>',
        'search'  => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'chart'   => '<path d="M3 21h18M6 21v-7M11 21V8M16 21v-4"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
         . 'stroke-linecap="round" stroke-linejoin="round" width="22" height="22">'
         . ($p[$name] ?? '') . '</svg>';
}

/** Tab bottom-nav mana yang aktif berdasarkan route saat ini. */
function _mobile_active_tab(string $route): string
{
    if ($route === 'm_home') return 'home';
    if ($route === 'm_transactions' || str_starts_with($route, 'transaction')) return 'tx';
    if ($route === 'm_exec' || $route === 'exec_dashboard') return 'exec';
    if ($route === 'renewals') return 'renew';
    return '';
}

function layout(string $title, callable $body, array $opts = []): void
{
    global $route, $config, $pdo;
    $appName = $config['app_name'] ?? 'CLARA';
    $flash   = flash();
    $nav     = [
        ['', 'dashboard', 'Dashboard', 'view_dashboard'],
        ['', 'exec_dashboard', 'Executive Summary', 'view_exec_summary'],
        ['', 'trend', 'Trend Revenue', 'view_dashboard'],
        ['', 'comparison', 'Perbandingan Periode', 'view_dashboard'],
        ['Input', 'offers', 'Surat Penawaran', 'manage_offers'],
        ['', 'transactions&module=cl', 'Exhibition', 'view_transactions'],
        ['', 'transactions&module=media', 'Media', 'view_transactions'],
        ['', 'transactions&module=gudang', 'Gudang', 'view_transactions'],
        ['', 'skp', 'SKP Pameran', 'manage_skp'],
        ['Master Data', 'master&type=cl', 'Master Exhibition', 'view_master'],
        ['', 'master&type=media', 'Master Media', 'view_master'],
        ['', 'master&type=gudang', 'Master Gudang', 'view_master'],
        ['', 'master&type=pic', 'Master PIC', 'view_master'],
        ['', 'master&type=target', 'Target', 'view_master'],
        ['', 'clients', 'Master Client', 'view_master'],
        ['', 'master_referrer', 'Master Referrer', 'view_master'],
        ['Analisa', 'daily_occupancy', 'Occupancy Harian', 'view_dashboard'],
        ['', 'renewals', 'Renewal Kontrak', 'view_renewals'],
        ['', 'client_analysis', 'Analisa Market Client', 'view_master'],
        ['', 'pic_report', 'Laporan PIC', 'view_pic_report'],
        ['', 'pic_performance', 'Performa PIC', 'view_pic_report'],
        ['', 'pic_reward', 'Rewarding PIC', 'view_pic_report'],
        ['', 'commission_sim', 'Simulasi Komisi', 'view_commission_sim'],
        ['Admin', 'recurring_candidates', 'Konversi Recurring', 'manage_transactions'],
        ['', 'lookup_manage', 'Kelola Opsi Dropdown', 'manage_master'],
        ['', 'users', 'Users & Role', 'manage_users'],
        ['', 'roles', 'Role & Permission', 'manage_users'],
        ['', 'audit', 'Activity Log', 'view_logs'],
        ['', 'deleted_transactions', 'Transaksi Dihapus', 'manage_deleted'],
    ];

    $currentProp  = current_property();
    $isMulti      = is_multi_property();
    $allowedProps = allowed_properties();
    $currentPid   = current_property_id();
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?> — <?= h($appName) ?></title>
        <link rel="icon" type="image/png" href="assets/clara-logo.png">
        <?php pwa_head(); ?>
        <link rel="stylesheet" href="assets/app.css?v=<?= CSS_VER ?>">
        <link rel="stylesheet" href="assets/flatpickr.min.css">
        <style>
        /* Flatpickr custom theme — compact */
        .flatpickr-input{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='15' height='15' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='4' width='18' height='18' rx='2'/%3E%3Cline x1='16' y1='2' x2='16' y2='6'/%3E%3Cline x1='8' y1='2' x2='8' y2='6'/%3E%3Cline x1='3' y1='10' x2='21' y2='10'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:32px!important;cursor:pointer}
        .flatpickr-calendar{border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.14);border:1px solid #E2E8F0;font-family:inherit;overflow:hidden;width:252px!important}
        .flatpickr-rContainer{width:252px}
        .dayContainer{width:238px;min-width:238px;max-width:238px}
        .flatpickr-months{background:#0D9488;padding:4px 0;border-radius:0}
        .flatpickr-month{color:#fff;height:34px}
        .flatpickr-current-month{color:#fff;font-weight:700;font-size:13px;padding-top:8px;width:75%;left:12.5%}
        .flatpickr-current-month .flatpickr-monthDropdown-months{background:transparent;color:#fff;font-weight:700;font-size:13px}
        .flatpickr-current-month .numInputWrapper span{border-color:rgba(255,255,255,.3)}
        .flatpickr-current-month input.cur-year{color:#fff;font-weight:700;font-size:13px}
        .flatpickr-prev-month,.flatpickr-next-month{color:#fff!important;fill:#fff!important;padding:6px 10px;top:2px}
        .flatpickr-prev-month:hover svg,.flatpickr-next-month:hover svg{fill:rgba(255,255,255,.7)!important}
        .flatpickr-weekdays{background:#F0FDF4;border-bottom:1px solid #D1FAE5;height:28px}
        .flatpickr-weekday{color:#0D9488;font-weight:700;font-size:10px;line-height:28px}
        .flatpickr-days{width:252px}
        .flatpickr-day{border-radius:6px;font-size:12px;height:28px;line-height:28px;max-width:28px;margin:1.5px}
        .flatpickr-day:hover:not(.selected):not(.today){background:#F0FDF4;border-color:#D1FAE5}
        .flatpickr-day.selected,.flatpickr-day.selected:hover{background:#0D9488;border-color:#0D9488;color:#fff;font-weight:700}
        .flatpickr-day.today:not(.selected){border-color:#0D9488;color:#0D9488;font-weight:700}
        .flatpickr-day.flatpickr-disabled,.flatpickr-day.flatpickr-disabled:hover{color:#CBD5E1}
        /* Weekend */
        .flatpickr-day.fp-sat,.flatpickr-day.fp-sun{color:#EF4444}
        .flatpickr-day.fp-sat.selected,.flatpickr-day.fp-sun.selected,.flatpickr-day.fp-sat.today,.flatpickr-day.fp-sun.today{color:#fff;background:#EF4444;border-color:#EF4444}
        /* Libur nasional */
        .flatpickr-day.fp-holiday{color:#EF4444;font-weight:700}
        .flatpickr-day.fp-holiday::after{content:'';display:block;width:4px;height:4px;background:#EF4444;border-radius:50%;margin:-3px auto 0;position:relative;top:-2px}
        .flatpickr-day.fp-holiday.selected{color:#fff;background:#EF4444;border-color:#EF4444}
        .flatpickr-day.fp-holiday.selected::after{background:#fff}
        /* Cuti bersama */
        .flatpickr-day.fp-cuti{color:#F97316;font-weight:700}
        .flatpickr-day.fp-cuti::after{content:'';display:block;width:4px;height:4px;background:#F97316;border-radius:50%;margin:-3px auto 0;position:relative;top:-2px}
        .flatpickr-day.fp-cuti.selected{color:#fff;background:#F97316;border-color:#F97316}
        .flatpickr-day.fp-cuti.selected::after{background:#fff}
        </style>
        <?php if ($isMulti): ?>
        <style>
            .prop-tabs{display:flex;gap:4px;align-items:center}
            .prop-tab{padding:5px 14px;border-radius:6px;font-size:12px;font-weight:700;color:var(--ink2,#3D4A5C);text-decoration:none;transition:background .15s,color .15s;white-space:nowrap;border:1px solid var(--line,#e2e8f0);background:#fff}
            .prop-tab.active{background:var(--primary,#0D9488);color:#fff;border-color:var(--primary,#0D9488)}
            .prop-tab:hover:not(.active){background:var(--soft,#f0fdf4);color:var(--primary-dark,#0A7267)}
        </style>
        <?php endif; ?>
        <?php if (mobile_view_active()): ?>
        <style>
        :root { --m-nav-h: 62px; }
        .m-shell { min-height: 100vh; padding-bottom: calc(var(--m-nav-h) + env(safe-area-inset-bottom, 0px) + 14px); }
        .m-top { position: sticky; top: 0; z-index: 50; background: linear-gradient(135deg, var(--primary), var(--primary2)); color: #fff; padding: 14px 16px; padding-top: calc(14px + env(safe-area-inset-top, 0px)); display: flex; align-items: center; justify-content: space-between; gap: 12px; box-shadow: 0 2px 10px rgba(13,148,136,.25); }
        .m-top .t-title { font-size: 17px; font-weight: 800; line-height: 1.15; }
        .m-top .t-sub { font-size: 11.5px; opacity: .85; margin-top: 2px; }
        .m-top a.t-desk { color: #fff; font-size: 11px; font-weight: 700; background: rgba(255,255,255,.18); padding: 6px 11px; border-radius: 999px; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
        .m-top .t-prop-sw { margin-top: 4px; max-width: 100%; width: auto; background: rgba(255,255,255,.18); color: #fff; border: none; border-radius: 8px; padding: 4px 26px 4px 9px; font-size: 12px; font-weight: 700; appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23ffffff' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 8px center; }
        .m-top .t-prop-sw option { color: #0F1623; }
        .m-body { padding: 14px; }
        .m-flash { margin: 12px 14px 0; padding: 11px 14px; background: var(--soft); color: var(--primary-dark); border-radius: 10px; font-weight: 600; font-size: 13px; border-left: 3px solid var(--primary); }
        .m-nav { position: fixed; left: 0; right: 0; bottom: 0; z-index: 60; height: calc(var(--m-nav-h) + env(safe-area-inset-bottom, 0px)); padding-bottom: env(safe-area-inset-bottom, 0px); background: #fff; border-top: 1px solid var(--line); display: flex; box-shadow: 0 -2px 14px rgba(16,24,40,.06); }
        .m-nav a { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; color: var(--muted); font-size: 10.5px; font-weight: 700; }
        .m-nav a.active { color: var(--primary); }
        .m-sec-title { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); margin: 4px 2px 10px; }
        .m-card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 16px; box-shadow: 0 1px 3px rgba(16,24,40,.05); margin-bottom: 13px; }
        </style>
        <?php endif; ?>
    </head>
    <body>
    <?php $isMobile = mobile_view_active(); ?>
    <?php if ($isMobile): ?>
        <div class="m-shell">
            <div class="m-top">
                <div style="min-width:0">
                    <div class="t-title"><?= h($title) ?></div>
                    <?php if (!isset($opts['prop_label']) && $isMulti): ?>
                    <select class="t-prop-sw" onchange="if(this.value)location.href=this.value" aria-label="Ganti properti">
                        <?php foreach ($allowedProps as $ap): ?>
                        <option value="?r=switch_property&to=<?= (int)$ap['id'] ?>&back=<?= urlencode('?' . ($_SERVER['QUERY_STRING'] ?? '')) ?>" <?= ((int)$ap['id'] === $currentPid) ? 'selected' : '' ?>><?= h($ap['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <div class="t-sub"><?= h($appName) ?> &middot; <?= h($opts['prop_label'] ?? ($currentProp['name'] ?? '')) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($flash): ?><div class="m-flash"><?= h($flash) ?></div><?php endif; ?>
            <div class="m-body"><?php $body(); ?></div>
        </div>
        <nav class="m-nav">
            <?php
            $_mtabs = [
                'home'  => ['m_home',         'Beranda',   'home',  'view_dashboard'],
                'tx'    => ['m_transactions', 'Transaksi', 'tx',    'view_transactions'],
                'exec'  => ['m_exec',         'Eksekutif', 'chart', 'view_exec_summary'],
                'renew' => ['renewals',       'Renewal',   'renew', 'view_renewals'],
            ];
            $_mact  = _mobile_active_tab($route);
            foreach ($_mtabs as $_k => [$_rt, $_lbl, $_ic, $_perm]):
                if (!can($_perm)) continue; ?>
                <a href="?r=<?= h($_rt) ?>" class="<?= $_mact === $_k ? 'active' : '' ?>"><?= _m_icon($_ic) ?><span><?= h($_lbl) ?></span></a>
            <?php endforeach; ?>
        </nav>
    <?php else: ?>
    <div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>
    <div class="app">
        <aside class="sidebar" id="sidebar">
            <div class="brand">
                <img class="brand-logo" src="assets/clara-logo.png" alt="CLARA" onerror="this.hidden=true;this.nextElementSibling.style.display='flex'">
                <div style="display:none;align-items:center;gap:10px">
                    <div class="brand-icon">CL</div>
                    <div class="brand-text"><?= h($appName) ?><small>Casual Leasing Achievement<br>& Revenue Analytics</small></div>
                </div>
            </div>
            <nav class="nav">
                <?php
                $_adminRoutes = ['lookup_manage','users','user_form','roles','audit','deleted_transactions'];
                $_adminOpen   = in_array($route, $_adminRoutes);
                $_inAdmin     = false;
                foreach ($nav as [$group, $key, $label, $permission]):
                    if (!can($permission)) continue;
                    parse_str('r=' . $key, $_kp);
                    $_active = ($route === ($_kp['r'] ?? ''));
                    if ($_active) {
                        foreach ($_kp as $_pk => $_pv) {
                            if ($_pk === 'r') continue;
                            if (getv($_pk) !== $_pv) { $_active = false; break; }
                        }
                    }
                    if ($group === 'Admin' && !$_inAdmin) {
                        $_inAdmin = true; ?>
                        <button type="button" class="nav-admin-btn <?= $_adminOpen ? 'open' : '' ?>"
                            onclick="var p=this.nextElementSibling;p.classList.toggle('open');this.classList.toggle('open')">
                            Admin <span class="nav-admin-arr">▾</span>
                        </button>
                        <div class="nav-admin-panel <?= $_adminOpen ? 'open' : '' ?>">
                    <?php } elseif ($group !== '' && $group !== 'Admin') {
                        echo '<div class="nav-label">' . h($group) . '</div>';
                    } ?>
                    <a class="<?= $_active ? 'active' : '' ?>" href="?r=<?= h($key) ?>"><?= h($label) ?></a>
                <?php endforeach; ?>
                <?php if ($_inAdmin): ?></div><?php endif; ?>
                <div class="nav-logout">
                    <?php if (is_mobile_device()): ?>
                    <a href="?view=mobile">📱 Tampilan HP</a>
                    <?php endif; ?>
                    <a href="?r=logout">↩ Logout</a>
                </div>
            </nav>
            <div style="padding:10px 16px 14px;border-top:1px solid #1A2D40;margin-top:2px">
                <div style="font-size:9px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#2E4A63;margin-bottom:3px">CLARA &middot; v<?= h($config['app_version'] ?? '1.0') ?></div>
                <div style="font-size:9.5px;color:#3A5570;line-height:1.5">&copy; 2026 IT Dept. PT. Wulandari Bangun Laksana Tbk.</div>
            </div>
        </aside>
        <main class="main">
            <div class="topbar">
                <div style="display:flex;align-items:center;gap:12px;min-width:0;flex:1">
                    <button class="hamburger" onclick="openSidebar()" title="Menu">&#9776;</button>
                    <div style="min-width:0;flex:1">
                        <h1><?= h($title) ?></h1>
                        <div class="muted">
                            Casual Leasing Achievement &amp; Revenue Analytics
                            &middot; <span style="color:var(--primary);font-weight:700"><?= h($currentProp['name']) ?></span>
                        </div>
                    </div>
                    <?php if ($isMulti && empty($opts['hide_prop_tabs'])): ?>
                    <div class="prop-tabs">
                        <?php foreach ($allowedProps as $ap): ?>
                        <a href="?r=switch_property&to=<?= (int)$ap['id'] ?>&back=<?= urlencode('?' . $_SERVER['QUERY_STRING']) ?>"
                           class="prop-tab <?= ((int)$ap['id'] === $currentPid) ? 'active' : '' ?>">
                            <?= h($ap['name']) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="badge" style="flex-shrink:0"><?= h($_SESSION['user']['name'] ?? 'User') ?> &middot; <?= h(roles()[current_role()] ?? current_role()) ?></div>
            </div>
            <?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
            <?php $body(); ?>
        </main>
    </div>
    <?php endif; ?>
    <?php if (!$isMobile && !empty($_SESSION['_show_welcome']) && isset($pdo)):
        unset($_SESSION['_show_welcome']);
        $wpPeriod = date('Y-m');
        $wpPid    = current_property_id();
        $wpUser   = $_SESSION['user'] ?? [];
        $wpProp   = current_property();
        $wpName   = $wpUser['name'] ?? 'User';

        // Semua property_id yang boleh diakses user ini
        $wpPids = array_map('intval', array_column($allowedProps, 'id'));
        $wpPh   = implode(',', $wpPids);

        // Cek apakah user adalah PIC sales (cari di semua properti yang diakses)
        $wpPic = null;
        if (!empty($wpUser['id'])) {
            $s = $pdo->prepare(
                'SELECT p.name AS pic_name, p.target_share, p.property_id,
                        COALESCE(SUM(a.amount),0) AS actual
                 FROM master_pic p
                 LEFT JOIN transaction_allocations a
                        ON a.pic_name=p.name AND a.period_key=? AND a.property_id=p.property_id
                 WHERE p.user_id=? AND p.status=\'active\'
                 GROUP BY p.id LIMIT 1'
            );
            $s->execute([$wpPeriod, $wpUser['id']]);
            $wpPic = $s->fetch() ?: null;
        }

        // Target: gabungkan semua properti yang diakses
        $s = $pdo->query("SELECT COALESCE(SUM(target_amount),0) FROM targets_monthly WHERE period_key='$wpPeriod' AND property_id IN ($wpPh)");
        $wpTarget = (float)($s->fetchColumn() ?: 0);

        if ($wpPic) {
            // PIC: pakai target properti spesifik milik PIC tersebut
            $sPicTarget = $pdo->prepare('SELECT COALESCE(target_amount,0) FROM targets_monthly WHERE period_key=? AND property_id=?');
            $sPicTarget->execute([$wpPeriod, (int)$wpPic['property_id']]);
            $wpPicPropTarget = (float)($sPicTarget->fetchColumn() ?: 0);
            $wpActual     = (float)$wpPic['actual'];
            $wpMyTarget   = $wpPicPropTarget * (float)$wpPic['target_share']; // target_share = pecahan (0.25 = 25%)
            $wpAchieve    = $wpMyTarget > 0 ? round($wpActual / $wpMyTarget * 100) : 0;
            $wpSubject    = $wpPic['pic_name'];
            $wpHeading    = 'Achievement Pribadi';
            $wpTargetShow = $wpMyTarget;
        } else {
            // Non-PIC: per-property achievement breakdown
            $wpHeading = 'Ringkasan Properti';
            $wpProps   = [];
            foreach ($allowedProps as $_ap) {
                $_apId  = (int)$_ap['id'];
                $_sT    = $pdo->prepare('SELECT COALESCE(target_amount,0) FROM targets_monthly WHERE period_key=? AND property_id=?');
                $_sT->execute([$wpPeriod, $_apId]);
                $_apTarget  = (float)($_sT->fetchColumn() ?: 0);
                $_sA    = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM transaction_allocations WHERE period_key=? AND property_id=?');
                $_sA->execute([$wpPeriod, $_apId]);
                $_apActual  = (float)$_sA->fetchColumn();
                $_apAchieve = $_apTarget > 0 ? round($_apActual / $_apTarget * 100) : 0;
                $wpProps[]  = [
                    'name'    => $_ap['name'],
                    'actual'  => $_apActual,
                    'target'  => $_apTarget,
                    'achieve' => $_apAchieve,
                    'color'   => $_apAchieve >= 100 ? '#10B981' : ($_apAchieve >= 80 ? '#F59E0B' : '#EF4444'),
                ];
            }
            $wpActual     = array_sum(array_column($wpProps, 'actual'));
            $wpAchieve    = count($wpProps) === 1 ? $wpProps[0]['achieve'] : 0;
            $wpSubject    = count($wpProps) === 1 ? ($allowedProps[0]['name'] ?? '') : '';
            $wpTargetShow = $wpTarget;
        }

        $wpColor  = $wpAchieve >= 100 ? '#10B981' : ($wpAchieve >= 80 ? '#F59E0B' : '#EF4444');
        $wpMonth  = period_label($wpPeriod);
    ?>
    <style>
    @keyframes wpFadeIn{from{opacity:0}to{opacity:1}}
    @keyframes wpSlideUp{from{opacity:0;transform:translateY(20px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
    @keyframes wpBar{from{width:100%}to{width:0%}}
    #wp-overlay{position:fixed;inset:0;background:rgba(10,18,30,.5);z-index:9000;display:flex;align-items:center;justify-content:center;animation:wpFadeIn .2s ease;backdrop-filter:blur(2px)}
    #wp-card{background:#fff;border-radius:16px;width:min(380px,92vw);box-shadow:0 28px 70px rgba(0,0,0,.25);animation:wpSlideUp .35s cubic-bezier(.22,.68,0,1.2);overflow:hidden;position:relative}
    #wp-close{position:absolute;top:11px;right:12px;width:26px;height:26px;border:none;background:rgba(255,255,255,.2);border-radius:50%;cursor:pointer;font-size:17px;color:#fff;line-height:1;z-index:1;display:flex;align-items:center;justify-content:center}
    #wp-close:hover{background:rgba(255,255,255,.35)}
    #wp-head{background:linear-gradient(-45deg,#115E59,#0D9488,#0891B2);background-size:200% 200%;animation:bgShift 8s ease infinite;padding:22px 24px 18px;color:#fff}
    #wp-head h2{font-size:17px;font-weight:800;margin:0 0 3px}
    #wp-head p{font-size:12px;opacity:.82;margin:0}
    #wp-body{padding:18px 24px 14px}
    .wp-row{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px}
    .wp-lbl{font-size:11px;color:#7B8A9C;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
    .wp-val{font-size:14px;font-weight:700;color:#0F1623}
    .wp-pct{font-size:32px;font-weight:900;text-align:center;padding:6px 0 14px;letter-spacing:-.5px}
    .wp-pct small{font-size:14px;opacity:.6;font-weight:700}
    .wp-prop-row{display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid #F1F5F9}
    .wp-prop-row:last-child{border-bottom:none;padding-bottom:4px}
    .wp-prop-name{font-size:13px;font-weight:700;color:#0F1623}
    .wp-prop-pct{font-size:24px;font-weight:900;letter-spacing:-.5px}
    #wp-bar-wrap{height:3px;background:#F1F5F9;overflow:hidden}
    #wp-bar{height:100%;background:linear-gradient(90deg,#0D9488,#0891B2);animation:wpBar 3s linear forwards}
    </style>
    <div id="wp-overlay" onclick="if(event.target===this)wpClose()">
        <div id="wp-card">
            <button id="wp-close" onclick="wpClose()">&#x2715;</button>
            <div id="wp-head">
                <h2>Selamat datang, <?= h($wpName) ?>! &nbsp;&#128075;</h2>
                <p><?= h($wpHeading) ?> &middot; <?= h($wpMonth) ?><?= $wpSubject !== '' ? ' &middot; ' . h($wpSubject) : '' ?></p>
            </div>
            <div id="wp-body">
                <?php if (!empty($wpProps) && count($wpProps) > 1): ?>
                    <?php foreach ($wpProps as $_wp): ?>
                    <div class="wp-prop-row">
                        <span class="wp-prop-name"><?= h($_wp['name']) ?></span>
                        <span class="wp-prop-pct" style="color:<?= $_wp['color'] ?>"><?= $_wp['achieve'] ?><small style="font-size:13px;font-weight:700;opacity:.65">%</small></span>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="wp-row">
                    <span class="wp-lbl">Aktual</span>
                    <span class="wp-val">Rp&nbsp;<?= number_format($wpActual, 0, ',', '.') ?></span>
                </div>
                <div class="wp-row">
                    <span class="wp-lbl">Target</span>
                    <span class="wp-val">Rp&nbsp;<?= number_format($wpTargetShow, 0, ',', '.') ?></span>
                </div>
                <div class="wp-pct" style="color:<?= $wpColor ?>"><?= $wpAchieve ?><small>%</small></div>
                <?php endif; ?>
            </div>
            <div id="wp-bar-wrap"><div id="wp-bar"></div></div>
        </div>
    </div>
    <script>
    function wpClose(){var o=document.getElementById('wp-overlay');if(!o)return;o.style.transition='opacity .18s';o.style.opacity='0';setTimeout(function(){o.remove();},200);}
    setTimeout(wpClose, 3000);
    </script>
    <?php endif; ?>
    <script>
    <?php if (!$isMobile): ?>
    function openSidebar(){document.getElementById('sidebar').classList.add('open');document.getElementById('sidebar-overlay').classList.add('active');document.body.style.overflow='hidden';}
    function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebar-overlay').classList.remove('active');document.body.style.overflow='';}
    (function(){var sb=document.getElementById('sidebar');var ss=sessionStorage.getItem('_sb_scroll');if(ss)sb.scrollTop=parseInt(ss,10);document.querySelectorAll('.nav a').forEach(function(a){a.addEventListener('click',function(){sessionStorage.setItem('_sb_scroll',sb.scrollTop);closeSidebar();});});})();
    <?php endif; ?>
    (function(){
        var timeout=<?= SESSION_TIMEOUT ?>,warnBefore=120,warnAt=(timeout-warnBefore)*1000,logoutAt=timeout*1000;
        var overlay=document.createElement('div');
        overlay.id='session-warn';
        overlay.style.cssText='display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center';
        overlay.innerHTML='<div style="background:#fff;border-radius:14px;padding:32px 36px;max-width:360px;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.18)"><div style="font-size:32px;margin-bottom:12px">⏳</div><div style="font-weight:800;font-size:16px;margin-bottom:8px;color:#0F1623">Sesi Hampir Berakhir</div><div style="color:#7B8A9C;font-size:13px;margin-bottom:20px">Anda akan otomatis keluar dalam <b id="secs">120</b> detik.<br>Klik tombol di bawah untuk tetap masuk.</div><button onclick="location.reload()" style="background:#0D9488;color:#fff;border:none;border-radius:8px;padding:10px 28px;font-weight:700;font-size:14px;cursor:pointer">Tetap Masuk</button></div>';
        document.body.appendChild(overlay);
        setTimeout(function(){overlay.style.display='flex';var secs=warnBefore;var tick=setInterval(function(){secs--;var el=document.getElementById('secs');if(el)el.textContent=secs;if(secs<=0)clearInterval(tick);},1000);},warnAt);
        setTimeout(function(){window.location.href='?r=logout';},logoutAt);
    })();
    document.addEventListener('submit',function(e){var form=e.target;if(form.dataset.submitted){e.preventDefault();return;}form.dataset.submitted='1';form.querySelectorAll('button[type=submit]').forEach(function(btn){btn.disabled=true;btn.dataset.orig=btn.textContent;btn.textContent='Menyimpan...';});});
    </script>
    <script src="assets/flatpickr.min.js"></script>
    <script src="assets/spread-table.js"></script>
    <script src="assets/mobile-tables.js?v=<?= CSS_VER ?>"></script>
    <script>
    (function(){
        if(typeof flatpickr==='undefined') return;
        var _fpHolidays = {};

        /* custom tooltip */
        var _tip = document.createElement('div');
        _tip.style.cssText = 'display:none;position:fixed;z-index:9999999;background:#1E293B;color:#fff;font-size:11px;line-height:1.4;padding:5px 9px;border-radius:6px;pointer-events:none;white-space:nowrap;box-shadow:0 4px 12px rgba(0,0,0,.25);max-width:200px;white-space:normal';
        document.body.appendChild(_tip);
        function _tipShow(text, el) {
            _tip.textContent = text;
            _tip.style.display = 'block';
            var r = el.getBoundingClientRect();
            var tx = r.left + r.width / 2 - _tip.offsetWidth / 2;
            var ty = r.top - _tip.offsetHeight - 6;
            if (tx < 4) tx = 4;
            if (ty < 4) ty = r.bottom + 6;
            _tip.style.left = tx + 'px';
            _tip.style.top  = ty + 'px';
        }
        function _tipHide() { _tip.style.display = 'none'; }

        function _fpFetch(year, cb) {
            var yr = String(year);
            if (_fpHolidays[yr] !== undefined) { cb(_fpHolidays[yr]); return; }
            _fpHolidays[yr] = {};
            fetch('https://libur.deno.dev/api?year=' + yr, {cache:'force-cache'})
                .then(function(r){ return r.json(); })
                .then(function(data){
                    data.forEach(function(h){
                        _fpHolidays[yr][h.date] = { n: h.name, c: !h.is_national_holiday };
                    });
                    cb(_fpHolidays[yr]);
                })
                .catch(function(){ cb({}); });
        }

        function _fpMark(fp) {
            _fpFetch(fp.currentYear, function(){ fp.redraw(); });
        }

        var curYear = new Date().getFullYear();
        _fpFetch(curYear, function(){});
        _fpFetch(curYear + 1, function(){});

        var fpCfg = {
            dateFormat: 'Y-m-d',
            locale: { firstDayOfWeek: 1 },
            disableMobile: true,
            onDayCreate: function(_d, _s, _fp, day) {
                var wd  = day.dateObj.getDay();
                var yr  = day.dateObj.getFullYear();
                var mm  = String(day.dateObj.getMonth() + 1).padStart(2, '0');
                var dd  = String(day.dateObj.getDate()).padStart(2, '0');
                var ds  = yr + '-' + mm + '-' + dd;
                var hl  = (_fpHolidays[yr] || {})[ds];
                if (wd === 6) day.classList.add('fp-sat');
                if (wd === 0) day.classList.add('fp-sun');
                if (hl) {
                    day.classList.add(hl.c ? 'fp-cuti' : 'fp-holiday');
                    day.addEventListener('mouseenter', function(){ _tipShow(hl.n, day); });
                    day.addEventListener('mouseleave', _tipHide);
                }
            },
            onOpen:        function(_d, _s, fp){ _fpMark(fp); },
            onClose:       function(){ _tipHide(); },
            onMonthChange: function(_d, _s, fp){ _fpMark(fp); },
            onYearChange:  function(_d, _s, fp){ _fpMark(fp); }
        };

        document.querySelectorAll('input[type=date]').forEach(function(el){
            flatpickr(el, Object.assign({}, fpCfg));
        });
    })();
    </script>
    </body>
    </html>
    <?php
}

function periods(PDO $pdo): array
{
    $pid = current_property_id();
    $allKeys = $pdo->prepare(
        "SELECT DISTINCT pk FROM (
             SELECT period_key pk FROM periods WHERE property_id = ?
             UNION SELECT DISTINCT period_key pk FROM transaction_allocations WHERE property_id = ?
             UNION SELECT DISTINCT period_key pk FROM targets_monthly WHERE property_id = ?
         ) t ORDER BY pk ASC"
    );
    $allKeys->execute([$pid, $pid, $pid]);
    $allKeys = $allKeys->fetchAll(PDO::FETCH_COLUMN);

    if (empty($allKeys)) return [];

    $placeholders = implode(',', array_fill(0, count($allKeys), '?'));
    $stmt = $pdo->prepare("SELECT * FROM periods WHERE property_id = $pid AND period_key IN ($placeholders)");
    $stmt->execute($allKeys);
    $registered = [];
    foreach ($stmt->fetchAll() as $r) {
        $registered[$r['period_key']] = $r;
    }

    return array_map(function (string $pk) use ($registered): array {
        return $registered[$pk] ?? [
            'period_key' => $pk,
            'label'      => period_label($pk),
            'starts_on'  => $pk . '-01',
            'ends_on'    => (new DateTimeImmutable($pk . '-01'))->modify('last day of this month')->format('Y-m-d'),
        ];
    }, $allKeys);
}

function masterOptions(PDO $pdo, string $module): array
{
    $pid = current_property_id();

    if ($module === 'cl') {
        $s = $pdo->prepare("SELECT code, location_name label, rate, 'daily_area' pricing_type, area_sqm, 1 quantity, 1 slots FROM master_cl_units WHERE status='active' AND property_id=? ORDER BY sort_order ASC, CASE floor WHEN 'LG' THEN 1 WHEN 'GF' THEN 2 WHEN 'UG' THEN 3 WHEN 'FF' THEN 4 WHEN 'SF' THEN 5 ELSE 6 END, code");
    } elseif ($module === 'gudang') {
        $s = $pdo->prepare("SELECT code, name label, monthly_rate rate, 'monthly' pricing_type, area_sqm, 1 quantity, 1 slots FROM master_gudang WHERE status='active' AND property_id=? ORDER BY sort_order ASC, code");
    } else {
        $concat = "CONCAT(media_type, ' - ', location, ' - ', COALESCE(point,''))";
        $s = $pdo->prepare("SELECT code, $concat label, rate, pricing_type, 0 area_sqm, quantity, slots, COALESCE(size,'') size, media_type FROM master_media WHERE status='active' AND property_id=? ORDER BY sort_order ASC, code");
    }

    $s->execute([$pid]);
    return $s->fetchAll();
}

function client_options(?PDO $pdo = null): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $defaults = [
        'business_type'  => ['F&B / Kuliner','Fashion & Apparel','Kecantikan & Skincare','Kesehatan & Farmasi','Elektronik & Gadget','Hobi & Lifestyle','Olahraga & Outdoor','Pendidikan & Kursus','Perbankan & Keuangan','Properti & Developer','Otomotif','Travel & Wisata','Hiburan & Event','Aksesori & Perhiasan','Lain-lain'],
        'business_scale' => ['UKM / UMKM', 'Lokal', 'Nasional'],
        'brand_origin'   => ['Lokal', 'Asing / Internasional', 'Franchise'],
        'target_segment' => ['Family', 'Youth', 'Young Professional', 'Premium', 'Mass Market', 'Mixed'],
        'channel'        => ['Offline', 'Online', 'Omnichannel'],
    ];
    if ($pdo) {
        try {
            $pid  = current_property_id();
            $rows = $pdo->prepare("SELECT category, value FROM master_lookup_options WHERE status='active' AND property_id=? ORDER BY sort_order, value");
            $rows->execute([$pid]);
            $rows = $rows->fetchAll();
            $db = [];
            foreach ($rows as $r) $db[$r['category']][] = $r['value'];
            foreach ($defaults as $cat => $_) {
                if (!empty($db[$cat])) $defaults[$cat] = $db[$cat];
            }
        } catch (Throwable $e) {}
    }
    $cache = $defaults;
    return $cache;
}
