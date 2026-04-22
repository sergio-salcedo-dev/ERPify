<?php

declare(strict_types=1);

/*
 * Behat runner that reconciles two vendor trees.
 *
 * api/vendor provides the Symfony 8 kernel; api/tools/behat/vendor provides
 * Behat 3.31 (which still constrains Symfony DI/Config/HttpKernel to ^7.x).
 * We register the app autoload *first* so the Symfony 8 classes win for any
 * shared FQCN — the tools-vendor tree then supplies Behat's own packages.
 */

$behatRoot = __DIR__;
$apiRoot = dirname($behatRoot, 2);

require $apiRoot . '/vendor/autoload.php';
require $behatRoot . '/vendor/autoload.php';

if (is_file($apiRoot . '/.env')) {
    (new Symfony\Component\Dotenv\Dotenv())->bootEnv($apiRoot . '/.env');
}

require $behatRoot . '/vendor/behat/behat/bin/behat';
