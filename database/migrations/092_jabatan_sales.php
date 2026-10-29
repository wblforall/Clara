<?php
/**
 * Jabatan "Sales" untuk Sita, Shinta, Zulfikar, dan Evan.
 *
 * Surat (SKP / SKS / Form Utilities) dulu menulis mati "Sales Executive" di
 * bawah nama pembuatnya, siapa pun orangnya. Setelah templatnya dibuat membaca
 * Master PIC → kolom Jabatan, yang tercetak adalah jabatan sebenarnya — dan
 * empat orang ini memang bukan Sales Executive.
 *
 * Hanya menyentuh jabatan yang masih bercorak "Sales …" (Sales Executive /
 * Senior / Junior). Bila salah satu dari mereka sudah dinaikkan jabatan di
 * Master PIC (mis. jadi Asst. Manager), jabatannya dibiarkan apa adanya —
 * migrasi tidak berhak menurunkan orang kembali jadi Sales.
 */

$nama = ['Sita', 'Shinta', 'Zulfikar', 'Evan'];

$rows = $pdo->query('SELECT id, name, role_name FROM master_pic')->fetchAll(PDO::FETCH_ASSOC);
$set  = $pdo->prepare('UPDATE master_pic SET role_name = ? WHERE id = ?');
$n = 0; $lewat = 0;
foreach ($rows as $r) {
    $orang = mb_strtolower(trim((string) $r['name']));
    $cocok = false;
    foreach ($nama as $x) {
        $x = mb_strtolower($x);
        // Nama lengkap boleh ("Shinta Koemara"), nama lain yang kebetulan
        // berawalan sama tidak ("Sitanggang").
        if ($orang === $x || str_starts_with($orang, $x . ' ')) { $cocok = true; break; }
    }
    if (!$cocok) continue;
    $jab = trim((string) ($r['role_name'] ?? ''));
    if ($jab === 'Sales') continue;                                  // sudah benar
    if ($jab !== '' && stripos($jab, 'sales') !== 0) { $lewat++; continue; }  // jabatan lain
    $set->execute(['Sales', (int) $r['id']]);
    echo "     {$r['name']}: " . ($jab !== '' ? $jab : '(kosong)') . " → Sales\n";
    $n++;
}
echo "     $n jabatan PIC disetel jadi Sales\n";
if ($lewat) echo "     $lewat dilewati karena jabatannya bukan Sales lagi\n";
