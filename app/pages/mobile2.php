<?php
declare(strict_types=1);

/**
 * Tampilan mobile CLARA untuk Sales dan Asst. Manager — mengikuti prototipe
 * clara-sales-proto. Aktif hanya bila mobile2_aktif() (HP + peran itu); peran
 * lain tetap memakai tampilan lama di mobile.php.
 *
 * Tahap 1: kerangka (header, menu kapsul), Beranda sales & manager, Unit
 * (daftar, kalender mingguan, detail), Profil. Halaman lain masih halaman
 * biasa yang dibungkus kerangka ini sampai layarnya dibuat.
 *
 * Gaya: public/assets/mobile2.css (kelas berawalan m2-).
 */

require_once dirname(__DIR__) . '/ApprovalLine.php';
require_once __DIR__ . '/mobile2b.php';

// ─── Alat kecil ───────────────────────────────────────────────────────────────

function m2_ic(string $nama): string
{
    static $p = [
        'home'     => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
        'unit'     => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'filter'   => '<path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>',
        'klien'    => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M12 6h.01M16 6h.01M8 10h.01M12 10h.01M16 10h.01"/>',
        'cek'      => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'kirim'    => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
        'prospek'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
        'box'      => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96 12 12.01l8.73-5.05"/><path d="M12 22.08V12"/>',
        'kiri'     => '<path d="m15 18-6-6 6-6"/>',
        'kanan'    => '<path d="m9 18 6-6-6-6"/>',
        'cari'     => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'chart'    => '<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/>',
        'renew'    => '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
        'trash'    => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/>',
        'ttd'      => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'kunci'    => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'layar'    => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>',
        'keluar'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'surat'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
    ];
    return '<svg class="m2-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$nama] ?? '') . '</svg>';
}

/** Rupiah ringkas utuh-bersahabat: Rp 356,4 jt / Rp 1,69 M. */
function m2_rp(float $v): string
{
    $neg = $v < 0 ? '-' : '';
    $v = abs($v);
    $f = fn(float $x, int $d) => rtrim(rtrim(number_format($x, $d, ',', '.'), '0'), ',');
    if ($v >= 1e9) return $neg . 'Rp ' . $f($v / 1e9, 2) . ' M';
    if ($v >= 1e6) return $neg . 'Rp ' . $f($v / 1e6, 1) . ' jt';
    return $neg . money($v);
}

function m2_tgl_pendek(string $ymd): string
{
    static $b = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $t = strtotime($ymd);
    return (int) date('j', $t) . ' ' . $b[(int) date('n', $t)];
}

function m2_hari_pendek(string $ymd): string
{
    static $h = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
    return $h[(int) date('w', strtotime($ymd))];
}

function m2_sapaan(): string
{
    $j = (int) date('G');
    return $j < 11 ? 'Selamat pagi' : ($j < 15 ? 'Selamat siang' : ($j < 18 ? 'Selamat sore' : 'Selamat malam'));
}

function m2_peran_label(): string
{
    return match (current_role()) {
        'asst_manager' => 'Asst. Manager',
        'sales'        => 'Sales',
        default        => roles()[current_role()] ?? 'Pengguna',
    };
}

/** Kuncinya: berapa hari lagi bulan ini berakhir. */
function m2_sisa_hari_bulan(): int
{
    return (int) date('t') - (int) date('j');
}

// ─── Kerangka ─────────────────────────────────────────────────────────────────

/** Header Beranda: avatar → profil, sapaan, tab properti, tanggal. */
function m2_head_beranda(): void
{
    $nama   = (string) ($_SESSION['user']['name'] ?? 'User');
    $ini    = strtoupper(mb_substr(trim($nama), 0, 1) ?: 'U');
    $props  = allowed_properties();
    $cur    = current_property_id();
    $back   = urlencode('?' . ($_SERVER['QUERY_STRING'] ?? ''));
    ?>
    <header class="m2-head">
        <div class="m2-head-row">
            <a class="m2-av" href="?r=m2_profil" aria-label="Profil"><?= h($ini) ?></a>
            <div class="m2-hello">
                <small><?= h(m2_sapaan()) ?></small>
                <div class="nm"><h1><?= h($nama) ?></h1><span class="m2-chip"><?= h(m2_peran_label()) ?></span></div>
            </div>
        </div>
        <div class="m2-head-row2">
            <?php if (count($props) > 1): ?>
            <div class="m2-tabs" role="tablist" aria-label="Properti">
                <?php foreach ($props as $p): ?>
                <a role="tab" href="?r=switch_property&to=<?= (int) $p['id'] ?>&back=<?= $back ?>" aria-selected="<?= (int) $p['id'] === $cur ? 'true' : 'false' ?>"><?= h(explode(' ', trim((string) $p['name']))[0]) ?></a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="m2-tabs" aria-label="Properti"><a aria-selected="true"><?= h(current_property()['name'] ?? '') ?></a></div>
            <?php endif; ?>
            <span class="m2-date"><?= m2_ic('unit') ?><?= h(m2_hari_pendek(date('Y-m-d')) . ', ' . m2_tgl_pendek(date('Y-m-d'))) ?></span>
        </div>
    </header>
    <?php
}

/** Header halaman turunan: tombol kembali + judul. */
function m2_top(string $judul, string $kembali = '?r=m_home', string $sub = ''): void
{
    ?>
    <header class="m2-top">
        <a class="m2-back" href="<?= h($kembali) ?>" aria-label="Kembali"><?= m2_ic('kiri') ?></a>
        <h1><?= h($judul) ?><?php if ($sub !== ''): ?><small><?= h($sub) ?></small><?php endif; ?></h1>
    </header>
    <?php
}

/** Menu bawah kapsul. Sales: Beranda·Unit·+·Pipeline·Klien. Manager: Persetujuan di tempat Unit. */
function m2_nav(string $route, PDO $pdo): void
{
    // Layar turunan (detail unit, profil) tidak memakai menu bawah, seperti prototipe.
    if (in_array($route, ['m2_unit_detail', 'm2_profil', 'm2_alokasi'], true)) return;
    $manager = current_role() !== 'sales' && can('manage_skp');
    $aktif = match (true) {
        $route === 'm_home'                                   => 'home',
        str_starts_with($route, 'm2_unit'), $route === 'm2_kalender' => 'unit',
        $route === 'm2_skp' && getv('giliran') === '1'        => 'setuju',
        in_array($route, ['m2_pipeline', 'prospect_form'], true) => 'pipeline',
        in_array($route, ['m2_klien', 'client_profile', 'client_form'], true) => 'klien',
        default                                               => '',
    };
    $antre = 0;
    if ($manager) {
        try { $antre = count(ApprovalLine::antreanSaya($pdo, current_property_id())); } catch (Throwable $e) {}
    }
    $kedua = $manager
        ? ['setuju', '?r=m2_skp&giliran=1', 'Persetujuan', 'cek']
        : ['unit',   '?r=m2_unit',       'Unit',        'unit'];
    $item = fn(string $k, string $href, string $lbl, string $ic) =>
        '<a href="' . h($href) . '" aria-label="' . h($lbl) . '"' . ($aktif === $k ? ' aria-current="page"' : '') . '>'
        . m2_ic($ic) . '<span aria-hidden="true">' . h($lbl) . '</span>'
        . ($k === 'setuju' && $antre > 0 ? '<span class="cnt">' . $antre . '</span>' : '') . '</a>';
    ?>
    <div class="m2-navwrap">
        <nav class="m2-nav" aria-label="Menu utama">
            <svg viewBox="0 0 358 78" preserveAspectRatio="none" aria-hidden="true"><path d="M30,0 H118 C131,0 135,4 139.8,12 A40,40 0 0 0 218.2,12 C223,4 227,0 240,0 H328 A30,30 0 0 1 358,30 V48 A30,30 0 0 1 328,78 H30 A30,30 0 0 1 0,48 V30 A30,30 0 0 1 30,0 Z" fill="#FFFFFF"/></svg>
            <div class="cols">
                <?= $item('home', '?r=m_home', 'Beranda', 'home') ?>
                <?= $item($kedua[0], $kedua[1], $kedua[2], $kedua[3]) ?>
                <span class="gap" aria-hidden="true">Penawaran</span>
                <?= $item('pipeline', '?r=m2_pipeline', 'Pipeline', 'filter') ?>
                <?= $item('klien', '?r=m2_klien', 'Klien', 'klien') ?>
            </div>
            <?php if (can('manage_offers')): ?>
            <a class="fab" href="?r=offer_form" aria-label="Buat penawaran"><?= m2_ic('plus') ?></a>
            <?php endif; ?>
        </nav>
    </div>
    <?php
}

/** Halaman biasa (belum punya layar mobile baru) di dalam kerangka baru. */
function m2_shell_legacy(string $judul, callable $body, ?string $flash): void
{
    m2_top($judul);
    if ($flash) echo '<div class="m2-flash">' . h($flash) . '</div>';
    echo '<div class="m2-legacy">';
    $body();
    echo '</div>';
}

// ─── Beranda ──────────────────────────────────────────────────────────────────

/** Satu baris "perlu tindakan". */
function _m2_tugas(string $tag, string $bg, string $fg, string $judul, string $ket, string $aksi, string $href): void
{
    ?>
    <a class="m2-task" href="<?= h($href) ?>">
        <span class="tag" style="background:<?= $bg ?>;color:<?= $fg ?>"><?= h($tag) ?></span>
        <span class="tx"><b><?= h($judul) ?></b><small><?= h($ket) ?></small></span>
        <span class="go"><?= h($aksi) ?></span>
    </a>
    <?php
}

/** Kumpulkan tindakan milik sales: penawaran mau habis, SKP dikembalikan, tagihan lewat tempo, kontrak mau habis. */
function _m2_tindakan_sales(PDO $pdo, int $pid): array
{
    $out = [];
    [$sc, $sp] = current_sales_scope_sql($pdo, $pid, 'o.pic_name', 'o.created_by');

    $st = $pdo->prepare("SELECT o.id, o.offer_no, o.status, o.sign_token_expires_at, c.company_name, c.brand_name
                           FROM offers o LEFT JOIN master_clients c ON c.id = o.client_id
                          WHERE o.property_id = ? AND o.deleted_at IS NULL AND o.status IN ('sent','nego')
                            AND o.sign_token_expires_at IS NOT NULL AND o.sign_token_expires_at <= DATE_ADD(NOW(), INTERVAL 2 DAY) $sc
                          ORDER BY o.sign_token_expires_at LIMIT 6");
    $st->execute(array_merge([$pid], $sp));
    foreach ($st->fetchAll() as $o) {
        $sisa = (int) floor((strtotime((string) $o['sign_token_expires_at']) - time()) / 86400);
        $judul = ($o['brand_name'] ?: $o['company_name'] ?: 'Penawaran') . ' — ' . ($sisa < 0 ? 'link TTD sudah berakhir' : ($sisa === 0 ? 'link TTD berakhir hari ini' : 'link TTD berakhir besok'));
        $out[] = ['SP', '#FEF3C7', '#92400E', $judul, 'Belum ditandatangani · ' . $o['offer_no'], 'Kirim ulang', '?r=offer_form&id=' . (int) $o['id']];
    }

    [$sc2, $sp2] = current_sales_scope_sql($pdo, $pid, 's.pic_name', 's.created_by');
    $st = $pdo->prepare("SELECT s.id, s.reject_note, s.skp_no, c.company_name, c.brand_name
                           FROM skp_documents s LEFT JOIN master_clients c ON c.id = s.client_id
                          WHERE s.property_id = ? AND s.deleted_at IS NULL AND s.status = 'rejected' $sc2
                          ORDER BY s.updated_at DESC LIMIT 6");
    $st->execute(array_merge([$pid], $sp2));
    foreach ($st->fetchAll() as $s) {
        $out[] = ['SKP', '#FEE2E2', '#991B1B', ($s['brand_name'] ?: $s['company_name'] ?: 'Klien') . ' — SKP dikembalikan', trim((string) $s['reject_note']) ?: 'Perlu diperbaiki', 'Perbaiki', '?r=skp_form&id=' . (int) $s['id']];
    }

    [$sc3, $sp3] = current_sales_scope_sql($pdo, $pid, 't.pic_name', 't.created_by');
    $st = $pdo->prepare("SELECT * FROM (SELECT t.id, t.invoice_no, t.invoice_due, c.company_name, c.brand_name, " . pembayaran_select_sql('t') . "
                           FROM transactions t LEFT JOIN master_clients c ON c.id = t.client_id
                          WHERE t.property_id = ? AND t.deleted_at IS NULL $sc3) x
                          WHERE x.bayar_lewat = 1 ORDER BY x.invoice_due LIMIT 6");
    $st->execute(array_merge([$pid], $sp3));
    foreach ($st->fetchAll() as $t) {
        $out[] = ['Rp', '#FEE2E2', '#991B1B', ($t['brand_name'] ?: $t['company_name'] ?: 'Klien') . ' — tagihan lewat tempo',
                  'Sisa ' . money((float) $t['tagihan'] - (float) $t['terbayar']) . ' · jatuh tempo ' . m2_tgl_pendek((string) $t['invoice_due']), 'Lihat', '?r=payment_form&id=' . (int) $t['id']];
    }

    $st = $pdo->prepare("SELECT COUNT(*) FROM transactions t WHERE t.property_id = ? AND t.deleted_at IS NULL AND t.module = 'cl'
                            AND t.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) $sc3");
    $st->execute(array_merge([$pid], $sp3));
    $n = (int) $st->fetchColumn();
    if ($n > 0) $out[] = ['RN', '#E0F2FE', '#075985', $n . ' kontrak segera berakhir', 'Dalam 30 hari ke depan', 'Renewal', '?r=renewals'];

    return $out;
}

function m2_beranda_sales(PDO $pdo): void
{
    $period = periode_bulan();
    $user   = $_SESSION['user'] ?? [];
    $pid    = current_property_id();

    $s = $pdo->prepare('SELECT p.name AS pic_name, p.target_share, p.property_id, COALESCE(SUM(a.amount),0) AS actual
                          FROM master_pic p
                          LEFT JOIN transaction_allocations a ON a.pic_name = p.name AND a.period_key = ? AND a.property_id = p.property_id
                         WHERE p.user_id = ? AND p.status = "active" AND p.property_id = ? GROUP BY p.id LIMIT 1');
    $s->execute([$period, (int) ($user['id'] ?? 0), $pid]);
    $pic = $s->fetch() ?: null;

    layout('Beranda', function () use ($pdo, $pic, $period, $pid) {
        m2_head_beranda();
        echo '<main class="m2-wrap">';
        if (!$pic) {
            echo '<div class="m2-card m2-empty">Akun Anda belum tertaut sebagai PIC di properti ini.<br>Hubungi admin agar target dan data penawaran Anda muncul di sini.</div>';
            m2_quick_sales();
            echo '</main>';
            return;
        }
        $t = $pdo->prepare('SELECT COALESCE(target_amount,0) FROM targets_monthly WHERE period_key = ? AND property_id = ?');
        $t->execute([$period, $pid]);
        $targetTim = (float) $t->fetchColumn();
        $a = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM transaction_allocations WHERE period_key = ? AND property_id = ?');
        $a->execute([$period, $pid]);
        $aktualTim = (float) $a->fetchColumn();

        $targetSaya = $targetTim * (float) $pic['target_share'];
        $aktualSaya = (float) $pic['actual'];
        $pctSaya = $targetSaya > 0 ? $aktualSaya / $targetSaya * 100 : 0;
        $pctTim  = $targetTim > 0 ? $aktualTim / $targetTim * 100 : 0;
        $kontrib = $aktualTim > 0 ? $aktualSaya / $aktualTim * 100 : 0;

        $rk = $pdo->prepare('SELECT pic_name, COALESCE(SUM(amount),0) amt FROM transaction_allocations
                              WHERE period_key = ? AND property_id = ? AND pic_name IS NOT NULL AND pic_name <> "" GROUP BY pic_name ORDER BY amt DESC');
        $rk->execute([$period, $pid]);
        $rank = 0; $jml = 0;
        foreach ($rk->fetchAll() as $i => $r) { $jml++; if ($r['pic_name'] === $pic['pic_name']) $rank = $i + 1; }

        $fmt = fn(float $v) => rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');
        $sisa = m2_sisa_hari_bulan();
        $bulanLbl = pembayaran_nama_bulan($period, true);
        ?>
        <a class="m2-hero" href="?r=pic_performance">
            <div class="top"><span><?= h($bulanLbl) ?></span><span class="pill"><?= $period === date('Y-m') ? ($sisa > 0 ? $sisa . ' hari lagi' : 'hari terakhir') : 'selesai' ?></span></div>
            <div class="two">
                <div><div class="lbl">Target saya</div><div class="big"><?= $fmt($pctSaya) ?>%</div><div class="sub"><?= m2_rp($aktualSaya) ?> / <?= m2_rp($targetSaya) ?></div></div>
                <div><div class="lbl">Target tim</div><div class="big"><?= $fmt($pctTim) ?>%</div><div class="sub"><?= m2_rp($aktualTim) ?> / <?= m2_rp($targetTim) ?></div></div>
            </div>
            <div class="bar-l"><span>Saya</span><span><?= $aktualSaya >= $targetSaya ? 'lebih <b>' . m2_rp($aktualSaya - $targetSaya) . '</b>' : 'kurang <b>' . m2_rp($targetSaya - $aktualSaya) . '</b>' ?></span></div>
            <div class="bar"><i style="width:<?= min(100, max(0, $pctSaya)) ?>%"></i></div>
            <div class="bar-l b2"><span>Tim · kontribusi saya <b><?= $fmt($kontrib) ?>%</b></span><span><?= $aktualTim >= $targetTim ? 'lebih <b>' . m2_rp($aktualTim - $targetTim) . '</b>' : 'kurang <b>' . m2_rp($targetTim - $aktualTim) . '</b>' ?></span></div>
            <div class="bar"><i style="width:<?= min(100, $kontrib) ?>%"></i><i class="lt" style="width:<?= max(0, min(100 - $kontrib, $pctTim - $kontrib)) ?>%"></i></div>
            <div class="foot"><span><?= $rank > 0 ? 'Peringkat tim: <b>' . $rank . ' dari ' . $jml . '</b>' : 'Belum ada peringkat' ?></span><b>Lihat performa ›</b></div>
        </a>
        <?php
        m2_quick_sales();
        $tugas = _m2_tindakan_sales($pdo, $pid);
        ?>
        <section class="m2-card">
            <div class="m2-card-h"><h2>Perlu tindakan</h2><?php if ($tugas): ?><span class="m2-badge"><?= count($tugas) ?></span><?php endif; ?></div>
            <?php if (!$tugas): ?>
                <div class="m2-empty" style="padding:14px 4px">Tidak ada yang menunggu. Penawaran, SKP, dan tagihan Anda aman.</div>
            <?php else:
                foreach (array_slice($tugas, 0, 4) as $x) _m2_tugas(...$x);
                if (count($tugas) > 4): ?>
                <a class="m2-more" href="?r=m2_pipeline"><span>+<?= count($tugas) - 4 ?> lainnya</span><b>Lihat semua ›</b></a>
            <?php endif; endif; ?>
        </section>
        </main>
        <?php
    }, ['m2' => true]);
}

function m2_quick_sales(): void
{
    $q = [
        ['?r=m2_unit', 'Cek unit', 'unit'],
        ['?r=m2_offers', 'Penawaran', 'kirim'],
        ['?r=prospect_form', 'Prospek', 'prospek'],
        ['?r=m2_skp&module=gudang', 'SKS / FU', 'box'],
    ];
    echo '<nav class="m2-quick" aria-label="Pintasan">';
    foreach ($q as [$href, $lbl, $ic]) echo '<a href="' . h($href) . '">' . m2_ic($ic) . h($lbl) . '</a>';
    echo '</nav>';
}

function m2_beranda_manager(PDO $pdo): void
{
    $period = periode_bulan();
    $pid    = current_property_id();

    layout('Beranda', function () use ($pdo, $period, $pid) {
        m2_head_beranda();
        echo '<main class="m2-wrap">';
        $t = $pdo->prepare('SELECT COALESCE(target_amount,0) FROM targets_monthly WHERE period_key = ? AND property_id = ?');
        $t->execute([$period, $pid]);
        $target = (float) $t->fetchColumn();
        $a = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM transaction_allocations WHERE period_key = ? AND property_id = ?');
        $a->execute([$period, $pid]);
        $aktual = (float) $a->fetchColumn();
        $pct = $target > 0 ? $aktual / $target * 100 : 0;

        require_once __DIR__ . '/prospects.php';
        $prospek = 0.0;
        foreach (prospek_bulan($pdo, $pid, $period) as $pr) $prospek += (float) $pr['est_amount'];

        $pics = _m_pic_ach_rows($pdo, $period, [$pid]);
        $capai = count(array_filter($pics, fn($r) => $r['ach'] !== null && $r['ach'] >= 100));
        $antre = 0;
        try { $antre = count(ApprovalLine::antreanSaya($pdo, $pid)); } catch (Throwable $e) {}

        $fmt = fn(float $v) => rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',');
        $sisa = m2_sisa_hari_bulan();
        ?>
        <section class="m2-mhero">
            <div class="top"><span>Target tim · <?= h(pembayaran_nama_bulan($period, true)) ?></span><span class="pill"><?= $period === date('Y-m') ? ($sisa > 0 ? $sisa . ' hari lagi' : 'hari terakhir') : 'selesai' ?></span></div>
            <div class="nilai"><b><?= $fmt($pct) ?>%</b><span><?= m2_rp($aktual) ?> dari <?= m2_rp($target) ?></span></div>
            <div class="bar"><i style="width:<?= min(100, max(0, $pct)) ?>%"></i></div>
            <div class="tri">
                <div><b><?= $aktual >= $target ? m2_rp($aktual - $target) : m2_rp($target - $aktual) ?></b><?= $aktual >= $target ? 'lebih' : 'kurang' ?></div>
                <div><b><?= m2_rp($prospek) ?></b>prospek</div>
                <div><b><?= $capai ?> dari <?= count($pics) ?></b>PIC capai target</div>
            </div>
        </section>
        <div class="m2-duo">
            <?php if (can('view_exec_summary')): ?>
            <a href="?r=m_exec"><span class="ico"><?= m2_ic('chart') ?></span><span style="min-width:0"><b>Exec Summary</b><small>Ringkasan eksekutif</small></span></a>
            <?php endif; ?>
            <a href="?r=m2_skp&giliran=1"><span class="ico <?= $antre > 0 ? 'n' : '' ?>"><?= $antre > 0 ? $antre : m2_ic('cek') ?></span><span style="min-width:0"><b>Persetujuan</b><small><?= $antre > 0 ? $antre . ' menunggu Anda' : 'Tidak ada antrean' ?></small></span></a>
        </div>
        <section class="m2-card">
            <div class="m2-card-h"><h2>Pencapaian per PIC</h2><small>vs target individu</small></div>
            <?php if (!$pics): ?>
                <div class="m2-empty" style="padding:14px 4px">Belum ada PIC bertarget di properti ini.</div>
            <?php else: foreach ($pics as $p):
                $ok = $p['ach'] !== null && $p['ach'] >= 100;
                $lebih = $p['actual'] - $p['target']; ?>
            <a class="m2-pic" href="?r=pic_performance&pic=<?= urlencode($p['pic_name']) ?>">
                <div class="r">
                    <span class="ini"><?= h(strtoupper(mb_substr($p['pic_name'], 0, 1))) ?></span>
                    <span class="nm"><b><?= h($p['pic_name']) ?></b><small><?= m2_rp($p['actual']) ?> / <?= m2_rp($p['target']) ?></small></span>
                    <span class="pc"><b class="<?= $ok ? 'ok' : 'kurang' ?>"><?= $p['ach'] === null ? '—' : $p['ach'] . '%' ?></b><small class="<?= $ok ? 'ok' : 'kurang' ?>"><?= $lebih >= 0 ? 'lebih ' : 'kurang ' ?><?= m2_rp(abs($lebih)) ?></small></span>
                </div>
                <div class="tr"><i style="width:<?= min(100, max(0, (int) $p['ach'])) ?>%;background:<?= $ok ? '#0F766E' : '#F59E0B' ?>"></i></div>
            </a>
            <?php endforeach; endif; ?>
        </section>
        </main>
        <?php
    }, ['m2' => true]);
}

// ─── Unit ─────────────────────────────────────────────────────────────────────

function _m2_urut_lantai(string $l): int
{
    static $u = ['LG' => 1, 'GF' => 2, 'UG' => 3, 'FF' => 4, 'SF' => 5, 'P3' => 6, 'P4' => 7, 'P5' => 8];
    return $u[strtoupper(trim(explode(' ', $l)[0]))] ?? 9;
}

/** Unit Exhibition aktif properti ini, terurut menurut lantai lalu nama. */
function _m2_unit_master(PDO $pdo, int $pid): array
{
    $st = $pdo->prepare("SELECT code, floor, location_name, unit_type, area_sqm, rate FROM master_cl_units WHERE property_id = ? AND status = 'active'");
    $st->execute([$pid]);
    $u = $st->fetchAll();
    usort($u, function ($a, $b) {
        $c = [_m2_urut_lantai((string) $a['floor']), (string) $a['floor']] <=> [_m2_urut_lantai((string) $b['floor']), (string) $b['floor']];
        return $c ?: strnatcasecmp((string) ($a['location_name'] ?: $a['code']), (string) ($b['location_name'] ?: $b['code']));
    });
    return $u;
}

/** Transaksi Exhibition yang menyentuh rentang tanggal, dikelompokkan per kode unit. */
function _m2_transaksi_rentang(PDO $pdo, int $pid, string $dari, string $sampai): array
{
    [$where, $params] = transaksi_tampil_kondisi($pdo, 't');
    $where[] = "t.property_id = :pid AND t.module = 'cl' AND t.start_date <= :akhir AND t.end_date >= :awal";
    $params += [':pid' => $pid, ':akhir' => $sampai, ':awal' => $dari];
    $st = $pdo->prepare("SELECT t.id, t.master_code, t.start_date, t.end_date, t.final_amount, t.pic_name, t.created_by, t.invoice_no,
                                c.company_name, c.brand_name, " . pembayaran_select_sql('t') . "
                           FROM transactions t LEFT JOIN master_clients c ON c.id = t.client_id
                          WHERE " . implode(' AND ', $where) . " ORDER BY t.start_date, t.end_date, t.id");
    $st->execute($params);
    $per = [];
    foreach ($st->fetchAll() as $t) $per[(string) $t['master_code']][] = $t;
    return $per;
}

function _m2_header_unit(string $aktif, string $isi = ''): void
{
    ?>
    <header class="m2-head-unit">
        <div class="ttl"><h1>Ketersediaan unit</h1><span>Exhibition</span></div>
        <nav class="m2-seg" aria-label="Tampilan">
            <?php if ($aktif === 'daftar'): ?><span aria-current="page">Daftar</span><a href="?r=m2_kalender">Kalender</a>
            <?php else: ?><a href="?r=m2_unit">Daftar</a><span aria-current="page">Kalender</span><?php endif; ?>
        </nav>
        <?= $isi ?>
    </header>
    <?php
}

function m2_unit_page(PDO $pdo): void
{
    require_permission('view_transactions');
    $pid = current_property_id();

    $dari = (string) getv('dari', '');
    $sampai = (string) getv('sampai', '');
    $ok = fn(string $d) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    if (!$ok($dari) || !$ok($sampai) || $sampai < $dari) {
        // Hari ini bulan menjelang habis → tampilkan bulan depan penuh.
        if ((int) date('j') >= 26) { $dari = date('Y-m-01', strtotime('first day of next month')); $sampai = date('Y-m-t', strtotime($dari)); }
        else { $dari = date('Y-m-d'); $sampai = date('Y-m-t'); }
    }
    if ((strtotime($sampai) - strtotime($dari)) / 86400 > 92) $sampai = date('Y-m-d', strtotime($dari . ' +92 days'));
    $n = (int) round((strtotime($sampai) - strtotime($dari)) / 86400) + 1;

    $unit = _m2_unit_master($pdo, $pid);
    $per  = _m2_transaksi_rentang($pdo, $pid, $dari, $sampai);
    $lantaiAktif = trim((string) getv('lantai', ''));
    $lantai = [];
    foreach ($unit as $u) $lantai[(string) $u['floor']] = true;
    $lantai = array_keys($lantai);

    $baris = []; $adaKosong = 0;
    foreach ($unit as $u) {
        if ($lantaiAktif !== '' && (string) $u['floor'] !== $lantaiAktif) continue;
        $isi = array_fill(0, $n, false);
        foreach ($per[(string) $u['code']] ?? [] as $t) {
            $a = max(0, (int) round((strtotime(max($t['start_date'], $dari)) - strtotime($dari)) / 86400));
            $b = min($n - 1, (int) round((strtotime(min($t['end_date'], $sampai)) - strtotime($dari)) / 86400));
            for ($i = $a; $i <= $b; $i++) $isi[$i] = true;
        }
        $kosong = $n - count(array_filter($isi));
        if ($kosong > 0) $adaKosong++;
        // Ruas berurutan: [status, panjang]
        $ruas = [];
        foreach ($isi as $v) {
            if ($ruas && $ruas[count($ruas) - 1][0] === $v) $ruas[count($ruas) - 1][1]++; else $ruas[] = [$v, 1];
        }
        $baris[] = $u + ['kosong' => $kosong, 'ruas' => $ruas];
    }

    $q = fn(array $ubah) => '?' . http_build_query(array_merge(['r' => 'm2_unit', 'dari' => $dari, 'sampai' => $sampai, 'lantai' => $lantaiAktif], $ubah));
    $rentangLbl = m2_tgl_pendek($dari) . '–' . m2_tgl_pendek($sampai);

    layout('Unit', function () use ($baris, $adaKosong, $lantai, $lantaiAktif, $q, $dari, $sampai, $n, $rentangLbl) {
        ob_start(); ?>
        <div class="m2-search">
            <label><?= m2_ic('cari') ?><input type="search" id="m2cari" placeholder="Cari unit" aria-label="Cari unit"></label>
            <button type="button" class="m2-btn-o" id="m2tglbtn"><?= m2_ic('unit') ?><?= h($rentangLbl) ?></button>
        </div>
        <div class="m2-floors" role="tablist" aria-label="Lantai">
            <a role="tab" href="<?= h($q(['lantai' => ''])) ?>" aria-selected="<?= $lantaiAktif === '' ? 'true' : 'false' ?>">Semua</a>
            <?php foreach ($lantai as $l): ?><a role="tab" href="<?= h($q(['lantai' => $l])) ?>" aria-selected="<?= $lantaiAktif === $l ? 'true' : 'false' ?>"><?= h($l) ?></a><?php endforeach; ?>
        </div>
        <?php _m2_header_unit('daftar', ob_get_clean()); ?>
        <main class="m2-wrap">
            <div class="m2-sum">
                <span><b><?= $adaKosong ?> unit</b> punya hari kosong di <?= h($rentangLbl) ?></span>
                <span class="lg"><span><i style="background:#CCEFE9"></i>kosong</span><span><i style="background:#94A3B8"></i>terisi</span></span>
            </div>
            <?php if (!$baris): ?><div class="m2-card m2-empty">Tidak ada unit di lantai ini.</div><?php endif; ?>
            <?php foreach ($baris as $u):
                $k = (int) $u['kosong'];
                [$kls, $lbl] = $k === $n ? ['hj', 'Kosong penuh'] : ($k > 0 ? ['kn', 'Kosong ' . $k . ' hari'] : ['ab', 'Terisi penuh']);
                $tarif = (float) $u['rate'] > 0 ? ' · ' . money((float) $u['rate']) : '';
                $ket = trim($u['floor'] . ' · ' . rtrim(rtrim(number_format((float) $u['area_sqm'], 2, ',', ''), '0'), ',') . ' m²' . $tarif);
                $nama = trim($u['code'] . ($u['location_name'] ? ' · ' . $u['location_name'] : '')); ?>
            <a class="m2-u" data-cari="<?= h(mb_strtolower($nama)) ?>" href="?r=m2_unit_detail&kode=<?= urlencode($u['code']) ?>&dari=<?= h($dari) ?>&sampai=<?= h($sampai) ?>">
                <div class="h"><div><b><?= h($nama) ?></b><small><?= h($ket) ?></small></div><span class="m2-st <?= $kls ?>"><?= h($lbl) ?></span></div>
                <div class="m2-strip" aria-hidden="true"><?php foreach ($u['ruas'] as [$terisi, $pj]): ?><i class="<?= $terisi ? 't' : 'k' ?>" style="flex:<?= $pj ?>"></i><?php endforeach; ?></div>
                <div class="m2-strip-l"><span><?= h(m2_tgl_pendek($dari)) ?></span><span><?= $n ?> hari</span><span><?= h(m2_tgl_pendek($sampai)) ?></span></div>
            </a>
            <?php endforeach; ?>
        </main>

        <div class="m2-sheet" id="m2tglsheet" role="dialog" aria-modal="true" aria-label="Pilih tanggal">
            <button type="button" class="ov" aria-label="Tutup"></button>
            <form class="pn" method="get">
                <div class="grip"></div><h2>Rentang tanggal</h2>
                <input type="hidden" name="r" value="m2_unit"><input type="hidden" name="lantai" value="<?= h($lantaiAktif) ?>">
                <label class="m2-lbl" for="m2d1">Dari</label><input class="m2-fld" type="date" id="m2d1" name="dari" value="<?= h($dari) ?>" required>
                <label class="m2-lbl" for="m2d2">Sampai</label><input class="m2-fld" type="date" id="m2d2" name="sampai" value="<?= h($sampai) ?>" required>
                <div style="margin-top:16px"><button class="m2-primary" type="submit">Terapkan</button></div>
            </form>
        </div>
        <script>
        (function () {
            var s = document.getElementById('m2tglsheet');
            document.getElementById('m2tglbtn').addEventListener('click', function () { s.classList.add('on'); });
            s.querySelector('.ov').addEventListener('click', function () { s.classList.remove('on'); });
            var c = document.getElementById('m2cari');
            c.addEventListener('input', function () {
                var v = c.value.trim().toLowerCase();
                document.querySelectorAll('.m2-u').forEach(function (e) { e.style.display = e.getAttribute('data-cari').indexOf(v) > -1 ? '' : 'none'; });
            });
        })();
        </script>
        <?php
    }, ['m2' => true]);
}

/** Kalender mingguan: unit di baris, Senin–Minggu di kolom. */
function m2_kalender_page(PDO $pdo): void
{
    require_permission('view_transactions');
    $pid = current_property_id();

    $ref = (string) getv('minggu', date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref) || strtotime($ref) === false) $ref = date('Y-m-d');
    $senin = date('Y-m-d', strtotime('monday this week', strtotime($ref)));
    $minggu = date('Y-m-d', strtotime($senin . ' +6 days'));
    $hari = [];
    for ($i = 0; $i < 7; $i++) $hari[] = date('Y-m-d', strtotime($senin . " +$i days"));

    $unit = _m2_unit_master($pdo, $pid);
    $per  = _m2_transaksi_rentang($pdo, $pid, $senin, $minggu);
    require_once __DIR__ . '/prospects.php';
    $warna = prospek_warna_pic($pdo, $pid);
    $scope = current_sales_scope($pdo, $pid);

    // Prospek terbuka yang menyentuh minggu ini.
    $ps = $pdo->prepare("SELECT * FROM prospects WHERE property_id = ? AND deleted_at IS NULL AND status = 'open' AND module = 'cl' AND start_date <= ? AND end_date >= ?");
    $ps->execute([$pid, $minggu, $senin]);
    $pros = [];
    foreach ($ps->fetchAll() as $p) $pros[trim((string) $p['master_code'])][] = $p;

    $pakaiPic = [];
    $grup = [];
    foreach ($unit as $u) {
        $kode = (string) $u['code'];
        $blok = [];
        foreach ($per[$kode] ?? [] as $t) {
            $milik = !$scope || ($scope['pic'] !== '' && (string) $t['pic_name'] === $scope['pic']) || (string) $t['created_by'] === $scope['uname'];
            $blok[] = ['a' => max($t['start_date'], $senin), 'b' => min($t['end_date'], $minggu), 'pros' => false, 'milik' => $milik,
                       'merek' => (string) ($t['brand_name'] ?: $t['company_name'] ?: '-'), 'pt' => (string) $t['company_name'],
                       'pic' => (string) $t['pic_name'], 'warna' => $warna[(string) $t['pic_name']] ?? '#E2E8F0',
                       'periode' => m2_tgl_pendek($t['start_date']) . ' – ' . m2_tgl_pendek($t['end_date']),
                       'nilai' => $milik ? money((float) $t['final_amount']) : '', 'id' => (int) $t['id'], 'bayar' => $milik ? (string) $t['bayar_status'] : '', 'lewat' => $milik ? (int) $t['bayar_lewat'] : 0];
            $pakaiPic[(string) $t['pic_name']] = $warna[(string) $t['pic_name']] ?? '#E2E8F0';
        }
        foreach ($pros[$kode] ?? [] as $p) {
            $blok[] = ['a' => max((string) $p['start_date'], $senin), 'b' => min((string) $p['end_date'], $minggu), 'pros' => true, 'milik' => true,
                       'merek' => (string) $p['client_name'], 'pt' => 'prospek', 'pic' => (string) $p['pic_name'], 'warna' => $warna[(string) $p['pic_name']] ?? '#FDE68A',
                       'periode' => m2_tgl_pendek((string) $p['start_date']) . ' – ' . m2_tgl_pendek((string) $p['end_date']),
                       'nilai' => '± ' . money((float) $p['est_amount']), 'id' => (int) $p['id'], 'bayar' => '', 'lewat' => 0];
        }
        // Jalur: blok yang tanggalnya tidak bertabrakan berbagi baris.
        usort($blok, fn($x, $y) => $x['a'] <=> $y['a']);
        $lane = [];
        foreach ($blok as $i => $b) {
            $pos = null;
            foreach ($lane as $li => $akhir) if ($akhir < $b['a']) { $pos = $li; break; }
            if ($pos === null) { $pos = count($lane); }
            $lane[$pos] = $b['b'];
            $blok[$i]['lane'] = $pos;
        }
        $grup[(string) $u['floor']][] = $u + ['blok' => $blok, 'jalur' => max(1, count($lane))];
    }

    $prev = date('Y-m-d', strtotime($senin . ' -7 days'));
    $next = date('Y-m-d', strtotime($senin . ' +7 days'));
    $judulMinggu = m2_tgl_pendek($senin) . ' – ' . m2_tgl_pendek($minggu) . ' ' . date('Y', strtotime($minggu));
    $bisaOffer = can('manage_offers');
    $bisaBayar = can('view_payments') || can('manage_payments');
    $statBayar = ['lunas' => 'Lunas', 'sebagian' => 'Sebagian', 'belum_bayar' => 'Belum dibayar', 'belum_invoice' => 'Belum invoice', 'tanpa_tagihan' => 'Tanpa tagihan'];

    layout('Kalender', function () use ($grup, $hari, $senin, $prev, $next, $judulMinggu, $bisaOffer, $bisaBayar, $statBayar, $pakaiPic) {
        _m2_header_unit('kalender', '<div class="m2-weekbar">
            <a href="?r=m2_kalender&minggu=' . $prev . '" aria-label="Minggu sebelumnya">' . m2_ic('kiri') . '</a>
            <div>' . h($judulMinggu) . '<small>geser kiri-kanan untuk ganti minggu</small></div>
            <a href="?r=m2_kalender&minggu=' . $next . '" aria-label="Minggu berikutnya">' . m2_ic('kanan') . '</a></div>');
        ?>
        <main class="m2-wrap" id="m2kal" data-prev="<?= h($prev) ?>" data-next="<?= h($next) ?>">
            <div class="m2-cal">
                <div class="rowh">
                    <div class="u">UNIT</div>
                    <?php foreach ($hari as $d): ?><div class="c<?= $d === date('Y-m-d') ? ' now' : '' ?>"><?= h(m2_hari_pendek($d)) ?><b><?= (int) substr($d, 8, 2) ?></b></div><?php endforeach; ?>
                </div>
                <?php if (!$grup): ?><div class="m2-empty">Belum ada unit Exhibition di properti ini.</div><?php endif; ?>
                <?php foreach ($grup as $lantai => $daftar): ?>
                    <div class="fl"><?= h(strtoupper($lantai)) ?></div>
                    <?php foreach ($daftar as $u): $kode = (string) $u['code']; $L = (int) $u['jalur']; ?>
                    <div class="rowu" style="grid-template-rows:repeat(<?= $L ?>,44px)">
                        <div class="un" style="grid-row:1 / span <?= $L ?>"><b><?= h($u['location_name'] ?: $kode) ?></b><small><?= h(preg_replace('/\s+/', ' ', (string) $u['unit_type']) ?: $kode) ?></small></div>
                        <?php for ($r = 1; $r <= $L; $r++) foreach ($hari as $i => $d):
                            $we = in_array(date('w', strtotime($d)), ['0', '6'], true);
                            if ($bisaOffer): ?>
                            <a class="ko<?= $we ? ' we' : '' ?>" style="grid-row:<?= $r ?>;grid-column:<?= $i + 2 ?>" aria-label="Kosong <?= h(m2_tgl_pendek($d)) ?>, mulai penawaran" href="?r=offer_form&module=cl&master_code=<?= urlencode($kode) ?>&start_date=<?= $d ?>&end_date=<?= $d ?>"></a>
                            <?php else: ?><span class="ko<?= $we ? ' we' : '' ?>" style="grid-row:<?= $r ?>;grid-column:<?= $i + 2 ?>"></span><?php endif;
                        endforeach; ?>
                        <?php foreach ($u['blok'] as $b):
                            $c1 = (int) round((strtotime($b['a']) - strtotime($senin)) / 86400);
                            $c2 = (int) round((strtotime($b['b']) - strtotime($senin)) / 86400);
                            $det = ['merek' => $b['merek'], 'pt' => $b['pt'], 'unit' => $kode, 'periode' => $b['periode'], 'pic' => $b['pic'], 'nilai' => $b['nilai'],
                                    'pros' => $b['pros'], 'id' => $b['id'], 'milik' => $b['milik'], 'bayar' => ($bisaBayar && $b['bayar']) ? ($statBayar[$b['bayar']] ?? $b['bayar']) . ($b['lewat'] ? ' · lewat tempo' : '') : ''];
                            ?>
                        <button type="button" class="blk<?= $b['pros'] ? ' pros' : '' ?>" style="grid-row:<?= $b['lane'] + 1 ?>;grid-column:<?= $c1 + 2 ?> / span <?= $c2 - $c1 + 1 ?>;background:<?= h($b['warna']) ?>" data-d="<?= h(json_encode($det, JSON_UNESCAPED_UNICODE)) ?>"><?= h($b['merek']) ?></button>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
            <div class="m2-legend">
                <?php foreach ($pakaiPic as $nm => $w): if ($nm === '') continue; ?><span><i style="background:<?= h($w) ?>"></i><?= h($nm) ?></span><?php endforeach; ?>
                <span><i style="background:#FDE68A;border:1px dashed #94A3B8"></i>prospek</span>
            </div>
        </main>

        <div class="m2-sheet" id="m2det" role="dialog" aria-modal="true" aria-label="Detail">
            <button type="button" class="ov" aria-label="Tutup"></button>
            <section class="pn">
                <div class="grip"></div>
                <h2 id="m2dh"></h2><div id="m2dpt" style="color:#5B6B7F;font-size:13px;margin-top:-6px"></div>
                <div class="m2-kv" id="m2dkv"></div>
                <div class="m2-acts" id="m2dact"></div>
            </section>
        </div>
        <script>
        (function () {
            var sh = document.getElementById('m2det'), BISA_OFFER = <?= $bisaOffer ? 'true' : 'false' ?>;
            function kv(k, v) { if (!v) return null; var d = document.createElement('div'), s = document.createElement('small'), b = document.createElement('b'); s.textContent = k; b.textContent = v; d.appendChild(s); d.appendChild(b); return d; }
            function aksi(t, href, p) { var a = document.createElement('a'); a.textContent = t; a.href = href; if (p) a.className = 'p'; return a; }
            document.querySelectorAll('.m2-cal .blk').forEach(function (el) {
                el.addEventListener('click', function () {
                    var j = JSON.parse(el.getAttribute('data-d'));
                    document.getElementById('m2dh').textContent = (j.pros ? 'Prospek · ' : '') + j.merek;
                    document.getElementById('m2dpt').textContent = j.pt;
                    var g = document.getElementById('m2dkv'); g.textContent = '';
                    [kv('Unit', j.unit), kv('Periode', j.periode), kv('PIC', j.pic), kv(j.pros ? 'Perkiraan' : 'Nilai', j.nilai), kv('Pembayaran', j.bayar)].forEach(function (x) { if (x) g.appendChild(x); });
                    var a = document.getElementById('m2dact'); a.textContent = '';
                    if (j.pros) { a.appendChild(aksi('Ubah prospek', '?r=prospect_form&id=' + j.id)); }
                    else if (j.milik) {
                        a.appendChild(aksi('Rincian', '?r=m2_alokasi&id=' + j.id));
                        <?php if ($bisaBayar): ?>a.appendChild(aksi('Tagihan', '?r=payment_form&id=' + j.id));<?php endif; ?>
                        if (BISA_OFFER) a.appendChild(aksi('Perpanjang', '?r=offer_form&renew_from=' + j.id, true));
                    }
                    sh.classList.add('on');
                });
            });
            sh.querySelector('.ov').addEventListener('click', function () { sh.classList.remove('on'); });
            // Geser kiri/kanan ganti minggu (hanya bila gerak mendatar jelas, bukan scroll).
            var m = document.getElementById('m2kal'), x0 = 0, y0 = 0;
            m.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; }, { passive: true });
            m.addEventListener('touchend', function (e) {
                var dx = e.changedTouches[0].clientX - x0, dy = e.changedTouches[0].clientY - y0;
                if (Math.abs(dx) > 90 && Math.abs(dx) > Math.abs(dy) * 2) location.href = '?r=m2_kalender&minggu=' + (dx < 0 ? m.dataset.next : m.dataset.prev);
            }, { passive: true });
        })();
        </script>
        <?php
    }, ['m2' => true]);
}

function m2_unit_detail_page(PDO $pdo): void
{
    require_permission('view_transactions');
    $pid  = current_property_id();
    $kode = trim((string) getv('kode', ''));
    $st = $pdo->prepare("SELECT * FROM master_cl_units WHERE property_id = ? AND code = ? LIMIT 1");
    $st->execute([$pid, $kode]);
    $u = $st->fetch();
    if (!$u) { flash('Unit tidak ditemukan.'); redirect_to('m2_unit'); }

    $bulan = periode_bulan('bulan');
    $awal  = $bulan . '-01';
    $akhir = date('Y-m-t', strtotime($awal));
    $per   = _m2_transaksi_rentang($pdo, $pid, $awal, $akhir)[$kode] ?? [];
    $terisi = [];   // tanggal => nama merek
    foreach ($per as $t) {
        for ($d = max($t['start_date'], $awal); $d <= min($t['end_date'], $akhir); $d = date('Y-m-d', strtotime($d . ' +1 day')))
            $terisi[$d] = (string) ($t['brand_name'] ?: $t['company_name']);
    }
    // Penyewa sebelumnya
    [$wh, $pr] = transaksi_tampil_kondisi($pdo, 't');
    $wh[] = "t.property_id = :pid AND t.module = 'cl' AND t.master_code = :kode";
    $pr += [':pid' => $pid, ':kode' => $kode];
    $sb = $pdo->prepare("SELECT c.company_name, c.brand_name, MIN(t.start_date) mulai, MAX(t.end_date) selesai, COUNT(*) n
                           FROM transactions t LEFT JOIN master_clients c ON c.id = t.client_id
                          WHERE " . implode(' AND ', $wh) . "
                          GROUP BY t.client_id ORDER BY selesai DESC LIMIT 4");
    $sb->execute($pr);
    $sebelum = $sb->fetchAll();

    $prev = date('Y-m', strtotime($awal . ' -1 month'));
    $next = date('Y-m', strtotime($awal . ' +1 month'));
    $hariN = (int) date('t', strtotime($awal));
    $offset = (int) date('N', strtotime($awal)) - 1;
    $nama = $u['location_name'] ?: $u['code'];

    layout($nama, function () use ($u, $kode, $bulan, $awal, $hariN, $offset, $terisi, $sebelum, $prev, $next, $nama) {
        $back = '?r=m2_unit' . (getv('dari') ? '&dari=' . urlencode((string) getv('dari')) . '&sampai=' . urlencode((string) getv('sampai')) : '');
        m2_top((string) $nama, $back, current_property()['name'] . ' · Exhibition');
        $luas = rtrim(rtrim(number_format((float) $u['area_sqm'], 2, ',', ''), '0'), ',');
        ?>
        <main class="m2-wrap" style="padding-bottom:90px">
            <div class="m2-stats">
                <div><small>Luas</small><b><?= h($luas) ?> m²</b></div>
                <div><small>Tarif</small><b><?= (float) $u['rate'] > 0 ? m2_rp((float) $u['rate']) : '—' ?></b></div>
                <div><small>Lantai</small><b><?= h($u['floor']) ?></b></div>
            </div>
            <section class="m2-card">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
                    <a class="m2-back" href="?r=m2_unit_detail&kode=<?= urlencode($kode) ?>&bulan=<?= $prev ?>" aria-label="Bulan sebelumnya"><?= m2_ic('kiri') ?></a>
                    <h2><?= h(pembayaran_nama_bulan($bulan, true)) ?></h2>
                    <a class="m2-back" href="?r=m2_unit_detail&kode=<?= urlencode($kode) ?>&bulan=<?= $next ?>" aria-label="Bulan berikutnya"><?= m2_ic('kanan') ?></a>
                </div>
                <div class="m2-month" id="m2bln" data-bulan="<?= h($bulan) ?>">
                    <?php foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $hn): ?><span class="hd"><?= $hn ?></span><?php endforeach; ?>
                    <?php for ($i = 0; $i < $offset; $i++) echo '<span></span>'; ?>
                    <?php for ($d = 1; $d <= $hariN; $d++): $tgl = $bulan . '-' . str_pad((string) $d, 2, '0', STR_PAD_LEFT); $isi = $terisi[$tgl] ?? null; ?>
                    <button type="button" class="<?= $isi !== null ? 't' : '' ?>" data-tgl="<?= $tgl ?>" <?= $isi !== null ? 'disabled title="' . h($isi) . '"' : '' ?>><?= $d ?></button>
                    <?php endfor; ?>
                </div>
                <div class="m2-legend" style="margin-top:10px">
                    <span><i style="background:#E2E8F0"></i>Terisi</span><span><i style="background:#0F766E"></i>Dipilih</span>
                </div>
                <div class="m2-empty" style="padding:8px 0 0;font-size:12.5px" id="m2hint">Ketuk tanggal mulai, lalu tanggal akhir.</div>
            </section>
            <section class="m2-card">
                <h2>Penyewa sebelumnya</h2>
                <?php if (!$sebelum): ?><div class="m2-empty" style="padding:12px 0 4px">Belum pernah disewa.</div>
                <?php else: foreach ($sebelum as $s): ?>
                <div class="m2-prior"><span><?= h($s['brand_name'] ?: $s['company_name'] ?: '-') ?></span><span><?= h(m2_tgl_pendek($s['mulai']) . ' – ' . m2_tgl_pendek($s['selesai'])) ?><?= (int) $s['n'] > 1 ? ' · ' . (int) $s['n'] . ' kali' : '' ?></span></div>
                <?php endforeach; endif; ?>
            </section>
        </main>
        <div class="m2-stickbar">
            <?php if (can('manage_prospects')): ?><a class="m2-sec" id="m2pros" href="?r=prospect_form&master_code=<?= urlencode($kode) ?>">Catat prospek</a><?php else: ?><span></span><?php endif; ?>
            <?php if (can('manage_offers')): ?><a class="m2-primary" id="m2tawar" href="?r=offer_form&module=cl&master_code=<?= urlencode($kode) ?>">Tawarkan unit</a><?php endif; ?>
        </div>
        <script>
        (function () {
            var g = document.getElementById('m2bln'), a = null, b = null, hint = document.getElementById('m2hint');
            var kode = <?= json_encode($kode) ?>;
            function ymd(s) { var p = s.split('-'); return +p[2]; }
            function bln(s) { var d = new Date(s + 'T00:00:00'); return d.getDate() + ' ' + ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'][d.getMonth()]; }
            function ulas() {
                g.querySelectorAll('button').forEach(function (x) {
                    var t = x.getAttribute('data-tgl'), on = a && t >= a && t <= (b || a);
                    if (!x.disabled) x.className = on ? 's' : '';
                });
                var bt = document.getElementById('m2tawar'), pr = document.getElementById('m2pros');
                if (a) {
                    var z = b || a;
                    hint.textContent = bln(a) + ' – ' + bln(z) + ' · ' + (Math.round((new Date(z) - new Date(a)) / 86400000) + 1) + ' hari';
                    if (bt) bt.href = '?r=offer_form&module=cl&master_code=' + encodeURIComponent(kode) + '&start_date=' + a + '&end_date=' + z;
                    if (pr) pr.href = '?r=prospect_form&master_code=' + encodeURIComponent(kode) + '&start_date=' + a + '&end_date=' + z;
                }
            }
            g.addEventListener('click', function (e) {
                var x = e.target.closest('button'); if (!x || x.disabled) return;
                var t = x.getAttribute('data-tgl');
                if (!a || b) { a = t; b = null; } else if (t < a) { b = a; a = t; } else { b = t; }
                // Rentang tidak boleh melompati hari yang terisi.
                if (b) { var bad = false; g.querySelectorAll('button[disabled]').forEach(function (y) { var s = y.getAttribute('data-tgl'); if (s > a && s < b) bad = true; });
                    if (bad) { hint.textContent = 'Rentang itu melewati hari yang sudah terisi. Pilih ulang.'; a = null; b = null; ulas(); return; } }
                ulas();
            });
        })();
        </script>
        <?php
    }, ['m2' => true]);
}

// ─── Profil ───────────────────────────────────────────────────────────────────

function m2_profil_page(PDO $pdo): void
{
    $user = $_SESSION['user'] ?? [];
    $pid  = current_property_id();
    $nama = (string) ($user['name'] ?? 'User');
    $period = periode_bulan();

    $st = $pdo->prepare('SELECT p.name, p.target_share FROM master_pic p WHERE p.user_id = ? AND p.status = "active" AND p.property_id = ? LIMIT 1');
    $st->execute([(int) ($user['id'] ?? 0), $pid]);
    $pic = $st->fetch() ?: null;

    $pct = null; $deal = null; $klien = null;
    if ($pic) {
        $t = $pdo->prepare('SELECT COALESCE(target_amount,0) FROM targets_monthly WHERE period_key = ? AND property_id = ?');
        $t->execute([$period, $pid]);
        $target = (float) $t->fetchColumn() * (float) $pic['target_share'];
        $a = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM transaction_allocations WHERE pic_name = ? AND period_key = ? AND property_id = ?');
        $a->execute([$pic['name'], $period, $pid]);
        $pct = $target > 0 ? (int) round((float) $a->fetchColumn() / $target * 100) : null;
        $d = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE pic_name = ? AND property_id = ? AND deleted_at IS NULL AND start_date >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)");
        $d->execute([$pic['name'], $pid]);
        $deal = (int) $d->fetchColumn();
        $k = $pdo->prepare("SELECT COUNT(DISTINCT client_id) FROM transactions WHERE pic_name = ? AND property_id = ? AND deleted_at IS NULL AND end_date >= CURDATE()");
        $k->execute([$pic['name'], $pid]);
        $klien = (int) $k->fetchColumn();
    }
    $renew = 0;
    [$sc, $sp] = current_sales_scope_sql($pdo, $pid, 't.pic_name', 't.created_by');
    $r = $pdo->prepare("SELECT COUNT(*) FROM transactions t WHERE t.property_id = ? AND t.deleted_at IS NULL AND t.module = 'cl' AND t.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) $sc");
    $r->execute(array_merge([$pid], $sp));
    $renew = (int) $r->fetchColumn();

    layout('Profil', function () use ($user, $nama, $pic, $pct, $deal, $klien, $renew) {
        $props = allowed_properties();
        ?>
        <header class="m2-prof">
            <a class="m2-back" href="?r=m_home" aria-label="Kembali"><?= m2_ic('kiri') ?></a>
            <div class="id">
                <span class="m2-av"><?= h(strtoupper(mb_substr($nama, 0, 1))) ?></span>
                <div><b><?= h($nama) ?></b><small><?= h(m2_peran_label()) ?> · Casual Leasing</small>
                    <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap"><?php foreach ($props as $p): ?><span class="m2-chip"><?= h($p['name']) ?></span><?php endforeach; ?></div></div>
            </div>
            <?php if ($pic): ?>
            <div class="stat">
                <div><b><?= $pct === null ? '—' : $pct . '%' ?></b><small>target bulan ini</small></div>
                <div><b><?= (int) $deal ?></b><small>deal 3 bln</small></div>
                <div><b><?= (int) $klien ?></b><small>klien aktif</small></div>
            </div>
            <?php endif; ?>
        </header>
        <main class="m2-wrap" style="padding-top:16px">
            <div>
                <div class="m2-cap">Pekerjaan saya</div>
                <div class="m2-list">
                    <?php if (can('view_pic_report')): ?><a class="m2-row" href="?r=pic_performance"><span class="ri"><?= m2_ic('chart') ?></span><span class="t">Performa &amp; evaluasi</span><?= m2_ic('kanan') ?></a><?php endif; ?>
                    <?php if (can('view_renewals')): ?><a class="m2-row" href="?r=renewals"><span class="ri"><?= m2_ic('renew') ?></span><span class="t">Renewal kontrak</span><span class="rv"><?= $renew > 0 ? $renew . ' segera habis' : '' ?></span><?= m2_ic('kanan') ?></a><?php endif; ?>
                    <?php if (can('request_delete')): ?><a class="m2-row" href="?r=deletion_request"><span class="ri"><?= m2_ic('trash') ?></span><span class="t">Pengajuan hapus data</span><?= m2_ic('kanan') ?></a><?php endif; ?>
                </div>
            </div>
            <div>
                <div class="m2-cap">Akun</div>
                <div class="m2-list">
                    <a class="m2-row" href="?r=my_signature"><span class="ri"><?= m2_ic('ttd') ?></span><span class="t">Tanda tangan saya</span><?= m2_ic('kanan') ?></a>
                    <?php if (can('approve_skp')): ?><a class="m2-row" href="?r=my_paraf"><span class="ri"><?= m2_ic('ttd') ?></span><span class="t">Paraf saya</span><?= m2_ic('kanan') ?></a><?php endif; ?>
                    <a class="m2-row" href="?r=change_password"><span class="ri"><?= m2_ic('kunci') ?></span><span class="t">Ganti password</span><?= m2_ic('kanan') ?></a>
                    <a class="m2-row" href="?view=desktop"><span class="ri"><?= m2_ic('layar') ?></span><span class="t">Tampilan desktop</span><?= m2_ic('kanan') ?></a>
                    <div class="m2-row"><span class="ri"><?= m2_ic('surat') ?></span><span class="t" style="font-size:13px;overflow:hidden;text-overflow:ellipsis"><?= h((string) ($user['email'] ?? '')) ?></span></div>
                </div>
            </div>
            <a class="m2-out" href="?r=logout"><?= m2_ic('keluar') ?>Keluar</a>
        </main>
        <?php
    }, ['m2' => true]);
}
