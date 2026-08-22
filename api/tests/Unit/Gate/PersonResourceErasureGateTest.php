<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\AuditResourceTypeRegistry;
use Erpify\Tests\Support\PersonResourceDeclaration;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over `api/.audit-resource-types`, the declared classification of every audit `resource_type`
 * as person-denoting or not.
 *
 * It exists because GDPR erasure of the resource axis is assigned to the bounded context that owns the
 * person (`docs/adr/audit-activity-log.md` D4), and a distributed obligation is only safe if the moment it
 * arises is impossible to pass silently. The defect this repo actually shipped was exactly that: a new
 * `resource_type = 'User'` row was introduced with nobody erasing it, so the subject's real id survived
 * their own erasure beside their pseudonym. The gate turns "somebody must remember" into "the build stops
 * until somebody decides".
 *
 * Five directions, all mechanical:
 *
 *   - **Completeness** — every type reaching `AuditResource::of()` or declared as a route's
 *     `_audit_resource_type` default must be classified here, whether the type is written at the call as a
 *     literal or held in a constant — in the calling class or in another one it imports. The constant form
 *     is not an exotic case to tolerate: it is the form the person type itself uses, so a regex that only
 *     saw literals would be blind to the one type this gate exists for. A constant reference the sweep
 *     cannot resolve raises rather than yielding no type, because yielding no type IS the failure this
 *     whole direction exists to prevent, reached quietly.
 *   - **Derivation** — the file a `person` line names as its eraser must also be one of the files that
 *     BUILD the type. Whoever persists a person's identifier has to be the one obliged to remove it, or the
 *     obligation is handed to a caller nobody checks; and it is what keeps the type derivable at all, so the
 *     anti-deletion property of the line does not reduce to a coincidence somebody may refactor away.
 *   - **Wiring** — every `person` line must name a file that holds an `AuditResourceAnonymiser` property
 *     *and calls `anonymise()` on it*, so "declared a person, nobody erases it" fails too. Matching is done
 *     on comment-stripped source: a docblock naming the collaborator is prose, not wiring, and must not be
 *     able to satisfy the check on its own.
 *   - **Witness** — every `person` line must also name an acceptance scenario, distinct from that file,
 *     which seeds a row of the type and asserts none survives the erasure. Wiring proves the call is
 *     written; only the witness proves it reaches a row.
 *   - **Staleness** — a `non-person` type nothing writes any more is a graveyard entry and fails. The check
 *     stops at `non-person` deliberately, because the two classifications carry different risks: for a
 *     `person` line the risk is not an entry nobody uses, it is an obligation nobody executes, and the
 *     witness is what answers that. Reading the type literal out of `src` cannot: a person type's only
 *     literal is the constant its own declared owner holds, so the check would be satisfied by the
 *     declaration it is meant to verify — see the demonstration below.
 *
 * What it deliberately cannot do: judge the classification. Calling `Contact` a non-person passes. That is
 * a review decision, and the gate's job is to force it to be *made*, in a diffable file, at the moment the
 * type is introduced. One source form stays out of reach — a type assembled at runtime from request
 * attributes ({@see \Erpify\Shared\Audit\Infrastructure\Http\RequestAuditResourceExtractor}, whose inputs
 * the route-default check covers instead). And one ambiguity remains by construction: constants are keyed by
 * the holder's SHORT name, so two same-named classes in different namespaces collapse onto one key. When
 * their literals agree that over-includes, which fails loudly; when they disagree the sweep now raises,
 * because resolving a call site to its namesake's literal would drop the type it really writes.
 *
 * The rules themselves live in {@see AuditResourceTypeRegistry} and their falsifiability is pinned by
 * {@see PersonResourceErasureRulesGateTest}; this class only asserts them over the real tree.
 *
 * @internal
 */
#[CoversNothing]
final class PersonResourceErasureGateTest extends TestCase
{
    #[Test]
    public function everyResourceTypeInUseIsClassified(): void
    {
        $registry = $this->registry();
        $unclassified = \array_values(\array_diff(
            $registry->resourceTypesInSource(),
            \array_keys($registry->classification()),
        ));

        $this->assertSame([], $unclassified, \sprintf(
            'These audit resource types are written by the code but not classified in .audit-resource-types: %s. '
            . 'Declare each as `non-person` or `person :: <erasure use case> :: <witness>` — a person-denoting '
            . 'type with no erasure leaves the subject named in the trail after their own erasure.',
            \implode(', ', $unclassified),
        ));
    }

    #[Test]
    public function everyPersonTypeNamesAFileThatAnonymisesIt(): void
    {
        $registry = $this->registry();

        foreach ($this->personTypes() as $type => $personResourceDeclaration) {
            $defect = $registry->erasureDefectIn($type, $personResourceDeclaration->erasedBy);

            $this->assertNull($defect, (string) $defect);
        }
    }

    #[Test]
    public function everyPersonTypeIsBuiltByTheFileDeclaredToEraseIt(): void
    {
        $registry = $this->registry();
        $personTypes = $this->personTypes();

        // The only assertion below lives inside the loop, so an empty registry side satisfies it vacuously:
        // reclassifying the one `person` type as `non-person` would leave this executing zero assertions and
        // reporting green, which is the shape a rule must not be allowed to fail in.
        $this->assertNotEmpty(
            $personTypes,
            'The registry declares no person-denoting resource type, so this rule has no subject left and '
            . 'passes whatever the source tree does.',
        );

        foreach ($personTypes as $type => $personResourceDeclaration) {
            $deriving = $registry->filesDerivingType($type);

            $this->assertContains($personResourceDeclaration->erasedBy, $deriving, \sprintf(
                'The file declared to erase "%s" does not build it: whoever persists a person\'s identifier '
                . 'has to be the one obliged to remove it, or the obligation is handed to a caller nobody '
                . 'checks. Files that do build it: %s. This is also what keeps the type derivable at all — '
                . 'the anti-deletion property of its registry line rests on the sweep still finding it in '
                . '`src`, and pinning the owner as one of those sites is what stops that reducing to a '
                . 'coincidence somebody may refactor away.',
                $type,
                [] === $deriving ? 'none' : \implode(', ', $deriving),
            ));
        }
    }

    /**
     * The containment rule for the resource axis: a person type may be written by more than one file, but
     * every one of them lives in the module whose use case is declared to erase it.
     *
     * It is deliberately weaker than demanding a single writer, and the weakening is what makes it survive.
     * Demanding one writer would rest on an argument about deadlock: `FulfilIdentityErasure` runs the actor
     * pass and the resource pass as two statements in one transaction, so two concurrent erasures can take
     * them in opposite orders — a textbook ABBA — and the only thing that would make it unreachable is the
     * erasure being the sole writer of the axis while hard-deleting the identity, so a subject once erased
     * never acts again. An administrator acting on another administrator ends that, and any invariant built
     * on it.
     *
     * **That protection now lives where it belongs, in the code rather than in a file census.**
     * {@see \Erpify\Shared\Audit\Application\AuditSubjectRowLock} takes both axes as one set in `id` order
     * before either is rewritten, so concurrent erasures acquire their shared rows in the same sequence and
     * one blocks instead of cycling. `FulfilIdentityErasureTest` pins that the lock is taken BEFORE either
     * anonymiser, which is the half a call-count assertion would miss. A rule about who may write a column is
     * the wrong instrument for a concurrency invariant: it went red for a change that was correct, and it
     * would have gone green again the moment a writer was deleted for unrelated reasons.
     *
     * What remains is the claim the census can actually support. `docs/adr/audit-activity-log.md` D4 assigns
     * erasure of a person-denoting resource to the context that owns the person, so a writer OUTSIDE that
     * module is the shape that breaks the assignment: it produces rows in a context that has no erasure
     * declared for them, and the registry — one owner per type — has no way to express a second one. Siblings
     * inside the module are fine because the declared erasure already covers them: it matches on
     * `(resource_type, resource_id)`, never on who wrote the row.
     *
     * Sibling of `theStalenessOfAPersonTypeIsSatisfiedByItsOwnErasureDeclaration` and not a duplicate: that
     * one matches the quoted literal, so a writer reaching the type through an imported constant or a route
     * default is invisible to it — which is exactly how both current writers reach it. Derivation resolves
     * all three forms.
     *
     * **What it does not see**, because a rule that overstates its reach is worse than none:
     *  - A type passed as a VARIABLE — `AuditResource::of($type, $id)` — matches none of the three derivation
     *    forms and raises nothing. {@see \Erpify\Shared\Audit\Infrastructure\Http\RequestAuditResourceExtractor}
     *    is written that way and is covered only because its INPUT is a `#[Route]` default literal; a
     *    `$request->attributes->set('_audit_resource_type', …)` at runtime would be covered by neither.
     *  - `api/src` only. The row seeded by `features/backoffice/users/erase.feature` and the
     *    `AuditResource::of('User', …)` in `AuditActorAnonymiserFunctionalTest` are test surfaces, correctly
     *    invisible here.
     *  - Nothing about concurrency. A green here is not evidence the lock exists, is called, or is called in
     *    time; those are three separate assertions and they live in the erasure's own unit test.
     */
    #[Test]
    public function everyFileWritingAPersonTypeLivesInTheModuleThatErasesIt(): void
    {
        $registry = $this->registry();

        foreach ($this->personTypes() as $type => $personResourceDeclaration) {
            $owningModule = $this->moduleOf($personResourceDeclaration->erasedBy);
            $foreignWriters = \array_values(\array_filter(
                $registry->filesDerivingType($type),
                fn (string $sourceFile): bool => $this->moduleOf($sourceFile) !== $owningModule,
            ));

            $this->assertSame([], $foreignWriters, \sprintf(
                'These files write "%s" into the audit resource axis from outside %s, the module whose use '
                . 'case is declared to erase it: %s. `docs/adr/audit-activity-log.md` D4 assigns erasure of a '
                . 'person-denoting resource to the context that owns the person, so a writer outside it '
                . 'produces rows whose erasure nobody in that context knows to account for. Either move the '
                . 'write behind a seam the owning module publishes, or reclassify the type and give the new '
                . 'owner its own registry line.',
                $type,
                $owningModule,
                \implode(', ', $foreignWriters),
            ));
        }
    }

    #[Test]
    public function theModuleBoundaryTheRuleComparesIsTheOneDeptracIsolates(): void
    {
        // Over the real tree every writer of the one person type sits in `src/Iam/Identity`, so the rule
        // above compares an empty difference and would keep reporting green if `moduleOf()` were widened —
        // `0, 1` turns "same module" into "anywhere under src/" and the rule becomes vacuous with no test
        // noticing. That is the direction that fails SILENTLY, so it is the one that needs pinning; the
        // narrow direction already fails loudly, by rejecting a correct writer.
        $this->assertSame(
            'src/Iam/Identity',
            $this->moduleOf('src/Iam/Identity/Application/FulfilIdentityErasure.php'),
        );
        $this->assertNotSame(
            $this->moduleOf('src/Iam/Identity/Application/FulfilIdentityErasure.php'),
            $this->moduleOf('src/Backoffice/Health/Application/Probe.php'),
            'Two different contexts resolve to the same module, so the containment rule permits any writer.',
        );
        // Sibling MODULES of one context must separate too — deptrac isolates `Backoffice/Bank` from
        // `Backoffice/BankAccount`, and a prefix of two segments would fold them together.
        $this->assertNotSame(
            $this->moduleOf('src/Backoffice/Bank/Application/X.php'),
            $this->moduleOf('src/Backoffice/BankAccount/Application/X.php'),
        );
    }

    /**
     * The `src/<Context>/<Module>` prefix — the granularity deptrac isolates at, so "same module" here means
     * the same thing it means to the boundary gate rather than a second, softer definition.
     */
    private function moduleOf(string $sourceFile): string
    {
        return \implode('/', \array_slice(\explode('/', $sourceFile), 0, 3));
    }

    #[Test]
    public function everyPersonTypeNamesAWitnessThatProvesItsErasure(): void
    {
        $witness = $this->registry()->witness();

        foreach ($this->personTypes() as $type => $personResourceDeclaration) {
            $defect = $witness->defectIn($type, $personResourceDeclaration->witness);

            $this->assertNull($defect, (string) $defect);
        }
    }

    #[Test]
    public function theRegistryDeclaresNoNonPersonTypeThatNothingWrites(): void
    {
        $stale = $this->registry()->staleNonPersonTypes();

        $this->assertSame([], $stale, \sprintf(
            'These types are classified but no longer written anywhere: %s. Remove them so the registry '
            . 'stays a live inventory rather than a graveyard.',
            \implode(', ', $stale),
        ));
    }

    #[Test]
    public function theStalenessOfAPersonTypeIsSatisfiedByItsOwnErasureDeclaration(): void
    {
        // Why the check above stops at `non-person`, demonstrated instead of argued: the only file in `src`
        // carrying a person type's literal is the very file the same registry line names as its erasure
        // owner. Extended to `person`, the check would read green because its CONSUMER carries the type,
        // never because anything writes it — a green that carries no information, which is what the witness
        // supplies instead.
        //
        // It is NOT a tripwire on production writers, and reading a green here as evidence that none exists
        // would be wrong: this sweep matches the quoted literal alone, so a writer reaching the type through
        // a constant it imports is invisible to it — the completeness direction is what resolves those, not
        // this one. What the assertion buys is narrower and worth keeping: the literal has not spread beyond
        // the class that declares it, which is exactly what the self-satisfaction argument above rests on.
        // A second class spelling the literal turns this red.
        $registry = $this->registry();

        foreach ($this->personTypes() as $type => $personResourceDeclaration) {
            $this->assertSame([$personResourceDeclaration->erasedBy], $registry->sourceFilesCarrying($type), \sprintf(
                'The person type "%s" is carried by files other than its declared erasure owner, so the '
                . 'staleness check would no longer be self-satisfied for it. Revisit whether the declared '
                . 'witness is still what establishes its liveness.',
                $type,
            ));
        }
    }

    /**
     * The person lines, with the empty case rejected: an empty loop asserts nothing and reports success,
     * which is the failure mode this gate exists against, reproduced inside the gate.
     *
     * @return array<string, PersonResourceDeclaration>
     */
    private function personTypes(): array
    {
        $personTypes = \array_filter($this->registry()->classification());

        $this->assertNotEmpty($personTypes, 'No person type is classified, so these checks assert nothing.');

        return $personTypes;
    }

    private function registry(): AuditResourceTypeRegistry
    {
        return AuditResourceTypeRegistry::fromGateLocation(__DIR__);
    }
}
