<?php

declare(strict_types=1);

namespace Erpify\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Which test classes are artifact gates — the universe `api/.artifact-gate-placement` has to classify.
 *
 * An artifact gate is a kernel-free test whose subject is a repository artifact rather than a running
 * behaviour: it reads the tree (or a registry, a compose file, a doc) as data and asserts a rule over it.
 * The mechanism is what defines the category, not the subject, which is why the detector below looks at how
 * a test is built and never at what it is about.
 *
 * Two sources, deliberately asymmetric:
 *
 *   - **Location.** Every test file under {@see self::HOME} is in the universe whatever it contains. That
 *     half needs no detector and cannot be evaded, which is what makes the completeness check over the home
 *     airtight.
 *   - **Shape.** Elsewhere, a test qualifies when it is kernel-free, credits no production coverage, and
 *     either climbs out of its own directory to the filesystem or reaches the tree through a rule engine.
 *
 * The shape half is a CONSERVATIVE approximation and under-approximates on purpose; its blind spots are
 * enumerated in the registry header. What keeps it from drifting is the registry itself: the gate compares
 * this sweep against the file in both directions, so a detector that stops seeing something turns its line
 * stale, and one that starts seeing more leaves the newcomer unclassified. Neither direction can go quiet.
 *
 * @internal test support
 */
final class ArtifactGateSweep
{
    /**
     * The category's home, api-relative. The path is HISTORICAL: it names the folder's founding member, a
     * bounded-context isolation gate, and never tracked what landed on it afterwards.
     */
    public const string HOME = 'tests/Unit/Shared/Architecture';

    /** Where a rule engine belongs. */
    public const string ENGINE_HOME = 'tests/Support';

    /**
     * The one other directory that still holds rule engines, and a ratchet: it may only shrink. One of its
     * two files is imported from outside the home, which is the argument for the engine home above.
     */
    public const string GRANDFATHERED_ENGINE_HOME = self::HOME . '/Support';

    /**
     * @var list<string> the two engine namespaces, matched as import prefixes. Enumerated rather than derived
     *                   from a trailing `Support\` segment, which was measured to catch every unit test of the
     *                   Behat support classes — tests of test infrastructure, not gates
     */
    private const array ENGINE_NAMESPACES = [
        'Erpify\Tests\Support\\',
        'Erpify\Tests\Unit\Shared\Architecture\Support\\',
    ];

    /**
     * Kernel-free means PHPUnit's own `TestCase` and nothing derived from it. Pinned to the import as well
     * as the `extends`, so a same-named class from anywhere else does not read as kernel-free — and the
     * import is read for its alias, because `use … as X` renames the token the `extends` then carries.
     */
    private const string TEST_CASE_IMPORT = '/\buse\s+PHPUnit\\\Framework\\\TestCase(?:\s+as\s+(\w+))?\s*;/';

    private const string EXTENDS_TEST_CASE_FQ
        = '/\bclass\s+\w+\s+extends\s+\\\?PHPUnit\\\Framework\\\TestCase\b/';

    /** Everything under `tests/` lives here, so a class credited from this namespace is not production. */
    private const string TEST_NAMESPACE = 'Erpify\Tests\\';

    /**
     * The ways a test names a path outside its own directory: a climb (`dirname(__DIR__…)`, `__DIR__ . '/..`,
     * and the `__FILE__` spellings of both) or a root taken from the process (`getcwd()`, `realpath()`). Not
     * exhaustive, and the registry header lists what it still misses.
     */
    private const string REACHES_OUT
        = '/\\\?dirname\s*\(\s*__(?:DIR|FILE)__|__(?:DIR|FILE)__\s*\.\s*[\x27"]\/\.\.'
        . '|\\\?getcwd\s*\(|\\\?realpath\s*\(/';

    /**
     * Reflection is the other route to the tree and the one no path expression shows: a gate that asks a
     * class where it lives never writes a path at all. One gate in the tree reaches it only this way.
     */
    private const string REFLECTS_A_FILE = '/\bgetFileName\s*\(/';

    /**
     * Every coverage attribute that credits a target, matched WITHOUT the `#[` anchor so a grouped list
     * (`#[CoversClass(A::class), CoversClass(B::class)]`) is read past its first member — which was measured
     * hiding a production target behind a test-namespace one.
     */
    private const string COVERS_ATTRIBUTE
        = '/Covers(?:Class|Trait|Method|Function)\s*\(\s*\\\?([A-Za-z0-9_\\\]+)\s*::class/';

    /**
     * Every artifact gate under `$apiRoot`, api-relative and sorted.
     *
     * `$suffix` is PHPUnit's discovery suffix. It is a parameter only so the rules gate can drive this over a
     * fixture tree whose files must reach neither the real suite nor a fixer — the fixtures are `.txt`
     * holding PHP source, since this reads text and a rewritten fixture is a changed input. The production
     * value is pinned by the tree, since a wrong one empties the sweep and turns every registry line stale.
     *
     * @return list<string>
     */
    public static function over(string $apiRoot, string $suffix = 'Test.php'): array
    {
        $testsRoot = $apiRoot . '/tests';

        if (!\is_dir($testsRoot)) {
            return [];
        }

        $paths = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($testsRoot, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            if (!\str_ends_with($file->getFilename(), $suffix)) {
                continue;
            }

            $source = \file_get_contents($file->getPathname());

            if (false === $source) {
                throw new RuntimeException(\sprintf('Cannot read test file "%s".', $file->getPathname()));
            }

            $relative = \substr($file->getPathname(), \strlen($apiRoot) + 1);

            if (self::isArtifactGate($relative, $source)) {
                $paths[] = $relative;
            }
        }

        \sort($paths);

        return $paths;
    }

    /**
     * Whether the test at `$apiRelativePath` with this `$source` is an artifact gate.
     *
     * Read over comment-stripped source throughout: a docblock explaining that a gate reads `api/src` must
     * not be what makes it one, and the whole category exists because prose is not evidence.
     */
    public static function isArtifactGate(string $apiRelativePath, string $source): bool
    {
        if (\str_starts_with($apiRelativePath, self::HOME . '/')) {
            return true;
        }

        // A file that is not PHP is classified by its prose alone, and prose standing in for code is the
        // exact substitution this whole category exists to refuse.
        if (!\str_contains($source, '<?php')) {
            return false;
        }

        $code = self::flatten(PhpSource::withoutComments($source));

        if (!self::isKernelFree($code)) {
            return false;
        }

        if (self::creditsProductionCoverage($code)) {
            return false;
        }

        return 1 === \preg_match(self::REACHES_OUT, $code)
            || 1 === \preg_match(self::REFLECTS_A_FILE, $code)
            || self::importsARuleEngine($code);
    }

    /**
     * An intermediate abstract base between the class and `TestCase` is invisible here — a text matcher
     * cannot follow one, and the registry header says so. Nothing in the tree has one today.
     */
    private static function isKernelFree(string $code): bool
    {
        if (1 === \preg_match(self::EXTENDS_TEST_CASE_FQ, $code)) {
            return true;
        }

        if (1 !== \preg_match(self::TEST_CASE_IMPORT, $code, $import)) {
            return false;
        }

        $alias = '' === ($import[1] ?? '') ? 'TestCase' : $import[1];

        return 1 === \preg_match('/\bclass\s+\w+\s+extends\s+' . \preg_quote($alias, '/') . '\b/', $code);
    }

    private static function flatten(string $code): string
    {
        return \preg_replace('/\s+/', ' ', $code) ?? $code;
    }

    /**
     * A test crediting a production class is a behavioural test whose placement follows its subject, even
     * when one of its assertions reads that subject's source. Crediting only `Erpify\Tests\…` is what a gate
     * does: the coverage allowlist stops at `src`, so there is no production line for it to claim.
     */
    private static function creditsProductionCoverage(string $code): bool
    {
        \preg_match_all(self::COVERS_ATTRIBUTE, $code, $matches);

        return \array_any(
            $matches[1],
            static fn (string $reference): bool => !\str_starts_with(
                self::resolve($reference, $code),
                self::TEST_NAMESPACE,
            ),
        );
    }

    /**
     * A short class name resolves through its import; with none it belongs to the file's own namespace, which
     * for anything under `tests/` is `Erpify\Tests\…`. An ALIASED import (`use X as Y`) is not resolved and
     * reads as same-namespace — the one direction where this fails toward "not production".
     */
    private static function resolve(string $reference, string $code): string
    {
        $reference = \ltrim($reference, '\\');

        if (\str_contains($reference, '\\')) {
            return $reference;
        }

        $pattern = '/\buse\s+([A-Za-z0-9_\\\]+\\\\' . \preg_quote($reference, '/') . ')\s*;/';

        if (1 === \preg_match($pattern, $code, $import) && isset($import[1])) {
            return $import[1];
        }

        return self::TEST_NAMESPACE . $reference;
    }

    private static function importsARuleEngine(string $code): bool
    {
        return \array_any(
            self::ENGINE_NAMESPACES,
            static fn (string $namespace): bool => \str_contains($code, 'use ' . $namespace),
        );
    }
}
