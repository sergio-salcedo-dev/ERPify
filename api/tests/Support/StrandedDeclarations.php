<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * Which `#[PersonSubjectReference]` declarations name an obligation nothing can ever read.
 *
 * The registry is keyed per persisted column, so a declaration on a property that backs no column can never
 * appear in it: the owner it names is invisible to the wiring check, the agreement check and review alike.
 * That is the failure this answers — and the one case that LOOKS like it and is not.
 *
 * A declaration on an ABSTRACT parent is not stranded. The property is declared once, on the parent, which is
 * the shape a `#[ORM\MappedSuperclass]` takes, so it cannot be annotated per child; what reaches a table is
 * the child's inherited copy. Reading the key literally would leave that mapping with no green state at all —
 * the only way to pass would be to omit the declaration, which is the half the rule exists to require.
 *
 * Split from {@see PersonReferences} because it reconciles two derived sets against each other rather than
 * deriving either, and because it is the piece whose rule has an inheritance case worth testing on its own.
 *
 * @internal test support
 */
final class StrandedDeclarations
{
    /**
     * @param array<string, string> $declaredOwners
     * @param list<string>          $universe
     *
     * @return list<string>
     */
    public static function in(array $declaredOwners, array $universe): array
    {
        return \array_values(\array_filter(
            \array_keys($declaredOwners),
            static fn (string $key): bool => !\in_array($key, $universe, true)
                && !self::isInheritedIntoAnEntity($key, $universe),
        ));
    }

    /**
     * Whether a concrete entity inheriting from `$key`'s declaring class contributes the same property to the
     * universe — the child's copy of a column the parent declares.
     *
     * @param list<string> $universe
     */
    private static function isInheritedIntoAnEntity(string $key, array $universe): bool
    {
        $declaringClass = \strstr($key, '::$', true);
        $property = \strstr($key, '::$');

        if (false === $declaringClass || false === $property) {
            return false;
        }

        return \array_any($universe, static fn (string $derived): bool => \str_ends_with($derived, $property)
            && \is_subclass_of((string) \strstr($derived, '::$', true), $declaringClass));
    }
}
