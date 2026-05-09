<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Test-only controller that throws a `ValidationFailedException` carrying an EMPTY violation
 * list. Pins the empty-list contract: body still has `type: 'validation-failed'`,
 * `status: 400`, and a `violations` extension whose value is an empty JSON array.
 */
final class ThrowValidationFailedEmptyController
{
    public function __invoke(): Response
    {
        throw new ValidationFailedException(
            value: null,
            violations: new ConstraintViolationList([]),
        );
    }
}
