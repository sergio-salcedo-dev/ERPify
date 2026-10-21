<?php

declare(strict_types=1);

use Erpify\Tests\Support\Database\RefuseRuntimeDatabaseGuard;
use Erpify\Tests\Support\PHPUnit\FreezeSystemClockExtension;
use Symfony\Component\Dotenv\Dotenv;

$apiRoot = dirname(__DIR__, 2);

require_once $apiRoot . '/vendor/autoload.php';

// Before anything can run a rate limiter: PHP binds an unqualified `microtime()` call site to whatever it
// resolves on first execution, so a shim declared later is silently bypassed. Why the shim exists at all is
// on Erpify\Tests\Double\Clock\RateLimiterClock.
require_once $apiRoot . '/tests/Double/Clock/rate-limiter-microtime.php';

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

// The clock is pinned here as well as from FreezeSystemClockExtension's two per-test subscribers, because
// three things run OUTSIDE any per-test event and would otherwise read the host wall clock — the source this
// whole harness exists to remove. A data provider resolves while the suite is being BUILT
// (`TestBuilder::build()`, before the runner emits anything), and three providers in this tree construct
// aggregates there, so `AggregateRoot::__construct()` stamped them from the wall clock while the test body
// receiving them ran at the pinned instant. `setUpBeforeClass()` of the first class executed is the same
// window. And an isolated child process (`--process-isolation`, `#[RunInSeparateProcess]`) never registers
// extensions at all: its template calls `Facade::instance()->initForIsolation()`, which builds a dispatcher
// with no subscribers, so the pin would not exist there for the whole test — but the template DOES
// `require_once` this file, which is why the pin belongs here and not only in the extension.
FreezeSystemClockExtension::pin();
