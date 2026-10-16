<?php
/**
 * Pemeriksa tanda tangan client — untuk menjawab satu pertanyaan saja:
 * dokumen ini MEMANG tidak ditandatangani, atau tanda tangannya hilang
 * karena sistem?
 *
 * Dipakai saat ada yang mempertanyakan dokumen tanpa TTD. Jangan menuduh
 * siapa pun sebelum menjalankan ini: ada empat sebab yang kelihatannya sama
 * di layar tetapi penyebabnya berbeda jauh —
 *
 *   1. Transaksinya dibuat SEBELUM aturan TTD berlaku (ttd_wajib_mulai).
 *      Dokumen memang tidak perlu TTD. Tidak ada yang salah.
 *   2. Status dijadikan DEAL / disetujui manual oleh orang dalam. signed_at
 *      ikut terisi, padahal client tidak pernah meneken apa pun.
 *   3. Client benar-benar meneken (ada jejak audit customer_sign), lalu
 *      dokumennya direvisi — revisi mengembalikan status ke draft dan
 *      mematikan link tanda tangannya. Itu memang aturannya.
 *   4. Ada jejak audit customer_sign TAPI kolom tanda tangannya kosong.
 *      Hanya kasus inilah yang betul-betul kesalahan sistem.
 *
 * Read-only: tidak menulis satu baris pun ke database.
 *
 * Pakai:  php scripts/cek_ttd.php "Nama Client"
 */

require __DIR__ . '/../app/env.php';
require __DIR__ . '/../app/Database.php';

$cari = trim((string) ($argv[1] ?? ''));
if ($cari === '') {
    fwrite(STDERR, "Pakai: php scripts/cek_ttd.php \"Nama Client\"\n");
    exit(2);
}

$pdo = Database::connect();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

/** Kolom sebuah tabel — dipakai supaya skrip ini tidak mati di skema yang beda. */
function kolom(PDO $pdo, string $tabel): array
{
    static $cache = [];
    if (isset($cache[$tabel])) return $cache[$tabel];
    try {
        $cache[$tabel] = array_column($pdo->query("SHOW COLUMNS FROM `$tabel`")->fetchAll(), 'Field');
    } catch (Throwable $e) {
        $cache[$tabel] = [];
    }
    return $cache[$tabel];
}

function punya(PDO $pdo, string $tabel, string $kol): bool
{
    return in_array($kol, kolom($pdo, $tabel), true);
}

function amb(array $r, string $k, string $kosong = '—')
{
    $v = $r[$k] ?? null;
    return ($v === null || $v === '') ? $kosong : $v;
}

function garis(string $judul = ''): void
{
    echo $judul === '' ? str_repeat('─', 78) . "\n"
                       : "\n" . $judul . "\n" . str_repeat('─', 78) . "\n";
}

echo "\n";
garis('PEMERIKSAAN TANDA TANGAN — "' . $cari . '"');

// ── 1. Sejak kapan TTD diwajibkan ────────────────────────────────────────────
$ttdMulai = '';
try {
    $ttdMulai = (string) ($pdo->query("SELECT value FROM settings WHERE `key`='ttd_wajib_mulai' LIMIT 1")
                              ->fetchColumn() ?: '');
} catch (Throwable $e) {
}
echo "Aturan TTD mulai berlaku : " . ($ttdMulai !== '' ? $ttdMulai : '(belum disetel — TTD tidak diwajibkan untuk siapa pun)') . "\n";
echo "Waktu pemeriksaan        : " . date('Y-m-d H:i:s') . "\n";

// ── 2. Cari clientnya ────────────────────────────────────────────────────────
$klien = [];
foreach (['name', 'company_name', 'client_name'] as $k) {
    if (!punya($pdo, 'clients', $k)) continue;
    $st = $pdo->prepare("SELECT * FROM clients WHERE `$k` LIKE ?");
    $st->execute(['%' . $cari . '%']);
    foreach ($st->fetchAll() as $r) $klien[$r['id']] = $r;
}
garis('CLIENT');
if (!$klien) {
    echo "  tidak ada baris di tabel clients yang namanya mengandung \"$cari\"\n";
} else {
    foreach ($klien as $r) {
        printf("  id %-5s prop %-3s %s\n", $r['id'], amb($r, 'property_id'),
            amb($r, 'name', amb($r, 'company_name')));
    }
}
$idKlien = array_keys($klien);

// ── 3. Kumpulkan dokumen yang terkait ────────────────────────────────────────
/** Ambil baris tabel yang terkait client ini — lewat client_id maupun nama yang diketik. */
function dokumen(PDO $pdo, string $tabel, array $idKlien, string $cari): array
{
    if (!kolom($pdo, $tabel)) return [];
    $syarat = [];
    $args   = [];
    if ($idKlien && punya($pdo, $tabel, 'client_id')) {
        $syarat[] = 'client_id IN (' . implode(',', array_fill(0, count($idKlien), '?')) . ')';
        $args     = array_merge($args, $idKlien);
    }
    foreach (['client_name', 'company_name', 'nama_client', 'pic_name'] as $k) {
        if (!punya($pdo, $tabel, $k)) continue;
        $syarat[] = "`$k` LIKE ?";
        $args[]   = '%' . $cari . '%';
    }
    if (!$syarat) return [];
    try {
        $st = $pdo->prepare("SELECT * FROM `$tabel` WHERE " . implode(' OR ', $syarat) . ' ORDER BY id');
        $st->execute($args);
        return $st->fetchAll();
    } catch (Throwable $e) {
        echo "  (gagal membaca $tabel: " . $e->getMessage() . ")\n";
        return [];
    }
}

$offers = dokumen($pdo, 'offers', $idKlien, $cari);
$trx    = dokumen($pdo, 'transactions', $idKlien, $cari);

// SKP: lewat transaksi / penawaran yang sudah ketemu, plus nama yang diketik langsung.
$skps = [];
$idTrx   = array_column($trx, 'id');
$idOffer = array_column($offers, 'id');
foreach ([['transaction_id', $idTrx], ['offer_id', $idOffer]] as [$kol, $ids]) {
    if (!$ids || !punya($pdo, 'skp_documents', $kol)) continue;
    try {
        $st = $pdo->prepare("SELECT * FROM skp_documents WHERE `$kol` IN ("
            . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) $skps[$r['id']] = $r;
    } catch (Throwable $e) {
        echo "  (gagal membaca skp_documents via $kol: " . $e->getMessage() . ")\n";
    }
}
foreach (dokumen($pdo, 'skp_documents', $idKlien, $cari) as $r) $skps[$r['id']] = $r;
ksort($skps);

// ── 4. Laporan per dokumen ───────────────────────────────────────────────────
/** Jejak audit sebuah dokumen, terutama customer_sign. */
function jejak(PDO $pdo, string $tabel, int $id): array
{
    try {
        $st = $pdo->prepare("SELECT action, user_name, actor, ip_address, created_at
                               FROM audit_logs
                              WHERE table_name = ? AND record_id = ?
                              ORDER BY id");
        $st->execute([$tabel, (string) $id]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function laporDok(PDO $pdo, string $tabel, array $d, string $ttdMulai, array $indukTrx = []): void
{
    $id  = (int) $d['id'];
    $no  = amb($d, 'skp_no', amb($d, 'offer_no', '(tanpa nomor)'));
    printf("\n  ▸ %s #%d  %s\n", $tabel, $id, $no);
    printf("      status            : %s\n", amb($d, 'status'));
    printf("      dibuat            : %s\n", amb($d, 'created_at'));
    if (array_key_exists('revisi_ke', $d)) printf("      revisi ke         : %s\n", (int) ($d['revisi_ke'] ?? 0));

    // Apakah dokumen ini terikat aturan TTD?
    $acuan = (string) ($indukTrx['created_at'] ?? $d['created_at'] ?? '');
    $terikat = ($ttdMulai !== '' && $acuan !== '' && substr($acuan, 0, 10) >= $ttdMulai);
    printf("      terikat aturan TTD: %s (acuan dibuat %s)\n",
        $terikat ? 'YA' : 'TIDAK', $acuan !== '' ? substr($acuan, 0, 10) : '?');

    // Keadaan tanda tangannya
    printf("      sign_method       : %s\n", amb($d, 'sign_method'));
    printf("      sign_name         : %s\n", amb($d, 'sign_name'));
    printf("      signed_at         : %s\n", amb($d, 'signed_at'));
    printf("      sign_ip           : %s\n", amb($d, 'sign_ip'));
    $punyaGambar = !empty($d['signature_data']);
    printf("      gambar TTD (data) : %s\n",
        $punyaGambar ? 'ADA (' . number_format(strlen((string) $d['signature_data'])) . ' karakter)' : 'TIDAK ADA');
    if (array_key_exists('signed_doc_path', $d)) {
        // Path tersimpan relatif ke web root, mis. 'uploads/skp/xxx.pdf'.
        $p = (string) ($d['signed_doc_path'] ?? '');
        $ada = $p !== '' && is_file(dirname(__DIR__) . '/public/' . ltrim($p, '/'));
        printf("      dokumen ber-TTD   : %s%s\n", $p !== '' ? $p : '—',
            $p !== '' ? ($ada ? '  [berkas ADA]' : '  [BERKAS HILANG DI DISK]') : '');
    }
    printf("      sign_token        : %s\n", !empty($d['sign_token']) ? 'aktif' : 'tidak ada / sudah dimatikan');
    if (array_key_exists('sign_token_expires_at', $d)) {
        $exp = (string) ($d['sign_token_expires_at'] ?? '');
        $hbs = $exp !== '' && strtotime($exp) !== false && strtotime($exp) < time();
        printf("      link berlaku s/d  : %s%s\n", $exp !== '' ? $exp : '— (tanpa batas)', $hbs ? '  [SUDAH KEDALUWARSA]' : '');
    }

    // Jejak audit — bukti yang tidak bisa dibantah
    $log = jejak($pdo, $tabel, $id);
    $ttdLog = array_values(array_filter($log, fn($r) => $r['action'] === 'customer_sign'));
    echo "      jejak audit       : " . (count($log) ? count($log) . ' baris' : 'KOSONG') . "\n";
    foreach ($log as $r) {
        printf("          %-19s %-18s %-22s %s\n",
            amb($r, 'created_at'), amb($r, 'action'), amb($r, 'user_name'), amb($r, 'ip_address'));
    }

    // ── Kesimpulan untuk dokumen ini ─────────────────────────────────────────
    $sudahTtd = !empty($d['signed_at']);
    $ttdAsli  = count($ttdLog) > 0 || $punyaGambar || (($d['sign_method'] ?? '') === 'wet' && !empty($d['signed_doc_path']));
    echo "      ──\n";
    if (count($ttdLog) > 0 && !$sudahTtd) {
        echo "      KESIMPULAN: KESALAHAN SISTEM. Ada jejak client meneken\n";
        echo "                  (customer_sign " . $ttdLog[0]['created_at'] . ") tetapi kolom tanda\n";
        echo "                  tangannya kosong. Penyebab yang mungkin: status dokumen\n";
        echo "                  berubah (disetujui ulang / direvisi) tepat di antara client\n";
        echo "                  membuka halaman dan menekan Simpan, sehingga UPDATE-nya\n";
        echo "                  tidak kena baris mana pun. Client TIDAK bersalah.\n";
    } elseif (count($ttdLog) > 0 && (int) ($d['revisi_ke'] ?? 0) > 0) {
        echo "      KESIMPULAN: Client PERNAH meneken, lalu dokumennya direvisi.\n";
        echo "                  Revisi memang mematikan tanda tangan — sesuai aturan.\n";
    } elseif ($ttdAsli) {
        echo "      KESIMPULAN: Ada tanda tangan client yang sah.\n";
    } elseif ($sudahTtd && !$ttdAsli) {
        echo "      KESIMPULAN: signed_at terisi TANPA tanda tangan client — status\n";
        echo "                  dijadikan DEAL/disetujui manual oleh orang dalam.\n";
        echo "                  Bukan kesalahan sistem.\n";
    } elseif (!$terikat) {
        echo "      KESIMPULAN: Dokumen ini TIDAK terikat aturan TTD (dibuat sebelum\n";
        echo "                  " . ($ttdMulai ?: '—') . "). Memang tidak perlu TTD — tidak ada yang salah.\n";
    } else {
        echo "      KESIMPULAN: Belum pernah ditandatangani client, dan tidak ada\n";
        echo "                  jejak percobaan meneken.\n";
        $exp = (string) ($d['sign_token_expires_at'] ?? '');
        if ($exp !== '' && strtotime($exp) !== false && strtotime($exp) < time()) {
            echo "                  CATATAN: link tanda tangannya sudah kedaluwarsa sejak\n";
            echo "                  " . substr($exp, 0, 16) . ". Kalau client membukanya setelah itu, dia\n";
            echo "                  hanya melihat pesan kedaluwarsa dan tidak meninggalkan jejak\n";
            echo "                  apa pun. Yang kurang di sini pengiriman link baru, bukan\n";
            echo "                  kesalahan client maupun bug.\n";
        } else {
            echo "                  Bukan kesalahan sistem — dokumennya memang belum diteken.\n";
        }
    }
}

garis('SURAT PENAWARAN');
if (!$offers) echo "  tidak ada\n";
foreach ($offers as $d) laporDok($pdo, 'offers', $d, $ttdMulai);

garis('SKP / SKS / FU');
if (!$skps) echo "  tidak ada\n";
$trxById = [];
foreach ($trx as $t) $trxById[$t['id']] = $t;
foreach ($skps as $d) {
    laporDok($pdo, 'skp_documents', $d, $ttdMulai, $trxById[$d['transaction_id'] ?? 0] ?? []);
}

garis('TRANSAKSI TERKAIT');
if (!$trx) echo "  tidak ada\n";
foreach ($trx as $t) {
    printf("  trx #%-6s prop %-3s dibuat %s  status %s\n",
        $t['id'], amb($t, 'property_id'), substr((string) amb($t, 'created_at', '?'), 0, 19), amb($t, 'status'));
}

garis();
echo "Selesai. Tidak ada data yang diubah.\n\n";
