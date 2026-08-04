<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\AuditResourceTypeRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Falsifiability of the REGISTRY rules {@see PersonResourceErasureGateTest} asserts over the real tree: it
 * drives {@see AuditResourceTypeRegistry} across a fixture tree, so each check is shown to go red for the
 * reason claimed rather than being taken on trust.
 *
 * Two of them carry the weight. The staleness narrowing has to report a graveyard entry and spare a person
 * line in the same run, because a rule that did neither and a rule that did both are both wrong and neither
 * is visible from one half alone. And the parser has to REJECT rather than degrade: every person-only rule
 * skips whatever is not a person line, so a spelling that quietly falls through to `non-person` takes the
 * erasure obligation with it.
 *
 * Separate from {@see PersonResourceErasureWitnessGateTest} along the seam the production code already has,
 * and the make target selects both through a common prefix rather than naming either.
 *
 * @internal
 */
#[CoversNothing]
final class PersonResourceErasureRulesGateTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/Fixture/PersonResource';

    private const string TYPE = 'FixtureResource';

    private const string IMPORTED_TYPE = 'ImportedFixtureResource';

    private const string REAL_OWNER = 'src/Iam/Identity/Application/FulfilIdentityErasure.php';

    #[Test]
    public function theStalenessCheckReportsTheGraveyardAndSparesThePersonLine(): void
    {
        // One assertion for both halves of the narrowing, because either half alone is satisfiable by the
        // wrong rule: a check that reported neither would look identical to one that spares person lines,
        // and a check that reported both would be the circular one this narrowing replaced.
        $this->assertSame(
            ['Ghost'],
            $this->overFixtures('registry.stale')->staleNonPersonTypes(),
            'The staleness check no longer separates a graveyard entry from a person line whose literal '
            . 'lives in the constant its own owner holds.',
        );
        // The clean twin belongs in the same case: reporting `Ghost` proves nothing about the rule if the
        // check also reports types that ARE written, and only a registry with none can show that.
        $this->assertSame([], $this->overFixtures('registry.complete')->staleNonPersonTypes());
    }

    #[Test]
    public function theDerivationRuleRejectsAnOwnerThatErasesATypeItNeverBuilds(): void
    {
        // ReceivingEraserFixture holds the anonymiser, calls it and names the type, so the wiring rule is
        // satisfied — and the resource it erases arrives as a parameter, so whoever writes the identifier is
        // named nowhere. Green on every earlier check is exactly what makes this the shape worth refusing.
        $registry = $this->overFixtures('registry.owner-not-deriving');

        $this->assertNotContains(
            'src/ReceivingEraserFixture.php',
            $registry->filesDerivingType(self::TYPE),
            "An owner that never builds the resource it erases was accepted as the type's deriving file.",
        );
        $this->assertNull(
            $registry->erasureDefectIn(self::TYPE, 'src/ReceivingEraserFixture.php'),
            'The fixture no longer passes the wiring rule, so the derivation rule is no longer isolated.',
        );
        // Both halves in one case, because either alone is satisfiable by the wrong rule: a check that
        // accepted nothing would look identical to one that rejects receivers, and a check that accepted
        // everything would be no rule at all.
        $this->assertContains(
            'src/AuditResourceFixtureWriter.php',
            $this->overFixtures('registry.complete')->filesDerivingType(self::TYPE),
        );
    }

    #[Test]
    public function theSweepResolvesATypeNamedOnlyThroughAnotherClassesConstant(): void
    {
        // The writer that matters most in the real tree spells no literal at all: the erasure of a natural
        // person reaches its resource type through the constant the owning context declares. A sweep that
        // only understood `self::` would see no writer, demand no line, and therefore name nobody as obliged
        // to erase it — the registry would be complete and empty of the one obligation it exists for.
        $types = $this->overFixtures('registry.complete')->resourceTypesInSource();

        $this->assertContains(self::IMPORTED_TYPE, $types, \sprintf(
            'The sweep did not resolve %s::IMPORTED_TYPE, so a type named only through an imported constant '
            . 'never enters the universe and no line is ever demanded for it.',
            'ImportedConstantHolderFixture',
        ));
    }

    #[Test]
    public function theFixtureSourceIsActuallyScanned(): void
    {
        // If the fixture source root resolved to nothing, every fixture check above would pass while
        // scanning an empty tree — including the staleness one, whose whole point is a type that IS written.
        $registry = $this->overFixtures('registry.complete');

        $this->assertSame([self::TYPE, self::IMPORTED_TYPE], $registry->resourceTypesInSource());
        $this->assertSame(
            ['src/AnonymiserHolderFixture.php', 'src/AuditResourceFixtureWriter.php', 'src/ReceivingEraserFixture.php'],
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

    #[Test]
    public function theWiringCheckAcceptsAnOwnerThatAnonymises(): void
    {
        $this->assertNull(
            $this->registry()->erasureDefectIn('User', self::REAL_OWNER),
            'The wiring rule rejected the owner that genuinely holds the anonymiser, calls it and carries '
            . 'the type, so the reds below prove nothing.',
        );
    }

    #[Test]
    public function theWiringCheckRejectsAnOwnerThatHoldsNoAnonymiser(): void
    {
        // DbalAuditLogWriter names the anonymiser in a DOCBLOCK and nowhere else, so it is also what pins
        // the comment stripping: read raw, its prose would pass for a collaborator.
        $defect = $this->registry()->erasureDefectIn(
            'User',
            'src/Shared/Audit/Infrastructure/Persistence/DbalAuditLogWriter.php',
        );

        $this->assertNotNull($defect, 'A file that only mentions the anonymiser was accepted as erasing.');
        $this->assertStringContainsString('holds no AuditResourceAnonymiser property', $defect);
    }

    #[Test]
    public function theWiringCheckRejectsAnOwnerThatHoldsOneButNeverCallsIt(): void
    {
        // The middle red, and the one with no counterpart in the real tree — every file there that holds an
        // anonymiser also calls it. Without the fixture this branch is unreachable, and the whole rule could
        // be replaced by `return null;` with every gate still green.
        $defect = $this->overFixtures('registry.complete')
            ->erasureDefectIn(self::TYPE, 'src/AnonymiserHolderFixture.php')
        ;

        $this->assertNotNull($defect, 'An owner that holds the anonymiser and never calls it was accepted.');
        $this->assertStringContainsString('never calls anonymise() on it', $defect);
    }

    #[Test]
    public function theWiringCheckRejectsAnOwnerThatDoesNotCarryTheType(): void
    {
        // Holding the anonymiser and calling it is not enough: the owner has to name the type it claims to
        // erase, or the same file would answer for every type declared against it.
        $defect = $this->registry()->erasureDefectIn('Ghost', self::REAL_OWNER);

        $this->assertNotNull($defect, 'An owner that never names the type was accepted as erasing it.');
        $this->assertStringContainsString('does not carry the "Ghost" literal', $defect);
    }

    #[Test]
    public function theWiringCheckRejectsAPathThatIsNotSourceAndOneThatEscapes(): void
    {
        // The directory branch of the shared path helper is pinned once, on the witness side, where a
        // fixture directory named like a witness exists to reach it. What a directory under `src/` reaches
        // here is the shape check, which is the earlier and more common rejection.
        $registry = $this->registry();

        $this->assertStringContainsString(
            'is not a .php file under src/',
            (string) $registry->erasureDefectIn('User', 'src/Iam/Identity/Application'),
        );
        $this->assertStringContainsString(
            'escapes the repository',
            (string) $registry->erasureDefectIn('User', 'src/../' . self::REAL_OWNER),
        );
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
            self::FIXTURES . '/src',
            self::FIXTURES . '/' . $registry,
        );
    }
}
