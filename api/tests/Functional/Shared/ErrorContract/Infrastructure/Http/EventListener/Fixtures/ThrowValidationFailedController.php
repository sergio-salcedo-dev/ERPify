<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\ErrorContract\Infrastructure\Http\EventListener\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Test-only controller that throws a `ValidationFailedException` carrying three field-level
 * violations on `name`, `email`, and `age`. Exercises the factory's validation-failed branch.
 */
final class ThrowValidationFailedController
{
    public function __invoke(): Response
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation(
                message: 'This value should not be blank.',
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: 'name',
                invalidValue: '',
                plural: null,
                code: 'c1051bb4-d103-4f74-8988-acbcafc7fdc3',
            ),
            new ConstraintViolation(
                message: 'This value is not a valid email address.',
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: 'email',
                invalidValue: 'invalid',
                plural: null,
                code: 'bd79c0ab-ddba-46cc-a703-a7a4b08de310',
            ),
            new ConstraintViolation(
                message: 'This value should be greater than or equal to 18.',
                messageTemplate: null,
                parameters: [],
                root: null,
                propertyPath: 'age',
                invalidValue: 17,
                plural: null,
                code: 'ea4e51d1-3342-48bd-87f1-9e672cd90cad',
            ),
        ]);

        throw new ValidationFailedException(
            value: ['name' => '', 'email' => 'invalid', 'age' => 17],
            violations: $constraintViolationList,
        );
    }
}
