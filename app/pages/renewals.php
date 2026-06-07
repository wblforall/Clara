<?php
declare(strict_types=1);

/**
 * Opsi 2 — Papan Renewal Kontrak (mobile-first)
 *
 * Membaca end_date kontrak yang sudah ada di `transactions` (tidak membuat data baru),
 * fokus ke recurring (billing_method='spread') + modul CL/Exhibition & Gudang.
 * Status tindak lanjut renewal dikelola MANUAL oleh PIC.
 *
 * Ambang urgensi: 🔴 ≤15 hari (& sudah lewat) · 🟠 16–30 hari.
 */

const RENEWAL_HORIZON_DAYS = 30;   // batas atas: kontrak berakhir dalam 30 hari ke depan
const RENEWAL_CRITICAL_DAYS = 15;  // ≤15 hari = kritis (merah)
const RENEWAL_OVERDUE_DAYS = 7;    // batas bawah: hanya yang baru lewat ≤7 hari (masih dikejar)

function _renewal_statuses(): array
{
    return [
        'none'        => ['label' => 'Belum dihubungi', 'color' => '#64748b'],
        'contacted'   => ['label' => 'Sudah dihubungi', 'color' => '#2563eb'],
        'negotiating' => ['label' => 'Sedang nego',     'color' => '#d97706'],
        'will_renew'  => ['label' => 'Akan perpanjang',  'color' => '#0d9488'],
        'renewed'     => ['label' => 'Diperpanjang',     'color' => '#16a34a'],
        'churned'     => ['label' => 'Tidak lanjut',     'color' => '#dc2626'],
    ];
}

function _renewal_date_id(string $ymd): string
{
    $mn = ['01'=>'Jan','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'Mei','06'=>'Jun',
           '07'=>'Jul','08'=>'Ags','09'=>'Sep','10'=>'Okt','11'=>'Nov','12'=>'Des'];
    $ts = strtotime($ymd);
    if (!$ts) return $ymd;
    return date('d', $ts) . ' ' . ($mn[date('m', $ts)] ?? '') . ' ' . date('Y', $ts);
}

/**
 * Scope visibilitas per-PIC.
 * Role 'sales' hanya boleh melihat/mengubah kontrak milik PIC yang terhubung ke
 * akunnya (master_pic.user_id). Role lain (supervisor/finance/administrasi/admin/
 * superadmin) melihat semua.
 *
 * @return string[]|null  null = lihat semua; array nama PIC = dibatasi ke nama itu
 *                        (array kosong = sales tanpa PIC terhubung → tidak lihat apa pun)
 */
function _renewal_scope_pics(PDO $pdo, int $pid): ?array
{
    if (current_role() !== 'sales') {
        return null; // lihat semua
    }
    $uid = $_SESSION['user']['id'] ?? null;
    if (!$uid) {
        return []; // sales tanpa akun ter-link → tidak lihat apa pun
    }
    $stmt = $pdo->prepare(
        "SELECT name FROM master_pic WHERE user_id = ? AND property_id = ? AND status = 'active'"
    );
    $stmt->execute([$uid, $pid]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function renewals_page(PDO $pdo): void
{
    require_permission('view_renewals');

    $action = getv('action', 'list');
    if ($action === 'update') {
        _renewal_update($pdo);
        return;
    }
    _renewal_list($pdo);
}

// ─── UPDATE STATUS ────────────────────────────────────────────────────────────
function _renewal_update(PDO $pdo): void
{
    require_permission('manage_renewals');
    verify_csrf();

    $id     = (int) post('id', 0);
    $status = (string) post('renewal_status', 'none');
    $note   = trim((string) post('renewal_note', ''));

    if (!array_key_exists($status, _renewal_statuses())) {
        $status = 'none';
    }

    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    $before = $stmt->fetch();

    // Proteksi: sales hanya boleh mengubah kontrak milik PIC-nya sendiri
    $scopePics = _renewal_scope_pics($pdo, (int)($before['property_id'] ?? current_property_id()));
    if ($before && $scopePics !== null && !in_array($before['pic_name'] ?? '', $scopePics, true)) {
        flash('Anda tidak berhak mengubah kontrak ini.');
        redirect_to('renewals');
    }

    if ($before) {
        $actor = $_SESSION['user']['name'] ?? ($_SESSION['user']['username'] ?? null);
        $upd = $pdo->prepare(
            "UPDATE transactions
                SET renewal_status = ?, renewal_note = ?, renewal_updated_at = NOW(), renewal_updated_by = ?
              WHERE id = ?"
        );
        $upd->execute([$status, $note ?: null, $actor, $id]);

        audit(
            $pdo, 'renewal_update', 'transactions', (string)$id,
            ['renewal_status' => $status, 'renewal_note' => $note],
            ['renewal_status' => $before['renewal_status'] ?? 'none', 'renewal_note' => $before['renewal_note'] ?? ''],
            'renewals'
        );
        flash('Status renewal diperbarui.');
    }

    redirect_to('renewals');
}

// ─── LIST / BOARD ───────────────────────────────────────────────────────────────
function _renewal_list(PDO $pdo): void
{
    $pid          = current_property_id();
    $moduleFilter = getv('module', ''); // '', 'cl', 'gudang'
    $today        = date('Y-m-d');
    $horizon      = date('Y-m-d', strtotime("+" . RENEWAL_HORIZON_DAYS . " days"));
    // Batas bawah: kontrak yang baru saja lewat (masih bisa dikejar). Lebih lama
    // dari ini dianggap sudah hilang, bukan target renewal aktif.
    $floor        = date('Y-m-d', strtotime("-" . RENEWAL_OVERDUE_DAYS . " days"));

    // Filter modul: default recurring (spread) + CL + Gudang
    $moduleCond = "(t.billing_method = 'spread' OR t.module IN ('cl','gudang'))";
    if ($moduleFilter === 'cl' || $moduleFilter === 'gudang') {
        $moduleCond = "t.module = " . $pdo->quote($moduleFilter);
    }

    // Scope per-PIC: sales hanya lihat kontrak miliknya
    $scopePics = _renewal_scope_pics($pdo, $pid);
    $scopeCond = '';
    if ($scopePics !== null) {
        if (empty($scopePics)) {
            $scopeCond = " AND 1 = 0"; // sales tanpa PIC terhubung → kosong
        } else {
            $quoted    = implode(',', array_map([$pdo, 'quote'], $scopePics));
            $scopeCond = " AND t.pic_name IN ($quoted)";
        }
    }

    // Per (property, client, master_code): ambil kontrak dengan end_date TERAKHIR
    // (mewakili kontrak berjalan terkini). Status renewal menempel di baris itu.
    $sql =
        "SELECT t.id, t.module, t.master_code, t.client_id, t.property_id,
                t.start_date, t.end_date, t.contract_months, t.billing_method,
                t.final_amount, t.pic_name,
                t.renewal_status, t.renewal_note, t.renewal_updated_at, t.renewal_updated_by,
                c.company_name, c.brand_name
         FROM transactions t
         JOIN (
             SELECT t.property_id, t.client_id, t.master_code, MAX(t.end_date) AS max_end
             FROM transactions t
             WHERE t.deleted_at IS NULL AND t.client_id IS NOT NULL
               AND $moduleCond
             GROUP BY t.property_id, t.client_id, t.master_code
         ) m ON m.property_id = t.property_id
            AND m.client_id   = t.client_id
            AND m.master_code = t.master_code
            AND m.max_end     = t.end_date
         LEFT JOIN master_clients c ON c.id = t.client_id
         WHERE t.deleted_at IS NULL
           AND t.property_id = :pid
           AND $moduleCond
           AND t.end_date <= :horizon
           AND t.end_date >= :floor
           AND t.renewal_status NOT IN ('renewed','churned')
           $scopeCond
         GROUP BY t.property_id, t.client_id, t.master_code
         ORDER BY t.end_date ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':pid' => $pid, ':horizon' => $horizon, ':floor' => $floor]);
    $rows = $stmt->fetchAll();

    $todayTs = strtotime($today);
    $cards   = [];
    foreach ($rows as $r) {
        $daysLeft = (int) floor((strtotime($r['end_date']) - $todayTs) / 86400);
        $months   = max(1, (int)($r['contract_months'] ?? 0) ?: 1);
        // Nilai per bulan: spread = total/contract_months; lainnya = final_amount (per siklus)
        $perMonth = ($r['billing_method'] === 'spread')
            ? round((float)$r['final_amount'] / $months)
            : round((float)$r['final_amount']);

        $r['days_left']  = $daysLeft;
        $r['per_month']  = $perMonth;
        $r['urgency']    = $daysLeft <= RENEWAL_CRITICAL_DAYS ? 'red' : 'orange';
        $cards[] = $r;
    }

    // Statistik ringkas
    $redCards    = array_filter($cards, fn($c) => $c['urgency'] === 'red');
    $orangeCards = array_filter($cards, fn($c) => $c['urgency'] === 'orange');
    $redVal      = array_sum(array_map(fn($c) => $c['per_month'], $redCards));
    $orangeVal   = array_sum(array_map(fn($c) => $c['per_month'], $orangeCards));
    $totalVal    = $redVal + $orangeVal;

    $statuses = _renewal_statuses();

    layout('Renewal Kontrak', function () use (
        $cards, $redCards, $orangeCards, $redVal, $orangeVal, $totalVal,
        $statuses, $moduleFilter
    ) {
        $moduleLabel = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
        ?>
        <style>
          .rnw-head { margin-bottom: 14px; }
          .rnw-head h2 { margin: 0; font-size: 20px; }
          .rnw-head p { margin: 4px 0 0; color: var(--muted); font-size: 13px; }
          .rnw-stats { display: grid; grid-template-columns: repeat(3,1fr); gap: 10px; margin-bottom: 18px; }
          .rnw-stat { border-radius: 10px; padding: 12px 14px; color: #fff; }
          .rnw-stat .n { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; opacity: .9; }
          .rnw-stat .v { font-size: 18px; font-weight: 800; margin-top: 3px; line-height: 1.15; }
          .rnw-stat .c { font-size: 12px; opacity: .9; margin-top: 1px; }
          .rnw-filter { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
          .rnw-filter a { font-size: 13px; padding: 6px 14px; border-radius: 20px; border: 1px solid var(--line); text-decoration: none; color: var(--muted); }
          .rnw-filter a.active { background: var(--primary, #1e3a5f); color: #fff; border-color: var(--primary, #1e3a5f); }
          .rnw-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 14px; }
          .rnw-card { border: 1px solid var(--line); border-radius: 12px; padding: 14px 16px; background: #fff; border-left-width: 5px; }
          .rnw-card.red { border-left-color: #dc2626; }
          .rnw-card.orange { border-left-color: #d97706; }
          .rnw-card-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
          .rnw-code { font-weight: 800; font-size: 15px; }
          .rnw-badge { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 6px; background: #eef2f7; color: #475569; white-space: nowrap; }
          .rnw-client { font-size: 14px; font-weight: 600; margin-top: 6px; }
          .rnw-brand { font-size: 12px; color: var(--muted); }
          .rnw-meta { display: flex; justify-content: space-between; gap: 8px; margin-top: 10px; font-size: 13px; }
          .rnw-expire { font-weight: 700; }
          .rnw-expire.red { color: #dc2626; }
          .rnw-expire.orange { color: #d97706; }
          .rnw-perm { color: var(--muted); }
          .rnw-pic { font-size: 12px; color: var(--muted); margin-top: 2px; }
          .rnw-form { margin-top: 12px; border-top: 1px dashed var(--line); padding-top: 10px; }
          .rnw-form select, .rnw-form textarea { width: 100%; font-size: 13px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; }
          .rnw-form textarea { margin-top: 8px; resize: vertical; min-height: 38px; }
          .rnw-actions { display: flex; gap: 8px; margin-top: 10px; }
          .rnw-actions button { flex: 1; padding: 9px; font-size: 13px; font-weight: 700; border-radius: 8px; border: none; background: var(--primary, #1e3a5f); color: #fff; cursor: pointer; }
          .rnw-actions a.renew { flex: 1; text-align: center; padding: 9px; font-size: 13px; font-weight: 700; border-radius: 8px; text-decoration: none; background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
          .rnw-empty { text-align: center; padding: 56px 24px; color: var(--muted); }
          @media (max-width: 480px) {
            .rnw-stats { grid-template-columns: 1fr; }
            .rnw-cards { grid-template-columns: 1fr; }
          }
        </style>

        <div class="rnw-head">
            <h2>Renewal Kontrak</h2>
            <p>Kontrak sewa &amp; recurring yang berakhir dalam 30 hari. Tandai status tindak lanjut agar tidak ada revenue yang bocor.</p>
        </div>

        <div class="rnw-stats">
            <div class="rnw-stat" style="background:#dc2626">
                <div class="n">🔴 Kritis (≤15 hari)</div>
                <div class="v"><?= money($redVal) ?></div>
                <div class="c"><?= count($redCards) ?> kontrak / bulan</div>
            </div>
            <div class="rnw-stat" style="background:#d97706">
                <div class="n">🟠 Perlu tindak lanjut (16–30 hari)</div>
                <div class="v"><?= money($orangeVal) ?></div>
                <div class="c"><?= count($orangeCards) ?> kontrak / bulan</div>
            </div>
            <div class="rnw-stat" style="background:#1e3a5f">
                <div class="n">Total nilai at-risk</div>
                <div class="v"><?= money($totalVal) ?></div>
                <div class="c"><?= count($cards) ?> kontrak / bulan</div>
            </div>
        </div>

        <div class="rnw-filter">
            <a href="?r=renewals" class="<?= $moduleFilter === '' ? 'active' : '' ?>">Semua</a>
            <a href="?r=renewals&module=cl" class="<?= $moduleFilter === 'cl' ? 'active' : '' ?>">Exhibition</a>
            <a href="?r=renewals&module=gudang" class="<?= $moduleFilter === 'gudang' ? 'active' : '' ?>">Gudang</a>
        </div>

        <?php if (empty($cards)): ?>
            <div class="panel rnw-empty">
                🎉 Tidak ada kontrak yang berakhir dalam 30 hari ke depan.<br>
                <span style="font-size:13px">Semua kontrak aman untuk saat ini.</span>
            </div>
        <?php else: ?>
        <div class="rnw-cards">
            <?php foreach ($cards as $c):
                $st        = $statuses[$c['renewal_status']] ?? $statuses['none'];
                $daysLeft  = (int) $c['days_left'];
                $expireTxt = $daysLeft < 0
                    ? 'Lewat ' . abs($daysLeft) . ' hari'
                    : ($daysLeft === 0 ? 'Berakhir hari ini' : $daysLeft . ' hari lagi');
                $canManage = can('manage_renewals');
            ?>
            <div class="rnw-card <?= $c['urgency'] ?>">
                <div class="rnw-card-top">
                    <div class="rnw-code"><?= h($c['master_code']) ?></div>
                    <span class="rnw-badge">
                        <?= $c['billing_method'] === 'spread' ? 'Recurring' : ($moduleLabel[$c['module']] ?? strtoupper($c['module'])) ?>
                    </span>
                </div>
                <div class="rnw-client"><?= h($c['company_name'] ?? '—') ?></div>
                <?php if (!empty($c['brand_name'])): ?>
                    <div class="rnw-brand"><?= h($c['brand_name']) ?></div>
                <?php endif; ?>

                <div class="rnw-meta">
                    <div>
                        <div class="rnw-expire <?= $c['urgency'] ?>">
                            <?= _renewal_date_id($c['end_date']) ?> · <?= $expireTxt ?>
                        </div>
                        <div class="rnw-pic">PIC: <?= h($c['pic_name'] ?? '—') ?></div>
                    </div>
                    <div class="rnw-perm" style="text-align:right">
                        <div style="font-weight:700;color:#111"><?= money($c['per_month']) ?></div>
                        <div style="font-size:11px">/ bulan</div>
                    </div>
                </div>

                <?php if ($canManage): ?>
                <form class="rnw-form" method="post" action="?r=renewals&action=update">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                    <select name="renewal_status">
                        <?php foreach ($statuses as $key => $meta): ?>
                            <option value="<?= $key ?>" <?= $c['renewal_status'] === $key ? 'selected' : '' ?>>
                                <?= h($meta['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <textarea name="renewal_note" placeholder="Catatan tindak lanjut (opsional)..."><?= h($c['renewal_note'] ?? '') ?></textarea>
                    <div class="rnw-actions">
                        <button type="submit">Simpan</button>
                        <a class="renew" href="?r=transaction_form&module=<?= h($c['module']) ?>&renew_from=<?= (int)$c['id'] ?>" target="_blank" rel="noopener">+ Perpanjang</a>
                    </div>
                </form>
                <?php else: ?>
                <div class="rnw-form">
                    <span class="rnw-badge" style="background:<?= $st['color'] ?>1a;color:<?= $st['color'] ?>">
                        <?= h($st['label']) ?>
                    </span>
                    <?php if (!empty($c['renewal_note'])): ?>
                        <div style="font-size:12px;color:var(--muted);margin-top:6px"><?= h($c['renewal_note']) ?></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php
    });
}
