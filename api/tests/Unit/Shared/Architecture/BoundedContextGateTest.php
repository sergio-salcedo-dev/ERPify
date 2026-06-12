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
 *      imports `Erpify\<Top>\<ContextB>\Domain\…` or `…\Application\…` of a
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
 * context from its path, parses its `use Erpify\…;` imports, and flags every
 * cross-context `Domain`/`Application` import not covered by the allowlist.
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
     * foreign context's domain model or use cases. `Infrastructure` is
     * deliberately out of scope — a context's adapters are not a published seam,
     * but importing them is already unusual and not the modeled rule.
     *
     * @var list<string>
     */
    private const array GUARDED_LAYERS = ['Domain', 'Application'];

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
            use Erpify\Shared\Domain\Uuid\Uuid;
            use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
            final class BankAccount {}
            PHP;

        $this->assertSame(
            [],
            $this->matchCrossContextImports($cleanSource, 'Backoffice/BankAccount'),
            'Gate matcher flagged a shared-kernel / same-context import — false positive.',
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

        if (false === \preg_match_all(
            '/targetEntity:\s*\\\?([A-Za-z0-9_\\\]+)::class/',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE,
        )) {
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
     * @return list<array{line: int, fqcn: string}>
     */
    private function parseImports(string $source): array
    {
        $split = \preg_split('/\R/', $source);
        $lines = false === $split ? [] : $split;
        $imports = [];

        foreach ($lines as $i => $line) {
            // Class imports only — `use function …` / `use const …` start with a
            // different keyword and never match this anchored pattern.
            if (1 !== \preg_match('/^\s*use\s+(Erpify\\\[A-Za-z0-9_\\\]+)/', $line, $m)) {
                continue;
            }

            $imports[] = ['line' => $i + 1, 'fqcn' => $m[1]];
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
            $right = \trim($parts[1] ?? '');

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
