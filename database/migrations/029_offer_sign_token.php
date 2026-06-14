<?php
// Token verifikasi untuk Surat Penawaran → QR "Scan untuk validasi" pada TTD
// sales di PDF (samakan dgn SKP). Lihat [[project-offer-pipeline]].

$cols = array_column($pdo->query('SHOW COLUMNS FROM offers')->fetchAll(), 'Field');
if (!in_array('sign_token', $cols, true)) {
    $pdo->exec("ALTER TABLE offers ADD COLUMN sign_token VARCHAR(64) NULL");
}
