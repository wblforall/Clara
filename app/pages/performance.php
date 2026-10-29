<?php
declare(strict_types=1);

/**
 * Performance & Outlook — pencapaian terhadap target per bulan dalam satu
 * tahun, plus perkiraan bulan yang belum selesai.
 *
 * Dasar perhitungan (ditulis juga di halaman supaya bisa dijelaskan ke direksi):
 *   - Bulan yang sudah lewat  : angka aktual (alokasi transaksi), outlook = aktual.
 *   - Bulan berjalan & depan  : Outlook = Kontrak panjang yang sudah terkunci
 *       + pameran/transaksi bulanan yang sudah terkunci, digenapkan sampai
 *         rata-rata 3 bulan terakhir yang sudah selesai bila masih di bawahnya
 *         (untuk bulan berjalan, hanya sebesar sisa harinya).
 *   - Outlook + Perpanjangan : Outlook ditambah kontrak panjang yang akan
 *       berakhir dan belum punya penerus, dikalikan tingkat perpanjangan riil
 *       12 bulan terakhir (bisa diubah di halaman).
 *
 * "Kontrak panjang" = billing spread dengan rentang lebih dari 45 hari.
 * "Recurring" yang ditampilkan memakai recurring_match_sql() — definisi yang
 * sama dengan halaman Trend Revenue, supaya angkanya tidak berbeda antar menu.
 */

const PERF_KONTRAK_PANJANG_HARI = 45;
const PERF_BASELINE_BULAN       = 3;
const PERF_RENEW_BAWAAN         = 0.70;   // dipakai bila contoh kontrak berakhir < 5

function _perf_segmen(): array
{
    return ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
}

function _perf_bulan_label(string $m): string
{
    $mn = ['01'=>'Jan','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'Mei','06'=>'Jun',
           '07'=>'Jul','08'=>'Agt','09'=>'Sep','10'=>'Okt','11'=>'Nov','12'=>'Des'];
    return $mn[$m] ?? $m;
}

/** Tingkat perpanjangan riil: kontrak panjang yang berakhir 12 bulan terakhir dan punya penerus. */
function _perf_renew_rate(PDO $pdo, array $pids, DateTimeImmutable $today): array
{
    $in = implode(',', array_map('intval', $pids));
    $row = $pdo->query(
        "SELECT COUNT(*) n,
                COALESCE(SUM(EXISTS(SELECT 1 FROM transactions s
                    WHERE s.deleted_at IS NULL AND s.id <> t.id AND s.property_id = t.property_id
                      AND s.client_id = t.client_id AND s.master_code = t.master_code
                      AND s.start_date > t.start_date
                      AND s.start_date >= DATE_SUB(t.end_date, INTERVAL 31 DAY))), 0) ok
         FROM transactions t
         WHERE t.deleted_at IS NULL AND t.property_id IN ($in)
           AND t.billing_method = 'spread'
           AND DATEDIFF(t.end_date, t.start_date) > " . PERF_KONTRAK_PANJANG_HARI . "
           AND t.end_date BETWEEN " . $pdo->quote($today->modify('-365 days')->format('Y-m-d'))
         . " AND " . $pdo->quote($today->modify('-1 day')->format('Y-m-d'))
    )->fetch();
    $n = (int) $row['n'];
    if ($n < 5) return ['rate' => PERF_RENEW_BAWAAN, 'n' => $n, 'ok' => (int) $row['ok'], 'bawaan' => true];
    return ['rate' => (int) $row['ok'] / $n, 'n' => $n, 'ok' => (int) $row['ok'], 'bawaan' => false];
}

/**
 * @param int[] $pids
 * @return array{months: array, seg: array, year: array, renew: array}
 */
function performance_compute(PDO $pdo, array $pids, int $year, ?float $renewOverride = null): array
{
    $today    = new DateTimeImmutable('today');
    $cur      = $today->format('Y-m');
    $in       = implode(',', array_map('intval', $pids));
    $segmen   = _perf_segmen();
    $baseFrom = $today->modify('first day of -' . PERF_BASELINE_BULAN . ' month')->format('Y-m');
    $baseTo   = $today->modify('first day of -1 month')->format('Y-m');
    $from     = min($year . '-01', $baseFrom);
    $to       = $year . '-12';

    // Alokasi per properti/periode/segmen/kelas + porsi recurring (definisi Trend Revenue)
    $st = $pdo->prepare(
        "SELECT a.property_id pid, a.period_key pk, a.module seg,
                CASE WHEN t.billing_method = 'spread'
                      AND DATEDIFF(t.end_date, t.start_date) > " . PERF_KONTRAK_PANJANG_HARI . "
                     THEN 'long' ELSE 'short' END cls,
                SUM(a.amount) amt,
                SUM(CASE WHEN " . recurring_match_sql('t') . " THEN a.amount ELSE 0 END) rec
         FROM transaction_allocations a
         JOIN transactions t ON t.id = a.transaction_id AND t.deleted_at IS NULL
         WHERE a.property_id IN ($in) AND a.period_key BETWEEN ? AND ?
         GROUP BY a.property_id, a.period_key, a.module, cls"
    );
    $st->execute([$from, $to]);
    $amt = $rec = [];
    foreach ($st->fetchAll() as $r) {
        $amt[$r['pid']][$r['pk']][$r['seg']][$r['cls']] = (float) $r['amt'];
        $rec[$r['pid']][$r['pk']] = ($rec[$r['pid']][$r['pk']] ?? 0) + (float) $r['rec'];
    }

    // Target bulanan
    $st = $pdo->prepare("SELECT property_id pid, period_key pk, target_amount FROM targets_monthly
                         WHERE property_id IN ($in) AND period_key BETWEEN ? AND ?");
    $st->execute([$year . '-01', $year . '-12']);
    $target = [];
    foreach ($st->fetchAll() as $r) $target[$r['pk']] = ($target[$r['pk']] ?? 0) + (float) $r['target_amount'];

    // Baseline: rata-rata pameran/transaksi bulanan 3 bulan terakhir yang sudah selesai
    $base = [];
    foreach ($pids as $pid) {
        foreach ($segmen as $mod => $_) {
            $sum = 0.0; $cnt = 0;
            for ($d = $today->modify('first day of -' . PERF_BASELINE_BULAN . ' month');
                 $d->format('Y-m') <= $baseTo; $d = $d->modify('+1 month')) {
                $sum += $amt[$pid][$d->format('Y-m')][$mod]['short'] ?? 0;
                $cnt++;
            }
            $base[$pid][$mod] = $cnt ? $sum / $cnt : 0.0;
        }
    }

    // Perpanjangan: kontrak panjang yang akan berakhir tanpa penerus
    $renew = _perf_renew_rate($pdo, $pids, $today);
    $rate  = $renewOverride !== null ? max(0.0, min(1.0, $renewOverride)) : $renew['rate'];
    $renew['dipakai'] = $rate;
    $renew['ubah']    = $renewOverride !== null;
    $st = $pdo->prepare(
        "SELECT t.property_id pid, t.module seg, t.start_date, t.end_date, t.final_amount
         FROM transactions t
         WHERE t.deleted_at IS NULL AND t.property_id IN ($in) AND t.billing_method = 'spread'
           AND DATEDIFF(t.end_date, t.start_date) > " . PERF_KONTRAK_PANJANG_HARI . "
           AND t.end_date >= ? AND t.end_date < ?
           AND NOT EXISTS (SELECT 1 FROM transactions s
                WHERE s.deleted_at IS NULL AND s.id <> t.id AND s.property_id = t.property_id
                  AND s.client_id = t.client_id AND s.master_code = t.master_code
                  AND s.start_date > t.start_date
                  AND s.start_date >= DATE_SUB(t.end_date, INTERVAL 31 DAY))"
    );
    $st->execute([$today->modify('first day of this month')->format('Y-m-d'), $year . '-12-31']);
    $kontrakHabis = $st->fetchAll();

    $months = [];
    $seg    = [];
    foreach (array_keys($segmen) as $mod) $seg[$mod] = ['aktual' => 0.0, 'fc' => 0.0, 'fcc' => 0.0];
    $ytdAct = $ytdTgt = $hit = $done = 0;
    $yearTgt = $yearFc = $yearFcc = $yearRec = 0.0;

    for ($i = 1; $i <= 12; $i++) {
        $m  = sprintf('%02d', $i);
        $pk = $year . '-' . $m;
        $status = $pk < $cur ? 'actual' : ($pk === $cur ? 'running' : 'forecast');
        $mStart = new DateTimeImmutable($pk . '-01');
        $mEnd   = $mStart->modify('last day of this month');

        $locked = $fc = $fcc = $recur = 0.0;
        foreach ($pids as $pid) {
            $recur += $rec[$pid][$pk] ?? 0;
            // Pelengkap baseline dihitung di tingkat properti (bukan per segmen) supaya
            // jumlah max() per segmen tidak menggelembungkan total, lalu dibagi
            // ke segmen menurut porsi baseline masing-masing.
            $shortLockP = 0.0; $baseP = array_sum($base[$pid]);
            foreach ($segmen as $mod => $_) $shortLockP += $amt[$pid][$pk][$mod]['short'] ?? 0;
            // Bulan berjalan: kekurangan hanya terisi sebesar sisa harinya.
            $sisa   = $status === 'running'
                ? ((int) $mEnd->format('j') - (int) $today->format('j')) / (int) $mEnd->format('j')
                : 1.0;
            $tambah = $status === 'actual' ? 0.0 : max(0.0, $baseP - $shortLockP) * $sisa;
            foreach ($segmen as $mod => $_) {
                $long  = $amt[$pid][$pk][$mod]['long']  ?? 0;
                $short = $amt[$pid][$pk][$mod]['short'] ?? 0;
                $lock  = $long + $short;
                $porsi = $baseP > 0 ? $base[$pid][$mod] / $baseP : 1 / count($segmen);
                $a     = $lock + $tambah * $porsi;
                $c     = $a;
                if ($status !== 'actual') {
                    foreach ($kontrakHabis as $k) {
                        if ((int) $k['pid'] !== (int) $pid || $k['seg'] !== $mod) continue;
                        $akhir = new DateTimeImmutable($k['end_date']);
                        $s = max($mStart, $akhir->modify('+1 day'));
                        if ($s > $mEnd) continue;
                        $hari = (int) $s->diff($mEnd)->days + 1;
                        $span = (int) (new DateTimeImmutable($k['start_date']))->diff($akhir)->days + 1;
                        $c += ((float) $k['final_amount'] / max(1, $span)) * $hari * $rate;
                    }
                }
                $locked += $lock; $fc += $a; $fcc += $c;
                if ($status === 'actual') $seg[$mod]['aktual'] += $lock;
                $seg[$mod]['fc']  += $a;
                $seg[$mod]['fcc'] += $c;
            }
        }
        $tgt = (float) ($target[$pk] ?? 0);
        $months[] = [
            'pk' => $pk, 'label' => _perf_bulan_label($m), 'status' => $status,
            'locked' => $locked, 'recurring' => $recur, 'regular' => max(0, $locked - $recur),
            'target' => $tgt, 'fc' => $fc, 'fcc' => $fcc,
        ];
        $yearTgt += $tgt; $yearFc += $fc; $yearFcc += $fcc; $yearRec += $recur;
        if ($status === 'actual') {
            $ytdAct += $locked; $ytdTgt += $tgt;
            if ($tgt > 0) { $done++; if ($locked >= $tgt) $hit++; }
        }
    }

    return [
        'months' => $months, 'seg' => $seg, 'renew' => $renew, 'cur' => $cur,
        'year' => ['target' => $yearTgt, 'fc' => $yearFc, 'fcc' => $yearFcc, 'recurring' => $yearRec,
                   'ytd_act' => $ytdAct, 'ytd_tgt' => $ytdTgt, 'hit' => $hit, 'done' => $done],
    ];
}

/** Rupiah utuh tanpa awalan, untuk sel tabel (kolom diberi judul "Rp"). */
function _perf_jt($v): string
{
    return number_format((float) $v, 0, ',', '.');
}

/** Rupiah utuh dengan awalan, untuk kartu dan ringkasan. */
function _perf_ringkas($v): string
{
    return money($v);
}

/**
 * Tabel bulanan melintang: bulan Jan–Des dari kiri ke kanan, indikator di baris,
 * kolom Total di ujung. Dipakai halaman dan cetakan agar isinya tidak berbeda.
 *
 * @param bool $cetak true = warna hex & kelas cetak, false = variabel tema halaman
 */
function _perf_tabel_bulanan(array $months, array $y, float $gapFcc, bool $cetak): void
{
    $badge = ['actual' => 'Aktual', 'running' => 'Berjalan', 'forecast' => 'Outlook'];
    $bg    = ['actual' => ['#047857', '#D1FAE5'], 'running' => ['#B45309', '#FEF3C7'], 'forecast' => ['#475569', '#E2E8F0']];
    $hijau = $cetak ? '#16a34a' : 'var(--green)';
    $merah = $cetak ? '#dc2626' : 'var(--accent)';
    $muted = $cetak ? '#7B8A9C' : 'var(--muted)';
    $warna = fn(?float $a) => $a === null ? 'inherit' : ($a >= 1 ? $hijau : ($a >= .8 ? ($cetak ? '#d97706' : 'var(--amber)') : $merah));
    $dash  = '<span style="color:' . ($cetak ? '#CBD5E1' : 'var(--muted)') . '">—</span>';
    $jt    = fn($v) => $v > 0 ? _perf_jt($v) : $dash;
    $gapFmt = fn(?float $g) => $g === null ? $dash
        : '<span style="color:' . ($g >= 0 ? $hijau : $merah) . '">' . ($g >= 0 ? '+' : '−') . _perf_jt(abs($g)) . '</span>';

    $tReg = $tRec = $tLock = 0.0;
    foreach ($months as $m) { $tReg += $m['regular']; $tRec += $m['recurring']; $tLock += $m['locked']; }

    // [judul, gaya sel, penghasil isi sel per bulan, isi sel Total]
    $baris = [
        ['Status', '', function ($m) use ($badge, $bg, $cetak) {
            if ($cetak) return $badge[$m['status']];
            [$c, $b] = $bg[$m['status']];
            return '<span style="display:inline-block;padding:2px 7px;border-radius:99px;font-size:10px;font-weight:700;color:' . $c . ';background:' . $b . '">' . $badge[$m['status']] . '</span>';
        }, ''],
        ['Regular', '', fn($m) => $jt($m['regular']), $jt($tReg)],
        ['Recurring', 'color:#0369a1', fn($m) => $jt($m['recurring']), $jt($tRec)],
        ['Aktual / Terkunci', 'font-weight:700', fn($m) => $jt($m['locked']), $jt($tLock)],
        ['Target', '', fn($m) => $jt($m['target']), $jt($y['target'])],
        ['Achievement', 'font-weight:700', function ($m) use ($warna, $dash) {
            if ($m['status'] !== 'actual' || $m['target'] <= 0) return $dash;
            $a = $m['locked'] / $m['target'];
            return '<span style="color:' . $warna($a) . '">' . pct($a) . '</span>';
        }, $dash],
        ['Outlook', '', fn($m) => $m['status'] === 'actual' ? $dash : _perf_jt($m['fc']), _perf_jt($y['fc'])],
        ['Outlook + Perpanjangan', 'font-weight:700', function ($m) use ($warna, $dash) {
            if ($m['status'] === 'actual') return $dash;
            $h = _perf_jt($m['fcc']);
            if ($m['target'] > 0) {
                $a = $m['fcc'] / $m['target'];
                $h .= '<div style="font-size:9.5px;font-weight:700;color:' . $warna($a) . '">' . pct($a) . '</div>';
            }
            return $h;
        }, _perf_jt($y['fcc'])],
        ['Gap ke Target', '', fn($m) => $gapFmt($m['target'] > 0 ? $m['fcc'] - $m['target'] : null), $gapFmt($y['target'] > 0 ? $gapFcc : null)],
    ];

    $stk = $cetak ? '' : 'position:sticky;left:0;background:#fff;z-index:1;';
    echo '<table class="' . ($cetak ? 'tbl-bulan' : 'perf-t') . '"><thead><tr><th style="' . $stk . '">Rupiah</th>';
    foreach ($months as $m) {
        $now = $m['status'] === 'running';
        echo '<th class="r"' . ($now ? ' style="background:#FEF3C7;color:#92400E"' : '') . '>' . h($m['label']) . '</th>';
    }
    echo '<th class="r">Total</th></tr></thead><tbody>';
    foreach ($baris as [$judul, $gaya, $isi, $total]) {
        echo '<tr><td style="font-weight:600;' . $stk . '">' . h($judul) . '</td>';
        foreach ($months as $m) {
            $now = $m['status'] === 'running';
            echo '<td style="text-align:right;' . $gaya . ($now ? ';background:#FFFBEB' : '') . '">' . $isi($m) . '</td>';
        }
        echo '<td style="text-align:right;font-weight:700;background:' . ($cetak ? '#F0FDFA' : '#F8FAFC') . ';' . ($gaya && str_contains($gaya, 'color') ? $gaya : '') . '">' . $total . '</td></tr>';
    }
    echo '</tbody></table>';
}

/** Satu baris ringkasan di atas tabel per properti. */
function _perf_baris_prop(array $pp, ?array $lastDone, bool $cetak): string
{
    $w = function (?float $a) use ($cetak) {
        if ($a === null) return 'inherit';
        return $a >= 1 ? ($cetak ? '#16a34a' : 'var(--green)') : ($a >= .8 ? ($cetak ? '#d97706' : 'var(--amber)') : ($cetak ? '#dc2626' : 'var(--accent)'));
    };
    $out = 'Pencapaian' . ($lastDone ? ' s.d. ' . h($lastDone['label']) : '') . ': <b style="color:' . $w($pp['ytd']) . '">'
         . ($pp['ytd'] === null ? '—' : pct($pp['ytd'])) . '</b>'
         . ' &nbsp;·&nbsp; Outlook + perpanjangan akhir tahun: <b style="color:' . $w($pp['fcc']) . '">'
         . ($pp['fcc'] === null ? '—' : pct($pp['fcc'])) . '</b> (' . _perf_ringkas($pp['y']['fcc']) . ' dari target ' . _perf_ringkas($pp['y']['target']) . ')'
         . ' &nbsp;·&nbsp; ' . ($pp['gap'] >= 0 ? 'Lebih ' : 'Kurang ') . _perf_ringkas(abs($pp['gap'])) . ' dari target';
    return $out;
}

function _perf_warna(?float $ach): string
{
    if ($ach === null) return 'var(--muted)';
    return $ach >= 1 ? 'var(--green)' : ($ach >= .8 ? 'var(--amber)' : 'var(--accent)');
}

/** Parameter + hasil hitung yang dipakai bersama oleh halaman dan cetakannya. */
function _perf_context(PDO $pdo): array
{
    $props = array_values(array_filter(allowed_properties(), fn($p) => isset($p['id'])));
    if (!$props) $props = $pdo->query("SELECT id, name FROM properties WHERE status='active' ORDER BY id")->fetchAll();
    $allowed = array_map(fn($p) => (int) $p['id'], $props);

    $scope = (string) getv('scope', 'all');
    if ($scope !== 'all' && !in_array((int) $scope, $allowed, true)) $scope = 'all';
    $pids  = $scope === 'all' ? $allowed : [(int) $scope];
    $scopeLabel = 'Gabungan';
    foreach ($props as $p) if ((string) $p['id'] === $scope) $scopeLabel = (string) $p['name'];

    $in = implode(',', array_map('intval', $pids));
    $years = $pdo->query(
        "SELECT DISTINCT LEFT(period_key,4) FROM targets_monthly WHERE property_id IN ($in)
         UNION SELECT DISTINCT LEFT(period_key,4) FROM transaction_allocations WHERE property_id IN ($in)"
    )->fetchAll(PDO::FETCH_COLUMN);
    $years = array_values(array_unique(array_map('strval', $years)));
    rsort($years);
    if (!$years) $years = [date('Y')];
    $year = (string) getv('year', in_array(date('Y'), $years, true) ? date('Y') : $years[0]);
    if (!in_array($year, $years, true)) $year = $years[0];

    $renewParam = getv('renew', '');
    $renewOver  = ($renewParam !== '' && is_numeric($renewParam)) ? max(0, min(100, (float) $renewParam)) / 100 : null;

    $d      = performance_compute($pdo, $pids, (int) $year, $renewOver);
    $months = $d['months'];
    $y      = $d['year'];
    $renew  = $d['renew'];
    $cur    = $d['cur'];
    $segLbl = _perf_segmen();

    $ytdAch   = $y['ytd_tgt'] > 0 ? $y['ytd_act'] / $y['ytd_tgt'] : null;
    $fcAch    = $y['target'] > 0 ? $y['fc'] / $y['target'] : null;
    $fccAch   = $y['target'] > 0 ? $y['fcc'] / $y['target'] : null;
    $gapFcc   = $y['fcc'] - $y['target'];
    $curRow   = null;
    foreach ($months as $m) if ($m['pk'] === $cur) $curRow = $m;
    $lastDone = null;
    foreach ($months as $m) if ($m['status'] === 'actual') $lastDone = $m;

    // Gabungan: tabel per properti dengan tingkat perpanjangan yang SAMA, supaya jumlahnya cocok dengan Gabungan.
    $perProp = [];
    if ($scope === 'all' && count($pids) > 1) {
        foreach ($props as $p) {
            $pd = performance_compute($pdo, [(int) $p['id']], (int) $year, $d['renew']['dipakai']);
            $py = $pd['year'];
            $perProp[] = [
                'name' => (string) $p['name'], 'months' => $pd['months'], 'y' => $py,
                'gap'  => $py['fcc'] - $py['target'],
                'ytd'  => $py['ytd_tgt'] > 0 ? $py['ytd_act'] / $py['ytd_tgt'] : null,
                'fcc'  => $py['target'] > 0 ? $py['fcc'] / $py['target'] : null,
            ];
        }
    }

    return compact('props', 'scope', 'scopeLabel', 'years', 'year', 'renewOver', 'd', 'months', 'y', 'renew', 'cur',
                   'segLbl', 'ytdAch', 'fcAch', 'fccAch', 'gapFcc', 'curRow', 'lastDone', 'perProp');
}

function performance_page(PDO $pdo): void
{
    require_permission('view_exec_summary');

    extract(_perf_context($pdo));

    layout('Performance & Outlook', function () use (
        $months, $y, $d, $renew, $years, $year, $scope, $props, $scopeLabel, $ytdAch, $fcAch, $fccAch,
        $gapFcc, $curRow, $lastDone, $segLbl, $cur, $perProp
    ) {
        $badge = [
            'actual'   => ['Aktual',   '#047857', '#D1FAE5'],
            'running'  => ['Berjalan', '#B45309', '#FEF3C7'],
            'forecast' => ['Outlook', '#475569', '#E2E8F0'],
        ];
        $dash = '<span style="color:var(--muted)">—</span>';
        ?>
        <script src="assets/chart.umd.min.js"></script>

        <style>.perf-t td,.perf-t th{white-space:nowrap}.perf-t{letter-spacing:-.01em}.perf-t td{padding:7px 2px;font-size:10px}.perf-t th{padding:7px 2px;font-size:9px}.perf-t th.r{text-align:right}.perf-t td:first-child,.perf-t th:first-child{white-space:normal;min-width:60px;max-width:64px;padding-left:8px}</style>
        <form method="get" class="toolbar" style="margin-bottom:20px">
            <input type="hidden" name="r" value="performance">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <label style="margin:0;font-size:13px;color:var(--muted)">Tahun</label>
                <select name="year" onchange="this.form.submit()" style="width:auto">
                    <?php foreach ($years as $yr): ?>
                        <option value="<?= h($yr) ?>" <?= $yr === $year ? 'selected' : '' ?>><?= h($yr) ?></option>
                    <?php endforeach; ?>
                </select>
                <label style="margin:0 0 0 8px;font-size:13px;color:var(--muted)">Properti</label>
                <select name="scope" onchange="this.form.submit()" style="width:auto">
                    <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>>Gabungan</option>
                    <?php foreach ($props as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (string) $p['id'] === $scope ? 'selected' : '' ?>><?= h($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <label style="margin:0 0 0 8px;font-size:13px;color:var(--muted)">Asumsi perpanjangan</label>
                <input type="number" name="renew" min="0" max="100" step="1" style="width:84px"
                       value="<?= h((string) round($renew['dipakai'] * 100)) ?>" onchange="this.form.submit()">
                <span style="font-size:13px;color:var(--muted)">%</span>
                <?php if (can('export_reports')): ?>
                    <a class="btn light" target="_blank" style="margin-left:auto;background:#f1f5f9;color:var(--ink2);border:1px solid var(--line);text-decoration:none"
                       href="?r=print_performance&year=<?= h($year) ?>&scope=<?= h($scope) ?><?= $renew['ubah'] ? '&renew=' . (int) round($renew['dipakai'] * 100) : '' ?>">🖨 Print / PDF</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:20px">
            <div class="card">
                <div class="kpi-label">Pencapaian<?= $lastDone ? ' s.d. ' . h($lastDone['label']) : '' ?></div>
                <div class="kpi-value" style="color:<?= _perf_warna($ytdAch) ?>"><?= $ytdAch === null ? '—' : pct($ytdAch) ?></div>
                <div style="font-size:12px;color:var(--muted);margin-top:4px">
                    <?= _perf_ringkas($y['ytd_act']) ?> dari <?= _perf_ringkas($y['ytd_tgt']) ?><br>
                    <?= (int) $y['hit'] ?> dari <?= (int) $y['done'] ?> bulan mencapai target
                </div>
            </div>
            <div class="card">
                <div class="kpi-label">Bulan berjalan<?= $curRow ? ' (' . h($curRow['label']) . ')' : '' ?></div>
                <?php $ca = ($curRow && $curRow['target'] > 0) ? $curRow['fc'] / $curRow['target'] : null; ?>
                <div class="kpi-value" style="color:<?= _perf_warna($ca) ?>"><?= $ca === null ? '—' : pct($ca) ?></div>
                <div style="font-size:12px;color:var(--muted);margin-top:4px">
                    <?= $curRow ? 'Outlook ' . _perf_ringkas($curRow['fc']) . '<br>Target ' . _perf_ringkas($curRow['target']) : 'Bukan tahun berjalan' ?>
                </div>
            </div>
            <div class="card">
                <div class="kpi-label">Outlook akhir tahun</div>
                <div class="kpi-value" style="color:<?= _perf_warna($fcAch) ?>"><?= $fcAch === null ? '—' : pct($fcAch) ?></div>
                <div style="font-size:12px;color:var(--muted);margin-top:4px"><?= _perf_ringkas($y['fc']) ?><br>dari target <?= _perf_ringkas($y['target']) ?></div>
            </div>
            <div class="card">
                <div class="kpi-label">Outlook + perpanjangan</div>
                <div class="kpi-value" style="color:<?= _perf_warna($fccAch) ?>"><?= $fccAch === null ? '—' : pct($fccAch) ?></div>
                <div style="font-size:12px;color:var(--muted);margin-top:4px">
                    <?= _perf_ringkas($y['fcc']) ?><br>
                    <?= $gapFcc >= 0 ? 'Lebih ' : 'Kurang ' ?><?= _perf_ringkas(abs($gapFcc)) ?> dari target
                </div>
            </div>
            <div class="card">
                <div class="kpi-label">Recurring <?= h($year) ?></div>
                <div class="kpi-value" style="color:#0369a1"><?= _perf_ringkas($y['recurring']) ?></div>
                <div style="font-size:12px;color:var(--muted);margin-top:4px">
                    <?= $y['fc'] > 0 ? pct($y['recurring'] / $y['fc']) . ' dari outlook' : '' ?>
                </div>
            </div>
        </div>

        <div class="panel" style="margin-bottom:16px">
            <h2>Target, Aktual, dan Outlook — <?= h($year) ?> · <?= h($scopeLabel) ?></h2>
            <div style="position:relative;height:320px"><canvas id="chartPerf"></canvas></div>
        </div>

        <div class="panel" style="margin-bottom:16px">
            <h2>Tabel Bulanan — <?= h($scopeLabel) ?></h2>
            <div class="table-wrap"><?php _perf_tabel_bulanan($months, $y, $gapFcc, false); ?></div>
        </div>

        <?php foreach ($perProp as $pp): ?>
        <div class="panel" style="margin-bottom:16px">
            <h2><?= h($pp['name']) ?></h2>
            <div style="font-size:13px;margin-bottom:10px;color:var(--ink2)"><?= _perf_baris_prop($pp, $lastDone, false) ?></div>
            <div class="table-wrap"><?php _perf_tabel_bulanan($pp['months'], $pp['y'], $pp['gap'], false); ?></div>
        </div>
        <?php endforeach; ?>

        <div class="panel" style="margin-bottom:16px">
            <h2>Per Segmen — <?= h($year) ?> <span style="font-weight:400;font-size:13px;color:var(--muted)">(Rp)</span></h2>
            <div class="table-wrap">
                <table>
                    <thead><tr>
                        <th>Segmen</th>
                        <th style="text-align:right">Aktual bulan selesai</th>
                        <th style="text-align:right">Outlook akhir tahun</th>
                        <th style="text-align:right">Outlook + Perpanjangan</th>
                        <th style="text-align:right">Porsi</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($segLbl as $mod => $lbl): $s = $d['seg'][$mod]; ?>
                        <tr>
                            <td style="font-weight:600"><?= h($lbl) ?></td>
                            <td style="text-align:right"><?= $s['aktual'] > 0 ? _perf_jt($s['aktual']) : $dash ?></td>
                            <td style="text-align:right"><?= $s['fc'] > 0 ? _perf_jt($s['fc']) : $dash ?></td>
                            <td style="text-align:right"><?= $s['fcc'] > 0 ? _perf_jt($s['fcc']) : $dash ?></td>
                            <td style="text-align:right"><?= $y['fcc'] > 0 ? pct($s['fcc'] / $y['fcc']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="font-size:12px;color:var(--muted);margin-top:8px">Target ditetapkan per properti, bukan per segmen, sehingga pencapaian terhadap target hanya ditampilkan untuk total.</div>
        </div>

        <details class="panel" style="margin-bottom:16px">
            <summary style="cursor:pointer;font-weight:700">Cara membaca angka outlook</summary>
            <div style="font-size:13px;line-height:1.6;margin-top:10px;color:var(--ink2)">
                <p><b>Aktual / Terkunci.</b> Bulan yang sudah lewat memakai angka aktual. Bulan berjalan dan bulan depan memakai
                   alokasi kontrak yang <i>sudah</i> ditandatangani, jadi masih akan bertambah sampai bulannya selesai.</p>
                <p><b>Outlook.</b> Kontrak panjang yang sudah terkunci, ditambah pameran/transaksi bulanan yang sudah
                   terkunci, digenapkan sampai rata-rata 3 bulan terakhir yang sudah selesai (untuk bulan berjalan hanya
                   sebesar sisa harinya). Kontrak panjang adalah
                   kontrak <i>spread</i> dengan rentang lebih dari <?= PERF_KONTRAK_PANJANG_HARI ?> hari.</p>
                <p><b>Outlook + Perpanjangan.</b> Outlook ditambah kontrak panjang yang akan berakhir dan belum punya
                   kontrak penerus, dikalikan tingkat perpanjangan.
                   Tingkat yang dipakai <b><?= round($renew['dipakai'] * 100) ?>%</b><?php if ($renew['ubah']): ?> (diubah manual)<?php elseif ($renew['bawaan']): ?> (nilai bawaan, contoh kontrak terlalu sedikit)<?php else: ?>, dihitung dari
                   <?= (int) $renew['ok'] ?> dari <?= (int) $renew['n'] ?> kontrak panjang yang berakhir dalam 12 bulan terakhir dan benar-benar diperpanjang<?php endif; ?>.</p>
                <p><b>Recurring</b> memakai definisi yang sama dengan halaman Trend Revenue, sehingga angkanya sama.</p>
                <p>Perpanjangan baru terasa saat ada kontrak panjang yang berakhir di dalam tahun yang dipilih. Untuk 2026 hampir semuanya berakhir 22–31 Desember, jadi dampaknya muncul di Januari 2027.</p>
                <p><b>Bukan FORECAST di Kalender.</b> Kolom FORECAST di Kalender Pameran adalah potensi unit bila semua terisi penuh
                   dengan tarif normal (Proyeksi Bulanan di Master). Outlook di halaman ini adalah perkiraan pendapatan yang realistis.</p>
                <p>Outlook tidak memperhitungkan musim (mis. Desember yang biasanya lebih tinggi) dan belum memakai penawaran yang sedang berjalan.</p>
            </div>
        </details>

        <script>
        const pFmt = v => 'Rp ' + Number(v).toLocaleString('id-ID');
        const pRows = <?= json_encode($months, JSON_UNESCAPED_UNICODE) ?>;
        const pLbl  = pRows.map(r => r.label);
        const solid = r => r.status === 'actual';
        new Chart(document.getElementById('chartPerf'), {
            type: 'bar',
            data: {
                labels: pLbl,
                datasets: [
                    { type: 'line', label: 'Target', data: pRows.map(r => Math.round(r.target)),
                      borderColor: '#EF4444', backgroundColor: 'transparent', borderWidth: 2, borderDash: [6, 4],
                      pointRadius: 4, pointBackgroundColor: '#EF4444', tension: 0.3, order: 0, stack: 'garis-target' },
                    { type: 'line', label: 'Outlook + Perpanjangan', data: pRows.map(r => solid(r) ? null : Math.round(r.fcc)),
                      borderColor: '#D97706', backgroundColor: 'transparent', borderWidth: 2,
                      pointRadius: 4, pointBackgroundColor: '#D97706', tension: 0.3, order: 0, spanGaps: false, stack: 'garis-forecast' },
                    { label: 'Regular', data: pRows.map(r => Math.round(r.regular)), backgroundColor: 'rgba(13,148,136,.85)', stack: 'a', order: 1 },
                    { label: 'Recurring', data: pRows.map(r => Math.round(r.recurring)), backgroundColor: 'rgba(3,105,161,.8)', stack: 'a', order: 1 },
                    { label: 'Tambahan outlook', data: pRows.map(r => solid(r) ? 0 : Math.max(0, Math.round(r.fc - r.locked))),
                      backgroundColor: 'rgba(148,163,184,.45)', borderColor: 'rgba(100,116,139,.8)', borderWidth: 1,
                      borderRadius: [4, 4, 0, 0], stack: 'a', order: 1 },
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false, animation: false,
                plugins: {
                    legend: { position: 'top', labels: { usePointStyle: true, font: { size: 12 } } },
                    tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + pFmt(c.raw) } }
                },
                scales: {
                    x: { stacked: true, grid: { color: 'rgba(0,0,0,.04)' } },
                    y: { stacked: true, grid: { color: 'rgba(0,0,0,.04)' },
                         ticks: { font: { size: 11 }, callback: v => 'Rp ' + (v / 1e6).toFixed(0) + ' jt' } }
                }
            }
        });
        </script>
        <?php
    });
}

/** Cetak / PDF — A4 landscape, tema yang sama dengan cetak Trend Revenue. */
function print_performance(PDO $pdo): void
{
    extract(_perf_context($pdo));
    $app = env_value('APP_NAME', 'CLARA');
    audit($pdo, 'print_performance', 'performance', $year, ['year' => $year, 'scope' => $scope], [], 'reporting');
    $warna = fn(?float $a) => $a === null ? '#0F1623' : ($a >= 1 ? '#16a34a' : ($a >= .8 ? '#d97706' : '#dc2626'));
    $dash  = '<span style="color:#CBD5E1">—</span>';
    $badge = ['actual' => 'Aktual', 'running' => 'Berjalan', 'forecast' => 'Outlook'];
    ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Performance & Outlook <?= h($year) ?> — <?= h($app) ?></title>
<link rel="icon" type="image/png" href="assets/clara-logo.png">
<script src="assets/chart.umd.min.js"></script>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Inter', sans-serif; font-size: 11px; color: #0F1623; background: #fff; }
@page { size: A4 landscape; margin: 14mm 12mm; }
.no-print { position: fixed; top: 16px; right: 16px; display: flex; gap: 8px; z-index: 99; }
.no-print button { padding: 9px 18px; border: none; border-radius: 8px; font-weight: 700; font-size: 13px; cursor: pointer; }
.btn-print { background: #0D9488; color: #fff; }
.btn-close { background: #f1f5f9; color: #374151; }
@media print { .no-print { display: none; } }
.rpt-header { display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 10px; border-bottom: 2px solid #0D9488; margin-bottom: 14px; }
.rpt-logo { width: 160px; height: auto; display: block; object-fit: contain; }
.rpt-meta { text-align: right; font-size: 12px; color: #7B8A9C; line-height: 1.8; }
.rpt-period { font-size: 24px; font-weight: 900; color: #0F1623; }
.kpi-row { display: grid; grid-template-columns: repeat(5,1fr); gap: 10px; margin-bottom: 14px; }
.kpi-box { border: 1px solid #E4E9F0; border-radius: 8px; padding: 10px 12px; }
.kpi-box-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #7B8A9C; }
.kpi-box-value { font-size: 18px; font-weight: 800; margin-top: 4px; }
.kpi-box-sub { font-size: 9.5px; color: #7B8A9C; margin-top: 3px; line-height: 1.4; }
.kpi-box:nth-child(1) { border-top: 3px solid #0D9488; }
.kpi-box:nth-child(2) { border-top: 3px solid #F59E0B; }
.kpi-box:nth-child(3) { border-top: 3px solid #10B981; }
.kpi-box:nth-child(4) { border-top: 3px solid #D97706; }
.kpi-box:nth-child(5) { border-top: 3px solid #0369a1; }
.chart-wrap { border: 1px solid #E4E9F0; border-radius: 8px; padding: 10px 12px; margin-bottom: 12px; page-break-inside: avoid; break-inside: avoid; }
.chart-title { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: #0D9488; margin-bottom: 8px; }
.chart-box { position: relative; height: 230px; }
.page2 { page-break-before: always; break-before: page; }
table { width: 100%; border-collapse: collapse; font-size: 10px; }
th { background: #F8FAFC; color: #344054; font-weight: 700; text-transform: uppercase; font-size: 9px; letter-spacing: .04em; padding: 6px 8px; border: 1px solid #E4E9F0; text-align: left; }
td { padding: 5px 8px; border: 1px solid #E4E9F0; vertical-align: middle; white-space: nowrap; }
td.r, th.r { text-align: right; }
.tbl-bulan { font-size: 8px; }
.tbl-bulan td, .tbl-bulan th { padding: 6px 4px; }
.tbl-bulan th { font-size: 7.5px; }
.tbl-bulan th { text-align: right; }
.tbl-bulan th:first-child { text-align: left; }
td.money { text-align: right; font-weight: 600; }
tfoot td { background: #F0FDFA; font-weight: 800; border-top: 2px solid #0D9488; }
tr.berjalan td { background: #FFFBEB; }
.sec { margin-top: 14px; }
.catatan { margin-top: 12px; font-size: 9.5px; line-height: 1.55; color: #475467; border: 1px solid #E4E9F0; border-radius: 8px; padding: 10px 12px; page-break-inside: avoid; break-inside: avoid; }
.catatan b { color: #0F1623; }
.rpt-footer { margin-top: 14px; padding-top: 8px; border-top: 1px solid #E4E9F0; display: flex; justify-content: space-between; font-size: 9px; color: #7B8A9C; }
* { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
</style>
</head>
<body>

<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨 Cetak / Simpan PDF</button>
    <button class="btn-close" onclick="window.close()">✕ Tutup</button>
</div>

<div class="rpt-header">
    <div><img class="rpt-logo" src="assets/clara-logo.png" alt="CLARA"></div>
    <div class="rpt-meta">
        <div class="rpt-period">Performance &amp; Outlook <?= h($year) ?></div>
        <div style="font-size:20px;font-weight:800;color:#0D9488;margin-bottom:2px"><?= h($scopeLabel) ?></div>
        <div>Dicetak: <?= date('d/m/Y H:i:s') ?></div>
        <div>Oleh: <?= h($_SESSION['user']['name'] ?? '-') ?> (<?= h($_SESSION['user']['role'] ?? '-') ?>)</div>
    </div>
</div>

<div class="kpi-row">
    <div class="kpi-box">
        <div class="kpi-box-label">Pencapaian<?= $lastDone ? ' s.d. ' . h($lastDone['label']) : '' ?></div>
        <div class="kpi-box-value" style="color:<?= $warna($ytdAch) ?>"><?= $ytdAch === null ? '—' : pct($ytdAch) ?></div>
        <div class="kpi-box-sub"><?= _perf_ringkas($y['ytd_act']) ?> dari <?= _perf_ringkas($y['ytd_tgt']) ?><br><?= (int) $y['hit'] ?> dari <?= (int) $y['done'] ?> bulan mencapai target</div>
    </div>
    <div class="kpi-box">
        <?php $ca = ($curRow && $curRow['target'] > 0) ? $curRow['fc'] / $curRow['target'] : null; ?>
        <div class="kpi-box-label">Bulan berjalan<?= $curRow ? ' (' . h($curRow['label']) . ')' : '' ?></div>
        <div class="kpi-box-value" style="color:<?= $warna($ca) ?>"><?= $ca === null ? '—' : pct($ca) ?></div>
        <div class="kpi-box-sub"><?= $curRow ? 'Outlook ' . _perf_ringkas($curRow['fc']) . '<br>Target ' . _perf_ringkas($curRow['target']) : 'Bukan tahun berjalan' ?></div>
    </div>
    <div class="kpi-box">
        <div class="kpi-box-label">Outlook akhir tahun</div>
        <div class="kpi-box-value" style="color:<?= $warna($fcAch) ?>"><?= $fcAch === null ? '—' : pct($fcAch) ?></div>
        <div class="kpi-box-sub"><?= _perf_ringkas($y['fc']) ?><br>dari target <?= _perf_ringkas($y['target']) ?></div>
    </div>
    <div class="kpi-box">
        <div class="kpi-box-label">Outlook + perpanjangan</div>
        <div class="kpi-box-value" style="color:<?= $warna($fccAch) ?>"><?= $fccAch === null ? '—' : pct($fccAch) ?></div>
        <div class="kpi-box-sub"><?= _perf_ringkas($y['fcc']) ?><br><?= $gapFcc >= 0 ? 'Lebih ' : 'Kurang ' ?><?= _perf_ringkas(abs($gapFcc)) ?> dari target</div>
    </div>
    <div class="kpi-box">
        <div class="kpi-box-label">Recurring <?= h($year) ?></div>
        <div class="kpi-box-value" style="color:#0369a1"><?= _perf_ringkas($y['recurring']) ?></div>
        <div class="kpi-box-sub"><?= $y['fc'] > 0 ? pct($y['recurring'] / $y['fc']) . ' dari outlook' : '' ?></div>
    </div>
</div>

<div class="chart-wrap">
    <div class="chart-title">Target, Aktual, dan Outlook — <?= h($year) ?></div>
    <div class="chart-box"><canvas id="chartPerf"></canvas></div>
</div>

<div class="catatan">
    <b>Cara membaca.</b> Bulan yang sudah lewat memakai angka aktual. Bulan berjalan dan bulan depan memakai kontrak yang sudah
    ditandatangani (terkunci), digenapkan sampai rata-rata 3 bulan terakhir yang sudah selesai (bulan berjalan hanya sebesar sisa harinya).
    <b>Outlook + Perpanjangan</b> menambahkan kontrak panjang (spread, &gt; <?= PERF_KONTRAK_PANJANG_HARI ?> hari) yang akan berakhir tanpa penerus,
    dikalikan tingkat perpanjangan <b><?= round($renew['dipakai'] * 100) ?>%</b><?php if ($renew['ubah']): ?> (diatur manual)<?php elseif ($renew['bawaan']): ?> (nilai bawaan)<?php else: ?> (<?= (int) $renew['ok'] ?> dari <?= (int) $renew['n'] ?> kontrak panjang berakhir 12 bulan terakhir diperpanjang)<?php endif; ?>.
    Outlook berbeda dengan kolom FORECAST di Kalender (potensi unit bila terisi penuh). Recurring memakai definisi yang sama dengan Trend Revenue. Outlook belum memperhitungkan musim dan penawaran yang sedang berjalan.
</div>

<div class="page2">
<div style="font-size:14px;font-weight:800;color:#0D9488;margin-bottom:6px">Tabel Bulanan — <?= h($scopeLabel) ?></div>
<?php _perf_tabel_bulanan($months, $y, $gapFcc, true); ?>

<div class="sec">
<table style="width:70%">
    <thead><tr>
        <th>Segmen (Rp)</th>
        <th class="r">Aktual bulan selesai</th>
        <th class="r">Outlook akhir tahun</th>
        <th class="r">Outlook + Perpanjangan</th>
        <th class="r">Porsi</th>
    </tr></thead>
    <tbody>
    <?php foreach ($segLbl as $mod => $lbl): $sg = $d['seg'][$mod]; ?>
        <tr>
            <td style="font-weight:600"><?= h($lbl) ?></td>
            <td class="money"><?= $sg['aktual'] > 0 ? _perf_jt($sg['aktual']) : $dash ?></td>
            <td class="money"><?= $sg['fc'] > 0 ? _perf_jt($sg['fc']) : $dash ?></td>
            <td class="money"><?= $sg['fcc'] > 0 ? _perf_jt($sg['fcc']) : $dash ?></td>
            <td class="r"><?= $y['fcc'] > 0 ? pct($sg['fcc'] / $y['fcc']) : '—' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<div style="font-size:9px;color:#7B8A9C;margin-top:5px">Target ditetapkan per properti, bukan per segmen, sehingga pencapaian terhadap target hanya ditampilkan untuk total.</div>
</div>
</div>

<?php if ($perProp): ?>
<div class="page2">
<?php foreach ($perProp as $i => $pp): ?>
    <div class="sec" style="<?= $i === 0 ? 'margin-top:0' : '' ?>">
        <div style="font-size:14px;font-weight:800;color:#0D9488;margin-bottom:3px"><?= h($pp['name']) ?></div>
        <div style="font-size:10px;margin-bottom:6px;color:#475467"><?= _perf_baris_prop($pp, $lastDone, true) ?></div>
        <?php _perf_tabel_bulanan($pp['months'], $pp['y'], $pp['gap'], true); ?>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="rpt-footer">
    <span><?= h($app) ?> — Performance &amp; Outlook</span>
    <span><?= h($scopeLabel) ?> &nbsp;|&nbsp; Tahun <?= h($year) ?> &nbsp;|&nbsp; Dicetak <?= date('d/m/Y H:i:s') ?></span>
</div>

<script>
const pFmt = v => 'Rp ' + Number(v).toLocaleString('id-ID');
const pRows = <?= json_encode($months, JSON_UNESCAPED_UNICODE) ?>;
const solid = r => r.status === 'actual';
new Chart(document.getElementById('chartPerf'), {
    type: 'bar',
    data: {
        labels: pRows.map(r => r.label),
        datasets: [
            { type: 'line', label: 'Target', data: pRows.map(r => Math.round(r.target)), borderColor: '#EF4444', backgroundColor: 'transparent',
              borderWidth: 2, borderDash: [6, 4], pointRadius: 3, pointBackgroundColor: '#EF4444', tension: 0.3, order: 0, stack: 'garis-target' },
            { type: 'line', label: 'Outlook + Perpanjangan', data: pRows.map(r => solid(r) ? null : Math.round(r.fcc)), borderColor: '#D97706',
              backgroundColor: 'transparent', borderWidth: 2, pointRadius: 3, pointBackgroundColor: '#D97706', tension: 0.3, order: 0, stack: 'garis-forecast' },
            { label: 'Regular', data: pRows.map(r => Math.round(r.regular)), backgroundColor: 'rgba(13,148,136,.85)', stack: 'a', order: 1 },
            { label: 'Recurring', data: pRows.map(r => Math.round(r.recurring)), backgroundColor: 'rgba(3,105,161,.8)', stack: 'a', order: 1 },
            { label: 'Tambahan outlook', data: pRows.map(r => solid(r) ? 0 : Math.max(0, Math.round(r.fc - r.locked))),
              backgroundColor: 'rgba(148,163,184,.45)', borderColor: 'rgba(100,116,139,.8)', borderWidth: 1, borderRadius: [4, 4, 0, 0], stack: 'a', order: 1 },
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false, animation: false,
        devicePixelRatio: 4,   // canvas beresolusi tinggi supaya tajam di PDF, tidak buram saat diperbesar
        plugins: {
            legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8, boxHeight: 8, font: { size: 10 } } },
            tooltip: { callbacks: { label: c => ' ' + c.dataset.label + ': ' + pFmt(c.raw) } }
        },
        scales: {
            x: { stacked: true, grid: { color: 'rgba(0,0,0,.04)' }, ticks: { font: { size: 10 } } },
            y: { stacked: true, grid: { color: 'rgba(0,0,0,.04)' }, ticks: { font: { size: 10 }, callback: v => 'Rp ' + (v / 1e6).toFixed(0) + ' jt' } }
        }
    }
});
</script>
</body>
</html>
<?php
    exit;
}
