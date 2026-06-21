<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\ErrorContract\Infrastructure\Http\EventListener\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Test-only controller that throws Symfony's `AccessDeniedHttpException` (an
 * `HttpExceptionInterface` subclass with status 403) — exercises the factory's
 * status-keyed mapping path to `type: 'forbidden'`.
 */
final class ThrowHttpForbiddenController
{
    public function __invoke(): Response
    {
        throw new AccessDeniedHttpException('Access denied to bank.');
    }
}
