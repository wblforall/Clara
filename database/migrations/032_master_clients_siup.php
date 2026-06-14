<?php
// No. SIUP penanggung jawab / perusahaan — disimpan ke master_clients (reusable
// auto-prefill SKP berikutnya, pola sama dgn ktp/npwp). Lihat [[project-offer-pipeline]].

$cols = array_column($pdo->query('SHOW COLUMNS FROM master_clients')->fetchAll(), 'Field');
if (!in_array('siup', $cols, true)) {
    $pdo->exec("ALTER TABLE master_clients ADD COLUMN siup VARCHAR(100) NULL");
}
