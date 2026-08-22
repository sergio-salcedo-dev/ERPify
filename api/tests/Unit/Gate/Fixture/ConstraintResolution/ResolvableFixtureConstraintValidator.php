<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture\ConstraintResolution;

use Override;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Never invoked. It exists so a class answers to the name {@see ResolvableFixtureConstraint}'s
 * `validatedBy()` derives — the signature is imposed by `ConstraintValidator`, and giving the body
 * anything to do would only add a second reason for this file to change.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
final class ResolvableFixtureConstraintValidator extends ConstraintValidator
{
    #[Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
    }
}
