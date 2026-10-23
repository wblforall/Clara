<?php
/**
 * Ganti nama pelaku di JEJAK dokumen: Super Admin → Asst. Manager propertinya.
 *
 * Super Admin mengerjakan banyak hal atas nama orang lain. Di jejak dokumen
 * namanya tercatat polos, sehingga "adil menolak" terbaca sama persis dengan
 * pemeriksa sungguhan yang menolak — padahal yang dimaksud adalah Asst. Manager
 * properti itu. Skrip ini menyamakan jejak yang SUDAH TERLANJUR ada dengan nama
 * orangnya: Ocha untuk e-Walk, Yusri Yusuf untuk Pentacity.
 *
 * Nama tujuannya TIDAK ditanam di kode: diambil dari Alur Approval tiap properti
 * (tahap Asst. Manager). Kalau alurnya diubah, skrip ini ikut benar sendiri.
 *
 * YANG TIDAK DISENTUH — dan ini disengaja:
 *
 *   audit_logs          catatan forensik. Menimpa siapa pelakunya di sana sama
 *                       dengan menghapus pertanggungjawaban, bukan merapikan
 *                       tampilan. Di situ nama aslinya harus tetap apa adanya.
 *   *_flow, *_approver  tabel PENGATURAN (siapa menyusun alurnya), bukan jejak
 *                       dokumen. Tidak pernah tampil sebagai "X menolak".
 *   pic_name, cp_name,  bukan pelaku: PIC sales, penanggung jawab client, nama
 *   sign_name,          penanda tangan, nama perusahaan. Menggantinya akan
 *   requester_name      memindahkan income dan mengubah isi surat.
 *
 * Untuk tindakan BARU, kodenya sudah menulis "Adil (superadmin)" sendiri
 * (lihat clara_pelaku() di app/helpers.php) — jadi tindakan Super Admin ke
 * depan tidak lagi menyamar sebagai Asst. Manager, dan skrip ini tidak perlu
 * dijalankan berulang.
 *
 * Tanpa --terapkan hanya MELIHAT.
 *
 * Pakai:
 *   php scripts/ganti_nama_pelaku.php
 *   php scripts/ganti_nama_pelaku.php --prop=1
 *   php scripts/ganti_nama_pelaku.php --terapkan
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';

$opt      = array_merge(['prop' => ''], getopt('', ['prop:', 'terapkan']));
$terapkan = array_key_exists('terapkan', getopt('', ['terapkan']));

/**
 * Nama-nama yang berarti "Super Admin ini".
 *
 * Jalur lama sempat mencatat ALAMAT EMAIL, bukan nama — baris seperti "dihapus
 * oleh saadilaeffendi@gmail.com" berasal dari situ. Orangnya sama, jadi ikut
 * diganti; dicantumkan terang-terangan supaya tidak ada yang tertukar diam-diam.
 *
 * Keterangan dalam kurung di belakang nama dipertahankan: "adil (reuse)" — berkas
 * yang dipakai ulang dari dokumen sebelumnya — menjadi "Ocha (reuse)", bukan
 * kehilangan keterangannya.
 */
$ALIAS = ['adil', 'saadilaeffendi@gmail.com'];

/**
 * Keterangan yang justru DITARUH oleh clara_pelaku() untuk menandai tindakan
 * Super Admin. Baris bertanda ini memang sengaja menyebut Super Admin, jadi
 * tidak boleh ikut diganti — kalau tidak, menjalankan skrip ini dua kali akan
 * menghapus penandaan yang baru saja dipasang.
 */
$JANGAN = [' (superadmin)', ' (lewat '];

/**
 * Kolom JEJAK yang menyimpan nama pelaku, beserta cara menemukan propertinya.
 * 'join' diisi hanya untuk tabel yang tidak punya property_id sendiri.
 */
$PETA = [
    'skp_approvals'         => ['prop' => 't.property_id', 'kolom' => ['approver_name']],
    'skp_documents'         => ['prop' => 't.property_id', 'kolom' => ['created_by', 'updated_by', 'approved_by', 'deleted_by']],
    'skp_revision_requests' => ['prop' => 't.property_id', 'kolom' => ['requested_by', 'decided_by']],
    'skp_attachments'       => ['prop' => 'd.property_id', 'kolom' => ['uploaded_by'],
                                'join' => 'JOIN skp_documents d ON d.id = t.skp_id'],
    'transactions'          => ['prop' => 't.property_id', 'kolom' => ['created_by', 'updated_by', 'deleted_by', 'renewal_updated_by']],
    'transaction_pic_splits'=> ['prop' => 't.property_id', 'kolom' => ['created_by']],
    'offers'                => ['prop' => 't.property_id', 'kolom' => ['created_by', 'updated_by', 'closed_by', 'deleted_by']],
    'offer_revisions'       => ['prop' => 'o.property_id', 'kolom' => ['created_by'],
                                'join' => 'JOIN offers o ON o.id = t.offer_id'],
    'offer_area_tiers'      => ['prop' => 't.property_id', 'kolom' => ['created_by']],
    'price_steps'           => ['prop' => 't.property_id', 'kolom' => ['created_by']],
    'deletion_requests'     => ['prop' => 't.property_id', 'kolom' => ['requested_by', 'decided_by', 'executed_by', 'restored_by']],
    'contract_requests'     => ['prop' => 't.property_id', 'kolom' => ['created_by', 'legal_by']],
];

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/** Nama Asst. Manager tiap properti, dari Alur Approval yang berlaku. */
$tujuan = [];
foreach ($pdo->query("SELECT property_id, pic_name FROM skp_approval_flow
                       WHERE is_active = 1 AND role_name = 'Asst. Manager'
                         AND pic_name IS NOT NULL AND pic_name <> ''") as $r) {
    $tujuan[(int) $r['property_id']] = trim((string) $r['pic_name']);
}
$namaProp = [];
foreach ($pdo->query('SELECT id, name FROM properties') as $r) $namaProp[(int) $r['id']] = (string) $r['name'];

echo "\nGANTI NAMA PELAKU DI JEJAK DOKUMEN\n";
echo str_repeat('=', 86) . "\n";
if (!$tujuan) {
    fwrite(STDERR, "  Tidak ada tahap 'Asst. Manager' berisi nama di Alur Approval mana pun.\n"
                 . "  Isi dulu Master Data -> Alur Approval, supaya nama tujuannya jelas.\n\n");
    exit(1);
}
foreach ($tujuan as $pid => $nm) {
    printf("  Properti %d (%s) -> %s\n", $pid, $namaProp[$pid] ?? '?', $nm);
}
printf("  Nama yang diganti  : %s\n", implode(', ', $ALIAS));
printf("  Mode               : %s\n\n", $terapkan ? 'TERAPKAN' : 'hanya melihat');

$total = 0;
$pdo->beginTransaction();
try {
    foreach ($PETA as $tabel => $cfg) {
        foreach ($cfg['kolom'] as $kol) {
            $join = $cfg['join'] ?? '';
            foreach ($tujuan as $pid => $nama) {
                if ($opt['prop'] !== '' && (int) $opt['prop'] !== $pid) continue;
                // Dicocokkan dari AWAL nama, bukan LIKE '%adil%': "Fadil" bukan
                // orang ini. Keterangan dalam kurung di belakangnya ikut terbawa.
                foreach ($ALIAS as $alias) {
                    $syarat = ["(t.`$kol` = ? OR t.`$kol` LIKE ?)", "{$cfg['prop']} = ?"];
                    $args   = [$alias, $alias . ' (%', $pid];
                    foreach ($JANGAN as $tanda) {
                        $syarat[] = "t.`$kol` NOT LIKE ?";
                        $args[]   = $alias . $tanda . '%';
                    }
                    $where = implode(' AND ', $syarat);

                    $q = $pdo->prepare("SELECT COUNT(*) FROM `$tabel` t $join WHERE $where");
                    $q->execute($args);
                    $n = (int) $q->fetchColumn();
                    if ($n === 0) continue;

                    printf("  %-24s %-20s prop %d : %4d baris : %s -> %s\n", $tabel, $kol, $pid, $n, $alias, $nama);
                    $total += $n;
                    if (!$terapkan) continue;

                    $u = $pdo->prepare("UPDATE `$tabel` t $join
                                           SET t.`$kol` = CONCAT(?, SUBSTRING(t.`$kol`, CHAR_LENGTH(?) + 1))
                                         WHERE $where");
                    $u->execute(array_merge([$nama, $alias], $args));
                }
            }
        }
    }

    // Nama akun Super Admin dirapikan hurufnya supaya jejak BARU terbaca
    // "Adil (superadmin)", bukan "adil (superadmin)". Hanya huruf besarnya;
    // orangnya, akunnya, dan hak aksesnya tidak berubah sama sekali.
    $u = $pdo->prepare("SELECT id, name FROM users WHERE role = 'superadmin' AND BINARY name <> CONCAT(UPPER(LEFT(name,1)), SUBSTRING(name,2))");
    $u->execute();
    foreach ($u->fetchAll() as $us) {
        $rapi = mb_strtoupper(mb_substr($us['name'], 0, 1)) . mb_substr($us['name'], 1);
        printf("  %-24s %-20s        : %s -> %s\n", 'users', 'name (huruf besar)', $us['name'], $rapi);
        if ($terapkan) {
            $pdo->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$rapi, (int) $us['id']]);
        }
    }

    if ($terapkan) {
        audit($pdo, 'ganti_nama_pelaku', 'users', '0', ['alias' => $ALIAS, 'tujuan' => $tujuan, 'baris' => $total]);
        $pdo->commit();
    } else {
        $pdo->rollBack();
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "\nGAGAL: " . $e->getMessage() . " — tidak ada yang diubah.\n\n");
    exit(1);
}

echo "\n";
if ($total === 0) {
    echo "  Tidak ada jejak atas nama Super Admin. Tidak ada yang perlu diganti.\n\n";
    exit(0);
}
printf("  %d baris%s\n", $total, $terapkan ? ' sudah diganti.' : ' akan diganti.');
if (!$terapkan) {
    echo "  Jalankan ulang dengan --terapkan untuk menerapkannya.\n";
}
echo "  audit_logs sengaja TIDAK ikut: di sana nama aslinya harus tetap tercatat.\n\n";
