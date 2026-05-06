<?php

declare(strict_types=1);

namespace Erpify\Shared\Application\Validation;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\GroupSequence;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class Validator
{
    public function __construct(private ValidatorInterface $validator)
    {
    }

    /**
     * Validates a value against a constraint or list of constraints; throws
     * Symfony's native ValidationFailedException when one or more violations
     * are produced. No-op on empty violations.
     *
     * @param Constraint|array<Constraint>|null                            $constraints
     * @param string|GroupSequence|array<string>|array<GroupSequence>|null $groups
     *
     * @throws ValidationFailedException
     */
    public function ensure(
        mixed $value,
        array|Constraint|null $constraints = null,
        array|GroupSequence|string|null $groups = null,
    ): void {
        $constraintViolationList = $this->validator->validate($value, $constraints, $groups);

        if (0 === \count($constraintViolationList)) {
            return;
        }

        throw new ValidationFailedException($value, $constraintViolationList);
    }
}
