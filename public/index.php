<?php
declare(strict_types=1);

define('APP_PUBLIC', __DIR__);
define('APP_ROOT',   dirname(__DIR__));
define('CSS_VER',    filemtime(__DIR__ . '/assets/app.css'));

require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/Database.php';
require_once __DIR__ . '/../app/AllocationService.php';
require_once __DIR__ . '/../app/DashboardService.php';
require_once __DIR__ . '/../app/pages/bootstrap.php';

$config = require __DIR__ . '/../app/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Makassar');
if ($config['app_debug']) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}
ini_set('serialize_precision', '-1');

$route          = getv('r', 'dashboard');
$isDisplayRoute = in_array($route, ['display', 'display_data'], true);

if (!$isDisplayRoute && session_status() !== PHP_SESSION_ACTIVE) {
    $sessionPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'clara-sessions';
    if ((is_dir($sessionPath) || @mkdir($sessionPath, 0775, true)) && is_writable($sessionPath)) {
        ini_set('session.save_path', $sessionPath);
    }
    session_name('CLARA');
    session_start();
}

define('SESSION_TIMEOUT', 1800);
if (!$isDisplayRoute && isset($_SESSION['user'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        session_start();
        flash('Sesi Anda telah berakhir. Silakan masuk kembali.');
        header('Location: ?r=login');
        exit;
    }
    if (!isset($_SESSION['last_activity']) || (time() - $_SESSION['last_activity']) > 60) {
        $_SESSION['last_activity'] = time();
    }
}

try {
    $pdo = Database::connect();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Koneksi database gagal</h1>';
    echo '<p>Cek file .env dan pastikan database sudah dibuat.</p>';
    if ($config['app_debug']) {
        echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    }
    exit;
}

// ─── Refresh nama properti dari DB (supaya perubahan nama langsung efektif tanpa re-login) ──
if (!$isDisplayRoute && !empty($_SESSION['allowed_properties'])
    && (time() - ($_SESSION['_prop_refresh_at'] ?? 0)) > 300) {
    try {
        $ids   = array_map('intval', array_column($_SESSION['allowed_properties'], 'id'));
        $ph    = implode(',', $ids);
        $fresh = $pdo->query("SELECT id, name FROM properties WHERE id IN ($ph)")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($_SESSION['allowed_properties'] as &$_ap) {
            if (isset($fresh[(int)$_ap['id']])) $_ap['name'] = $fresh[(int)$_ap['id']];
        }
        $_SESSION['_prop_refresh_at'] = time();
        unset($_ap, $fresh, $ids, $ph);
    } catch (Throwable $_e) {}
}

// ─── masterConfig for master.php ─────────────────────────────────────────────
$masterConfig = [
    'media' => [
        'title'     => 'Master Media',
        'table'     => 'master_media',
        'key'       => 'code',
        'sortable'  => true,
        'columns'   => ['code', 'media_type', 'location', 'point', 'size', 'quantity', 'slots', 'rate', 'pricing_type', 'projection_monthly', 'status'],
        'fields'    => [
            'code' => 'Kode', 'media_type' => 'Jenis Media', 'location' => 'Lokasi', 'point' => 'Titik',
            'size' => 'Ukuran', 'quantity' => 'Qty', 'slots' => 'Slot', 'rate' => 'Rate',
            'pricing_type' => 'Pricing Type', 'package_note' => 'Paket/Keterangan',
            'projection_monthly' => 'Potensi Bulanan', 'status' => 'Status',
        ],
        'order'     => 'sort_order ASC, code ASC',
    ],
    'cl' => [
        'title'     => 'Master Exhibition',
        'table'     => 'master_cl_units',
        'key'       => 'code',
        'sortable'  => true,
        'columns'   => ['code', 'floor', 'location_name', 'unit_type', 'area_sqm', 'rate', 'projection_monthly', 'status'],
        'fields'    => [
            'code' => 'Kode', 'floor' => 'Lantai', 'location_name' => 'Nama Lokasi', 'unit_type' => 'Tipe Unit',
            'area_sqm' => 'Luas m2', 'rate' => 'Rate Harian/m2', 'projection_monthly' => 'Potensi Bulanan',
            'status' => 'Status',
        ],
        'order'     => "sort_order ASC, CASE floor WHEN 'LG' THEN 1 WHEN 'GF' THEN 2 WHEN 'UG' THEN 3 WHEN 'FF' THEN 4 WHEN 'SF' THEN 5 ELSE 6 END, code",
    ],
    'gudang' => [
        'title'     => 'Master Gudang',
        'table'     => 'master_gudang',
        'key'       => 'code',
        'sortable'  => true,
        'columns'   => ['code', 'location', 'name', 'area_sqm', 'monthly_rate', 'projection_monthly', 'status'],
        'fields'    => [
            'code' => 'Kode', 'location' => 'Lokasi', 'name' => 'Nama Gudang/Tenant', 'area_sqm' => 'Luas m2',
            'monthly_rate' => 'Rate Bulanan', 'projection_monthly' => 'Potensi Bulanan', 'status' => 'Status',
        ],
        'order'     => 'sort_order ASC, code ASC',
    ],
    'pic' => [
        'title'         => 'Master PIC',
        'table'         => 'master_pic',
        'key'           => 'name',
        'columns'       => ['name', 'role_name', 'target_share', 'user_id', 'status'],
        'column_labels' => ['user_id' => 'User Akun'],
        'fields'        => [
            'name' => 'Nama PIC', 'role_name' => 'Jabatan', 'target_share' => 'Porsi Target',
            'user_id' => 'User Akun', 'status' => 'Status',
        ],
    ],
    'target' => [
        'title'   => 'Target Bulanan',
        'table'   => 'targets_monthly',
        'key'     => 'period_key',
        'columns' => ['period_key', 'target_amount'],
        'fields'  => [
            'period_key' => 'Periode YYYY-MM', 'target_amount' => 'Target Amount',
        ],
    ],
];

// ─── Routes that bypass login ─────────────────────────────────────────────────
if ($route === 'login') {
    require_once APP_ROOT . '/app/pages/auth.php';
    login_page($pdo);
    exit;
}

if (in_array($route, ['display', 'display_data'], true)) {
    require_once APP_ROOT . '/app/pages/dashboard.php';
    if (!display_authorized($config)) {
        http_response_code(403);
        exit('Display token tidak valid.');
    }
    if ($route === 'display_data') {
        display_json($pdo);
    }
    display_page($pdo, $config);
    exit;
}

if ($route === 'logout') {
    if (!$isDisplayRoute) {
        audit($pdo, 'logout', 'users', isset($_SESSION['user']['id']) ? (string)$_SESSION['user']['id'] : null, [], [], 'auth');
    }
    session_destroy();
    redirect_to('login');
}

// ─── Property selector ────────────────────────────────────────────────────────
if ($route === 'select_property') {
    require_once APP_ROOT . '/app/pages/auth.php';
    if (empty($_SESSION['user'])) redirect_to('login');
    select_property_page();
    exit;
}

if ($route === 'change_password') {
    require_once APP_ROOT . '/app/pages/auth.php';
    if (empty($_SESSION['user'])) redirect_to('login');
    change_password_page($pdo);
    exit;
}

if ($route === 'switch_property') {
    if (empty($_SESSION['user'])) redirect_to('login');
    $to  = (int)getv('to', 0);
    $ids = array_map('intval', array_column(allowed_properties(), 'id'));
    if ($to > 0 && in_array($to, $ids, true)) {
        $_SESSION['current_property_id'] = $to;
    }
    $back = getv('back', '?r=dashboard');
    header('Location: ' . $back);
    exit;
}

// ─── Authenticated area ───────────────────────────────────────────────────────
require_login();

if (!empty($_SESSION['_must_change_pw'])) {
    redirect_to('change_password');
}

// Permission matrix — cached 5 min in session
if (!isset($_SESSION['_perm_matrix']) || (time() - ($_SESSION['_perm_cache_at'] ?? 0)) > 300) {
    try {
        $_permRows   = $pdo->query('SELECT role, permission FROM role_permissions')->fetchAll();
        $_permMatrix = [];
        foreach ($_permRows as $_r) {
            $_permMatrix[$_r['role']][] = $_r['permission'];
        }
        $_SESSION['_perm_matrix']   = $_permMatrix;
        $_SESSION['_perm_cache_at'] = time();
        unset($_permRows, $_permMatrix, $_r);
    } catch (Throwable $e) {}
}
if (!empty($_SESSION['_perm_matrix'])) {
    permission_matrix($_SESSION['_perm_matrix']);
}

$permission = permission_for_route($route);
if (!can($permission)) {
    audit($pdo, 'access_denied', module_from_request($route), getv('id'), ['route' => $route, 'permission' => $permission], [], module_from_request($route));
    require_permission($permission);
}


$pageFiles = [
    'dashboard'                   => 'dashboard.php',
    'exec_dashboard'              => 'exec_dashboard.php',
    'master'                      => 'master.php',
    'master_form'                 => 'master.php',
    'master_save'                 => 'master.php',
    'generate_periods'            => 'master.php',
    'transactions'                => 'transactions.php',
    'transaction_form'            => 'transactions.php',
    'transaction_save'            => 'transactions.php',
    'transaction_edit'            => 'transactions.php',
    'transaction_update'          => 'transactions.php',
    'transaction_delete'          => 'transactions.php',
    'deleted_transactions'        => 'transactions.php',
    'allocation_detail'           => 'transactions.php',
    'transaction_history'         => 'transactions.php',
    'transaction_overlap_check'   => 'transactions.php',
    'transaction_overlaps'        => 'transactions.php',
    'import_media'                => 'import.php',
    'import_template'             => 'import.php',
    'export_summary'              => 'print_export.php',
    'print_dashboard'             => 'print_export.php',
    'print_exec'                  => 'print_export.php',
    'print_exec_summary'          => 'print_export.php',
    'print_trend'                 => 'print_export.php',
    'export_transactions_xlsx'    => 'print_export.php',
    'export_pic_report_xlsx'      => 'print_export.php',
    'export_client_analysis_xlsx' => 'print_export.php',
    'pic_report'                  => 'laporan.php',
    'pic_report_print'            => 'laporan.php',
    'pic_reward'                  => 'laporan_reward.php',
    'pic_reward_save'             => 'laporan_reward.php',
    'commission_sim'              => 'commission_sim.php',
    'master_sort_save'            => 'master.php',
    'clients'                     => 'clients.php',
    'client_form'                 => 'clients.php',
    'client_save'                 => 'clients.php',
    'client_analysis'             => 'clients.php',
    'users'                       => 'users.php',
    'user_form'                   => 'users.php',
    'user_save'                   => 'users.php',
    'user_created'                => 'users.php',
    'roles'                       => 'users.php',
    'roles_save'                  => 'users.php',
    'audit'                       => 'users.php',
    'lookup_manage'               => 'lookup.php',
    'lookup_save'                 => 'lookup.php',
    'lookup_delete'               => 'lookup.php',
    'trend'                       => 'analytics.php',
    'comparison'                  => 'analytics.php',
    'daily_occupancy'             => 'daily_occupancy.php',
    'recurring_candidates'        => 'recurring_candidates.php',
    'recurring_merge_execute'     => 'recurring_candidates.php',
];
require_once APP_ROOT . '/app/pages/' . ($pageFiles[$route] ?? 'dashboard.php');
match ($route) {
    'dashboard'                   => dashboard($pdo),
    'exec_dashboard'              => exec_dashboard($pdo),
    'master'                      => master_page($pdo, $masterConfig),
    'master_form'                 => master_form($pdo, $masterConfig),
    'master_save'                 => master_save($pdo, $masterConfig),
    'generate_periods'            => generate_periods($pdo),
    'clients'                     => clients_page($pdo),
    'client_form'                 => client_form($pdo),
    'client_save'                 => client_save($pdo),
    'client_analysis'             => client_analysis_page($pdo),
    'export_client_analysis_xlsx' => export_client_analysis_xlsx($pdo),
    'pic_report'                  => pic_report_page($pdo),
    'pic_report_print'            => pic_report_print($pdo),
    'export_pic_report_xlsx'      => export_pic_report_xlsx($pdo),
    'pic_reward'                  => pic_reward_page($pdo),
    'pic_reward_save'             => pic_reward_save($pdo),
    'commission_sim'              => commission_sim($pdo),
    'master_sort_save'            => master_sort_save($pdo, $masterConfig),
    'lookup_manage'               => lookup_manage_page($pdo),
    'lookup_save'                 => lookup_save($pdo),
    'lookup_delete'               => lookup_delete($pdo),
    'transactions'                => transactions_page($pdo),
    'export_transactions_xlsx'    => export_transactions_xlsx($pdo),
    'transaction_form'            => transaction_form($pdo),
    'transaction_save'            => transaction_save($pdo),
    'transaction_edit'            => transaction_edit($pdo),
    'transaction_update'          => transaction_update($pdo),
    'transaction_delete'          => transaction_delete($pdo),
    'deleted_transactions'        => deleted_transactions_page($pdo),
    'allocation_detail'           => allocation_detail($pdo),
    'transaction_history'         => transaction_history_page($pdo),
    'transaction_overlap_check'   => transaction_overlap_check($pdo),
    'transaction_overlaps'        => transaction_overlaps_page($pdo),
    'import_media'                => import_media($pdo),
    'import_template'             => import_template($pdo),
    'export_summary'              => export_summary($pdo),
    'print_dashboard'             => print_dashboard($pdo),
    'print_exec'                  => print_exec($pdo),
    'print_exec_summary'          => print_exec_summary($pdo),
    'print_trend'                 => print_trend($pdo),
    'users'                       => users_page($pdo),
    'user_form'                   => user_form($pdo),
    'user_save'                   => user_save($pdo),
    'user_created'                => user_created_page($pdo),
    'roles'                       => roles_page($pdo),
    'roles_save'                  => roles_save($pdo),
    'audit'                       => audit_page($pdo),
    'trend'                       => trend_page($pdo),
    'comparison'                  => comparison_page($pdo),
    'daily_occupancy'             => daily_occupancy_page($pdo),
    'recurring_candidates'        => recurring_candidates_page($pdo),
    'recurring_merge_execute'     => recurring_merge_execute($pdo),
    default                       => dashboard($pdo),
};
