<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Unit\Gate\Support\BehatVocabularyReader;
use Erpify\Tests\Unit\Gate\Support\StepVocabularyRegistry;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Static gate over the Behat step vocabulary: every declared step pattern is classified in
 * `api/.behat-step-vocabulary`, and the classification is checked against what the features do.
 *
 * The rule it mechanises was written down and left in prose. Prose drifts without anything going red:
 * within weeks the same paragraph counted 205 patterns where there were 209 and 47 features where
 * there were 49, and named a context as wholly idle that thirteen scenarios were reaching. Every gate
 * stayed green through all three, because nothing recomputed any of it. A rule that only lives in
 * prose is not a control, it is an intention — and the point of this one is that idle vocabulary is an
 * asset to spend rather than dead code to delete, which is a claim about a number nobody was keeping.
 *
 * So the numbers move into a file a diff has to touch. Adding a step nobody uses is fine and is meant
 * to be visible; deleting one for being unused stops being a quiet subtraction; and a pattern flipping
 * between used and idle shows up as the coverage event it is.
 *
 * Reading the tree belongs to {@see BehatVocabularyReader}; what is here is the comparison, and the
 * guards that keep it from passing on nothing.
 *
 * @internal
 */
#[CoversNothing]
final class BehatStepVocabularyGateTest extends TestCase
{
    /**
     * Failure preamble — a class const so log scrapers can grep for the literal string.
     */
    public const string FAILURE_PREAMBLE = 'The Behat step vocabulary drifted from api/.behat-step-vocabulary';

    private const string REGISTRY_PATH = '.behat-step-vocabulary';

    /**
     * Classifications a feature may never reach, for two different reasons — kept apart from each
     * other and from `idle` because the count of idle vocabulary is a claim about coverage debt, and
     * neither of these is any.
     *
     * @var list<string>
     */
    private const array UNREACHABLE = [StepVocabularyRegistry::MANUAL, StepVocabularyRegistry::REFUSED];

    private ?BehatVocabularyReader $reader = null;

    public function testEveryDeclaredPatternIsClassifiedAndEveryClassificationHasAPattern(): void
    {
        $declared = $this->declaredPatterns();
        $registered = $this->registry();

        $this->assertSame(
            [],
            \array_values(\array_diff($declared, \array_keys($registered))),
            \sprintf(
                "%s\nStep pattern(s) declared in a context and classified nowhere. Add a line for each: a "
                . 'pattern no feature uses is `idle` (or `manual` for a hand-invocation escape hatch), which '
                . 'is a coverage signal worth recording, not a reason to delete it.',
                self::FAILURE_PREAMBLE,
            ),
        );

        $this->assertSame(
            [],
            \array_values(\array_diff(\array_keys($registered), $declared)),
            \sprintf(
                "%s\nClassified pattern(s) no context declares any more. If a step was renamed, rename its "
                . 'line; if one was deleted, this is the subtraction the rule exists to make visible — say in '
                . 'the PR why the assertion it offered is no longer worth offering.',
                self::FAILURE_PREAMBLE,
            ),
        );
    }

    public function testEveryPatternClassifiedUsedIsReachedByAFeature(): void
    {
        $this->assertSame(
            [],
            $this->patternsWhere(StepVocabularyRegistry::USED, reached: false),
            \sprintf(
                "%s\nPattern(s) recorded as used that no scenario reaches any more. The assertion they were "
                . 'making is no longer being made, which is a coverage loss whether or not it was intended. '
                . 'Reclassify to `idle` in the same commit that stops using them.',
                self::FAILURE_PREAMBLE,
            ),
        );
    }

    public function testEveryPatternClassifiedIdleIsReachedByNoFeature(): void
    {
        $this->assertSame(
            [],
            $this->patternsWhere(StepVocabularyRegistry::IDLE, reached: true),
            \sprintf(
                "%s\nPattern(s) recorded as idle that a scenario now uses. This is the good direction — an "
                . 'assertion that existed and nobody was making is being made. Move them to `used`.',
                self::FAILURE_PREAMBLE,
            ),
        );
    }

    public function testNoCommittedScenarioCallsAStepThatIsNotForScenarios(): void
    {
        $called = [];

        foreach (self::UNREACHABLE as $classification) {
            foreach ($this->patternsWhere($classification, reached: true) as $pattern) {
                $called[] = \sprintf('[%s] %s', $classification, $pattern);
            }
        }

        $this->assertSame(
            [],
            $called,
            \sprintf(
                "%s\nA committed scenario calls a step that is not meant for scenarios.\n"
                . '`manual` is a hand-invocation escape hatch: it prints to the formatter on every CI run, or '
                . 'overrides an automatic BeforeScenario hook and blanks state the scenario had already built, '
                . "after which its later assertions pass against nothing.\n"
                . '`refused` is a superseded phrasing kept registered only so reaching for it returns the '
                . 'canonical form instead of "step undefined" — it throws, so this can only mean a feature has '
                . 'not been migrated.',
                self::FAILURE_PREAMBLE,
            ),
        );
    }

    /**
     * The reader's own falsification. Every check above asks "does any feature reach this pattern", so
     * a matcher that silently stopped matching would report the whole vocabulary as idle and half this
     * file would go quiet. A step resolving to no pattern is either that, or a scenario calling a step
     * that does not exist.
     */
    public function testEveryStepWrittenInAFeatureResolvesToADeclaredPattern(): void
    {
        $this->assertSame(
            [],
            $this->reader()->unresolvedSteps(),
            \sprintf(
                "%s\nStep(s) written in a feature that match no declared pattern. Either a scenario calls a "
                . 'step that does not exist, or this gate has stopped reading the vocabulary and every check '
                . 'above it has gone vacuous.',
                self::FAILURE_PREAMBLE,
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function patternsWhere(string $classification, bool $reached): array
    {
        $found = [];

        foreach ($this->registry() as $pattern => $actual) {
            if ($classification === $actual && $reached === ([] !== $this->reader()->stepsMatching($pattern))) {
                $found[] = $pattern;
            }
        }

        return $found;
    }

    /**
     * Every check below asks a question of two scans, so both empty is the one input that satisfies
     * all five at once. The guard runs per test rather than inside the readers: PHPUnit builds a
     * fresh instance for each, so a guard reachable from only one of them leaves the other four
     * green over a scan of nothing whenever a `--filter` selects around it.
     */
    #[Override]
    protected function setUp(): void
    {
        $this->assertNotEmpty(
            $this->reader()->declaredPatterns(),
            \sprintf(
                '%s%sNo step pattern matched under tests/Behat. Either the contexts no longer declare steps '
                . 'with attributes, or this gate is reading nothing and every check in it is vacuous.',
                self::FAILURE_PREAMBLE,
                PHP_EOL,
            ),
        );

        $this->assertNotEmpty(
            $this->reader()->featureSteps(),
            \sprintf(
                '%s%sNo step line found under features; a zero-step scan would make every check here vacuous.',
                self::FAILURE_PREAMBLE,
                PHP_EOL,
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function declaredPatterns(): array
    {
        return $this->reader()->declaredPatterns();
    }

    /**
     * @return array<string, string> pattern => classification
     */
    private function registry(): array
    {
        $path = $this->apiRoot() . '/' . self::REGISTRY_PATH;
        $contents = \file_get_contents($path);

        $this->assertIsString($contents, \sprintf('%s%sUnreadable at %s.', self::FAILURE_PREAMBLE, PHP_EOL, $path));

        try {
            return StepVocabularyRegistry::parse($contents);
        } catch (RuntimeException $runtimeException) {
            $this->fail(\sprintf('%s%s%s', self::FAILURE_PREAMBLE, PHP_EOL, $runtimeException->getMessage()));
        }
    }

    private function reader(): BehatVocabularyReader
    {
        return $this->reader ??= new BehatVocabularyReader(
            $this->apiRoot() . '/tests/Behat',
            $this->apiRoot() . '/features',
        );
    }

    private function apiRoot(): string
    {
        return \dirname(__DIR__, 4);
    }
}
