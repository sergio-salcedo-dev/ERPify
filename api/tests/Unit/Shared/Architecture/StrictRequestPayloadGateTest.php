<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SplFileInfo;

/**
 * Prescriptive gate for the request-body boundary: a controller maps its payload with
 * `Erpify\Shared\Http\Infrastructure\StrictRequestPayload`, never with Symfony's bare
 * `#[MapRequestPayload]`. The bare attribute accepts members the payload does not declare and
 * discards them, so a body asking for something the endpoint cannot do is answered `200` — the
 * caller is told the whole request succeeded when only the recognised part ran. Rationale and the
 * 422 shape live in the {@see StrictRequestPayload} docblock.
 *
 * `#[MapQueryString]` is deliberately out of scope and stays permissive: an unknown query parameter
 * is ambient (analytics, cache-busting, a pasted campaign URL) rather than an instruction, so
 * failing a read over one would be self-inflicted.
 *
 * The one sanctioned reference is {@see StrictRequestPayload} itself, which extends the bare
 * attribute to bake the policy in. That file is excluded by identity rather than through a
 * `.allowlist` — unlike the other gates, whose allowlists hold genuine call-site exemptions that
 * legitimately grow, this exception set is structurally fixed at one: the subclass that defines the
 * policy. An allowlist file here would invite exactly the "just add yours" entry the gate exists to
 * prevent.
 *
 * Scope: the coupling is flagged where it is expressed — the `use` import, plus the inline
 * `#[\Symfony\...\MapRequestPayload]` FQCN that would sidestep an import-only scan. Resolution via
 * reflection or a container alias is not checked; the attribute seam is the shape that occurs.
 *
 * @internal
 */
#[CoversNothing]
final class StrictRequestPayloadGateTest extends TestCase
{
    /**
     * Failure preamble — a class const so make-target wrappers and CI log scrapers can grep the
     * literal string.
     */
    public const string FAILURE_PREAMBLE
        = 'Controllers must map request bodies with Erpify\Shared\Http\Infrastructure\StrictRequestPayload, '
        . 'not with Symfony\Component\HttpKernel\Attribute\MapRequestPayload directly, '
        . 'so a body carrying undeclared members is answered 422 instead of silently discarded.';

    private const string FORBIDDEN_FQCN = \Symfony\Component\HttpKernel\Attribute\MapRequestPayload::class;

    public function testNoBareMapRequestPayloadInApiSource(): void
    {
        $hits = $this->scanApiSource();

        if ([] === $hits) {
            // Empty result is the green path. Pin the assertion count so PHPUnit does not flag a
            // risky no-assertion test.
            $this->addToAssertionCount(1);

            return;
        }

        $message = self::FAILURE_PREAMBLE . "\n" . \implode("\n", \array_map(
            static fn (array $hit): string => \sprintf('%s:%d', $hit['file'], $hit['line']),
            $hits,
        ));

        $this->fail($message);
    }

    public function testGateScansAtLeastOneSourceFile(): void
    {
        // A silent zero-file scan would make the gate vacuous and let the smell merge.
        $count = 0;

        foreach (ApiSourceFiles::phpFiles() as $file) {
            if (!$this->isPolicyDefinition($file)) {
                ++$count;
            }
        }

        $this->assertGreaterThan(0, $count, 'Strict-payload gate scanned zero source files.');
    }

    public function testPolicyDefinitionIsExcludedAndStillExtendsTheBareAttribute(): void
    {
        // The exclusion is only sound while the subclass is what makes the bare import legitimate.
        // If StrictRequestPayload stopped extending MapRequestPayload, the carve-out would be
        // hiding an ordinary violation. Asserted through reflection rather than is_subclass_of()
        // because PHPStan proves the latter true at analysis time and rejects it as narrowed.
        $reflection = new ReflectionClass(StrictRequestPayload::class);
        $parent = $reflection->getParentClass();

        $carveOutRationale = \sprintf(
            '%s must extend %s for the gate carve-out to hold.',
            StrictRequestPayload::class,
            self::FORBIDDEN_FQCN,
        );

        $this->assertNotFalse($parent, $carveOutRationale);
        $this->assertSame(self::FORBIDDEN_FQCN, $parent->getName(), $carveOutRationale);

        $definition = new SplFileInfo($reflection->getFileName() ?: '');

        $this->assertTrue(
            $this->isPolicyDefinition($definition),
            'The policy-definition carve-out no longer matches StrictRequestPayload — '
                . 'the gate would flag its own definition.',
        );
    }

    public function testFixtureExposesMatcher(): void
    {
        $dirtyImport = <<<'PHP'
            <?php
            namespace Erpify\Backoffice\Bank\Infrastructure\Controller;
            use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
            final class Sample {}
            PHP;

        $this->assertSame(
            [3],
            $this->matchForbiddenUsage($dirtyImport),
            'Gate matcher failed to flag a bare MapRequestPayload import — the parser has regressed.',
        );

        $dirtyInline = <<<'PHP'
            <?php
            namespace Erpify\Backoffice\Bank\Infrastructure\Controller;
            final class Sample {
                public function __invoke(#[\Symfony\Component\HttpKernel\Attribute\MapRequestPayload] Dto $dto): void {}
            }
            PHP;

        $this->assertSame(
            [4],
            $this->matchForbiddenUsage($dirtyInline),
            'Gate matcher failed to flag an inline FQCN MapRequestPayload attribute — the import-only bypass is open.',
        );

        $clean = <<<'PHP'
            <?php
            namespace Erpify\Backoffice\Bank\Infrastructure\Controller;
            use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
            use Symfony\Component\HttpKernel\Attribute\MapQueryString;
            /** Mapped from the body, see StrictRequestPayload rather than MapRequestPayload. */
            final class Sample {}
            PHP;

        $this->assertSame(
            [],
            $this->matchForbiddenUsage($clean),
            'Gate matcher flagged StrictRequestPayload, MapQueryString or a docblock mention — false positive.',
        );
    }

    /**
     * @return list<array{file: string, line: int}>
     */
    private function scanApiSource(): array
    {
        $hits = [];

        foreach (ApiSourceFiles::phpFiles() as $file) {
            if ($this->isPolicyDefinition($file)) {
                continue;
            }

            $contents = \file_get_contents($file->getPathname());

            if (false === $contents) {
                continue;
            }

            foreach ($this->matchForbiddenUsage($contents) as $line) {
                $hits[] = ['file' => $this->relativePath($file->getPathname()), 'line' => $line];
            }
        }

        return $hits;
    }

    /**
     * Line numbers carrying the bare attribute, as an import or as an inline FQCN. A docblock
     * mention is not a coupling — only `use ...;` and `#[\...]` are matched.
     *
     * @return list<int>
     */
    private function matchForbiddenUsage(string $contents): array
    {
        $fqcn = \preg_quote(self::FORBIDDEN_FQCN, '/');
        $pattern = \sprintf('/^\s*use\s+%s\s*;|#\[\s*\\\%s\b/', $fqcn, $fqcn);
        $lines = [];

        foreach (\preg_split('/\R/', $contents) ?: [] as $index => $line) {
            if (1 === \preg_match($pattern, $line)) {
                $lines[] = $index + 1;
            }
        }

        return $lines;
    }

    private function isPolicyDefinition(SplFileInfo $file): bool
    {
        return ApiSourceFiles::root() . '/Shared/Http/Infrastructure/StrictRequestPayload.php'
            === $file->getPathname();
    }

    private function relativePath(string $pathname): string
    {
        return \str_replace(ApiSourceFiles::root() . '/', 'api/src/', $pathname);
    }
}
