<?php

declare(strict_types=1);

/*
 * Behat runner that reconciles two vendor trees.
 *
 * api/vendor provides the app's Symfony kernel; api/tools/behat/vendor provides Behat. behat/behat
 * caps symfony/console, config, dependency-injection, event-dispatcher, translation, yaml,
 * filesystem and process at ^7.0, so those stay on 7.4 — inside this tree only, which is the whole
 * point of isolating it.
 *
 * Composer resolves a shared FQCN from the autoloader registered *last*, so the tools tree wins
 * every collision regardless of the order below. That makes its symfony/* pins load-bearing: any
 * package Behat does not cap must track the app's minor line, or the suite exercises the app
 * against a different Symfony than the one it ships with.
 */

$behatRoot = __DIR__;
$apiRoot = dirname($behatRoot, 2);

require_once $apiRoot . '/vendor/autoload.php';
require_once $behatRoot . '/vendor/autoload.php';

if (is_file($apiRoot . '/.env')) {
    (new Symfony\Component\Dotenv\Dotenv())->bootEnv($apiRoot . '/.env');
}

require_once $behatRoot . '/vendor/behat/behat/bin/behat';
