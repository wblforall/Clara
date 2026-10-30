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
    // Urutan daftar. Tim mencari berdasarkan NAMA PENYEWA — menelusuri daftar
    // sampai ketemu, seperti membuka buku — jadi abjad nama yang jadi bawaan,
    // bukan tanggal mulai seperti sebelumnya.
    $urutKolom = [
        'penyewa'  => 'x.company_name', 'unit' => 'x.master_code', 'kontrak' => 'x.contract_no',
        'mulai'    => 'x.start_date',   'pic'  => 'x.pic_name',    'tagihan' => 'x.tagihan',
        'terbayar' => 'x.terbayar',     'sisa' => '(x.tagihan - x.terbayar)',
        'invoice'  => 'x.invoice_no',   'status' => 'x.bayar_status',
    ];
    $urut = (string) getv('sort', 'penyewa');
    if (!isset($urutKolom[$urut])) $urut = 'penyewa';
    $arah = strtolower((string) getv('dir', '')) === 'desc' ? 'DESC' : 'ASC';

    [$sf, $sp] = current_sales_scope_sql($pdo, $pid, 't.pic_name', 't.created_by');
    $dalam = "SELECT t.id, t.skp_id, t.property_id, t.module, t.master_code, t.start_date, t.end_date, t.final_amount,
                     t.pic_name, t.created_by, t.invoice_no, t.invoice_date, t.invoice_due, t.invoice_amount,
                     t.contract_no, t.contract_date, t.area_sqm, t.unit_rate, t.pricing_type, t.content_note,
                     c.company_name, c.brand_name, c.business_type, ct.name AS cp_name,
                     " . pembayaran_select_sql('t') . "
              FROM transactions t
                   LEFT JOIN master_clients c ON c.id = t.client_id
                   LEFT JOIN master_client_contacts ct ON ct.id = t.contact_id
              WHERE " . implode(' AND ', $where) . $sf;
    // Campuran :nama dan ? tidak boleh dalam satu pernyataan — ubah ? milik
    // pembatas sales menjadi parameter bernama.
    $n = 0;
    $dalam = preg_replace_callback('/\?/', function () use (&$n) { return ':sc' . $n++; }, $dalam);
    // Pencarian dikerjakan di lapisan luar karena nomor kontrak baru ada di
    // sana — ia hasil sub-select, bukan kolom tabel.
    $cariSql = '';
    if ($cari !== '') {
        $cariSql = '(x.company_name LIKE :q1 OR x.brand_name LIKE :q2 OR x.master_code LIKE :q3
                     OR x.invoice_no LIKE :q4 OR x.contract_no LIKE :q5)';
        foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $k) $params[":$k"] = "%$cari%";
    }
    $syaratStatus = $status === 'lewat' ? 'x.bayar_lewat = 1' : ($status !== '' ? 'x.bayar_status = :status' : '');
    $gabung = fn(array $a) => ($a = array_filter($a)) ? ' WHERE ' . implode(' AND ', $a) : '';
    $luar  = "FROM ($dalam) x" . $gabung([$cariSql, $syaratStatus]);
    $bind = $params;
    foreach ($sp as $i => $v) $bind[':sc' . $i] = $v;
    if ($status !== '' && $status !== 'lewat') $bind[':status'] = $status;

    // Hitungan di lencana saringan memakai SEMUA baris (tanpa saringan status),
    // kalau tidak lencana lain jadi 0 begitu satu status dipilih.
    $dasar   = "FROM ($dalam) x" . $gabung([$cariSql]);
    $bindDasar = $bind; unset($bindDasar[':status']);
    $chip = $pdo->prepare("SELECT COUNT(*) n_all, SUM(bayar_status='belum_invoice') n_belum_inv, SUM(bayar_status='belum_bayar') n_belum,
                                  SUM(bayar_status='sebagian') n_sebagian, SUM(bayar_status='lunas') n_lunas,
                                  SUM(bayar_lewat=1) n_lewat $dasar");
    $chip->execute($bindDasar);
    $ringkas = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(tagihan),0) tagihan, COALESCE(SUM(terbayar),0) terbayar $luar");
    $ringkas->execute($bind);
    $sum = array_map(fn($v) => (float) $v, $chip->fetch() + $ringkas->fetch());

    // Berapa yang SEHARUSNYA ditransfer client — sewa + PPN + listrik +
    // deposit. Inilah angka yang dicocokkan tim: kalau semuanya sudah dibayar,
    // "Sudah dibayar" mestinya sama dengan angka ini, bukan dengan Tagihan.
    // Kolom Tagihan sendiri tidak memuat listrik & deposit, dan itulah sebab
    // pembayaran kerap terlihat lebih besar daripada tagihannya.
    //
    // Dihitung atas SELURUH baris tersaring (bukan hanya sehalaman), dengan
    // kolom seperlunya saja supaya tidak memberatkan.
    $semua = $pdo->prepare("SELECT x.id, x.skp_id, x.module, x.master_code, x.start_date, x.end_date, x.tagihan $luar");
    $semua->execute($bind);
    $sum['penuh'] = 0.0;
    foreach (pembayaran_lengkapi_komponen($pdo, $semua->fetchAll()) as $b) $sum['penuh'] += (float) $b['k_perkiraan'];

    $halaman = max(1, (int) getv('page', 1));
    $per     = 200;
    $batas   = $semuaBaris ? '' : ' LIMIT ' . $per . ' OFFSET ' . (($halaman - 1) * $per);
    // Yang kosong selalu di belakang — daftar yang diawali deretan strip
    // membuat orang mengira datanya hilang.
    $kolom = $urutKolom[$urut];
    $kosongAkhir = in_array($urut, ['kontrak', 'invoice', 'penyewa', 'pic'], true)
        ? "($kolom IS NULL OR $kolom = '') ASC, " : '';
    $st = $pdo->prepare("SELECT x.* $luar ORDER BY $kosongAkhir$kolom $arah, x.start_date DESC, x.id DESC" . $batas);
    $st->execute($bind);
    $rows = pembayaran_nama_unit($pdo, pembayaran_lengkapi_komponen($pdo, $st->fetchAll()), $pid);
    return [
        'rows' => $rows, 'sum' => $sum, 'halaman' => $halaman, 'per' => $per,
        'f' => ['period' => $periode, 'mode' => $mode, 'module' => $modul, 'pic' => $pic, 'status' => $status,
                'q' => $cari, 'sort' => $urut, 'dir' => strtolower($arah)],
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
            <div><label class="muted">Cari</label><input type="text" name="q" value="<?= h($f['q']) ?>" placeholder="Nama, unit, no. kontrak, invoice" style="width:220px"></div>
            <input type="hidden" name="sort" value="<?= h($f['sort']) ?>"><input type="hidden" name="dir" value="<?= h($f['dir']) ?>">
            <button type="submit">Terapkan</button>
            <a class="btn light" href="?r=payments&period=semua">Semua periode</a>
            <?php /* Unduhan utama bergaya berkas Income Marketing tim: kolomnya
                     sama, lebarnya sudah diatur, angkanya sudah berformat rupiah.
                     CSV tetap disediakan untuk yang mau datanya mentah. */ ?>
            <a class="btn" href="<?= h($qs(['r' => 'payments_xlsx'])) ?>">Unduh Excel</a>
            <a class="btn light" href="<?= h($qs(['r' => 'payments_csv'])) ?>">CSV mentah</a>
        </form>

        <?php
        // Kartu "Seharusnya ditransfer" adalah jawaban atas pertanyaan yang
        // selama ini menggantung: kenapa yang dibayar lebih besar daripada
        // tagihan. Karena yang ditransfer sudah termasuk PPN, listrik, dan
        // deposit — dan terhadap angka itulah pembayaran dicocokkan.
        $kurangPenuh = (float) $sum['penuh'] - (float) $sum['terbayar'];
        $kartu = [
            ['Tagihan', money($sum['tagihan']), 'var(--primary)',
             'Nilai sewa, atau jumlah invoice bila diisi sendiri. Belum termasuk PPN, listrik, dan deposit.'],
            ['Seharusnya ditransfer', money($sum['penuh']), '#0F766E',
             'Sewa + PPN + listrik + security deposit. Kalau semuanya sudah dibayar, "Sudah dibayar" akan sama dengan angka ini.'],
            ['Sudah dibayar', money($sum['terbayar']), '#047857', 'Seluruh pembayaran yang tercatat masuk.'],
            ['Belum dibayar', money($kurangPenuh), $kurangPenuh > 0.5 ? '#B91C1C' : '#047857',
             'Seharusnya ditransfer dikurangi yang sudah masuk.'],
            ['Lewat jatuh tempo', (int) $sum['n_lewat'] . ' transaksi', (int) $sum['n_lewat'] ? '#B91C1C' : 'var(--muted)',
             'Invoice yang tanggal jatuh temponya sudah lewat dan belum lunas.'],
        ];
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(176px,1fr));gap:12px;margin-bottom:14px">
            <?php foreach ($kartu as [$lbl, $val, $clr, $arti]): ?>
                <div class="card" style="padding:12px 14px;border-top:3px solid <?= $clr ?>" title="<?= h($arti) ?>">
                    <div class="muted" style="font-size:12px"><?= h($lbl) ?></div>
                    <div style="font-size:19px;font-weight:800;color:<?= $clr ?>"><?= h($val) ?></div>
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
            <?php
            // Judul kolom bisa diklik untuk mengurutkan. Panah menandai kolom yang
            // sedang dipakai, supaya jelas daftar ini sedang urut menurut apa.
            $th = function (string $label, string $key, string $rata = 'left') use ($f, $qs) {
                $aktif = $f['sort'] === $key;
                $balik = ($aktif && $f['dir'] === 'asc') ? 'desc' : 'asc';
                $panah = $aktif ? ($f['dir'] === 'asc' ? ' ▲' : ' ▼') : '';
                echo '<th style="text-align:' . $rata . ';white-space:nowrap"><a href="'
                   . h($qs(['sort' => $key, 'dir' => $balik, 'page' => ''])) . '" title="Urutkan menurut '
                   . h(mb_strtolower($label)) . '" style="color:inherit;text-decoration:none;border-bottom:1px dotted #CBD5E1'
                   . ($aktif ? ';font-weight:800;border-bottom-color:#0F766E' : '') . '">' . h($label) . $panah . '</a></th>';
            };
            ?>
            <thead><tr>
                <?php $th('Unit', 'unit'); $th('No. Kontrak', 'kontrak'); $th('Penyewa', 'penyewa'); ?>
                <?php $th('Periode sewa', 'mulai'); $th('PIC', 'pic'); $th('Tagihan', 'tagihan', 'right'); ?>
                <?php $th('Terbayar', 'terbayar', 'right'); $th('Sisa', 'sisa', 'right'); ?>
                <?php $th('Invoice', 'invoice'); $th('Status', 'status'); ?>
                <th></th></tr></thead>
            <tbody>
            <?php if (!$d['rows']): ?>
                <tr><td colspan="11" style="text-align:center;color:var(--muted);padding:24px">Tidak ada transaksi yang sesuai saringan.</td></tr>
            <?php endif; ?>
            <?php foreach ($d['rows'] as $r):
                $sisaR = (float) $r['tagihan'] - (float) $r['terbayar'];
                // Komponen di luar nilai transaksi: inilah sebab bayaran kerap
                // lebih besar daripada tagihannya.
                $tambahan = (float) $r['k_ppn'] + (float) $r['k_listrik'] + (float) $r['k_deposit'];
                $cocokPerkiraan = $sisaR < -0.5 && abs((float) $r['terbayar'] - (float) $r['k_perkiraan']) <= 1000; ?>
                <tr>
                    <td><b><?= h($r['unit_name'] !== '' ? $r['unit_name'] : $r['master_code']) ?></b>
                        <br><span class="muted" style="font-size:11px"><?= h($r['master_code']) ?> &middot; <?= h($modLabel[$r['module']] ?? $r['module']) ?></span></td>
                    <td style="white-space:nowrap">
                        <?php if (!empty($r['contract_no'])): ?><b><?= h($r['contract_no']) ?></b>
                        <?php else: ?><span class="muted" title="Nomor kontrak diisi Departemen Legal saat menyetujui Permintaan Kontrak">—</span><?php endif; ?>
                    </td>
                    <td><?= h($r['company_name'] ?? '-') ?><?= !empty($r['brand_name']) ? '<br><span class="muted" style="font-size:11px">' . h($r['brand_name']) . '</span>' : '' ?></td>
                    <td style="white-space:nowrap"><?= h($r['start_date']) ?><br><span class="muted" style="font-size:11px">s/d <?= h($r['end_date']) ?></span></td>
                    <td><?= h($r['pic_name'] ?? '-') ?></td>
                    <td style="text-align:right"><?= money($r['tagihan']) ?>
                        <?php if ($tambahan > 0.5): ?>
                            <br><span class="muted" style="font-size:11px;white-space:nowrap"
                                  title="Sewa <?= h(money($r['k_sewa'])) ?>&#10;PPN <?= h(money($r['k_ppn'])) ?><?php
                                    if ((float) $r['k_listrik'] > 0) echo '&#10;Listrik ' . h(money($r['k_listrik']));
                                    if ((float) $r['k_deposit'] > 0) echo '&#10;Deposit ' . h(money($r['k_deposit']));
                                  ?>&#10;&#10;Perkiraan yang ditransfer client: <?= h(money($r['k_perkiraan'])) ?>">
                                +PPN<?= (float) $r['k_listrik'] > 0 ? '+listrik' : '' ?><?= (float) $r['k_deposit'] > 0 ? '+deposit' : '' ?>
                                &rarr; <?= h(money($r['k_perkiraan'])) ?></span>
                        <?php endif; ?></td>
                    <td style="text-align:right"><?= money($r['terbayar']) ?>
                        <?php if ($cocokPerkiraan): ?><br><span style="font-size:11px;color:#047857;white-space:nowrap"
                            title="Bayarannya pas dengan sewa + PPN + listrik/deposit — bukan kelebihan bayar">sesuai rincian</span><?php endif; ?></td>
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
    // PHP 8.4 memperingatkan bila ketiga pembatas fputcsv() tidak disebutkan,
    // dan peringatan itu ikut tertulis ke dalam berkasnya — CSV-nya jadi rusak
    // saat dibuka. Jadi ditulis apa adanya, sama dengan bawaan sebelumnya.
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Unit', 'Nama Unit', 'Modul', 'No. Kontrak', 'Penyewa', 'Brand', 'Mulai', 'Selesai', 'PIC', 'Tagihan',
                 'PPN', 'Listrik', 'Deposit', 'Perkiraan Transfer', 'Terbayar', 'Sisa',
                 'No. Invoice', 'Tgl Invoice', 'Jatuh Tempo', 'Status', 'Lewat Tempo'], ';', '"', '\\');
    foreach ($d['rows'] as $r) {
        // Sel yang diawali = + - @ dibaca Excel sebagai rumus; awali apostrof.
        $aman = fn($v) => ($v !== null && $v !== '' && strpos('=+-@', (string) $v[0]) !== false) ? "'" . $v : $v;
        fputcsv($o, [$r['master_code'], $aman($r['unit_name'] ?? ''), $r['module'], $aman($r['contract_no']), $aman($r['company_name']), $aman($r['brand_name']),
            $r['start_date'], $r['end_date'], $r['pic_name'],
            (float) $r['tagihan'], (float) $r['k_ppn'], (float) $r['k_listrik'], (float) $r['k_deposit'], (float) $r['k_perkiraan'],
            (float) $r['terbayar'], (float) $r['tagihan'] - (float) $r['terbayar'], $aman($r['invoice_no']),
            $r['invoice_date'], $r['invoice_due'], $st[$r['bayar_status']][0] ?? $r['bayar_status'], $r['bayar_lewat'] ? 'Ya' : ''], ';', '"', '\\');
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

/**
 * Unduhan Excel bergaya berkas Income Marketing tim.
 *
 * Susunan kolomnya mengikuti berkas yang mereka pakai selama ini
 * (NO, KON, PENANGGUNG JAWAB, PERUSAHAAN, NAMA STAND, LOK, HARGA, PERIODE,
 * LUAS, JANGKA WAKTU, NILAI SEWA, PPN, SUB TTL, PEMBAYARAN, TANGGAL
 * PEMBAYARAN, PIC, PERIODE BULAN, NO INV, KETERANGAN), ditambah tiga kolom
 * yang hanya dipunyai CLARA: biaya listrik, deposit, dan total yang
 * sebenarnya ditagihkan — itulah yang menjelaskan kenapa pembayaran kerap
 * lebih besar daripada nilai sewanya.
 *
 * Unit disebut dengan NAMA, bukan kode, karena begitulah tim menyebutnya.
 */
function payments_xlsx(PDO $pdo): void
{
    require_permission('view_payments');
    require_once dirname(__DIR__) . '/Xlsx.php';

    $d   = _pay_query($pdo, true);
    $f   = $d['f'];
    $bln = [1=>'JANUARI','FEBRUARI','MARET','APRIL','MEI','JUNI','JULI','AGUSTUS','SEPTEMBER','OKTOBER','NOVEMBER','DESEMBER'];
    $blnPendek = [1=>'JAN','FEB','MAR','APR','MEI','JUN','JUL','AGU','SEP','OKT','NOV','DES'];
    $tglPanjang = function (?string $t) use ($bln): string {
        if (!$t || !strtotime($t)) return '';
        return (int) date('j', strtotime($t)) . ' ' . mb_convert_case($bln[(int) date('n', strtotime($t))], MB_CASE_TITLE)
             . ' ' . date('Y', strtotime($t));
    };

    // Tanggal & keterangan pembayaran per transaksi — satu kueri untuk semua baris.
    $bayar = [];
    if ($d['rows']) {
        $id = array_map(fn($r) => (int) $r['id'], $d['rows']);
        $st = $pdo->prepare('SELECT transaction_id, paid_at, note FROM transaction_payments
                              WHERE deleted_at IS NULL AND transaction_id IN ('
                            . implode(',', array_fill(0, count($id), '?')) . ') ORDER BY paid_at, id');
        $st->execute($id);
        foreach ($st->fetchAll() as $b) {
            $bayar[(int) $b['transaction_id']]['tgl'][] = date('d/m/y', strtotime((string) $b['paid_at']));
            foreach (preg_split('/\s*\·\s*/', (string) ($b['note'] ?? '')) as $k) {
                $k = trim($k);
                if ($k !== '') $bayar[(int) $b['transaction_id']]['ket'][$k] = true;
            }
        }
    }

    $judulBulan = ($f['period'] === '' || $f['period'] === 'semua')
        ? 'SEMUA PERIODE'
        : 'BULAN ' . $bln[(int) substr($f['period'], 5, 2)] . ' ' . substr($f['period'], 0, 4);
    $prop = current_property();

    $x = (new Xlsx('Income Marketing'))
        ->judul('LAPORAN INCOME MARKETING ' . $judulBulan . ' — ' . mb_strtoupper((string) ($prop['name'] ?? '')))
        ->kolom('NO', 5, 'tengah')
        ->kolom('KON', 14)
        ->kolom('PENANGGUNG JAWAB', 24)
        ->kolom('PERUSAHAAN', 28)
        ->kolom('NAMA STAND', 30)
        ->kolom('LOK', 30)
        ->kolom('HARGA /M²/HARI', 15, 'rupiah')
        ->kolom('PERIODE', 30)
        ->kolom('LUAS', 9, 'tengah')
        ->kolom('JANGKA WAKTU', 12, 'tengah')
        ->kolom('NILAI SEWA', 15, 'rupiah')
        ->kolom('PPN', 13, 'rupiah')
        ->kolom('SUB TTL', 15, 'rupiah')
        ->kolom('BIAYA LISTRIK', 14, 'rupiah')
        ->kolom('DEPOSIT', 14, 'rupiah')
        ->kolom('TOTAL DITAGIHKAN', 17, 'rupiah')
        ->kolom('PEMBAYARAN', 15, 'rupiah')
        ->kolom('TANGGAL PEMBAYARAN', 20)
        ->kolom('PIC', 14)
        ->kolom('PERIODE BULAN', 16)
        ->kolom('NO INV', 26)
        ->kolom('KETERANGAN', 24);

    $no = 0;
    foreach ($d['rows'] as $r) {
        $no++;
        $hari = (strtotime((string) $r['end_date']) - strtotime((string) $r['start_date'])) / 86400 + 1;   // dipakai juga oleh kolom harga
        $hari = $hari > 0 ? (int) round($hari) : 0;
        $b1 = (int) substr((string) $r['start_date'], 5, 2);
        $b2 = (int) substr((string) $r['end_date'], 5, 2);
        $periodeBulan = $b1 === $b2
            ? $bln[$b1] . ' ' . substr((string) $r['start_date'], 0, 4)
            : 'KON ' . $blnPendek[$b1] . '-' . $blnPendek[$b2];
        $ket = array_keys($bayar[(int) $r['id']]['ket'] ?? []);
        if (!$ket) $ket = [pembayaran_status_daftar()[$r['bayar_status']][0] ?? ''];

        $x->baris([
            $no,
            (string) $r['contract_no'],
            (string) $r['cp_name'],
            (string) $r['company_name'],
            // "NAMA STAND" di berkas mereka berbunyi "Jenis usaha (Merek)".
            trim(trim((string) $r['business_type']) . ' (' . trim((string) $r['brand_name']) . ')', ' ()') ?: (string) $r['brand_name'],
            trim(((string) ($r['unit_name'] ?? '')) !== '' ? (string) $r['unit_name'] : (string) $r['master_code']),
            // Kolom ini di berkas mereka berisi harga per m² per hari, dihitung
            // dari nilai sewanya — bukan tarif yang diketik di transaksi, karena
            // satuan tarif berbeda-beda menurut jenis harganya.
            ((float) $r['area_sqm'] > 0 && $hari > 0) ? round((float) $r['k_sewa'] / (float) $r['area_sqm'] / $hari) : '',
            $tglPanjang((string) $r['start_date']) . ' s/d ' . $tglPanjang((string) $r['end_date']),
            (float) $r['area_sqm'] > 0 ? rtrim(rtrim(number_format((float) $r['area_sqm'], 2, ',', ''), '0'), ',') . ' m²' : '',
            $hari ? $hari . ' Hari' : '',
            (float) $r['k_sewa'],
            (float) $r['k_ppn'],
            (float) $r['k_sewa'] + (float) $r['k_ppn'],
            (float) $r['k_listrik'] ?: '',
            (float) $r['k_deposit'] ?: '',
            (float) $r['k_perkiraan'],
            (float) $r['terbayar'] ?: '',
            implode(' + ', $bayar[(int) $r['id']]['tgl'] ?? []),
            (string) $r['pic_name'],
            $periodeBulan,
            (string) $r['invoice_no'],
            implode(' · ', array_filter($ket)),
        ]);
    }

    $x->unduh('Income-Marketing-' . ($f['period'] === 'semua' || $f['period'] === '' ? 'semua' : $f['period']) . '.xlsx');
}

function payment_form(PDO $pdo): void
{
    require_permission('view_payments');
    $pid = current_property_id();
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
    // Komponen di luar nilai transaksi (listrik, deposit) + PPN-nya, supaya
    // selisih bayar punya penjelasan di layar ini, bukan dicari-cari tiap bulan.
    $trx = pembayaran_nama_unit($pdo, pembayaran_lengkapi_komponen($pdo, [$trx]), $pid)[0];
    // Dari mana nomor kontraknya datang — untuk keterangan kecil di bawah isian.
    $asal = $pdo->prepare("SELECT cr.contract_no, cr.legal_by FROM contract_requests cr
                             JOIN skp_documents sd ON sd.id = cr.skp_id
                            WHERE (sd.transaction_id = ? OR sd.id = ?)
                              AND cr.contract_no IS NOT NULL AND cr.contract_no <> ''
                            ORDER BY cr.id DESC LIMIT 1");
    $asal->execute([$id, (int) ($trx['skp_id'] ?? 0)]);
    $dariLegal = $asal->fetch() ?: [];

    layout('Tagihan & Pembayaran', function () use ($trx, $bayar, $back, $sisa, $modLabel, $id, $bisaIsi, $dariLegal) {
        $tagihan  = (float) $trx['tagihan'];
        $terbayar = (float) $trx['terbayar'];
        $persen   = $tagihan > 0 ? max(0, min(100, (int) round($terbayar / $tagihan * 100))) : 0;
        $tglID = function (?string $d): string {
            if (!$d || !strtotime($d)) return '-';
            $b = [1=>'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
            return (int) date('j', strtotime($d)) . ' ' . $b[(int) date('n', strtotime($d))] . ' ' . date('Y', strtotime($d));
        };
        // Di berkas lama tiap pembayaran diberi keterangan singkat (LUNAS,
        // BUKPOT, ...). Keterangan itulah yang membuat selisih bayar langsung
        // terbaca sebabnya, jadi disediakan sebagai tombol di form catat MAUPUN
        // form ubah — tetap boleh diketik bebas.
        $ketCepat = [
            'LUNAS'            => 'Dibayar penuh sesuai tagihan',
            'BUKPOT'           => 'Pajak dipotong client, yang masuk lebih kecil daripada invoice',
            'TERMASUK LISTRIK' => 'Transfer sudah termasuk biaya listrik',
            'TERMASUK DEPOSIT' => 'Transfer sudah termasuk security deposit',
            'DP 50%'           => 'Pembayaran muka',
            '2 KONTRAK'        => 'Satu transfer untuk dua kontrak sekaligus',
        ];
        ?>
        <style>
            /* Satu bahasa rupa untuk seluruh halaman ini: kotak putih, garis
               tipis, tanpa gradasi dan tanpa ikon — yang menonjol hanya angka. */
            .pf-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:14px}
            .pf-judul{font-size:21px;font-weight:800;line-height:1.2;color:#0F2A3F}
            .pf-sub{color:var(--muted);font-size:13px;margin-top:3px}
            .pf-kartu{background:#fff;border:1px solid var(--line);border-radius:12px}
            .pf-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1px;background:var(--line);
                border:1px solid var(--line);border-radius:12px;overflow:hidden;margin-bottom:14px}
            .pf-grid>div{background:#fff;padding:12px 16px}
            .pf-grid .l{font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#98A2B3}
            .pf-grid .v{font-size:14px;font-weight:600;margin-top:3px;color:#1F2937}
            .pf-angka{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:1px;background:var(--line);
                border:1px solid var(--line);border-radius:12px;overflow:hidden}
            .pf-angka>div{background:#fff;padding:14px 18px}
            .pf-angka .l{font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#98A2B3}
            .pf-angka .n{font-size:23px;font-weight:800;margin-top:2px;font-variant-numeric:tabular-nums;line-height:1.15}
            .pf-bar{height:6px;background:#EEF1F5;border-radius:99px;overflow:hidden;margin:14px 0 18px}
            .pf-bar i{display:block;height:100%;background:#0D9488}
            .pf-kolom{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:14px;align-items:start}
            .pf-kartu h3{margin:0;padding:14px 18px;font-size:14px;font-weight:700;border-bottom:1px solid var(--line);color:#1F2937}
            .pf-isi{padding:16px 18px}
            .pf-isi label{display:block;font-size:12px;font-weight:600;color:#475467;margin:12px 0 5px}
            .pf-isi label:first-child{margin-top:0}
            .pf-isi input,.pf-isi select{width:100%}
            .pf-dua{display:grid;grid-template-columns:1fr 1fr;gap:10px}
            .pf-bantu{font-size:11.5px;color:var(--muted);margin-top:5px;line-height:1.45}
            /* Awalan "Rp" menempel di kotaknya — tidak perlu menebak satuannya. */
            .rp-box{display:flex;align-items:stretch;border:1px solid var(--line);border-radius:9px;overflow:hidden;background:#fff}
            .rp-box>span{display:flex;align-items:center;padding:0 12px;background:#F1F5F9;color:#475569;
                font-weight:700;font-size:12.5px;border-right:1px solid var(--line)}
            .rp-box input{border:0!important;border-radius:0!important;flex:1;min-width:0;box-shadow:none!important}
            .pf-cepat{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
            .pf-cepat button{font-size:11.5px;padding:4px 11px}
            .pf-rinci{width:100%;font-size:13px;border-collapse:collapse}
            .pf-rinci td{padding:5px 0;border:0}
            .pf-rinci td:last-child{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
            .pf-rinci tr.jml td{border-top:1px solid var(--line);font-weight:800;padding-top:9px}
            .pf-bayar{width:100%;font-size:13px;border-collapse:collapse;margin-bottom:4px}
            .pf-bayar th{font-size:10.5px;letter-spacing:.05em;color:#98A2B3;text-align:left;padding:0 0 6px;border-bottom:1px solid var(--line)}
            .pf-bayar td{padding:9px 0;border-bottom:1px solid #F5F7FA;vertical-align:top}
            .pf-bayar td.n{text-align:right;font-variant-numeric:tabular-nums;font-weight:700;white-space:nowrap}
            .pf-kosong{color:var(--muted);font-size:13px;padding:14px 0}
            .pf-tag{display:inline-block;font-size:10.5px;font-weight:700;padding:1px 7px;border-radius:4px;background:#F1F5F9;color:#475569;margin-right:4px}
            .pf-aksi{display:flex;gap:6px;justify-content:flex-end}
            .pf-aksi .btn{padding:3px 10px;font-size:11.5px}
            /* Baris yang sedang diperbaiki: formnya muncul tepat di bawah
               barisnya, supaya jelas angka mana yang sedang diubah.
               app.css memperlakukan setiap sel ber-colspan sebagai judul
               kelompok (tebal, HURUF BESAR) — di sini perlakuan itu ditolak. */
            .pf-ubah>td{background:#F8FAFC!important;border-top:0!important;
                border-bottom:1px solid var(--line)!important;padding:14px 12px!important;
                font-weight:400!important;font-size:13px!important;letter-spacing:normal!important;
                text-transform:none!important;color:#1F2937!important}
            .pf-ubah label{display:block;font-size:12px;font-weight:600;color:#475467;margin:10px 0 5px;
                text-transform:none;letter-spacing:normal}
            .pf-ubah .pf-dua>div>label{margin-top:0}
            .pf-ubah input,.pf-ubah select{width:100%;font-weight:400}
            .pf-ubah .pf-dua{margin-bottom:10px}
            .pf-ubah .pf-dua:last-of-type{margin-bottom:0}
            tr.pf-disorot td{background:#FFFBEB}
            /* Di layar kecil, mobile-tables.js mengubah tiap baris jadi kartu
               label-kiri/nilai-kanan. Kolom keterangan isinya bertingkat (cara
               bayar, nomor bukti, label, catatan perubahan) — dipepet ke kanan,
               hurufnya jadi menurun satu-satu. Kolom ini dibuat penuh. */
            @media (max-width: 768px) {
                .pf-bayar td.pf-ket{display:block;text-align:left}
                .pf-bayar td.pf-ket::before{display:block;flex:none;margin-bottom:4px}
                .pf-aksi{justify-content:flex-start}
            }
        </style>

        <div class="pf-top">
            <div>
                <?php /* Unit disebut dengan NAMA — itu yang dipakai tim sehari-hari;
                         kodenya tetap tertulis di bawahnya. */ ?>
                <div class="pf-judul"><?= h($trx['unit_name'] !== '' ? $trx['unit_name'] : $trx['master_code']) ?></div>
                <div class="pf-sub"><?= h($trx['master_code']) ?> &middot; <?= h($modLabel[$trx['module']] ?? $trx['module']) ?>
                    &middot; <?= h($trx['company_name'] ?? '-') ?><?= !empty($trx['brand_name']) ? ' (' . h($trx['brand_name']) . ')' : '' ?></div>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
                <?= pembayaran_badge((string) $trx['bayar_status'], (bool) $trx['bayar_lewat']) ?>
                <a class="btn light" href="?<?= $back !== '' ? h($back) : 'r=payments' ?>">Kembali ke daftar</a>
            </div>
        </div>

        <div class="pf-grid">
            <div><div class="l">Periode sewa</div><div class="v"><?= h($tglID($trx['start_date'])) ?> &ndash; <?= h($tglID($trx['end_date'])) ?></div></div>
            <div><div class="l">PIC</div><div class="v"><?= h($trx['pic_name'] ?: '-') ?></div></div>
            <div><div class="l">No. Kontrak</div><div class="v"><?= $trx['contract_no'] ? h($trx['contract_no']) : '<span style="color:#B45309;font-weight:500">belum diisi</span>' ?></div></div>
            <div><div class="l">Nilai transaksi</div><div class="v"><?= money($trx['final_amount']) ?></div></div>
        </div>

        <div class="pf-angka">
            <div><div class="l">Tagihan</div><div class="n"><?= money($tagihan) ?></div></div>
            <div><div class="l">Sudah dibayar</div><div class="n" style="color:#047857"><?= money($terbayar) ?></div></div>
            <div><div class="l"><?= $sisa < -0.5 ? 'Lebih bayar' : 'Sisa' ?></div>
                <div class="n" style="color:<?= $sisa > 0.5 ? '#B91C1C' : '#047857' ?>"><?= money(abs($sisa)) ?></div></div>
        </div>
        <div class="pf-bar"><i style="width:<?= (int) $persen ?>%"></i></div>

        <?php if (!$bisaIsi): ?>
            <div class="pf-kartu" style="padding:13px 18px;margin-bottom:14px;background:#F8FAFC">
                <b>Hanya melihat.</b> Anda bisa memeriksa invoice dan pembayaran di halaman ini,
                tetapi tidak mengubahnya. Yang mengisi adalah Admin Sales dan PIC transaksinya.
            </div>
        <?php endif; ?>

        <?php $kunci = $bisaIsi ? '' : ' disabled'; ?>
        <div class="pf-kolom">
            <form method="post" action="?r=payment_invoice_save" class="pf-kartu">
                <h3>Invoice</h3>
                <div class="pf-isi">
                    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int) $id ?>">
                    <input type="hidden" name="back" value="<?= h($back) ?>">

                    <?php /* Nomor kontrak diisi di sini juga, bukan hanya oleh Legal:
                             admin kerap sudah memegang nomornya lebih dulu, dan selama
                             nomornya cuma boleh lahir dari Legal, invoice tidak bisa
                             ditulis lengkap di sini. */ ?>
                    <div class="pf-dua">
                        <div>
                            <label>No. Kontrak</label>
                            <input type="text" name="contract_no" value="<?= h((string) $trx['contract_no']) ?>" maxlength="80" placeholder="00430/KS/e-Walk/BSB-BPP/X/2026"<?= $kunci ?>>
                        </div>
                        <div>
                            <label>Tanggal kontrak</label>
                            <input type="date" name="contract_date" value="<?= h((string) $trx['contract_date']) ?>"<?= $kunci ?>>
                        </div>
                    </div>
                    <div class="pf-bantu">
                        <?php if (!empty($dariLegal['contract_no'])): ?>
                            Dari Legal: <b><?= h($dariLegal['contract_no']) ?></b><?= $dariLegal['legal_by'] ? ' (' . h($dariLegal['legal_by']) . ')' : '' ?>.
                        <?php else: ?>
                            Dasar penerbitan invoice. Bisa diketik di sini, atau terisi sendiri saat Legal menyetujui Permintaan Kontrak.
                        <?php endif; ?>
                    </div>

                    <label>No. Invoice</label>
                    <input type="text" name="invoice_no" value="<?= h((string) $trx['invoice_no']) ?>" maxlength="60" placeholder="INV/CL-EWALK/26/X/001"<?= $kunci ?>>

                    <div class="pf-dua">
                        <div><label>Tanggal invoice</label><input type="date" name="invoice_date" value="<?= h((string) $trx['invoice_date']) ?>"<?= $kunci ?>></div>
                        <div><label>Jatuh tempo</label><input type="date" name="invoice_due" value="<?= h((string) $trx['invoice_due']) ?>"<?= $kunci ?>></div>
                    </div>

                    <label>Jumlah tagihan</label>
                    <div class="rp-box"><span>Rp</span>
                        <input type="text" name="invoice_amount" id="inv-amt" inputmode="numeric"
                               value="<?= $trx['invoice_amount'] === null ? '' : h(number_format((float) $trx['invoice_amount'], 0, ',', '.')) ?>"
                               placeholder="<?= h(number_format((float) $trx['final_amount'], 0, ',', '.')) ?>"<?= $kunci ?>></div>
                    <div class="pf-bantu">Dikosongkan berarti memakai nilai transaksi, <?= money($trx['final_amount']) ?>.</div>
                    <?php if ($bisaIsi): ?>
                    <div class="pf-cepat">
                        <button type="button" class="btn light" onclick="isiRp(<?= (int) round((float) $trx['final_amount']) ?>)">Nilai transaksi</button>
                        <button type="button" class="btn light" onclick="isiRp(<?= (int) round((float) $trx['k_sewa'] + (float) $trx['k_ppn']) ?>)">Nilai + PPN</button>
                        <?php if ((float) $trx['k_listrik'] > 0 || (float) $trx['k_deposit'] > 0): ?>
                            <button type="button" class="btn light" style="border-color:#A7F3D0;background:#ECFDF5;color:#065F46"
                                    onclick="isiRp(<?= (int) round((float) $trx['k_perkiraan']) ?>)">Seluruh rincian</button>
                        <?php endif; ?>
                        <button type="button" class="btn light" onclick="isiRp('')">Kosongkan</button>
                    </div>
                    <div style="margin-top:18px"><button type="submit">Simpan invoice</button></div>
                    <?php endif; ?>
                </div>
            </form>

            <div style="display:grid;gap:14px">
                <?php /* Rincian tagihan: memperlihatkan apa saja yang sebenarnya ditransfer
                         client. Nilai transaksi TIDAK memuat listrik dan deposit — keduanya
                         tercetak di surat penawaran & dokumen konfirmasi, tapi tidak ikut
                         ke kolom Tagihan. Di situlah "terbayar lebih besar" itu lahir. */ ?>
                <?php if ((float) $trx['k_listrik'] > 0 || (float) $trx['k_deposit'] > 0 || (float) $trx['k_ppn'] > 0): ?>
                <div class="pf-kartu">
                    <h3>Rincian tagihan</h3>
                    <div class="pf-isi">
                        <table class="pf-rinci">
                            <tr><td>Nilai sewa</td><td><?= money($trx['k_sewa']) ?></td></tr>
                            <tr><td>PPN 12% <span class="muted">(nilai &times; 11/12 &times; 12%)</span></td><td><?= money($trx['k_ppn']) ?></td></tr>
                            <?php if ((float) $trx['k_listrik'] > 0): ?>
                            <tr><td>Biaya listrik <span class="muted">(dari dokumen konfirmasi)</span></td><td><?= money($trx['k_listrik']) ?></td></tr>
                            <?php endif; ?>
                            <?php if ((float) $trx['k_deposit'] > 0): ?>
                            <tr><td>Security deposit <span class="muted">(belum disetor)</span></td><td><?= money($trx['k_deposit']) ?></td></tr>
                            <?php endif; ?>
                            <tr class="jml"><td>Perkiraan yang ditransfer client</td><td><?= money($trx['k_perkiraan']) ?></td></tr>
                        </table>
                        <div class="pf-bantu" style="margin-top:10px">Angka <b>Tagihan</b> hanya nilai sewa (atau jumlah invoice bila diisi sendiri).
                            Listrik dan deposit tidak termasuk nilai transaksi, jadi pembayaran yang sudah mencakup keduanya akan terlihat lebih besar &mdash; itu wajar, bukan salah input.</div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="pf-kartu">
                    <h3>Pembayaran masuk</h3>
                    <div class="pf-isi">
                        <?php if (!$bayar): ?>
                            <div class="pf-kosong">Belum ada pembayaran tercatat.</div>
                        <?php else: ?>
                        <table class="pf-bayar">
                            <thead><tr><th>Tanggal</th><th>Cara &amp; keterangan</th><th style="text-align:right">Jumlah</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($bayar as $p): $k = (int) $p['id']; ?>
                                <tr id="bayar-<?= $k ?>">
                                    <td style="white-space:nowrap"><?= h($tglID($p['paid_at'])) ?></td>
                                    <td class="pf-ket"><?= h(trim((string) ($p['method'] ?? ''))) ?><?= $p['ref_no'] ? ' <span class="muted">' . h($p['ref_no']) . '</span>' : '' ?>
                                        <?php if ($p['note']): ?><br><?php foreach (preg_split('/\s*·\s*/', (string) $p['note']) as $bagian):
                                            if (trim($bagian) === '') continue; ?><span class="pf-tag"><?= h(trim($bagian)) ?></span><?php endforeach; ?><?php endif; ?>
                                        <?php /* Baris yang pernah diperbaiki menyebutkannya sendiri —
                                                 angka yang diam-diam berubah lebih meresahkan daripada
                                                 angka yang salah. */ ?>
                                        <?php if (!empty($p['updated_at'])): ?><div class="pf-bantu" style="margin-top:3px">diubah
                                            <?= h($tglID(substr((string) $p['updated_at'], 0, 10))) ?><?= $p['updated_by'] ? ' oleh ' . h($p['updated_by']) : '' ?></div><?php endif; ?></td>
                                    <td class="n"><?= money($p['amount']) ?></td>
                                    <td>
                                        <?php if ($bisaIsi): ?><div class="pf-aksi">
                                            <button type="button" class="btn light" onclick="ubahBayar(<?= $k ?>)">Ubah</button>
                                            <form method="post" action="?r=payment_delete" onsubmit="return confirm('Hapus pembayaran ini?')" style="display:inline">
                                            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $id ?>">
                                            <input type="hidden" name="pay_id" value="<?= $k ?>"><input type="hidden" name="back" value="<?= h($back) ?>">
                                            <button type="submit" class="btn light" style="color:#B91C1C">Hapus</button></form>
                                        </div><?php endif; ?>
                                    </td>
                                </tr>
                                <?php if ($bisaIsi): ?>
                                <tr class="pf-ubah" id="ubah-<?= $k ?>" style="display:none">
                                    <td colspan="4">
                                        <form method="post" action="?r=payment_edit">
                                            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                                            <input type="hidden" name="id" value="<?= (int) $id ?>">
                                            <input type="hidden" name="pay_id" value="<?= $k ?>">
                                            <input type="hidden" name="back" value="<?= h($back) ?>">
                                            <div class="pf-dua">
                                                <div><label>Tanggal bayar</label>
                                                    <input type="date" name="paid_at" value="<?= h((string) $p['paid_at']) ?>" required></div>
                                                <div><label>Jumlah dibayar</label>
                                                    <div class="rp-box"><span>Rp</span>
                                                        <input type="text" name="amount" class="rp-ketik" inputmode="numeric"
                                                               value="<?= h(number_format((float) $p['amount'], 0, ',', '.')) ?>" required></div></div>
                                            </div>
                                            <div class="pf-dua">
                                                <div><label>Cara bayar</label>
                                                    <select name="method">
                                                        <?php foreach (['Transfer', 'Tunai', 'Giro', 'Lainnya'] as $cara): ?>
                                                            <option<?= $cara === trim((string) ($p['method'] ?? '')) ? ' selected' : '' ?>><?= h($cara) ?></option>
                                                        <?php endforeach; ?>
                                                    </select></div>
                                                <div><label>No. referensi / bukti <span class="muted" style="font-weight:400">(opsional)</span></label>
                                                    <input type="text" name="ref_no" maxlength="80" value="<?= h((string) ($p['ref_no'] ?? '')) ?>"></div>
                                            </div>
                                            <label>Keterangan</label>
                                            <input type="text" name="note" id="ket-<?= $k ?>" maxlength="190" value="<?= h((string) ($p['note'] ?? '')) ?>">
                                            <div class="pf-cepat">
                                                <?php foreach ($ketCepat as $teks => $arti): ?>
                                                    <button type="button" class="btn light" title="<?= h($arti) ?>"
                                                            onclick="tambahKet('<?= h($teks) ?>', 'ket-<?= $k ?>')"><?= h($teks) ?></button>
                                                <?php endforeach; ?>
                                                <button type="button" class="btn light" style="color:#B91C1C"
                                                        onclick="document.getElementById('ket-<?= $k ?>').value=''">Kosongkan</button>
                                            </div>
                                            <div style="margin-top:14px;display:flex;gap:8px">
                                                <button type="submit">Simpan perubahan</button>
                                                <button type="button" class="btn light" onclick="ubahBayar(<?= $k ?>)">Batal</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>

                        <?php if ($bisaIsi): ?>
                        <form method="post" action="?r=payment_add" style="border-top:1px solid var(--line);margin-top:14px;padding-top:14px">
                            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int) $id ?>">
                            <input type="hidden" name="back" value="<?= h($back) ?>">
                            <div class="pf-dua">
                                <div><label>Tanggal bayar</label><input type="date" name="paid_at" value="<?= h(date('Y-m-d')) ?>" required></div>
                                <div><label>Jumlah dibayar</label>
                                    <div class="rp-box"><span>Rp</span>
                                        <input type="text" name="amount" id="pay-amt" inputmode="numeric"
                                               value="<?= $sisa > 0.5 ? h(number_format($sisa, 0, ',', '.')) : '' ?>" placeholder="0" required></div></div>
                            </div>
                            <label>Cara bayar</label>
                            <select name="method"><option>Transfer</option><option>Tunai</option><option>Giro</option><option>Lainnya</option></select>
                            <label>No. referensi / bukti <span class="muted" style="font-weight:400">(opsional)</span></label>
                            <input type="text" name="ref_no" maxlength="80">
                            <label>Keterangan <span class="muted" style="font-weight:400">(klik salah satu, atau ketik sendiri)</span></label>
                            <input type="text" name="note" id="pay-note" maxlength="190" placeholder="mis. LUNAS, BUKPOT, DP 50%">
                            <div class="pf-cepat">
                                <?php foreach ($ketCepat as $teks => $arti): ?>
                                    <button type="button" class="btn light" title="<?= h($arti) ?>"
                                            onclick="tambahKet('<?= h($teks) ?>')"><?= h($teks) ?></button>
                                <?php endforeach; ?>
                                <button type="button" class="btn light" style="color:#B91C1C" onclick="document.getElementById('pay-note').value=''">Kosongkan</button>
                            </div>
                            <div style="margin-top:18px"><button type="submit">Catat pembayaran</button></div>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <script>
        // Ribuan diberi titik sambil diketik, sama seperti kotak rupiah lain.
        function rapikanRp(el) {
            el.addEventListener('input', function () {
                var a = this.value.replace(/\D/g, '');
                this.value = a ? parseInt(a, 10).toLocaleString('id-ID') : '';
            });
        }
        ['inv-amt', 'pay-amt'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) rapikanRp(el);
        });
        Array.prototype.forEach.call(document.querySelectorAll('.rp-ketik'), rapikanRp);
        // Satu baris diperbaiki pada satu waktu: barisnya disembunyikan, form
        // ubahnya muncul di tempatnya. Yang lain ikut ditutup supaya tidak ada
        // dua form terbuka yang isinya mirip.
        function ubahBayar(k) {
            var baris = document.getElementById('bayar-' + k), form = document.getElementById('ubah-' + k);
            if (!baris || !form) return;
            var buka = form.style.display === 'none';
            if (buka) {
                Array.prototype.forEach.call(document.querySelectorAll('tr.pf-ubah'), function (f) {
                    if (f === form || f.style.display === 'none') return;
                    f.style.display = 'none';
                    var b = document.getElementById('bayar-' + f.id.slice(5));
                    if (b) b.classList.remove('pf-disorot');
                });
            }
            form.style.display = buka ? '' : 'none';
            baris.classList.toggle('pf-disorot', buka);
        }
        function isiRp(n) {
            var el = document.getElementById('inv-amt');
            el.value = n === '' ? '' : Number(n).toLocaleString('id-ID');
        }
        function tambahKet(t, id) {
            var n = document.getElementById(id || 'pay-note');
            if (!n) return;
            n.value = n.value.trim() ? n.value.trim() + ' · ' + t : t;
        }
        </script>
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
        // Nomor kontrak boleh diketik admin di sini — tidak menunggu Legal.
        'contract_no'    => mb_substr(trim((string) post('contract_no', '')), 0, 80) ?: null,
        'contract_date'  => $tgl(post('contract_date', '')),
    ];
    $lama = array_intersect_key($trx, $baru);
    $pdo->prepare('UPDATE transactions SET invoice_no = :invoice_no, invoice_date = :invoice_date, invoice_due = :invoice_due,
                          invoice_amount = :invoice_amount, contract_no = :contract_no, contract_date = :contract_date,
                          updated_at = NOW(), updated_by = :by WHERE id = :id')
        ->execute($baru + [':by' => (string) ($_SESSION['user']['name'] ?? ''), ':id' => $id]);
    audit($pdo, 'update', 'transactions', (string) $id, $baru, $lama, 'payments');
    flash('Invoice disimpan.');
    _pay_kembali($id);
}

/**
 * Isian satu baris pembayaran dari form — dipakai saat mencatat maupun saat
 * memperbaiki. Isian yang tidak masuk akal tidak pernah lolos ke basis data:
 * pengguna dikembalikan ke halamannya dengan pesan, bukan disimpan diam-diam.
 */
function _pay_baris_post(int $id): array
{
    $tgl = (string) post('paid_at', '');
    $jml = parse_rupiah((string) post('amount', ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl) || strtotime($tgl) === false) { flash('Tanggal bayar tidak valid.'); _pay_kembali($id); }
    if ($jml <= 0) { flash('Jumlah dibayar harus lebih dari nol.'); _pay_kembali($id); }
    return [
        'paid_at' => $tgl, 'amount' => $jml,
        'method'  => in_array(post('method'), ['Transfer', 'Tunai', 'Giro', 'Lainnya'], true) ? (string) post('method') : null,
        'ref_no'  => mb_substr(trim((string) post('ref_no', '')), 0, 80) ?: null,
        'note'    => mb_substr(trim((string) post('note', '')), 0, 190) ?: null,
    ];
}

function payment_add(PDO $pdo): void
{
    require_permission('manage_payments');
    verify_csrf();
    $id  = (int) post('id', 0);
    _pay_muat($pdo, $id, true);
    $baris = _pay_baris_post($id);
    $pdo->prepare('INSERT INTO transaction_payments (property_id, transaction_id, paid_at, amount, method, ref_no, note, created_by)
                   VALUES (:pid, :tid, :paid_at, :amount, :method, :ref_no, :note, :by)')
        ->execute($baris + [':pid' => current_property_id(), ':tid' => $id, ':by' => (string) ($_SESSION['user']['name'] ?? '')]);
    audit($pdo, 'create', 'transaction_payments', (string) $pdo->lastInsertId(), $baris + ['transaction_id' => $id], [], 'payments');
    flash('Pembayaran dicatat.');
    _pay_kembali($id);
}

/**
 * Perbaiki pembayaran yang sudah tercatat.
 *
 * Sebelum ini satu-satunya jalan membetulkan salah ketik adalah menghapus lalu
 * mencatat ulang — jejaknya jadi dua baris mati untuk satu pembayaran. Di sini
 * barisnya diperbaiki di tempat, lengkap dengan catatan siapa yang mengubah dan
 * kapan, dan perubahannya tetap tercatat utuh di Jejak Audit (nilai lama vs baru).
 */
function payment_edit(PDO $pdo): void
{
    require_permission('manage_payments');
    verify_csrf();
    $id = (int) post('id', 0);
    _pay_muat($pdo, $id, true);
    $payId = (int) post('pay_id', 0);
    // Pembayaran harus milik transaksi yang sedang dibuka — kalau tidak, id
    // dari halaman lain bisa dipakai menyunting pembayaran orang lain.
    $st = $pdo->prepare('SELECT * FROM transaction_payments WHERE id = ? AND transaction_id = ? AND deleted_at IS NULL');
    $st->execute([$payId, $id]);
    $lama = $st->fetch();
    if (!$lama) { flash('Pembayaran tidak ditemukan atau sudah dihapus.'); _pay_kembali($id); }
    $baru = _pay_baris_post($id);
    $pdo->prepare('UPDATE transaction_payments SET paid_at = :paid_at, amount = :amount, method = :method,
                          ref_no = :ref_no, note = :note, updated_at = NOW(), updated_by = :by
                    WHERE id = :payid')
        ->execute($baru + [':by' => (string) ($_SESSION['user']['name'] ?? ''), ':payid' => $payId]);
    audit($pdo, 'update', 'transaction_payments', (string) $payId, $baru, array_intersect_key($lama, $baru), 'payments');
    flash('Pembayaran diperbarui.');
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
