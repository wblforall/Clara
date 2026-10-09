<?php

/**
 * CLARA — pemeriksaan sebelum fitur Pengajuan Hapus Data dipakai di server.
 *
 * Menjawab empat hal yang harus benar sebelum diumumkan ke tim:
 *   1. tabelnya sudah ada (migrasi 070–072 sudah jalan)
 *   2. izin "Ajukan Hapus Data" & "Putuskan Pengajuan Hapus" sudah pada peran yang tepat
 *   3. pemutusnya sudah disetel per properti, dan orangnya memang ada
 *   4. tidak ada peran asing yang izinnya bisa hilang saat Role & Permission disimpan
 *
 * Tidak mengubah apa pun — hanya membaca dan melaporkan.
 *
 *   php scripts/cek_pengajuan_hapus.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('CLARA_ROOT', dirname(__DIR__));
require_once CLARA_ROOT . '/app/env.php';
require_once CLARA_ROOT . '/app/Database.php';

$pdo = Database::connect();
$masalah = [];
$ok = fn(string $s) => print("  OK    $s\n");
$no = function (string $s) use (&$masalah) { $masalah[] = $s; print("  PERLU $s\n"); };

echo "\n1. Tabel & kolom\n";
// Keberadaan tabel dicatat dulu. Bagian 3 & 5 membacanya, dan alat pemeriksa
// tidak boleh mati justru pada keadaan yang seharusnya dia laporkan.
$ada = [];
foreach (['deletion_requests', 'deletion_approver'] as $t) {
    $ada[$t] = (bool) $pdo->query("SHOW TABLES LIKE '$t'")->fetch();
    $ada[$t] ? $ok("tabel $t ada") : $no("tabel $t belum ada — jalankan: php db_migrate.php");
}
if ($ada['deletion_requests']) {
    foreach (['batch_no', 'restored_by', 'alokasi_pulih'] as $k) {
        (bool) $pdo->query("SHOW COLUMNS FROM deletion_requests LIKE '$k'")->fetchColumn()
            ? $ok("kolom $k ada")
            : $no("kolom deletion_requests.$k belum ada — jalankan: php db_migrate.php");
    }
}
foreach (['deleted_at', 'deleted_by'] as $k) {
    (bool) $pdo->query("SHOW COLUMNS FROM skp_documents LIKE '$k'")->fetchColumn()
        ? $ok("skp_documents.$k ada")
        : $no("skp_documents.$k belum ada — jalankan: php db_migrate.php");
}

echo "\n2. Izin per peran\n";
foreach (['request_delete' => 'Ajukan Hapus Data', 'approve_delete' => 'Putuskan Pengajuan Hapus'] as $izin => $label) {
    $q = $pdo->prepare('SELECT role FROM role_permissions WHERE permission = ? ORDER BY role');
    $q->execute([$izin]);
    $peran = $q->fetchAll(PDO::FETCH_COLUMN);
    $peran
        ? $ok(sprintf('%-26s %s', $label, implode(', ', $peran)))
        : $no("$label belum diberikan ke peran mana pun — centang di menu Role & Permission");
}
echo "        (superadmin & admin selalu boleh, tidak perlu dicentang)\n";

echo "\n3. Pemutus per properti\n";
$prop = $ada['deletion_approver'] ? $pdo->query('SELECT id, name FROM properties ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) : [];
if (!$ada['deletion_approver']) echo "  dilewati — tabelnya belum ada\n";
foreach ($prop as $p) {
    $q = $pdo->prepare('SELECT role_name, pic_name FROM deletion_approver WHERE property_id = ?');
    $q->execute([(int) $p['id']]);
    $set = $q->fetch(PDO::FETCH_ASSOC);
    if (!$set || trim((string) $set['role_name']) === '') {
        $no($p['name'] . ': pemutus belum disetel — pengajuan akan menggantung tanpa tujuan');
        continue;
    }
    // Jabatan yang disetel harus benar-benar dipegang seseorang, kalau tidak
    // pengajuannya tidak akan pernah bisa diputuskan siapa pun.
    $c = $pdo->prepare("SELECT COUNT(*) FROM master_pic
                         WHERE property_id = ? AND status = 'active' AND role_name = ?"
                       . (trim((string) $set['pic_name']) !== '' ? ' AND name = ?' : ''));
    $par = [(int) $p['id'], $set['role_name']];
    if (trim((string) $set['pic_name']) !== '') $par[] = $set['pic_name'];
    $c->execute($par);
    $jml = (int) $c->fetchColumn();
    $siapa = $set['role_name'] . (trim((string) $set['pic_name']) !== '' ? ' — ' . $set['pic_name'] : ' (semua yang berjabatan itu)');
    $jml > 0
        ? $ok(sprintf('%-22s %s — %d orang aktif', $p['name'], $siapa, $jml))
        : $no($p['name'] . ': disetel ke "' . $siapa . '" tapi TIDAK ADA PIC aktif yang cocok');
}

echo "\n4. Peran asing\n";
$peranIzin = $pdo->query('SELECT DISTINCT role FROM role_permissions')->fetchAll(PDO::FETCH_COLUMN);
$bisaDiedit = ['superadmin', 'admin', 'supervisor', 'sales', 'finance', 'administrasi', 'viewer'];
$asing = [];
foreach ($pdo->query('SELECT DISTINCT role FROM users')->fetchAll(PDO::FETCH_COLUMN) as $r) {
    if (!in_array($r, $bisaDiedit, true)) $asing[] = $r;
}
$asing
    ? $no('peran di luar daftar yang bisa diedit: ' . implode(', ', $asing)
          . ' — izinnya akan terhapus setiap kali halaman Role & Permission disimpan')
    : $ok('tidak ada peran asing');

echo "\n5. Isi pengajuan saat ini\n";
if (!$ada['deletion_requests']) {
    echo "  dilewati — tabelnya belum ada\n";
} else {
    $r = $pdo->query("SELECT status, COUNT(*) n FROM deletion_requests GROUP BY status ORDER BY status")->fetchAll(PDO::FETCH_ASSOC);
    if (!$r) echo "  belum ada pengajuan sama sekali\n";
    foreach ($r as $x) printf("  %-12s %d\n", $x['status'], (int) $x['n']);
    $sisa = (int) $pdo->query("SELECT COUNT(*) FROM deletion_requests WHERE status = 'disetujui'")->fetchColumn();
    if ($sisa > 0) $no("$sisa pengajuan berstatus 'disetujui' dari alur lama — tuntaskan di panel 'Sisa pengajuan alur lama'");
}

echo "\n" . str_repeat('-', 60) . "\n";
echo $masalah
    ? 'BELUM SIAP — ' . count($masalah) . " hal perlu dibereskan dulu:\n  · " . implode("\n  · ", $masalah) . "\n"
    : "SIAP DIPAKAI. Coba satu dokumen uji dulu (ajukan → setujui → pulihkan) sebelum diumumkan ke tim.\n";
exit($masalah ? 1 : 0);
