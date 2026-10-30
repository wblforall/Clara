<?php
declare(strict_types=1);

// layout() memanggil ViewAs::banner(); dimuat di sini supaya halaman yang
// menyertakan bootstrap tanpa lewat index.php tidak jatuh ke fatal error.
require_once dirname(__DIR__) . '/ViewAs.php';

/**
 * Daftar token TV Display yang sah dari nilai DISPLAY_TOKEN (boleh berisi
 * BEBERAPA token dipisah koma). Membuang nilai kosong & placeholder default.
 * Sumber tunggal parsing untuk display_authorized() & display_url() agar tak
 * bisa drift. Mendukung rotasi tanpa downtime: token lama & baru berlaku
 * bersamaan, lalu yang lama dihapus setelah semua TV pindah.
 */
function display_tokens(string $raw): array
{
    $out = [];
    foreach (array_map('trim', explode(',', $raw)) as $t) {
        if ($t !== '' && $t !== 'change-this-display-token') $out[] = $t;
    }
    return $out;
}

/** Validasi token TV Display (timing-safe: hash_equals untuk tiap token, tanpa break). */
function display_authorized(array $config): bool
{
    $token = (string) getv('token', '');
    if ($token === '') return false;
    $ok = false;
    foreach (display_tokens((string) $config['display_token']) as $t) {
        if (hash_equals($t, $token)) $ok = true;
    }
    return $ok;
}

/**
 * URL TV Display lengkap dengan token, untuk tombol "Display TV" di dashboard.
 * Mengambil token PERTAMA yang sah. Return '' bila tak ada (mis. masih placeholder)
 * → tombol disembunyikan. Token hanya disisipkan untuk user berhak yang sudah login.
 */
function display_url(): string
{
    $tokens = display_tokens((string) env_value('DISPLAY_TOKEN', ''));
    return $tokens ? '?r=display&token=' . urlencode($tokens[0]) : '';
}

function xlsx_download(string $filename, array $headers, array $rows): void
{
    $col = static function (int $n): string {
        $r = '';
        do { $r = chr(65 + ($n % 26)) . $r; $n = intdiv($n, 26) - 1; } while ($n >= 0);
        return $r;
    };
    $cell = static function (int $c, int $r, $v) use ($col): string {
        $ref = $col($c) . $r;
        if (is_numeric($v) && $v !== '') {
            return "<c r=\"$ref\"><v>" . htmlspecialchars((string) $v, ENT_XML1) . "</v></c>";
        }
        return "<c r=\"$ref\" t=\"inlineStr\"><is><t>" . htmlspecialchars((string) $v, ENT_XML1) . "</t></is></c>";
    };
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    $xml .= '<row r="1">';
    foreach ($headers as $i => $h) { $xml .= $cell($i, 1, $h); }
    $xml .= '</row>';
    foreach ($rows as $ri => $row) {
        $rn = $ri + 2;
        $xml .= "<row r=\"$rn\">";
        foreach (array_values($row) as $ci => $v) { $xml .= $cell($ci, $rn, $v); }
        $xml .= '</row>';
    }
    $xml .= '</sheetData></worksheet>';
    $tmpDir = sys_get_temp_dir();
    $tmp = tempnam($tmpDir, 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
    $zip->close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

/** Ikon inline (stroke SVG) untuk shell mobile. */
function _m_icon(string $name): string
{
    $p = [
        'home'    => '<path d="M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10"/>',
        'tx'      => '<path d="M4 4h16v16H4zM8 9h8M8 13h8M8 17h5"/>',
        'renew'   => '<path d="M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5"/>',
        'desktop' => '<path d="M3 4h18v12H3zM8 20h8M12 16v4"/>',
        'plus'    => '<path d="M12 5v14M5 12h14"/>',
        'search'  => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'chart'   => '<path d="M3 21h18M6 21v-7M11 21V8M16 21v-4"/>',
        'offer'   => '<path d="M3 12V5a2 2 0 0 1 2-2h7l9 9-9 9-9-9z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
         . 'stroke-linecap="round" stroke-linejoin="round" width="22" height="22">'
         . ($p[$name] ?? '') . '</svg>';
}

/** Tab bottom-nav mana yang aktif berdasarkan route saat ini. */
function _mobile_active_tab(string $route): string
{
    if ($route === 'm_home') return 'home';
    if ($route === 'm_offers' || $route === 'm_skp' || str_starts_with($route, 'offer') || $route === 'skp' || str_starts_with($route, 'skp_')) return 'offer';
    if ($route === 'm_transactions' || str_starts_with($route, 'transaction')) return 'tx';
    if ($route === 'm_exec' || $route === 'exec_dashboard') return 'exec';
    if ($route === 'renewals') return 'renew';
    return '';
}

/**
 * Ikon menu samping (garis, 24×24, mengikuti warna teks). Satu tempat untuk
 * semua ikon supaya menu baru cukup menambah satu baris di sini.
 */
function _nav_ikon(string $key): string
{
    $r = strtok($key, '&');
    parse_str((string) parse_url('?' . $key, PHP_URL_QUERY), $q);
    $k = $r . (isset($q['module']) ? ':' . $q['module'] : '') . (isset($q['type']) ? ':' . $q['type'] : '');
    $p = [
        'dashboard'          => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
        'exec_dashboard'     => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
        'trend'              => '<path d="m22 7-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/>',
        'performance'        => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'unit_analysis'      => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
        'comparison'         => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 3v18"/>',
        'offers'             => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
        'skp:cl'             => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/>',
        'skp:gudang'         => '<path d="M22 8.35V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8.35a2 2 0 0 1 1.26-1.85l8-3.2a2 2 0 0 1 1.48 0l8 3.2A2 2 0 0 1 22 8.35Z"/><path d="M6 18h12"/><path d="M6 14h12"/>',
        'skp:media'          => '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
        'prospects'          => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/>',
        'contract_requests'  => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
        'deletion_request'   => '<path d="M3 6h18"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'transactions:cl'    => '<path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M2 7h20"/><path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"/>',
        'transactions:media' => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>',
        'transactions:gudang'=> '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.27 6.96 12 12.01l8.73-5.05"/><path d="M12 22.08V12"/>',
        'payments'           => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/>',
        'master:cl'          => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'master:cl_unit_type'=> '<path d="M12 2H2v10l9.29 9.29a1 1 0 0 0 1.41 0l8.59-8.59a1 1 0 0 0 0-1.41Z"/><circle cx="7" cy="7" r="1.5"/>',
        'offer_templates'    => '<rect x="3" y="3" width="18" height="7" rx="1"/><rect x="3" y="14" width="9" height="7" rx="1"/><rect x="16" y="14" width="5" height="7" rx="1"/>',
        'master:media'       => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/>',
        'master:gudang'      => '<rect x="3" y="8" width="18" height="13" rx="1"/><path d="M3 8l2-5h14l2 5"/><path d="M10 12h4"/>',
        'master:pic'         => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'master:target'      => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22v-7"/>',
        'master:holiday'     => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
        'clients'            => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M12 6h.01M16 6h.01M8 10h.01M12 10h.01M16 10h.01M8 14h.01M12 14h.01M16 14h.01"/>',
        'master_referrer'    => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'calendar'           => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>',
        'daily_occupancy'    => '<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>',
        'renewals'           => '<path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
        'client_analysis'    => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>',
        'pic_report'         => '<path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="5"/><rect x="12" y="8" width="3" height="9"/><rect x="17" y="5" width="3" height="12"/>',
        'pic_performance'    => '<circle cx="12" cy="8" r="6"/><path d="M15.48 12.89 17 22l-5-3-5 3 1.52-9.11"/>',
        'pic_pipeline'       => '<path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>',
        'pic_reward'         => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5"/>',
        'commission_sim'     => '<path d="M19 5 5 19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
        'recurring_candidates'=> '<path d="m17 1 4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="m7 23-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
        'lookup_manage'      => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'users'              => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'roles'              => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
        'approval_flow'      => '<path d="M6 3v12"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/>',
        'audit'              => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'deleted_transactions'=> '<rect x="2" y="3" width="20" height="5" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/>',
        'my_signature'       => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'my_paraf'           => '<path d="m9 11 3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        '_logout'            => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        '_hp'                => '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>',
        '_ciut'              => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/>',
        '_turun'             => '<path d="m6 9 6 6 6-6"/>',
        '_tambah'            => '<circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/>',
    ];
    $d = $p[$k] ?? $p[$r] ?? '<circle cx="12" cy="12" r="3"/>';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}

/**
 * Pintasan "buat baru" untuk tombol (+) di menu samping: [rute, izin, label].
 * Hanya menu yang memang punya halaman buat-baru; SKP Exhibition sengaja tidak,
 * karena SKP-nya lahir dari Surat Penawaran.
 */
function _nav_tambah(string $key): ?array
{
    return [
        'offers'                    => ['offer_form', 'manage_offers', 'Surat Penawaran baru'],
        'skp&module=gudang'         => ['skp_pick&module=gudang', 'manage_skp', 'SKS Gudang baru'],
        'skp&module=media'          => ['skp_pick&module=media', 'manage_skp', 'Form Utilities baru'],
        'prospects'                 => ['prospect_form', 'view_transactions', 'Prospek baru'],
        'contract_requests'         => ['contract_request_form', 'manage_skp', 'Permintaan kontrak baru'],
        'transactions&module=cl'    => ['transaction_form&module=cl', 'manage_transactions', 'Transaksi Exhibition baru'],
        'transactions&module=media' => ['transaction_form&module=media', 'manage_transactions', 'Transaksi Media baru'],
        'transactions&module=gudang'=> ['transaction_form&module=gudang', 'manage_transactions', 'Transaksi Gudang baru'],
        'clients'                   => ['client_form', 'manage_master', 'Client baru'],
        'offer_templates'           => ['offer_template_form', 'manage_master', 'Template baru'],
        'users'                     => ['user_form', 'manage_users', 'User baru'],
    ][$key] ?? null;
}

function layout(string $title, callable $body, array $opts = []): void
{
    global $route, $config, $pdo;
    // Halaman HTML dimampatkan dari PHP: LiteSpeed di hosting tidak memampatkan
    // keluaran dinamis walau .htaccess memintanya, sehingga Kalender (±570 KB)
    // terkirim utuh. Hanya di sini — unduhan PDF/Excel tidak lewat layout() dan
    // menulis Content-Length sendiri, jadi tidak tersentuh.
    if (!headers_sent() && extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
        ob_start('ob_gzhandler');
    }
    $appName = $config['app_name'] ?? 'CLARA';
    $flash   = flash();
    $nav     = [
        ['', 'dashboard', 'Dashboard', 'view_dashboard'],
        ['', 'exec_dashboard', 'Executive Summary', 'view_exec_summary'],
        ['', 'trend', 'Trend Revenue', 'view_dashboard'],
        ['', 'performance', 'Performance & Outlook', 'view_exec_summary'],
        ['', 'unit_analysis', 'Analisa Unit', 'view_dashboard'],
        ['', 'comparison', 'Perbandingan Periode', 'view_dashboard'],
        ['Input', 'offers', 'Surat Penawaran', 'manage_offers'],
        // Satu menu bernama "SKP" dulu memuat tiga jenis dokumen yang alurnya
        // berbeda — Exhibition lewat penawaran, Gudang & Media tidak. Orang yang
        // mau membuat SKS Gudang harus menebak bahwa tempatnya di dalam menu
        // bernama SKP. Dipisah supaya pintu masuknya sejelas isinya; daftarnya
        // tetap satu halaman dengan saringan modul, jadi riwayatnya utuh.
        ['', 'skp&module=cl', 'SKP Exhibition', 'manage_skp'],
        ['', 'skp&module=gudang', 'SKS Gudang', 'manage_skp'],
        ['', 'skp&module=media', 'Form Utilities (Media)', 'manage_skp'],
        ['', 'prospects', 'Prospek', 'view_transactions'],
        ['', 'contract_requests', 'Permintaan Kontrak', 'manage_skp'],
        ['', 'deletion_request', 'Pengajuan Hapus Data', 'request_delete'],
        ['', 'transactions&module=cl', 'Exhibition', 'view_transactions'],
        ['', 'transactions&module=media', 'Media', 'view_transactions'],
        ['', 'transactions&module=gudang', 'Gudang', 'view_transactions'],
        ['', 'payments', 'Tagihan & Pembayaran', 'manage_payments'],
        ['Master Data', 'master&type=cl', 'Master Exhibition', 'view_master'],
        ['', 'master&type=cl_unit_type', 'Master Tipe Unit', 'view_master'],
        ['', 'offer_templates', 'Template Penawaran', 'manage_master'],
        ['', 'master&type=media', 'Master Media', 'view_master'],
        ['', 'master&type=gudang', 'Master Gudang', 'view_master'],
        ['', 'master&type=pic', 'Master PIC', 'view_master'],
        ['', 'master&type=target', 'Target', 'view_master'],
        ['', 'master&type=holiday', 'Hari Libur', 'view_master'],
        ['', 'clients', 'Master Client', 'view_master'],
        ['', 'master_referrer', 'Master Referrer', 'view_master'],
        ['Analisa', 'calendar', 'Kalender Pameran', 'view_transactions'],
        ['', 'daily_occupancy', 'Occupancy Harian', 'view_dashboard'],
        ['', 'renewals', 'Renewal Kontrak', 'view_renewals'],
        ['', 'client_analysis', 'Analisa Market Client', 'view_master'],
        ['', 'pic_report', 'Laporan PIC', 'view_pic_report'],
        ['', 'pic_performance', 'Performa PIC', 'view_pic_report'],
        ['', 'pic_pipeline', 'Aktivitas & Pipeline PIC', 'view_pic_report'],
        ['', 'pic_reward', 'Rewarding PIC', 'view_pic_report'],
        ['', 'commission_sim', 'Simulasi Komisi', 'view_commission_sim'],
        ['Admin', 'recurring_candidates', 'Konversi Recurring', 'manage_transactions'],
        ['', 'lookup_manage', 'Kelola Opsi Dropdown', 'manage_master'],
        ['', 'users', 'Users & Role', 'manage_users'],
        ['', 'roles', 'Role & Permission', 'manage_users'],
        ['', 'approval_flow', 'Alur Approval Dokumen', 'manage_users'],
        ['', 'audit', 'Activity Log', 'view_logs'],
        ['', 'deleted_transactions', 'Transaksi Dihapus', 'manage_deleted'],
        ['Akun', 'my_signature', 'Tanda Tangan Saya', 'view_dashboard'],
        ['', 'my_paraf', 'Paraf Saya', 'approve_skp'],
    ];

    // Lencana angka di sidebar: pemutus pengajuan penghapusan perlu tahu ada
    // yang menunggu tanpa harus membuka menunya. Dihitung sekali per halaman.
    $navBadge = ['deletion_request' => ($pdo instanceof PDO) ? deletion_pending_count($pdo, current_property_id()) : 0];

    $currentProp  = current_property();
    $isMulti      = is_multi_property();
    $allowedProps = allowed_properties();
    $currentPid   = current_property_id();
    ?>
    <!doctype html>
    <html lang="id"<?= (($_COOKIE['clara_sb'] ?? '') === 'mini') ? ' class="sb-mini"' : '' ?>>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?> — <?= h($appName) ?></title>
        <link rel="icon" type="image/png" href="assets/clara-logo.png">
        <?php pwa_head(); ?>
        <link rel="stylesheet" href="assets/app.css?v=<?= CSS_VER ?>">
        <link rel="stylesheet" href="assets/flatpickr.min.css">
        <script src="assets/count-up.js?v=<?= (int) @filemtime(__DIR__ . '/../../public/assets/count-up.js') ?>" defer></script>
        <style>
        /* Flatpickr custom theme — compact */
        .flatpickr-input{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='15' height='15' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='4' width='18' height='18' rx='2'/%3E%3Cline x1='16' y1='2' x2='16' y2='6'/%3E%3Cline x1='8' y1='2' x2='8' y2='6'/%3E%3Cline x1='3' y1='10' x2='21' y2='10'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:32px!important;cursor:pointer}
        .flatpickr-calendar{border-radius:12px;box-shadow:0 8px 28px rgba(0,0,0,.14);border:1px solid #E2E8F0;font-family:inherit;overflow:hidden;width:252px!important}
        .flatpickr-rContainer{width:252px}
        .dayContainer{width:238px;min-width:238px;max-width:238px}
        .flatpickr-months{background:#0D9488;padding:4px 0;border-radius:0}
        .flatpickr-month{color:#fff;height:34px}
        .flatpickr-current-month{color:#fff;font-weight:700;font-size:13px;padding-top:8px;width:75%;left:12.5%}
        .flatpickr-current-month .flatpickr-monthDropdown-months{background:transparent;color:#fff;font-weight:700;font-size:13px}
        .flatpickr-current-month .numInputWrapper span{border-color:rgba(255,255,255,.3)}
        .flatpickr-current-month input.cur-year{color:#fff;font-weight:700;font-size:13px}
        .flatpickr-prev-month,.flatpickr-next-month{color:#fff!important;fill:#fff!important;padding:6px 10px;top:2px}
        .flatpickr-prev-month:hover svg,.flatpickr-next-month:hover svg{fill:rgba(255,255,255,.7)!important}
        .flatpickr-weekdays{background:#F0FDF4;border-bottom:1px solid #D1FAE5;height:28px}
        .flatpickr-weekday{color:#0D9488;font-weight:700;font-size:10px;line-height:28px}
        .flatpickr-days{width:252px}
        .flatpickr-day{border-radius:6px;font-size:12px;height:28px;line-height:28px;max-width:28px;margin:1.5px}
        .flatpickr-day:hover:not(.selected):not(.today){background:#F0FDF4;border-color:#D1FAE5}
        .flatpickr-day.selected,.flatpickr-day.selected:hover{background:#0D9488;border-color:#0D9488;color:#fff;font-weight:700}
        .flatpickr-day.today:not(.selected){border-color:#0D9488;color:#0D9488;font-weight:700}
        .flatpickr-day.flatpickr-disabled,.flatpickr-day.flatpickr-disabled:hover{color:#CBD5E1}
        /* Weekend */
        .flatpickr-day.fp-sat,.flatpickr-day.fp-sun{color:#EF4444}
        .flatpickr-day.fp-sat.selected,.flatpickr-day.fp-sun.selected,.flatpickr-day.fp-sat.today,.flatpickr-day.fp-sun.today{color:#fff;background:#EF4444;border-color:#EF4444}
        /* Libur nasional */
        .flatpickr-day.fp-holiday{color:#EF4444;font-weight:700}
        .flatpickr-day.fp-holiday::after{content:'';display:block;width:4px;height:4px;background:#EF4444;border-radius:50%;margin:-3px auto 0;position:relative;top:-2px}
        .flatpickr-day.fp-holiday.selected{color:#fff;background:#EF4444;border-color:#EF4444}
        .flatpickr-day.fp-holiday.selected::after{background:#fff}
        /* Cuti bersama */
        .flatpickr-day.fp-cuti{color:#F97316;font-weight:700}
        .flatpickr-day.fp-cuti::after{content:'';display:block;width:4px;height:4px;background:#F97316;border-radius:50%;margin:-3px auto 0;position:relative;top:-2px}
        .flatpickr-day.fp-cuti.selected{color:#fff;background:#F97316;border-color:#F97316}
        .flatpickr-day.fp-cuti.selected::after{background:#fff}
        </style>
        <?php if ($isMulti): ?>
        <style>
            .prop-tabs{display:flex;gap:4px;align-items:center}
            .prop-tab{padding:5px 14px;border-radius:6px;font-size:12px;font-weight:700;color:var(--ink2,#3D4A5C);text-decoration:none;transition:background .15s,color .15s;white-space:nowrap;border:1px solid var(--line,#e2e8f0);background:#fff}
            .prop-tab.active{background:var(--primary,#0D9488);color:#fff;border-color:var(--primary,#0D9488)}
            .prop-tab:hover:not(.active){background:var(--soft,#f0fdf4);color:var(--primary-dark,#0A7267)}
        </style>
        <?php endif; ?>
        <?php if (mobile_view_active()): ?>
        <style>
        :root { --m-nav-h: 62px; }
        .m-shell { min-height: 100vh; padding-bottom: calc(var(--m-nav-h) + env(safe-area-inset-bottom, 0px) + 14px); }
        .m-top { position: sticky; top: 0; z-index: 50; background: linear-gradient(135deg, var(--primary), var(--primary2)); color: #fff; padding: 14px 16px; padding-top: calc(14px + env(safe-area-inset-top, 0px)); display: flex; align-items: center; justify-content: space-between; gap: 12px; box-shadow: 0 2px 10px rgba(13,148,136,.25); }
        .m-top .t-title { font-size: 17px; font-weight: 800; line-height: 1.15; }
        .m-top .t-sub { font-size: 11.5px; opacity: .85; margin-top: 2px; }
        .m-top a.t-desk { color: #fff; font-size: 11px; font-weight: 700; background: rgba(255,255,255,.18); padding: 6px 11px; border-radius: 999px; display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
        .m-top .t-prop-sw { margin-top: 4px; max-width: 100%; width: auto; background: rgba(255,255,255,.18); color: #fff; border: none; border-radius: 8px; padding: 4px 26px 4px 9px; font-size: 12px; font-weight: 700; appearance: none; -webkit-appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23ffffff' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 8px center; }
        .m-top .t-prop-sw option { color: #0F1623; }
        .m-acct { position: relative; flex-shrink: 0; }
        .m-acct > summary { list-style: none; cursor: pointer; width: 38px; height: 38px; border-radius: 50%; background: rgba(255,255,255,.2); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 15px; }
        .m-acct > summary::-webkit-details-marker { display: none; }
        .m-acct[open] > summary { background: rgba(255,255,255,.35); }
        .m-acct .m-acct-menu { position: absolute; right: 0; top: calc(100% + 8px); z-index: 70; min-width: 210px; background: #fff; border-radius: 12px; box-shadow: 0 10px 30px rgba(16,24,40,.22); overflow: hidden; }
        .m-acct .m-acct-menu .hd { padding: 12px 15px; border-bottom: 1px solid var(--line); }
        .m-acct .m-acct-menu .hd b { display: block; font-size: 14px; color: var(--ink); }
        .m-acct .m-acct-menu .hd small { font-size: 11.5px; color: var(--muted); }
        .m-acct .m-acct-menu a { display: flex; align-items: center; gap: 9px; padding: 12px 15px; font-size: 13.5px; font-weight: 600; color: var(--ink2); text-decoration: none; border-top: 1px solid #f1f5f9; }
        .m-acct .m-acct-menu a:first-of-type { border-top: none; }
        .m-acct .m-acct-menu a.danger { color: #b91c1c; }
        .m-body { padding: 14px; }
        .m-flash { margin: 12px 14px 0; padding: 11px 14px; background: var(--soft); color: var(--primary-dark); border-radius: 10px; font-weight: 600; font-size: 13px; border-left: 3px solid var(--primary); }
        .m-nav { position: fixed; left: 0; right: 0; bottom: 0; z-index: 60; height: calc(var(--m-nav-h) + env(safe-area-inset-bottom, 0px)); padding-bottom: env(safe-area-inset-bottom, 0px); background: #fff; border-top: 1px solid var(--line); display: flex; box-shadow: 0 -2px 14px rgba(16,24,40,.06); }
        .m-nav a { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; color: var(--muted); font-size: 10.5px; font-weight: 700; }
        .m-nav a.active { color: var(--primary); }
        .m-sec-title { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); margin: 4px 2px 10px; }
        .m-card { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 16px; box-shadow: 0 1px 3px rgba(16,24,40,.05); margin-bottom: 13px; }

        /* ── Animasi halus (app-like). Hemat performa (transform/opacity) + hormati reduce-motion ── */
        @keyframes m-rise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
        @keyframes m-pop  { from { opacity: 0; transform: scale(.6); } to { opacity: 1; transform: scale(1); } }
        .m-body > * { animation: m-rise .4s cubic-bezier(.22,.61,.36,1) both; }
        .m-body > *:nth-child(2) { animation-delay: .04s; }
        .m-body > *:nth-child(3) { animation-delay: .08s; }
        .m-body > *:nth-child(4) { animation-delay: .12s; }
        .m-body > *:nth-child(5) { animation-delay: .16s; }
        .m-body > *:nth-child(6) { animation-delay: .20s; }
        .m-body > *:nth-child(n+7) { animation-delay: .24s; }
        /* umpan-balik sentuh: sedikit mengecil saat ditekan */
        .m-tx, .m-ofr-main, .m-qa a, .m-ofr .acts a, .m-tabs a, .m-mfil a, .m-seg a, .m-nav a, .m-fab, .m-prop,
        .m-acct .m-acct-menu a { transition: transform .12s ease, box-shadow .2s ease, background .2s ease, color .2s ease; }
        .m-tx:active, .m-ofr-main:active, .m-qa a:active, .m-ofr .acts a:active, .m-tabs a:active,
        .m-mfil a:active, .m-seg a:active, .m-acct .m-acct-menu a:active { transform: scale(.97); }
        .m-nav a:active { transform: scale(.9); }
        .m-nav a svg { transition: transform .2s cubic-bezier(.22,1.2,.4,1); }
        .m-nav a.active svg { transform: translateY(-1px) scale(1.08); }
        .m-fab { animation: m-pop .32s cubic-bezier(.22,1.2,.4,1) both .12s; }
        .m-fab:active { transform: scale(.9); }
        @media (prefers-reduced-motion: reduce) {
            .m-body > *, .m-fab { animation: none !important; }
            .m-tx, .m-ofr-main, .m-qa a, .m-nav a, .m-nav a svg, .m-fab { transition: none !important; transform: none !important; }
        }
        </style>
        <?php endif; ?>
    </head>
    <body>
    <script>
    /* Kontinuitas dari layar transisi login: tirai teal memudar (tanpa JS / reduced-motion: tidak ada) */
    (function(){try{
        if(sessionStorage.getItem('clara_from_login')!=='1')return;
        sessionStorage.removeItem('clara_from_login');
        if(window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches)return;
        var o=document.createElement('div');o.id='clara-from-login';o.setAttribute('aria-hidden','true');
        o.style.cssText='position:fixed;top:0;right:0;bottom:0;left:0;z-index:99999;pointer-events:none;opacity:1;background:linear-gradient(135deg,#0D9488,#115E59);transition:opacity .45s ease';
        document.body.appendChild(o);
        var kill=function(){if(o.parentNode)o.parentNode.removeChild(o);};
        requestAnimationFrame(function(){requestAnimationFrame(function(){o.style.opacity='0';});});
        o.addEventListener('transitionend',kill);setTimeout(kill,900);
    }catch(e){}})();
    </script>
    <?php ViewAs::banner(); ?>
    <?php $isMobile = mobile_view_active(); ?>
    <?php if ($isMobile): ?>
        <div class="m-shell">
            <div class="m-top">
                <div style="min-width:0">
                    <div class="t-title"><?= h($title) ?></div>
                    <?php if (!isset($opts['prop_label']) && $isMulti): ?>
                    <select class="t-prop-sw" onchange="if(this.value)location.href=this.value" aria-label="Ganti properti">
                        <?php foreach ($allowedProps as $ap): ?>
                        <option value="?r=switch_property&to=<?= (int)$ap['id'] ?>&back=<?= urlencode('?' . ($_SERVER['QUERY_STRING'] ?? '')) ?>" <?= ((int)$ap['id'] === $currentPid) ? 'selected' : '' ?>><?= h($ap['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <div class="t-sub"><?= h($appName) ?> &middot; <?= h($opts['prop_label'] ?? ($currentProp['name'] ?? '')) ?></div>
                    <?php endif; ?>
                </div>
                <?php
                $_uName = (string) ($_SESSION['user']['name'] ?? 'User');
                $_uRole = (string) ($_SESSION['user']['role'] ?? '');
                $_uInit = strtoupper(mb_substr(trim($_uName), 0, 1) ?: 'U');
                ?>
                <details class="m-acct">
                    <summary aria-label="Menu akun"><?= h($_uInit) ?></summary>
                    <div class="m-acct-menu">
                        <div class="hd"><b><?= h($_uName) ?></b><small><?= h($_uRole) ?></small></div>
                        <a href="?r=my_signature"><?= _m_icon('offer') ?> Tanda Tangan Saya</a>
                        <?php if (can('approve_skp')): ?><a href="?r=my_paraf"><?= _m_icon('offer') ?> Paraf Saya</a><?php endif; ?>
                        <a href="?r=change_password">🔑 Ganti Password</a>
                        <a href="?view=desktop"><?= _m_icon('desktop') ?> Tampilan Desktop</a>
                        <a class="danger" href="?r=logout">↩ Logout</a>
                    </div>
                </details>
            </div>
            <?php if ($flash): ?><div class="m-flash"><?= h($flash) ?></div><?php endif; ?>
            <div class="m-body"><?php $body(); ?></div>
        </div>
        <nav class="m-nav">
            <?php
            $_mtabs = [
                'home'  => ['m_home',         'Beranda',   'home',  'view_dashboard'],
                'offer' => ['m_offers',       'Penawaran', 'offer', 'manage_offers'],
                'tx'    => ['m_transactions', 'Transaksi', 'tx',    'view_transactions'],
                'exec'  => ['m_exec',         'Eksekutif', 'chart', 'view_exec_summary'],
                'renew' => ['renewals',       'Renewal',   'renew', 'view_renewals'],
            ];
            $_mact  = _mobile_active_tab($route);
            foreach ($_mtabs as $_k => [$_rt, $_lbl, $_ic, $_perm]):
                if (!can($_perm)) continue; ?>
                <a href="?r=<?= h($_rt) ?>" class="<?= $_mact === $_k ? 'active' : '' ?>"><?= _m_icon($_ic) ?><span><?= h($_lbl) ?></span></a>
            <?php endforeach; ?>
        </nav>
        <script>
        /* Bagikan PDF langsung ke WhatsApp/dll via Web Share API (FILE).
           PENTING: share file butuh secure context (HTTPS / localhost). Di HTTP
           biasa (mis. diakses via IP LAN) Web Share file TIDAK didukung — jangan
           dipaksa (akan "membocorkan" blob URL); langsung UNDUH PDF-nya saja. */
        function _claraPdfDownload(url, filename) {
            var a = document.createElement('a');
            a.href = url; a.download = (filename || 'dokumen') + '.pdf';
            a.target = '_blank'; a.rel = 'noopener';
            document.body.appendChild(a); a.click(); a.remove();
        }
        async function claraSharePdf(url, filename, btn) {
            var label = btn ? btn.innerHTML : '';
            // Tanpa secure context atau tanpa dukungan share file → unduh biasa.
            if (!window.isSecureContext || !navigator.canShare) { _claraPdfDownload(url, filename); return; }
            try {
                if (btn) { btn.style.pointerEvents = 'none'; btn.innerHTML = '⏳ Menyiapkan…'; }
                var res = await fetch(url, { credentials: 'same-origin' });
                if (!res.ok) throw new Error('http ' + res.status);
                var blob = await res.blob();
                var file = new File([blob], (filename || 'dokumen') + '.pdf', { type: 'application/pdf' });
                if (navigator.canShare({ files: [file] })) {
                    await navigator.share({ files: [file], title: filename });   // kirim FILE, bukan URL
                } else {
                    _claraPdfDownload(url, filename);
                }
            } catch (e) {
                if (!(e && e.name === 'AbortError')) _claraPdfDownload(url, filename);
            } finally {
                if (btn) { btn.style.pointerEvents = ''; btn.innerHTML = label; }
            }
        }
        </script>
    <?php else: ?>
    <div class="sidebar-overlay" id="sidebar-overlay" onclick="closeSidebar()"></div>
    <div class="app">
        <aside class="sidebar" id="sidebar">
            <div class="brand">
                <img class="brand-logo" src="assets/clara-logo.png" alt="CLARA">
                <img class="brand-mini" src="assets/icon-192.png" alt="CLARA">
            </div>
            <nav class="nav">
                <?php
                // Kelompok yang dilipat diingat di cookie supaya server langsung
                // merender keadaan yang sama — tanpa kedipan saat halaman dimuat.
                // Admin terlipat sebagai bawaan; kelompok berisi halaman aktif selalu terbuka.
                $_navState = [];
                foreach (explode(',', (string) ($_COOKIE['clara_nav'] ?? '')) as $_kv) {
                    if (preg_match('/^([a-z_]+)=([01])$/', $_kv, $_m)) $_navState[$_m[1]] = $_m[2] === '1';
                }
                $_groups = []; $_g = -1;
                foreach ($nav as [$group, $key, $label, $permission]) {
                    if ($group !== '' || $_g < 0) $_groups[++$_g] = ['label' => $group, 'items' => [], 'active' => false];
                    if (!can($permission)) continue;
                    parse_str('r=' . $key, $_kp);
                    $_active = ($route === ($_kp['r'] ?? ''));
                    if ($_active) {
                        foreach ($_kp as $_pk => $_pv) {
                            if ($_pk === 'r') continue;
                            if (getv($_pk) !== $_pv) { $_active = false; break; }
                        }
                    }
                    $_add = _nav_tambah($key);
                    if ($_add && !can($_add[1])) $_add = null;
                    $_groups[$_g]['items'][] = [$key, $label, $_active, (int) ($navBadge[$_kp['r'] ?? ''] ?? 0), $_add];
                    if ($_active) $_groups[$_g]['active'] = true;
                }
                foreach ($_groups as $_grp):
                    if (!$_grp['items']) continue;
                    $_slug = strtolower(preg_replace('/[^a-z]+/i', '_', $_grp['label']));
                    $_open = $_grp['active'] || ($_navState[$_slug] ?? ($_slug !== 'admin'));
                ?>
                <div class="nav-group<?= $_open ? '' : ' tutup' ?>"<?= $_grp['label'] !== '' ? ' data-grup="' . h($_slug) . '"' : '' ?>>
                    <?php if ($_grp['label'] !== ''): ?>
                    <button type="button" class="nav-group-btn" aria-expanded="<?= $_open ? 'true' : 'false' ?>">
                        <span><?= h($_grp['label']) ?></span><?= _nav_ikon('_turun') ?>
                    </button>
                    <?php endif; ?>
                    <div class="nav-group-isi">
                    <?php foreach ($_grp['items'] as [$key, $label, $_active, $_bdg, $_add]): ?>
                        <div class="nav-item">
                            <a class="<?= $_active ? 'active' : '' ?>" href="?r=<?= h($key) ?>" data-label="<?= h($label) ?>"<?=
                                $_add ? ' data-add="?r=' . h($_add[0]) . '" data-add-label="' . h($_add[2]) . '"' : '' ?>>
                                <span class="nav-ico"><?= _nav_ikon($key) ?></span>
                                <span class="nav-teks"><?= h($label) ?></span>
                                <?php if ($_bdg > 0): ?><span class="nav-bdg" title="<?= $_bdg ?> pengajuan menunggu keputusan Anda"><?= $_bdg ?></span><?php endif; ?>
                            </a>
                            <?php if ($_add): ?>
                            <a class="nav-add" href="?r=<?= h($_add[0]) ?>" title="<?= h($_add[2]) ?>" aria-label="<?= h($_add[2]) ?>"><?= _nav_ikon('_tambah') ?></a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="nav-logout">
                    <?php if (is_mobile_device()): ?>
                    <div class="nav-item"><a href="?view=mobile" data-label="Tampilan HP"><span class="nav-ico"><?= _nav_ikon('_hp') ?></span><span class="nav-teks">Tampilan HP</span></a></div>
                    <?php endif; ?>
                    <div class="nav-item"><a href="?r=logout" class="nav-keluar" data-label="Logout"><span class="nav-ico"><?= _nav_ikon('_logout') ?></span><span class="nav-teks">Logout</span></a></div>
                </div>
            </nav>
            <div class="nav-kaki">
                <div class="nav-versi">
                    <div>CLARA &middot; v<?= h($config['app_version'] ?? '1.0') ?></div>
                    <small>&copy; 2026 IT Dept. PT. Wulandari Bangun Laksana Tbk.</small>
                </div>
                <button type="button" class="nav-ciut" id="nav-ciut" title="Ciutkan / lebarkan menu" aria-label="Ciutkan atau lebarkan menu"><?= _nav_ikon('_ciut') ?></button>
            </div>
        </aside>
        <div class="nav-fly" id="nav-fly" role="tooltip"></div>
        <main class="main">
            <?php
            // Keterangan kecil di atas judul = kelompok menu halaman ini (dari menu samping).
            $_hdrGrup = '';
            foreach ($_groups as $_grp) if ($_grp['active']) $_hdrGrup = $_grp['label'] !== '' ? $_grp['label'] : 'Ringkasan';
            if ($_hdrGrup === '') {
                // Halaman turunan yang tidak ada di menu (formulir, detail) ikut kelompok induknya.
                foreach (['offer' => 'Input', 'skp' => 'Input', 'contract_request' => 'Input', 'prospect' => 'Input',
                          'transaction' => 'Input', 'payment' => 'Input', 'deletion' => 'Input', 'allocation' => 'Input',
                          'master' => 'Master Data', 'client' => 'Master Data', 'lookup' => 'Admin',
                          'user' => 'Admin', 'role' => 'Admin', 'audit' => 'Admin', 'pic_' => 'Analisa',
                          'calendar' => 'Analisa', 'unit_analysis' => 'Ringkasan'] as $_pre => $_lbl) {
                    if (str_starts_with((string) $route, $_pre)) { $_hdrGrup = $_lbl; break; }
                }
            }
            $_uNama = (string) ($_SESSION['user']['name'] ?? 'User');
            ?>
            <header class="topbar hdr">
                <div class="hdr-kiri">
                    <button class="hamburger" onclick="openSidebar()" title="Menu" aria-label="Buka menu">&#9776;</button>
                    <div class="hdr-judul">
                        <div class="hdr-jejak"><?= h($_hdrGrup !== '' ? $_hdrGrup : $appName) ?></div>
                        <h1><?= h($title) ?></h1>
                    </div>
                </div>
                <div class="hdr-kanan">
                    <?php if ($isMulti && empty($opts['hide_prop_tabs'])): ?>
                    <nav class="prop-tabs hdr-prop" aria-label="Pilih properti">
                        <?php foreach ($allowedProps as $ap): $_on = (int) $ap['id'] === $currentPid; ?>
                        <a href="?r=switch_property&to=<?= (int)$ap['id'] ?>&back=<?= urlencode('?' . $_SERVER['QUERY_STRING']) ?>"
                           class="prop-tab<?= $_on ? ' active' : '' ?>"<?= $_on ? ' aria-current="true"' : '' ?>>
                            <span class="hdr-dot"></span><?= h($ap['name']) ?>
                        </a>
                        <?php endforeach; ?>
                    </nav>
                    <?php else: ?>
                    <div class="hdr-prop hdr-prop-satu"><span class="prop-tab active"><span class="hdr-dot"></span><?= h($currentProp['name']) ?></span></div>
                    <?php endif; ?>
                    <div class="hdr-user" title="<?= h($_uNama) ?> · <?= h(roles()[current_role()] ?? current_role()) ?>">
                        <span class="hdr-avatar"><?= h(mb_strtoupper(mb_substr($_uNama, 0, 1))) ?></span>
                        <span class="hdr-user-teks"><b><?= h($_uNama) ?></b><small><?= h(roles()[current_role()] ?? current_role()) ?></small></span>
                    </div>
                </div>
            </header>
            <?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
            <?php
            /* Kode sudah diperbarui, databasenya belum.
               Sampai `php db_migrate.php` dijalankan, setiap penyimpanan yang
               menyentuh kolom baru gagal dengan HTTP 500 tanpa keterangan.
               Peringatan ini mengubahnya jadi satu kalimat yang bisa ditindak,
               dan hanya diperlihatkan kepada yang bisa menjalankannya. */
            $_migrasiSisa = (isset($pdo) && $pdo instanceof PDO && in_array(current_role(), ['superadmin', 'admin'], true))
                ? clara_migrasi_tertunda($pdo) : [];
            ?>
            <?php if ($_migrasiSisa): ?>
            <div style="margin:0 0 12px;padding:12px 16px;border-radius:10px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;font-size:13px;line-height:1.55">
                <strong>&#9888; Database belum diperbarui &mdash; <?= count($_migrasiSisa) ?> migrasi belum dijalankan.</strong><br>
                Kode di server ini sudah versi baru, tetapi kolom barunya belum ada. Penyimpanan dokumen
                (terutama Gudang &amp; Media) akan <b>gagal dengan Error 500</b> sampai ini dijalankan.
                Buka <b>cPanel &rarr; Terminal</b>, lalu:
                <div style="margin:7px 0 4px;padding:7px 10px;background:#fff;border:1px solid #fecaca;border-radius:7px;font-family:monospace;font-size:12px">cd ~/public_html/clara &amp;&amp; php db_migrate.php</div>
                <span style="font-size:11.5px;color:#7f1d1d">Belum jalan: <?= h(implode(', ', array_slice($_migrasiSisa, 0, 6))) ?><?= count($_migrasiSisa) > 6 ? ' &hellip;' : '' ?></span>
            </div>
            <?php endif; ?>
            <?php $body(); ?>
        </main>
    </div>
    <?php endif; ?>
    <?php if (!$isMobile && !empty($_SESSION['_show_welcome']) && isset($pdo)):
        unset($_SESSION['_show_welcome']);
        $wpPeriod = date('Y-m');
        $wpPid    = current_property_id();
        $wpUser   = $_SESSION['user'] ?? [];
        $wpProp   = current_property();
        $wpName   = $wpUser['name'] ?? 'User';

        // Semua property_id yang boleh diakses user ini
        $wpPids = array_map('intval', array_column($allowedProps, 'id'));
        $wpPh   = implode(',', $wpPids);

        // Cek apakah user adalah PIC sales (cari di semua properti yang diakses)
        $wpPic = null;
        if (!empty($wpUser['id'])) {
            $s = $pdo->prepare(
                'SELECT p.name AS pic_name, p.target_share, p.property_id,
                        COALESCE(SUM(a.amount),0) AS actual
                 FROM master_pic p
                 LEFT JOIN transaction_allocations a
                        ON a.pic_name=p.name AND a.period_key=? AND a.property_id=p.property_id
                 WHERE p.user_id=? AND p.status=\'active\'
                 GROUP BY p.id LIMIT 1'
            );
            $s->execute([$wpPeriod, $wpUser['id']]);
            $wpPic = $s->fetch() ?: null;
        }

        // Target: gabungkan semua properti yang diakses
        $s = $pdo->query("SELECT COALESCE(SUM(target_amount),0) FROM targets_monthly WHERE period_key='$wpPeriod' AND property_id IN ($wpPh)");
        $wpTarget = (float)($s->fetchColumn() ?: 0);

        if ($wpPic) {
            // PIC: pakai target properti spesifik milik PIC tersebut
            $sPicTarget = $pdo->prepare('SELECT COALESCE(target_amount,0) FROM targets_monthly WHERE period_key=? AND property_id=?');
            $sPicTarget->execute([$wpPeriod, (int)$wpPic['property_id']]);
            $wpPicPropTarget = (float)($sPicTarget->fetchColumn() ?: 0);
            $wpActual     = (float)$wpPic['actual'];
            $wpMyTarget   = $wpPicPropTarget * (float)$wpPic['target_share']; // target_share = pecahan (0.25 = 25%)
            $wpAchieve    = $wpMyTarget > 0 ? round($wpActual / $wpMyTarget * 100) : 0;
            $wpSubject    = $wpPic['pic_name'];
            $wpHeading    = 'Achievement Pribadi';
            $wpTargetShow = $wpMyTarget;
        } else {
            // Non-PIC: per-property achievement breakdown
            $wpHeading = 'Ringkasan Properti';
            $wpProps   = [];
            foreach ($allowedProps as $_ap) {
                $_apId  = (int)$_ap['id'];
                $_sT    = $pdo->prepare('SELECT COALESCE(target_amount,0) FROM targets_monthly WHERE period_key=? AND property_id=?');
                $_sT->execute([$wpPeriod, $_apId]);
                $_apTarget  = (float)($_sT->fetchColumn() ?: 0);
                $_sA    = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM transaction_allocations WHERE period_key=? AND property_id=?');
                $_sA->execute([$wpPeriod, $_apId]);
                $_apActual  = (float)$_sA->fetchColumn();
                $_apAchieve = $_apTarget > 0 ? round($_apActual / $_apTarget * 100) : 0;
                $wpProps[]  = [
                    'name'    => $_ap['name'],
                    'actual'  => $_apActual,
                    'target'  => $_apTarget,
                    'achieve' => $_apAchieve,
                    'color'   => $_apAchieve >= 100 ? '#10B981' : ($_apAchieve >= 80 ? '#F59E0B' : '#EF4444'),
                ];
            }
            $wpActual     = array_sum(array_column($wpProps, 'actual'));
            $wpAchieve    = count($wpProps) === 1 ? $wpProps[0]['achieve'] : 0;
            $wpSubject    = count($wpProps) === 1 ? ($allowedProps[0]['name'] ?? '') : '';
            $wpTargetShow = $wpTarget;
        }

        $wpColor  = $wpAchieve >= 100 ? '#10B981' : ($wpAchieve >= 80 ? '#F59E0B' : '#EF4444');
        $wpMonth  = period_label($wpPeriod);
    ?>
    <style>
    @keyframes wpFadeIn{from{opacity:0}to{opacity:1}}
    @keyframes wpSlideUp{from{opacity:0;transform:translateY(20px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
    @keyframes wpBar{from{width:100%}to{width:0%}}
    #wp-overlay{position:fixed;inset:0;background:rgba(10,18,30,.5);z-index:9000;display:flex;align-items:center;justify-content:center;animation:wpFadeIn .2s ease;backdrop-filter:blur(2px)}
    #wp-card{background:#fff;border-radius:16px;width:min(380px,92vw);box-shadow:0 28px 70px rgba(0,0,0,.25);animation:wpSlideUp .35s cubic-bezier(.22,.68,0,1.2);overflow:hidden;position:relative}
    #wp-close{position:absolute;top:11px;right:12px;width:26px;height:26px;border:none;background:rgba(255,255,255,.2);border-radius:50%;cursor:pointer;font-size:17px;color:#fff;line-height:1;z-index:1;display:flex;align-items:center;justify-content:center}
    #wp-close:hover{background:rgba(255,255,255,.35)}
    #wp-head{background:linear-gradient(-45deg,#115E59,#0D9488,#0891B2);background-size:200% 200%;animation:bgShift 8s ease infinite;padding:22px 24px 18px;color:#fff}
    #wp-head h2{font-size:17px;font-weight:800;margin:0 0 3px}
    #wp-head p{font-size:12px;opacity:.82;margin:0}
    #wp-body{padding:18px 24px 14px}
    .wp-row{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px}
    .wp-lbl{font-size:11px;color:#7B8A9C;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
    .wp-val{font-size:14px;font-weight:700;color:#0F1623}
    .wp-pct{font-size:32px;font-weight:900;text-align:center;padding:6px 0 14px;letter-spacing:-.5px}
    .wp-pct small{font-size:14px;opacity:.6;font-weight:700}
    .wp-prop-row{display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid #F1F5F9}
    .wp-prop-row:last-child{border-bottom:none;padding-bottom:4px}
    .wp-prop-name{font-size:13px;font-weight:700;color:#0F1623}
    .wp-prop-pct{font-size:24px;font-weight:900;letter-spacing:-.5px}
    #wp-bar-wrap{height:3px;background:#F1F5F9;overflow:hidden}
    #wp-bar{height:100%;background:linear-gradient(90deg,#0D9488,#0891B2);animation:wpBar 3s linear forwards}
    </style>
    <div id="wp-overlay" onclick="if(event.target===this)wpClose()">
        <div id="wp-card">
            <button id="wp-close" onclick="wpClose()">&#x2715;</button>
            <div id="wp-head">
                <h2>Ringkasan Anda hari ini</h2>
                <p><?= h($wpHeading) ?> &middot; <?= h($wpMonth) ?><?= $wpSubject !== '' ? ' &middot; ' . h($wpSubject) : '' ?></p>
            </div>
            <div id="wp-body">
                <?php if (!empty($wpProps) && count($wpProps) > 1): ?>
                    <?php foreach ($wpProps as $_wp): ?>
                    <div class="wp-prop-row">
                        <span class="wp-prop-name"><?= h($_wp['name']) ?></span>
                        <span class="wp-prop-pct" style="color:<?= $_wp['color'] ?>"><?= $_wp['achieve'] ?><small style="font-size:13px;font-weight:700;opacity:.65">%</small></span>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="wp-row">
                    <span class="wp-lbl">Aktual</span>
                    <span class="wp-val">Rp&nbsp;<?= number_format($wpActual, 0, ',', '.') ?></span>
                </div>
                <div class="wp-row">
                    <span class="wp-lbl">Target</span>
                    <span class="wp-val">Rp&nbsp;<?= number_format($wpTargetShow, 0, ',', '.') ?></span>
                </div>
                <div class="wp-pct" style="color:<?= $wpColor ?>"><?= $wpAchieve ?><small>%</small></div>
                <?php endif; ?>
            </div>
            <div id="wp-bar-wrap"><div id="wp-bar"></div></div>
        </div>
    </div>
    <script>
    function wpClose(){var o=document.getElementById('wp-overlay');if(!o)return;o.style.transition='opacity .18s';o.style.opacity='0';setTimeout(function(){o.remove();},200);}
    setTimeout(wpClose, 3000);
    </script>
    <?php endif; ?>
    <script>
    <?php if (!$isMobile): ?>
    function openSidebar(){document.getElementById('sidebar').classList.add('open');document.getElementById('sidebar-overlay').classList.add('active');document.body.style.overflow='hidden';}
    function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebar-overlay').classList.remove('active');document.body.style.overflow='';}
    (function(){var sb=document.querySelector('#sidebar .nav')||document.getElementById('sidebar');var ss=sessionStorage.getItem('_sb_scroll');if(ss)sb.scrollTop=parseInt(ss,10);document.querySelectorAll('.nav a').forEach(function(a){a.addEventListener('click',function(){sessionStorage.setItem('_sb_scroll',sb.scrollTop);closeSidebar();});});})();
    /* Menu samping: ciut/lebar, kelompok terlipat, label melayang saat ciut.
       Status disimpan di cookie supaya server merender keadaan yang sama. */
    (function(){
        var html=document.documentElement, sb=document.getElementById('sidebar'), fly=document.getElementById('nav-fly');
        if(!sb||!fly)return;
        function simpan(n,v){document.cookie=n+'='+v+';path=/;max-age=31536000;samesite=lax';}
        function mini(){return html.classList.contains('sb-mini')&&window.matchMedia('(min-width: 769px)').matches;}
        document.getElementById('nav-ciut').addEventListener('click',function(){
            html.classList.toggle('sb-mini'); simpan('clara_sb',html.classList.contains('sb-mini')?'mini':'wide'); sembunyi();
        });
        sb.querySelectorAll('.nav-group-btn').forEach(function(b){
            b.addEventListener('click',function(){
                var g=b.parentElement; g.classList.toggle('tutup');
                b.setAttribute('aria-expanded',g.classList.contains('tutup')?'false':'true');
                var st=[]; sb.querySelectorAll('.nav-group[data-grup]').forEach(function(x){st.push(x.dataset.grup+'='+(x.classList.contains('tutup')?0:1));});
                simpan('clara_nav',st.join(','));
            });
        });
        // Jenis penunjuk terakhir (mouse/touch/pen) — lebih andal daripada
        // media query (hover) yang keliru di sebagian perangkat hibrida.
        var tunda=null, aktif=null, penunjuk='mouse';
        document.addEventListener('pointerdown',function(e){penunjuk=e.pointerType||'mouse';},true);
        function sembunyi(){fly.classList.remove('show'); aktif=null;}
        function tampil(a){
            clearTimeout(tunda); aktif=a;
            var esc=function(t){var d=document.createElement('div');d.textContent=t;return d.innerHTML;};
            fly.innerHTML='<a class="nav-fly-lbl" href="'+a.getAttribute('href')+'">'+esc(a.dataset.label||'')+'</a>'
                +(a.dataset.add?'<a class="nav-fly-add" href="'+a.dataset.add+'" title="'+esc(a.dataset.addLabel||'Baru')+'">'+document.querySelector('.nav-add svg').outerHTML+'</a>':'');
            var r=a.getBoundingClientRect(), sr=sb.getBoundingClientRect();
            fly.style.left=(sr.right+10)+'px';
            fly.classList.add('show');
            fly.style.top=Math.max(8,r.top+r.height/2-fly.offsetHeight/2)+'px';
        }
        sb.querySelectorAll('.nav-item > a:first-child').forEach(function(a){
            a.addEventListener('pointerenter',function(e){ if(mini()&&e.pointerType==='mouse') tampil(a); });
            a.addEventListener('pointerleave',function(e){ if(mini()&&e.pointerType==='mouse') tunda=setTimeout(sembunyi,160); });
            a.addEventListener('click',function(e){
                // Layar sentuh tidak punya "arahkan kursor": ketukan pertama menampilkan label, ketukan kedua membuka.
                if(mini()&&penunjuk!=='mouse'&&aktif!==a){ e.preventDefault(); e.stopImmediatePropagation(); tampil(a); }
            });
        });
        fly.addEventListener('pointerenter',function(){clearTimeout(tunda);});
        fly.addEventListener('pointerleave',function(e){ if(e.pointerType==='mouse') tunda=setTimeout(sembunyi,160); });
        (sb.querySelector('.nav')||sb).addEventListener('scroll',sembunyi);
        // Menu aktif yang berada di luar layar (dibuka lewat tautan, bukan klik menu) digulir ke tempat terlihat.
        var nv=sb.querySelector('.nav'), ak=sb.querySelector('.nav-item > a.active');
        if(nv&&ak){var r=ak.getBoundingClientRect(), n=nv.getBoundingClientRect(); if(r.top<n.top||r.bottom>n.bottom) nv.scrollTop+=r.top-n.top-n.height/2+r.height/2;}
        document.addEventListener('click',function(e){ if(fly.classList.contains('show')&&!fly.contains(e.target)&&!sb.contains(e.target)) sembunyi(); });
    })();
    <?php endif; ?>
    (function(){
        var timeout=<?= SESSION_TIMEOUT ?>,warnBefore=120,warnAt=(timeout-warnBefore)*1000,logoutAt=timeout*1000;
        var overlay=document.createElement('div');
        overlay.id='session-warn';
        overlay.style.cssText='display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center';
        overlay.innerHTML='<div style="background:#fff;border-radius:14px;padding:32px 36px;max-width:360px;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.18)"><div style="font-size:32px;margin-bottom:12px">⏳</div><div style="font-weight:800;font-size:16px;margin-bottom:8px;color:#0F1623">Sesi Hampir Berakhir</div><div style="color:#7B8A9C;font-size:13px;margin-bottom:20px">Anda akan otomatis keluar dalam <b id="secs">120</b> detik.<br>Klik tombol di bawah untuk tetap masuk.</div><button onclick="location.reload()" style="background:#0D9488;color:#fff;border:none;border-radius:8px;padding:10px 28px;font-weight:700;font-size:14px;cursor:pointer">Tetap Masuk</button></div>';
        document.body.appendChild(overlay);
        setTimeout(function(){overlay.style.display='flex';var secs=warnBefore;var tick=setInterval(function(){secs--;var el=document.getElementById('secs');if(el)el.textContent=secs;if(secs<=0)clearInterval(tick);},1000);},warnAt);
        setTimeout(function(){window.location.href='?r=logout';},logoutAt);
    })();
    /* Anti klik-ganda. e.defaultPrevented wajib diperiksa: validasi milik
       halaman (mis. "pembagian income belum pas") memanggil preventDefault di
       listener form, dan event-nya tetap naik ke sini. Tanpa pemeriksaan ini
       formnya ikut ditandai terkirim lalu tombolnya mati permanen — submit yang
       sudah diperbaiki tidak pernah bisa dikirim sampai halaman dimuat ulang. */
    /* PENTING: tombolnya dimatikan SETELAH data formulir disusun peramban,
       bukan di dalam listener ini. Tombol yang sudah disabled TIDAK ikut
       terkirim — padahal banyak formulir di sini memakai dua tombol submit yang
       membedakan maksudnya lewat name/value (keputusan=setuju vs tolak,
       putusan, ke_pic). Mematikannya terlalu cepat membuat nilai itu lenyap,
       dan sisi server membacanya sebagai pilihan sebaliknya: "Setujui Revisi"
       tercatat sebagai Ditolak. setTimeout(...,0) menunda sampai pengiriman
       sudah disusun, sehingga penjaga klik-ganda tetap jalan tanpa menelan
       nilai tombolnya. */
    /* Pengiriman yang membuka TAB BARU tidak meninggalkan halaman ini. Tombol
       "Lihat Contoh Surat" di Surat Penawaran mengirim formulir yang SAMA dengan
       formaction + formtarget="_blank": pratinjaunya terbuka di tab lain,
       formulirnya tetap di layar dan masih dipakai. Menguncinya membuat tombol
       Simpan tertinggal "Menyimpan..." selamanya — dan penjaga klik-ganda di
       bawah menolak setiap percobaan simpan berikutnya. */
    function claraTabBaru(e){
        var s=e.submitter||document.activeElement;
        var t=(s&&s.getAttribute&&s.getAttribute('formtarget'))||e.target.getAttribute('target')||'';
        return t!==''&&t!=='_self';
    }
    document.addEventListener('submit',function(e){if(e.defaultPrevented)return;if(claraTabBaru(e))return;var form=e.target;if(form.dataset.submitted){e.preventDefault();return;}form.dataset.submitted='1';setTimeout(function(){form.querySelectorAll('button[type=submit]').forEach(function(btn){btn.disabled=true;btn.dataset.orig=btn.textContent;btn.textContent='Menyimpan...';});},0);});
    /* Kembali ke halaman ini lewat tombol Back memulihkannya dari cache peramban
       APA ADANYA — termasuk tombol yang sudah dimatikan. Tanpa ini formulirnya
       terlihat menggantung "Menyimpan..." padahal tidak ada yang sedang jalan. */
    window.addEventListener('pageshow',function(e){
        if(!e.persisted)return;
        document.querySelectorAll('form[data-submitted]').forEach(function(form){
            delete form.dataset.submitted;
            form.querySelectorAll('button[type=submit]').forEach(function(btn){
                btn.disabled=false;
                if(btn.dataset.orig){btn.textContent=btn.dataset.orig;delete btn.dataset.orig;}
            });
        });
    });
    /* Salin teks ke clipboard dgn fallback. navigator.clipboard hanya ada di
       secure context (HTTPS/localhost); di PWA via IP LAN (HTTP) ia undefined,
       jadi tombol Salin "diam" tanpa fallback. Fallback: textarea + execCommand. */
    function claraCopyText(text, btn, okLabel){
        okLabel = okLabel || 'Tersalin ✓';
        var orig = btn ? btn.textContent : '';
        function done(ok){
            if(!btn) return;
            btn.textContent = ok ? okLabel : 'Gagal — salin manual';
            setTimeout(function(){ btn.textContent = orig; }, 2000);
        }
        function fallback(){
            try{
                var ta=document.createElement('textarea');
                ta.value=text; ta.setAttribute('readonly','');
                ta.style.cssText='position:fixed;top:0;left:0;opacity:0';
                document.body.appendChild(ta);
                ta.select(); ta.setSelectionRange(0, text.length);
                var ok=document.execCommand('copy');
                ta.remove(); done(ok);
            }catch(e){ done(false); }
        }
        if(navigator.clipboard && window.isSecureContext){
            navigator.clipboard.writeText(text).then(function(){ done(true); }, fallback);
        }else{ fallback(); }
    }
    </script>
    <script src="assets/flatpickr.min.js"></script>
    <script src="assets/spread-table.js"></script>
    <script src="assets/mobile-tables.js?v=<?= CSS_VER ?>"></script>
    <script>
    (function(){
        if(typeof flatpickr==='undefined') return;
        var _fpHolidays = {};

        /* custom tooltip */
        var _tip = document.createElement('div');
        _tip.style.cssText = 'display:none;position:fixed;z-index:9999999;background:#1E293B;color:#fff;font-size:11px;line-height:1.4;padding:5px 9px;border-radius:6px;pointer-events:none;white-space:nowrap;box-shadow:0 4px 12px rgba(0,0,0,.25);max-width:200px;white-space:normal';
        document.body.appendChild(_tip);
        function _tipShow(text, el) {
            _tip.textContent = text;
            _tip.style.display = 'block';
            var r = el.getBoundingClientRect();
            var tx = r.left + r.width / 2 - _tip.offsetWidth / 2;
            var ty = r.top - _tip.offsetHeight - 6;
            if (tx < 4) tx = 4;
            if (ty < 4) ty = r.bottom + 6;
            _tip.style.left = tx + 'px';
            _tip.style.top  = ty + 'px';
        }
        function _tipHide() { _tip.style.display = 'none'; }

        function _fpFetch(year, cb) {
            var yr = String(year);
            if (_fpHolidays[yr] !== undefined) { cb(_fpHolidays[yr]); return; }
            _fpHolidays[yr] = {};
            fetch('https://libur.deno.dev/api?year=' + yr, {cache:'force-cache'})
                .then(function(r){ return r.json(); })
                .then(function(data){
                    data.forEach(function(h){
                        _fpHolidays[yr][h.date] = { n: h.name, c: !h.is_national_holiday };
                    });
                    cb(_fpHolidays[yr]);
                })
                .catch(function(){ cb({}); });
        }

        function _fpMark(fp) {
            _fpFetch(fp.currentYear, function(){ fp.redraw(); });
        }

        var curYear = new Date().getFullYear();
        _fpFetch(curYear, function(){});
        _fpFetch(curYear + 1, function(){});

        var fpCfg = {
            dateFormat: 'Y-m-d',
            locale: { firstDayOfWeek: 1 },
            disableMobile: true,
            onDayCreate: function(_d, _s, _fp, day) {
                var wd  = day.dateObj.getDay();
                var yr  = day.dateObj.getFullYear();
                var mm  = String(day.dateObj.getMonth() + 1).padStart(2, '0');
                var dd  = String(day.dateObj.getDate()).padStart(2, '0');
                var ds  = yr + '-' + mm + '-' + dd;
                var hl  = (_fpHolidays[yr] || {})[ds];
                if (wd === 6) day.classList.add('fp-sat');
                if (wd === 0) day.classList.add('fp-sun');
                if (hl) {
                    day.classList.add(hl.c ? 'fp-cuti' : 'fp-holiday');
                    day.addEventListener('mouseenter', function(){ _tipShow(hl.n, day); });
                    day.addEventListener('mouseleave', _tipHide);
                }
            },
            onOpen:        function(_d, _s, fp){ _fpMark(fp); },
            onClose:       function(){ _tipHide(); },
            onMonthChange: function(_d, _s, fp){ _fpMark(fp); },
            onYearChange:  function(_d, _s, fp){ _fpMark(fp); }
        };

        /* Pemasang kalender yang bisa dipanggil ulang.
           flatpickr MENGUBAH input tanggal menjadi text + readonly dan memasang
           kalendernya pada elemen itu sendiri. Baris tabel yang digandakan lewat
           cloneNode() ikut membawa bentuk hasil ubahan itu, tetapi TIDAK membawa
           kalendernya — hasilnya kolom tanggal di baris baru readonly dan tidak
           bereaksi sama sekali saat diklik. Di sini bentuknya dikembalikan dulu
           ke input tanggal biasa, baru dipasangi kalender lagi. */
        function pasangTanggal(el) {
            if (!el || el._flatpickr) return;
            if (el.classList.contains('flatpickr-input')) {
                el.classList.remove('flatpickr-input');
                el.removeAttribute('readonly');
                el.readOnly = false;
                try { el.type = 'date'; } catch (e) {}
            }
            flatpickr(el, Object.assign({}, fpCfg));
        }
        window.claraTanggal = function (scope) {
            (scope || document).querySelectorAll('input[type=date], input.flatpickr-input')
                .forEach(pasangTanggal);
        };
        window.claraTanggal(document);
    })();
    </script>
    </body>
    </html>
    <?php
}

function periods(PDO $pdo): array
{
    $pid = current_property_id();
    $allKeys = $pdo->prepare(
        "SELECT DISTINCT pk FROM (
             SELECT period_key pk FROM periods WHERE property_id = ?
             UNION SELECT DISTINCT period_key pk FROM transaction_allocations WHERE property_id = ?
             UNION SELECT DISTINCT period_key pk FROM targets_monthly WHERE property_id = ?
         ) t ORDER BY pk ASC"
    );
    $allKeys->execute([$pid, $pid, $pid]);
    $allKeys = $allKeys->fetchAll(PDO::FETCH_COLUMN);

    if (empty($allKeys)) return [];

    $placeholders = implode(',', array_fill(0, count($allKeys), '?'));
    $stmt = $pdo->prepare("SELECT * FROM periods WHERE property_id = $pid AND period_key IN ($placeholders)");
    $stmt->execute($allKeys);
    $registered = [];
    foreach ($stmt->fetchAll() as $r) {
        $registered[$r['period_key']] = $r;
    }

    return array_map(function (string $pk) use ($registered): array {
        return $registered[$pk] ?? [
            'period_key' => $pk,
            'label'      => period_label($pk),
            'starts_on'  => $pk . '-01',
            'ends_on'    => (new DateTimeImmutable($pk . '-01'))->modify('last day of this month')->format('Y-m-d'),
        ];
    }, $allKeys);
}

function masterOptions(PDO $pdo, string $module): array
{
    $pid = current_property_id();

    if ($module === 'cl') {
        $s = $pdo->prepare("SELECT code, location_name label, rate, 'daily_area' pricing_type, area_sqm, 1 quantity, 1 slots FROM master_cl_units WHERE status='active' AND property_id=? ORDER BY sort_order ASC, CASE floor WHEN 'LG' THEN 1 WHEN 'GF' THEN 2 WHEN 'UG' THEN 3 WHEN 'FF' THEN 4 WHEN 'SF' THEN 5 ELSE 6 END, code");
    } elseif ($module === 'gudang') {
        $s = $pdo->prepare("SELECT code, name label, ROUND(monthly_rate * area_sqm) rate, 'monthly' pricing_type, area_sqm, 1 quantity, 1 slots FROM master_gudang WHERE status='active' AND property_id=? ORDER BY sort_order ASC, code");
    } else {
        $concat = "CONCAT(media_type, ' - ', location, ' - ', COALESCE(point,''))";
        $s = $pdo->prepare("SELECT code, $concat label, rate, pricing_type, 0 area_sqm, quantity, slots, COALESCE(size,'') size, media_type FROM master_media WHERE status='active' AND property_id=? ORDER BY sort_order ASC, code");
    }

    $s->execute([$pid]);
    return $s->fetchAll();
}

function client_options(?PDO $pdo = null): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $defaults = [
        'business_type'  => ['F&B / Kuliner','Fashion & Apparel','Kecantikan & Skincare','Kesehatan & Farmasi','Elektronik & Gadget','Hobi & Lifestyle','Olahraga & Outdoor','Pendidikan & Kursus','Perbankan & Keuangan','Properti & Developer','Otomotif','Travel & Wisata','Hiburan & Event','Aksesori & Perhiasan','Lain-lain'],
        'business_scale' => ['UKM / UMKM', 'Lokal', 'Nasional'],
        'brand_origin'   => ['Lokal', 'Asing / Internasional', 'Franchise'],
        'target_segment' => ['Family', 'Youth', 'Young Professional', 'Premium', 'Mass Market', 'Mixed'],
        'channel'        => ['Offline', 'Online', 'Omnichannel'],
    ];
    if ($pdo) {
        try {
            $pid  = current_property_id();
            $rows = $pdo->prepare("SELECT category, value FROM master_lookup_options WHERE status='active' AND property_id=? ORDER BY sort_order, value");
            $rows->execute([$pid]);
            $rows = $rows->fetchAll();
            $db = [];
            foreach ($rows as $r) $db[$r['category']][] = $r['value'];
            foreach ($defaults as $cat => $_) {
                if (!empty($db[$cat])) $defaults[$cat] = $db[$cat];
            }
        } catch (Throwable $e) {}
    }
    $cache = $defaults;
    return $cache;
}
