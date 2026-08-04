<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * Every class name a constructor's parameter types mention.
 *
 * For rules of the form "this type must not hold collaborator X", which are otherwise written against the
 * STRING form of the type and are bypassable by construction: the string form of a nullable parameter is
 * `?Fqcn`, which no equality with the FQCN matches, so re-introducing the collaborator as an optional
 * `?X $x = null` — the ordinary way a dependency arrives without breaking callers — leaves the rule green.
 * Union and intersection types have the same hole with more members.
 *
 * @internal test support
 */
final class ConstructorCollaboratorTypes
{
    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    public static function of(string $class): array
    {
        $parameters = (new ReflectionClass($class))->getConstructor()?->getParameters() ?? [];
        $names = [];

        foreach ($parameters as $parameter) {
            $names = [...$names, ...self::namesIn($parameter->getType())];
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private static function namesIn(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return [$type->getName()];
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $names = [];

            foreach ($type->getTypes() as $reflectionType) {
                $names = [...$names, ...self::namesIn($reflectionType)];
            }

            return $names;
        }

        return [];
    }
}
