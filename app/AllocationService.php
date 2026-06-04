<?php

final class AllocationService
{
    public static function preview(array $trx): array
    {
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
                    $stmt->execute([
                        $propertyId, $transactionId, $trx['module'], $trx['master_code'],
                        $a['period_key'], $a['allocation_start'], $a['allocation_end'],
                        $a['allocated_days'], $a['amount'], $a['capacity_days'],
                        $trx['pic_name'] ?? null,
                    ]);
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
            $stmt->execute([
                ':property_id'    => $propertyId,
                ':transaction_id' => $transactionId,
                ':module'         => $trx['module'],
                ':master_code'    => $trx['master_code'],
                ':period_key'     => $allocation['period_key'],
                ':allocation_start' => $allocation['allocation_start'],
                ':allocation_end'   => $allocation['allocation_end'],
                ':allocated_days'   => $allocation['allocated_days'],
                ':amount'         => $allocation['amount'],
                ':capacity_days'  => $allocation['capacity_days'],
                ':pic_name'       => $trx['pic_name'] ?? null,
            ]);
        }
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

    private static function monthlyCycleAllocations(
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
