<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\CapturedPersonReferences;
use Erpify\Tests\Support\PersonReferences;
use Erpify\Tests\Support\UndeclaredPersonReferences;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over `api/.person-reference-policy`: the declared classification of every persisted identifier
 * column as holding a natural person's id or not, and — when it does — the file obliged to erase it. The
 * derivation rules live in {@see PersonReferences}, their falsifiability in
 * {@see PersonReferenceRulesGateTest}; this class is the assertions over the real tree.
 *
 * It exists because no object graph crosses a module boundary in this codebase: a context that needs a person
 * refers to them by id, with no mapped association — and nothing in the schema references `identity_user` at
 * all. Nothing therefore drags a person's id out of another table when their identity is deleted, so
 * every such column is a separate, remembered obligation — and every new context that touches a person mints
 * another one.
 *
 * Five directions, all mechanical:
 *
 *   - **Completeness** — every `Types::GUID` column an entity declares must be classified, whether it looks
 *     like a person reference or not. A name filter would be the blind spot rather than the gate: `$actorId`
 *     and `$createdBy` match no `*user*_id` heuristic.
 *   - **Staleness** — every line must still match a column that exists, so the registry is a live inventory
 *     rather than a graveyard. With both directions pinned to the source, a line cannot be its own evidence.
 *   - **Wiring** — a `person` line must name a file that holds a collaborator speaking the entity AND calls a
 *     deletion on it, matched on comment-stripped source. Without this half, `person :: <any existing file>`
 *     passes and the registry degenerates into documentation.
 *   - **Agreement** — a `#[PersonSubjectReference]` at a property and the line in the registry must name the
 *     same owner, so the declaration next to the column and the reviewable inventory cannot drift apart.
 *   - **Containment** — no `person` column may sit on an aggregate that opts into write change-data-capture.
 *     The two attributes of `Shared/Privacy` leave no third option there: the capture serialises every scalar
 *     field into `audit_log.metadata`, only `#[PersonalData]` columns are sealed, and filing a person's id
 *     under the row's encryption scope is exactly the mistake that attribute's own contract forbids — so the
 *     id would land in the trail as cleartext no mutation policy on that table can reach.
 *
 * What it deliberately cannot do is judge the classification; the registry header states that limit and the
 * rest in full.
 *
 * @internal
 */
#[CoversNothing]
final class PersonReferenceGateTest extends TestCase
{
    /**
     * Failure preamble — a class const so make-target wrappers and CI log scrapers can grep the literal
     * string, and so the rule travels with the failure instead of only the offending property's name.
     */
    public const string FAILURE_PREAMBLE
        = 'A persisted reference to a person must have an identified owner of its erasure, and that owner must '
        . 'execute it. These columns cross a bounded context by id with no foreign key, so nothing removes them '
        . "when the identity is deleted: what is left is the subject's real id, surviving the erasure the "
        . 'application confirmed to them. Classify each column in api/.person-reference-policy and, for a '
        . 'person reference, name the file that erases it.';

    #[Test]
    public function everyPersistedIdentifierColumnIsClassified(): void
    {
        $references = $this->references();
        $unclassified = \array_values(\array_diff(
            $references->universe(),
            \array_keys($references->classification()),
        ));

        if ([] === $unclassified) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail(self::FAILURE_PREAMBLE . "\n" . \sprintf(
            "These persisted identifier columns are declared by an entity but carry no line in\n"
            . ".person-reference-policy:\n  %s",
            \implode("\n  ", $unclassified),
        ));
    }

    #[Test]
    public function theRegistryDeclaresNoColumnThatNoEntityDeclares(): void
    {
        $references = $this->references();
        $stale = \array_values(\array_diff(
            \array_keys($references->classification()),
            $references->universe(),
        ));

        $this->assertSame([], $stale, \sprintf(
            'These columns are classified but no entity declares them any more: %s. Remove them so the '
            . 'registry stays a live inventory rather than a graveyard — a line nothing corresponds to is a '
            . 'claim about erasure that no code can contradict.',
            \implode(', ', $stale),
        ));
    }

    #[Test]
    public function everyPersonReferenceNamesAFileThatErasesIt(): void
    {
        $references = $this->references();
        $defects = [];

        foreach ($references->classification() as $key => $owner) {
            if (null === $owner) {
                continue;
            }

            $defect = $references->erasureDefectIn($key, $owner);

            if (null !== $defect) {
                $defects[] = \sprintf('%s: %s', $key, $defect);
            }
        }

        $this->assertSame([], $defects, self::FAILURE_PREAMBLE . "\n" . \implode("\n", $defects));
    }

    #[Test]
    public function everyDeclarationAtAPropertyAgreesWithTheRegistry(): void
    {
        $references = $this->references();
        $classification = $references->classification();
        $disagreements = [];

        foreach ($references->declaredOwners() as $key => $declared) {
            $classified = $classification[$key] ?? null;

            if ($declared === $classified) {
                continue;
            }

            $disagreements[] = \sprintf(
                '%s declares its erasure owner as "%s" but the registry says "%s"',
                $key,
                $declared,
                $classified ?? $this->registryVerdictFor($key, $classification),
            );
        }

        $this->assertSame([], $disagreements, \sprintf(
            "A #[PersonSubjectReference] and .person-reference-policy disagree:\n  %s\nThe declaration next to "
            . 'the column and the reviewable inventory must name the same owner, or one of them is describing '
            . 'an erasure that does not happen.',
            \implode("\n  ", $disagreements),
        ));
    }

    #[Test]
    public function everyPersonReferenceIsDeclaredAtItsProperty(): void
    {
        $references = $this->references();
        $undeclared = UndeclaredPersonReferences::in($references->classification(), $references->declaredOwners());

        $this->assertSame([], $undeclared, \sprintf(
            "These columns are classified as person references but carry no #[PersonSubjectReference]:\n  %s\n"
            . 'The registry is reviewed rarely and the entity is edited often, so the declaration at the '
            . 'column is what the next author actually sees. Without it the obligation is only in a dotfile '
            . 'nobody opens while adding a field.',
            \implode("\n  ", $undeclared),
        ));
    }

    #[Test]
    public function noDeclarationSitsOutsideThePersistedUniverse(): void
    {
        $outside = $this->references()->strandedDeclarations();

        $this->assertSame([], $outside, \sprintf(
            'These properties carry a #[PersonSubjectReference] but are not persisted identifier columns: %s. '
            . 'The registry can never carry them, so the declared owner is invisible to every check here — an '
            . 'obligation written where nothing reads it.',
            \implode(', ', $outside),
        ));
    }

    #[Test]
    public function noPersonReferenceSitsOnAnEntityWhoseWritesTheTrailCaptures(): void
    {
        $captured = CapturedPersonReferences::in($this->references()->classification());

        $this->assertSame([], $captured, \sprintf(
            'These person references live on an aggregate that opts into write change-data-capture, so every '
            . 'write of the row copies the id into `audit_log.metadata` as cleartext, permanently: %s. The '
            . 'copy is unreachable — no mutation policy on that table enters the JSON, so it outlives the '
            . "person's erasure — and the escape of marking the column #[PersonalData] is worse, because it "
            . 'would file the id under the encryption scope of the aggregate owning the ROW, to die with that '
            . 'aggregate rather than with the person. Either drop the AuditedEntity marker or stop persisting '
            . 'the id on that entity.',
            \implode(', ', $captured),
        ));
    }

    #[Test]
    public function theGateScansTheRealTreeAndCanFire(): void
    {
        // A silent zero-scan on either side would make every check above vacuously green.
        $references = $this->references();

        $this->assertNotEmpty($references->universe(), 'The gate discovered zero persisted identifier columns.');
        $this->assertNotEmpty(
            \array_filter($references->classification(), static fn (?string $owner): bool => null !== $owner),
            'The registry classifies no column as a person reference, so the wiring check can never fire.',
        );
        // Both declaration checks iterate the declarations themselves, so an empty set satisfies each of them
        // vacuously: delete every #[PersonSubjectReference] in the tree and, without this leg, the build stays
        // green while the property-side declaration has silently disappeared.
        $this->assertNotEmpty(
            $references->declaredOwners(),
            'No property declares a #[PersonSubjectReference], so the agreement check can never fire.',
        );
        // Every check here needs a leg of its own or it inherits the vacuity the leg above closes. This one
        // is for the direction that requires the attribute: with the declarations emptied, at least one
        // column must still be REQUIRED to carry it, or the rule is satisfied by having no subjects.
        $this->assertNotEmpty(
            UndeclaredPersonReferences::in($references->classification(), []),
            'Every person reference is exempt from declaring its owner, so the declaration rule has no '
            . 'subject left and passes whatever the entities say.',
        );
    }

    #[Test]
    public function theGateDoesNotRewriteTheRegistry(): void
    {
        // A control that rewrites what it checks reads green by construction, and `php.quality.dry-run`
        // promises its whole list is read-only and parallel-safe.
        $references = $this->references();
        $before = (string) \file_get_contents($references->registryPath());

        $references->universe();
        $references->declaredOwners();
        $references->classification();

        $this->assertSame(
            $before,
            (string) \file_get_contents($references->registryPath()),
            'The gate mutated .person-reference-policy while checking it.',
        );
    }

    /**
     * @param array<string, string|null> $classification
     */
    private function registryVerdictFor(string $key, array $classification): string
    {
        return \array_key_exists($key, $classification) ? PersonReferences::NON_PERSON : 'nothing at all';
    }

    private function references(): PersonReferences
    {
        return PersonReferences::fromGateLocation(__DIR__);
    }
}
