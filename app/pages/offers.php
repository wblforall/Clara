<?php
// ─── Surat Penawaran (Quotation) ─────────────────────────────────────────────
// Titik masuk baru CLARA (offer-first). 1 penawaran = 1 unit. Bisa direvisi N
// kali; tiap revisi disimpan → jumlah nego. Saat DEAL → dasar Dokumen Konfirmasi
// (SKP/SKS). Lihat [[project-offer-pipeline]].

function _offer_prop_code(string $key): string
{
    return match ($key) { 'ewalk' => 'e-Walk', 'pentacity' => 'Pentacity', default => ucfirst($key) };
}
function _offer_roman(int $m): string
{
    return ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$m] ?? (string) $m;
}
function _offer_module_label(string $m): string
{
    return ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'][$m] ?? strtoupper($m);
}

/** Kategori alasan penawaran ditutup tanpa deal (untuk analisa). */
function offer_lost_categories(): array
{
    return [
        'harga'          => 'Harga terlalu tinggi',
        'kompetitor'     => 'Pilih kompetitor / mall lain',
        'budget'         => 'Budget / keputusan internal client batal',
        'tidak_respon'   => 'Client tidak merespon / hilang kontak',
        'jadwal'         => 'Periode / jadwal tidak cocok',
        'lokasi'         => 'Lokasi / unit tidak sesuai',
        'fiktif'         => 'Tidak valid / dibatalkan internal',
        'lainnya'        => 'Lainnya',
    ];
}
function offer_lost_label(?string $k): string
{
    return offer_lost_categories()[$k] ?? '—';
}

/**
 * Skor risiko "fiktif" sebuah penawaran (0–100) + daftar sinyal.
 * Heuristik aktivitas: penawaran asli umumnya benar-benar DIKIRIM ke client,
 * punya effort (revisi/nego), data kontak lengkap, & tidak ditutup instan.
 * $o = baris offers. $dupCount = jumlah penawaran lain identik oleh PIC sama.
 * $clientHasPhone = apakah client punya nomor telepon.
 */
function offer_fiktif_assess(array $o, int $dupCount = 0, bool $clientHasPhone = true): array
{
    $score = 0; $flags = [];
    $status   = $o['status'] ?? 'draft';
    $closed   = $status === 'cancelled';
    $everSent = !empty($o['sent_at']) || !empty($o['nego_at']) || in_array($status, ['deal'], true);
    $revs     = (int) ($o['revision_count'] ?? 0);

    // 1) Tidak pernah benar-benar dikirim ke client, tapi sudah closed/deal.
    if (!$everSent && in_array($status, ['cancelled', 'deal'], true)) {
        $score += 30; $flags[] = 'Tidak pernah ditandai terkirim ke client';
    }
    // 2) Ditutup tanpa effort sama sekali (0 revisi).
    if ($closed && $revs === 0) {
        $score += 15; $flags[] = 'Ditutup tanpa revisi/nego';
    }
    // 3) Ditutup sangat cepat setelah dibuat (< 1 jam).
    if ($closed && !empty($o['created_at']) && !empty($o['cancelled_at'])) {
        $secs = strtotime($o['cancelled_at']) - strtotime($o['created_at']);
        if ($secs >= 0 && $secs < 3600) { $score += 20; $flags[] = 'Ditutup < 1 jam sejak dibuat'; }
    }
    // 4) Kategori tutup = fiktif/internal (diakui sendiri).
    if ($closed && ($o['lost_category'] ?? '') === 'fiktif') {
        $score += 25; $flags[] = 'Ditandai tidak valid / dibatalkan internal';
    }
    // 5) Tanpa contact person.
    if (empty($o['contact_id'])) { $score += 10; $flags[] = 'Tanpa contact person'; }
    // 6) Client tanpa nomor telepon.
    if (!$clientHasPhone) { $score += 10; $flags[] = 'Client tanpa nomor telepon'; }
    // 7) Duplikat (client+unit+nilai sama oleh PIC sama).
    if ($dupCount > 0) { $score += 20; $flags[] = 'Duplikat penawaran identik (' . $dupCount . '×)'; }

    $score = min(100, $score);
    $level = $score >= 50 ? 'tinggi' : ($score >= 25 ? 'sedang' : 'rendah');
    return ['score' => $score, 'level' => $level, 'flags' => $flags];
}

/** Field ekonomi yang di-snapshot tiap revisi. */
function _offer_fields(): array
{
    return ['module', 'client_id', 'contact_id', 'pic_name', 'master_code', 'keterangan',
            'pricing_type', 'unit_rate', 'area_sqm', 'quantity', 'slots',
            'start_date', 'end_date', 'contract_months', 'monthly_amount', 'total_calculated',
            'dp_months', 'dp_amount', 'deposit_months', 'deposit_amount', 'perihal', 'offer_date'];
}

// ─── Daftar ──────────────────────────────────────────────────────────────────
function offers_list_page(PDO $pdo): void
{
    require_permission('manage_offers');
    $pid = current_property_id();
    $status = getv('status', '');
    $where = ['o.property_id = ?']; $params = [$pid];
    if (in_array($status, ['draft', 'sent', 'nego', 'deal', 'cancelled'], true)) { $where[] = 'o.status = ?'; $params[] = $status; }
    $stmt = $pdo->prepare(
        'SELECT o.*, c.company_name FROM offers o LEFT JOIN master_clients c ON c.id = o.client_id
         WHERE ' . implode(' AND ', $where) . ' ORDER BY o.id DESC'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    layout('Surat Penawaran', function () use ($rows, $status) {
        $badge = [
            'draft'     => ['Draft', '#64748b', '#f1f5f9'],
            'sent'      => ['Terkirim', '#0369a1', '#e0f2fe'],
            'nego'      => ['Negosiasi', '#92400e', '#fef3c7'],
            'deal'      => ['DEAL', '#166534', '#dcfce7'],
            'cancelled' => ['Tidak Deal', '#991b1b', '#fee2e2'],
        ];
        ?>
        <div class="toolbar" style="gap:8px;flex-wrap:wrap">
            <a class="btn" href="?r=offer_form">+ Buat Penawaran</a>
            <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap">
                <?php foreach (['' => 'Semua', 'draft' => 'Draft', 'sent' => 'Terkirim', 'nego' => 'Nego', 'deal' => 'Deal', 'cancelled' => 'Tidak Deal'] as $k => $lbl): ?>
                    <a class="btn light" style="<?= $status === $k ? 'background:var(--primary,#0d9488);color:#fff' : '' ?>" href="?r=offers<?= $k ? '&status=' . $k : '' ?>"><?= $lbl ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="panel" style="margin-top:12px">
            <div class="table-wrap">
                <table style="font-size:12.5px">
                    <thead><tr><th>No. Penawaran</th><th>Modul</th><th>Client</th><th>Periode</th><th>Harga/bln</th><th>Revisi</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?><tr><td colspan="8" style="text-align:center;color:var(--muted);padding:24px">Belum ada penawaran.</td></tr><?php endif; ?>
                    <?php foreach ($rows as $r): $b = $badge[$r['status']] ?? $badge['draft']; ?>
                        <tr>
                            <td style="white-space:nowrap;font-weight:600"><?= h($r['offer_no'] ?? '—') ?></td>
                            <td><?= h(_offer_module_label($r['module'])) ?></td>
                            <td><?= h($r['company_name'] ?? '-') ?></td>
                            <td style="white-space:nowrap;font-size:11.5px"><?= $r['start_date'] ? h(date('d/m/y', strtotime($r['start_date'])) . '–' . date('d/m/y', strtotime($r['end_date']))) : '—' ?></td>
                            <td style="white-space:nowrap"><?= money($r['monthly_amount']) ?></td>
                            <td style="text-align:center"><?= (int)$r['revision_count'] ?>×</td>
                            <td><span class="badge" style="color:<?= $b[1] ?>;background:<?= $b[2] ?>"><?= $b[0] ?></span><?php if ($r['status'] === 'cancelled' && !empty($r['lost_category'])): ?><div style="font-size:10.5px;color:#991b1b;margin-top:2px"><?= h(offer_lost_label($r['lost_category'])) ?></div><?php endif; ?></td>
                            <td style="white-space:nowrap">
                                <a class="btn light" href="?r=offer_form&id=<?= (int)$r['id'] ?>"><?= in_array($r['status'], ['deal', 'cancelled'], true) ? 'Lihat' : 'Edit' ?></a>
                                <?php if ($r['offer_no']): ?><a class="btn light" href="?r=offer_print&id=<?= (int)$r['id'] ?>" target="_blank">PDF</a><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    });
}

// ─── Form (buat/edit) ────────────────────────────────────────────────────────
function offer_form(PDO $pdo): void
{
    require_permission('manage_offers');
    $pid = current_property_id();
    $id  = (int) getv('id');
    $offer = null;
    if ($id) {
        $st = $pdo->prepare('SELECT * FROM offers WHERE id = ? AND property_id = ?');
        $st->execute([$id, $pid]);
        $offer = $st->fetch();
        if (!$offer) { flash('Penawaran tidak ditemukan.'); redirect_to('offers'); }
    }
    $module  = $offer['module'] ?? getv('module', 'cl');
    if (!in_array($module, ['cl', 'media', 'gudang'], true)) $module = 'cl';
    $editable = !$offer || !in_array($offer['status'], ['deal', 'cancelled'], true);

    $masters  = masterOptions($pdo, $module);
    $clients  = $pdo->query("SELECT id, company_name, brand_name FROM master_clients WHERE status='active' ORDER BY company_name")->fetchAll();
    $contacts = $pdo->query("SELECT id, client_id, name FROM master_client_contacts WHERE status='active' ORDER BY name")->fetchAll();
    $picsStmt = $pdo->prepare("SELECT name FROM master_pic WHERE status='active' AND property_id=? ORDER BY name");
    $picsStmt->execute([$pid]);
    $pics = $picsStmt->fetchAll();
    $linkedPic = null;
    if ($uid = $_SESSION['user']['id'] ?? null) {
        $lp = $pdo->prepare("SELECT name FROM master_pic WHERE user_id=? AND status='active' AND property_id=? LIMIT 1");
        $lp->execute([$uid, $pid]);
        $linkedPic = $lp->fetchColumn() ?: null;
    }
    $v = fn(string $k, $def = '') => h((string) ($offer[$k] ?? $def));

    layout(($offer ? ($editable ? 'Edit' : 'Lihat') : 'Buat') . ' Penawaran', function () use ($pdo, $offer, $id, $module, $editable, $masters, $clients, $contacts, $pics, $linkedPic, $v) {
        $picSel = $offer['pic_name'] ?? $linkedPic;
        $disabled = $editable ? '' : 'disabled';
        ?>
        <div class="toolbar" style="gap:8px"><a class="btn light" href="?r=offers">← Daftar Penawaran</a><?php if ($offer && $offer['offer_no']): ?><a class="btn light" href="?r=offer_print&id=<?= (int)$offer['id'] ?>" target="_blank">🖨 PDF</a><?php endif; ?>
            <?php if ($offer && $offer['status'] === 'deal' && can('manage_skp')): ?>
            <a class="btn" style="background:#0369a1;margin-left:auto" href="?r=skp_form&offer_id=<?= (int)$offer['id'] ?>">→ Buat <?= $offer['module'] === 'cl' ? 'SKP' : 'SKS' ?> (Konfirmasi)</a>
            <?php endif; ?>
        </div>

        <?php if ($offer): ?>
        <div class="panel" style="margin-top:10px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
            <div><strong style="font-size:15px"><?= h($offer['offer_no'] ?? '(no. terbit saat disimpan)') ?></strong> · <span class="badge"><?= h(_offer_module_label($offer['module'])) ?></span> · Revisi/nego: <strong><?= (int)$offer['revision_count'] ?>×</strong></div>
            <?php if ($editable): ?>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                <?php foreach (['sent' => 'Tandai Terkirim', 'nego' => 'Tandai Nego', 'deal' => 'Tandai DEAL'] as $s => $lbl): if ($offer['status'] === $s) continue; ?>
                <form method="post" action="?r=offer_status" style="display:inline" onsubmit="return confirm('Ubah status ke <?= $lbl ?>?')">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$offer['id'] ?>"><input type="hidden" name="status" value="<?= $s ?>">
                    <button class="btn light" style="<?= $s === 'deal' ? 'background:#16a34a;color:#fff' : '' ?>"><?= $lbl ?></button>
                </form>
                <?php endforeach; ?>
                <details style="display:inline-block">
                    <summary class="btn light" style="background:#fee2e2;color:#991b1b;list-style:none;cursor:pointer">Tutup (Tidak Deal)</summary>
                    <form method="post" action="?r=offer_close" style="position:absolute;z-index:20;margin-top:6px;background:#fff;border:1px solid var(--line,#e5e7eb);border-radius:10px;padding:12px;box-shadow:0 6px 24px rgba(0,0,0,.12);width:320px">
                        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$offer['id'] ?>">
                        <label style="font-size:12px;font-weight:700">Alasan tidak deal</label>
                        <select name="lost_category" required style="width:100%;margin:4px 0 8px">
                            <option value="">- Pilih alasan -</option>
                            <?php foreach (offer_lost_categories() as $k => $lbl): ?><option value="<?= h($k) ?>"><?= h($lbl) ?></option><?php endforeach; ?>
                        </select>
                        <label style="font-size:12px;font-weight:700">Catatan (wajib)</label>
                        <textarea name="status_note" required rows="2" placeholder="Jelaskan kronologi singkat kenapa tidak deal…" style="width:100%;margin-top:4px"></textarea>
                        <button class="btn" style="background:#991b1b;width:100%;margin-top:8px" onclick="return confirm('Tutup penawaran ini sebagai TIDAK DEAL? Tidak bisa diubah lagi.')">Tutup Penawaran</button>
                    </form>
                </details>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($offer['status'] === 'cancelled'): ?>
        <div class="panel" style="margin-top:10px;background:#fef2f2;border-color:#fecaca">
            <strong style="color:#991b1b">Ditutup — Tidak Deal</strong>
            · Alasan: <strong><?= h(offer_lost_label($offer['lost_category'] ?? null)) ?></strong>
            <?php if (!empty($offer['cancelled_at'])): ?><span style="color:var(--muted)"> · <?= h(date('d/m/Y H:i', strtotime($offer['cancelled_at']))) ?></span><?php endif; ?>
            <?php if (!empty($offer['status_note'])): ?><div style="margin-top:6px;font-size:13px">“<?= h($offer['status_note']) ?>”</div><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <form class="panel" method="post" action="?r=offer_save" style="margin-top:12px" id="offer-form">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= (int)($offer['id'] ?? 0) ?>">
            <input type="hidden" name="module" value="<?= h($module) ?>">

            <h3 style="margin-top:0">Penerima</h3>
            <div class="form-grid">
                <div>
                    <label>Client / Perusahaan</label>
                    <select name="client_id" id="client_id" required <?= $disabled ?>>
                        <option value="">- Pilih Client -</option>
                        <?php foreach ($clients as $cl): ?>
                            <option value="<?= (int)$cl['id'] ?>" <?= (int)($offer['client_id'] ?? 0) === (int)$cl['id'] ? 'selected' : '' ?>><?= h($cl['company_name']) ?><?= $cl['brand_name'] ? ' (' . h($cl['brand_name']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Up. (Contact Person)</label>
                    <select name="contact_id" id="contact_id" <?= $disabled ?>><option value="">- Pilih -</option></select>
                </div>
                <div>
                    <label>PIC Sales (pembuat)</label>
                    <select name="pic_name" required <?= $disabled ?>>
                        <option value="">- Pilih PIC -</option>
                        <?php foreach ($pics as $p): ?><option <?= ($p['name'] === $picSel) ? 'selected' : '' ?>><?= h($p['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>

            <h3>Objek Sewa</h3>
            <div class="form-grid">
                <div>
                    <label>Unit / Lokasi</label>
                    <select name="master_code" id="master_code" required <?= $disabled ?>>
                        <option value="">- Pilih Unit -</option>
                        <?php foreach ($masters as $m): ?>
                            <option value="<?= h($m['code']) ?>" data-rate="<?= h($m['rate']) ?>" data-area="<?= h($m['area_sqm']) ?>" data-pricing="<?= h($m['pricing_type']) ?>" <?= ($offer['master_code'] ?? '') === $m['code'] ? 'selected' : '' ?>><?= h($m['code'] . ' — ' . $m['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Luas (m²)</label><input type="number" step="0.01" name="area_sqm" id="area_sqm" value="<?= $v('area_sqm') ?>" <?= $disabled ?>></div>
                <div><label>Pricing Type</label>
                    <select name="pricing_type" id="pricing_type" <?= $disabled ?>>
                        <?php foreach (['daily_area', 'daily_slot', 'daily_point', 'monthly', 'fixed'] as $o): ?><option <?= ($offer['pricing_type'] ?? '') === $o ? 'selected' : '' ?>><?= $o ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div><label>Rate (referensi /m/bln)</label><input type="number" step="0.01" name="unit_rate" id="unit_rate" value="<?= $v('unit_rate') ?>" <?= $disabled ?>></div>
                <div class="wide"><label>Keterangan</label><input name="keterangan" value="<?= $v('keterangan') ?>" <?= $disabled ?>></div>
            </div>

            <h3>Periode & Harga</h3>
            <div class="form-grid">
                <div><label>Tanggal Mulai</label><input type="date" name="start_date" id="start_date" value="<?= $v('start_date') ?>" required <?= $disabled ?>></div>
                <div><label>Tanggal Selesai</label><input type="date" name="end_date" id="end_date" value="<?= $v('end_date') ?>" required <?= $disabled ?>></div>
                <div><label>Masa Kontrak (bulan)</label><input type="number" step="1" min="1" name="contract_months" id="contract_months" value="<?= $v('contract_months', '1') ?>" <?= $disabled ?>></div>
                <div><label>Harga / Bulan (nego)</label><input type="number" step="1" name="monthly_amount" id="monthly_amount" value="<?= $v('monthly_amount') ?>" required <?= $disabled ?>></div>
                <div><label>Total Kontrak</label><input type="number" id="total_calc" value="<?= $v('total_calculated') ?>" readonly><input type="hidden" name="total_calculated" id="total_calc_h" value="<?= $v('total_calculated') ?>"></div>
            </div>

            <h3>Pembayaran <span style="font-weight:400;font-size:12px;color:var(--muted)">(DP minimal 2 bulan; deposit adjustable)</span></h3>
            <div class="form-grid">
                <div><label>DP (bulan, min 2)</label><input type="number" step="0.5" min="2" name="dp_months" id="dp_months" value="<?= $v('dp_months', '2') ?>" <?= $disabled ?>></div>
                <div><label>Nominal DP</label><input type="number" step="1" name="dp_amount" id="dp_amount" value="<?= $v('dp_amount') ?>" <?= $disabled ?>></div>
                <div><label>Deposit (bulan)</label><input type="number" step="0.5" min="0" name="deposit_months" id="deposit_months" value="<?= $v('deposit_months', '1') ?>" <?= $disabled ?>></div>
                <div><label>Nominal Deposit</label><input type="number" step="1" name="deposit_amount" id="deposit_amount" value="<?= $v('deposit_amount') ?>" <?= $disabled ?>></div>
            </div>

            <?php if ($offer && $editable): ?>
            <h3>Catatan Revisi / Nego</h3>
            <input name="rev_note" placeholder="mis. turun harga jadi 20jt, tambah 1 bulan gratis…" <?= $disabled ?> style="width:100%">
            <?php endif; ?>

            <?php if ($editable): ?>
            <p style="margin-top:16px"><button type="submit">💾 <?= $offer ? 'Simpan Revisi' : 'Simpan Penawaran' ?></button> <a class="btn secondary" href="?r=offers">Batal</a></p>
            <?php endif; ?>
        </form>

        <script>
        (function () {
            var contacts = <?= json_encode($contacts) ?>;
            var clientSel = document.getElementById('client_id'), contactSel = document.getElementById('contact_id');
            var curContact = <?= (int)($offer['contact_id'] ?? 0) ?>;
            function fillContacts() {
                var cid = parseInt(clientSel.value || '0', 10);
                contactSel.innerHTML = '<option value="">- Pilih -</option>';
                contacts.filter(function (c) { return parseInt(c.client_id, 10) === cid; }).forEach(function (c) {
                    var o = document.createElement('option'); o.value = c.id; o.textContent = c.name;
                    if (parseInt(c.id, 10) === curContact) o.selected = true;
                    contactSel.appendChild(o);
                });
            }
            if (clientSel) { clientSel.addEventListener('change', function () { curContact = 0; fillContacts(); }); fillContacts(); }

            var unit = document.getElementById('master_code');
            if (unit) unit.addEventListener('change', function () {
                var o = this.options[this.selectedIndex];
                if (!o.value) return;
                if (o.dataset.area && !document.getElementById('area_sqm').value) document.getElementById('area_sqm').value = o.dataset.area;
                if (o.dataset.rate && !document.getElementById('unit_rate').value) document.getElementById('unit_rate').value = o.dataset.rate;
                if (o.dataset.pricing) document.getElementById('pricing_type').value = o.dataset.pricing;
            });

            function num(id) { return parseFloat((document.getElementById(id) || {}).value || '0') || 0; }
            function recalc() {
                var monthly = num('monthly_amount'), months = num('contract_months');
                var total = Math.round(monthly * months);
                document.getElementById('total_calc').value = total;
                document.getElementById('total_calc_h').value = total;
                var dp = document.getElementById('dp_amount'), dep = document.getElementById('deposit_amount');
                // auto-isi DP/deposit hanya bila kosong/0 (biar nego manual tidak ketimpa)
                if (dp && (!dp.value || dp.value === '0')) dp.value = Math.round(monthly * num('dp_months'));
                if (dep && (!dep.value || dep.value === '0')) dep.value = Math.round(monthly * num('deposit_months'));
            }
            ['monthly_amount', 'contract_months', 'dp_months', 'deposit_months'].forEach(function (id) {
                var el = document.getElementById(id); if (el) el.addEventListener('input', recalc);
            });
        })();
        </script>
        <?php
    });
}

// ─── Simpan (insert/update + revisi) ─────────────────────────────────────────
function offer_save(PDO $pdo): void
{
    require_permission('manage_offers');
    verify_csrf();
    $pid = current_property_id();
    $id  = (int) post('id');
    $uname = $_SESSION['user']['name'] ?? 'system';

    $data = [
        'module'          => in_array(post('module'), ['cl', 'media', 'gudang'], true) ? post('module') : 'cl',
        'client_id'       => (int) post('client_id') ?: null,
        'contact_id'      => (int) post('contact_id') ?: null,
        'pic_name'        => trim((string) post('pic_name')) ?: null,
        'master_code'     => trim((string) post('master_code')) ?: null,
        'keterangan'      => trim((string) post('keterangan')) ?: null,
        'pricing_type'    => post('pricing_type') ?: null,
        'unit_rate'       => (float) post('unit_rate', 0),
        'area_sqm'        => (float) post('area_sqm', 0),
        'quantity'        => 1,
        'slots'           => 1,
        'start_date'      => post('start_date') ?: null,
        'end_date'        => post('end_date') ?: null,
        'contract_months' => (int) post('contract_months', 1) ?: 1,
        'monthly_amount'  => (float) post('monthly_amount', 0),
        'total_calculated' => (float) post('total_calculated', 0),
        'dp_months'       => max(2, (float) post('dp_months', 2)),
        'dp_amount'       => (float) post('dp_amount', 0),
        'deposit_months'  => (float) post('deposit_months', 1),
        'deposit_amount'  => (float) post('deposit_amount', 0),
        'perihal'         => 'Surat Penawaran Sewa Kontrak ' . ((int) post('contract_months', 1)) . ' bulan',
        'offer_date'      => date('Y-m-d'),
    ];

    if ($id) {
        $cur = $pdo->prepare('SELECT * FROM offers WHERE id=? AND property_id=?');
        $cur->execute([$id, $pid]);
        $offer = $cur->fetch();
        if (!$offer || in_array($offer['status'], ['deal', 'cancelled'], true)) { flash('Penawaran terkunci / tidak ditemukan.'); redirect_to('offers'); }
        $sets = []; $vals = [];
        foreach ($data as $k => $val) { $sets[] = "$k=:$k"; $vals[":$k"] = $val; }
        $vals[':id'] = $id; $vals[':pid'] = $pid; $vals[':uname'] = $uname;
        $newRev = (int) $offer['revision_count'] + 1;
        $vals[':rev'] = $newRev;
        $pdo->prepare('UPDATE offers SET ' . implode(', ', $sets) . ', revision_count=:rev, updated_at=CURRENT_TIMESTAMP, updated_by=:uname WHERE id=:id AND property_id=:pid')->execute($vals);
        // snapshot revisi
        $snap = array_intersect_key(array_merge($offer, $data), array_flip(_offer_fields()));
        $pdo->prepare('INSERT INTO offer_revisions (offer_id, rev_no, snapshot_json, note, created_by) VALUES (?,?,?,?,?)')
            ->execute([$id, $newRev, json_encode($snap, JSON_UNESCAPED_UNICODE), trim((string) post('rev_note')) ?: null, $uname]);
        audit($pdo, 'update', 'offers', (string) $id, $data);
        flash("Revisi #$newRev disimpan.");
        redirect_to('offer_form', ['id' => $id]);
    }

    // INSERT baru + generate No. Penawaran
    $year = (int) date('Y');
    $prop = current_property();
    $pdo->prepare('INSERT INTO offer_counters (property_id, year, last_no) VALUES (?,?,1) ON DUPLICATE KEY UPDATE last_no=last_no+1')->execute([$pid, $year]);
    $seq = (int) $pdo->query("SELECT last_no FROM offer_counters WHERE property_id=$pid AND year=$year")->fetchColumn();
    $offerNo = sprintf('%03d/QT-CL/%s/BSB-BPN/%s/%d', $seq, _offer_prop_code($prop['key'] ?? ''), _offer_roman((int) date('n')), $year);

    $cols = array_keys($data);
    $place = array_map(fn($c) => ':' . $c, $cols);
    $vals = [];
    foreach ($data as $k => $val) $vals[":$k"] = $val;
    $vals[':pid'] = $pid; $vals[':no'] = $offerNo; $vals[':uname'] = $uname;
    $pdo->prepare('INSERT INTO offers (property_id, offer_no, status, created_by, ' . implode(', ', $cols) . ')
                   VALUES (:pid, :no, \'draft\', :uname, ' . implode(', ', $place) . ')')->execute($vals);
    $newId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO offer_revisions (offer_id, rev_no, snapshot_json, note, created_by) VALUES (?,0,?,?,?)')
        ->execute([$newId, json_encode(array_intersect_key($data, array_flip(_offer_fields())), JSON_UNESCAPED_UNICODE), 'Penawaran awal', $uname]);
    audit($pdo, 'create', 'offers', (string) $newId, $data);
    flash("Penawaran dibuat: $offerNo");
    redirect_to('offer_form', ['id' => $newId]);
}

// ─── Ubah status ─────────────────────────────────────────────────────────────
function offer_status(PDO $pdo): void
{
    require_permission('manage_offers');
    verify_csrf();
    $pid = current_property_id();
    $id  = (int) post('id');
    $to  = post('status');
    // 'cancelled' (tutup/tidak deal) ditangani offer_close (wajib alasan).
    if (!in_array($to, ['sent', 'nego', 'deal'], true)) { redirect_to('offer_form', ['id' => $id]); }
    $cur = $pdo->prepare('SELECT status, sent_at, nego_at FROM offers WHERE id=? AND property_id=?');
    $cur->execute([$id, $pid]);
    $row = $cur->fetch();
    if (!$row || in_array($row['status'], ['deal', 'cancelled'], true)) { flash('Status terkunci.'); redirect_to('offer_form', ['id' => $id]); }
    // Stempel engagement (sekali, tidak ditimpa) untuk analisa aktivitas PIC.
    $extra = '';
    if ($to === 'sent' && empty($row['sent_at'])) $extra .= ', sent_at=CURRENT_TIMESTAMP';
    if ($to === 'nego') { if (empty($row['sent_at'])) $extra .= ', sent_at=CURRENT_TIMESTAMP'; if (empty($row['nego_at'])) $extra .= ', nego_at=CURRENT_TIMESTAMP'; }
    if ($to === 'deal') $extra .= ', deal_at=CURRENT_TIMESTAMP';
    $pdo->prepare("UPDATE offers SET status=? $extra WHERE id=? AND property_id=?")->execute([$to, $id, $pid]);
    audit($pdo, 'status_' . $to, 'offers', (string) $id, ['status' => $to]);
    flash('Status penawaran diperbarui: ' . $to);
    redirect_to('offer_form', ['id' => $id]);
}

// ─── Tutup penawaran (tidak deal) — WAJIB alasan, untuk analisa ───────────────
function offer_close(PDO $pdo): void
{
    require_permission('manage_offers');
    verify_csrf();
    $pid = current_property_id();
    $id  = (int) post('id');
    $cat = (string) post('lost_category');
    $note = trim((string) post('status_note'));
    if (!array_key_exists($cat, offer_lost_categories())) { flash('Pilih alasan penawaran ditutup.'); redirect_to('offer_form', ['id' => $id]); }
    if ($note === '') { flash('Catatan alasan wajib diisi agar bisa dianalisa.'); redirect_to('offer_form', ['id' => $id]); }
    $cur = $pdo->prepare('SELECT status FROM offers WHERE id=? AND property_id=?');
    $cur->execute([$id, $pid]);
    $st = $cur->fetchColumn();
    if ($st === false || in_array($st, ['deal', 'cancelled'], true)) { flash('Status terkunci.'); redirect_to('offer_form', ['id' => $id]); }
    $pdo->prepare(
        "UPDATE offers SET status='cancelled', cancelled_at=CURRENT_TIMESTAMP,
                lost_category=?, status_note=?, closed_by=? WHERE id=? AND property_id=?"
    )->execute([$cat, $note, $_SESSION['user']['name'] ?? 'system', $id, $pid]);
    audit($pdo, 'close', 'offers', (string) $id, ['lost_category' => $cat, 'note' => $note]);
    flash('Penawaran ditutup (tidak deal) dengan alasan: ' . offer_lost_label($cat) . '.');
    redirect_to('offer_form', ['id' => $id]);
}

// ─── Cetak / PDF Surat Penawaran ─────────────────────────────────────────────
function offer_print(PDO $pdo): void
{
    require_permission('manage_offers');
    $pid = current_property_id();
    $id  = (int) getv('id');
    $st = $pdo->prepare(
        "SELECT o.*, c.company_name, c.brand_name, c.address,
                ct.name cp_name,
                u.location_name, u.floor,
                p.email pic_email, p.phone pic_phone
         FROM offers o
         LEFT JOIN master_clients c ON c.id = o.client_id
         LEFT JOIN master_client_contacts ct ON ct.id = o.contact_id
         LEFT JOIN master_cl_units u ON u.code = o.master_code AND u.property_id = o.property_id
         LEFT JOIN master_pic p ON p.name = o.pic_name AND p.property_id = o.property_id
         WHERE o.id = ? AND o.property_id = ?"
    );
    $st->execute([$id, $pid]);
    $o = $st->fetch();
    if (!$o || empty($o['offer_no'])) { http_response_code(404); exit('Penawaran tidak ditemukan / nomor belum terbit.'); }
    $prop = current_property();
    $rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $h  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    include __DIR__ . '/offer_print_template.php';
}
