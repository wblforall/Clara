<?php
// Isi khusus per jenis dokumen disimpan di skp_documents.detail_json:
//   sks (Gudang) → baris tabel harga sewa (lokasi, luas, harga/m²/bln, harga/bln,
//                  total, bullet keterangan) + identitas tambahan (nama toko, dll)
//   fu  (Media)  → daftar centang Utilities / Media Promo / Parkir beserta lokasi
//                  & biaya tiap baris, plus rincian biaya & terbilang
// Dokumen Exhibition (skp) tidak memakai kolom ini.
//
// Tidak ada tabel baru: isinya memang satu kesatuan dengan dokumennya dan ikut
// terkunci saat approve lewat snapshot_json.

$cols = array_column($pdo->query('SHOW COLUMNS FROM skp_documents')->fetchAll(), 'Field');

if (!in_array('detail_json', $cols, true)) {
    $pdo->exec('ALTER TABLE skp_documents ADD COLUMN detail_json MEDIUMTEXT NULL AFTER note');
}

// doc_type kini mengenal 'fu' (Form Utilities) di samping 'skp' dan 'sks'.
// Dokumen media lama terlanjur tersimpan sebagai 'sks' → dipindah supaya
// cetakannya memakai format Form Utilities.
$pdo->exec(
    "UPDATE skp_documents s
        JOIN transactions t ON t.id = s.transaction_id
        SET s.doc_type = 'fu'
      WHERE s.doc_type = 'sks' AND t.module = 'media'"
);
$pdo->exec(
    "UPDATE skp_documents s
        JOIN offers o ON o.id = s.offer_id
        SET s.doc_type = 'fu'
      WHERE s.doc_type = 'sks' AND o.module = 'media'"
);
