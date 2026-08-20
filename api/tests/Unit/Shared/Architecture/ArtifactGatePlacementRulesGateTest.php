<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\ArtifactGatePlacements;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Falsifiability of the rules {@see ArtifactGatePlacementGateTest} asserts over the real tree: the registry
 * is driven across a fixture tree it disagrees with in one way at a time, and its grammar over hand-built
 * lines. The gate over the real tree can only ever exercise the state that tree happens to be in, so without
 * this every one of its refusals is an assertion nothing can reach.
 *
 * The agreeing fixture is asserted first and for the same reason a seed is asserted before an absence: with
 * no green baseline, a rule that answers "wrong" to everything would satisfy every other case here.
 *
 * @internal
 */
#[CoversNothing]
final class ArtifactGatePlacementRulesGateTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/Fixture/ArtifactGatePlacement';

    private const string TREE = self::FIXTURES . '/tree';

    private const string MIRRORED_PROBE = 'tests/Unit/Alpha/MirroredGateProbe.txt';

    private const string HOME_PROBE = 'tests/Unit/Shared/Architecture/HomeGateProbe.txt';

    #[Test]
    public function itAcceptsATreeAndRegistryThatAgree(): void
    {
        $placements = $this->over('placement.complete');

        $this->assertSame([], $placements->unclassified());
        $this->assertSame([], $placements->stale());
        $this->assertSame([], $placements->disagreements());
    }

    #[Test]
    public function itRejectsAGateWithNoLine(): void
    {
        // The direction that separates a detector from a document: a gate filed by copying its neighbours,
        // with nothing recording that a placement was chosen.
        $this->assertSame(
            [self::MIRRORED_PROBE],
            $this->over('placement.incomplete')->unclassified(),
        );
    }

    #[Test]
    public function itRejectsALineNoGateBacks(): void
    {
        $this->assertSame(
            ['tests/Unit/Alpha/VanishedGateProbe.txt'],
            $this->over('placement.stale')->stale(),
        );
    }

    #[Test]
    public function itRejectsAMirrorSubjectTheTreeContradicts(): void
    {
        // `mirrored` is only worth more than the word if the subject is checked against the tree in both
        // ways. Without the first, any existing directory satisfies any placement away from the home;
        // without the second, `src/Support` and `tests/Support` — same mirror, only one of them real —
        // become interchangeable.
        $this->assertArrayHasKey(
            self::MIRRORED_PROBE,
            $this->over('placement.wrong-mirror')->disagreements(),
            'A subject the file does not mirror was accepted.',
        );

        $this->assertArrayHasKey(
            self::MIRRORED_PROBE,
            $this->over('placement.absent-subject')->disagreements(),
            'A subject that is not a directory was accepted.',
        );
    }

    #[Test]
    public function itRejectsAPlacementTheFilesOwnPathContradicts(): void
    {
        // Five directions of one rule. Only the first occurs in the real tree at all, so without these
        // fixtures the other four are live code nothing can reach.
        $this->assertArrayHasKey(
            self::MIRRORED_PROBE,
            $this->over('placement.home-elsewhere')->disagreements(),
            'A `home` line on a file outside the home was accepted.',
        );

        $this->assertArrayHasKey(
            self::MIRRORED_PROBE,
            $this->over('placement.undecided-elsewhere')->disagreements(),
            'An `undecided` line outside the home was accepted.',
        );

        $this->assertArrayHasKey(
            self::HOME_PROBE,
            $this->over('placement.mirrored-in-home')->disagreements(),
            'A `mirrored` line on a file sitting in the home was accepted.',
        );

        $this->assertArrayHasKey(
            self::HOME_PROBE,
            $this->over('placement.functional-in-home')->disagreements(),
            'A `functional` line on a file sitting in the category home was accepted. The token declares '
            . 'the file outside the category, so the home is the one place it cannot be.',
        );

        // Without this direction the token is an escape hatch: it would accept any out-of-home path with a
        // reason attached, where `mirrored` refuses one that does not sit on its subject.
        $this->assertArrayHasKey(
            self::MIRRORED_PROBE,
            $this->over('placement.functional-outside-its-root')->disagreements(),
            'A `functional` line on a file outside tests/Functional/ was accepted.',
        );
    }

    /**
     * @param list<string> $lines
     */
    #[Test]
    #[DataProvider('provideItRefusesAMalformedRegistryLineCases')]
    public function itRefusesAMalformedRegistryLine(array $lines): void
    {
        $this->expectException(RuntimeException::class);

        ArtifactGatePlacements::parse($lines);
    }

    /**
     * One case per grammar refusal, each reduced to the single line (or pair of lines) that trips it —
     * consolidated into one data-driven test rather than one method per case, which is what pushed this
     * class over PHPMD's public-method ceiling the day the bare-root-without-a-slash and empty-path cases
     * were added.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function provideItRefusesAMalformedRegistryLineCases(): iterable
    {
        // `src` mirrors the whole of `tests/Unit`, which is not a placement — and it is the one value that
        // reaches the arm of mirrorOf() whose comment calls it unreachable.
        yield 'a bare source root as a subject' => [
            ['tests/Unit/Alpha/AlphaGateTest.php :: mirrored :: src/'],
        ];

        // The same refusal, spelled without the trailing slash: `str_starts_with('src', 'src/')` is false,
        // so the bare-root check must not depend on the prefix match finding it first.
        yield 'a bare source root missing its trailing slash' => [
            ['tests/Unit/Alpha/AlphaGateTest.php :: mirrored :: src'],
        ];

        // A path-less line would otherwise register under the empty string and surface only later, as a
        // confusing `stale()` entry with no line to point at.
        yield 'a line with no path' => [
            [' :: home'],
        ];

        // PHP array assignment keeps the later value for a repeated key, so without the refusal one of the
        // two classifications is never evaluated while the file still reads as complete.
        yield 'a duplicate path' => [
            [
                'tests/Unit/Alpha/AlphaGateTest.php :: home',
                'tests/Unit/Alpha/AlphaGateTest.php :: mirrored :: src/Alpha',
            ],
        ];

        yield 'an unknown placement' => [
            ['tests/Unit/Alpha/AlphaGateTest.php :: wherever'],
        ];

        // An exemption with no reason is indistinguishable from an oversight, which is the state the
        // classification exists to make impossible.
        yield 'an undecided line with no reason' => [
            ['tests/Unit/Shared/Architecture/AlphaGateTest.php :: undecided'],
        ];

        // Same reason as the undecided case above, for the token added later.
        yield 'a functional line with no reason' => [
            ['tests/Functional/Alpha/AlphaGateTest.php :: functional'],
        ];

        yield 'a mirrored subject outside src and tests' => [
            ['tests/Unit/Alpha/AlphaGateTest.php :: mirrored :: config/Alpha'],
        ];
    }

    private function over(string $registry): ArtifactGatePlacements
    {
        $path = self::FIXTURES . '/' . $registry;

        $this->assertFileExists($path);

        return ArtifactGatePlacements::over(self::TREE, $path, 'Probe.txt');
    }
}
