<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use Doctrine\ORM\Mapping\Id;
use ReflectionProperty;

/**
 * Which `person ::` lines of the registry carry no `#[PersonSubjectReference]` at their property.
 *
 * This is the direction the rest of the gate does not have. Every other declaration check iterates the
 * DECLARATIONS, so a registry line whose property was never annotated is simply not visited: the half of
 * the rule that says the obligation must be visible where the column is declared went unenforced for every
 * column, and deleting an attribute left the build green.
 *
 * The exemption is the SUBJECT'S OWN PRIMARY KEY, and it is semantic rather than mechanical. The attribute
 * is named for a REFERENCE: an id of a person held by a row that is not them, which no foreign key removes
 * because cross-context references carry none — that is the distributed obligation the axis exists to make
 * impossible to pass in silence. A primary key carries no such obligation: erasing it IS deleting its own
 * row, which the wiring check already proves. Exempting by "the property is inherited" instead would key
 * the rule to an implementation accident and let any person id dropped into any shared trait escape it.
 *
 * A declaration IS resolved through inheritance, which is a different question from the exemption: a column
 * a concrete entity inherits is classified under the child's key while the attribute can only be written on
 * the parent that declares the property, so reading the key literally would report a properly declared
 * column as undeclared.
 *
 * @internal test support
 */
final class UndeclaredPersonReferences
{
    /**
     * @param array<string, string|null> $classification `<Fqcn>::$<property>` => owner path, null when non-person
     * @param array<string, string>      $declaredOwners `<Fqcn>::$<property>` => owner path
     *
     * @return list<string>
     */
    public static function in(array $classification, array $declaredOwners): array
    {
        $personReferences = \array_keys(
            \array_filter($classification, static fn (?string $owner): bool => null !== $owner),
        );

        return \array_values(\array_filter(
            $personReferences,
            static fn (string $key): bool => !self::isTheSubjectItself($key)
                && !self::isDeclared($key, $declaredOwners),
        ));
    }

    /**
     * Whether the column is the primary key of the aggregate that IS the person.
     */
    private static function isTheSubjectItself(string $key): bool
    {
        $property = self::propertyOf($key);

        return $property instanceof ReflectionProperty && [] !== $property->getAttributes(Id::class);
    }

    /**
     * Whether the attribute sits at this key or at the ancestor that declares the property.
     *
     * @param array<string, string> $declaredOwners
     */
    private static function isDeclared(string $key, array $declaredOwners): bool
    {
        if (\array_key_exists($key, $declaredOwners)) {
            return true;
        }

        // Matched over the KEYS rather than by reflecting the child: a property the parent declares `private`
        // — the shape a `#[ORM\MappedSuperclass]` takes — is not reachable from the child through
        // `ReflectionProperty`, so resolving the declaring class that way would report a column whose
        // attribute is written in the only place it can be written.
        $entity = \strstr($key, '::$', true);
        $property = \strstr($key, '::$');

        if (false === $entity || false === $property) {
            return false;
        }

        return \array_any(
            \array_keys($declaredOwners),
            static fn (string $declared): bool => \str_ends_with($declared, $property)
                && \is_subclass_of($entity, (string) \strstr($declared, '::$', true)),
        );
    }

    /**
     * The reflected property behind a registry key, or `null` when the key resolves to nothing — a state the
     * staleness check already fails on, so it is not this rule's job to report it a second time.
     */
    private static function propertyOf(string $key): ?ReflectionProperty
    {
        $class = \strstr($key, '::$', true);
        $name = \substr((string) \strstr($key, '::$'), 3);

        if (false === $class || '' === $name || !\class_exists($class) || !\property_exists($class, $name)) {
            return null;
        }

        return new ReflectionProperty($class, $name);
    }
}
