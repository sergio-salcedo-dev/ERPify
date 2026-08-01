<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\AuditResourceTypeRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Falsifiability of the WITNESS rule — the one direction of `api/.audit-resource-types` that reads an
 * artefact outside `src`, and the one that needed pinning most.
 *
 * It is the second witness of a registry whose only manual entry could otherwise satisfy its own liveness
 * check, so a witness rule that silently accepted everything would reinstate exactly the circularity it was
 * introduced to break — and nothing about a green build would say so. The fixtures carry the ways a scenario
 * can name a type and testify to nothing: asserting the row is still there, asserting an absence it never
 * created, seeding it only in a comment, and answering the query with a count from the next scenario.
 *
 * Separate from {@see PersonResourceErasureRulesGateTest} along the seam the production code already has —
 * {@see \Erpify\Tests\Support\AuditWitnessScenario} reads scenarios, the registry engine reads
 * declarations — and the make target selects both through a common prefix rather than naming either.
 *
 * @internal
 */
#[CoversNothing]
final class PersonResourceErasureWitnessGateTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/Fixture/PersonResource';

    private const string TYPE = 'FixtureResource';

    private const string ERASURE_OWNER = 'src/Iam/Identity/Application/FulfilIdentityErasure.php';

    #[Test]
    public function theWitnessCheckAcceptsAScenarioThatSeedsTheTypeAndAssertsItIsGone(): void
    {
        $this->assertNull(
            $this->overFixtures('registry.complete')
                ->witness()->defectIn(self::TYPE, 'features/witness-complete.feature'),
            'The witness rule rejected a scenario that writes the type and asserts none survives, so the '
            . 'reds below prove nothing.',
        );
    }

    #[Test]
    public function theWitnessCheckRejectsAScenarioThatNeverAssertsTheRowIsGone(): void
    {
        $defect = $this->overFixtures('registry.complete')
            ->witness()->defectIn(self::TYPE, 'features/witness-without-erasure.feature')
        ;

        $this->assertNotNull($defect, 'A scenario that only watches the write was accepted as a witness.');
        $this->assertStringContainsString('never asserts that no row of it survives', $defect);
    }

    #[Test]
    public function theWitnessCheckRejectsAScenarioThatNeverWritesTheType(): void
    {
        // The vacuous half: asserting an absence over a row that was never created holds with the erasure
        // deleted entirely, which is the precedent this epic already paid for once.
        $defect = $this->overFixtures('registry.complete')
            ->witness()->defectIn(self::TYPE, 'features/witness-without-write.feature')
        ;

        $this->assertNotNull($defect, 'A scenario that never seeds the type was accepted as a witness.');
        $this->assertStringContainsString('never writes a row of', $defect);
    }

    #[Test]
    public function theWitnessCheckRejectsTheErasureOwnerItself(): void
    {
        // What keeps the declaration from testifying for itself. It is a path rule rather than a comparison
        // of the two segments: an owner is a `.php` under `src/`, so refusing that shape as a witness is
        // what makes the two disjoint, and this is the case that would notice the prefix being relaxed.
        $defect = $this->registry()->witness()->defectIn('User', self::ERASURE_OWNER);

        $this->assertNotNull($defect, 'The erasure owner was accepted as the witness of its own declaration.');
        $this->assertStringContainsString('is not a .feature file under features/', $defect);
    }

    #[Test]
    public function theWitnessCheckRejectsAScenarioWhoseOnlyWriteIsCommentedOut(): void
    {
        // Dead text must not stand in for the write, which is the rule the sibling wiring check already
        // applies to PHP source. Without it a `# INSERT … 'FixtureResource'` line satisfies the write check
        // and the vacuous absence below it reads as an erasure the scenario witnessed.
        $defect = $this->overFixtures('registry.complete')
            ->witness()->defectIn(self::TYPE, 'features/witness-write-commented-out.feature')
        ;

        $this->assertNotNull($defect, 'A commented-out write was accepted as seeding the type.');
        $this->assertStringContainsString('never writes a row of', $defect);
    }

    #[Test]
    public function theWitnessCheckDoesNotPairAQueryWithACountInTheNextScenario(): void
    {
        // What "the very next step" has to mean. The query is the last step of one scenario and the zero
        // count the first of the next, over another table entirely — a pair that proves nothing.
        $defect = $this->overFixtures('registry.complete')
            ->witness()->defectIn(self::TYPE, 'features/witness-count-in-next-scenario.feature')
        ;

        $this->assertNotNull($defect, 'A count belonging to the next scenario was read as answering the query.');
        $this->assertStringContainsString('never asserts that no row of it survives', $defect);
    }

    #[Test]
    public function theWitnessCheckRejectsADirectoryAndATraversal(): void
    {
        $witness = $this->overFixtures('registry.complete')->witness();

        // The path is shaped like a witness in every respect but one: it is a DIRECTORY. `file_exists()`
        // accepts those, so `is_file()` is the only thing between a declared directory and a check that
        // silences itself with nothing written at all — and this is the only input that reaches it, since
        // anything with the wrong extension is refused earlier.
        $this->assertSame(
            'the witness declared for "FixtureResource" is unusable: "features/witness-directory.feature" '
            . 'does not exist',
            $witness->defectIn(self::TYPE, 'features/witness-directory.feature'),
            'A directory was accepted as the witness of a person type.',
        );
        $this->assertNotNull(
            $witness->defectIn(self::TYPE, 'features/../../etc/passwd'),
            'A path escaping the repository was accepted as a witness.',
        );
    }

    private function registry(): AuditResourceTypeRegistry
    {
        return AuditResourceTypeRegistry::fromGateLocation(__DIR__);
    }

    private function overFixtures(string $registry): AuditResourceTypeRegistry
    {
        return new AuditResourceTypeRegistry(
            self::FIXTURES,
            self::FIXTURES . '/Source',
            self::FIXTURES . '/' . $registry,
        );
    }
}
