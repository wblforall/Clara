<?php
declare(strict_types=1);

/**
 * Analisa Unit — keterisian, harga, dan pengisi tiap slot sewa (Exhibition, Media, Gudang)
 * dalam jendela 12 bulan.
 *
 * Titik sambung untuk lapisan lain (mis. Analisa AI): unit_analysis_data() mengembalikan
 * struktur murni tanpa HTML. Nama klien HANYA ada di kunci 'penyewa'; kunci lain
 * ('meta','bulan','unit','segmen','harian') berisi angka agregat dan teks temuan tanpa nama klien,
 * sehingga aman dikirim ke luar bila perlu.
 *
 * Definisi (ditulis juga di halaman, blok "Cara menghitung"):
 *  - Sumber "terisi" = transaction_allocations JOIN transactions (deleted_at IS NULL).
 *  - Hari terisi per bulan = gabungan tanggal alokasi (tidak dihitung ganda bila tumpang tindih).
 *    Berlaku sama untuk Exhibition, Gudang, dan Media (lihat catatan Media di halaman).
 *  - Bulan "data" = dari bulan pertama segmen punya alokasi sampai bulan berjalan; sebelumnya
 *    "belum ada data" (tidak masuk penyebut), sesudahnya "terjadwal" (tidak masuk metrik).
 *  - Pendapatan = SUM(amount) alokasi (sama dengan Executive Summary). Harga rata-rata memakai
 *    nilai kontrak yang dibagi rata per hari (pengakuan pendapatan Clara bisa menumpuk di bulan pertama).
 */

const UA_MODULES = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
const UA_BULAN_PENDEK = ['01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr', '05' => 'Mei', '06' => 'Jun',
                         '07' => 'Jul', '08' => 'Agt', '09' => 'Sep', '10' => 'Okt', '11' => 'Nov', '12' => 'Des'];

// ───────────────────────── util kecil ─────────────────────────

function _ua_e(mixed $v): string
{
    return h((string) $v);
}

function _ua_modul(mixed $v, string $bawaan = 'cl'): string
{
    $v = is_string($v) ? $v : '';
    return array_key_exists($v, UA_MODULES) ? $v : $bawaan;
}

function _ua_bulan(mixed $v, string $bawaan): string
{
    return (is_string($v) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v)) ? $v : $bawaan;
}

function _ua_tambah_bulan(string $ym, int $n): string
{
    return (new DateTimeImmutable($ym . '-01'))->modify(($n >= 0 ? '+' : '') . $n . ' months')->format('Y-m');
}

function _ua_hari_dlm_bulan(string $ym): int
{
    return (int) date('t', strtotime($ym . '-01'));
}

function _ua_label(string $ym): string
{
    return (UA_BULAN_PENDEK[substr($ym, 5, 2)] ?? substr($ym, 5, 2)) . ' ' . substr($ym, 0, 4);
}

function _ua_tgl(string $ymd): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) return $ymd;
    return (int) $m[3] . ' ' . (UA_BULAN_PENDEK[$m[2]] ?? $m[2]) . ' ' . $m[1];
}

function _ua_angka(?float $v, int $desimal = 0): string
{
    return $v === null ? '–' : number_format($v, $desimal, ',', '.');
}

function _ua_rp(?float $v): string
{
    if ($v === null) return '–';
    $a = abs($v);
    if ($a >= 1e9) return 'Rp ' . number_format($v / 1e9, 2, ',', '.') . ' M';
    if ($a >= 1e6) return 'Rp ' . number_format($v / 1e6, 1, ',', '.') . ' jt';
    return 'Rp ' . number_format($v, 0, ',', '.');
}

function _ua_persen(?float $v, int $desimal = 1): string
{
    return $v === null ? '–' : number_format($v, $desimal, ',', '.') . '%';
}

function _ua_popcount(int $m): int
{
    return substr_count(decbin($m), '1');
}

// ───────────────────────── master & periode ─────────────────────────

/** Master unit milik properti, dinormalkan lintas segmen. Kunci = kode unit. */
function _ua_master(PDO $pdo, int $pid, string $module): array
{
    $out = [];
    if ($module === 'cl') {
        $st = $pdo->prepare('SELECT code, floor, location_name, unit_type, area_sqm, rate, status FROM master_cl_units WHERE property_id = ? ORDER BY sort_order, id');
        $st->execute([$pid]);
        foreach ($st->fetchAll() as $r) {
            $out[$r['code']] = [
                'code' => $r['code'], 'lantai' => (string) $r['floor'], 'lokasi' => (string) $r['location_name'],
                'tipe' => (string) ($r['unit_type'] ?? ''), 'luas' => (float) $r['area_sqm'],
                'tarif' => (float) $r['rate'], 'satuan_tarif' => 'per m² per hari', 'basis_tarif' => 'm2_hari',
                'status' => (string) $r['status'], 'jenis_harga' => 'daily_area', 'slot' => null, 'ukuran' => '',
            ];
        }
    } elseif ($module === 'media') {
        $st = $pdo->prepare('SELECT code, media_type, location, point, size, quantity, slots, rate, pricing_type, status FROM master_media WHERE property_id = ? ORDER BY sort_order, id');
        $st->execute([$pid]);
        $satuan = ['daily_point' => 'per hari per titik', 'daily_slot' => 'per hari per slot', 'monthly' => 'per bulan',
                   'fixed' => 'paket (tetap)', 'daily_area' => 'per m² per hari', 'monthly_area' => 'per m² per bulan'];
        $basis  = ['daily_point' => 'hari', 'monthly' => 'bulan'];
        foreach ($st->fetchAll() as $r) {
            $out[$r['code']] = [
                'code' => $r['code'], 'lantai' => '', 'lokasi' => trim((string) $r['location'] . ((string) $r['point'] !== '' ? ' · ' . $r['point'] : '')),
                'tipe' => (string) $r['media_type'], 'luas' => 0.0,
                'tarif' => (float) $r['rate'], 'satuan_tarif' => $satuan[$r['pricing_type']] ?? (string) $r['pricing_type'],
                'basis_tarif' => $basis[$r['pricing_type']] ?? null,
                'status' => (string) $r['status'], 'jenis_harga' => (string) $r['pricing_type'],
                'slot' => (float) $r['slots'], 'ukuran' => (string) ($r['size'] ?? ''),
            ];
        }
    } else {
        $st = $pdo->prepare('SELECT code, location, name, area_sqm, monthly_rate, status FROM master_gudang WHERE property_id = ? ORDER BY sort_order, id');
        $st->execute([$pid]);
        foreach ($st->fetchAll() as $r) {
            $out[$r['code']] = [
                'code' => $r['code'], 'lantai' => (string) $r['location'], 'lokasi' => (string) $r['name'],
                'tipe' => '', 'luas' => (float) $r['area_sqm'],
                'tarif' => (float) $r['monthly_rate'], 'satuan_tarif' => 'per m² per bulan', 'basis_tarif' => 'm2_bulan',
                'status' => (string) $r['status'], 'jenis_harga' => 'monthly', 'slot' => null, 'ukuran' => '',
            ];
        }
    }
    return $out;
}

/** Bulan pertama segmen punya alokasi (transaksi aktif) di properti ini; null bila belum ada. */
function _ua_bulan_pertama(PDO $pdo, int $pid, string $module): ?string
{
    $st = $pdo->prepare(
        'SELECT MIN(a.period_key) FROM transaction_allocations a
         JOIN transactions t ON t.id = a.transaction_id AND t.deleted_at IS NULL
         WHERE a.property_id = ? AND a.module = ?'
    );
    $st->execute([$pid, $module]);
    $v = $st->fetchColumn();
    return $v ? (string) $v : null;
}

// ───────────────────────── fungsi data publik ─────────────────────────

/**
 * Data analisa unit untuk satu segmen, jendela 12 bulan berakhir di $endMonth.
 * $code diisi → struktur yang sama ditambah rincian penyewa/kontrak/harian untuk unit itu.
 *
 * Kunci hasil:
 *  'meta'    => module, module_label, property_id, akhir, awal, hari_ini_bulan, bulan_pertama_data,
 *               dibatasi_sales, jumlah_orphan
 *  'bulan'   => [ {ym, label, hari, state: data|nodata|scheduled} ]  (12 elemen, urut lama→baru)
 *  'unit'    => [kode => {master, agregat, bulan[ym], temuan[]}]      (angka & teks, tanpa nama klien)
 *  'segmen'  => {jumlah_unit, hari_terisi, hari_tersedia, okupansi, pendapatan, tak_pernah, terlaris[],
 *                tersepi[], bulan[ym]{hari_terisi,hari_booking,nilai,hari_tersedia}, orphan{kode:{hari,nilai}}}
 *  'harian'  => [kode => [ym => bitmask hari terisi]]  (hanya untuk $code)
 *  'penyewa' => {dibatasi, per_unit: [kode => {ringkas[], kontrak[]}]}  (SATU-SATUNYA tempat nama klien; hanya $code)
 */
function unit_analysis_data(PDO $pdo, int $pid, string $module, string $endMonth, ?string $code = null): array
{
    $module   = _ua_modul($module);
    $nowYm    = date('Y-m');
    $endMonth = _ua_bulan($endMonth, $nowYm);
    $awal     = _ua_tambah_bulan($endMonth, -11);

    $bulanPertama = _ua_bulan_pertama($pdo, $pid, $module);
    $bulan = [];
    $state = [];
    for ($i = 0; $i < 12; $i++) {
        $ym = _ua_tambah_bulan($awal, $i);
        $s  = ($bulanPertama === null || $ym < $bulanPertama) ? 'nodata' : ($ym > $nowYm ? 'scheduled' : 'data');
        $state[$ym] = $s;
        $bulan[] = ['ym' => $ym, 'label' => _ua_label($ym), 'hari' => _ua_hari_dlm_bulan($ym), 'state' => $s];
    }
    $dataBulan = array_keys(array_filter($state, fn($s) => $s === 'data'));
    $dim = [];
    foreach ($bulan as $b) $dim[$b['ym']] = $b['hari'];
    $hariTersedia = 0;
    foreach ($dataBulan as $ym) $hariTersedia += $dim[$ym];

    $master = _ua_master($pdo, $pid, $module);

    // Cakupan peran sales: daftar penyewa/kontrak dibatasi ke transaksi miliknya (agregat tetap utuh,
    // sama seperti Dashboard & Trend yang juga tidak dipersempit per sales).
    $dibatasi = current_sales_scope($pdo, $pid) !== null;
    $boleh = null;
    if ($dibatasi) {
        [$frag, $par] = current_sales_scope_sql($pdo, $pid, 't.pic_name', 't.created_by');
        $q = $pdo->prepare('SELECT t.id FROM transactions t WHERE t.property_id = ? AND t.module = ? AND t.deleted_at IS NULL' . $frag);
        $q->execute(array_merge([$pid, $module], $par));
        $boleh = array_fill_keys(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    // SATU query untuk seluruh segmen.
    $st = $pdo->prepare(
        'SELECT a.master_code, a.transaction_id AS tid, a.period_key, a.allocation_start AS ast, a.allocation_end AS aen,
                a.allocated_days AS dys, a.amount,
                t.client_id, t.start_date, t.end_date, t.final_amount, t.area_sqm AS tarea, t.pricing_type, t.slots AS tslots,
                t.pic_name, LEFT(t.remarks, 160) AS remarks, t.renewal_status, t.recurring_flag, t.billing_method,
                c.company_name, c.brand_name, tot.amt_total, tot.days_total
         FROM transaction_allocations a
         JOIN transactions t ON t.id = a.transaction_id AND t.deleted_at IS NULL
         LEFT JOIN master_clients c ON c.id = t.client_id
         JOIN (SELECT transaction_id, SUM(amount) AS amt_total, SUM(allocated_days) AS days_total
                 FROM transaction_allocations WHERE property_id = ? GROUP BY transaction_id) tot ON tot.transaction_id = a.transaction_id
         WHERE a.property_id = ? AND a.module = ? AND a.period_key BETWEEN ? AND ?
         ORDER BY a.master_code, a.period_key, a.id'
    );
    $st->execute([$pid, $pid, $module, $awal, $endMonth]);

    $U = [];          // kode → akumulator
    $orphan = [];
    $kontrakDetail = [];   // hanya $code
    $penyewaDetail = [];   // hanya $code
    $harianDetail  = [];

    foreach ($st->fetchAll() as $r) {
        $kode = (string) $r['master_code'];
        $ym   = (string) $r['period_key'];
        if (!isset($state[$ym])) continue;
        $dys   = (int) $r['dys'];
        $amt   = (float) $r['amount'];
        $isData = $state[$ym] === 'data';

        if (!isset($master[$kode])) {           // kode transaksi yang tidak ada di master properti ini
            if ($isData) {
                $orphan[$kode]['hari']  = ($orphan[$kode]['hari'] ?? 0) + $dys;
                $orphan[$kode]['nilai'] = ($orphan[$kode]['nilai'] ?? 0.0) + $amt;
            }
            continue;
        }
        $u = &$U[$kode];
        if ($u === null) $u = ['mask' => [], 'bm' => [], 'tid' => [], 'cl' => [], 'tidJ' => [], 'tot_hari_sched' => 0, 'nilai_sched' => 0.0];

        // topeng hari terisi (gabungan tanggal)
        $s = max(1, (int) substr((string) $r['ast'], 8, 2));
        $e = min($dim[$ym], (int) substr((string) $r['aen'], 8, 2));
        if (substr((string) $r['aen'], 0, 7) > $ym) $e = $dim[$ym];
        if ($e >= $s) {
            $u['mask'][$ym] = ($u['mask'][$ym] ?? 0) | (((1 << ($e - $s + 1)) - 1) << ($s - 1));
        }

        $effHarian = ((int) $r['days_total']) > 0 ? ((float) $r['amt_total']) / (int) $r['days_total'] : 0.0;
        $prorata   = $effHarian * $dys;
        $areaEf    = (float) $r['tarea'] > 0 ? (float) $r['tarea'] : (float) $master[$kode]['luas'];

        $b = &$u['bm'][$ym];
        if ($b === null) $b = ['raw' => 0, 'nilai' => 0.0, 'pro' => 0.0, 'm2d' => 0.0];
        $b['raw'] += $dys; $b['nilai'] += $amt; $b['pro'] += $prorata; $b['m2d'] += $dys * $areaEf;
        unset($b);

        $cid = $r['client_id'] !== null ? (int) $r['client_id'] : 0;
        $tid = (int) $r['tid'];
        if ($isData) {
            $u['tid'][$tid] = true;
            $u['cl'][$cid]['tid'][$tid] = true;
            $u['cl'][$cid]['ym'][$ym] = true;
        } else {
            $u['tot_hari_sched'] += $dys;
            $u['nilai_sched'] += $amt;
        }

        // rincian untuk unit yang diminta
        if ($code !== null && $kode === $code && ($boleh === null || isset($boleh[$tid]))) {
            $nama = trim((string) ($r['company_name'] ?? '')) ?: 'Tanpa klien';
            $brand = trim((string) ($r['brand_name'] ?? ''));
            $k = &$kontrakDetail[$tid];
            if ($k === null) {
                $dur = max(1, (int) round((strtotime((string) $r['end_date']) - strtotime((string) $r['start_date'])) / 86400) + 1);
                $k = [
                    'transaction_id' => $tid, 'klien_id' => $cid ?: null, 'klien' => $nama, 'brand' => $brand,
                    'mulai' => (string) $r['start_date'], 'selesai' => (string) $r['end_date'], 'durasi_hari' => $dur,
                    'nilai_kontrak' => (float) $r['final_amount'], 'tarif_per_hari' => (float) $r['final_amount'] / $dur,
                    'hari_jendela' => 0, 'nilai_jendela' => 0.0, 'nilai_prorata' => 0.0, 'hari_terjadwal' => 0,
                    'pic' => (string) ($r['pic_name'] ?? ''), 'perpanjangan' => (string) $r['renewal_status'],
                    'berulang' => ((int) $r['recurring_flag'] === 1 || $r['billing_method'] === 'spread'),
                    'jenis_harga' => (string) $r['pricing_type'], 'slot' => (float) $r['tslots'],
                    'catatan' => mb_strimwidth(trim((string) ($r['remarks'] ?? '')), 0, 90, '…'),
                ];
            }
            if ($isData) { $k['hari_jendela'] += $dys; $k['nilai_jendela'] += $amt; $k['nilai_prorata'] += $prorata; }
            else         { $k['hari_terjadwal'] += $dys; }
            unset($k);
            if ($isData) {
                $p = &$penyewaDetail[$cid];
                if ($p === null) $p = ['klien_id' => $cid ?: null, 'nama' => $nama, 'brand' => $brand, 'tid' => [], 'hari' => 0, 'nilai' => 0.0, 'nilai_prorata' => 0.0, 'pertama' => (string) $r['ast'], 'terakhir' => (string) $r['aen']];
                $p['tid'][$tid] = true; $p['hari'] += $dys; $p['nilai'] += $amt; $p['nilai_prorata'] += $prorata;
                if ((string) $r['ast'] < $p['pertama']) $p['pertama'] = (string) $r['ast'];
                if ((string) $r['aen'] > $p['terakhir']) $p['terakhir'] = (string) $r['aen'];
                unset($p);
            }
        }
        unset($u);
    }

    // ── agregat per unit ──
    $unitOut = [];
    $segHari = $segNilai = 0; $segBulan = [];
    foreach ($bulan as $b) $segBulan[$b['ym']] = ['hari_terisi' => 0, 'hari_booking' => 0, 'nilai' => 0.0, 'hari_tersedia' => 0];
    $tampil = 0; $nonaktifUnit = 0; $nonaktifNilai = 0.0;

    foreach ($master as $kode => $m) {
        $u = $U[$kode] ?? null;
        $aktif = ($m['status'] === 'active');
        if (!$aktif && $u === null) continue;      // nonaktif tanpa riwayat di jendela → tidak ditampilkan
        $tampil++;

        $hari = 0; $raw = 0; $nilai = 0.0; $pro = 0.0; $m2d = 0.0; $bulanTerisi = 0;
        $strip = [];
        foreach ($bulan as $bi) {
            $ym = $bi['ym']; $stt = $bi['state']; $bm = $u['bm'][$ym] ?? null;
            $hu = isset($u['mask'][$ym]) ? _ua_popcount($u['mask'][$ym]) : 0;
            $strip[$ym] = [
                'state' => $stt, 'hari' => $hu, 'hari_dlm_bulan' => $bi['hari'],
                'okupansi' => $stt === 'data' ? round($hu / $bi['hari'] * 100, 1) : null,
                'nilai' => $bm ? round($bm['nilai']) : 0.0,
                'harga_hari' => ($bm && $bm['raw'] > 0 && $bm['pro'] > 0) ? round($bm['pro'] / $bm['raw']) : null,
            ];
            if ($stt !== 'data') continue;
            $hari += $hu; if ($hu > 0) $bulanTerisi++;
            $segBulan[$ym]['hari_terisi'] += $hu;
            $segBulan[$ym]['hari_tersedia'] += $bi['hari'];
            if ($bm) {
                $raw += $bm['raw']; $nilai += $bm['nilai']; $pro += $bm['pro']; $m2d += $bm['m2d'];
                $segBulan[$ym]['hari_booking'] += $bm['raw']; $segBulan[$ym]['nilai'] += $bm['nilai'];
            }
        }
        $segHari += $hari; $segNilai += $nilai;
        if (!$aktif) { $nonaktifUnit++; $nonaktifNilai += $nilai; }

        $hargaHari  = ($raw > 0 && $pro > 0) ? $pro / $raw : null;
        $hargaBulan = $hargaHari !== null ? $hargaHari * 30 : null;
        $hargaM2Hari = ($m['basis_tarif'] !== null && in_array($m['basis_tarif'], ['m2_hari', 'm2_bulan'], true) && $m2d > 0 && $pro > 0) ? $pro / $m2d : null;
        $hargaM2Bulan = $hargaM2Hari !== null ? $hargaM2Hari * 30 : null;

        // selisih vs tarif master — hanya bila satuannya pasti sebanding
        $banding = null;
        if ($m['tarif'] > 0) {
            $banding = match ($m['basis_tarif']) {
                'm2_hari'  => $hargaM2Hari,
                'm2_bulan' => $hargaM2Bulan,
                'hari'     => $hargaHari,
                'bulan'    => $hargaBulan,
                default    => null,
            };
        }
        $selisih = $banding !== null ? ($banding / $m['tarif'] - 1) * 100 : null;

        // kosong terpanjang (hanya di bulan berdata)
        $best = 0; $bestMulai = null; $bestAkhir = null; $run = 0; $runMulai = null; $prev = null;
        foreach ($dataBulan as $ym) {
            $mk = $u['mask'][$ym] ?? 0;
            for ($d = 1; $d <= $dim[$ym]; $d++) {
                $tgl = sprintf('%s-%02d', $ym, $d);
                if (($mk >> ($d - 1)) & 1) { $run = 0; $runMulai = null; }
                else {
                    if ($run === 0) $runMulai = $tgl;
                    $run++;
                    if ($run > $best) { $best = $run; $bestMulai = $runMulai; $bestAkhir = $tgl; }
                }
            }
        }

        $kontrak = isset($u['tid']) ? count($u['tid']) : 0;
        $klienUnik = 0; $klienKembali = 0;
        foreach (($u['cl'] ?? []) as $cl) {
            $klienUnik++;
            if (count($cl['tid']) > 1 || count($cl['ym']) > 1) $klienKembali++;
        }

        $agregat = [
            'hari_terisi' => $hari, 'hari_tersedia' => $hariTersedia, 'okupansi' => $hariTersedia > 0 ? round($hari / $hariTersedia * 100, 2) : null,
            'bulan_terisi' => $bulanTerisi, 'bulan_tersedia' => count($dataBulan),
            'kontrak' => $kontrak, 'penyewa_unik' => $klienUnik, 'penyewa_kembali' => $klienKembali,
            'pendapatan' => round($nilai, 2), 'hari_booking' => $raw, 'hari_ganda' => max(0, $raw - $hari),
            'harga_per_hari' => $hargaHari !== null ? round($hargaHari) : null,
            'harga_per_bulan' => $hargaBulan !== null ? round($hargaBulan) : null,
            'harga_per_m2_hari' => $hargaM2Hari !== null ? round($hargaM2Hari) : null,
            'harga_per_m2_bulan' => $hargaM2Bulan !== null ? round($hargaM2Bulan) : null,
            'tarif_master' => $m['tarif'], 'satuan_tarif' => $m['satuan_tarif'],
            'selisih_vs_master_pct' => $selisih !== null ? round($selisih, 1) : null,
            'kosong_terpanjang_hari' => $best, 'kosong_mulai' => $bestMulai, 'kosong_akhir' => $bestAkhir,
            'tak_pernah_terisi' => ($hari === 0 && $hariTersedia > 0),
            'hari_terjadwal' => (int) ($u['tot_hari_sched'] ?? 0), 'nilai_terjadwal' => round((float) ($u['nilai_sched'] ?? 0)),
        ];
        $unitOut[$kode] = ['master' => $m, 'agregat' => $agregat, 'bulan' => $strip, 'temuan' => []];
    }

    // ── ringkasan segmen ──
    $okupSeg = $hariTersedia > 0 && $tampil > 0 ? $segHari / ($hariTersedia * $tampil) * 100 : null;
    $terisi = array_filter($unitOut, fn($x) => $x['agregat']['hari_terisi'] > 0);
    $urut = function (array $arr, bool $desc): array {
        uasort($arr, function ($a, $b) use ($desc) {
            $c = $a['agregat']['okupansi'] <=> $b['agregat']['okupansi'];
            if ($c === 0) $c = $b['agregat']['pendapatan'] <=> $a['agregat']['pendapatan'];
            return $desc ? -$c : $c;
        });
        return $arr;
    };
    $ringkas = fn($kode, $x) => ['kode' => $kode, 'okupansi' => $x['agregat']['okupansi'], 'pendapatan' => $x['agregat']['pendapatan'], 'hari_terisi' => $x['agregat']['hari_terisi']];
    $terlaris = $tersepi = [];
    foreach (array_slice($urut($terisi, true), 0, 5, true) as $k => $x) $terlaris[] = $ringkas($k, $x);
    foreach (array_slice($urut($terisi, false), 0, 5, true) as $k => $x) $tersepi[] = $ringkas($k, $x);
    $orphanNilai = 0.0; foreach ($orphan as $o) $orphanNilai += $o['nilai'];

    $segmen = [
        'jumlah_unit' => $tampil, 'hari_terisi' => $segHari, 'hari_tersedia' => $hariTersedia * $tampil,
        'okupansi' => $okupSeg !== null ? round($okupSeg, 2) : null, 'pendapatan' => round($segNilai, 2),
        'tak_pernah' => count(array_filter($unitOut, fn($x) => $x['agregat']['tak_pernah_terisi'])),
        'terlaris' => $terlaris, 'tersepi' => $tersepi, 'bulan' => $segBulan,
        'orphan' => $orphan, 'orphan_nilai' => round($orphanNilai, 2),
        'nonaktif_unit' => $nonaktifUnit, 'nonaktif_nilai' => round($nonaktifNilai, 2),
    ];

    // ── temuan otomatis (aturan, bukan AI; tanpa nama klien) ──
    foreach ($unitOut as $kode => &$x) {
        $x['temuan'] = _ua_temuan($x, $segmen, $dataBulan);
    }
    unset($x);

    $hasil = [
        'meta' => [
            'module' => $module, 'module_label' => UA_MODULES[$module], 'property_id' => $pid,
            'awal' => $awal, 'akhir' => $endMonth, 'hari_ini_bulan' => $nowYm, 'bulan_pertama_data' => $bulanPertama,
            'dibatasi_sales' => $dibatasi, 'jumlah_orphan' => count($orphan),
        ],
        'bulan' => $bulan,
        'unit' => $unitOut,
        'segmen' => $segmen,
        'harian' => [],
        'penyewa' => ['dibatasi' => $dibatasi, 'per_unit' => []],
    ];

    if ($code !== null && isset($unitOut[$code])) {
        $hasil['harian'][$code] = $U[$code]['mask'] ?? [];
        $ringkasP = [];
        foreach ($penyewaDetail as $p) {
            $p['kontrak'] = count($p['tid']); unset($p['tid']);
            $ringkasP[] = $p;
        }
        usort($ringkasP, fn($a, $b) => $b['nilai'] <=> $a['nilai']);
        $kon = array_values($kontrakDetail);
        usort($kon, fn($a, $b) => [$a['mulai'], $a['transaction_id']] <=> [$b['mulai'], $b['transaction_id']]);
        $hasil['penyewa']['per_unit'][$code] = ['ringkas' => $ringkasP, 'kontrak' => $kon];
    }
    return $hasil;
}

/** Temuan berbasis aturan untuk satu unit. Hanya angka & tanggal — tanpa nama klien. */
function _ua_temuan(array $x, array $segmen, array $dataBulan): array
{
    $a = $x['agregat'];
    $t = [];
    if ($a['hari_tersedia'] === 0) {
        return [['tipe' => 'info', 'teks' => 'Belum ada bulan berdata pada jendela ini, jadi belum ada yang bisa dianalisa.']];
    }
    if ($a['hari_terisi'] === 0) {
        $t[] = ['tipe' => 'buruk', 'teks' => 'Unit tidak pernah terisi pada jendela ini (' . $a['hari_tersedia'] . ' hari tersedia).'];
    } else {
        $ramai = $sepi = null;
        foreach ($dataBulan as $ym) {
            $b = $x['bulan'][$ym];
            if ($ramai === null || $b['hari'] > $x['bulan'][$ramai]['hari']) $ramai = $ym;
            if ($sepi === null || $b['hari'] < $x['bulan'][$sepi]['hari']) $sepi = $ym;
        }
        if ($ramai !== null && $sepi !== null && $x['bulan'][$ramai]['hari'] > $x['bulan'][$sepi]['hari']) {
            $br = $x['bulan'][$ramai]; $bs = $x['bulan'][$sepi];
            $t[] = ['tipe' => 'info', 'teks' => 'Bulan paling ramai: ' . _ua_label($ramai) . ' (' . $br['hari'] . ' dari ' . $br['hari_dlm_bulan'] . ' hari, ' . _ua_persen($br['okupansi'], 0) . '). Paling sepi: '
                . _ua_label($sepi) . ' (' . $bs['hari'] . ' hari, ' . _ua_persen($bs['okupansi'], 0) . ').'];
        } elseif ($ramai !== null) {
            $t[] = ['tipe' => 'info', 'teks' => 'Keterisian merata di setiap bulan berdata (' . $x['bulan'][$ramai]['hari'] . ' hari per bulan tertinggi).'];
        }
    }
    if ($a['kosong_terpanjang_hari'] > 0 && $a['hari_terisi'] > 0) {
        $t[] = ['tipe' => $a['kosong_terpanjang_hari'] >= 60 ? 'buruk' : 'info',
                'teks' => 'Kosong terpanjang ' . $a['kosong_terpanjang_hari'] . ' hari berturut-turut, ' . _ua_tgl((string) $a['kosong_mulai']) . ' – ' . _ua_tgl((string) $a['kosong_akhir']) . '.'];
    } elseif ($a['hari_terisi'] > 0) {
        $t[] = ['tipe' => 'baik', 'teks' => 'Tidak ada hari kosong pada bulan-bulan berdata: terisi penuh sepanjang jendela.'];
    }

    // tren harga: paruh awal vs paruh akhir bulan-bulan yang punya harga
    $seri = [];
    foreach ($dataBulan as $ym) {
        if ($x['bulan'][$ym]['harga_hari'] !== null) $seri[] = $x['bulan'][$ym]['harga_hari'];
    }
    if (count($seri) >= 4) {
        $n = intdiv(count($seri), 2);
        $awal = array_sum(array_slice($seri, 0, $n)) / $n;
        $akhir = array_sum(array_slice($seri, -$n)) / $n;
        if ($awal > 0) {
            $d = ($akhir / $awal - 1) * 100;
            $arah = abs($d) < 5 ? 'relatif stabil' : ($d > 0 ? 'naik' : 'turun');
            $t[] = ['tipe' => $d <= -5 ? 'buruk' : ($d >= 5 ? 'baik' : 'info'),
                    'teks' => 'Tren harga ' . $arah . ': rata-rata per hari terisi paruh awal ' . _ua_rp($awal) . ' → paruh akhir ' . _ua_rp($akhir) . ' (' . ($d >= 0 ? '+' : '') . number_format($d, 1, ',', '.') . '%).'];
        }
    } elseif ($a['hari_terisi'] > 0) {
        $t[] = ['tipe' => 'info', 'teks' => 'Tren harga belum bisa dinilai: kurang dari 4 bulan yang punya harga.'];
    }

    if ($a['penyewa_unik'] > 0) {
        $t[] = ['tipe' => $a['penyewa_kembali'] > 0 ? 'baik' : 'info',
                'teks' => $a['penyewa_kembali'] > 0
                    ? $a['penyewa_kembali'] . ' dari ' . $a['penyewa_unik'] . ' penyewa kembali (lebih dari satu kontrak atau satu bulan): pengisi berulang.'
                    : 'Semua ' . $a['penyewa_unik'] . ' penyewa hanya muncul sekali (tidak ada yang kembali pada jendela ini).'];
    }

    if ($segmen['okupansi'] !== null && $a['okupansi'] !== null) {
        $d = $a['okupansi'] - $segmen['okupansi'];
        $t[] = ['tipe' => $d >= 5 ? 'baik' : ($d <= -5 ? 'buruk' : 'info'),
                'teks' => 'Okupansi ' . _ua_persen($a['okupansi']) . ' ' . (abs($d) < 5 ? 'setara' : ($d > 0 ? 'di atas' : 'di bawah')) . ' rata-rata segmen ' . _ua_persen($segmen['okupansi'])
                    . ' (' . ($d >= 0 ? '+' : '') . number_format($d, 1, ',', '.') . ' poin).'];
    }
    if ($a['hari_ganda'] > 0) {
        $t[] = ['tipe' => 'peringatan', 'teks' => 'Ada ' . $a['hari_ganda'] . ' hari-booking yang tumpang tindih dengan kontrak lain pada unit ini; hari terisi dihitung sekali, nilai tetap dijumlahkan.'];
    }
    if ($a['hari_terjadwal'] > 0) {
        $t[] = ['tipe' => 'info', 'teks' => 'Sudah terjadwal ' . $a['hari_terjadwal'] . ' hari di bulan mendatang (belum dihitung dalam metrik).'];
    }
    return $t;
}

// ───────────────────────── parameter & urutan ─────────────────────────

function _ua_param_akhir(PDO $pdo, int $pid): array
{
    $nowYm = date('Y-m');
    $tahun = getv('tahun', '');
    $tahun = (is_string($tahun) && preg_match('/^(20\d{2})$/', $tahun)) ? $tahun : '';
    $to    = _ua_bulan(getv('to', ''), $nowYm);
    if ($tahun !== '') $to = $tahun . '-12';
    return [$to, $tahun];
}

function _ua_opsi_bulan(PDO $pdo, int $pid): array
{
    $st = $pdo->prepare('SELECT MIN(period_key) FROM transaction_allocations WHERE property_id = ?');
    $st->execute([$pid]);
    $min = (string) ($st->fetchColumn() ?: date('Y-m'));
    $maks = _ua_tambah_bulan(date('Y-m'), 3);
    $out = [];
    for ($ym = $maks; $ym >= $min; $ym = _ua_tambah_bulan($ym, -1)) $out[] = $ym;
    return $out ?: [date('Y-m')];
}

function _ua_urutkan(array $unit, string $urut): array
{
    $kunci = ['okupansi_desc', 'okupansi_asc', 'pendapatan_desc', 'harga_desc', 'kosong_desc', 'kode'];
    if (!in_array($urut, $kunci, true)) $urut = 'okupansi_desc';
    if ($urut === 'kode') return $unit;
    $nilai = function (array $x) use ($urut) {
        $a = $x['agregat'];
        return match ($urut) {
            'okupansi_desc', 'okupansi_asc' => (float) ($a['okupansi'] ?? -1),
            'pendapatan_desc' => (float) $a['pendapatan'],
            'harga_desc' => (float) ($a['harga_per_bulan'] ?? -1),
            'kosong_desc' => (float) $a['kosong_terpanjang_hari'],
        };
    };
    $asc = $urut === 'okupansi_asc';
    $kodes = array_keys($unit);
    $idx = array_flip($kodes);
    uksort($unit, function ($p, $q) use ($unit, $nilai, $asc, $idx) {
        $c = $nilai($unit[$p]) <=> $nilai($unit[$q]);
        if ($c === 0) return $idx[$p] <=> $idx[$q];
        return $asc ? $c : -$c;
    });
    return $unit;
}

function _ua_saring(array $unit, string $q): array
{
    $q = mb_strtolower(trim($q));
    if ($q === '') return $unit;
    return array_filter($unit, function ($x) use ($q) {
        $m = $x['master'];
        return str_contains(mb_strtolower($m['code'] . ' ' . $m['lokasi'] . ' ' . $m['lantai'] . ' ' . $m['tipe']), $q);
    });
}

function _ua_url(string $r, array $p): string
{
    return '?' . http_build_query(array_merge(['r' => $r], array_filter($p, fn($v) => $v !== '' && $v !== null)));
}

// ───────────────────────── komponen tampilan ─────────────────────────

function _ua_css(): void
{ ?>
<style>
.ua-wrap { max-width: 100%; }
.ua-tabs { display:flex; gap:6px; flex-wrap:wrap; }
.ua-tab { padding:8px 16px; border-radius:999px; background:#fff; border:1px solid var(--line); font-weight:700; font-size:13px; color:var(--ink2); box-shadow:var(--shadow-1); }
.ua-tab.on { background:var(--primary); color:#fff; border-color:var(--primary); }
.ua-filter { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; background:#fff; border:1px solid var(--line); border-radius:var(--r-lg); padding:14px 16px; box-shadow:var(--shadow-1); margin:14px 0; }
.ua-filter .f { min-width:140px; }
.ua-filter label { margin-bottom:4px; font-size:11.5px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; }
.ua-filter select, .ua-filter input { width:auto; min-width:140px; }
.ua-filter .grow { flex:1 1 200px; }
.ua-filter .grow input { width:100%; }
.ua-note { border-radius:var(--r-lg); padding:11px 16px; font-size:13px; margin-bottom:12px; border:1px solid var(--line); background:#fff; box-shadow:var(--shadow-1); }
.ua-note.warn { background:#FFFBEB; border-color:#FDE68A; color:#92400E; }
.ua-note.info { background:#F0FDFA; border-color:#99F6E4; color:#115E59; }
.ua-kpis { display:grid; grid-template-columns:repeat(5, minmax(0,1fr)); gap:12px; margin-bottom:14px; }
.ua-kpis.k6 { grid-template-columns:repeat(6, minmax(0,1fr)); }
.ua-kpi { background:#fff; border:1px solid var(--line); border-radius:var(--r-lg); padding:14px 16px; box-shadow:var(--shadow-1); border-top:3px solid var(--primary); min-width:0; }
.ua-kpi .l { font-size:10.5px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); }
.ua-kpi .v { font-size:20px; font-weight:800; margin-top:5px; line-height:1.15; overflow-wrap:anywhere; }
.ua-kpi .s { font-size:11.5px; color:var(--muted); margin-top:3px; }
.ua-card { background:#fff; border:1px solid var(--line); border-radius:var(--r-lg); box-shadow:var(--shadow-1); padding:16px 18px; margin-bottom:14px; min-width:0; }
.ua-card h2 { font-size:14px; font-weight:800; margin:0 0 10px; color:var(--ink); }
.ua-card h2::before { display:none; }
.ua-two { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px; }
.ua-two .ua-card { margin-bottom:0; }
.ua-rank { width:100%; font-size:13px; }
.ua-rank td { padding:6px 4px; border-bottom:1px solid #F1F5F9; }
.ua-rank a { color:var(--primary-dark); font-weight:700; }
.ua-bar { display:inline-block; vertical-align:middle; width:54px; height:6px; border-radius:99px; background:#E5E7EB; overflow:hidden; margin-left:6px; }
.ua-bar > i { display:block; height:100%; background:var(--primary); }
.ua-table th { cursor:pointer; user-select:none; }
.ua-table th.nos { cursor:default; }
.ua-table th.asc::after { content:" ▲"; font-size:9px; } .ua-table th.desc::after { content:" ▼"; font-size:9px; }
.ua-table td { white-space:nowrap; }
.ua-table td.wrap { white-space:normal; min-width:150px; }
.ua-table .num { text-align:right; font-variant-numeric:tabular-nums; }
.ua-table a.kode { font-weight:800; color:var(--primary-dark); }
.ua-table .sub { display:block; font-size:11.5px; color:var(--muted); }
.ua-strip { display:inline-flex; gap:2px; vertical-align:middle; }
.ua-m { display:inline-block; width:13px; height:20px; border-radius:3px; background:#fff; border:1px solid #CBD5E1; box-sizing:border-box; }
.ua-m.nd { background:#E5E7EB; border-color:#E5E7EB; }
.ua-m.l1 { background:#CCFBF1; border-color:#99F6E4; } .ua-m.l2 { background:#5EEAD4; border-color:#2DD4BF; }
.ua-m.l3 { background:#14B8A6; border-color:#0D9488; } .ua-m.l4 { background:#0F766E; border-color:#115E59; }
.ua-m.sc { border-style:dashed; border-color:#0D9488; background-image:repeating-linear-gradient(135deg, rgba(255,255,255,.65) 0 3px, transparent 3px 6px); }
.ua-m.sc.l0 { background-color:#fff; }
.ua-legend { display:flex; gap:14px; flex-wrap:wrap; font-size:12px; color:var(--muted); align-items:center; margin:2px 0 10px; }
.ua-legend span { display:inline-flex; align-items:center; gap:5px; }
.ua-pill { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11.5px; font-weight:700; }
.ua-pill.up { background:#DCFCE7; color:#15803D; } .ua-pill.down { background:#FEE2E2; color:#B91C1C; } .ua-pill.flat { background:#F1F5F9; color:#475569; }
.ua-pill.off { background:#F1F5F9; color:#94A3B8; }
.ua-def summary { cursor:pointer; font-weight:700; color:var(--primary-dark); }
.ua-def ul { margin:8px 0 0 18px; line-height:1.65; font-size:13px; color:var(--ink2); }
.ua-def li { margin-bottom:4px; }
.ua-attr { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
.ua-attr span { background:#F0FDFA; border:1px solid #CCFBF1; border-radius:999px; padding:4px 12px; font-size:12.5px; color:#115E59; }
.ua-attr span b { color:var(--ink); }
.ua-head { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; flex-wrap:wrap; }
.ua-head h1 { font-size:24px; margin:0; font-weight:800; }
.ua-chart { position:relative; height:300px; }
.ua-heat { display:grid; grid-template-columns:74px repeat(31, minmax(0,1fr)); gap:2px; align-items:center; font-size:10.5px; color:var(--muted); }
.ua-heat .hl { font-size:12px; font-weight:700; color:var(--ink2); }
.ua-heat .hd { text-align:center; }
.ua-hc { height:20px; border-radius:3px; background:#fff; border:1px solid #E2E8F0; }
.ua-hc.f { background:#14B8A6; border-color:#0D9488; } .ua-hc.nd { background:#E5E7EB; border-color:#E5E7EB; }
.ua-hc.x { visibility:hidden; } .ua-hc.today { outline:2px solid #F59E0B; outline-offset:-1px; }
.ua-hc.sc.f { background-image:repeating-linear-gradient(135deg, rgba(255,255,255,.55) 0 3px, transparent 3px 6px); border-style:dashed; }
.ua-temuan { list-style:none; margin:0; padding:0; }
.ua-temuan li { padding:9px 12px 9px 14px; border-left:4px solid #94A3B8; background:#F8FAFC; border-radius:0 10px 10px 0; margin-bottom:8px; font-size:13.5px; line-height:1.55; }
.ua-temuan li.baik { border-color:#10B981; background:#F0FDF4; } .ua-temuan li.buruk { border-color:#EF4444; background:#FEF2F2; }
.ua-temuan li.peringatan { border-color:#F59E0B; background:#FFFBEB; }
.ua-empty { text-align:center; color:var(--muted); padding:28px 0; }
@media (max-width: 1100px) { .ua-kpis, .ua-kpis.k6 { grid-template-columns:repeat(3, minmax(0,1fr)); } .ua-two { grid-template-columns:1fr; } }
</style>
<?php }

/** Kotak kecil keterisian satu bulan. */
function _ua_kotak(array $b, string $ym): string
{
    $lbl = _ua_label($ym);
    if ($b['state'] === 'nodata') {
        return '<span class="ua-m nd" title="' . _ua_e($lbl . ': belum ada data') . '"></span>';
    }
    $o = (float) ($b['okupansi'] ?? ($b['hari_dlm_bulan'] > 0 ? $b['hari'] / $b['hari_dlm_bulan'] * 100 : 0));
    if ($b['state'] === 'scheduled') $o = $b['hari_dlm_bulan'] > 0 ? $b['hari'] / $b['hari_dlm_bulan'] * 100 : 0;
    $lv = $b['hari'] <= 0 ? 0 : ($o <= 25 ? 1 : ($o <= 50 ? 2 : ($o <= 75 ? 3 : 4)));
    $tip = $lbl . ($b['state'] === 'scheduled' ? ' (terjadwal): ' : ': ') . $b['hari'] . ' dari ' . $b['hari_dlm_bulan'] . ' hari terisi'
         . ($b['nilai'] > 0 ? ' · ' . money($b['nilai']) : '');
    $cls = 'ua-m l' . $lv . ($b['state'] === 'scheduled' ? ' sc' : '');
    return '<span class="' . $cls . '" title="' . _ua_e($tip) . '"></span>';
}

function _ua_legenda(): string
{
    return '<div class="ua-legend"><span><i class="ua-m"></i> kosong</span><span><i class="ua-m l1"></i> ≤25%</span><span><i class="ua-m l2"></i> ≤50%</span>'
         . '<span><i class="ua-m l3"></i> ≤75%</span><span><i class="ua-m l4"></i> &gt;75%</span>'
         . '<span><i class="ua-m nd"></i> belum ada data</span><span><i class="ua-m sc l3"></i> terjadwal (belum terjadi)</span></div>';
}

function _ua_blok_definisi(string $module, array $meta): void
{ ?>
    <details class="ua-card ua-def">
        <summary>Cara menghitung</summary>
        <ul>
            <li><b>Sumber &quot;terisi&quot;</b>: alokasi transaksi (tabel alokasi per bulan) dari transaksi yang tidak dihapus. Pendapatan = jumlah nilai alokasi, sehingga sama dengan Executive Summary.</li>
            <li><b>Hari terisi</b> per bulan = gabungan tanggal alokasi pada unit itu; dua kontrak yang tumpang tindih tidak dihitung ganda.
                <?php if ($module === 'media'): ?>
                <b>Media:</b> unit dianggap terisi pada hari yang tercatat ada booking. Kapasitas tidak dikalikan jumlah slot karena isian jumlah slot pada booking tidak konsisten (booking penuh pada media 12 slot ada yang tercatat 1 dan ada yang 12), dan di data tidak ada booking yang tumpang tindih pada titik yang sama.
                <?php endif; ?></li>
            <li><b>Hari tersedia</b> = jumlah hari dalam bulan, hanya untuk bulan yang sudah punya data segmen (dari bulan pertama transaksi segmen ini sampai bulan berjalan). Bulan sebelumnya &quot;belum ada data&quot; (abu-abu) dan tidak masuk penyebut. Bulan setelah bulan berjalan &quot;terjadwal&quot; (garis putus-putus) dan tidak masuk metrik. Bulan berjalan dihitung penuh.</li>
            <li><b>Okupansi</b> = hari terisi ÷ hari tersedia. Okupansi segmen = total hari terisi ÷ total hari tersedia semua unit.</li>
            <li><b>Harga rata-rata</b>: nilai kontrak dibagi rata per hari kontrak (pengakuan pendapatan di Clara sering menumpuk di bulan pertama kontrak, jadi nilai bulanan mentah menyesatkan untuk harga), lalu dirata-ratakan terhadap hari booking dalam jendela. Di tabel penyewa dan kontrak, &quot;nilai prorata&quot; memakai dasar yang sama, sedangkan &quot;nilai diakui&quot; adalah jumlah alokasi bulanan (dasar pendapatan). Per bulan setara = per hari × 30. Per m² memakai luas pada kontrak (luas master bila kosong); dilewati bila luas 0.</li>
            <li><b>Tarif master dan selisih</b>: Exhibition = per m² per hari (rate × luas × hari, terbukti dari total hitung kontrak harian); Gudang = per m² per bulan; Media mengikuti jenis harga unit. Selisih hanya dihitung bila satuannya sebanding (Exhibition, Gudang, Media per hari per titik, Media bulanan); selain itu tarif tampil apa adanya tanpa selisih.</li>
            <li><b>Kosong terpanjang</b> = hari berturut-turut tanpa terisi pada bulan-bulan berdata (bisa menyambung antarbulan).</li>
            <li>Unit nonaktif hanya tampil bila punya riwayat dalam jendela. Kode transaksi yang tidak ada di master tidak masuk daftar unit (dicatat sebagai catatan di atas).</li>
        </ul>
    </details>
<?php }

// ───────────────────────── Analisa AI (unit & segmen) ─────────────────────────

/** id numerik master untuk kunci cache AI; null bila kode tidak ada di properti ini. */
function _ua_master_id(PDO $pdo, int $pid, string $module, string $code): ?int
{
    $tabel = ['cl' => 'master_cl_units', 'media' => 'master_media', 'gudang' => 'master_gudang'][$module] ?? null;
    if ($tabel === null) return null;
    $st = $pdo->prepare("SELECT id FROM {$tabel} WHERE property_id = ? AND code = ? LIMIT 1");
    $st->execute([$pid, $code]);
    $v = $st->fetchColumn();
    return $v === false ? null : (int) $v;
}

/**
 * Kartu "Analisa AI" (gaya sama dengan Executive Summary).
 * $o: judul, narasi (baris ai_narasi|null), siap (kunci AI terpasang), bisa (data cukup untuk dianalisa),
 *     basi (angka berubah sejak analisa dibuat), hidden (field tersembunyi form), kosong (teks bila belum ada), dasar (catatan kaki)
 */
function _ua_kartu_ai(array $o): void
{
    $n = $o['narasi'];
    ?>
    <div class="ua-card ua-ai" style="border-left:4px solid #7c3aed">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px">
            <h2 style="margin:0"><?= _ua_e($o['judul']) ?></h2>
            <span style="background:#ede9fe;color:#6d28d9;font-size:10px;font-weight:800;letter-spacing:.06em;padding:2px 8px;border-radius:99px">DITULIS AI</span>
            <?php if ($n): ?>
            <span title="Engine AI yang menulis analisa ini (id: <?= _ua_e($n['model']) ?>)" style="background:#f1f5f9;color:#334155;font-size:11px;font-weight:700;padding:2px 9px;border-radius:99px;border:1px solid #e2e8f0">⚙ <?= _ua_e(AiNarasi::namaTampil((string) $n['model'])) ?> · <?= _ua_e($n['penyedia'] ?? 'Anthropic') ?></span>
            <?php endif; ?>
            <?php if ($o['siap'] && $o['bisa']): ?>
            <form method="post" action="?r=unit_analysis_ai" style="margin-left:auto"
                  onsubmit="var b=this.querySelector('button');if(b.disabled){return false;}b.disabled=true;b.style.opacity='.7';b.textContent='Menganalisa… (±20 dtk)';return true;">
                <input type="hidden" name="_csrf" value="<?= _ua_e(csrf_token()) ?>">
                <?php foreach ($o['hidden'] as $k => $v): if ($v === '' || $v === null) continue; ?>
                    <input type="hidden" name="<?= _ua_e($k) ?>" value="<?= _ua_e($v) ?>">
                <?php endforeach; ?>
                <button type="submit" style="padding:6px 12px;background:#7c3aed;color:#fff;border:0;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer">
                    <?= $n ? '↻ Perbarui Analisa AI' : '✨ Analisa AI' ?>
                </button>
            </form>
            <?php endif; ?>
        </div>
        <?php if ($n): ?>
            <div style="font-size:13.5px;line-height:1.7;color:var(--ink,#0f172a)"><?= nl2br(_ua_e($n['teks'])) ?></div>
            <?php if ($o['basi']): ?>
                <div class="ua-note warn" style="margin:10px 0 0">Angka pada halaman ini sudah berubah sejak analisa dibuat. Tekan <b>Perbarui Analisa AI</b> agar sesuai.</div>
            <?php endif; ?>
            <div style="margin-top:10px;font-size:11px;color:var(--muted,#64748b)">
                Dihasilkan <?= _ua_e(date('d M Y H:i', strtotime((string) $n['dibuat_pada']))) ?> oleh engine <b><?= _ua_e(AiNarasi::namaTampil((string) $n['model'])) ?></b> (<?= _ua_e($n['model']) ?>, <?= _ua_e($n['penyedia'] ?? 'Anthropic') ?>) ·
                dasar: <?= _ua_e($o['dasar']) ?> AI hanya menjelaskan angka, bukan menghitung; semua penyebab berupa dugaan. Periksa sebelum dikutip.
            </div>
        <?php elseif (!$o['siap']): ?>
            <div style="font-size:13px;color:var(--muted,#64748b)">Fitur Analisa AI belum diaktifkan di server ini.</div>
        <?php elseif (!$o['bisa']): ?>
            <div style="font-size:13px;color:var(--muted,#64748b)">Belum ada bulan berdata pada jendela ini, jadi belum ada yang bisa dianalisa.</div>
        <?php else: ?>
            <div style="font-size:13px;color:var(--muted,#64748b)"><?= $o['kosong'] ?></div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * POST ?r=unit_analysis_ai — buat/perbarui Analisa AI satu unit (skop=unit) atau satu segmen (skop=segmen).
 * AI dipanggil hanya bila angkanya berubah dan tidak lebih sering dari 1x / 20 detik per sesi.
 */
function unit_analysis_ai(PDO $pdo): void
{
    require_permission('view_dashboard');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        redirect_to('unit_analysis');
    }
    verify_csrf();

    $pid    = current_property_id();
    $skop   = post('skop', '') === 'unit' ? 'unit' : 'segmen';
    $rawMod = post('module', '');
    if (!is_string($rawMod) || !array_key_exists($rawMod, UA_MODULES)) {
        flash('Segmen tidak dikenal.');
        redirect_to('unit_analysis');
    }
    $module = $rawMod;
    $code   = is_string(post('code', '')) ? trim((string) post('code', '')) : '';
    $akhir  = _ua_bulan(post('akhir', ''), '');

    // parameter untuk kembali ke halaman asal (hanya yang dikenal)
    $to    = _ua_bulan(post('to', ''), '');
    $tahun = is_string(post('tahun', '')) && preg_match('/^20\d{2}$/', (string) post('tahun', '')) ? (string) post('tahun', '') : '';
    $q     = mb_substr(trim(is_string(post('q', '')) ? (string) post('q', '') : ''), 0, 60);
    $urut  = is_string(post('urut', '')) ? (string) post('urut', '') : '';
    if (!in_array($urut, ['okupansi_desc', 'okupansi_asc', 'pendapatan_desc', 'harga_desc', 'kosong_desc', 'kode'], true)) $urut = '';
    $balik = array_filter(['module' => $module, 'to' => $to, 'tahun' => $tahun], fn($v) => $v !== '');
    if ($skop === 'unit') {
        $rute = 'unit_analysis_detail';
        $balik['code'] = $code;
    } else {
        $rute = 'unit_analysis';
        $balik += array_filter(['q' => $q, 'urut' => $urut], fn($v) => $v !== '');
    }

    if ($akhir === '') {
        flash('Periode tidak valid.');
        redirect_to($rute, $balik);
    }
    if (!AiNarasi::terkonfigurasi()) {
        flash('Fitur Analisa AI belum diaktifkan di server ini.');
        redirect_to($rute, $balik);
    }

    if ($skop === 'unit') {
        $mid = $code !== '' && strlen($code) <= 40 ? _ua_master_id($pdo, $pid, $module, $code) : null;
        if ($mid === null) {
            flash('Unit tidak ditemukan di properti aktif.');
            redirect_to('unit_analysis', ['module' => $module]);
        }
        $d = unit_analysis_data($pdo, $pid, $module, $akhir, $code);
        if (!isset($d['unit'][$code]) || $d['unit'][$code]['agregat']['hari_tersedia'] === 0) {
            flash('Belum ada bulan berdata pada jendela ini, jadi belum ada yang bisa dianalisa.');
            redirect_to($rute, $balik);
        }
        $jenis = AiNarasi::jenisUnit($module, $mid);
        $fakta = AiNarasi::faktaUnit($d, $code);
        $label = $code . ' (' . UA_MODULES[$module] . ') · ' . _ua_label($d['meta']['awal']) . ' – ' . _ua_label($d['meta']['akhir']);
        $nama  = 'unit ' . $code;
    } else {
        $d = unit_analysis_data($pdo, $pid, $module, $akhir);
        if ($d['segmen']['jumlah_unit'] === 0 || $d['segmen']['hari_tersedia'] === 0) {
            flash('Belum ada bulan berdata pada jendela ini, jadi belum ada yang bisa dianalisa.');
            redirect_to($rute, $balik);
        }
        $jenis = AiNarasi::jenisSegmen($module, $pid);
        $fakta = AiNarasi::faktaSegmen($d);
        $label = UA_MODULES[$module] . ' · ' . _ua_label($d['meta']['awal']) . ' – ' . _ua_label($d['meta']['akhir']);
        $nama  = 'segmen ' . UA_MODULES[$module];
    }
    $hash = AiNarasi::hash($fakta);

    $ada = AiNarasi::ambil($pdo, $jenis, $akhir);
    if ($ada && hash_equals((string) $ada['fakta_hash'], $hash)) {
        flash('Analisa AI sudah sesuai dengan angka terbaru — tidak perlu diperbarui.');
        redirect_to($rute, $balik);
    }

    // Pelindung biaya: maksimal 1 panggilan AI per 20 detik per sesi.
    $sisa = 20 - (time() - (int) ($_SESSION['ua_ai_terakhir'] ?? 0));
    if ($sisa > 0) {
        flash('Analisa AI baru saja diminta. Mohon tunggu ' . $sisa . ' detik lagi, lalu tekan tombolnya kembali.');
        redirect_to($rute, $balik);
    }
    $_SESSION['ua_ai_terakhir'] = time();

    try {
        [$teks, $masuk, $keluar] = $skop === 'unit' ? AiNarasi::tulisUnit($fakta, $label) : AiNarasi::tulisSegmen($fakta, $label);
    } catch (RuntimeException $e) {
        $_SESSION['ua_ai_terakhir'] = time();
        flash($e->getMessage());
        redirect_to($rute, $balik);
    }
    $_SESSION['ua_ai_terakhir'] = time();   // jeda dihitung juga dari selesainya panggilan

    AiNarasi::simpan($pdo, $jenis, $akhir, $teks, AiNarasi::model(), $hash, $masuk, $keluar, ($_SESSION['user']['id'] ?? null));
    audit($pdo, 'generate', 'ai_narasi', $jenis . '/' . $akhir, ['model' => AiNarasi::model(), 'token_masuk' => $masuk, 'token_keluar' => $keluar], [], 'reporting');
    flash('Analisa AI untuk ' . $nama . ' dibuat.');
    redirect_to($rute, $balik);
}

// ───────────────────────── halaman daftar ─────────────────────────

function unit_analysis_page(PDO $pdo): void
{
    require_permission('view_dashboard');
    $pid    = current_property_id();
    $module = _ua_modul(getv('module', 'cl'));
    [$to, $tahun] = _ua_param_akhir($pdo, $pid);
    $q    = mb_substr(trim((string) (is_string(getv('q', '')) ? getv('q', '') : '')), 0, 60);
    $urut = is_string(getv('urut', '')) ? (string) getv('urut', '') : '';
    if (!in_array($urut, ['okupansi_desc', 'okupansi_asc', 'pendapatan_desc', 'harga_desc', 'kosong_desc', 'kode'], true)) $urut = 'okupansi_desc';

    $d = unit_analysis_data($pdo, $pid, $module, $to);
    $unit = _ua_urutkan(_ua_saring($d['unit'], $q), $urut);
    $opsiBulan = _ua_opsi_bulan($pdo, $pid);
    $tahunOpsi = array_values(array_unique(array_map(fn($ym) => substr($ym, 0, 4), $opsiBulan)));
    $base = ['module' => $module, 'to' => $tahun === '' ? $to : '', 'tahun' => $tahun, 'q' => $q, 'urut' => $urut];
    $adaCsv = can('export_reports');
    $aiSiap = AiNarasi::terkonfigurasi();
    $aiNarasi = $aiSiap ? AiNarasi::ambil($pdo, AiNarasi::jenisSegmen($module, $pid), $d['meta']['akhir']) : null;
    $aiBasi = $aiNarasi !== null && $d['segmen']['hari_tersedia'] > 0
        && !hash_equals((string) $aiNarasi['fakta_hash'], AiNarasi::hash(AiNarasi::faktaSegmen($d)));
    $aiBisa = $d['segmen']['jumlah_unit'] > 0 && $d['segmen']['hari_tersedia'] > 0;

    layout('Analisa Unit', function () use ($d, $unit, $module, $to, $tahun, $q, $urut, $opsiBulan, $tahunOpsi, $base, $adaCsv, $aiSiap, $aiNarasi, $aiBasi, $aiBisa) {
        $meta = $d['meta']; $seg = $d['segmen'];
        _ua_css(); ?>
        <div class="ua-wrap">
        <div class="ua-head">
            <div>
                <div class="muted" style="font-size:14px">Keterisian, harga rata-rata, dan pengisi tiap slot sewa selama 12 bulan: <b><?= _ua_e(_ua_label($meta['awal'])) ?> – <?= _ua_e(_ua_label($meta['akhir'])) ?></b></div>
            </div>
            <div class="ua-tabs">
                <?php foreach (UA_MODULES as $k => $lbl): ?>
                    <a class="ua-tab <?= $k === $module ? 'on' : '' ?>" href="<?= _ua_e(_ua_url('unit_analysis', array_merge($base, ['module' => $k, 'q' => '']))) ?>"><?= _ua_e($lbl) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <form method="get" class="ua-filter" id="ua-filter">
            <input type="hidden" name="r" value="unit_analysis">
            <input type="hidden" name="module" value="<?= _ua_e($module) ?>">
            <div class="f"><label>Sampai bulan</label>
                <select name="to" id="ua-to" onchange="document.getElementById('ua-tahun').value='';this.form.submit()">
                    <?php foreach ($opsiBulan as $ym): ?>
                        <option value="<?= _ua_e($ym) ?>" <?= $ym === $to ? 'selected' : '' ?>><?= _ua_e(period_label($ym)) ?></option>
                    <?php endforeach; ?>
                    <?php if (!in_array($to, $opsiBulan, true)): ?><option value="<?= _ua_e($to) ?>" selected><?= _ua_e(period_label($to)) ?></option><?php endif; ?>
                </select></div>
            <div class="f"><label>Atau tahun kalender</label>
                <select name="tahun" id="ua-tahun" onchange="this.form.submit()">
                    <option value="">— pilih —</option>
                    <?php foreach ($tahunOpsi as $y): ?><option value="<?= _ua_e($y) ?>" <?= $y === $tahun ? 'selected' : '' ?>><?= _ua_e($y) ?> (Jan–Des)</option><?php endforeach; ?>
                </select></div>
            <div class="f grow"><label>Cari unit</label><input type="search" name="q" value="<?= _ua_e($q) ?>" placeholder="Kode, lokasi, lantai, atau tipe"></div>
            <div class="f"><label>Urutkan</label>
                <select name="urut" onchange="this.form.submit()">
                    <?php foreach (['okupansi_desc' => 'Okupansi tertinggi', 'okupansi_asc' => 'Okupansi terendah', 'pendapatan_desc' => 'Pendapatan terbesar', 'harga_desc' => 'Harga per bulan tertinggi', 'kosong_desc' => 'Kosong terpanjang', 'kode' => 'Urutan master'] as $k => $l): ?>
                        <option value="<?= _ua_e($k) ?>" <?= $k === $urut ? 'selected' : '' ?>><?= _ua_e($l) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <button type="submit">Terapkan</button>
            <?php if ($adaCsv): ?><a class="btn light" href="<?= _ua_e(_ua_url('unit_analysis_csv', $base)) ?>">Export CSV</a><?php endif; ?>
        </form>

        <?php if ($meta['bulan_pertama_data'] === null): ?>
            <div class="ua-note warn">Segmen <?= _ua_e($meta['module_label']) ?> belum punya transaksi di properti ini.</div>
        <?php elseif ($meta['bulan_pertama_data'] > $meta['awal']): ?>
            <div class="ua-note info">Data riwayat baru ada sejak <b><?= _ua_e(_ua_label($meta['bulan_pertama_data'])) ?></b>. Bulan sebelumnya ditampilkan abu-abu &quot;belum ada data&quot; dan tidak dihitung sebagai kosong.</div>
        <?php endif; ?>
        <?php if ($meta['akhir'] > $meta['hari_ini_bulan']): ?>
            <div class="ua-note info">Bulan setelah <?= _ua_e(_ua_label($meta['hari_ini_bulan'])) ?> berstatus <b>terjadwal</b> (garis putus-putus) dan tidak ikut dihitung dalam angka di bawah.</div>
        <?php endif; ?>
        <?php if ($meta['dibatasi_sales']): ?>
            <div class="ua-note warn">Peran Sales: daftar penyewa dan kontrak di halaman detail hanya menampilkan data Anda. Angka keterisian dan pendapatan per unit tetap total, sama seperti Dashboard.</div>
        <?php endif; ?>
        <?php if ($meta['jumlah_orphan'] > 0): ?>
            <div class="ua-note warn"><?= (int) $meta['jumlah_orphan'] ?> kode pada transaksi tidak ada di master properti ini (<?= _ua_e(implode(', ', array_map(fn($c) => (string) $c === '' ? '(kode kosong)' : (string) $c, array_keys($seg['orphan'])))) ?>), nilai <?= _ua_e(money($seg['orphan_nilai'])) ?>. Tidak masuk daftar unit maupun tabel okupansi Executive Summary.</div>
        <?php endif; ?>

        <div class="ua-kpis">
            <div class="ua-kpi"><div class="l">Jumlah unit</div><div class="v"><?= _ua_angka((float) $seg['jumlah_unit']) ?></div><div class="s"><?= _ua_e($meta['module_label']) ?><?= $seg['nonaktif_unit'] > 0 ? ', termasuk ' . (int) $seg['nonaktif_unit'] . ' unit nonaktif yang punya riwayat (' . _ua_e(_ua_rp($seg['nonaktif_nilai'])) . '), yang tidak dihitung di Executive Summary' : '' ?></div></div>
            <div class="ua-kpi"><div class="l">Okupansi rata-rata</div><div class="v"><?= _ua_persen($seg['okupansi']) ?></div><div class="s"><?= _ua_angka((float) $seg['hari_terisi']) ?> dari <?= _ua_angka((float) $seg['hari_tersedia']) ?> hari-unit</div></div>
            <div class="ua-kpi"><div class="l">Total pendapatan</div><div class="v" title="<?= _ua_e(money($seg['pendapatan'])) ?>"><?= _ua_e(_ua_rp($seg['pendapatan'])) ?></div><div class="s">12 bulan, bulan berdata saja</div></div>
            <div class="ua-kpi" style="border-top-color:#EF4444"><div class="l">Tidak pernah terisi</div><div class="v"><?= _ua_angka((float) $seg['tak_pernah']) ?></div><div class="s">unit tanpa hari terisi pada jendela</div></div>
            <div class="ua-kpi" style="border-top-color:#F59E0B"><div class="l">Bulan berdata</div><div class="v"><?= count(array_filter($d['bulan'], fn($b) => $b['state'] === 'data')) ?> / 12</div><div class="s">sisanya belum ada data atau terjadwal</div></div>
        </div>

        <?php _ua_kartu_ai([
            'judul' => 'Analisa AI segmen ' . $meta['module_label'], 'narasi' => $aiNarasi, 'siap' => $aiSiap, 'bisa' => $aiBisa, 'basi' => $aiBasi,
            'hidden' => ['skop' => 'segmen', 'module' => $module, 'akhir' => $meta['akhir'], 'to' => $base['to'], 'tahun' => $tahun, 'q' => $q, 'urut' => $urut],
            'kosong' => 'Belum ada analisa untuk segmen ini pada jendela ini. Tekan <b>Analisa AI</b> — dihasilkan sekali lalu disimpan; ringkasan seluruh unit segmen dan beberapa unit menonjol.',
            'dasar' => 'angka ringkasan segmen dan daftar singkat unit menonjol pada halaman ini (tanpa nama penyewa).',
        ]); ?>

        <div class="ua-two">
            <?php foreach (['terlaris' => 'Lima unit terlaris', 'tersepi' => 'Lima unit tersepi (yang pernah terisi)'] as $k => $jd): ?>
            <div class="ua-card">
                <h2><?= _ua_e($jd) ?></h2>
                <?php if (!$seg[$k]): ?><div class="muted">Belum ada unit yang terisi.</div><?php else: ?>
                <table class="ua-rank"><tbody>
                <?php foreach ($seg[$k] as $r): ?>
                    <tr><td><a href="<?= _ua_e(_ua_url('unit_analysis_detail', ['module' => $module, 'code' => $r['kode'], 'to' => $tahun === '' ? $to : '', 'tahun' => $tahun])) ?>"><?= _ua_e($r['kode']) ?></a></td>
                        <td class="num" style="text-align:right"><?= _ua_persen($r['okupansi']) ?><span class="ua-bar"><i style="width:<?= (int) min(100, round((float) $r['okupansi'])) ?>%"></i></span></td>
                        <td class="num" style="text-align:right;color:var(--muted)"><?= _ua_e(_ua_rp($r['pendapatan'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="ua-card">
            <h2>Per unit <span class="muted" style="font-weight:400">(<?= count($unit) ?> unit<?= $q !== '' ? ', hasil pencarian' : '' ?>; klik judul kolom untuk mengurutkan)</span></h2>
            <?= _ua_legenda() ?>
            <?php if (!$unit): ?><div class="ua-empty">Tidak ada unit yang cocok.</div><?php else: ?>
            <div class="table-wrap">
            <table class="ua-table" id="ua-tabel">
                <thead><tr>
                    <th data-t="s">Kode</th><th data-t="s"><?= $module === 'gudang' ? 'Lokasi / nama' : ($module === 'media' ? 'Lokasi / jenis' : 'Lantai / lokasi / tipe') ?></th>
                    <?php if ($module !== 'media'): ?><th data-t="n" class="num">Luas m²</th><?php endif; ?>
                    <th data-t="n">Okupansi</th><th class="nos">12 bulan (<?= _ua_e(UA_BULAN_PENDEK[substr($meta['awal'], 5, 2)]) ?> → <?= _ua_e(UA_BULAN_PENDEK[substr($meta['akhir'], 5, 2)]) ?>)</th>
                    <th data-t="n" class="num">Kontrak</th><th data-t="n" class="num">Penyewa</th><th data-t="n" class="num">Pendapatan</th>
                    <th data-t="n" class="num">Harga / bulan</th><?php if ($module !== 'media'): ?><th data-t="n" class="num">Harga / m² / bulan</th><?php endif; ?>
                    <th data-t="n" class="num">Tarif master</th><th data-t="n" class="num">Selisih</th><th data-t="n" class="num">Kosong terpanjang</th>
                </tr></thead>
                <tbody>
                <?php foreach ($unit as $kode => $x): $m = $x['master']; $a = $x['agregat'];
                    $href = _ua_url('unit_analysis_detail', ['module' => $module, 'code' => $kode, 'to' => $tahun === '' ? $to : '', 'tahun' => $tahun]); ?>
                <tr>
                    <td data-v="<?= _ua_e($kode) ?>"><a class="kode" href="<?= _ua_e($href) ?>"><?= _ua_e($kode) ?></a><?php if ($m['status'] !== 'active'): ?> <span class="ua-pill off">nonaktif</span><?php endif; ?></td>
                    <td class="wrap" data-v="<?= _ua_e($m['lantai'] . ' ' . $m['lokasi']) ?>"><?= _ua_e(trim($m['lantai'] . ($m['lantai'] !== '' && $m['lokasi'] !== '' ? ' · ' : '') . $m['lokasi'])) ?><?php if ($m['tipe'] !== ''): ?><span class="sub"><?= _ua_e($m['tipe']) ?></span><?php endif; ?></td>
                    <?php if ($module !== 'media'): ?><td class="num" data-v="<?= _ua_e($m['luas']) ?>"><?= _ua_angka($m['luas'], 2) ?></td><?php endif; ?>
                    <td data-v="<?= _ua_e($a['okupansi'] ?? -1) ?>"><b><?= _ua_persen($a['okupansi']) ?></b><span class="ua-bar"><i style="width:<?= (int) min(100, round((float) ($a['okupansi'] ?? 0))) ?>%"></i></span><span class="sub"><?= (int) $a['hari_terisi'] ?>/<?= (int) $a['hari_tersedia'] ?> hari · <?= (int) $a['bulan_terisi'] ?>/<?= (int) $a['bulan_tersedia'] ?> bln</span></td>
                    <td><span class="ua-strip"><?php foreach ($x['bulan'] as $ym => $b) echo _ua_kotak($b, $ym); ?></span></td>
                    <td class="num" data-v="<?= (int) $a['kontrak'] ?>"><?= (int) $a['kontrak'] ?></td>
                    <td class="num" data-v="<?= (int) $a['penyewa_unik'] ?>"><?= (int) $a['penyewa_unik'] ?></td>
                    <td class="num" data-v="<?= _ua_e($a['pendapatan']) ?>" title="<?= _ua_e(money($a['pendapatan'])) ?>"><?= _ua_e(_ua_rp($a['pendapatan'])) ?></td>
                    <td class="num" data-v="<?= _ua_e($a['harga_per_bulan'] ?? -1) ?>"><?= $a['harga_per_bulan'] !== null ? _ua_e(_ua_rp($a['harga_per_bulan'])) : '–' ?></td>
                    <?php if ($module !== 'media'): ?><td class="num" data-v="<?= _ua_e($a['harga_per_m2_bulan'] ?? -1) ?>"><?= $a['harga_per_m2_bulan'] !== null ? _ua_e(_ua_rp($a['harga_per_m2_bulan'])) : '–' ?></td><?php endif; ?>
                    <td class="num" data-v="<?= _ua_e($m['tarif']) ?>" title="<?= _ua_e($m['satuan_tarif']) ?>"><?= _ua_e(_ua_rp($m['tarif'])) ?><span class="sub"><?= _ua_e($m['satuan_tarif']) ?></span></td>
                    <td class="num" data-v="<?= _ua_e($a['selisih_vs_master_pct'] ?? -9999) ?>"><?php
                        $sl = $a['selisih_vs_master_pct'];
                        if ($sl === null) echo '–';
                        else echo '<span class="ua-pill ' . ($sl >= 5 ? 'up' : ($sl <= -5 ? 'down' : 'flat')) . '">' . ($sl > 0 ? '+' : '') . _ua_e(number_format($sl, 1, ',', '.')) . '%</span>'; ?></td>
                    <td class="num" data-v="<?= (int) $a['kosong_terpanjang_hari'] ?>"><?php
                        if ($a['kosong_terpanjang_hari'] > 0) echo (int) $a['kosong_terpanjang_hari'] . ' hari<span class="sub">' . _ua_e(_ua_tgl((string) $a['kosong_mulai'])) . '</span>'; else echo '–'; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <?php _ua_blok_definisi($module, $meta); ?>
        </div>
        <script>
        (function(){
            var t = document.getElementById('ua-tabel'); if(!t) return;
            var ths = t.querySelectorAll('thead th');
            ths.forEach(function(th, idx){
                if(!th.dataset.t) { th.classList.add('nos'); return; }
                th.addEventListener('click', function(){
                    var asc = !th.classList.contains('asc');
                    ths.forEach(function(x){ x.classList.remove('asc','desc'); });
                    th.classList.add(asc ? 'asc' : 'desc');
                    var tb = t.tBodies[0], rows = Array.prototype.slice.call(tb.rows);
                    rows.sort(function(a, b){
                        var va = a.cells[idx].dataset.v, vb = b.cells[idx].dataset.v;
                        var r = th.dataset.t === 'n' ? (parseFloat(va) - parseFloat(vb)) : String(va).localeCompare(String(vb), 'id', {numeric:true});
                        return asc ? r : -r;
                    });
                    rows.forEach(function(r){ tb.appendChild(r); });
                });
            });
        })();
        </script>
        <?php
    });
}

// ───────────────────────── halaman detail ─────────────────────────

function unit_analysis_detail_page(PDO $pdo): void
{
    require_permission('view_dashboard');
    $pid  = current_property_id();
    $rawModul = getv('module', '');
    if (!is_string($rawModul) || !array_key_exists($rawModul, UA_MODULES)) {
        flash('Segmen tidak dikenal.');
        redirect_to('unit_analysis');
    }
    $module = $rawModul;
    $code = getv('code', '');
    $code = is_string($code) ? trim($code) : '';
    [$to, $tahun] = _ua_param_akhir($pdo, $pid);

    $master = $code !== '' && strlen($code) <= 40 ? (_ua_master($pdo, $pid, $module)[$code] ?? null) : null;
    if ($master === null) {
        flash('Unit tidak ditemukan di properti aktif.');
        redirect_to('unit_analysis', ['module' => $module]);
    }

    $d = unit_analysis_data($pdo, $pid, $module, $to, $code);
    $x = $d['unit'][$code] ?? null;
    if ($x === null) {   // unit nonaktif tanpa riwayat pada jendela ini
        $x = ['master' => $master, 'agregat' => null, 'bulan' => [], 'temuan' => []];
    }
    $pen = $d['penyewa']['per_unit'][$code] ?? ['ringkas' => [], 'kontrak' => []];
    $opsiBulan = _ua_opsi_bulan($pdo, $pid);
    $tahunOpsi = array_values(array_unique(array_map(fn($ym) => substr($ym, 0, 4), $opsiBulan)));
    $mask = $d['harian'][$code] ?? [];
    $backParams = ['module' => $module, 'to' => $tahun === '' ? $to : '', 'tahun' => $tahun];
    $aiSiap = AiNarasi::terkonfigurasi();
    $aiBisa = $x['agregat'] !== null && $x['agregat']['hari_tersedia'] > 0;
    $aiNarasi = null; $aiBasi = false;
    if ($aiSiap && $aiBisa && ($mid = _ua_master_id($pdo, $pid, $module, $code)) !== null) {
        $aiNarasi = AiNarasi::ambil($pdo, AiNarasi::jenisUnit($module, $mid), $d['meta']['akhir']);
        $aiBasi = $aiNarasi !== null && !hash_equals((string) $aiNarasi['fakta_hash'], AiNarasi::hash(AiNarasi::faktaUnit($d, $code)));
    }

    layout('Analisa Unit ' . $code, function () use ($d, $x, $pen, $module, $code, $to, $tahun, $opsiBulan, $tahunOpsi, $mask, $backParams, $master, $aiSiap, $aiBisa, $aiNarasi, $aiBasi) {
        $meta = $d['meta']; $m = $x['master']; $a = $x['agregat']; $seg = $d['segmen'];
        _ua_css(); ?>
        <div class="ua-wrap">
        <div class="ua-head">
            <div>
                <a class="muted" href="<?= _ua_e(_ua_url('unit_analysis', $backParams)) ?>">← Kembali ke daftar <?= _ua_e($meta['module_label']) ?></a>
                <h1 style="margin-top:6px"><?= _ua_e($code) ?> <?php if ($m['status'] !== 'active'): ?><span class="ua-pill off">nonaktif</span><?php endif; ?></h1>
                <div class="ua-attr">
                    <?php if ($m['lantai'] !== ''): ?><span><?= $module === 'gudang' ? 'Lokasi' : 'Lantai' ?>: <b><?= _ua_e($m['lantai']) ?></b></span><?php endif; ?>
                    <?php if ($m['lokasi'] !== ''): ?><span><?= $module === 'gudang' ? 'Nama' : 'Lokasi' ?>: <b><?= _ua_e($m['lokasi']) ?></b></span><?php endif; ?>
                    <?php if ($m['tipe'] !== ''): ?><span><?= $module === 'media' ? 'Jenis' : 'Tipe' ?>: <b><?= _ua_e($m['tipe']) ?></b></span><?php endif; ?>
                    <?php if ($module !== 'media'): ?><span>Luas: <b><?= angka($m['luas']) ?> m²</b></span><?php endif; ?>
                    <?php if ($module === 'media' && $m['ukuran'] !== ''): ?><span>Ukuran: <b><?= _ua_e($m['ukuran']) ?></b></span><?php endif; ?>
                    <?php if ($module === 'media' && $m['slot'] !== null): ?><span>Slot master: <b><?= _ua_angka($m['slot'], $m['slot'] == floor($m['slot']) ? 0 : 1) ?></b></span><?php endif; ?>
                    <span>Tarif master: <b><?= _ua_e(money($m['tarif'])) ?></b> <?= _ua_e($m['satuan_tarif']) ?></span>
                </div>
            </div>
            <form method="get" class="ua-filter" style="margin:0">
                <input type="hidden" name="r" value="unit_analysis_detail">
                <input type="hidden" name="module" value="<?= _ua_e($module) ?>">
                <input type="hidden" name="code" value="<?= _ua_e($code) ?>">
                <div class="f"><label>Sampai bulan</label>
                    <select name="to" onchange="this.form.elements['tahun'].value='';this.form.submit()">
                        <?php foreach ($opsiBulan as $ym): ?><option value="<?= _ua_e($ym) ?>" <?= $ym === $to ? 'selected' : '' ?>><?= _ua_e(period_label($ym)) ?></option><?php endforeach; ?>
                        <?php if (!in_array($to, $opsiBulan, true)): ?><option value="<?= _ua_e($to) ?>" selected><?= _ua_e(period_label($to)) ?></option><?php endif; ?>
                    </select></div>
                <div class="f"><label>Atau tahun</label>
                    <select name="tahun" onchange="this.form.submit()"><option value="">— pilih —</option>
                        <?php foreach ($tahunOpsi as $y): ?><option value="<?= _ua_e($y) ?>" <?= $y === $tahun ? 'selected' : '' ?>><?= _ua_e($y) ?></option><?php endforeach; ?>
                    </select></div>
            </form>
        </div>
        <div class="muted" style="margin:10px 0 12px">Jendela: <b><?= _ua_e(_ua_label($meta['awal'])) ?> – <?= _ua_e(_ua_label($meta['akhir'])) ?></b></div>

        <?php if ($meta['bulan_pertama_data'] === null || $meta['bulan_pertama_data'] > $meta['awal']): ?>
            <div class="ua-note info">Data riwayat <?= _ua_e($meta['module_label']) ?> baru ada sejak <b><?= $meta['bulan_pertama_data'] ? _ua_e(_ua_label($meta['bulan_pertama_data'])) : '–' ?></b>. Bulan sebelumnya abu-abu, bukan kosong.</div>
        <?php endif; ?>
        <?php if ($meta['dibatasi_sales']): ?>
            <div class="ua-note warn">Hanya data Anda: tabel penyewa dan kontrak di bawah dibatasi ke transaksi milik Anda; angka metrik tetap total unit.</div>
        <?php endif; ?>

        <?php if ($a === null): ?>
            <div class="ua-card ua-empty">Unit nonaktif ini tidak punya riwayat pada jendela yang dipilih.</div>
        <?php else: ?>
        <div class="ua-kpis k6">
            <div class="ua-kpi"><div class="l">Okupansi</div><div class="v"><?= _ua_persen($a['okupansi']) ?></div><div class="s">segmen: <?= _ua_persen($seg['okupansi']) ?></div></div>
            <div class="ua-kpi"><div class="l">Hari terisi</div><div class="v"><?= (int) $a['hari_terisi'] ?> <span class="muted">/ <?= (int) $a['hari_tersedia'] ?></span></div><div class="s"><?= (int) $a['bulan_terisi'] ?> dari <?= (int) $a['bulan_tersedia'] ?> bulan terisi</div></div>
            <div class="ua-kpi"><div class="l">Pendapatan</div><div class="v" title="<?= _ua_e(money($a['pendapatan'])) ?>"><?= _ua_e(_ua_rp($a['pendapatan'])) ?></div><div class="s"><?= (int) $a['kontrak'] ?> kontrak / booking</div></div>
            <div class="ua-kpi"><div class="l">Harga rata-rata</div><div class="v"><?= $a['harga_per_hari'] !== null ? _ua_e(_ua_rp($a['harga_per_hari'])) : '–' ?></div><div class="s">per hari terisi<?= $a['harga_per_bulan'] !== null ? ' · ' . _ua_e(_ua_rp($a['harga_per_bulan'])) . ' / bulan setara' : '' ?></div></div>
            <div class="ua-kpi"><div class="l"><?= $module === 'media' ? 'Tarif master' : 'Harga per m²' ?></div>
                <?php if ($module === 'media'): ?>
                    <div class="v"><?= _ua_e(_ua_rp($m['tarif'])) ?></div><div class="s"><?= _ua_e($m['satuan_tarif']) ?></div>
                <?php else: ?>
                    <div class="v"><?= $a['harga_per_m2_bulan'] !== null ? _ua_e(_ua_rp($a['harga_per_m2_bulan'])) : '–' ?></div><div class="s">per m² per bulan setara<?= $m['luas'] <= 0 ? ' · luas 0, dilewati' : '' ?></div>
                <?php endif; ?></div>
            <div class="ua-kpi"><div class="l">Selisih vs master</div><div class="v"><?= $a['selisih_vs_master_pct'] !== null ? (($a['selisih_vs_master_pct'] > 0 ? '+' : '') . _ua_persen($a['selisih_vs_master_pct'])) : '–' ?></div>
                <div class="s"><?= $a['selisih_vs_master_pct'] !== null ? 'terhadap tarif master ' . _ua_e($m['satuan_tarif']) : 'satuan tarif tidak sebanding atau belum ada harga' ?></div></div>
            <div class="ua-kpi" style="border-top-color:#EF4444"><div class="l">Kosong terpanjang</div><div class="v"><?= (int) $a['kosong_terpanjang_hari'] ?> hari</div><div class="s"><?= $a['kosong_terpanjang_hari'] > 0 ? _ua_e(_ua_tgl((string) $a['kosong_mulai'])) . ' – ' . _ua_e(_ua_tgl((string) $a['kosong_akhir'])) : 'tidak ada' ?></div></div>
            <div class="ua-kpi" style="border-top-color:#3B82F6"><div class="l">Penyewa unik</div><div class="v"><?= (int) $a['penyewa_unik'] ?></div><div class="s"><?= (int) $a['penyewa_kembali'] ?> kembali</div></div>
            <div class="ua-kpi" style="border-top-color:#F59E0B"><div class="l">Terjadwal</div><div class="v"><?= (int) $a['hari_terjadwal'] ?> hari</div><div class="s"><?= $a['nilai_terjadwal'] > 0 ? _ua_e(_ua_rp($a['nilai_terjadwal'])) . ' di bulan mendatang' : 'tidak ada bulan mendatang' ?></div></div>
        </div>

        <div class="ua-card">
            <h2>Hari terisi dan harga rata-rata per bulan</h2>
            <div class="ua-chart"><canvas id="ua-chart"></canvas></div>
        </div>

        <div class="ua-card">
            <h2>Keterisian per hari</h2>
            <?= _ua_legenda() ?>
            <?php $hariIni = date('Y-m-d'); ?>
            <div class="ua-heat">
                <div></div><?php for ($dd = 1; $dd <= 31; $dd++): ?><div class="hd"><?= $dd ?></div><?php endfor; ?>
                <?php foreach ($d['bulan'] as $b): $ym = $b['ym']; $mk = (int) ($mask[$ym] ?? 0); ?>
                    <div class="hl"><?= _ua_e(_ua_label($ym)) ?></div>
                    <?php for ($dd = 1; $dd <= 31; $dd++):
                        if ($dd > $b['hari']) { echo '<div class="ua-hc x"></div>'; continue; }
                        $f = ($mk >> ($dd - 1)) & 1;
                        $cls = 'ua-hc';
                        if ($b['state'] === 'nodata') $cls .= ' nd';
                        else { if ($f) $cls .= ' f'; if ($b['state'] === 'scheduled') $cls .= ' sc'; }
                        if (sprintf('%s-%02d', $ym, $dd) === $hariIni) $cls .= ' today';
                        $tgl = sprintf('%s-%02d', $ym, $dd);
                        $tip = _ua_tgl($tgl) . ': ' . ($b['state'] === 'nodata' ? 'belum ada data' : ($f ? ($b['state'] === 'scheduled' ? 'terjadwal terisi' : 'terisi') : 'kosong'));
                        echo '<div class="' . $cls . '" title="' . _ua_e($tip) . '"></div>';
                    endfor; ?>
                <?php endforeach; ?>
            </div>
            <div class="muted" style="margin-top:8px">Bingkai kuning = hari ini.</div>
        </div>

        <div class="ua-card">
            <h2>Temuan otomatis <span class="muted" style="font-weight:400">(berbasis aturan, bukan AI)</span></h2>
            <?php if (!$x['temuan']): ?><div class="muted">Belum ada temuan.</div><?php else: ?>
            <ul class="ua-temuan"><?php foreach ($x['temuan'] as $t): ?><li class="<?= _ua_e($t['tipe']) ?>"><?= _ua_e($t['teks']) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        </div>

        <div id="ai-slot"><?php _ua_kartu_ai([
            'judul' => 'Analisa AI unit ' . $code, 'narasi' => $aiNarasi, 'siap' => $aiSiap, 'bisa' => $aiBisa, 'basi' => $aiBasi,
            'hidden' => ['skop' => 'unit', 'module' => $module, 'code' => $code, 'akhir' => $meta['akhir'], 'to' => $tahun === '' ? $to : '', 'tahun' => $tahun],
            'kosong' => 'Belum ada analisa untuk unit ini pada jendela ini. Tekan <b>Analisa AI</b> — dihasilkan sekali lalu disimpan.',
            'dasar' => 'angka keterisian, harga, dan penyewa (jumlah saja, tanpa nama) pada halaman ini.',
        ]); ?></div>

        <div class="ua-card">
            <h2>Siapa pengisinya <span class="muted" style="font-weight:400">(dalam jendela)</span></h2>
            <?php if (!$pen['ringkas']): ?><div class="ua-empty">Tidak ada penyewa<?= $meta['dibatasi_sales'] ? ' milik Anda' : '' ?> pada jendela ini.</div><?php else: ?>
            <div class="table-wrap"><table class="ua-table"><thead><tr><th class="nos">Penyewa</th><th class="nos num">Kontrak</th><th class="nos num">Total hari</th><th class="nos num">Nilai diakui</th><th class="nos num">Nilai prorata</th><th class="nos">Periode pertama</th><th class="nos">Periode terakhir</th></tr></thead><tbody>
            <?php foreach ($pen['ringkas'] as $p): ?>
                <tr><td class="wrap"><b><?= _ua_e($p['nama']) ?></b><?php if ($p['brand'] !== ''): ?><span class="sub"><?= _ua_e($p['brand']) ?></span><?php endif; ?></td>
                    <td class="num"><?= (int) $p['kontrak'] ?></td><td class="num"><?= (int) $p['hari'] ?></td><td class="num"><?= _ua_e(money($p['nilai'])) ?></td><td class="num"><?= _ua_e(money($p['nilai_prorata'])) ?></td>
                    <td><?= _ua_e(_ua_tgl($p['pertama'])) ?></td><td><?= _ua_e(_ua_tgl($p['terakhir'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </div>

        <div class="ua-card">
            <h2>Timeline kontrak</h2>
            <?php if (!$pen['kontrak']): ?><div class="ua-empty">Tidak ada kontrak pada jendela ini.</div><?php else: ?>
            <div class="table-wrap"><table class="ua-table"><thead><tr><th class="nos">Penyewa</th><th class="nos">Mulai</th><th class="nos">Selesai</th><th class="nos num">Durasi</th><th class="nos num">Nilai kontrak</th><th class="nos num">Tarif efektif / hari</th><th class="nos num">Hari &amp; nilai di jendela</th><th class="nos">PIC</th><th class="nos">Status</th><th class="nos">Catatan</th></tr></thead><tbody>
            <?php foreach ($pen['kontrak'] as $k): ?>
                <tr><td class="wrap"><b><?= _ua_e($k['klien']) ?></b><?php if ($k['brand'] !== ''): ?><span class="sub"><?= _ua_e($k['brand']) ?></span><?php endif; ?></td>
                    <td><?= _ua_e(_ua_tgl($k['mulai'])) ?></td><td><?= _ua_e(_ua_tgl($k['selesai'])) ?></td><td class="num"><?= (int) $k['durasi_hari'] ?> hr</td>
                    <td class="num"><?= _ua_e(money($k['nilai_kontrak'])) ?></td><td class="num"><?= _ua_e(money($k['tarif_per_hari'])) ?></td>
                    <td class="num"><?= (int) $k['hari_jendela'] ?> hr<span class="sub">prorata <?= _ua_e(money($k['nilai_prorata'])) ?> · diakui <?= _ua_e(money($k['nilai_jendela'])) ?><?= $k['hari_terjadwal'] > 0 ? ' · +' . (int) $k['hari_terjadwal'] . ' hr terjadwal' : '' ?></span></td>
                    <td><?= _ua_e($k['pic'] !== '' ? $k['pic'] : '–') ?></td>
                    <td><?php
                        $st = [];
                        if ($k['berulang']) $st[] = 'berulang';
                        if ($k['perpanjangan'] !== '' && $k['perpanjangan'] !== 'none') $st[] = 'perpanjangan: ' . $k['perpanjangan'];
                        echo $st ? _ua_e(implode(', ', $st)) : '–'; ?></td>
                    <td class="wrap" style="min-width:160px"><?= _ua_e($k['catatan'] !== '' ? $k['catatan'] : '–') ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php _ua_blok_definisi($module, $meta); ?>
        </div>

        <?php if ($a !== null):
            $lab = $hr = $hg = $wr = [];
            foreach ($d['bulan'] as $b) {
                $bm = $x['bulan'][$b['ym']];
                $lab[] = $b['label'];
                $hr[]  = $b['state'] === 'nodata' ? null : $bm['hari'];
                $hg[]  = $b['state'] === 'data' ? $bm['harga_hari'] : null;
                $wr[]  = $b['state'];
            }
        ?>
        <script src="assets/chart.umd.min.js"></script>
        <script>
        (function(){
            var states = <?= json_encode($wr) ?>;
            var dim = <?= json_encode(array_column($d['bulan'], 'hari')) ?>;
            var ctx = document.getElementById('ua-chart'); if(!ctx || typeof Chart === 'undefined') return;
            var warna = states.map(function(s){ return s === 'scheduled' ? 'rgba(13,148,136,.28)' : 'rgba(13,148,136,.85)'; });
            new Chart(ctx, {
                data: {
                    labels: <?= json_encode($lab) ?>,
                    datasets: [
                        { type:'bar', label:'Hari terisi', data: <?= json_encode($hr) ?>, backgroundColor: warna, borderColor:'#0D9488', borderWidth: states.map(function(s){ return s==='scheduled'?1:0; }), borderRadius:5, yAxisID:'y', order:2 },
                        { type:'line', label:'Harga rata-rata per hari terisi', data: <?= json_encode($hg) ?>, borderColor:'#F59E0B', backgroundColor:'#F59E0B', tension:.25, spanGaps:false, pointRadius:4, yAxisID:'y1', order:1 }
                    ]
                },
                options: {
                    responsive:true, maintainAspectRatio:false, interaction:{mode:'index', intersect:false},
                    plugins:{ legend:{position:'bottom'}, tooltip:{ callbacks:{
                        label:function(c){
                            if(c.parsed.y === null) return null;
                            if(c.dataset.yAxisID === 'y1') return c.dataset.label + ': Rp ' + Math.round(c.parsed.y).toLocaleString('id-ID');
                            return c.dataset.label + ': ' + c.parsed.y + ' dari ' + dim[c.dataIndex] + ' hari' + (states[c.dataIndex]==='scheduled' ? ' (terjadwal)' : '');
                        } } } },
                    scales:{
                        y:{ beginAtZero:true, suggestedMax:31, title:{display:true, text:'Hari terisi'} },
                        y1:{ position:'right', beginAtZero:true, grid:{drawOnChartArea:false}, title:{display:true, text:'Rp per hari'},
                             ticks:{ callback:function(v){ return v>=1e6 ? (v/1e6).toLocaleString('id-ID')+' jt' : v.toLocaleString('id-ID'); } } }
                    }
                }
            });
        })();
        </script>
        <?php endif;
    });
}

// ───────────────────────── CSV ─────────────────────────

function unit_analysis_csv(PDO $pdo): void
{
    require_permission('export_reports');
    $pid    = current_property_id();
    $module = _ua_modul(getv('module', 'cl'));
    [$to, $tahun] = _ua_param_akhir($pdo, $pid);
    $q    = mb_substr(trim((string) (is_string(getv('q', '')) ? getv('q', '') : '')), 0, 60);
    $urut = is_string(getv('urut', '')) ? (string) getv('urut', '') : '';

    $d = unit_analysis_data($pdo, $pid, $module, $to);
    $unit = _ua_urutkan(_ua_saring($d['unit'], $q), $urut);

    $nama = ['cl' => 'exhibition', 'media' => 'media', 'gudang' => 'gudang'][$module];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="unit-analisis-' . $nama . '-' . $d['meta']['akhir'] . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $head = ['kode', 'lantai_lokasi', 'lokasi_nama', 'tipe', 'luas_m2', 'status_unit', 'okupansi_pct', 'hari_terisi', 'hari_tersedia', 'bulan_terisi', 'bulan_tersedia',
             'kontrak', 'penyewa_unik', 'pendapatan', 'harga_per_hari', 'harga_per_bulan_setara', 'harga_per_m2_bulan',
             'tarif_master', 'satuan_tarif_master', 'selisih_vs_master_pct', 'kosong_terpanjang_hari', 'kosong_mulai', 'kosong_akhir', 'hari_terjadwal'];
    foreach ($d['bulan'] as $b) $head[] = 'hari_terisi_' . $b['ym'] . ($b['state'] === 'nodata' ? '_belum_ada_data' : ($b['state'] === 'scheduled' ? '_terjadwal' : ''));
    fputcsv($out, $head, ',', '"', '\\');
    foreach ($unit as $kode => $x) {
        $m = $x['master']; $a = $x['agregat'];
        $row = [$kode, $m['lantai'], $m['lokasi'], $m['tipe'], $module === 'media' ? '' : $m['luas'], $m['status'],
                $a['okupansi'], $a['hari_terisi'], $a['hari_tersedia'], $a['bulan_terisi'], $a['bulan_tersedia'],
                $a['kontrak'], $a['penyewa_unik'], $a['pendapatan'], $a['harga_per_hari'], $a['harga_per_bulan'], $a['harga_per_m2_bulan'],
                $m['tarif'], $m['satuan_tarif'], $a['selisih_vs_master_pct'], $a['kosong_terpanjang_hari'], $a['kosong_mulai'], $a['kosong_akhir'], $a['hari_terjadwal']];
        foreach ($x['bulan'] as $b) $row[] = $b['state'] === 'nodata' ? '' : $b['hari'];
        fputcsv($out, array_map(fn($v) => $v === null ? '' : $v, $row), ',', '"', '\\');
    }
    fclose($out);
    exit;
}
