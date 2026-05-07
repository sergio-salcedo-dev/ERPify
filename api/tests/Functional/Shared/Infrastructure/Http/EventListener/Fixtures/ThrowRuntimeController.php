<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test-only controller throwing a plain `\RuntimeException` to exercise the listener's
 * non-`DomainException` fallback (factory-side: 500 / `unhandled-exception`).
 */
final class ThrowRuntimeController
{
    public function __invoke(): Response
    {
        throw new RuntimeException('boom');
    }
}
