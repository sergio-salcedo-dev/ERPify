<?php

declare(strict_types=1);

use Erpify\Tests\Support\Database\RefuseRuntimeDatabaseGuard;
use Symfony\Component\Dotenv\Dotenv;

/**
 * CLI entry point for the same guard api/tools/phpunit/bootstrap.php calls.
 *
 * It exists because `db.test.prepare` is a prerequisite of the PHPUnit targets, so make runs it BEFORE
 * `bin/phpunit` loads that bootstrap — and the target's second command is `doctrine:migrations:migrate`. With
 * the suffix broken, a feature branch's new migration would land on the runtime database and only then would
 * the suite refuse. This runs first instead.
 */
$apiRoot = dirname(__DIR__, 2);

require_once $apiRoot . '/vendor/autoload.php';

if (class_exists(Dotenv::class) && is_file($apiRoot . '/.env')) {
    (new Dotenv())->bootEnv($apiRoot . '/.env');
}

RefuseRuntimeDatabaseGuard::refuseUnlessTestDatabase(
    $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null,
    $apiRoot . '/config/packages/test/doctrine.yaml',
    $_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? null,
);
