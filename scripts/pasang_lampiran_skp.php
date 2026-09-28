<?php
/**
 * Pasang / ganti lampiran (scan KTP, NPWP, SIUP) pada satu dokumen SKP.
 *
 * Dipakai kalau berkasnya perlu diganti dari sisi server — misalnya sales
 * terlanjur mengunggah scan yang salah dan dokumennya sudah disubmit
 * sehingga formulirnya terkunci.
 *
 *   php scripts/pasang_lampiran_skp.php                       # daftar dokumen + lampirannya
 *   php scripts/pasang_lampiran_skp.php 12 ktp  ~/naik/ktp.png
 *   php scripts/pasang_lampiran_skp.php 12 ktp  ~/naik/ktp.png --apply
 *
 * Berkas disalin ke public/uploads/skp dengan nama acak; baris lampiran
 * lama untuk jenis yang sama dihapus (berkas lamanya ikut dihapus bila
 * tidak dipakai dokumen lain).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/Database.php';
$pdo = Database::connect();

$JENIS = ['ktp', 'npwp', 'siup', 'bukti_transfer', 'pengajuan'];

if (!isset($argv[1])) {
    echo "Dokumen SKP terbaru beserta lampirannya:\n\n";
    $q = $pdo->query(
        "SELECT s.id, s.status, COALESCE(tc.company_name, oc.company_name) client,
                COALESCE(t.master_code, o.master_code) kode,
                COALESCE(s.pic_name, o.pic_name, t.pic_name) pic
           FROM skp_documents s
           LEFT JOIN transactions t ON t.id = s.transaction_id
           LEFT JOIN offers o ON o.id = s.offer_id
           LEFT JOIN master_clients tc ON tc.id = t.client_id
           LEFT JOIN master_clients oc ON oc.id = o.client_id
          ORDER BY s.id DESC LIMIT 15"
    );
    $la = $pdo->prepare('SELECT kind, file_path FROM skp_attachments WHERE skp_id = ? ORDER BY kind');
    foreach ($q as $r) {
        printf("#%-4s %-11s %-10s %-26s %s\n", $r['id'], $r['status'], $r['kode'] ?: '-',
            mb_substr((string) $r['client'], 0, 26), $r['pic'] ?: '-');
        $la->execute([$r['id']]);
        $rows = $la->fetchAll();
        if (!$rows) { echo "        (belum ada lampiran)\n"; continue; }
        foreach ($rows as $a) printf("        %-16s %s\n", $a['kind'], $a['file_path']);
    }
    echo "\nPakai: php scripts/pasang_lampiran_skp.php <id> <" . implode('|', $JENIS) . "> <berkas> [--apply]\n";
    exit;
}

$id    = (int) $argv[1];
$kind  = strtolower(trim((string) ($argv[2] ?? '')));
$asal  = (string) ($argv[3] ?? '');
$apply = in_array('--apply', $argv, true);

if (!in_array($kind, $JENIS, true)) { fwrite(STDERR, "Jenis harus salah satu: " . implode(', ', $JENIS) . "\n"); exit(1); }
if ($asal === '' || !is_file($asal)) { fwrite(STDERR, "Berkas tidak ketemu: $asal\n"); exit(1); }

$ext = strtolower(pathinfo($asal, PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) { fwrite(STDERR, "Format $ext tidak didukung.\n"); exit(1); }
$ukuran = filesize($asal);
if ($ukuran <= 0 || $ukuran > 5 * 1024 * 1024) { fwrite(STDERR, "Ukuran berkas harus 1 byte–5 MB (sekarang " . round($ukuran / 1048576, 2) . " MB).\n"); exit(1); }

$st = $pdo->prepare(
    "SELECT s.id, s.status, s.skp_no, COALESCE(tc.company_name, oc.company_name) client,
            COALESCE(t.master_code, o.master_code) kode
       FROM skp_documents s
       LEFT JOIN transactions t ON t.id = s.transaction_id
       LEFT JOIN offers o ON o.id = s.offer_id
       LEFT JOIN master_clients tc ON tc.id = t.client_id
       LEFT JOIN master_clients oc ON oc.id = o.client_id
      WHERE s.id = ?"
);
$st->execute([$id]);
$skp = $st->fetch();
if (!$skp) { fwrite(STDERR, "SKP id $id tidak ada.\n"); exit(1); }

$la = $pdo->prepare('SELECT id, file_path, original_name FROM skp_attachments WHERE skp_id = ? AND kind = ?');
$la->execute([$id, $kind]);
$lama = $la->fetch();

echo "Dokumen : #{$skp['id']} " . ($skp['skp_no'] ?: '(nomor belum terbit)') . " · status {$skp['status']}\n";
echo "Client  : {$skp['client']} · {$skp['kode']}\n";
echo "Jenis   : $kind\n";
echo "Sekarang: " . ($lama ? $lama['file_path'] : '(belum ada)') . "\n";
echo "Diganti : " . basename($asal) . ' (' . round($ukuran / 1024) . " KB)\n\n";

if (!$apply) { echo "SIMULASI — tidak ada yang diubah. Tambahkan --apply untuk menyimpan.\n"; exit; }

$dir = $root . '/public/uploads/skp';
if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { fwrite(STDERR, "Gagal membuat folder $dir\n"); exit(1); }
$nama = 'skp' . $id . '_' . $kind . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
if (!@copy($asal, $dir . '/' . $nama)) { fwrite(STDERR, "Gagal menyalin berkas ke $dir\n"); exit(1); }
@chmod($dir . '/' . $nama, 0644);

$pdo->beginTransaction();
$pdo->prepare('DELETE FROM skp_attachments WHERE skp_id = ? AND kind = ?')->execute([$id, $kind]);
$pdo->prepare('INSERT INTO skp_attachments (skp_id, kind, file_path, original_name, uploaded_by) VALUES (?,?,?,?,?)')
    ->execute([$id, $kind, 'uploads/skp/' . $nama, substr(basename($asal), 0, 190), 'skrip:pasang_lampiran']);
$pdo->commit();

// Berkas lama dibuang hanya bila tidak dirujuk dokumen lain (scan bisa dipakai ulang).
if ($lama) {
    $pakai = $pdo->prepare('SELECT COUNT(*) FROM skp_attachments WHERE file_path = ?');
    $pakai->execute([$lama['file_path']]);
    if ((int) $pakai->fetchColumn() === 0) {
        $abs = $root . '/public/' . ltrim($lama['file_path'], '/');
        if (is_file($abs) && @unlink($abs)) echo "Berkas lama dihapus: {$lama['file_path']}\n";
    } else {
        echo "Berkas lama dibiarkan (masih dipakai dokumen lain): {$lama['file_path']}\n";
    }
}
echo "TERPASANG → uploads/skp/$nama\n";
