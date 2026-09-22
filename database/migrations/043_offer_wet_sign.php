<?php
// Unggah dokumen ber-TTD untuk Surat Penawaran — opsi kedua di samping tautan
// TTD online. Alurnya sama dengan SKP (migrasi 027): surat dikirim/dicetak,
// customer menandatangani, sales mengunggah kembali PDF/scan-nya → penawaran
// langsung DEAL sehingga bisa lanjut ke SKP.
// Lihat [[project-offer-pipeline]].

$cols = array_column($pdo->query('SHOW COLUMNS FROM offers')->fetchAll(), 'Field');

if (!in_array('sign_method', $cols, true)) {
    // 'online' = TTD canvas lewat tautan; 'wet' = dokumen ber-TTD yang diunggah.
    $pdo->exec("ALTER TABLE offers ADD COLUMN sign_method VARCHAR(10) NOT NULL DEFAULT 'online'");
}
if (!in_array('signed_doc_path', $cols, true)) {
    $pdo->exec("ALTER TABLE offers ADD COLUMN signed_doc_path VARCHAR(255) NULL");
}
