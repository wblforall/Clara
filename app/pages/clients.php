<?php
declare(strict_types=1);

function clients_geo_data(): array
{
    return [
        'Aceh'                       => ['Banda Aceh','Lhokseumawe','Langsa','Sabang','Subulussalam','Aceh Besar','Pidie','Bireuen'],
        'Sumatera Utara'             => ['Medan','Binjai','Pematang Siantar','Tebing Tinggi','Deli Serdang','Asahan','Simalungun','Tapanuli Utara','Karo','Dairi'],
        'Sumatera Barat'             => ['Padang','Bukittinggi','Solok','Payakumbuh','Padang Panjang','Pariaman','Sawahlunto'],
        'Riau'                       => ['Pekanbaru','Dumai','Siak','Bengkalis','Kampar','Rokan Hulu','Rokan Hilir'],
        'Kepulauan Riau'             => ['Batam','Tanjung Pinang','Karimun','Bintan','Natuna'],
        'Jambi'                      => ['Jambi','Sungai Penuh','Muaro Jambi','Bungo','Merangin','Sarolangun'],
        'Sumatera Selatan'           => ['Palembang','Prabumulih','Lubuklinggau','Pagaralam','Banyuasin','Ogan Ilir','Muara Enim'],
        'Kepulauan Bangka Belitung'  => ['Pangkal Pinang','Belitung','Bangka Barat','Bangka Tengah','Bangka Selatan'],
        'Bengkulu'                   => ['Bengkulu','Rejang Lebong','Kepahiang','Muko-Muko'],
        'Lampung'                    => ['Bandar Lampung','Metro','Pringsewu','Tulang Bawang','Pesawaran','Lampung Selatan'],
        'Banten'                     => ['Tangerang','Serang','Cilegon','Tangerang Selatan','Pandeglang','Lebak'],
        'DKI Jakarta'                => ['Jakarta Pusat','Jakarta Utara','Jakarta Barat','Jakarta Selatan','Jakarta Timur','Kepulauan Seribu'],
        'Jawa Barat'                 => ['Bandung','Bekasi','Depok','Bogor','Cimahi','Tasikmalaya','Sukabumi','Cirebon','Karawang','Garut','Purwakarta','Subang'],
        'Jawa Tengah'                => ['Semarang','Solo','Surakarta','Salatiga','Magelang','Pekalongan','Tegal','Kudus','Klaten','Purwokerto','Cilacap','Kebumen'],
        'DI Yogyakarta'              => ['Yogyakarta','Sleman','Bantul','Gunung Kidul','Kulon Progo'],
        'Jawa Timur'                 => ['Surabaya','Malang','Sidoarjo','Gresik','Banyuwangi','Kediri','Mojokerto','Pasuruan','Jombang','Madiun','Jember','Lumajang'],
        'Bali'                       => ['Denpasar','Badung','Gianyar','Tabanan','Buleleng','Klungkung','Bangli','Karangasem','Jembrana'],
        'Nusa Tenggara Barat'        => ['Mataram','Bima','Sumbawa','Praya','Lombok Barat','Lombok Tengah','Lombok Timur'],
        'Nusa Tenggara Timur'        => ['Kupang','Ende','Maumere','Waingapu','Labuan Bajo','Atambua'],
        'Kalimantan Barat'           => ['Pontianak','Singkawang','Ketapang','Sanggau','Sintang','Mempawah'],
        'Kalimantan Tengah'          => ['Palangka Raya','Kotawaringin Barat','Kotawaringin Timur','Kapuas','Barito Utara'],
        'Kalimantan Selatan'         => ['Banjarmasin','Banjarbaru','Martapura','Barabai','Amuntai','Tanjung'],
        'Kalimantan Timur'           => ['Samarinda','Balikpapan','Bontang','Kutai Kartanegara','Berau','Penajam Paser Utara'],
        'Kalimantan Utara'           => ['Tarakan','Bulungan','Nunukan','Malinau','Tana Tidung'],
        'Sulawesi Utara'             => ['Manado','Bitung','Tomohon','Kotamobagu','Minahasa','Bolaang Mongondow'],
        'Gorontalo'                  => ['Gorontalo','Bone Bolango','Pohuwato','Boalemo','Gorontalo Utara'],
        'Sulawesi Tengah'            => ['Palu','Poso','Tolitoli','Banggai','Donggala','Parigi Moutong','Morowali'],
        'Sulawesi Barat'             => ['Mamuju','Polewali Mandar','Majene','Pasangkayu','Mamasa'],
        'Sulawesi Selatan'           => ['Makassar','Parepare','Palopo','Maros','Gowa','Takalar','Jeneponto','Bulukumba','Selayar','Bantaeng','Sinjai','Bone','Soppeng','Wajo','Sidrap','Pinrang','Enrekang','Tana Toraja','Toraja Utara','Luwu','Luwu Utara','Luwu Timur','Barru'],
        'Sulawesi Tenggara'          => ['Kendari','Bau-Bau','Konawe','Kolaka','Muna','Buton','Konawe Selatan'],
        'Maluku'                     => ['Ambon','Tual','Maluku Tengah','Seram Bagian Barat','Seram Bagian Timur','Buru'],
        'Maluku Utara'               => ['Ternate','Tidore Kepulauan','Halmahera Utara','Halmahera Selatan'],
        'Papua Barat'                => ['Manokwari','Sorong','Fakfak','Kaimana','Teluk Bintuni'],
        'Papua Barat Daya'           => ['Sorong Kota','Raja Ampat','Sorong','Maybrat','Tambrauw'],
        'Papua'                      => ['Jayapura','Biak','Merauke','Sarmi','Keerom','Jayawijaya'],
        'Papua Selatan'              => ['Merauke','Boven Digoel','Mappi','Asmat'],
        'Papua Tengah'               => ['Timika','Nabire','Paniai','Dogiyai','Deiyai'],
        'Papua Pegunungan'           => ['Wamena','Tolikara','Lanny Jaya','Nduga','Pegunungan Bintang'],
    ];
}

function clients_page(PDO $pdo): void
{
    require_permission('view_master');
    $filterType = getv('business_type', '');
    $filterScale = getv('business_scale', '');
    $where = 'WHERE 1=1';
    $params = [];
    if ($filterType) { $where .= ' AND c.business_type = ?'; $params[] = $filterType; }
    if ($filterScale) { $where .= ' AND c.business_scale = ?'; $params[] = $filterScale; }
    $stmt = $pdo->prepare(
        "SELECT c.*,
                (SELECT cc.name  FROM master_client_contacts cc WHERE cc.client_id = c.id AND cc.status='active' ORDER BY cc.is_primary DESC, cc.id ASC LIMIT 1) primary_contact_name,
                (SELECT cc.phone FROM master_client_contacts cc WHERE cc.client_id = c.id AND cc.status='active' ORDER BY cc.is_primary DESC, cc.id ASC LIMIT 1) primary_contact_phone
         FROM master_clients c $where ORDER BY c.company_name"
    );
    $stmt->execute($params);
    $clients = $stmt->fetchAll();
    $opts = client_options($pdo);
    layout('Master Client', function () use ($clients, $opts, $filterType, $filterScale) {
        ?>
        <div class="toolbar" style="flex-wrap:wrap;gap:8px">
            <?php if (can('manage_master')): ?><a class="btn" href="?r=client_form">Tambah Client</a><?php endif; ?>
            <form method="get" style="display:flex;gap:6px;align-items:center;margin-left:auto">
                <input type="hidden" name="r" value="clients">
                <select name="business_type" onchange="this.form.submit()" style="font-size:12px">
                    <option value="">— Semua Jenis —</option>
                    <?php foreach (array_filter($opts['business_type']) as $o): ?>
                        <option value="<?= h($o) ?>" <?= $filterType === $o ? 'selected' : '' ?>><?= h($o) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="business_scale" onchange="this.form.submit()" style="font-size:12px">
                    <option value="">— Semua Skala —</option>
                    <?php foreach (array_filter($opts['business_scale']) as $o): ?>
                        <option value="<?= h($o) ?>" <?= $filterScale === $o ? 'selected' : '' ?>><?= h($o) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($filterType || $filterScale): ?><a href="?r=clients" class="btn light" style="font-size:12px">Reset</a><?php endif; ?>
            </form>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Nama Perusahaan</th><th>Nama Brand</th><th>Kota / Provinsi</th><th>Jenis Usaha</th><th>Skala</th><th>Asal Brand</th><th>Segmen</th><th>Channel</th><th>PIC Client</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($clients as $row): ?>
                    <tr>
                        <td><?= h($row['company_name']) ?></td>
                        <td><?= h($row['brand_name'] ?? '-') ?></td>
                        <td>
                            <?php if ($row['city'] ?? ''): ?><span style="font-weight:600"><?= h($row['city']) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?>
                            <?php if ($row['province'] ?? ''): ?><br><small class="muted"><?= h($row['province']) ?></small><?php endif; ?>
                        </td>
                        <td><?= h($row['business_type'] ?? '-') ?></td>
                        <td><?= h($row['business_scale'] ?? '-') ?></td>
                        <td><?= h($row['brand_origin'] ?? '-') ?></td>
                        <td><?= h($row['target_segment'] ?? '-') ?></td>
                        <td><?= h($row['channel'] ?? '-') ?></td>
                        <td><?= h($row['primary_contact_name'] ?? '-') ?><br><small class="muted"><?= h($row['primary_contact_phone'] ?? '') ?></small></td>
                        <td><?= h($row['status']) ?></td>
                        <td><?php if (can('manage_master')): ?><a class="btn light" href="?r=client_form&id=<?= h((string) $row['id']) ?>">Edit</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$clients): ?><tr><td colspan="11" class="muted" style="text-align:center">Tidak ada data.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    });
}

function client_form(PDO $pdo): void
{
    require_permission('manage_master');
    $id = getv('id');
    $row = ['status' => 'active'];
    $contacts = [];
    if ($id) {
        $stmt = $pdo->prepare('SELECT * FROM master_clients WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch() ?: $row;
        $cStmt = $pdo->prepare('SELECT * FROM master_client_contacts WHERE client_id = ? ORDER BY is_primary DESC, name');
        $cStmt->execute([$id]);
        $contacts = $cStmt->fetchAll();
    }
    $opts = client_options($pdo);
    $geoData = clients_geo_data();
    layout(($id ? 'Edit' : 'Tambah') . ' Client', function () use ($row, $id, $contacts, $opts, $pdo, $geoData) {
        $curProvince = $row['province'] ?? '';
        $curCity     = $row['city'] ?? '';
        ?>
        <form class="panel" method="post" action="?r=client_save">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= h((string) $id) ?>">
            <h3 style="margin-top:0">Data Perusahaan</h3>
            <div class="form-grid">
                <div><label>Nama Perusahaan</label><input name="company_name" required value="<?= field($row, 'company_name') ?>"></div>
                <div><label>Nama Brand</label><input name="brand_name" value="<?= field($row, 'brand_name') ?>" placeholder="Nama brand jika berbeda dari perusahaan"></div>
                <div><label>NPWP</label><input name="npwp" value="<?= field($row, 'npwp') ?>"></div>
                <div class="wide"><label>Alamat</label><textarea name="address"><?= h($row['address'] ?? '') ?></textarea></div>
                <div>
                    <label>Provinsi</label>
                    <select name="province" id="province-select" onchange="updateCities(this.value,'')">
                        <option value="">— Pilih Provinsi —</option>
                        <?php foreach (array_keys($geoData) as $prov): ?>
                        <option value="<?= h($prov) ?>" <?= $curProvince === $prov ? 'selected' : '' ?>><?= h($prov) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Kota / Kabupaten</label>
                    <select name="city" id="city-select">
                        <option value="">— Pilih Provinsi dulu —</option>
                        <?php if ($curProvince && isset($geoData[$curProvince])): ?>
                            <?php foreach ($geoData[$curProvince] as $c): ?>
                            <option value="<?= h($c) ?>" <?= $curCity === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            <script>
            var geoData = <?= json_encode($geoData, JSON_UNESCAPED_UNICODE) ?>;
            function updateCities(prov, preselect) {
                var sel = document.getElementById('city-select');
                sel.innerHTML = '<option value="">— Pilih Kota —</option>';
                var cities = geoData[prov] || [];
                cities.forEach(function(c) {
                    var opt = document.createElement('option');
                    opt.value = c; opt.textContent = c;
                    if (c === preselect) opt.selected = true;
                    sel.appendChild(opt);
                });
                sel.disabled = cities.length === 0;
            }
            // Init on load for edit mode
            (function(){
                var prov = document.getElementById('province-select').value;
                if (prov) updateCities(prov, <?= json_encode($curCity) ?>);
            })();
            </script>
            <h3>Profil Bisnis & Target Market</h3>
            <div class="form-grid">
                <?php foreach ([
                    'business_type'  => 'Jenis Usaha',
                    'business_scale' => 'Skala Usaha',
                    'brand_origin'   => 'Asal Brand',
                    'channel'        => 'Channel Penjualan',
                ] as $field => $label): ?>
                <div>
                    <label><?= $label ?></label>
                    <select name="<?= $field ?>">
                        <option value="">— Pilih —</option>
                        <?php foreach ($opts[$field] as $o): ?>
                            <option value="<?= h($o) ?>" <?= ($row[$field] ?? '') === $o ? 'selected' : '' ?>><?= h($o) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>
                <div class="wide">
                    <label>Target Segmen <span class="muted" style="font-weight:400">(bisa lebih dari satu)</span></label>
                    <?php $selectedSegs = array_filter(array_map('trim', explode(',', $row['target_segment'] ?? ''))); ?>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px">
                        <?php foreach ($opts['target_segment'] as $o): ?>
                        <label style="display:flex;align-items:center;gap:5px;font-weight:400;cursor:pointer;background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:5px 10px;font-size:13px">
                            <input type="checkbox" name="target_segment[]" value="<?= h($o) ?>" <?= in_array($o, $selectedSegs) ? 'checked' : '' ?>>
                            <?= h($o) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div><label>Tags <span class="muted" style="font-weight:400">(pisah koma)</span></label><input name="tags" value="<?= field($row, 'tags') ?>" placeholder="misal: anchor, seasonal, promo rutin"></div>
                <div>
                    <label>Status</label>
                    <select name="status">
                        <?php foreach (['active' => 'Active', 'inactive' => 'Inactive'] as $k => $v): ?>
                            <option value="<?= h($k) ?>" <?= ($row['status'] ?? 'active') === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <p><button type="submit">Simpan Client</button> <a class="btn secondary" href="?r=clients">Kembali</a></p>
        </form>
        <?php if ($id): ?>
        <div class="panel" style="margin-top:14px">
            <div style="display:flex;justify-content:space-between;align-items:center">
                <h3 style="margin:0">Daftar Contact Person</h3>
                <a class="btn" href="?r=client_form&id=<?= h((string) $id) ?>&add_contact=1">+ Tambah Kontak</a>
            </div>
            <div class="table-wrap" style="margin-top:12px">
                <table>
                    <thead><tr><th>Nama</th><th>Telepon</th><th>Email</th><th>Utama</th><th>Status</th><th>Aksi</th></tr></thead>
                    <tbody>
                    <?php foreach ($contacts as $ct): ?>
                        <tr>
                            <td><?= h($ct['name']) ?></td>
                            <td><?= h($ct['phone'] ?? '-') ?></td>
                            <td><?= h($ct['email'] ?? '-') ?></td>
                            <td><?= $ct['is_primary'] ? '<span class="badge">Utama</span>' : '-' ?></td>
                            <td><?= h($ct['status']) ?></td>
                            <td><a class="btn light" href="?r=client_form&id=<?= h((string) $id) ?>&contact_id=<?= h((string) $ct['id']) ?>">Edit</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$contacts): ?><tr><td colspan="6" class="muted" style="text-align:center">Belum ada kontak.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
            $contactId = getv('contact_id');
            $addContact = getv('add_contact');
            $ctRow = ['status' => 'active', 'is_primary' => 0];
            if ($contactId) {
                $ctStmt = $pdo->prepare('SELECT * FROM master_client_contacts WHERE id = ? AND client_id = ?');
                $ctStmt->execute([$contactId, $id]);
                $ctRow = $ctStmt->fetch() ?: $ctRow;
            }
            if ($contactId || $addContact):
            ?>
            <form method="post" action="?r=client_save" style="margin-top:16px;border-top:1px solid var(--border);padding-top:16px">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= h((string) $id) ?>">
                <input type="hidden" name="contact_id" value="<?= h((string) ($contactId ?: '')) ?>">
                <input type="hidden" name="save_contact" value="1">
                <h4 style="margin-top:0"><?= $contactId ? 'Edit' : 'Tambah' ?> Contact Person</h4>
                <div class="form-grid">
                    <div><label>Nama</label><input name="contact_name" required value="<?= field($ctRow, 'name') ?>"></div>
                    <div><label>Telepon</label><input name="contact_phone" value="<?= field($ctRow, 'phone') ?>"></div>
                    <div><label>Email</label><input type="email" name="contact_email" value="<?= field($ctRow, 'email') ?>"></div>
                    <div>
                        <label>Jadikan Kontak Utama</label>
                        <select name="contact_is_primary">
                            <option value="0" <?= !($ctRow['is_primary'] ?? 0) ? 'selected' : '' ?>>Tidak</option>
                            <option value="1" <?= ($ctRow['is_primary'] ?? 0) ? 'selected' : '' ?>>Ya</option>
                        </select>
                    </div>
                    <div>
                        <label>Status</label>
                        <select name="contact_status">
                            <option value="active" <?= ($ctRow['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= ($ctRow['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                </div>
                <p><button type="submit">Simpan Kontak</button> <a class="btn secondary" href="?r=client_form&id=<?= h((string) $id) ?>">Batal</a></p>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php
    });
}

function client_save(PDO $pdo): void
{
    require_permission('manage_master');
    verify_csrf();
    $id = post('id');

    if (post('save_contact')) {
        $contactId = post('contact_id');
        $data = [
            'name'       => trim((string) post('contact_name')),
            'phone'      => trim((string) post('contact_phone')),
            'email'      => trim((string) post('contact_email')),
            'is_primary' => (int) post('contact_is_primary'),
            'status'     => post('contact_status', 'active'),
        ];
        if ($data['is_primary']) {
            $pdo->prepare('UPDATE master_client_contacts SET is_primary=0 WHERE client_id=?')->execute([$id]);
        }
        if ($contactId) {
            $pdo->prepare('UPDATE master_client_contacts SET name=:name, phone=:phone, email=:email, is_primary=:is_primary, status=:status WHERE id=:id AND client_id=:client_id')
                ->execute(array_merge($data, [':id' => $contactId, ':client_id' => $id]));
        } else {
            $pdo->prepare('INSERT INTO master_client_contacts (client_id, name, phone, email, is_primary, status) VALUES (:client_id, :name, :phone, :email, :is_primary, :status)')
                ->execute(array_merge($data, [':client_id' => $id]));
        }
        flash('Kontak disimpan.');
        redirect_to('client_form', ['id' => $id]);
    }

    $opts    = client_options($pdo);
    $geoData = clients_geo_data();
    $postProv = trim((string) post('province'));
    $postCity = trim((string) post('city'));
    $data = [
        'company_name'   => trim((string) post('company_name')),
        'brand_name'     => trim((string) post('brand_name')) ?: null,
        'npwp'           => trim((string) post('npwp')) ?: null,
        'address'        => trim((string) post('address')) ?: null,
        'province'       => isset($geoData[$postProv]) ? $postProv : null,
        'city'           => ($postCity && isset($geoData[$postProv]) && in_array($postCity, $geoData[$postProv], true)) ? $postCity : null,
        'business_type'  => in_array(post('business_type'), $opts['business_type'], true) ? post('business_type') : null,
        'business_scale' => in_array(post('business_scale'), $opts['business_scale'], true) ? post('business_scale') : null,
        'brand_origin'   => in_array(post('brand_origin'), $opts['brand_origin'], true) ? post('brand_origin') : null,
        'target_segment' => (function() use ($opts) {
            $raw = $_POST['target_segment'] ?? [];
            if (!is_array($raw)) $raw = [];
            $valid = array_filter($raw, fn($v) => in_array($v, $opts['target_segment'], true));
            return $valid ? implode(', ', $valid) : null;
        })(),
        'channel'        => in_array(post('channel'), $opts['channel'], true) ? post('channel') : null,
        'tags'           => trim((string) post('tags')) ?: null,
        'status'         => post('status', 'active'),
    ];

    if ($id) {
        $pdo->prepare('UPDATE master_clients SET company_name=:company_name, brand_name=:brand_name, npwp=:npwp, address=:address, province=:province, city=:city, business_type=:business_type, business_scale=:business_scale, brand_origin=:brand_origin, target_segment=:target_segment, channel=:channel, tags=:tags, status=:status WHERE id=:id')
            ->execute(array_merge($data, [':id' => $id]));
        audit($pdo, 'update', 'master_clients', (string) $id, $data);
    } else {
        $pdo->prepare('INSERT INTO master_clients (company_name, brand_name, npwp, address, province, city, business_type, business_scale, brand_origin, target_segment, channel, tags, status) VALUES (:company_name, :brand_name, :npwp, :address, :province, :city, :business_type, :business_scale, :brand_origin, :target_segment, :channel, :tags, :status)')
            ->execute($data);
        $id = $pdo->lastInsertId();
        audit($pdo, 'create', 'master_clients', (string) $id, $data);
    }
    flash('Client disimpan.');
    redirect_to('client_form', ['id' => $id]);
}

function client_analysis_page(PDO $pdo): void
{
    require_permission('view_master');

    $opts          = client_options($pdo);
    $period        = getv('period', date('Y-m'));
    $filterType    = getv('business_type', '');
    $filterScale   = getv('business_scale', '');
    $filterSegment = getv('target_segment', '');

    // Build WHERE for simple queries (no table alias)
    $sw = ["status='active'"]; $sp = [];
    if ($filterType)    { $sw[] = 'business_type = ?';       $sp[] = $filterType; }
    if ($filterScale)   { $sw[] = 'business_scale = ?';      $sp[] = $filterScale; }
    if ($filterSegment) { $sw[] = 'target_segment LIKE ?';   $sp[] = '%' . $filterSegment . '%'; }
    $simpleWhere = implode(' AND ', $sw);

    // Build WHERE for join queries (with c. alias)
    $jw = ["c.status='active'"]; $jp = [];
    if ($filterType)    { $jw[] = 'c.business_type = ?';     $jp[] = $filterType; }
    if ($filterScale)   { $jw[] = 'c.business_scale = ?';    $jp[] = $filterScale; }
    if ($filterSegment) { $jw[] = 'c.target_segment LIKE ?'; $jp[] = '%' . $filterSegment . '%'; }
    $joinWhere = implode(' AND ', $jw);

    $dist = function(string $col) use ($pdo, $simpleWhere, $sp): array {
        $stmt = $pdo->prepare("SELECT $col k, COUNT(*) n FROM master_clients WHERE $simpleWhere AND $col IS NOT NULL AND $col!='' GROUP BY $col ORDER BY n DESC");
        $stmt->execute($sp);
        return $stmt->fetchAll();
    };

    $byType    = $dist('business_type');
    $byScale   = $dist('business_scale');
    $byOrigin  = $dist('brand_origin');
    $currentYear = substr($period, 0, 4);
    $prevYear = (string)((int)$currentYear - 1);

    // Fix: split comma-separated target_segment
    $segRaw = $pdo->prepare("SELECT target_segment FROM master_clients WHERE $simpleWhere AND target_segment IS NOT NULL AND target_segment != ''");
    $segRaw->execute($sp);
    $segCounts = [];
    foreach ($segRaw->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        foreach (array_map('trim', explode(',', $raw)) as $s) {
            if ($s !== '') $segCounts[$s] = ($segCounts[$s] ?? 0) + 1;
        }
    }
    arsort($segCounts);
    $bySegment = array_map(fn($k, $n) => ['k' => $k, 'n' => $n], array_keys($segCounts), array_values($segCounts));

    $cntStmt = $pdo->prepare("SELECT COUNT(*) total, SUM(CASE WHEN business_type IS NOT NULL AND business_type != '' THEN 1 ELSE 0 END) filled FROM master_clients WHERE $simpleWhere");
    $cntStmt->execute($sp);
    $cnt    = $cntStmt->fetch();
    $total  = (int)$cnt['total'];
    $filled = (int)$cnt['filled'];

    $pid = current_property_id();
    $revStmt = $pdo->prepare(
        "SELECT c.business_type, COALESCE(SUM(a.amount),0) revenue, COUNT(DISTINCT t.id) trx_count, COUNT(DISTINCT c.id) client_count
         FROM master_clients c
         JOIN transactions t ON t.client_id = c.id AND t.deleted_at IS NULL AND t.property_id = ?
         JOIN transaction_allocations a ON a.transaction_id = t.id AND a.period_key = ? AND a.property_id = ?
         WHERE $joinWhere AND c.business_type IS NOT NULL AND c.business_type != ''
         GROUP BY c.business_type ORDER BY revenue DESC"
    );
    $revStmt->execute(array_merge([$pid, $period, $pid], $jp));
    $revRows = $revStmt->fetchAll();

    // New vs returning clients for selected period
    $newReturnStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT c.id) total_period,
                COUNT(DISTINCT CASE WHEN first_t.min_period = ? THEN c.id END) new_client,
                COUNT(DISTINCT CASE WHEN first_t.min_period < ? THEN c.id END) returning_client
         FROM master_clients c
         JOIN transactions t ON t.client_id = c.id AND t.deleted_at IS NULL AND t.property_id = ?
         JOIN transaction_allocations a ON a.transaction_id = t.id AND a.period_key = ? AND a.property_id = ?
         LEFT JOIN (
             SELECT t2.client_id, MIN(a2.period_key) min_period
             FROM transactions t2
             JOIN transaction_allocations a2 ON a2.transaction_id = t2.id
             WHERE t2.deleted_at IS NULL AND t2.property_id = ? AND a2.property_id = ?
             GROUP BY t2.client_id
         ) first_t ON first_t.client_id = c.id
         WHERE $joinWhere"
    );
    $newReturnStmt->execute(array_merge([$period, $period, $pid, $period, $pid, $pid, $pid], $jp));
    $newReturn = $newReturnStmt->fetch();

    // YoY retention
    $retentionStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT CASE WHEN prev.client_id IS NOT NULL THEN c.id END) active_prev_year,
                COUNT(DISTINCT CASE WHEN prev.client_id IS NOT NULL AND curr.client_id IS NOT NULL THEN c.id END) retained
         FROM master_clients c
         LEFT JOIN (
             SELECT DISTINCT t2.client_id FROM transactions t2
             JOIN transaction_allocations a2 ON a2.transaction_id = t2.id
             WHERE a2.period_key BETWEEN ? AND ? AND t2.deleted_at IS NULL AND t2.client_id IS NOT NULL AND t2.property_id = ? AND a2.property_id = ?
         ) prev ON prev.client_id = c.id
         LEFT JOIN (
             SELECT DISTINCT t2.client_id FROM transactions t2
             JOIN transaction_allocations a2 ON a2.transaction_id = t2.id
             WHERE a2.period_key BETWEEN ? AND ? AND t2.deleted_at IS NULL AND t2.client_id IS NOT NULL AND t2.property_id = ? AND a2.property_id = ?
         ) curr ON curr.client_id = c.id
         WHERE $joinWhere"
    );
    $retentionStmt->execute(array_merge([$prevYear . '-01', $prevYear . '-12', $pid, $pid, $currentYear . '-01', $currentYear . '-12', $pid, $pid], $jp));
    $retention = $retentionStmt->fetch();

    // Transaction frequency distribution (this year)
    $freqStmt = $pdo->prepare(
        "SELECT CASE WHEN period_count = 1 THEN '1 Periode'
                     WHEN period_count <= 3 THEN '2-3 Periode'
                     WHEN period_count <= 6 THEN '4-6 Periode'
                     ELSE '7+ Periode' END bucket,
                COUNT(*) cnt,
                MIN(period_count) sort_key
         FROM (
             SELECT c.id, COUNT(DISTINCT a.period_key) period_count
             FROM master_clients c
             JOIN transactions t ON t.client_id = c.id AND t.deleted_at IS NULL AND t.property_id = ?
             JOIN transaction_allocations a ON a.transaction_id = t.id AND a.property_id = ?
             WHERE $joinWhere AND a.period_key BETWEEN ? AND ?
             GROUP BY c.id
         ) sub
         GROUP BY 1
         ORDER BY 3"
    );
    $freqStmt->execute(array_merge([$pid, $pid], $jp, [$currentYear . '-01', $currentYear . '-12']));
    $freqDist = $freqStmt->fetchAll();

    // At-risk clients: active last year, no transaction this year
    $atRiskStmt = $pdo->prepare(
        "SELECT c.company_name, c.brand_name, c.business_type,
                COALESCE(SUM(a_prev.amount),0) prev_revenue,
                MAX(a_prev.period_key) last_period
         FROM master_clients c
         JOIN transactions t_prev ON t_prev.client_id = c.id AND t_prev.deleted_at IS NULL AND t_prev.property_id = ?
         JOIN transaction_allocations a_prev ON a_prev.transaction_id = t_prev.id
             AND a_prev.period_key BETWEEN ? AND ? AND a_prev.property_id = ?
         LEFT JOIN (
             SELECT DISTINCT t2.client_id FROM transactions t2
             JOIN transaction_allocations a2 ON a2.transaction_id = t2.id
             WHERE a2.period_key BETWEEN ? AND ? AND t2.deleted_at IS NULL AND t2.client_id IS NOT NULL AND t2.property_id = ? AND a2.property_id = ?
         ) curr ON curr.client_id = c.id
         WHERE $joinWhere AND curr.client_id IS NULL
         GROUP BY c.id, c.company_name, c.brand_name, c.business_type
         ORDER BY prev_revenue DESC
         LIMIT 15"
    );
    $atRiskStmt->execute(array_merge([$pid, $prevYear . '-01', $prevYear . '-12', $pid, $currentYear . '-01', $currentYear . '-12', $pid, $pid], $jp));
    $atRiskClients = $atRiskStmt->fetchAll();

    // Top 10 clients by revenue
    $topStmt = $pdo->prepare(
        "SELECT c.company_name, c.brand_name, c.business_type, c.business_scale,
                COALESCE(SUM(a.amount),0) revenue, COUNT(DISTINCT t.id) trx_count
         FROM master_clients c
         JOIN transactions t ON t.client_id = c.id AND t.deleted_at IS NULL AND t.property_id = ?
         JOIN transaction_allocations a ON a.transaction_id = t.id AND a.period_key = ? AND a.property_id = ?
         WHERE $joinWhere
         GROUP BY c.id, c.company_name, c.brand_name, c.business_type, c.business_scale
         ORDER BY revenue DESC LIMIT 10"
    );
    $topStmt->execute(array_merge([$pid, $period, $pid], $jp));
    $topClients = $topStmt->fetchAll();

    // Active vs dormant per business type (this year)
    $actStmt = $pdo->prepare(
        "SELECT c.business_type, COUNT(DISTINCT c.id) total,
                COUNT(DISTINCT act.client_id) active_count
         FROM master_clients c
         LEFT JOIN (
             SELECT DISTINCT t2.client_id FROM transactions t2
             JOIN transaction_allocations a2 ON a2.transaction_id = t2.id
             WHERE a2.period_key BETWEEN ? AND ? AND t2.deleted_at IS NULL AND t2.client_id IS NOT NULL AND t2.property_id = ? AND a2.property_id = ?
         ) act ON act.client_id = c.id
         WHERE $joinWhere AND c.business_type IS NOT NULL AND c.business_type != ''
         GROUP BY c.business_type ORDER BY total DESC"
    );
    $actStmt->execute(array_merge([$currentYear . '-01', $currentYear . '-12', $pid, $pid], $jp));
    $activityByType = $actStmt->fetchAll();

    // Overall activity summary
    $actSumStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT c.id) total,
                COUNT(DISTINCT act.client_id) active_year,
                COUNT(DISTINCT CASE WHEN ever.client_id IS NULL THEN c.id END) never_trx
         FROM master_clients c
         LEFT JOIN (
             SELECT DISTINCT t2.client_id FROM transactions t2
             JOIN transaction_allocations a2 ON a2.transaction_id = t2.id
             WHERE a2.period_key BETWEEN ? AND ? AND t2.deleted_at IS NULL AND t2.client_id IS NOT NULL AND t2.property_id = ? AND a2.property_id = ?
         ) act ON act.client_id = c.id
         LEFT JOIN (
             SELECT DISTINCT client_id FROM transactions WHERE deleted_at IS NULL AND client_id IS NOT NULL AND property_id = ?
         ) ever ON ever.client_id = c.id
         WHERE $joinWhere"
    );
    $actSumStmt->execute(array_merge([$currentYear . '-01', $currentYear . '-12', $pid, $pid, $pid], $jp));
    $actSummary = $actSumStmt->fetch();

    // Distribution by floor (CL, selected period)
    $byFloorStmt = $pdo->prepare(
        "SELECT m.floor, COUNT(DISTINCT c.id) client_count, COALESCE(SUM(a.amount),0) revenue
         FROM master_clients c
         JOIN transactions t ON t.client_id = c.id AND t.deleted_at IS NULL AND t.property_id = ?
         JOIN transaction_allocations a ON a.transaction_id = t.id AND a.period_key = ? AND a.module = 'cl' AND a.property_id = ?
         JOIN master_cl_units m ON m.code = a.master_code AND m.property_id = ?
         WHERE $joinWhere
         GROUP BY m.floor
         ORDER BY CASE m.floor WHEN 'LG' THEN 1 WHEN 'GF' THEN 2 WHEN 'UG' THEN 3 WHEN 'FF' THEN 4 WHEN 'SF' THEN 5 ELSE 6 END"
    );
    $byFloorStmt->execute(array_merge([$pid, $period, $pid, $pid], $jp));
    $byFloor = $byFloorStmt->fetchAll();

    // Client type distribution per floor (for pie charts)
    $floorTypeStmt = $pdo->prepare(
        "SELECT m.floor, c.business_type, COUNT(DISTINCT c.id) client_count
         FROM master_clients c
         JOIN transactions t ON t.client_id = c.id AND t.deleted_at IS NULL AND t.property_id = ?
         JOIN transaction_allocations a ON a.transaction_id = t.id AND a.period_key = ? AND a.module = 'cl' AND a.property_id = ?
         JOIN master_cl_units m ON m.code = a.master_code AND m.property_id = ?
         WHERE $joinWhere AND c.business_type IS NOT NULL AND c.business_type != ''
         GROUP BY m.floor, c.business_type
         ORDER BY CASE m.floor WHEN 'LG' THEN 1 WHEN 'GF' THEN 2 WHEN 'UG' THEN 3 WHEN 'FF' THEN 4 WHEN 'SF' THEN 5 ELSE 6 END, client_count DESC"
    );
    $floorTypeStmt->execute(array_merge([$pid, $period, $pid, $pid], $jp));
    $floorTypes = [];
    foreach ($floorTypeStmt->fetchAll() as $r) {
        $floorTypes[$r['floor']][] = $r;
    }

    // Revenue breakdown by module
    $byModuleStmt = $pdo->prepare(
        "SELECT a.module, COUNT(DISTINCT c.id) client_count, COUNT(DISTINCT t.id) trx_count, COALESCE(SUM(a.amount),0) revenue
         FROM master_clients c
         JOIN transactions t ON t.client_id = c.id AND t.deleted_at IS NULL AND t.property_id = ?
         JOIN transaction_allocations a ON a.transaction_id = t.id AND a.period_key = ? AND a.property_id = ?
         WHERE $joinWhere
         GROUP BY a.module
         ORDER BY revenue DESC"
    );
    $byModuleStmt->execute(array_merge([$pid, $period, $pid], $jp));
    $byModule = $byModuleStmt->fetchAll();

    $periods = $pdo->query("SELECT period_key, label FROM periods ORDER BY period_key DESC")->fetchAll();
    $hasFilter = $filterType || $filterScale || $filterSegment;

    layout('Analisa Market Client', function () use ($byType, $byScale, $byOrigin, $bySegment, $total, $filled, $revRows, $topClients, $activityByType, $actSummary, $currentYear, $period, $periods, $opts, $filterType, $filterScale, $filterSegment, $hasFilter, $prevYear, $newReturn, $retention, $freqDist, $atRiskClients, $byFloor, $byModule, $floorTypes) {
        $pct = fn($n) => $total > 0 ? round($n / $total * 100) : 0;
        $colors = ['#0D9488','#0891B2','#7C3AED','#F59E0B','#EF4444','#10B981','#F97316','#6366F1','#EC4899','#84CC16','#14B8A6','#8B5CF6'];
        ?>
        <form method="get" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:16px">
            <input type="hidden" name="r" value="client_analysis">
            <select name="business_type" onchange="this.form.submit()" style="font-size:12px">
                <option value="">— Semua Jenis —</option>
                <?php foreach ($opts['business_type'] as $o): ?>
                    <option value="<?= h($o) ?>" <?= $filterType === $o ? 'selected' : '' ?>><?= h($o) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="business_scale" onchange="this.form.submit()" style="font-size:12px">
                <option value="">— Semua Skala —</option>
                <?php foreach ($opts['business_scale'] as $o): ?>
                    <option value="<?= h($o) ?>" <?= $filterScale === $o ? 'selected' : '' ?>><?= h($o) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="target_segment" onchange="this.form.submit()" style="font-size:12px">
                <option value="">— Semua Segmen —</option>
                <?php foreach ($opts['target_segment'] as $o): ?>
                    <option value="<?= h($o) ?>" <?= $filterSegment === $o ? 'selected' : '' ?>><?= h($o) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($hasFilter): ?><a href="?r=client_analysis" class="btn light" style="font-size:12px">Reset Filter</a><?php endif; ?>
            <div style="margin-left:auto;display:flex;gap:6px;align-items:center">
                <label style="font-size:12px;color:var(--muted)">Periode Revenue:</label>
                <select name="period" onchange="this.form.submit()" style="font-size:12px">
                    <?php foreach ($periods as $p): ?>
                        <option value="<?= h($p['period_key']) ?>" <?= $p['period_key'] === $period ? 'selected' : '' ?>><?= h($p['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <a class="btn light" href="?r=export_client_analysis_xlsx&business_type=<?= urlencode($filterType) ?>&business_scale=<?= urlencode($filterScale) ?>&target_segment=<?= urlencode($filterSegment) ?>" style="font-size:12px;white-space:nowrap">⬇ Export Excel</a>
            </div>
        </form>
        <?php if ($hasFilter): ?>
        <div style="background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;padding:8px 14px;font-size:12px;color:#92400E;margin-bottom:14px">
            Filter aktif:
            <?php if ($filterType): ?><strong><?= h($filterType) ?></strong><?php endif; ?>
            <?php if ($filterScale): ?><?= $filterType ? ' · ' : '' ?><strong><?= h($filterScale) ?></strong><?php endif; ?>
            <?php if ($filterSegment): ?><?= ($filterType||$filterScale) ? ' · ' : '' ?>Segmen <strong><?= h($filterSegment) ?></strong><?php endif; ?>
            — menampilkan <strong><?= $total ?></strong> client
        </div>
        <?php endif; ?>

        <?php
        $activeYear = (int)($actSummary['active_year'] ?? 0);
        $neverTrx   = (int)($actSummary['never_trx'] ?? 0);
        $dormant    = $total - $activeYear;
        ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px">
            <?php foreach ([
                ['Total Client',            $total,      '',        ''],
                ['Profil Terisi',           $filled,     ' client', ''],
                ['Kelengkapan',             $total > 0 ? round($filled/$total*100).'%' : '0%', '', ''],
                ['Aktif '.$currentYear,     $activeYear, ' client', '#0D9488'],
                ['Dormant '.$currentYear,   $dormant,    ' client', '#F59E0B'],
                ['Belum Pernah Transaksi',  $neverTrx,   ' client', '#EF4444'],
            ] as [$lbl, $val, $suffix, $color]): ?>
            <div class="panel" style="padding:14px 16px;margin:0<?= $color ? ';border-top:3px solid '.$color : '' ?>">
                <div class="kpi-label"><?= $lbl ?></div>
                <div class="kpi-value" style="font-size:22px<?= $color ? ';color:'.$color : '' ?>"><?= $val ?><?= $suffix ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php
        $retainedCount  = (int)($retention['retained'] ?? 0);
        $prevYearActive = (int)($retention['active_prev_year'] ?? 0);
        $retentionRate  = $prevYearActive > 0 ? round($retainedCount / $prevYearActive * 100) : 0;
        $retRateColor   = $retentionRate >= 70 ? '#0D9488' : ($retentionRate >= 40 ? '#F59E0B' : '#EF4444');
        ?>
        <div class="panel" style="margin-bottom:20px;padding:14px 16px">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);letter-spacing:.5px;margin-bottom:10px">Akuisisi & Retensi — <?= h($period) ?></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px">
                <?php foreach ([
                    ['Aktif Periode Ini',  (int)($newReturn['total_period'] ?? 0),    ' client', '#0891B2'],
                    ['Baru (Akuisisi)',    (int)($newReturn['new_client'] ?? 0),       ' client', '#7C3AED'],
                    ['Returning',         (int)($newReturn['returning_client'] ?? 0), ' client', '#10B981'],
                    ['Retensi YoY',       $retentionRate . '%',                       ' ('.$retainedCount.'/'.$prevYearActive.')', $retRateColor],
                ] as [$lbl, $val, $suffix, $color]): ?>
                <div style="border-left:3px solid <?= $color ?>;padding-left:10px">
                    <div class="kpi-label"><?= $lbl ?></div>
                    <div style="font-size:20px;font-weight:700;color:<?= $color ?>"><?= $val ?><span style="font-size:11px;color:var(--muted);font-weight:400"><?= $suffix ?></span></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
            <div class="panel" style="margin:0">
                <div style="font-weight:700;margin-bottom:12px">Distribusi Jenis Usaha</div>
                <?php if ($byType): ?>
                <?php foreach ($byType as $i => $r): ?>
                <div style="margin-bottom:8px">
                    <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px">
                        <span><?= h($r['k']) ?></span>
                        <span style="color:var(--muted)"><?= $r['n'] ?> (<?= $pct($r['n']) ?>%)</span>
                    </div>
                    <div style="background:#F1F5F9;border-radius:4px;height:8px">
                        <div style="background:<?= $colors[$i % count($colors)] ?>;width:<?= $pct($r['n']) ?>%;height:8px;border-radius:4px;transition:.3s"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?><p class="muted">Belum ada data.</p><?php endif; ?>
            </div>
            <div style="display:flex;flex-direction:column;gap:16px">
                <div class="panel" style="margin:0">
                    <div style="font-weight:700;margin-bottom:10px">Skala Usaha</div>
                    <?php if ($byScale): ?>
                    <?php foreach ($byScale as $i => $r): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--line);font-size:13px">
                        <span><?= h($r['k']) ?></span>
                        <span style="font-weight:700;color:<?= $colors[$i] ?>"><?= $r['n'] ?> <span class="muted">(<?= $pct($r['n']) ?>%)</span></span>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?><p class="muted">Belum ada data.</p><?php endif; ?>
                </div>
                <div class="panel" style="margin:0">
                    <div style="font-weight:700;margin-bottom:10px">Asal Brand</div>
                    <?php if ($byOrigin): ?>
                    <?php foreach ($byOrigin as $i => $r): ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--line);font-size:13px">
                        <span><?= h($r['k']) ?></span>
                        <span style="font-weight:700;color:<?= $colors[$i+3] ?>"><?= $r['n'] ?> <span class="muted">(<?= $pct($r['n']) ?>%)</span></span>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?><p class="muted">Belum ada data.</p><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="panel" style="margin-bottom:16px">
            <div style="font-weight:700;margin-bottom:12px">Target Segmen Pasar</div>
            <?php if ($bySegment): ?>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <?php foreach ($bySegment as $i => $r): ?>
                <div style="background:<?= $colors[$i % count($colors)] ?>18;border:1px solid <?= $colors[$i % count($colors)] ?>44;border-radius:20px;padding:6px 14px;font-size:13px">
                    <span style="font-weight:700;color:<?= $colors[$i % count($colors)] ?>"><?= h($r['k']) ?></span>
                    <span style="color:var(--muted);margin-left:6px"><?= $r['n'] ?> client</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?><p class="muted">Belum ada data.</p><?php endif; ?>
        </div>


        <?php if (!empty($floorTypes)):
            $floorColors = ['LG' => '#0D9488', 'GF' => '#0891B2', 'UG' => '#7C3AED', 'FF' => '#F59E0B', 'SF' => '#F97316'];
            $floorCids   = array_combine(array_keys($floorTypes), array_map(fn($f) => 'pieFloor_' . preg_replace('/[^a-z0-9]/i', '_', $f), array_keys($floorTypes)));
        ?>
        <div class="panel" style="margin-bottom:16px">
            <div style="font-weight:700;margin-bottom:16px">Sebaran Jenis Client per Lantai — <?= h($period) ?></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:24px;align-items:start">
                <?php foreach ($floorTypes as $floor => $rows):
                    $fc       = $floorColors[$floor] ?? '#6366F1';
                    $cid      = $floorCids[$floor];
                    $flrTotal = array_sum(array_column($rows, 'client_count'));
                ?>
                <div>
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px">
                        <span style="background:<?= $fc ?>18;color:<?= $fc ?>;border:1px solid <?= $fc ?>44;border-radius:4px;padding:2px 10px;font-size:12px;font-weight:700"><?= h($floor) ?></span>
                        <span style="font-size:11px;color:var(--muted)"><?= $flrTotal ?> client</span>
                    </div>
                    <div style="position:relative;height:160px">
                        <canvas id="<?= $cid ?>"></canvas>
                    </div>
                    <div style="margin-top:4px">
                        <?php foreach ($rows as $i => $r): ?>
                        <div style="display:flex;align-items:center;gap:5px;font-size:11px;margin-bottom:3px">
                            <span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:<?= $colors[$i % count($colors)] ?>;flex-shrink:0"></span>
                            <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--ink)" title="<?= h($r['business_type']) ?>"><?= h($r['business_type']) ?> <span style="color:var(--muted)">(<?= $r['client_count'] ?>)</span></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <script src="assets/chart.umd.min.js"></script>
        <script>
        (function(){
            var colors = <?= json_encode($colors) ?>;
            <?php foreach ($floorTypes as $floor => $rows):
                $cid       = $floorCids[$floor];
                $labels    = array_column($rows, 'business_type');
                $data      = array_map(fn($r) => (int)$r['client_count'], $rows);
                $pieColors = array_map(fn($i) => $colors[$i % count($colors)], range(0, count($rows) - 1));
            ?>
            new Chart(document.getElementById('<?= $cid ?>'), {
                type: 'pie',
                data: { labels: <?= json_encode($labels) ?>, datasets: [{ data: <?= json_encode($data) ?>, backgroundColor: <?= json_encode($pieColors) ?>, borderWidth: 1 }] },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: function(c){ return ' '+c.label+': '+c.raw+' client'; } } }
                    }
                }
            });
            <?php endforeach; ?>
        })();
        </script>
        <?php endif; ?>

        <div class="panel" style="margin-bottom:16px">
            <div style="font-weight:700;margin-bottom:12px">Revenue per Jenis Usaha — <?= h($period) ?></div>
            <?php if ($revRows): ?>
            <div style="height:<?= max(100, count($revRows) * 32) ?>px;position:relative;margin-bottom:14px"><canvas id="revChart"></canvas></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Jenis Usaha</th><th class="r">Jml Client</th><th class="r">Jml Transaksi</th><th class="r">Avg/Client</th><th class="r">Total Revenue</th></tr></thead>
                    <tbody>
                    <?php $grandTotal = array_sum(array_column($revRows, 'revenue')); ?>
                    <?php foreach ($revRows as $i => $r): ?>
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px">
                                <div style="width:10px;height:10px;border-radius:2px;background:<?= $colors[$i % count($colors)] ?>;flex-shrink:0"></div>
                                <?= h($r['business_type']) ?>
                            </div>
                        </td>
                        <td class="r"><?= $r['client_count'] ?></td>
                        <td class="r"><?= $r['trx_count'] ?></td>
                        <td class="r money"><?= $r['client_count'] > 0 ? money((float)$r['revenue'] / (int)$r['client_count']) : '-' ?></td>
                        <td class="r money"><?= money($r['revenue']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="font-weight:700;border-top:2px solid var(--line)">
                        <td>Total</td>
                        <td class="r"><?= array_sum(array_column($revRows,'client_count')) ?></td>
                        <td class="r"><?= array_sum(array_column($revRows,'trx_count')) ?></td>
                        <td class="r"></td>
                        <td class="r money"><?= money($grandTotal) ?></td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <?php else: ?><p class="muted">Belum ada transaksi dengan data jenis usaha untuk periode ini.</p><?php endif; ?>
        </div>

        <?php if ($byModule):
            $moduleLabel = ['cl' => 'Commercial Leasing', 'atm' => 'ATM', 'adv' => 'Media & Advertising', 'exhibition' => 'Exhibition', 'gudang' => 'Gudang', 'media' => 'Media', 'event' => 'Event'];
            $moduleColor = ['cl' => '#0D9488', 'atm' => '#0891B2', 'adv' => '#7C3AED', 'exhibition' => '#F59E0B', 'gudang' => '#F97316', 'media' => '#7C3AED', 'event' => '#EF4444'];
            $modGrandTotal = array_sum(array_column($byModule, 'revenue'));
        ?>
        <div class="panel" style="margin-bottom:16px">
            <div style="font-weight:700;margin-bottom:12px">Revenue per Modul — <?= h($period) ?></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Modul</th><th class="r">Jml Client</th><th class="r">Jml Transaksi</th><th class="r">% Revenue</th><th class="r">Total Revenue</th></tr></thead>
                    <tbody>
                    <?php foreach ($byModule as $r):
                        $mc  = $moduleColor[$r['module']] ?? '#6366F1';
                        $ml  = $moduleLabel[$r['module']] ?? ucfirst($r['module']);
                        $pct = $modGrandTotal > 0 ? round((float)$r['revenue'] / $modGrandTotal * 100) : 0;
                    ?>
                    <tr>
                        <td><span style="background:<?= $mc ?>18;color:<?= $mc ?>;border:1px solid <?= $mc ?>44;border-radius:4px;padding:2px 10px;font-size:12px;font-weight:700"><?= h($ml) ?></span></td>
                        <td class="r"><?= $r['client_count'] ?></td>
                        <td class="r"><?= $r['trx_count'] ?></td>
                        <td class="r">
                            <div style="display:flex;align-items:center;gap:6px;justify-content:flex-end">
                                <div style="width:80px;background:#F1F5F9;border-radius:4px;height:6px">
                                    <div style="background:<?= $mc ?>;width:<?= $pct ?>%;height:6px;border-radius:4px"></div>
                                </div>
                                <span style="font-size:12px;min-width:28px;text-align:right"><?= $pct ?>%</span>
                            </div>
                        </td>
                        <td class="r money"><?= money($r['revenue']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="font-weight:700;border-top:2px solid var(--line)">
                        <td>Total</td>
                        <td class="r"><?= array_sum(array_column($byModule,'client_count')) ?></td>
                        <td class="r"><?= array_sum(array_column($byModule,'trx_count')) ?></td>
                        <td class="r">100%</td>
                        <td class="r money"><?= money($modGrandTotal) ?></td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($activityByType): ?>
        <div class="panel" style="margin-bottom:16px">
            <div style="font-weight:700;margin-bottom:12px">Aktif vs Dormant per Jenis Usaha — <?= h($currentYear) ?></div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Jenis Usaha</th>
                            <th class="r">Total</th>
                            <th class="r" style="color:#0D9488">Aktif</th>
                            <th class="r" style="color:#D97706">Dormant</th>
                            <th class="r">% Aktif</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $totAct = 0; $totDor = 0; $totAll = 0;
                    foreach ($activityByType as $r):
                        $act    = (int)$r['active_count'];
                        $tot    = (int)$r['total'];
                        $dor    = $tot - $act;
                        $actPct = $tot > 0 ? round($act / $tot * 100) : 0;
                        $totAct += $act; $totDor += $dor; $totAll += $tot;
                    ?>
                    <tr>
                        <td><?= h($r['business_type']) ?></td>
                        <td class="r"><?= $tot ?></td>
                        <td class="r" style="color:#0D9488;font-weight:600"><?= $act ?></td>
                        <td class="r" style="color:#D97706;font-weight:600"><?= $dor ?></td>
                        <td class="r">
                            <span style="font-weight:600;color:<?= $actPct >= 70 ? '#0D9488' : ($actPct >= 40 ? '#D97706' : '#DC2626') ?>"><?= $actPct ?>%</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr style="font-weight:700;border-top:2px solid var(--line)">
                        <td>Total</td>
                        <td class="r"><?= $totAll ?></td>
                        <td class="r" style="color:#0D9488"><?= $totAct ?></td>
                        <td class="r" style="color:#D97706"><?= $totDor ?></td>
                        <td class="r"><?= $totAll > 0 ? round($totAct / $totAll * 100) : 0 ?>%</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($freqDist): ?>
        <div class="panel" style="margin-bottom:16px">
            <div style="font-weight:700;margin-bottom:12px">Frekuensi Transaksi — <?= h($currentYear) ?></div>
            <?php
            $freqTotal = array_sum(array_column($freqDist, 'cnt'));
            foreach ($freqDist as $i => $f):
                $fpct = $freqTotal > 0 ? round($f['cnt'] / $freqTotal * 100) : 0;
            ?>
            <div style="margin-bottom:8px">
                <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px">
                    <span><?= h($f['bucket']) ?></span>
                    <span style="color:var(--muted)"><?= $f['cnt'] ?> client (<?= $fpct ?>%)</span>
                </div>
                <div style="background:#F1F5F9;border-radius:4px;height:8px">
                    <div style="background:<?= $colors[$i % count($colors)] ?>;width:<?= $fpct ?>%;height:8px;border-radius:4px;transition:.3s"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($atRiskClients): ?>
        <div class="panel" style="margin-bottom:16px">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                <div style="font-weight:700">At-Risk Clients <span style="font-size:12px;color:var(--muted);font-weight:400">— aktif <?= h($prevYear) ?>, belum transaksi <?= h($currentYear) ?></span></div>
                <span style="background:#FEF3C7;color:#92400E;border:1px solid #FCD34D;border-radius:20px;padding:2px 10px;font-size:11px;font-weight:600"><?= count($atRiskClients) ?> client</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>#</th><th>Nama Perusahaan</th><th>Brand</th><th>Jenis Usaha</th><th class="r">Revenue <?= h($prevYear) ?></th><th class="r">Terakhir Aktif</th></tr></thead>
                    <tbody>
                    <?php foreach ($atRiskClients as $i => $r): ?>
                    <tr>
                        <td style="color:var(--muted);font-size:12px"><?= $i + 1 ?></td>
                        <td style="font-weight:600"><?= h($r['company_name']) ?></td>
                        <td><?= h($r['brand_name'] ?? '-') ?></td>
                        <td><?= h($r['business_type'] ?? '-') ?></td>
                        <td class="r money"><?= money($r['prev_revenue']) ?></td>
                        <td class="r" style="font-size:12px"><?= h($r['last_period']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($topClients): ?>
        <div class="panel" style="margin-bottom:0">
            <div style="font-weight:700;margin-bottom:12px">Top 10 Client by Revenue — <?= h($period) ?></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>#</th><th>Nama Perusahaan</th><th>Brand</th><th>Jenis Usaha</th><th>Skala</th><th class="r">Jml Transaksi</th><th class="r">Revenue</th></tr></thead>
                    <tbody>
                    <?php foreach ($topClients as $i => $r): ?>
                    <tr>
                        <td style="color:var(--muted);font-size:12px"><?= $i + 1 ?></td>
                        <td style="font-weight:600"><?= h($r['company_name']) ?></td>
                        <td><?= h($r['brand_name'] ?? '-') ?></td>
                        <td><?= h($r['business_type'] ?? '-') ?></td>
                        <td><?= h($r['business_scale'] ?? '-') ?></td>
                        <td class="r"><?= $r['trx_count'] ?></td>
                        <td class="r money"><?= money($r['revenue']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($revRows):
            $chartColors = array_map(fn($i) => $colors[$i % count($colors)], range(0, count($revRows) - 1));
        ?>
        <script src="assets/chart.umd.min.js"></script>
        <script>
        (function(){
            var labels = <?= json_encode(array_column($revRows, 'business_type')) ?>;
            var data   = <?= json_encode(array_map(fn($r) => (float)$r['revenue'], $revRows)) ?>;
            var colors = <?= json_encode($chartColors) ?>;
            new Chart(document.getElementById('revChart'), {
                type: 'bar',
                data: { labels: labels, datasets: [{ label: 'Revenue', data: data, backgroundColor: colors, borderRadius: 4 }] },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: function(c){ return ' Rp ' + Number(c.parsed.x).toLocaleString('id-ID'); } } }
                    },
                    scales: {
                        x: { ticks: { callback: function(v){ return 'Rp ' + Number(v).toLocaleString('id-ID'); } } },
                        y: { grid: { display: false } }
                    }
                }
            });
        })();
        </script>
        <?php endif; ?>
        <?php
    });
}
