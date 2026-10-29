<?php
/**
 * Nama lengkap untuk surat.
 *
 * Di seluruh CLARA orang dipanggil dengan nama pendeknya — "Sita", "Tengku",
 * "Alfa" — karena nama itulah kunci yang menyambungkan PIC ke transaksi,
 * alokasi, target, dan laporan. Nama pendek itu tidak boleh diubah.
 *
 * Tetapi surat yang keluar ke penyewa (Penawaran, SKP, SKS, Form Utilities)
 * adalah dokumen resmi: yang tercetak di bawah garis tanda tangan harus nama
 * lengkap. Maka namanya disimpan TERPISAH di sini, dan hanya dipakai saat
 * mencetak — tidak satu pun sambungan data yang ikut berubah.
 *
 * Isi awalnya diambil dari nama yang memang sudah lengkap (ada spasinya,
 * mis. "Yusri Yusuf"). Sisanya sengaja dibiarkan kosong: menebak nama lengkap
 * orang dari alamat email bukan tebakan yang pantas ada di surat resmi. Yang
 * masih kosong tetap tercetak memakai nama pendeknya seperti sekarang, sampai
 * diisi lewat Master PIC → Nama Lengkap (atau Pengguna → Nama Lengkap).
 */

$tambah = function (string $tabel) use ($pdo): bool {
    $kol = array_column($pdo->query("SHOW COLUMNS FROM `$tabel`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (in_array('full_name', $kol, true)) { echo "     $tabel.full_name sudah ada — dilewati\n"; return false; }
    $pdo->exec("ALTER TABLE `$tabel` ADD COLUMN full_name VARCHAR(160) NULL AFTER `name`");
    echo "     $tabel.full_name ditambahkan\n";
    return true;
};

$isiAwal = function (string $tabel) use ($pdo): int {
    // Hanya nama yang sudah berbentuk nama lengkap (dua kata atau lebih).
    $st = $pdo->query("SELECT id, name FROM `$tabel`
                        WHERE (full_name IS NULL OR full_name = '')
                          AND TRIM(name) LIKE '% %'");
    $set = $pdo->prepare("UPDATE `$tabel` SET full_name = ? WHERE id = ?");
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $set->execute([trim((string) $r['name']), (int) $r['id']]);
        $n++;
    }
    return $n;
};

foreach (['master_pic', 'users'] as $t) {
    $tambah($t);
    echo "     $t: " . $isiAwal($t) . " baris terisi dari nama yang sudah lengkap\n";
}
