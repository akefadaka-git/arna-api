<?php

declare(strict_types=1);

/**
 * Temporary, read-only PHP-FPM runtime diagnostic for A5.
 *
 * Deploy this file temporarily as a uniquely named file in the API public/
 * directory. Request it over HTTPS, record the result, then delete it.
 *
 * It does not read .env, access the database, expose paths, or modify state.
 */

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['status' => 'FAIL', 'reason' => 'GET required'], JSON_PRETTY_PRINT);
        exit;
    }
}

$required = [
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

$extensions = [];
$missing = [];

foreach ($required as $extension) {
    $loaded = extension_loaded($extension);
    $extensions[$extension] = $loaded ? 'loaded' : 'missing';
    if (! $loaded) {
        $missing[] = $extension;
    }
}

$result = [
    'status' => $missing === [] ? 'PASS' : 'FAIL',
    'php_version' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'required_extensions' => $extensions,
    'missing_extensions' => $missing,
    'scope' => 'PHP runtime only; no environment secrets, filesystem paths, database checks, or configuration changes',
];

if (PHP_SAPI !== 'cli' && $missing !== []) {
    http_response_code(503);
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
