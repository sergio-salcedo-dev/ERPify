<?php

declare(strict_types=1);

/*
 * Prints the `cache.rate_limiter` item key holding one actor's declare budget in the DEV application — derived from
 * the dev kernel's secret inside this process, which is never printed — so the live-stack probes can delete their own
 * identity's budget and nobody else's. Usage: php dev-declare-budget-item.php <actor-id>
 */

use Erpify\Documents\Document\Infrastructure\Security\DeclareUploadThrottle;
use Erpify\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__, 4) . '/.env');

$kernel = new Kernel('dev', true);
$kernel->boot();
$secret = $kernel->getContainer()->getParameter('kernel.secret');

if (!is_string($secret) || !isset($argv[1])) {
    exit(1);
}

echo sha1('document_upload_declare_per_actor-' . DeclareUploadThrottle::keyFor($argv[1], $secret));
