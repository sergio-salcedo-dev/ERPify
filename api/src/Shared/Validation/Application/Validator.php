<?php

declare(strict_types=1);

namespace Erpify\Shared\Validation\Application;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\GroupSequence;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
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
     * `$propertyPath` rebinds violations whose own `getPropertyPath()` is empty
     * (the default for scalar-root validations such as a route id) to the supplied
     * name, so the wire `violations[].field` is never blank for callers like
     * `BankFinder::find($id, …, propertyPath: 'id')`. Violations that already
     * carry a property path (e.g. nested object validation) are passed through
     * untouched.
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
        string $propertyPath = '',
    ): void {
        $constraintViolationList = $this->validator->validate($value, $constraints, $groups);

        if (0 === \count($constraintViolationList)) {
            return;
        }

        if ('' !== $propertyPath) {
            $constraintViolationList = $this->rebindEmptyPropertyPath($constraintViolationList, $propertyPath);
        }

        throw new ValidationFailedException($value, $constraintViolationList);
    }

    private function rebindEmptyPropertyPath(
        ConstraintViolationListInterface $violations,
        string $propertyPath,
    ): ConstraintViolationListInterface {
        $constraintViolationList = new ConstraintViolationList();

        foreach ($violations as $violation) {
            if ('' !== $violation->getPropertyPath()) {
                $constraintViolationList->add($violation);

                continue;
            }

            $constraintViolationList->add(new ConstraintViolation(
                message: $violation->getMessage(),
                messageTemplate: $violation->getMessageTemplate(),
                parameters: $violation->getParameters(),
                root: $violation->getRoot(),
                propertyPath: $propertyPath,
                invalidValue: $violation->getInvalidValue(),
                plural: $violation->getPlural(),
                code: $violation->getCode(),
                constraint: $violation->getConstraint(),
                cause: $violation->getCause(),
            ));
        }

        return $constraintViolationList;
    }
}
