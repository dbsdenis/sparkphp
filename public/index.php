<?php

declare(strict_types=1);

// ─── Base path is one level up from /public ───────────────────────────────
define('SPARK_BASE', dirname(__DIR__));

// When running under `php -S ... public/index.php`, let the built-in server
// serve real files that live inside /public instead of routing them as app URLs.
$uriPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$publicRoot = realpath(SPARK_BASE . '/public');

if ($publicRoot !== false && str_starts_with($uriPath, '/public/')) {
    $requestedFile = realpath(SPARK_BASE . $uriPath);

    if (
        $requestedFile !== false
        && is_file($requestedFile)
        && str_starts_with($requestedFile, $publicRoot . DIRECTORY_SEPARATOR)
    ) {
        return false;
    }
}

// ─── Load Bootstrap ───────────────────────────────────────────────────────
require_once SPARK_BASE . '/core/Bootstrap.php';

$app = new Bootstrap(SPARK_BASE);
$app->boot();
$app->run();
