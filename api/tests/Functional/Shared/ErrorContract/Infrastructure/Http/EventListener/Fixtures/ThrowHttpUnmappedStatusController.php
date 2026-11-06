<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\ErrorContract\Infrastructure\Http\EventListener\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Test-only controller that throws an `HttpException` with an unmapped status (418) —
 * exercises the factory's `'http-error'` fallback for statuses not in `HTTP_STATUS_TYPE_MAP`.
 */
final class ThrowHttpUnmappedStatusController
{
    public function __invoke(): Response
    {
        throw new HttpException(418, "I'm a teapot.");
    }
}
