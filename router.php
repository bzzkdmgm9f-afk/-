<?php
declare(strict_types=1);

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (str_contains($path, '..') || preg_match('#/(?:src|config|database|tools|runtime)(?:/|$)#', $path)) {
    http_response_code(404);
    exit;
}
// SVG is served only from bundled assets; uploaded files never use that type.
if (preg_match('#\A/assets/[A-Za-z0-9_-]+\.svg\z#', $path) || preg_match('#\A/(?:assets|uploads)/[A-Za-z0-9_./-]+\.(?:css|js|png|jpe?g|webp|woff2|ico)\z#i', $path)) {
    $file = realpath(__DIR__ . $path);
    if ($file && str_starts_with($file, __DIR__ . '/') && is_file($file)) {
        return false;
    }
    http_response_code(404);
    exit;
}
if (!in_array($path, ['/', '/index.php'], true)) {
    http_response_code(404);
    exit;
}
require __DIR__ . '/index.php';
