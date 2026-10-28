<?php
declare(strict_types=1);

/**
 * Kalender Pameran: unit di baris, tanggal 1–31 di kolom, nama merek di selot
 * yang terisi. Pengganti sheet "KALENDER PAMERAN" di Excel Casual Leasing —
 * dengan data yang sama dengan transaksi, jadi tidak ada lagi dua catatan.
 *
 * Hanya membaca. Satu-satunya "tulis" adalah tautan: klik tanggal kosong →
 * Surat Penawaran baru dengan unit & tanggal sudah terisi (alur offer-first);
 * klik selot terisi → rincian, lalu tautan ke transaksi / perpanjangan /
 * invoice.
 */

function _cal_urutan_lantai(string $lantai): int
{
    static $u = ['LG' => 1, 'GF' => 2, 'UG' => 3, 'FF' => 4, 'SF' => 5, 'P3' => 6, 'P4' => 7, 'P5' => 8];
    $k = strtoupper(trim(explode(' ', $lantai)[0]));
    return $u[$k] ?? 9;
}

/** Urutan alami kode: GF-2 sebelum GF-10. */
function _cal_cmp_kode(string $a, string $b): int
{
    return strnatcasecmp($a, $b);
}

function calendar_page(PDO $pdo): void
{
    $pid    = current_property_id();
    $modul  = in_array(getv('module', 'cl'), ['cl', 'media', 'gudang'], true) ? (string) getv('module', 'cl') : 'cl';
    $bulan  = periode_bulan('month');
    $awal   = new DateTimeImmutable($bulan . '-01');
    $hariN  = (int) $awal->format('t');
    $akhir  = $awal->modify('last day of this month');
    $lantaiF = trim((string) getv('floor', ''));
    $picF   = trim((string) getv('pic', ''));
    $hariIni = date('Y-m-d');
    $scope  = current_sales_scope($pdo, $pid);

    // ── Unit (baris) ────────────────────────────────────────────────────────
    $unit = [];   // kode => [kode, kelompok, label, info]
    if ($modul === 'cl') {
        $st = $pdo->prepare("SELECT code, floor, location_name, unit_type, area_sqm FROM master_cl_units WHERE property_id = ? AND status = 'active'");
        $st->execute([$pid]);
        foreach ($st->fetchAll() as $u) {
            $unit[$u['code']] = ['kode' => $u['code'], 'grup' => (string) $u['floor'], 'label' => (string) $u['location_name'],
                                 'info' => trim(($u['unit_type'] ?? '') . ' · ' . rtrim(rtrim(number_format((float) $u['area_sqm'], 2, ',', ''), '0'), ',') . ' m²', ' ·')];
        }
    } elseif ($modul === 'media') {
        $st = $pdo->prepare("SELECT code, media_type, location, point, size FROM master_media WHERE property_id = ? AND status = 'active'");
        $st->execute([$pid]);
        foreach ($st->fetchAll() as $u) {
            $unit[$u['code']] = ['kode' => $u['code'], 'grup' => mb_convert_case((string) $u['media_type'], MB_CASE_TITLE), 'label' => trim($u['location'] . ' ' . ($u['point'] ?? '')),
                                 'info' => (string) ($u['size'] ?? '')];
        }
    } else {
        $st = $pdo->prepare("SELECT code, location, name, area_sqm FROM master_gudang WHERE property_id = ? AND status = 'active'");
        $st->execute([$pid]);
        foreach ($st->fetchAll() as $u) {
            $unit[$u['code']] = ['kode' => $u['code'], 'grup' => (string) $u['location'], 'label' => (string) $u['name'],
                                 'info' => rtrim(rtrim(number_format((float) $u['area_sqm'], 2, ',', ''), '0'), ',') . ' m²'];
        }
    }

    // ── Transaksi bulan ini (aturan tampil sama dengan daftar transaksi) ─────
    [$where, $params] = transaksi_tampil_kondisi($pdo, 't');
    $where[] = 't.property_id = :pid AND t.module = :modul AND t.start_date <= :akhir AND t.end_date >= :awal';
    $params += [':pid' => $pid, ':modul' => $modul, ':akhir' => $akhir->format('Y-m-d'), ':awal' => $awal->format('Y-m-d')];
    if ($picF !== '') { $where[] = 't.pic_name = :pic'; $params[':pic'] = $picF; }
    $st = $pdo->prepare("SELECT t.id, t.master_code, t.start_date, t.end_date, t.final_amount, t.pic_name, t.created_by, t.invoice_no, t.content_note,
                                c.company_name, c.brand_name, (" . recurring_match_sql('t') . ") AS recurring, " . pembayaran_select_sql('t') . "
                         FROM transactions t LEFT JOIN master_clients c ON c.id = t.client_id
                         WHERE " . implode(' AND ', $where) . " ORDER BY t.start_date, t.end_date, t.id");
    $st->execute($params);
    $trxPer = [];
    foreach ($st->fetchAll() as $t) $trxPer[$t['master_code']][] = $t;

    // ── Warna PIC ───────────────────────────────────────────────────────────
    // Di spreadsheet tiap kotak diberi warna sesuai PIC yang menjualnya, dan
    // itulah yang membuat seorang PIC tahu posisinya tanpa menyaring apa pun.
    require_once __DIR__ . '/prospects.php';
    $warnaPic = prospek_warna_pic($pdo, $pid);

    // ── Nilai yang jatuh di bulan ini (prorata) ─────────────────────────────
    // Sumbernya transaction_allocations — tabel yang sama dengan Dashboard dan
    // Executive Summary, jadi angka di kalender tidak mungkin berbeda dari
    // angka di laporan.
    $alok = $pdo->prepare("SELECT transaction_id, COALESCE(SUM(amount),0) nilai
                             FROM transaction_allocations
                            WHERE property_id = ? AND period_key = ? AND module = ?
                            GROUP BY transaction_id");
    $alok->execute([$pid, $bulan, $modul]);
    $nilaiBulan = [];
    foreach ($alok->fetchAll() as $a) $nilaiBulan[(int) $a['transaction_id']] = (float) $a['nilai'];

    // ── Hari libur bulan ini ────────────────────────────────────────────────
    // Lembar tim menuliskannya di blok "KET : HARI LIBUR" tiap bulan, karena
    // tanggal merah justru hari paling laku untuk pameran — dan unit yang
    // kosong di tanggal merah artinya lain daripada kosong di hari biasa.
    $libur = [];
    if ($pdo->query("SHOW TABLES LIKE 'holidays'")->fetchColumn()) {
        $lb = $pdo->prepare("SELECT holiday_date, name FROM holidays
                              WHERE property_id = ? AND status = 'active'
                                AND holiday_date BETWEEN ? AND ? ORDER BY holiday_date");
        $lb->execute([$pid, $awal->format('Y-m-d'), $akhir->format('Y-m-d')]);
        foreach ($lb->fetchAll() as $l) $libur[(int) substr((string) $l['holiday_date'], 8, 2)] = (string) $l['name'];
    }

    // ── Forecast per unit ───────────────────────────────────────────────────
    // Di lembar tim, kolom FORECAST berisi potensi sebulan tiap unit — 7,5 juta
    // untuk satu fashion booth, 70 juta untuk satu island GF. Angka itu sudah
    // ada di CLARA sebagai projection_monthly di master, dan bisa ditimpa per
    // bulan lewat period_potentials. Rumusnya disamakan dengan get_projection()
    // supaya jumlah Forecast di sini sama dengan proyeksi di Dashboard.
    $tabelMaster = ['cl' => 'master_cl_units', 'media' => 'master_media', 'gudang' => 'master_gudang'][$modul];
    $segmen      = ['cl' => 'exhibition', 'media' => 'media', 'gudang' => 'gudang'][$modul];
    $fc = $pdo->prepare("SELECT m.id, m.code, COALESCE(pp.potential_value, m.projection_monthly) fc,
                                 pp.potential_value AS khusus
                           FROM $tabelMaster m
                           LEFT JOIN period_potentials pp ON pp.slot_id = m.id AND pp.segment = ?
                                AND pp.period_key = ? AND pp.property_id = ?
                          WHERE m.property_id = ? AND m.status = 'active'");
    $fc->execute([$segmen, $bulan, $pid, $pid]);
    $forecast = []; $forecastId = []; $forecastKhusus = [];
    foreach ($fc->fetchAll() as $r) {
        $forecast[(string) $r['code']]       = (float) $r['fc'];
        $forecastId[(string) $r['code']]     = (int) $r['id'];
        $forecastKhusus[(string) $r['code']] = $r['khusus'] !== null;
    }

    // ── Prospek (calon yang belum pasti) ────────────────────────────────────
    $prospek = prospek_bulan($pdo, $pid, $bulan, $modul);
    if ($picF !== '') $prospek = array_values(array_filter($prospek, fn($x) => (string) $x['pic_name'] === $picF));
    $prosPer = []; $prosLepas = [];
    foreach ($prospek as $pr) {
        $k = trim((string) $pr['master_code']);
        if ($k !== '') $prosPer[$k][] = $pr; else $prosLepas[] = $pr;
    }

    // Kode transaksi yang tak ada di master tetap ditampilkan, di kelompok sendiri.
    foreach ($trxPer as $kode => $_) {
        if (!isset($unit[$kode])) $unit[$kode] = ['kode' => $kode, 'grup' => 'Tidak ada di master', 'label' => '(kode belum terdaftar di Master)', 'info' => ''];
    }
    foreach ($prosPer as $kode => $daftar) {
        if (isset($unit[$kode])) continue;
        $unit[$kode] = ['kode' => $kode, 'grup' => 'Tidak ada di master', 'label' => '(kode belum terdaftar di Master)', 'info' => ''];
    }
    if ($lantaiF !== '') $unit = array_filter($unit, fn($u) => $u['grup'] === $lantaiF);
    $grupDaftar = [];
    foreach ($unit as $u) $grupDaftar[$u['grup']] = true;
    uasort($unit, function ($a, $b) {
        $g = [$a['grup'] === 'Tidak ada di master' ? 99 : _cal_urutan_lantai($a['grup']), $a['grup']] <=> [$b['grup'] === 'Tidak ada di master' ? 99 : _cal_urutan_lantai($b['grup']), $b['grup']];
        if ($g !== 0) return $g;
        // Diurut menurut NAMA unit — itu yang tertulis di kolomnya. Mengurut
        // menurut kode membuat urutannya terlihat acak karena kodenya tidak
        // ditampilkan. Perbandingan alami supaya 2 datang sebelum 10.
        $n = _cal_cmp_kode($a['label'] !== '' ? $a['label'] : $a['kode'], $b['label'] !== '' ? $b['label'] : $b['kode']);
        return $n !== 0 ? $n : _cal_cmp_kode($a['kode'], $b['kode']);
    });
    $semuaGrup = array_keys($grupDaftar);
    usort($semuaGrup, fn($a, $b) => [_cal_urutan_lantai($a), $a] <=> [_cal_urutan_lantai($b), $b]);

    // ── Susun selot per unit: jalur (lane) supaya yang bersamaan tidak saling menimpa
    $bisaBayar = can('view_payments') || can('manage_payments');
    $baris = []; $terisiHari = 0; $kapasitas = 0; $unitTerisiHariIni = 0;
    foreach ($unit as $kode => $u) {
        $lanes = [];   // tiap jalur: daftar selot
        $hariPakai = array_fill(1, $hariN, false);
        // Ringkasan per unit untuk kolom di tepi kanan — bentuk yang sudah
        // biasa mereka baca di spreadsheet (Recurring / Exhibition di kanan
        // kalender, pada baris yang sama).
        // Angka blok kanan dikumpulkan PER JALUR, bukan per unit: satu baris di
        // layar harus memperlihatkan isi baris itu sendiri. Kalau dijumlahkan
        // per unit, pemesanan di baris kedua muncul sebagai tambahan di baris
        // pertama — dan barisnya jadi tidak bisa dibaca apa adanya.
        $kosongJalur = ['rec' => 0.0, 'exh' => 0.0, 'pros' => 0.0, 'pic' => [], 'picPros' => []];
        $jalur = [];
        foreach ($trxPer[$kode] ?? [] as $t) {
            $a = max($t['start_date'], $awal->format('Y-m-d'));
            $b = min($t['end_date'], $akhir->format('Y-m-d'));
            $da = (int) substr($a, 8, 2); $db = (int) substr($b, 8, 2);
            $milik = !$scope || ($scope['pic'] !== '' && (string) $t['pic_name'] === $scope['pic']) || (string) $t['created_by'] === $scope['uname'];
            $brand = trim((string) ($t['brand_name'] ?: $t['company_name'] ?: '-'));
            $selot = ['id' => (int) $t['id'], 'da' => $da, 'db' => $db, 'brand' => $brand, 'pt' => (string) $t['company_name'],
                      'mulai' => $t['start_date'], 'selesai' => $t['end_date'], 'rec' => (int) $t['recurring'], 'milik' => $milik,
                      'potongKiri' => $t['start_date'] < $awal->format('Y-m-d'), 'potongKanan' => $t['end_date'] > $akhir->format('Y-m-d')];
            // Warna kotak mengikuti PIC-nya (bukan jenis transaksinya) — jenis
            // tetap terbaca lewat garis tebal di tepi kiri untuk Recurring.
            $selot['warna'] = $warnaPic[(string) $t['pic_name']] ?? '#E2E8F0';
            $selot['picSemua'] = (string) $t['pic_name'];
            // Nilai yang jatuh di bulan ini (prorata, sumber sama dengan laporan).
            $rpBulan = $nilaiBulan[(int) $t['id']] ?? 0.0;
            // Nilai, PIC, invoice, dan status bayar dibaca SEMUA orang yang boleh
            // membuka kalender — begitulah lembar kerja tim selama ini, dan
            // tanpa itu seorang PIC tidak bisa menakar posisinya terhadap rekan.
            // $milik tetap dipakai, tapi hanya untuk menentukan tombol tindakan.
            $selot['bulanRp'] = $rpBulan;
            $selot += ['pic' => (string) $t['pic_name'], 'nilai' => (float) $t['final_amount'],
                       'inv' => (string) $t['invoice_no'], 'ket' => trim((string) ($t['content_note'] ?? ''))];
            if ($bisaBayar) $selot += ['bayar' => (string) $t['bayar_status'], 'lewat' => (int) $t['bayar_lewat'],
                                       'sisa' => (float) $t['tagihan'] - (float) $t['terbayar']];
            for ($d = $da; $d <= $db; $d++) $hariPakai[$d] = true;
            $iJalur = null;
            foreach ($lanes as $i => $lane) {
                if ($lane && end($lane)['db'] < $da) { $lanes[$i][] = $selot; $iJalur = $i; break; }
            }
            if ($iJalur === null) { $lanes[] = [$selot]; $iJalur = count($lanes) - 1; }
            if (!isset($jalur[$iJalur])) $jalur[$iJalur] = $kosongJalur;
            if ((int) $t['recurring']) $jalur[$iJalur]['rec'] += $rpBulan; else $jalur[$iJalur]['exh'] += $rpBulan;
            $jalur[$iJalur]['pic'][(string) $t['pic_name']] = ($jalur[$iJalur]['pic'][(string) $t['pic_name']] ?? 0) + $rpBulan;
        }
        // ── Prospek: jalurnya sendiri supaya tidak pernah tertukar dengan yang
        //    sudah pasti. Tampil samar dan bergaris putus-putus di layar.
        foreach ($prosPer[$kode] ?? [] as $pr) {
            $a = max((string) $pr['start_date'], $awal->format('Y-m-d'));
            $b = min((string) $pr['end_date'], $akhir->format('Y-m-d'));
            $da = (int) substr($a, 8, 2); $db = (int) substr($b, 8, 2);
            if ($db < $da) continue;
            $selotPr = [
                'pros' => true, 'id' => (int) $pr['id'], 'da' => $da, 'db' => $db,
                'brand' => trim((string) $pr['client_name']), 'pt' => '',
                'mulai' => (string) $pr['start_date'], 'selesai' => (string) $pr['end_date'],
                'rec' => 0, 'milik' => true, 'potongKiri' => (string) $pr['start_date'] < $awal->format('Y-m-d'),
                'potongKanan' => (string) $pr['end_date'] > $akhir->format('Y-m-d'),
                'pic' => (string) $pr['pic_name'], 'nilai' => (float) $pr['est_amount'],
                'bulanRp' => (float) $pr['est_amount'], 'yakin' => (string) $pr['confidence'],
                'ket' => trim((string) ($pr['note'] ?? '')),
                'warna' => $warnaPic[(string) $pr['pic_name']] ?? '#FDE68A', 'inv' => '',
            ];
            // Menumpang jalur yang sudah ada selama tanggalnya tidak bertabrakan,
            // sama seperti transaksi — kalau tidak, satu prospek saja sudah
            // menambah satu baris kosong dan barisan unitnya jadi sulit diikuti.
            $iJalur = null;
            foreach ($lanes as $i => $lane) {
                if ($lane && end($lane)['db'] < $da) { $lanes[$i][] = $selotPr; $iJalur = $i; break; }
            }
            if ($iJalur === null) { $lanes[] = [$selotPr]; $iJalur = count($lanes) - 1; }
            if (!isset($jalur[$iJalur])) $jalur[$iJalur] = $kosongJalur;
            $jalur[$iJalur]['pros'] += (float) $pr['est_amount'];
            // Kalau calonnya jadi, uangnya masuk ke kolom PIC ini juga — jadi
            // angkanya ikut ditampilkan di sana, bertanda kuning, di baris ini.
            $jalur[$iJalur]['picPros'][(string) $pr['pic_name']] = ($jalur[$iJalur]['picPros'][(string) $pr['pic_name']] ?? 0) + (float) $pr['est_amount'];
        }

        $terisi = count(array_filter($hariPakai));
        $terisiHari += $terisi; $kapasitas += $hariN;
        if ($bulan === date('Y-m') && $hariPakai[(int) date('j')]) $unitTerisiHariIni++;
        $uRec = $uExh = $uPros = 0.0; $uPic = []; $uPicPros = [];
        foreach ($jalur as $j) {
            $uRec += $j['rec']; $uExh += $j['exh']; $uPros += $j['pros'];
            foreach ($j['pic'] as $nm => $v) $uPic[$nm] = ($uPic[$nm] ?? 0) + $v;
            foreach ($j['picPros'] as $nm => $v) $uPicPros[$nm] = ($uPicPros[$nm] ?? 0) + $v;
        }
        $baris[$kode] = $u + ['lanes' => $lanes ?: [[]], 'terisi' => $terisi, 'jumlahSelot' => array_sum(array_map('count', $lanes)),
                              'rpRec' => $uRec, 'rpExh' => $uExh, 'rpPros' => $uPros,
                              'rpFc' => $forecast[$kode] ?? 0.0, 'rpPic' => $uPic, 'rpPicPros' => $uPicPros,
                              'jalur' => $jalur, 'jalurKosong' => $kosongJalur,
                              'fcId' => $forecastId[$kode] ?? 0, 'fcKhusus' => !empty($forecastKhusus[$kode])];
    }

    // ── Jumlah per lantai & jumlah seluruhnya ───────────────────────────────
    // Lembar tim menutup tiap blok lantai dengan baris "TOTAL LOWER GROUND".
    // Tanpa itu, angka per unit tidak pernah menjadi kesimpulan.
    $kosong  = ['fc' => 0.0, 'rec' => 0.0, 'exh' => 0.0, 'act' => 0.0, 'pros' => 0.0, 'pic' => [], 'picPros' => []];
    $tambah = function (array $t, array $u): array {
        $t['fc']   += (float) $u['rpFc'];
        $t['rec']  += (float) $u['rpRec'];
        $t['exh']  += (float) $u['rpExh'];
        $t['act']  += (float) $u['rpRec'] + (float) $u['rpExh'];
        $t['pros'] += (float) $u['rpPros'];
        foreach ($u['rpPic'] as $nm => $v) $t['pic'][$nm] = ($t['pic'][$nm] ?? 0) + (float) $v;
        foreach ($u['rpPicPros'] as $nm => $v) $t['picPros'][$nm] = ($t['picPros'][$nm] ?? 0) + (float) $v;
        return $t;
    };
    // Berapa baris yang dipakai tiap lantai — dipakai sebagai rowspan kolom
    // lantai di kiri, meniru sel gabungan "GROUND FLOOR" di lembar mereka.
    $barisGrup = [];
    foreach ($baris as $u) $barisGrup[$u['grup']] = ($barisGrup[$u['grup']] ?? 0) + max(1, count($u['lanes']));

    $totGrup = []; $totAll = $kosong;
    foreach ($baris as $u) {
        $g = $u['grup'];
        $totGrup[$g] = $tambah($totGrup[$g] ?? $kosong, $u);
        $totAll      = $tambah($totAll, $u);
    }

    $persen = $kapasitas ? round($terisiHari / $kapasitas * 100) : 0;

    $picList = $pdo->prepare("SELECT name FROM master_pic WHERE status='active' AND property_id = ? ORDER BY name");
    $picList->execute([$pid]);
    $picList = $picList->fetchAll(PDO::FETCH_COLUMN);
    // Kolom PIC mengikuti lembar mereka (MUSI / SITA / ZUL / ICHA): hanya nama
    // yang benar-benar ada angkanya bulan ini, supaya tabelnya tidak melebar
    // karena nama yang tidak pernah terpakai.
    $picKolom = array_values(array_filter(array_keys(array_filter($totAll['pic'], fn($v) => (float) $v > 0)), fn($n) => $n !== ''));
    sort($picKolom);
    // Lebar tabel ada batasnya; di layar sempit kolom PIC bisa disembunyikan.
    $picOn = getv('picol', '1') !== '0';
    $bulanSebelum = $awal->modify('-1 month')->format('Y-m');
    $bulanSesudah = $awal->modify('+1 month')->format('Y-m');
    $hari3 = ['Mg', 'Sn', 'Sl', 'Rb', 'Km', 'Jm', 'Sb'];
    $bisaOffer = can('manage_offers');
    $modLabel = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];
    $qsBase = fn(array $ubah) => '?' . http_build_query(array_filter(array_merge(['r' => 'calendar', 'month' => $bulan, 'module' => $modul, 'floor' => $lantaiF, 'pic' => $picF], $ubah), fn($v) => $v !== ''));
    $stBayar = pembayaran_status_daftar();
    // Angka ringkas untuk di dalam kotak kalender: 3,5jt / 45jt / 1,2M.
    // Kotaknya selebar beberapa hari saja, jadi "Rp 3.500.000" tidak akan muat.
    $rpKecil = function (float $v): string {
        if ($v < 1000) return '';
        $potong = fn($x, $sat) => rtrim(rtrim(number_format($x, 1, ',', '.'), '0'), ',') . $sat;
        if ($v >= 1000000000) return $potong($v / 1000000000, 'M');
        if ($v >= 1000000)    return $potong($v / 1000000, 'jt');
        return round($v / 1000) . 'rb';
    };

    // ── Rekap & target bulan ini ────────────────────────────────────────────
    // Di spreadsheet, kalender dan rekapnya ada di baris yang sama: tim melihat
    // jadwal sambil langsung melihat angkanya. Di CLARA keduanya terpisah, dan
    // pertanyaan yang paling sering muncul di lapangan — "unit ini masih
    // kosong, lalu kita masih kurang berapa?" — tidak terjawab satu layar pun.
    //
    // Angka di bawah memakai sumber yang SAMA dengan Dashboard & Executive
    // Summary (transaction_allocations, targets_monthly, master_pic.target_share),
    // jadi tidak mungkin berbeda dengan laporan manajemen.
    $tgt = $pdo->prepare('SELECT COALESCE(target_amount,0) FROM targets_monthly WHERE period_key = ? AND property_id = ?');
    $tgt->execute([$bulan, $pid]);
    $targetTim = (float) ($tgt->fetchColumn() ?: 0);

    $cap = $pdo->prepare("SELECT COALESCE(a.pic_name,'') pic, a.module, COALESCE(SUM(a.amount),0) nilai
                            FROM transaction_allocations a
                           WHERE a.property_id = ? AND a.period_key = ?
                           GROUP BY COALESCE(a.pic_name,''), a.module");
    $cap->execute([$pid, $bulan]);
    $capPic = []; $capModul = ['cl' => 0.0, 'media' => 0.0, 'gudang' => 0.0]; $capaianTim = 0.0;
    foreach ($cap->fetchAll() as $r) {
        $capPic[(string) $r['pic']] = ($capPic[(string) $r['pic']] ?? 0) + (float) $r['nilai'];
        if (isset($capModul[(string) $r['module']])) $capModul[(string) $r['module']] += (float) $r['nilai'];
        $capaianTim += (float) $r['nilai'];
    }

    $shr = $pdo->prepare("SELECT name, COALESCE(target_share,0) share, color FROM master_pic
                           WHERE property_id = ? AND status = 'active' ORDER BY name");
    $shr->execute([$pid]);
    $picTarget = $shr->fetchAll();

    // Prospek dihitung untuk SELURUH modul — pertanyaan "target tercapai atau
    // tidak" memang pertanyaan satu properti, bukan satu modul.
    $prosTim = 0.0; $prosPic = [];
    foreach (prospek_bulan($pdo, $pid, $bulan, '') as $pr) {
        $prosTim += (float) $pr['est_amount'];
        $prosPic[(string) $pr['pic_name']] = ($prosPic[(string) $pr['pic_name']] ?? 0) + (float) $pr['est_amount'];
    }
    // Angka per orang hanya untuk yang memang boleh membaca laporan PIC
    // (sales sudah punya izin ini di halaman Laporan/Performa PIC).
    $bolehLihatPic = can('view_pic_report');
    $bisaProspek   = can('manage_prospects');
    // Forecast boleh dibetulkan langsung di kalender, seperti mengetik di sel
    // spreadsheet. Angka dasarnya datang dari Master (Proyeksi Bulanan);
    // yang diketik di sini hanya berlaku untuk bulan yang sedang dibuka.
    $bisaUbahFc = can('manage_master');

    // Proyeksi: potensi penuh seluruh unit bulan ini, memakai rumus yang sama
    // dengan Dashboard (get_projection). Di lembar tim angkanya berdiri di
    // samping target, sebagai pembanding "seandainya semua unit terisi".
    $proyeksiTim = array_sum(get_projection($pdo, $bulan, $pid));

    // "Eks WBL": pencapaian tanpa menghitung pemasukan WBL Unit — unit yang
    // dikelola sendiri, bukan hasil jualan PIC. Lembar tim memisahkannya
    // karena yang dinilai adalah kerja timnya.
    $capWbl = 0.0;
    foreach ($capPic as $nm => $v) if (stripos((string) $nm, 'WBL') !== false) $capWbl += (float) $v;
    $capEksWbl = $capaianTim - $capWbl;

    layout('Kalender Pameran', function () use ($baris, $bulan, $awal, $hariN, $modul, $lantaiF, $picF, $hariIni, $persen, $terisiHari, $kapasitas, $unitTerisiHariIni, $picList,
                                                $bulanSebelum, $bulanSesudah, $hari3, $bisaOffer, $modLabel, $qsBase, $semuaGrup, $bisaBayar, $stBayar, $pid,
                                                $warnaPic, $targetTim, $capaianTim, $capPic, $capModul, $picTarget, $prosTim, $prosPic, $bolehLihatPic, $bisaProspek, $prosLepas, $rpKecil, $proyeksiTim, $capWbl, $capEksWbl, $libur, $bisaUbahFc, $segmen,
                                                $totGrup, $totAll, $picKolom, $picOn) {
        $nilaiHariIni = $bulan === date('Y-m');
        ?>
        <style>
            .cal-wrap{overflow:auto;max-height:72vh;border:1px solid var(--line);border-radius:12px;background:#fff}
            table.cal{border-collapse:separate;border-spacing:0;width:max-content;min-width:100%;font-size:11px}
            table.cal th,table.cal td{padding:0;border-right:1px solid #EEF1F5;border-bottom:1px solid #EEF1F5;height:27px;text-align:center}
            table.cal thead th{position:sticky;top:0;z-index:3;background:#F8FAFC;font-weight:700;color:#475467;height:34px;line-height:1.15}
            table.cal thead th small{display:block;font-weight:500;color:#98A2B3;font-size:9px}
            table.cal .hd-we{background:#FFF7ED}
            table.cal .hd-libur{background:#FEE2E2;color:#B91C1C}
            table.cal td.e.libur{background:#FEF2F2}
            table.cal .hd-now{background:#CCFBF1;color:#0F766E}
            /* Kolom lantai: satu sel memanjang ke bawah, seperti sel gabungan
               "GROUND FLOOR" di lembar mereka. Tulisannya ditegakkan supaya
               tidak memakan lebar yang dibutuhkan nama unit. */
            table.cal th.lt{position:sticky;left:0;z-index:3;background:#DCE9F5;color:#0F2A3F;
                width:28px;min-width:28px;max-width:28px;padding:0;border-right:1px solid #BBD0E6;
                font-size:10px;font-weight:800;letter-spacing:.06em;text-align:center}
            table.cal th.lt{border-bottom:0}
            table.cal th.lt.lt-awal{border-top:1px solid #BBD0E6}
            table.cal th.lt span{writing-mode:vertical-rl;transform:rotate(180deg);display:inline-block;white-space:nowrap;padding:10px 0}
            table.cal thead th.lt{z-index:6;background:#F8FAFC}
            table.cal th.u{position:sticky;left:28px;z-index:2;background:#fff;text-align:left;min-width:232px;max-width:232px;padding:2px 10px;border-right:2px solid #E4E9F0;font-weight:600;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
            table.cal tr.tot th.u,table.cal tr.tot-all th.u{left:0}
            table.cal thead th.u{z-index:4;background:#F8FAFC}
            table.cal th.u small{display:block;font-weight:400;color:#98A2B3;font-size:10px;overflow:hidden;text-overflow:ellipsis}
            table.cal tr.lane2 th.u{color:#98A2B3;font-weight:400}
            table.cal td.e{min-width:30px;width:30px;cursor:pointer}
            table.cal td.e.we{background:#FFFAF3}
            table.cal td.e.now{background:#F0FDFA}
            table.cal td.e:hover{background:#CCFBF1}
            table.cal td.e.pilih{background:#5EEAD4}
            /* Kotaknya dibuat hampir memenuhi selnya, dengan garis pemisah setipis
               mungkin — di spreadsheet warnanya menyambung, dan garis putih tebal
               membuat warna yang sama terbaca lebih pucat di layar.
               overflow:visible supaya nama merek bisa meluber ke hari kosong di
               sebelahnya, persis perilaku teks di sel spreadsheet. */
            table.cal td.s{padding:0 3px;overflow:visible;white-space:nowrap;cursor:pointer;font-weight:600;
                font-size:10px;letter-spacing:0;max-width:0;border-radius:2px;border:1px solid #fff;position:relative}
            table.cal td.s.r{background:#DBEAFE;color:#1E40AF}
            table.cal td.s.x{background:#FDE68A;color:#78350F}
            table.cal td.s.o{background:#E5E7EB;color:#374151;font-style:italic}
            table.cal td.s.dim{opacity:.18}
            table.cal td.s.hit{outline:2px solid #DB2777}
            table.cal td.s .pay{position:absolute;right:2px;top:2px;width:7px;height:7px;border-radius:50%}
            table.cal td.s.kiri{border-top-left-radius:0;border-bottom-left-radius:0}
            table.cal td.s.kanan{border-top-right-radius:0;border-bottom-right-radius:0}
            .cal-pop{position:fixed;z-index:50;background:#fff;border:1px solid #CBD5E1;border-radius:12px;box-shadow:0 12px 32px rgba(15,23,42,.18);padding:14px 16px;width:300px;font-size:13px;display:none}
            .cal-pop h4{margin:0 0 4px;font-size:15px}
            .cal-pop .k{color:var(--muted);font-size:11px}
            .cal-bar{position:fixed;left:50%;bottom:18px;transform:translateX(-50%);background:#0F2A3F;color:#fff;padding:10px 16px;border-radius:99px;display:none;gap:12px;align-items:center;z-index:50;box-shadow:0 8px 24px rgba(0,0,0,.25);font-size:13px}
            @media (max-width:760px){
                .cal-filter{display:grid!important;grid-template-columns:1fr 1fr;gap:8px!important}
                .cal-filter>*{width:auto!important;min-width:0}
                .cal-filter>div:first-of-type,.cal-filter>button{grid-column:1/-1}
                .cal-filter select,.cal-filter input{width:100%!important}
                .cal-wrap{max-height:68vh}
                table.cal th.u{min-width:74px;max-width:74px;padding:2px 5px;font-size:11px}
                table.cal th.u small{display:none}
                table.cal td.e{min-width:28px;width:28px}
            }
            /* ── Panel rekap & target ─────────────────────────────────── */
            .cal-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px 22px}
            .cal-kpi b{font-size:19px;display:block;line-height:1.25}
            .cal-kpi .muted{font-size:12px}
            .cal-bar2{display:flex;height:9px;border-radius:99px;overflow:hidden;background:#EEF1F5;margin-top:10px}
            .cal-bar2 i{display:block;height:100%}
            table.cal-pic{width:100%;margin-top:12px;font-size:12.5px;border-collapse:collapse}
            table.cal-pic th{text-align:left;font-size:11px;color:#98A2B3;font-weight:700;padding:4px 8px;border-bottom:1px solid #EEF1F5}
            table.cal-pic td{padding:5px 8px;border-bottom:1px solid #F5F7FA;white-space:nowrap}
            table.cal-pic .dot{display:inline-block;width:11px;height:11px;border-radius:3px;margin-right:6px;vertical-align:-1px}
            table.cal-pic .mini{display:inline-block;width:62px;height:7px;border-radius:99px;background:#EEF1F5;overflow:hidden;vertical-align:0;margin-right:5px}
            table.cal-pic .mini i{display:block;height:100%}
            /* ── Blok rekap di kanan kalender ─────────────────────────────
               Susunannya mengikuti lembar tim: FORECAST, RECURRING, EXHIBITION,
               ACTUAL, lalu satu kolom tiap PIC. Sengaja TIDAK menempel di tepi
               (sticky) — supaya geserannya terasa seperti Excel dan tidak
               memakan lebar layar terus-menerus. */
            /* Lebar kolomnya mengikuti isi — tidak dipatok, tidak dipotong, dan
               judulnya tidak dipaksa membungkus. Angka uang ditulis penuh
               (Rp 7.500.000), bukan dibulatkan, karena angka inilah yang
               dicocokkan tim dengan berkas mereka. */
            table.cal th.rk,table.cal td.rk{text-align:right;padding:2px 10px;white-space:nowrap;
                font-variant-numeric:tabular-nums;font-size:10.5px}
            table.cal th.rk{font-size:9.5px;letter-spacing:.03em;padding:4px 10px}
            table.cal td.rk.pros-ada{background:#FFFBEB}
            /* Sel Forecast bisa diketik langsung, seperti sel spreadsheet. */
            table.cal td.fc-sel{padding:0}
            table.cal td.fc-sel input.fc-in{width:100%;border:0;background:transparent;text-align:right;
                padding:3px 10px;font:inherit;font-size:10.5px;font-variant-numeric:tabular-nums;color:inherit;border-radius:0}
            table.cal td.fc-sel input.fc-in:focus{outline:2px solid #0D9488;outline-offset:-2px;background:#F0FDFA}
            table.cal td.fc-sel.fc-khusus{background:#F0FDFA}
            table.cal td.fc-sel.simpan{background:#DCFCE7}
            table.cal td.rk .pros-rp{display:block;color:#B45309;font-weight:700;font-size:10px;margin-top:1px}
            table.cal tr.tot td.rk.pros-ada{background:#FEF3C7}
            /* Kolom tiap PIC diberi warna orangnya sendiri (warnanya ditulis di
               halaman, karena datang dari Master PIC). Dibuat sangat tipis
               supaya angka tetap terbaca, lalu menebal di baris total. */
            table.cal td.rk-pic,table.cal th.rk-pic{position:relative}
            table.cal td.rk-pic::before,table.cal th.rk-pic::before{content:'';position:absolute;inset:0;
                background:var(--wpic);opacity:.20;pointer-events:none}
            table.cal th.rk-pic::before{opacity:.45}
            table.cal td.rk-pic>*,table.cal th.rk-pic>*{position:relative}
            table.cal tr.tot td.rk-pic::before{opacity:.42}
            table.cal tr.tot-all td.rk-pic::before{opacity:.30}
            /* Baris total JANGAN memudar saat disentuh kursor — aturan hover
               umum mengganti latarnya, dan tulisan putihnya jadi hilang. */
            table.cal tbody tr.tot:hover td,table.cal tbody tr.tot:hover th{background:#F1F5F9}
            table.cal tbody tr.tot-all:hover td,table.cal tbody tr.tot-all:hover th{background:#0F2A3F;color:#fff}
            table.cal tbody tr.tot{cursor:pointer}
            table.cal .rk-awal{border-left:2px solid #E4E9F0}
            table.cal td.rk.nol{color:#CBD5E1}
            table.cal tr.tot{background:#F1F5F9;font-weight:800}
            table.cal tr.tot th.u{background:#F1F5F9;color:#0F2A3F;text-transform:uppercase;font-size:10.5px;letter-spacing:.03em}
            table.cal tr.tot td.rk{font-size:10.5px;color:#0F2A3F}
            table.cal tr.tot-all{background:#0F2A3F;color:#fff}
            table.cal tr.tot-all th.u{background:#0F2A3F;color:#fff}
            table.cal tr.tot-all td.rk{color:#fff}
            /* ── Nilai di dalam kotak ─────────────────────────────────── */
            table.cal td.s .s-isi{display:flex;align-items:center;gap:8px;width:100%;overflow:hidden}
            table.cal td.s .s-nama{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:center}
            /* Nama yang tidak muat meluber ke kanan selama harinya masih kosong. */
            table.cal td.s .s-isi.luber{position:absolute;left:4px;top:0;bottom:0;z-index:4;
                justify-content:flex-start;overflow:hidden;pointer-events:none}
            table.cal td.s .s-isi.luber .s-nama{flex:0 1 auto;text-align:left}
            table.cal td.s .rp{flex:0 0 auto;font-weight:700;opacity:.72;font-size:10px;
                text-transform:none;font-style:normal;letter-spacing:0}
            table.cal td.s.rec{box-shadow:inset 4px 0 0 #1D4ED8}
            /* Prospek memakai warna PIC-nya seperti pemesanan biasa — yang
               membedakan hanya segitiga kuning di pojok kanan atas dan tulisan
               miring. Latar kuning penuh dulu membuat kolom PIC-nya tak terbaca. */
            table.cal td.s.pros{font-style:italic;border-style:dashed;border-color:#F3C94B;border-width:1.5px}
            table.cal td.s.pros::after{content:'';position:absolute;right:0;top:0;width:0;height:0;
                border-style:solid;border-width:0 11px 11px 0;border-color:transparent #EAB308 transparent transparent}
            @media (max-width:900px){table.cal th.rk,table.cal td.rk{display:none}}
            .cal-leg{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:#475467;margin:10px 0}
            .cal-leg i{display:inline-block;width:14px;height:10px;border-radius:3px;margin-right:5px;vertical-align:-1px}
        </style>

        <form method="get" class="toolbar cal-filter" style="gap:10px">
            <input type="hidden" name="r" value="calendar">
            <div style="display:flex;gap:6px;align-items:flex-end">
                <a class="btn light" href="<?= h($qsBase(['month' => $bulanSebelum])) ?>">←</a>
                <div><label class="muted">Bulan</label><input type="month" name="month" value="<?= h($bulan) ?>" style="width:160px"></div>
                <a class="btn light" href="<?= h($qsBase(['month' => $bulanSesudah])) ?>">→</a>
                <?php if (!$nilaiHariIni): ?><a class="btn light" href="<?= h($qsBase(['month' => date('Y-m')])) ?>">Bulan ini</a><?php endif; ?>
            </div>
            <div><label class="muted">Modul</label>
                <select name="module" onchange="this.form.floor.value='';this.form.submit()">
                    <?php foreach ($modLabel as $k => $v): ?><option value="<?= h($k) ?>" <?= $modul === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
            <div><label class="muted"><?= $modul === 'cl' ? 'Lantai' : ($modul === 'media' ? 'Jenis' : 'Lokasi') ?></label>
                <select name="floor" onchange="this.form.submit()"><option value="">Semua</option>
                    <?php foreach ($semuaGrup as $g): ?><option <?= $lantaiF === $g ? 'selected' : '' ?>><?= h($g) ?></option><?php endforeach; ?></select></div>
            <div><label class="muted">PIC</label>
                <select name="pic" onchange="this.form.submit()"><option value="">Semua</option>
                    <?php foreach ($picList as $p): ?><option <?= $picF === $p ? 'selected' : '' ?>><?= h($p) ?></option><?php endforeach; ?></select></div>
            <div><label class="muted">Cari merek</label><input type="text" id="cal-cari" placeholder="mis. Matahari" style="width:170px" autocomplete="off"></div>
            <button type="submit">Tampilkan</button>
            <?php if ($bisaProspek): ?><a class="btn light" style="background:#FFFBEB;color:#92400E;border-color:#FDE68A"
                href="?r=prospect_form&month=<?= h($bulan) ?>">+ Prospek</a><?php endif; ?>
            <?php if ($bolehLihatPic && $picKolom): ?>
                <a class="btn light" href="<?= h($qsBase(['picol' => $picOn ? '0' : '1'])) ?>"
                   title="Kolom nilai per PIC di blok kanan"><?= $picOn ? 'Sembunyikan kolom PIC' : 'Tampilkan kolom PIC' ?></a>
            <?php endif; ?>
            <a class="btn light" href="?r=prospects&month=<?= h($bulan) ?>">Daftar Prospek</a>
        </form>

        <?php
        // ── Rekap & target ──────────────────────────────────────────────────
        // Letaknya di ATAS kalender karena inilah angka yang pertama dicari tim
        // setiap kali membuka berkasnya: sebelum melihat jadwal, sebelum apa pun.
        $kurang = $targetTim - $capaianTim;
        $pst    = $targetTim > 0 ? round($capaianTim / $targetTim * 100) : 0;
        $pstPros = $targetTim > 0 ? round(($capaianTim + $prosTim) / $targetTim * 100) : 0;
        $rp = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
        ?>
        <div class="panel cal-rekap" style="padding:14px 18px;margin-bottom:12px">
            <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:10px">
                <strong style="font-size:15px">Pencapaian <?= h(period_label($bulan)) ?></strong>
                <span class="muted" style="font-size:12px">Seluruh modul (Exhibition + Media + Gudang) &middot; angka yang sama dengan Dashboard &amp; Executive Summary</span>
            </div>

            <div class="cal-kpi">
                <div><div class="muted">Target tim</div><b><?= h($rp($targetTim)) ?></b>
                    <?php if ($targetTim <= 0): ?><div class="muted" style="font-size:11px">belum diisi di Master Data → Target</div><?php endif; ?></div>
                <div><div class="muted">Sudah masuk</div><b style="color:#047857"><?= h($rp($capaianTim)) ?></b>
                    <div class="muted" style="font-size:11px"><?= (int) $pst ?>% dari target</div></div>
                <div><div class="muted"><?= $kurang > 0 ? 'Masih kurang' : 'Lebih dari target' ?></div>
                    <b style="color:<?= $kurang > 0 ? '#B91C1C' : '#047857' ?>"><?= h($rp(abs($kurang))) ?></b></div>
                <div><div class="muted">Proyeksi <span title="Potensi penuh seluruh unit bulan ini, seandainya semuanya terisi">(potensi penuh)</span></div>
                    <b style="color:#475569"><?= h($rp($proyeksiTim)) ?></b>
                    <div class="muted" style="font-size:11px"><?= $proyeksiTim > 0 ? (int) round($capaianTim / $proyeksiTim * 100) : 0 ?>% terpakai</div></div>
                <div style="background:#FFFBEB;border-radius:8px;padding:6px 10px;margin:-6px -4px">
                    <div class="muted">Prospek <span title="Calon yang belum pasti — belum ada surat penawaran">(belum pasti)</span></div>
                    <b style="color:#B45309"><?= h($rp($prosTim)) ?></b>
                    <div class="muted" style="font-size:11px">kalau semua jadi: <b><?= (int) $pstPros ?>%</b></div></div>
            </div>

            <?php /* Batang: hijau = sudah masuk, kuning bergaris = prospek. Dua warna
                     yang sama persis dengan arti warnanya di kalender di bawah. */ ?>
            <?php if ($targetTim > 0): ?>
            <div class="cal-bar2" title="Hijau = sudah masuk. Kuning = prospek yang belum pasti.">
                <i style="width:<?= min(100, (int) $pst) ?>%;background:#10B981"></i>
                <i style="width:<?= max(0, min(100 - min(100, (int) $pst), (int) ($pstPros - $pst))) ?>%;background:repeating-linear-gradient(45deg,#FDE68A,#FDE68A 5px,#FCD34D 5px,#FCD34D 10px)"></i>
            </div>
            <?php endif; ?>

            <div class="muted" style="font-size:12px;margin-top:8px">
                Exhibition <b><?= h($rp($capModul['cl'])) ?></b> &middot;
                Media <b><?= h($rp($capModul['media'])) ?></b> &middot;
                Gudang <b><?= h($rp($capModul['gudang'])) ?></b>
                <?php if ($capWbl > 0): $kurangEks = $targetTim - $capEksWbl; ?>
                    <br>Tanpa WBL Unit: <b><?= h($rp($capEksWbl)) ?></b>
                    <span style="color:<?= $kurangEks > 0 ? '#B91C1C' : '#047857' ?>">(<?= $kurangEks > 0 ? 'kurang' : 'lebih' ?> <?= h($rp(abs($kurangEks))) ?>)</span>
                    <span title="Pemasukan dari unit yang dikelola sendiri, bukan hasil jualan PIC">&mdash; WBL Unit <?= h($rp($capWbl)) ?></span>
                <?php endif; ?>
            </div>

            <?php if ($bolehLihatPic): ?>
            <table class="cal-pic">
                <thead><tr><th>PIC</th><th style="text-align:right">Target</th><th style="text-align:right">Sudah masuk</th>
                    <th style="width:110px">Capaian</th>
                    <th style="text-align:right" title="Sudah masuk dikurangi target — angka inilah yang dipakai tim, bukan persennya">Selisih</th>
                    <th style="text-align:right">Prospek</th></tr></thead>
                <tbody>
                <?php
                $dipakai = [];
                foreach ($picTarget as $pt):
                    $nm = (string) $pt['name'];
                    $dipakai[$nm] = true;
                    $tP = $targetTim * (float) $pt['share'];
                    $cP = (float) ($capPic[$nm] ?? 0);
                    $pP = (float) ($prosPic[$nm] ?? 0);
                    if ($tP <= 0 && $cP <= 0 && $pP <= 0) continue;   // yang benar-benar kosong tidak perlu baris
                    $persenP = $tP > 0 ? round($cP / $tP * 100) : 0; ?>
                    <tr<?= $picF === $nm ? ' style="background:#F0FDFA"' : '' ?>>
                        <?php /* Nama PIC membuka rincian apa saja yang ia jual bulan ini;
                                 lingkaran warnanya tetap untuk menyaring kalender. */ ?>
                        <td><a href="<?= h($qsBase(['pic' => $picF === $nm ? '' : $nm])) ?>" title="Saring kalender ke <?= h($nm) ?>"><span class="dot" style="background:<?= h((string) ($pt['color'] ?: '#CBD5E1')) ?>"></span></a>
                            <a href="?r=calendar_detail&month=<?= h($bulan) ?>&pic=<?= h(urlencode($nm)) ?>"
                               title="Lihat apa saja yang dijual <?= h($nm) ?> bulan ini"
                               style="color:#0F766E;font-weight:600;border-bottom:1px dotted #99C7C2"><?= h($nm) ?></a></td>
                        <td style="text-align:right"><?= $tP > 0 ? h($rp($tP)) : '<span class="muted">' . '—' . '</span>' ?></td>
                        <td style="text-align:right;font-weight:700"><?= h($rp($cP)) ?></td>
                        <td><?php if ($tP > 0): ?><div class="mini"><i style="width:<?= min(100, (int) $persenP) ?>%;background:<?= $persenP >= 100 ? '#10B981' : ($persenP >= 70 ? '#F59E0B' : '#EF4444') ?>"></i></div>
                            <span style="font-size:11px"><?= (int) $persenP ?>%</span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                        <?php $selisihP = $cP - $tP; ?>
                        <td style="text-align:right;font-weight:700;color:<?= $tP <= 0 ? 'var(--muted)' : ($selisihP >= 0 ? '#047857' : '#B91C1C') ?>">
                            <?= $tP > 0 ? ($selisihP >= 0 ? '+' : '−') . ' ' . h($rp(abs($selisihP))) : '—' ?></td>
                        <td style="text-align:right;color:#B45309"><?= $pP > 0 ? h($rp($pP)) : '<span class="muted">—</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($capPic as $nm => $cP): if ($nm === '' || isset($dipakai[$nm]) || $cP <= 0) continue; ?>
                    <tr><td><span class="dot" style="background:#CBD5E1"></span><?= h($nm) ?> <span class="muted" style="font-size:11px">(tidak ada di Master PIC)</span></td>
                        <td style="text-align:right"><span class="muted">—</span></td>
                        <td style="text-align:right;font-weight:700"><?= h($rp($cP)) ?></td>
                        <td><span class="muted">—</span></td><td style="text-align:right"><span class="muted">—</span></td>
                        <td style="text-align:right"><span class="muted">—</span></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (array_sum(array_map(fn($x) => (float) $x['share'], $picTarget)) <= 0): ?>
                <p class="muted" style="font-size:12px;margin:8px 0 0">Porsi target tiap PIC belum diisi — atur di <b>Master Data → Master PIC</b> supaya kolom Target terisi.</p>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <div style="display:flex;gap:14px;flex-wrap:wrap;margin:4px 0 10px">
            <div class="card" style="padding:8px 14px"><span class="muted">Keterisian <?= h(period_label($bulan)) ?></span> <b style="font-size:18px;margin-left:6px"><?= (int) $persen ?>%</b>
                <span class="muted" style="margin-left:6px"><?= (int) $terisiHari ?> dari <?= (int) $kapasitas ?> hari-unit</span></div>
            <?php if ($nilaiHariIni): ?><div class="card" style="padding:8px 14px"><span class="muted">Terisi hari ini</span> <b style="font-size:18px;margin-left:6px"><?= (int) $unitTerisiHariIni ?></b>
                <span class="muted">dari <?= count($baris) ?> unit</span></div><?php endif; ?>
        </div>

        <div class="cal-wrap no-mobile-cards" id="cal-wrap"><table class="cal" data-no-mc="1">
            <?php /* Kolom ringkasan menempel di tepi kanan, pada baris yang sama
                     dengan unitnya — bentuk yang sudah biasa dibaca tim di
                     spreadsheet: jadwal di kiri, angkanya di kanan. */ ?>
            <thead><tr><th class="lt"></th><th class="u">Unit</th>
                <?php for ($d = 1; $d <= $hariN; $d++):
                    $tgl = $awal->setDate((int) $awal->format('Y'), (int) $awal->format('m'), $d);
                    $w = (int) $tgl->format('w');
                    $isLibur = isset($libur[$d]);
                    $kls = $tgl->format('Y-m-d') === $hariIni ? 'hd-now' : ($isLibur ? 'hd-libur' : (($w === 0 || $w === 6) ? 'hd-we' : '')); ?>
                    <th class="<?= $kls ?>"<?= $isLibur ? ' title="' . h($libur[$d]) . '"' : '' ?>><?= $d ?><small><?= h($hari3[$w]) ?></small></th>
                <?php endfor; ?>
                <?php if ($bolehLihatPic): ?>
                    <th class="rk rk-awal" title="Potensi sebulan unit ini (Proyeksi Bulanan di Master, bisa ditimpa per bulan)">FORECAST</th>
                    <th class="rk" title="Kontrak berulang yang jatuh di bulan ini">RECURRING</th>
                    <th class="rk" title="Sewa sekali jalan yang jatuh di bulan ini">EXHIBITION</th>
                    <th class="rk" title="Recurring + Exhibition = yang benar-benar masuk bulan ini">ACTUAL</th>
                    <?php if ($picOn) foreach ($picKolom as $nm): ?>
                        <th class="rk rk-pic" style="--wpic:<?= h($warnaPic[$nm] ?? '#E2E8F0') ?>"
                            title="Nilai yang masuk lewat <?= h($nm) ?>"><span><?= h(mb_strtoupper($nm)) ?></span></th>
                    <?php endforeach; ?>
                    <th class="rk rk-awal" title="Calon yang belum pasti — tidak masuk pencapaian resmi">PROSPEK</th>
                <?php endif; ?>
            </tr></thead>
            <tbody>
            <?php
            /* Lembar tim menutup tiap blok lantai dengan baris "TOTAL LOWER
               GROUND", lalu angka itulah yang dibaca, bukan deretan unitnya.
               Baris total di bawah ini mengikuti bentuk yang sama. */
            $barisTotal = function (string $label, array $t, string $kelas, string $lantaiIni = '')
                           use ($hariN, $bolehLihatPic, $picOn, $picKolom, $bulan, $modul, $warnaPic) {
                $sel = function ($v, float $pros = 0.0, string $kelas = '', string $warna = '') {
                    $v = (float) $v;
                    echo '<td class="rk ' . $kelas . ($pros > 0 ? ' pros-ada' : '') . '"'
                       . ($warna !== '' ? ' style="--wpic:' . h($warna) . '"' : '') . '>'
                       . '<span>' . ($v > 0 ? h(money($v)) : '-')
                       . ($pros > 0 ? '<span class="pros-rp">+ ' . h(money($pros)) . '</span>' : '')
                       . '</span></td>';
                };
                // Judul baris total mengantar ke rincian isinya — pertanyaan
                // berikutnya setelah melihat angka selalu "dari mana?".
                // Seluruh barisnya bisa diklik, bukan hanya tulisannya — sasaran
                // selebar baris jauh lebih mudah dikenai daripada satu kata.
                $tuju = '?r=calendar_detail&month=' . urlencode($bulan) . '&module=' . urlencode($modul)
                      . ($lantaiIni !== '' ? '&floor=' . urlencode($lantaiIni) : '');
                echo '<tr class="' . $kelas . '" data-tuju="' . h($tuju) . '" title="Klik untuk melihat rincian penjualannya">'
                   . '<th class="u" colspan="2">'
                   . '<a href="' . h($tuju) . '" style="color:inherit;border-bottom:1px dotted currentColor">' . h($label) . '</a></th>'
                   . '<td colspan="' . $hariN . '"></td>';
                if ($bolehLihatPic) {
                    $pros = (float) ($t['pros'] ?? 0);
                    $sel($t['fc'] ?? 0, 0.0, 'rk-awal');
                    $sel($t['rec'] ?? 0);
                    $sel($t['exh'] ?? 0, $pros);
                    $sel($t['act'] ?? 0, $pros);
                    if ($picOn) foreach ($picKolom as $nm)
                        $sel($t['pic'][$nm] ?? 0, (float) ($t['picPros'][$nm] ?? 0), 'rk-pic', $warnaPic[$nm] ?? '#E2E8F0');
                    $sel($pros, 0.0, 'rk-awal' . ($pros > 0 ? ' pros-ada' : ''));
                }
                echo '</tr>';
            };
            $kodeUrut = array_keys($baris);
            $grupSekarang = null;
            foreach ($kodeUrut as $iBaris => $kode): $u = $baris[$kode];
                if ($u['grup'] !== $grupSekarang): $grupSekarang = $u['grup']; ?>
                    <?php /* Nama lantainya ditaruh di kolom paling kiri sebagai satu sel
                             memanjang ke bawah — persis sel gabungan "GROUND FLOOR"
                             di lembar mereka, dan ikut membeku saat digeser. */
                    $grupBaru = true;
                endif;
                foreach ($u['lanes'] as $li => $lane):
                    $peta = [];   // hari => selot yang dimulai di hari itu
                    foreach ($lane as $s) $peta[$s['da']] = $s;
                    $mulaiLane = array_keys($peta); sort($mulaiLane); ?>
                    <tr class="<?= $li > 0 ? 'lane2' : '' ?>" data-unit="<?= h($kode) ?>">
                        <?php /* Sel ini ada di SETIAP baris, tapi tulisannya hanya di baris
                                 pertama lantai dan garis bawahnya dihapus — jadi terlihat
                                 sebagai satu sel memanjang, seperti di lembar mereka.
                                 (rowspan tidak dipakai: ia tidak bisa dibekukan di kiri
                                 oleh peramban, dan barisnya jadi melenceng.) */
                        $awalGrup = !empty($grupBaru); $grupBaru = false; ?>
                        <th class="lt<?= $awalGrup ? ' lt-awal' : '' ?>" title="<?= h($grupSekarang) ?>"><?php
                            if ($awalGrup): ?><span><?= h($grupSekarang) ?></span><?php endif; ?></th>
                        <?php /* Cukup namanya saja — di lembar mereka kolom ini memang
                                 hanya berisi nama unit. Kode dan luasnya tetap terbaca
                                 saat kursor berhenti di atasnya. */ ?>
                        <th class="u" title="<?= h(($u['label'] !== '' ? $u['label'] . ' ' : '') . '(' . $kode . ')' . ($u['info'] !== '' ? ' ' . $u['info'] : '')) ?>">
                            <?php if ($li === 0): ?><?= h($u['label'] !== '' ? $u['label'] : $kode) ?>
                            <?php else: /* Baris lanjutan unit yang sama: namanya diulang samar
                                            supaya tidak terbaca sebagai milik unit di bawahnya. */ ?>
                                <span style="color:#B6C2D2;font-weight:400">↳ <?= h($u['label'] !== '' ? $u['label'] : $kode) ?></span>
                            <?php endif; ?></th>
                        <?php for ($d = 1; $d <= $hariN; ):
                            if (isset($peta[$d])): $s = $peta[$d]; $span = $s['db'] - $s['da'] + 1;
                                $pros = !empty($s['pros']);
                                $kls  = ($pros ? 'pros' : '') . ($s['rec'] ? ' rec' : '');
                                // Angka di dalam kotak ditulis penuh juga. Kotaknya hanya
                                // selebar beberapa hari, jadi ia baru ditulis kalau masih
                                // tersisa ruang untuk nama mereknya; selebihnya angkanya
                                // tetap terbaca di blok rekap sebelah kanan.
                                // Sampai hari ke berapa nama merek boleh meluber: sampai
                                // selot berikutnya di jalur yang sama, atau akhir bulan.
                                $berikut = null;
                                foreach ($mulaiLane as $kMulai) { if ($kMulai > $s['da']) { $berikut = $kMulai; break; } }
                                $ruang = (($berikut ?? ($hariN + 1)) - $s['da']) * 30;
                                $butuhNama = mb_strlen((string) $s['brand']) * 6.3 + 16;
                                $luber = $butuhNama > ($span * 30 - 10) && $ruang > ($span * 30);
                                $lebarIsi = $luber ? (int) min(ceil($butuhNama), $ruang - 8) : 0;
                                $angka = (isset($s['bulanRp']) && (float) $s['bulanRp'] > 0) ? money((float) $s['bulanRp']) : '';
                                // Nama merek selalu menang: angka baru ikut ditulis kalau
                                // nama LENGKAP dan angkanya sama-sama muat tanpa meluber.
                                // Kalau tidak, seluruh lebar diserahkan ke namanya.
                                $muatAngka = $angka !== '' && !$luber
                                           && ($span * 30) >= ($butuhNama + mb_strlen($angka) * 5.6 + 14);
                                $data = ['id' => $s['id'], 'b' => $s['brand'], 'pt' => $s['pt'], 'm' => $s['mulai'], 's' => $s['selesai'], 'u' => $kode,
                                         'rec' => $s['rec'], 'own' => $s['milik'] ? 1 : 0, 'pros' => $pros ? 1 : 0,
                                         'picAll' => (string) ($s['picSemua'] ?? $s['pic'] ?? ''), 'yakin' => (string) ($s['yakin'] ?? ''),
                                         'ket' => (string) ($s['ket'] ?? '')]
                                      + (isset($s['pic']) ? ['pic' => $s['pic'], 'nilai' => $s['nilai'], 'inv' => $s['inv']] : [])
                                      + (isset($s['bulanRp']) ? ['bln' => $s['bulanRp']] : [])
                                      + (isset($s['bayar']) ? ['bayar' => $s['bayar'], 'lewat' => $s['lewat'], 'sisa' => $s['sisa']] : []); ?>
                                <td class="s <?= $kls ?> <?= $s['potongKiri'] ? 'kiri' : '' ?> <?= $s['potongKanan'] ? 'kanan' : '' ?>" colspan="<?= $span ?>"
                                    style="background:<?= h((string) ($s['warna'] ?? '#E2E8F0')) ?>"
                                    data-b="<?= h(mb_strtolower($s['brand'] . ' ' . $s['pt'])) ?>" data-j="<?= h(json_encode($data, JSON_UNESCAPED_UNICODE)) ?>"
                                    title="<?= h(($pros ? 'PROSPEK (belum pasti) — ' : '') . $s['brand'] . ' — ' . $s['mulai'] . ' s/d ' . $s['selesai']
                                                . (($s['picSemua'] ?? '') !== '' ? ' — PIC ' . $s['picSemua'] : '')) ?>">
                                    <span class="s-isi<?= $luber ? ' luber' : '' ?>"<?= $luber ? ' style="width:' . (int) $lebarIsi . 'px"' : '' ?>><span class="s-nama"><?= h($s['brand']) ?></span><?php
                                        if ($muatAngka): ?><span class="rp"><?= h($angka) ?></span><?php endif; ?></span>
                                    <?php if (isset($s['bayar'])): ?><span class="pay" style="background:<?= h($stBayar[$s['bayar']][1] ?? '#64748B') ?>"></span><?php endif; ?></td>
                                <?php $d += $span;
                            else:
                                $tglD = $awal->setDate((int) $awal->format('Y'), (int) $awal->format('m'), $d);
                                $w = (int) $tglD->format('w'); ?>
                                <td class="e <?= isset($libur[$d]) ? 'libur' : (($w === 0 || $w === 6) ? 'we' : '') ?> <?= $tglD->format('Y-m-d') === $hariIni ? 'now' : '' ?>" data-d="<?= $d ?>"></td>
                                <?php $d++;
                            endif;
                        endfor; ?>
                        <?php if ($bolehLihatPic):
                            /* Angka di blok kanan mengikuti BARIS ini, bukan unitnya.
                               Pemesanan yang jatuh di baris kedua angkanya ikut turun
                               ke baris kedua — tidak ditumpuk sebagai tambahan di baris
                               pertama. Nilai utuhnya tetap terbaca di tempatnya. */
                            $jl = $u['jalur'][$li] ?? $u['jalurKosong'];
                            $sel = function (float $v, float $pros = 0.0, string $kelas = '', string $warna = '') {
                                $kls = 'rk ' . $kelas . ($v > 0 ? '' : ' nol') . ($pros > 0 ? ' pros-ada' : '');
                                echo '<td class="' . trim($kls) . '"'
                                   . ($warna !== '' ? ' style="--wpic:' . h($warna) . '"' : '')
                                   . ($pros > 0 ? ' title="Calon yang belum pasti: ' . h(money($pros)) . '"' : '') . '>'
                                   . '<span>' . ($v > 0 ? h(money($v)) : ($pros > 0 ? '' : '-'))
                                   . ($pros > 0 ? '<span class="pros-rp">' . ($v > 0 ? '+ ' : '') . h(money($pros)) . '</span>' : '')
                                   . '</span></td>';
                            }; ?>
                            <?php /* Forecast milik UNIT, bukan baris — jadi hanya di baris
                                     pertama; baris lanjutannya dikosongkan. */ ?>
                            <?php if ($li !== 0): ?><td class="rk rk-awal"></td>
                            <?php elseif ($bisaUbahFc && (int) $u['fcId'] > 0): ?>
                                <?php /* Diketik di sini = forecast bulan ini untuk unit ini.
                                         Bulan lain tidak ikut berubah. */ ?>
                                <td class="rk rk-awal fc-sel<?= !empty($u['fcKhusus']) ? ' fc-khusus' : '' ?>">
                                    <input class="fc-in" inputmode="numeric" data-slot="<?= (int) $u['fcId'] ?>" data-kode="<?= h($kode) ?>"
                                           value="<?= (float) $u['rpFc'] > 0 ? h(number_format((float) $u['rpFc'], 0, ',', '.')) : '' ?>"
                                           title="Forecast <?= h(period_label($bulan)) ?> untuk unit ini — ketik lalu tekan Enter">
                                </td>
                            <?php else: $sel((float) $u['rpFc'], 0.0, 'rk-awal'); endif; ?>
                            <?php $sel((float) $jl['rec']); ?>
                            <?php /* Prospek dianggap sewa sekali jalan — kalau jadi, ia masuk
                                     ke Exhibition dan ikut menambah Actual, di baris ini juga. */ ?>
                            <?php $sel((float) $jl['exh'], (float) $jl['pros']); ?>
                            <?php $sel((float) $jl['rec'] + (float) $jl['exh'], (float) $jl['pros']); ?>
                            <?php if ($picOn) foreach ($picKolom as $nm)
                                $sel((float) ($jl['pic'][$nm] ?? 0), (float) ($jl['picPros'][$nm] ?? 0),
                                     'rk-pic', $warnaPic[$nm] ?? '#E2E8F0'); ?>
                            <?php $sel((float) $jl['pros'], 0.0, 'rk-awal' . ((float) $jl['pros'] > 0 ? ' pros-ada' : '')); ?>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php /* Blok lantai ini habis? tutup dengan jumlahnya. */
                $kodeLanjut = $kodeUrut[$iBaris + 1] ?? null;
                if ($kodeLanjut === null || $baris[$kodeLanjut]['grup'] !== $grupSekarang) {
                    $barisTotal('Total ' . $grupSekarang, $totGrup[$grupSekarang] ?? [], 'tot', $grupSekarang);
                } ?>
            <?php endforeach; ?>
            <?php if (count($baris) > 0) $barisTotal('Total seluruhnya', $totAll, 'tot tot-all'); ?>
            <?php if (!$baris): ?><tr><td colspan="<?= $hariN + 2 + ($bolehLihatPic ? 5 + ($picOn ? count($picKolom) : 0) : 0) ?>" style="padding:24px;color:var(--muted)">Tidak ada unit untuk saringan ini.</td></tr><?php endif; ?>
            </tbody>
        </table></div>

        <?php if ($bisaUbahFc): ?>
        <p class="muted" style="font-size:12px;margin:8px 0 0">
            Kolom <b>Forecast</b> bisa langsung diketik di selnya lalu tekan Enter &mdash; berlaku untuk
            <b><?= h(period_label($bulan)) ?></b> saja. Angka dasarnya diambil dari <b>Proyeksi Bulanan</b> di Master,
            jadi kalau yang salah berlaku sepanjang tahun, perbaiki di
            <a href="?r=master&type=<?= h($modul === 'cl' ? 'cl' : $modul) ?>">Master <?= h($modLabel[$modul]) ?></a>.
        </p>
        <?php endif; ?>

        <?php if ($libur): ?>
        <?php /* Blok keterangan hari libur, meniru "KET : HARI LIBUR" di lembar
                 mereka. Diisi lewat Master Data -> Hari Libur. */ ?>
        <div class="panel" style="padding:10px 16px;margin-top:10px;background:#FEF2F2;border:1px solid #FECACA">
            <b style="color:#B91C1C;font-size:12.5px">Hari libur <?= h(period_label($bulan)) ?>:</b>
            <?php foreach ($libur as $tgl => $nm): ?>
                <span style="font-size:12.5px;margin-left:10px"><b><?= (int) $tgl ?></b> <?= h($nm) ?></span>
            <?php endforeach; ?>
            <?php if (can('manage_master')): ?>
                <a class="btn light" style="font-size:11px;padding:2px 9px;margin-left:10px" href="?r=master&type=holiday">Atur</a>
            <?php endif; ?>
        </div>
        <?php elseif (can('manage_master')): ?>
        <div class="panel" style="padding:10px 16px;margin-top:10px">
            <span class="muted" style="font-size:12.5px">Belum ada hari libur tercatat untuk bulan ini.</span>
            <a class="btn light" style="font-size:11px;padding:2px 9px;margin-left:8px" href="?r=master&type=holiday">Atur Hari Libur</a>
        </div>
        <?php endif; ?>

        <div class="cal-leg">
            <?php /* Warna = PIC, persis seperti di spreadsheet. Jenis transaksinya
                     tetap terbaca lewat garis biru tebal di tepi kiri kotak. */ ?>
            <span style="font-weight:700;color:#334155">Warna = PIC:</span>
            <?php foreach ($warnaPic as $nm => $w): ?>
                <span><i style="background:<?= h($w) ?>"></i><?= h($nm) ?></span>
            <?php endforeach; ?>
            <span><i style="background:#E2E8F0"></i>tanpa PIC</span>
            <span style="border-left:1px solid #E4E9F0;padding-left:14px"><i style="background:#F1F5F9;box-shadow:inset 4px 0 0 #1D4ED8"></i>garis biru = Recurring</span>
            <span><i style="background:#E2E8F0;border:1px dashed #EAB308;position:relative">
                <b style="position:absolute;right:0;top:0;width:0;height:0;border-style:solid;border-width:0 6px 6px 0;border-color:transparent #EAB308 transparent transparent"></b>
            </i>Prospek (belum pasti) — sudut kuning</span>
            <?php if ($bisaBayar): ?><span>Titik di pojok:
                <?php foreach (['lunas', 'sebagian', 'belum_bayar', 'belum_invoice'] as $k): ?><span style="margin-left:8px"><i style="background:<?= h($stBayar[$k][1]) ?>;width:8px;height:8px;border-radius:50%"></i><?= h($stBayar[$k][0]) ?></span><?php endforeach; ?></span><?php endif; ?>
            <?php if ($bisaOffer): ?><span class="muted">Klik tanggal kosong untuk mulai, klik tanggal lain di unit yang sama untuk akhir → buat Surat Penawaran.</span><?php endif; ?>
        </div>

        <?php if ($prosLepas): ?>
        <div class="panel" style="padding:12px 16px;margin-top:10px;background:#FFFBEB;border:1px solid #FDE68A">
            <b style="color:#92400E">Prospek yang belum ditentukan unitnya</b>
            <span class="muted" style="font-size:12px">— tidak bisa ditaruh di baris kalender, tapi nilainya ikut dihitung di panel atas.</span>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
                <?php foreach ($prosLepas as $pr): ?>
                    <a class="btn light" style="font-size:12px" href="?r=prospect_form&id=<?= (int) $pr['id'] ?>">
                        <?= h($pr['client_name']) ?> <span class="muted">— <?= h($rpKecil((float) $pr['est_amount'])) ?><?= $pr['pic_name'] ? ' · ' . h($pr['pic_name']) : '' ?></span></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="cal-pop" id="cal-pop"></div>
        <div class="cal-bar" id="cal-bar"><span id="cal-bar-t"></span>
            <a class="btn" id="cal-bar-go" href="#">Buat Surat Penawaran</a><button type="button" class="btn light" id="cal-bar-x">Batal</button></div>

        <script>
        (function(){
            var BISA_OFFER = <?= $bisaOffer ? 'true' : 'false' ?>, BULAN = <?= json_encode($bulan) ?>, MODUL = <?= json_encode($modul) ?>;
            var SEGMEN = <?= json_encode($segmen) ?>, CSRF = <?= json_encode(csrf_token()) ?>;
            var ST = <?= json_encode(array_map(fn($d) => $d[0], $stBayar), JSON_UNESCAPED_UNICODE) ?>;
            var wrap = document.getElementById('cal-wrap'), pop = document.getElementById('cal-pop'), bar = document.getElementById('cal-bar');
            var uang = function(n){ return 'Rp ' + Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.'); };
            var esc = function(s){ var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; };
            var mulai = null;
            function tutup(){ pop.style.display = 'none'; }
            function reset(){ mulai = null; bar.style.display = 'none'; wrap.querySelectorAll('td.pilih').forEach(function(x){ x.classList.remove('pilih'); }); }
            function tgl(d){ return BULAN + '-' + ('0' + d).slice(-2); }
            function buatBar(unit, a, b){
                var jml = b - a + 1;
                document.getElementById('cal-bar-t').textContent = unit + ' · ' + tgl(a).slice(8) + (b !== a ? '–' + tgl(b).slice(8) : '') + ' ' + BULAN + ' (' + jml + ' hari)';
                var url = '?r=offer_form&module=' + encodeURIComponent(MODUL) + '&master_code=' + encodeURIComponent(unit) + '&start_date=' + tgl(a) + '&end_date=' + tgl(b);
                document.getElementById('cal-bar-go').href = url; bar.style.display = 'flex';
            }
            wrap.addEventListener('click', function(ev){
                var s = ev.target.closest('td.s'), e = ev.target.closest('td.e');
                if (s) {
                    reset(); var j = JSON.parse(s.getAttribute('data-j'));
                    var h = (j.pros ? '<div style="font-size:11px;font-weight:800;color:#B45309;letter-spacing:.04em">PROSPEK \u2014 BELUM PASTI</div>' : '')
                        + '<h4>' + esc(j.b) + '</h4><div class="k">' + esc(j.pt) + '</div><hr style="border:0;border-top:1px solid #EEF1F5;margin:8px 0">'
                        + '<div><span class="k">Unit</span> ' + esc(j.u) + '</div><div><span class="k">Periode</span> ' + esc(j.m) + ' s/d ' + esc(j.s) + '</div>';
                    if (j.picAll) h += '<div><span class="k">PIC</span> ' + esc(j.picAll) + '</div>';
                    if (j.pros) {
                        h += '<div><span class="k">Perkiraan nilai</span> <b>' + uang(j.nilai) + '</b></div>'
                           + (j.yakin ? '<div><span class="k">Keyakinan</span> ' + esc(j.yakin) + '</div>' : '')
                           + (j.ket ? '<div><span class="k">Keterangan</span> ' + esc(j.ket) + '</div>' : '')
                           + '<div class="k" style="margin-top:6px">Belum masuk pencapaian resmi. Dihitung terpisah di panel atas.</div>';
                    } else {
                        h += '<div><span class="k">Nilai kontrak</span> ' + uang(j.nilai) + '</div>';
                        if (j.bln !== undefined && Math.abs(j.bln - j.nilai) > 1) h += '<div><span class="k">Jatuh di bulan ini</span> <b>' + uang(j.bln) + '</b></div>';
                        if (j.inv) h += '<div><span class="k">Invoice</span> ' + esc(j.inv) + '</div>';
                        if (j.bayar) h += '<div><span class="k">Pembayaran</span> <b>' + esc(ST[j.bayar] || j.bayar) + '</b>' + (j.lewat ? ' <b style="color:#DC2626">· lewat tempo</b>' : '') + (j.sisa > 0.5 ? ' · sisa ' + uang(j.sisa) : '') + '</div>';
                        if (j.ket) h += '<div><span class="k">Keterangan</span> ' + esc(j.ket) + '</div>';
                    }
                    h += '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px">';
                    if (j.pros) {
                        <?php if ($bisaProspek): ?>
                        h += '<a class="btn light" href="?r=prospect_form&id=' + j.id + '">Ubah prospek</a>'
                           + '<a class="btn light" style="background:#ECFDF5;color:#065F46;border-color:#A7F3D0" href="?r=offer_form&module=' + encodeURIComponent(MODUL)
                           + '&master_code=' + encodeURIComponent(j.u) + '&start_date=' + encodeURIComponent(j.m) + '&end_date=' + encodeURIComponent(j.s)
                           + '&from_prospect=' + j.id + '">Jadi penawaran</a>';
                        <?php endif; ?>
                        h += '<button type="button" class="btn light" onclick="document.getElementById(\'cal-pop\').style.display=\'none\'">Tutup</button></div>';
                        pop.innerHTML = h; pop.style.display = 'block';
                        var rp0 = s.getBoundingClientRect(), xp = Math.min(window.innerWidth - 312, Math.max(12, rp0.left)), yp = rp0.bottom + 6;
                        if (yp + pop.offsetHeight > window.innerHeight - 12) yp = Math.max(12, rp0.top - pop.offsetHeight - 6);
                        pop.style.left = xp + 'px'; pop.style.top = yp + 'px';
                        ev.stopPropagation(); return;
                    }
                    <?php /* Tombol tindakan tetap mengikuti kepemilikan: membuka invoice
                             milik orang lain memang ditolak di halamannya. */ ?>
                    <?php if ($bisaBayar): ?>if (j.own) h += '<a class="btn light" href="?r=payment_form&id=' + j.id + '&back=' + encodeURIComponent(location.search.slice(1)) + '">Invoice &amp; bayar</a>';<?php endif; ?>
                    <?php if (can('view_transactions')): ?>if (j.own) h += '<a class="btn light" href="?r=allocation_detail&id=' + j.id + '">Rincian</a>';<?php endif; ?>
                    <?php if ($bisaOffer): ?>h += '<a class="btn light" href="?r=offer_form&renew_from=' + j.id + '">Perpanjang</a>';<?php endif; ?>
                    h += '<button type="button" class="btn light" onclick="document.getElementById(\'cal-pop\').style.display=\'none\'">Tutup</button></div>';
                    pop.innerHTML = h; pop.style.display = 'block';
                    var r = s.getBoundingClientRect(), w = 300, x = Math.min(window.innerWidth - w - 12, Math.max(12, r.left)), y = r.bottom + 6;
                    if (y + pop.offsetHeight > window.innerHeight - 12) y = Math.max(12, r.top - pop.offsetHeight - 6);
                    pop.style.left = x + 'px'; pop.style.top = y + 'px';
                    ev.stopPropagation(); return;
                }
                tutup();
                if (e && BISA_OFFER) {
                    var unit = e.parentNode.getAttribute('data-unit'), d = parseInt(e.getAttribute('data-d'), 10);
                    if (mulai && mulai.unit === unit) {
                        var a = Math.min(mulai.d, d), b = Math.max(mulai.d, d);
                        wrap.querySelectorAll('tr[data-unit="' + unit + '"] td.e').forEach(function(x){ var n = parseInt(x.getAttribute('data-d'), 10); if (n >= a && n <= b) x.classList.add('pilih'); });
                        buatBar(unit, a, b);
                    } else {
                        reset(); mulai = {unit: unit, d: d}; e.classList.add('pilih'); buatBar(unit, d, d);
                    }
                }
            });
            document.addEventListener('click', function(ev){ if (!ev.target.closest('#cal-pop') && !ev.target.closest('td.s')) tutup(); });
            document.getElementById('cal-bar-x').addEventListener('click', reset);
            document.addEventListener('keydown', function(ev){ if (ev.key === 'Escape') { tutup(); reset(); } });
            document.getElementById('cal-cari').addEventListener('input', function(){
                var q = this.value.trim().toLowerCase();
                wrap.querySelectorAll('td.s').forEach(function(x){
                    var cocok = q !== '' && x.getAttribute('data-b').indexOf(q) !== -1;
                    x.classList.toggle('hit', cocok); x.classList.toggle('dim', q !== '' && !cocok);
                });
            });
            // ── Forecast diketik langsung di selnya ──────────────────────────
            // Disimpan ke potensi bulan yang sedang dibuka saja; angka dasarnya
            // di Master tidak disentuh, jadi bulan lain tidak ikut berubah.
            // Klik di mana pun pada baris total membuka rinciannya.
            wrap.addEventListener('click', function (ev) {
                var tr = ev.target.closest ? ev.target.closest('tr.tot') : null;
                if (!tr || ev.target.closest('a')) return;
                var t = tr.getAttribute('data-tuju');
                if (t) location.href = t;
            });
            wrap.addEventListener('keydown', function (ev) {
                if (ev.target.classList && ev.target.classList.contains('fc-in') && ev.key === 'Enter') { ev.preventDefault(); ev.target.blur(); }
            });
            wrap.addEventListener('input', function (ev) {
                var el = ev.target;
                if (!el.classList || !el.classList.contains('fc-in')) return;
                var a = el.value.replace(/\D/g, '');
                el.value = a ? parseInt(a, 10).toLocaleString('id-ID') : '';
            });
            wrap.addEventListener('focusout', function (ev) {
                var el = ev.target;
                if (!el.classList || !el.classList.contains('fc-in')) return;
                var nilai = el.value.replace(/\D/g, '');
                if (el.dataset.awal === undefined) el.dataset.awal = '';
                if (nilai === el.dataset.tersimpan) return;
                var fd = new FormData();
                fd.append('_csrf', CSRF); fd.append('period', BULAN); fd.append('segment', SEGMEN);
                fd.append('slot_id', el.dataset.slot); fd.append('value', nilai);
                fetch('?r=calendar_forecast_save', { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (!j || !j.ok) { alert('Forecast gagal disimpan.' + (j && j.pesan ? ' ' + j.pesan : '')); return; }
                        el.dataset.tersimpan = nilai;
                        var td = el.closest('td');
                        td.classList.add('simpan'); td.classList.toggle('fc-khusus', nilai !== '');
                        setTimeout(function () { td.classList.remove('simpan'); }, 900);
                    })
                    .catch(function () { alert('Forecast gagal disimpan — periksa sambungan.'); });
            });

            var now = wrap.querySelector('thead .hd-now');
            if (now) wrap.scrollLeft = Math.max(0, now.offsetLeft - 220);
        })();
        </script>
        <?php
    });
}

/**
 * Simpan Forecast satu unit untuk satu bulan (dipanggil dari Kalender).
 *
 * Yang ditulis adalah potensi per periode — angka dasar di Master tidak
 * disentuh, sehingga bulan lain tidak ikut berubah. Nilai kosong berarti
 * "kembalikan ke angka Master", jadi barisnya dihapus, bukan diisi nol.
 */
function calendar_forecast_save(PDO $pdo): void
{
    require_permission('manage_master');
    verify_csrf();
    header('Content-Type: application/json; charset=utf-8');
    $pid  = current_property_id();
    $per  = (string) post('period', '');
    $seg  = (string) post('segment', '');
    $slot = (int) post('slot_id', 0);
    $nilaiMentah = trim((string) post('value', ''));

    if (!preg_match('/^\d{4}-\d{2}$/', $per) || !in_array($seg, ['exhibition', 'media', 'gudang'], true) || $slot <= 0) {
        echo json_encode(['ok' => false, 'pesan' => 'Isian tidak dikenali.']);
        return;
    }
    // Unit harus benar-benar milik properti yang sedang dibuka.
    $tabel = ['exhibition' => 'master_cl_units', 'media' => 'master_media', 'gudang' => 'master_gudang'][$seg];
    $cek = $pdo->prepare("SELECT code FROM $tabel WHERE id = ? AND property_id = ?");
    $cek->execute([$slot, $pid]);
    $kode = $cek->fetchColumn();
    if ($kode === false) { echo json_encode(['ok' => false, 'pesan' => 'Unit tidak ditemukan.']); return; }

    if ($nilaiMentah === '') {
        $pdo->prepare('DELETE FROM period_potentials WHERE property_id=? AND period_key=? AND segment=? AND slot_id=?')
            ->execute([$pid, $per, $seg, $slot]);
        audit($pdo, 'delete', 'period_potentials', $per . '/' . $kode, [], [], 'calendar');
        echo json_encode(['ok' => true, 'kosong' => true]);
        return;
    }
    $nilai = parse_rupiah($nilaiMentah);
    if ($nilai < 0) { echo json_encode(['ok' => false, 'pesan' => 'Angka tidak boleh negatif.']); return; }
    $pdo->prepare('INSERT INTO period_potentials (property_id, period_key, segment, slot_id, slot_code, potential_value, updated_at)
                   VALUES (?,?,?,?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE potential_value = VALUES(potential_value), slot_code = VALUES(slot_code), updated_at = NOW()')
        ->execute([$pid, $per, $seg, $slot, (string) $kode, $nilai]);
    audit($pdo, 'update', 'period_potentials', $per . '/' . $kode, ['potential_value' => $nilai], [], 'calendar');
    echo json_encode(['ok' => true, 'nilai' => $nilai]);
}

/**
 * Rincian "apa saja yang dijual" — dibuka dari nama PIC atau baris total di
 * Kalender.
 *
 * Panel di Kalender hanya menjawab BERAPA. Pertanyaan berikutnya selalu sama:
 * dari mana angkanya? Halaman ini menjawabnya dalam satu daftar — unit, siapa
 * penyewanya, berapa lama, berapa nilainya, dan sudah dibayar atau belum —
 * tanpa perlu mengklik satu per satu di kalender.
 *
 * Nilainya dihitung dengan sumber yang sama dengan panel (transaction_allocations),
 * jadi jumlah di bawah daftar ini pasti sama dengan angka yang diklik.
 */
function calendar_detail(PDO $pdo): void
{
    require_permission('view_pic_report');
    $pid    = current_property_id();
    $bulan  = periode_bulan('month');
    $modul  = in_array(getv('module', ''), ['cl', 'media', 'gudang'], true) ? (string) getv('module') : '';
    $picF   = trim((string) getv('pic', ''));
    $lantai = trim((string) getv('floor', ''));
    $awal   = $bulan . '-01';
    $akhir  = date('Y-m-t', strtotime($awal));

    require_once __DIR__ . '/prospects.php';
    $warnaPic = prospek_warna_pic($pdo, $pid);
    $modLabel = ['cl' => 'Exhibition', 'media' => 'Media', 'gudang' => 'Gudang'];

    // Nama & lantai unit per modul, supaya baris bisa menyebut namanya.
    $unit = [];
    foreach ([
        'cl'     => "SELECT code, location_name nm, floor lt, sort_order so FROM master_cl_units WHERE property_id = ?",
        'media'  => "SELECT code, CONCAT_WS(' ', media_type, location, NULLIF(point,'')) nm, media_type lt, sort_order so FROM master_media WHERE property_id = ?",
        'gudang' => "SELECT code, name nm, location lt, sort_order so FROM master_gudang WHERE property_id = ?",
    ] as $m => $sql) {
        if ($modul !== '' && $modul !== $m) continue;
        $st = $pdo->prepare($sql);
        $st->execute([$pid]);
        foreach ($st->fetchAll() as $u) $unit[$m . '|' . $u['code']] = ['nm' => (string) $u['nm'], 'lt' => (string) $u['lt'], 'so' => (int) $u['so']];
    }

    [$where, $params] = transaksi_tampil_kondisi($pdo, 't');
    $where[] = 't.property_id = :pid AND t.start_date <= :akhir AND t.end_date >= :awal';
    $params += [':pid' => $pid, ':akhir' => $akhir, ':awal' => $awal];
    if ($modul !== '') { $where[] = 't.module = :modul'; $params[':modul'] = $modul; }
    if ($picF !== '')  { $where[] = 't.pic_name = :pic';  $params[':pic']  = $picF; }

    $bisaBayar = can('view_payments') || can('manage_payments');
    $st = $pdo->prepare(
        "SELECT t.id, t.module, t.master_code, t.start_date, t.end_date, t.final_amount, t.area_sqm,
                t.pic_name, t.invoice_no, t.contract_no, (" . recurring_match_sql('t') . ") AS recurring,
                c.company_name, c.brand_name, " . pembayaran_select_sql('t') . ",
                COALESCE((SELECT SUM(a.amount) FROM transaction_allocations a
                           WHERE a.transaction_id = t.id AND a.period_key = :bln), 0) AS nilai_bulan
           FROM transactions t LEFT JOIN master_clients c ON c.id = t.client_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY t.master_code, t.start_date"
    );
    $st->execute($params + [':bln' => $bulan]);

    $baris = []; $jumBulan = 0.0; $jumKontrak = 0.0; $nRec = 0;
    foreach ($st->fetchAll() as $t) {
        $u = $unit[$t['module'] . '|' . $t['master_code']] ?? ['nm' => '', 'lt' => '', 'so' => 9999];
        if ($lantai !== '' && $u['lt'] !== $lantai) continue;
        $t['unit_nama'] = $u['nm'] !== '' ? $u['nm'] : (string) $t['master_code'];
        $t['lantai']    = $u['lt'];
        $t['urut']      = $u['so'];
        $baris[] = $t;
        $jumBulan   += (float) $t['nilai_bulan'];
        $jumKontrak += (float) $t['final_amount'];
        if ((int) $t['recurring']) $nRec++;
    }
    // Diurut menurut abjad nama unit di dalam tiap lantai — itu yang tertulis
    // di kolomnya, dan itu pula cara tim mencari.
    usort($baris, function ($a, $b) {
        $g = _cal_urutan_lantai($a['lantai']) <=> _cal_urutan_lantai($b['lantai']);
        if ($g !== 0) return $g;
        $n = strnatcasecmp((string) $a['unit_nama'], (string) $b['unit_nama']);
        return $n !== 0 ? $n : strcmp((string) $a['start_date'], (string) $b['start_date']);
    });

    // Prospek dengan saringan yang sama — ditampilkan terpisah, tidak dijumlah
    // bersama yang sudah pasti.
    $pros = prospek_bulan($pdo, $pid, $bulan, $modul);
    foreach ($pros as $i => $pr) {
        $u = $unit[$pr['module'] . '|' . (string) $pr['master_code']] ?? null;
        $pros[$i]['unit_nama'] = $u['nm'] ?? '';
    }
    if ($picF !== '') $pros = array_values(array_filter($pros, fn($p) => (string) $p['pic_name'] === $picF));
    if ($lantai !== '') {
        // Prospek ikut disaring ke lantai yang sama; tanpa ini, rincian satu
        // lantai menampilkan calon dari lantai lain dan jumlahnya menyesatkan.
        $pros = array_values(array_filter($pros, function ($p) use ($unit, $lantai) {
            $u = $unit[$p['module'] . '|' . (string) $p['master_code']] ?? null;
            return $u !== null && $u['lt'] === $lantai;
        }));
    }
    $jumPros = array_sum(array_map(fn($p) => (float) $p['est_amount'], $pros));

    $judul = $picF !== '' ? 'Yang dijual ' . $picF
           : ($lantai !== '' ? 'Lantai ' . $lantai : 'Seluruh penjualan');
    $kembali = '?' . http_build_query(array_filter(['r' => 'calendar', 'month' => $bulan, 'module' => $modul, 'floor' => $lantai], fn($v) => $v !== ''));

    layout($judul . ' — ' . period_label($bulan), function () use ($baris, $pros, $jumBulan, $jumKontrak, $jumPros, $nRec,
                                                                 $bulan, $picF, $lantai, $modul, $modLabel, $warnaPic, $kembali, $bisaBayar) {
        $stBayar = pembayaran_status_daftar();
        $tgl = function (?string $d): string {
            if (!$d || !strtotime($d)) return '-';
            $b = [1=>'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
            return (int) date('j', strtotime($d)) . ' ' . $b[(int) date('n', strtotime($d))];
        };
        ?>
        <style>
            .cd-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:14px}
            .cd-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:1px;background:var(--line);
                border:1px solid var(--line);border-radius:12px;overflow:hidden;margin-bottom:14px}
            .cd-kpi>div{background:#fff;padding:13px 16px}
            .cd-kpi .l{font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#98A2B3}
            .cd-kpi .v{font-size:19px;font-weight:800;margin-top:2px;color:#0F2A3F;font-variant-numeric:tabular-nums}
            table.cd{width:100%;border-collapse:collapse;font-size:12.5px;background:#fff}
            table.cd th{background:#0F2A3F;color:#fff;text-align:left;padding:7px 10px;font-size:10.5px;letter-spacing:.03em;white-space:nowrap}
            table.cd td{border-bottom:1px solid #EEF1F5;padding:7px 10px;vertical-align:top}
            table.cd td.r{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
            table.cd tr.lt td{background:#F1F5F9;font-weight:800;font-size:11px;letter-spacing:.04em;color:#0F2A3F;padding:5px 10px}
            table.cd tfoot td{background:#0F2A3F;color:#fff;font-weight:800;padding:8px 10px}
            .cd-dot{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;vertical-align:-1px}
            .cd-jenis{display:inline-block;font-size:10px;font-weight:700;padding:1px 7px;border-radius:4px}
        </style>

        <div class="cd-top">
            <div>
                <div style="font-size:21px;font-weight:800;color:#0F2A3F;line-height:1.2">
                    <?php if ($picF !== ''): ?><span class="cd-dot" style="width:14px;height:14px;background:<?= h($warnaPic[$picF] ?? '#CBD5E1') ?>"></span><?php endif; ?>
                    <?= h($picF !== '' ? $picF : ($lantai !== '' ? 'Lantai ' . $lantai : 'Seluruh penjualan')) ?>
                </div>
                <div class="muted" style="font-size:13px;margin-top:3px"><?= h(period_label($bulan)) ?>
                    <?= $modul !== '' ? ' · ' . h($modLabel[$modul]) : ' · seluruh modul' ?></div>
            </div>
            <a class="btn light" href="<?= h($kembali) ?>">← Kembali ke Kalender</a>
        </div>

        <div class="cd-kpi">
            <div><div class="l">Jumlah transaksi</div><div class="v"><?= count($baris) ?></div>
                <div class="muted" style="font-size:11.5px"><?= (int) $nRec ?> recurring · <?= count($baris) - (int) $nRec ?> sekali jalan</div></div>
            <div><div class="l">Masuk bulan ini</div><div class="v" style="color:#047857"><?= money($jumBulan) ?></div>
                <div class="muted" style="font-size:11.5px">angka yang sama dengan panel Kalender</div></div>
            <div><div class="l">Nilai seluruh kontrak</div><div class="v"><?= money($jumKontrak) ?></div>
                <div class="muted" style="font-size:11.5px">termasuk bagian di bulan lain</div></div>
            <?php if ($pros): ?>
            <div style="background:#FFFBEB"><div class="l">Prospek (belum pasti)</div>
                <div class="v" style="color:#B45309"><?= money($jumPros) ?></div>
                <div class="muted" style="font-size:11.5px"><?= count($pros) ?> calon</div></div>
            <?php endif; ?>
        </div>

        <div class="table-wrap"><table class="cd">
            <thead><tr>
                <th>Unit</th><th>Penyewa</th><th>Periode</th><th>Jenis</th>
                <?php if ($picF === ''): ?><th>PIC</th><?php endif; ?>
                <th style="text-align:right">Nilai kontrak</th><th style="text-align:right">Masuk bulan ini</th>
                <th>No. Kontrak</th><th>Invoice</th><?php if ($bisaBayar): ?><th>Bayar</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php if (!$baris): ?>
                <tr><td colspan="10" style="text-align:center;color:var(--muted);padding:26px">Tidak ada transaksi untuk saringan ini.</td></tr>
            <?php endif; ?>
            <?php $ltSekarang = null; foreach ($baris as $b):
                if ($b['lantai'] !== $ltSekarang): $ltSekarang = $b['lantai']; ?>
                    <tr class="lt"><td colspan="10"><?= h($ltSekarang !== '' ? $ltSekarang : 'Tidak ada di master') ?></td></tr>
                <?php endif; ?>
                <tr>
                    <?php /* Cukup nama unitnya — kodenya tidak dipakai tim sehari-hari. */ ?>
                    <td><b><?= h($b['unit_nama']) ?></b>
                        <?= (float) $b['area_sqm'] > 0 ? '<br><span class="muted" style="font-size:11px">' . h(rtrim(rtrim(number_format((float) $b['area_sqm'], 2, ',', ''), '0'), ',')) . ' m²</span>' : '' ?></td>
                    <td><b><?= h($b['brand_name'] ?: $b['company_name'] ?: '-') ?></b>
                        <?= $b['brand_name'] && $b['company_name'] ? '<br><span class="muted" style="font-size:11px">' . h($b['company_name']) . '</span>' : '' ?></td>
                    <td style="white-space:nowrap"><?= h($tgl($b['start_date'])) ?> – <?= h($tgl($b['end_date'])) ?>
                        <br><span class="muted" style="font-size:11px"><?= (int) ((strtotime((string) $b['end_date']) - strtotime((string) $b['start_date'])) / 86400 + 1) ?> hari</span></td>
                    <td><span class="cd-jenis" style="<?= (int) $b['recurring'] ? 'background:#DBEAFE;color:#1E40AF' : 'background:#FEF3C7;color:#92400E' ?>">
                        <?= (int) $b['recurring'] ? 'Recurring' : 'Sekali jalan' ?></span>
                        <br><span class="muted" style="font-size:11px"><?= h($modLabel[$b['module']] ?? $b['module']) ?></span></td>
                    <?php if ($picF === ''): ?>
                    <td style="white-space:nowrap"><span class="cd-dot" style="background:<?= h($warnaPic[(string) $b['pic_name']] ?? '#CBD5E1') ?>"></span><?= h($b['pic_name'] ?: '-') ?></td>
                    <?php endif; ?>
                    <td class="r"><?= money($b['final_amount']) ?></td>
                    <td class="r"><b><?= (float) $b['nilai_bulan'] > 0 ? money($b['nilai_bulan']) : '<span class="muted">—</span>' ?></b></td>
                    <td style="white-space:nowrap"><?= $b['contract_no'] ? h($b['contract_no']) : '<span class="muted">—</span>' ?></td>
                    <td style="white-space:nowrap"><?= $b['invoice_no'] ? h($b['invoice_no']) : '<span class="muted">—</span>' ?></td>
                    <?php if ($bisaBayar): ?><td><?= pembayaran_badge((string) $b['bayar_status'], (bool) $b['bayar_lewat']) ?></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($baris): ?>
            <tfoot><tr>
                <td colspan="<?= $picF === '' ? 5 : 4 ?>">Jumlah <?= count($baris) ?> transaksi</td>
                <td class="r"><?= money($jumKontrak) ?></td><td class="r"><?= money($jumBulan) ?></td>
                <td colspan="<?= $bisaBayar ? 3 : 2 ?>"></td>
            </tr></tfoot>
            <?php endif; ?>
        </table></div>

        <?php if ($pros): ?>
        <h3 style="margin:20px 0 6px;font-size:14px">Prospek — belum pasti, belum masuk pencapaian</h3>
        <div class="table-wrap"><table class="cd">
            <thead><tr><th>Unit</th><th>Calon penyewa</th><th>Perkiraan periode</th><th>Keyakinan</th>
                <?php if ($picF === ''): ?><th>PIC</th><?php endif; ?><th style="text-align:right">Perkiraan nilai</th></tr></thead>
            <tbody>
            <?php $yk = _prospek_yakin(); foreach ($pros as $p): $y = $yk[$p['confidence']] ?? $yk['sedang']; ?>
                <tr style="background:#FFFBEB">
                    <td><?= ($p['unit_nama'] ?? '') !== '' ? h($p['unit_nama'])
                            : ($p['master_code'] ? h($p['master_code']) : '<span class="muted">belum ditentukan</span>') ?></td>
                    <td><b><?= h($p['client_name']) ?></b><?= $p['note'] ? '<br><span class="muted" style="font-size:11px">' . h($p['note']) . '</span>' : '' ?></td>
                    <td style="white-space:nowrap"><?= h($tgl($p['start_date'])) ?> – <?= h($tgl($p['end_date'])) ?></td>
                    <td><span class="cd-jenis" style="color:<?= $y[2] ?>;background:<?= $y[3] ?>"><?= h($y[0]) ?></span></td>
                    <?php if ($picF === ''): ?><td><?= h($p['pic_name'] ?: '-') ?></td><?php endif; ?>
                    <td class="r" style="color:#B45309;font-weight:700"><?= money($p['est_amount']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
        <?php
    });
}
