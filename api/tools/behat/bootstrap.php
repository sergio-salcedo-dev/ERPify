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

// Force APP_ENV=test before Dotenv::bootEnv so .env.test (which points
// DATABASE_URL at the dedicated test DB) is resolved instead of dev's.
$_ENV['APP_ENV'] = 'test';
$_SERVER['APP_ENV'] = 'test';
putenv('APP_ENV=test');

require $apiRoot . '/vendor/autoload.php';

if (class_exists(Dotenv::class) && is_file($apiRoot . '/.env')) {
    $dotenv = new Dotenv();
    $dotenv->bootEnv($apiRoot . '/.env');

    // bootEnv populates immutably, so values already set by .env (like
    // DATABASE_URL) won't be overridden by .env.test. Re-load .env.test
    // with override semantics so behat always points at the test DB.
    if (is_file($apiRoot . '/.env.test')) {
        $dotenv->overload($apiRoot . '/.env.test');
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
