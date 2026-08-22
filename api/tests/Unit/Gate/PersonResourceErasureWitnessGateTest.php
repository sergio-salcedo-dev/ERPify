<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\AuditResourceTypeRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Falsifiability of the WITNESS rule — the one direction of `api/.audit-resource-types` that reads an
 * artefact outside `src`, and the one that needs pinning most.
 *
 * It is the second witness of a registry whose only manual entry could otherwise satisfy its own liveness
 * check, so a witness rule that quietly accepted everything would reinstate the very circularity it was
 * introduced to break, and nothing about a green build would say so. Each fixture is one way a scenario can
 * name a type and testify to nothing: asserting the row is still there, asserting an absence it never
 * created, seeding only in text that never executes, splitting the seed and the assertion across two
 * scenarios, seeding with a query that inserts nothing, and being about another type entirely.
 *
 * Separate from {@see PersonResourceErasureRulesGateTest} along the seam the production code already has —
 * {@see \Erpify\Tests\Support\AuditWitnessScenario} reads scenarios, the registry engine reads declarations
 * — and the make target selects both through a common prefix rather than naming either.
 *
 * @internal
 */
#[CoversNothing]
final class PersonResourceErasureWitnessGateTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/Fixture/PersonResource';

    private const string TYPE = 'FixtureResource';

    private const string ERASURE_OWNER = 'src/Iam/Identity/Application/FulfilIdentityErasure.php';

    private const string NO_SEED = 'so nothing it asserts is about that type';

    private const string NO_PROOF = 'witnesses the write and not the erasure';

    #[Test]
    public function theWitnessCheckAcceptsAScenarioThatSeedsTheTypeAndAssertsItIsGone(): void
    {
        $this->assertNull(
            $this->defectIn('features/witness-complete.feature'),
            'The witness rule rejected a scenario that writes the type and asserts none survives, so the '
            . 'reds below prove nothing.',
        );
    }

    #[Test]
    public function theWitnessCheckRejectsAScenarioThatNeverAssertsTheRowIsGone(): void
    {
        $defect = $this->defectIn('features/witness-without-erasure.feature');

        $this->assertNotNull($defect, 'A scenario that only watches the write was accepted as a witness.');
        $this->assertStringContainsString(self::NO_PROOF, $defect);
    }

    #[Test]
    public function theWitnessCheckRejectsAScenarioThatNeverWritesTheType(): void
    {
        // The vacuous half: asserting an absence over a row that was never created holds with the erasure
        // deleted entirely.
        $defect = $this->defectIn('features/witness-without-write.feature');

        $this->assertNotNull($defect, 'A scenario that never seeds the type was accepted as a witness.');
        $this->assertStringContainsString(self::NO_SEED, $defect);
    }

    #[Test]
    public function theWitnessCheckCountsOnlyExecutableSteps(): void
    {
        // Dead text must not stand in for the write, and there are two kinds of it. A commented-out step is
        // the obvious one; a scenario TITLE or a description line naming the type beside the word INSERT
        // reads as documentation and executes just as little.
        foreach (['witness-write-commented-out', 'witness-write-in-a-title'] as $fixture) {
            $defect = $this->defectIn(\sprintf('features/%s.feature', $fixture));

            $this->assertNotNull($defect, \sprintf('%s: text that never runs was accepted as a write.', $fixture));
            $this->assertStringContainsString(self::NO_SEED, $defect);
        }
    }

    #[Test]
    public function theWitnessCheckRequiresOneScenarioToBothSeedAndAssert(): void
    {
        // Matched over the whole file, the seed can live in a scenario that asserts nothing and the
        // assertion in a scenario that seeds nothing — two honest halves that together say nothing. The
        // second fixture is the same defect one step down: the count answering the query belongs to the
        // next scenario.
        $this->assertStringContainsString(
            self::NO_PROOF,
            (string) $this->defectIn('features/witness-write-in-another-scenario.feature'),
            'A seed in one scenario was read as backing an assertion in another.',
        );
        $this->assertStringContainsString(
            self::NO_PROOF,
            (string) $this->defectIn('features/witness-count-in-next-scenario.feature'),
            'A count belonging to the next scenario was read as answering the query.',
        );
    }

    #[Test]
    public function theWitnessCheckRejectsASeedThatInsertsNothing(): void
    {
        // `INSERT … SELECT` over an empty table inserts zero rows, so the absence asserted afterwards was
        // never created — the vacuous seed this repository has already shipped once.
        $defect = $this->defectIn('features/witness-insert-select.feature');

        $this->assertNotNull($defect, 'A seed that inserts whatever a query returns was accepted.');
        $this->assertStringContainsString(self::NO_SEED, $defect);
    }

    #[Test]
    public function theWitnessCheckRejectsAScenarioAboutAnotherType(): void
    {
        // A complete, well-formed seed-then-vanish pair — for a different type. It separates a rule that
        // checks "some INSERT and some SELECT exist" from one that checks they are about the type being
        // declared: drop either literal test from the rule and this is the only fixture that notices.
        $defect = $this->defectIn('features/witness-other-type.feature');

        $this->assertNotNull($defect, 'A witness entirely about another type was accepted.');
        $this->assertStringContainsString(self::NO_SEED, $defect);
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
    public function theWitnessCheckRejectsADirectoryATraversalAndAnAbsentFile(): void
    {
        // A directory is the case `file_exists()` would accept, and it is reported apart from absence so
        // this assertion cannot pass because the fixture quietly disappeared.
        $this->assertDirectoryExists(self::FIXTURES . '/features/witness-directory.feature');
        $this->assertStringContainsString(
            'is a directory, not a file',
            (string) $this->defectIn('features/witness-directory.feature'),
        );

        // The traversal input has to survive the prefix and extension checks, or it is refused for its shape
        // and the `..` guard is never reached — which is how the directory case used to pass while testing
        // nothing. This one resolves to a real, readable witness.
        $this->assertStringContainsString(
            'escapes the repository',
            (string) $this->defectIn('features/../features/witness-complete.feature'),
        );

        $this->assertStringContainsString(
            'does not exist',
            (string) $this->defectIn('features/witness-that-was-deleted.feature'),
        );
    }

    private function defectIn(string $witnessPath): ?string
    {
        return $this->overFixtures()->witness()->defectIn(self::TYPE, $witnessPath);
    }

    private function registry(): AuditResourceTypeRegistry
    {
        return AuditResourceTypeRegistry::fromGateLocation(__DIR__);
    }

    private function overFixtures(): AuditResourceTypeRegistry
    {
        return new AuditResourceTypeRegistry(
            self::FIXTURES,
            self::FIXTURES . '/src',
            self::FIXTURES . '/registry.complete',
        );
    }
}
