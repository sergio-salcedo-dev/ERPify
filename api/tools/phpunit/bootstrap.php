<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

$apiRoot = dirname(__DIR__, 2);

require $apiRoot . '/vendor/autoload.php';

if (class_exists(Dotenv::class) && is_file($apiRoot . '/.env')) {
    (new Dotenv())->bootEnv($apiRoot . '/.env');
}

if (filter_var($_SERVER['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
    umask(0000);
}
