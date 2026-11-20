<?php
declare(strict_types=1);

function users_page(PDO $pdo): void
{
    $rows = $pdo->query('SELECT id, name, email, role, status, must_change_password, last_login_at, created_at FROM users ORDER BY id')->fetchAll();
    $upRows = $pdo->query('SELECT up.user_id, p.name FROM user_properties up JOIN properties p ON p.id = up.property_id ORDER BY up.user_id, p.name')->fetchAll();
    $userProps = [];
    foreach ($upRows as $up) {
        $userProps[$up['user_id']][] = $up['name'];
    }
    // Baris Master PIC per akun. Akun tanpa baris ini bisa login tetapi tidak
    // bisa membuat dokumen atas namanya sendiri — keadaan yang sebelumnya hanya
    // ketahuan dari keluhan orangnya, bukan dari layar ini.
    $userPic = [];
    foreach ($pdo->query("SELECT user_id, name FROM master_pic WHERE user_id IS NOT NULL AND status = 'active' ORDER BY user_id, property_id")->fetchAll() as $mp) {
        $userPic[(int) $mp['user_id']][] = $mp['name'];
    }
    layout('Pengguna', function () use ($rows, $userProps, $userPic) {
        ?>
        <div class="toolbar"><a class="btn" href="?r=user_form">Tambah User</a></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>ID</th><th>Nama</th><th>Email</th><th>Role</th><th>Status</th><th>Properties</th><th>Master PIC</th><th>Password</th><th>Last Login</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= h((string) $row['id']) ?></td>
                        <td><?= h($row['name']) ?></td>
                        <td><?= h($row['email']) ?></td>
                        <td><span class="badge"><?= h(roles()[$row['role']] ?? $row['role']) ?></span></td>
                        <td><?= h($row['status']) ?></td>
                        <td style="font-size:12px;color:var(--muted)"><?= !empty($userProps[$row['id']]) ? h(implode(', ', $userProps[$row['id']])) : '<em>—</em>' ?></td>
                        <?php /* Hanya peran yang memang MEMBUAT dokumen yang ditandai kuning.
                                 Superadmin, viewer, dan finance tidak perlu baris PIC, dan menandai
                                 mereka hanya akan membuat peringatan ini diabaikan. */ ?>
                        <?php $picAkun = $userPic[(int) $row['id']] ?? []; ?>
                        <td style="font-size:12px">
                            <?php if ($picAkun): ?>
                                <?= h(implode(', ', $picAkun)) ?>
                            <?php elseif (in_array($row['role'], ['sales', 'asst_manager', 'supervisor', 'administrasi'], true) && ($row['status'] ?? '') === 'active'): ?>
                                <a href="?r=user_form&id=<?= h((string) $row['id']) ?>" class="badge" style="background:#fef3c7;color:#92400e;text-decoration:none"
                                   title="Belum punya baris Master PIC — namanya tidak muncul di pilihan PIC Sales pada Surat Penawaran, dan daftar dokumennya kosong. Klik untuk mendaftarkan.">⚠ belum terdaftar</a>
                            <?php else: ?>
                                <span style="color:var(--muted)">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $row['must_change_password'] ? '<span class="badge" style="background:#fef9c3;color:#92400e">Harus Ganti</span>' : '<span style="color:var(--muted);font-size:12px">OK</span>' ?></td>
                        <td><?= h($row['last_login_at'] ?? '-') ?></td>
                        <td style="white-space:nowrap">
                            <a class="btn light" href="?r=user_form&id=<?= h((string) $row['id']) ?>">Edit</a>
                            <?php /* "Lihat sebagai": memastikan hak akses orang lain tanpa meminjam
                                     akunnya. Tidak ditawarkan untuk Super Admin/Admin (tidak ada
                                     gunanya untuk pengujian) maupun akun nonaktif. */ ?>
                            <?php if (current_role() === 'superadmin'
                                      && !in_array($row['role'], ['superadmin', 'admin'], true)
                                      && ($row['status'] ?? '') === 'active'): ?>
                            <form method="post" action="?r=view_as" style="display:inline">
                                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                <input type="hidden" name="user_id" value="<?= h((string) $row['id']) ?>">
                                <button type="submit" class="btn light" style="font-size:12px"
                                        title="Lihat CLARA dari sudut pandang <?= h($row['name']) ?> &mdash; tanpa password, tanpa bisa mengubah apa pun">👁 Lihat sebagai</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    });
}

function user_form(PDO $pdo): void
{
    $id = getv('id');
    $row = ['role' => 'sales', 'status' => 'active', 'must_change_password' => 0];
    if ($id) {
        $stmt = $pdo->prepare('SELECT id, name, full_name, email, role, status, must_change_password FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch() ?: $row;
    }
    $allProperties = $pdo->query('SELECT id, name FROM properties WHERE status=\'active\' ORDER BY name')->fetchAll();
    $assignedPropIds = [];
    $izinTambahan = [];
    if ($id) {
        $apStmt = $pdo->prepare('SELECT property_id FROM user_properties WHERE user_id = ?');
        $apStmt->execute([$id]);
        $assignedPropIds = $apStmt->fetchAll(PDO::FETCH_COLUMN);
        $ipStmt = $pdo->prepare('SELECT permission FROM user_permissions WHERE user_id = ?');
        $ipStmt->execute([$id]);
        $izinTambahan = $ipStmt->fetchAll(PDO::FETCH_COLUMN);
    }
    // Baris Master PIC yang sudah tertaut ke akun ini. Satu orang bisa punya
    // baris di lebih dari satu properti (mis. Asst. Manager yang memegang
    // keduanya), jadi yang diambil semuanya — yang disunting di panel bawah
    // hanya yang pertama, sisanya cukup ditampilkan supaya terlihat ada.
    $picRows = [];
    if ($id) {
        $pcStmt = $pdo->prepare(
            'SELECT mp.id, mp.property_id, mp.name, mp.full_name, mp.role_name, mp.phone,
                    mp.commission_cat, mp.target_share, mp.status, mp.show_in_offer, mp.show_achievement,
                    p.name AS property_name
               FROM master_pic mp
               LEFT JOIN properties p ON p.id = mp.property_id
              WHERE mp.user_id = ? ORDER BY mp.property_id'
        );
        $pcStmt->execute([$id]);
        $picRows = $pcStmt->fetchAll();
    }
    $picUtama = $picRows[0] ?? [];
    // Izin yang SUDAH dimiliki perannya tidak perlu dicentang lagi di sini —
    // kotak ini khusus untuk kelebihan yang diberikan ke orangnya saja.
    $izinPeran = [];
    foreach ($pdo->query('SELECT role, permission FROM role_permissions')->fetchAll() as $rp) {
        $izinPeran[$rp['role']][] = $rp['permission'];
    }
    $izinPeranIni = $izinPeran[$row['role'] ?? ''] ?? [];
    layout(($id ? 'Edit' : 'Tambah') . ' User', function () use ($row, $id, $allProperties, $assignedPropIds, $izinTambahan, $izinPeranIni, $picRows, $picUtama) {
        ?>
        <form method="post" action="?r=user_save">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= h((string) $id) ?>">

            <?php /* ── Informasi Dasar ── */ ?>
            <div class="panel" style="margin-bottom:16px">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:14px">Informasi Dasar</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
                    <div><label>Nama</label><input name="name" required value="<?= field($row, 'name') ?>"><div class="help">Nama panggilan yang dipakai di seluruh CLARA.</div></div>
                    <div><label>Email</label><input type="email" name="email" required value="<?= field($row, 'email') ?>"></div>
                    <?php /* Nama lengkap TERPISAH dari Nama: "Nama" dipakai sebagai kunci
                             pencocokan (created_by, PIC, penyetuju) di banyak tempat, jadi
                             tidak boleh berubah. Yang tercetak di surat adalah yang ini. */ ?>
                    <div style="grid-column:1/-1"><label>Nama Lengkap</label><input name="full_name" value="<?= field($row, 'full_name') ?>" placeholder="dicetak di bawah tanda tangan surat"><div class="help">Dipakai di Surat Penawaran, SKP, SKS, dan Form Utilities. Kosong = memakai Nama di atas.</div></div>
                    <div>
                        <label>Role</label>
                        <select name="role">
                            <?php foreach (roles() as $key => $lbl): ?>
                                <option value="<?= h($key) ?>" <?= ($row['role'] ?? '') === $key ? 'selected' : '' ?>><?= h($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Status</label>
                        <select name="status">
                            <option value="active"   <?= ($row['status'] ?? 'active') === 'active'   ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= ($row['status'] ?? 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                </div>
            </div>

            <?php /* ── Password ── */ ?>
            <div class="panel" style="margin-bottom:16px">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:14px">Password</div>
                <?php if (!$id): ?>
                <div style="display:flex;align-items:flex-start;gap:12px;background:#f0fdfa;border:1px solid #99f6e4;border-radius:8px;padding:12px 14px">
                    <span style="font-size:20px;line-height:1">🔐</span>
                    <div style="font-size:13px;color:#134e4a;line-height:1.6">
                        Password default <strong>123456</strong> akan ditetapkan otomatis.<br>
                        User akan diminta mengganti password saat login pertama kali.
                    </div>
                </div>
                <?php else: ?>
                <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid var(--border,#e2e8f0);border-radius:8px;background:var(--bg)">
                    <input type="checkbox" name="reset_password" value="1" id="chk_reset" style="width:16px;height:16px;cursor:pointer;flex-shrink:0">
                    <label for="chk_reset" style="font-weight:400;font-size:13px;cursor:pointer;margin:0;line-height:1.5">
                        Reset password ke default <strong>123456</strong><br>
                        <span style="color:var(--muted);font-size:12px">User akan diminta ganti password saat login berikutnya</span>
                    </label>
                </div>
                <?php endif; ?>
            </div>

            <?php /* ── Akses Property ── */ ?>
            <?php if (!empty($allProperties)): ?>
            <div class="panel" style="margin-bottom:16px">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:14px">Akses Property</div>
                <div style="display:flex;flex-wrap:wrap;gap:10px">
                    <?php foreach ($allProperties as $prop): ?>
                    <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer;background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:6px;padding:8px 14px;font-size:13px">
                        <input type="checkbox" name="property_ids[]" value="<?= h((string) $prop['id']) ?>"
                            <?= in_array((string) $prop['id'], array_map('strval', $assignedPropIds)) ? 'checked' : '' ?>>
                        <?= h($prop['name']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="help" style="margin-top:8px">Superadmin selalu punya akses ke semua property.</div>
            </div>
            <?php endif; ?>

            <?php /* ── Master PIC: identitas penjualan orang ini ──────────────
                     Akun login dan baris Master PIC adalah DUA hal berbeda, dan
                     selama ini layar ini hanya membuat yang pertama. Akibatnya
                     orang baru bisa masuk tetapi namanya TIDAK ADA di pilihan
                     "PIC Sales (pembuat)" pada Surat Penawaran — kotak itu wajib
                     diisi, jadi satu-satunya jalan baginya adalah memakai nama
                     sales lain, yang membuat incomenya jatuh ke orang itu.
                     Daftar Penawaran/SKP/Transaksinya juga kosong, karena yang
                     terlihat olehnya dicari lewat tautan master_pic.user_id
                     (current_sales_scope di app/helpers.php), dan ia tidak
                     muncul di Laporan PIC maupun papan Achievement.
                     Dua-duanya sekarang dikerjakan sekaligus di sini. */ ?>
            <?php
            $picAda   = !empty($picUtama);
            // Panel dibuka sejak awal untuk SEMUA akun Sales, bukan hanya yang
            // baru: akun sales lama yang belum punya baris PIC justru keadaan
            // yang ditandai "belum terdaftar" di halaman Pengguna, dan orang
            // sampai ke layar ini karena menekan tanda itu.
            $picCek   = $picAda || ($row['role'] ?? '') === 'sales';
            $picPct   = $picAda ? rtrim(rtrim(number_format((float) $picUtama['target_share'] * 100, 2, '.', ''), '0'), '.') : '';
            $picProp  = (int) ($picUtama['property_id'] ?? 0);
            $komisi   = ['' => '— tidak dikategorikan —', 'sales' => 'Sales', 'manager' => 'Manager',
                         'asst_manager' => 'Asst. Manager', 'admin' => 'Admin', 'other' => 'Lainnya'];
            ?>
            <div class="panel" style="margin-bottom:16px">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:14px">Master PIC &mdash; identitas penjualan</div>

                <?php if (count($picRows) > 1): ?>
                <div class="help" style="margin-bottom:12px">
                    Akun ini punya <?= count($picRows) ?> baris Master PIC:
                    <?php foreach ($picRows as $i => $pr): ?><?= $i ? ' &middot; ' : '' ?><strong><?= h($pr['name']) ?></strong> (<?= h($pr['property_name'] ?? ('properti ' . $pr['property_id'])) ?>)<?php endforeach; ?>.
                    Yang bisa disunting di sini hanya yang pertama; sisanya lewat <a href="?r=master&type=pic">Master PIC</a>.
                </div>
                <?php endif; ?>

                <label style="display:flex;align-items:flex-start;gap:10px;font-weight:400;cursor:pointer;background:<?= $picAda ? '#f0fdf4' : '#fefce8' ?>;border:1px solid <?= $picAda ? '#bbf7d0' : '#fde68a' ?>;border-radius:8px;padding:12px 14px;margin:0 0 14px">
                    <input type="checkbox" name="pic_create" id="pic_create" value="1" style="width:16px;height:16px;flex-shrink:0;margin-top:2px" <?= $picCek ? 'checked' : '' ?>>
                    <span style="font-size:13px;line-height:1.6">
                        <strong>Daftarkan juga di Master PIC</strong> &mdash; wajib bagi siapa pun yang akan
                        <em>membuat</em> dokumen atas namanya sendiri.
                        <span style="display:block;color:var(--muted);margin-top:3px">
                            Tanpa baris ini akun tetap bisa login, tetapi namanya tidak muncul di pilihan
                            <strong>PIC Sales</strong> pada Surat Penawaran, daftar dokumennya kosong, dan ia
                            tidak ikut terhitung di Laporan PIC.
                            <?php if ($picAda): ?><br>Baris <strong><?= h($picUtama['name']) ?></strong> sudah ada &mdash; isian di bawah ikut memperbaruinya.<?php endif; ?>
                        </span>
                    </span>
                </label>

                <div id="pic_fields" style="display:grid;grid-template-columns:1fr 1fr;gap:14px<?= $picCek ? '' : ';display:none' ?>">
                    <div>
                        <label>Properti</label>
                        <select name="pic_property_id">
                            <?php foreach ($allProperties as $prop): ?>
                            <option value="<?= h((string) $prop['id']) ?>" <?= $picProp === (int) $prop['id'] ? 'selected' : '' ?>><?= h($prop['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="help">Harus termasuk yang dicentang di <strong>Akses Property</strong> di atas.</div>
                    </div>
                    <div>
                        <label>Nama PIC</label>
                        <input name="pic_name" id="pic_name" value="<?= h((string) ($picUtama['name'] ?? $row['name'] ?? '')) ?>" placeholder="ikut Nama di atas">
                        <div class="help">Kunci pencocokan di transaksi, alokasi, dan laporan &mdash; <strong>jangan diubah lagi</strong> setelah dipakai.</div>
                    </div>
                    <div>
                        <label>Jabatan</label>
                        <input name="pic_role_name" value="<?= h((string) ($picUtama['role_name'] ?? (($row['role'] ?? '') === 'sales' ? 'Sales' : ''))) ?>" placeholder="mis. Sales, Sales Executive">
                        <div class="help">Tercetak di bawah tanda tangan surat.</div>
                    </div>
                    <div>
                        <label>Nomor WhatsApp</label>
                        <input name="pic_phone" value="<?= h((string) ($picUtama['phone'] ?? '')) ?>" placeholder="08...">
                        <div class="help">Dicantumkan di Surat Penawaran sebagai kontak PIC.</div>
                    </div>
                    <div>
                        <label>Porsi Target (%)</label>
                        <input type="number" name="pic_target_share_pct" min="0" max="100" step="0.01" value="<?= h($picPct) ?>" placeholder="0">
                        <div class="help">Bagian dari target properti yang dibebankan ke orang ini. <strong>0 = tidak masuk papan Achievement.</strong></div>
                    </div>
                    <div>
                        <label>Kategori Komisi</label>
                        <select name="pic_commission_cat">
                            <?php foreach ($komisi as $k => $lbl): ?>
                            <option value="<?= h($k) ?>" <?= (string) ($picUtama['commission_cat'] ?? '') === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="grid-column:1/-1;display:flex;flex-wrap:wrap;gap:10px">
                        <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer;background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:6px;padding:8px 14px;font-size:13px">
                            <input type="checkbox" name="pic_show_in_offer" value="1" <?= (!$picAda || !empty($picUtama['show_in_offer'])) ? 'checked' : '' ?>>
                            Muncul di pilihan PIC Surat Penawaran
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:pointer;background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:6px;padding:8px 14px;font-size:13px">
                            <input type="checkbox" name="pic_show_achievement" value="1" <?= (!$picAda || !empty($picUtama['show_achievement'])) ? 'checked' : '' ?>>
                            Tampil di papan Achievement
                        </label>
                    </div>
                </div>
                <?php if ($picAda): ?>
                <div class="help" style="margin-top:10px">
                    Melepas centang di atas <strong>tidak</strong> menghapus baris yang sudah ada &mdash;
                    menonaktifkan PIC dilakukan di <a href="?r=master_form&type=pic&id=<?= (int) $picUtama['id'] ?>">Master PIC</a>.
                </div>
                <?php endif; ?>
            </div>

            <script>
            (function () {
                var cek = document.getElementById('pic_create');
                var box = document.getElementById('pic_fields');
                if (!cek || !box) return;
                cek.addEventListener('change', function () { box.style.display = cek.checked ? 'grid' : 'none'; });
                <?php if (!$id): ?>
                // Akun baru: nama PIC mengikuti Nama selama belum diketik sendiri,
                // supaya tidak ada dua ejaan untuk satu orang.
                var namaAkun = document.querySelector('input[name="name"]');
                var namaPic  = document.getElementById('pic_name');
                var diketik  = false;
                if (namaPic) namaPic.addEventListener('input', function () { diketik = true; });
                if (namaAkun && namaPic) namaAkun.addEventListener('input', function () {
                    if (!diketik) namaPic.value = namaAkun.value;
                });
                // Centang default mengikuti peran — sales hampir selalu perlu,
                // superadmin/viewer biasanya tidak. Berhenti ikut begitu
                // centangnya disentuh sendiri.
                var manual = false;
                cek.addEventListener('click', function () { manual = true; });
                var peran = document.querySelector('select[name="role"]');
                if (peran) peran.addEventListener('change', function () {
                    if (manual) return;
                    cek.checked = peran.value === 'sales';
                    box.style.display = cek.checked ? 'grid' : 'none';
                });
                <?php endif; ?>
            })();
            </script>

            <?php /* ── Izin Tambahan (per orang, bukan per peran) ── */ ?>
            <div class="panel" style="margin-bottom:16px">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:14px">Izin Tambahan</div>
                <div style="display:flex;flex-wrap:wrap;gap:10px">
                    <?php foreach (permission_labels() as $kode => $label): ?>
                    <?php $sudahDariPeran = in_array($kode, $izinPeranIni, true); ?>
                    <label style="display:flex;align-items:center;gap:6px;font-weight:400;cursor:<?= $sudahDariPeran ? 'default' : 'pointer' ?>;background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:6px;padding:8px 14px;font-size:13px;opacity:<?= $sudahDariPeran ? '.5' : '1' ?>">
                        <input type="checkbox" name="extra_permissions[]" value="<?= h($kode) ?>"
                            <?= $sudahDariPeran ? 'checked disabled' : (in_array($kode, $izinTambahan, true) ? 'checked' : '') ?>>
                        <?= h($label) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="help" style="margin-top:8px">
                    Hanya MENAMBAH izin untuk orang ini saja &mdash; tidak pernah mencabut apa yang sudah
                    diberikan perannya di halaman <strong>Hak Akses</strong>. Yang sudah menyala dari peran
                    ditampilkan abu-abu dan tidak bisa dimatikan di sini.
                </div>
            </div>

            <div style="display:flex;gap:10px">
                <button type="submit">Simpan User</button>
                <a class="btn secondary" href="?r=users">Kembali</a>
            </div>
        </form>
        <?php
    });
}

/**
 * Simpan izin tambahan milik satu orang.
 *
 * Hanya kode izin yang memang dikenal yang disimpan — kiriman formulir tidak
 * boleh membuat nama izin baru. Cache izin di sesi dibuang supaya perubahannya
 * langsung terasa, tanpa menunggu 5 menit.
 */
function _simpan_izin_tambahan(PDO $pdo, int $userId): void
{
    $dikenal = array_keys(permission_labels());
    $dipilih = array_values(array_intersect((array) (post('extra_permissions') ?: []), $dikenal));
    $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$userId]);
    if ($dipilih) {
        $ins = $pdo->prepare('INSERT INTO user_permissions (user_id, permission) VALUES (?, ?)');
        foreach ($dipilih as $kode) $ins->execute([$userId, $kode]);
    }
    unset($_SESSION['_perm_matrix'], $_SESSION['_perm_user'], $_SESSION['_perm_cache_at']);
}

/** Warna kotak PIC di Kalender Pameran. Sama dengan pilihan cepat di Master
 *  PIC; kuning sengaja tidak ada — itu warna Prospek. */
const CLARA_WARNA_PIC = ['#FBCFE8', '#D8DEE8', '#BBF7D0', '#FECACA', '#BFDBFE', '#DDD6FE', '#FED7AA', '#99F6E4'];

/** Warna pertama yang belum dipakai PIC lain di properti itu. */
function _warna_pic_bebas(PDO $pdo, int $pid): string
{
    $st = $pdo->prepare('SELECT color FROM master_pic WHERE property_id = ? AND color IS NOT NULL');
    $st->execute([$pid]);
    $dipakai = array_map('strtoupper', array_filter((array) $st->fetchAll(PDO::FETCH_COLUMN)));
    foreach (CLARA_WARNA_PIC as $w) {
        if (!in_array(strtoupper($w), $dipakai, true)) return $w;
    }
    return CLARA_WARNA_PIC[0];
}

/**
 * Apakah isian Master PIC pada formulir user layak disimpan?
 *
 * @return string|null pesan kesalahan untuk ditampilkan; null = aman
 */
function _periksa_master_pic(PDO $pdo, ?int $userId, array $propDicentang): ?string
{
    if (post('pic_create') !== '1') return null;

    $pid  = (int) post('pic_property_id', 0);
    $nama = trim((string) post('pic_name', '')) ?: trim((string) post('name', ''));

    if ($pid <= 0)   return 'Master PIC tidak disimpan: propertinya belum dipilih.';
    if ($nama === '') return 'Master PIC tidak disimpan: Nama PIC masih kosong.';

    // Akun yang tidak punya akses ke propertinya tidak akan pernah melihat
    // dokumen atas namanya sendiri — barisnya jadi ada tetapi tak berguna.
    if (!in_array($pid, $propDicentang, true)) {
        return 'Master PIC tidak disimpan: properti PIC harus ikut dicentang di Akses Property.';
    }

    $st = $pdo->prepare('SELECT id, user_id FROM master_pic WHERE property_id = ? AND name = ? LIMIT 1');
    $st->execute([$pid, $nama]);
    $ada = $st->fetch();
    if ($ada && (int) ($ada['user_id'] ?? 0) > 0 && (int) $ada['user_id'] !== (int) $userId) {
        return 'Nama PIC "' . $nama . '" sudah dipakai akun lain di properti itu. '
             . 'Pakai nama yang berbeda — nama PIC dipakai mencocokkan transaksi dan laporan, '
             . 'jadi tidak boleh dipegang dua orang.';
    }
    return null;
}

/**
 * Buat atau perbarui baris Master PIC milik satu akun.
 *
 * Tidak pernah MENGHAPUS: centang yang dilepas hanya berarti "jangan sentuh".
 * Menonaktifkan PIC adalah tindakan yang berakibat ke laporan, dan tempatnya
 * di Master PIC, bukan efek samping dari menyimpan akun.
 *
 * Baris senama yang belum bertuan DIAMBIL ALIH, bukan digandakan — PIC yang
 * terlanjur dibuat manual lebih dulu lalu akunnya menyusul adalah urutan yang
 * biasa terjadi, dan baris kedua dengan nama sama akan memecah riwayatnya.
 */
function _simpan_master_pic(PDO $pdo, int $userId, array $akun): void
{
    if (post('pic_create') !== '1' || $userId <= 0) return;

    $pid  = (int) post('pic_property_id', 0);
    $nama = trim((string) post('pic_name', '')) ?: trim((string) ($akun['name'] ?? ''));
    if ($pid <= 0 || $nama === '') return;

    $lengkap = trim((string) ($akun['full_name'] ?? ''));
    $komisi  = (string) post('pic_commission_cat', '');
    if (!in_array($komisi, ['', 'sales', 'manager', 'asst_manager', 'admin', 'other'], true)) $komisi = '';

    $kolom = [
        'property_id'      => $pid,
        'name'             => $nama,
        'full_name'        => $lengkap !== '' ? $lengkap : $nama,
        'role_name'        => trim((string) post('pic_role_name', '')) ?: null,
        'email'            => trim((string) ($akun['email'] ?? '')) ?: null,
        'phone'            => trim((string) post('pic_phone', '')) ?: null,
        'commission_cat'   => $komisi !== '' ? $komisi : null,
        'show_achievement' => post('pic_show_achievement') === '1' ? 1 : 0,
        'show_in_offer'    => post('pic_show_in_offer') === '1' ? 1 : 0,
        'target_share'     => max(0.0, min(100.0, (float) post('pic_target_share_pct', '0'))) / 100,
        'status'           => 'active',
        'user_id'          => $userId,
        'updated_at'       => date('Y-m-d H:i:s'),
    ];

    // Yang sudah tertaut ke akun ini didahulukan; baru sesudahnya baris senama
    // yang belum bertuan.
    $cari = $pdo->prepare(
        'SELECT * FROM master_pic WHERE property_id = ? AND (user_id = ? OR (name = ? AND user_id IS NULL))
          ORDER BY (user_id = ?) DESC, id ASC LIMIT 1'
    );
    $cari->execute([$pid, $userId, $nama, $userId]);
    $lama = $cari->fetch();

    if ($lama) {
        // Nama lengkap & email TIDAK diketik di panel ini, jadi yang sudah
        // terisi di Master PIC tidak boleh tertimpa hanya karena akunnya
        // disimpan ulang — keduanya tercetak di surat, dan bisa saja sengaja
        // dibuat berbeda dari data akun. Diisikan hanya bila masih kosong.
        if (trim((string) ($lama['full_name'] ?? '')) !== '') $kolom['full_name'] = $lama['full_name'];
        if (trim((string) ($lama['email'] ?? '')) !== '')     $kolom['email']     = $lama['email'];
        $sets = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($kolom)));
        $kolom['id'] = (int) $lama['id'];
        $pdo->prepare("UPDATE master_pic SET $sets WHERE id = :id")->execute($kolom);
        audit($pdo, 'update', 'master_pic', (string) $lama['id'], $kolom, $lama, 'users');
        return;
    }

    $kolom['color'] = _warna_pic_bebas($pdo, $pid);
    $cols   = implode(', ', array_keys($kolom));
    $params = ':' . implode(', :', array_keys($kolom));
    $pdo->prepare("INSERT INTO master_pic ($cols) VALUES ($params)")->execute($kolom);
    audit($pdo, 'create', 'master_pic', (string) $pdo->lastInsertId(), $kolom, [], 'users');
}

function user_save(PDO $pdo): void
{
    verify_csrf();
    // post() mengembalikan STRING. Dengan declare(strict_types=1) di berkas ini,
    // meneruskannya ke parameter bertipe int melempar TypeError — dan menyimpan
    // user yang SUDAH ADA berakhir layar putih "Fatal error", tepat setelah
    // barisnya terlanjur ter-update dan akses propertinya ditulis ulang. Yang
    // terlihat oleh admin: perubahan peran/status seperti gagal total, padahal
    // separuhnya sudah masuk. Dibulatkan sekali di sini.
    $id = (int) post('id', 0);
    $data = [
        'name'       => trim((string) post('name')),
        'full_name'  => trim((string) post('full_name')) ?: null,
        'email'      => trim((string) post('email')),
        'role'       => post('role', 'sales'),
        'status'     => post('status', 'active'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    if (!array_key_exists($data['role'], roles())) {
        $data['role'] = 'sales';
    }
    if (($data['status'] ?? '') !== 'active') {
        $data['status'] = 'inactive';
    }

    $submittedPropIds = array_map('intval', (array) ($_POST['property_ids'] ?? []));

    // Baris Master PIC diperiksa SEBELUM satu baris pun ditulis. Nama PIC
    // adalah kunci pencocokan di transaksi, alokasi, dan laporan — dua orang
    // dengan nama yang sama di satu properti akan memecah riwayat keduanya,
    // dan itu tidak bisa dirapikan belakangan tanpa menyentuh data lama.
    if ($salahPic = _periksa_master_pic($pdo, $id ? (int) $id : null, $submittedPropIds)) {
        flash($salahPic);
        redirect_to('user_form', array_filter(['id' => $id ?: null]));
    }

    if ($id) {
        if ((int) $id === (int) ($_SESSION['user']['id'] ?? 0) && $data['status'] !== 'active') {
            flash('User sedang login tidak boleh dinonaktifkan sendiri.');
            redirect_to('user_form', ['id' => $id]);
        }
        $beforeStmt = $pdo->prepare('SELECT id, name, full_name, email, role, status FROM users WHERE id = ?');
        $beforeStmt->execute([$id]);
        $before = $beforeStmt->fetch() ?: [];
        $sets = 'name=:name, full_name=:full_name, email=:email, role=:role, status=:status, updated_at=:updated_at';
        if (post('reset_password') === '1') {
            $data['password_hash']        = password_hash('123456', PASSWORD_DEFAULT);
            $data['must_change_password'] = 1;
            $sets .= ', password_hash=:password_hash, must_change_password=:must_change_password';
        }
        $data['id'] = $id;
        $stmt = $pdo->prepare("UPDATE users SET $sets WHERE id=:id");
        $stmt->execute($data);
        audit($pdo, 'update_user_role', 'users', (string) $id, $data, $before, 'users');
        $pdo->prepare('DELETE FROM user_properties WHERE user_id = ?')->execute([$id]);
        $upStmt = $pdo->prepare('INSERT INTO user_properties (user_id, property_id) VALUES (?, ?)');
        foreach ($submittedPropIds as $propId) {
            if ($propId > 0) { $upStmt->execute([$id, $propId]); }
        }
        _simpan_izin_tambahan($pdo, $id);
        _simpan_master_pic($pdo, (int) $id, $data);
    } else {
        $data['password_hash']        = password_hash('123456', PASSWORD_DEFAULT);
        $data['must_change_password'] = 1;
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, full_name, email, password_hash, role, status, must_change_password, updated_at)
             VALUES (:name, :full_name, :email, :password_hash, :role, :status, :must_change_password, :updated_at)'
        );
        $stmt->execute($data);
        $newUserId = (int) $pdo->lastInsertId();
        audit($pdo, 'create_user', 'users', (string) $newUserId, $data, [], 'users');
        $upStmt = $pdo->prepare('INSERT INTO user_properties (user_id, property_id) VALUES (?, ?)');
        foreach ($submittedPropIds as $propId) {
            if ($propId > 0) { $upStmt->execute([$newUserId, $propId]); }
        }
        _simpan_izin_tambahan($pdo, $newUserId);
        _simpan_master_pic($pdo, $newUserId, $data);
        redirect_to('user_created', ['id' => $newUserId]);
    }
    flash('User tersimpan.');
    redirect_to('users');
}

function user_created_page(PDO $pdo): void
{
    $id   = (int) getv('id', 0);
    $stmt = $pdo->prepare('SELECT name, email, role FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) { redirect_to('users'); }

    // Akun tanpa baris Master PIC bisa login tetapi tidak bisa membuat dokumen
    // atas namanya sendiri. Itu keadaan yang baru ketahuan berhari-hari
    // kemudian, lewat laporan "CLARA saya kosong" — jadi dikatakan di sini,
    // selagi orang yang membuatnya masih di layar ini.
    $pcStmt = $pdo->prepare(
        'SELECT mp.name, p.name AS property_name FROM master_pic mp
           LEFT JOIN properties p ON p.id = mp.property_id
          WHERE mp.user_id = ? AND mp.status = \'active\' ORDER BY mp.property_id'
    );
    $pcStmt->execute([$id]);
    $picAkun = $pcStmt->fetchAll();

    $appUrl = rtrim(env_value('APP_URL', '') ?: (
        ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST']
        . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/')
    ), '/') . '/';

    $waText = "Halo *{$user['name']}*,\n\n"
            . "Akun CLARA Anda telah dibuat. Berikut detail untuk login:\n\n"
            . "🔗 *Link:* {$appUrl}\n"
            . "📧 *Email:* {$user['email']}\n"
            . "🔑 *Password:* 123456\n\n"
            . "Silakan login dan ganti password Anda saat pertama kali masuk.\n\n"
            . "Untuk bantuan, hubungi IT Dept.";

    layout('Akun User Dibuat', function () use ($user, $waText, $id, $picAkun) {
        ?>
        <div class="panel" style="max-width:560px">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
                <div style="width:44px;height:44px;border-radius:12px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;font-size:22px">✅</div>
                <div>
                    <div style="font-weight:700;font-size:16px">Akun berhasil dibuat</div>
                    <div style="color:var(--muted);font-size:13px"><?= h($user['name']) ?> · <?= h($user['email']) ?></div>
                </div>
            </div>

            <?php if ($picAkun): ?>
            <div style="display:flex;align-items:flex-start;gap:10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:11px 14px;margin-bottom:16px;font-size:13px;line-height:1.6;color:#166534">
                <span style="flex-shrink:0">🧾</span>
                <span>Terdaftar di <strong>Master PIC</strong> sebagai
                    <?php foreach ($picAkun as $i => $pc): ?><?= $i ? ', ' : '' ?><strong><?= h($pc['name']) ?></strong> (<?= h($pc['property_name'] ?? '-') ?>)<?php endforeach; ?>.
                    Namanya sudah bisa dipilih di Surat Penawaran.</span>
            </div>
            <?php else: ?>
            <div style="display:flex;align-items:flex-start;gap:10px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:11px 14px;margin-bottom:16px;font-size:13px;line-height:1.6;color:#92400e">
                <span style="flex-shrink:0">⚠️</span>
                <span><strong>Belum terdaftar di Master PIC.</strong> Akun ini bisa login, tetapi namanya
                    tidak akan muncul di pilihan <strong>PIC Sales</strong> pada Surat Penawaran dan daftar
                    dokumennya akan kosong. Kalau orang ini memang akan membuat dokumen sendiri,
                    buka <a href="?r=user_form&id=<?= (int) $id ?>" style="color:#92400e;font-weight:700">Edit User</a>
                    lalu centang <em>Daftarkan juga di Master PIC</em>.</span>
            </div>
            <?php endif; ?>

            <div style="margin-bottom:12px;font-size:13px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.05em">Template WA</div>
            <div id="wa-box" style="background:var(--bg);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:14px 16px;font-size:13px;line-height:1.7;white-space:pre-wrap;font-family:inherit;color:var(--ink)"><?= h($waText) ?></div>

            <div style="display:flex;gap:10px;margin-top:14px">
                <button id="copy-btn" onclick="copyWa()" style="display:flex;align-items:center;gap:6px">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    Salin Teks WA
                </button>
                <a class="btn secondary" href="?r=users">Kembali ke Daftar User</a>
            </div>
        </div>
        <script>
        function copyWa() {
            var text = <?= json_encode($waText) ?>;
            navigator.clipboard.writeText(text).then(function() {
                var btn = document.getElementById('copy-btn');
                btn.textContent = '✓ Tersalin!';
                btn.style.background = 'var(--success, #16a34a)';
                btn.style.color = '#fff';
                setTimeout(function() {
                    btn.innerHTML = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> Salin Teks WA';
                    btn.style.background = '';
                    btn.style.color = '';
                }, 2000);
            });
        }
        </script>
        <?php
    });
}

function audit_page(PDO $pdo): void
{
    $pid    = current_property_id();
    $module = getv('module', '');
    if ($module !== '') {
        $stmt = $pdo->prepare('SELECT * FROM audit_logs WHERE module = ? AND property_id = ? ORDER BY id DESC LIMIT 300');
        $stmt->execute([$module, $pid]);
        $rows = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare('SELECT * FROM audit_logs WHERE property_id = ? ORDER BY id DESC LIMIT 300');
        $stmt->execute([$pid]);
        $rows = $stmt->fetchAll();
    }
    layout('Audit Log', function () use ($rows) {
        ?>
        <form class="toolbar" method="get">
            <input type="hidden" name="r" value="audit">
            <div style="max-width:260px"><label>Filter Modul</label><input name="module" value="<?= h(getv('module', '')) ?>" placeholder="contoh: master_media"></div>
            <button type="submit">Filter</button>
        </form>
        <div class="table-wrap"><table>
            <thead><tr><th>Waktu</th><th>User</th><th>Role</th><th>Modul</th><th>Aksi</th><th>Route</th><th>Tabel</th><th>Record</th><th>IP</th><th>Komputer</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td style="white-space:nowrap"><?= h($row['created_at']) ?></td>
                    <td><?= h($row['user_name'] ?? '') ?><?= $row['actor'] ? '<br><span style="font-size:11px;color:var(--muted)">' . h($row['actor']) . '</span>' : '' ?></td>
                    <td><?= h($row['user_role'] ?? '') ?></td>
                    <td><?= h($row['module'] ?? '') ?></td>
                    <td><?= h($row['action']) ?></td>
                    <td><?= h($row['route'] ?? '') ?></td>
                    <td><?= h($row['table_name']) ?></td>
                    <td><?= h($row['record_id']) ?></td>
                    <td style="font-family:monospace;font-size:12px"><?= h($row['ip_address'] ?? '') ?></td>
                    <td style="font-size:12px"><?= h($row['computer_name'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php
    });
}

function roles_page(PDO $pdo): void
{
    require_permission('manage_users');
    $allPermissions = permission_labels();
    $editableRoles = editable_roles();
    $current = [];
    foreach ($pdo->query('SELECT role, permission FROM role_permissions')->fetchAll() as $r) {
        $current[$r['role']][$r['permission']] = true;
    }
    layout('Hak Akses', function () use ($allPermissions, $editableRoles, $current) {
        ?>
        <form class="panel" method="post" action="?r=roles_save">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <p style="margin-top:0;color:var(--muted);font-size:13px">
                <strong>Super Admin</strong> selalu punya akses penuh. <strong>Lihat Dashboard</strong> selalu aktif untuk semua role.
            </p>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th style="min-width:120px">Role</th>
                            <?php foreach ($allPermissions as $perm => $label): ?>
                                <th style="text-align:center;font-size:12px"><?= h($label) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <tr style="background:#f8fafc">
                            <td><strong>Super Admin</strong></td>
                            <?php foreach ($allPermissions as $perm => $label): ?>
                                <td style="text-align:center"><input type="checkbox" checked disabled title="Selalu aktif"></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php foreach ($editableRoles as $role): ?>
                        <tr>
                            <td><?= h(roles()[$role] ?? $role) ?></td>
                            <?php foreach ($allPermissions as $perm => $label): ?>
                                <?php $locked = $perm === 'view_dashboard'; ?>
                                <?php $checked = $locked || !empty($current[$role][$perm]); ?>
                                <td style="text-align:center">
                                    <?php if ($locked): ?>
                                        <input type="checkbox" checked disabled title="Selalu aktif">
                                    <?php else: ?>
                                        <input type="checkbox" name="perms[<?= h($role) ?>][]" value="<?= h($perm) ?>" <?= $checked ? 'checked' : '' ?>>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="margin-top:14px"><button type="submit">Simpan Permission</button></p>
        </form>
        <?php
    });
}

function roles_save(PDO $pdo): void
{
    require_permission('manage_users');
    verify_csrf();

    $editableRoles  = editable_roles();
    $allPermissions = array_keys(permission_labels());

    $submitted = $_POST['perms'] ?? [];

    // HANYA peran yang benar-benar ada di layar ini yang dikosongkan. Dulu
    // seluruh baris selain superadmin dihapus, lalu diisi ulang hanya untuk
    // $editableRoles — artinya peran di luar daftar itu (mis. peran buatan
    // sendiri) kehilangan SELURUH izinnya setiap kali tombol Simpan ditekan,
    // diam-diam, tanpa pernah muncul di halaman ini.
    $tanya = implode(',', array_fill(0, count($editableRoles), '?'));
    $pdo->prepare("DELETE FROM role_permissions WHERE role IN ($tanya)")->execute($editableRoles);

    $stmt = $pdo->prepare('INSERT INTO role_permissions (role, permission) VALUES (?, ?)');
    foreach ($editableRoles as $role) {
        $stmt->execute([$role, 'view_dashboard']);
        foreach ($allPermissions as $perm) {
            if ($perm === 'view_dashboard') continue;
            if (!empty($submitted[$role]) && in_array($perm, (array) $submitted[$role], true)) {
                $stmt->execute([$role, $perm]);
            }
        }
    }

    $rows = $pdo->query('SELECT role, permission FROM role_permissions')->fetchAll();
    $matrix = [];
    foreach ($rows as $r) {
        $matrix[$r['role']][] = $r['permission'];
    }
    permission_matrix($matrix);
    unset($_SESSION['_perm_matrix'], $_SESSION['_perm_cache_at']);

    audit($pdo, 'update', 'role_permissions', null, $submitted);
    flash('Permission berhasil disimpan.');
    redirect_to('roles');
}
