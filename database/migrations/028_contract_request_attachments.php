<?php
// Lampiran Akta Pendirian & Surat Kuasa pada Formulir Permintaan Kontrak, +
// token share agar bisa dikirim ke Legal lewat link (tanpa login).
// Lihat [[project-offer-pipeline]].

$cols = array_column($pdo->query('SHOW COLUMNS FROM contract_requests')->fetchAll(), 'Field');
if (!in_array('akta_path', $cols, true)) {
    $pdo->exec("ALTER TABLE contract_requests ADD COLUMN akta_path VARCHAR(255) NULL");
}
if (!in_array('surat_kuasa_path', $cols, true)) {
    $pdo->exec("ALTER TABLE contract_requests ADD COLUMN surat_kuasa_path VARCHAR(255) NULL");
}
if (!in_array('share_token', $cols, true)) {
    $pdo->exec("ALTER TABLE contract_requests ADD COLUMN share_token VARCHAR(64) NULL");
}
