<?php

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Daftar baku Tipe Unit (Exhibition/Casual Leasing) — controlled vocabulary
 * untuk dropdown di Master Unit. Mengganti input teks bebas (sumber typo &
 * variasi: "Puschart", "Island L1/L2", singkatan "FC/SC/PC"). Per-properti,
 * dikelola lewat Master Tipe Unit (tabel master_cl_unit_types, migrasi 034).
 * Fallback ke daftar statis bila tabel belum ada/kosong (aman sebelum migrasi).
 * Acuan tunggal; dipakai juga utk template Surat Penawaran per tipe unit.
 */
function cl_unit_types(?PDO $pdo = null, ?int $propertyId = null): array
{
    $fallback = [
        'Fashion Booth', 'Food Stall', 'Food Court', 'Snack Corner', 'Pushcart',
        'Island', 'Circle', 'Free Standing', 'Atrium', 'Playground', 'Photobox',
        'Leasable Area', 'Parking Area',
    ];
    if (!$pdo) return $fallback;
    try {
        $pid = $propertyId ?? (function_exists('current_property_id') ? current_property_id() : 0);
        $st = $pdo->prepare("SELECT name FROM master_cl_unit_types WHERE property_id = ? AND status = 'active' ORDER BY sort_order ASC, name ASC");
        $st->execute([(int) $pid]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        return $rows ?: $fallback;
    } catch (Throwable $e) {
        return $fallback;
    }
}

/**
 * Daftar lantai baku per properti — dipakai Lantai Exhibition dan Lokasi Gudang.
 * Sumbernya master_lookup_options kategori 'floor' (bisa diubah admin di Kelola
 * Opsi Dropdown), urut dari lantai terbawah. Fallback hanya bila tabel belum diisi.
 */
function floor_options(?PDO $pdo = null, ?int $propertyId = null): array
{
    $fallback = ['LG', 'GF', 'UG', 'FF', 'SF'];
    if (!$pdo) return $fallback;
    try {
        $pid = $propertyId ?? (function_exists('current_property_id') ? current_property_id() : 0);
        $st = $pdo->prepare("SELECT value FROM master_lookup_options WHERE property_id = ? AND category = 'floor' AND status = 'active' ORDER BY sort_order ASC, value ASC");
        $st->execute([(int) $pid]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        return $rows ?: $fallback;
    } catch (Throwable $e) {
        return $fallback;
    }
}

/**
 * Potensi bulanan satu unit master — SATU-SATUNYA rumus proyeksi. Master adalah angka
 * proyeksi (realisasi datang dari transaksi), jadi potensi tidak diketik melainkan
 * diturunkan dari tarif. Rumus Media mengikuti AllocationService (qty × slot).
 *
 *   Exhibition : tarif/hari/m² × luas × 30
 *   Gudang     : tarif/m²/bulan × luas
 *   Media      : daily_point tarif × qty × 30 · daily_slot tarif × qty × slot × 30
 *                monthly tarif × qty · fixed tarif
 */
function master_projection(string $type, array $r): float
{
    $num = fn(string $k, float $d = 0.0) => (float) ($r[$k] ?? $d);
    $v = match ($type) {
        'cl'     => $num('rate') * max(1.0, $num('area_sqm')) * 30,
        'gudang' => $num('monthly_rate') * $num('area_sqm'),
        'media'  => match ((string) ($r['pricing_type'] ?? '')) {
            'daily_slot' => $num('rate') * $num('quantity', 1) * $num('slots', 1) * 30,
            'monthly'    => $num('rate') * $num('quantity', 1),
            'fixed'      => $num('rate'),
            default      => $num('rate') * $num('quantity', 1) * 30,
        },
        default  => 0.0,
    };
    return round($v);
}

/**
 * Batas wajar tarif per m² di master. Di atas angka ini hampir pasti yang diketik adalah
 * total sewa, bukan tarif per m² (Gudang nyata Rp70–220 ribu/m²/bulan, Exhibition
 * Rp150–165 ribu/m²/hari). Tidak menolak simpan — hanya peringatan + notifikasi.
 */
function master_rate_limit(string $type): ?array
{
    return [
        'gudang' => ['kolom' => 'monthly_rate', 'maks' => 500000,  'label' => 'Tarif per m² / bulan', 'saran' => 'isi total sewa sebulan ÷ luas.'],
        'cl'     => ['kolom' => 'rate',         'maks' => 1000000, 'label' => 'Tarif per hari / m²',  'saran' => 'isi tarif per hari per m².'],
    ][$type] ?? null;
}

/**
 * Tarif acuan per properti = tarif yang paling banyak dipakai unit aktif (modus).
 * Unit yang tarifnya berbeda ditandai di notifikasi — bisa salah ketik, bisa juga
 * harga khusus yang perlu disamakan atau dikonfirmasi tim.
 */
function master_rate_reference(array $rows, string $rateCol): ?float
{
    $hitung = [];
    foreach ($rows as $r) {
        $t = round((float) $r[$rateCol], 2);
        if ($t > 0) $hitung[(string) $t] = ($hitung[(string) $t] ?? 0) + 1;
    }
    if (!$hitung) return null;
    arsort($hitung);
    return (float) array_key_first($hitung);
}

/**
 * Data master aktif yang salah isi — untuk notifikasi dashboard. Semuanya berpengaruh
 * ke proyeksi: tarif/luas kosong atau menyimpang, slot/qty keliru, potensi tak sesuai
 * rumus, dan lantai/tipe di luar daftar baku (laporan per lantai/tipe jadi terpecah).
 * Dicocokkan di PHP, bukan JOIN, karena kolom-kolomnya beda collation di produksi.
 *
 * @return list<array{judul:string, type:string, rows:list<array{id:int, code:string, nilai:string}>}>
 */
function master_data_issues(PDO $pdo, int $pid): array
{
    $ambil = function (string $tabel) use ($pdo, $pid): array {
        $st = $pdo->prepare("SELECT * FROM `$tabel` WHERE property_id = ? AND status = 'active' ORDER BY sort_order, code");
        $st->execute([$pid]);
        return $st->fetchAll();
    };
    $rp = fn($v) => 'Rp' . number_format((float) $v, 0, ',', '.');
    $floors = floor_options($pdo, $pid);
    $units  = cl_unit_types($pdo, $pid);

    $data = [
        'cl'     => $ambil('master_cl_units'),
        'gudang' => $ambil('master_gudang'),
        'media'  => $ambil('master_media'),
    ];
    $acuan = [
        'cl'     => master_rate_reference($data['cl'], 'rate'),
        'gudang' => master_rate_reference($data['gudang'], 'monthly_rate'),
    ];
    $rateCol = ['cl' => 'rate', 'gudang' => 'monthly_rate', 'media' => 'rate'];
    $nama    = ['cl' => 'Exhibition', 'gudang' => 'Gudang', 'media' => 'Media'];

    // [judul => [type, rows]] — urutan judul = urutan tampil
    $hasil = [];
    $catat = function (string $judul, string $type, array $r, string $nilai) use (&$hasil) {
        $hasil[$judul]['type'] = $type;
        $hasil[$judul]['rows'][] = ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'nilai' => $nilai];
    };

    foreach ($data as $type => $rows) {
        $n = $nama[$type];
        foreach ($rows as $r) {
            $tarif = (float) $r[$rateCol[$type]];
            $batas = master_rate_limit($type);
            if ($tarif <= 0) {
                $catat("Tarif $n kosong", $type, $r, 'Rp0');
            } elseif ($batas && $tarif > $batas['maks']) {
                $catat("Tarif $n tidak wajar (di atas " . $rp($batas['maks']) . ' — kemungkinan total sewa)', $type, $r, $rp($tarif));
            } elseif (isset($acuan[$type]) && abs($tarif - $acuan[$type]) >= 0.01) {
                $catat("Tarif $n berbeda dari acuan " . $rp($acuan[$type]) . ($type === 'cl' ? '/hari/m²' : '/m²'), $type, $r, $rp($tarif));
            }
            if ($type !== 'media' && (float) $r['area_sqm'] <= 0) {
                $catat("Luas $n kosong", $type, $r, '0 m²');
            }
            if ($type === 'media' && $r['pricing_type'] === 'daily_slot' && (float) $r['slots'] <= 1) {
                $catat('Slot per hari Media belum diisi (tarif per slot)', $type, $r, (float) $r['slots'] . ' slot');
            }
            if ($type === 'media' && $r['pricing_type'] !== 'fixed' && (float) $r['quantity'] <= 0) {
                $catat('Qty Media kosong', $type, $r, '0');
            }
            $rumus = master_projection($type, $r);
            if (abs((float) $r['projection_monthly'] - $rumus) >= 1) {
                $catat("Potensi $n tidak sesuai rumus (terhitung ulang saat disimpan)", $type, $r,
                    $rp($r['projection_monthly']) . ' → ' . $rp($rumus));
            }
        }
    }
    foreach ($data['cl'] as $r) {
        if (!in_array((string) $r['floor'], $floors, true)) $catat('Lantai Exhibition di luar daftar baku', 'cl', $r, (string) $r['floor']);
        if (!in_array((string) $r['unit_type'], $units, true)) $catat('Tipe Unit Exhibition di luar daftar baku', 'cl', $r, (string) ($r['unit_type'] ?? ''));
    }
    foreach ($data['gudang'] as $r) {
        if (!in_array((string) $r['location'], $floors, true)) $catat('Lokasi Gudang di luar daftar baku', 'gudang', $r, (string) $r['location']);
    }

    // Porsi target PIC: target per PIC = porsi × target bulanan, jadi jumlah porsi PIC
    // yang tampil di Achievement harus 100% — kalau tidak, target terbagi kurang/lebih.
    $st = $pdo->prepare("SELECT id, name AS code, target_share FROM master_pic WHERE property_id = ? AND status = 'active' AND show_achievement = 1 AND target_share > 0 ORDER BY target_share DESC, name");
    $st->execute([$pid]);
    $pics = $st->fetchAll();
    $jumlah = array_sum(array_map(fn($r) => (float) $r['target_share'], $pics));
    if ($pics && abs($jumlah - 1) >= 0.0001) {
        $persen = fn($v) => rtrim(rtrim(number_format((float) $v * 100, 2, ',', '.'), '0'), ',') . '%';
        foreach ($pics as $r) {
            $catat('Porsi target PIC berjumlah ' . $persen($jumlah) . ' (seharusnya 100%)', 'pic', $r, $persen($r['target_share']));
        }
    }

    $keluar = [];
    foreach ($hasil as $judul => $isi) {
        $keluar[] = ['judul' => $judul, 'type' => $isi['type'], 'rows' => $isi['rows']];
    }
    return $keluar;
}

function money($value): string
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

/**
 * Parse input nominal rupiah dari form menjadi float, aman terhadap pemisah
 * ribuan & desimal. Konvensi Indonesia: titik = ribuan, koma = desimal.
 * Mengganti pola lama preg_replace('/\D/','',..) yang MEMBUANG titik desimal
 * sehingga "1500000.50" salah jadi 150000050 (~100x). Lihat temuan review #3/#5.
 */
function parse_rupiah($raw): float
{
    $s = trim((string) $raw);
    if ($s === '') return 0.0;
    // Buang semua kecuali digit, titik, koma, minus.
    $s = preg_replace('/[^\d.,\-]/', '', $s);
    if ($s === '' || $s === '-') return 0.0;
    if (strpos($s, ',') !== false) {
        // Ada koma → koma adalah desimal, titik adalah ribuan.
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        // Tanpa koma → titik dianggap pemisah ribuan (konvensi ID), buang.
        $s = str_replace('.', '', $s);
    }
    return is_numeric($s) ? (float) $s : 0.0;
}

/**
 * Ambil nomor urut berikutnya dari tabel counter (offer_counters / skp_counters
 * / contract_request_counters) secara ATOMIK. Mengganti pola lama:
 *   INSERT .. ON DUPLICATE KEY UPDATE last_no=last_no+1;  SELECT last_no ...
 * yang rawan duplikat nomor saat dua request bersamaan (temuan review #15).
 * LAST_INSERT_ID() membuat baca-nilai menyatu dengan increment per-koneksi.
 */
function next_seq_no(PDO $pdo, string $table, int $pid, int $year): int
{
    // $table dipanggil dengan literal di kode kami (bukan input user), namun
    // batasi tetap ke whitelist agar tidak pernah jadi vektor injeksi.
    $allowed = ['offer_counters', 'skp_counters', 'contract_request_counters'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException("counter table tidak dikenal: $table");
    }
    $pdo->prepare(
        "INSERT INTO `$table` (property_id, year, last_no) VALUES (?, ?, LAST_INSERT_ID(1))
         ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)"
    )->execute([$pid, $year]);
    return (int) $pdo->lastInsertId();
}

function pct($value): string
{
    return number_format(((float) $value) * 100, 1, ',', '.') . '%';
}

/**
 * Kondisi SQL "transaksi dihitung recurring" — dipakai SERAGAM di semua angka
 * recurring (dashboard, exec, occupancy, laporan, mobile). Recurring bila:
 *   1) billing_method = 'spread' (kontrak spread), ATAU
 *   2) ditandai manual oleh sales (recurring_flag = 1), ATAU
 *   3) anchor_cycle yang TERDETEKSI berulang (unit+klien sama pada bulan
 *      bersebelahan) — nominal & pricing_type per bulan boleh beda (diskon /
 *      metode hitung beda), karena tetap satu sewa berulang yang sama.
 * Murni pengukuran: TIDAK mengubah billing_method / nominal / meng-convert data.
 *
 * @param string $t alias tabel transactions di query pemanggil (default 't')
 */
function recurring_match_sql(string $t = 't'): string
{
    return "($t.billing_method = 'spread' OR $t.recurring_flag = 1 OR ($t.billing_method = 'anchor_cycle' AND EXISTS (
        SELECT 1 FROM transactions rt2
        WHERE rt2.deleted_at IS NULL
          AND rt2.billing_method = 'anchor_cycle'
          AND rt2.master_code  = $t.master_code
          AND rt2.client_id    = $t.client_id
          AND rt2.property_id  = $t.property_id
          AND rt2.id <> $t.id
          AND ABS(PERIOD_DIFF(REPLACE(rt2.period_key,'-',''), REPLACE($t.period_key,'-',''))) = 1
    )))";
}

function redirect_to(string $route, array $params = []): never
{
    $params = array_merge(['r' => $route], $params);
    header('Location: ?' . http_build_query($params));
    exit;
}

function post(string $key, $default = null)
{
    return $_POST[$key] ?? $default;
}

function getv(string $key, $default = null)
{
    return $_GET[$key] ?? $default;
}

/** Baca setting key/value (tabel settings). Default bila tidak ada. */
function get_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = $pdo->prepare('SELECT value FROM settings WHERE `key` = ? LIMIT 1');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $cache[$key] = ($v === false ? $default : (string) $v);
    } catch (Throwable $e) {
        return $default;
    }
}

// ─── Mobile view ─────────────────────────────────────────────────────────────

/** Deteksi perangkat HP dari User-Agent (tablet tetap dianggap desktop). */
function is_mobile_device(): bool
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/iPad|Tablet|PlayBook|Nexus 7|Nexus 10/i', $ua)) {
        return false;
    }
    return (bool) preg_match('/Android|iPhone|iPod|Mobile|Opera Mini|IEMobile|BlackBerry|webOS/i', $ua);
}

/**
 * Apakah tampilan mobile aktif. Override eksplisit (cookie clara_view) menang;
 * jika tidak ada, auto-deteksi dari User-Agent. User bisa paksa lewat
 * ?view=mobile / ?view=desktop (ditangani di index.php).
 */
function mobile_view_active(): bool
{
    $pref = $_COOKIE['clara_view'] ?? '';
    if ($pref === 'mobile')  return true;
    if ($pref === 'desktop') return false;
    return is_mobile_device();
}

// ─── Property helpers ────────────────────────────────────────────────────────

function current_property_id(): int
{
    return (int)($_SESSION['current_property_id'] ?? 1);
}

function allowed_properties(): array
{
    return $_SESSION['allowed_properties'] ?? [];
}

function allowed_property_ids(): array
{
    return array_column(allowed_properties(), 'id');
}

function current_property(): array
{
    $pid = current_property_id();
    foreach (allowed_properties() as $p) {
        if ((int)$p['id'] === $pid) return $p;
    }
    return ['id' => $pid, 'key' => 'unknown', 'name' => 'Property'];
}

function is_multi_property(): bool
{
    return count(allowed_properties()) > 1;
}

function prop_filter(): string
{
    return 'AND property_id = ' . current_property_id();
}

// ─── Auth ────────────────────────────────────────────────────────────────────

function require_login(): void
{
    if (empty($_SESSION['user'])) {
        redirect_to('login');
    }
    // If logged in but no property selected yet (multi-property user)
    if (empty($_SESSION['current_property_id']) && !empty($_SESSION['allowed_properties'])) {
        $allowed = $_SESSION['allowed_properties'];
        if (count($allowed) === 1) {
            $_SESSION['current_property_id'] = (int)$allowed[0]['id'];
        } else {
            $route = getv('r', '');
            if ($route !== 'select_property' && $route !== 'set_property') {
                redirect_to('select_property');
            }
        }
    }
}

function roles(): array
{
    return [
        'superadmin'   => 'Super Admin',
        'supervisor'   => 'Supervisor',
        'sales'        => 'Sales',
        'finance'      => 'Finance',
        'administrasi' => 'Administrasi',
        'viewer'       => 'Viewer',
    ];
}

function current_role(): string
{
    return $_SESSION['user']['role'] ?? 'guest';
}

/**
 * Pembatasan visibilitas per-sales. Role 'sales' hanya melihat data miliknya
 * (penawaran/SKP dengan PIC = dirinya, atau yang ia buat). Role lain → null
 * (lihat semua). Return ['pic'=>nama PIC tertaut|'', 'uname'=>nama user] atau null.
 */
function current_sales_scope(PDO $pdo, int $pid): ?array
{
    if (current_role() !== 'sales') return null;
    $uid   = (int) ($_SESSION['user']['id'] ?? 0);
    $uname = (string) ($_SESSION['user']['name'] ?? '');
    $pic = '';
    if ($uid) {
        $st = $pdo->prepare("SELECT name FROM master_pic WHERE user_id = ? AND status = 'active' AND property_id = ? LIMIT 1");
        $st->execute([$uid, $pid]);
        $pic = (string) ($st->fetchColumn() ?: '');
    }
    return ['pic' => $pic, 'uname' => $uname];
}

/**
 * Fragmen WHERE untuk pembatasan per-sales, siap ditempel ke query.
 * Mengembalikan [sqlFragment, params].
 *
 * PENTING (temuan review #14): bila PIC tertaut kosong (''), JANGAN ikut
 * mencocokkan pic_name='' — itu membocorkan semua baris ber-PIC kosong milik
 * sales lain. Saat kosong, batasi HANYA ke created_by.
 *
 * @param string|string[] $picCol kolom pic (mis. 'o.pic_name' / beberapa kolom)
 * @param string $byCol  nama kolom pembuat (mis. 'o.created_by')
 */
function current_sales_scope_sql(PDO $pdo, int $pid, string|array $picCol = 'pic_name', string $byCol = 'created_by'): array
{
    $scope = current_sales_scope($pdo, $pid);
    if (!$scope) return ['', []];                       // bukan sales → tanpa batas
    if ($scope['pic'] === '') {
        return [" AND $byCol = ?", [$scope['uname']]]; // pic kosong → created_by saja
    }
    // Boleh beberapa kolom PIC sekaligus (mis. PIC dokumen, PIC penawaran, PIC
    // transaksi) supaya dokumen milik sales tetap terlihat walau yang membuatkan
    // orang lain (admin/manager).
    $or = []; $params = [];
    foreach ((array) $picCol as $kol) { $or[] = "$kol = ?"; $params[] = $scope['pic']; }
    $or[] = "$byCol = ?"; $params[] = $scope['uname'];
    return [' AND (' . implode(' OR ', $or) . ')', $params];
}

function permission_matrix(?array $set = null): array
{
    static $matrix = [];
    if ($set !== null) {
        $matrix = $set;
    }
    return $matrix;
}

function can(string $permission): bool
{
    $role = current_role();
    if ($role === 'superadmin' || $role === 'admin') {
        return true;
    }
    return in_array($permission, permission_matrix()[$role] ?? [], true);
}

function require_permission(string $permission): void
{
    if (!can($permission)) {
        http_response_code(403);
        exit('Akses ditolak untuk role Anda.');
    }
}

/**
 * Cetak tag <head> PWA (manifest, theme-color, ikon Apple) + registrasi service
 * worker. Dipanggil di tiap halaman yang punya <head> sendiri (layout utama &
 * halaman auth) agar aplikasi bisa di-install ke home screen HP.
 */
function pwa_head(): void
{
    ?>
        <meta name="theme-color" content="#0D9488">
        <link rel="manifest" href="manifest.webmanifest">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="CLARA">
        <link rel="apple-touch-icon" href="assets/icon-192.png">
        <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('service-worker.js').catch(function () {});
            });
        }
        </script>
    <?php
}

function permission_for_route(string $route): string
{
    return match ($route) {
        'dashboard' => 'view_dashboard',
        'transactions', 'transaction_form', 'transaction_edit', 'allocation_detail' => 'view_transactions',
        'transaction_save', 'transaction_update' => 'manage_transactions',
        'transaction_delete', 'deleted_transactions' => 'manage_deleted',
        // Halaman pengajuan dibuka dengan request_delete; tiap tindakan di
        // dalamnya memeriksa izinnya sendiri (approve_delete / manage_deleted).
        'deletion_request', 'deletion_request_save' => 'request_delete',
        'deletion_request_decide' => 'approve_delete',
        'deletion_request_execute', 'deletion_approver_save' => 'manage_deleted',
        // Pulihkan sengaja TIDAK dikunci di sini. Yang berhak adalah pemutus
        // ATAU pemegang manage_deleted — dua himpunan yang tidak bisa diwakili
        // satu nama izin, dan gerbang yang memakai salah satunya akan menolak
        // orang yang sebenarnya berhak. Pemeriksaannya ada di dalam
        // deletion_request_restore(), satu tempat, tanpa dua aturan yang bisa
        // berbeda. Rute tanpa daftar jatuh ke default di bawah.
        'transaction_cancel', 'price_step_save', 'pic_split_save' => 'approve_skp',
        'transaction_history' => 'view_transactions',
        'master' => 'view_master',
        'master_form', 'master_save', 'generate_periods' => 'manage_master',
        'import_media', 'import_template' => 'import_master',
        'exec_dashboard', 'print_exec_summary' => 'view_exec_summary',
        'export_summary', 'print_dashboard', 'print_exec', 'print_trend' => 'export_reports',
        'export_transactions_xlsx', 'export_pic_report_xlsx', 'export_client_analysis_xlsx' => 'export_reports',
        'audit' => 'view_logs',
        'users', 'user_form', 'user_save', 'roles', 'roles_save',
        'approval_flow', 'approval_flow_save' => 'manage_users',
        'clients', 'client_form', 'client_save' => 'manage_master',
        'client_analysis', 'client_profile' => 'view_master',
        'pic_report', 'pic_report_print' => 'view_pic_report',
        'pic_reward', 'pic_reward_save'  => 'view_pic_report',
        'pic_performance', 'pic_pipeline' => 'view_pic_report',
        'offer_close', 'offer_sign_upload' => 'manage_offers',
        'renewals' => 'view_renewals',
        'skp', 'skp_pick', 'skp_form', 'skp_save', 'skp_print', 'skp_sign_upload',
        'skp_attachment_replace' => 'manage_skp',
        'contract_requests', 'contract_request_form', 'contract_request_save', 'contract_request_print' => 'manage_skp',
        'skp_approve', 'skp_reject', 'skp_cancel_deal', 'skp_revision_decide' => 'approve_skp',
        'skp_revision_request' => 'manage_skp',
        'my_paraf', 'my_paraf_save', 'my_paraf_reset' => 'approve_skp',
        'offers', 'offer_view', 'offer_form', 'offer_save', 'offer_status', 'offer_print', 'offer_template_rule' => 'manage_offers',
        'offer_templates', 'offer_template_form', 'offer_template_save', 'offer_template_preview' => 'manage_master',
        'm_home' => 'view_dashboard',
        'm_transactions' => 'view_transactions',
        'm_exec' => 'view_exec_summary',
        'm_offers' => 'manage_offers',
        'm_skp' => 'manage_skp',
        'stock_reports', 'stock_sheet' => 'view_stock_report',
        'stock_report_form', 'stock_report_save', 'stock_report_delete', 'stock_sheet_api' => 'manage_stock_report',
        'lookup_manage', 'lookup_save', 'lookup_delete' => 'manage_master',
        'trend', 'comparison' => 'view_dashboard',
        'switch_property', 'select_property', 'set_property' => 'view_dashboard',
        default => 'view_dashboard',
    };
}

function module_from_request(string $route): string
{
    if (in_array($route, ['master', 'master_form', 'master_save'], true)) {
        return 'master_' . (getv('type', post('type', 'general')));
    }
    if (in_array($route, ['transactions', 'transaction_form', 'transaction_save', 'transaction_edit', 'transaction_update'], true)) {
        return 'transaction_' . (getv('module', post('module', 'general')));
    }
    return match ($route) {
        'dashboard'                  => 'dashboard',
        'allocation_detail'          => 'allocation',
        'import_media', 'import_template' => 'master_media',
        'export_summary'             => 'reporting',
        'audit'                      => 'audit',
        'users', 'user_form', 'user_save' => 'users',
        'roles', 'roles_save'        => 'roles',
        'deleted_transactions', 'transaction_delete' => 'deleted_transactions',
        'clients', 'client_form', 'client_save' => 'clients',
        'login', 'logout'            => 'auth',
        default                      => $route,
    };
}

function flash(?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return null;
    }
    $current = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $current;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

/**
 * Mulai kapan aturan "income masuk setelah client menandatangani" mengikat.
 *
 * Kesepakatan yang transaksinya dibuat SEBELUM tanggal ini tetap berperilaku
 * lama: angkanya terhitung sejak disetujui Manager dan barisnya tetap terlihat
 * di daftar modul. Yang dibuat sejak tanggal ini wajib lewat tanda tangan
 * client dulu.
 *
 * Batas ini ada supaya pergantian aturan tidak berlaku surut. Tanpanya, satu
 * kali deploy akan melepas miliaran rupiah dari laporan orang-orang yang
 * kesepakatannya sudah berjalan — dan itu pernah terjadi.
 *
 * Kosong = belum disetel; artinya aturan lama untuk semuanya.
 */
function ttd_wajib_mulai(PDO $pdo): string
{
    static $tgl = null;
    if ($tgl === null) {
        try {
            $tgl = (string) ($pdo->query("SELECT value FROM settings WHERE `key` = 'ttd_wajib_mulai' LIMIT 1")
                                 ->fetchColumn() ?: '');
        } catch (Throwable $e) {
            $tgl = '';
        }
    }
    return $tgl;
}

/** Apakah transaksi ini sudah terikat aturan tanda tangan? */
function ttd_wajib_untuk(PDO $pdo, ?string $dibuatPada): bool
{
    $mulai = ttd_wajib_mulai($pdo);
    if ($mulai === '' || !$dibuatPada) return false;
    return substr($dibuatPada, 0, 10) >= $mulai;
}

/**
 * Berapa pengajuan penghapusan yang menunggu keputusan ORANG INI.
 *
 * Dipakai untuk lencana angka di sidebar: sebelum ini pemutus hanya tahu ada
 * pengajuan kalau kebetulan membuka menunya, dan pengajuan bisa menggantung
 * berhari-hari tanpa ada yang salah.
 *
 * Satu pengajuan berisi beberapa dokumen dihitung SATU — yang diputuskan
 * pemutus memang satu keputusan, bukan sejumlah dokumennya.
 *
 * Dihitung di layout(), jadi berjalan di tiap halaman: semua kegagalan ditelan
 * dan menghasilkan 0. Tabelnya bisa belum ada (migrasi belum dijalankan), dan
 * sebuah angka hiasan di sidebar tidak boleh sampai mematikan aplikasi.
 */
function deletion_pending_count(PDO $pdo, int $pid): int
{
    if (!can('approve_delete')) return 0;
    try {
        // Jabatan pemutus harus cocok dengan yang disetel; superadmin & admin
        // selalu boleh, sama seperti perlakuan can() di seluruh aplikasi.
        if (!in_array(current_role(), ['superadmin', 'admin'], true)) {
            $set = $pdo->prepare('SELECT role_name, pic_name FROM deletion_approver WHERE property_id = ?');
            $set->execute([$pid]);
            $cfg = $set->fetch(PDO::FETCH_ASSOC);
            if (!$cfg || trim((string) $cfg['role_name']) === '') return 0;

            require_once __DIR__ . '/ApprovalLine.php';
            $jabatan = ApprovalLine::jabatan($pdo, $pid);
            if ($jabatan === '' || strcasecmp($jabatan, (string) $cfg['role_name']) !== 0) return 0;

            $khusus = trim((string) ($cfg['pic_name'] ?? ''));
            if ($khusus !== '' && strcasecmp(ApprovalLine::namaPic($pdo, $pid), $khusus) !== 0) return 0;
        }
        $q = $pdo->prepare("SELECT COUNT(DISTINCT COALESCE(NULLIF(batch_no, ''), CONCAT('x', id)))
                              FROM deletion_requests WHERE property_id = ? AND status = 'menunggu'");
        $q->execute([$pid]);
        return (int) $q->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function verify_csrf(): void
{
    // hash_equals: perbandingan waktu-konstan (anti timing attack).
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['_csrf'] ?? ''))) {
        unset($_SESSION['csrf']);
        flash('Sesi form sudah kedaluwarsa. Silakan coba lagi.');
        redirect_to('login');
    }
}

function field(array $row, string $key, $default = ''): string
{
    return h((string) ($row[$key] ?? $default));
}

function period_label(string $periodKey): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $periodKey . '-01');
    $months = [
        '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
        '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
        '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
    ];
    if (!$dt) return $periodKey;
    return ($months[$dt->format('m')] ?? $dt->format('m')) . ' ' . $dt->format('Y');
}

/**
 * Pilihan periode untuk dropdown laporan: terbaru di atas, paling jauh 3 bulan ke depan.
 *
 * Batas depan wajib ada. Kontrak panjang punya alokasi sampai bertahun-tahun ke depan
 * (ada yang sampai 2030), sehingga "36 bulan terbaru" tanpa batas hanya berisi bulan
 * masa depan yang hampir kosong — dan bulan yang sudah lewat, yang justru dicari untuk
 * laporan dan komisi, tak bisa dipilih.
 */
function report_period_options(PDO $pdo, string $selected, int $limit = 36): array
{
    $batas = (new DateTimeImmutable('first day of this month'))->modify('+3 months')->format('Y-m');
    $s = $pdo->prepare(
        'SELECT DISTINCT period_key FROM transaction_allocations
         WHERE period_key <= ? ORDER BY period_key DESC LIMIT ' . (int) $limit
    );
    $s->execute([$batas]);
    $keys = $s->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($selected, $keys, true)) {
        $keys[] = $selected;
        rsort($keys);
    }
    return $keys;
}

function audit(PDO $pdo, string $action, string $table, ?string $id, array $after = [], array $before = [], ?string $module = null): void
{
    $ip   = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs
         (property_id, user_id, actor, user_name, user_role, action, module, table_name, record_id,
          route, method, ip_address, computer_name, user_agent, before_json, after_json)
         VALUES
         (:property_id, :user_id, :actor, :user_name, :user_role, :action, :module, :table_name, :record_id,
          :route, :method, :ip_address, :computer_name, :user_agent, :before_json, :after_json)'
    );
    $user = $_SESSION['user'] ?? [];
    $stmt->execute([
        ':property_id'   => current_property_id(),
        ':user_id'       => $user['id'] ?? null,
        ':actor'         => $user['email'] ?? 'system',
        ':user_name'     => $user['name'] ?? 'System',
        ':user_role'     => $user['role'] ?? 'system',
        ':action'        => $action,
        ':module'        => $module ?? module_from_request(getv('r', 'system')),
        ':table_name'    => $table,
        ':record_id'     => $id,
        ':route'         => getv('r', 'system'),
        ':method'        => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        ':ip_address'    => $ip,
        ':computer_name' => null,
        ':user_agent'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ':before_json'   => $before ? json_encode($before) : null,
        ':after_json'    => $after  ? json_encode($after)  : null,
    ]);

    if (random_int(1, 100) === 1) {
        $pdo->prepare('DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)')->execute();
    }
}

function log_activity(PDO $pdo, string $action, ?string $module = null, ?string $recordId = null, array $context = []): void
{
    audit($pdo, $action, $module ?? module_from_request(getv('r', 'system')), $recordId, $context, [], $module);
}

/**
 * Ambil total potensi per segmen untuk periode tertentu.
 * Prioritas: snapshot di period_potentials → fallback ke projection_monthly master.
 */
function get_projection(PDO $pdo, string $period, int $pid): array
{
    $map = [
        'cl'     => ['master_cl_units', 'exhibition'],
        'media'  => ['master_media',    'media'],
        'gudang' => ['master_gudang',   'gudang'],
    ];
    $result = [];
    foreach ($map as $key => [$table, $segment]) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(COALESCE(pp.potential_value, m.projection_monthly)), 0)
            FROM $table m
            LEFT JOIN period_potentials pp ON pp.slot_id = m.id AND pp.segment = ?
                AND pp.period_key = ? AND pp.property_id = ?
            WHERE m.status = 'active' AND m.property_id = ?
        ");
        $stmt->execute([$segment, $period, $pid, $pid]);
        $result[$key] = (float) $stmt->fetchColumn();
    }
    return $result;
}

function snapshot_potential(PDO $pdo, string $segment, int $slotId, string $slotCode, float $newValue, int $pid, ?float $priorMasterValue = null): void
{
    $periodKey = date('Y-m');
    $userId    = (int) ($_SESSION['user']['id'] ?? 0);

    $oldRow = $pdo->prepare(
        'SELECT potential_value FROM period_potentials
         WHERE property_id = ? AND period_key = ? AND segment = ? AND slot_id = ?'
    );
    $oldRow->execute([$pid, $periodKey, $segment, $slotId]);
    $oldValue = (float) ($oldRow->fetchColumn() ?: 0);

    // Freeze past months that have no snapshot yet so they are not affected by future master changes.
    // Only do this when we know what the slot's value was before this edit ($priorMasterValue).
    if ($priorMasterValue !== null) {
        $chk = $pdo->prepare(
            'SELECT COUNT(*) FROM period_potentials
             WHERE property_id = ? AND period_key = ? AND segment = ? AND slot_id = ?'
        );
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO period_potentials
             (property_id, period_key, segment, slot_id, slot_code, potential_value)
             VALUES (?,?,?,?,?,?)'
        );
        for ($i = 1; $i <= 12; $i++) {
            $pastPeriod = date('Y-m', strtotime("-$i month"));
            $chk->execute([$pid, $pastPeriod, $segment, $slotId]);
            if ((int)$chk->fetchColumn() === 0) {
                $ins->execute([$pid, $pastPeriod, $segment, $slotId, $slotCode, $priorMasterValue]);
            }
        }
    }

    if (abs($newValue - $oldValue) < 0.01) return;

    $pdo->prepare(
        'INSERT INTO potential_history
         (property_id, period_key, segment, slot_id, slot_code, old_value, new_value, changed_by, change_source)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([$pid, $periodKey, $segment, $slotId, $slotCode, $oldValue, $newValue, $userId, 'master_' . $segment]);

    $pdo->prepare(
        'INSERT INTO period_potentials
         (property_id, period_key, segment, slot_id, slot_code, potential_value)
         VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE slot_code = VALUES(slot_code), potential_value = VALUES(potential_value)'
    )->execute([$pid, $periodKey, $segment, $slotId, $slotCode, $newValue]);
}

/**
 * URL aman untuk berkas unggahan (temuan H2). Mengganti link langsung ke
 * /uploads/... (yang world-readable & permanen). Semua berkas KTP/NPWP/akta/
 * surat-kuasa/TTD basah kini disajikan lewat route ?r=file yang mewajibkan
 * sesi login ATAU token share yang sah (lihat secure_file()). $rel = path
 * relatif tersimpan, mis. 'uploads/skp/skp1_ktp_xx.png'. $token opsional untuk
 * akses publik via share_token (halaman Legal).
 */
function upload_url(?string $rel, string $token = ''): string
{
    $rel = ltrim((string) $rel, '/');
    if ($rel === '') return '';
    $u = '?r=file&p=' . urlencode($rel);
    if ($token !== '') $u .= '&t=' . urlencode($token);
    return $u;
}

/**
 * Ekspresi SQL masa berlaku token tanda tangan customer (H3) — sumber tunggal
 * kebijakan 30 hari, dipakai di semua titik penerbitan token (offers & SKP) agar
 * tidak tercecer. Bukan input user (literal konstan), aman diinterpolasi ke SQL.
 */
function sign_token_expiry_sql(): string
{
    return 'DATE_ADD(NOW(), INTERVAL 30 DAY)';
}

/**
 * Token tanda tangan customer (sign_token offers/SKP) kedaluwarsa? (temuan H3)
 * NULL/kosong = legacy tanpa kedaluwarsa → dianggap masih berlaku agar link lama
 * tidak putus. Dipakai HANYA di halaman tanda tangan, bukan halaman validasi QR.
 */
function sign_token_expired(?string $expiresAt): bool
{
    if (empty($expiresAt)) return false;
    $ts = strtotime($expiresAt);
    return $ts !== false && $ts < time();
}

function validate_password(string $pw): ?string
{
    if (strlen($pw) < 8)                          return 'Password minimal 8 karakter.';
    if (!preg_match('/[A-Z]/', $pw))              return 'Password harus mengandung minimal 1 huruf besar.';
    if (!preg_match('/[a-z]/', $pw))              return 'Password harus mengandung minimal 1 huruf kecil.';
    if (!preg_match('/[0-9]/', $pw))              return 'Password harus mengandung minimal 1 angka.';
    if (!preg_match('/[^A-Za-z0-9]/', $pw))       return 'Password harus mengandung minimal 1 karakter spesial (!@#$%^&* dll).';
    if ($pw === '123456')                          return 'Gunakan password selain password default.';
    return null;
}

/** Formatter untuk mendukung **bold** dengan aman (pentest-safe karena di-escape dulu). */
function clara_format_bold(?string $text): string
{
    $escaped = h($text);
    return preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $escaped);
}
