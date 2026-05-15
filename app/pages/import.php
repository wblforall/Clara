<?php
declare(strict_types=1);

function import_media(PDO $pdo): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $file = $_FILES['csv'] ?? $_FILES['xlsx'] ?? null;
        if (!$file || !is_uploaded_file($file['tmp_name'])) {
            flash('File belum dipilih.');
            redirect_to('import_media');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext === 'xlsx') {
            // Handle as template
            handle_excel_template_import($pdo, $file['tmp_name'], $file['name']);
            redirect_to('dashboard');
        }

        $fh = fopen($file['tmp_name'], 'r');
        $headers = array_map('trim', fgetcsv($fh) ?: []);
        $count = 0;
        while (($row = fgetcsv($fh)) !== false) {
            if (count($headers) !== count($row)) {
                flash('Format baris tidak valid atau jumlah kolom tidak sesuai pada baris ke-' . ($count + 1));
                redirect_to('import_media');
            }
            $data = array_combine($headers, $row);
            if (!$data || empty($data['code'])) {
                continue;
            }
            $pid = current_property_id();
            $sql = 'INSERT INTO master_media (property_id, code, media_type, location, point, size, quantity, slots, rate, pricing_type, package_note, projection_monthly, status, updated_at)
                    VALUES (:property_id, :code, :media_type, :location, :point, :size, :quantity, :slots, :rate, :pricing_type, :package_note, :projection_monthly, :status, CURRENT_TIMESTAMP)
                    ON DUPLICATE KEY UPDATE
                    media_type=VALUES(media_type), location=VALUES(location), point=VALUES(point), size=VALUES(size),
                    quantity=VALUES(quantity), slots=VALUES(slots), rate=VALUES(rate), pricing_type=VALUES(pricing_type),
                    package_note=VALUES(package_note), projection_monthly=VALUES(projection_monthly), status=VALUES(status), updated_at=CURRENT_TIMESTAMP';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':property_id'       => $pid,
                ':code'              => $data['code'],
                ':media_type'        => $data['media_type'] ?? '',
                ':location'          => $data['location'] ?? '',
                ':point'             => $data['point'] ?? '',
                ':size'              => $data['size'] ?? '',
                ':quantity'          => $data['quantity'] ?? 1,
                ':slots'             => $data['slots'] ?? 1,
                ':rate'              => $data['rate'] ?? 0,
                ':pricing_type'      => $data['pricing_type'] ?? 'daily_point',
                ':package_note'      => $data['package_note'] ?? '',
                ':projection_monthly'=> $data['projection_monthly'] ?? 0,
                ':status'            => $data['status'] ?? 'active',
            ]);
            $count++;
        }
        audit($pdo, 'import_csv', 'master_media', null, ['rows' => $count]);
        flash("$count baris media berhasil diimport/update.");
        redirect_to('master', ['type' => 'media']);
    }

    layout('Import Master Media / Template', function () {
        ?>
        <form class="panel" method="post" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <p class="muted">Anda dapat mengunggah file **CSV** (khusus Media) atau file **Excel Template** (untuk update seluruh master data).</p>
            <p><label>File (CSV / XLSX)</label><input type="file" name="csv" accept=".csv,.xlsx" required></p>
            <button type="submit">Import</button>
        </form>
        <?php
    });
}

function import_template(PDO $pdo): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        if (!is_uploaded_file($_FILES['xlsx']['tmp_name'] ?? '')) {
            flash('File Excel belum dipilih.');
            redirect_to('import_template');
        }
        handle_excel_template_import($pdo, $_FILES['xlsx']['tmp_name'], $_FILES['xlsx']['name'] ?? '');
        redirect_to('dashboard');
    }

    layout('Import Master Template (Excel)', function () {
        ?>
        <form class="panel" method="post" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <p>Gunakan fitur ini untuk memperbarui seluruh data master (Media, Exhibition, Gudang, PIC) melalui file Excel Template.</p>
            <p><label>File Excel (.xlsx)</label><input type="file" name="xlsx" accept=".xlsx" required></p>
            <button type="submit">Upload & Proses Template</button>
        </form>
        <?php
    });
}

function handle_excel_template_import(PDO $pdo, string $uploadedTmpPath, string $originalName = ''): void
{
    $ext = $originalName ? strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) : 'xlsx';

    if ($ext === 'csv') {
        handle_csv_template_import($pdo, $uploadedTmpPath);
        return;
    }

    $tmpFile = APP_ROOT . '/tmp/' . bin2hex(random_bytes(8)) . '.xlsx';
    if (!is_dir(dirname($tmpFile))) {
        mkdir(dirname($tmpFile), 0775, true);
    }
    copy($uploadedTmpPath, $tmpFile);

    $nodeScript = APP_ROOT . '/app/scripts/template_parser.js';
    $nodePath = 'node';
    // Cek path umum di Windows jika 'node' tidak di PATH
    if (!shell_exec('node -v') && file_exists('C:\Program Files\nodejs\node.exe')) {
        $nodePath = '"C:\Program Files\nodejs\node.exe"';
    }
    $command = "$nodePath " . escapeshellarg($nodeScript) . " " . escapeshellarg($tmpFile);
    $output = shell_exec($command);
    @unlink($tmpFile);

    if (!$output) {
        flash('Gagal memproses file Excel. Pastikan Node.js terinstal. Tips: Gunakan format CSV jika tidak ingin menginstal Node.js.');
        return;
    }

    $data = json_decode($output, true);
    if (!$data) {
        flash('Gagal membaca data dari output parser.');
        return;
    }

    process_template_data($pdo, $data);
}

function handle_csv_template_import(PDO $pdo, string $csvPath): void
{
    $fh = fopen($csvPath, 'r');
    $result = [
        'media' => [],
        'cl_units' => [],
        'gudang' => [],
        'pic' => [],
        'target' => []
    ];

    $currentSection = '';
    $rowNum = 0;

    while (($row = fgetcsv($fh)) !== false) {
        $rowNum++;
        $firstCell = trim((string)($row[0] ?? ''));
        $secondCell = trim((string)($row[1] ?? ''));

        // Detect Sections
        if (str_contains($firstCell, 'OCCUPANCY PER UNIT')) {
            $currentSection = 'cl_units';
            continue;
        }
        if (str_contains($firstCell, 'MEDIA PROMO')) {
            $currentSection = 'media';
            continue;
        }
        if (str_contains($firstCell, 'GUDANG / STORAGE')) {
            $currentSection = 'gudang';
            continue;
        }
        if (str_contains($firstCell, 'TRACING DEALING')) {
            $currentSection = 'pic';
            continue;
        }

        if ($currentSection === 'cl_units') {
            if (preg_match('/^\d+$/', $firstCell) && str_contains($secondCell, '-')) {
                $result['cl_units'][] = [
                    'code' => $secondCell,
                    'floor' => trim((string)($row[2] ?? '')),
                    'location_name' => trim((string)($row[3] ?? '')),
                    'unit_type' => trim((string)($row[4] ?? '')),
                    'area_sqm' => (float)str_replace(',', '', (string)($row[5] ?? '0')),
                    'projection_monthly' => (float)str_replace(',', '', (string)($row[6] ?? '0')),
                    'status' => 'active'
                ];
            }
        } elseif ($currentSection === 'media') {
            if (preg_match('/^\d+$/', $firstCell) && str_starts_with($secondCell, 'Medi-')) {
                $result['media'][] = [
                    'code' => $secondCell,
                    'media_type' => trim((string)($row[2] ?? '')),
                    'location' => trim((string)($row[3] ?? '')),
                    'point' => '',
                    'size' => trim((string)($row[4] ?? '')),
                    'quantity' => (int)($row[5] ?? 1),
                    'slots' => 1,
                    'rate' => 0,
                    'pricing_type' => 'daily_point',
                    'package_note' => '',
                    'projection_monthly' => (float)str_replace(',', '', (string)($row[6] ?? '0')),
                    'status' => 'active'
                ];
            }
        } elseif ($currentSection === 'gudang') {
            if (preg_match('/^\d+$/', $firstCell) && str_starts_with($secondCell, 'Guda-')) {
                $result['gudang'][] = [
                    'code' => $secondCell,
                    'location' => trim((string)($row[2] ?? '')),
                    'name' => trim((string)($row[3] ?? '')),
                    'area_sqm' => (float)str_replace(',', '', (string)($row[4] ?? '0')),
                    'monthly_rate' => (float)str_replace(',', '', (string)($row[5] ?? '0')),
                    'projection_monthly' => (float)str_replace(',', '', (string)($row[6] ?? '0')),
                    'status' => 'active'
                ];
            }
        } elseif ($currentSection === 'pic') {
            if (preg_match('/^\d+$/', $secondCell) && trim((string)($row[2] ?? '')) !== '') {
                $result['pic'][] = [
                    'name' => trim((string)($row[2] ?? '')),
                    'role_name' => trim((string)($row[8] ?? '')),
                    'target_share' => (float)str_replace(['%', ','], '', (string)($row[9] ?? '0')) / 100,
                    'status' => 'active'
                ];
            }
        }
    }
    fclose($fh);

    process_template_data($pdo, $result);
}

function process_template_data(PDO $pdo, array $data): void
{
    global $config;
    $isMysql = ($config['db_connection'] === 'mysql');

    $pdo->beginTransaction();
    try {
        // Import Media
        $pid = current_property_id();

        // Add property_id to each row
        $data['media']    = array_map(fn($r) => array_merge([':property_id' => $pid], $r), $data['media'] ?? []);
        $data['cl_units'] = array_map(fn($r) => array_merge([':property_id' => $pid], $r), $data['cl_units'] ?? []);
        $data['gudang']   = array_map(fn($r) => array_merge([':property_id' => $pid], $r), $data['gudang'] ?? []);
        $data['pic']      = array_map(fn($r) => array_merge([':property_id' => $pid], $r), $data['pic'] ?? []);

        $sql = 'INSERT INTO master_media (property_id, code, media_type, location, point, size, quantity, slots, rate, pricing_type, package_note, projection_monthly, status, updated_at)
                VALUES (:property_id, :code, :media_type, :location, :point, :size, :quantity, :slots, :rate, :pricing_type, :package_note, :projection_monthly, :status, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE media_type=VALUES(media_type), location=VALUES(location), point=VALUES(point), size=VALUES(size), quantity=VALUES(quantity), slots=VALUES(slots), rate=VALUES(rate), pricing_type=VALUES(pricing_type), projection_monthly=VALUES(projection_monthly), status=VALUES(status), updated_at=CURRENT_TIMESTAMP';
        $mediaStmt = $pdo->prepare($sql);
        foreach ($data['media'] as $m) { $mediaStmt->execute($m); }

        // Import CL Units
        $sql = 'INSERT INTO master_cl_units (property_id, code, floor, location_name, unit_type, area_sqm, rate, projection_monthly, status, updated_at)
                VALUES (:property_id, :code, :floor, :location_name, :unit_type, :area_sqm, 0, :projection_monthly, :status, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE floor=VALUES(floor), location_name=VALUES(location_name), unit_type=VALUES(unit_type), area_sqm=VALUES(area_sqm), projection_monthly=VALUES(projection_monthly), status=VALUES(status), updated_at=CURRENT_TIMESTAMP';
        $clStmt = $pdo->prepare($sql);
        foreach ($data['cl_units'] as $c) { $clStmt->execute($c); }

        // Import Gudang
        $sql = 'INSERT INTO master_gudang (property_id, code, location, name, area_sqm, monthly_rate, projection_monthly, status, updated_at)
                VALUES (:property_id, :code, :location, :name, :area_sqm, :monthly_rate, :projection_monthly, :status, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE location=VALUES(location), name=VALUES(name), area_sqm=VALUES(area_sqm), monthly_rate=VALUES(monthly_rate), projection_monthly=VALUES(projection_monthly), status=VALUES(status), updated_at=CURRENT_TIMESTAMP';
        $gudangStmt = $pdo->prepare($sql);
        foreach ($data['gudang'] as $g) { $gudangStmt->execute($g); }

        // Import PIC
        $sql = 'INSERT INTO master_pic (property_id, name, role_name, target_share, status, updated_at)
                VALUES (:property_id, :name, :role_name, :target_share, :status, CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE role_name=VALUES(role_name), target_share=VALUES(target_share), updated_at=CURRENT_TIMESTAMP';
        $picStmt = $pdo->prepare($sql);
        foreach ($data['pic'] as $p) { $picStmt->execute($p); }

        // Import Target
        if (isset($data['target']) && is_array($data['target'])) {
            $data['target'] = array_map(fn($r) => array_merge([':property_id' => $pid], $r), $data['target']);
            $sql = 'INSERT INTO targets_monthly (property_id, period_key, target_amount) VALUES (:property_id, :period_key, :target_amount)
                    ON DUPLICATE KEY UPDATE target_amount=VALUES(target_amount)';
            // Replacing original sql assignment below (was conditional mysql/sqlite, now mysql-only)
            $targetStmt = $pdo->prepare($sql);
            foreach ($data['target'] as $t) { $targetStmt->execute($t); }
        }

        $pdo->commit();
        $countTotal = count($data['media']) + count($data['cl_units']) + count($data['gudang']) + count($data['pic']) + (isset($data['target']) ? count($data['target']) : 0);
        flash("Import template berhasil. $countTotal data diperbarui.");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('Error saat simpan ke database: ' . $e->getMessage());
    }
}
