<?php
/** Router that mirrors nginx try_files behaviour for isolated HTTP tests. */

declare(strict_types=1);

$root = dirname(__DIR__);
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$decodedPath = is_string($path) ? rawurldecode($path) : '';
if ($decodedPath !== '' && $decodedPath !== '/' && !str_contains($decodedPath, "\0")) {
    $candidate = realpath($root . '/' . ltrim($decodedPath, '/'));
    if ($candidate !== false
        && str_starts_with($candidate, $root . '/')
        && is_file($candidate)) {
        return false;
    }
}

require $root . '/index.php';
