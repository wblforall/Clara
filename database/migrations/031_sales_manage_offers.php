<?php
// Role 'sales' butuh izin manage_offers untuk pipeline offer-first (buat/lihat
// Surat Penawaran). Visibilitas dibatasi per-sales di kode (current_sales_scope).
// Lihat [[project-offer-pipeline]].

$has = $pdo->query("SHOW TABLES LIKE 'role_permissions'")->fetch();
if ($has) {
    $pdo->prepare("INSERT IGNORE INTO role_permissions (role, permission) VALUES ('sales','manage_offers')")->execute();
}
