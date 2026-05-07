<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures;

use Doctrine\DBAL\Driver\AbstractException as DriverAbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Symfony\Component\HttpFoundation\Response;

/**
 * Story 3.5 (NFR17) — test-only controller throwing a {@see ConnectionLost} mid-controller so
 * the listener pipeline can assert that a Doctrine driver-side connection failure still produces
 * a conforming RFC 9457 Problem Details 500 response — never a cascading failure.
 *
 * `ConnectionLost` extends `Doctrine\DBAL\Exception\ConnectionException` whose constructor
 * (inherited from `DriverException`) requires a `Driver\Exception` plus a nullable `Query`. We
 * synthesise a minimal anonymous-class driver exception via {@see DriverAbstractException} so
 * the fixture has zero runtime dependency on a live PDO connection.
 *
 * Wired by `api/config/services_test.yaml` (Fixtures resource glob) and routed by
 * `api/config/routes/test.yaml` (loaded only when `APP_ENV=test`).
 */
final class ThrowDoctrineConnectionLostController
{
    public function __invoke(): Response
    {
        $driverException = new class ('underlying connection lost', '08006', 0) extends DriverAbstractException {
        };

        throw new ConnectionLost($driverException, null);
    }
}
