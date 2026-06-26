<?php
/**
 * CLARA — Schema Migration Runner
 * Run: php db_migrate.php
 */

declare(strict_types=1);

// KEAMANAN: skrip maintenance — HANYA boleh dijalankan dari command line.
// Tanpa guard ini, file fisik di web root bisa dipanggil via HTTP (rewrite
// ke public/ tidak menangkap file -f yang nyata) sehingga siapa pun dapat
// memicu DDL ke database produksi tanpa autentikasi (temuan pentest H1).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('CLARA_ROOT', __DIR__);
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/env.php';

$pdo = Database::connect();

// Buat tabel tracker kalau belum ada
$pdo->exec("
    CREATE TABLE IF NOT EXISTS schema_migrations (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        migration   VARCHAR(255) NOT NULL UNIQUE,
        ran_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Ambil migration yang sudah jalan
$ran = $pdo->query("SELECT migration FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
$ran = array_flip($ran);

// Scan folder migrations
$files = glob(__DIR__ . '/database/migrations/*.php');
sort($files);

$count = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (isset($ran[$name])) {
        echo "  skip  $name\n";
        continue;
    }

    echo "  run   $name ... ";
    try {
        require $file;
        $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?)")->execute([$name]);
        echo "OK\n";
        $count++;
    } catch (Throwable $e) {
        echo "GAGAL\n";
        echo "  ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
}

if ($count === 0) {
    echo "Tidak ada migration baru.\n";
} else {
    echo "\n$count migration berhasil dijalankan.\n";
}
