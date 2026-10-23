<?php
/**
 * Ganti nama pelaku di JEJAK: Super Admin → orang yang memang berwenang di situ.
 *
 * Super Admin mengerjakan banyak hal atas nama orang lain. Di jejak namanya
 * tercatat polos, sehingga "adil menolak" terbaca sama persis dengan pemeriksa
 * sungguhan yang menolak. Skrip ini menyamakan jejak yang SUDAH TERLANJUR ada
 * dengan nama orangnya.
 *
 * Tujuannya BEDA per alur, dan keduanya dibaca dari pengaturan — tidak ada nama
 * yang ditanam di kode, jadi kalau pengaturannya berubah skrip ini ikut benar:
 *
 *   Dokumen (SKP/SKS/Media, penawaran, transaksi)
 *       → Asst. Manager properti itu, dari Alur Approval.
 *         e-Walk: Ocha · Pentacity: Yusri Yusuf
 *
 *   Pengajuan Hapus Data
 *       → penyetuju hapus properti itu, dari Master Data → Penyetuju Hapus.
 *         Keduanya memakai jabatan Manager, yaitu Tengku.
 *         Memutus & menghapus memang wewenang Manager, bukan Asst. Manager.
 *
 * Kolom "diajukan oleh" pada Pengajuan Hapus Data TIDAK ikut dipindah: mengajukan
 * penghapusan bukan tindakan penyetuju, melainkan tindakan orang yang memegang
 * datanya. Kalau Super Admin yang mengajukan, jejaknya mengikuti aturan dokumen.
 *
 * YANG TIDAK DISENTUH — dan ini disengaja:
 *
 *   audit_logs          catatan forensik. Menimpa siapa pelakunya di sana sama
 *                       dengan menghapus pertanggungjawaban, bukan merapikan
 *                       tampilan. Di situ nama aslinya harus tetap apa adanya.
 *   *_flow, *_approver  tabel PENGATURAN (siapa menyusun alurnya), bukan jejak.
 *                       Tidak pernah tampil sebagai "X menolak".
 *   pic_name, cp_name,  bukan pelaku: PIC sales, penanggung jawab client, nama
 *   sign_name,          penanda tangan, nama perusahaan. Menggantinya akan
 *   requester_name      memindahkan income dan mengubah isi surat.
 *
 * Untuk tindakan BARU, kodenya sudah menulis "Adil (superadmin)" sendiri
 * (lihat clara_pelaku() di app/helpers.php) — jadi tindakan Super Admin ke depan
 * tidak lagi menyamar, dan skrip ini tidak perlu dijalankan berulang.
 *
 * Tanpa --terapkan hanya MELIHAT.
 *
 * Pakai:
 *   php scripts/ganti_nama_pelaku.php
 *   php scripts/ganti_nama_pelaku.php --prop=1
 *   php scripts/ganti_nama_pelaku.php --terapkan
 *
 *   --perbaiki-hapus   membetulkan jejak Pengajuan Hapus Data yang terlanjur
 *                      dipindah ke Asst. Manager oleh versi awal skrip ini.
 *                      Aman: memutus & menghapus adalah wewenang penyetuju
 *                      hapus, jadi nama Asst. Manager di kolom itu hanya bisa
 *                      berasal dari kekeliruan tersebut.
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';

$opt       = array_merge(['prop' => ''], getopt('', ['prop:', 'terapkan', 'perbaiki-hapus']));
$terapkan  = array_key_exists('terapkan', $opt);
$perbaiki  = array_key_exists('perbaiki-hapus', $opt);

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
 * Kolom JEJAK yang menyimpan nama pelaku.
 *
 * 'tujuan' menentukan siapa penggantinya: 'dokumen' = Asst. Manager properti,
 * 'hapus' = penyetuju Pengajuan Hapus Data. 'join' diisi hanya untuk tabel yang
 * tidak punya property_id sendiri.
 */
$PETA = [
    ['tabel' => 'skp_approvals',          'prop' => 't.property_id', 'tujuan' => 'dokumen', 'kolom' => ['approver_name']],
    ['tabel' => 'skp_documents',          'prop' => 't.property_id', 'tujuan' => 'dokumen', 'kolom' => ['created_by', 'updated_by', 'approved_by', 'deleted_by']],
    ['tabel' => 'skp_revision_requests',  'prop' => 't.property_id', 'tujuan' => 'dokumen', 'kolom' => ['requested_by', 'decided_by']],
    ['tabel' => 'skp_attachments',        'prop' => 'd.property_id', 'tujuan' => 'dokumen', 'kolom' => ['uploaded_by'],
     'join'  => 'JOIN skp_documents d ON d.id = t.skp_id'],
    ['tabel' => 'transactions',           'prop' => 't.property_id', 'tujuan' => 'dokumen', 'kolom' => ['created_by', 'updated_by', 'deleted_by', 'renewal_updated_by']],
    ['tabel' => 'transaction_pic_splits', 'prop' => 't.property_id', 'tujuan' => 'dokumen', 'kolom' => ['created_by']],
    ['tabel' => 'offers',                 'prop' => 't.property_id', 'tujuan' => 'dokumen', 'kolom' => ['created_by', 'updated_by', 'closed_by', 'deleted_by']],
    ['tabel' => 'offer_revisions',        'prop' => 'o.property_id', 'tujuan' => 'dokumen', 'kolom' => ['created_by'],
     'join'  => 'JOIN offers o ON o.id = t.offer_id'],
    ['tabel' => 'offer_area_tiers',       'prop' => 't.property_id', 'tujuan' => 'dokumen', 'kolom' => ['created_by']],
    ['tabel' => 'price_steps',            'prop' => 't.property_id', 'tujuan' => 'dokumen', 'kolom' => ['created_by']],
    ['tabel' => 'contract_requests',      'prop' => 't.property_id', 'tujuan' => 'dokumen', 'kolom' => ['created_by', 'legal_by']],
    // Mengajukan penghapusan = tindakan pemegang data, bukan penyetuju.
    ['tabel' => 'deletion_requests',      'prop' => 't.property_id', 'tujuan' => 'dokumen', 'kolom' => ['requested_by']],
    // Memutus, menghapus, memulihkan = wewenang penyetuju hapus.
    ['tabel' => 'deletion_requests',      'prop' => 't.property_id', 'tujuan' => 'hapus',   'kolom' => ['decided_by', 'executed_by', 'restored_by']],
];

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/** Nama pemegang satu jabatan di Alur Approval properti tertentu. */
$pemegang = static function (PDO $pdo, int $pid, string $jabatan): string {
    $st = $pdo->prepare("SELECT pic_name FROM skp_approval_flow
                          WHERE property_id = ? AND is_active = 1 AND role_name = ?
                            AND pic_name IS NOT NULL AND pic_name <> '' LIMIT 1");
    $st->execute([$pid, $jabatan]);
    return trim((string) ($st->fetchColumn() ?: ''));
};

// Tujuan untuk jejak DOKUMEN: Asst. Manager tiap properti.
$tujuanDok = [];
foreach ($pdo->query("SELECT property_id, pic_name FROM skp_approval_flow
                       WHERE is_active = 1 AND role_name = 'Asst. Manager'
                         AND pic_name IS NOT NULL AND pic_name <> ''") as $r) {
    $tujuanDok[(int) $r['property_id']] = trim((string) $r['pic_name']);
}

// Tujuan untuk jejak PENGAJUAN HAPUS DATA: penyetuju hapus tiap properti.
// Namanya boleh dikunci langsung (pic_name); kalau hanya jabatannya yang
// diatur, dicari siapa pemegang jabatan itu di Alur Approval properti sama.
$tujuanHapus = [];
foreach ($pdo->query('SELECT property_id, role_name, pic_name FROM deletion_approver') as $r) {
    $pid = (int) $r['property_id'];
    $nm  = trim((string) ($r['pic_name'] ?? ''));
    if ($nm === '') $nm = $pemegang($pdo, $pid, trim((string) ($r['role_name'] ?? '')));
    if ($nm !== '') $tujuanHapus[$pid] = $nm;
}

$namaProp = [];
foreach ($pdo->query('SELECT id, name FROM properties') as $r) $namaProp[(int) $r['id']] = (string) $r['name'];

echo "\nGANTI NAMA PELAKU DI JEJAK\n";
echo str_repeat('=', 92) . "\n";
if (!$tujuanDok) {
    fwrite(STDERR, "  Tidak ada tahap 'Asst. Manager' berisi nama di Alur Approval mana pun.\n"
                 . "  Isi dulu Master Data -> Alur Approval, supaya nama tujuannya jelas.\n\n");
    exit(1);
}
foreach ($tujuanDok as $pid => $nm) {
    printf("  Properti %d (%-26s) dokumen -> %-14s hapus data -> %s\n",
        $pid, $namaProp[$pid] ?? '?', $nm, $tujuanHapus[$pid] ?? '(belum diatur — dilewati)');
}
printf("  Nama yang diganti  : %s\n", implode(', ', $ALIAS));
printf("  Mode               : %s%s\n\n", $terapkan ? 'TERAPKAN' : 'hanya melihat',
    $perbaiki ? ' · termasuk perbaikan jejak Hapus Data' : '');

$total = 0;
$pdo->beginTransaction();
try {
    foreach ($PETA as $cfg) {
        $tabel = $cfg['tabel'];
        $join  = $cfg['join'] ?? '';
        $peta  = $cfg['tujuan'] === 'hapus' ? $tujuanHapus : $tujuanDok;
        foreach ($cfg['kolom'] as $kol) {
            foreach ($peta as $pid => $nama) {
                if ($opt['prop'] !== '' && (int) $opt['prop'] !== $pid) continue;

                // Sumber yang dicari. Untuk perbaikan jejak Hapus Data, nama
                // Asst. Manager ikut dicari — di kolom memutus/menghapus nama
                // itu hanya bisa berasal dari kekeliruan versi awal skrip ini.
                $sumber = $ALIAS;
                if ($perbaiki && $cfg['tujuan'] === 'hapus'
                    && isset($tujuanDok[$pid]) && $tujuanDok[$pid] !== $nama) {
                    $sumber[] = $tujuanDok[$pid];
                }

                foreach ($sumber as $alias) {
                    // Dicocokkan dari AWAL nama, bukan LIKE '%adil%': "Fadil"
                    // bukan orang ini. Keterangan dalam kurung ikut terbawa.
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

                    printf("  %-22s %-20s prop %d : %4d baris : %s -> %s\n",
                        $tabel, $kol, $pid, $n, $alias, $nama);
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
    $u = $pdo->prepare("SELECT id, name FROM users
                         WHERE role = 'superadmin'
                           AND BINARY name <> CONCAT(UPPER(LEFT(name,1)), SUBSTRING(name,2))");
    $u->execute();
    foreach ($u->fetchAll() as $us) {
        $rapi = mb_strtoupper(mb_substr($us['name'], 0, 1)) . mb_substr($us['name'], 1);
        printf("  %-22s %-20s        : %s -> %s\n", 'users', 'name (huruf besar)', $us['name'], $rapi);
        if ($terapkan) {
            $pdo->prepare('UPDATE users SET name = ? WHERE id = ?')->execute([$rapi, (int) $us['id']]);
        }
    }

    if ($terapkan) {
        audit($pdo, 'ganti_nama_pelaku', 'users', '0',
            ['alias' => $ALIAS, 'dokumen' => $tujuanDok, 'hapus_data' => $tujuanHapus, 'baris' => $total]);
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
