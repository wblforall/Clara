<?php
// Idempotensi SKP per penawaran: 1 offer hanya boleh punya 1 SKP/SKS.
// Migrasi 021 menambah offer_id sebagai INDEX biasa (bukan UNIQUE), sehingga
// double-submit bisa menghasilkan dua SKP untuk satu penawaran. Di sini kita
// pasang UNIQUE index sebagai pengaman lapisan DB (selain guard app-level di
// skp_save()/skp_form()). MySQL mengizinkan banyak NULL pada UNIQUE index,
// jadi jalur non-offer (transaction-based, offer_id NULL) tidak terpengaruh.

// Aman di-rerun: bila index sudah ada, lewati.
$exists = $pdo->query("SHOW INDEX FROM skp_documents WHERE Key_name = 'uniq_skp_offer'")->fetchAll();
if ($exists) {
    return;
}

// Defensif: data lama mungkin sudah mengandung offer_id duplikat, yang akan
// membuat ALTER ... ADD UNIQUE gagal. Deteksi dulu; bila ada duplikat, HENTIKAN
// migrasi dengan pesan jelas (throw) agar TIDAK tercatat sebagai sudah-jalan.
// Operator membersihkan duplikat lalu menjalankan ulang db_migrate → migrasi ini
// otomatis dicoba lagi dan constraint terpasang. (Kalau hanya error_log+return,
// migrasi tercatat selesai & backstop DB tak pernah terpasang.)
$dups = $pdo->query(
    "SELECT offer_id, COUNT(*) AS c
     FROM skp_documents
     WHERE offer_id IS NOT NULL
     GROUP BY offer_id
     HAVING c > 1"
)->fetchAll();

if ($dups) {
    $ids = array_map(function ($r) {
        return $r['offer_id'] . ' (x' . $r['c'] . ')';
    }, $dups);
    throw new RuntimeException(
        'UNIQUE skp_documents.offer_id tidak bisa dipasang: ada offer_id duplikat → '
        . implode(', ', $ids) . '. Bersihkan/gabungkan SKP duplikat tersebut, '
        . 'lalu jalankan ulang `php db_migrate.php`.'
    );
}

// Tidak ada duplikat → pasang UNIQUE index.
$pdo->exec("ALTER TABLE skp_documents ADD UNIQUE KEY uniq_skp_offer (offer_id)");
