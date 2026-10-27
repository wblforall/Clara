<?php
declare(strict_types=1);

/**
 * Tagihan & Pembayaran: nomor invoice, jumlah tagihan, dan pembayaran per
 * transaksi. Menggantikan kolom invoice / pembayaran / LUNAS di Excel Casual
 * Leasing. Tidak menyentuh nilai income: pembayaran hanya mencatat uang yang
 * sudah masuk terhadap tagihan yang sudah diterbitkan.
 *
 * Diisi oleh Admin Sales dan Sales (Sales hanya transaksi miliknya).
 */

/** Saringan + data daftar tagihan; dipakai halaman dan ekspor CSV. */
function _pay_query(PDO $pdo, bool $semuaBaris): array
{
    $pid     = current_property_id();
    $periode = trim((string) getv('period', date('Y-m')));
    if ($periode !== '' && $periode !== 'semua') $periode = periode_bulan('period');
    $mode    = in_array(getv('mode', 'mulai'), ['mulai', 'jalan'], true) ? getv('mode', 'mulai') : 'mulai';
    $modul   = in_array(getv('module', ''), ['cl', 'media', 'gudang'], true) ? (string) getv('module') : '';
    $pic     = trim((string) getv('pic', ''));
    $status  = (string) getv('status', '');
    if (!isset(pembayaran_status_daftar()[$status]) && $status !== 'lewat') $status = '';
    $cari    = trim((string) getv('q', ''));

    [$where, $params] = transaksi_tampil_kondisi($pdo, 't');
    $where[]              = 't.property_id = :pid';
    $params[':pid']       = $pid;
    if ($periode !== '' && $periode !== 'semua') {
        $awal = $periode . '-01';
        $akhir = date('Y-m-t', strtotime($awal));
        if ($mode === 'mulai') {
            $where[] = 't.start_date BETWEEN :awal AND :akhir';
        } else {
            $where[] = 't.start_date <= :akhir AND t.end_date >= :awal';
        }
        $params[':awal'] = $awal; $params[':akhir'] = $akhir;
    }
    if ($modul !== '') { $where[] = 't.module = :modul'; $params[':modul'] = $modul; }
    if ($pic !== '')   { $where[] = 't.pic_name = :pic'; $params[':pic'] = $pic; }
    if ($cari !== '') {
        $where[] = '(c.company_name LIKE :q1 OR c.brand_name LIKE :q2 OR t.master_code LIKE :q3 OR t.invoice_no LIKE :q4)';
        foreach (['q1', 'q2', 'q3', 'q4'] as $k) $params[":$k"] = "%$cari%";
    }
    [$sf, $sp] = current_sales_scope_sql($pdo, $pid, 't.pic_name', 't.created_by');
    $dalam = "SELECT t.id, t.property_id, t.module, t.master_code, t.start_date, t.end_date, t.final_amount,
                     t.pic_name, t.created_by, t.invoice_no, t.invoice_date, t.invoice_due, t.invoice_amount,
                     c.company_name, c.brand_name, " . pembayaran_select_sql('t') . "
              FROM transactions t LEFT JOIN master_clients c ON c.id = t.client_id
              WHERE " . implode(' AND ', $where) . $sf;
    // Campuran :nama dan ? tidak boleh dalam satu pernyataan — ubah ? milik
    // pembatas sales menjadi parameter bernama.
    $n = 0;
    $dalam = preg_replace_callback('/\?/', function () use (&$n) { return ':sc' . $n++; }, $dalam);
    $luar  = "FROM ($dalam) x" . ($status === 'lewat' ? ' WHERE x.bayar_lewat = 1'
                                  : ($status !== '' ? ' WHERE x.bayar_status = :status' : ''));
    $bind = $params;
    foreach ($sp as $i => $v) $bind[':sc' . $i] = $v;
    if ($status !== '' && $status !== 'lewat') $bind[':status'] = $status;

    // Hitungan di lencana saringan memakai SEMUA baris (tanpa saringan status),
    // kalau tidak lencana lain jadi 0 begitu satu status dipilih.
    $dasar   = "FROM ($dalam) x";
    $bindDasar = $bind; unset($bindDasar[':status']);
    $chip = $pdo->prepare("SELECT COUNT(*) n_all, SUM(bayar_status='belum_invoice') n_belum_inv, SUM(bayar_status='belum_bayar') n_belum,
                                  SUM(bayar_status='sebagian') n_sebagian, SUM(bayar_status='lunas') n_lunas,
                                  SUM(bayar_lewat=1) n_lewat $dasar");
    $chip->execute($bindDasar);
    $ringkas = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(tagihan),0) tagihan, COALESCE(SUM(terbayar),0) terbayar $luar");
    $ringkas->execute($bind);
    $sum = array_map(fn($v) => (float) $v, $chip->fetch() + $ringkas->fetch());

    $halaman = max(1, (int) getv('page', 1));
    $per     = 200;
    $batas   = $semuaBaris ? '' : ' LIMIT ' . $per . ' OFFSET ' . (($halaman - 1) * $per);
    $st = $pdo->prepare("SELECT x.* $luar ORDER BY x.start_date DESC, x.id DESC" . $batas);
    $st->execute($bind);
    return [
        'rows' => $st->fetchAll(), 'sum' => $sum, 'halaman' => $halaman, 'per' => $per,
        'f' => ['period' => $periode, 'mode' => $mode, 'module' => $modul, 'pic' => $pic, 'status' => $status, 'q' => $cari],
    ];
}

function payments_page(PDO $pdo): void
{
    require_permission('view_payments');
    $d    = _pay_query($pdo, false);
    $f    = $d['f'];
    $sum  = $d['sum'];
    $pid  = current_property_id();
    $pics = $pdo->prepare("SELECT name FROM master_pic WHERE status='active' AND property_id = ? ORDER BY name");
    $pics->execute([$pid]);
    $pics = $pics->fetchAll(PDO::FETCH_COLUMN);
    $modLabel = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
    $qs = fn(array $ubah) => '?' . http_build_query(array_filter(array_merge(['r' => 'payments'] + $f, $ubah), fn($v) => $v !== '' && $v !== null));
    $totalHal = max(1, (int) ceil(((int) $sum['n']) / $d['per']));
    $sisa = (float) $sum['tagihan'] - (float) $sum['terbayar'];

    layout('Tagihan & Pembayaran', function () use ($d, $f, $sum, $pics, $modLabel, $qs, $totalHal, $sisa) {
        $stDaftar = pembayaran_status_daftar();
        ?>
        <form method="get" class="toolbar" style="gap:10px">
            <input type="hidden" name="r" value="payments">
            <div><label class="muted">Periode</label>
                <input type="month" name="period" value="<?= h($f['period'] === 'semua' ? '' : $f['period']) ?>" style="width:160px"></div>
            <div><label class="muted">Dihitung dari</label>
                <select name="mode"><option value="mulai" <?= $f['mode'] === 'mulai' ? 'selected' : '' ?>>Tanggal mulai di bulan ini</option>
                    <option value="jalan" <?= $f['mode'] === 'jalan' ? 'selected' : '' ?>>Kontrak yang berjalan di bulan ini</option></select></div>
            <div><label class="muted">Modul</label>
                <select name="module"><option value="">Semua</option>
                    <?php foreach ($modLabel as $k => $v): ?><option value="<?= h($k) ?>" <?= $f['module'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
            <div><label class="muted">PIC</label>
                <select name="pic"><option value="">Semua</option>
                    <?php foreach ($pics as $p): ?><option <?= $f['pic'] === $p ? 'selected' : '' ?>><?= h($p) ?></option><?php endforeach; ?></select></div>
            <div><label class="muted">Cari</label><input type="text" name="q" value="<?= h($f['q']) ?>" placeholder="Klien, unit, invoice" style="width:180px"></div>
            <button type="submit">Terapkan</button>
            <a class="btn light" href="?r=payments&period=semua">Semua periode</a>
            <a class="btn light" href="<?= h($qs(['r' => 'payments_csv'])) ?>">Unduh CSV</a>
        </form>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:14px">
            <?php foreach ([['Tagihan', money($sum['tagihan']), 'var(--primary)'], ['Sudah dibayar', money($sum['terbayar']), '#047857'],
                            ['Belum dibayar', money($sisa), $sisa > 0 ? '#B91C1C' : '#047857'], ['Lewat jatuh tempo', (int) $sum['n_lewat'] . ' transaksi', (int) $sum['n_lewat'] ? '#B91C1C' : 'var(--muted)']] as [$lbl, $val, $clr]): ?>
                <div class="card" style="padding:12px 14px;border-top:3px solid <?= $clr ?>">
                    <div class="muted" style="font-size:12px"><?= h($lbl) ?></div>
                    <div style="font-size:20px;font-weight:800;color:<?= $clr ?>"><?= h($val) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
            <a class="btn <?= $f['status'] === '' ? '' : 'light' ?>" href="<?= h($qs(['status' => '', 'page' => ''])) ?>">Semua (<?= (int) $sum['n_all'] ?>)</a>
            <?php
            $hit = ['belum_invoice' => 'n_belum_inv', 'belum_bayar' => 'n_belum', 'sebagian' => 'n_sebagian', 'lunas' => 'n_lunas'];
            foreach ($hit as $k => $kol): ?>
                <a class="btn <?= $f['status'] === $k ? '' : 'light' ?>" href="<?= h($qs(['status' => $k, 'page' => ''])) ?>"><?= h($stDaftar[$k][0]) ?> (<?= (int) $sum[$kol] ?>)</a>
            <?php endforeach; ?>
            <a class="btn <?= $f['status'] === 'lewat' ? '' : 'light' ?>" href="<?= h($qs(['status' => 'lewat', 'page' => ''])) ?>" style="color:#B91C1C">Lewat tempo (<?= (int) $sum['n_lewat'] ?>)</a>
        </div>

        <div class="table-wrap"><table>
            <thead><tr><th>Unit</th><th>Penyewa</th><th>Periode sewa</th><th>PIC</th><th style="text-align:right">Tagihan</th>
                <th style="text-align:right">Terbayar</th><th style="text-align:right">Sisa</th><th>Invoice</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$d['rows']): ?>
                <tr><td colspan="10" style="text-align:center;color:var(--muted);padding:24px">Tidak ada transaksi yang sesuai saringan.</td></tr>
            <?php endif; ?>
            <?php foreach ($d['rows'] as $r):
                $sisaR = (float) $r['tagihan'] - (float) $r['terbayar']; ?>
                <tr>
                    <td style="white-space:nowrap"><b><?= h($r['master_code']) ?></b><br><span class="muted" style="font-size:11px"><?= h($modLabel[$r['module']] ?? $r['module']) ?></span></td>
                    <td><?= h($r['company_name'] ?? '-') ?><?= !empty($r['brand_name']) ? '<br><span class="muted" style="font-size:11px">' . h($r['brand_name']) . '</span>' : '' ?></td>
                    <td style="white-space:nowrap"><?= h($r['start_date']) ?><br><span class="muted" style="font-size:11px">s/d <?= h($r['end_date']) ?></span></td>
                    <td><?= h($r['pic_name'] ?? '-') ?></td>
                    <td style="text-align:right"><?= money($r['tagihan']) ?></td>
                    <td style="text-align:right"><?= money($r['terbayar']) ?></td>
                    <td style="text-align:right;color:<?= $sisaR > 0.5 ? '#B91C1C' : 'inherit' ?>"><?= money($sisaR) ?></td>
                    <td style="white-space:nowrap"><?= $r['invoice_no'] ? h($r['invoice_no']) : '<span class="muted">-</span>' ?>
                        <?= $r['invoice_due'] ? '<br><span class="muted" style="font-size:11px">tempo ' . h($r['invoice_due']) . '</span>' : '' ?></td>
                    <td><?= pembayaran_badge((string) $r['bayar_status'], (bool) $r['bayar_lewat']) ?></td>
                    <td><a class="btn light" href="?r=payment_form&id=<?= (int) $r['id'] ?>&back=<?= h(urlencode(ltrim($qs([]), '?'))) ?>">Kelola</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>

        <?php if ($totalHal > 1): ?>
            <div style="display:flex;gap:8px;align-items:center;margin-top:12px">
                <?php if ($d['halaman'] > 1): ?><a class="btn light" href="<?= h($qs(['page' => $d['halaman'] - 1])) ?>">← Sebelumnya</a><?php endif; ?>
                <span class="muted">Halaman <?= (int) $d['halaman'] ?> dari <?= (int) $totalHal ?></span>
                <?php if ($d['halaman'] < $totalHal): ?><a class="btn light" href="<?= h($qs(['page' => $d['halaman'] + 1])) ?>">Berikutnya →</a><?php endif; ?>
            </div>
        <?php endif; ?>
        <?php
    });
}

function payments_csv(PDO $pdo): void
{
    require_permission('view_payments');
    $d = _pay_query($pdo, true);
    $st = pembayaran_status_daftar();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tagihan-pembayaran-' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Unit', 'Modul', 'Penyewa', 'Brand', 'Mulai', 'Selesai', 'PIC', 'Tagihan', 'Terbayar', 'Sisa', 'No. Invoice', 'Tgl Invoice', 'Jatuh Tempo', 'Status', 'Lewat Tempo'], ';');
    foreach ($d['rows'] as $r) {
        // Sel yang diawali = + - @ dibaca Excel sebagai rumus; awali apostrof.
        $aman = fn($v) => ($v !== null && $v !== '' && strpos('=+-@', (string) $v[0]) !== false) ? "'" . $v : $v;
        fputcsv($o, [$r['master_code'], $r['module'], $aman($r['company_name']), $aman($r['brand_name']), $r['start_date'], $r['end_date'], $r['pic_name'],
            (float) $r['tagihan'], (float) $r['terbayar'], (float) $r['tagihan'] - (float) $r['terbayar'], $aman($r['invoice_no']),
            $r['invoice_date'], $r['invoice_due'], $st[$r['bayar_status']][0] ?? $r['bayar_status'], $r['bayar_lewat'] ? 'Ya' : ''], ';');
    }
    fclose($o);
    exit;
}

/** Muat transaksi yang boleh diisi pengguna ini; kalau tidak boleh → kembali ke daftar. */
function _pay_muat(PDO $pdo, int $id, bool $untukUbah = false): array
{
    $st = $pdo->prepare("SELECT t.*, c.company_name, c.brand_name, " . pembayaran_select_sql('t') . "
                         FROM transactions t LEFT JOIN master_clients c ON c.id = t.client_id
                         WHERE t.id = ? AND t.property_id = ? AND t.deleted_at IS NULL");
    $st->execute([$id, current_property_id()]);
    $trx = $st->fetch();
    if (!$trx || !pembayaran_boleh_lihat($pdo, $trx)) {
        flash('Transaksi tidak ditemukan atau bukan milik Anda.');
        redirect_to('payments');
    }
    if ($untukUbah && !pembayaran_boleh_isi($pdo, $trx)) {
        flash('Anda hanya bisa melihat tagihan ini, tidak mengubahnya.');
        redirect_to('payment_form', ['id' => $id]);
    }
    return $trx;
}

/** Tujuan kembali: hanya ke halaman tagihan sendiri, tidak pernah alamat luar. */
function _pay_back(): string
{
    $b = (string) (getv('back', post('back', '')));
    return (str_starts_with($b, 'r=payments') || str_starts_with($b, 'r=calendar')) ? $b : '';
}

function _pay_kembali(int $id): never
{
    $b = _pay_back();
    header('Location: ?r=payment_form&id=' . $id . ($b !== '' ? '&back=' . urlencode($b) : ''));
    exit;
}

function payment_form(PDO $pdo): void
{
    require_permission('view_payments');
    $id  = (int) getv('id', 0);
    $trx = _pay_muat($pdo, $id);
    // Manager membuka halaman ini untuk memeriksa, bukan mengisi. Isiannya
    // dimatikan di sini supaya ia tidak sempat mengetik lalu ditolak saat
    // menyimpan — penolakan sesudah mengetik terasa seperti kerusakan.
    $bisaIsi = pembayaran_boleh_isi($pdo, $trx);
    $pb  = $pdo->prepare('SELECT * FROM transaction_payments WHERE transaction_id = ? AND deleted_at IS NULL ORDER BY paid_at, id');
    $pb->execute([$id]);
    $bayar = $pb->fetchAll();
    $back  = _pay_back();
    $sisa  = (float) $trx['tagihan'] - (float) $trx['terbayar'];
    $modLabel = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];

    layout('Invoice & Pembayaran', function () use ($trx, $bayar, $back, $sisa, $modLabel, $id, $bisaIsi) {
        ?>
        <div class="toolbar"><a class="btn light" href="?<?= $back !== '' ? h($back) : 'r=payments' ?>">← Kembali</a>
            <div><?= pembayaran_badge((string) $trx['bayar_status'], (bool) $trx['bayar_lewat']) ?></div></div>

        <div class="panel" style="padding:16px 20px;margin-bottom:14px">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px 24px">
                <div><div class="muted">Unit</div><b><?= h($trx['master_code']) ?></b> · <?= h($modLabel[$trx['module']] ?? $trx['module']) ?></div>
                <div><div class="muted">Penyewa</div><b><?= h($trx['company_name'] ?? '-') ?></b><?= !empty($trx['brand_name']) ? ' (' . h($trx['brand_name']) . ')' : '' ?></div>
                <div><div class="muted">Periode sewa</div><?= h($trx['start_date']) ?> s/d <?= h($trx['end_date']) ?></div>
                <div><div class="muted">PIC</div><?= h($trx['pic_name'] ?? '-') ?></div>
                <div><div class="muted">Nilai transaksi</div><b><?= money($trx['final_amount']) ?></b></div>
                <div><div class="muted">Tagihan</div><b><?= money($trx['tagihan']) ?></b></div>
                <div><div class="muted">Terbayar</div><b style="color:#047857"><?= money($trx['terbayar']) ?></b></div>
                <div><div class="muted"><?= $sisa < -0.5 ? 'Lebih bayar' : 'Sisa' ?></div><b style="color:<?= $sisa > 0.5 ? '#B91C1C' : '#047857' ?>"><?= money(abs($sisa)) ?></b></div>
            </div>
        </div>

        <?php $kunci = $bisaIsi ? '' : ' disabled'; ?>
        <?php if (!$bisaIsi): ?>
            <div class="panel" style="padding:12px 18px;margin-bottom:14px;background:#F8FAFC;border:1px solid #E2E8F0">
                <b>Hanya melihat.</b> Anda bisa memeriksa invoice dan pembayaran di halaman ini,
                tetapi tidak mengubahnya. Yang mengisi adalah Admin Sales dan PIC transaksinya.
            </div>
        <?php endif; ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px;align-items:start">
            <form method="post" action="?r=payment_invoice_save" class="panel" style="padding:16px 20px">
                <h3 style="margin:0 0 10px">Invoice</h3>
                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <input type="hidden" name="back" value="<?= h($back) ?>">
                <label>No. Invoice</label>
                <input type="text" name="invoice_no" value="<?= h((string) $trx['invoice_no']) ?>" maxlength="60" placeholder="INV/CL-EWALK/26/X/001"<?= $kunci ?>>
                <label>Tanggal invoice</label>
                <input type="date" name="invoice_date" value="<?= h((string) $trx['invoice_date']) ?>"<?= $kunci ?>>
                <label>Jatuh tempo</label>
                <input type="date" name="invoice_due" value="<?= h((string) $trx['invoice_due']) ?>"<?= $kunci ?>>
                <label>Jumlah tagihan <span class="muted" style="font-weight:400">(kosong = nilai transaksi <?= money($trx['final_amount']) ?>)</span></label>
                <input type="text" name="invoice_amount" id="inv-amt" inputmode="numeric" value="<?= $trx['invoice_amount'] === null ? '' : h((string) (int) round((float) $trx['invoice_amount'])) ?>" placeholder="Rp"<?= $kunci ?>>
                <div style="display:flex;gap:6px;margin:6px 0 12px;flex-wrap:wrap">
                    <?php if ($bisaIsi): ?><button type="button" class="btn light" onclick="document.getElementById('inv-amt').value=<?= (int) round((float) $trx['final_amount'] * 1.11) ?>">Nilai + PPN 11%</button>
                    <button type="button" class="btn light" onclick="document.getElementById('inv-amt').value=<?= (int) round((float) $trx['final_amount']) ?>">Sama dengan nilai transaksi</button>
                <?php endif; ?></div>
                <?php if ($bisaIsi): ?><button type="submit">Simpan invoice</button><?php endif; ?>
            </form>

            <div class="panel" style="padding:16px 20px">
                <h3 style="margin:0 0 10px">Pembayaran</h3>
                <?php if (!$bayar): ?><p class="muted">Belum ada pembayaran tercatat.</p><?php else: ?>
                <table style="margin-bottom:12px"><thead><tr><th>Tanggal</th><th style="text-align:right">Jumlah</th><th>Cara / Ref.</th><th></th></tr></thead><tbody>
                    <?php foreach ($bayar as $p): ?>
                        <tr><td><?= h($p['paid_at']) ?></td><td style="text-align:right"><?= money($p['amount']) ?></td>
                            <td><?= h(trim(($p['method'] ?? '') . ' ' . ($p['ref_no'] ?? ''))) ?><?= $p['note'] ? '<br><span class="muted" style="font-size:11px">' . h($p['note']) . '</span>' : '' ?></td>
                            <td><?php if ($bisaIsi): ?><form method="post" action="?r=payment_delete" onsubmit="return confirm('Hapus pembayaran ini?')">
                                <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $id ?>">
                                <input type="hidden" name="pay_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
                                <button type="submit" class="btn light" style="color:#B91C1C">Hapus</button></form><?php endif; ?></td></tr>
                    <?php endforeach; ?></tbody></table>
                <?php endif; ?>
                <form method="post" action="?r=payment_add">
                    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $id ?>">
                    <input type="hidden" name="back" value="<?= h($back) ?>">
                    <label>Tanggal bayar</label><input type="date" name="paid_at" value="<?= h(date('Y-m-d')) ?>" required<?= $kunci ?>>
                    <label>Jumlah dibayar</label><input type="text" name="amount" id="pay-amt" inputmode="numeric" value="<?= $sisa > 0.5 ? (int) round($sisa) : '' ?>" placeholder="Rp" required<?= $kunci ?>>
                    <label>Cara bayar</label>
                    <select name="method"<?= $kunci ?>><option>Transfer</option><option>Tunai</option><option>Giro</option><option>Lainnya</option></select>
                    <label>No. referensi / bukti <span class="muted" style="font-weight:400">(opsional)</span></label><input type="text" name="ref_no" maxlength="80"<?= $kunci ?>>
                    <label>Catatan <span class="muted" style="font-weight:400">(mis. DP 50%, pelunasan)</span></label><input type="text" name="note" maxlength="190"<?= $kunci ?>>
                    <?php if ($bisaIsi): ?><div style="margin-top:12px"><button type="submit">Catat pembayaran</button></div><?php endif; ?>
                </form>
            </div>
        </div>
        <?php
    });
}

function payment_invoice_save(PDO $pdo): void
{
    require_permission('manage_payments');
    verify_csrf();
    $id  = (int) post('id', 0);
    $trx = _pay_muat($pdo, $id, true);
    $tgl = fn($v) => (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) && strtotime((string) $v) !== false) ? (string) $v : null;
    $jml = trim((string) post('invoice_amount', ''));
    $jml = $jml === '' ? null : parse_rupiah($jml);
    if ($jml !== null && $jml < 0) { flash('Jumlah tagihan tidak boleh negatif.'); _pay_kembali($id); }
    $baru = [
        'invoice_no'     => trim((string) post('invoice_no', '')) ?: null,
        'invoice_date'   => $tgl(post('invoice_date', '')),
        'invoice_due'    => $tgl(post('invoice_due', '')),
        'invoice_amount' => $jml,
    ];
    $lama = array_intersect_key($trx, $baru);
    $pdo->prepare('UPDATE transactions SET invoice_no = :invoice_no, invoice_date = :invoice_date, invoice_due = :invoice_due,
                          invoice_amount = :invoice_amount, updated_at = NOW(), updated_by = :by WHERE id = :id')
        ->execute($baru + [':by' => (string) ($_SESSION['user']['name'] ?? ''), ':id' => $id]);
    audit($pdo, 'update', 'transactions', (string) $id, $baru, $lama, 'payments');
    flash('Invoice disimpan.');
    _pay_kembali($id);
}

function payment_add(PDO $pdo): void
{
    require_permission('manage_payments');
    verify_csrf();
    $id  = (int) post('id', 0);
    _pay_muat($pdo, $id, true);
    $tgl = (string) post('paid_at', '');
    $jml = parse_rupiah((string) post('amount', ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl) || strtotime($tgl) === false) { flash('Tanggal bayar tidak valid.'); _pay_kembali($id); }
    if ($jml <= 0) { flash('Jumlah dibayar harus lebih dari nol.'); _pay_kembali($id); }
    $baris = [
        'paid_at' => $tgl, 'amount' => $jml,
        'method'  => in_array(post('method'), ['Transfer', 'Tunai', 'Giro', 'Lainnya'], true) ? (string) post('method') : null,
        'ref_no'  => mb_substr(trim((string) post('ref_no', '')), 0, 80) ?: null,
        'note'    => mb_substr(trim((string) post('note', '')), 0, 190) ?: null,
    ];
    $pdo->prepare('INSERT INTO transaction_payments (property_id, transaction_id, paid_at, amount, method, ref_no, note, created_by)
                   VALUES (:pid, :tid, :paid_at, :amount, :method, :ref_no, :note, :by)')
        ->execute($baris + [':pid' => current_property_id(), ':tid' => $id, ':by' => (string) ($_SESSION['user']['name'] ?? '')]);
    audit($pdo, 'create', 'transaction_payments', (string) $pdo->lastInsertId(), $baris + ['transaction_id' => $id], [], 'payments');
    flash('Pembayaran dicatat.');
    _pay_kembali($id);
}

function payment_delete(PDO $pdo): void
{
    require_permission('manage_payments');
    verify_csrf();
    $id = (int) post('id', 0);
    _pay_muat($pdo, $id, true);
    $payId = (int) post('pay_id', 0);
    $st = $pdo->prepare('SELECT * FROM transaction_payments WHERE id = ? AND transaction_id = ? AND deleted_at IS NULL');
    $st->execute([$payId, $id]);
    if ($lama = $st->fetch()) {
        $pdo->prepare('UPDATE transaction_payments SET deleted_at = NOW(), deleted_by = ? WHERE id = ?')
            ->execute([(string) ($_SESSION['user']['name'] ?? ''), $payId]);
        audit($pdo, 'delete', 'transaction_payments', (string) $payId, [], $lama, 'payments');
        flash('Pembayaran dihapus.');
    }
    _pay_kembali($id);
}
