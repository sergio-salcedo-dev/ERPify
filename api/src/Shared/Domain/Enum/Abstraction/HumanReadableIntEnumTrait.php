<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain\Enum\Abstraction;

use Erpify\Shared\Domain\Enum\Attribute\HumanReadableIntEnumValue;
use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionEnum;
use SplObjectStorage;

trait HumanReadableIntEnumTrait
{
    public function getLabel(): ?string
    {
        return $this->getEnumCaseAttribute()?->label;
    }

    public function getLabelOrFail(): string
    {
        $label = $this->getEnumCaseAttribute()?->label;

        if (null === $label) {
            throw new InvalidArgumentException(\sprintf('Label not found for enum case %s', $this->name));
        }

        return $label;
    }

    /**
     * @return string[]|null[]
     */
    public static function getLabels(): array
    {
        return \array_map(
            static fn (?HumanReadableIntEnumInterface $intEnum): ?string => $intEnum?->getLabel(),
            \iterator_to_array(self::enumValueAttributes()),
        );
    }

    public static function fromLabel(string $label): ?self
    {
        foreach (self::enumValueAttributes() as $enum) {
            if (!$enum instanceof self) {
                continue;
            }

            if ($enum->getLabel() === $label) {
                return $enum;
            }
        }

        return null;
    }

    public static function fromLabelOrFail(string $label): static
    {
        $enum = self::fromLabel($label);

        if (!$enum instanceof self) {
            throw new InvalidArgumentException(\sprintf("Label '%s' not found in enum %s", $label, static::class));
        }

        return $enum;
    }

    public static function getKeysFromValues(array $labels): array
    {
        $values = [];

        foreach (self::cases() as $enum) {
            if (\in_array($enum->getLabel(), $labels, true)) {
                $values[] = $enum->value;
            }
        }

        return $values;
    }

    public static function getValues(): array
    {
        return \array_reduce(
            self::cases(),
            static function (array $values, $enum): array {
                $values[$enum->value] = $enum;

                return $values;
            },
            [],
        );
    }

    public static function getValuesNotIn(array $inputLabels): array
    {
        return \array_values(
            \array_filter(
                static::cases(),
                static fn ($enum): bool => !\in_array($enum, $inputLabels, true),
            ),
        );
    }

    private function getEnumCaseAttribute(): ?HumanReadableIntEnumValue
    {
        return static::enumValueAttributes()[$this] ?? null;
    }

    private static function enumValueAttributes(): SplObjectStorage
    {
        static $attributes;

        if (!isset($attributes)) {
            $attributes = new SplObjectStorage();

            foreach ((new ReflectionEnum(static::class))->getCases() as $reflectionEnumUnitCase) {
                $reflectionAttributes = $reflectionEnumUnitCase->getAttributes(
                    HumanReadableIntEnumValue::class,
                    ReflectionAttribute::IS_INSTANCEOF,
                );

                if (0 === \count($reflectionAttributes)) {
                    continue;
                }

                $attribute = $reflectionAttributes[0]->newInstance();
                $attributes[$reflectionEnumUnitCase->getValue()] = $attribute;
            }
        }

        return $attributes;
    }
}
