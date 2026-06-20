<?php
/**
 * TDD Security Suite — CLARA (lokal/dev).
 * Jalankan: /Applications/XAMPP/xamppfiles/bin/php scripts/security_test.php
 * Red (gagal) → terapkan perbaikan → Green (lulus). Hanya menguji HTTP+file lokal.
 */
$BASE = getenv('BASE') ?: 'http://localhost/clara';
$ROOT = dirname(__DIR__);
$pass = 0; $fail = 0; $fails = [];

function curl_code(string $u): string { return trim((string) shell_exec('curl -s -o /dev/null -w "%{http_code}" ' . escapeshellarg($u))); }
function curl_body(string $u): string { return (string) shell_exec('curl -s ' . escapeshellarg($u)); }
function curl_headers(string $u): string { return (string) shell_exec('curl -s -D - -o /dev/null ' . escapeshellarg($u)); }

function check(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail, $fails;
    if ($cond) { $pass++; echo "  \033[32m✓ PASS\033[0m  $name\n"; }
    else { $fail++; $fails[] = $name; echo "  \033[31m✗ FAIL\033[0m  $name" . ($detail ? "  → $detail" : '') . "\n"; }
}

echo "=== TDD Security Suite — $BASE ===\n\n";

echo "[1] KRITIS: .git tidak boleh terekspos via HTTP\n";
$c = curl_code("$BASE/.git/config"); check(".git/config diblok (bukan 200)", $c !== '200', "HTTP $c");
$c = curl_code("$BASE/.git/HEAD");   check(".git/HEAD diblok (bukan 200)",   $c !== '200', "HTTP $c");

echo "[regresi] .env & dump .sql tetap diblok\n";
check(".env diblok (403/404)",            in_array(curl_code("$BASE/.env"), ['403','404'], true));
check("clara_unified.sql diblok (403/404)", in_array(curl_code("$BASE/clara_unified.sql"), ['403','404'], true));

echo "[2] KRITIS: cookie sesi di-harden\n";
$h = curl_headers("$BASE/public/?r=login");
preg_match('/^set-cookie:[^\r\n]*CLARA=[^\r\n]*/im', $h, $m);
$sc = $m[0] ?? '';
check("Set-Cookie CLARA ada", $sc !== '');
check("cookie HttpOnly", stripos($sc, 'HttpOnly') !== false, $sc ?: '(tak ada cookie)');
check("cookie SameSite", stripos($sc, 'SameSite') !== false, $sc ?: '(tak ada cookie)');

echo "[3] TINGGI: folder upload tidak mengeksekusi PHP\n";
$probe = "$ROOT/public/uploads/skp/__sectest.php";
@file_put_contents($probe, '<?php echo "EXEC_OK_" . (7 * 6);');
$body = curl_body("$BASE/public/uploads/skp/__sectest.php");
check("upload .php tidak tereksekusi", strpos($body, 'EXEC_OK_42') === false, 'body=' . substr(trim($body), 0, 40));
@unlink($probe);

echo "[4] SEDANG: header keamanan\n";
$h = curl_headers("$BASE/public/?r=login");
check("Content-Security-Policy", stripos($h, 'content-security-policy') !== false);
check("Referrer-Policy",         stripos($h, 'referrer-policy') !== false);
check("Permissions-Policy",      stripos($h, 'permissions-policy') !== false);
check("X-Frame-Options (regresi)",        stripos($h, 'x-frame-options') !== false);
check("X-Content-Type-Options (regresi)", stripos($h, 'x-content-type-options') !== false);

echo "[5] SEDANG: aturan redirect HTTPS ada di .htaccess\n";
$ht = (string) @file_get_contents("$ROOT/.htaccess");
check("redirect HTTPS di .htaccess", (bool) preg_match('/RewriteCond\s+%\{HTTPS\}\s+off/i', $ht));

echo "[6] RENDAH: CSRF pakai hash_equals\n";
$hp = (string) @file_get_contents("$ROOT/app/helpers.php");
check("verify_csrf pakai hash_equals", strpos($hp, 'hash_equals') !== false);

echo "\n=== HASIL: $pass lulus, $fail gagal ===\n";
if ($fail) { echo "MASIH MERAH: " . implode(', ', $fails) . "\n"; exit(1); }
echo "SEMUA HIJAU 🟢\n"; exit(0);
