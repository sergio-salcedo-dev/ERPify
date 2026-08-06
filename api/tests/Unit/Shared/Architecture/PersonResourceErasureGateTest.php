<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

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
     * The single-writer tripwire. `FulfilIdentityErasure` runs the actor pass and the resource pass inside
     * one transaction, in that order, and neither statement fixes its lock order with an `ORDER BY` — so two
     * concurrent erasures taking the two axes in opposite orders is a textbook ABBA deadlock. It is
     * unreachable, and the reason is NOT that anything serialises them: it is that the reciprocal pair of
     * rows cannot coexist. The only file that names a person as an audit RESOURCE is this erasure, and the
     * erasure hard-deletes the identity, so a subject once erased never acts again.
     *
     * That argument dies the moment a second file writes the type — an admin action recorded against a user,
     * say — because then the resource axis carries rows the erasure did not write, two live administrators
     * can each be the other's resource, and the deadlock becomes reachable while every other gate stays
     * green. A `40P01` surfaces as a 500 through the RFC 9457 pipeline.
     *
     * This is the sibling of `theStalenessOfAPersonTypeIsSatisfiedByItsOwnErasureDeclaration` and not a
     * duplicate of it: that one matches the quoted literal, so a writer reaching the type through an
     * imported constant or a route default is invisible to it. Derivation resolves all three forms, which is
     * what makes this one a tripwire rather than a spot check.
     *
     * **What it does not see**, because a tripwire that overstates its reach is worse than none:
     *  - A type passed as a VARIABLE — `AuditResource::of($type, $id)` — matches none of the three derivation
     *    forms and raises nothing. {@see \Erpify\Shared\Audit\Infrastructure\Http\RequestAuditResourceExtractor}
     *    is written that way and is covered only because its INPUT is a `#[Route]` default literal; a
     *    `$request->attributes->set('_audit_resource_type', …)` at runtime would be covered by neither.
     *  - `api/src` only. The row seeded by `features/backoffice/users/erase.feature` and the
     *    `AuditResource::of('User', …)` in `AuditActorAnonymiserFunctionalTest` are test surfaces, correctly
     *    invisible here — they do not make the deadlock reachable in production.
     */
    #[Test]
    public function noSecondFileWritesAPersonTypeIntoTheResourceAxis(): void
    {
        $registry = $this->registry();

        foreach ($this->personTypes() as $type => $personResourceDeclaration) {
            $this->assertSame([$personResourceDeclaration->erasedBy], $registry->filesDerivingType($type), \sprintf(
                'A file other than the declared erasure of "%s" now writes it into the audit resource axis. '
                . 'Two consequences, and the second is the one nobody will look for: the erasure no longer '
                . 'accounts for every row naming a person as a resource, and the ABBA deadlock between the '
                . 'actor pass and the resource pass of FulfilIdentityErasure — unreachable only because the '
                . 'reciprocal rows cannot coexist — becomes reachable. Fix the first by naming the new '
                . "writer's erasure; fix the second by giving both passes a deterministic lock order "
                . '(`ORDER BY id`, as DoctrineActiveAdministratorDirectory already does) and by confirming '
                . '40P01 still maps to a retryable marker.',
                $type,
            ));
        }
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
