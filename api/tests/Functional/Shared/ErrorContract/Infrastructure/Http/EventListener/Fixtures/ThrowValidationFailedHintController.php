<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\ErrorContract\Infrastructure\Http\EventListener\Fixtures;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Test-only controller reproducing the violations Symfony's argument resolver builds from a
 * denormalization failure, which differ only in whether it had expected types to interpolate.
 *
 * The first has none, so its rendered message says nothing and the actionable sentence sits in the
 * `hint` parameter. The second has them, and its `hint` names the target class — the reason the
 * projection prefers a hint only over the uninformative template, never as a general override.
 * The third has nothing to say either, but its hint carries an FQCN-shaped token: the producer set
 * is open, and a hint with a backslash is dropped in favour of the template rather than emitted.
 */
final class ThrowValidationFailedHintController
{
    public function __invoke(): Response
    {
        $constraintViolationList = new ConstraintViolationList([
            new ConstraintViolation(
                message: 'This value was of an unexpected type.',
                messageTemplate: 'This value was of an unexpected type.',
                parameters: ['hint' => 'The data must be one of the following values: "detailed", "light"'],
                root: null,
                propertyPath: 'paginationMode',
                invalidValue: 'unknownPaginationMode',
            ),
            new ConstraintViolation(
                message: 'This value should be of type int|string.',
                messageTemplate: 'This value should be of type {{ type }}.',
                parameters: [
                    '{{ type }}' => 'int|string',
                    'hint' => 'The data is neither an integer nor a string, you should pass an integer or a '
                        . 'string that can be parsed as an enumeration case of type '
                        . 'Erpify\Shared\Search\Domain\PaginationMode.',
                ],
                root: null,
                propertyPath: 'direction',
                invalidValue: ['light'],
            ),
            new ConstraintViolation(
                message: 'This value was of an unexpected type.',
                messageTemplate: 'This value was of an unexpected type.',
                parameters: [
                    'hint' => 'Failed to create object because the class '
                        . 'Erpify\Shared\Search\Domain\PaginationMode misses the "status" property.',
                ],
                root: null,
                propertyPath: 'status',
                invalidValue: 42,
            ),
        ]);

        throw new ValidationFailedException(value: null, violations: $constraintViolationList);
    }
}
