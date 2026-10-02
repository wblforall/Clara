<?php

/**
 * Koneksi baca-saja untuk mode "Lihat sebagai" (lihat app/ViewAs.php).
 *
 * Penjagaan di routing bergantung pada daftar nama rute, dan daftar semacam itu
 * selalu ketinggalan begitu ada rute baru. Lapis ini tidak: apa pun yang
 * mencoba menulis ditolak di titik paling akhir sebelum menyentuh database.
 *
 * audit_logs sengaja dikecualikan — justru mode ini yang harus meninggalkan
 * jejak siapa melihat sebagai siapa dan kapan.
 */
final class PdoBacaSaja extends PDO
{
    private const TULIS = '/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|DROP|ALTER|CREATE|RENAME|LOAD\s+DATA)\b/i';
    private const IZIN  = '/^\s*INSERT\s+INTO\s+`?audit_logs`?\b/i';

    private function periksa(string $sql): void
    {
        if (preg_match(self::IZIN, $sql))  return;
        if (!preg_match(self::TULIS, $sql)) return;
        throw new ViewAsTulisDitolak('Perintah tulis ditolak dalam mode lihat-sebagai.');
    }

    public function exec(string $statement): int|false
    {
        $this->periksa($statement);
        return parent::exec($statement);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->periksa($query);
        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        $this->periksa($query);
        return $fetchMode === null
            ? parent::query($query)
            : parent::query($query, $fetchMode, ...$args);
    }
}

final class Database
{
    private static ?PDO $pdo = null;
    private static bool $bacaSaja = false;

    /** Dipanggil SEBELUM connect() pertama, saat sesi dalam mode lihat-sebagai. */
    public static function bacaSaja(bool $aktif = true): void
    {
        self::$bacaSaja = $aktif;
    }

    public static function connect(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $config = require __DIR__ . '/config.php';

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['db_host'],
            $config['db_port'],
            $config['db_database']
        );

        $kelas = self::$bacaSaja ? PdoBacaSaja::class : PDO::class;
        self::$pdo = new $kelas($dsn, $config['db_username'], $config['db_password'], [
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT         => true,
        ]);

        return self::$pdo;
    }
}
