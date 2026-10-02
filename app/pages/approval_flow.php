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

/** Daftar jabatan yang tersedia di properti ini (dari Master PIC). */
function _af_jabatan(PDO $pdo, int $pid): array
{
    $st = $pdo->prepare("SELECT DISTINCT role_name FROM master_pic
                          WHERE property_id = ? AND status = 'active'
                            AND COALESCE(role_name,'') <> '' ORDER BY role_name");
    $st->execute([$pid]);
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
    $st = $pdo->prepare("SELECT p.role_name, p.name, p.user_id,
                                u.id AS akun, u.status AS akun_status, u.role AS akun_role,
                                (up.user_id IS NOT NULL) AS boleh_properti,
                                (u.role IN ('superadmin','admin') OR rp.role IS NOT NULL) AS boleh_approve
                           FROM master_pic p
                           LEFT JOIN users u ON u.id = p.user_id
                           LEFT JOIN user_properties up ON up.user_id = p.user_id AND up.property_id = p.property_id
                           LEFT JOIN role_permissions rp ON rp.role = u.role AND rp.permission = 'approve_skp'
                          WHERE p.property_id = ? AND p.status = 'active'
                            AND COALESCE(p.role_name,'') <> '' ORDER BY p.role_name, p.name");
    $st->execute([$pid]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['role_name']][] = $r;
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
    $prop    = current_property();

    // Berapa dokumen yang sedang berjalan — mengubah alur saat ada dokumen di
    // tengah jalan harus disadari, bukan kejutan.
    $jalan = $pdo->prepare("SELECT COUNT(*) FROM skp_documents
                             WHERE property_id = ? AND status = 'submitted'");
    $jalan->execute([$pid]);
    $jalan = (int) $jalan->fetchColumn();

    layout('Alur Approval Dokumen', function () use ($baris, $jabatan, $orang, $jenis, $prop, $jalan) {
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
                <p style="margin-top:12px"><button type="submit">Simpan Alur</button>
                   <a class="btn secondary" href="?r=skp">Batal</a></p>
            </form>

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

    $peran = (array) ($_POST['flow_role'] ?? []);
    $label = (array) ($_POST['flow_label'] ?? []);
    $orang = (array) ($_POST['flow_pic'] ?? []);

    // Jabatan harus benar-benar ada di Master PIC properti ini — nama bebas dari
    // POST tidak boleh masuk, karena tidak akan pernah cocok dengan siapa pun
    // lalu dokumen tersangkut selamanya.
    $sah = _af_jabatan($pdo, $pid);
    $sahOrang = $pdo->prepare("SELECT name FROM master_pic WHERE property_id = ? AND status = 'active'");
    $sahOrang->execute([$pid]);
    $sahOrang = $sahOrang->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $tahap = [];
    $ditolak = [];
    foreach ($peran as $i => $r) {
        $r = trim((string) $r);
        if ($r === '') continue;
        if (!in_array($r, $sah, true)) { $ditolak[] = $r; continue; }
        $pc = trim((string) ($orang[$i] ?? ''));
        if ($pc !== '' && !in_array($pc, $sahOrang, true)) $pc = '';
        $tahap[] = [
            'role'  => $r,
            'label' => trim((string) ($label[$i] ?? '')) ?: $r,
            'pic'   => $pc ?: null,
        ];
    }
    if ($ditolak) {
        flash('Jabatan tidak dikenal di properti ini: ' . implode(', ', array_unique($ditolak)) . '. Baris itu tidak disimpan.');
    }

    // Apakah rantainya benar-benar berubah? Menyimpan ulang tanpa perubahan
    // tidak boleh mengganggu dokumen yang sedang berjalan.
    $lamaSt = $pdo->prepare("SELECT role_name, pic_name FROM skp_approval_flow
                              WHERE property_id = ? AND (doc_type = ? OR ((doc_type IS NULL OR doc_type = '') AND ? = 'skp'))
                              ORDER BY step_no ASC, id ASC");
    $lamaSt->execute([$pid, $jenis, $jenis]);
    $sidik = fn(array $rows) => implode('|', array_map(fn($r) => ($r['role_name'] ?? $r['role']) . '/' . ($r['pic_name'] ?? $r['pic'] ?? ''), $rows));
    $berubah = $sidik($lamaSt->fetchAll(PDO::FETCH_ASSOC) ?: []) !== $sidik($tahap);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM skp_approval_flow WHERE property_id = ?
                        AND (doc_type = ? OR ((doc_type IS NULL OR doc_type = '') AND ? = 'skp'))")
            ->execute([$pid, $jenis, $jenis]);
        $ins = $pdo->prepare('INSERT INTO skp_approval_flow
                              (property_id, step_no, role_name, label, pic_name, doc_type, is_active, created_by)
                              VALUES (?,?,?,?,?,?,1,?)');
        foreach ($tahap as $i => $t) {
            $ins->execute([$pid, $i + 1, $t['role'], $t['label'], $t['pic'], $jenis, $uname]);
        }
        // Dokumen yang sedang di tengah rantai LAMA dikembalikan ke tahap 1.
        // approval_level hanya menghitung BERAPA tahap yang sudah lewat, bukan
        // jabatan mana — kalau urutannya berubah, "sudah 1 tahap" bisa berarti
        // jabatan yang sama sekali berbeda, dan paraf orang lain jadi salah
        // alamat. Mengulang dari awal lebih sedikit ruginya daripada dokumen
        // yang terlihat sudah diperiksa padahal belum.
        $diulang = 0;
        if ($berubah) {
            $u = $pdo->prepare("UPDATE skp_documents SET approval_level = 0
                                 WHERE property_id = ? AND status = 'submitted'
                                   AND approval_level > 0 AND doc_type = ?");
            $u->execute([$pid, $jenis]);
            $diulang = $u->rowCount();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    audit($pdo, 'update', 'skp_approval_flow', $jenis,
        ['tahap' => array_map(fn($t) => $t['role'] . ($t['pic'] ? ' / ' . $t['pic'] : ''), $tahap)]);
    flash(($tahap
        ? 'Alur approval disimpan: ' . count($tahap) . ' tahap (' . implode(' → ', array_column($tahap, 'label')) . ').'
        : 'Alur approval dikosongkan — dokumen kembali langsung ke pemegang izin approval.')
        . ($diulang > 0 ? ' ' . $diulang . ' dokumen yang sedang berjalan dikembalikan ke tahap 1 karena urutannya berubah.' : ''));
    redirect_to('approval_flow', ['doc_type' => $jenis]);
}
