<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use Doctrine\ORM\Mapping\Id;
use ReflectionProperty;

/**
 * Which registry keys are person REFERENCES, as opposed to the person themselves.
 *
 * One definition, because two rules depend on it and they must not drift: every such column has to carry a
 * `#[PersonSubjectReference]` at its property ({@see UndeclaredPersonReferences}), and every one of them has
 * to be covered by a source of the detective control ({@see PersonReferenceSources}). Writing the exemption
 * twice is how a quarter of a gate gets switched off while staying compile-clean.
 *
 * The exemption is the SUBJECT'S OWN PRIMARY KEY, and it is semantic rather than mechanical. A reference is
 * an id of a person held by a row that is not them, which no foreign key removes because cross-context
 * references carry none — the distributed obligation the axis exists to make impossible to pass in silence.
 * A primary key carries no such obligation: erasing it IS deleting its own row.
 *
 * It must not be written as "whose owner is not the identity erasure use case". That reads like the same
 * thing and is not: `PasswordResetToken::$userId` names exactly that owner and IS a reference, so the
 * by-owner spelling silently drops it from every rule built on this.
 *
 * @internal test support
 */
final class PersonReferenceKeys
{
    /**
     * @param array<string, string|null> $classification `<Fqcn>::$<property>` => owner path, null when non-person
     *
     * @return list<string>
     */
    public static function referencesIn(array $classification): array
    {
        $personKeys = \array_keys(
            \array_filter($classification, static fn (?string $owner): bool => null !== $owner),
        );

        return \array_values(\array_filter(
            $personKeys,
            static fn (string $key): bool => !self::isTheSubjectItself($key),
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
