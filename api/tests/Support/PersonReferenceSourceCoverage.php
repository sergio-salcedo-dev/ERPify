<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

/**
 * The comparison between what `api/.person-reference-policy` classifies as a person reference and what the
 * detective control actually covers, in both directions plus the collision.
 *
 * Both directions are needed and they fail for different reasons. A registry line with no source is a column
 * whose erasure nothing verifies — the defect the control exists against, and the one that arrives by
 * forgetting rather than by deciding. A source whose axis the registry does not carry is a source watching
 * a column nobody classified: its findings are attributed to a key no reviewer will find, and if the key is
 * simply misspelled the column it was meant to watch is silently uncovered while the count of sources looks
 * right.
 *
 * Pure functions over already-derived inputs, so every branch is exercisable without a fixture tree.
 *
 * @internal test support
 */
final class PersonReferenceSourceCoverage
{
    /**
     * Person references the registry carries that no source covers.
     *
     * @param array<string, string|null>  $classification `<Fqcn>::$<property>` => owner path, null when non-person
     * @param array<string, list<string>> $declaredAxes   axis key => the sources declaring it
     *
     * @return list<string>
     */
    public static function uncoveredIn(array $classification, array $declaredAxes): array
    {
        return \array_values(\array_diff(
            PersonReferenceKeys::referencesIn($classification),
            \array_keys($declaredAxes),
        ));
    }

    /**
     * Axes a source declares that the registry does not carry as a person reference.
     *
     * @param array<string, string|null>  $classification
     * @param array<string, list<string>> $declaredAxes
     *
     * @return list<string>
     */
    public static function unbackedIn(array $classification, array $declaredAxes): array
    {
        return \array_values(\array_diff(
            \array_keys($declaredAxes),
            PersonReferenceKeys::referencesIn($classification),
        ));
    }

    /**
     * Axes more than one source claims to cover.
     *
     * @param array<string, list<string>> $declaredAxes
     *
     * @return array<string, list<string>>
     */
    public static function duplicatedIn(array $declaredAxes): array
    {
        return \array_filter($declaredAxes, static fn (array $declaring): bool => \count($declaring) > 1);
    }
}
