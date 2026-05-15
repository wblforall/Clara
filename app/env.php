<?php

function load_env(): array
{
    static $env = null;
    if ($env !== null) {
        return $env;
    }

    $env = [];
    $path = __DIR__ . '/../.env';
    if (!file_exists($path)) {
        $path = __DIR__ . '/../.env.example';
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        $env[trim($key)] = $value;
    }

    return $env;
}

function env_value(string $key, ?string $default = null): ?string
{
    $env = load_env();
    return $env[$key] ?? getenv($key) ?: $default;
}
