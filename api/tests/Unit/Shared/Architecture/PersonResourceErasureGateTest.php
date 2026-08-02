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
 * Four directions, all mechanical:
 *
 *   - **Completeness** — every type reaching `AuditResource::of()` or declared as a route's
 *     `_audit_resource_type` default must be classified here, whether the type is written at the call as a
 *     literal or held in a same-class constant. The constant form is not an exotic case to tolerate: it is
 *     the form the person type itself uses, so a regex that only saw literals would be blind to the one
 *     type this gate exists for.
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
 * type is introduced. Two source forms also stay out of reach — a type assembled at runtime from request
 * attributes ({@see \Erpify\Shared\Audit\Infrastructure\Http\RequestAuditResourceExtractor}, whose inputs
 * the route-default check covers instead) and a constant imported from another class.
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
        // It doubles as a tripwire. The day a production writer of the type appears this goes red, and the
        // reader decides whether the declared witness is still what establishes that type's liveness.
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
