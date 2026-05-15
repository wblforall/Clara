<?php
declare(strict_types=1);

function users_page(PDO $pdo): void
{
    $rows = $pdo->query('SELECT id, name, email, role, status, last_login_at, created_at FROM users ORDER BY id')->fetchAll();
    // Load property assignments per user
    $upRows = $pdo->query('SELECT up.user_id, p.name FROM user_properties up JOIN properties p ON p.id = up.property_id ORDER BY up.user_id, p.name')->fetchAll();
    $userProps = [];
    foreach ($upRows as $up) {
        $userProps[$up['user_id']][] = $up['name'];
    }
    layout('Users & Role', function () use ($rows, $userProps) {
        ?>
        <div class="toolbar"><a class="btn" href="?r=user_form">Tambah User</a></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Nama</th><th>Email</th><th>Role</th><th>Status</th><th>Properties</th><th>Last Login</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= h((string) $row['id']) ?></td>
                        <td><?= h($row['name']) ?></td>
                        <td><?= h($row['email']) ?></td>
                        <td><span class="badge"><?= h(roles()[$row['role']] ?? $row['role']) ?></span></td>
                        <td><?= h($row['status']) ?></td>
                        <td style="font-size:12px;color:var(--muted)"><?= !empty($userProps[$row['id']]) ? h(implode(', ', $userProps[$row['id']])) : '<em>—</em>' ?></td>
                        <td><?= h($row['last_login_at'] ?? '-') ?></td>
                        <td><a class="btn light" href="?r=user_form&id=<?= h((string) $row['id']) ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    });
}

function user_form(PDO $pdo): void
{
    $id = getv('id');
    $row = ['role' => 'sales', 'status' => 'active'];
    if ($id) {
        $stmt = $pdo->prepare('SELECT id, name, email, role, status FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch() ?: $row;
    }
    // Load all properties for assignment checkboxes
    $allProperties = $pdo->query('SELECT id, name FROM properties WHERE status=\'active\' ORDER BY name')->fetchAll();
    // Load currently assigned property IDs for this user
    $assignedPropIds = [];
    if ($id) {
        $apStmt = $pdo->prepare('SELECT property_id FROM user_properties WHERE user_id = ?');
        $apStmt->execute([$id]);
        $assignedPropIds = $apStmt->fetchAll(PDO::FETCH_COLUMN);
    }
    layout(($id ? 'Edit' : 'Tambah') . ' User', function () use ($row, $id, $allProperties, $assignedPropIds) {
        ?>
        <form class="panel" method="post" action="?r=user_save">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= h((string) $id) ?>">
            <div class="form-grid">
                <div><label>Nama</label><input name="name" required value="<?= field($row, 'name') ?>"></div>
                <div><label>Email</label><input type="email" name="email" required value="<?= field($row, 'email') ?>"></div>
                <div>
                    <label>Role</label>
                    <select name="role">
                        <?php foreach (roles() as $key => $label): ?>
                            <option value="<?= h($key) ?>" <?= ($row['role'] ?? '') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Status</label>
                    <select name="status">
                        <?php foreach (['active' => 'Active', 'inactive' => 'Inactive'] as $key => $label): ?>
                            <option value="<?= h($key) ?>" <?= ($row['status'] ?? 'active') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="wide">
                    <label>Password <?= $id ? '(kosongkan jika tidak diganti)' : '' ?></label>
                    <input type="password" name="password" <?= $id ? '' : 'required' ?> minlength="6">
                </div>
                <?php if (!empty($allProperties)): ?>
                <div class="wide">
                    <label>Akses Property</label>
                    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:6px">
                        <?php foreach ($allProperties as $prop): ?>
                        <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer;background:var(--bg);border:1px solid var(--border,#e2e8f0);border-radius:6px;padding:6px 12px;font-size:13px">
                            <input type="checkbox" name="property_ids[]" value="<?= h((string) $prop['id']) ?>"
                                <?= in_array((string) $prop['id'], array_map('strval', $assignedPropIds)) ? 'checked' : '' ?>>
                            <?= h($prop['name']) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="help" style="margin-top:4px">Pilih property yang dapat diakses oleh user ini. Superadmin selalu punya akses ke semua property.</div>
                </div>
                <?php endif; ?>
            </div>
            <p><button type="submit">Simpan User</button> <a class="btn secondary" href="?r=users">Kembali</a></p>
        </form>
        <?php
    });
}

function user_save(PDO $pdo): void
{
    verify_csrf();
    $id = post('id');
    $data = [
        'name' => trim((string) post('name')),
        'email' => trim((string) post('email')),
        'role' => post('role', 'sales'),
        'status' => post('status', 'active'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    if (!array_key_exists($data['role'], roles())) {
        $data['role'] = 'sales';
    }
    if (($data['status'] ?? '') !== 'active') {
        $data['status'] = 'inactive';
    }

    $submittedPropIds = array_map('intval', (array) ($_POST['property_ids'] ?? []));

    if ($id) {
        if ((int) $id === (int) ($_SESSION['user']['id'] ?? 0) && $data['status'] !== 'active') {
            flash('User sedang login tidak boleh dinonaktifkan sendiri.');
            redirect_to('user_form', ['id' => $id]);
        }
        $beforeStmt = $pdo->prepare('SELECT id, name, email, role, status FROM users WHERE id = ?');
        $beforeStmt->execute([$id]);
        $before = $beforeStmt->fetch() ?: [];
        $sets = 'name=:name, email=:email, role=:role, status=:status, updated_at=:updated_at';
        if (post('password') !== '') {
            $data['password_hash'] = password_hash((string) post('password'), PASSWORD_DEFAULT);
            $sets .= ', password_hash=:password_hash';
        }
        $data['id'] = $id;
        $stmt = $pdo->prepare("UPDATE users SET $sets WHERE id=:id");
        $stmt->execute($data);
        audit($pdo, 'update_user_role', 'users', (string) $id, $data, $before, 'users');
        // Sync user_properties
        $pdo->prepare('DELETE FROM user_properties WHERE user_id = ?')->execute([$id]);
        $upStmt = $pdo->prepare('INSERT INTO user_properties (user_id, property_id) VALUES (?, ?)');
        foreach ($submittedPropIds as $propId) {
            if ($propId > 0) { $upStmt->execute([$id, $propId]); }
        }
    } else {
        $data['password_hash'] = password_hash((string) post('password'), PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, status, updated_at)
             VALUES (:name, :email, :password_hash, :role, :status, :updated_at)'
        );
        $stmt->execute($data);
        $newUserId = (int) $pdo->lastInsertId();
        audit($pdo, 'create_user', 'users', (string) $newUserId, $data, [], 'users');
        // Assign user_properties for new user
        $upStmt = $pdo->prepare('INSERT INTO user_properties (user_id, property_id) VALUES (?, ?)');
        foreach ($submittedPropIds as $propId) {
            if ($propId > 0) { $upStmt->execute([$newUserId, $propId]); }
        }
    }
    flash('User dan role tersimpan.');
    redirect_to('users');
}

function audit_page(PDO $pdo): void
{
    $pid    = current_property_id();
    $module = getv('module', '');
    if ($module !== '') {
        $stmt = $pdo->prepare('SELECT * FROM audit_logs WHERE module = ? AND property_id = ? ORDER BY id DESC LIMIT 300');
        $stmt->execute([$module, $pid]);
        $rows = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare('SELECT * FROM audit_logs WHERE property_id = ? ORDER BY id DESC LIMIT 300');
        $stmt->execute([$pid]);
        $rows = $stmt->fetchAll();
    }
    layout('Audit Log', function () use ($rows) {
        ?>
        <form class="toolbar" method="get">
            <input type="hidden" name="r" value="audit">
            <div style="max-width:260px"><label>Filter Modul</label><input name="module" value="<?= h(getv('module', '')) ?>" placeholder="contoh: master_media"></div>
            <button type="submit">Filter</button>
        </form>
        <div class="table-wrap"><table>
            <thead><tr><th>Waktu</th><th>User</th><th>Role</th><th>Modul</th><th>Aksi</th><th>Route</th><th>Tabel</th><th>Record</th><th>IP</th><th>Komputer</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td style="white-space:nowrap"><?= h($row['created_at']) ?></td>
                    <td><?= h($row['user_name'] ?? '') ?><?= $row['actor'] ? '<br><span style="font-size:11px;color:var(--muted)">' . h($row['actor']) . '</span>' : '' ?></td>
                    <td><?= h($row['user_role'] ?? '') ?></td>
                    <td><?= h($row['module'] ?? '') ?></td>
                    <td><?= h($row['action']) ?></td>
                    <td><?= h($row['route'] ?? '') ?></td>
                    <td><?= h($row['table_name']) ?></td>
                    <td><?= h($row['record_id']) ?></td>
                    <td style="font-family:monospace;font-size:12px"><?= h($row['ip_address'] ?? '') ?></td>
                    <td style="font-size:12px"><?= h($row['computer_name'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php
    });
}

function roles_page(PDO $pdo): void
{
    require_permission('manage_users');
    $allPermissions = [
        'view_dashboard'      => 'Lihat Dashboard',
        'view_exec_summary'   => 'Executive Summary',
        'view_transactions'   => 'Lihat Transaksi',
        'manage_transactions' => 'Kelola Transaksi',
        'view_master'         => 'Lihat Master Data',
        'manage_master'       => 'Kelola Master Data',
        'import_master'       => 'Import Master',
        'export_reports'      => 'Export Laporan',
        'view_pic_report'     => 'Laporan PIC',
        'view_logs'           => 'Activity Log',
        'manage_users'        => 'Kelola Users',
        'manage_deleted'      => 'Hapus Transaksi',
    ];
    $editableRoles = ['supervisor', 'sales', 'finance', 'administrasi', 'viewer'];
    $current = [];
    foreach ($pdo->query('SELECT role, permission FROM role_permissions')->fetchAll() as $r) {
        $current[$r['role']][$r['permission']] = true;
    }
    layout('Role & Permission', function () use ($allPermissions, $editableRoles, $current) {
        ?>
        <form class="panel" method="post" action="?r=roles_save">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <p style="margin-top:0;color:var(--muted);font-size:13px">
                <strong>Super Admin</strong> selalu punya akses penuh. <strong>Lihat Dashboard</strong> selalu aktif untuk semua role.
            </p>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="min-width:120px">Role</th>
                            <?php foreach ($allPermissions as $perm => $label): ?>
                                <th style="text-align:center;font-size:12px"><?= h($label) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php /* Super Admin row — all locked */ ?>
                        <tr style="background:#f8fafc">
                            <td><strong>Super Admin</strong></td>
                            <?php foreach ($allPermissions as $perm => $label): ?>
                                <td style="text-align:center"><input type="checkbox" checked disabled title="Selalu aktif"></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php foreach ($editableRoles as $role): ?>
                        <tr>
                            <td><?= h(roles()[$role] ?? $role) ?></td>
                            <?php foreach ($allPermissions as $perm => $label): ?>
                                <?php $locked = $perm === 'view_dashboard'; ?>
                                <?php $checked = $locked || !empty($current[$role][$perm]); ?>
                                <td style="text-align:center">
                                    <?php if ($locked): ?>
                                        <input type="checkbox" checked disabled title="Selalu aktif">
                                    <?php else: ?>
                                        <input type="checkbox" name="perms[<?= h($role) ?>][]" value="<?= h($perm) ?>" <?= $checked ? 'checked' : '' ?>>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="margin-top:14px"><button type="submit">Simpan Permission</button></p>
        </form>
        <?php
    });
}

function roles_save(PDO $pdo): void
{
    require_permission('manage_users');
    verify_csrf();

    $editableRoles = ['supervisor', 'sales', 'finance', 'administrasi', 'viewer'];
    $allPermissions = [
        'view_dashboard', 'view_exec_summary', 'view_transactions', 'manage_transactions',
        'view_master', 'manage_master', 'import_master',
        'export_reports', 'view_pic_report', 'view_logs', 'manage_users', 'manage_deleted',
    ];

    $submitted = $_POST['perms'] ?? [];

    $pdo->prepare('DELETE FROM role_permissions WHERE role != ?')->execute(['superadmin']);

    $stmt = $pdo->prepare('INSERT INTO role_permissions (role, permission) VALUES (?, ?)');
    foreach ($editableRoles as $role) {
        // view_dashboard always on
        $stmt->execute([$role, 'view_dashboard']);
        foreach ($allPermissions as $perm) {
            if ($perm === 'view_dashboard') continue;
            if (!empty($submitted[$role]) && in_array($perm, (array) $submitted[$role], true)) {
                $stmt->execute([$role, $perm]);
            }
        }
    }

    // Reload static cache for current request + invalidate session cache
    $rows = $pdo->query('SELECT role, permission FROM role_permissions')->fetchAll();
    $matrix = [];
    foreach ($rows as $r) {
        $matrix[$r['role']][] = $r['permission'];
    }
    permission_matrix($matrix);
    unset($_SESSION['_perm_matrix'], $_SESSION['_perm_cache_at']);

    audit($pdo, 'update', 'role_permissions', null, $submitted);
    flash('Permission berhasil disimpan.');
    redirect_to('roles');
}
