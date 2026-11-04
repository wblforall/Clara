<?php
declare(strict_types=1);

/**
 * Tampilan mobile baru, tahap 2–3: Penawaran, SKP/SKS (+ Persetujuan),
 * Pipeline, Klien. Kerangka & alat bersama ada di mobile2.php.
 *
 * Halaman ini hanya MEMBACA dan menautkan ke halaman yang sudah ada untuk
 * tindakan (form, cetak, paraf) — alur dan aturan bisnisnya tidak diubah.
 */

/** Header berjudul dengan segmen opsional. $seg = [[href, label, aktif], ...] */
function _m2_head(string $judul, string $kanan = '', array $seg = [], string $isi = ''): void
{
    ?>
    <header class="m2-head-unit">
        <div class="ttl"><h1><?= h($judul) ?></h1><?php if ($kanan !== '' && !m2_prop_tabs()): ?><span><?= h($kanan) ?></span><?php endif; ?></div>
        <?= m2_prop_tabs() ?>
        <?php if ($seg): ?>
        <nav class="m2-seg" style="grid-template-columns:repeat(<?= count($seg) ?>,1fr)">
            <?php foreach ($seg as [$href, $lbl, $on]): echo $on ? '<span aria-current="page">' . h($lbl) . '</span>' : '<a href="' . h($href) . '">' . h($lbl) . '</a>'; endforeach; ?>
        </nav>
        <?php endif; ?>
        <?= $isi ?>
    </header>
    <?php
}

/** Deretan chip (saringan). $chip = [[href, label, aktif, jumlah|null], ...] */
function _m2_chips(array $chip): string
{
    $o = '<div class="m2-floors" role="tablist">';
    foreach ($chip as [$href, $lbl, $on, $n]) {
        $o .= '<a role="tab" href="' . h($href) . '" aria-selected="' . ($on ? 'true' : 'false') . '">' . h($lbl)
            . ($n !== null ? ' <span class="m2-n">' . (int) $n . '</span>' : '') . '</a>';
    }
    return $o . '</div>';
}

function _m2_modul(string $m): array
{
    return ['cl' => ['Exhibition', '#0F766E', '#CCFBF1'], 'media' => ['Media', '#075985', '#E0F2FE'], 'gudang' => ['Gudang', '#92400E', '#FEF3C7']][$m] ?? [strtoupper($m), '#374151', '#F1F5F9'];
}

function _m2_tgl_pendek_aman(?string $d): string
{
    return $d && strtotime($d) ? m2_tgl_pendek($d) : '—';
}

function _m2_seg_dokumen(string $aktif): array
{
    $seg = [['?r=m2_offers', 'Penawaran', $aktif === 'o']];
    if (can('manage_skp')) $seg[] = ['?r=m2_skp', 'SKP / SKS', $aktif === 's'];
    return $seg;
}

// ─── Penawaran ────────────────────────────────────────────────────────────────

function m2_offers_page(PDO $pdo): void
{
    require_permission('manage_offers');
    $pid = current_property_id();

    $tabStatus = ['on_going' => ['draft', 'sent', 'nego'], 'deal' => ['deal'], 'closed' => ['cancelled']];
    $tab = (string) getv('tab', 'on_going');
    if (!isset($tabStatus[$tab])) $tab = 'on_going';
    $module = (string) getv('module', '');
    if (!in_array($module, ['cl', 'media', 'gudang'], true)) $module = '';

    [$sc, $sp]   = current_sales_scope_sql($pdo, $pid, 'o.pic_name', 'o.created_by');
    [$scC, $spC] = current_sales_scope_sql($pdo, $pid, 'pic_name', 'created_by');

    $counts = ['on_going' => 0, 'deal' => 0, 'closed' => 0];
    $cs = $pdo->prepare('SELECT status, COUNT(*) c FROM offers WHERE property_id = ? AND deleted_at IS NULL' . ($module ? ' AND module = ?' : '') . $scC . ' GROUP BY status');
    $cs->execute(array_merge([$pid], $module ? [$module] : [], $spC));
    foreach ($cs->fetchAll() as $r) foreach ($tabStatus as $t => $sts) if (in_array($r['status'], $sts, true)) $counts[$t] += (int) $r['c'];

    $in = implode(',', array_fill(0, count($tabStatus[$tab]), '?'));
    $st = $pdo->prepare("SELECT o.*, c.company_name, c.brand_name FROM offers o LEFT JOIN master_clients c ON c.id = o.client_id
                          WHERE o.property_id = ? AND o.deleted_at IS NULL AND o.status IN ($in)" . ($module ? ' AND o.module = ?' : '') . $sc . ' ORDER BY o.id DESC LIMIT 80');
    $st->execute(array_merge([$pid], $tabStatus[$tab], $module ? [$module] : [], $sp));
    $rows = $st->fetchAll();

    layout('Penawaran', function () use ($rows, $tab, $counts, $module) {
        $mq = $module ? '&module=' . $module : '';
        $chip = '<div style="margin-top:12px">' . _m2_chips([
            ['?r=m2_offers&tab=on_going' . $mq, 'Berjalan', $tab === 'on_going', $counts['on_going']],
            ['?r=m2_offers&tab=deal' . $mq, 'Deal', $tab === 'deal', $counts['deal']],
            ['?r=m2_offers&tab=closed' . $mq, 'Tidak deal', $tab === 'closed', $counts['closed']],
        ]) . '</div>';
        _m2_head('Dokumen', current_property()['name'] ?? '', _m2_seg_dokumen('o'), $chip);
        $badge = ['draft' => ['Draft', '#475569', '#F1F5F9'], 'sent' => ['Terkirim', '#075985', '#E0F2FE'], 'nego' => ['Negosiasi', '#92400E', '#FEF3C7'],
                  'deal' => ['Deal', '#166534', '#DCFCE7'], 'cancelled' => ['Tidak deal', '#991B1B', '#FEE2E2']];
        $mods = ['' => 'Semua', 'cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
        ?>
        <main class="m2-wrap">
            <div class="m2-chipline">
                <?php foreach ($mods as $k => $l): ?><a href="?r=m2_offers&tab=<?= h($tab) ?><?= $k ? '&module=' . $k : '' ?>" class="<?= $module === $k ? 'on' : '' ?>"><?= h($l) ?></a><?php endforeach; ?>
            </div>
            <?php if (!$rows): ?><div class="m2-card m2-empty">Belum ada penawaran di tab ini.</div><?php endif; ?>
            <?php foreach ($rows as $o):
                $b = $badge[$o['status']] ?? $badge['draft'];
                [$ml, $mc, $mbg] = _m2_modul((string) $o['module']);
                $amt = !empty($o['override_amount']) ? $o['override_amount'] : ($o['total_calculated'] ?? 0);
                $nama = $o['brand_name'] ?: $o['company_name'] ?: '—'; ?>
            <article class="m2-o">
                <a class="mn" href="?r=offer_view&id=<?= (int) $o['id'] ?>">
                    <div class="h"><b><?= h($o['offer_no'] ?: '(draft)') ?></b><span class="m2-st" style="color:<?= $b[1] ?>;background:<?= $b[2] ?>"><?= $b[0] ?></span></div>
                    <div class="nm"><?= h($nama) ?></div>
                    <div class="meta"><span class="m2-mod" style="color:<?= $mc ?>;background:<?= $mbg ?>"><?= h($ml) ?></span>
                        <span><?= h(_m2_tgl_pendek_aman($o['start_date'])) ?> – <?= h(_m2_tgl_pendek_aman($o['end_date'])) ?></span>
                        <b><?= m2_rp((float) $amt) ?></b></div>
                </a>
                <?php if (!empty($o['offer_no'])): ?>
                <div class="acts">
                    <a href="?r=offer_view&id=<?= (int) $o['id'] ?>">Buka</a>
                    <a href="?r=offer_print&id=<?= (int) $o['id'] ?>" target="_blank" rel="noopener">PDF</a>
                    <a href="#" onclick="claraSharePdf('?r=offer_print&id=<?= (int) $o['id'] ?>','<?= h($o['offer_no']) ?>',this);return false">Bagikan</a>
                </div>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
        </main>
        <?php
    }, ['m2' => true]);
}

// ─── SKP / SKS dan Persetujuan ────────────────────────────────────────────────

function m2_skp_page(PDO $pdo): void
{
    require_permission('manage_skp');
    $pid     = current_property_id();
    $status  = (string) getv('status', '');
    $giliran = getv('giliran') === '1';
    $module  = (string) getv('module', '');
    if (!in_array($module, ['cl', 'media', 'gudang'], true)) $module = '';
    $antrean = [];
    try { $antrean = ApprovalLine::antreanSaya($pdo, $pid); } catch (Throwable $e) {}

    $where = ['s.property_id = ?', 's.deleted_at IS NULL']; $params = [$pid];
    if ($giliran) {
        $where[] = $antrean ? 's.id IN (' . implode(',', array_map('intval', $antrean)) . ')' : '1 = 0';
    } elseif (in_array($status, ['draft', 'submitted', 'approved', 'signed', 'rejected'], true)) {
        $where[] = 's.status = ?'; $params[] = $status;
    }
    if ($module) {
        $where[] = '(s.module = ? OR (s.module IS NULL AND (COALESCE(t.module, o.module) = ?
                     OR EXISTS (SELECT 1 FROM offer_items oi WHERE oi.offer_id = s.offer_id AND oi.segment = ?))))';
        array_push($params, $module, $module, $module);
    }
    [$ssc, $ssp] = current_sales_scope_sql($pdo, $pid, ['s.pic_name', 'o.pic_name', 't.pic_name'], 's.created_by');
    if ($ssc !== '') { $where[] = substr($ssc, 5); $params = array_merge($params, $ssp); }

    $st = $pdo->prepare(
        'SELECT s.*, COALESCE(s.master_code, t.master_code, o.master_code) master_code,
                (SELECT COUNT(*) FROM offer_items oi1 WHERE oi1.offer_id = s.offer_id) paket_n,
                COALESCE(s.start_date, t.start_date, o.start_date) start_date, COALESCE(s.end_date, t.end_date, o.end_date) end_date,
                COALESCE(s.module, t.module, o.module) module,
                COALESCE(sc.brand_name, tc.brand_name, oc.brand_name) brand_name,
                COALESCE(sc.company_name, tc.company_name, oc.company_name) company_name
           FROM skp_documents s
           LEFT JOIN transactions t ON t.id = s.transaction_id LEFT JOIN master_clients tc ON tc.id = t.client_id
           LEFT JOIN offers o ON o.id = s.offer_id LEFT JOIN master_clients oc ON oc.id = o.client_id
           LEFT JOIN master_clients sc ON sc.id = s.client_id
          WHERE ' . implode(' AND ', $where) . ' ORDER BY s.id DESC LIMIT 80');
    $st->execute($params);
    $rows = $st->fetchAll();

    $alur = [];
    foreach ($rows as $i => $r) {
        $dt = (string) ($r['doc_type'] ?? 'skp');
        if (!array_key_exists($dt, $alur)) $alur[$dt] = ApprovalLine::steps($pdo, $pid, $dt);
        $rows[$i]['_ket'] = ApprovalLine::keterangan($r, $alur[$dt]);
    }

    layout('SKP / SKS', function () use ($rows, $status, $giliran, $module, $antrean) {
        $mq = $module ? '&module=' . $module : '';
        $chip = [];
        if (can('approve_skp')) $chip[] = ['?r=m2_skp&giliran=1' . $mq, 'Giliran saya', $giliran, count($antrean)];
        $chip[] = ['?r=m2_skp' . $mq, 'Semua', !$giliran && $status === '', null];
        foreach (['draft' => 'Draft', 'submitted' => 'Menunggu', 'approved' => 'Disetujui', 'signed' => 'Bertanda tangan', 'rejected' => 'Dikembalikan'] as $k => $l)
            $chip[] = ['?r=m2_skp&status=' . $k . $mq, $l, !$giliran && $status === $k, null];
        _m2_head($giliran ? 'Persetujuan' : 'Dokumen', current_property()['name'] ?? '', $giliran ? [] : _m2_seg_dokumen('s'), '<div style="margin-top:12px">' . _m2_chips($chip) . '</div>');
        $badge = ['draft' => ['Draft', '#475569', '#F1F5F9'], 'submitted' => ['Menunggu persetujuan', '#92400E', '#FEF3C7'], 'approved' => ['Disetujui · perlu TTD', '#166534', '#DCFCE7'],
                  'signed' => ['Bertanda tangan', '#075985', '#E0F2FE'], 'rejected' => ['Dikembalikan', '#991B1B', '#FEE2E2']];
        $mods = ['' => 'Semua', 'cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
        $dasar = '?r=m2_skp' . ($giliran ? '&giliran=1' : ($status ? '&status=' . $status : ''));
        ?>
        <main class="m2-wrap">
            <div class="m2-chipline">
                <?php foreach ($mods as $k => $l): ?><a href="<?= h($dasar . ($k ? '&module=' . $k : '')) ?>" class="<?= $module === $k ? 'on' : '' ?>"><?= h($l) ?></a><?php endforeach; ?>
            </div>
            <?php if (!$rows): ?><div class="m2-card m2-empty"><?= $giliran ? 'Tidak ada dokumen yang menunggu Anda.' : 'Belum ada dokumen di saringan ini.' ?></div><?php endif; ?>
            <?php foreach ($rows as $s):
                $b = $badge[$s['status']] ?? $badge['draft'];
                [$ml, $mc, $mbg] = _m2_modul((string) $s['module']);
                $kode = (int) $s['paket_n'] > 1 ? 'Paket ' . (int) $s['paket_n'] . ' unit' : (string) $s['master_code'];
                $edit = in_array($s['status'], ['draft', 'rejected'], true);
                $nama = $s['brand_name'] ?: $s['company_name'] ?: '—'; ?>
            <article class="m2-o">
                <a class="mn" href="?r=skp_form&id=<?= (int) $s['id'] ?>">
                    <div class="h"><b><?= h($s['skp_no'] ?: strtoupper((string) ($s['doc_type'] ?? 'skp')) . ' (draft)') ?></b><span class="m2-st" style="color:<?= $b[1] ?>;background:<?= $b[2] ?>"><?= $b[0] ?></span></div>
                    <div class="nm"><?= h($nama) ?></div>
                    <div class="meta"><span class="m2-mod" style="color:<?= $mc ?>;background:<?= $mbg ?>"><?= h($ml) ?></span>
                        <span><?= h($kode) ?> · <?= h(_m2_tgl_pendek_aman($s['start_date'])) ?> – <?= h(_m2_tgl_pendek_aman($s['end_date'])) ?></span></div>
                    <?php if ($s['status'] === 'submitted' && !empty($s['_ket'])): ?><div class="ket"><?= h($s['_ket']) ?></div><?php endif; ?>
                    <?php if ($s['status'] === 'rejected' && trim((string) $s['reject_note']) !== ''): ?><div class="ket" style="color:#991B1B"><?= h($s['reject_note']) ?></div><?php endif; ?>
                </a>
                <div class="acts">
                    <a href="?r=skp_form&id=<?= (int) $s['id'] ?>" class="<?= ($giliran || $edit) ? 'p' : '' ?>"><?= $giliran ? 'Periksa' : ($edit ? 'Ubah' : 'Lihat') ?></a>
                    <?php if (!empty($s['skp_no'])): ?>
                    <a href="?r=skp_print&id=<?= (int) $s['id'] ?>" target="_blank" rel="noopener">PDF</a>
                    <a href="#" onclick="claraSharePdf('?r=skp_print&id=<?= (int) $s['id'] ?>','<?= h($s['skp_no']) ?>',this);return false">Bagikan</a>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </main>
        <?php
    }, ['m2' => true]);
}

// ─── Pipeline ─────────────────────────────────────────────────────────────────

function m2_pipeline_page(PDO $pdo): void
{
    require_permission('view_transactions');
    $pid = current_property_id();
    $tab = (string) getv('tab', 'prospek');
    if (!in_array($tab, ['prospek', 'penawaran', 'deal'], true)) $tab = 'prospek';
    $bulan = date('Y-m');

    [$sp, $spp] = current_sales_scope_sql($pdo, $pid, 'pic_name', 'created_by');
    $ps = $pdo->prepare("SELECT * FROM prospects WHERE property_id = ? AND deleted_at IS NULL AND status = 'open' $sp ORDER BY start_date, id");
    $ps->execute(array_merge([$pid], $spp));
    $prospek = $ps->fetchAll();

    [$so, $sop] = current_sales_scope_sql($pdo, $pid, 'o.pic_name', 'o.created_by');
    $os = $pdo->prepare("SELECT o.*, c.company_name, c.brand_name FROM offers o LEFT JOIN master_clients c ON c.id = o.client_id
                          WHERE o.property_id = ? AND o.deleted_at IS NULL AND o.status IN ('draft','sent','nego') $so ORDER BY o.id DESC LIMIT 60");
    $os->execute(array_merge([$pid], $sop));
    $penawaran = $os->fetchAll();

    $ds = $pdo->prepare("SELECT o.*, c.company_name, c.brand_name FROM offers o LEFT JOIN master_clients c ON c.id = o.client_id
                          WHERE o.property_id = ? AND o.deleted_at IS NULL AND o.status = 'deal' AND o.deal_at >= ? $so ORDER BY o.deal_at DESC LIMIT 60");
    $ds->execute(array_merge([$pid, $bulan . '-01 00:00:00'], $sop));
    $deal = $ds->fetchAll();

    $nilaiPros = array_sum(array_map(fn($p) => (float) $p['est_amount'], $prospek));
    $nilaiPen = 0.0;
    foreach ($penawaran as $o) $nilaiPen += (float) (!empty($o['override_amount']) ? $o['override_amount'] : ($o['total_calculated'] ?? 0));

    layout('Pipeline', function () use ($prospek, $penawaran, $deal, $tab, $nilaiPros, $nilaiPen) {
        $ringkas = '<div class="m2-pipe">
            <div><small>Prospek</small><b>' . count($prospek) . '</b><span>' . h(m2_rp($nilaiPros)) . '</span></div>
            <div><small>Penawaran</small><b>' . count($penawaran) . '</b><span>' . h(m2_rp($nilaiPen)) . '</span></div>
            <div><small>Deal bulan ini</small><b>' . count($deal) . '</b><span>&nbsp;</span></div></div>';
        $chip = '<div style="margin-top:12px">' . _m2_chips([
            ['?r=m2_pipeline&tab=prospek', 'Prospek', $tab === 'prospek', count($prospek)],
            ['?r=m2_pipeline&tab=penawaran', 'Penawaran', $tab === 'penawaran', count($penawaran)],
            ['?r=m2_pipeline&tab=deal', 'Deal bulan ini', $tab === 'deal', count($deal)],
        ]) . '</div>';
        _m2_head('Pipeline', current_property()['name'] ?? '', [], $ringkas . $chip);
        $yakin = ['tinggi' => ['Tinggi', '#15803D', '#DCFCE7'], 'sedang' => ['Sedang', '#B45309', '#FEF3C7'], 'rendah' => ['Rendah', '#475569', '#F1F5F9']];
        $badge = ['draft' => ['Draft', '#475569', '#F1F5F9'], 'sent' => ['Terkirim', '#075985', '#E0F2FE'], 'nego' => ['Negosiasi', '#92400E', '#FEF3C7'], 'deal' => ['Deal', '#166534', '#DCFCE7']];
        ?>
        <main class="m2-wrap">
        <?php if ($tab === 'prospek'): ?>
            <?php if (can('manage_prospects')): ?><a class="m2-primary" href="?r=prospect_form">Catat prospek baru</a><?php endif; ?>
            <?php if (!$prospek): ?><div class="m2-card m2-empty">Belum ada prospek terbuka.<br>Catat calon klien yang masih dibicarakan supaya ikut terhitung di kalender.</div><?php endif; ?>
            <?php foreach ($prospek as $p): $y = $yakin[$p['confidence']] ?? $yakin['sedang']; ?>
            <article class="m2-o">
                <a class="mn" href="?r=prospect_form&id=<?= (int) $p['id'] ?>">
                    <div class="h"><b><?= h($p['client_name']) ?></b><span class="m2-st" style="color:<?= $y[1] ?>;background:<?= $y[2] ?>">Yakin <?= h($y[0]) ?></span></div>
                    <div class="meta"><span><?= h(trim((string) $p['master_code']) ?: 'Unit belum dipilih') ?> · <?= h(_m2_tgl_pendek_aman($p['start_date'])) ?> – <?= h(_m2_tgl_pendek_aman($p['end_date'])) ?></span><b><?= m2_rp((float) $p['est_amount']) ?></b></div>
                    <?php if (trim((string) $p['note']) !== ''): ?><div class="ket"><?= h($p['note']) ?></div><?php endif; ?>
                </a>
                <?php if (can('manage_offers')): ?><div class="acts"><a href="?r=prospect_form&id=<?= (int) $p['id'] ?>">Ubah</a>
                    <a class="p" href="?r=offer_form&module=<?= h($p['module'] ?: 'cl') ?>&master_code=<?= urlencode((string) $p['master_code']) ?>&start_date=<?= h($p['start_date']) ?>&end_date=<?= h($p['end_date']) ?>&from_prospect=<?= (int) $p['id'] ?>">Jadikan penawaran</a></div><?php endif; ?>
            </article>
            <?php endforeach; ?>
        <?php else:
            $daftar = $tab === 'deal' ? $deal : $penawaran;
            if (!$daftar): ?><div class="m2-card m2-empty"><?= $tab === 'deal' ? 'Belum ada deal bulan ini.' : 'Tidak ada penawaran yang sedang berjalan.' ?></div><?php endif;
            foreach ($daftar as $o): $b = $badge[$o['status']] ?? $badge['draft'];
                $amt = !empty($o['override_amount']) ? $o['override_amount'] : ($o['total_calculated'] ?? 0); ?>
            <article class="m2-o">
                <a class="mn" href="?r=offer_view&id=<?= (int) $o['id'] ?>">
                    <div class="h"><b><?= h($o['brand_name'] ?: $o['company_name'] ?: '—') ?></b><span class="m2-st" style="color:<?= $b[1] ?>;background:<?= $b[2] ?>"><?= $b[0] ?></span></div>
                    <div class="meta"><span><?= h($o['offer_no'] ?: '(draft)') ?> · <?= h(_m2_tgl_pendek_aman($o['start_date'])) ?> – <?= h(_m2_tgl_pendek_aman($o['end_date'])) ?></span><b><?= m2_rp((float) $amt) ?></b></div>
                </a>
            </article>
            <?php endforeach; endif; ?>
        </main>
        <?php
    }, ['m2' => true]);
}

// ─── Klien ────────────────────────────────────────────────────────────────────

function m2_klien_page(PDO $pdo): void
{
    $pid = current_property_id();
    $q = trim((string) getv('q', ''));
    [$sc, $sp] = current_sales_scope_sql($pdo, $pid, 't.pic_name', 't.created_by');

    $sql = "SELECT c.id, c.company_name, c.brand_name, x.akhir, x.n, x.lewat, x.sisa
              FROM master_clients c
              JOIN (SELECT client_id, MAX(end_date) akhir, COUNT(*) n, SUM(bayar_lewat) lewat, SUM(GREATEST(tagihan - terbayar, 0)) sisa
                      FROM (SELECT t.client_id, t.end_date, " . pembayaran_select_sql('t') . "
                              FROM transactions t WHERE t.property_id = ? AND t.deleted_at IS NULL AND t.client_id IS NOT NULL $sc) z
                     GROUP BY client_id) x ON x.client_id = c.id";
    $params = array_merge([$pid], $sp);
    if ($q !== '') { $sql .= ' WHERE (c.company_name LIKE ? OR c.brand_name LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
    $sql .= ' ORDER BY (x.akhir >= CURDATE()) DESC, x.akhir DESC LIMIT 60';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    layout('Klien', function () use ($rows, $q) {
        $cari = '<form class="m2-search" method="get"><input type="hidden" name="r" value="m2_klien"><label>' . m2_ic('cari')
            . '<input type="search" name="q" value="' . h($q) . '" placeholder="Cari klien atau merek" aria-label="Cari klien"></label>'
            . (can('manage_clients') ? '<a class="m2-btn-o" href="?r=client_form" style="text-decoration:none">+ Klien</a>' : '') . '</form>';
        _m2_head('Klien', current_property()['name'] ?? '', [], $cari);
        ?>
        <main class="m2-wrap">
            <?php if (!$rows): ?><div class="m2-card m2-empty"><?= $q !== '' ? 'Tidak ada klien yang cocok dengan “' . h($q) . '”.' : 'Belum ada klien dengan kontrak di properti ini.' ?></div><?php endif; ?>
            <?php foreach ($rows as $c):
                $aktif = $c['akhir'] >= date('Y-m-d'); ?>
            <a class="m2-u" href="?r=client_profile&id=<?= (int) $c['id'] ?>">
                <div class="h"><div><b><?= h($c['brand_name'] ?: $c['company_name']) ?></b><small><?= h($c['brand_name'] ? $c['company_name'] : '') ?></small></div>
                    <span class="m2-st <?= $aktif ? 'hj' : 'ab' ?>"><?= $aktif ? 'Aktif' : 'Berakhir' ?></span></div>
                <div class="m2-strip-l" style="margin-top:8px"><span><?= (int) $c['n'] ?> kontrak · sampai <?= h(m2_tgl_pendek((string) $c['akhir'])) ?></span>
                    <?php if ((int) $c['lewat'] > 0): ?><b style="color:#B91C1C">Lewat tempo <?= m2_rp((float) $c['sisa']) ?></b><?php elseif ((float) $c['sisa'] > 0.5): ?><span>Sisa <?= m2_rp((float) $c['sisa']) ?></span><?php endif; ?></div>
            </a>
            <?php endforeach; ?>
        </main>
        <?php
    }, ['m2' => true]);
}

/** Detail alokasi transaksi di HP: unit & luasan, nilai, pembayaran, alokasi per bulan. */
function m2_alokasi_page(PDO $pdo): void
{
    $id  = (int) getv('id');
    $pid = current_property_id();
    [$sc, $sp] = current_sales_scope_sql($pdo, $pid, 't.pic_name', 't.created_by');
    $st = $pdo->prepare("SELECT t.*, c.company_name, c.brand_name, cc.name cp_name, cc.phone cp_phone, " . pembayaran_select_sql('t') . "
                           FROM transactions t
                           LEFT JOIN master_clients c ON c.id = t.client_id
                           LEFT JOIN master_client_contacts cc ON cc.id = t.contact_id
                          WHERE t.id = ? AND t.property_id = ? $sc");
    $st->execute(array_merge([$id, $pid], $sp));
    $t = $st->fetch();
    if (!$t) { flash('Transaksi tidak ditemukan.'); redirect_to('m2_klien'); }

    // Lokasi unit dari master sesuai modul; transaksi sendiri menyimpan luas & jumlahnya.
    $lokasi = '';
    $luasMaster = 0.0;
    try {
        if ($t['module'] === 'cl') {
            $q = $pdo->prepare('SELECT floor, location_name, unit_type, area_sqm FROM master_cl_units WHERE property_id = ? AND code = ?');
            $q->execute([$pid, $t['master_code']]);
            if ($u = $q->fetch()) { $lokasi = trim(($u['floor'] ? $u['floor'] . ' · ' : '') . ($u['location_name'] ?: $u['unit_type'])); $luasMaster = (float) $u['area_sqm']; }
        } elseif ($t['module'] === 'gudang') {
            $q = $pdo->prepare('SELECT location, name, area_sqm FROM master_gudang WHERE property_id = ? AND code = ?');
            $q->execute([$pid, $t['master_code']]);
            if ($u = $q->fetch()) { $lokasi = trim(($u['location'] ?: '') . ($u['name'] ? ' · ' . $u['name'] : ''), ' ·'); $luasMaster = (float) $u['area_sqm']; }
        } elseif ($t['module'] === 'media') {
            $q = $pdo->prepare('SELECT media_type, location, point, size FROM master_media WHERE property_id = ? AND code = ?');
            $q->execute([$pid, $t['master_code']]);
            if ($u = $q->fetch()) $lokasi = trim(implode(' · ', array_filter([$u['media_type'], $u['location'], $u['point'], $u['size']])));
        }
    } catch (Throwable $e) {}

    $al = $pdo->prepare('SELECT period_key, pic_name, allocated_days, capacity_days, amount, allocation_start, allocation_end
                           FROM transaction_allocations WHERE transaction_id = ? AND property_id = ?
                          ORDER BY allocation_start, period_key, id');
    $al->execute([$id, $pid]);
    $perBulan = [];
    foreach ($al->fetchAll() as $a) {
        $b = &$perBulan[$a['period_key']];
        $b['jumlah'] = ($b['jumlah'] ?? 0) + (float) $a['amount'];
        $b['hari']   = ($b['hari'] ?? 0) + (float) $a['allocated_days'];
        $b['baris'][] = $a;
        unset($b);
    }
    $perBulan = array_map(function ($b) {
        $b['bagi'] = count(array_unique(array_column($b['baris'], 'pic_name'))) > 1;
        return $b;
    }, $perBulan);

    $nama   = $t['brand_name'] ?: ($t['company_name'] ?: 'Transaksi #' . $id);
    $nilai  = (float) ($t['final_amount'] ?: $t['total_calculated']);
    $fmt    = fn($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    $tipe   = ['monthly' => 'Bulanan', 'monthly_area' => 'Bulanan per m²', 'daily_area' => 'Harian per m²', 'daily_point' => 'Harian per titik', 'daily_slot' => 'Harian per slot', 'fixed' => 'Paket tetap'];
    $bayarL = ['lunas' => ['Lunas', 'hj'], 'sebagian' => ['Sebagian', ''], 'belum_bayar' => ['Belum dibayar', 'ab'], 'belum_invoice' => ['Belum ada invoice', ''], 'tanpa_tagihan' => ['Tanpa tagihan', '']];
    [$mdl, $mdlC, $mdlB] = _m2_modul((string) $t['module']);
    $dari = (string) getv('dari', '');
    $back = $dari !== '' ? $dari : ($t['client_id'] ? '?r=client_profile&id=' . (int) $t['client_id'] : '?r=m2_klien');

    layout('Alokasi #' . $id, function () use ($t, $id, $nama, $nilai, $fmt, $tipe, $bayarL, $mdl, $mdlC, $mdlB, $lokasi, $luasMaster, $perBulan, $back) {
        m2_top((string) $nama, $back, 'Alokasi #' . $id . ' · ' . (current_property()['name'] ?? ''));
        $qty = (float) $t['quantity'];
        // Luas unit = master (tetap). Luas sewa = yang tersimpan di transaksi dan dipakai
        // menghitung harga; bisa beda dari unit (sewa sebagian / lebih luas), jadi tidak ditimpa.
        $luasSewa = (float) $t['area_sqm'];
        $luasUnit = $luasMaster;
        $by = $bayarL[$t['bayar_status']] ?? ['—', ''];
        ?>
        <main class="m2-wrap" style="padding-bottom:24px">
            <section class="m2-card">
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start">
                    <div><b style="font-size:16px"><?= h($t['master_code']) ?></b><?php if ($lokasi !== ''): ?><br><small style="color:var(--m2-mute)"><?= h($lokasi) ?></small><?php endif; ?></div>
                    <span class="m2-st" style="background:<?= $mdlB ?>;color:<?= $mdlC ?>"><?= h($mdl) ?></span>
                </div>
                <div class="m2-stats" style="margin-top:10px">
                    <div><small>Luas unit</small><b><?= $luasUnit > 0 ? h($fmt($luasUnit)) . ' m²' : '—' ?></b></div>
                    <div><small>Luas sewa</small><b><?= $luasSewa > 0 ? h($fmt($luasSewa)) . ' m²' : '—' ?></b></div>
                    <div><small>Jumlah</small><b><?= $qty > 0 ? h($fmt($qty)) : '—' ?><?= (float) $t['slots'] > 1 ? ' · ' . h($fmt($t['slots'])) . ' slot' : '' ?></b></div>
                </div>
                <div class="m2-prior"><span>Periode</span><span><?= h(m2_tgl_pendek((string) $t['start_date']) . ' ' . substr((string) $t['start_date'], 0, 4) . ' – ' . m2_tgl_pendek((string) $t['end_date']) . ' ' . substr((string) $t['end_date'], 0, 4)) ?><?= (int) $t['contract_months'] > 0 ? ' · ' . (int) $t['contract_months'] . ' bln' : '' ?></span></div>
                <div class="m2-prior"><span>Skema harga</span><span><?= h($tipe[$t['pricing_type']] ?? $t['pricing_type']) ?><?= (float) $t['unit_rate'] > 0 ? ' · ' . m2_rp((float) $t['unit_rate']) : '' ?></span></div>
                <div class="m2-prior"><span>Pengakuan</span><span><?= $t['billing_method'] === 'spread' ? 'Dibagi per bulan' : 'Sekaligus' ?></span></div>
                <div class="m2-prior"><span>PIC</span><span><?= h($t['pic_name'] ?: '—') ?></span></div>
                <?php if (!empty($t['cp_name'])): ?>
                <div class="m2-prior"><span>Kontak</span><span><?= h($t['cp_name']) ?><?php if ($t['cp_phone']): ?> · <a href="tel:<?= h($t['cp_phone']) ?>"><?= h($t['cp_phone']) ?></a><?php endif; ?></span></div>
                <?php endif; ?>
            </section>

            <section class="m2-card">
                <h2>Nilai &amp; pembayaran</h2>
                <div class="m2-prior"><span>Total hitung</span><span><?= m2_rp((float) $t['total_calculated']) ?></span></div>
                <div class="m2-prior"><span>Nilai final</span><span><b style="color:var(--m2-ink,#0F1623)"><?= m2_rp($nilai) ?></b></span></div>
                <?php if ($t['invoice_no']): ?><div class="m2-prior"><span>No. invoice</span><span><?= h($t['invoice_no']) ?></span></div><?php endif; ?>
                <div class="m2-prior"><span>Terbayar</span><span><?= m2_rp((float) $t['terbayar']) ?> dari <?= m2_rp((float) $t['tagihan']) ?></span></div>
                <div class="m2-prior"><span>Status</span><span class="m2-st <?= $by[1] ?>"><?= h($by[0]) ?><?= (int) $t['bayar_lewat'] ? ' · lewat tempo' : '' ?></span></div>
            </section>

            <section class="m2-card">
                <h2>Alokasi per bulan</h2>
                <?php if (!$perBulan): ?><div class="m2-empty" style="padding:12px 0 4px">Belum ada alokasi untuk transaksi ini.</div>
                <?php else: foreach ($perBulan as $pk => $b): ?>
                <div class="m2-prior"><span><b><?= h(pembayaran_nama_bulan((string) $pk)) ?></b><br><small><?= h($fmt($b['hari'])) ?> hari</small></span><span><b style="color:var(--m2-ink,#0F1623)"><?= m2_rp($b['jumlah']) ?></b></span></div>
                <?php if ($b['bagi']): foreach ($b['baris'] as $r): ?>
                <div class="m2-prior" style="padding-left:12px;font-size:12.5px"><span><?= h($r['pic_name'] ?: '—') ?></span><span><?= m2_rp((float) $r['amount']) ?></span></div>
                <?php endforeach; endif; endforeach; ?>
                <div class="m2-prior"><span><b>Total</b></span><span><b style="color:var(--m2-ink,#0F1623)"><?= m2_rp(array_sum(array_column($perBulan, 'jumlah'))) ?></b></span></div>
                <?php endif; ?>
            </section>

            <section class="m2-card">
                <h2>Tindakan</h2>
                <?php if (($t['module'] ?? '') === 'cl' && can('manage_skp')): ?>
                <a class="m2-row" href="?r=skp_form&transaction_id=<?= $id ?>"><span class="ri"><?= m2_ic('surat') ?></span><span class="t">Buat / lihat SKP</span><?= m2_ic('kanan') ?></a>
                <?php endif; ?>
                <?php if (can('manage_transactions')): ?>
                <a class="m2-row" href="?r=transaction_edit&id=<?= $id ?>"><span class="ri"><?= m2_ic('surat') ?></span><span class="t">Edit transaksi</span><?= m2_ic('kanan') ?></a>
                <?php endif; ?>
                <a class="m2-row" href="?r=transaction_history&id=<?= $id ?>"><span class="ri"><?= m2_ic('surat') ?></span><span class="t">Riwayat perubahan</span><?= m2_ic('kanan') ?></a>
                <a class="m2-row" href="?r=allocation_detail&id=<?= $id ?>&full=1"><span class="ri"><?= m2_ic('surat') ?></span><span class="t">Pengaturan lengkap (harga bertahap, bagi PIC)</span><?= m2_ic('kanan') ?></a>
            </section>
        </main>
        <?php
    }, ['m2' => true]);
}
