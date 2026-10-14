<?php

/**
 * Menyandingkan baris spreadsheet dengan isi CLARA, lalu menamai selisihnya.
 *
 * Membandingkan total saja tidak menolong siapa pun: yang muncul hanya "beda 14
 * juta" tanpa petunjuk beda di mana. Yang dicari orang justru barisnya — mana
 * yang belum diinput, mana yang nominalnya salah ketik, mana yang terlanjur
 * dicatat dua kali, dan mana yang ada di sistem tapi tidak ada di catatan.
 *
 * Istilah hasilnya SENGAJA sama dengan audit manual yang pernah dibagikan ke
 * tim (scripts/audit/match.php): cocok / input / nilai / lebih. Memperkenalkan
 * kosakata baru untuk hal yang sama hanya membuat orang harus belajar dua kali.
 *
 * Urutan pencocokan mengikuti audit itu juga, dan urutannya penting: nilai yang
 * sama persis lebih dipercaya daripada nama yang mirip, sebab nama tenant di
 * spreadsheet ditulis sekenanya ("Amira Shoes" vs "PT Amira Sejahtera"),
 * sedangkan nominal sewa jarang kebetulan sama.
 *
 * Bentuk baris yang diterima (sama untuk kedua sisi):
 *   ['klien'=>string, 'brand'=>string, 'nilai'=>float, 'mulai'=>?string,
 *    'selesai'=>?string, 'pic'=>string, 'rujukan'=>string, 'kode'=>string]
 */
final class ReconMatch
{
    /** Selisih nominal di bawah ini dianggap pembulatan, bukan kesalahan. */
    public const TOLERANSI = 1000.0;
    /** Ambang kemiripan nama (0..1) agar dua baris dianggap penyewa yang sama. */
    public const AMBANG_NAMA = 0.62;

    /**
     * @param array<int,array> $excel baris dari spreadsheet
     * @param array<int,array> $clara baris dari transaction_allocations
     * @return array{cocok:array,nilai:array,pic:array,input:array,lebih:array,
     *               dobel_excel:array,dobel_clara:array,ringkas:array}
     */
    public static function cocokkan(array $excel, array $clara): array
    {
        foreach ($excel as $i => $b) $excel[$i] = self::siap($b);
        foreach ($clara as $i => $b) $clara[$i] = self::siap($b);

        $pakaiE = []; $pakaiC = [];
        $cocok = []; $bedaNilai = []; $bedaPic = [];

        // ── Putaran 1 & 2 — nilai sama, PIC sama ────────────────────────────
        // Toleransi dinaikkan di putaran kedua supaya pembulatan ribuan tidak
        // membuat pasangan yang jelas-jelas sama dianggap tidak ketemu.
        foreach ([0.0, 0.005] as $toleransi) {
            foreach ($excel as $i => $e) {
                if (isset($pakaiE[$i])) continue;
                $terbaik = -1; $skor = -1.0;
                foreach ($clara as $j => $c) {
                    if (isset($pakaiC[$j]) || !self::picSama($e, $c)) continue;
                    $batas = $toleransi === 0.0 ? self::TOLERANSI : max(self::TOLERANSI, $e['nilai'] * $toleransi);
                    if (abs($e['nilai'] - $c['nilai']) > $batas) continue;
                    $s = self::miripBaris($e, $c);
                    if ($s > $skor) { $skor = $s; $terbaik = $j; }
                }
                if ($terbaik >= 0) {
                    $pakaiE[$i] = true; $pakaiC[$terbaik] = true;
                    $cocok[] = ['excel' => $e, 'clara' => $clara[$terbaik], 'selisih' => 0.0];
                }
            }
        }

        // ── Putaran 3 — nama mirip, PIC sama, nilai BEDA → salah nominal ────
        foreach ($excel as $i => $e) {
            if (isset($pakaiE[$i])) continue;
            $terbaik = -1; $skor = self::AMBANG_NAMA;
            foreach ($clara as $j => $c) {
                if (isset($pakaiC[$j]) || !self::picSama($e, $c)) continue;
                $s = self::miripBaris($e, $c);
                if ($s > $skor) { $skor = $s; $terbaik = $j; }
            }
            if ($terbaik >= 0) {
                $pakaiE[$i] = true; $pakaiC[$terbaik] = true;
                $bedaNilai[] = ['excel' => $e, 'clara' => $clara[$terbaik],
                                'selisih' => $clara[$terbaik]['nilai'] - $e['nilai']];
            }
        }

        // ── Putaran 4 — cocok, tapi PIC-nya BERBEDA ─────────────────────────
        // Dikerjakan paling akhir supaya tidak mencuri pasangan milik PIC yang
        // benar. Baris seperti ini bukan selisih uang properti, melainkan
        // salah orang — dan itu mengubah pencapaian dua PIC sekaligus.
        foreach ($excel as $i => $e) {
            if (isset($pakaiE[$i])) continue;
            $terbaik = -1; $skor = self::AMBANG_NAMA;
            foreach ($clara as $j => $c) {
                if (isset($pakaiC[$j])) continue;
                if (abs($e['nilai'] - $c['nilai']) > self::TOLERANSI) continue;
                $s = self::miripBaris($e, $c);
                if ($s > $skor) { $skor = $s; $terbaik = $j; }
            }
            if ($terbaik >= 0) {
                $pakaiE[$i] = true; $pakaiC[$terbaik] = true;
                $bedaPic[] = ['excel' => $e, 'clara' => $clara[$terbaik],
                              'selisih' => $clara[$terbaik]['nilai'] - $e['nilai']];
            }
        }

        $input = []; $lebih = [];
        foreach ($excel as $i => $e) if (!isset($pakaiE[$i])) $input[] = $e;
        foreach ($clara as $j => $c) if (!isset($pakaiC[$j])) $lebih[] = $c;

        $totalE = array_sum(array_column($excel, 'nilai'));
        $totalC = array_sum(array_column($clara, 'nilai'));
        $ringkas = [
            'n_excel'       => count($excel),
            'n_clara'       => count($clara),
            'total_excel'   => $totalE,
            'total_clara'   => $totalC,
            'selisih'       => $totalC - $totalE,
            'n_cocok'       => count($cocok),
            'n_nilai'       => count($bedaNilai),
            'n_pic'         => count($bedaPic),
            'n_input'       => count($input),
            'n_lebih'       => count($lebih),
            'rp_nilai'      => array_sum(array_column($bedaNilai, 'selisih')),
            'rp_input'      => array_sum(array_column($input, 'nilai')),
            'rp_lebih'      => array_sum(array_column($lebih, 'nilai')),
        ];

        return [
            'cocok'       => $cocok,
            'nilai'       => $bedaNilai,
            'pic'         => $bedaPic,
            'input'       => $input,
            'lebih'       => $lebih,
            'dobel_excel' => self::cariDobel($excel),
            'dobel_clara' => self::cariDobel($clara),
            'ringkas'     => $ringkas,
        ];
    }

    /** Rekap per PIC: menurut spreadsheet berapa, menurut CLARA berapa, selisihnya. */
    public static function perPic(array $excel, array $clara): array
    {
        $out = [];
        foreach ($excel as $b) {
            $k = ReconCL::normal((string) ($b['pic'] ?? '')) ?: '(TANPA PIC)';
            $out[$k]['pic']     = ($b['pic'] ?? '') !== '' ? $b['pic'] : '(tanpa PIC)';
            $out[$k]['excel']   = ($out[$k]['excel'] ?? 0) + (float) $b['nilai'];
            $out[$k]['n_excel'] = ($out[$k]['n_excel'] ?? 0) + 1;
        }
        foreach ($clara as $b) {
            $k = ReconCL::normal((string) ($b['pic'] ?? '')) ?: '(TANPA PIC)';
            $out[$k]['pic']     = $out[$k]['pic'] ?? (($b['pic'] ?? '') !== '' ? $b['pic'] : '(tanpa PIC)');
            $out[$k]['clara']   = ($out[$k]['clara'] ?? 0) + (float) $b['nilai'];
            $out[$k]['n_clara'] = ($out[$k]['n_clara'] ?? 0) + 1;
        }
        foreach ($out as $k => $v) {
            $out[$k] += ['excel' => 0.0, 'clara' => 0.0, 'n_excel' => 0, 'n_clara' => 0];
            $out[$k]['selisih'] = $out[$k]['clara'] - $out[$k]['excel'];
        }
        uasort($out, fn($a, $b) => abs($b['selisih']) <=> abs($a['selisih']));
        return $out;
    }

    // ── bagian dalam ────────────────────────────────────────────────────────

    private static function siap(array $b): array
    {
        $b += ['klien' => '', 'brand' => '', 'nilai' => 0.0, 'mulai' => null,
               'selesai' => null, 'pic' => '', 'rujukan' => '', 'kode' => ''];
        $b['nilai'] = (float) $b['nilai'];
        $b['n_pic'] = ReconCL::normal((string) $b['pic']);
        return $b;
    }

    /**
     * Dua nama PIC menunjuk orang yang sama?
     *
     * Spreadsheet memakai panggilan ("MUSI", "ZUL"), CLARA memakai nama lengkap
     * ("Muslimin", "Zulfikar"). Dicocokkan dengan awalan: panggilan hampir
     * selalu potongan depan nama aslinya. Kalau salah satu kosong, dianggap
     * cocok — lebih baik dibandingkan daripada dilewatkan diam-diam.
     */
    private static function picSama(array $e, array $c): bool
    {
        $a = $e['n_pic']; $b = $c['n_pic'];
        if ($a === '' || $b === '') return true;
        if ($a === $b) return true;
        $pendek = strlen($a) <= strlen($b) ? $a : $b;
        $panjang = $pendek === $a ? $b : $a;
        return strlen($pendek) >= 3 && str_starts_with($panjang, $pendek);
    }

    /** Kemiripan 0..1 dua baris, dicari silang antara kolom klien & brand. */
    private static function miripBaris(array $e, array $c): float
    {
        $skor = 0.0;
        foreach ([$e['klien'], $e['brand']] as $a) {
            foreach ([$c['klien'], $c['brand']] as $b) {
                $s = self::mirip((string) $a, (string) $b);
                if ($s > $skor) $skor = $s;
            }
        }
        return $skor;
    }

    private static function mirip(string $a, string $b): float
    {
        $a = ReconCL::normal($a); $b = ReconCL::normal($b);
        if ($a === '' || $b === '') return 0.0;
        if ($a === $b) return 1.0;
        if (strlen($a) >= 5 && strlen($b) >= 5 && (str_contains($a, $b) || str_contains($b, $a))) return 0.9;
        similar_text($a, $b, $p);
        return $p / 100;
    }

    /**
     * Baris kembar DI DALAM satu sumber: penyewa, nominal, dan bulan sama.
     *
     * Ini penyebab selisih yang paling sering tidak disadari — satu sewa
     * terlanjur diketik dua kali, dan totalnya ikut terbawa ke pencapaian.
     */
    private static function cariDobel(array $baris): array
    {
        $peta = [];
        foreach ($baris as $b) {
            // Kode unit ikut jadi kunci: satu penyewa bisa benar-benar menyewa
            // dua titik berbeda dengan harga sama di bulan yang sama, dan itu
            // bukan dobel. Tanpa kode unit, sewa yang sah ikut tertuduh.
            $k = ReconCL::normal($b['klien'] . ' ' . $b['brand']) . '|' . round($b['nilai'])
               . '|' . substr((string) $b['mulai'], 0, 7) . '|' . ReconCL::normal((string) $b['kode']);
            $peta[$k][] = $b;
        }
        $out = [];
        foreach ($peta as $grup) {
            if (count($grup) < 2) continue;
            $out[] = ['jumlah' => count($grup), 'baris' => $grup,
                      'kelebihan' => (float) $grup[0]['nilai'] * (count($grup) - 1)];
        }
        usort($out, fn($a, $b) => $b['kelebihan'] <=> $a['kelebihan']);
        return $out;
    }
}
