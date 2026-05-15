<?php
/**
 * CLARA Unified — Migration Script
 * Merges clara_ewalk (property 1) + clara_pentacity (property 2) into clara_unified
 *
 * Run: php migrate.php
 */

declare(strict_types=1);

$dsn_opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$ewalk   = new PDO('mysql:host=localhost;dbname=clara_ewalk;charset=utf8mb4',   'root', '', $dsn_opts);
$penta   = new PDO('mysql:host=localhost;dbname=clara_pentacity;charset=utf8mb4','root', '', $dsn_opts);
$unified = new PDO('mysql:host=localhost;dbname=clara_unified;charset=utf8mb4', 'root', '', $dsn_opts);

function log_msg(string $msg): void { echo $msg . PHP_EOL; }
function section(string $t): void { log_msg("\n=== $t ==="); }

$unified->exec('SET FOREIGN_KEY_CHECKS=0');

// ─────────────────────────────────────────────────────────────
// USERS (shared, merged by email)
// ─────────────────────────────────────────────────────────────
section('USERS');
$user_map = ['ewalk' => [], 'penta' => []];
$email_to_uid = [];

$ins_user = $unified->prepare(
    'INSERT INTO users (name, email, password_hash, role, status, last_login_at, session_last_active, updated_at, created_at)
     VALUES (?,?,?,?,?,?,NULL,?,?)'
);
$ins_up = $unified->prepare('INSERT IGNORE INTO user_properties (user_id, property_id) VALUES (?,?)');

foreach ($ewalk->query('SELECT * FROM users ORDER BY id') as $u) {
    $ins_user->execute([$u['name'], $u['email'], $u['password_hash'], $u['role'], $u['status'],
        $u['last_login_at'], $u['updated_at'], $u['created_at']]);
    $new = (int)$unified->lastInsertId();
    $user_map['ewalk'][$u['id']] = $new;
    $email_to_uid[$u['email']] = $new;
    $ins_up->execute([$new, 1]);
    log_msg("  ewalk user #{$u['id']} -> #{$new}: {$u['email']}");
}

foreach ($penta->query('SELECT * FROM users ORDER BY id') as $u) {
    if (isset($email_to_uid[$u['email']])) {
        $new = $email_to_uid[$u['email']];
        log_msg("  penta user #{$u['id']} -> existing #{$new}: {$u['email']}");
    } else {
        $ins_user->execute([$u['name'], $u['email'], $u['password_hash'], $u['role'], $u['status'],
            $u['last_login_at'], $u['updated_at'] ?? null, $u['created_at']]);
        $new = (int)$unified->lastInsertId();
        $email_to_uid[$u['email']] = $new;
        log_msg("  penta user #{$u['id']} -> new #{$new}: {$u['email']}");
    }
    $user_map['penta'][$u['id']] = $new;
    $ins_up->execute([$new, 2]);
}

// ─────────────────────────────────────────────────────────────
// ROLE_PERMISSIONS (union of both)
// ─────────────────────────────────────────────────────────────
section('ROLE_PERMISSIONS');
$ins_rp = $unified->prepare('INSERT IGNORE INTO role_permissions (role, permission) VALUES (?,?)');
$count_rp = 0;
foreach ([$ewalk, $penta] as $db) {
    foreach ($db->query('SELECT * FROM role_permissions') as $r) {
        $ins_rp->execute([$r['role'], $r['permission']]);
        $count_rp++;
    }
}
log_msg("  $count_rp permission rows merged (deduped)");

// ─────────────────────────────────────────────────────────────
// MASTER_CLIENTS (shared — import all from both, no dedup)
// ─────────────────────────────────────────────────────────────
section('MASTER_CLIENTS (shared)');
$client_map = ['ewalk' => [], 'penta' => []];
$ins_client = $unified->prepare(
    'INSERT INTO master_clients (company_name, brand_name, npwp, address, business_type, business_scale,
     brand_origin, target_segment, channel, tags, pic_user_id, status, created_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
);

foreach (['ewalk' => $ewalk, 'penta' => $penta] as $src => $db) {
    $umap = $user_map[$src];
    $rows = $db->query('SELECT * FROM master_clients ORDER BY id')->fetchAll();
    foreach ($rows as $c) {
        $pic = $c['pic_user_id'] ? ($umap[$c['pic_user_id']] ?? null) : null;
        $ins_client->execute([$c['company_name'], $c['brand_name'], $c['npwp'], $c['address'],
            $c['business_type'], $c['business_scale'], $c['brand_origin'], $c['target_segment'],
            $c['channel'], $c['tags'], $pic, $c['status'], $c['created_at']]);
        $client_map[$src][$c['id']] = (int)$unified->lastInsertId();
    }
    log_msg("  $src: " . count($rows) . " clients");
}

// ─────────────────────────────────────────────────────────────
// MASTER_CLIENT_CONTACTS (shared)
// ─────────────────────────────────────────────────────────────
section('MASTER_CLIENT_CONTACTS');
$contact_map = ['ewalk' => [], 'penta' => []];
$ins_contact = $unified->prepare(
    'INSERT INTO master_client_contacts (client_id, name, phone, email, is_primary, status, created_at)
     VALUES (?,?,?,?,?,?,?)'
);

foreach (['ewalk' => $ewalk, 'penta' => $penta] as $src => $db) {
    $cmap = $client_map[$src];
    $rows = $db->query('SELECT * FROM master_client_contacts ORDER BY id')->fetchAll();
    foreach ($rows as $c) {
        $new_client = $cmap[$c['client_id']] ?? null;
        if (!$new_client) continue;
        $ins_contact->execute([$new_client, $c['name'], $c['phone'], $c['email'],
            $c['is_primary'], $c['status'], $c['created_at']]);
        $contact_map[$src][$c['id']] = (int)$unified->lastInsertId();
    }
    log_msg("  $src: " . count($rows) . " contacts");
}

// ─────────────────────────────────────────────────────────────
// MASTER_LOOKUP_OPTIONS (per property)
// ─────────────────────────────────────────────────────────────
section('MASTER_LOOKUP_OPTIONS');
$ins_lo = $unified->prepare(
    'INSERT IGNORE INTO master_lookup_options (property_id, category, value, sort_order, status, created_at)
     VALUES (?,?,?,?,?,?)'
);
foreach ([1 => $ewalk, 2 => $penta] as $pid => $db) {
    $rows = $db->query('SELECT * FROM master_lookup_options ORDER BY id')->fetchAll();
    foreach ($rows as $r) {
        $ins_lo->execute([$pid, $r['category'], $r['value'], $r['sort_order'], $r['status'], $r['created_at']]);
    }
    log_msg("  property $pid: " . count($rows) . " lookup options");
}

// ─────────────────────────────────────────────────────────────
// MASTER_PIC (per property)
// ─────────────────────────────────────────────────────────────
section('MASTER_PIC');
$ins_pic = $unified->prepare(
    'INSERT INTO master_pic (property_id, name, role_name, target_share, status, user_id, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?)'
);
foreach ([1 => ['ewalk', $ewalk], 2 => ['penta', $penta]] as $pid => [$src, $db]) {
    $umap = $user_map[$src];
    $rows = $db->query('SELECT * FROM master_pic ORDER BY id')->fetchAll();
    foreach ($rows as $p) {
        $uid = (isset($p['user_id']) && $p['user_id']) ? ($umap[$p['user_id']] ?? null) : null;
        $ins_pic->execute([$pid, $p['name'], $p['role_name'], $p['target_share'],
            $p['status'], $uid, $p['created_at'], $p['updated_at'] ?? null]);
    }
    log_msg("  $src: " . count($rows) . " PIC");
}

// ─────────────────────────────────────────────────────────────
// MASTER_MEDIA (per property)
// ─────────────────────────────────────────────────────────────
section('MASTER_MEDIA');
$ins_media = $unified->prepare(
    'INSERT INTO master_media (property_id, code, media_type, location, point, size, quantity, slots, rate,
     pricing_type, package_note, projection_monthly, status, effective_from, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
);
foreach ([1 => $ewalk, 2 => $penta] as $pid => $db) {
    $rows = $db->query('SELECT * FROM master_media ORDER BY id')->fetchAll();
    foreach ($rows as $r) {
        $ins_media->execute([$pid, $r['code'], $r['media_type'], $r['location'], $r['point'],
            $r['size'], $r['quantity'], $r['slots'], $r['rate'], $r['pricing_type'],
            $r['package_note'], $r['projection_monthly'], $r['status'],
            $r['effective_from'], $r['created_at'], $r['updated_at'] ?? null]);
    }
    log_msg("  property $pid: " . count($rows) . " media");
}

// ─────────────────────────────────────────────────────────────
// MASTER_CL_UNITS (per property)
// ─────────────────────────────────────────────────────────────
section('MASTER_CL_UNITS');
$ins_cl = $unified->prepare(
    'INSERT INTO master_cl_units (property_id, code, floor, location_name, unit_type, area_sqm,
     rate, projection_monthly, status, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
);
foreach ([1 => $ewalk, 2 => $penta] as $pid => $db) {
    $rows = $db->query('SELECT * FROM master_cl_units ORDER BY id')->fetchAll();
    foreach ($rows as $r) {
        $ins_cl->execute([$pid, $r['code'], $r['floor'], $r['location_name'], $r['unit_type'],
            $r['area_sqm'], $r['rate'], $r['projection_monthly'], $r['status'],
            $r['created_at'], $r['updated_at'] ?? null]);
    }
    log_msg("  property $pid: " . count($rows) . " CL units");
}

// ─────────────────────────────────────────────────────────────
// MASTER_GUDANG (per property)
// ─────────────────────────────────────────────────────────────
section('MASTER_GUDANG');
$ins_gd = $unified->prepare(
    'INSERT INTO master_gudang (property_id, code, location, name, area_sqm, monthly_rate,
     projection_monthly, status, created_at, updated_at)
     VALUES (?,?,?,?,?,?,?,?,?,?)'
);
foreach ([1 => $ewalk, 2 => $penta] as $pid => $db) {
    $rows = $db->query('SELECT * FROM master_gudang ORDER BY id')->fetchAll();
    foreach ($rows as $r) {
        $ins_gd->execute([$pid, $r['code'], $r['location'], $r['name'], $r['area_sqm'],
            $r['monthly_rate'], $r['projection_monthly'], $r['status'],
            $r['created_at'], $r['updated_at'] ?? null]);
    }
    log_msg("  property $pid: " . count($rows) . " gudang");
}

// ─────────────────────────────────────────────────────────────
// PERIODS (per property)
// ─────────────────────────────────────────────────────────────
section('PERIODS');
$ins_period = $unified->prepare(
    'INSERT INTO periods (property_id, period_key, label, starts_on, ends_on) VALUES (?,?,?,?,?)'
);
foreach ([1 => $ewalk, 2 => $penta] as $pid => $db) {
    $rows = $db->query('SELECT * FROM periods ORDER BY id')->fetchAll();
    foreach ($rows as $r) {
        $ins_period->execute([$pid, $r['period_key'], $r['label'], $r['starts_on'], $r['ends_on']]);
    }
    log_msg("  property $pid: " . count($rows) . " periods");
}

// ─────────────────────────────────────────────────────────────
// TARGETS_MONTHLY (per property)
// ─────────────────────────────────────────────────────────────
section('TARGETS_MONTHLY');
$ins_target = $unified->prepare(
    'INSERT INTO targets_monthly (property_id, period_key, target_amount, updated_at) VALUES (?,?,?,?)'
);
foreach ([1 => $ewalk, 2 => $penta] as $pid => $db) {
    $rows = $db->query('SELECT * FROM targets_monthly ORDER BY id')->fetchAll();
    foreach ($rows as $r) {
        $ins_target->execute([$pid, $r['period_key'], $r['target_amount'], $r['updated_at'] ?? null]);
    }
    log_msg("  property $pid: " . count($rows) . " targets");
}

// ─────────────────────────────────────────────────────────────
// TRANSACTIONS (per property — ID remapped)
// ─────────────────────────────────────────────────────────────
section('TRANSACTIONS');
$trx_map = ['ewalk' => [], 'penta' => []];
$ins_trx = $unified->prepare(
    'INSERT INTO transactions (property_id, module, client_id, contact_id, master_code, period_key,
     content_note, start_date, end_date, quantity, slots, area_sqm, pricing_type, unit_rate,
     contract_months, billing_method, total_calculated, override_amount, final_amount, pic_name,
     remarks, invoice_no, status, created_at, created_by, updated_at, updated_by, deleted_at, deleted_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
);

foreach ([1 => ['ewalk', $ewalk], 2 => ['penta', $penta]] as $pid => [$src, $db]) {
    $cmap = $client_map[$src];
    $ktmap = $contact_map[$src];
    $rows = $db->query('SELECT * FROM transactions ORDER BY id')->fetchAll();
    foreach ($rows as $r) {
        $new_client  = $r['client_id']  ? ($cmap[$r['client_id']]   ?? null) : null;
        $new_contact = $r['contact_id'] ? ($ktmap[$r['contact_id']] ?? null) : null;
        $ins_trx->execute([
            $pid, $r['module'], $new_client, $new_contact, $r['master_code'], $r['period_key'],
            $r['content_note'], $r['start_date'], $r['end_date'], $r['quantity'], $r['slots'],
            $r['area_sqm'], $r['pricing_type'], $r['unit_rate'], $r['contract_months'],
            $r['billing_method'], $r['total_calculated'], $r['override_amount'], $r['final_amount'],
            $r['pic_name'], $r['remarks'], $r['invoice_no'], $r['status'],
            $r['created_at'], $r['created_by'], $r['updated_at'], $r['updated_by'],
            $r['deleted_at'], $r['deleted_by'],
        ]);
        $trx_map[$src][$r['id']] = (int)$unified->lastInsertId();
    }
    log_msg("  $src: " . count($rows) . " transactions");
}

// ─────────────────────────────────────────────────────────────
// TRANSACTION_ALLOCATIONS (per property — follows transactions)
// ─────────────────────────────────────────────────────────────
section('TRANSACTION_ALLOCATIONS');
$ins_alloc = $unified->prepare(
    'INSERT INTO transaction_allocations (property_id, transaction_id, module, master_code, period_key,
     allocation_start, allocation_end, allocated_days, amount, capacity_days, pic_name)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)'
);

foreach ([1 => ['ewalk', $ewalk], 2 => ['penta', $penta]] as $pid => [$src, $db]) {
    $tmap = $trx_map[$src];
    $rows = $db->query('SELECT * FROM transaction_allocations ORDER BY id')->fetchAll();
    $skipped = 0;
    foreach ($rows as $r) {
        $new_trx = $tmap[$r['transaction_id']] ?? null;
        if (!$new_trx) { $skipped++; continue; }
        $ins_alloc->execute([
            $pid, $new_trx, $r['module'], $r['master_code'], $r['period_key'],
            $r['allocation_start'], $r['allocation_end'], $r['allocated_days'],
            $r['amount'], $r['capacity_days'], $r['pic_name'],
        ]);
    }
    log_msg("  $src: " . count($rows) . " allocations" . ($skipped ? " ($skipped skipped)" : ''));
}

// ─────────────────────────────────────────────────────────────
// AUDIT_LOGS (per property — best-effort, no ID remapping on record_id)
// ─────────────────────────────────────────────────────────────
section('AUDIT_LOGS');
$ins_audit = $unified->prepare(
    'INSERT INTO audit_logs (property_id, user_id, actor, user_name, user_role, action, module,
     table_name, record_id, route, method, ip_address, computer_name, user_agent,
     before_json, after_json, created_at)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
);

foreach ([1 => ['ewalk', $ewalk], 2 => ['penta', $penta]] as $pid => [$src, $db]) {
    $umap = $user_map[$src];
    $rows = $db->query('SELECT * FROM audit_logs ORDER BY id')->fetchAll();
    foreach ($rows as $r) {
        $new_uid = $r['user_id'] ? ($umap[$r['user_id']] ?? null) : null;
        $ins_audit->execute([
            $pid, $new_uid, $r['actor'], $r['user_name'], $r['user_role'], $r['action'],
            $r['module'], $r['table_name'], $r['record_id'], $r['route'], $r['method'],
            $r['ip_address'], $r['computer_name'], $r['user_agent'],
            $r['before_json'], $r['after_json'], $r['created_at'],
        ]);
    }
    log_msg("  $src: " . count($rows) . " audit logs");
}

$unified->exec('SET FOREIGN_KEY_CHECKS=1');

section('VERIFICATION');
$tables_to_check = [
    'users', 'user_properties', 'role_permissions', 'properties',
    'master_clients', 'master_client_contacts', 'master_lookup_options',
    'master_pic', 'master_media', 'master_cl_units', 'master_gudang',
    'periods', 'targets_monthly', 'transactions', 'transaction_allocations', 'audit_logs',
];
foreach ($tables_to_check as $t) {
    $count = $unified->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    log_msg(sprintf("  %-35s %5d rows", $t, $count));
}

log_msg("\n✓ Migration complete.");
