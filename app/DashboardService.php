<?php

final class DashboardService
{
    public static function data(PDO $pdo, string $period): array
    {
        $pid = current_property_id();

        $periodRowStmt = $pdo->prepare('SELECT * FROM periods WHERE period_key = ? AND property_id = ?');
        $periodRowStmt->execute([$period, $pid]);
        $periodRow = $periodRowStmt->fetch() ?: [
            'period_key' => $period,
            'label' => period_label($period),
            'starts_on' => $period . '-01',
            'ends_on' => (new DateTimeImmutable($period . '-01'))->modify('last day of this month')->format('Y-m-d'),
        ];

        $targetStmt = $pdo->prepare("SELECT target_amount FROM targets_monthly WHERE period_key = ? AND property_id = ?");
        $targetStmt->execute([$period, $pid]);
        $target = (float) ($targetStmt->fetchColumn() ?: 0);
        $projClStmt = $pdo->prepare("SELECT COALESCE(SUM(projection_monthly),0) FROM master_cl_units WHERE status='active' AND property_id = ?");
        $projClStmt->execute([$pid]);
        $projMediaStmt = $pdo->prepare("SELECT COALESCE(SUM(projection_monthly),0) FROM master_media WHERE status='active' AND property_id = ?");
        $projMediaStmt->execute([$pid]);
        $projGudangStmt = $pdo->prepare("SELECT COALESCE(SUM(projection_monthly),0) FROM master_gudang WHERE status='active' AND property_id = ?");
        $projGudangStmt->execute([$pid]);
        $projection = [
            'cl'     => (float) $projClStmt->fetchColumn(),
            'media'  => (float) $projMediaStmt->fetchColumn(),
            'gudang' => (float) $projGudangStmt->fetchColumn(),
        ];

        $actual = ['cl' => 0.0, 'media' => 0.0, 'gudang' => 0.0];
        $capacity = ['cl' => 0.0, 'media' => 0.0, 'gudang' => 0.0];
        $actualStmt = $pdo->prepare(
            'SELECT module, COALESCE(SUM(amount),0) actual, COALESCE(SUM(capacity_days),0) capacity_days
             FROM transaction_allocations
             WHERE period_key = ? AND property_id = ?
             GROUP BY module'
        );
        $actualStmt->execute([$period, $pid]);
        foreach ($actualStmt->fetchAll() as $row) {
            $actual[$row['module']] = (float) $row['actual'];
            $capacity[$row['module']] = (float) $row['capacity_days'];
        }

        $totalProjection = array_sum($projection);
        $totalActual = array_sum($actual);
        $gapTarget = $totalActual - $target;

        $picStmt = $pdo->prepare(
            "SELECT COALESCE(a.pic_name,'Tanpa PIC') pic_name,
                    COALESCE(p.role_name, '-') role_name,
                    COALESCE(p.target_share, 0) target_share,
                    COALESCE(SUM(a.amount),0) actual
             FROM transaction_allocations a
             LEFT JOIN master_pic p ON p.name = a.pic_name AND p.status = 'active' AND p.property_id = ?
             WHERE a.period_key=? AND a.property_id=?
             GROUP BY COALESCE(a.pic_name,'Tanpa PIC'), p.role_name, p.target_share
             ORDER BY actual DESC
             LIMIT 8"
        );
        $picStmt->execute([$pid, $period, $pid]);

        $latestStmt = $pdo->prepare(
            "SELECT t.id, t.module, t.master_code, c.company_name, t.start_date, t.end_date, t.final_amount, t.pic_name, t.created_at
             FROM transactions t
             LEFT JOIN master_clients c ON c.id = t.client_id
             WHERE t.property_id = ? AND EXISTS (
                SELECT 1 FROM transaction_allocations a
                WHERE a.transaction_id=t.id AND a.period_key=? AND a.property_id=?
             )
             ORDER BY t.created_at DESC, t.id DESC
             LIMIT 8"
        );
        $latestStmt->execute([$pid, $period, $pid]);

        $segmentRows = [];
        foreach (['cl' => 'Exhibition', 'media' => 'Media Promo & Wall Sign', 'gudang' => 'Gudang / Storage'] as $key => $label) {
            $segmentRows[] = [
                'key' => $key,
                'label' => $label,
                'projection' => $projection[$key],
                'actual' => $actual[$key],
                'achievement' => $projection[$key] > 0 ? $actual[$key] / $projection[$key] : 0,
                'capacity_days' => $capacity[$key],
            ];
        }

        return [
            'period' => $periodRow,
            'target' => $target,
            'projection' => $projection,
            'actual' => $actual,
            'capacity' => $capacity,
            'total_projection' => $totalProjection,
            'total_actual' => $totalActual,
            'achievement_projection' => $totalProjection > 0 ? $totalActual / $totalProjection : 0,
            'achievement_target' => $target > 0 ? $totalActual / $target : 0,
            'gap_target' => $gapTarget,
            'segments' => $segmentRows,
            'pics' => $picStmt->fetchAll(),
            'latest_transactions' => $latestStmt->fetchAll(),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    public static function jsonReady(array $data): array
    {
        $moneyFields = ['target', 'total_projection', 'total_actual', 'gap_target'];
        foreach ($moneyFields as $field) {
            $data[$field . '_formatted'] = money($data[$field]);
        }
        $data['achievement_projection_formatted'] = pct($data['achievement_projection']);
        $data['achievement_target_formatted'] = pct($data['achievement_target']);
        foreach ($data['segments'] as &$segment) {
            $segment['projection_formatted'] = money($segment['projection']);
            $segment['actual_formatted'] = money($segment['actual']);
            $segment['achievement_formatted'] = pct($segment['achievement']);
        }
        foreach ($data['pics'] as &$pic) {
            $pic['actual_formatted'] = money($pic['actual']);
            $targetPosisi = (float)($pic['target_share'] ?? 0) * $data['target'];
            $pic['target_posisi'] = $targetPosisi;
            $pic['target_posisi_formatted'] = money($targetPosisi);
            $pic['achievement'] = $targetPosisi > 0 ? (float)$pic['actual'] / $targetPosisi : 0;
            $pic['achievement_formatted'] = pct($pic['achievement']);
        }
        foreach ($data['latest_transactions'] as &$trx) {
            $trx['final_amount_formatted'] = money($trx['final_amount']);
        }
        return $data;
    }
}
