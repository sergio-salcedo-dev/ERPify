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
    public function getLabel(): string
    {
        return $this->getEnumCaseAttribute()->label;
    }

    /**
     * @return list<string>
     */
    public static function getLabels(): array
    {
        return \array_map(
            static fn (self $enum): string => $enum->getLabel(),
            self::cases(),
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

    /**
     * @param array<array-key, string|null> $labels
     *
     * @return list<int|string>
     */
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

    /**
     * @return array<int|string, self>
     */
    public static function getValues(): array
    {
        $values = [];

        foreach (self::cases() as $enum) {
            $values[$enum->value] = $enum;
        }

        return $values;
    }

    /**
     * @param array<self> $inputLabels
     *
     * @return list<self>
     */
    public static function getValuesNotIn(array $inputLabels): array
    {
        return \array_values(
            \array_filter(
                static::cases(),
                static fn (self $enum): bool => !\in_array($enum, $inputLabels, true),
            ),
        );
    }

    private function getEnumCaseAttribute(): HumanReadableIntEnumValue
    {
        return static::enumValueAttributes()[$this];
    }

    /**
     * @return SplObjectStorage<HumanReadableIntEnumInterface, HumanReadableIntEnumValue>
     */
    private static function enumValueAttributes(): SplObjectStorage
    {
        /** @var SplObjectStorage<HumanReadableIntEnumInterface, HumanReadableIntEnumValue>|null $cache */
        static $cache;

        if (null !== $cache) {
            return $cache;
        }

        /** @var SplObjectStorage<HumanReadableIntEnumInterface, HumanReadableIntEnumValue> $attributes */
        $attributes = new SplObjectStorage();

        foreach ((new ReflectionEnum(static::class))->getCases() as $reflectionEnumUnitCase) {
            $reflectionAttributes = $reflectionEnumUnitCase->getAttributes(
                HumanReadableIntEnumValue::class,
                ReflectionAttribute::IS_INSTANCEOF,
            );

            if ([] === $reflectionAttributes) {
                throw new InvalidArgumentException(\sprintf(
                    'Enum case %s::%s is missing the required #[%s] attribute.',
                    static::class,
                    $reflectionEnumUnitCase->getName(),
                    HumanReadableIntEnumValue::class,
                ));
            }

            $case = $reflectionEnumUnitCase->getValue();

            if (!$case instanceof HumanReadableIntEnumInterface) {
                continue;
            }

            $attributes[$case] = $reflectionAttributes[0]->newInstance();
        }

        $cache = $attributes;

        return $attributes;
    }
}
