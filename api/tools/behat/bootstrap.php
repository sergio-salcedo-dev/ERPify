<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

$toolRoot = __DIR__;
$apiRoot = dirname($toolRoot, 2);

require $toolRoot . '/vendor/autoload.php';

// Do not load api/vendor/autoload.php here: Symfony 8 from the app shadows Behat 3's
// Symfony 7 components and breaks interface compatibility (e.g. CompilerPassInterface).

if (is_file($apiRoot . '/.env') && class_exists(Dotenv::class) && method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv($apiRoot . '/.env');
}

if (false === getenv('MINK_BASE_URL') || '' === getenv('MINK_BASE_URL')) {
    $fromEnv = $_SERVER['MINK_BASE_URL'] ?? $_ENV['MINK_BASE_URL'] ?? null;

    if (is_string($fromEnv) && '' !== $fromEnv) {
        putenv('MINK_BASE_URL=' . $fromEnv);
    } else {
        // Local Symfony CLI / PHP built-in server
        putenv('MINK_BASE_URL=http://127.0.0.1:8000');
    }
}
