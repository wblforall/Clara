<?php
declare(strict_types=1);

/**
 * Laporan Stock counter — replika form kertas "LAPORAN STOCK"
 * (brand TRISET / WATCHOUT / TRISET KIDS).
 *
 * Halaman lembarnya (?r=stock_sheet) dirender sebagai KANVAS: kertas berukuran
 * tetap 1000px yang bisa dicubit (pinch) untuk zoom dan digeser, supaya bisa
 * diisi langsung dari HP tanpa kehilangan bentuk kertasnya.
 *
 * Isian TIDAK diketik di atas kertas (ukuran selnya kecil sekali di HP);
 * setiap sel disentuh → muncul panel di bawah layar dengan tombol besar.
 *
 * Logika stok mengikuti kertasnya:
 *   stock awal  = jumlah turus semua ukuran (36..41) pada baris itu
 *   tiap laku   = 1 turus dicoret + tanggal ditulis merah  (stock_report_sales)
 *   stock akhir = stock awal - jumlah penjualan            (selalu dihitung)
 */

/** Kolom ukuran di kertas, sesuai urutan header. */
function stock_sizes(): array
{
    return ['36', '37', '38', '39', '40', '41'];
}

function stock_brands(): array
{
    return ['TRISET', 'WATCHOUT', 'TRISET KIDS'];
}

/**
 * Buat tabel bila belum ada. Migrasi 042 sudah menyiapkan DDL-nya; ini hanya
 * jaring pengaman supaya menu langsung bisa dipakai di server yang belum
 * sempat menjalankan `php db_migrate.php`.
 */
function stock_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    if ($pdo->query("SHOW TABLES LIKE 'stock_report_sales'")->fetchColumn()) return;
    require APP_ROOT . '/database/migrations/042_create_stock_reports.php';
}

/** Ambil laporan + pastikan masih dalam properti yang boleh diakses user. */
function stock_report_find(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM stock_reports WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return null;
    if (!in_array((int) $row['property_id'], allowed_property_ids(), true)) return null;
    return $row;
}

/** Rapikan sort_order jadi 10, 20, 30, … (dipakai sebelum sisip/pindah baris). */
function stock_renumber(PDO $pdo, int $reportId): void
{
    $ids = $pdo->prepare('SELECT id FROM stock_report_rows WHERE report_id = ? ORDER BY sort_order, id');
    $ids->execute([$reportId]);
    $up = $pdo->prepare('UPDATE stock_report_rows SET sort_order = ? WHERE id = ?');
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $i => $rid) {
        $up->execute([($i + 1) * 10, $rid]);
    }
}

/** Bentuk state lengkap satu laporan (dipakai halaman lembar & balasan API). */
function stock_sheet_state(PDO $pdo, array $rep): array
{
    $sizes = stock_sizes();
    $rid   = (int) $rep['id'];

    $st = $pdo->prepare('SELECT * FROM stock_report_rows WHERE report_id = ? ORDER BY sort_order, id');
    $st->execute([$rid]);
    $rawRows = $st->fetchAll();

    $st = $pdo->prepare('SELECT id, row_id, size_code, sold_day FROM stock_report_sales WHERE report_id = ? ORDER BY sold_day, id');
    $st->execute([$rid]);
    $salesByRow = [];
    foreach ($st->fetchAll() as $s) {
        $salesByRow[(int) $s['row_id']][(string) $s['size_code']][] = [
            'id'  => (int) $s['id'],
            'day' => (int) $s['sold_day'],
        ];
    }

    $rows = [];
    foreach ($rawRows as $r) {
        $id  = (int) $r['id'];
        $q   = [];
        foreach ($sizes as $s) $q[$s] = (int) ($r['q' . $s] ?? 0);
        $rows[] = [
            'id'         => $id,
            'row_type'   => $r['row_type'],
            'group_code' => (string) ($r['group_code'] ?? ''),
            'group_text' => (string) ($r['group_text'] ?? ''),
            'artikel'    => (string) ($r['artikel'] ?? ''),
            'warna'      => (string) ($r['warna'] ?? ''),
            'q'          => $q,
            'harga'      => $r['harga'] !== null ? (float) $r['harga'] : null,
            'keterangan' => (string) ($r['keterangan'] ?? ''),
            'sales'      => $salesByRow[$id] ?? new stdClass(),
        ];
    }

    return [
        'report' => [
            'id'            => $rid,
            'counter_name'  => (string) $rep['counter_name'],
            'brand'         => (string) $rep['brand'],
            'period_key'    => (string) $rep['period_key'],
            'period_label'  => strtoupper(period_label((string) $rep['period_key'])),
            'tipe'          => (string) $rep['tipe'],
            'barang_datang' => (int) $rep['barang_datang'],
            'retur'         => (int) $rep['retur'],
            'discount_note' => (string) ($rep['discount_note'] ?? ''),
            'note'          => (string) ($rep['note'] ?? ''),
        ],
        'rows' => $rows,
    ];
}

// ─── Daftar laporan ───────────────────────────────────────────────────────────

function stock_reports_page(PDO $pdo): void
{
    stock_ensure_schema($pdo);
    $pid = current_property_id();

    $st = $pdo->prepare(
        "SELECT r.*,
                (SELECT COALESCE(SUM(w.q36+w.q37+w.q38+w.q39+w.q40+w.q41), 0)
                   FROM stock_report_rows w WHERE w.report_id = r.id AND w.row_type = 'item') awal,
                (SELECT COUNT(*) FROM stock_report_sales s WHERE s.report_id = r.id) laku
         FROM stock_reports r
         WHERE r.property_id = ?
         ORDER BY r.period_key DESC, r.counter_name"
    );
    $st->execute([$pid]);
    $reports = $st->fetchAll();

    layout('Laporan Stock', function () use ($reports) {
        ?>
        <div class="toolbar" style="flex-wrap:wrap;gap:8px">
            <?php if (can('manage_stock_report')): ?>
                <a class="btn" href="?r=stock_report_form">Buat Laporan Stock</a>
            <?php endif; ?>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Nama Counter</th><th>Brand</th><th>Periode</th><th>Tipe</th>
                        <th style="text-align:right">Barang Datang</th>
                        <th style="text-align:right">Stock Awal</th>
                        <th style="text-align:right">Terjual</th>
                        <th style="text-align:right">Stock Akhir</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($reports as $r): $awal = (int) $r['awal']; $laku = (int) $r['laku']; ?>
                    <tr>
                        <td><a href="?r=stock_sheet&id=<?= (int) $r['id'] ?>" style="font-weight:600;color:var(--primary,#0d9488)"><?= h($r['counter_name']) ?></a></td>
                        <td><?= h($r['brand']) ?></td>
                        <td><?= h(period_label((string) $r['period_key'])) ?></td>
                        <td><?= h($r['tipe']) ?></td>
                        <td style="text-align:right"><?= number_format((float) $r['barang_datang'], 0, ',', '.') ?></td>
                        <td style="text-align:right"><?= number_format($awal, 0, ',', '.') ?></td>
                        <td style="text-align:right"><?= number_format($laku, 0, ',', '.') ?></td>
                        <td style="text-align:right;font-weight:700"><?= number_format($awal - $laku, 0, ',', '.') ?></td>
                        <td>
                            <a class="btn light" href="?r=stock_sheet&id=<?= (int) $r['id'] ?>">Buka Lembar</a>
                            <?php if (can('manage_stock_report')): ?>
                                <a class="btn light" href="?r=stock_report_form&id=<?= (int) $r['id'] ?>">Edit</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$reports): ?>
                    <tr><td colspan="9" class="muted" style="text-align:center">Belum ada laporan stock.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    });
}

// ─── Form kepala laporan ──────────────────────────────────────────────────────

function stock_report_form(PDO $pdo): void
{
    require_permission('manage_stock_report');
    stock_ensure_schema($pdo);

    $id  = (int) getv('id', 0);
    $row = [
        'counter_name' => '', 'brand' => 'TRISET', 'period_key' => date('Y-m'),
        'tipe' => 'NORMAL', 'barang_datang' => 0, 'retur' => 0, 'discount_note' => '', 'note' => '',
    ];
    if ($id) {
        $found = stock_report_find($pdo, $id);
        if (!$found) { flash('Laporan tidak ditemukan.'); redirect_to('stock_reports'); }
        $row = $found;
    }

    layout(($id ? 'Edit' : 'Buat') . ' Laporan Stock', function () use ($row, $id) {
        ?>
        <form class="panel" method="post" action="?r=stock_report_save">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <div class="form-grid">
                <label>Nama Counter
                    <input name="counter_name" required maxlength="120" value="<?= field($row, 'counter_name') ?>" placeholder="TRISET SHOES">
                </label>
                <label>Brand
                    <select name="brand">
                        <?php foreach (stock_brands() as $b): ?>
                            <option value="<?= h($b) ?>" <?= ($row['brand'] ?? '') === $b ? 'selected' : '' ?>><?= h($b) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Periode
                    <input type="month" name="period_key" required value="<?= field($row, 'period_key') ?>">
                </label>
                <label>Tipe
                    <select name="tipe">
                        <?php foreach (['NORMAL', 'DISCOUNT'] as $t): ?>
                            <option value="<?= h($t) ?>" <?= ($row['tipe'] ?? '') === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Barang Datang
                    <input type="number" name="barang_datang" min="0" value="<?= field($row, 'barang_datang', '0') ?>">
                </label>
                <label>Retur
                    <input type="number" name="retur" min="0" value="<?= field($row, 'retur', '0') ?>">
                </label>
                <label class="wide">Discount
                    <input name="discount_note" maxlength="120" value="<?= field($row, 'discount_note') ?>" placeholder="Normal/..../..../....">
                </label>
                <label class="wide">Catatan
                    <textarea name="note" rows="2"><?= field($row, 'note') ?></textarea>
                </label>
            </div>
            <p style="margin-top:14px;display:flex;gap:8px;align-items:center">
                <button type="submit">Simpan</button>
                <a class="btn light" href="?r=stock_reports">Batal</a>
                <?php if ($id): ?>
                    <a class="btn warn" style="margin-left:auto" href="?r=stock_report_delete&id=<?= (int) $id ?>"
                       onclick="return confirm('Hapus laporan ini beserta seluruh isinya?')">Hapus Laporan</a>
                <?php endif; ?>
            </p>
            <?php if (!$id): ?>
                <p class="muted" style="font-size:12.5px;margin-top:10px">
                    Setelah disimpan, lembar kertasnya langsung terbuka dan siap diisi — sudah tersedia baris kosong,
                    tinggal sentuh selnya untuk mengisi.
                </p>
            <?php endif; ?>
        </form>
        <?php
    });
}

function stock_report_save(PDO $pdo): void
{
    require_permission('manage_stock_report');
    verify_csrf();
    stock_ensure_schema($pdo);

    $id     = (int) post('id', 0);
    $brand  = in_array(post('brand'), stock_brands(), true) ? post('brand') : 'TRISET';
    $tipe   = post('tipe') === 'DISCOUNT' ? 'DISCOUNT' : 'NORMAL';
    $period = (string) post('period_key', date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = date('Y-m');
    $counter = trim((string) post('counter_name', ''));
    if ($counter === '') { flash('Nama counter wajib diisi.'); redirect_to('stock_report_form', $id ? ['id' => $id] : []); }

    $data = [
        mb_substr($counter, 0, 120), $brand, $period, $tipe,
        max(0, (int) post('barang_datang', 0)), max(0, (int) post('retur', 0)),
        mb_substr(trim((string) post('discount_note', '')), 0, 120),
        trim((string) post('note', '')),
    ];
    $user = $_SESSION['user']['name'] ?? '';

    if ($id) {
        $rep = stock_report_find($pdo, $id);
        if (!$rep) { flash('Laporan tidak ditemukan.'); redirect_to('stock_reports'); }
        $st = $pdo->prepare(
            'UPDATE stock_reports SET counter_name=?, brand=?, period_key=?, tipe=?, barang_datang=?, retur=?,
                    discount_note=?, note=?, updated_by=?, updated_at=NOW() WHERE id=?'
        );
        $st->execute([...$data, $user, $id]);
        audit($pdo, 'update', 'stock_reports', (string) $id, ['counter' => $counter, 'period' => $period], $rep, 'stock_report');
    } else {
        $st = $pdo->prepare(
            'INSERT INTO stock_reports (property_id, counter_name, brand, period_key, tipe, barang_datang, retur,
                    discount_note, note, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([current_property_id(), ...$data, $user]);
        $id = (int) $pdo->lastInsertId();

        // Baris kosong awal supaya lembarnya langsung terasa seperti kertas.
        $ins = $pdo->prepare("INSERT INTO stock_report_rows (report_id, sort_order, row_type) VALUES (?, ?, 'item')");
        for ($i = 1; $i <= 15; $i++) $ins->execute([$id, $i * 10]);

        audit($pdo, 'create', 'stock_reports', (string) $id, ['counter' => $counter, 'period' => $period], [], 'stock_report');
    }

    flash('Laporan stock tersimpan.');
    redirect_to('stock_sheet', ['id' => $id]);
}

function stock_report_delete(PDO $pdo): void
{
    require_permission('manage_stock_report');
    stock_ensure_schema($pdo);

    $id  = (int) getv('id', 0);
    $rep = stock_report_find($pdo, $id);
    if (!$rep) { flash('Laporan tidak ditemukan.'); redirect_to('stock_reports'); }

    $pdo->prepare('DELETE FROM stock_report_sales WHERE report_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM stock_report_rows  WHERE report_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM stock_reports      WHERE id = ?')->execute([$id]);
    audit($pdo, 'delete', 'stock_reports', (string) $id, [], $rep, 'stock_report');

    flash('Laporan stock dihapus.');
    redirect_to('stock_reports');
}

// ─── API lembar (dipanggil dari halaman kanvas, balasan JSON) ─────────────────

function stock_sheet_api(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    $fail = function (string $msg): void {
        echo json_encode(['ok' => false, 'error' => $msg]);
        exit;
    };

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')                                        $fail('Metode tidak didukung.');
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) post('_csrf', '')))         $fail('Sesi kedaluwarsa. Muat ulang halaman.');
    if (!can('manage_stock_report'))                                                          $fail('Anda tidak punya akses mengubah laporan stock.');

    stock_ensure_schema($pdo);
    $rep = stock_report_find($pdo, (int) post('report_id', 0));
    if (!$rep) $fail('Laporan tidak ditemukan.');

    $reportId = (int) $rep['id'];
    $sizes    = stock_sizes();
    $user     = $_SESSION['user']['name'] ?? '';

    /** Ambil baris milik laporan ini saja (cegah menyentuh laporan lain). */
    $rowOf = function (int $rowId) use ($pdo, $reportId, $fail): array {
        $st = $pdo->prepare('SELECT * FROM stock_report_rows WHERE id = ? AND report_id = ?');
        $st->execute([$rowId, $reportId]);
        $r = $st->fetch();
        if (!$r) $fail('Baris tidak ditemukan.');
        return $r;
    };

    switch ((string) post('action', '')) {

        case 'header_update': {
            $field = (string) post('field', '');
            $value = (string) post('value', '');
            $allowed = ['counter_name', 'brand', 'period_key', 'tipe', 'barang_datang', 'retur', 'discount_note', 'note'];
            if (!in_array($field, $allowed, true)) $fail('Field tidak dikenal.');

            if ($field === 'brand' && !in_array($value, stock_brands(), true))       $fail('Brand tidak dikenal.');
            if ($field === 'tipe')       $value = $value === 'DISCOUNT' ? 'DISCOUNT' : 'NORMAL';
            if ($field === 'period_key' && !preg_match('/^\d{4}-\d{2}$/', $value))   $fail('Format periode harus YYYY-MM.');
            if ($field === 'barang_datang' || $field === 'retur') $value = (string) max(0, (int) $value);
            if ($field === 'counter_name') {
                $value = mb_substr(trim($value), 0, 120);
                if ($value === '') $fail('Nama counter tidak boleh kosong.');
            }
            if ($field === 'discount_note') $value = mb_substr(trim($value), 0, 120);
            if ($field === 'note')          $value = mb_substr(trim($value), 0, 2000);

            $pdo->prepare("UPDATE stock_reports SET `$field` = ?, updated_by = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$value, $user, $reportId]);
            $rep[$field] = $value;
            break;
        }

        case 'row_update': {
            $row   = $rowOf((int) post('row_id', 0));
            $field = (string) post('field', '');
            $value = (string) post('value', '');
            $lim   = ['artikel' => 40, 'warna' => 30, 'keterangan' => 190, 'group_code' => 20, 'group_text' => 255];
            if ($field === 'harga') {
                $harga = trim($value) === '' ? null : parse_rupiah($value);
                $pdo->prepare('UPDATE stock_report_rows SET harga = ? WHERE id = ?')->execute([$harga, $row['id']]);
                break;
            }
            if (!isset($lim[$field])) $fail('Field tidak dikenal.');
            $value = mb_substr(trim($value), 0, $lim[$field]);
            $pdo->prepare("UPDATE stock_report_rows SET `$field` = ? WHERE id = ?")->execute([$value, $row['id']]);
            break;
        }

        case 'qty_set': {
            $row  = $rowOf((int) post('row_id', 0));
            $size = (string) post('size', '');
            if (!in_array($size, $sizes, true)) $fail('Ukuran tidak dikenal.');
            $qty = max(0, min(99, (int) post('qty', 0)));

            // Turus tidak boleh lebih sedikit dari yang sudah tercatat laku —
            // kalau tidak, stock akhir jadi minus dan catatan tanggalnya menggantung.
            $st = $pdo->prepare('SELECT COUNT(*) FROM stock_report_sales WHERE row_id = ? AND size_code = ?');
            $st->execute([$row['id'], $size]);
            $sold = (int) $st->fetchColumn();
            if ($qty < $sold) $fail("Ukuran $size sudah laku $sold pasang. Hapus dulu catatan lakunya.");

            $pdo->prepare("UPDATE stock_report_rows SET `q$size` = ? WHERE id = ?")->execute([$qty, $row['id']]);
            break;
        }

        case 'sale_add': {
            $row  = $rowOf((int) post('row_id', 0));
            $size = (string) post('size', '');
            if (!in_array($size, $sizes, true)) $fail('Ukuran tidak dikenal.');

            $day  = (int) post('day', 0);
            $days = (int) date('t', (int) strtotime($rep['period_key'] . '-01'));
            if ($day < 1 || $day > $days) $fail('Tanggal di luar periode laporan.');

            $st = $pdo->prepare('SELECT COUNT(*) FROM stock_report_sales WHERE row_id = ? AND size_code = ?');
            $st->execute([$row['id'], $size]);
            if ((int) $st->fetchColumn() >= (int) $row['q' . $size]) $fail("Stok ukuran $size sudah habis.");

            $pdo->prepare('INSERT INTO stock_report_sales (report_id, row_id, size_code, sold_day, created_by) VALUES (?,?,?,?,?)')
                ->execute([$reportId, $row['id'], $size, $day, $user]);
            break;
        }

        case 'sale_delete': {
            $pdo->prepare('DELETE FROM stock_report_sales WHERE id = ? AND report_id = ?')
                ->execute([(int) post('sale_id', 0), $reportId]);
            break;
        }

        case 'row_add': {
            $type   = post('row_type') === 'group' ? 'group' : 'item';
            $afterId = (int) post('after_row_id', 0);
            stock_renumber($pdo, $reportId);

            $order = null;
            if ($afterId) {
                $after = $rowOf($afterId);
                $order = (int) $after['sort_order'] + 5;   // sisip tepat di bawahnya
            } else {
                $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM stock_report_rows WHERE report_id = ?');
                $st->execute([$reportId]);
                $order = (int) $st->fetchColumn();
            }
            $pdo->prepare('INSERT INTO stock_report_rows (report_id, sort_order, row_type) VALUES (?,?,?)')
                ->execute([$reportId, $order, $type]);
            stock_renumber($pdo, $reportId);
            break;
        }

        case 'row_delete': {
            $row = $rowOf((int) post('row_id', 0));
            $pdo->prepare('DELETE FROM stock_report_sales WHERE row_id = ?')->execute([$row['id']]);
            $pdo->prepare('DELETE FROM stock_report_rows WHERE id = ?')->execute([$row['id']]);
            break;
        }

        case 'row_type_set': {
            $row  = $rowOf((int) post('row_id', 0));
            $type = post('row_type') === 'group' ? 'group' : 'item';
            $pdo->prepare('UPDATE stock_report_rows SET row_type = ? WHERE id = ?')->execute([$type, $row['id']]);
            break;
        }

        case 'row_move': {
            $row = $rowOf((int) post('row_id', 0));
            $dir = post('dir') === 'up' ? 'up' : 'down';
            stock_renumber($pdo, $reportId);

            $st = $pdo->prepare('SELECT sort_order FROM stock_report_rows WHERE id = ?');
            $st->execute([$row['id']]);
            $cur = (int) $st->fetchColumn();

            $sql = $dir === 'up'
                ? 'SELECT id, sort_order FROM stock_report_rows WHERE report_id = ? AND sort_order < ? ORDER BY sort_order DESC LIMIT 1'
                : 'SELECT id, sort_order FROM stock_report_rows WHERE report_id = ? AND sort_order > ? ORDER BY sort_order ASC LIMIT 1';
            $st = $pdo->prepare($sql);
            $st->execute([$reportId, $cur]);
            $nb = $st->fetch();
            if ($nb) {
                $up = $pdo->prepare('UPDATE stock_report_rows SET sort_order = ? WHERE id = ?');
                $up->execute([(int) $nb['sort_order'], $row['id']]);
                $up->execute([$cur, (int) $nb['id']]);
            }
            break;
        }

        default:
            $fail('Aksi tidak dikenal.');
    }

    $pdo->prepare('UPDATE stock_reports SET updated_by = ?, updated_at = NOW() WHERE id = ?')->execute([$user, $reportId]);
    echo json_encode(['ok' => true] + stock_sheet_state($pdo, $rep), JSON_UNESCAPED_UNICODE);
}

// ─── Halaman lembar (kanvas kertas) ──────────────────────────────────────────

function stock_sheet_page(PDO $pdo): void
{
    stock_ensure_schema($pdo);

    $rep = stock_report_find($pdo, (int) getv('id', 0));
    if (!$rep) { flash('Laporan tidak ditemukan.'); redirect_to('stock_reports'); }

    $state            = stock_sheet_state($pdo, $rep);
    $state['sizes']   = stock_sizes();
    $state['brands']  = stock_brands();
    $state['canEdit'] = can('manage_stock_report');
    $state['csrf']    = csrf_token();
    $isPrint          = (string) getv('print', '') !== '';

    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    if ($isPrint) {
        // Versi cetak: tanpa kerangka aplikasi, kertas dirender apa adanya.
        ?>
        <!doctype html>
        <html lang="id">
        <head>
            <meta charset="utf-8">
            <title>Laporan Stock — <?= h($rep['counter_name']) ?> <?= h(period_label((string) $rep['period_key'])) ?></title>
            <style>
                body { margin: 0; background: #fff; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
                <?php stock_sheet_css(); ?>
                .sk-paper { position: static; box-shadow: none; margin: 0 auto; }
                @page { size: A4 portrait; margin: 8mm; }
            </style>
        </head>
        <body>
            <div class="sk-paper no-mobile-cards" id="paper"></div>
            <script>window.__SHEET__ = <?= $json ?>; window.__SHEET__.printMode = true;</script>
            <script><?php stock_sheet_js(); ?></script>
        </body>
        </html>
        <?php
        return;
    }

    layout('Laporan Stock — ' . $rep['counter_name'], function () use ($state, $json, $rep) {
        ?>
        <style><?php stock_sheet_css(); ?></style>

        <div class="sk-bar">
            <a class="btn light" href="?r=stock_reports">&larr; Daftar</a>
            <div class="sk-zoom">
                <button type="button" class="btn light" data-zoom="out" title="Perkecil">&minus;</button>
                <button type="button" class="btn light" data-zoom="fit" title="Muat layar">Fit</button>
                <button type="button" class="btn light" data-zoom="in" title="Perbesar">+</button>
            </div>
            <?php if ($state['canEdit']): ?>
                <button type="button" class="btn light" data-add="item">+ Baris</button>
                <button type="button" class="btn light" data-add="group">+ Grup</button>
            <?php endif; ?>
            <a class="btn light" href="?r=stock_sheet&id=<?= (int) $rep['id'] ?>&print=1" target="_blank" rel="noopener">Cetak</a>
            <span class="sk-save" id="skSave"></span>
        </div>

        <div class="sk-canvas" id="canvas">
            <div class="sk-paper no-mobile-cards" id="paper"></div>
        </div>
        <p class="muted sk-hint">Cubit layar untuk zoom, geser untuk berpindah. Sentuh sel mana pun untuk mengisinya.</p>

        <div class="sk-bs" id="bs" hidden>
            <div class="sk-bs-bd" data-close="1"></div>
            <div class="sk-bs-pn" role="dialog" aria-modal="true">
                <div class="sk-bs-hd"><b id="bsTitle"></b><button type="button" class="sk-x" data-close="1" aria-label="Tutup">&times;</button></div>
                <div class="sk-bs-bd2" id="bsBody"></div>
            </div>
        </div>

        <script>window.__SHEET__ = <?= $json ?>;</script>
        <script><?php stock_sheet_js(); ?></script>
        <?php
    });
}

/** CSS kertas + kanvas + panel isian (dipakai halaman lembar & versi cetak). */
function stock_sheet_css(): void
{
    ?>
    /* ── toolbar & kanvas ─────────────────────────────────────────────── */
    .sk-bar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
    .sk-bar .btn { font-size:12.5px; padding:7px 12px; }
    .sk-zoom { display:flex; gap:4px; }
    .sk-zoom .btn { min-width:40px; text-align:center; font-weight:700; }
    .sk-save { font-size:12px; color:var(--muted,#7B8A9C); margin-left:auto; }
    .sk-save.err { color:#dc2626; font-weight:700; }
    .sk-hint { font-size:12px; margin-top:8px; text-align:center; }

    .sk-canvas {
        position:relative; overflow:hidden; background:#4B5565; border-radius:12px;
        height:min(74vh, 820px); min-height:380px;
        touch-action:none; overscroll-behavior:contain;
        -webkit-user-select:none; user-select:none; -webkit-tap-highlight-color:transparent;
    }
    .sk-paper {
        position:absolute; top:0; left:0; transform-origin:0 0; will-change:transform;
        width:1000px; padding:26px 26px 34px; background:#fff; color:#0F1623;
        box-shadow:0 10px 34px rgba(0,0,0,.35);
    }

    /* ── kepala kertas ────────────────────────────────────────────────── */
    .pp-title { text-align:center; font-size:27px; font-weight:800; letter-spacing:.6px; }
    .pp-brand { text-align:center; font-size:15px; margin-top:5px; letter-spacing:.3px; }
    .pp-brand s { color:#9AA5B4; text-decoration-color:#dc2626; }
    .pp-brand b { font-weight:800; }
    .pp-coret { text-align:center; font-size:12px; color:#64748B; margin-top:2px; }
    .pp-hd { display:grid; grid-template-columns:1fr 200px 300px; gap:14px; margin-top:16px; font-size:15px; align-items:start; }
    .pp-f { display:flex; align-items:flex-end; gap:8px; margin-bottom:8px; }
    .pp-f > span.lb { flex:none; }
    .pp-f > span.lb.w { width:112px; }
    .pp-f .val { flex:1; min-height:21px; border-bottom:1px dotted #64748B; font-weight:700; padding:0 4px; }
    .pp-tipe { align-self:center; text-align:center; font-size:22px; font-weight:800; }
    .pp-auto { color:#0A7267; }

    /* ── tabel kertas ─────────────────────────────────────────────────── */
    /* Semua sifat ditulis eksplisit: tabel ini adalah KERTAS, bukan tabel data,
       jadi ia tidak boleh ikut gaya tabel aplikasi maupun mode kartu di HP. */
    table.pp { width:100%; border-collapse:collapse; table-layout:fixed; margin-top:16px; font-size:14px; background:#fff; display:table; min-width:0; }
    table.pp thead { display:table-header-group; }
    table.pp tbody { display:table-row-group; }
    table.pp tr { display:table-row; background:none; border:none; border-radius:0; padding:0; margin:0; box-shadow:none; }
    table.pp th, table.pp td {
        display:table-cell; border:1px solid #0F1623; padding:1px 4px; height:27px;
        vertical-align:middle; color:#0F1623; text-align:left; white-space:normal; background:none;
    }
    table.pp th { font-size:10.5px; font-weight:700; text-align:center; letter-spacing:.2px; text-transform:none; }
    table.pp td::before, table.pp th::before { content:none; }
    table.pp tbody tr:hover td { background:none; }
    table.pp td.c-art, table.pp td.c-wrn { text-align:center; font-weight:600; }
    table.pp td.c-sz { padding:1px 3px; line-height:1; }
    table.pp td.c-num { text-align:center; font-weight:700; }
    table.pp td.c-hrg, table.pp td.c-ket { text-align:center; font-size:12.5px; }
    table.pp tr.r-grp td { background:#F1F5F9; font-weight:800; text-align:center; height:29px; font-size:14px; letter-spacing:0; text-transform:none; }
    table.pp tr.r-grp td.c-long { text-align:left; font-weight:600; }
    table.pp tr.r-gt td { font-weight:800; text-align:center; height:30px; letter-spacing:.5px; font-size:14px; text-transform:none; background:none; }
    .tap { cursor:pointer; }
    .tap:active { background:#E6F7F5; }

    /* turus: batang tegak; yang laku dicoret merah + tanggalnya */
    .tk { display:inline-block; position:relative; width:6px; height:15px; margin-right:2px; vertical-align:middle; }
    .tk::before { content:''; position:absolute; left:2px; top:1px; width:2px; height:13px; background:#0F1623; border-radius:1px; }
    .tk.x::before { background:#94A3B8; }
    .tk.x::after { content:''; position:absolute; left:-2px; top:6px; width:10px; height:1.5px; background:#DC2626; transform:rotate(-25deg); }
    .dy { font-size:9.5px; font-weight:800; color:#DC2626; margin-right:3px; vertical-align:middle; }

    /* ── panel isian bawah ────────────────────────────────────────────── */
    .sk-bs { position:fixed; inset:0; z-index:200; }
    .sk-bs-bd { position:absolute; inset:0; background:rgba(15,22,35,.45); }
    .sk-bs-pn {
        position:absolute; left:0; right:0; bottom:0; background:#fff;
        border-radius:16px 16px 0 0; box-shadow:0 -8px 30px rgba(0,0,0,.25);
        max-height:82vh; display:flex; flex-direction:column;
        padding-bottom:env(safe-area-inset-bottom, 0px);
        animation:skUp .18s ease-out;
    }
    @keyframes skUp { from { transform:translateY(18px); opacity:.4; } to { transform:none; opacity:1; } }
    .sk-bs-hd { display:flex; align-items:center; gap:10px; padding:13px 16px; border-bottom:1px solid var(--line,#E4E9F0); }
    .sk-bs-hd b { font-size:14.5px; }
    .sk-x { margin-left:auto; background:none; border:none; box-shadow:none; color:var(--muted,#7B8A9C); font-size:24px; line-height:1; padding:0 4px; cursor:pointer; }
    .sk-bs-bd2 { padding:14px 16px 20px; overflow:auto; }
    .sk-bs-bd2 label { display:block; font-size:12px; font-weight:700; color:var(--ink2,#3D4A5C); margin-bottom:12px; }
    .sk-bs-bd2 input, .sk-bs-bd2 select, .sk-bs-bd2 textarea {
        width:100%; font-size:16px; padding:11px 12px; margin-top:5px;
        border:1px solid var(--line,#E4E9F0); border-radius:10px; background:#fff; color:inherit;
    }
    .sk-row { display:flex; gap:8px; flex-wrap:wrap; }
    .sk-row .btn { flex:1; min-width:110px; text-align:center; }
    .sk-step { display:flex; align-items:center; gap:12px; margin:6px 0 16px; }
    .sk-step button { width:52px; height:52px; font-size:24px; font-weight:800; border-radius:14px; }
    .sk-step .n { flex:1; text-align:center; font-size:32px; font-weight:800; }
    .sk-lbl { font-size:12px; font-weight:700; color:var(--ink2,#3D4A5C); margin:14px 0 7px; }
    .sk-days { display:grid; grid-template-columns:repeat(7, 1fr); gap:6px; }
    .sk-days button { padding:0; height:42px; font-size:15px; font-weight:700; border-radius:10px; }
    .sk-days button.today { outline:2px solid var(--primary,#0D9488); outline-offset:1px; }
    .sk-chips { display:flex; flex-wrap:wrap; gap:7px; }
    .sk-chip {
        display:inline-flex; align-items:center; gap:7px; background:#FEE2E2; color:#991B1B;
        border:1px solid #FCA5A5; border-radius:999px; padding:7px 8px 7px 13px; font-size:13px; font-weight:700;
    }
    .sk-chip button { background:none; border:none; box-shadow:none; color:#991B1B; font-size:17px; line-height:1; padding:0 3px; cursor:pointer; }
    .sk-sum { display:flex; gap:14px; font-size:12.5px; color:var(--muted,#7B8A9C); margin-bottom:6px; }
    .sk-sum b { color:var(--ink,#0F1623); font-size:15px; display:block; }

    @media (max-width: 640px) {
        .sk-canvas { height:min(66vh, 620px); }
        /* Satu baris yang bisa digeser: tombol tidak melipat ke bawah sehingga
           tinggi layar tetap milik kertasnya. */
        .sk-bar { flex-wrap:nowrap; overflow-x:auto; -webkit-overflow-scrolling:touch; padding-bottom:3px; }
        .sk-bar .btn { flex:0 0 auto; font-size:12px; padding:7px 10px; }
        .sk-save { flex:0 0 auto; margin-left:6px; }
    }
    @media print {
        .sk-bar, .sk-hint, .sk-bs, .sidebar, .topbar, .m-nav, .m-top { display:none !important; }
        .sk-canvas { overflow:visible; height:auto; background:none; border-radius:0; }
        .sk-paper { position:static; transform:none !important; box-shadow:none; width:100%; padding:0; }
    }
    <?php
}

/** Renderer + kanvas zoom/geser + panel isian. */
function stock_sheet_js(): void
{
    ?>
(function () {
    var S      = window.__SHEET__;
    var SIZES  = S.sizes;
    var paper  = document.getElementById('paper');
    var canvas = document.getElementById('canvas');
    var edit   = !!S.canEdit && !S.printMode;

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }
    function rowById(id) {
        for (var i = 0; i < S.rows.length; i++) if (S.rows[i].id === id) return S.rows[i];
        return null;
    }
    function salesOf(r, size) { return (r.sales && r.sales[size]) || []; }
    function awalOf(r)  { var t = 0; SIZES.forEach(function (s) { t += (r.q[s] || 0); }); return t; }
    function lakuOf(r)  { var t = 0; SIZES.forEach(function (s) { t += salesOf(r, s).length; }); return t; }
    function num(n)     { return n.toLocaleString('id-ID'); }
    function daysInPeriod() {
        var p = S.report.period_key.split('-');
        return new Date(+p[0], +p[1], 0).getDate();
    }

    // ── render kertas ────────────────────────────────────────────────────
    function tally(qty, sales) {
        var sold = sales.length, keep = Math.max(0, qty - sold), html = '', i;
        for (i = 0; i < keep; i++) html += '<i class="tk"></i>';
        for (i = 0; i < sold; i++) html += '<i class="tk x"></i><b class="dy">' + esc(sales[i].day) + '</b>';
        return html;
    }

    function headHtml() {
        var R = S.report, h = '';
        h += '<div class="pp-title">LAPORAN STOCK</div>';
        h += '<div class="pp-brand tap" data-act="hdr" data-field="brand">BRAND : ' +
             S.brands.map(function (b) {
                 return b === R.brand ? '<b>' + esc(b) + '</b>' : '<s>' + esc(b) + '</s>';
             }).join(' / ') + '</div>';
        h += '<div class="pp-coret">(coret salah satu)</div>';

        var totAwal = 0, totLaku = 0;
        S.rows.forEach(function (r) {
            if (r.row_type === 'item') { totAwal += awalOf(r); totLaku += lakuOf(r); }
        });

        h += '<div class="pp-hd">';
        h +=   '<div>';
        h +=     '<div class="pp-f"><span class="lb w">Nama Counter</span><span class="val tap" data-act="hdr" data-field="counter_name">' + esc(R.counter_name) + '</span></div>';
        h +=     '<div class="pp-f"><span class="lb w">Periode</span><span class="val tap" data-act="hdr" data-field="period_key">' + esc(R.period_label) + '</span></div>';
        h +=   '</div>';
        h +=   '<div class="pp-tipe tap" data-act="hdr" data-field="tipe">&ldquo;' + esc(R.tipe) + '&rdquo;</div>';
        h +=   '<div>';
        h +=     '<div class="pp-f"><span class="lb w">Barang Datang</span><span class="val tap" data-act="hdr" data-field="barang_datang">' + num(R.barang_datang) + '</span></div>';
        h +=     '<div class="pp-f"><span class="lb w">Retur</span><span class="val tap" data-act="hdr" data-field="retur">' + num(R.retur) + '</span></div>';
        h +=     '<div class="pp-f"><span class="lb w">Terjual</span><span class="val pp-auto">' + num(totLaku) + '</span></div>';
        h +=     '<div class="pp-f"><span class="lb w">Discount</span><span class="val tap" data-act="hdr" data-field="discount_note">' + esc(R.discount_note) + '</span></div>';
        h +=     '<div class="pp-f"><span class="lb w">Stock Akhir</span><span class="val pp-auto">' + num(totAwal - totLaku) + '</span></div>';
        h +=   '</div>';
        h += '</div>';
        return h;
    }

    function tableHtml() {
        var h = '<table class="pp"><colgroup>' +
                '<col style="width:124px"><col style="width:72px">' +
                SIZES.map(function () { return '<col style="width:60px">'; }).join('') +
                '<col style="width:90px"><col style="width:90px"><col style="width:90px"><col style="width:122px">' +
                '</colgroup><thead><tr>' +
                '<th>ARTIKEL</th><th>WARNA</th>' +
                SIZES.map(function (s) { return '<th>' + esc(s) + '</th>'; }).join('') +
                '<th>STOCK AWAL</th><th>STOCK AKHIR</th><th>HARGA</th><th>KETERANGAN</th>' +
                '</tr></thead><tbody>';

        var totAwal = 0, totAkhir = 0;
        S.rows.forEach(function (r) {
            if (r.row_type === 'group') {
                h += '<tr class="r-grp" data-row="' + r.id + '">' +
                     '<td class="tap" data-act="row" data-field="group_code">' + esc(r.group_code) + '</td>' +
                     '<td class="c-long tap" colspan="' + (SIZES.length + 5) + '" data-act="row" data-field="group_text">' + esc(r.group_text) + '</td>' +
                     '</tr>';
                return;
            }
            var awal = awalOf(r), akhir = awal - lakuOf(r);
            totAwal += awal; totAkhir += akhir;

            h += '<tr data-row="' + r.id + '">' +
                 '<td class="c-art tap" data-act="row" data-field="artikel">' + esc(r.artikel) + '</td>' +
                 '<td class="c-wrn tap" data-act="row" data-field="warna">' + esc(r.warna) + '</td>';
            SIZES.forEach(function (s) {
                h += '<td class="c-sz tap" data-act="size" data-size="' + s + '">' + tally(r.q[s] || 0, salesOf(r, s)) + '</td>';
            });
            h += '<td class="c-num">' + (awal ? awal : '') + '</td>' +
                 '<td class="c-num">' + (awal ? akhir : '') + '</td>' +
                 '<td class="c-hrg tap" data-act="row" data-field="harga">' + (r.harga ? num(r.harga) : '') + '</td>' +
                 '<td class="c-ket tap" data-act="row" data-field="keterangan">' + esc(r.keterangan) + '</td>' +
                 '</tr>';
        });

        h += '<tr class="r-gt">' +
             '<td colspan="' + (SIZES.length + 2) + '">GRAND TOTAL</td>' +
             '<td>' + num(totAwal) + '</td><td>' + num(totAkhir) + '</td><td></td><td></td>' +
             '</tr></tbody></table>';
        return h;
    }

    function render() {
        paper.innerHTML = headHtml() + tableHtml();
    }

    // ── simpan ke server ────────────────────────────────────────────────
    var saveEl = document.getElementById('skSave');
    function setSave(txt, isErr) {
        if (!saveEl) return;
        saveEl.textContent = txt;
        saveEl.className = 'sk-save' + (isErr ? ' err' : '');
    }
    function api(action, data) {
        data = data || {};
        data.action = action;
        data.report_id = S.report.id;
        data._csrf = S.csrf;
        setSave('Menyimpan…');
        return fetch('?r=stock_sheet_api', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams(data).toString()
        }).then(function (res) { return res.json(); }).then(function (j) {
            if (!j || !j.ok) throw new Error((j && j.error) || 'Gagal menyimpan.');
            S.rows = j.rows;
            S.report = j.report;
            render();
            refreshPanel();
            setSave('Tersimpan');
            setTimeout(function () { if (saveEl && saveEl.textContent === 'Tersimpan') setSave(''); }, 1600);
        }).catch(function (e) {
            setSave(e.message || 'Gagal menyimpan.', true);
        });
    }

    // ── panel isian bawah ───────────────────────────────────────────────
    var bs = document.getElementById('bs'), bsTitle = document.getElementById('bsTitle'),
        bsBody = document.getElementById('bsBody'), panel = null;

    /* Panel muncul TEPAT di bawah jari. Klik "hantu" bawaan browser sesudah
       sentuhan bisa mendarat di tombol yang baru saja muncul di titik itu
       (mis. "Hapus baris" / angka tanggal). Yang diabaikan HANYA klik yang
       datang sesaat setelah panel terbuka DAN di titik yang sama dengan
       ketukan pembukanya — ketukan sengaja di tombol lain tetap langsung jalan. */
    var armedAt = 0, tapX = 0, tapY = 0;
    function openPanel(p) { panel = p; bs.hidden = false; armedAt = Date.now(); refreshPanel(); }
    function closePanel() { panel = null; bs.hidden = true; }
    function refreshPanel() {
        if (!panel) return;
        var v = panel.build();
        if (!v) { closePanel(); return; }
        bsTitle.textContent = v.title;
        bsBody.innerHTML = v.html;
        if (v.after) v.after();
    }
    if (bs) {
        bs.addEventListener('click', function (e) {
            var ghost = (Date.now() - armedAt) < 450 &&
                        Math.abs(e.clientX - tapX) < 44 && Math.abs(e.clientY - tapY) < 44;
            if (ghost) { e.stopPropagation(); e.preventDefault(); return; }
            if (e.target.getAttribute('data-close')) closePanel();
        }, true);
    }

    var FIELDS = {
        artikel:    { label: 'Artikel',    type: 'text' },
        warna:      { label: 'Warna',      type: 'text' },
        harga:      { label: 'Harga',      type: 'text',  hint: 'Boleh diketik 199000 atau 199.000' },
        keterangan: { label: 'Keterangan', type: 'text' },
        group_code: { label: 'Kode Grup',  type: 'text',  hint: 'Contoh: TE, TF, TQ, TZ' },
        group_text: { label: 'Isi Kolom Panjang', type: 'text' }
    };

    /** Panel satu baris artikel (atau baris grup) + aksi barisnya. */
    function rowPanel(rowId, focusField) {
        openPanel({
            build: function () {
                var r = rowById(rowId);
                if (!r) return null;
                var isGrp = r.row_type === 'group';
                var flds  = isGrp ? ['group_code', 'group_text'] : ['artikel', 'warna', 'harga', 'keterangan'];
                var h = '';

                if (!isGrp) {
                    var awal = awalOf(r), laku = lakuOf(r);
                    h += '<div class="sk-sum"><span>Stock awal<b>' + awal + '</b></span>' +
                         '<span>Laku<b>' + laku + '</b></span>' +
                         '<span>Stock akhir<b>' + (awal - laku) + '</b></span></div>';
                }
                flds.forEach(function (f) {
                    var cfg = FIELDS[f], val = f === 'harga' ? (r.harga || '') : (r[f] || '');
                    h += '<label>' + esc(cfg.label) +
                         '<input data-f="' + f + '" value="' + esc(val) + '"' +
                         (cfg.hint ? ' placeholder="' + esc(cfg.hint) + '"' : '') + (edit ? '' : ' disabled') + '></label>';
                });
                if (edit) {
                    h += '<div class="sk-row"><button type="button" class="btn" data-do="save">Simpan</button></div>';
                    h += '<div class="sk-lbl">Aksi baris</div><div class="sk-row">' +
                         '<button type="button" class="btn light" data-do="up">&uarr; Naik</button>' +
                         '<button type="button" class="btn light" data-do="down">&darr; Turun</button>' +
                         '<button type="button" class="btn light" data-do="add-item">+ Baris di bawah</button>' +
                         '<button type="button" class="btn light" data-do="add-group">+ Grup di bawah</button>' +
                         '<button type="button" class="btn light" data-do="toggle">Jadikan ' + (isGrp ? 'baris artikel' : 'baris grup') + '</button>' +
                         '<button type="button" class="btn warn" data-do="del">Hapus baris</button>' +
                         '</div>';
                }
                return {
                    title: isGrp ? 'Baris Grup' : 'Baris Artikel',
                    html: h,
                    after: function () {
                        var first = bsBody.querySelector('[data-f="' + (focusField || flds[0]) + '"]') || bsBody.querySelector('input');
                        if (first && edit) { first.focus(); if (first.select) first.select(); }
                        if (!edit) return;

                        function saveAll() {
                            var jobs = [];
                            bsBody.querySelectorAll('[data-f]').forEach(function (inp) {
                                var f = inp.getAttribute('data-f');
                                var cur = f === 'harga' ? (r.harga || '') : (r[f] || '');
                                if (String(cur) !== inp.value) jobs.push({ field: f, value: inp.value });
                            });
                            if (!jobs.length) { closePanel(); return; }
                            var chain = Promise.resolve();
                            jobs.forEach(function (j) {
                                chain = chain.then(function () { return api('row_update', { row_id: rowId, field: j.field, value: j.value }); });
                            });
                            chain.then(closePanel);
                        }
                        bsBody.querySelectorAll('input').forEach(function (inp) {
                            inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); saveAll(); } });
                        });
                        bsBody.querySelectorAll('[data-do]').forEach(function (b) {
                            b.addEventListener('click', function () {
                                var d = b.getAttribute('data-do');
                                if (d === 'save')      return saveAll();
                                if (d === 'up')        return api('row_move', { row_id: rowId, dir: 'up' });
                                if (d === 'down')      return api('row_move', { row_id: rowId, dir: 'down' });
                                if (d === 'add-item')  return api('row_add', { after_row_id: rowId, row_type: 'item' }).then(closePanel);
                                if (d === 'add-group') return api('row_add', { after_row_id: rowId, row_type: 'group' }).then(closePanel);
                                if (d === 'toggle')    return api('row_type_set', { row_id: rowId, row_type: isGrp ? 'item' : 'group' });
                                if (d === 'del') {
                                    if (!confirm('Hapus baris ini beserta catatan lakunya?')) return;
                                    return api('row_delete', { row_id: rowId }).then(closePanel);
                                }
                            });
                        });
                    }
                };
            }
        });
    }

    /** Panel satu sel ukuran: jumlah turus + catatan laku per tanggal. */
    function sizePanel(rowId, size) {
        openPanel({
            build: function () {
                var r = rowById(rowId);
                if (!r) return null;
                var qty = r.q[size] || 0, sales = salesOf(r, size), sisa = qty - sales.length;
                var days = daysInPeriod();
                var today = new Date(), todayDay = 0;
                if (S.report.period_key === (today.getFullYear() + '-' + ('0' + (today.getMonth() + 1)).slice(-2))) {
                    todayDay = today.getDate();
                }

                var h = '<div class="sk-sum"><span>Stok ukuran ' + esc(size) + '<b>' + qty + '</b></span>' +
                        '<span>Laku<b>' + sales.length + '</b></span><span>Sisa<b>' + sisa + '</b></span></div>';

                h += '<div class="sk-lbl">Jumlah turus (stock awal ukuran ' + esc(size) + ')</div>';
                h += '<div class="sk-step">' +
                     '<button type="button" class="btn light" data-do="minus"' + (edit ? '' : ' disabled') + '>&minus;</button>' +
                     '<span class="n">' + qty + '</span>' +
                     '<button type="button" class="btn light" data-do="plus"' + (edit ? '' : ' disabled') + '>+</button>' +
                     '</div>';

                if (edit) {
                    h += '<div class="sk-lbl">Tandai laku &mdash; pilih tanggalnya' + (sisa <= 0 ? ' (stok habis)' : '') + '</div>';
                    h += '<div class="sk-days">';
                    for (var d = 1; d <= days; d++) {
                        h += '<button type="button" class="btn light' + (d === todayDay ? ' today' : '') + '" data-day="' + d + '"' +
                             (sisa <= 0 ? ' disabled' : '') + '>' + d + '</button>';
                    }
                    h += '</div>';
                }

                h += '<div class="sk-lbl">Sudah laku (' + sales.length + ')</div>';
                h += sales.length
                    ? '<div class="sk-chips">' + sales.map(function (s) {
                          return '<span class="sk-chip">tgl ' + esc(s.day) +
                                 (edit ? '<button type="button" data-sale="' + s.id + '" aria-label="Batalkan">&times;</button>' : '') + '</span>';
                      }).join('') + '</div>'
                    : '<p class="muted" style="font-size:12.5px">Belum ada yang laku di ukuran ini.</p>';

                return {
                    title: 'Ukuran ' + size + (r.artikel ? ' · ' + r.artikel : '') + (r.warna ? ' · warna ' + r.warna : ''),
                    html: h,
                    after: function () {
                        if (!edit) return;
                        bsBody.querySelectorAll('[data-do]').forEach(function (b) {
                            b.addEventListener('click', function () {
                                var q = b.getAttribute('data-do') === 'plus' ? qty + 1 : qty - 1;
                                if (q < 0) return;
                                api('qty_set', { row_id: rowId, size: size, qty: q });
                            });
                        });
                        bsBody.querySelectorAll('[data-day]').forEach(function (b) {
                            b.addEventListener('click', function () {
                                api('sale_add', { row_id: rowId, size: size, day: b.getAttribute('data-day') });
                            });
                        });
                        bsBody.querySelectorAll('[data-sale]').forEach(function (b) {
                            b.addEventListener('click', function () {
                                api('sale_delete', { sale_id: b.getAttribute('data-sale') });
                            });
                        });
                    }
                };
            }
        });
    }

    /** Panel isian kepala kertas. */
    function headerPanel(field) {
        openPanel({
            build: function () {
                var R = S.report, h = '', val = R[field] != null ? R[field] : '';
                var meta = {
                    counter_name:  ['Nama Counter', 'text'],
                    period_key:    ['Periode', 'month'],
                    brand:         ['Brand', 'select'],
                    tipe:          ['Tipe', 'select'],
                    barang_datang: ['Barang Datang', 'number'],
                    retur:         ['Retur', 'number'],
                    discount_note: ['Discount', 'text']
                }[field];
                if (!meta) return null;

                if (meta[1] === 'select') {
                    var opts = field === 'brand' ? S.brands : ['NORMAL', 'DISCOUNT'];
                    h += '<label>' + meta[0] + '<select id="skIn"' + (edit ? '' : ' disabled') + '>' +
                         opts.map(function (o) {
                             return '<option value="' + esc(o) + '"' + (o === val ? ' selected' : '') + '>' + esc(o) + '</option>';
                         }).join('') + '</select></label>';
                } else {
                    h += '<label>' + meta[0] + '<input id="skIn" type="' + meta[1] + '" value="' + esc(val) + '"' +
                         (edit ? '' : ' disabled') + '></label>';
                }
                if (edit) h += '<div class="sk-row"><button type="button" class="btn" data-do="save">Simpan</button></div>';

                return {
                    title: meta[0],
                    html: h,
                    after: function () {
                        var inp = document.getElementById('skIn');
                        if (inp && edit) inp.focus();
                        if (!edit) return;
                        function save() { api('header_update', { field: field, value: inp.value }).then(closePanel); }
                        if (inp) inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); save(); } });
                        var b = bsBody.querySelector('[data-do="save"]');
                        if (b) b.addEventListener('click', save);
                    }
                };
            }
        });
    }

    // ── kanvas: cubit untuk zoom, geser untuk berpindah ─────────────────
    var scale = 1, tx = 0, ty = 0, MIN = 0.12, MAX = 5;

    function applyT() { paper.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + scale + ')'; }
    function clampT() {
        var cw = canvas.clientWidth, ch = canvas.clientHeight;
        var pw = paper.offsetWidth * scale, ph = paper.offsetHeight * scale;
        tx = pw <= cw ? (cw - pw) / 2 : Math.min(0, Math.max(cw - pw, tx));
        ty = ph <= ch ? Math.max(8, (ch - ph) / 2) : Math.min(8, Math.max(ch - ph - 8, ty));
    }
    function setScale(next, cx, cy) {
        next = Math.max(MIN, Math.min(MAX, next));
        // titik kertas di bawah jari/kursor harus tetap di tempatnya
        var px = (cx - tx) / scale, py = (cy - ty) / scale;
        scale = next;
        tx = cx - px * scale;
        ty = cy - py * scale;
        clampT(); applyT();
    }
    function fit() {
        var cw = canvas.clientWidth;
        scale = Math.max(MIN, Math.min(MAX, (cw - 20) / paper.offsetWidth));
        tx = 0; ty = 8;
        clampT(); applyT();
    }

    /* Tinggi kanvas dipas ke sisa layar: kalau tidak, di HP bagian bawah kertas
       jatuh di bawah lipatan dan baris terakhir tak bisa disentuh sebelum
       halamannya digulir. */
    function sizeCanvas() {
        if (!canvas) return;
        var top     = canvas.getBoundingClientRect().top + (window.pageYOffset || 0);
        var reserve = document.querySelector('.m-nav') ? 116 : 58;   // bottom-nav HP / catatan bawah
        canvas.style.height = Math.max(320, Math.round(window.innerHeight - top - reserve)) + 'px';
    }

    if (canvas) {
        var pts = new Map(), pinch = null, panFrom = null, moved = false, gestureUntil = 0;

        canvas.addEventListener('pointerdown', function (e) {
            pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
            if (pts.size === 1) {
                panFrom = { x: e.clientX, y: e.clientY, tx: tx, ty: ty };
                moved = false;
            } else if (pts.size === 2) {
                var a = Array.from(pts.values());
                pinch = {
                    dist: Math.hypot(a[0].x - a[1].x, a[0].y - a[1].y),
                    scale: scale
                };
                panFrom = null;
                moved = true;   // cubit bukan ketukan
            }
        });

        canvas.addEventListener('pointermove', function (e) {
            if (!pts.has(e.pointerId)) return;
            pts.set(e.pointerId, { x: e.clientX, y: e.clientY });
            var rect = canvas.getBoundingClientRect();

            if (pts.size >= 2 && pinch) {
                var a = Array.from(pts.values());
                var d = Math.hypot(a[0].x - a[1].x, a[0].y - a[1].y);
                if (pinch.dist > 0) {
                    var mx = (a[0].x + a[1].x) / 2 - rect.left, my = (a[0].y + a[1].y) / 2 - rect.top;
                    setScale(pinch.scale * (d / pinch.dist), mx, my);
                }
                e.preventDefault();
                return;
            }
            if (panFrom) {
                var dx = e.clientX - panFrom.x, dy = e.clientY - panFrom.y;
                if (!moved && Math.hypot(dx, dy) > 7) moved = true;
                if (moved) {
                    tx = panFrom.tx + dx; ty = panFrom.ty + dy;
                    clampT(); applyT();
                    e.preventDefault();
                }
            }
        });

        function endPointer(e) {
            if (moved || pts.size > 1) gestureUntil = Date.now() + 400;  // geser/cubit → bukan ketukan
            pts.delete(e.pointerId);
            if (pts.size < 2) pinch = null;
            if (pts.size === 0) panFrom = null;
        }
        canvas.addEventListener('pointerup', endPointer);
        canvas.addEventListener('pointercancel', function (e) { pts.delete(e.pointerId); pinch = null; panFrom = null; });

        /* Ketukan ditangani lewat 'click', bukan pointerup: klik-nya jadi habis
           di sel ini, tidak menyusul mendarat di panel yang baru terbuka. */
        canvas.addEventListener('click', function (e) {
            if (Date.now() < gestureUntil) return;
            var cell = e.target && e.target.closest ? e.target.closest('.tap') : null;
            if (!cell) return;
            tapX = e.clientX; tapY = e.clientY;
            var act = cell.getAttribute('data-act');
            if (act === 'hdr')  return headerPanel(cell.getAttribute('data-field'));
            var tr = cell.closest('tr');
            if (!tr) return;
            var rowId = parseInt(tr.getAttribute('data-row'), 10);
            if (!rowId) return;
            if (act === 'size') return sizePanel(rowId, cell.getAttribute('data-size'));
            if (act === 'row')  return rowPanel(rowId, cell.getAttribute('data-field'));
        });

        canvas.addEventListener('wheel', function (e) {
            var rect = canvas.getBoundingClientRect();
            if (e.ctrlKey || e.metaKey) {
                setScale(scale * (e.deltaY < 0 ? 1.12 : 0.89), e.clientX - rect.left, e.clientY - rect.top);
            } else {
                tx -= e.deltaX; ty -= e.deltaY;
                clampT(); applyT();
            }
            e.preventDefault();
        }, { passive: false });

        canvas.addEventListener('dblclick', function (e) {
            if (!bs.hidden) return;                       // sedang mengisi panel
            var rect = canvas.getBoundingClientRect();
            setScale(scale < 0.9 ? 1.2 : (canvas.clientWidth - 20) / paper.offsetWidth, e.clientX - rect.left, e.clientY - rect.top);
        });

        document.querySelectorAll('[data-zoom]').forEach(function (b) {
            b.addEventListener('click', function () {
                var z = b.getAttribute('data-zoom'), cx = canvas.clientWidth / 2, cy = canvas.clientHeight / 2;
                if (z === 'in')  return setScale(scale * 1.25, cx, cy);
                if (z === 'out') return setScale(scale * 0.8, cx, cy);
                fit();
            });
        });
        document.querySelectorAll('[data-add]').forEach(function (b) {
            b.addEventListener('click', function () { api('row_add', { row_type: b.getAttribute('data-add') }); });
        });

        window.addEventListener('resize', function () { sizeCanvas(); clampT(); applyT(); });
        window.addEventListener('orientationchange', function () { setTimeout(function () { sizeCanvas(); fit(); }, 250); });
    }

    render();
    if (S.printMode) {
        window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 250); });
    } else if (canvas) {
        sizeCanvas();
        fit();
    }
})();
    <?php
}
