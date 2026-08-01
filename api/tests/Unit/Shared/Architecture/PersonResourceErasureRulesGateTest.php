<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\AuditResourceTypeRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Falsifiability of the rules {@see PersonResourceErasureGateTest} asserts over the real tree: it drives
 * {@see AuditResourceTypeRegistry} across a fixture tree and over real files chosen for the property they
 * lack, so each check is shown to go red for the reason claimed rather than being taken on trust.
 *
 * The witness rule is the one that needed this most. It is the second witness of a registry whose only
 * manual entry could otherwise satisfy its own liveness check, so a witness rule that silently accepted
 * everything would reinstate exactly the circularity it was introduced to break — and nothing about a green
 * build would say so. The fixture twins carry the two ways a scenario can name a type and testify to
 * nothing: asserting the row is still there, and asserting an absence it never created.
 *
 * Split from its sibling because one class carrying both would exceed the public-method ceiling, and the
 * make target selects them together through a common prefix rather than naming either.
 *
 * @internal
 */
#[CoversNothing]
final class PersonResourceErasureRulesGateTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/Fixture/PersonResource';

    private const string TYPE = 'FixtureResource';

    private const string ERASURE_OWNER = 'src/Iam/Identity/Application/FulfilIdentityErasure.php';

    #[Test]
    public function theWitnessCheckAcceptsAScenarioThatSeedsTheTypeAndAssertsItIsGone(): void
    {
        $this->assertNull(
            $this->overFixtures('registry.complete')
                ->witnessDefectIn(self::TYPE, 'features/witness-complete.feature'),
            'The witness rule rejected a scenario that writes the type and asserts none survives, so the '
            . 'reds below prove nothing.',
        );
    }

    #[Test]
    public function theWitnessCheckRejectsAScenarioThatNeverAssertsTheRowIsGone(): void
    {
        $defect = $this->overFixtures('registry.complete')
            ->witnessDefectIn(self::TYPE, 'features/witness-without-erasure.feature');

        $this->assertNotNull($defect, 'A scenario that only watches the write was accepted as a witness.');
        $this->assertStringContainsString('never asserts that no row of it survives', $defect);
    }

    #[Test]
    public function theWitnessCheckRejectsAScenarioThatNeverWritesTheType(): void
    {
        // The vacuous half: asserting an absence over a row that was never created holds with the erasure
        // deleted entirely, which is the precedent this epic already paid for once.
        $defect = $this->overFixtures('registry.complete')
            ->witnessDefectIn(self::TYPE, 'features/witness-without-write.feature');

        $this->assertNotNull($defect, 'A scenario that never seeds the type was accepted as a witness.');
        $this->assertStringContainsString('never writes a row of', $defect);
    }

    #[Test]
    public function theWitnessCheckRejectsTheErasureOwnerItself(): void
    {
        // What keeps the declaration from testifying for itself. It is a path rule rather than a comparison
        // of the two segments: an owner is a `.php` under `src/`, so refusing that shape as a witness is
        // what makes the two disjoint, and this is the case that would notice the prefix being relaxed.
        $defect = $this->registry()->witnessDefectIn('User', self::ERASURE_OWNER);

        $this->assertNotNull($defect, 'The erasure owner was accepted as the witness of its own declaration.');
        $this->assertStringContainsString('is not a .feature file under features/', $defect);
    }

    #[Test]
    public function theWitnessCheckRejectsADirectoryAndATraversal(): void
    {
        $registry = $this->registry();

        // is_file() is what stops the first: file_exists() is true for a directory, so a bare directory
        // would silence every check downstream with nothing written at all.
        $this->assertNotNull(
            $registry->witnessDefectIn('User', 'features/backoffice'),
            'A directory was accepted as the witness of a person type.',
        );
        $this->assertNotNull(
            $registry->witnessDefectIn('User', 'features/../../etc/passwd'),
            'A path escaping the repository was accepted as a witness.',
        );
    }

    #[Test]
    public function theStalenessCheckReportsTheGraveyardAndSparesThePersonLine(): void
    {
        // One assertion for both halves of the narrowing, because either half alone is satisfiable by the
        // wrong rule: a check that reported neither would look identical to one that spares person lines,
        // and a check that reported both would be the circular one this story removed.
        $this->assertSame(
            ['Ghost'],
            $this->overFixtures('registry.stale')->staleNonPersonTypes(),
            'The staleness check no longer separates a graveyard entry from a person line whose literal '
            . 'lives in the constant its own owner holds.',
        );
    }

    #[Test]
    public function theCleanFixtureTwinIsStaleFree(): void
    {
        $this->assertSame([], $this->overFixtures('registry.complete')->staleNonPersonTypes());
    }

    #[Test]
    public function theFixtureSourceIsActuallyScanned(): void
    {
        // If the fixture source root resolved to nothing, every fixture check above would pass while
        // scanning an empty tree — including the staleness one, whose whole point is a type that IS written.
        $registry = $this->overFixtures('registry.complete');

        $this->assertSame([self::TYPE], $registry->resourceTypesInSource());
        $this->assertSame(
            ['Source/AuditResourceFixtureWriter.php'],
            $registry->sourceFilesCarrying(self::TYPE),
        );
    }

    #[Test]
    public function theRegistryParserRejectsRatherThanDegrades(): void
    {
        // A duplicate would let the later line silently shadow the earlier, an unrecognised spelling would
        // fall through to "nobody has to erase this", and a person line without its witness would declare an
        // obligation nothing can falsify. Degrading any of the three is the one outcome that must be
        // impossible, because every person-only rule skips whatever is not a person line.
        $this->assertStringContainsString('Duplicate registry line', $this->parseFailureOf('registry.duplicate'));
        $this->assertStringContainsString(
            'Unrecognised classification',
            $this->parseFailureOf('registry.unrecognised'),
        );
        $this->assertStringContainsString('Unrecognised classification', $this->parseFailureOf('registry.no-witness'));
    }

    private function parseFailureOf(string $registry): string
    {
        try {
            $this->overFixtures($registry)->classification();
        } catch (RuntimeException $runtimeException) {
            return $runtimeException->getMessage();
        }

        $this->fail(\sprintf('%s parsed without complaint; the parser degraded it instead of rejecting.', $registry));
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
