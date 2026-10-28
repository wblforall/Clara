<?php
/**
 * Samakan warna PIC dengan warna yang dipakai tim di spreadsheet mereka.
 *
 * Migrasi 085 memberi warna bawaan hasil tebakan. Setelah dibandingkan dengan
 * berkas Casual Leasing milik tim, warnanya ternyata punya nilai yang pasti —
 * diambil dari kepala kolom MUSI / SITA / ZUL / ICHA di lembar e-Walk dan
 * ALFA / SAGAP / EVAN di lembar Pentacity. Dipakai apa adanya supaya tim tidak
 * perlu menghafal warna baru saat berpindah dari spreadsheet ke CLARA.
 *
 * Warna yang sudah dipilih sendiri lewat Master PIC TIDAK ditimpa: yang diubah
 * hanya yang masih memakai warna bawaan migrasi 085.
 */

$kol = array_column($pdo->query('SHOW COLUMNS FROM `master_pic`')->fetchAll(PDO::FETCH_ASSOC), 'Field');
if (!in_array('color', $kol, true)) { echo "     kolom master_pic.color belum ada — dilewati\n"; return; }

// Nama di spreadsheet ditulis singkat; dicocokkan dengan awalan nama di master.
$warna = [
    'muslimin' => '#B7B7B7',   // MUSI  — abu-abu
    'musi'     => '#B7B7B7',
    'sita'     => '#D5A6BD',   // SITA  — merah muda tua
    'rosita'   => '#D5A6BD',
    'zulfikar' => '#B6D7A8',   // ZUL   — hijau daun
    'zul'      => '#B6D7A8',
    'icha'     => '#E06666',   // ICHA  — merah
    'annisa'   => '#E06666',
    'alfa'     => '#EA9999',   // ALFA  — merah muda (Pentacity)
    'sagap'    => '#B6D7A8',   // SAGAP — hijau daun (Pentacity)
    'evan'     => '#B4A7D6',   // EVAN  — ungu (Pentacity)
];

// Warna bawaan migrasi 085 — hanya nilai-nilai ini yang boleh ditimpa.
$bawaan085 = ['#FBCFE8', '#D8DEE8', '#BBF7D0', '#FECACA', '#BFDBFE', '#DDD6FE',
              '#FED7AA', '#99F6E4', '#C7D2FE', '#F5D0FE', '#BAE6FD', '#D9F99D'];

$rows = $pdo->query('SELECT id, name, color FROM master_pic')->fetchAll(PDO::FETCH_ASSOC);
$set  = $pdo->prepare('UPDATE master_pic SET color = ? WHERE id = ?');
$n = 0; $lewat = 0;
foreach ($rows as $r) {
    $kini = strtoupper(trim((string) ($r['color'] ?? '')));
    if ($kini !== '' && !in_array($kini, $bawaan085, true)) { $lewat++; continue; }   // dipilih sendiri
    $nama = mb_strtolower(trim((string) $r['name']));
    $baru = null;
    foreach ($warna as $kunci => $w) {
        if ($nama === $kunci || str_starts_with($nama, $kunci . ' ')) { $baru = $w; break; }
    }
    if ($baru === null || $kini === strtoupper($baru)) continue;
    $set->execute([$baru, (int) $r['id']]);
    $n++;
}
echo "     $n PIC disamakan warnanya dengan spreadsheet tim\n";
if ($lewat) echo "     $lewat PIC dilewati karena warnanya sudah dipilih sendiri\n";
