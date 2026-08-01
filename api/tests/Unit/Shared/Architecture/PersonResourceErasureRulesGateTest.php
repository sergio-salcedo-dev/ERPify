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

    private function overFixtures(string $registry): AuditResourceTypeRegistry
    {
        return new AuditResourceTypeRegistry(
            self::FIXTURES,
            self::FIXTURES . '/Source',
            self::FIXTURES . '/' . $registry,
        );
    }
}
