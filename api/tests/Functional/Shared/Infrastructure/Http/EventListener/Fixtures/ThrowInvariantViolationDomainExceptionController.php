<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures;

use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\InvariantViolation;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test-only controller that throws a `DomainException` implementing the `InvariantViolation`
 * marker. Pins the branch-order regression at the integration layer: a marker 422 must
 * surface as `type: 'invariant-violation'` (without a `violations` extension), NOT as
 * `type: 'validation-failed'`.
 */
final class ThrowInvariantViolationDomainExceptionController
{
    public function __invoke(): Response
    {
        throw new class ('', 'Account already settled') extends DomainException implements InvariantViolation {
        };
    }
}
