<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Shared\Audit\Infrastructure\Http\RequestBoundarySecurityAudit;
use Erpify\Tests\Support\ApiSourceFiles;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every `security` audit write made at the HTTP boundary goes through {@see RequestBoundarySecurityAudit}.
 *
 * That seam is the one place the durability of such a row is checked: it refuses to write inside a leaked
 * transaction, where a later rollback would take the row with it. A listener under `Infrastructure/Http/` that
 * names `AuditLevel::SECURITY` itself skips the check, and nothing else goes red — its unit test passes, the
 * row is written, and the denial is lost only on the request that leaked a transaction. So the level may be
 * NAMED in code under `Infrastructure/Http/` by the seam alone.
 *
 * Read as tokens, so a docblock or comment mentioning the level (every boundary listener's does) is no
 * write. **A green proves** no file under an `Infrastructure/Http/` directory spells `AuditLevel::SECURITY` in
 * code other than the seam. It says nothing about a write reached by importing the case under an alias, by
 * a string-built level, from a CLI command or a use case — those write inside their own transaction on
 * purpose and are outside this rule — nor about a listener living outside an `Infrastructure/Http/` directory.
 *
 * @internal
 */
#[CoversNothing]
final class BoundarySecurityAuditSeamGateTest extends TestCase
{
    #[Test]
    public function onlyTheSeamNamesTheSecurityLevelUnderInfrastructureHttp(): void
    {
        $root = ApiSourceFiles::root();
        $seam = (string) (new ReflectionClass(RequestBoundarySecurityAudit::class))->getFileName();
        $scanned = 0;
        $offenders = [];

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $path = $file->getPathname();

            if (!\str_contains($path, '/Infrastructure/Http/')) {
                continue;
            }

            ++$scanned;
            $source = (string) \file_get_contents($path);

            if ($path !== $seam && $this->namesSecurityLevelInCode($source)) {
                $offenders[] = \substr($path, \strlen($root) + 1);
            }
        }

        $this->assertGreaterThan(10, $scanned, 'the sweep reached almost no Infrastructure/Http file');
        $this->assertTrue(
            $this->namesSecurityLevelInCode((string) \file_get_contents($seam)),
            'the seam itself no longer names the level, so this sweep would pass over nothing',
        );
        $this->assertSame(
            [],
            $offenders,
            'A request-boundary security write must go through RequestBoundarySecurityAudit::record(), '
            . 'which refuses inside a leaked transaction.',
        );
    }

    #[Test]
    public function aMentionInACommentOrDocblockIsNoWrite(): void
    {
        $this->assertFalse($this->namesSecurityLevelInCode(<<<'PHP'
            <?php
            /** Emits ACCESS_DENIED at {@see AuditLevel::SECURITY}. */
            final class A {
                // AuditLevel::SECURITY would be wrong here
                public function f(): void {}
            }
            PHP));
    }

    #[Test]
    public function theLevelNamedInCodeIsAWrite(): void
    {
        $this->assertTrue($this->namesSecurityLevelInCode(<<<'PHP'
            <?php
            final class A {
                public function f(): void { $this->logger->log('X', AuditLevel::SECURITY); }
            }
            PHP));
        $this->assertTrue($this->namesSecurityLevelInCode(<<<'PHP'
            <?php
            final class A {
                public function f(): void { $this->logger->log('X', \Erpify\Audit\AuditLevel :: SECURITY); }
            }
            PHP));
    }

    #[Test]
    public function anotherCaseOfTheLevelIsNotTheSecurityLevel(): void
    {
        $this->assertFalse($this->namesSecurityLevelInCode(<<<'PHP'
            <?php
            final class A {
                public function f(): void { $this->logger->log('X', AuditLevel::ACTIVITY); }
            }
            PHP));
    }

    private function namesSecurityLevelInCode(string $source): bool
    {
        $significant = \array_values(\array_filter(
            \token_get_all($source),
            static fn (array|string $token): bool => !\is_array($token)
                || !\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        return \array_any(
            $significant,
            fn (array|string $token, int $index): bool => \is_array($token) && T_DOUBLE_COLON === $token[0]
                && $this->isSecurityCaseAround($significant, $index),
        );
    }

    /**
     * Whether the tokens either side of the `::` at `$index` spell `AuditLevel` and `SECURITY`.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private function isSecurityCaseAround(array $tokens, int $index): bool
    {
        $class = $tokens[$index - 1] ?? null;
        $case = $tokens[$index + 1] ?? null;

        if (!\is_array($class) || !\is_array($case) || 'SECURITY' !== $case[1]) {
            return false;
        }

        $name = \ltrim($class[1], '\\');

        return 'AuditLevel' === $name || \str_ends_with($name, '\AuditLevel');
    }
}
