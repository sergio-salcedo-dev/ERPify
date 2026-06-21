<?php

declare(strict_types=1);

namespace Erpify\Shared\Validation\Infrastructure;

use BackedEnum;
use Override;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class EnumTypeValidator extends ConstraintValidator
{
    #[Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof EnumType) {
            throw new UnexpectedTypeException($constraint, EnumType::class);
        }

        // @phpstan-ignore function.alreadyNarrowedType (runtime guard for callers bypassing the PHPDoc type)
        if (!\is_a($constraint->enumClass, BackedEnum::class, true)) {
            throw new ConstraintDefinitionException(\sprintf(
                'The "enumClass" option of the %s constraint must be a backed enum, "%s" given.',
                $constraint::class,
                $constraint->enumClass,
            ));
        }

        if ($constraint->allowNull && null === $value) {
            return;
        }

        $isAllowedCase = $value instanceof BackedEnum
            && \is_a($value, $constraint->enumClass)
            && ([] === $constraint->cases || \in_array($value, $constraint->cases, true));

        if ($isAllowedCase) {
            return;
        }

        $this->context
            ->buildViolation($constraint->message)
            ->setParameter('{{ choices }}', $this->formatChoices($constraint))
            ->addViolation()
        ;
    }

    private function formatChoices(EnumType $constraint): string
    {
        $cases = [] !== $constraint->cases ? $constraint->cases : $constraint->enumClass::cases();

        return $this->formatValues(\array_map(
            static fn (BackedEnum $case): string => (string) $case->value,
            $cases,
        ));
    }
}
