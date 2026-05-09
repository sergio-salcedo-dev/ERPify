<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Infrastructure\Http\EventListener\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Test-only controller that throws a `ValidationFailedException` whose violation carries both
 * a resolved `message` and a templated `messageTemplate`. Pins the violation projection: the
 * wire shape surfaces the resolved `message` and never the `{{ ... }}` placeholder template form.
 */
final class ThrowValidationFailedTemplateController
{
    public function __invoke(): Response
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation(
                message: 'This value should be greater than or equal to 18.',
                messageTemplate: 'This value should be greater than or equal to {{ limit }}.',
                parameters: ['{{ limit }}' => '18'],
                root: null,
                propertyPath: 'age',
                invalidValue: 17,
                plural: null,
                code: 'ea4e51d1-3342-48bd-87f1-9e672cd90cad',
            ),
        ]);

        throw new ValidationFailedException(value: 17, violations: $constraintViolationList);
    }
}
