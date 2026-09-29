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
 * A second rule holds the seam's callers on `kernel.exception` to
 * {@see RequestBoundarySecurityAudit::recordOnException()}: `HttpKernel::handleThrowable()` wraps no `try`
 * around those listeners, so a refusal thrown by `record()` there escapes the kernel with no Problem Details
 * and is never reported. The set of files that take an
 * `ExceptionEvent` and call `recordOnException()` is pinned, so a sweep whose selection stopped matching reds
 * instead of passing over nothing. **A green proves** no file under `src` that mentions `ExceptionEvent` calls a
 * method named `record` through `->` or `?->` (method names compared case-insensitively, as PHP resolves them),
 * and that exactly the pinned files call the exception variant. It errs loud on any `->record(` in such a file,
 * whatever its receiver. It says nothing about `record` reached as a callable (`[$audit, 'record']`,
 * `$audit->record(...)` passed on), through a helper in another file, or from a listener that never names
 * `ExceptionEvent` — an untyped or `KernelEvent`-typed one.
 *
 * @internal
 */
#[CoversNothing]
final class BoundarySecurityAuditSeamGateTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const array EXCEPTION_LISTENERS = [
        'Iam/Identity/Infrastructure/Http/InvalidCurrentPasswordAuditListener.php',
        'Iam/Identity/Infrastructure/Http/SelfTargetedActRefusalAuditListener.php',
        'Shared/Audit/Infrastructure/Http/EventListener/AccessDeniedAuditListener.php',
    ];

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
            'A request-boundary security write must go through RequestBoundarySecurityAudit — record() on '
            . 'kernel.response, recordOnException() on kernel.exception — which refuses inside a leaked transaction.',
        );
    }

    #[Test]
    public function noExceptionListenerCallsThePlainRecord(): void
    {
        // A file that takes an ExceptionEvent is such a listener.
        $root = ApiSourceFiles::root();
        $seam = (string) (new ReflectionClass(RequestBoundarySecurityAudit::class))->getFileName();
        $offenders = [];
        $callers = [];

        foreach (ApiSourceFiles::phpFiles($root) as $file) {
            $path = $file->getPathname();
            $source = (string) \file_get_contents($path);

            if ($path === $seam || !\str_contains($source, 'ExceptionEvent')) {
                continue;
            }

            if ($this->callsMethod($source, 'record')) {
                $offenders[] = \substr($path, \strlen($root) + 1);
            }

            if ($this->callsMethod($source, 'recordOnException')) {
                $callers[] = \substr($path, \strlen($root) + 1);
            }
        }

        \sort($callers);
        $this->assertSame(self::EXCEPTION_LISTENERS, $callers, 'the kernel.exception callers of the seam moved');
        $this->assertSame([], $offenders, 'A kernel.exception listener must call recordOnException(), not record().');
    }

    #[Test]
    public function thePlainRecordCallIsSeenAndTheExceptionVariantIsNot(): void
    {
        $this->assertTrue($this->callsMethod('<?php $this->securityAudit->record(self::A, []);', 'record'));
        $this->assertTrue($this->callsMethod('<?php $this->securityAudit?->record(self::A, []);', 'record'));
        $this->assertTrue($this->callsMethod('<?php $this->securityAudit->Record(self::A, []);', 'record'));
        $this->assertFalse(
            $this->callsMethod('<?php $this->securityAudit->recordOnException($e, self::A, []);', 'record'),
        );
        $this->assertFalse($this->callsMethod('<?php /** calls ->record( here */ $x = 1;', 'record'));
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

    private function callsMethod(string $source, string $method): bool
    {
        $significant = \array_values(\array_filter(
            \token_get_all($source),
            static fn (array|string $token): bool => !\is_array($token)
                || !\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        return \array_any(
            $significant,
            static fn (array|string $token, int $index): bool => \is_array($token)
                && \in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && \is_array($significant[$index + 1] ?? null)
                && 0 === \strcasecmp($method, $significant[$index + 1][1])
                && '(' === ($significant[$index + 2] ?? null),
        );
    }
}
