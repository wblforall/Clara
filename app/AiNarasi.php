<?php
declare(strict_types=1);

/**
 * Analisa otomatis (AI) untuk laporan CLARA.
 *
 * AI hanya MEMBACA angka yang sudah dihitung sistem dan menjelaskannya dalam
 * kalimat; ia tidak mengubah nilai transaksi/penjualan apa pun. Yang dikirim
 * hanya angka teragregasi per properti dan per kelompok unit — tanpa nama
 * klien, nama sales/PIC, atau isi dokumen.
 *
 * Konfigurasi (.env): ANTHROPIC_API_KEY, ANTHROPIC_MODEL (bawaan claude-sonnet-5-5).
 */
final class AiNarasi
{
    public const JENIS_EXEC = 'exec_summary';
    public const PENYEDIA   = 'Anthropic';

    /** 'claude-sonnet-5-5' → 'Claude Sonnet 5.5' (id mentah tetap tersimpan di kolom model). */
    public static function namaTampil(string $model): string
    {
        if (preg_match('/^claude-([a-z]+)-(\d+)(?:-(\d+))?$/i', $model, $m)) {
            return 'Claude ' . ucfirst(strtolower($m[1])) . ' ' . $m[2] . (isset($m[3]) ? '.' . $m[3] : '');
        }
        return $model;
    }

    public static function terkonfigurasi(): bool
    {
        return trim((string) env_value('ANTHROPIC_API_KEY', '')) !== '';
    }

    public static function model(): string
    {
        return trim((string) env_value('ANTHROPIC_MODEL', 'claude-sonnet-5-5')) ?: 'claude-sonnet-5-5';
    }

    /** @return array<string,mixed>|null baris tersimpan atau null */
    public static function ambil(PDO $pdo, string $jenis, string $period): ?array
    {
        $s = $pdo->prepare('SELECT * FROM ai_narasi WHERE jenis = ? AND period_key = ?');
        $s->execute([$jenis, $period]);
        $r = $s->fetch();
        return $r ?: null;
    }

    public static function simpan(PDO $pdo, string $jenis, string $period, string $teks, string $model, string $hash, ?int $masuk, ?int $keluar, ?int $oleh): void
    {
        $s = $pdo->prepare(
            'INSERT INTO ai_narasi (jenis, period_key, teks, penyedia, model, fakta_hash, token_masuk, token_keluar, dibuat_oleh, dibuat_pada)
             VALUES (?,?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE teks=VALUES(teks), penyedia=VALUES(penyedia), model=VALUES(model), fakta_hash=VALUES(fakta_hash),
                 token_masuk=VALUES(token_masuk), token_keluar=VALUES(token_keluar),
                 dibuat_oleh=VALUES(dibuat_oleh), dibuat_pada=NOW()'
        );
        $s->execute([$jenis, $period, $teks, self::PENYEDIA, $model, $hash, $masuk, $keluar, $oleh]);
    }

    /**
     * Susun fakta ringkas (angka saja) untuk satu periode + pembandingnya.
     *
     * @param array<int,array> $propData  hasil _exec_fetch_prop_data per properti (+ 'name')
     * @param array<int,array>|null $prevData periode sebelumnya, bentuk sama, atau null
     * @return array<string,mixed>
     */
    public static function faktaExec(string $period, array $propData, string $prevPeriod, ?array $prevData): array
    {
        $ringkas = function (array $d): array {
            $proj = array_sum((array) $d['projection']);
            $kelompok = function (array $rows): array {
                return array_map(fn($r) => [
                    'kelompok'  => (string) ($r['group_key'] ?? ''),
                    'unit'      => (int) ($r['unit_count'] ?? 0),
                    'potensi'   => round((float) ($r['proj_total'] ?? 0)),
                    'aktual'    => round((float) ($r['actual_total'] ?? 0)),
                    'recurring' => round((float) ($r['recurring_total'] ?? 0)),
                ], $rows);
            };
            return [
                'properti'          => (string) ($d['name'] ?? ''),
                'target'            => round((float) $d['target']),
                'potensi'           => round((float) $proj),
                'aktual'            => round((float) $d['actual']),
                'aktual_per_segmen' => array_map(fn($v) => round((float) $v), (array) $d['actual_seg']),
                'potensi_per_segmen'=> array_map(fn($v) => round((float) $v), (array) $d['projection']),
                'recurring'         => round((float) $d['recurring']),
                'klien_baru'        => (int) $d['new_clients'],
                'capaian_target_pct'=> $d['target'] > 0 ? round($d['actual'] / $d['target'] * 100, 1) : null,
                'capaian_potensi_pct'=> $proj > 0 ? round($d['actual'] / $proj * 100, 1) : null,
                'cl_per_lantai'     => $kelompok((array) $d['floor_occ']),
                'media_per_jenis'   => $kelompok((array) $d['media_occ']),
                'gudang_per_lokasi' => $kelompok((array) $d['gudang_occ']),
            ];
        };

        return [
            'periode'           => $period,
            'properti'          => array_values(array_map($ringkas, $propData)),
            'periode_sebelumnya'=> $prevPeriod,
            'sebelumnya'        => $prevData === null ? null : array_values(array_map($ringkas, $prevData)),
        ];
    }

    // ───────────── Analisa Unit (satu unit / satu segmen) ─────────────

    /** Kunci cache satu unit: 'unit_<modul>_<id master>' (maks 30 karakter). */
    public static function jenisUnit(string $module, int $masterId): string
    {
        return 'unit_' . $module . '_' . $masterId;
    }

    /** Kunci cache satu segmen: 'useg_<modul>_<property_id>'. */
    public static function jenisSegmen(string $module, int $pid): string
    {
        return 'useg_' . $module . '_' . $pid;
    }

    /**
     * Lokasi unit yang aman dikirim. Master Gudang menaruh NAMA PENYEWA di kolom nama
     * (mis. "Reddog - FF Koridor 2") dan titik Media kadang memuat merek, jadi: Gudang
     * hanya dikirim lantainya (tanpa nama); Media hanya bagian sebelum " · " (lokasi, tanpa titik).
     */
    private static function lokasiAman(string $module, string $lokasi): string
    {
        if ($module === 'gudang') return '';
        if ($module === 'media')  return trim(explode(' · ', $lokasi)[0]);
        return $lokasi;
    }

    /** @return list<array<string,mixed>> 12 bulan jendela, angka saja. */
    private static function bulanUnit(array $d, string $kode): array
    {
        $out = [];
        $strip = $d['unit'][$kode]['bulan'] ?? [];
        foreach ($d['bulan'] as $b) {
            $s = $strip[$b['ym']] ?? null;
            $row = ['bulan' => $b['ym'], 'status' => $b['state'], 'hari_dlm_bulan' => $b['hari']];
            if ($b['state'] === 'data' && $s) {
                $row += ['hari_terisi' => $s['hari'], 'okupansi_pct' => $s['okupansi'], 'pendapatan' => $s['nilai'], 'harga_per_hari_terisi' => $s['harga_hari']];
            } elseif ($b['state'] === 'scheduled' && $s) {
                $row += ['hari_terjadwal' => $s['hari']];
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Fakta satu unit. Hanya angka & atribut master unit (kode/lantai/lokasi/tipe/luas/tarif):
     * TANPA nama klien/PIC, catatan, atau nomor dokumen.
     *
     * @param array $d hasil unit_analysis_data(...) (boleh dengan atau tanpa $code)
     * @return array<string,mixed>
     */
    public static function faktaUnit(array $d, string $kode): array
    {
        $x = $d['unit'][$kode];
        $m = $x['master']; $a = $x['agregat']; $seg = $d['segmen'];
        $selisihValid = $a['selisih_vs_master_pct'] !== null;
        return [
            'jenis'   => 'analisa_satu_unit',
            'segmen'  => $d['meta']['module_label'],
            'jendela' => ['awal' => $d['meta']['awal'], 'akhir' => $d['meta']['akhir'], 'bulan_ini' => $d['meta']['hari_ini_bulan'], 'data_riwayat_mulai' => $d['meta']['bulan_pertama_data']],
            'unit'    => [
                'kode' => $m['code'], 'lantai' => $m['lantai'], 'lokasi' => self::lokasiAman($d['meta']['module'], $m['lokasi']), 'tipe' => $m['tipe'],
                'luas_m2' => $m['luas'], 'status_master' => $m['status'],
                'harga_master' => $m['tarif'], 'satuan_harga_master' => $m['satuan_tarif'],
            ],
            'ringkasan' => [
                'hari_terisi' => $a['hari_terisi'], 'hari_tersedia' => $a['hari_tersedia'], 'okupansi_pct' => $a['okupansi'],
                'bulan_terisi' => $a['bulan_terisi'], 'bulan_berdata' => $a['bulan_tersedia'],
                'pendapatan' => $a['pendapatan'], 'jumlah_kontrak' => $a['kontrak'],
                'penyewa_unik' => $a['penyewa_unik'], 'penyewa_kembali' => $a['penyewa_kembali'],
                'harga_per_hari_terisi' => $a['harga_per_hari'], 'harga_per_bulan_setara' => $a['harga_per_bulan'],
                'harga_per_m2_bulan_setara' => $a['harga_per_m2_bulan'],
                'selisih_vs_harga_master_pct' => $a['selisih_vs_master_pct'],
                'selisih_vs_master_valid' => $selisihValid,
                'kosong_terpanjang_hari' => $a['kosong_terpanjang_hari'], 'kosong_mulai' => $a['kosong_terpanjang_hari'] > 0 ? $a['kosong_mulai'] : null,
                'kosong_akhir' => $a['kosong_terpanjang_hari'] > 0 ? $a['kosong_akhir'] : null,
                'tak_pernah_terisi' => $a['tak_pernah_terisi'],
                'hari_terjadwal_di_jendela' => $a['hari_terjadwal'], 'nilai_terjadwal_di_jendela' => $a['nilai_terjadwal'],
            ],
            'kejanggalan_ditandai' => [
                'hari_booking_tumpang_tindih' => $a['hari_ganda'],
                'catatan_harga' => 'Harga per hari memakai nilai kontrak yang dibagi rata per hari (prorata), karena pengakuan pendapatan bisa menumpuk di bulan pertama kontrak.',
            ],
            'pembanding_segmen' => ['okupansi_pct' => $seg['okupansi'], 'jumlah_unit' => $seg['jumlah_unit']],
            'bulan' => self::bulanUnit($d, $kode),
        ];
    }

    /**
     * Fakta satu segmen: ringkasan + distribusi + daftar ringkas (maks 15) unit menonjol.
     *
     * @return array<string,mixed>
     */
    public static function faktaSegmen(array $d): array
    {
        $seg = $d['segmen']; $units = $d['unit'];
        $bulanSeg = [];
        foreach ($d['bulan'] as $b) {
            $s = $seg['bulan'][$b['ym']] ?? ['hari_terisi' => 0, 'hari_tersedia' => 0, 'nilai' => 0];
            $row = ['bulan' => $b['ym'], 'status' => $b['state']];
            if ($b['state'] === 'data') {
                $row += ['hari_terisi' => $s['hari_terisi'], 'hari_tersedia' => $s['hari_tersedia'],
                         'okupansi_pct' => $s['hari_tersedia'] > 0 ? round($s['hari_terisi'] / $s['hari_tersedia'] * 100, 1) : null,
                         'pendapatan' => round((float) $s['nilai'])];
            }
            $bulanSeg[] = $row;
        }

        $sebaran = ['tak_pernah_terisi' => 0, 'di_bawah_25_pct' => 0, '25_sampai_50_pct' => 0, '50_sampai_75_pct' => 0, '75_pct_ke_atas' => 0];
        $kembali = 0; $adaPenyewa = 0; $tumpang = 0; $hargaBulan = []; $selisih = []; $kontrak = 0;
        foreach ($units as $x) {
            $a = $x['agregat']; $o = $a['okupansi'];
            if ($o === null) continue;
            if ($a['hari_terisi'] === 0)  $sebaran['tak_pernah_terisi']++;
            elseif ($o < 25)              $sebaran['di_bawah_25_pct']++;
            elseif ($o < 50)              $sebaran['25_sampai_50_pct']++;
            elseif ($o < 75)              $sebaran['50_sampai_75_pct']++;
            else                          $sebaran['75_pct_ke_atas']++;
            if ($a['penyewa_unik'] > 0)   $adaPenyewa++;
            if ($a['penyewa_kembali'] > 0) $kembali++;
            if ($a['hari_ganda'] > 0)     $tumpang++;
            $kontrak += $a['kontrak'];
            if ($a['harga_per_bulan'] !== null) $hargaBulan[] = $a['harga_per_bulan'];
            if ($a['selisih_vs_master_pct'] !== null) $selisih[] = $a['selisih_vs_master_pct'];
        }
        sort($hargaBulan);
        $median = function (array $v) { $n = count($v); return $n === 0 ? null : ($n % 2 ? $v[intdiv($n, 2)] : round(($v[$n / 2 - 1] + $v[$n / 2]) / 2)); };

        // Unit menonjol: terlaris, tersepi, lalu anomali — maks 15, tanpa duplikat.
        $pilih = []; $alasan = [];
        $tambah = function (string $kode, string $why) use (&$pilih, &$alasan) {
            if (count($pilih) >= 15 && !isset($pilih[$kode])) return;
            $pilih[$kode] = true; $alasan[$kode][] = $why;
        };
        foreach ($seg['terlaris'] as $r) $tambah($r['kode'], 'terlaris');
        foreach ($seg['tersepi'] as $r)  $tambah($r['kode'], 'tersepi_yang_pernah_terisi');
        $kand = [];
        foreach ($units as $kode => $x) {
            $a = $x['agregat'];
            if ($a['hari_ganda'] > 0)                    $kand[] = [3, $a['hari_ganda'], $kode, 'booking_tumpang_tindih'];
            if ($a['hari_terisi'] > 0 && $a['kosong_terpanjang_hari'] >= 60) $kand[] = [2, $a['kosong_terpanjang_hari'], $kode, 'kosong_panjang'];
            if ($a['selisih_vs_master_pct'] !== null && abs($a['selisih_vs_master_pct']) >= 25) $kand[] = [1, abs($a['selisih_vs_master_pct']), $kode, $a['selisih_vs_master_pct'] < 0 ? 'harga_jauh_di_bawah_master' : 'harga_jauh_di_atas_master'];
        }
        usort($kand, fn($p, $q) => [$q[0], $q[1], $p[2]] <=> [$p[0], $p[1], $q[2]]);
        $perJenis = [];
        foreach ($kand as [$pri, , $kode, $why]) {
            $perJenis[$why] = ($perJenis[$why] ?? 0) + 1;
            if ($perJenis[$why] <= 3) $tambah($kode, $why);
        }
        $menonjol = [];
        foreach (array_keys($pilih) as $kode) {
            $x = $units[$kode]; $a = $x['agregat']; $m = $x['master'];
            $menonjol[] = [
                'kode' => $kode, 'lantai' => $m['lantai'], 'lokasi' => self::lokasiAman($d['meta']['module'], $m['lokasi']), 'tipe' => $m['tipe'], 'luas_m2' => $m['luas'],
                'alasan' => $alasan[$kode],
                'okupansi_pct' => $a['okupansi'], 'hari_terisi' => $a['hari_terisi'], 'pendapatan' => $a['pendapatan'],
                'jumlah_kontrak' => $a['kontrak'], 'penyewa_unik' => $a['penyewa_unik'], 'penyewa_kembali' => $a['penyewa_kembali'],
                'harga_per_bulan_setara' => $a['harga_per_bulan'], 'selisih_vs_harga_master_pct' => $a['selisih_vs_master_pct'],
                'kosong_terpanjang_hari' => $a['kosong_terpanjang_hari'], 'hari_booking_tumpang_tindih' => $a['hari_ganda'],
            ];
        }

        return [
            'jenis'   => 'analisa_satu_segmen',
            'segmen'  => $d['meta']['module_label'],
            'jendela' => ['awal' => $d['meta']['awal'], 'akhir' => $d['meta']['akhir'], 'bulan_ini' => $d['meta']['hari_ini_bulan'], 'data_riwayat_mulai' => $d['meta']['bulan_pertama_data']],
            'ringkasan' => [
                'jumlah_unit' => $seg['jumlah_unit'], 'unit_nonaktif_beriwayat' => $seg['nonaktif_unit'],
                'hari_terisi' => $seg['hari_terisi'], 'hari_tersedia' => $seg['hari_tersedia'], 'okupansi_pct' => $seg['okupansi'],
                'pendapatan' => $seg['pendapatan'], 'unit_tak_pernah_terisi' => $seg['tak_pernah'],
                'jumlah_kontrak_semua_unit' => $kontrak,
                'unit_dengan_penyewa' => $adaPenyewa, 'unit_dengan_penyewa_kembali' => $kembali,
                'unit_dengan_booking_tumpang_tindih' => $tumpang,
                'kode_transaksi_tak_terdaftar' => $d['meta']['jumlah_orphan'],
            ],
            'sebaran_okupansi_unit' => $sebaran,
            'harga' => [
                'median_harga_per_bulan_setara' => $median($hargaBulan), 'unit_berharga' => count($hargaBulan),
                'selisih_vs_harga_master_hanya_yang_sebanding' => ['jumlah_unit' => count($selisih), 'rata_rata_pct' => $selisih ? round(array_sum($selisih) / count($selisih), 1) : null,
                    'di_bawah_master_lebih_10_pct' => count(array_filter($selisih, fn($v) => $v <= -10)),
                    'di_atas_master_lebih_10_pct' => count(array_filter($selisih, fn($v) => $v >= 10))],
                'catatan' => 'Harga per hari memakai nilai kontrak yang dibagi rata per hari (prorata) karena pendapatan bisa menumpuk di bulan pertama; selisih vs master hanya dihitung bila satuannya sebanding.',
            ],
            'bulan' => $bulanSeg,
            'unit_menonjol' => $menonjol,
        ];
    }

    private const SISTEM_UNIT = <<<'TXT'
Anda analis komersial di pengelola mal (Pentacity dan E-Walk). Sistem CLARA mencatat tiga segmen sewa: Exhibition (sewa pameran per unit), Media (iklan) dan Gudang (sewa per m²).
Tugas: tulis ANALISA HISTORIS keterisian %OBJEK% dalam jendela 12 bulan, untuk manajemen, dalam Bahasa Indonesia yang ringkas dan lugas.
Cakup: pola musiman (bulan ramai dan sepi), okupansi%SEBARAN%, harga rata-rata (per hari terisi, per bulan setara, per m² bila ada) dibanding tarif master (kunci `harga_master`), kekosongan terpanjang, keberulangan penyewa (jumlah penyewa unik dan berapa yang kembali; tanpa menyebut nama), lalu dugaan penyebab dan hal yang perlu dicek.

Aturan ketat:
- Pakai HANYA angka pada data JSON. Jangan menghitung ulang, mengarang angka, nama klien/PIC, atau penyebab.
- Semua penyebab ditulis sebagai DUGAAN ("kemungkinan", "perlu dicek"), tidak pernah sebagai fakta.
- Bulan berstatus "nodata" = belum ada data riwayat dan "scheduled" = terjadwal (belum dihitung); keduanya BUKAN kosong. Bila `data_riwayat_mulai` setelah awal jendela, sebut bahwa data riwayat baru ada sejak bulan itu.
- Bandingkan harga dengan tarif master HANYA bila `selisih_vs_master_valid` bernilai true; bila tidak, jangan membandingkan.
- Bila ada kejanggalan yang ditandai (booking tumpang tindih; pendapatan diakui menumpuk di bulan pertama sehingga harga memakai nilai prorata per hari), peringatkan pembaca bahwa angkanya perlu diperiksa.
- Uang ditulis dalam Rupiah ringkas (mis. Rp 1,25 miliar, Rp 340 juta). Persentase satu desimal. Tulis bulan dengan nama (mis. Mei 2026).
- Format: 3 paragraf pendek tanpa judul dan tanpa daftar: (1) pola keterisian dan musiman, (2) harga dan penyewa, (3) dugaan penyebab dan hal yang perlu dicek.
- Maksimal 220 kata. Jangan memberi saran di luar data (mis. kebijakan harga).
TXT;

    /** @return array{0:string,1:?int,2:?int} */
    public static function tulisUnit(array $fakta, string $label): array
    {
        $sistem = str_replace(['%OBJEK%', '%SEBARAN%'], ['satu unit sewa', ''], self::SISTEM_UNIT);
        return self::panggil($sistem, "Unit: {$label}\nData (JSON):\n" . json_encode($fakta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    /** @return array{0:string,1:?int,2:?int} */
    public static function tulisSegmen(array $fakta, string $label): array
    {
        $sistem = str_replace(['%OBJEK%', '%SEBARAN%'], ['satu segmen (seluruh unitnya)', ' dan sebarannya antar unit'], self::SISTEM_UNIT)
            . "\n- Data hanya memuat ringkasan segmen dan daftar singkat `unit_menonjol` (bukan semua unit); jangan menyimpulkan unit lain.";
        return self::panggil($sistem, "Segmen: {$label}\nData (JSON):\n" . json_encode($fakta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    public static function hash(array $fakta): string
    {
        return hash('sha256', json_encode($fakta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Minta Claude menulis narasi. Mengembalikan [teks, tokenMasuk, tokenKeluar]
     * atau melempar RuntimeException dengan pesan yang aman ditampilkan.
     *
     * @return array{0:string,1:?int,2:?int}
     */
    public static function tulisExec(array $fakta, string $periodLabel): array
    {
        $sistem = <<<'TXT'
Anda analis komersial di pengelola mal (properti: Pentacity dan E-Walk). Sistem CLARA mencatat tiga segmen pendapatan:
CL (Commercial Leasing/pameran per lantai), Media (iklan), dan Gudang (sewa per m²).
Tugas: tulis ANALISA Executive Summary bulanan untuk direksi, dalam Bahasa Indonesia yang ringkas dan lugas: apa yang terjadi pada angka, di mana letak perbedaannya, dan apa yang perlu ditindaklanjuti.

Aturan ketat:
- Pakai HANYA angka pada data JSON. Jangan mengarang angka, nama klien, atau penyebab.
- Bila menyebut penyebab, tandai sebagai dugaan ("kemungkinan", "perlu dicek"). Jangan menyatakan penyebab sebagai fakta.
- Bandingkan dengan periode sebelumnya bila datanya ada; bila `sebelumnya` null, katakan perbandingan belum tersedia.
- Uang ditulis dalam Rupiah ringkas (mis. Rp 1,25 miliar, Rp 340 juta). Persentase satu desimal.
- Format: 3 paragraf pendek tanpa judul dan tanpa daftar — (1) gambaran gabungan dan capaian terhadap target, (2) perbedaan antar properti dan segmen, (3) hal yang perlu diperhatikan/ditindaklanjuti.
- Maksimal 200 kata. Jangan memberi saran di luar data (mis. harga, kebijakan).
TXT;

        $pengguna = "Periode: {$periodLabel}\nData (JSON):\n"
            . json_encode($fakta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return self::panggil($sistem, $pengguna);
    }

    /**
     * Satu-satunya pintu HTTP ke Claude (dipakai tulisExec/tulisUnit/tulisSegmen).
     * Melempar RuntimeException dengan pesan aman; isi respons tidak pernah diteruskan ke layar.
     *
     * @return array{0:string,1:?int,2:?int}
     */
    private static function panggil(string $sistem, string $pengguna): array
    {
        $key = trim((string) env_value('ANTHROPIC_API_KEY', ''));
        if ($key === '') {
            throw new RuntimeException('Kunci AI belum dipasang di server.');
        }

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $key,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'model'      => self::model(),
                // Batas lega: model bisa menulis blok thinking dulu; tanpa ruang
                // cukup, seluruh batas habis dan teks tidak pernah keluar.
                'max_tokens' => 4000,
                'thinking'   => ['type' => 'between_tools'],
                'system'     => $sistem,
                'messages'   => [['role' => 'user', 'content' => $pengguna]],
            ], JSON_UNESCAPED_UNICODE),
        ]);
        $isi    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $galat  = curl_error($ch);

        if ($isi === false || $status !== 200) {
            // Isi galat TIDAK diteruskan ke layar (bisa memuat detail akun).
            error_log('[AiNarasi] gagal status=' . $status . ' curl=' . $galat . ' body=' . substr((string) $isi, 0, 300));
            throw new RuntimeException('Layanan AI sedang tidak bisa dihubungi. Coba lagi nanti.');
        }

        $data = json_decode((string) $isi, true);
        $teks = '';
        foreach (($data['content'] ?? []) as $blok) {
            if (($blok['type'] ?? '') === 'text') {
                $teks .= (string) ($blok['text'] ?? '');
            }
        }
        $teks = trim($teks);
        if ($teks === '') {
            throw new RuntimeException('AI tidak menghasilkan teks. Coba lagi.');
        }

        return [
            $teks,
            isset($data['usage']['input_tokens']) ? (int) $data['usage']['input_tokens'] : null,
            isset($data['usage']['output_tokens']) ? (int) $data['usage']['output_tokens'] : null,
        ];
    }
}
