<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\ErrorContract\Infrastructure\Http\EventListener\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Test-only controller that throws an `HttpException` with an unmapped status (410 Gone) —
 * exercises the factory's `'http-error'` fallback for statuses not in `HTTP_STATUS_TYPE_MAP`.
 */
final class ThrowHttpGoneController
{
    public function __invoke(): Response
    {
        throw new HttpException(410, 'Resource gone.');
    }
}
