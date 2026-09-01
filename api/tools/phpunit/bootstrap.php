<?php

declare(strict_types=1);

use Erpify\Tests\Support\Database\RefuseRuntimeDatabaseGuard;
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

// Last thing before the runner starts: refuse a suite pointed at the runtime database. This suite truncates
// and deletes without rolling back, so such a run reports success while consuming a developer's data. It has
// to be here rather than in a PHPUnit extension — both extension hooks were measured reporting the refusal
// and then running the whole suite anyway, while a throwable in a file bootstrap makes PHPUnit abort the run
// before it builds a test. The three inputs are read here, at file scope, so the guard itself stays free of
// superglobals and can be driven from a unit test.
RefuseRuntimeDatabaseGuard::refuseUnlessTestDatabase(
    $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null,
    $apiRoot . '/config/packages/test/doctrine.yaml',
    $_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? null,
);
