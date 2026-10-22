<?php
/**
 * Cari — dan tambal — dokumen SKP/SKS/Form Utilities yang tidak punya client.
 *
 * Kotak Client di formulir adalah kotak PENCARIAN: kalau namanya diketik
 * tetapi tidak dipilih dari daftar, teksnya terlihat terisi sementara nilainya
 * kosong. Dulu client hanya diperiksa saat persetujuan, sehingga dokumen
 * seperti itu tetap naik ke antrean manager dan baru ketahuan di sana — oleh
 * orang yang tidak bisa membetulkannya.
 *
 * Celahnya sudah ditutup di app/pages/skp.php (submit kini menolak dokumen
 * tanpa client). Skrip ini untuk yang TERLANJUR ada.
 *
 * Menebak client-nya TIDAK dilakukan diam-diam. Yang ditawarkan hanya calon
 * yang bisa dipertanggungjawabkan: penyewa terakhir di unit yang sama pada
 * properti yang sama. Penetapannya tetap harus disebut sendiri lewat --client.
 *
 * Tanpa --terapkan hanya MELIHAT.
 *
 * Pakai:
 *   php scripts/cek_skp_tanpa_client.php
 *   php scripts/cek_skp_tanpa_client.php --prop=2
 *   php scripts/cek_skp_tanpa_client.php --skp=123 --client=293 --terapkan
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = dirname(__DIR__);
require_once $root . '/app/env.php';
require_once $root . '/app/Database.php';
require_once $root . '/app/helpers.php';

$opt = array_merge(['prop' => '', 'skp' => '', 'client' => ''],
    getopt('', ['prop:', 'skp:', 'client:', 'terapkan']));
$terapkan = array_key_exists('terapkan', getopt('', ['terapkan']));

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$rp = fn($v) => number_format((float) $v, 0, ',', '.');

// Dokumen tanpa client: baik di dokumennya sendiri maupun lewat transaksi /
// penawaran asalnya. COALESCE ketiganya — persis seperti yang dipakai daftar
// dokumen untuk menampilkan nama client, jadi yang tampil "-" di layar itulah
// yang muncul di sini.
$syarat = ['s.deleted_at IS NULL',
           'COALESCE(s.client_id, t.client_id, o.client_id) IS NULL'];
$args = [];
if ($opt['prop'] !== '') { $syarat[] = 's.property_id = ?'; $args[] = (int) $opt['prop']; }
if ($opt['skp']  !== '') { $syarat[] = 's.id = ?';          $args[] = (int) $opt['skp']; }

$st = $pdo->prepare(
    "SELECT s.id, s.property_id, s.skp_no, s.doc_type, s.status, s.master_code,
            s.start_date, s.end_date, s.pic_name, s.created_by, s.created_at,
            s.transaction_id, s.offer_id,
            COALESCE(s.master_code, t.master_code, o.master_code) kode
       FROM skp_documents s
       LEFT JOIN transactions t ON t.id = s.transaction_id
       LEFT JOIN offers o       ON o.id = s.offer_id
      WHERE " . implode(' AND ', $syarat) . "
      ORDER BY s.property_id, s.id DESC"
);
$st->execute($args);
$rows = $st->fetchAll();

echo "\nDOKUMEN TANPA CLIENT\n";
echo str_repeat('=', 100) . "\n";
if (!$rows) { echo "  Tidak ada. Semua dokumen punya client.\n\n"; exit(0); }
printf("  Ditemukan %d dokumen%s\n\n", count($rows), $terapkan ? ' · MODE TERAPKAN' : ' (hanya melihat)');

// Calon client: penyewa TERAKHIR di unit yang sama, properti yang sama.
// Hanya saran — tidak pernah dipakai tanpa disebut lewat --client.
$calon = $pdo->prepare(
    "SELECT t.client_id, COALESCE(c.brand_name, c.company_name) nama,
            MAX(t.start_date) terakhir
       FROM transactions t
       JOIN master_clients c ON c.id = t.client_id
      WHERE t.property_id = ? AND t.master_code = ? AND t.deleted_at IS NULL
        AND t.client_id IS NOT NULL
      GROUP BY t.client_id
      ORDER BY terakhir DESC
      LIMIT 3"
);

$docNama = ['skp' => 'SKP', 'sks' => 'SKS', 'fu' => 'Form Utilities'];
foreach ($rows as $r) {
    printf("  #%-5s %-15s %-7s %-9s %s s/d %s · %s · dibuat %s oleh %s\n",
        $r['id'], $r['skp_no'] ?: '(belum terbit)', $docNama[$r['doc_type']] ?? $r['doc_type'],
        $r['kode'] ?: '-', substr((string) $r['start_date'], 0, 10), substr((string) $r['end_date'], 0, 10),
        $r['status'], substr((string) $r['created_at'], 0, 10), $r['pic_name'] ?: $r['created_by']);

    if (!$r['kode']) { echo "      (unitnya pun kosong — tidak ada dasar untuk menebak)\n\n"; continue; }
    $calon->execute([(int) $r['property_id'], (string) $r['kode']]);
    $daftar = $calon->fetchAll();
    if (!$daftar) { echo "      (unit ini belum pernah disewa siapa pun — tidak ada calon)\n\n"; continue; }
    echo "      Penyewa terakhir di unit ini:\n";
    foreach ($daftar as $c) {
        printf("        client_id %-5s %-30s terakhir %s\n",
            $c['client_id'], mb_substr((string) $c['nama'], 0, 30), substr((string) $c['terakhir'], 0, 10));
    }
    if (!$terapkan) {
        printf("      Tambal: php scripts/cek_skp_tanpa_client.php --skp=%d --client=%s --terapkan\n\n",
            (int) $r['id'], $daftar[0]['client_id']);
    }
    echo $terapkan ? '' : '';
}

if (!$terapkan) {
    echo "  Jalankan ulang dengan --skp=ID --client=ID --terapkan untuk menambal satu dokumen.\n";
    echo "  Client-nya DISEBUT sendiri, tidak ditebak oleh skrip ini — salah tambal\n";
    echo "  berarti surat terbit atas nama perusahaan yang keliru.\n\n";
    exit(0);
}

if ($opt['skp'] === '' || $opt['client'] === '') {
    fwrite(STDERR, "Mode terapkan butuh --skp=ID dan --client=ID (satu dokumen sekali jalan).\n");
    exit(2);
}
$skpId    = (int) $opt['skp'];
$clientId = (int) $opt['client'];

$ck = $pdo->prepare('SELECT id, company_name, brand_name, property_id FROM master_clients WHERE id = ?');
$ck->execute([$clientId]);
$cl = $ck->fetch();
if (!$cl) { fwrite(STDERR, "Client #$clientId tidak ada di Master Client.\n"); exit(1); }

$doc = $rows[0] ?? null;
foreach ($rows as $r) if ((int) $r['id'] === $skpId) $doc = $r;
if (!$doc || (int) $doc['id'] !== $skpId) {
    fwrite(STDERR, "Dokumen #$skpId tidak ada dalam daftar tanpa-client — mungkin sudah punya client.\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE skp_documents SET client_id = ? WHERE id = ? AND property_id = ?')
        ->execute([$clientId, $skpId, (int) $doc['property_id']]);
    // Transaksinya ikut, kalau dokumen ini memang sudah melahirkan transaksi —
    // supaya laporan dan daftar modul menyebut nama yang sama.
    if (!empty($doc['transaction_id'])) {
        $pdo->prepare('UPDATE transactions SET client_id = ? WHERE id = ? AND client_id IS NULL')
            ->execute([$clientId, (int) $doc['transaction_id']]);
    }
    audit($pdo, 'tambal_client', 'skp_documents', (string) $skpId,
        ['client_id' => $clientId, 'nama' => $cl['brand_name'] ?: $cl['company_name']]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'GAGAL: ' . $e->getMessage() . " — tidak ada yang diubah.\n");
    exit(1);
}
printf("\n  Dokumen #%d sekarang atas nama: %s\n", $skpId, $cl['company_name']
    . ($cl['brand_name'] ? ' (' . $cl['brand_name'] . ')' : ''));
echo "  Buka dokumennya dan periksa isinya sebelum diteruskan ke approval.\n\n";
