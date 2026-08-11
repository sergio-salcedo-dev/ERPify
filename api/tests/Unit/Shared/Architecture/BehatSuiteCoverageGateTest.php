<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Static gate over `api/behat.dist.php`: the acceptance suite must run every scenario in the tree.
 *
 * Nothing counts scenarios against an expected total, so a scenario the suite stops running leaves no
 * trace — the run stays green with a smaller number nobody reads. Two mechanisms can shrink it
 * silently, and this pins both:
 *
 *   (1) The default suite declares its feature roots one by one, so a `.feature` filed outside them is
 *       never collected. `api/features/` holds exactly the three roots today, which is what makes the
 *       trap easy to fall into: a new bounded context landing its features in a fourth directory reads
 *       as green because its scenarios were never parsed, not because they passed.
 *
 *   (2) A filter on the default profile excludes scenarios by tag. An exclusion is invisible in the
 *       summary line and invites parking a red scenario behind a tag.
 *
 * Textual rather than by loading the config: `Behat\Config\*` is a prerelease API this repo pins at
 * `^4.0@alpha`, so introspecting the object would couple the gate to a shape upstream may still change.
 *
 * @internal
 */
#[CoversNothing]
final class BehatSuiteCoverageGateTest extends TestCase
{
    /**
     * Failure preamble — a class const so log scrapers can grep for the literal string.
     */
    public const string FAILURE_PREAMBLE
        = 'The Behat suite must run every scenario under api/features. See api/behat.dist.php';

    private const string CONFIG_PATH = 'behat.dist.php';

    private const string FEATURES_DIRECTORY = 'features';

    /**
     * Feature roots the default suite declares, as `'%paths.base%/features/<name>'`. Anchored on
     * `features/` so the extension's `bootstrap` path — quoted the same way — is not read as one.
     */
    private const string SUITE_PATH_PATTERN = "/'%paths\\.base%\\/(features\\/[^']+)'/";

    public function testEveryFeatureFileSitsUnderADeclaredSuitePath(): void
    {
        $declared = $this->declaredSuitePaths();
        $uncollected = [];

        foreach ($this->featureFiles() as $relative) {
            if (!$this->isUnderAnyOf($relative, $declared)) {
                $uncollected[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $uncollected,
            \sprintf(
                "%s\nFeature file(s) under no declared suite path, so their scenarios never run:\n%s\n"
                . "Declared paths:\n%s",
                self::FAILURE_PREAMBLE,
                \implode("\n", $uncollected),
                \implode("\n", $declared),
            ),
        );
    }

    public function testTheDefaultProfileExcludesNoScenario(): void
    {
        $this->assertStringNotContainsString(
            'withFilter(',
            $this->readConfig(),
            self::FAILURE_PREAMBLE
            . "\nThe default profile declares a filter. Whatever it excludes stops being executed, and "
            . 'the summary line reports the smaller number as success — which is how a red scenario gets '
            . 'parked behind a tag and stays parked. An exclusion that is genuinely wanted belongs on a '
            . 'named profile the default run does not use.',
        );
    }

    public function testTheDefaultProfileInterpretsResultsStrictly(): void
    {
        // The empty argument list is the assertion, not the call: the option takes a boolean and
        // `withStrictResultInterpretation(false)` reads as declared while turning strictness off.
        $this->assertStringContainsString(
            'withStrictResultInterpretation()',
            $this->readConfig(),
            self::FAILURE_PREAMBLE
            . "\nThe default profile does not declare strict result interpretation. Behat's default "
            . 'counts a step nothing defines as neither pass nor failure — UNDEFINED is 30, below '
            . "FAILED's 99 — so a suite whose vocabulary has quietly shrunk still exits 0, which is "
            . 'the shape this gate exists to deny. A context dropped from the profile, or an assertion '
            . 'whose phrase was renamed, then removes the checking and leaves every scenario reporting '
            . 'as though it ran.',
        );
    }

    /**
     * @return list<string>
     */
    private function declaredSuitePaths(): array
    {
        \preg_match_all(self::SUITE_PATH_PATTERN, $this->readConfig(), $matches);

        /** @var list<string> $paths */
        $paths = \array_values(\array_unique($matches[1]));

        $this->assertNotEmpty(
            $paths,
            \sprintf(
                '%s%sNo suite path matched in %s. Either the suite declares none — every scenario in the '
                . 'tree would then go uncollected — or the config no longer spells its paths as '
                . "'%%paths.base%%/features/…' and this gate is reading nothing.",
                self::FAILURE_PREAMBLE,
                PHP_EOL,
                self::CONFIG_PATH,
            ),
        );

        return $paths;
    }

    /**
     * Every `.feature` in the tree, relative to `api/` — including any outside the declared roots,
     * which is the whole point of walking the filesystem instead of the config.
     *
     * @return list<string>
     */
    private function featureFiles(): array
    {
        $root = $this->apiRoot() . '/' . self::FEATURES_DIRECTORY;
        $prefix = \strlen($this->apiRoot()) + 1;
        $relative = [];

        // Depth-unbounded on purpose: a scan that stops at a fixed nesting level has the very blind
        // spot this gate exists to deny, one directory further down.
        $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($tree as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && 'feature' === $file->getExtension()) {
                $relative[] = \substr($file->getPathname(), $prefix);
            }
        }

        $this->assertNotEmpty(
            $relative,
            \sprintf(
                '%s%sNo .feature file found under %s; a zero-feature scan would make this gate vacuous.',
                self::FAILURE_PREAMBLE,
                PHP_EOL,
                $root,
            ),
        );

        \sort($relative);

        return $relative;
    }

    /**
     * @param list<string> $paths
     */
    private function isUnderAnyOf(string $relative, array $paths): bool
    {
        return \array_any($paths, static fn (string $path): bool => \str_starts_with($relative, $path . '/'));
    }

    private function readConfig(): string
    {
        $path = $this->apiRoot() . '/' . self::CONFIG_PATH;
        $config = \file_get_contents($path);

        $this->assertIsString($config, \sprintf('%s%sUnreadable at %s.', self::FAILURE_PREAMBLE, PHP_EOL, $path));

        return $config;
    }

    private function apiRoot(): string
    {
        return \dirname(__DIR__, 4);
    }
}
