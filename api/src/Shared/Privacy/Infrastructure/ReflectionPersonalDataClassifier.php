<?php

declare(strict_types=1);

namespace Erpify\Shared\Privacy\Infrastructure;

use Erpify\Shared\Privacy\Application\PersonalDataClassifier;
use Erpify\Shared\Privacy\Domain\PersonalData;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PersonalDataClassifier::class)]
final class ReflectionPersonalDataClassifier implements PersonalDataClassifier
{
    /** @var array<string, list<string>> */
    private array $cache = [];

    public function personalFieldsOf(object|string $entityOrClass): array
    {
        $class = \is_object($entityOrClass) ? $entityOrClass::class : $entityOrClass;

        return $this->cache[$class] ??= $this->resolve($class);
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private function resolve(string $class): array
    {
        $fields = [];

        foreach ((new ReflectionClass($class))->getProperties() as $reflectionProperty) {
            if ([] !== $reflectionProperty->getAttributes(PersonalData::class)) {
                $fields[] = $reflectionProperty->getName();
            }
        }

        \sort($fields);

        return $fields;
    }
}
