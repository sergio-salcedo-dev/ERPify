<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Which `person ::` lines of the registry carry no `#[PersonSubjectReference]` at their property.
 *
 * This is the direction the rest of the gate does not have. Every other declaration check iterates the
 * DECLARATIONS, so a registry line whose property was never annotated is simply not visited: the half of
 * the rule that says the obligation must be visible where the column is declared went unenforced for every
 * column, and deleting an attribute left the build green.
 *
 * Which keys the rule applies to — every person reference except the subject's own primary key — is
 * {@see PersonReferenceKeys}, shared with the source-coverage gate so the exemption has one definition.
 *
 * A declaration IS resolved through inheritance, which is a different question from that exemption: a column
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
        return \array_values(\array_filter(
            PersonReferenceKeys::referencesIn($classification),
            static fn (string $key): bool => !self::isDeclared($key, $declaredOwners),
        ));
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
}
