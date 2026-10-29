<?php
/**
 * Sembunyikan harga Utilities / Media Promo / Parkir pada Form Utilities milik
 * Perkumpulan Perusahaan Realestat Indonesia (REI).
 *
 * Permintaan pemilik: untuk penyewa ini yang perlu tercetak hanya fasilitas
 * APA yang dipakai, bukan tarif tiap fasilitasnya. Tarifnya tetap tersimpan di
 * dokumen — saklarnya ada di formulir ("Sembunyikan harga di surat") dan bisa
 * dilepas lagi kapan saja tanpa mengetik ulang.
 *
 * Hanya dokumen yang MASIH bisa diedit (draft / dikembalikan). Dokumen yang
 * sudah disetujui mencetak dari salinan bekunya dan memang tidak boleh berubah.
 */

$st = $pdo->prepare(
    "SELECT s.id, s.detail_json, c.company_name
       FROM skp_documents s
       LEFT JOIN master_clients c ON c.id = s.client_id
      WHERE s.doc_type = 'fu'
        AND s.status IN ('draft', 'rejected')
        AND c.company_name LIKE ?"
);
$st->execute(['%Perkumpulan Perusahaan Realestat%']);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$set = $pdo->prepare('UPDATE skp_documents SET detail_json = ? WHERE id = ?');
$n = 0;
foreach ($rows as $r) {
    $det = json_decode((string) $r['detail_json'], true);
    if (!is_array($det)) continue;
    $det['sembunyi_harga'] = ['utilities' => 1, 'media_promo' => 1, 'parkir' => 1];
    $set->execute([json_encode($det, JSON_UNESCAPED_UNICODE), (int) $r['id']]);
    echo "     dokumen #{$r['id']} ({$r['company_name']}): harga item disembunyikan\n";
    $n++;
}
echo "     $n Form Utilities disetel menyembunyikan harga item\n";
if (!$n) echo "     (tidak ada dokumen yang cocok — abaikan bila penyewanya belum ada di basis data ini)\n";
