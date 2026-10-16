<?php
declare(strict_types=1);

/**
 * API baca-saja daftar gudang untuk OpsJobs (?r=api_gudang).
 *
 * Gudang dikelola Casual Leasing di CLARA; OpsJobs hanya menautkan meteran
 * listrik/air ke kode gudang dan menagihkan pemakaiannya ke penyewa yang
 * sedang berjalan. Yang dikirim: kode, gedung, lokasi, luas, status, dan
 * penyewa berjalan / berikutnya / terakhir (nama & masa sewa). Tarif dan
 * nilai transaksi TIDAK dikirim.
 *
 * Keamanan: token di header `X-Api-Token` (bukan query string, supaya tidak
 * tercatat di log akses), dicocokkan dengan OPSJOBS_API_TOKEN di .env —
 * boleh beberapa token dipisah koma untuk rotasi tanpa putus. Tanpa token
 * yang sah → 403. Hanya GET.
 */

function api_gudang_tokens(): array
{
    $out = [];
    foreach (array_map('trim', explode(',', (string) env_value('OPSJOBS_API_TOKEN', ''))) as $t) {
        if (strlen($t) >= 24) $out[] = $t; // token pendek/kosong tidak pernah sah
    }
    return $out;
}

function api_gudang_authorized(): bool
{
    $token = (string) ($_SERVER['HTTP_X_API_TOKEN'] ?? '');
    if ($token === '') return false;
    $ok = false;
    foreach (api_gudang_tokens() as $t) {
        if (hash_equals($t, $token)) $ok = true; // tanpa break: waktu tetap
    }
    return $ok;
}

function api_gudang(PDO $pdo): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        echo json_encode(['message' => 'Hanya GET.']);
        exit;
    }
    if (!api_gudang_authorized()) {
        http_response_code(403);
        echo json_encode(['message' => 'Token tidak sah.']);
        exit;
    }

    $gudang = $pdo->query(
        "SELECT g.id, p.`key` AS gedung, g.code AS kode, g.location AS lokasi, g.name AS nama,
                g.area_sqm AS luas_m2, g.status, g.property_id
           FROM master_gudang g JOIN properties p ON p.id = g.property_id
          ORDER BY p.`key`, g.sort_order, g.code"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Sewa gudang (modul gudang), yang belum dihapus. Satu query, dipilah per gudang.
    $sewa = $pdo->query(
        "SELECT t.id, t.property_id, t.master_code, t.start_date, t.end_date, t.client_id,
                COALESCE(NULLIF(c.brand_name, ''), c.company_name) AS penyewa, c.company_name AS perusahaan
           FROM transactions t LEFT JOIN master_clients c ON c.id = t.client_id
          WHERE t.module = 'gudang' AND t.deleted_at IS NULL
          ORDER BY t.start_date"
    )->fetchAll(PDO::FETCH_ASSOC);
    $per = [];
    foreach ($sewa as $s) $per[$s['property_id'] . '|' . $s['master_code']][] = $s;

    $hariIni = (new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar')))->format('Y-m-d');
    $bentuk = static fn(?array $s): ?array => $s ? [
        'transaksi_id' => (int) $s['id'],
        'klien_id'     => $s['client_id'] !== null ? (int) $s['client_id'] : null,
        'penyewa'      => $s['penyewa'],
        'perusahaan'   => $s['perusahaan'],
        'mulai'        => $s['start_date'],
        'akhir'        => $s['end_date'],
    ] : null;

    $hasil = [];
    foreach ($gudang as $g) {
        $daftar = $per[$g['property_id'] . '|' . $g['kode']] ?? [];
        $berjalan = $berikut = $terakhir = null;
        foreach ($daftar as $s) {
            if ($s['start_date'] <= $hariIni && $s['end_date'] >= $hariIni) $berjalan = $s; // yang mulai paling akhir menang
            elseif ($s['start_date'] > $hariIni && !$berikut) $berikut = $s;
            elseif ($s['end_date'] < $hariIni) $terakhir = $s;
        }
        $hasil[] = [
            'gedung'   => $g['gedung'],
            'kode'     => $g['kode'],
            'lokasi'   => $g['lokasi'],
            'nama'     => $g['nama'],
            'luas_m2'  => (float) $g['luas_m2'],
            'status'   => $g['status'],
            'berjalan' => $bentuk($berjalan),
            'berikut'  => $bentuk($berikut),
            'terakhir' => $bentuk($terakhir),
        ];
    }

    echo json_encode(['sumber' => 'clara', 'tanggal' => $hariIni, 'jumlah' => count($hasil), 'gudang' => $hasil], JSON_UNESCAPED_UNICODE);
    exit;
}
