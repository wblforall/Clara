<?php
declare(strict_types=1);

function master_referrer_page(PDO $pdo): void
{
    require_permission('view_master');

    $action = getv('action', 'list');
    $id     = (int) getv('id', 0);

    if ($action === 'edit' && $id) {
        _referrer_form($pdo, $id);
    } elseif ($action === 'add') {
        _referrer_form($pdo, 0);
    } else {
        _referrer_list($pdo);
    }
}

function _referrer_list(PDO $pdo): void
{
    $rows = $pdo->query(
        "SELECT * FROM master_referrer ORDER BY status ASC, name ASC"
    )->fetchAll();

    layout('Master Referrer', function () use ($rows) {
        ?>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
            <div>
                <h2 style="margin:0">Master Referrer</h2>
                <p class="muted" style="margin:4px 0 0;font-size:13px">Karyawan non-CL yang bisa mereferensikan klien dan mendapat komisi 1% per dealing.</p>
            </div>
            <a class="btn" href="?r=master_referrer&action=add">+ Tambah Referrer</a>
        </div>
        <div class="panel">
            <?php if (empty($rows)): ?>
                <p class="muted" style="text-align:center;padding:32px">Belum ada data referrer.</p>
            <?php else: ?>
            <div class="table-wrap" style="overflow-x:auto">
                <table>
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Jabatan</th>
                            <th>Departemen</th>
                            <th>No. Rekening</th>
                            <th>Nama Bank</th>
                            <th style="text-align:center">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td style="font-weight:600"><?= h($r['name']) ?></td>
                            <td class="muted"><?= h($r['jabatan'] ?? '—') ?></td>
                            <td class="muted"><?= h($r['dept'] ?? '—') ?></td>
                            <td><?= h($r['no_rekening'] ?? '—') ?></td>
                            <td class="muted"><?= h($r['nama_bank'] ?? '—') ?></td>
                            <td style="text-align:center">
                                <?php if ($r['status'] === 'active'): ?>
                                    <span style="background:#dcfce7;color:#15803d;font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px">Aktif</span>
                                <?php else: ?>
                                    <span style="background:#f1f5f9;color:#64748b;font-size:11px;font-weight:700;padding:2px 8px;border-radius:99px">Nonaktif</span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap">
                                <a class="btn light" style="font-size:11px" href="?r=master_referrer&action=edit&id=<?= $r['id'] ?>">Edit</a>
                            </td>
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

function _referrer_form(PDO $pdo, int $id): void
{
    $row = [];
    if ($id) {
        $s = $pdo->prepare('SELECT * FROM master_referrer WHERE id = ?');
        $s->execute([$id]);
        $row = $s->fetch() ?: [];
        if (!$row) {
            flash('Data tidak ditemukan.');
            redirect_to('master_referrer');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $name       = trim((string) post('name'));
        $jabatan    = trim((string) post('jabatan'))    ?: null;
        $dept       = trim((string) post('dept'))       ?: null;
        $no_rekening = trim((string) post('no_rekening')) ?: null;
        $nama_bank  = trim((string) post('nama_bank'))  ?: null;
        $status     = post('status', 'active') === 'inactive' ? 'inactive' : 'active';

        if (!$name) {
            flash('Nama wajib diisi.');
            redirect_to('master_referrer', ['action' => $id ? 'edit' : 'add', 'id' => $id]);
        }

        if ($id) {
            $pdo->prepare(
                'UPDATE master_referrer SET name=?, jabatan=?, dept=?, no_rekening=?, nama_bank=?, status=? WHERE id=?'
            )->execute([$name, $jabatan, $dept, $no_rekening, $nama_bank, $status, $id]);
            flash('Data referrer diperbarui.');
        } else {
            $pdo->prepare(
                'INSERT INTO master_referrer (name, jabatan, dept, no_rekening, nama_bank, status) VALUES (?,?,?,?,?,?)'
            )->execute([$name, $jabatan, $dept, $no_rekening, $nama_bank, $status]);
            flash('Referrer baru ditambahkan.');
        }
        redirect_to('master_referrer');
    }

    $title = $id ? 'Edit Referrer' : 'Tambah Referrer';
    layout($title, function () use ($row, $id) {
        ?>
        <div style="max-width:520px">
            <div style="margin-bottom:12px">
                <a class="btn secondary" href="?r=master_referrer">← Kembali</a>
            </div>
            <form class="panel" method="post" action="?r=master_referrer<?= $id ? '&action=edit&id='.$id : '&action=add' ?>">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <div class="form-grid">
                    <div class="wide">
                        <label>Nama Lengkap <span style="color:#ef4444">*</span></label>
                        <input type="text" name="name" value="<?= h($row['name'] ?? '') ?>" required autofocus placeholder="cth. Budi Santoso">
                    </div>
                    <div>
                        <label>Jabatan <span class="muted" style="font-weight:400">(opsional)</span></label>
                        <input type="text" name="jabatan" value="<?= h($row['jabatan'] ?? '') ?>" placeholder="cth. Staff Marketing">
                    </div>
                    <div>
                        <label>Departemen <span class="muted" style="font-weight:400">(opsional)</span></label>
                        <input type="text" name="dept" value="<?= h($row['dept'] ?? '') ?>" placeholder="cth. Marketing, IT, F&B">
                    </div>
                    <div>
                        <label>No. Rekening <span class="muted" style="font-weight:400">(opsional)</span></label>
                        <input type="text" name="no_rekening" value="<?= h($row['no_rekening'] ?? '') ?>" placeholder="cth. 1234567890">
                    </div>
                    <div>
                        <label>Nama Bank <span class="muted" style="font-weight:400">(opsional)</span></label>
                        <input type="text" name="nama_bank" value="<?= h($row['nama_bank'] ?? '') ?>" placeholder="cth. BCA, BNI, Mandiri">
                    </div>
                    <div>
                        <label>Status</label>
                        <select name="status">
                            <option value="active"   <?= ($row['status'] ?? 'active') === 'active'   ? 'selected' : '' ?>>Aktif</option>
                            <option value="inactive" <?= ($row['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
                        </select>
                    </div>
                </div>
                <p style="margin-top:16px">
                    <button type="submit"><?= $id ? 'Simpan Perubahan' : 'Tambah Referrer' ?></button>
                    <a class="btn secondary" href="?r=master_referrer">Batal</a>
                </p>
            </form>
        </div>
        <?php
    });
}
