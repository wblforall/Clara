<?php
declare(strict_types=1);

function lookup_manage_page(PDO $pdo): void
{
    require_permission('manage_master');

    $categories = [
        'business_type'  => 'Jenis Usaha',
        'business_scale' => 'Skala Bisnis',
        'brand_origin'   => 'Asal Brand',
        'target_segment' => 'Target Segmen',
        'channel'        => 'Channel',
    ];
    $selectedCat = getv('cat', 'business_type');
    if (!isset($categories[$selectedCat])) $selectedCat = 'business_type';

    $pid = current_property_id();
    $rows = $pdo->prepare("SELECT id, value, sort_order, status FROM master_lookup_options WHERE category=? AND property_id=? ORDER BY sort_order, value");
    $rows->execute([$selectedCat, $pid]);
    $rows = $rows->fetchAll();

    layout('Kelola Opsi Dropdown', function () use ($categories, $selectedCat, $rows) { ?>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:18px">
            <h2 style="margin:0">Kelola Opsi Dropdown</h2>
            <a class="btn" href="?r=lookup_manage&cat=<?= h($selectedCat) ?>&action=add">+ Tambah Opsi</a>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px">
            <?php foreach ($categories as $k => $label): ?>
            <a href="?r=lookup_manage&cat=<?= h($k) ?>" class="btn <?= $k === $selectedCat ? '' : 'light' ?>"><?= h($label) ?></a>
            <?php endforeach; ?>
        </div>
        <?php if (getv('action') === 'add' || getv('action') === 'edit'): ?>
        <?php $editRow = getv('action') === 'edit' ? array_values(array_filter($rows, fn($r) => $r['id'] == getv('id')))[0] ?? null : null; ?>
        <div class="panel" style="margin-bottom:20px;max-width:480px">
            <h3 style="margin-top:0"><?= getv('action') === 'edit' ? 'Edit' : 'Tambah' ?> Opsi — <?= h($categories[$selectedCat]) ?></h3>
            <form method="post" action="?r=lookup_save">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="cat" value="<?= h($selectedCat) ?>">
                <input type="hidden" name="id" value="<?= h((string) ($editRow['id'] ?? '')) ?>">
                <div class="form-grid" style="grid-template-columns:1fr">
                    <div>
                        <label>Nilai / Label</label>
                        <input type="text" name="value" value="<?= h($editRow['value'] ?? '') ?>" required class="w-full">
                    </div>
                    <div>
                        <label>Urutan tampil</label>
                        <input type="number" name="sort_order" value="<?= h((string) ($editRow['sort_order'] ?? '0')) ?>" class="w-full">
                    </div>
                    <div>
                        <label>Status</label>
                        <select name="status" class="w-full">
                            <option value="active" <?= ($editRow['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Aktif</option>
                            <option value="inactive" <?= ($editRow['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top:14px;display:flex;gap:8px">
                    <button type="submit" class="btn">Simpan</button>
                    <a href="?r=lookup_manage&cat=<?= h($selectedCat) ?>" class="btn light">Batal</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
        <div class="panel" style="overflow-x:auto">
            <table class="table">
                <thead><tr><th>#</th><th>Nilai</th><th>Urutan</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php if (empty($rows)): ?>
                <tr><td colspan="5" class="muted" style="text-align:center;padding:24px">Belum ada opsi.</td></tr>
                <?php else: ?>
                <?php foreach ($rows as $i => $row): ?>
                <tr>
                    <td class="muted"><?= $i + 1 ?></td>
                    <td><?= h($row['value']) ?></td>
                    <td><?= h((string) $row['sort_order']) ?></td>
                    <td><span class="badge <?= $row['status'] === 'active' ? 'active' : 'inactive' ?>"><?= $row['status'] === 'active' ? 'Aktif' : 'Nonaktif' ?></span></td>
                    <td>
                        <a class="btn light" href="?r=lookup_manage&cat=<?= h($selectedCat) ?>&action=edit&id=<?= h((string) $row['id']) ?>">Edit</a>
                        <form method="post" action="?r=lookup_delete" style="display:inline" onsubmit="return confirm('Hapus opsi ini?')">
                            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="cat" value="<?= h($selectedCat) ?>">
                            <input type="hidden" name="id" value="<?= h((string) $row['id']) ?>">
                            <button type="submit" class="btn light" style="color:#c0392b">Hapus</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php });
}

function lookup_save(PDO $pdo): void
{
    require_permission('manage_master');
    verify_csrf();

    $cat = post('cat');
    $allowed = ['business_type','business_scale','brand_origin','target_segment','channel'];
    if (!in_array($cat, $allowed, true)) { flash('Kategori tidak valid.'); redirect_to('lookup_manage'); return; }

    $value      = trim((string) post('value'));
    $sort_order = (int) post('sort_order', '0');
    $status     = post('status', 'active') === 'inactive' ? 'inactive' : 'active';
    $id         = post('id');

    if ($value === '') { flash('Nilai tidak boleh kosong.'); redirect_to('lookup_manage', ['cat' => $cat]); return; }

    $pid = current_property_id();
    if ($id) {
        $pdo->prepare("UPDATE master_lookup_options SET value=?, sort_order=?, status=? WHERE id=? AND category=? AND property_id=?")
            ->execute([$value, $sort_order, $status, $id, $cat, $pid]);
        flash('Opsi diperbarui.');
    } else {
        $pdo->prepare("INSERT INTO master_lookup_options (property_id, category, value, sort_order, status) VALUES (?, ?, ?, ?, ?)")
            ->execute([$pid, $cat, $value, $sort_order, $status]);
        flash('Opsi ditambahkan.');
    }
    redirect_to('lookup_manage', ['cat' => $cat]);
}

function lookup_delete(PDO $pdo): void
{
    require_permission('manage_master');
    verify_csrf();

    $cat = post('cat');
    $allowed = ['business_type','business_scale','brand_origin','target_segment','channel'];
    if (!in_array($cat, $allowed, true)) { flash('Kategori tidak valid.'); redirect_to('lookup_manage'); return; }

    $pid = current_property_id();
    $id  = post('id');
    if ($id) {
        $pdo->prepare("DELETE FROM master_lookup_options WHERE id=? AND category=? AND property_id=?")->execute([$id, $cat, $pid]);
        flash('Opsi dihapus.');
    }
    redirect_to('lookup_manage', ['cat' => $cat]);
}
