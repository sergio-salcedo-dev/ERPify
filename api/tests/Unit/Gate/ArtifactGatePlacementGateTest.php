<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\ArtifactGatePlacements;
use Erpify\Tests\Support\ArtifactGateSweep;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Static gate over `api/.artifact-gate-placement`: where every artifact gate in the tree is filed, and why.
 *
 * The convention it mechanises was load-bearing long before it was written down. Twelve `php.lint.*` targets
 * select their gate classes out of this one directory by `--filter` and a thirteenth selects one that
 * deliberately sits elsewhere, while no rule anywhere said which case a new gate was in — so the placement
 * was decided by whichever neighbour got copied. That is how one subject ended up in three places at once.
 *
 * Three directions, all mechanical:
 *
 *   - **Completeness** — every gate the sweep finds must carry a line. This is the direction that separates
 *     a detector from a document: without it a new gate is filed by imitation and the registry agrees.
 *   - **Staleness** — every line must still match a gate that exists, so the file is a live inventory rather
 *     than a graveyard. With both directions pinned to the tree, a line cannot be its own evidence.
 *   - **Agreement** — the placement a line declares must be the placement the file's own path has. `home`
 *     must sit in the home; `mirrored` must sit on the subject it names, and that subject must exist.
 *
 * The two directions together also make the sweep unable to fail quietly. A detector that stopped seeing
 * things would turn its lines stale; one that started seeing more would leave the newcomers unclassified.
 * Neither degrades into a green over nothing, which is why this gate needs no separate floor on the count.
 *
 * What it deliberately cannot do is judge a classification — `mirrored` over a gate that sweeps the whole
 * tree passes, and review is the only control on that direction. The registry header states that limit and
 * the rest of the blind spots in full.
 *
 * @internal
 */
#[CoversNothing]
final class ArtifactGatePlacementGateTest extends TestCase
{
    private const string REGISTRY = '.artifact-gate-placement';

    /**
     * The rule engines still outside {@see ArtifactGateSweep::ENGINE_HOME}. A ratchet: the set may shrink,
     * never grow. One of the two is imported from outside this directory — by two tests under
     * `tests/Unit/Behat/` — which is the whole argument for a single engine home: an engine filed under a
     * gate's folder can only be reached by naming that folder.
     *
     * @var list<string>
     */
    private const array GRANDFATHERED_ENGINES = [
        'BehatVocabularyReader.php',
        'StepVocabularyRegistry.php',
    ];

    #[Test]
    public function itClassifiesEveryArtifactGateInTheTree(): void
    {
        $this->assertSame(
            [],
            $this->placements()->unclassified(),
            'An artifact gate carries no line in .artifact-gate-placement. Classify it `home` if it belongs '
            . 'in the category home, or `mirrored :: <directory>` if it is scoped to one module and sits on '
            . 'it — the registry header states how to decide which.',
        );
    }

    #[Test]
    public function itCarriesNoLineTheTreeNoLongerBacks(): void
    {
        $this->assertSame(
            [],
            $this->placements()->stale(),
            'A line in .artifact-gate-placement names a file the sweep does not find. Either the gate moved '
            . 'or was renamed — a rename is also a wiring change, since the make targets select by class '
            . 'name — or it stopped being an artifact gate and the line should go with it.',
        );
    }

    #[Test]
    public function itPlacesEveryGateWhereItsLineSaysItIs(): void
    {
        $this->assertSame(
            [],
            $this->placements()->disagreements(),
            'The placement a line declares is not the placement the file has.',
        );
    }

    /**
     * A rule engine is the reusable half of a gate, and one filed under a gate's own folder can only be
     * reached by naming that folder — which two tests under `tests/Unit/Behat/` already do. The set below is
     * closed downward, and the only sanctioned way to empty it is to MOVE both engines to the engine home.
     * The early return is otherwise a green over an empty claim — though it is not what a plain rename runs
     * into first: measured, renaming the directory leaves five importers naming a namespace that no longer
     * resolves and PHPUnit exits 255 before it can build the suite, so this arm is the second reader.
     *
     * This reads one directory, so an engine filed under a THIRD name elsewhere is invisible. That is the
     * gate's scope, not an oversight, and the registry header states it.
     */
    #[Test]
    public function itKeepsTheRuleEnginesInOnePlace(): void
    {
        $apiRoot = $this->apiRoot();
        $directory = $apiRoot . '/' . ArtifactGateSweep::GRANDFATHERED_ENGINE_HOME;

        if (!\is_dir($directory)) {
            foreach (self::GRANDFATHERED_ENGINES as $engine) {
                $this->assertFileExists(
                    $apiRoot . '/' . ArtifactGateSweep::ENGINE_HOME . '/' . $engine,
                    \sprintf(
                        '%s is gone but %s is not in %s. The set empties by moving the engines home, never '
                        . 'by renaming the directory around them.',
                        ArtifactGateSweep::GRANDFATHERED_ENGINE_HOME,
                        $engine,
                        ArtifactGateSweep::ENGINE_HOME,
                    ),
                );
            }

            return;
        }

        // An unreadable directory FAILS rather than reading as empty: `?: []` here would report the same
        // green as a directory that really holds nothing.
        $entries = \scandir($directory);
        $this->assertIsArray($entries, \sprintf('%s cannot be read.', ArtifactGateSweep::GRANDFATHERED_ENGINE_HOME));

        $engines = \array_values(\array_diff($entries, ['.', '..']));

        $this->assertSame(
            [],
            \array_values(\array_diff($engines, self::GRANDFATHERED_ENGINES)),
            \sprintf(
                'A rule engine was added to %s. Engines live in %s, where every gate can reach one without '
                . "importing another gate's folder.",
                ArtifactGateSweep::GRANDFATHERED_ENGINE_HOME,
                ArtifactGateSweep::ENGINE_HOME,
            ),
        );

        // The "no extras" check above cannot see an engine that vanished from this directory without
        // landing in the engine home — deleted rather than moved. A missing entry silently satisfies
        // array_diff's emptiness, so a departed engine is checked for at its only sanctioned destination.
        foreach (self::GRANDFATHERED_ENGINES as $engine) {
            if (\in_array($engine, $engines, true)) {
                continue;
            }

            $this->assertFileExists(
                $apiRoot . '/' . ArtifactGateSweep::ENGINE_HOME . '/' . $engine,
                \sprintf(
                    '%s is gone from %s but not in %s. The set empties by moving the engines home, never by '
                    . 'deleting them out of it.',
                    $engine,
                    ArtifactGateSweep::GRANDFATHERED_ENGINE_HOME,
                    ArtifactGateSweep::ENGINE_HOME,
                ),
            );
        }
    }

    private function placements(): ArtifactGatePlacements
    {
        $apiRoot = $this->apiRoot();
        $registry = $apiRoot . '/' . self::REGISTRY;

        $this->assertFileExists($registry, 'The placement registry is not reachable from the suite.');

        return ArtifactGatePlacements::over($apiRoot, $registry);
    }

    /**
     * An unresolvable root FAILS rather than skipping: a sweep that quietly finds nothing to look at reports
     * the same green as a real pass.
     */
    private function apiRoot(): string
    {
        $root = \dirname(__DIR__, 3);

        $this->assertDirectoryExists($root . '/tests', 'The test tree is not reachable from the suite.');

        return $root;
    }
}
