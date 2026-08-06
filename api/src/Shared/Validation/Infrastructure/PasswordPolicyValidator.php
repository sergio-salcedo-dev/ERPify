<?php

declare(strict_types=1);

namespace Erpify\Shared\Validation\Infrastructure;

use Override;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class PasswordPolicyValidator extends ConstraintValidator
{
    #[Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PasswordPolicy) {
            throw new UnexpectedTypeException($constraint, PasswordPolicy::class);
        }

        if (null === $value) {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        // NotBlank owns the "required" vocabulary, so an empty value leaves here with one violation
        // instead of three saying the same thing in three registers.
        if ('' === $value) {
            return;
        }

        if ($this->isOnlyWhitespace($value)) {
            $this->context->buildViolation(PasswordPolicy::BLANK_MESSAGE)->addViolation();

            return;
        }

        $this->assertLength($value);
    }

    /**
     * `mb_trim`, never `trim` and never a `\S` regex: a value made only of Unicode whitespace — a
     * non-breaking space (U+00A0), an ideographic space (U+3000) — survives ASCII trimming, and `\S`
     * without the `u` modifier matches the bytes those characters are made of.
     */
    private function isOnlyWhitespace(string $value): bool
    {
        return '' === \mb_trim($value);
    }

    private function assertLength(string $value): void
    {
        $length = \mb_strlen($value);

        if ($length < PasswordPolicy::MIN_LENGTH) {
            $this->addLimitViolation(PasswordPolicy::TOO_SHORT_MESSAGE, PasswordPolicy::MIN_LENGTH);

            return;
        }

        if ($length > PasswordPolicy::MAX_LENGTH) {
            $this->addLimitViolation(PasswordPolicy::TOO_LONG_MESSAGE, PasswordPolicy::MAX_LENGTH);
        }
    }

    private function addLimitViolation(string $message, int $limit): void
    {
        $this->context
            ->buildViolation($message)
            ->setParameter('{{ limit }}', (string) $limit)
            ->addViolation()
        ;
    }
}
