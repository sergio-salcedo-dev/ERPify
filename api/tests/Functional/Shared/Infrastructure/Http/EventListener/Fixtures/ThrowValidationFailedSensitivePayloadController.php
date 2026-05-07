<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Test-only controller that throws a `ValidationFailedException` carrying a single violation
 * whose `invalidValue` and `root` contain payloads that MUST NOT propagate to the wire.
 * Pins Story 1.6 AC #5: only `propertyPath`, `message`, `code` reach the body.
 */
final class ThrowValidationFailedSensitivePayloadController
{
    public function __invoke(): Response
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation(
                message: 'This value should not be blank.',
                messageTemplate: null,
                parameters: [],
                root: ['password' => 'leaked-secret'],
                propertyPath: 'name',
                invalidValue: 'super-secret-payload',
                plural: null,
                code: 'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
            ),
        ]);

        throw new ValidationFailedException(
            value: ['password' => 'leaked-secret'],
            violations: $constraintViolationList,
        );
    }
}
