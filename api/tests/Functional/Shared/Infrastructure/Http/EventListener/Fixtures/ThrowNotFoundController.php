<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures;

use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\NotFound;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test-only controller that throws a `DomainException` implementing the `NotFound` marker
 * to exercise the `ExceptionResponder` listener through a real Symfony kernel.
 *
 * Wired by `api/config/services_test.yaml` and routed by `api/config/routes/test.yaml`
 * (loaded only when `APP_ENV=test`).
 */
final class ThrowNotFoundController
{
    public function __invoke(): Response
    {
        throw new class ('', 'Bank not found', ['bank_id' => '01JABC']) extends DomainException implements NotFound {
        };
    }
}
