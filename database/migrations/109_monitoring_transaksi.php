<?php
/**
 * Monitoring Transaksi — satu tabel jejak tindak lanjut, satu izin baru.
 *
 * Yang TIDAK dibuat di sini: kolom status perjalanan. Tahap tiap dokumen
 * dihitung ulang dari keadaan dokumen, permintaan kontrak, invoice, dan
 * pembayaran setiap kali halaman dibuka — kolom status akan basi persis pada
 * hari pertama ada orang mengubah data lewat jalur lain.
 *
 * Yang dibuat: catatan "siapa sudah mengejar apa, kapan". Tanpa itu, daftar
 * macet hanya jadi pengingat yang dilihat lalu ditinggalkan — tidak ada bukti
 * siapa sudah berusaha dan siapa belum, dan Asisten Manager tetap harus
 * bertanya satu-satu.
 */

$pdo->exec("CREATE TABLE IF NOT EXISTS monitoring_tindak (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    property_id  TINYINT UNSIGNED NOT NULL,
    skp_id       INT UNSIGNED NOT NULL,
    tahap        VARCHAR(30) NULL,
    jenis        VARCHAR(20) NOT NULL,
    note         VARCHAR(500) NULL,
    tunda_sampai DATE NULL,
    created_by   VARCHAR(120) NULL,
    created_at   DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_mon_doc (property_id, skp_id, id),
    KEY idx_mon_jenis (property_id, jenis, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "     tabel monitoring_tindak siap\n";

// Izinnya terpisah dari view_transactions: yang perlu melihat sampai mana satu
// berkas berjalan belum tentu boleh membuka seluruh daftar transaksi dan
// angkanya, dan sebaliknya.
//
// PIC, Admin Sales, dan Asisten Manager diminta langsung oleh pemilik CLARA.
// Supervisor & Finance ikut supaya tidak ada yang harus menunggu dicentangkan
// izinnya hanya untuk membaca daftar baca-saja. Viewer sengaja tidak.
$peran = ['sales', 'administrasi', 'asst_manager', 'supervisor', 'finance'];
$ins = $pdo->prepare("INSERT IGNORE INTO role_permissions (role, permission) VALUES (?, 'view_monitoring')");
$n = 0;
foreach ($peran as $p) { $ins->execute([$p]); $n += $ins->rowCount(); }
echo "     $n peran dapat izin Monitoring Transaksi (" . implode(', ', $peran) . ")\n";
