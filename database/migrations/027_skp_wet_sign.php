<?php
// TTD basah (wet signature) untuk customer gaptek: SKP dicetak, ditandatangani
// manual di atas kertas, lalu sales unggah scan dokumen → status 'signed'.
// Alternatif dari TTD online (canvas). Lihat [[project-offer-pipeline]].

$cols = array_column($pdo->query('SHOW COLUMNS FROM skp_documents')->fetchAll(), 'Field');
if (!in_array('sign_method', $cols, true)) {
    // 'online' = TTD canvas; 'wet' = scan dokumen TTD basah.
    $pdo->exec("ALTER TABLE skp_documents ADD COLUMN sign_method VARCHAR(10) NOT NULL DEFAULT 'online'");
}
if (!in_array('signed_doc_path', $cols, true)) {
    $pdo->exec("ALTER TABLE skp_documents ADD COLUMN signed_doc_path VARCHAR(255) NULL");
}
