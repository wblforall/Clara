<?php
// Tanda tangan online customer untuk Surat Penawaran (canvas + metadata).
// Sales kirim link publik (?r=offer_sign&token=sign_token) → customer review &
// tanda tangan → status 'deal' (TTD = penawaran disetujui = DEAL) + snapshot
// dikunci. Pola sama dgn SKP (migrasi 015/016). sign_token sudah ada (029),
// snapshot_json sudah ada (017).

$cols = array_column($pdo->query('SHOW COLUMNS FROM offers')->fetchAll(), 'Field');

$add = [
    'sign_name'      => "VARCHAR(190) NULL",
    'sign_ip'        => "VARCHAR(45) NULL",
    'sign_ua'        => "VARCHAR(255) NULL",
    'signature_data' => "MEDIUMTEXT NULL",
    'signed_at'      => "DATETIME NULL",
    // snapshot nilai yg dikunci saat customer TTD (offers belum punya kolom ini;
    // snapshot_json di migrasi 017 ada di tabel offer_revisions, bukan offers).
    'snapshot_json'  => "MEDIUMTEXT NULL",
];
foreach ($add as $col => $def) {
    if (!in_array($col, $cols, true)) {
        $pdo->exec("ALTER TABLE offers ADD COLUMN $col $def");
    }
}

// index untuk lookup token cepat (sign_token dari migrasi 029)
$idx = $pdo->query("SHOW INDEX FROM offers WHERE Key_name = 'idx_offer_sign_token'")->fetchAll();
if (!$idx) {
    $pdo->exec("ALTER TABLE offers ADD INDEX idx_offer_sign_token (sign_token)");
}
