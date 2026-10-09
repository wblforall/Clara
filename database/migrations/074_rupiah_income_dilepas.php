<?php
/**
 * Catat RUPIAH income yang benar-benar lepas, bukan hanya jumlah barisnya.
 *
 * `alokasi_dilepas` hanya menyimpan berapa baris alokasi yang dihapus — angka
 * yang tidak berarti apa-apa bagi pembaca laporan. Sementara `nilai` adalah
 * nilai KONTRAK dokumennya, yang untuk dokumen draft atau yang belum
 * ditandatangani client tidak pernah masuk laporan sama sekali.
 *
 * Tanpa kolom ini, ringkasan "income yang hilang" terpaksa menjumlahkan nilai
 * kontrak dan melaporkan kerugian yang tidak pernah terjadi.
 */
foreach ([
    'rupiah_dilepas' => "DECIMAL(18,2) NOT NULL DEFAULT 0 COMMENT 'income yang benar-benar lepas saat dihapus'",
    'rupiah_pulih'   => "DECIMAL(18,2) NOT NULL DEFAULT 0 COMMENT 'income yang kembali saat dipulihkan'",
] as $kol => $tipe) {
    $ada = $pdo->query("SHOW COLUMNS FROM `deletion_requests` LIKE '$kol'")->fetchColumn();
    if (!$ada) {
        $pdo->exec("ALTER TABLE `deletion_requests` ADD COLUMN `$kol` $tipe");
        echo "     deletion_requests.$kol ditambahkan\n";
    }
}

/*
 * Baris lama tidak bisa diisi dengan tepat: alokasinya sudah dihapus, jadi
 * jumlah rupiahnya tidak ada lagi di mana pun. Yang bisa dilakukan hanya
 * perkiraan terbaik — dokumen yang punya baris alokasi saat dihapus berarti
 * memang pernah masuk laporan, dan nilai kontraknya dipakai sebagai angkanya.
 * Yang alokasinya nol memang tidak pernah masuk, jadi tetap nol. Ini menebak
 * ke arah yang aman: tidak mengarang kerugian pada dokumen yang jelas-jelas
 * belum pernah dihitung.
 */
$n = $pdo->exec(
    "UPDATE deletion_requests
        SET rupiah_dilepas = nilai
      WHERE rupiah_dilepas = 0 AND alokasi_dilepas > 0 AND status IN ('dihapus', 'dipulihkan')"
);
if ($n) echo "     $n baris lama diisi dari nilai kontraknya (alokasinya memang pernah ada)\n";
