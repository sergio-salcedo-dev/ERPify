<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Validator;

use BackedEnum;
use Erpify\Shared\Domain\Enum\Abstraction\HumanReadableIntEnumInterface;
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
        if ([] !== $constraint->cases) {
            return $this->formatValues($this->labelsFromCases($constraint->cases));
        }

        $enumClass = $constraint->enumClass;

        if (\is_a($enumClass, HumanReadableIntEnumInterface::class, true)) {
            return $this->formatValues($enumClass::getLabels());
        }

        return $this->formatValues(\array_map(
            static fn (BackedEnum $case): string => (string) $case->value,
            $enumClass::cases(),
        ));
    }

    /**
     * @param list<BackedEnum> $cases
     *
     * @return list<string>
     */
    private function labelsFromCases(array $cases): array
    {
        $labels = [];

        foreach ($cases as $case) {
            $labels[] = $case instanceof HumanReadableIntEnumInterface
                ? $case->getLabel()
                : (string) $case->value;
        }

        return $labels;
    }
}
