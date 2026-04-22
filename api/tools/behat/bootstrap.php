<?php

declare(strict_types=1);

use PHPUnit\Util\Exporter;
use SebastianBergmann\Exporter\Exporter as SebastianExporter;
use Symfony\Component\Dotenv\Dotenv;

$apiRoot = dirname(__DIR__, 2);

require $apiRoot . '/vendor/autoload.php';

if (class_exists(Dotenv::class) && is_file($apiRoot . '/.env')) {
    (new Dotenv())->bootEnv($apiRoot . '/.env');
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
