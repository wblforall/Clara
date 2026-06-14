<?php
// Legal dapat menyetujui Permintaan Kontrak via link publik (tanpa login).
// status: draft → sent → approved. Lihat [[project-offer-pipeline]].

$cols = array_column($pdo->query('SHOW COLUMNS FROM contract_requests')->fetchAll(), 'Field');
if (!in_array('legal_by', $cols, true)) {
    $pdo->exec("ALTER TABLE contract_requests ADD COLUMN legal_by VARCHAR(120) NULL");
}
if (!in_array('legal_note', $cols, true)) {
    $pdo->exec("ALTER TABLE contract_requests ADD COLUMN legal_note VARCHAR(500) NULL");
}
if (!in_array('legal_approved_at', $cols, true)) {
    $pdo->exec("ALTER TABLE contract_requests ADD COLUMN legal_approved_at DATETIME NULL");
}
