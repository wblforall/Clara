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

    // ── Forecast per unit ───────────────────────────────────────────────────
    // Di lembar tim, kolom FORECAST berisi potensi sebulan tiap unit — 7,5 juta
    // untuk satu fashion booth, 70 juta untuk satu island GF. Angka itu sudah
    // ada di CLARA sebagai projection_monthly di master, dan bisa ditimpa per
    // bulan lewat period_potentials. Rumusnya disamakan dengan get_projection()
    // supaya jumlah Forecast di sini sama dengan proyeksi di Dashboard.
    $tabelMaster = ['cl' => 'master_cl_units', 'media' => 'master_media', 'gudang' => 'master_gudang'][$modul];
    $segmen      = ['cl' => 'exhibition', 'media' => 'media', 'gudang' => 'gudang'][$modul];
    $fc = $pdo->prepare("SELECT m.code, COALESCE(pp.potential_value, m.projection_monthly) fc
                           FROM $tabelMaster m
                           LEFT JOIN period_potentials pp ON pp.slot_id = m.id AND pp.segment = ?
                                AND pp.period_key = ? AND pp.property_id = ?
                          WHERE m.property_id = ? AND m.status = 'active'");
    $fc->execute([$segmen, $bulan, $pid, $pid]);
    $forecast = [];
    foreach ($fc->fetchAll() as $r) $forecast[(string) $r['code']] = (float) $r['fc'];

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
        return $g !== 0 ? $g : _cal_cmp_kode($a['kode'], $b['kode']);
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
        $uRec = 0.0; $uExh = 0.0; $uPros = 0.0; $uPic = []; $uPicPros = [];
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
            if ((int) $t['recurring']) $uRec += $rpBulan; else $uExh += $rpBulan;
            $uPic[(string) $t['pic_name']] = ($uPic[(string) $t['pic_name']] ?? 0) + $rpBulan;
            if ($milik) {
                // Nilai per transaksi tetap hanya untuk pemiliknya — batas yang
                // sama dengan popup selama ini. Yang dibagi ke semua orang adalah
                // jumlahannya (per unit & per PIC), bukan rincian tiap client.
                $selot['bulanRp'] = $rpBulan;
                $selot += ['pic' => (string) $t['pic_name'], 'nilai' => (float) $t['final_amount'], 'inv' => (string) $t['invoice_no']];
                if ($bisaBayar) $selot += ['bayar' => (string) $t['bayar_status'], 'lewat' => (int) $t['bayar_lewat'], 'sisa' => (float) $t['tagihan'] - (float) $t['terbayar']];
            }
            for ($d = $da; $d <= $db; $d++) $hariPakai[$d] = true;
            $taruh = false;
            foreach ($lanes as $i => $lane) {
                if (end($lane)['db'] < $da) { $lanes[$i][] = $selot; $taruh = true; break; }
            }
            if (!$taruh) $lanes[] = [$selot];
        }
        // ── Prospek: jalurnya sendiri supaya tidak pernah tertukar dengan yang
        //    sudah pasti. Tampil samar dan bergaris putus-putus di layar.
        foreach ($prosPer[$kode] ?? [] as $pr) {
            $a = max((string) $pr['start_date'], $awal->format('Y-m-d'));
            $b = min((string) $pr['end_date'], $akhir->format('Y-m-d'));
            $da = (int) substr($a, 8, 2); $db = (int) substr($b, 8, 2);
            if ($db < $da) continue;
            $lanes[] = [[
                'pros' => true, 'id' => (int) $pr['id'], 'da' => $da, 'db' => $db,
                'brand' => trim((string) $pr['client_name']), 'pt' => '',
                'mulai' => (string) $pr['start_date'], 'selesai' => (string) $pr['end_date'],
                'rec' => 0, 'milik' => true, 'potongKiri' => (string) $pr['start_date'] < $awal->format('Y-m-d'),
                'potongKanan' => (string) $pr['end_date'] > $akhir->format('Y-m-d'),
                'pic' => (string) $pr['pic_name'], 'nilai' => (float) $pr['est_amount'],
                'bulanRp' => (float) $pr['est_amount'], 'yakin' => (string) $pr['confidence'],
                'warna' => $warnaPic[(string) $pr['pic_name']] ?? '#FDE68A', 'inv' => '',
            ]];
            $uPros += (float) $pr['est_amount'];
            // Kalau calonnya jadi, uangnya masuk ke kolom PIC ini juga — jadi
            // angkanya ikut ditampilkan di sana, bertanda kuning.
            $uPicPros[(string) $pr['pic_name']] = ($uPicPros[(string) $pr['pic_name']] ?? 0) + (float) $pr['est_amount'];
        }

        $terisi = count(array_filter($hariPakai));
        $terisiHari += $terisi; $kapasitas += $hariN;
        if ($bulan === date('Y-m') && $hariPakai[(int) date('j')]) $unitTerisiHariIni++;
        $baris[$kode] = $u + ['lanes' => $lanes ?: [[]], 'terisi' => $terisi, 'jumlahSelot' => array_sum(array_map('count', $lanes)),
                              'rpRec' => $uRec, 'rpExh' => $uExh, 'rpPros' => $uPros,
                              'rpFc' => $forecast[$kode] ?? 0.0, 'rpPic' => $uPic, 'rpPicPros' => $uPicPros];
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

    layout('Kalender Pameran', function () use ($baris, $bulan, $awal, $hariN, $modul, $lantaiF, $picF, $hariIni, $persen, $terisiHari, $kapasitas, $unitTerisiHariIni, $picList,
                                                $bulanSebelum, $bulanSesudah, $hari3, $bisaOffer, $modLabel, $qsBase, $semuaGrup, $bisaBayar, $stBayar, $pid,
                                                $warnaPic, $targetTim, $capaianTim, $capPic, $capModul, $picTarget, $prosTim, $prosPic, $bolehLihatPic, $bisaProspek, $prosLepas, $rpKecil,
                                                $totGrup, $totAll, $picKolom, $picOn) {
        $nilaiHariIni = $bulan === date('Y-m');
        ?>
        <style>
            .cal-wrap{overflow:auto;max-height:72vh;border:1px solid var(--line);border-radius:12px;background:#fff}
            table.cal{border-collapse:separate;border-spacing:0;width:max-content;min-width:100%;font-size:11px}
            table.cal th,table.cal td{padding:0;border-right:1px solid #EEF1F5;border-bottom:1px solid #EEF1F5;height:24px;text-align:center}
            table.cal thead th{position:sticky;top:0;z-index:3;background:#F8FAFC;font-weight:700;color:#475467;height:34px;line-height:1.15}
            table.cal thead th small{display:block;font-weight:500;color:#98A2B3;font-size:9px}
            table.cal .hd-we{background:#FFF7ED}
            table.cal .hd-now{background:#CCFBF1;color:#0F766E}
            table.cal th.u{position:sticky;left:0;z-index:2;background:#fff;text-align:left;min-width:186px;max-width:186px;padding:2px 8px;border-right:2px solid #E4E9F0;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
            table.cal thead th.u{z-index:4;background:#F8FAFC}
            table.cal th.u small{display:block;font-weight:400;color:#98A2B3;font-size:10px;overflow:hidden;text-overflow:ellipsis}
            table.cal tr.grp th,table.cal tr.grp td{background:#0F2A3F;color:#fff;text-align:left;padding:4px 10px;font-size:11px;letter-spacing:.04em;height:22px;border-color:#0F2A3F}
            table.cal tr.grp th.u{position:sticky;left:0;z-index:2;font-weight:700;border-right-color:#0F2A3F}
            table.cal tr.lane2 th.u{color:#98A2B3;font-weight:400}
            table.cal td.e{min-width:19px;width:19px;cursor:pointer}
            table.cal td.e.we{background:#FFFAF3}
            table.cal td.e.now{background:#F0FDFA}
            table.cal td.e:hover{background:#CCFBF1}
            table.cal td.e.pilih{background:#5EEAD4}
            table.cal td.s{padding:0 4px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;cursor:pointer;font-weight:600;max-width:0;border-radius:4px;border:2px solid #fff;position:relative}
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
            table.cal td.rk .pros-rp{display:block;color:#B45309;font-weight:700;font-size:10px;margin-top:1px}
            table.cal tr.tot td.rk.pros-ada{background:#FEF3C7}
            table.cal .rk-awal{border-left:2px solid #E4E9F0}
            table.cal td.rk.nol{color:#CBD5E1}
            table.cal tr.tot{background:#F1F5F9;font-weight:800}
            table.cal tr.tot th.u{background:#F1F5F9;color:#0F2A3F;text-transform:uppercase;font-size:10.5px;letter-spacing:.03em}
            table.cal tr.tot td.rk{font-size:10.5px;color:#0F2A3F}
            table.cal tr.tot-all{background:#0F2A3F;color:#fff}
            table.cal tr.tot-all th.u{background:#0F2A3F;color:#fff}
            table.cal tr.tot-all td.rk{color:#fff}
            /* ── Nilai di dalam kotak ─────────────────────────────────── */
            table.cal td.s .s-isi{display:flex;align-items:center;gap:8px;width:100%}
            table.cal td.s .s-nama{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:center}
            table.cal td.s .rp{flex:0 0 auto;font-weight:700;opacity:.72;font-size:10px;
                text-transform:none;font-style:normal;letter-spacing:0}
            table.cal td.s.rec{box-shadow:inset 4px 0 0 #1D4ED8}
            table.cal td.s.pros{background:repeating-linear-gradient(45deg,#FEF9C3,#FEF9C3 5px,#FEF08A 5px,#FEF08A 10px)!important;
                color:#854D0E;border:2px dashed #EAB308;font-style:italic}
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
            </div>

            <?php if ($bolehLihatPic): ?>
            <table class="cal-pic">
                <thead><tr><th>PIC</th><th style="text-align:right">Target</th><th style="text-align:right">Sudah masuk</th>
                    <th style="width:110px">Capaian</th><th style="text-align:right">Prospek</th></tr></thead>
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
                        <td><span class="dot" style="background:<?= h((string) ($pt['color'] ?: '#CBD5E1')) ?>"></span>
                            <a href="<?= h($qsBase(['pic' => $picF === $nm ? '' : $nm])) ?>" style="color:inherit"><?= h($nm) ?></a></td>
                        <td style="text-align:right"><?= $tP > 0 ? h($rp($tP)) : '<span class="muted">' . '—' . '</span>' ?></td>
                        <td style="text-align:right;font-weight:700"><?= h($rp($cP)) ?></td>
                        <td><?php if ($tP > 0): ?><div class="mini"><i style="width:<?= min(100, (int) $persenP) ?>%;background:<?= $persenP >= 100 ? '#10B981' : ($persenP >= 70 ? '#F59E0B' : '#EF4444') ?>"></i></div>
                            <span style="font-size:11px"><?= (int) $persenP ?>%</span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                        <td style="text-align:right;color:#B45309"><?= $pP > 0 ? h($rp($pP)) : '<span class="muted">—</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($capPic as $nm => $cP): if ($nm === '' || isset($dipakai[$nm]) || $cP <= 0) continue; ?>
                    <tr><td><span class="dot" style="background:#CBD5E1"></span><?= h($nm) ?> <span class="muted" style="font-size:11px">(tidak ada di Master PIC)</span></td>
                        <td style="text-align:right"><span class="muted">—</span></td>
                        <td style="text-align:right;font-weight:700"><?= h($rp($cP)) ?></td>
                        <td><span class="muted">—</span></td><td style="text-align:right"><span class="muted">—</span></td></tr>
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
            <thead><tr><th class="u">Unit</th>
                <?php for ($d = 1; $d <= $hariN; $d++):
                    $tgl = $awal->setDate((int) $awal->format('Y'), (int) $awal->format('m'), $d);
                    $w = (int) $tgl->format('w'); $kls = $tgl->format('Y-m-d') === $hariIni ? 'hd-now' : (($w === 0 || $w === 6) ? 'hd-we' : ''); ?>
                    <th class="<?= $kls ?>"><?= $d ?><small><?= h($hari3[$w]) ?></small></th>
                <?php endfor; ?>
                <?php if ($bolehLihatPic): ?>
                    <th class="rk rk-awal" title="Potensi sebulan unit ini (Proyeksi Bulanan di Master, bisa ditimpa per bulan)">FORECAST</th>
                    <th class="rk" title="Kontrak berulang yang jatuh di bulan ini">RECURRING</th>
                    <th class="rk" title="Sewa sekali jalan yang jatuh di bulan ini">EXHIBITION</th>
                    <th class="rk" title="Recurring + Exhibition = yang benar-benar masuk bulan ini">ACTUAL</th>
                    <?php if ($picOn) foreach ($picKolom as $nm): ?>
                        <th class="rk" title="Nilai yang masuk lewat <?= h($nm) ?>"><?= h(mb_strtoupper($nm)) ?></th>
                    <?php endforeach; ?>
                    <th class="rk rk-awal" title="Calon yang belum pasti — tidak masuk pencapaian resmi">PROSPEK</th>
                <?php endif; ?>
            </tr></thead>
            <tbody>
            <?php
            /* Lembar tim menutup tiap blok lantai dengan baris "TOTAL LOWER
               GROUND", lalu angka itulah yang dibaca, bukan deretan unitnya.
               Baris total di bawah ini mengikuti bentuk yang sama. */
            $barisTotal = function (string $label, array $t, string $kelas) use ($hariN, $bolehLihatPic, $picOn, $picKolom) {
                $sel = function ($v, float $pros = 0.0, string $kelas = '') {
                    $v = (float) $v;
                    echo '<td class="rk ' . $kelas . ($pros > 0 ? ' pros-ada' : '') . '">'
                       . ($v > 0 ? h(money($v)) : '-')
                       . ($pros > 0 ? '<span class="pros-rp">+ ' . h(money($pros)) . '</span>' : '')
                       . '</td>';
                };
                echo '<tr class="' . $kelas . '"><th class="u">' . h($label) . '</th>'
                   . '<td colspan="' . $hariN . '"></td>';
                if ($bolehLihatPic) {
                    $pros = (float) ($t['pros'] ?? 0);
                    $sel($t['fc'] ?? 0, 0.0, 'rk-awal');
                    $sel($t['rec'] ?? 0);
                    $sel($t['exh'] ?? 0, $pros);
                    $sel($t['act'] ?? 0, $pros);
                    if ($picOn) foreach ($picKolom as $nm) $sel($t['pic'][$nm] ?? 0, (float) ($t['picPros'][$nm] ?? 0));
                    $sel($pros, 0.0, 'rk-awal' . ($pros > 0 ? ' pros-ada' : ''));
                }
                echo '</tr>';
            };
            $kodeUrut = array_keys($baris);
            $grupSekarang = null;
            foreach ($kodeUrut as $iBaris => $kode): $u = $baris[$kode];
                if ($u['grup'] !== $grupSekarang): $grupSekarang = $u['grup']; ?>
                    <?php /* Judulnya ditaruh di sel kolom unit supaya ikut membeku saat
                             tabel digeser ke kanan — sel ber-colspan selebar tabel
                             justru hilang dari pandangan begitu digeser. */ ?>
                    <tr class="grp"><th class="u"><?= h($grupSekarang) ?></th>
                        <td colspan="<?= $hariN + ($bolehLihatPic ? 5 + ($picOn ? count($picKolom) : 0) : 0) ?>"></td></tr>
                <?php endif;
                foreach ($u['lanes'] as $li => $lane):
                    $peta = [];   // hari => selot yang dimulai di hari itu
                    foreach ($lane as $s) $peta[$s['da']] = $s; ?>
                    <tr class="<?= $li > 0 ? 'lane2' : '' ?>" data-unit="<?= h($kode) ?>">
                        <?php /* Tim menyebut unitnya dengan NAMA, bukan kode — di lembar
                                 mereka kolom pertama memang berisi nama. Kodenya tetap
                                 tertulis kecil di bawahnya. */ ?>
                        <th class="u" title="<?= h(($u['label'] !== '' ? $u['label'] . ' ' : '') . '(' . $kode . ')' . ($u['info'] !== '' ? ' ' . $u['info'] : '')) ?>">
                            <?php if ($li === 0): ?><?= h($u['label'] !== '' ? $u['label'] : $kode) ?><small><?= h($kode) ?><?= $u['info'] !== '' ? ' · ' . h($u['info']) : '' ?></small>
                            <?php else: ?>↳<?php endif; ?></th>
                        <?php for ($d = 1; $d <= $hariN; ):
                            if (isset($peta[$d])): $s = $peta[$d]; $span = $s['db'] - $s['da'] + 1;
                                $pros = !empty($s['pros']);
                                $kls  = ($pros ? 'pros' : '') . ($s['rec'] ? ' rec' : '');
                                // Angka di dalam kotak ditulis penuh juga. Kotaknya hanya
                                // selebar beberapa hari, jadi ia baru ditulis kalau masih
                                // tersisa ruang untuk nama mereknya; selebihnya angkanya
                                // tetap terbaca di blok rekap sebelah kanan.
                                $angka = (isset($s['bulanRp']) && (float) $s['bulanRp'] > 0) ? money((float) $s['bulanRp']) : '';
                                $muatAngka = $angka !== '' && ($span * 19) >= (mb_strlen($angka) * 6 + 52);
                                $data = ['id' => $s['id'], 'b' => $s['brand'], 'pt' => $s['pt'], 'm' => $s['mulai'], 's' => $s['selesai'], 'u' => $kode,
                                         'rec' => $s['rec'], 'own' => $s['milik'] ? 1 : 0, 'pros' => $pros ? 1 : 0,
                                         'picAll' => (string) ($s['picSemua'] ?? $s['pic'] ?? ''), 'yakin' => (string) ($s['yakin'] ?? '')]
                                      + (isset($s['pic']) ? ['pic' => $s['pic'], 'nilai' => $s['nilai'], 'inv' => $s['inv']] : [])
                                      + (isset($s['bulanRp']) ? ['bln' => $s['bulanRp']] : [])
                                      + (isset($s['bayar']) ? ['bayar' => $s['bayar'], 'lewat' => $s['lewat'], 'sisa' => $s['sisa']] : []); ?>
                                <td class="s <?= $kls ?> <?= $s['potongKiri'] ? 'kiri' : '' ?> <?= $s['potongKanan'] ? 'kanan' : '' ?>" colspan="<?= $span ?>"
                                    style="<?= $pros ? '' : 'background:' . h((string) ($s['warna'] ?? '#E2E8F0')) ?>"
                                    data-b="<?= h(mb_strtolower($s['brand'] . ' ' . $s['pt'])) ?>" data-j="<?= h(json_encode($data, JSON_UNESCAPED_UNICODE)) ?>"
                                    title="<?= h(($pros ? 'PROSPEK (belum pasti) — ' : '') . $s['brand'] . ' — ' . $s['mulai'] . ' s/d ' . $s['selesai']
                                                . (($s['picSemua'] ?? '') !== '' ? ' — PIC ' . $s['picSemua'] : '')) ?>">
                                    <span class="s-isi"><span class="s-nama"><?= h($s['brand']) ?></span><?php
                                        if ($muatAngka): ?><span class="rp"><?= h($angka) ?></span><?php endif; ?></span>
                                    <?php if (isset($s['bayar'])): ?><span class="pay" style="background:<?= h($stBayar[$s['bayar']][1] ?? '#64748B') ?>"></span><?php endif; ?></td>
                                <?php $d += $span;
                            else:
                                $tglD = $awal->setDate((int) $awal->format('Y'), (int) $awal->format('m'), $d);
                                $w = (int) $tglD->format('w'); ?>
                                <td class="e <?= ($w === 0 || $w === 6) ? 'we' : '' ?> <?= $tglD->format('Y-m-d') === $hariIni ? 'now' : '' ?>" data-d="<?= $d ?>"></td>
                                <?php $d++;
                            endif;
                        endfor; ?>
                        <?php if ($bolehLihatPic):
                            // Hanya di jalur pertama; jalur berikutnya masih unit yang
                            // sama, jadi angkanya tidak diulang.
                            /* Angka ditulis penuh. $pros = nilai calon yang BELUM pasti:
                               kalau jadi, uangnya masuk ke kolom ini juga, jadi ia
                               ditampilkan di bawah angka resminya dengan warna kuning
                               — tanda yang sama dengan kotak kuning di kalender. */
                            $sel = function (float $v, float $pros = 0.0, string $kelas = '') use ($li) {
                                if ($li !== 0) { echo '<td class="rk ' . $kelas . '"></td>'; return; }
                                $kls = 'rk ' . $kelas . ($v > 0 ? '' : ' nol') . ($pros > 0 ? ' pros-ada' : '');
                                echo '<td class="' . trim($kls) . '"'
                                   . ($pros > 0 ? ' title="Calon yang belum pasti: ' . h(money($pros)) . '"' : '') . '>'
                                   . ($v > 0 ? h(money($v)) : '-')
                                   . ($pros > 0 ? '<span class="pros-rp">+ ' . h(money($pros)) . '</span>' : '')
                                   . '</td>';
                            };
                            $prosUnit = (float) $u['rpPros']; ?>
                            <?php $sel((float) $u['rpFc'], 0.0, 'rk-awal'); ?>
                            <?php $sel((float) $u['rpRec']); ?>
                            <?php /* Prospek dianggap sewa sekali jalan — kalau jadi, ia masuk
                                     ke Exhibition dan ikut menambah Actual. */ ?>
                            <?php $sel((float) $u['rpExh'], $prosUnit); ?>
                            <?php $sel((float) $u['rpRec'] + (float) $u['rpExh'], $prosUnit); ?>
                            <?php if ($picOn) foreach ($picKolom as $nm) $sel((float) ($u['rpPic'][$nm] ?? 0), (float) ($u['rpPicPros'][$nm] ?? 0)); ?>
                            <?php $sel($prosUnit, 0.0, 'rk-awal' . ($prosUnit > 0 ? ' pros-ada' : '')); ?>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php /* Blok lantai ini habis? tutup dengan jumlahnya. */
                $kodeLanjut = $kodeUrut[$iBaris + 1] ?? null;
                if ($kodeLanjut === null || $baris[$kodeLanjut]['grup'] !== $grupSekarang) {
                    $barisTotal('Total ' . $grupSekarang, $totGrup[$grupSekarang] ?? [], 'tot');
                } ?>
            <?php endforeach; ?>
            <?php if (count($baris) > 0) $barisTotal('Total seluruhnya', $totAll, 'tot tot-all'); ?>
            <?php if (!$baris): ?><tr><td colspan="<?= $hariN + 1 + ($bolehLihatPic ? 5 + ($picOn ? count($picKolom) : 0) : 0) ?>" style="padding:24px;color:var(--muted)">Tidak ada unit untuk saringan ini.</td></tr><?php endif; ?>
            </tbody>
        </table></div>

        <div class="cal-leg">
            <?php /* Warna = PIC, persis seperti di spreadsheet. Jenis transaksinya
                     tetap terbaca lewat garis biru tebal di tepi kiri kotak. */ ?>
            <span style="font-weight:700;color:#334155">Warna = PIC:</span>
            <?php foreach ($warnaPic as $nm => $w): ?>
                <span><i style="background:<?= h($w) ?>"></i><?= h($nm) ?></span>
            <?php endforeach; ?>
            <span><i style="background:#E2E8F0"></i>tanpa PIC</span>
            <span style="border-left:1px solid #E4E9F0;padding-left:14px"><i style="background:#F1F5F9;box-shadow:inset 4px 0 0 #1D4ED8"></i>garis biru = Recurring</span>
            <span><i style="background:repeating-linear-gradient(45deg,#FEF9C3,#FEF9C3 4px,#FEF08A 4px,#FEF08A 8px);border:1px dashed #EAB308"></i>Prospek (belum pasti)</span>
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
                           + '<div class="k" style="margin-top:6px">Belum masuk pencapaian resmi. Dihitung terpisah di panel atas.</div>';
                    } else if (j.own) {
                        h += '<div><span class="k">Nilai kontrak</span> ' + uang(j.nilai) + '</div>';
                        if (j.bln !== undefined && Math.abs(j.bln - j.nilai) > 1) h += '<div><span class="k">Jatuh di bulan ini</span> <b>' + uang(j.bln) + '</b></div>';
                        if (j.inv) h += '<div><span class="k">Invoice</span> ' + esc(j.inv) + '</div>';
                        if (j.bayar) h += '<div><span class="k">Pembayaran</span> <b>' + esc(ST[j.bayar] || j.bayar) + '</b>' + (j.lewat ? ' <b style="color:#DC2626">· lewat tempo</b>' : '') + (j.sisa > 0.5 ? ' · sisa ' + uang(j.sisa) : '') + '</div>';
                    } else {
                        h += '<div class="k" style="margin-top:6px">Nilainya hanya bisa dilihat PIC yang menjualnya.</div>';
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
            var now = wrap.querySelector('thead .hd-now');
            if (now) wrap.scrollLeft = Math.max(0, now.offsetLeft - 220);
        })();
        </script>
        <?php
    });
}
