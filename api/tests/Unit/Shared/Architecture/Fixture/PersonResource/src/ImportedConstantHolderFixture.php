<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Fixture\PersonResource\src;

/**
 * Holds a resource type's literal for a writer in another file, which is how the real tree spells the type
 * that denotes a natural person: the context owning the vocabulary declares it once, and the writer, the
 * anonymiser and the reconciler all reach it from there.
 *
 * It exists apart from {@see ImportedConstantWriterFixture} on purpose — a holder and a writer in the SAME
 * file would be resolved by the older `self::` rule and would prove nothing about the cross-file one.
 *
 * Nothing executes it; the gate reads source as text.
 *
 * @internal test fixture
 */
final class ImportedConstantHolderFixture
{
    public const string IMPORTED_TYPE = 'ImportedFixtureResource';
}
