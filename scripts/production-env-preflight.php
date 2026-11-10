<?php

declare(strict_types=1);

/**
 * Production environment preflight.
 *
 * Usage: php scripts/production-env-preflight.php /absolute/path/to/.env
 * Reads values only; never prints secret values and never modifies the file.
 */

$requiredExtensions = [
    'ctype',
    'curl',
    'dom',
    'fileinfo',
    'mbstring',
    'openssl',
    'pdo',
    'pdo_mysql',
    'tokenizer',
    'xml',
];
$missingExtensions = array_values(array_filter(
    $requiredExtensions,
    static fn (string $extension): bool => ! extension_loaded($extension)
));

fwrite(STDOUT, 'PHP runtime: '.PHP_VERSION.'; SAPI: '.PHP_SAPI."\n");
if ($missingExtensions !== []) {
    fwrite(STDERR, 'FAIL: required PHP extensions are not loaded: '.implode(', ', $missingExtensions)."\n");
}

$envPath = $argv[1] ?? dirname(__DIR__).'/.env';
if (!is_file($envPath) || !is_readable($envPath)) {
    fwrite(STDERR, "FAIL: environment file is missing or unreadable.\n");
    exit(2);
}

$values = [];
foreach (file($envPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    if (!preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $matches)) {
        continue;
    }
    $value = trim($matches[2]);
    if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
        $value = substr($value, 1, -1);
    }
    $values[$matches[1]] = $value;
}

$failures = $missingExtensions === [] ? [] : ['Required PHP extensions are missing from the PHP runtime executing this script'];
$require = static function (string $key) use (&$values, &$failures): string {
    $value = trim((string) ($values[$key] ?? ''));
    if ($value === '' || str_contains(strtoupper($value), 'CHANGE-ME')) {
        $failures[] = "{$key} is missing or still a template placeholder";
    }
    return $value;
};

if ($require('APP_ENV') !== 'production') $failures[] = 'APP_ENV must equal production';
if (strtolower($require('APP_DEBUG')) !== 'false') $failures[] = 'APP_DEBUG must be false';
if (!str_starts_with($require('APP_URL'), 'https://api.')) $failures[] = 'APP_URL must be the HTTPS API origin';
if ($require('DB_CONNECTION') !== 'mysql') $failures[] = 'DB_CONNECTION must be mysql';
$require('DB_HOST');
$require('DB_DATABASE');
$require('DB_USERNAME');
$require('DB_PASSWORD');
$require('MAIL_HOST');
$require('MAIL_USERNAME');
$require('MAIL_PASSWORD');
$require('MAIL_FROM_ADDRESS');

$key = $require('APP_KEY');
if (str_starts_with($key, 'base64:')) {
    $decoded = base64_decode(substr($key, 7), true);
    if ($decoded === false || strlen($decoded) !== 32) $failures[] = 'APP_KEY must decode to exactly 32 bytes';
} else {
    $failures[] = 'APP_KEY must use Laravel base64: format';
}

foreach ([
    'SESSION_ENCRYPT' => 'true',
    'SESSION_SECURE_COOKIE' => 'true',
    'SESSION_HTTP_ONLY' => 'true',
    'SESSION_SAME_SITE' => 'lax',
    'SESSION_DOMAIN' => '.maalearna.com.et',
] as $key => $expected) {
    if (strtolower($require($key)) !== strtolower($expected)) {
        $failures[] = "{$key} must be configured as {$expected}";
    }
}

$hosts = array_filter(array_map('trim', explode(',', $require('SANCTUM_STATEFUL_DOMAINS'))));
$requiredHosts = ['app.maalearna.com.et', 'portal.maalearna.com.et', 'admin.maalearna.com.et'];
foreach ($requiredHosts as $host) {
    if (!in_array($host, $hosts, true)) $failures[] = "SANCTUM_STATEFUL_DOMAINS is missing {$host}";
}
foreach ($hosts as $host) {
    if (str_contains($host, '://') || str_contains($host, '/') || str_contains($host, '*')) {
        $failures[] = 'SANCTUM_STATEFUL_DOMAINS must contain exact hostnames only';
        break;
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Production environment preflight: FAIL\n");
    foreach (array_unique($failures) as $failure) fwrite(STDERR, "- {$failure}\n");
    exit(1);
}

fwrite(STDOUT, "Production environment preflight: PASS\n");
fwrite(STDOUT, "Validated the executing PHP runtime's required extensions, production mode, HTTPS API URL, non-debug mode, MySQL configuration, Laravel key format, secure session cookie controls, and required SPA stateful hosts. Secret values were not displayed.\n");
fwrite(STDOUT, "NOTE: This reports the PHP runtime executing the script (normally CLI); confirm it matches the domain's PHP-FPM runtime in Plesk.\n");
