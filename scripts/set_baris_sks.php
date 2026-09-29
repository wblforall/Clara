<?php
/**
 * Ubah satu baris pada tabel "Rincian Harga Sewa Gudang" (dokumen SKS) —
 * nominal totalnya dan/atau keterangannya. Dipakai kalau dokumennya sudah
 * terkunci sehingga formulirnya tidak bisa diedit.
 *
 *   php scripts/set_baris_sks.php 17
 *   php scripts/set_baris_sks.php 17 1 --total=3500000 --tambah-ket="Harga sudah termasuk diskon"
 *   php scripts/set_baris_sks.php 17 1 --total=3500000 --tambah-ket="..." --apply
 *
 *   <baris>            nomor baris (1 = baris pertama)
 *   --total=RP         Total Harga Sewa baris itu
 *   --harga-bulan=RP   Harga Sewa / Bulan baris itu
 *   --tambah-ket="…"   tambah satu baris baru di paling bawah Keterangan
 *   --apply            simpan; tanpa ini hanya simulasi
 *
 * Snapshot cetak ikut diperbarui supaya PDF-nya menampilkan angka yang sama.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/app/Database.php';
$pdo = Database::connect();

$rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

if (!isset($argv[1])) {
    fwrite(STDERR, "Pakai: php scripts/set_baris_sks.php <id_dokumen> [baris] [--total=RP] [--harga-bulan=RP] [--tambah-ket=\"…\"] [--apply]\n");
    exit(1);
}
$id    = (int) $argv[1];
$baris = isset($argv[2]) && ctype_digit((string) $argv[2]) ? (int) $argv[2] : 0;
$apply = in_array('--apply', $argv, true);
$total = null; $hargaBulan = null; $tambahKet = null;
foreach ($argv as $a) {
    if (preg_match('/^--total=(\d+)$/', $a, $m))       $total = (float) $m[1];
    if (preg_match('/^--harga-bulan=(\d+)$/', $a, $m)) $hargaBulan = (float) $m[1];
    if (preg_match('/^--tambah-ket=(.*)$/s', $a, $m))  $tambahKet = trim($m[1]);
}

$st = $pdo->prepare(
    "SELECT s.*, COALESCE(sc.company_name, tc.company_name) client
       FROM skp_documents s
       LEFT JOIN master_clients sc ON sc.id = s.client_id
       LEFT JOIN transactions t ON t.id = s.transaction_id
       LEFT JOIN master_clients tc ON tc.id = t.client_id
      WHERE s.id = ?"
);
$st->execute([$id]);
$skp = $st->fetch();
if (!$skp) { fwrite(STDERR, "Dokumen id $id tidak ada.\n"); exit(1); }
if (($skp['doc_type'] ?? '') !== 'sks') { fwrite(STDERR, "Dokumen #$id bukan SKS (doc_type={$skp['doc_type']}).\n"); exit(1); }

$detail = json_decode((string) $skp['detail_json'], true) ?: [];
$rows   = $detail['rows'] ?? [];

echo "Dokumen : #{$skp['id']} " . ($skp['skp_no'] ?: '(nomor belum terbit)') . " · status {$skp['status']}\n";
echo "Client  : {$skp['client']} · dibuat {$skp['created_by']}\n\n";
if (!$rows) { echo "Belum ada baris rincian harga.\n"; exit; }

$tampil = function (array $rows) use ($rp) {
    foreach ($rows as $i => $r) {
        printf("  [%d] %-10s luas %-7s /m² %-14s /bulan %-14s TOTAL %s\n",
            $i + 1, $r['lokasi'] ?? '-', $r['luas'] ?? '-',
            $rp($r['harga_m2'] ?? 0), $rp($r['harga_bulan'] ?? 0), $rp($r['total'] ?? 0));
        foreach (preg_split('/\R/', (string) ($r['keterangan'] ?? '')) as $k) {
            if (trim($k) !== '') echo "        · $k\n";
        }
    }
};
echo "Baris sekarang:\n"; $tampil($rows);

if ($baris < 1) {
    echo "\nSebutkan nomor barisnya untuk mengubah, mis.:\n";
    echo "  php scripts/set_baris_sks.php $id 1 --total=3500000 --tambah-ket=\"Harga sudah termasuk diskon\"\n";
    exit;
}
if (!isset($rows[$baris - 1])) { fwrite(STDERR, "Baris $baris tidak ada.\n"); exit(1); }
if ($total === null && $hargaBulan === null && $tambahKet === null) {
    fwrite(STDERR, "Tidak ada yang diubah — beri --total, --harga-bulan atau --tambah-ket.\n"); exit(1);
}

$baru = $rows;
$r = &$baru[$baris - 1];
if ($total !== null)      $r['total'] = $total;
if ($hargaBulan !== null) $r['harga_bulan'] = $hargaBulan;
if ($tambahKet !== null) {
    $ket = rtrim((string) ($r['keterangan'] ?? ''));
    $r['keterangan'] = $ket === '' ? $tambahKet : $ket . "\n" . $tambahKet;
}
unset($r);
echo "\nJadi:\n"; $tampil($baru);
echo "\n";
if (!$apply) { echo "SIMULASI — tidak ada yang diubah. Tambahkan --apply untuk menyimpan.\n"; exit; }

$detail['rows'] = $baru;
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE skp_documents SET detail_json = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ?')
        ->execute([json_encode($detail, JSON_UNESCAPED_UNICODE), 'skrip:set_baris_sks', $id]);
    // Cetakan dokumen yang sudah disetujui membaca snapshot, bukan detail_json.
    if (!empty($skp['snapshot_json'])) {
        $snap = json_decode((string) $skp['snapshot_json'], true);
        if (is_array($snap) && isset($snap['detail']['rows'])) {
            $snap['detail']['rows'] = $baru;
            $pdo->prepare('UPDATE skp_documents SET snapshot_json = ? WHERE id = ?')
                ->execute([json_encode($snap, JSON_UNESCAPED_UNICODE), $id]);
        }
    }
    $pdo->commit();
} catch (Throwable $e) { $pdo->rollBack(); throw $e; }

$st->execute([$id]);
$cek = json_decode((string) $st->fetch()['detail_json'], true)['rows'] ?? [];
echo "TERSIMPAN →\n"; $tampil($cek);
