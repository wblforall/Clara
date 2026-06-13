<?php
// Pelacakan pipeline penawaran utk analisa performa PIC + deteksi fiktif:
//  - sent_at / nego_at  : stempel waktu engagement nyata (penawaran dikirim/nego)
//  - lost_category      : alasan terstruktur saat penawaran ditutup (tidak deal)
//  - closed_by          : siapa yang menutup
// status_note (sudah ada) dipakai utk catatan bebas alasan tutup.
// cancelled_at (sudah ada) = waktu tutup. Lihat [[project-offer-pipeline]].

$cols = array_column($pdo->query('SHOW COLUMNS FROM offers')->fetchAll(), 'Field');

if (!in_array('sent_at', $cols, true)) {
    $pdo->exec("ALTER TABLE offers ADD COLUMN sent_at DATETIME NULL AFTER status");
}
if (!in_array('nego_at', $cols, true)) {
    $pdo->exec("ALTER TABLE offers ADD COLUMN nego_at DATETIME NULL AFTER sent_at");
}
if (!in_array('lost_category', $cols, true)) {
    $pdo->exec("ALTER TABLE offers ADD COLUMN lost_category VARCHAR(50) NULL AFTER cancelled_at");
}
if (!in_array('closed_by', $cols, true)) {
    $pdo->exec("ALTER TABLE offers ADD COLUMN closed_by VARCHAR(120) NULL AFTER lost_category");
}
