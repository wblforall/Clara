<?php

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money($value): string
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

function pct($value): string
{
    return number_format(((float) $value) * 100, 1, ',', '.') . '%';
}

/**
 * Kondisi SQL "transaksi dihitung recurring" — dipakai SERAGAM di semua angka
 * recurring (dashboard, exec, occupancy, laporan, mobile). Recurring bila:
 *   1) billing_method = 'spread' (kontrak spread), ATAU
 *   2) ditandai manual oleh sales (recurring_flag = 1), ATAU
 *   3) anchor_cycle yang TERDETEKSI berulang (unit+klien sama pada bulan
 *      bersebelahan) — nominal & pricing_type per bulan boleh beda (diskon /
 *      metode hitung beda), karena tetap satu sewa berulang yang sama.
 * Murni pengukuran: TIDAK mengubah billing_method / nominal / meng-convert data.
 *
 * @param string $t alias tabel transactions di query pemanggil (default 't')
 */
function recurring_match_sql(string $t = 't'): string
{
    return "($t.billing_method = 'spread' OR $t.recurring_flag = 1 OR ($t.billing_method = 'anchor_cycle' AND EXISTS (
        SELECT 1 FROM transactions rt2
        WHERE rt2.deleted_at IS NULL
          AND rt2.billing_method = 'anchor_cycle'
          AND rt2.master_code  = $t.master_code
          AND rt2.client_id    = $t.client_id
          AND rt2.property_id  = $t.property_id
          AND rt2.id <> $t.id
          AND ABS(PERIOD_DIFF(REPLACE(rt2.period_key,'-',''), REPLACE($t.period_key,'-',''))) = 1
    )))";
}

function redirect_to(string $route, array $params = []): never
{
    $params = array_merge(['r' => $route], $params);
    header('Location: ?' . http_build_query($params));
    exit;
}

function post(string $key, $default = null)
{
    return $_POST[$key] ?? $default;
}

function getv(string $key, $default = null)
{
    return $_GET[$key] ?? $default;
}

/** Baca setting key/value (tabel settings). Default bila tidak ada. */
function get_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = $pdo->prepare('SELECT value FROM settings WHERE `key` = ? LIMIT 1');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $cache[$key] = ($v === false ? $default : (string) $v);
    } catch (Throwable $e) {
        return $default;
    }
}

// ─── Mobile view ─────────────────────────────────────────────────────────────

/** Deteksi perangkat HP dari User-Agent (tablet tetap dianggap desktop). */
function is_mobile_device(): bool
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/iPad|Tablet|PlayBook|Nexus 7|Nexus 10/i', $ua)) {
        return false;
    }
    return (bool) preg_match('/Android|iPhone|iPod|Mobile|Opera Mini|IEMobile|BlackBerry|webOS/i', $ua);
}

/**
 * Apakah tampilan mobile aktif. Override eksplisit (cookie clara_view) menang;
 * jika tidak ada, auto-deteksi dari User-Agent. User bisa paksa lewat
 * ?view=mobile / ?view=desktop (ditangani di index.php).
 */
function mobile_view_active(): bool
{
    $pref = $_COOKIE['clara_view'] ?? '';
    if ($pref === 'mobile')  return true;
    if ($pref === 'desktop') return false;
    return is_mobile_device();
}

// ─── Property helpers ────────────────────────────────────────────────────────

function current_property_id(): int
{
    return (int)($_SESSION['current_property_id'] ?? 1);
}

function allowed_properties(): array
{
    return $_SESSION['allowed_properties'] ?? [];
}

function allowed_property_ids(): array
{
    return array_column(allowed_properties(), 'id');
}

function current_property(): array
{
    $pid = current_property_id();
    foreach (allowed_properties() as $p) {
        if ((int)$p['id'] === $pid) return $p;
    }
    return ['id' => $pid, 'key' => 'unknown', 'name' => 'Property'];
}

function is_multi_property(): bool
{
    return count(allowed_properties()) > 1;
}

function prop_filter(): string
{
    return 'AND property_id = ' . current_property_id();
}

// ─── Auth ────────────────────────────────────────────────────────────────────

function require_login(): void
{
    if (empty($_SESSION['user'])) {
        redirect_to('login');
    }
    // If logged in but no property selected yet (multi-property user)
    if (empty($_SESSION['current_property_id']) && !empty($_SESSION['allowed_properties'])) {
        $allowed = $_SESSION['allowed_properties'];
        if (count($allowed) === 1) {
            $_SESSION['current_property_id'] = (int)$allowed[0]['id'];
        } else {
            $route = getv('r', '');
            if ($route !== 'select_property' && $route !== 'set_property') {
                redirect_to('select_property');
            }
        }
    }
}

function roles(): array
{
    return [
        'superadmin'   => 'Super Admin',
        'supervisor'   => 'Supervisor',
        'sales'        => 'Sales',
        'finance'      => 'Finance',
        'administrasi' => 'Administrasi',
        'viewer'       => 'Viewer',
    ];
}

function current_role(): string
{
    return $_SESSION['user']['role'] ?? 'guest';
}

function permission_matrix(?array $set = null): array
{
    static $matrix = [];
    if ($set !== null) {
        $matrix = $set;
    }
    return $matrix;
}

function can(string $permission): bool
{
    $role = current_role();
    if ($role === 'superadmin' || $role === 'admin') {
        return true;
    }
    return in_array($permission, permission_matrix()[$role] ?? [], true);
}

function require_permission(string $permission): void
{
    if (!can($permission)) {
        http_response_code(403);
        exit('Akses ditolak untuk role Anda.');
    }
}

/**
 * Cetak tag <head> PWA (manifest, theme-color, ikon Apple) + registrasi service
 * worker. Dipanggil di tiap halaman yang punya <head> sendiri (layout utama &
 * halaman auth) agar aplikasi bisa di-install ke home screen HP.
 */
function pwa_head(): void
{
    ?>
        <meta name="theme-color" content="#0D9488">
        <link rel="manifest" href="manifest.webmanifest">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="CLARA">
        <link rel="apple-touch-icon" href="assets/icon-192.png">
        <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('service-worker.js').catch(function () {});
            });
        }
        </script>
    <?php
}

function permission_for_route(string $route): string
{
    return match ($route) {
        'dashboard' => 'view_dashboard',
        'transactions', 'transaction_form', 'transaction_edit', 'allocation_detail' => 'view_transactions',
        'transaction_save', 'transaction_update' => 'manage_transactions',
        'transaction_delete', 'deleted_transactions' => 'manage_deleted',
        'transaction_history' => 'view_transactions',
        'master' => 'view_master',
        'master_form', 'master_save', 'generate_periods' => 'manage_master',
        'import_media', 'import_template' => 'import_master',
        'exec_dashboard', 'print_exec_summary' => 'view_exec_summary',
        'export_summary', 'print_dashboard', 'print_exec', 'print_trend' => 'export_reports',
        'export_transactions_xlsx', 'export_pic_report_xlsx', 'export_client_analysis_xlsx' => 'export_reports',
        'audit' => 'view_logs',
        'users', 'user_form', 'user_save', 'roles', 'roles_save' => 'manage_users',
        'clients', 'client_form', 'client_save' => 'manage_master',
        'client_analysis', 'client_profile' => 'view_master',
        'pic_report', 'pic_report_print' => 'view_pic_report',
        'pic_reward', 'pic_reward_save'  => 'view_pic_report',
        'renewals' => 'view_renewals',
        'skp', 'skp_form', 'skp_save', 'skp_print' => 'manage_skp',
        'skp_approve', 'skp_reject' => 'approve_skp',
        'offers', 'offer_form', 'offer_save', 'offer_status', 'offer_print' => 'manage_offers',
        'm_home' => 'view_dashboard',
        'm_transactions' => 'view_transactions',
        'm_exec' => 'view_exec_summary',
        'lookup_manage', 'lookup_save', 'lookup_delete' => 'manage_master',
        'trend', 'comparison' => 'view_dashboard',
        'switch_property', 'select_property', 'set_property' => 'view_dashboard',
        default => 'view_dashboard',
    };
}

function module_from_request(string $route): string
{
    if (in_array($route, ['master', 'master_form', 'master_save'], true)) {
        return 'master_' . (getv('type', post('type', 'general')));
    }
    if (in_array($route, ['transactions', 'transaction_form', 'transaction_save', 'transaction_edit', 'transaction_update'], true)) {
        return 'transaction_' . (getv('module', post('module', 'general')));
    }
    return match ($route) {
        'dashboard'                  => 'dashboard',
        'allocation_detail'          => 'allocation',
        'import_media', 'import_template' => 'master_media',
        'export_summary'             => 'reporting',
        'audit'                      => 'audit',
        'users', 'user_form', 'user_save' => 'users',
        'roles', 'roles_save'        => 'roles',
        'deleted_transactions', 'transaction_delete' => 'deleted_transactions',
        'clients', 'client_form', 'client_save' => 'clients',
        'login', 'logout'            => 'auth',
        default                      => $route,
    };
}

function flash(?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return null;
    }
    $current = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $current;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    if (($_POST['_csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) {
        unset($_SESSION['csrf']);
        flash('Sesi form sudah kedaluwarsa. Silakan coba lagi.');
        redirect_to('login');
    }
}

function field(array $row, string $key, $default = ''): string
{
    return h((string) ($row[$key] ?? $default));
}

function period_label(string $periodKey): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $periodKey . '-01');
    $months = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
        '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
    ];
    if (!$dt) return $periodKey;
    return ($months[$dt->format('m')] ?? $dt->format('m')) . ' ' . $dt->format('Y');
}

function audit(PDO $pdo, string $action, string $table, ?string $id, array $after = [], array $before = [], ?string $module = null): void
{
    $ip   = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs
         (property_id, user_id, actor, user_name, user_role, action, module, table_name, record_id,
          route, method, ip_address, computer_name, user_agent, before_json, after_json)
         VALUES
         (:property_id, :user_id, :actor, :user_name, :user_role, :action, :module, :table_name, :record_id,
          :route, :method, :ip_address, :computer_name, :user_agent, :before_json, :after_json)'
    );
    $user = $_SESSION['user'] ?? [];
    $stmt->execute([
        ':property_id'   => current_property_id(),
        ':user_id'       => $user['id'] ?? null,
        ':actor'         => $user['email'] ?? 'system',
        ':user_name'     => $user['name'] ?? 'System',
        ':user_role'     => $user['role'] ?? 'system',
        ':action'        => $action,
        ':module'        => $module ?? module_from_request(getv('r', 'system')),
        ':table_name'    => $table,
        ':record_id'     => $id,
        ':route'         => getv('r', 'system'),
        ':method'        => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        ':ip_address'    => $ip,
        ':computer_name' => null,
        ':user_agent'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ':before_json'   => $before ? json_encode($before) : null,
        ':after_json'    => $after  ? json_encode($after)  : null,
    ]);

    if (random_int(1, 100) === 1) {
        $pdo->prepare('DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)')->execute();
    }
}

function log_activity(PDO $pdo, string $action, ?string $module = null, ?string $recordId = null, array $context = []): void
{
    audit($pdo, $action, $module ?? module_from_request(getv('r', 'system')), $recordId, $context, [], $module);
}

/**
 * Ambil total potensi per segmen untuk periode tertentu.
 * Prioritas: snapshot di period_potentials → fallback ke projection_monthly master.
 */
function get_projection(PDO $pdo, string $period, int $pid): array
{
    $map = [
        'cl'     => ['master_cl_units', 'exhibition'],
        'media'  => ['master_media',    'media'],
        'gudang' => ['master_gudang',   'gudang'],
    ];
    $result = [];
    foreach ($map as $key => [$table, $segment]) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(COALESCE(pp.potential_value, m.projection_monthly)), 0)
            FROM $table m
            LEFT JOIN period_potentials pp ON pp.slot_id = m.id AND pp.segment = ?
                AND pp.period_key = ? AND pp.property_id = ?
            WHERE m.status = 'active' AND m.property_id = ?
        ");
        $stmt->execute([$segment, $period, $pid, $pid]);
        $result[$key] = (float) $stmt->fetchColumn();
    }
    return $result;
}

function snapshot_potential(PDO $pdo, string $segment, int $slotId, string $slotCode, float $newValue, int $pid, ?float $priorMasterValue = null): void
{
    $periodKey = date('Y-m');
    $userId    = (int) ($_SESSION['user']['id'] ?? 0);

    $oldRow = $pdo->prepare(
        'SELECT potential_value FROM period_potentials
         WHERE property_id = ? AND period_key = ? AND segment = ? AND slot_id = ?'
    );
    $oldRow->execute([$pid, $periodKey, $segment, $slotId]);
    $oldValue = (float) ($oldRow->fetchColumn() ?: 0);

    // Freeze past months that have no snapshot yet so they are not affected by future master changes.
    // Only do this when we know what the slot's value was before this edit ($priorMasterValue).
    if ($priorMasterValue !== null) {
        $chk = $pdo->prepare(
            'SELECT COUNT(*) FROM period_potentials
             WHERE property_id = ? AND period_key = ? AND segment = ? AND slot_id = ?'
        );
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO period_potentials
             (property_id, period_key, segment, slot_id, slot_code, potential_value)
             VALUES (?,?,?,?,?,?)'
        );
        for ($i = 1; $i <= 12; $i++) {
            $pastPeriod = date('Y-m', strtotime("-$i month"));
            $chk->execute([$pid, $pastPeriod, $segment, $slotId]);
            if ((int)$chk->fetchColumn() === 0) {
                $ins->execute([$pid, $pastPeriod, $segment, $slotId, $slotCode, $priorMasterValue]);
            }
        }
    }

    if (abs($newValue - $oldValue) < 0.01) return;

    $pdo->prepare(
        'INSERT INTO potential_history
         (property_id, period_key, segment, slot_id, slot_code, old_value, new_value, changed_by, change_source)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([$pid, $periodKey, $segment, $slotId, $slotCode, $oldValue, $newValue, $userId, 'master_' . $segment]);

    $pdo->prepare(
        'INSERT INTO period_potentials
         (property_id, period_key, segment, slot_id, slot_code, potential_value)
         VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE slot_code = VALUES(slot_code), potential_value = VALUES(potential_value)'
    )->execute([$pid, $periodKey, $segment, $slotId, $slotCode, $newValue]);
}

function validate_password(string $pw): ?string
{
    if (strlen($pw) < 8)                          return 'Password minimal 8 karakter.';
    if (!preg_match('/[A-Z]/', $pw))              return 'Password harus mengandung minimal 1 huruf besar.';
    if (!preg_match('/[a-z]/', $pw))              return 'Password harus mengandung minimal 1 huruf kecil.';
    if (!preg_match('/[0-9]/', $pw))              return 'Password harus mengandung minimal 1 angka.';
    if (!preg_match('/[^A-Za-z0-9]/', $pw))       return 'Password harus mengandung minimal 1 karakter spesial (!@#$%^&* dll).';
    if ($pw === '123456')                          return 'Gunakan password selain password default.';
    return null;
}
