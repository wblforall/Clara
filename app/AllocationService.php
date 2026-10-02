<?php

final class AllocationService
{
    public static function preview(array $trx): array
    {
        // Penjaga lapis kedua: string kosong dibaca PHP sebagai "hari ini" dan
        // 0000-00-00 sebagai tanggal ngawur, sehingga alokasi bisa terbentuk di
        // bulan yang tidak ada hubungannya dengan kontraknya (lihat #914).
        $tglMulai   = (string) ($trx['start_date'] ?? '');
        $tglSelesai = (string) ($trx['end_date'] ?? '');
        foreach ([$tglMulai, $tglSelesai] as $t) {
            $d = DateTimeImmutable::createFromFormat('Y-m-d', $t);
            if (!($d instanceof DateTimeImmutable) || $d->format('Y-m-d') !== $t) {
                throw new InvalidArgumentException('Tanggal transaksi belum diisi dengan benar.');
            }
        }

        $start = new DateTimeImmutable($trx['start_date']);
        $end = new DateTimeImmutable($trx['end_date']);
        if ($end < $start) {
            throw new InvalidArgumentException('Tanggal selesai tidak boleh lebih kecil dari tanggal mulai.');
        }

        $pricingType = $trx['pricing_type'];
        $unitRate = (float) $trx['unit_rate'];
        $qty = max(1.0, (float) ($trx['quantity'] ?? 1));
        $slots = max(1.0, (float) ($trx['slots'] ?? 1));
        $area = max(0.0, (float) ($trx['area_sqm'] ?? 0));
        $contractMonths = (int) ($trx['contract_months'] ?? 0);

        if ($pricingType === 'monthly') {
            $cycles = self::monthlyCycles($start, $end, $contractMonths, $unitRate * $qty);
            return self::splitCyclesToCalendarMonths($cycles, $pricingType, $qty, $slots, $area);
        }

        if ($pricingType === 'fixed') {
            $totalDays = self::daysInclusive($start, $end);
            $allocations = [];
            foreach (self::monthSegments($start, $end) as $segment) {
                $allocations[] = [
                    'period_key' => $segment['period_key'],
                    'allocation_start' => $segment['start']->format('Y-m-d'),
                    'allocation_end' => $segment['end']->format('Y-m-d'),
                    'allocated_days' => $segment['days'],
                    'amount' => round($unitRate * ($segment['days'] / $totalDays)),
                    'capacity_days' => self::capacityDays($pricingType, $segment['days'], $qty, $slots, $area),
                ];
            }
            return self::adjustRounding($allocations, $unitRate);
        }

        $dailyMultiplier = match ($pricingType) {
            'daily_slot' => $qty * $slots,
            'daily_area' => max(1.0, $area),
            default => $qty,
        };

        $allocations = [];
        foreach (self::monthSegments($start, $end) as $segment) {
            $amount = $segment['days'] * $unitRate * $dailyMultiplier;
            $capacity = self::capacityDays($pricingType, $segment['days'], $qty, $slots, $area);
            $allocations[] = [
                'period_key' => $segment['period_key'],
                'allocation_start' => $segment['start']->format('Y-m-d'),
                'allocation_end' => $segment['end']->format('Y-m-d'),
                'allocated_days' => $segment['days'],
                'amount' => round($amount),
                'capacity_days' => $capacity,
            ];
        }

        return $allocations;
    }

    public static function saveAllocations(PDO $pdo, int $transactionId, array $trx, array $monthOverrides = []): void
    {
        $pdo->prepare('DELETE FROM transaction_allocations WHERE transaction_id = ?')->execute([$transactionId]);
        $allocations = self::preview($trx);
        // Pembagian income ke beberapa PIC: baris alokasi dipecah menurut porsinya
        // supaya laporan per PIC ikut benar tanpa satu query pun diubah.
        $splits = self::picSplits($pdo, $transactionId);
        $totalSplit = array_sum(array_column($splits, 'amount'));

        $finalAmount = (float) ($trx['final_amount'] ?? 0);

        if (($trx['billing_method'] ?? '') === 'spread') {
            $cycleRecognition = $trx['cycle_recognition'] ?? 'cycle_start';
            if (($trx['pricing_type'] ?? '') === 'monthly'
                && in_array($cycleRecognition, ['cycle_start', 'cycle_end'], true)
            ) {
                // Alokasi per siklus — tiap cycle diakui di 1 period_key tanpa dipecah
                $allocations = self::monthlyCycleAllocations(
                    new DateTimeImmutable($trx['start_date']),
                    new DateTimeImmutable($trx['end_date']),
                    $finalAmount,
                    $cycleRecognition
                );
                // Jadwal harga bertahap menggantikan pembagian rata bila ada.
                $tahap = self::priceSteps($pdo, $transactionId);
                if ($tahap) {
                    $allocations = self::terapkanTahap($allocations, $tahap);
                    // Jadwal harga hanya mengatur SEWA per bulan. Biaya lain yang
                    // ikut masuk nilai kontrak (mis. listrik) tidak ada di jadwal,
                    // jadi selisihnya dibagi rata ke tiap bulan — kalau tidak,
                    // biaya itu hilang dari income bulanan.
                    $allocations = self::sebarSisaKontrak($allocations, $finalAmount);
                }
                if ($monthOverrides) {
                    $allocations = self::applyMonthOverrides($allocations, $monthOverrides);
                }
                $propertyId = (int)($trx['property_id'] ?? (function_exists('current_property_id') ? current_property_id() : 1));
                $stmt = $pdo->prepare(
                    'INSERT INTO transaction_allocations
                    (property_id, transaction_id, module, master_code, period_key, allocation_start, allocation_end, allocated_days, amount, capacity_days, pic_name)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)'
                );
                foreach ($allocations as $a) {
                    foreach (self::pecahPerPic($a, $splits, $totalSplit) as $b) {
                        $stmt->execute([
                            $propertyId, $transactionId, $trx['module'], $trx['master_code'],
                            $b['period_key'], $b['allocation_start'], $b['allocation_end'],
                            $b['allocated_days'], $b['amount'], $b['capacity_days'],
                            $b['pic_name'] ?? ($trx['pic_name'] ?? null),
                        ]);
                    }
                }
                return;
            }
            $allocations = self::applySpreadAmount($allocations, $finalAmount);
            if ($monthOverrides) {
                $allocations = self::applyMonthOverrides($allocations, $monthOverrides);
            }
        } else {
            if ($finalAmount > 0) {
                $allocations = self::applyOverrideAmount($allocations, $finalAmount);
            }
            $recognitionPeriod = $trx['recognition_period'] ?? null;
            if ($recognitionPeriod !== null) {
                $totalAmount = array_sum(array_column($allocations, 'amount'));
                foreach ($allocations as &$a) {
                    $a['amount'] = $a['period_key'] === $recognitionPeriod ? $totalAmount : 0;
                }
                unset($a);
            }
        }

        $propertyId = (int)($trx['property_id'] ?? (function_exists('current_property_id') ? current_property_id() : 1));
        $stmt = $pdo->prepare(
            'INSERT INTO transaction_allocations
            (property_id, transaction_id, module, master_code, period_key, allocation_start, allocation_end, allocated_days, amount, capacity_days, pic_name)
            VALUES
            (:property_id, :transaction_id, :module, :master_code, :period_key, :allocation_start, :allocation_end, :allocated_days, :amount, :capacity_days, :pic_name)'
        );

        foreach ($allocations as $allocation) {
            foreach (self::pecahPerPic($allocation, $splits, $totalSplit) as $b) {
                $stmt->execute([
                    ':property_id'    => $propertyId,
                    ':transaction_id' => $transactionId,
                    ':module'         => $trx['module'],
                    ':master_code'    => $trx['master_code'],
                    ':period_key'     => $b['period_key'],
                    ':allocation_start' => $b['allocation_start'],
                    ':allocation_end'   => $b['allocation_end'],
                    ':allocated_days'   => $b['allocated_days'],
                    ':amount'         => $b['amount'],
                    ':capacity_days'  => $b['capacity_days'],
                    ':pic_name'       => $b['pic_name'] ?? ($trx['pic_name'] ?? null),
                ]);
            }
        }
    }

    /**
     * Pembagian income transaksi ini ke beberapa PIC (kosong = tidak dibagi).
     * Dibaca dari transaction_pic_splits, bukan dari baris alokasi — alokasi
     * selalu dihapus & ditulis ulang, jadi tidak bisa jadi tempat menyimpan niat.
     */
    public static function picSplits(PDO $pdo, int $transactionId): array
    {
        if ($transactionId <= 0) return [];
        try {
            $st = $pdo->prepare('SELECT pic_name, amount FROM transaction_pic_splits WHERE transaction_id = ? ORDER BY id');
            $st->execute([$transactionId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];   // tabel belum ada (migrasi belum jalan) → perilaku lama
        }
        $out = [];
        foreach ($rows as $r) {
            $n = (float) $r['amount'];
            if ($n > 0 && trim((string) $r['pic_name']) !== '') $out[] = ['pic' => (string) $r['pic_name'], 'amount' => $n];
        }
        if (!$out) return [];
        // Pembagian hanya dipakai bila jumlahnya PAS dengan nilai kontrak.
        // Bila tidak, nominalnya akan bekerja sebagai rasio dan memberi porsi
        // yang sama sekali tidak diminta — lebih aman kembali ke satu PIC.
        $tq = $pdo->prepare('SELECT final_amount, total_calculated FROM transactions WHERE id = ?');
        $tq->execute([$transactionId]);
        $t = $tq->fetch(PDO::FETCH_ASSOC);
        $nilai = $t ? round((float) ($t['final_amount'] ?: $t['total_calculated'])) : 0;
        if ($nilai > 0 && round(array_sum(array_column($out, 'amount'))) !== $nilai) return [];
        return $out;
    }

    /**
     * Pecah satu baris alokasi menjadi beberapa baris sesuai porsi tiap PIC.
     *
     * HARI hanya ditaruh di baris pertama (allocated_days & capacity_days = 0
     * pada baris berikutnya) supaya occupancy dan tarif rata-rata — yang
     * menjumlahkan hari — tidak ikut berganda. Sisa pembulatan dibuang ke baris
     * terakhir, konvensi yang sama dengan adjustRounding().
     */
    private static function pecahPerPic(array $allocation, array $splits, float $totalSplit): array
    {
        if (!$splits || $totalSplit <= 0) return [$allocation];
        $nilai = (float) $allocation['amount'];
        $baris = [];
        $terpakai = 0.0;
        $n = count($splits);
        foreach ($splits as $i => $s) {
            $porsi = $i === $n - 1 ? round($nilai - $terpakai, 2) : round($nilai * ($s['amount'] / $totalSplit), 2);
            $terpakai += $porsi;
            $b = $allocation;
            $b['amount']   = $porsi;
            $b['pic_name'] = $s['pic'];
            if ($i > 0) { $b['allocated_days'] = 0; $b['capacity_days'] = 0; }
            $baris[] = $b;
        }
        return $baris;
    }

    /**
     * Terapkan pembagian PIC pada alokasi yang SUDAH ada, tanpa menghitung ulang
     * nominalnya. Dipakai saat pembagian diubah pada transaksi yang sudah
     * berjalan: nilai per bulan dan jumlah hari dipertahankan apa adanya —
     * yang berubah hanya kepada siapa bulan itu dicatat.
     *
     * Aman dijalankan berkali-kali: baris yang sudah terpecah dikumpulkan dulu
     * per (periode, tanggal) sebelum dipecah ulang.
     */
    public static function terapkanPembagian(PDO $pdo, int $transactionId, ?string $picUtama = null): int
    {
        if ($transactionId <= 0) return 0;
        // Tanpa pembagian, income kembali ke PIC TRANSAKSI — bukan ke PIC yang
        // kebetulan ada di baris alokasi pertama (itu bisa penerima pembagian lama).
        if ($picUtama === null) {
            $pq = $pdo->prepare('SELECT pic_name FROM transactions WHERE id = ?');
            $pq->execute([$transactionId]);
            $picUtama = (string) ($pq->fetchColumn() ?: '') ?: null;
        }
        $st = $pdo->prepare('SELECT * FROM transaction_allocations WHERE transaction_id = ? ORDER BY id');
        $st->execute([$transactionId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) return 0;

        // Kumpulkan kembali baris yang mungkin sudah pernah dipecah.
        $gabung = [];
        foreach ($rows as $r) {
            $k = $r['period_key'] . '|' . $r['allocation_start'] . '|' . $r['allocation_end'];
            if (!isset($gabung[$k])) {
                $gabung[$k] = $r;
                $gabung[$k]['amount']         = 0.0;
                $gabung[$k]['allocated_days'] = 0;
                $gabung[$k]['capacity_days']  = 0;
            }
            $gabung[$k]['amount']         += (float) $r['amount'];
            $gabung[$k]['allocated_days']  = max((int) $gabung[$k]['allocated_days'], (int) $r['allocated_days']);
            $gabung[$k]['capacity_days']   = max((float) $gabung[$k]['capacity_days'], (float) $r['capacity_days']);
        }

        $splits = self::picSplits($pdo, $transactionId);
        $totalSplit = array_sum(array_column($splits, 'amount'));

        $pdo->prepare('DELETE FROM transaction_allocations WHERE transaction_id = ?')->execute([$transactionId]);
        $ins = $pdo->prepare(
            'INSERT INTO transaction_allocations
             (property_id, transaction_id, module, master_code, period_key,
              allocation_start, allocation_end, allocated_days, amount, capacity_days, pic_name)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
        $n = 0;
        foreach ($gabung as $g) {
            foreach (self::pecahPerPic($g, $splits, $totalSplit) as $b) {
                $ins->execute([
                    $g['property_id'], $transactionId, $g['module'], $g['master_code'],
                    $g['period_key'], $g['allocation_start'], $g['allocation_end'],
                    $b['allocated_days'], $b['amount'], $b['capacity_days'],
                    // Baris gabungan membawa pic_name lama; saat tidak dibagi,
                    // yang menentukan adalah PIC transaksi.
                    $splits ? ($b['pic_name'] ?? $g['pic_name']) : ($picUtama ?: $g['pic_name']),
                ]);
                $n++;
            }
        }
        return $n;
    }
    /**
     * Jadwal harga bertahap milik satu kontrak (kosong = harga tunggal).
     * Dibaca dari price_steps; diurutkan menurut tanggal berlakunya.
     */
    public static function priceSteps(PDO $pdo, ?int $transactionId = null, ?int $offerId = null): array
    {
        if (!$transactionId && !$offerId) return [];
        try {
            if ($transactionId) {
                $st = $pdo->prepare('SELECT effective_from, monthly_amount, label FROM price_steps WHERE transaction_id = ? ORDER BY effective_from, id');
                $st->execute([$transactionId]);
            } else {
                $st = $pdo->prepare('SELECT effective_from, monthly_amount, label FROM price_steps WHERE offer_id = ? AND transaction_id IS NULL ORDER BY effective_from, id');
                $st->execute([$offerId]);
            }
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];                       // tabel belum ada → perilaku lama
        }
        $out = [];
        foreach ($rows as $r) {
            if ((float) $r['monthly_amount'] <= 0 || !$r['effective_from']) continue;
            $out[] = [
                'from'   => (string) $r['effective_from'],
                'amount' => (float) $r['monthly_amount'],
                'label'  => (string) ($r['label'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Nilai satu siklus menurut jadwal: tahap yang berlaku adalah tahap dengan
     * tanggal mulai TERAKHIR yang tidak melewati awal siklus. Siklus sebelum
     * tahap pertama memakai tahap pertama.
     */
    private static function nilaiTahap(array $steps, string $mulaiSiklus): ?float
    {
        if (!$steps) return null;
        $pakai = null;
        foreach ($steps as $s) {
            if ($s['from'] <= $mulaiSiklus) $pakai = $s;
        }
        if ($pakai === null) $pakai = $steps[0];
        return (float) $pakai['amount'];
    }

    /**
     * Terapkan jadwal harga ke daftar siklus bulanan: tiap siklus memakai
     * nominal tahap yang berlaku, menggantikan pembagian rata.
     */
    public static function terapkanTahap(array $allocations, array $steps): array
    {
        if (!$steps) return $allocations;
        foreach ($allocations as &$a) {
            $n = self::nilaiTahap($steps, (string) $a['allocation_start']);
            if ($n !== null) $a['amount'] = round($n);
        }
        unset($a);
        return $allocations;
    }

    /**
     * Ratakan selisih antara nilai kontrak dan jumlah jadwal harga ke tiap
     * siklus. Dipakai untuk biaya yang ditagih bulanan tapi tidak ikut jadwal
     * (biaya listrik), supaya jumlah alokasi selalu sama dengan nilai kontrak.
     * Selisih nol / nilai kontrak kosong → daftar alokasi dikembalikan apa adanya.
     */
    private static function sebarSisaKontrak(array $allocations, float $finalAmount): array
    {
        $n = count($allocations);
        if ($n === 0 || $finalAmount <= 0) return $allocations;
        $sisa = round($finalAmount) - round(array_sum(array_column($allocations, 'amount')));
        if (abs($sisa) < 1) return $allocations;
        $perBulan = floor(abs($sisa) / $n) * ($sisa < 0 ? -1 : 1);
        $terpakai = 0.0;
        foreach ($allocations as $i => &$a) {
            $tambah = $i === $n - 1 ? $sisa - $terpakai : $perBulan;
            $terpakai += $tambah;
            $a['amount'] = round((float) $a['amount'] + $tambah);
        }
        unset($a);
        return $allocations;
    }

    /**
     * Total nilai kontrak bila memakai jadwal harga — dipakai agar
     * transactions.final_amount tetap sama dengan jumlah alokasinya.
     */
    public static function totalDariTahap(array $trx, array $steps): float
    {
        if (!$steps) return 0.0;
        $alok = self::monthlyCycleAllocations(
            new DateTimeImmutable($trx['start_date']),
            new DateTimeImmutable($trx['end_date']),
            0.0,
            $trx['cycle_recognition'] ?? 'cycle_start'
        );
        return (float) array_sum(array_column(self::terapkanTahap($alok, $steps), 'amount'));
    }

    /**
     * Terapkan jadwal harga HANYA pada bulan yang belum lewat.
     *
     * Dipakai saat harga kontrak berjalan dinaikkan: baris alokasi bulan yang
     * sudah ditutup tidak boleh berubah, jadi fungsi ini meng-UPDATE per baris
     * (bukan menghapus-dan-menulis-ulang seperti saveAllocations). Mengembalikan
     * jumlah baris yang berubah dan total kontrak yang baru.
     */
    public static function terapkanTahapKeDepan(PDO $pdo, int $transactionId, string $mulai): array
    {
        $steps = self::priceSteps($pdo, $transactionId);
        if (!$steps || $transactionId <= 0) return ['diubah' => 0, 'total' => 0.0];

        $st = $pdo->prepare('SELECT id, period_key, allocation_start, amount, pic_name FROM transaction_allocations WHERE transaction_id = ? ORDER BY allocation_start, id');
        $st->execute([$transactionId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) return ['diubah' => 0, 'total' => 0.0];

        // Baris bisa terpecah per PIC (pembagian income) — nilainya dijumlah
        // dulu per siklus, lalu dibagi lagi menurut porsi yang sama.
        $perSiklus = [];
        foreach ($rows as $r) $perSiklus[$r['allocation_start']][] = $r;

        $upd = $pdo->prepare('UPDATE transaction_allocations SET amount = ? WHERE id = ?');
        $diubah = 0; $total = 0.0;
        foreach ($perSiklus as $mulaiSiklus => $baris) {
            $lama = array_sum(array_map(fn($x) => (float) $x['amount'], $baris));
            if ($mulaiSiklus < $mulai) { $total += $lama; continue; }   // bulan lewat: jangan disentuh
            $baru = self::nilaiTahap($steps, (string) $mulaiSiklus);
            if ($baru === null) { $total += $lama; continue; }
            $baru = round($baru);
            $total += $baru;
            if (count($baris) === 1) {
                if ((float) $baris[0]['amount'] !== (float) $baru) { $upd->execute([$baru, $baris[0]['id']]); $diubah++; }
                continue;
            }
            // Beberapa PIC: porsi lama dipertahankan, sisa pembulatan ke baris terakhir.
            $terpakai = 0.0; $n = count($baris);
            foreach ($baris as $i => $b) {
                $porsi = $lama > 0
                    ? ($i === $n - 1 ? round($baru - $terpakai, 2) : round($baru * ((float) $b['amount'] / $lama), 2))
                    : ($i === 0 ? $baru : 0);
                $terpakai += $porsi;
                if ((float) $b['amount'] !== (float) $porsi) { $upd->execute([$porsi, $b['id']]); $diubah++; }
            }
        }
        // Nilai kontrak disamakan dengan jumlah alokasinya.
        $pdo->prepare('UPDATE transactions SET final_amount = ?, override_amount = ? WHERE id = ?')
            ->execute([$total, $total, $transactionId]);
        return ['diubah' => $diubah, 'total' => $total];
    }
    public static function totalCalculated(array $trx): float
    {
        return array_sum(array_column(self::preview($trx), 'amount'));
    }

    private static function monthlyCycles(DateTimeImmutable $start, DateTimeImmutable $end, int $contractMonths, float $cycleAmount): array
    {
        $cycles = [];
        $cursor = $start;
        $limit = $contractMonths > 0 ? $contractMonths : 120;
        $count = 0;

        while ($cursor <= $end && $count < $limit) {
            $nextAnchor = $cursor->modify('+1 month');
            $cycleEnd = $nextAnchor->modify('-1 day');
            if ($cycleEnd > $end) {
                $cycleEnd = $end;
            }

            $days = self::daysInclusive($cursor, $cycleEnd);
            $fullCycleDays = self::daysInclusive($cursor, $nextAnchor->modify('-1 day'));
            $amount = $cycleAmount;

            if ($contractMonths <= 0 && $cycleEnd < $nextAnchor->modify('-1 day')) {
                $amount = $cycleAmount * ($days / $fullCycleDays);
            }

            $cycles[] = [
                'start' => $cursor,
                'end' => $cycleEnd,
                'days' => $days,
                'amount' => $amount,
            ];

            $cursor = $cycleEnd->modify('+1 day');
            $count++;
        }

        return $cycles;
    }

    private static function splitCyclesToCalendarMonths(array $cycles, string $pricingType, float $qty, float $slots, float $area): array
    {
        $allocations = [];
        foreach ($cycles as $cycle) {
            foreach (self::monthSegments($cycle['start'], $cycle['end']) as $segment) {
                $amount = $cycle['amount'] * ($segment['days'] / $cycle['days']);
                $key = $segment['period_key'] . '|' . $segment['start']->format('Y-m-d') . '|' . $segment['end']->format('Y-m-d');
                $allocations[$key] = [
                    'period_key' => $segment['period_key'],
                    'allocation_start' => $segment['start']->format('Y-m-d'),
                    'allocation_end' => $segment['end']->format('Y-m-d'),
                    'allocated_days' => $segment['days'],
                    'amount' => round($amount),
                    'capacity_days' => self::capacityDays($pricingType, $segment['days'], $qty, $slots, $area),
                ];
            }
        }

        return self::adjustRounding(array_values($allocations), array_sum(array_column($cycles, 'amount')));
    }

    private static function monthSegments(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $segments = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $monthEnd = $cursor->modify('last day of this month');
            $segEnd = $monthEnd < $end ? $monthEnd : $end;
            $segments[] = [
                'period_key' => $cursor->format('Y-m'),
                'start' => $cursor,
                'end' => $segEnd,
                'days' => self::daysInclusive($cursor, $segEnd),
            ];
            $cursor = $segEnd->modify('+1 day');
        }
        return $segments;
    }

    private static function daysInclusive(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return ((int) $start->diff($end)->format('%a')) + 1;
    }

    private static function capacityDays(string $pricingType, int $days, float $qty, float $slots, float $area): float
    {
        return match ($pricingType) {
            'daily_slot' => $qty * $slots * $days,
            'daily_area' => max(1.0, $area) * $days,
            'monthly' => $qty * $days,
            default => $qty * $days,
        };
    }

    private static function applyMonthOverrides(array $allocations, array $overrides): array
    {
        foreach ($allocations as &$a) {
            if (isset($overrides[$a['period_key']])) {
                $a['amount'] = (float) $overrides[$a['period_key']];
            }
        }
        unset($a);
        return $allocations;
    }

    private static function applyOverrideAmount(array $allocations, float $finalAmount): array
    {
        $basis = array_sum(array_column($allocations, 'amount'));
        if ($basis <= 0) {
            $basis = array_sum(array_column($allocations, 'allocated_days'));
        }
        $running = 0;
        $lastIndex = count($allocations) - 1;

        foreach ($allocations as $i => &$allocation) {
            if ($i === $lastIndex) {
                $allocation['amount'] = round($finalAmount - $running);
                break;
            }
            $share = $basis > 0 ? ((float) $allocation['amount'] / $basis) : 0;
            if ($share <= 0 && $basis > 0) {
                $share = ((float) $allocation['allocated_days'] / $basis);
            }
            $allocation['amount'] = round($finalAmount * $share);
            $running += $allocation['amount'];
        }

        return $allocations;
    }

    private static function applySpreadAmount(array $allocations, float $finalAmount): array
    {
        $n = count($allocations);
        if ($n === 0) return $allocations;
        $perMonth = floor($finalAmount / $n);
        $running = 0;
        $lastIndex = $n - 1;
        foreach ($allocations as $i => &$a) {
            if ($i === $lastIndex) {
                $a['amount'] = round($finalAmount - $running);
            } else {
                $a['amount'] = (int) $perMonth;
                $running += $a['amount'];
            }
        }
        unset($a);
        return $allocations;
    }

    public static function monthlyCycleAllocations(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        float $finalAmount,
        string $recognition
    ): array {
        $cycles = [];
        $cursor = $start;
        $limit  = 120;
        while ($cursor <= $end && $limit-- > 0) {
            $cycleEnd = $cursor->modify('+1 month')->modify('-1 day');
            if ($cycleEnd > $end) $cycleEnd = $end;
            $days      = self::daysInclusive($cursor, $cycleEnd);
            $periodKey = ($recognition === 'cycle_end' ? $cycleEnd : $cursor)->format('Y-m');
            $cycles[]  = [
                'period_key'       => $periodKey,
                'allocation_start' => $cursor->format('Y-m-d'),
                'allocation_end'   => $cycleEnd->format('Y-m-d'),
                'allocated_days'   => $days,
                'capacity_days'    => $days,
                'amount'           => 0,
            ];
            $cursor = $cycleEnd->modify('+1 day');
        }
        $n = count($cycles);
        if (!$n) return $cycles;
        $perC = (int) floor($finalAmount / $n);
        $running = 0;
        foreach ($cycles as $i => &$c) {
            $c['amount'] = ($i === $n - 1) ? (int) round($finalAmount - $running) : $perC;
            $running    += $c['amount'];
        }
        unset($c);
        return $cycles;
    }

    private static function adjustRounding(array $allocations, float $target): array
    {
        if (!$allocations) {
            return [];
        }
        $sum = array_sum(array_column($allocations, 'amount'));
        $diff = round($target) - $sum;
        $allocations[count($allocations) - 1]['amount'] += $diff;
        return $allocations;
    }
}
