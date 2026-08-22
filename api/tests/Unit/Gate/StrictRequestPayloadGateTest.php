<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\Http\Infrastructure\StrictRequestPayload;
use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
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
 * Scope: the file is tokenised with PHP's own lexer and every identifier whose trailing segment is
 * `MapRequestPayload` counts, whichever spelling carries it — a plain import, an aliased one, a
 * group `use`, a leading-backslash FQCN, an inline attribute, or an attribute broken across lines.
 * A regex over raw lines cannot see those as one thing, and each spelling it misses is a silent way
 * back to permissive mapping. Tokenising also drops comments and string literals for free, so a
 * docblock naming the attribute is not mistaken for a coupling. Resolution via reflection or a
 * container alias is not checked; the attribute seam is the shape that occurs.
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

    private const string FORBIDDEN_SHORT_NAME = 'MapRequestPayload';

    /**
     * Token ids a class name can arrive as: bare (imported or same-namespace), qualified,
     * fully qualified, and `namespace\`-relative.
     *
     * @var list<int>
     */
    private const array IDENTIFIER_TOKENS = [
        T_STRING,
        T_NAME_QUALIFIED,
        T_NAME_FULLY_QUALIFIED,
        T_NAME_RELATIVE,
    ];

    public function testNoBareMapRequestPayloadInApiSource(): void
    {
        $hits = $this->scanApiSource();

        $this->assertSame([], $hits, self::FAILURE_PREAMBLE . "\n" . \implode("\n", \array_map(
            static fn (array $hit): string => \sprintf('%s:%d', $hit['file'], $hit['line']),
            $hits,
        )));
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

    /**
     * @param list<int> $expectedLines
     */
    #[DataProvider('provideFixtureExposesMatcherCases')]
    public function testFixtureExposesMatcher(string $source, array $expectedLines, string $rationale): void
    {
        $this->assertSame($expectedLines, $this->matchForbiddenUsage($source), $rationale);
    }

    /**
     * Every spelling that carries the attribute into a file. Each one is a way back to permissive
     * mapping, so each is pinned rather than trusted to the shape a reviewer happens to picture.
     *
     * @return iterable<string, array{string, list<int>, string}>
     */
    public static function provideFixtureExposesMatcherCases(): iterable
    {
        yield 'plain import' => [
            "<?php\nuse Symfony\\Component\\HttpKernel\\Attribute\\MapRequestPayload;\n",
            [2],
            'A bare MapRequestPayload import went unflagged — the matcher has regressed.',
        ];

        yield 'aliased import' => [
            "<?php\nuse Symfony\\Component\\HttpKernel\\Attribute\\MapRequestPayload as Payload;\n",
            [2],
            'An alias renames the attribute without loosening it — the coupling is still there.',
        ];

        yield 'group import' => [
            "<?php\nuse Symfony\\Component\\HttpKernel\\Attribute\\{MapRequestPayload, MapUploadedFile};\n",
            [2],
            'Folding the attribute into a group use hid it — controllers already import both names.',
        ];

        yield 'leading-backslash import' => [
            "<?php\nuse \\Symfony\\Component\\HttpKernel\\Attribute\\MapRequestPayload;\n",
            [2],
            'A leading backslash is legal PHP and resolves to the same class.',
        ];

        yield 'inline fully-qualified attribute' => [
            "<?php\nclass S {\n function f("
                . "#[\\Symfony\\Component\\HttpKernel\\Attribute\\MapRequestPayload] D \$d) {}\n}\n",
            [3],
            'An inline FQCN needs no import at all — the import-only bypass.',
        ];

        yield 'attribute split across lines' => [
            "<?php\nclass S {\n function f(\n  #[\n   "
                . "\\Symfony\\Component\\HttpKernel\\Attribute\\MapRequestPayload\n  ]\n  D \$d) {}\n}\n",
            [5],
            'A line-by-line scan loses an attribute wrapped across lines.',
        ];

        yield 'mention outside code' => [
            "<?php\nuse Erpify\\Shared\\Http\\Infrastructure\\StrictRequestPayload;\n"
                . "/** Mapped from the body, see StrictRequestPayload not MapRequestPayload. */\n"
                . "\$s = 'MapRequestPayload';\n",
            [],
            'A docblock or string mention is not a coupling — false positive.',
        ];
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
     * Line numbers naming the bare attribute in code. Identifier tokens only, so a mention inside a
     * comment, docblock or string literal is not a coupling.
     *
     * @return list<int>
     */
    private function matchForbiddenUsage(string $contents): array
    {
        $lines = [];

        foreach (\token_get_all($contents) as $token) {
            if (!\is_array($token)) {
                continue;
            }

            if (!\in_array($token[0], self::IDENTIFIER_TOKENS, true)) {
                continue;
            }

            // A qualified name arrives as one token, so the trailing segment is what identifies the
            // class — `Strict…` and `…PayloadFactory` are different classes, not near-misses.
            $segments = \explode('\\', $token[1]);

            if (self::FORBIDDEN_SHORT_NAME === \end($segments)) {
                $lines[] = $token[2];
            }
        }

        return \array_values(\array_unique($lines));
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
