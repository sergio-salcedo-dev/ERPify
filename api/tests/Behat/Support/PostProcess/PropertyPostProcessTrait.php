<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\PostProcess;

use Erpify\Tests\Behat\Assertion\NodeModifier\NodeModifierInterface;
use Erpify\Tests\Behat\Assertion\NodeModifier\NodeModifierLocator;
use Symfony\Contracts\Service\Attribute\Required;

trait PropertyPostProcessTrait
{
    protected NodeModifierLocator $nodeModifierLocator;

    #[Required]
    public function setNodeModifierLocator(NodeModifierLocator $nodeModifierLocator): static
    {
        $this->nodeModifierLocator = $nodeModifierLocator;

        return $this;
    }

    public function propertyPostProcessValue(string $property, mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }

        $nodeModifier = $this->nodeModifierLocator->getFor($property, $value);

        if (!$nodeModifier instanceof NodeModifierInterface) {
            return $value;
        }

        return $nodeModifier->getProcessedValue($value);
    }

    public function propertyPostProcessName(string $property): string
    {
        $type = $this->propertyPostProcessGetType($property);

        if (null === $type) {
            return $property;
        }

        return \substr($property, 0, -(\strlen($type) + 2));
    }

    public function propertyPostProcessGetType(string $property): ?string
    {
        return $this->nodeModifierLocator
            ->getFor($property, null)
            ?->getModifier()
        ;
    }

    public function propertyPostProcessIsBackedEnum(mixed $value): bool
    {
        return \is_string($value) && false !== \stripos($value, 'Enum::');
    }
}
