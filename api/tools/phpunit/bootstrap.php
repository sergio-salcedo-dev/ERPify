<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

$apiRoot = dirname(__DIR__, 2);

require $apiRoot . '/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv($apiRoot . '/.env');
}

if (filter_var($_SERVER['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
    umask(0000);
}
