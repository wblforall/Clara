<?php

/**
 * Pembaca berkas rekap Casual Leasing (spreadsheet kerja tim) → baris transaksi.
 *
 * Tim memegang satu berkas Excel yang diperbarui tiap bulan, dan selama ini
 * tidak ada cara memeriksa apakah isinya sama dengan yang tercatat di CLARA.
 * Kalau berbeda, tidak ada yang tahu beda di baris mana — yang terlihat hanya
 * totalnya meleset. Kelas ini mengubah sheet mereka menjadi daftar baris yang
 * bisa dibandingkan satu per satu.
 *
 * Tiga bentuk sheet yang dikenali (dideteksi dari isinya, bukan dari namanya,
 * karena nama tab berubah tiap tahun):
 *
 *   monitoring — satu baris per transaksi: NAMA KLIENT, BRAND, NILAI SEWA
 *                (CLOSING), PERIODE SEWA, NAMA SALES. Inilah yang paling tepat
 *                dibandingkan dengan transaksi CLARA.
 *   kalender   — kalender pameran per bulan: satu baris per unit, dengan kolom
 *                ACTUAL dan satu kolom nominal untuk tiap sales.
 *   rekap      — rekapitulasi pencapaian: satu baris per sales (target,
 *                recurring, exhibition, realisasi).
 *
 * Tata letak kolomnya TIDAK tetap: e-Walk dan Pentacity berbeda, dan semester 1
 * berbeda dengan semester 2 (bergeser satu kolom). Karena itu semua kolom
 * dicari dari LABEL di baris judulnya, bukan dari huruf kolomnya.
 */
final class ReconCL
{
    public const BULAN = ['JANUARI', 'FEBRUARI', 'MARET', 'APRIL', 'MEI', 'JUNI',
                          'JULI', 'AGUSTUS', 'SEPTEMBER', 'OKTOBER', 'NOVEMBER', 'DESEMBER'];

    /** Tanggal Excel (angka hari sejak 30/12/1899) → 'YYYY-MM-DD'. */
    public static function tanggalExcel($v): ?string
    {
        $n = (float) $v;
        if ($n < 1 || $n > 90000) return null;
        $ts = (int) round(($n - 25569) * 86400);   // 25569 = 01/01/1970
        return gmdate('Y-m-d', $ts);
    }

    /** Angka dari sel Excel ("1.7E7", "17000000", "Rp 17.000.000") → float. */
    public static function angka($v): float
    {
        $s = trim((string) $v);
        if ($s === '') return 0.0;
        if (preg_match('/^-?\d+(\.\d+)?([eE][+-]?\d+)?$/', $s)) return (float) $s;
        // Ketikan manusia: "Rp 17.000.000" / "17,000,000"
        $s = preg_replace('/[^\d,.-]/', '', $s);
        if (substr_count($s, ',') === 1 && substr_count($s, '.') !== 1) $s = str_replace(',', '.', str_replace('.', '', $s));
        else $s = str_replace(',', '', $s);
        return (float) $s;
    }

    /**
     * Nama dibuat seragam supaya bisa dicocokkan.
     *
     * Spreadsheet ditulis manusia: "PT. Efata Grasia Indonesia", "pt efata
     * grasia", "EFATA GRASIA" — ketiganya klien yang sama. Yang dibuang:
     * bentuk badan usaha, tanda baca, gelar, dan spasi ganda.
     */
    public static function normal(string $s): string
    {
        $s = mb_strtoupper(trim($s), 'UTF-8');
        $s = preg_replace('/\b(PT|CV|UD|TB|PD|TOKO|BPK|IBU|BAPAK|SDR|SDRI)\b\.?/u', ' ', $s);
        $s = preg_replace('/,\s*(SE|ST|MM|SH|S\.KOM|A\.MD)\b\.?/iu', ' ', $s);
        $s = preg_replace('/[^A-Z0-9]+/u', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /**
     * Jenis sheet: 'monitoring' | 'kalender' | 'rekap' | '' (tidak dikenali).
     *
     * @param array<int,array<string,string>> $awal beberapa baris pertama
     */
    public static function jenisSheet(array $awal): string
    {
        $teks = '';
        foreach ($awal as $sel) $teks .= ' ' . mb_strtoupper(implode(' ', $sel), 'UTF-8');
        if (str_contains($teks, 'NILAI SEWA') && str_contains($teks, 'NAMA SALES')) return 'monitoring';
        if (str_contains($teks, 'ACTUAL') && (str_contains($teks, 'FORECAST') || str_contains($teks, 'RECURRING'))) return 'kalender';
        if (str_contains($teks, 'TARGET SALES') && str_contains($teks, 'REALISASI')) return 'rekap';
        return '';
    }

    /**
     * Baca sheet "Monitoring" → daftar transaksi.
     *
     * @return array{baris:array<int,array>,sales:array<int,string>,bulan:?string,peringatan:array<int,string>}
     */
    public static function bacaMonitoring(XlsxReader $x, string $target): array
    {
        $baris = []; $peringatan = []; $bulan = null; $kol = []; $hariKol = [];
        foreach ($x->rows($target) as $no => $sel) {
            // Judul sheet memuat bulannya: "MONITORING SALES - BULAN AGUSTUS 2025".
            if ($bulan === null) {
                foreach ($sel as $v) {
                    if (preg_match('/\b(' . implode('|', self::BULAN) . ')\s+(\d{4})\b/u', mb_strtoupper($v, 'UTF-8'), $m)) {
                        $bulan = sprintf('%04d-%02d', (int) $m[2], array_search($m[1], self::BULAN, true) + 1);
                        break;
                    }
                }
            }
            if (!$kol) {
                $peta = self::petaLabel($sel, ['NAMA KLIENT' => 'klien', 'NAMA KLIEN' => 'klien', 'BRAND' => 'brand',
                    'JENIS PAMERAN' => 'jenis', 'JENIS KLIEN' => 'kategori', 'NILAI SEWA (CLOSING)' => 'nilai',
                    'NILAI SEWA' => 'nilai', 'PERIODE SEWA' => 'mulai', 'JUMLAH HARI' => 'hari',
                    'NAMA SALES' => 'sales', 'KETERANGAN' => 'ket', 'NO' => 'no']);
                if (isset($peta['klien'], $peta['nilai'], $peta['sales'])) {
                    $kol = $peta;
                    // Kolom tanggal (1..31) dipakai menandai baris data yang benar.
                    foreach ($sel as $k => $v) if (preg_match('/^\d+(\.0)?$/', $v) && (int) $v >= 1 && (int) $v <= 31) $hariKol[$k] = (int) $v;
                }
                continue;
            }
            $klien = trim($sel[$kol['klien']] ?? '');
            $nilai = self::angka($sel[$kol['nilai']] ?? '');
            // Transaksi selalu punya nama klien. Baris bernominal besar tanpa nama
            // adalah baris TOTAL di kaki tabel — ikut terhitung, jumlahnya jadi dua
            // kali lipat, dan selisihnya terbaca seolah-olah CLARA yang kurang.
            if ($klien === '') continue;
            if (preg_match('/^(TOTAL|JUMLAH|GRAND|SUB)/iu', $klien)) continue;

            // Periode: "45870 | s/d | 45900" — kolom tengahnya hanya tulisan.
            $mulai = self::tanggalExcel($sel[$kol['mulai']] ?? '');
            $selesai = null;
            if (isset($kol['mulai'])) {
                $n = XlsxReader::kolomKeAngka($kol['mulai']);
                for ($i = 1; $i <= 3; $i++) {
                    $k = XlsxReader::angkaKeKolom($n + $i);
                    $t = self::tanggalExcel($sel[$k] ?? '');
                    if ($t !== null) { $selesai = $t; break; }
                }
            }
            $baris[] = [
                'baris'   => $no,
                'klien'   => $klien,
                'brand'   => trim($sel[$kol['brand'] ?? ''] ?? ''),
                'jenis'   => trim($sel[$kol['jenis'] ?? ''] ?? ''),
                'kategori' => trim($sel[$kol['kategori'] ?? ''] ?? ''),
                'nilai'   => $nilai,
                'mulai'   => $mulai,
                'selesai' => $selesai,
                'hari'    => (int) self::angka($sel[$kol['hari'] ?? ''] ?? ''),
                'sales'   => trim($sel[$kol['sales']] ?? ''),
                'ket'     => trim($sel[$kol['ket'] ?? ''] ?? ''),
            ];
        }
        if (!$kol) $peringatan[] = 'Baris judul tidak ditemukan (dicari: NAMA KLIENT, NILAI SEWA, NAMA SALES).';
        if ($bulan === null) $peringatan[] = 'Bulan tidak terbaca dari judul sheet — pilih sendiri bulannya.';
        $sales = array_values(array_unique(array_filter(array_column($baris, 'sales'))));
        sort($sales);
        return ['baris' => $baris, 'sales' => $sales, 'bulan' => $bulan, 'peringatan' => $peringatan];
    }

    /**
     * Baca sheet kalender → daftar pemakaian unit per bulan.
     *
     * Satu sheet memuat banyak blok: tiap bulan dipecah lagi per lantai, jadi
     * "JANUARI" bisa muncul tiga kali. Semuanya dikumpulkan ke bulan yang sama.
     *
     * @return array{baris:array<int,array>,sales:array<int,string>,bulanAda:array<int,string>,
     *               total_sheet:array<string,array>,peringatan:array<int,string>}
     */
    public static function bacaKalender(XlsxReader $x, string $target, int $tahun): array
    {
        $merge = $x->merges($target);
        $baris = []; $peringatan = []; $semuaSales = [];
        $bulan = null; $kol = []; $picKol = []; $hariKol = []; $kolUnit = null;
        $totalSheet = []; $tungguTotal = []; $tungguPic = []; $tungguBulan = null;

        foreach ($x->rows($target) as $no => $sel) {
            $atas = array_map(fn($v) => mb_strtoupper($v, 'UTF-8'), $sel);

            $adaLabel = in_array('ACTUAL', $atas, true)
                     && (in_array('FORECAST', $atas, true) || in_array('RECURRING', $atas, true));
            // Hanya kolom identitas paling kiri (A..D) yang diperiksa, dan katanya
            // dieja utuh. Pernah memakai pola longgar "GRAND": nama tenant
            // "GRAND CITY" di tengah kalender ikut terbaca sebagai baris total,
            // dan sewanya Rp 27.720.000 hilang dari hitungan tanpa jejak.
            $adaTotal = false;
            foreach ($atas as $k => $v) {
                if (XlsxReader::kolomKeAngka($k) > 4) continue;
                if (preg_match('/^(TOTAL|REALISASI|JUMLAH|SUBTOTAL|GRAND TOTAL)\b/u', trim($v))) { $adaTotal = true; break; }
            }

            // ── Baris rekap di kaki bulan: MENUTUP blok, bukan membuka yang baru ─
            //
            // Di kaki tiap bulan ada baris "TOTAL / REALISASI AGUSTUS" yang
            // MENGULANG seluruh label kolom (ACTUAL, MUSLIMIN, SITA, ...), lalu
            // di bawahnya satu baris berisi jumlah sebulan. Dibaca apa adanya,
            // baris itu terbaca sebagai blok baru dan jumlah sebulan ikut
            // terhitung lagi — seluruh angka bulan itu jadi TEPAT dua kali lipat.
            if ($adaLabel && $adaTotal) {
                // Baris berikutnya memuat jumlah sebulan versi SHEET ITU SENDIRI.
                // Disimpan sebagai pembanding: kalau hasil baca kita berbeda dari
                // angka itu, berarti ada baris yang terlewat atau terhitung dua
                // kali — dan itu harus ketahuan, bukan lolos jadi "selisih".
                $tungguTotal = self::petaLabel($sel, ['ACTUAL' => 'actual', 'KETERANGAN' => 'ket']);
                $tungguPic = [];
                if (isset($tungguTotal['actual'])) {
                    $a = XlsxReader::kolomKeAngka($tungguTotal['actual']);
                    // Baris rekap sering TIDAK memuat label KETERANGAN, jadi batas
                    // kanannya tidak selalu ada. Tanpa cadangan ini, kolom nama
                    // sales di baris rekap tidak pernah terbaca dan pemeriksaan
                    // silangnya diam-diam tidak jalan.
                    $b = isset($tungguTotal['ket']) ? XlsxReader::kolomKeAngka($tungguTotal['ket']) : $a + 7;
                    for ($i = $a + 1; $i < $b; $i++) {
                        $k = XlsxReader::angkaKeKolom($i);
                        if (trim($sel[$k] ?? '') !== '') $tungguPic[$k] = trim($sel[$k]);
                    }
                }
                $tungguBulan = $bulan;
                $kol = []; $hariKol = []; $kolUnit = null;
                continue;
            }
            if (!empty($tungguTotal) && $tungguBulan !== null) {
                $t = ['actual' => self::angka($sel[$tungguTotal['actual']] ?? ''), 'pic' => []];
                foreach ($tungguPic as $k => $nm) $t['pic'][$nm] = self::angka($sel[$k] ?? '');
                if ($t['actual'] > 0) $totalSheet[$tungguBulan] = $t;
                $tungguTotal = []; $tungguPic = []; $tungguBulan = null;
                continue;
            }
            // Subtotal biasa di tengah blok ("TOTAL GUDANG", "TOTAL LG") cukup
            // dilewati: di bawahnya masih ada baris data lanjutan, jadi bloknya
            // TIDAK boleh ikut ditutup.
            if ($adaTotal && !$adaLabel) continue;

            // ── Baris judul blok: memuat ACTUAL dan FORECAST/RECURRING ──────
            if ($adaLabel) {
                $kol = self::petaLabel($sel, ['FORECAST' => 'forecast', 'RECURRING' => 'recurring',
                    'EXHIBITION' => 'exhibition', 'ACTUAL' => 'actual', 'KETERANGAN' => 'ket', 'LUASAN/m' => 'luas']);
                // Kolom antara ACTUAL dan KETERANGAN = satu kolom per sales.
                $picKol = [];
                if (isset($kol['actual'], $kol['ket'])) {
                    $a = XlsxReader::kolomKeAngka($kol['actual']);
                    $b = XlsxReader::kolomKeAngka($kol['ket']);
                    for ($i = $a + 1; $i < $b; $i++) {
                        $k = XlsxReader::angkaKeKolom($i);
                        $nm = trim($sel[$k] ?? '');
                        if ($nm !== '') { $picKol[$k] = $nm; $semuaSales[$nm] = true; }
                    }
                }
                foreach ($atas as $v) {
                    $i = array_search($v, self::BULAN, true);
                    if ($i !== false) { $bulan = sprintf('%04d-%02d', $tahun, $i + 1); break; }
                }
                $hariKol = []; $kolUnit = null;
                continue;
            }
            if (!$kol) continue;

            // ── Baris tanggal (1..31): menentukan kolom kalender & kolom unit ─
            $angkaHari = [];
            foreach ($sel as $k => $v) if (preg_match('/^\d+(\.0)?$/', $v) && (int) $v >= 1 && (int) $v <= 31) $angkaHari[$k] = (int) $v;
            if (count($angkaHari) >= 20) {
                $hariKol = $angkaHari;
                $kolPertama = min(array_map([XlsxReader::class, 'kolomKeAngka'], array_keys($hariKol)));
                $kolUnit = XlsxReader::angkaKeKolom($kolPertama - 1);   // kolom tepat sebelum kalender
                continue;
            }
            if (!$hariKol || $kolUnit === null || $bulan === null) continue;

            // ── Baris data ──────────────────────────────────────────────────
            $unit = trim($sel[$kolUnit] ?? '');
            $actual = self::angka($sel[$kol['actual'] ?? ''] ?? '');
            if (preg_match('/^(TOTAL|JUMLAH|SUB)/iu', $unit)) continue;

            // Tenant ditulis di sel tanggal mulainya, digabung sampai tanggal selesai.
            $tenant = ''; $dMulai = null; $dSelesai = null;
            foreach ($hariKol as $k => $hari) {
                $v = trim($sel[$k] ?? '');
                if ($v === '' || preg_match('/^\d+(\.\d+)?$/', $v)) continue;
                $tenant = $v; $dMulai = $hari;
                $akhir = $merge[$k . $no] ?? null;
                $dSelesai = $akhir ? ($hariKol[preg_replace('/\d+/', '', $akhir)] ?? $hari) : $hari;
                break;
            }

            // Subtotal seksi yang TIDAK diberi label apa pun — kolom unit kosong,
            // kalender kosong, tapi nominalnya terisi (mis. baris 106 sheet e-Walk
            // 2026-1: jumlah empat baris FIRST FLOOR di atasnya). Tanpa penjagaan
            // ini, baris seperti itu terbaca sebagai sewa tersendiri dan income
            // seksinya terhitung dua kali — persis sebesar subtotalnya.
            if ($unit === '' && $tenant === '') continue;
            $perPic = [];
            foreach ($picKol as $k => $nm) {
                $n = self::angka($sel[$k] ?? '');
                if ($n != 0.0) $perPic[$nm] = $n;
            }
            $baris[] = [
                'baris'    => $no,
                'bulan'    => $bulan,
                'unit'     => $unit,
                'tenant'   => $tenant,
                'mulai'    => $dMulai ? sprintf('%s-%02d', $bulan, $dMulai) : null,
                'selesai'  => $dSelesai ? sprintf('%s-%02d', $bulan, $dSelesai) : null,
                'actual'   => $actual,
                'forecast' => self::angka($sel[$kol['forecast'] ?? ''] ?? ''),
                'per_pic'  => $perPic,
                'ket'      => trim($sel[$kol['ket'] ?? ''] ?? ''),
            ];
        }
        if (!$baris) $peringatan[] = 'Tidak ada baris data terbaca — pastikan sheet ini kalender pameran.';
        $bulanAda = array_values(array_unique(array_column($baris, 'bulan')));
        sort($bulanAda);
        return ['baris' => $baris, 'sales' => array_keys($semuaSales), 'bulanAda' => $bulanAda,
                'total_sheet' => $totalSheet, 'peringatan' => $peringatan];
    }

    /**
     * Padankan nama PIC di spreadsheet dengan nama PIC di CLARA.
     *
     * Spreadsheet memakai panggilan sehari-hari — MUSI, ZUL, SINTA — sedangkan
     * CLARA menyimpan nama yang dipakai di dokumen: Muslimin, Zulfikar, Shinta.
     * Tanpa dipadankan, tiap PIC muncul dua kali di rekapitulasi (satu dengan
     * nilai Excel saja, satu dengan nilai CLARA saja) dan SETIAP baris terbaca
     * sebagai "beda PIC" — laporannya jadi penuh selisih palsu.
     *
     * Hasilnya ditampilkan ke pengguna dan boleh dikoreksi sendiri: tebakan
     * yang salah lebih berbahaya daripada tebakan yang kelihatan.
     *
     * @param array<int,string> $namaExcel nama PIC dari spreadsheet
     * @param array<int,string> $namaClara nama PIC yang ada di CLARA
     * @return array<string,string> nama Excel => nama CLARA ('' bila tidak yakin)
     */
    public static function petakanPic(array $namaExcel, array $namaClara): array
    {
        $out = [];
        foreach ($namaExcel as $e) {
            $ne = self::normal($e);
            if ($ne === '') { $out[$e] = ''; continue; }
            $skor = [];
            foreach ($namaClara as $c) {
                $nc = self::normal($c);
                if ($nc === '') continue;
                if ($ne === $nc) { $skor[$c] = 1.0; continue; }
                // Panggilan hampir selalu potongan depan nama aslinya.
                if (strlen($ne) >= 3 && (str_starts_with($nc, $ne) || str_starts_with($ne, $nc))) { $skor[$c] = 0.9; continue; }
                similar_text($ne, $nc, $p);
                $skor[$c] = $p / 100;
            }
            arsort($skor);
            $terbaik = key($skor);
            $nilai = $skor ? current($skor) : 0.0;
            $kedua = count($skor) > 1 ? array_values($skor)[1] : 0.0;
            // Dipakai hanya bila cukup yakin DAN cukup unggul dari calon kedua;
            // dua nama yang sama-sama mirip lebih baik ditanyakan ke pengguna.
            $out[$e] = ($nilai >= 0.55 && ($nilai - $kedua) >= 0.08) ? (string) $terbaik : '';
        }
        return $out;
    }

    /**
     * Baris transaksi CLARA untuk satu properti & satu bulan.
     *
     * Kuerinya SENGAJA disalin dari Laporan PIC (app/pages/laporan.php) —
     * rincian per transaksi, dikelompokkan menurut PIC pada BARIS ALOKASI,
     * bukan PIC transaksinya. Kalau rekonsiliasi memakai kueri karangan
     * sendiri, selisih yang muncul bisa jadi cuma beda cara menghitung, dan
     * orang akan mengejar hantu.
     *
     * Yang dibaca adalah transaction_allocations: nilainya bisa diketik manual
     * per bulan (month_overrides) atau datang dari jadwal harga bertahap, jadi
     * TIDAK ada rumus yang bisa menghitungnya ulang dari nilai kontrak.
     *
     * @return array<int,array>
     */
    public static function dariClara(PDO $pdo, int $pid, string $periode): array
    {
        $st = $pdo->prepare(
            "SELECT t.id, t.module, t.master_code, t.invoice_no,
                    COALESCE(c.company_name, '-') company_name,
                    COALESCE(c.brand_name, '') brand_name,
                    t.start_date, t.end_date,
                    COALESCE(NULLIF(a.pic_name, ''), t.pic_name, 'Tanpa PIC') pic_name,
                    COALESCE(SUM(a.amount), 0) period_amount
               FROM transactions t
               LEFT JOIN master_clients c ON c.id = t.client_id
               JOIN transaction_allocations a ON a.transaction_id = t.id AND a.period_key = ? AND a.property_id = ?
              WHERE t.deleted_at IS NULL AND t.property_id = ?
              GROUP BY t.id, t.module, t.master_code, t.invoice_no, c.company_name, c.brand_name,
                       t.start_date, t.end_date, COALESCE(NULLIF(a.pic_name, ''), t.pic_name, 'Tanpa PIC')
              HAVING period_amount <> 0
              ORDER BY period_amount DESC"
        );
        $st->execute([$periode, $pid, $pid]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'klien'   => (string) $r['company_name'],
                'brand'   => (string) $r['brand_name'],
                'kode'    => (string) $r['master_code'],
                'nilai'   => (float) $r['period_amount'],
                'mulai'   => $r['start_date'],
                'selesai' => $r['end_date'],
                'pic'     => (string) $r['pic_name'],
                'modul'   => (string) $r['module'],
                'invoice' => (string) ($r['invoice_no'] ?? ''),
                'rujukan' => '#' . $r['id'],
            ];
        }
        return $out;
    }

    /**
     * Peta label → huruf kolom, dari satu baris judul.
     *
     * @param array<string,string> $sel
     * @param array<string,string> $cari label (HURUF BESAR) => nama kunci
     * @return array<string,string> nama kunci => huruf kolom
     */
    private static function petaLabel(array $sel, array $cari): array
    {
        $out = [];
        foreach ($sel as $k => $v) {
            // Judul kolom sering diketik dua baris ("JUMLAH\nHARI", "NILAI SEWA\n(CLOSING)").
            // Tanpa diseragamkan, labelnya tidak pernah cocok dan kolomnya terlewat diam-diam.
            $v = preg_replace('/\s+/u', ' ', mb_strtoupper(trim($v), 'UTF-8'));
            foreach ($cari as $label => $kunci) {
                if (isset($out[$kunci])) continue;
                if ($v === $label || str_starts_with($v, $label)) { $out[$kunci] = $k; break; }
            }
        }
        return $out;
    }
}
