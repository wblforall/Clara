<?php

/**
 * Pengaturan Alur Approval dokumen (SKP / SKS / Form Utilities).
 *
 * Dulu SKP diparaf Asst. Manager dulu, baru naik ke Manager. Halaman ini
 * mengembalikan langkah itu — dan membuatnya bisa diatur sendiri, per properti,
 * tanpa menyentuh kode.
 *
 * Rantainya ditulis dengan JABATAN (diambil dari Master PIC), bukan nama orang,
 * supaya saat orangnya berganti alurnya tidak perlu diubah. Bila memang harus
 * dikunci ke satu orang, kolom "Khusus orang" boleh diisi.
 */

require_once dirname(__DIR__) . '/ApprovalLine.php';

/**
 * Properti yang boleh dilihat/diatur oleh pemakai ini — dipakai sebagai lingkup
 * pencarian jabatan dan sebagai sasaran saat alurnya diberlakukan ke semuanya.
 */
function _af_properti(): array
{
    $ids = array_map('intval', allowed_property_ids());
    return $ids ?: [current_property_id()];
}

/**
 * Daftar jabatan yang bisa dipakai di alur properti ini.
 *
 * Lingkupnya SELURUH properti yang boleh diakses, bukan properti ini saja.
 * Casual Leasing e-Walk & Pentacity dipegang tim yang sama: Manager dan Asst.
 * Manager-nya satu orang untuk dua mal, tetapi di Master PIC ia hanya terdaftar
 * di salah satunya. Kalau daftarnya dibatasi per properti, jabatan itu tidak
 * muncul sama sekali di mal yang lain dan alurnya mustahil disusun di sana.
 */
function _af_jabatan(PDO $pdo, int $pid): array
{
    $ids = _af_properti();
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT DISTINCT role_name FROM master_pic
                          WHERE property_id IN ($in) AND status = 'active'
                            AND COALESCE(role_name,'') <> '' ORDER BY role_name");
    $st->execute($ids);
    return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/**
 * Orang per jabatan — berikut apakah akunnya benar-benar BISA memparaf di sini.
 *
 * Jabatan di Master PIC, hak akses properti, dan izin approval adalah TIGA hal
 * terpisah. Seseorang bisa tercatat sebagai Asst. Manager di sini tetapi akunnya
 * tidak diberi akses ke properti ini, atau role-nya tidak memegang izin
 * "Approve SKP" — dua-duanya membuat tombol parafnya tidak pernah muncul dan
 * alurnya tersangkut tanpa ada yang tahu sebabnya. Jadi ketiga syarat itu
 * diperiksa dan kondisinya ditampilkan terang-terangan.
 */
function _af_orang(PDO $pdo, int $pid): array
{
    // can() memuliakan superadmin & admin tanpa melihat matrix, jadi dua role itu
    // dianggap selalu berizin — sama seperti perilaku can() yang sebenarnya.
    // PIC dicari di semua properti yang boleh diakses (lihat _af_jabatan),
    // TETAPI hak akses propertinya diperiksa terhadap properti yang sedang
    // diatur — bukan properti tempat baris PIC-nya terdaftar. Yusri boleh
    // terdaftar sebagai Asst. Manager di e-Walk, namun untuk memaraf dokumen
    // Pentacity akunnya tetap harus diberi akses ke Pentacity.
    $ids = _af_properti();
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT p.role_name, p.name, p.user_id, p.property_id,
                                u.id AS akun, u.status AS akun_status, u.role AS akun_role,
                                (up.user_id IS NOT NULL) AS boleh_properti,
                                (u.role IN ('superadmin','admin') OR rp.role IS NOT NULL) AS boleh_approve
                           FROM master_pic p
                           LEFT JOIN users u ON u.id = p.user_id
                           LEFT JOIN user_properties up ON up.user_id = p.user_id AND up.property_id = ?
                           LEFT JOIN role_permissions rp ON rp.role = u.role AND rp.permission = 'approve_skp'
                          WHERE p.property_id IN ($in) AND p.status = 'active'
                            AND COALESCE(p.role_name,'') <> ''
                          ORDER BY p.role_name, (p.property_id = ?) DESC, p.name");
    $st->execute(array_merge([$pid], $ids, [$pid]));
    $out = [];
    // Satu orang bisa punya baris PIC di dua properti dengan jabatan sama —
    // tampilkan sekali saja supaya dropdown tidak berisi nama kembar.
    $sudah = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $kunci = $r['role_name'] . '/' . $r['name'];
        if (isset($sudah[$kunci])) continue;
        $sudah[$kunci] = true;
        $out[$r['role_name']][] = $r;
    }
    return $out;
}

/** Orang pada jabatan ini yang benar-benar siap memparaf di properti ini. */
function _af_siap(array $orang, string $jabatan): array
{
    $siap = []; $kendala = [];
    foreach ($orang[$jabatan] ?? [] as $o) {
        if (!$o['akun'])                       { $kendala[] = $o['name'] . ' (belum punya akun)'; continue; }
        if (($o['akun_status'] ?? '') !== 'active') { $kendala[] = $o['name'] . ' (akun nonaktif)'; continue; }
        if (!$o['boleh_properti'])             { $kendala[] = $o['name'] . ' (akunnya belum diberi akses properti ini)'; continue; }
        // Tanpa izin Approve SKP, tombol parafnya tidak akan pernah muncul
        // (ApprovalLine::canAct mensyaratkannya) — jadi dia belum "siap".
        if (!$o['boleh_approve'])              { $kendala[] = $o['name'] . ' (role "' . ($o['akun_role'] ?: '-') . '" belum punya izin Approve SKP)'; continue; }
        $siap[] = $o['name'];
    }
    return ['siap' => $siap, 'kendala' => $kendala];
}

function approval_flow_page(PDO $pdo): void
{
    require_permission('manage_users');
    $pid   = current_property_id();
    $jenis = in_array(getv('doc_type'), ['skp', 'sks', 'fu'], true) ? getv('doc_type') : 'skp';

    $st = $pdo->prepare("SELECT * FROM skp_approval_flow
                          WHERE property_id = ? AND (doc_type = ? OR ((doc_type IS NULL OR doc_type = '') AND ? = 'skp'))
                          ORDER BY step_no ASC, id ASC");
    $st->execute([$pid, $jenis, $jenis]);
    $baris = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $jabatan = _af_jabatan($pdo, $pid);
    $orang   = _af_orang($pdo, $pid);
    // Penanggung jawab permintaan revisi (dokumen sudah disetujui tapi belum
    // ditandatangani client). Kosong = ikut tahap pertama alur persetujuan.
    $rv = $pdo->prepare("SELECT role_name, pic_name FROM skp_revision_flow
                          WHERE property_id = ? AND (doc_type = ? OR ((doc_type IS NULL OR doc_type = '') AND ? = 'skp'))
                          ORDER BY id ASC LIMIT 1");
    $rv->execute([$pid, $jenis, $jenis]);
    $revisi = $rv->fetch(PDO::FETCH_ASSOC) ?: ['role_name' => '', 'pic_name' => ''];
    $prop    = current_property();

    // Apakah pengaturan ini sudah sama persis di SEMUA properti? Kalau ya,
    // centang "berlaku untuk semua properti" ditampilkan sudah tercentang —
    // supaya menyimpan ulang tidak diam-diam memutus kesamaannya.
    $sidikAlur = function (int $q) use ($pdo, $jenis): string {
        $sx = $pdo->prepare("SELECT role_name, pic_name FROM skp_approval_flow
                              WHERE property_id = ? AND (doc_type = ? OR ((doc_type IS NULL OR doc_type = '') AND ? = 'skp'))
                              ORDER BY step_no ASC, id ASC");
        $sx->execute([$q, $jenis, $jenis]);
        return implode('|', array_map(
            fn($r) => (string) $r['role_name'] . '/' . (string) ($r['pic_name'] ?? ''),
            $sx->fetchAll(PDO::FETCH_ASSOC) ?: []
        ));
    };
    $sidikRev = function (int $q) use ($pdo, $jenis): string {
        $sx = $pdo->prepare("SELECT role_name, pic_name FROM skp_revision_flow
                              WHERE property_id = ? AND (doc_type = ? OR ((doc_type IS NULL OR doc_type = '') AND ? = 'skp'))
                              ORDER BY id ASC LIMIT 1");
        $sx->execute([$q, $jenis, $jenis]);
        $r = $sx->fetch(PDO::FETCH_ASSOC) ?: null;
        return $r ? ((string) $r['role_name'] . '/' . (string) ($r['pic_name'] ?? '')) : '';
    };
    $propSemua = _af_properti();
    $namaProp  = array_column(allowed_properties(), 'name');
    $banyakProp = count($propSemua) > 1;
    $lintasAlur = $banyakProp
        && count(array_unique(array_map($sidikAlur, $propSemua))) === 1
        && $sidikAlur($pid) !== '';
    $lintasRev  = $banyakProp
        && count(array_unique(array_map($sidikRev, $propSemua))) === 1
        && $sidikRev($pid) !== '';

    // Berapa dokumen yang sedang berjalan — mengubah alur saat ada dokumen di
    // tengah jalan harus disadari, bukan kejutan.
    $jalan = $pdo->prepare("SELECT COUNT(*) FROM skp_documents
                             WHERE property_id = ? AND status = 'submitted' AND doc_type = ?");
    $jalan->execute([$pid, $jenis]);
    $jalan = (int) $jalan->fetchColumn();

    layout('Alur Approval Dokumen', function () use ($baris, $jabatan, $orang, $jenis, $prop, $jalan, $revisi, $banyakProp, $namaProp, $lintasAlur, $lintasRev) {
        $label = ['skp' => 'SKP Pameran (Exhibition)', 'sks' => 'SKS Gudang', 'fu' => 'Form Utilities (Media)'];
        ?>
        <div class="panel">
            <h2 style="margin-top:0">Alur Approval Dokumen</h2>
            <p class="help" style="margin-top:0">Menentukan <strong>siapa memeriksa dokumen secara berurutan</strong> sebelum nomornya terbit.
               Alurnya ditulis dengan <strong>jabatan</strong>, bukan nama orang &mdash; jadi saat orangnya berganti, alur ini tidak perlu diubah.
               Berlaku untuk properti <strong><?= h($prop['name'] ?? '-') ?></strong>.</p>

            <div style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0">
                <?php foreach ($label as $k => $l): ?>
                <a class="btn <?= $k === $jenis ? '' : 'light' ?>" href="?r=approval_flow&doc_type=<?= $k ?>"><?= h($l) ?></a>
                <?php endforeach; ?>
            </div>

            <?php if (!$jabatan): ?>
            <div class="panel" style="background:#fef2f2;border:1px solid #fecaca">
                <p style="margin:0;color:#991b1b">Properti ini belum punya PIC aktif yang mengisi kolom <strong>Jabatan</strong> di Master PIC.
                   Isi dulu jabatannya di <a href="?r=master&type=pic">Master PIC</a>, baru alur approval bisa disusun.</p>
            </div>
            <?php else: ?>

            <?php if ($jalan > 0): ?>
            <div class="panel" style="background:#fffbeb;border:1px solid #fde68a">
                <p style="margin:0;color:#92400e">Ada <strong><?= $jalan ?> dokumen</strong> yang sedang menunggu persetujuan.
                   Bila urutan tahapnya Anda ubah, dokumen itu <strong>dikembalikan ke tahap 1</strong> dan harus diparaf ulang &mdash;
                   sebab paraf yang sudah masuk menilai urutan yang lama, dan memindahkannya begitu saja akan salah alamat.
                   Menyimpan tanpa mengubah apa pun tidak mengganggu dokumen yang berjalan.</p>
            </div>
            <?php endif; ?>

            <?php
            $buntu = [];
            foreach ($baris as $b2) {
                if (!($b2['role_name'] ?? '')) continue;
                if (!_af_siap($orang, $b2['role_name'])['siap']) $buntu[] = $b2['label'] ?: $b2['role_name'];
            }
            ?>
            <?php if ($buntu): ?>
            <div class="panel" style="background:#fef2f2;border:1px solid #fecaca">
                <p style="margin:0;color:#991b1b"><strong>Alur ini akan tersangkut.</strong> Tidak ada orang aktif yang bisa memparaf tahap:
                   <strong><?= h(implode(', ', $buntu)) ?></strong>. Pastikan orangnya punya akun, akunnya aktif,
                   akun itu diberi akses ke properti ini di <a href="?r=users">Users &amp; Role</a>,
                   dan role-nya memegang izin <strong>Approve SKP</strong> di <a href="?r=roles">Role &amp; Permission</a>.
                   Keterangan per baris di bawah menyebut kendalanya masing-masing.</p>
            </div>
            <?php endif; ?>
            <form method="post" action="?r=approval_flow_save">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="doc_type" value="<?= h($jenis) ?>">
                <table class="data" id="alur-tabel" style="width:100%;max-width:860px">
                    <thead><tr>
                        <th style="width:9%">Tahap</th>
                        <th style="width:27%">Jabatan Pemeriksa</th>
                        <th style="width:27%">Sebutan di layar</th>
                        <th style="width:27%">Khusus orang <span class="muted" style="font-weight:400">(opsional)</span></th>
                        <th style="width:10%"></th>
                    </tr></thead>
                    <tbody>
                    <?php $render = $baris ?: [['step_no' => 1, 'role_name' => '', 'label' => '', 'pic_name' => '']]; ?>
                    <?php foreach ($render as $i => $b): ?>
                    <tr>
                        <td style="text-align:center;font-weight:700" class="urut"><?= $i + 1 ?></td>
                        <td>
                            <select name="flow_role[]">
                                <option value="">&mdash; pilih jabatan &mdash;</option>
                                <?php foreach ($jabatan as $j): $sp = _af_siap($orang, $j); ?>
                                <option value="<?= h($j) ?>" <?= ($b['role_name'] ?? '') === $j ? 'selected' : '' ?>><?= h($j) ?> (<?= count($sp['siap']) ?> siap)</option>
                                <?php endforeach; ?>
                                <?php /* Jabatan yang sudah tidak ada lagi di Master PIC tetap
                                         ditampilkan. Tanpa ini, satu klik Simpan menghapus
                                         tahapnya diam-diam karena pilihannya terkirim kosong. */ ?>
                                <?php if (($b['role_name'] ?? '') !== '' && !in_array($b['role_name'], $jabatan, true)): ?>
                                <option value="<?= h($b['role_name']) ?>" selected><?= h($b['role_name']) ?> &mdash; sudah tidak ada di Master PIC aktif</option>
                                <?php endif; ?>
                            </select>
                            <?php $spIni = ($b['role_name'] ?? '') ? _af_siap($orang, $b['role_name']) : null; ?>
                            <?php if ($spIni): ?>
                                <?php if ($spIni['siap']): ?>
                                <div class="help" style="margin-top:3px">Dipegang: <?= h(implode(', ', $spIni['siap'])) ?></div>
                                <?php else: ?>
                                <div class="help" style="margin-top:3px;color:#b91c1c;font-weight:600">Tidak ada yang bisa memparaf tahap ini!</div>
                                <?php endif; ?>
                                <?php if ($spIni['kendala']): ?>
                                <div class="help" style="margin-top:2px;color:#92400e">Terkendala: <?= h(implode('; ', $spIni['kendala'])) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><input name="flow_label[]" value="<?= h($b['label'] ?? '') ?>" placeholder="mis. Verifikasi Asst. Manager"></td>
                        <td>
                            <select name="flow_pic[]">
                                <option value="">semua yang berjabatan itu</option>
                                <?php foreach ($orang as $jn => $list): foreach ($list as $o): ?>
                                <option value="<?= h($o['name']) ?>" <?= ($b['pic_name'] ?? '') === $o['name'] ? 'selected' : '' ?>><?= h($o['name']) ?> &mdash; <?= h($jn) ?></option>
                                <?php endforeach; endforeach; ?>
                            </select>
                        </td>
                        <td style="text-align:center"><button type="button" class="btn warn alur-hapus" style="padding:4px 9px;font-size:12px">&times;</button></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin:9px 0 0"><button type="button" class="btn light" id="alur-tambah" style="font-size:12.5px">+ Tambah Tahap</button></p>
                <div id="alur-ringkas" style="margin-top:10px;font-size:13px"></div>
                <?php if ($banyakProp): ?>
                <label style="display:flex;gap:8px;align-items:flex-start;margin-top:12px;padding:10px 12px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:6px;max-width:860px;cursor:pointer">
                    <input type="checkbox" name="semua_properti" value="1" style="margin-top:2px" <?= $lintasAlur ? 'checked' : '' ?>>
                    <span style="font-size:13px">Berlaku untuk <strong>semua properti</strong> (<?= h(implode(' &amp; ', $namaProp)) ?>)
                        <span class="help" style="display:block;margin-top:2px">Alur yang sama disimpan ke seluruh properti sekaligus, jadi tidak perlu diatur dua kali.
                        Lepas centangnya bila properti ini ingin dibuat berbeda.</span></span>
                </label>
                <?php endif; ?>
                <p style="margin-top:12px"><button type="submit">Simpan Alur</button>
                   <a class="btn secondary" href="?r=skp">Batal</a></p>
            </form>

            <div class="panel" style="margin-top:14px;border:1px solid #ddd6fe;background:#f5f3ff">
                <h3 style="margin-top:0;color:#5b21b6">Penanggung Jawab Permintaan Revisi</h3>
                <p class="help" style="margin-top:0">Dokumen yang <strong>sudah disetujui tetapi belum ditandatangani client</strong>
                   masih bisa diperbaiki &mdash; PIC mengajukan revisi, dan jabatan di bawah ini yang memutuskan.
                   Bila disetujui, dokumen terbuka kembali untuk PIC dengan <strong>nomor yang sama</strong>, lalu menempuh
                   alur persetujuan dari awal lagi. Dokumen yang sudah ditandatangani tidak bisa direvisi.</p>
                <form method="post" action="?r=approval_flow_save" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="doc_type" value="<?= h($jenis) ?>">
                    <input type="hidden" name="hanya_revisi" value="1">
                    <div>
                        <label style="font-size:12px;font-weight:700;display:block;margin-bottom:3px">Jabatan pemutus revisi</label>
                        <select name="revisi_role">
                            <option value="">&mdash; ikut tahap pertama alur persetujuan &mdash;</option>
                            <?php foreach ($jabatan as $j): ?>
                            <option value="<?= h($j) ?>" <?= ($revisi['role_name'] ?? '') === $j ? 'selected' : '' ?>><?= h($j) ?></option>
                            <?php endforeach; ?>
                            <?php if (($revisi['role_name'] ?? '') !== '' && !in_array($revisi['role_name'], $jabatan, true)): ?>
                            <option value="<?= h($revisi['role_name']) ?>" selected><?= h($revisi['role_name']) ?> &mdash; sudah tidak ada di Master PIC aktif</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:12px;font-weight:700;display:block;margin-bottom:3px">Khusus orang <span class="muted" style="font-weight:400">(opsional)</span></label>
                        <select name="revisi_pic">
                            <option value="">semua yang berjabatan itu</option>
                            <?php foreach ($orang as $jn => $list): foreach ($list as $o): ?>
                            <option value="<?= h($o['name']) ?>" <?= ($revisi['pic_name'] ?? '') === $o['name'] ? 'selected' : '' ?>><?= h($o['name']) ?> &mdash; <?= h($jn) ?></option>
                            <?php endforeach; endforeach; ?>
                        </select>
                    </div>
                    <?php if ($banyakProp): ?>
                    <label style="display:flex;gap:7px;align-items:center;font-size:12.5px;cursor:pointer">
                        <input type="checkbox" name="semua_properti" value="1" <?= $lintasRev ? 'checked' : '' ?>>
                        Berlaku untuk semua properti
                    </label>
                    <?php endif; ?>
                    <button type="submit">Simpan Pemutus Revisi</button>
                </form>
            </div>

            <div class="panel" style="margin-top:14px;background:#f8fafc">
                <h3 style="margin-top:0">Cara kerjanya</h3>
                <ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.7">
                    <li>Sales menekan <strong>Submit untuk Approval</strong> &rarr; dokumen masuk ke <strong>tahap 1</strong>.</li>
                    <li>Pemegang jabatan tahap 1 menekan <strong>Paraf &amp; Teruskan</strong>. <strong>Nomor dokumen belum terbit</strong> di tahap ini.</li>
                    <li>Dokumen naik ke tahap berikutnya, dan seterusnya.</li>
                    <li>Pada <strong>tahap terakhir</strong> barulah tombolnya berbunyi <strong>Setujui</strong> &mdash; saat itu nomor dokumen terbit, nilai dikunci, dan transaksinya masuk laporan.</li>
                    <li><strong>Ditolak di tahap mana pun</strong> &rarr; dokumen kembali ke sales dan harus menempuh alur dari tahap 1 lagi.</li>
                    <li>Atasan (jabatan di tahap yang lebih tinggi) boleh memparaf tahap di bawahnya bila yang bersangkutan berhalangan &mdash; tercatat sebagai &ldquo;mewakili&rdquo; di jejak persetujuan.</li>
                    <li><strong>Kosongkan seluruh tahap</strong> untuk kembali ke cara lama: satu langkah, langsung ke pemegang izin approval.</li>
                    <li><strong>Revisi</strong> hanya untuk dokumen yang sudah disetujui dan <strong>belum ditandatangani client</strong>. Nomor dokumennya tidak berubah; nomor revisinya bertambah, dan tautan tanda tangan lama dimatikan.</li>
                </ul>
            </div>
            <?php endif; ?>
        </div>

        <script>
        (function () {
            var tabel = document.getElementById('alur-tabel');
            if (!tabel) return;
            var ringkas = document.getElementById('alur-ringkas');
            function nomori() {
                var n = 0;
                tabel.querySelectorAll('tbody tr').forEach(function (tr) {
                    tr.querySelector('.urut').textContent = String(++n);
                });
                var urut = [];
                tabel.querySelectorAll('tbody tr').forEach(function (tr) {
                    var s = tr.querySelector('select[name^="flow_role"]');
                    var l = tr.querySelector('input[name^="flow_label"]');
                    if (s && s.value) urut.push((l && l.value.trim()) || s.value);
                });
                ringkas.innerHTML = urut.length
                    ? 'Alurnya: <b>Sales submit</b> &rarr; <b>' + urut.join('</b> &rarr; <b>') + '</b> &rarr; nomor dokumen terbit.'
                    : '<span class="muted">Belum ada tahap &mdash; dokumen langsung ke pemegang izin approval, seperti sebelumnya.</span>';
            }
            tabel.addEventListener('change', nomori);
            tabel.addEventListener('input', nomori);
            tabel.addEventListener('click', function (e) {
                if (!e.target.classList.contains('alur-hapus')) return;
                var rows = tabel.querySelectorAll('tbody tr');
                if (rows.length > 1) e.target.closest('tr').remove();
                else e.target.closest('tr').querySelectorAll('select,input').forEach(function (i) { i.value = ''; });
                nomori();
            });
            document.getElementById('alur-tambah').addEventListener('click', function () {
                var tr = tabel.querySelector('tbody tr').cloneNode(true);
                tr.querySelectorAll('select,input').forEach(function (i) { i.value = ''; });
                tabel.querySelector('tbody').appendChild(tr);
                nomori();
            });
            nomori();
        })();
        </script>
        <?php
    });
}

function approval_flow_save(PDO $pdo): void
{
    require_permission('manage_users');
    verify_csrf();
    $pid   = current_property_id();
    $jenis = in_array(post('doc_type'), ['skp', 'sks', 'fu'], true) ? post('doc_type') : 'skp';
    $uname = $_SESSION['user']['name'] ?? 'system';

    // Formulir pemutus revisi dikirim terpisah — jangan sampai menyentuh
    // rantai persetujuan (yang field-nya tidak ikut terkirim) dan menghapusnya.
    if (post('hanya_revisi') === '1') {
        $rRole = trim((string) post('revisi_role'));
        $rPic  = trim((string) post('revisi_pic'));
        if ($rRole !== '' && !in_array($rRole, _af_jabatan($pdo, $pid), true)) {
            flash('Jabatan "' . $rRole . '" tidak ada di Master PIC aktif properti ini. Pengaturan revisi tidak disimpan.');
            redirect_to('approval_flow', ['doc_type' => $jenis]);
        }
        $sasaranProp = post('semua_properti') === '1' ? _af_properti() : [$pid];
        $hapus = $pdo->prepare("DELETE FROM skp_revision_flow WHERE property_id = ?
                                 AND (doc_type = ? OR ((doc_type IS NULL OR doc_type = '') AND ? = 'skp'))");
        $tulis = $pdo->prepare('INSERT INTO skp_revision_flow (property_id, doc_type, role_name, pic_name, is_active, created_by)
                                VALUES (?,?,?,?,1,?)');
        foreach ($sasaranProp as $q) {
            $hapus->execute([$q, $jenis, $jenis]);
            if ($rRole !== '') {
                $tulis->execute([$q, $jenis, $rRole, $rPic !== '' ? $rPic : null, $uname]);
            }
        }
        audit($pdo, 'update', 'skp_revision_flow', $jenis, [
            'jabatan'  => $rRole ?: '(ikut tahap pertama)',
            'orang'    => $rPic ?: null,
            'properti' => $sasaranProp,
        ]);
        $cakupan = count($sasaranProp) > 1 ? ' Berlaku untuk ' . count($sasaranProp) . ' properti.' : '';
        flash(($rRole !== ''
            ? 'Permintaan revisi akan diputuskan oleh ' . $rRole . ($rPic !== '' ? ' (' . $rPic . ')' : '') . '.'
            : 'Permintaan revisi mengikuti tahap pertama alur persetujuan.') . $cakupan);
        redirect_to('approval_flow', ['doc_type' => $jenis]);
    }

    $peran = (array) ($_POST['flow_role'] ?? []);
    $label = (array) ($_POST['flow_label'] ?? []);
    $orang = (array) ($_POST['flow_pic'] ?? []);

    // Jabatan harus benar-benar ada di Master PIC properti ini — nama bebas dari
    // POST tidak boleh masuk, karena tidak akan pernah cocok dengan siapa pun
    // lalu dokumen tersangkut selamanya.
    $sah = _af_jabatan($pdo, $pid);
    // Lingkupnya ikut _af_jabatan(): seluruh properti yang boleh diakses. Kalau
    // dibatasi properti ini saja, nama yang baru saja dipilih dari dropdown
    // (mis. Asst. Manager yang terdaftar di mal sebelah) ditolak diam-diam dan
    // tahapnya tersimpan tanpa penguncian orang yang dimaksud.
    $idsProp = _af_properti();
    $sahOrang = $pdo->prepare("SELECT name FROM master_pic
                                WHERE property_id IN (" . implode(',', array_fill(0, count($idsProp), '?')) . ")
                                  AND status = 'active'");
    $sahOrang->execute($idsProp);
    $sahOrang = $sahOrang->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $tahap = [];
    $ditolak = [];
    foreach ($peran as $i => $r) {
        $r = trim((string) $r);
        if ($r === '') continue;
        // Jabatan yang sudah tidak ada di Master PIC TETAP disimpan — membuangnya
        // justru menghapus tahap yang masih dipakai. Yang perlu dilakukan adalah
        // memperingatkan, dan halaman ini sudah menampilkan "tidak ada yang bisa
        // memparaf tahap ini" di baris bersangkutan.
        if (!in_array($r, $sah, true)) $ditolak[] = $r;
        $pc = trim((string) ($orang[$i] ?? ''));
        if ($pc !== '' && !in_array($pc, $sahOrang, true)) $pc = '';
        $tahap[] = [
            'role'  => $r,
            'label' => trim((string) ($label[$i] ?? '')) ?: $r,
            'pic'   => $pc ?: null,
        ];
    }
    if ($ditolak) {
        flash('Perhatian: jabatan ' . implode(', ', array_unique($ditolak)) . ' tidak ada lagi di Master PIC aktif properti ini. Tahapnya tetap disimpan, tetapi belum ada yang bisa memparafnya — perbaiki di Master PIC atau ganti jabatannya.');
    }

    // Satu alur boleh diberlakukan ke beberapa properti sekaligus. Casual
    // Leasing e-Walk & Pentacity dijalankan tim yang sama, jadi mengatur alur
    // yang identik dua kali hanya menambah peluang keduanya jadi berbeda tanpa
    // disengaja. Tiap properti tetap diperiksa & diperbarui sendiri-sendiri.
    $sasaranProp = post('semua_properti') === '1' ? _af_properti() : [$pid];

    $sidik = fn(array $rows) => implode('|', array_map(fn($r) => ($r['role_name'] ?? $r['role']) . '/' . ($r['pic_name'] ?? $r['pic'] ?? ''), $rows));
    $lamaSt = $pdo->prepare("SELECT role_name, pic_name FROM skp_approval_flow
                              WHERE property_id = ? AND (doc_type = ? OR ((doc_type IS NULL OR doc_type = '') AND ? = 'skp'))
                              ORDER BY step_no ASC, id ASC");

    $pdo->beginTransaction();
    try {
        $hapus = $pdo->prepare("DELETE FROM skp_approval_flow WHERE property_id = ?
                                 AND (doc_type = ? OR ((doc_type IS NULL OR doc_type = '') AND ? = 'skp'))");
        $ins = $pdo->prepare('INSERT INTO skp_approval_flow
                              (property_id, step_no, role_name, label, pic_name, doc_type, is_active, created_by)
                              VALUES (?,?,?,?,?,?,1,?)');
        $diulang = 0;
        foreach ($sasaranProp as $q) {
            // Apakah rantainya benar-benar berubah DI PROPERTI INI? Menyimpan
            // ulang tanpa perubahan tidak boleh mengganggu dokumen berjalan —
            // dan dua properti bisa saja berangkat dari keadaan berbeda.
            $lamaSt->execute([$q, $jenis, $jenis]);
            $berubah = $sidik($lamaSt->fetchAll(PDO::FETCH_ASSOC) ?: []) !== $sidik($tahap);

            $hapus->execute([$q, $jenis, $jenis]);
            foreach ($tahap as $i => $t) {
                $ins->execute([$q, $i + 1, $t['role'], $t['label'], $t['pic'], $jenis, $uname]);
            }
            if (!$berubah) continue;

            // Dokumen yang sedang di tengah rantai LAMA dikembalikan ke tahap 1.
            // approval_level hanya menghitung BERAPA tahap yang sudah lewat,
            // bukan jabatan mana — kalau urutannya berubah, "sudah 1 tahap" bisa
            // berarti jabatan yang sama sekali berbeda, dan paraf orang lain
            // jadi salah alamat. Mengulang dari awal lebih sedikit ruginya
            // daripada dokumen yang terlihat sudah diperiksa padahal belum.
            $sasaran = $pdo->prepare("SELECT id FROM skp_documents
                                        WHERE property_id = ? AND status = 'submitted'
                                          AND approval_level > 0 AND doc_type = ?");
            $sasaran->execute([$q, $jenis]);
            $sasaran = $sasaran->fetchAll(PDO::FETCH_COLUMN) ?: [];
            if ($sasaran) {
                $pdo->prepare('UPDATE skp_documents SET approval_level = 0 WHERE id IN ('
                    . implode(',', array_fill(0, count($sasaran), '?')) . ')')->execute($sasaran);
                // Penanda putaran: tanpa ini, paraf dari urutan LAMA ikut
                // dibekukan ke snapshot dan tercetak di surat yang diserahkan ke
                // client, seolah memeriksa dokumen dengan alur yang sekarang.
                foreach ($sasaran as $sid) {
                    ApprovalLine::record($pdo, $q, (int) $sid, 0, null, 'ulang',
                        'Alur approval diubah — pemeriksaan diulang dari tahap 1');
                }
            }
            $diulang += count($sasaran);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    audit($pdo, 'update', 'skp_approval_flow', $jenis, [
        'tahap'    => array_map(fn($t) => $t['role'] . ($t['pic'] ? ' / ' . $t['pic'] : ''), $tahap),
        'properti' => $sasaranProp,
    ]);
    $cakupan = count($sasaranProp) > 1 ? ' Berlaku untuk ' . count($sasaranProp) . ' properti.' : '';
    flash(($tahap
        ? 'Alur approval disimpan: ' . count($tahap) . ' tahap (' . implode(' → ', array_column($tahap, 'label')) . ').'
        : 'Alur approval dikosongkan — dokumen kembali langsung ke pemegang izin approval.')
        . $cakupan
        . ($diulang > 0 ? ' ' . $diulang . ' dokumen yang sedang berjalan dikembalikan ke tahap 1 karena urutannya berubah.' : ''));
    redirect_to('approval_flow', ['doc_type' => $jenis]);
}
