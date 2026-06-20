<?php
declare(strict_types=1);

function master_page(PDO $pdo, array $masterConfig): void
{
    $type = getv('type', 'media');
    $cfg = $masterConfig[$type] ?? $masterConfig['media'];
    $orderBy = isset($cfg['order']) ? $cfg['order'] : 'id DESC';
    $pid = current_property_id();
    $picStatus = ($type === 'pic') ? getv('pic_status', 'active') : '';
    if ($type === 'pic' && in_array($picStatus, ['active', 'inactive', 'archived', ''], true)) {
        $statusWhere = $picStatus !== '' ? ' AND p.status = ' . $pdo->quote($picStatus) : '';
        $stmt = $pdo->prepare(
            "SELECT p.*, u.name AS user_name FROM master_pic p
             LEFT JOIN users u ON u.id = p.user_id
             WHERE p.property_id = ? $statusWhere ORDER BY p.$orderBy LIMIT 300"
        );
        $stmt->execute([$pid]);
        $rows = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare('SELECT * FROM ' . $cfg['table'] . ' WHERE property_id = ? ORDER BY ' . $orderBy . ' LIMIT 300');
        $stmt->execute([$pid]);
        $rows = $stmt->fetchAll();
    }
    layout($cfg['title'], function () use ($type, $cfg, $rows, $picStatus, $pdo, $pid) {
        ?>
        <div class="toolbar">
            <?php if (can('manage_master')): ?><a class="btn" href="?r=master_form&type=<?= h($type) ?>">Tambah Data</a><?php endif; ?>
            <?php if ($type === 'target' && can('manage_master')): ?>
                <form method="post" action="?r=generate_periods" style="display:inline-flex;align-items:center;gap:6px">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <select name="gen_year" style="width:auto">
                        <?php foreach (range((int)date('Y') - 1, (int)date('Y') + 2) as $gy): ?>
                            <option value="<?= $gy ?>" <?= $gy == (int)date('Y') ? 'selected' : '' ?>><?= $gy ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn light">Generate 12 Periode</button>
                </form>
            <?php endif; ?>
            <?php if ($type === 'media' && can('import_master')): ?>
                <a class="btn light" href="?r=import_media">Import CSV Media</a>
                <a class="btn" style="background:#28a745" href="?r=import_template">Import Master Template (Excel)</a>
            <?php endif; ?>
        </div>
        <?php if ($type === 'pic'): ?>
        <div style="display:flex;gap:6px;margin-bottom:12px">
            <?php foreach (['active' => 'Aktif', 'inactive' => 'Tidak Aktif', 'archived' => 'Arsip', '' => 'Semua'] as $sv => $sl): ?>
                <a class="btn <?= $picStatus === $sv ? '' : 'light' ?>" href="?r=master&type=pic&pic_status=<?= h($sv) ?>"><?= $sl ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="table-wrap">
            <table id="master-table">
                <thead><tr>
                    <?php if (!empty($cfg['sortable']) && can('manage_master')): ?><th style="width:32px"></th><?php endif; ?>
                    <?php foreach ($cfg['columns'] as $col): ?><th><?= h($cfg['column_labels'][$col] ?? $col) ?></th><?php endforeach; ?>
                    <th>Aksi</th>
                </tr></thead>
                <tbody id="master-tbody">
                <?php foreach ($rows as $row): ?>
                    <tr data-id="<?= (int)$row['id'] ?>">
                        <?php if (!empty($cfg['sortable']) && can('manage_master')): ?>
                        <td class="drag-handle" title="Drag untuk atur urutan" style="cursor:grab;text-align:center;color:#cbd5e1;font-size:16px;user-select:none">⠿</td>
                        <?php endif; ?>
                        <?php foreach ($cfg['columns'] as $col): ?>
                            <?php $isMoney = is_numeric($row[$col] ?? null) && (str_contains($col, 'amount') || str_contains($col, 'rate') || str_contains($col, 'projection')); ?>
                            <?php $isShare = $col === 'target_share'; ?>
                            <?php $isUserId = $col === 'user_id'; ?>
                            <td><?php
                                if ($isUserId) {
                                    echo $row['user_name'] ? '<span class="badge" style="background:#e0f2fe;color:#0369a1">' . h($row['user_name']) . '</span>' : '<span style="color:var(--muted)">—</span>';
                                } elseif ($isShare) {
                                    echo pct((float)($row[$col] ?? 0));
                                } elseif ($isMoney) {
                                    echo money($row[$col]);
                                } else {
                                    echo h((string)($row[$col] ?? ''));
                                }
                            ?></td>
                        <?php endforeach; ?>
                        <td><?php if (can('manage_master')): ?><a class="btn light" href="?r=master_form&type=<?= h($type) ?>&id=<?= h((string) $row['id']) ?>">Edit</a><?php else: ?><span class="muted">Read only</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!empty($cfg['sortable']) && can('manage_master')): ?>
        <script src="assets/sortable.min.js"></script>
        <script>
        (function() {
            var tbody = document.getElementById('master-tbody');
            if (!tbody) return;
            var saveEl = null;
            Sortable.create(tbody, {
                handle: '.drag-handle',
                animation: 150,
                ghostClass: 'sortable-ghost',
                onStart: function() {
                    if (!saveEl) {
                        saveEl = document.createElement('div');
                        saveEl.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#0d9488;color:#fff;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:700;z-index:999;opacity:0;transition:opacity .2s';
                        saveEl.textContent = 'Menyimpan urutan…';
                        document.body.appendChild(saveEl);
                    }
                },
                onEnd: function() {
                    var ids = Array.from(tbody.querySelectorAll('tr[data-id]')).map(function(r) {
                        return parseInt(r.dataset.id, 10);
                    });
                    saveEl.textContent = 'Menyimpan urutan…';
                    saveEl.style.opacity = '1';
                    fetch('?r=master_sort_save&type=<?= h($type) ?>', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ids: ids})
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        saveEl.textContent = d.ok ? '✓ Urutan tersimpan' : '✗ Gagal simpan';
                        setTimeout(function() { saveEl.style.opacity = '0'; }, 1500);
                    })
                    .catch(function() {
                        saveEl.textContent = '✗ Gagal simpan';
                        setTimeout(function() { saveEl.style.opacity = '0'; }, 1500);
                    });
                }
            });
        })();
        </script>
        <style>
        .sortable-ghost { opacity:.4; background:#e0f2fe !important; }
        #master-tbody tr { transition: background .15s; }
        .drag-handle:hover { color:#0d9488 !important; }
        </style>
        <?php endif; ?>

        <?php if ($type === 'target'): ?>
        <?php
            $histori = $pdo->prepare(
                "SELECT h.changed_at, h.period_key, h.segment, h.slot_code,
                        h.old_value, h.new_value, u.name AS user_name
                 FROM potential_history h
                 LEFT JOIN users u ON u.id = h.changed_by
                 WHERE h.property_id = ?
                 ORDER BY h.changed_at DESC
                 LIMIT 100"
            );
            $histori->execute([$pid]);
            $histRows = $histori->fetchAll();
            $segLabel = ['exhibition' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
        ?>
        <div style="margin-top:24px">
            <h3 style="font-size:14px;font-weight:700;color:var(--text);margin-bottom:12px">Histori Perubahan Potensi</h3>
            <?php if (empty($histRows)): ?>
                <p style="color:var(--muted);font-size:13px">Belum ada perubahan potensi tercatat. Histori akan muncul setelah ada perubahan di Master Exhibition, Media, atau Gudang.</p>
            <?php else: ?>
            <div class="table-wrap">
                <table style="font-size:12px">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Periode</th>
                            <th>Segmen</th>
                            <th>Slot</th>
                            <th style="text-align:right">Nilai Lama</th>
                            <th style="text-align:right">Nilai Baru</th>
                            <th style="text-align:right">Selisih</th>
                            <th>Diubah Oleh</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($histRows as $h): ?>
                        <?php $diff = (float)$h['new_value'] - (float)$h['old_value']; ?>
                        <tr>
                            <td style="white-space:nowrap;color:var(--muted)"><?= h(date('d/m/Y H:i', strtotime($h['changed_at']))) ?></td>
                            <td><?= h($h['period_key']) ?></td>
                            <td><?= h($segLabel[$h['segment']] ?? $h['segment']) ?></td>
                            <td><code><?= h($h['slot_code']) ?></code></td>
                            <td style="text-align:right"><?= money($h['old_value']) ?></td>
                            <td style="text-align:right"><?= money($h['new_value']) ?></td>
                            <td style="text-align:right;font-weight:700;color:<?= $diff >= 0 ? '#16a34a' : '#dc2626' ?>">
                                <?= ($diff >= 0 ? '+' : '') . money($diff) ?>
                            </td>
                            <td><?= h($h['user_name'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php
    });
}

function master_form(PDO $pdo, array $masterConfig): void
{
    $type = getv('type', 'media');
    $cfg = $masterConfig[$type] ?? $masterConfig['media'];
    $pid = current_property_id();
    $id = getv('id');
    $row = [];
    if ($id) {
        $stmt = $pdo->prepare('SELECT * FROM ' . $cfg['table'] . ' WHERE id = ? AND property_id = ?');
        $stmt->execute([$id, $pid]);
        $row = $stmt->fetch() ?: [];
    }
    $periodYears = [];
    if ($type === 'target') {
        $stmt = $pdo->prepare("SELECT DISTINCT substr(period_key,1,4) y FROM periods WHERE property_id = ? ORDER BY y");
        $stmt->execute([$pid]);
        $existingYears = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $curYear = (int) date('Y');
        $allYears = array_unique(array_merge($existingYears, [
            (string)($curYear - 1), (string)$curYear, (string)($curYear + 1), (string)($curYear + 2),
        ]));
        sort($allYears);
        $periodYears = $allYears;
    }
    $periodMonthNames = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
    $users = $type === 'pic'
        ? $pdo->query("SELECT id, name FROM users WHERE status='active' ORDER BY name")->fetchAll()
        : [];

    // Kode yang sudah ada — untuk auto-generate di form tambah baru
    $existingCodes = [];
    if (!$id && in_array($type, ['cl', 'gudang', 'media'], true)) {
        $s = $pdo->prepare("SELECT code FROM {$cfg['table']} WHERE property_id = ?");
        $s->execute([$pid]);
        $existingCodes = $s->fetchAll(PDO::FETCH_COLUMN);
    }

    // Daftar baku Tipe Unit (dropdown) — per properti, dari master_cl_unit_types.
    $unitTypes = $type === 'cl' ? cl_unit_types($pdo, $pid) : [];

    layout(($id ? 'Edit ' : 'Tambah ') . $cfg['title'], function () use ($type, $cfg, $row, $id, $periodYears, $periodMonthNames, $users, $existingCodes, $unitTypes) {
        ?>
        <?php if (!$id && !empty($existingCodes)): ?>
        <script>
        var _existingCodes = <?= json_encode($existingCodes) ?>;
        var _masterType    = <?= json_encode($type) ?>;

        function _nextCode() {
            var type = _masterType;
            if (type === 'cl') {
                var floor = (document.getElementById('floor') || {}).value || '';
                if (!floor) return '';
                var prefix = floor.toUpperCase() + '-';
                var max = 0;
                _existingCodes.forEach(function(c) {
                    if (c.toUpperCase().startsWith(prefix)) {
                        var n = parseInt(c.slice(prefix.length), 10);
                        if (!isNaN(n) && n > max) max = n;
                    }
                });
                return prefix + String(max + 1).padStart(3, '0');
            } else {
                var prefix = type === 'gudang' ? 'Guda-' : 'Medi-';
                var max = 0;
                _existingCodes.forEach(function(c) {
                    if (c.startsWith(prefix)) {
                        var n = parseInt(c.slice(prefix.length), 10);
                        if (!isNaN(n) && n > max) max = n;
                    }
                });
                return prefix + String(max + 1).padStart(3, '0');
            }
        }

        function generateCode() {
            var code = _nextCode();
            if (code) document.getElementById('field-code').value = code;
        }

        // CL: regenerate saat lantai berubah (sebelum user isi kode)
        document.addEventListener('DOMContentLoaded', function() {
            var floorEl = document.getElementById('floor');
            if (floorEl) {
                floorEl.addEventListener('change', function() {
                    var codeEl = document.getElementById('field-code');
                    if (codeEl && codeEl.value === '') generateCode();
                });
            }
        });
        </script>
        <?php endif; ?>

        <form class="panel" method="post" action="?r=master_save&type=<?= h($type) ?>" id="master-form">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= h((string) $id) ?>">
            <div class="form-grid">
                <?php foreach ($cfg['fields'] as $name => $label): ?>
                    <div>
                        <label><?= h($label) ?></label>
                        <?php if ($name === 'period_key' && $type === 'target'): ?>
                            <?php
                                $pkVal  = $row['period_key'] ?? date('Y-m');
                                $pkYear = substr($pkVal, 0, 4);
                                $pkMonth = substr($pkVal, 5, 2);
                            ?>
                            <input type="hidden" name="period_key" id="period_key_hidden" value="<?= h($pkVal) ?>">
                            <div style="display:flex;gap:8px">
                                <select id="pk-year" onchange="syncPeriodKey()" style="flex:1">
                                    <?php foreach ($periodYears as $y): ?>
                                        <option value="<?= h($y) ?>" <?= $pkYear === $y ? 'selected' : '' ?>><?= h($y) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select id="pk-month" onchange="syncPeriodKey()" style="flex:1">
                                    <?php foreach ($periodMonthNames as $m => $mLabel): ?>
                                        <?php $mStr = sprintf('%02d', $m); ?>
                                        <option value="<?= h($mStr) ?>" <?= $pkMonth === $mStr ? 'selected' : '' ?>><?= h($mLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <script>
                            function syncPeriodKey() {
                                var y = document.getElementById('pk-year').value;
                                var m = document.getElementById('pk-month').value;
                                document.getElementById('period_key_hidden').value = y + '-' + m;
                            }
                            </script>
                        <?php elseif ($name === 'pricing_type'): ?>
                            <select name="<?= h($name) ?>">
                                <?php foreach (['daily_point', 'daily_slot', 'daily_area', 'monthly', 'fixed'] as $opt): ?>
                                    <option value="<?= h($opt) ?>" <?= ($row[$name] ?? '') === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($name === 'user_id'): ?>
                            <select name="user_id">
                                <option value="">— Tidak ada —</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= h((string)$u['id']) ?>" <?= (string)($row['user_id'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>>
                                        <?= h($u['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help">Hubungkan ke akun login untuk auto-select saat input transaksi.</div>
                        <?php elseif ($name === 'commission_cat'): ?>
                            <select name="commission_cat">
                                <?php
                                $commCats = [
                                    ''             => '— Tidak Dapat Komisi —',
                                    'sales'        => 'Sales',
                                    'manager'      => 'Manager',
                                    'asst_manager' => 'Asst. Manager',
                                    'admin'        => 'Admin',
                                    'other'        => 'Other',
                                ];
                                $curCat = $row['commission_cat'] ?? '';
                                foreach ($commCats as $val => $lbl): ?>
                                    <option value="<?= h($val) ?>" <?= $curCat === $val ? 'selected' : '' ?>><?= h($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help">Kosong = tidak masuk perhitungan komisi (contoh: WBL Unit).</div>
                        <?php elseif ($name === 'show_achievement'): ?>
                            <select name="show_achievement">
                                <option value="1" <?= ($row['show_achievement'] ?? 1) == 1 ? 'selected' : '' ?>>Ya — Tampil di Achievement PIC</option>
                                <option value="0" <?= ($row['show_achievement'] ?? 1) == 0 ? 'selected' : '' ?>>Tidak — Sembunyikan</option>
                            </select>
                            <div class="help">Sembunyikan dari tabel Achievement di Dashboard, Exec Summary, dan Laporan PIC.</div>
                        <?php elseif ($name === 'show_in_offer'): ?>
                            <select name="show_in_offer">
                                <option value="1" <?= ($row['show_in_offer'] ?? 1) == 1 ? 'selected' : '' ?>>Ya — Muncul di dropdown PIC Penawaran</option>
                                <option value="0" <?= ($row['show_in_offer'] ?? 1) == 0 ? 'selected' : '' ?>>Tidak — Sembunyikan dari pilihan</option>
                            </select>
                            <div class="help">Hanya yang dicentang "Ya" yang muncul saat memilih PIC di Surat Penawaran.</div>
                        <?php elseif ($name === 'status'): ?>
                            <select name="<?= h($name) ?>">
                                <?php foreach (['active', 'inactive', 'archived'] as $opt): ?>
                                    <option value="<?= h($opt) ?>" <?= ($row[$name] ?? 'active') === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($name === 'target_share'): ?>
                            <div style="display:flex;align-items:center;gap:6px">
                                <input type="number" step="0.01" min="0" max="100" name="target_share_pct" value="<?= h((string) round((float)($row['target_share'] ?? 0) * 100, 2)) ?>">
                                <span>%</span>
                            </div>
                            <input type="hidden" name="target_share" id="target_share_hidden" value="<?= h((string) ($row['target_share'] ?? 0)) ?>">
                            <script>
                            document.querySelector('[name=target_share_pct]').addEventListener('input', function() {
                                document.getElementById('target_share_hidden').value = (parseFloat(this.value) || 0) / 100;
                            });
                            </script>
                        <?php elseif ($name === 'target_amount'): ?>
                            <?php $taRaw = (string) ($row['target_amount'] ?? ''); ?>
                            <input type="text" inputmode="numeric" id="target_amount_fmt" value="<?= $taRaw !== '' ? number_format((float)$taRaw, 0, ',', '.') : '' ?>" placeholder="0">
                            <input type="hidden" name="target_amount" id="target_amount_hidden" value="<?= h($taRaw) ?>">
                            <script>
                            document.getElementById('target_amount_fmt').addEventListener('input', function() {
                                var raw = this.value.replace(/\D/g, '');
                                this.value = raw ? parseInt(raw, 10).toLocaleString('id-ID') : '';
                                document.getElementById('target_amount_hidden').value = raw;
                            });
                            </script>
                        <?php elseif (in_array($name, ['rate', 'monthly_rate', 'projection_monthly'], true)): ?>
                            <?php $rawVal = (string) ($row[$name] ?? ''); $rawInt = $rawVal !== '' ? (string)(int)(float)$rawVal : ''; ?>
                            <input type="text" inputmode="numeric" id="<?= h($name) ?>_fmt" autocomplete="off"
                                   value="<?= $rawInt !== '' && $rawInt !== '0' ? number_format((int)$rawInt, 0, ',', '.') : '' ?>"
                                   placeholder="0">
                            <input type="hidden" name="<?= h($name) ?>" id="<?= h($name) ?>_hidden" value="<?= h($rawInt) ?>">
                            <script>
                            (function() {
                                var fmt = document.getElementById('<?= h($name) ?>_fmt');
                                var hid = document.getElementById('<?= h($name) ?>_hidden');
                                fmt.addEventListener('input', function() {
                                    var raw = this.value.replace(/\D/g, '');
                                    this.value = raw ? parseInt(raw, 10).toLocaleString('id-ID') : '';
                                    hid.value = raw;
                                });
                            })();
                            </script>
                        <?php else: ?>
                            <?php if ($name === 'code' && !$id && !empty($existingCodes)): ?>
                            <div style="display:flex;gap:6px;align-items:center">
                                <input name="code" id="field-code" value="<?= field($row, 'code') ?>" style="flex:1">
                                <button type="button" onclick="generateCode()" class="btn light" style="white-space:nowrap;flex-shrink:0">⚡ Generate</button>
                            </div>
                            <?php elseif ($name === 'floor'): ?>
                            <select name="floor" id="floor">
                                <?php foreach (['LG','GF','UG','FF','SF'] as $fl): ?>
                                    <option value="<?= $fl ?>" <?= ($row['floor'] ?? '') === $fl ? 'selected' : '' ?>><?= $fl ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php elseif ($name === 'unit_type'): ?>
                            <?php $utCur = (string)($row['unit_type'] ?? ''); $utBaku = $unitTypes; ?>
                            <select name="unit_type" id="unit_type">
                                <option value="">— pilih tipe —</option>
                                <?php foreach ($utBaku as $ut): ?>
                                    <option value="<?= h($ut) ?>" <?= $utCur === $ut ? 'selected' : '' ?>><?= h($ut) ?></option>
                                <?php endforeach; ?>
                                <?php if ($utCur !== '' && !in_array($utCur, $utBaku, true)): ?>
                                    <option value="<?= h($utCur) ?>" selected><?= h($utCur) ?> (lama — perbaiki)</option>
                                <?php endif; ?>
                            </select>
                            <div class="help">Pilih dari daftar baku. Nilai lama yang tak baku ditandai "(lama — perbaiki)" agar diseragamkan.</div>
                            <?php else: ?>
                            <input name="<?= h($name) ?>" value="<?= field($row, $name) ?>">
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <p>
                <?php if (array_key_exists('projection_monthly', $cfg['fields'])): ?>
                <button type="button" onclick="hitungPotensi()" class="btn light" style="background:#0ea5e9;color:#fff">Hitung Potensi</button>
                <?php endif; ?>
                <button type="submit">Simpan</button>
                <a class="btn secondary" href="?r=master&type=<?= h($type) ?>">Kembali</a>
            </p>
        </form>
        <?php if (array_key_exists('projection_monthly', $cfg['fields'])): ?>
        <script>
        function hitungPotensi() {
            const rate        = parseFloat(document.querySelector('[name=rate]')?.value) || 0;
            const monthlyRate = parseFloat(document.querySelector('[name=monthly_rate]')?.value) || 0;
            const pricing     = document.querySelector('[name=pricing_type]')?.value || '';
            const slots       = parseFloat(document.querySelector('[name=slots]')?.value) || 1;
            const areaSqm     = parseFloat(document.querySelector('[name=area_sqm]')?.value) || 0;
            const sizeVal     = document.querySelector('[name=size]')?.value || '';

            let area = areaSqm;
            if (!area && sizeVal) {
                const m = sizeVal.replace(/[mM²]/g, '').match(/(\d+\.?\d*)\s*[×xX×]\s*(\d+\.?\d*)/);
                if (m) area = parseFloat(m[1]) * parseFloat(m[2]);
            }

            let projection = 0;
            if (monthlyRate > 0) {
                projection = monthlyRate; // gudang: monthly_rate sudah per bulan
            } else if (pricing) {
                switch (pricing) {
                    case 'daily_slot': projection = rate * slots * 30; break;
                    case 'daily_area': projection = rate * Math.max(1, area) * 30; break;
                    case 'monthly':    projection = rate; break;
                    case 'fixed':      projection = rate; break;
                    default:           projection = rate * 30; break;
                }
            } else {
                // cl: rate harian/m² × area × 30
                projection = rate * Math.max(1, area) * 30;
            }

            const rounded   = Math.round(projection);
            const hiddenFld = document.getElementById('projection_monthly_hidden');
            const fmtFld    = document.getElementById('projection_monthly_fmt');
            if (hiddenFld) {
                hiddenFld.value = rounded || '';
                fmtFld.value    = rounded ? rounded.toLocaleString('id-ID') : '';
                fmtFld.style.background = '#fef9c3';
                setTimeout(() => fmtFld.style.background = '', 900);
            }
        }
        </script>
        <?php endif; ?>
        <?php
    });
}

function master_sort_save(PDO $pdo, array $masterConfig): void
{
    require_permission('manage_master');
    header('Content-Type: application/json');
    $type = getv('type', '');
    $cfg  = $masterConfig[$type] ?? null;
    if (!$cfg || empty($cfg['sortable'])) {
        echo json_encode(['ok' => false, 'error' => 'not sortable']);
        exit;
    }
    $ids = json_decode(file_get_contents('php://input'), true)['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        echo json_encode(['ok' => false, 'error' => 'no ids']);
        exit;
    }
    $pid  = current_property_id();
    $stmt = $pdo->prepare('UPDATE ' . $cfg['table'] . ' SET sort_order = ? WHERE id = ? AND property_id = ?');
    foreach ($ids as $order => $id) {
        $stmt->execute([$order, (int)$id, $pid]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

function master_save(PDO $pdo, array $masterConfig): void
{
    verify_csrf();
    $type = getv('type', 'media');
    $cfg = $masterConfig[$type] ?? $masterConfig['media'];
    $id = post('id');
    $data = [];
    foreach (array_keys($cfg['fields']) as $field) {
        $data[$field] = post($field, '');
    }
    if ($type === 'pic' && isset($data['target_share'])) {
        $pct = (float) post('target_share_pct', '0');
        $data['target_share'] = $pct / 100;
    }
    if ($type === 'pic' && array_key_exists('user_id', $data)) {
        $data['user_id'] = $data['user_id'] !== '' ? (int)$data['user_id'] : null;
    }
    $data['updated_at'] = date('Y-m-d H:i:s');
    $pid = current_property_id();

    $savedId = 0;
    $priorMasterValue = null;
    $segmentMap = ['cl' => 'exhibition', 'media' => 'media', 'gudang' => 'gudang'];
    if ($id) {
        // Capture old projection_monthly before update so past months can be frozen correctly
        if (isset($segmentMap[$type])) {
            $old = $pdo->prepare('SELECT projection_monthly FROM ' . $cfg['table'] . ' WHERE id = ? AND property_id = ?');
            $old->execute([(int)$id, $pid]);
            $priorMasterValue = ($v = $old->fetchColumn()) !== false ? (float)$v : null;
        }
        $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($data)));
        $stmt = $pdo->prepare('UPDATE ' . $cfg['table'] . " SET $sets WHERE id = :id AND property_id = :property_id");
        $data['id'] = $id;
        $data['property_id'] = $pid;
        $stmt->execute($data);
        $savedId = (int) $id;
        audit($pdo, 'update', $cfg['table'], (string) $id, $data);
    } else {
        $data['property_id'] = $pid;
        $cols   = implode(', ', array_keys($data));
        $params = ':' . implode(', :', array_keys($data));
        $stmt = $pdo->prepare('INSERT INTO ' . $cfg['table'] . " ($cols) VALUES ($params)");
        $stmt->execute($data);
        $savedId = (int) $pdo->lastInsertId();
        audit($pdo, 'create', $cfg['table'], (string) $savedId, $data);
        // Slot baru: freeze bulan lalu dengan 0 agar tidak mempengaruhi histori
        if (isset($segmentMap[$type])) $priorMasterValue = 0.0;
    }
    // Snapshot potensi bulan ini saat master CL/Media/Gudang diubah
    if (isset($segmentMap[$type]) && isset($data['projection_monthly']) && $savedId > 0) {
        snapshot_potential($pdo, $segmentMap[$type], $savedId, (string) ($data['code'] ?? ''), (float) $data['projection_monthly'], $pid, $priorMasterValue);
    }

    // Auto-register period ke tabel periods saat simpan target
    if ($type === 'target' && !empty($data['period_key'])) {
        $pk = $data['period_key'];
        $lbl = period_label($pk);
        $first = $pk . '-01';
        $last  = (new DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');
        $pdo->prepare(
            'INSERT INTO periods (property_id, period_key, label, starts_on, ends_on) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE label=VALUES(label), starts_on=VALUES(starts_on), ends_on=VALUES(ends_on)'
        )->execute([$pid, $pk, $lbl, $first, $last]);
    }

    flash('Data master tersimpan.');
    redirect_to('master', ['type' => $type]);
}

function generate_periods(PDO $pdo): void
{
    verify_csrf();
    require_permission('manage_master');
    $year = (int) post('gen_year', date('Y'));
    if ($year < 2000 || $year > 2100) {
        flash('Tahun tidak valid.');
        redirect_to('master', ['type' => 'target']);
    }
    $pid  = current_property_id();
    $stmt = $pdo->prepare(
        'INSERT INTO periods (property_id, period_key, label, starts_on, ends_on) VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE label=VALUES(label), starts_on=VALUES(starts_on), ends_on=VALUES(ends_on)'
    );
    for ($m = 1; $m <= 12; $m++) {
        $pk    = sprintf('%04d-%02d', $year, $m);
        $lbl   = period_label($pk);
        $first = $pk . '-01';
        $last  = (new DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');
        $stmt->execute([$pid, $pk, $lbl, $first, $last]);
    }
    flash("12 periode tahun $year berhasil di-generate.");
    redirect_to('master', ['type' => 'target']);
}
