<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

/**
 * Test-only controller throwing a plain `\UnexpectedValueException` (an SPL
 * `\RuntimeException` subclass) to exercise the listener's non-`DomainException`
 * fallback (factory-side: 500 / `unhandled-exception`).
 */
final class ThrowRuntimeController
{
    public function __invoke(): Response
    {
        throw new UnexpectedValueException('boom');
    }
}
