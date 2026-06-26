<?php
// Temuan pentest H3: token tanda tangan customer (offers.sign_token &
// skp_documents.sign_token) sebelumnya TIDAK pernah kedaluwarsa, sehingga link
// yang bocor/diteruskan memaparkan PII customer + harga selamanya. Tambah kolom
// masa berlaku, diisi NOW()+30 hari saat token diterbitkan. NULL = legacy
// (tanpa kedaluwarsa, tetap valid agar tidak memutus link lama). Halaman VALIDASI
// (offer_verify / doc_verify) sengaja TIDAK ikut kedaluwarsa — ia bukti dokumen
// yang sudah ditandatangani dan harus tetap bisa diverifikasi. Pola mengikuti
// contract share_token (migrasi 037). Lihat [[project-offer-pipeline]].

$cols = $pdo->query("SHOW COLUMNS FROM offers")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('sign_token_expires_at', $cols, true)) {
    $pdo->exec("ALTER TABLE offers ADD COLUMN sign_token_expires_at DATETIME NULL AFTER sign_token");
}

$cols = $pdo->query("SHOW COLUMNS FROM skp_documents")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('sign_token_expires_at', $cols, true)) {
    $pdo->exec("ALTER TABLE skp_documents ADD COLUMN sign_token_expires_at DATETIME NULL AFTER sign_token");
}
