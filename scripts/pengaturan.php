<?php
/**
 * Lihat / ubah pengaturan aplikasi (tabel settings).
 *
 *   php scripts/pengaturan.php                                  # daftar semua
 *   php scripts/pengaturan.php doc_manager_name                 # lihat satu
 *   php scripts/pengaturan.php doc_manager_name "Tengku" --apply
 *   php scripts/pengaturan.php doc_manager_name "" --apply      # kosongkan
 *
 * Kunci yang dipakai dokumen:
 *   doc_manager_name — nama pada blok "Mengetahui / Menyetujui" di SKP, SKS &
 *                      Form Utilities. Kosong = memakai nama yang menyetujui.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/app/Database.php';
$pdo = Database::connect();

if (!isset($argv[1])) {
    echo "Pengaturan saat ini:\n\n";
    foreach ($pdo->query('SELECT `key`, value FROM settings ORDER BY `key`') as $r) {
        printf("  %-24s = %s\n", $r['key'], $r['value']);
    }
    echo "\nUbah: php scripts/pengaturan.php <kunci> \"<nilai>\" --apply\n";
    exit;
}

$key = trim((string) $argv[1]);
$st  = $pdo->prepare('SELECT value FROM settings WHERE `key` = ? LIMIT 1');
$st->execute([$key]);
$lama = $st->fetchColumn();
$lama = $lama === false ? null : (string) $lama;

if (!array_key_exists(2, $argv)) {
    echo "$key = " . ($lama === null ? '(belum ada)' : '"' . $lama . '"') . "\n";
    exit;
}

$baru  = (string) $argv[2];
$apply = in_array('--apply', $argv, true);
echo "Kunci   : $key\n";
echo "Sekarang: " . ($lama === null ? '(belum ada)' : '"' . $lama . '"') . "\n";
echo "Jadi    : \"$baru\"\n\n";
if (!$apply) { echo "SIMULASI — tidak ada yang diubah. Tambahkan --apply untuk menyimpan.\n"; exit; }

if ($lama === null) {
    $pdo->prepare('INSERT INTO settings (`key`, value) VALUES (?, ?)')->execute([$key, $baru]);
} else {
    $pdo->prepare('UPDATE settings SET value = ? WHERE `key` = ?')->execute([$baru, $key]);
}
$st->execute([$key]);
echo "TERSIMPAN → $key = \"" . (string) $st->fetchColumn() . "\"\n";
