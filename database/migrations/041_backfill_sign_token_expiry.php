<?php
// Temuan code-review: migrasi 040 hanya menambah kolom; token yang SUDAH terbit
// sebelum 040 punya sign_token_expires_at = NULL → sign_token_expired(NULL)=false
// sehingga link lama TAK PERNAH kedaluwarsa (H3 belum tertutup retroaktif).
// Backfill: beri masa berlaku 30 hari DARI SEKARANG untuk token lama yang belum
// punya kedaluwarsa — link in-flight tidak langsung putus, tapi tetap akan
// kedaluwarsa. Hanya menyentuh baris BELUM ditandatangani (yang sudah TTD tidak
// terpengaruh karena gate kedaluwarsa hanya berlaku saat belum TTD). Idempoten:
// baris yang sudah punya expiry (terbit pasca-040) tidak diubah.

$pdo->exec(
    "UPDATE offers
        SET sign_token_expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY)
      WHERE sign_token IS NOT NULL
        AND sign_token_expires_at IS NULL
        AND signed_at IS NULL
        AND status <> 'cancelled'"
);

$pdo->exec(
    "UPDATE skp_documents
        SET sign_token_expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY)
      WHERE sign_token IS NOT NULL
        AND sign_token_expires_at IS NULL
        AND status = 'approved'"
);
