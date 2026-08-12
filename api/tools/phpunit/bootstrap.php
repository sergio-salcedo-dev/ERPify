<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

$apiRoot = dirname(__DIR__, 2);

require_once $apiRoot . '/vendor/autoload.php';

// Signals Erpify\Kernel to compile this runner's container into its own directory, so a
// `bin/console` invocation under the same env cannot warm the container out from under the
// deprecation gate. Set all three ways because Kernel::getCacheDir() reads it with getenv(),
// which sees neither $_ENV nor $_SERVER on its own — the same reason
// api/tests/Behat/bootstrap.php sets BEHAT_RUNNING three times. Must precede any kernel boot.
$_ENV['PHPUNIT_RUNNING'] = '1';
$_SERVER['PHPUNIT_RUNNING'] = '1';
putenv('PHPUNIT_RUNNING=1');

if (class_exists(Dotenv::class) && is_file($apiRoot . '/.env')) {
    (new Dotenv())->bootEnv($apiRoot . '/.env');
}

if (filter_var($_SERVER['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
    umask(0000);
}
