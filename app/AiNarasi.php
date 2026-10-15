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
        $key = trim((string) env_value('ANTHROPIC_API_KEY', ''));
        if ($key === '') {
            throw new RuntimeException('Kunci AI belum dipasang di server.');
        }

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
        curl_close($ch);

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
