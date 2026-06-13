<?php
// Samakan collation tabel offers ke utf8mb4_unicode_ci (sama dgn tabel lain),
// supaya join string (pic_name/master_code) tidak bentrok collation.

foreach (['offers', 'offer_revisions', 'offer_counters'] as $t) {
    $st = $pdo->query("SHOW TABLE STATUS LIKE '$t'")->fetch();
    if ($st && stripos((string) $st['Collation'], 'unicode') === false) {
        $pdo->exec("ALTER TABLE $t CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
}
