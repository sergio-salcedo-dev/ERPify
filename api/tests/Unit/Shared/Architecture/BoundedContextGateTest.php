<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Static gate for bounded-context isolation in the modular monolith. ERPify is
 * one physical database with code split into top-level contexts
 * (`Backoffice/`, `Frontoffice/`, `Shared/`), each carrying second-level
 * business contexts (`Backoffice/Bank`, `Backoffice/BankAccount`, …). The rule
 * is *enforce boundaries, not total isolation*: a context references another's
 * identities and reacts to its events, but never knows its internals. Full
 * statement: docs/rules/database.md#bounded-context-data-isolation-modular-monolith.
 *
 * Three coupling levels, two of them machine-checked here:
 *
 *   🔴 Level 1 (ERROR — fails the gate). A file in business context A that
 *      imports the `Domain`/`Application`/`Infrastructure` namespace of a
 *      *different* business context B. This is the "knows another context's
 *      internals" defect — including injecting another context's repository
 *      (repository interfaces live in `Domain\Repository`). `Erpify\Shared\…`
 *      is the shared kernel and is always importable. The only other allowed
 *      seams — a context's published Application service interface and its
 *      integration-event classes — are declared in `api/.bounded-context-allowlist`.
 *
 *   🟡 Level 2 (WARNING — reported, never fails). A cross-context Doctrine
 *      association (`#[ORM\ManyToOne]` / `#[ORM\OneToOne]` / …) whose
 *      `targetEntity` resolves to another business context's entity. The repo
 *      default is a bare UUID id column; a real cross-context FK is a soft rule
 *      to justify in the PR, not a block. Printed to STDERR for visibility.
 *
 *   🟢 Level 3 (allowed). Shared kernel (`Erpify\Shared\…`: `User`, tenant,
 *      `Money`, `Uuid`, `Media`), ID-only references (plain strings — no import,
 *      naturally invisible to the gate), event integration, and read models.
 *
 * The matcher walks each `.php` file under `api/src/`, derives the file's owning
 * context from its path, tokenizes its `use Erpify\…;` imports (group-use- and
 * alias-aware), and flags every cross-context guarded-layer import not covered by
 * the allowlist.
 *
 * Scope of the guarantee: the gate catches coupling expressed as a `use` import —
 * the overwhelmingly common shape. It does NOT catch a foreign class named by a
 * fully-qualified string built at runtime, reached by reflection, or resolved
 * through a container alias; nor does ID-only referencing (a plain string) count
 * as coupling. The gate enforces the boundary at the import seam, not total
 * runtime independence.
 *
 * Failure output:
 *
 *   Cross-context import of another context's Domain/Application is forbidden
 *   (Level 1). Reference identities and react to events instead.
 *   See docs/rules/database.md#bounded-context-data-isolation-modular-monolith
 *   <relative/path.php>:<line>: <A/B> imports <Imported\Fqcn>
 *   ...
 *
 * @internal
 *
 * @phpstan-type Seam array{file: string, target: string}
 * @phpstan-type Allowlist array{files: list<string>, seams: list<Seam>, globalSeams: list<string>}
 *
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 */
#[CoversNothing]
final class BoundedContextGateTest extends TestCase
{
    /**
     * Failure preamble — kept as a class const so make-target wrappers and CI
     * log scrapers can grep for the literal string.
     */
    public const string FAILURE_PREAMBLE
        = "Cross-context import of another context's Domain/Application is forbidden (Level 1). "
        . 'Reference identities and react to events instead. '
        . 'See docs/rules/database.md#bounded-context-data-isolation-modular-monolith';

    /**
     * Cross-context import layers that constitute the Level 1 defect: knowing a
     * foreign context's internals. All three of a business context's layers are
     * guarded — importing another context's `Infrastructure` (a concrete Doctrine
     * repository, controller, or mapper) is as much a boundary breach as its
     * `Domain`/`Application`. The only sanctioned cross-context seams are a
     * context's published Application service interface and its integration-event
     * classes, carried by `api/.bounded-context-allowlist`.
     *
     * @var list<string>
     */
    private const array GUARDED_LAYERS = ['Domain', 'Application', 'Infrastructure'];

    /**
     * Top-level namespace segment of the shared kernel: always importable from
     * any context, so it never produces a Level 1 hit.
     */
    private const string SHARED_TOP = 'Shared';

    public function testNoCrossContextDomainOrApplicationImports(): void
    {
        $hits = $this->scanForCrossContextImports();

        if ([] === $hits) {
            // Empty result is the green path. Pin the assertion count so PHPUnit
            // doesn't flag this as a "risky test" with no assertions.
            $this->addToAssertionCount(1);

            return;
        }

        $message = self::FAILURE_PREAMBLE . "\n" . \implode("\n", \array_map(
            static fn (array $hit): string => \sprintf(
                '%s:%d: %s imports %s',
                $hit['file'],
                $hit['line'],
                $hit['owner'],
                $hit['target'],
            ),
            $hits,
        ));

        $this->fail($message);
    }

    public function testCrossContextForeignKeysAreReportedNotFailed(): void
    {
        // Level 2 is a soft rule: collect and surface cross-context Doctrine
        // associations, but never fail the build on them.
        $warnings = $this->scanForCrossContextAssociations();

        if ([] !== $warnings) {
            \fwrite(STDERR, "\n[bounded-context] Level 2 cross-context FK warnings "
                . "(soft rule — justify in the PR, prefer a bare UUID id column):\n");

            foreach ($warnings as $warning) {
                \fwrite(STDERR, \sprintf(
                    "  %s:%d: %s -> %s\n",
                    $warning['file'],
                    $warning['line'],
                    $warning['owner'],
                    $warning['target'],
                ));
            }
        }

        // The gate never blocks on Level 2 — the warnings are diagnostic only.
        // Pin the assertion count so PHPUnit doesn't flag this as a risky test.
        $this->addToAssertionCount(1);
    }

    public function testGateScansAtLeastOneSourceFile(): void
    {
        // Pins that the iterator wiring resolves to a non-empty set — a silent
        // zero-file scan would make the gate vacuous and let drift merge.
        $count = \iterator_count(ApiSourceFiles::phpFiles($this->apiSrcRoot()));

        $this->assertGreaterThan(0, $count, 'Bounded-context gate scanned zero files.');
    }

    public function testFixtureExposesGateMatcher(): void
    {
        // Drift fixture: BankAccount importing Bank's domain entity is the exact
        // coupling docs/adr/bank-bankaccount-modeling.md removed (D1).
        $driftSource = <<<'PHP'
            <?php
            namespace Erpify\Backoffice\BankAccount\Domain\Entity;
            use Erpify\Backoffice\Bank\Domain\Entity\Bank;
            final class BankAccount {}
            PHP;

        $hits = $this->matchCrossContextImports($driftSource, 'Backoffice/BankAccount');

        $this->assertNotEmpty(
            $hits,
            'Gate matcher failed to flag a cross-context Domain import — the parser has regressed.',
        );
        $this->assertSame(\Erpify\Backoffice\Bank\Domain\Entity\Bank::class, $hits[0]['target']);

        // Clean fixture: a shared-kernel import and a same-context import are both
        // allowed and must not be flagged.
        $cleanSource = <<<'PHP'
            <?php
            namespace Erpify\Backoffice\BankAccount\Domain\Entity;
            use Erpify\Shared\Uuid\Domain\Uuid;
            use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
            final class BankAccount {}
            PHP;

        $this->assertSame(
            [],
            $this->matchCrossContextImports($cleanSource, 'Backoffice/BankAccount'),
            'Gate matcher flagged a shared-kernel / same-context import — false positive.',
        );
    }

    public function testGateCatchesGroupedAndAliasedImports(): void
    {
        // The tokenizer-based parser must not be evadable via grouped imports,
        // aliases, a cross-line statement, or a cross-context Infrastructure
        // import; closure `use` and `use function` must stay invisible.
        $source = <<<'PHP'
            <?php
            namespace Erpify\Backoffice\BankAccount\Application;
            use Erpify\Backoffice\Bank\Domain\{
                Entity\Bank,
                Repository\BankRepository
            };
            use Erpify\Backoffice\Bank\Infrastructure\Persistence\Doctrine\DoctrineBankRepository as Repo;
            use Erpify\Shared\Uuid\Domain\Uuid;
            use function Erpify\Backoffice\Bank\Application\helper;
            final class Service {
                public function run(Uuid $id): callable {
                    return function () use ($id) {
                        return $id;
                    };
                }
            }
            PHP;

        $targets = \array_column(
            $this->matchCrossContextImports($source, 'Backoffice/BankAccount'),
            'target',
        );
        \sort($targets);

        $this->assertSame(
            [
                \Erpify\Backoffice\Bank\Domain\Entity\Bank::class,
                \Erpify\Backoffice\Bank\Domain\Repository\BankRepository::class,
                \Erpify\Backoffice\Bank\Infrastructure\Persistence\Doctrine\DoctrineBankRepository::class,
            ],
            $targets,
            'Grouped / aliased / Infrastructure cross-context imports must all be flagged, '
            . 'while `use function` and closure `use` stay invisible.',
        );
    }

    public function testFixtureExposesAssociationMatcher(): void
    {
        // A ManyToOne whose targetEntity resolves (via the file's imports) to
        // another business context is a Level 2 warning.
        $crossSource = <<<'PHP'
            <?php
            namespace Erpify\Backoffice\BankAccount\Domain\Entity;
            use Erpify\Backoffice\Bank\Domain\Entity\Bank;
            final class BankAccount {
                #[ORM\ManyToOne(targetEntity: Bank::class)]
                private Bank $bank;
            }
            PHP;

        $this->assertNotEmpty(
            $this->matchCrossContextAssociations($crossSource, 'Backoffice/BankAccount'),
            'Association matcher failed to flag a cross-context ManyToOne.',
        );

        // A ManyToOne toward the shared kernel (Media) is Level 3 — allowed.
        $sharedSource = <<<'PHP'
            <?php
            namespace Erpify\Backoffice\Bank\Domain\Entity;
            use Erpify\Shared\Media\Domain\Entity\Media;
            final class Bank {
                #[ORM\ManyToOne(targetEntity: Media::class)]
                private ?Media $logo = null;
            }
            PHP;

        $this->assertSame(
            [],
            $this->matchCrossContextAssociations($sharedSource, 'Backoffice/Bank'),
            'Association matcher flagged a shared-kernel ManyToOne — false positive.',
        );
    }

    public function testAllowlistEntriesPointToExistingFiles(): void
    {
        $apiRoot = $this->apiRoot();
        $allowlist = $this->loadAllowlist();
        $missing = [];

        // Both whole-file exemptions and per-seam exemptions carry a concrete
        // importer path that must still exist; global seams (`*`) do not.
        $paths = $allowlist['files'];

        foreach ($allowlist['seams'] as $seam) {
            $paths[] = $seam['file'];
        }

        foreach (\array_unique($paths) as $relative) {
            if (!\is_file($apiRoot . '/' . $relative)) {
                $missing[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $missing,
            \sprintf(
                "Stale entries in api/.bounded-context-allowlist (file no longer exists):\n%s",
                \implode("\n", $missing),
            ),
        );
    }

    public function testAllowlistTargetsResolveToExistingClasses(): void
    {
        // A seam/global-seam target whose class was renamed or removed leaves the
        // allowlist exempting a name nothing imports any more — dead weight that
        // would silently accumulate. Resolve each target through PSR-4
        // (`Erpify\ => src/`) and require the file to exist.
        $apiRoot = $this->apiRoot();
        $missing = [];

        $targets = $this->loadAllowlist()['globalSeams'];

        foreach ($this->loadAllowlist()['seams'] as $seam) {
            $targets[] = $seam['target'];
        }

        foreach (\array_unique($targets) as $target) {
            $relative = $this->fqcnToRelativePath($target);

            if (null === $relative || !\is_file($apiRoot . '/' . $relative)) {
                $missing[] = $target;
            }
        }

        $this->assertSame(
            [],
            $missing,
            \sprintf(
                "Stale target(s) in api/.bounded-context-allowlist (class file not found under src/):\n%s",
                \implode("\n", $missing),
            ),
        );
    }

    /**
     * Maps an `Erpify\…` FQCN to its PSR-4 source path (`Erpify\ => src/`), or
     * null when the name is not under the `Erpify\` root the allowlist governs.
     */
    private function fqcnToRelativePath(string $fqcn): ?string
    {
        if (!\str_starts_with($fqcn, 'Erpify\\')) {
            return null;
        }

        $relative = \substr($fqcn, \strlen('Erpify\\'));

        return 'src/' . \str_replace('\\', '/', $relative) . '.php';
    }

    /**
     * @return list<array{file: string, line: int, owner: string, target: string}>
     */
    private function scanForCrossContextImports(): array
    {
        $apiPrefix = $this->apiRoot() . '/';
        $allowlist = $this->loadAllowlist();
        $hits = [];

        foreach (ApiSourceFiles::phpFiles($this->apiSrcRoot()) as $file) {
            $absolute = $file->getPathname();
            $relative = \str_starts_with($absolute, $apiPrefix)
                ? \substr($absolute, \strlen($apiPrefix))
                : $absolute;

            $owner = $this->ownerContextFromRelativePath($relative);

            if (null === $owner) {
                continue;
            }

            $contents = \file_get_contents($absolute);

            if (false === $contents) {
                continue;
            }

            foreach ($this->matchCrossContextImports($contents, $owner) as $match) {
                if ($this->isAllowedSeam($allowlist, $relative, $match['target'])) {
                    continue;
                }

                $hits[] = [
                    'file' => $relative,
                    'line' => $match['line'],
                    'owner' => $owner,
                    'target' => $match['target'],
                ];
            }
        }

        return $hits;
    }

    /**
     * @return list<array{file: string, line: int, owner: string, target: string}>
     */
    private function scanForCrossContextAssociations(): array
    {
        $apiPrefix = $this->apiRoot() . '/';
        $warnings = [];

        foreach (ApiSourceFiles::phpFiles($this->apiSrcRoot()) as $file) {
            $absolute = $file->getPathname();
            $relative = \str_starts_with($absolute, $apiPrefix)
                ? \substr($absolute, \strlen($apiPrefix))
                : $absolute;

            $owner = $this->ownerContextFromRelativePath($relative);

            if (null === $owner) {
                continue;
            }

            $contents = \file_get_contents($absolute);

            if (false === $contents) {
                continue;
            }

            foreach ($this->matchCrossContextAssociations($contents, $owner) as $match) {
                $warnings[] = [
                    'file' => $relative,
                    'line' => $match['line'],
                    'owner' => $owner,
                    'target' => $match['target'],
                ];
            }
        }

        return $warnings;
    }

    /**
     * Parses `use Erpify\…;` imports and returns those that cross into another
     * business context's guarded (`Domain`/`Application`) namespace.
     *
     * @return list<array{line: int, target: string}>
     */
    private function matchCrossContextImports(string $source, string $ownerContext): array
    {
        $hits = [];

        foreach ($this->parseImports($source) as $import) {
            $target = $import['fqcn'];
            [$context, $layer] = $this->contextAndLayer($target);

            if (null === $context) {
                continue;
            }

            if ($context === $ownerContext) {
                continue;
            }

            if (self::SHARED_TOP === \explode('/', $context)[0]) {
                continue;
            }

            if (null === $layer) {
                continue;
            }

            if (!\in_array($layer, self::GUARDED_LAYERS, true)) {
                continue;
            }

            $hits[] = ['line' => $import['line'], 'target' => $target];
        }

        return $hits;
    }

    /**
     * Best-effort scan for Doctrine associations (`targetEntity: X::class`) whose
     * target resolves — via the file's imports or an inline FQCN — to another
     * business context's entity.
     *
     * @return list<array{line: int, target: string}>
     */
    private function matchCrossContextAssociations(string $source, string $ownerContext): array
    {
        $importsByShortName = $this->importsByShortName($source);
        $hits = [];

        $matched = \preg_match_all(
            '/targetEntity:\s*\\\?([A-Za-z0-9_\\\]+)::class/',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        if (false === $matched) {
            return [];
        }

        foreach ($matches[1] as $match) {
            $reference = $match[0];
            $offset = $match[1];
            $target = $this->resolveEntityReference($reference, $importsByShortName);

            if (null === $target) {
                continue;
            }

            [$context] = $this->contextAndLayer($target);

            if (null === $context) {
                continue;
            }

            if ($context === $ownerContext) {
                continue;
            }

            if (self::SHARED_TOP === \explode('/', $context)[0]) {
                continue;
            }

            $hits[] = [
                'line' => 1 + \substr_count(\substr($source, 0, $offset), "\n"),
                'target' => $target,
            ];
        }

        return $hits;
    }

    /**
     * Resolves a `targetEntity` reference to an `Erpify\…` FQCN, or null when it
     * is same-namespace / not an ERPify class the gate can reason about.
     *
     * @param array<string, string> $importsByShortName
     */
    private function resolveEntityReference(string $reference, array $importsByShortName): ?string
    {
        $reference = \ltrim($reference, '\\');

        if (\str_contains($reference, '\\')) {
            return \str_starts_with($reference, 'Erpify\\') ? $reference : null;
        }

        return $importsByShortName[$reference] ?? null;
    }

    /**
     * @return array<string, string> short class name => imported `Erpify\…` FQCN
     */
    private function importsByShortName(string $source): array
    {
        $map = [];

        foreach ($this->parseImports($source) as $import) {
            $fqcn = $import['fqcn'];
            $separator = \strrpos($fqcn, '\\');
            $shortName = false === $separator ? $fqcn : \substr($fqcn, $separator + 1);
            $map[$shortName] = $fqcn;
        }

        return $map;
    }

    /**
     * Extracts every class `use Erpify\…;` import via the tokenizer rather than a
     * line regex, so the gate cannot be evaded with forms a naive regex misses:
     * grouped imports (`use Erpify\A\{B, C\D};`), aliases (`… as X`), and
     * statements split across lines. `use function`/`use const` and closure
     * `use (...)` captures are skipped — they are not class imports.
     *
     * @return list<array{line: int, fqcn: string}>
     */
    private function parseImports(string $source): array
    {
        $tokens = \token_get_all($source);
        $imports = [];

        foreach ($tokens as $i => $token) {
            if (!\is_array($token)) {
                continue;
            }

            if (T_USE !== $token[0]) {
                continue;
            }

            // Hand the statement's tail (everything after the `use` keyword) to the
            // collector by value, so it iterates without index arithmetic.
            $rest = \array_slice($tokens, $i + 1);

            foreach ($this->collectUseImports($rest, $token[2]) as $import) {
                $imports[] = $import;
            }
        }

        return $imports;
    }

    /**
     * Collects the `Erpify\…` class names introduced by a single `use` statement,
     * given the tokens that follow its `use` keyword (up to and including the
     * terminating `;`) and the statement's line. Grouped imports are expanded
     * against their prefix; closure `use`, `use function`, and `use const` yield
     * an empty list.
     *
     * @param list<string|array{0: int, 1: string, 2: int}> $rest
     *
     * @return list<array{line: int, fqcn: string}>
     *
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @SuppressWarnings("PHPMD.NPathComplexity")
     * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
     */
    private function collectUseImports(array $rest, int $line): array
    {
        $names = [];
        $prefix = '';
        $current = '';
        $skipAlias = false;

        foreach ($rest as $token) {
            if (\is_array($token)) {
                // `use function …` / `use const …` — not a class import.
                if (T_FUNCTION === $token[0] || T_CONST === $token[0]) {
                    return [];
                }

                if (T_AS === $token[0]) {
                    $skipAlias = true;

                    continue;
                }

                if (T_STRING === $token[0] && $skipAlias) {
                    $skipAlias = false; // the alias identifier — ignore it

                    continue;
                }

                if (T_NS_SEPARATOR === $token[0]) {
                    $current .= '\\';

                    continue;
                }

                if (\in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING], true)) {
                    $current .= \ltrim($token[1], '\\');
                }

                continue;
            }

            // Closure `use (...)` capture — not an import.
            if ('(' === $token) {
                return [];
            }

            if ('{' === $token) {
                $prefix = $current;
                $current = '';

                continue;
            }

            if (',' === $token) {
                $names[] = $prefix . $current;
                $current = '';
                $skipAlias = false;

                continue;
            }

            if (';' === $token) {
                if ('' !== $current) {
                    $names[] = $prefix . $current;
                }

                break;
            }
        }

        return $this->toErpifyImports($names, $line);
    }

    /**
     * @param list<string> $names
     *
     * @return list<array{line: int, fqcn: string}>
     */
    private function toErpifyImports(array $names, int $line): array
    {
        $imports = [];

        foreach ($names as $name) {
            if (\str_starts_with($name, 'Erpify\\')) {
                $imports[] = ['line' => $line, 'fqcn' => $name];
            }
        }

        return $imports;
    }

    /**
     * Derives `<Top>/<Context>` and the layer segment from an `Erpify\…` FQCN.
     * `Erpify\Backoffice\Bank\Domain\Entity\Bank` → `['Backoffice/Bank', 'Domain']`.
     * Fewer than three segments after `Erpify` (no context) yields `[null, null]`.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function contextAndLayer(string $fqcn): array
    {
        $segments = \explode('\\', $fqcn);

        // [Erpify, Top, Context, Layer, …]
        if (\count($segments) < 3) {
            return [null, null];
        }

        $context = $segments[1] . '/' . $segments[2];
        $layer = $segments[3] ?? null;

        return [$context, $layer];
    }

    /**
     * Owning `<Top>/<Context>` of a source file from its api-relative path, or
     * null when the path has no context level (e.g. `src/Kernel.php`).
     */
    private function ownerContextFromRelativePath(string $relative): ?string
    {
        if (!\str_starts_with($relative, 'src/')) {
            return null;
        }

        $segments = \explode('/', \substr($relative, \strlen('src/')));

        // [Top, Context, …file] — need at least Top/Context plus a file segment.
        if (\count($segments) < 3) {
            return null;
        }

        return $segments[0] . '/' . $segments[1];
    }

    /**
     * @param Allowlist $allowlist
     */
    private function isAllowedSeam(array $allowlist, string $relative, string $target): bool
    {
        if (\in_array($relative, $allowlist['files'], true)) {
            return true;
        }

        if (\in_array($target, $allowlist['globalSeams'], true)) {
            return true;
        }

        foreach ($allowlist['seams'] as $seam) {
            if ($seam['file'] === $relative && $seam['target'] === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Allowlist
     */
    private function loadAllowlist(): array
    {
        $result = ['files' => [], 'seams' => [], 'globalSeams' => []];
        $path = $this->apiRoot() . '/.bounded-context-allowlist';

        if (!\is_file($path)) {
            return $result;
        }

        $raw = \file_get_contents($path);

        if (false === $raw) {
            return $result;
        }

        foreach (\preg_split('/\R/', $raw) ?: [] as $line) {
            $trimmed = \trim($line);

            if ('' === $trimmed) {
                continue;
            }

            if (\str_starts_with($trimmed, '#')) {
                continue;
            }

            if (!\str_contains($trimmed, '=>')) {
                $result['files'][] = $trimmed;

                continue;
            }

            $parts = \explode('=>', $trimmed, 2);
            $left = \trim($parts[0]);
            $right = \trim($parts[1]);

            if ('*' === $left) {
                $result['globalSeams'][] = $right;

                continue;
            }

            $result['seams'][] = ['file' => $left, 'target' => $right];
        }

        return $result;
    }

    private function apiRoot(): string
    {
        return \dirname(__DIR__, 4);
    }

    private function apiSrcRoot(): string
    {
        return $this->apiRoot() . '/src';
    }
}
