<?php
// Daftar lantai baku per properti — kategori 'floor' di master_lookup_options.
//
// Sebelumnya Lantai Exhibition adalah dropdown yang tertanam di kode (LG, GF, UG,
// FF, SF) dan Lokasi Gudang adalah teks bebas. Teks bebas melahirkan nilai seperti
// "UG (samping tenant Oh some)" yang memecah laporan occupancy per lokasi menjadi
// baris sendiri. Kini keduanya memilih dari daftar ini, dan daftarnya per properti
// karena Pentacity punya lantai P3–P5 yang tidak dimiliki E-Walk.
//
// Urutan mengikuti gedung dari bawah ke atas, bukan abjad.

$lantai = [
    'ewalk'     => ['LG', 'GF', 'UG', 'FF', 'SF'],
    'pentacity' => ['LG', 'GF', 'UG', 'FF', 'SF', 'P3', 'P4', 'P5'],
];

$ada = $pdo->prepare("SELECT COUNT(*) FROM master_lookup_options WHERE property_id = ? AND category = 'floor' AND value = ?");
$tambah = $pdo->prepare(
    "INSERT INTO master_lookup_options (property_id, category, value, sort_order, status) VALUES (?, 'floor', ?, ?, 'active')"
);
foreach ($pdo->query('SELECT id, `key` FROM properties')->fetchAll() as $p) {
    foreach ($lantai[$p['key']] ?? $lantai['ewalk'] as $i => $nilai) {
        $ada->execute([(int) $p['id'], $nilai]);
        if ((int) $ada->fetchColumn() === 0) {
            $tambah->execute([(int) $p['id'], $nilai, ($i + 1) * 10]);
        }
    }
}
