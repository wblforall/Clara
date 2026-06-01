<?php
declare(strict_types=1);

function daily_occupancy_page(PDO $pdo): void
{
    $pid  = current_property_id();
    $date = getv('date', date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    $floorOrder = "CASE m.floor WHEN 'LG' THEN 1 WHEN 'GF' THEN 2 WHEN 'UG' THEN 3 WHEN 'FF' THEN 4 WHEN 'SF' THEN 5 ELSE 6 END";

    // CL: ringkasan per lantai
    $s = $pdo->prepare(
        "SELECT m.floor,
                COUNT(*) total,
                COUNT(occ.master_code) occupied
         FROM master_cl_units m
         LEFT JOIN (
             SELECT DISTINCT master_code
             FROM transactions
             WHERE property_id=? AND module='cl' AND deleted_at IS NULL
               AND start_date<=? AND end_date>=?
         ) occ ON occ.master_code=m.code
         WHERE m.property_id=? AND m.status='active'
         GROUP BY m.floor
         ORDER BY $floorOrder"
    );
    $s->execute([$pid, $date, $date, $pid]);
    $clFloors = $s->fetchAll();

    // CL: detail unit yang occupied
    $s = $pdo->prepare(
        "SELECT m.code, m.floor, m.location_name, m.area_sqm,
                t.id trx_id, t.start_date, t.end_date,
                COALESCE(c.company_name, '-') company_name,
                COALESCE(t.pic_name, '-') pic_name,
                t.final_amount
         FROM transactions t
         JOIN master_cl_units m ON m.code=t.master_code AND m.property_id=t.property_id
         LEFT JOIN master_clients c ON c.id=t.client_id
         WHERE t.property_id=? AND t.module='cl' AND t.deleted_at IS NULL
           AND t.start_date<=? AND t.end_date>=?
         ORDER BY $floorOrder, m.code"
    );
    $s->execute([$pid, $date, $date]);
    $clDetail = $s->fetchAll();

    // Media: ringkasan per tipe
    $s = $pdo->prepare(
        "SELECT m.media_type,
                COUNT(*) total,
                COUNT(occ.master_code) occupied
         FROM master_media m
         LEFT JOIN (
             SELECT DISTINCT master_code
             FROM transactions
             WHERE property_id=? AND module='media' AND deleted_at IS NULL
               AND start_date<=? AND end_date>=?
         ) occ ON occ.master_code=m.code
         WHERE m.property_id=? AND m.status='active'
         GROUP BY m.media_type
         ORDER BY m.media_type"
    );
    $s->execute([$pid, $date, $date, $pid]);
    $mediaTypes = $s->fetchAll();

    // Media: detail unit yang occupied
    $s = $pdo->prepare(
        "SELECT m.code, m.media_type, m.location, m.size,
                t.id trx_id, t.start_date, t.end_date,
                COALESCE(c.company_name, '-') company_name,
                COALESCE(t.pic_name, '-') pic_name,
                t.final_amount
         FROM transactions t
         JOIN master_media m ON m.code=t.master_code AND m.property_id=t.property_id
         LEFT JOIN master_clients c ON c.id=t.client_id
         WHERE t.property_id=? AND t.module='media' AND t.deleted_at IS NULL
           AND t.start_date<=? AND t.end_date>=?
         ORDER BY m.media_type, m.code"
    );
    $s->execute([$pid, $date, $date]);
    $mediaDetail = $s->fetchAll();

    $clTotal     = array_sum(array_column($clFloors,   'total'));
    $clOccupied  = array_sum(array_column($clFloors,   'occupied'));
    $medTotal    = array_sum(array_column($mediaTypes, 'total'));
    $medOccupied = array_sum(array_column($mediaTypes, 'occupied'));

    $clByFloor = [];
    foreach ($clDetail as $r)  { $clByFloor[$r['floor']][]      = $r; }
    $medByType = [];
    foreach ($mediaDetail as $r){ $medByType[$r['media_type']][] = $r; }

    layout('Occupancy Harian', function () use (
        $date, $clFloors, $mediaTypes, $clByFloor, $medByType,
        $clTotal, $clOccupied, $medTotal, $medOccupied
    ) {
        ?>
        <!-- DATE PICKER -->
        <form method="get" style="display:flex;align-items:flex-end;gap:10px;margin-bottom:20px;flex-wrap:wrap">
            <input type="hidden" name="r" value="daily_occupancy">
            <div>
                <label style="font-size:12px;color:var(--muted);display:block;margin-bottom:3px">Pilih Tanggal</label>
                <input type="date" name="date" value="<?= h($date) ?>" style="width:180px">
            </div>
            <button type="submit">Lihat</button>
            <?php if ($date !== date('Y-m-d')): ?>
                <a class="btn secondary" href="?r=daily_occupancy">Hari Ini</a>
            <?php endif; ?>
        </form>

        <!-- SUMMARY CARDS -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px">
            <?php
            $cards = [
                ['Exhibition (CL)', $clOccupied,  $clTotal,  '#0D9488'],
                ['Media',           $medOccupied, $medTotal, '#0891B2'],
            ];
            foreach ($cards as [$label, $occ, $tot, $color]):
                $pct = $tot > 0 ? round($occ / $tot * 100) : 0;
            ?>
            <div class="panel" style="padding:16px 20px">
                <div style="font-size:12px;color:var(--muted);margin-bottom:6px"><?= h($label) ?></div>
                <div style="display:flex;align-items:baseline;gap:8px">
                    <span style="font-size:28px;font-weight:700;color:<?= $color ?>"><?= $occ ?></span>
                    <span style="font-size:14px;color:var(--muted)">/ <?= $tot ?> unit</span>
                    <span style="margin-left:auto;font-size:22px;font-weight:700;color:<?= $color ?>"><?= $pct ?>%</span>
                </div>
                <div style="margin-top:8px;height:6px;background:#E2E8F0;border-radius:99px;overflow:hidden">
                    <div style="height:100%;width:<?= $pct ?>%;background:<?= $color ?>;border-radius:99px;transition:width .4s"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- EXHIBITION (CL) -->
        <div class="panel" style="margin-bottom:14px">
            <h2 style="margin-bottom:14px">Exhibition — Per Lantai</h2>
            <?php if (empty($clFloors)): ?>
                <p class="muted">Tidak ada data unit Exhibition.</p>
            <?php else: ?>
            <div class="table-wrap" style="margin-bottom:16px">
                <table style="font-size:13px">
                    <thead>
                        <tr>
                            <th>Lantai</th>
                            <th style="text-align:center">Occupied</th>
                            <th style="text-align:center">Total Unit</th>
                            <th style="text-align:center">Kosong</th>
                            <th style="text-align:right">Occupancy</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    foreach ($clFloors as $row):
                        $pct = $row['total'] > 0 ? round($row['occupied'] / $row['total'] * 100) : 0;
                        $kosong = $row['total'] - $row['occupied'];
                    ?>
                        <tr>
                            <td style="font-weight:600"><?= h($row['floor']) ?></td>
                            <td style="text-align:center;color:#0D9488;font-weight:600"><?= $row['occupied'] ?></td>
                            <td style="text-align:center"><?= $row['total'] ?></td>
                            <td style="text-align:center;color:<?= $kosong > 0 ? '#64748B' : 'var(--muted)' ?>"><?= $kosong ?></td>
                            <td style="text-align:right">
                                <span style="font-weight:700;color:<?= $pct >= 80 ? '#0D9488' : ($pct >= 50 ? '#D97706' : '#EF4444') ?>"><?= $pct ?>%</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <?php $pctTotal = $clTotal > 0 ? round($clOccupied / $clTotal * 100) : 0; ?>
                        <tr style="font-weight:700;border-top:2px solid var(--line)">
                            <td>Total</td>
                            <td style="text-align:center;color:#0D9488"><?= $clOccupied ?></td>
                            <td style="text-align:center"><?= $clTotal ?></td>
                            <td style="text-align:center"><?= $clTotal - $clOccupied ?></td>
                            <td style="text-align:right;color:#0D9488"><?= $pctTotal ?>%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php if (!empty($clByFloor)): ?>
            <div style="font-size:13px;font-weight:600;color:var(--muted);margin-bottom:8px">Detail Unit Terisi</div>
            <?php foreach ($clFloors as $floorRow):
                $floor = $floorRow['floor'];
                if (empty($clByFloor[$floor])) continue;
            ?>
            <div style="margin-bottom:12px">
                <div style="font-size:12px;font-weight:700;color:#0D9488;margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em"><?= h($floor) ?></div>
                <div class="table-wrap" style="margin:0">
                    <table style="font-size:12px">
                        <thead>
                            <tr><th>Kode</th><th>Lokasi</th><th>Luas</th><th>Client</th><th>Durasi Kontrak</th><th>PIC</th><th style="text-align:right">Nilai</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($clByFloor[$floor] as $u): ?>
                            <tr>
                                <td><a href="?r=allocation_detail&id=<?= (int)$u['trx_id'] ?>"><?= h($u['code']) ?></a></td>
                                <td style="color:var(--muted)"><?= h($u['location_name']) ?></td>
                                <td style="white-space:nowrap"><?= h((string)$u['area_sqm']) ?> m²</td>
                                <td><?= h($u['company_name']) ?></td>
                                <td style="white-space:nowrap;color:var(--muted)"><?= h($u['start_date']) ?> s/d <?= h($u['end_date']) ?></td>
                                <td><?= h($u['pic_name']) ?></td>
                                <td style="text-align:right"><?= money($u['final_amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php if (empty($clByFloor)): ?>
                <p class="muted" style="font-size:13px">Tidak ada unit Exhibition yang terisi pada tanggal ini.</p>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- MEDIA -->
        <div class="panel">
            <h2 style="margin-bottom:14px">Media — Per Tipe</h2>
            <?php if (empty($mediaTypes)): ?>
                <p class="muted">Tidak ada data unit Media.</p>
            <?php else: ?>
            <div class="table-wrap" style="margin-bottom:16px">
                <table style="font-size:13px">
                    <thead>
                        <tr>
                            <th>Tipe Media</th>
                            <th style="text-align:center">Occupied</th>
                            <th style="text-align:center">Total Unit</th>
                            <th style="text-align:center">Kosong</th>
                            <th style="text-align:right">Occupancy</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($mediaTypes as $row):
                        $pct    = $row['total'] > 0 ? round($row['occupied'] / $row['total'] * 100) : 0;
                        $kosong = $row['total'] - $row['occupied'];
                    ?>
                        <tr>
                            <td style="font-weight:600"><?= h($row['media_type']) ?></td>
                            <td style="text-align:center;color:#0891B2;font-weight:600"><?= $row['occupied'] ?></td>
                            <td style="text-align:center"><?= $row['total'] ?></td>
                            <td style="text-align:center;color:<?= $kosong > 0 ? '#64748B' : 'var(--muted)' ?>"><?= $kosong ?></td>
                            <td style="text-align:right">
                                <span style="font-weight:700;color:<?= $pct >= 80 ? '#0891B2' : ($pct >= 50 ? '#D97706' : '#EF4444') ?>"><?= $pct ?>%</span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <?php $pctMedTotal = $medTotal > 0 ? round($medOccupied / $medTotal * 100) : 0; ?>
                        <tr style="font-weight:700;border-top:2px solid var(--line)">
                            <td>Total</td>
                            <td style="text-align:center;color:#0891B2"><?= $medOccupied ?></td>
                            <td style="text-align:center"><?= $medTotal ?></td>
                            <td style="text-align:center"><?= $medTotal - $medOccupied ?></td>
                            <td style="text-align:right;color:#0891B2"><?= $pctMedTotal ?>%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php if (!empty($medByType)): ?>
            <div style="font-size:13px;font-weight:600;color:var(--muted);margin-bottom:8px">Detail Unit Terisi</div>
            <?php foreach ($mediaTypes as $typeRow):
                $type = $typeRow['media_type'];
                if (empty($medByType[$type])) continue;
            ?>
            <div style="margin-bottom:12px">
                <div style="font-size:12px;font-weight:700;color:#0891B2;margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em"><?= h($type) ?></div>
                <div class="table-wrap" style="margin:0">
                    <table style="font-size:12px">
                        <thead>
                            <tr><th>Kode</th><th>Lokasi</th><th>Ukuran</th><th>Client</th><th>Durasi Kontrak</th><th>PIC</th><th style="text-align:right">Nilai</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($medByType[$type] as $u): ?>
                            <tr>
                                <td><a href="?r=allocation_detail&id=<?= (int)$u['trx_id'] ?>"><?= h($u['code']) ?></a></td>
                                <td style="color:var(--muted)"><?= h($u['location']) ?></td>
                                <td style="white-space:nowrap"><?= h($u['size'] ?? '-') ?></td>
                                <td><?= h($u['company_name']) ?></td>
                                <td style="white-space:nowrap;color:var(--muted)"><?= h($u['start_date']) ?> s/d <?= h($u['end_date']) ?></td>
                                <td><?= h($u['pic_name']) ?></td>
                                <td style="text-align:right"><?= money($u['final_amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php if (empty($medByType)): ?>
                <p class="muted" style="font-size:13px">Tidak ada unit Media yang terisi pada tanggal ini.</p>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    });
}
