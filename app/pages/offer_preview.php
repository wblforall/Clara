<?php
/**
 * Pratinjau Surat Penawaran — dari isian yang SEDANG diketik, belum disimpan.
 *
 * Surat penawaran hanya bisa dicetak setelah nomornya terbit, dan nomor terbit
 * saat penawarannya disimpan. Akibatnya sales harus menyimpan dulu hanya untuk
 * melihat hasil jadinya — dan kalau ternyata ada yang salah, penawaran setengah
 * jadi sudah terlanjur ada beserta nomornya.
 *
 * Halaman ini menerima POST dari formulir penawaran dan merender surat yang
 * sama persis dengan yang nanti tercetak, memakai berkas templat yang sama
 * (offer_print_template.php) supaya tidak ada versi kedua yang bisa melenceng.
 * Tidak ada satu pun baris yang ditulis ke basis data.
 *
 * Hasilnya dicap melintang "CONTOH — BELUM SAH" dan nomornya sengaja kosong,
 * jadi tidak akan pernah bisa disodorkan sebagai surat sungguhan.
 */

function offer_preview(PDO $pdo): void
{
    require_permission('manage_offers');
    verify_csrf();
    // offer_letter(), offer_unit_type() dan kawan-kawannya tinggal di offers.php;
    // router hanya memuat berkas yang dipetakan, jadi dipanggil sendiri di sini.
    require_once __DIR__ . '/offers.php';
    $pid    = current_property_id();
    $module = in_array(post('module'), ['cl', 'media', 'gudang'], true) ? (string) post('module') : 'cl';

    $num = static fn(string $k, float $def = 0.0): float => (float) preg_replace('/[^0-9.\-]/', '', (string) post($k, (string) $def));
    $txt = static fn(string $k): ?string => (($v = trim((string) post($k))) !== '' ? $v : null);

    // Identitas diambil dari Master, bukan dari ketikan — supaya surat contoh
    // memakai nama, alamat, dan kontak yang sama dengan surat aslinya nanti.
    $clientId  = (int) post('client_id');
    $contactId = (int) post('contact_id');
    $cl = ['company_name' => null, 'brand_name' => null, 'address' => null, 'city' => null];
    if ($clientId > 0) {
        $q = $pdo->prepare('SELECT company_name, brand_name, address, city FROM master_clients WHERE id = ?');
        $q->execute([$clientId]);
        $cl = $q->fetch(PDO::FETCH_ASSOC) ?: $cl;
    }
    $cpName = null;
    if ($contactId > 0) {
        $q = $pdo->prepare('SELECT name FROM master_client_contacts WHERE id = ?');
        $q->execute([$contactId]);
        $cpName = $q->fetchColumn() ?: null;
    }
    $kode = $txt('master_code');
    $unit = ['location_name' => null, 'floor' => null];
    if ($kode !== null && $module === 'cl') {
        $q = $pdo->prepare('SELECT location_name, floor FROM master_cl_units WHERE code = ? AND property_id = ?');
        $q->execute([$kode, $pid]);
        $unit = $q->fetch(PDO::FETCH_ASSOC) ?: $unit;
    }
    $picName = $txt('pic_name');
    $pic = ['pic_email' => null, 'pic_phone' => null, 'pic_signature' => null, 'pic_role' => null];
    if ($picName !== null) {
        $q = $pdo->prepare('SELECT email pic_email, phone pic_phone, signature_path pic_signature, role_name pic_role
                              FROM master_pic WHERE name = ? AND property_id = ?');
        $q->execute([$picName, $pid]);
        $pic = $q->fetch(PDO::FETCH_ASSOC) ?: $pic;
    }

    // Bentuknya disamakan dengan baris hasil SELECT di offer_print(), supaya
    // templat cetaknya tidak perlu tahu bedanya.
    $o = array_merge($cl, $unit, $pic, [
        'id'            => 0,
        'property_id'   => $pid,
        'module'        => $module,
        // Nomor SENGAJA kosong: nomor terbit saat penawaran disimpan.
        'offer_no'      => null,
        'offer_date'    => date('Y-m-d'),
        'cp_name'       => $cpName,
        'client_id'     => $clientId ?: null,
        'contact_id'    => $contactId ?: null,
        'master_code'   => $kode,
        'pic_name'      => $picName,
        'keterangan'    => $txt('keterangan'),
        'ukuran'        => $txt('ukuran'),
        'pricing_type'  => (string) post('pricing_type', 'daily_area'),
        'unit_rate'     => $num('unit_rate'),
        'area_sqm'      => $num('area_sqm'),
        'slots'         => $num('slots', 1),
        'start_date'    => $txt('start_date'),
        'end_date'      => $txt('end_date'),
        'contract_months'  => null,
        'total_calculated' => $num('total_calculated'),
        'override_amount'  => $num('override_amount'),
        'monthly_amount'   => $num('monthly_amount'),
        'ppn_flag'      => post('ppn_flag') ? 1 : 0,
        'sc_flag'       => post('sc_flag') ? 1 : 0,
        'sc_monthly'    => $num('sc_monthly'),
        'dp_amount'     => $num('dp_amount'),
        'dp_months'     => (int) $num('dp_months'),
        'deposit_amount'=> $num('deposit_amount'),
        'deposit_months'=> (int) $num('deposit_months'),
        'deposit_paid'  => post('deposit_paid') ? 1 : 0,
        'electricity_flag'    => post('electricity_flag') ? 1 : 0,
        'electricity_amount'  => $num('electricity_amount'),
        'electricity_monthly' => $num('electricity_monthly'),
        'electricity_units'   => $num('electricity_units'),
        'diskon_aktif'  => post('diskon_aktif') ? 1 : 0,
        // Kotak Rate di formulir adalah TARIF PUBLISH bila diskon dipakai;
        // tarif hasil nego diketik di kotak diskon. Disamakan dengan cara
        // penyimpanan supaya baris diskon di surat contoh ikut benar.
        'tarif_publish' => post('diskon_aktif') ? $num('unit_rate') : 0.0,
        'is_bundle'     => post('is_bundle') ? 1 : 0,
        'letter_json'   => null,
        // Tanda tangan & token verifikasi memang belum ada di surat contoh.
        'sign_token'    => null, 'sign_method' => null, 'sign_name' => null,
        'signature_data'=> null, 'signed_at'   => null,
    ]);
    if (post('diskon_aktif')) {
        $nego = $num('tarif_diskon');
        if ($nego > 0) $o['unit_rate'] = $nego;
    }

    $prop   = current_property();
    $letter = offer_letter($pdo, $o);
    // Komponen paket, jadwal harga bertahap, dan tarif bertingkat semuanya
    // tersimpan sebagai baris anak milik penawaran — penawaran yang belum
    // disimpan belum punya satu pun, jadi kosong apa adanya.
    $items      = [];
    $tahapHarga = [];
    $tierHarga  = [];
    $rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $h  = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    require_once dirname(__DIR__) . '/pdf.php';
    $PDF_MODE = true;
    ob_start();
    include __DIR__ . '/offer_print_template.php';
    $html = (string) ob_get_clean();
    clara_render_letterhead_pdf($html, 'PRATINJAU - Surat Penawaran', [], 'CONTOH — BELUM SAH');
}
