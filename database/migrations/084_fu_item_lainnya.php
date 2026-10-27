<?php
/**
 * Tambahkan item "Lainnya" pada daftar Utilities di Form Utilities (Media).
 *
 * Daftar Media Promo sudah punya baris "Lainnya" sejak awal, sehingga apa pun
 * yang tidak tercantum masih bisa ditagihkan. Daftar Utilities tidak — isinya
 * terkunci pada enam item (Listrik, Sound & Lighting, LED Screen, Stage,
 * Kebersihan, Air). Begitu client memakai utilitas di luar keenam itu, tidak
 * ada tempat menuliskannya di formulir maupun di dokumen yang tercetak.
 *
 * Migrasi ini menyisipkan "Lainnya" di urutan terakhir daftar Utilities pada
 * setiap Template Dokumen modul Media. Templatenya tetap bisa diubah sendiri
 * lewat Surat Penawaran → Template, jadi urutan atau namanya boleh disesuaikan
 * belakangan.
 *
 * Yang SENGAJA tidak disentuh: `skp_documents.detail_json`. Isi dokumen adalah
 * salinan beku — dokumen yang sudah terbit tidak boleh berubah isinya. Dokumen
 * yang masih draft menyusul sendiri saat dibuka, karena formulirnya menyamakan
 * daftar itemnya dengan templatenya (skp_fu_sinkron_tpl).
 */

if (!$pdo->query("SHOW TABLES LIKE 'offer_templates'")->fetchColumn()) {
    echo "     tabel offer_templates belum ada — dilewati\n";
    return;
}

$rows = $pdo->query("SELECT id, extra_json FROM offer_templates WHERE module = 'media'")
            ->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) { echo "     belum ada template modul media\n"; return; }

$simpan = $pdo->prepare('UPDATE offer_templates SET extra_json = ? WHERE id = ?');
$n = 0;

foreach ($rows as $r) {
    $extra = json_decode((string) $r['extra_json'], true);
    if (!is_array($extra)) $extra = [];
    $util = array_values(array_filter(
        array_map(fn($x) => trim((string) $x), (array) ($extra['utilities'] ?? [])),
        fn($x) => $x !== ''
    ));
    foreach ($util as $x) {
        if (strcasecmp($x, 'Lainnya') === 0) continue 2;   // sudah ada
    }
    $util[] = 'Lainnya';
    $extra['utilities'] = $util;
    $simpan->execute([json_encode($extra, JSON_UNESCAPED_UNICODE), (int) $r['id']]);
    $n++;
}

echo $n > 0
    ? "     $n template media: 'Lainnya' ditambahkan ke daftar Utilities\n"
    : "     semua template media sudah punya item 'Lainnya'\n";
