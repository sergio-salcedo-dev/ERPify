<?php

declare(strict_types=1);

use PHPUnit\Util\Exporter;
use SebastianBergmann\Exporter\Exporter as SebastianExporter;
use Symfony\Component\Dotenv\Dotenv;

$apiRoot = dirname(__DIR__, 2);

// Signals Erpify\Kernel to import config/services_behat.yaml. Must be set
// before the kernel boots so the container compile sees it.
$_ENV['BEHAT_RUNNING'] = '1';
$_SERVER['BEHAT_RUNNING'] = '1';
putenv('BEHAT_RUNNING=1');

// Force APP_ENV=test before Dotenv::bootEnv so the test-env config — including the
// dbname_suffix that keeps this suite off the runtime database — is the config that loads.
$_ENV['APP_ENV'] = 'test';
$_SERVER['APP_ENV'] = 'test';
putenv('APP_ENV=test');

// Gives this lane a database of its own: `<dbname>_test_behat` against PHPUnit's `<dbname>_test`.
// TEST_TOKEN is the per-process token config/packages/test/doctrine.yaml appends after `_test`, and the
// separation is load-bearing rather than tidy — FixturesContext DROPs and re-clones the database it
// connects to, so sharing one with PHPUnit kills an in-flight `make -j php.test` mid-query, and rows
// PHPUnit left behind would be baked into the backup every feature restores from. Set all three ways for
// the reason BEHAT_RUNNING is: the container compile reads it through getenv(), which sees neither $_ENV
// nor $_SERVER on its own. Must precede any kernel boot.
$_ENV['TEST_TOKEN'] = '_behat';
$_SERVER['TEST_TOKEN'] = '_behat';
putenv('TEST_TOKEN=_behat');

if (is_file($apiRoot . '/.env')) {
    $dotenv = new Dotenv();
    $dotenv->bootEnv($apiRoot . '/.env');

    // bootEnv populates immutably, so a variable compose already exported wins over .env.test and the file
    // binds nothing. Re-load it with override semantics so this lane actually gets the values it declares —
    // MAILER_DSN, RATE_LIMIT_*, DEFAULT_NOTIFICATION_EMAIL and MERCURE_*, each of which compose also exports.
    // The database is NOT among them: it comes from dbname_suffix, which applies to the resolved connection
    // whatever the DSN says. This asymmetry with api/tools/phpunit/bootstrap.php (bootEnv, no overload) is
    // why a DSN in .env.test bound this lane and silently failed to bind that one.
    if (is_file($apiRoot . '/.env.test')) {
        $dotenv->overload($apiRoot . '/.env.test');
    }

    // Per-developer local override for running outside the docker stack (e.g. a host-side Postgres host and
    // port). Loaded LAST, mirroring Symfony's precedence: .env → .env.<env> → .env.<env>.local. A DATABASE_URL
    // here must name the RUNTIME database: the suffix is appended on top, so naming one that already ends in
    // `_test` yields `..._test_test` and a matching orphan backup.
    if (is_file($apiRoot . '/.env.test.local')) {
        $dotenv->overload($apiRoot . '/.env.test.local');
    }
}

// PHPUnit's Util\Exporter lazily calls TextUI\Configuration\Registry::get(),
// which asserts an initialized Configuration instance. Outside of PHPUnit's
// own runner (i.e. when PHPUnit's Assert base class is reached via Behat),
// that assertion crashes the first time a step assertion fails. Pre-seed the
// exporter with a plain SebastianBergmann one so the Registry is never
// touched. Verified against PHPUnit 13.1.7 — revisit when upgrading PHPUnit.
if (class_exists(Exporter::class)) {
    $exporterProperty = new ReflectionProperty(Exporter::class, 'exporter');
    $exporterProperty->setValue(null, new SebastianExporter());
}
