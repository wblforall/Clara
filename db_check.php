<?php
/**
 * CLARA — Schema Drift Checker
 * Bandingkan skema local (expected) vs DB yang sedang berjalan.
 * Run: php db_check.php
 */
declare(strict_types=1);

define('CLARA_ROOT', __DIR__);
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/env.php';

$pdo = Database::connect();

// Skema yang diharapkan ada di production
$expected = [
    'users' => [
        'id', 'name', 'email', 'password_hash', 'role', 'status',
        'must_change_password', 'last_login_at', 'session_last_active',
        'updated_at', 'created_at',
    ],
    'properties' => ['id', 'key', 'name', 'address', 'status'],
    'user_properties' => ['user_id', 'property_id'],
    'role_permissions' => ['role', 'permission'],
    'periods' => ['id', 'property_id', 'period_key', 'label', 'starts_on', 'ends_on'],
    'targets_monthly' => ['id', 'property_id', 'period_key', 'target_amount', 'updated_at'],
    'master_cl_units' => [
        'id', 'property_id', 'code', 'floor', 'location_name', 'unit_type',
        'area_sqm', 'rate', 'projection_monthly', 'status', 'created_at', 'updated_at',
    ],
    'master_media' => [
        'id', 'property_id', 'code', 'media_type', 'location', 'point', 'size',
        'quantity', 'slots', 'rate', 'pricing_type', 'package_note',
        'projection_monthly', 'status', 'effective_from', 'created_at', 'updated_at',
    ],
    'master_gudang' => [
        'id', 'property_id', 'code', 'location', 'name', 'area_sqm',
        'monthly_rate', 'projection_monthly', 'status', 'created_at', 'updated_at',
    ],
    'master_pic' => [
        'id', 'property_id', 'name', 'role_name',
        'commission_cat', 'show_achievement',
        'target_share', 'status', 'user_id', 'created_at', 'updated_at',
    ],
    'master_clients' => [
        'id', 'company_name', 'brand_name', 'npwp', 'address',
        'city', 'province', 'business_type', 'business_scale',
        'brand_origin', 'target_segment', 'channel', 'tags',
        'pic_user_id', 'status', 'created_at',
    ],
    'master_client_contacts' => [
        'id', 'client_id', 'name', 'phone', 'email', 'is_primary', 'status', 'created_at',
    ],
    'master_lookup_options' => [
        'id', 'property_id', 'category', 'value', 'sort_order', 'status', 'created_at',
    ],
    'transactions' => [
        'id', 'property_id', 'module', 'client_id', 'contact_id', 'master_code',
        'period_key', 'content_note', 'start_date', 'end_date', 'quantity', 'slots',
        'area_sqm', 'pricing_type', 'unit_rate', 'contract_months',
        'billing_method', 'cycle_recognition',
        'total_calculated', 'override_amount', 'final_amount',
        'pic_name', 'referrer_name', 'remarks',
        'invoice_no', 'status', 'created_at', 'created_by', 'updated_at', 'updated_by',
        'deleted_at', 'deleted_by',
    ],
    'transaction_allocations' => [
        'id', 'property_id', 'transaction_id', 'module', 'master_code', 'period_key',
        'allocation_start', 'allocation_end', 'allocated_days', 'amount',
        'capacity_days', 'pic_name',
    ],
    'audit_logs' => [
        'id', 'property_id', 'user_id', 'actor', 'user_name', 'user_role',
        'action', 'module', 'table_name', 'record_id', 'route', 'method',
        'ip_address', 'computer_name', 'user_agent', 'before_json', 'after_json', 'created_at',
    ],
    'period_potentials' => [
        'id', 'property_id', 'period_key', 'segment', 'slot_id', 'slot_code',
        'potential_value', 'updated_at',
    ],
    'potential_history' => [
        'id', 'property_id', 'period_key', 'segment', 'slot_id', 'slot_code',
        'old_value', 'new_value', 'changed_by', 'changed_at', 'change_source',
    ],
    'schema_migrations' => ['id', 'migration', 'ran_at'],
    'settings' => ['key', 'value', 'updated_at'],
    'master_referrer' => ['id', 'name', 'dept', 'status', 'created_at'],
];

$existingTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$existingTables = array_flip($existingTables);

$issues = [];

foreach ($expected as $table => $columns) {
    if (!isset($existingTables[$table])) {
        $issues[] = "MISSING TABLE : $table";
        continue;
    }
    $existing = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    $existing = array_flip($existing);
    foreach ($columns as $col) {
        if (!isset($existing[$col])) {
            $issues[] = "MISSING COLUMN: $table.$col";
        }
    }
}

if (empty($issues)) {
    echo "✓ Semua tabel dan kolom yang diharapkan sudah ada.\n";
} else {
    echo count($issues) . " masalah ditemukan:\n\n";
    foreach ($issues as $issue) {
        echo "  $issue\n";
    }
}
