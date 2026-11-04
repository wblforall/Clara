<?php
/**
 * Nomor rekening e-Walk pada Template Dokumen.
 *
 * Sebagian template masih memuat nomor CONTOH "2078-01-999999-56-3" — nomor
 * yang tidak pernah ada. Nomor itu ikut tercetak di Surat Konfirmasi Sewa
 * Gudang yang dikirim ke penyewa, jadi client bisa mentransfer ke rekening
 * yang salah. Sisanya memakai nomor yang benar tetapi dengan penulisan yang
 * berbeda-beda.
 *
 * Migrasi ini menyeragamkan seluruh template e-Walk ke satu penulisan:
 * 2078-01-0005600304. Template Pentacity SENGAJA tidak disentuh — nomornya
 * belum dipastikan, dan menebak nomor rekening jauh lebih berbahaya daripada
 * membiarkannya terlihat salah.
 */

$BARU  = '2078-01-0005600304';
$LAMA  = ['2078-01-999999-56-3', '2078-01-000560-30-4'];

$st = $pdo->query("SELECT id, name, notes_json, bank_json, penutup FROM offer_templates WHERE property_id = 1");
$upd = $pdo->prepare('UPDATE offer_templates SET notes_json = ?, bank_json = ?, penutup = ? WHERE id = ?');
$n = 0;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
    $asal = [$t['notes_json'], $t['bank_json'], $t['penutup']];
    $baru = array_map(
        fn($v) => $v === null ? null : str_replace($LAMA, $BARU, (string) $v),
        $asal
    );
    if ($baru === $asal) continue;
    $upd->execute([$baru[0], $baru[1], $baru[2], $t['id']]);
    echo "     #{$t['id']} {$t['name']} — nomor rekening diseragamkan\n";
    $n++;
}
echo "     $n template e-Walk diperbarui ke $BARU\n";

// Pentacity hanya dilaporkan, tidak diubah.
$sisa = $pdo->query("SELECT id, name FROM offer_templates
                      WHERE property_id = 2
                        AND (notes_json LIKE '%999999%' OR bank_json LIKE '%999999%'
                             OR COALESCE(penutup,'') LIKE '%999999%')")->fetchAll(PDO::FETCH_ASSOC);
foreach ($sisa as $t) {
    echo "     ! Pentacity #{$t['id']} {$t['name']} masih memakai nomor contoh 999999 — perlu nomor yang benar\n";
}
