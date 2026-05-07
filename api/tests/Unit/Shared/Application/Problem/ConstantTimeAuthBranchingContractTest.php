<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Application\Problem;

use Erpify\Shared\Application\Problem\ProblemDetailsFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Story 3.7 (NFR9) — pins the constant-time-branching invariant for the four
 * 401/403 routes through {@see ProblemDetailsFactory::fromThrowable}: the
 * `Unauthenticated` / `Forbidden` marker paths and the `AuthenticationException`
 * / `AccessDeniedException` Symfony bridges. The contract is "the listener's own
 * contribution to response time is uniform across these four exception types,
 * regardless of which one is thrown" — implemented via two source-text pins:
 *
 *   1. {@see testFactorySourceContainsNoConditionalTimingPrimitive} — the
 *      production source MUST NOT call any wall-clock or pseudo-random helper
 *      (`microtime`, `hrtime`, `usleep`, `time_nanosleep`, `mt_rand`,
 *      `random_int`, `random_bytes`). A conditional `usleep` would be the
 *      classic timing-side-channel mitigation that masks rather than fixes a
 *      branching asymmetry, and a `random_*` call would introduce a different
 *      kind of timing variance. Neither belongs in the factory.
 *
 *   2. {@see testFactoryAuthBranchesUseUniformConstructionShape} — the Symfony
 *      bridge branches for 401/403 (`AuthenticationException` /
 *      `AccessDeniedException`) MUST construct their {@see \Erpify\Shared\Application\Problem\ProblemDetails}
 *      via the shared {@see ProblemDetailsFactory::buildBridgeResponse} helper,
 *      so a future PR cannot quietly inline a divergent code path on one side
 *      of the 401/403 split. The marker path constructs `new ProblemDetails(...)`
 *      directly because it must thread `buildExtensions(...)` for the
 *      `DomainException::context()` substrate; that asymmetry is type-driven
 *      (not resource-presence-driven) and applies symmetrically to ALL marker
 *      branches (NotFound, Conflict, etc.) — not just 401/403. NFR9 only forbids
 *      asymmetry across the 401-vs-403 split, not asymmetry across marker-vs-bridge.
 *
 * Out of scope (explicit): application-level timing — database lookup latency,
 * controller-side resource resolution, repository round trips — is the
 * controller's concern, not the listener's. NFR9 only covers the listener /
 * factory's own contribution.
 *
 * @internal
 */
#[CoversNothing]
final class ConstantTimeAuthBranchingContractTest extends TestCase
{
    #[DataProvider('provideFactorySourceContainsNoConditionalTimingPrimitiveCases')]
    public function testFactorySourceContainsNoConditionalTimingPrimitive(string $primitive): void
    {
        $source = $this->loadFactorySource();

        // Match either a function-call shape (`primitive(`) or a fully-qualified-call shape
        // (`\primitive(`). Whitespace between identifier and paren is allowed because PHP
        // tolerates it; the negative match guards against future drift.
        $pattern = '/(?:^|[^a-zA-Z0-9_])\\\?' . \preg_quote($primitive, '/') . '\s*\(/';

        $this->assertSame(
            0,
            \preg_match($pattern, $source),
            \sprintf(
                'ProblemDetailsFactory must not call %s() — Story 3.7 (NFR9) requires the '
                . 'listener / factory path to perform no wall-clock, sleep, or random-source '
                . 'work. A conditional %s() would either expose the path to wall-clock-based '
                . 'branching, mask asymmetry instead of fixing it, or add unrelated variance.',
                $primitive,
                $primitive,
            ),
        );
    }

    /**
     * The forbidden timing primitives. `microtime` / `hrtime` would expose the
     * factory to wall-clock-based branching; `usleep` / `time_nanosleep` would
     * mask asymmetry rather than fix it; `mt_rand` / `random_int` /
     * `random_bytes` would introduce new variance into the listener path. None
     * of these belong in the constant-time error-mapping site.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideFactorySourceContainsNoConditionalTimingPrimitiveCases(): iterable
    {
        yield 'microtime' => ['microtime'];

        yield 'hrtime' => ['hrtime'];

        yield 'usleep' => ['usleep'];

        yield 'time_nanosleep' => ['time_nanosleep'];

        yield 'mt_rand' => ['mt_rand'];

        yield 'random_int' => ['random_int'];

        yield 'random_bytes' => ['random_bytes'];
    }

    public function testFactoryAuthBranchesUseUniformConstructionShape(): void
    {
        $fromThrowableSource = $this->loadFromThrowableSource();

        // Each Symfony bridge type (AccessDeniedException for 403,
        // AuthenticationException for 401) appears in `fromThrowable` exactly once: in its
        // `instanceof` guard. Pinning the count guards against a future PR that splits one
        // bridge into two divergent code paths conditioned on something other than the type.
        $this->assertSame(
            1,
            \substr_count($fromThrowableSource, 'instanceof AccessDeniedException'),
            'fromThrowable must contain exactly one AccessDeniedException branch — the 403 '
            . 'bridge. A second branch would imply resource-presence-conditional logic, '
            . 'breaking NFR9.',
        );
        $this->assertSame(
            1,
            \substr_count($fromThrowableSource, 'instanceof AuthenticationException'),
            'fromThrowable must contain exactly one AuthenticationException branch — the 401 '
            . 'bridge. A second branch would imply resource-presence-conditional logic, '
            . 'breaking NFR9.',
        );

        // Both bridge branches must construct via `buildBridgeResponse(`. A divergent inline
        // `new ProblemDetails(...)` on one side would be a regression — the shared helper is
        // the load-bearing pin that the two bridges produce structurally identical bodies
        // (no extensions, no debug threading other than `withDebug`) modulo type / status / title.
        $accessDeniedFragment = $this->extractInstanceofBlock($fromThrowableSource, 'AccessDeniedException');
        $authenticationFragment = $this->extractInstanceofBlock($fromThrowableSource, 'AuthenticationException');

        $this->assertStringContainsString(
            '$this->buildBridgeResponse(',
            $accessDeniedFragment,
            'AccessDeniedException branch must construct via buildBridgeResponse() — a '
            . 'divergent inline ProblemDetails would break NFR9 symmetry with the 401 bridge.',
        );
        $this->assertStringContainsString(
            '$this->buildBridgeResponse(',
            $authenticationFragment,
            'AuthenticationException branch must construct via buildBridgeResponse() — a '
            . 'divergent inline ProblemDetails would break NFR9 symmetry with the 403 bridge.',
        );

        // Both bridge branches must wrap through the shared `withDebug(...)` and
        // `applyBodyCap(...)` post-processing pipeline. Symmetry across the 401/403 split
        // means the listener's contribution to response time is the same set of helper calls
        // for either status.
        $this->assertStringContainsString(
            '$this->applyBodyCap($this->withDebug($this->buildBridgeResponse(',
            $accessDeniedFragment,
            'AccessDeniedException branch must use the canonical applyBodyCap(withDebug(buildBridgeResponse(...))) '
            . 'pipeline to remain symmetric with the AuthenticationException branch (NFR9).',
        );
        $this->assertStringContainsString(
            '$this->applyBodyCap($this->withDebug($this->buildBridgeResponse(',
            $authenticationFragment,
            'AuthenticationException branch must use the canonical applyBodyCap(withDebug(buildBridgeResponse(...))) '
            . 'pipeline to remain symmetric with the AccessDeniedException branch (NFR9).',
        );
    }

    private function loadFactorySource(): string
    {
        $reflectionClass = new ReflectionClass(ProblemDetailsFactory::class);
        $fileName = $reflectionClass->getFileName();
        $this->assertNotFalse(
            $fileName,
            'ProblemDetailsFactory must be loadable from a userland .php file for the source-text pin.',
        );

        $contents = \file_get_contents($fileName);
        $this->assertNotFalse(
            $contents,
            \sprintf('Failed to read %s for the source-text pin.', $fileName),
        );

        return $contents;
    }

    private function loadFromThrowableSource(): string
    {
        $reflectionMethod = new ReflectionMethod(ProblemDetailsFactory::class, 'fromThrowable');
        $fileName = $reflectionMethod->getFileName();
        $startLine = $reflectionMethod->getStartLine();
        $endLine = $reflectionMethod->getEndLine();

        $this->assertNotFalse(
            $fileName,
            'ProblemDetailsFactory::fromThrowable must be loadable from a userland .php file.',
        );
        $this->assertNotFalse($startLine, 'fromThrowable must declare a start line.');
        $this->assertNotFalse($endLine, 'fromThrowable must declare an end line.');

        $lines = \file($fileName);
        $this->assertNotFalse(
            $lines,
            \sprintf('Failed to read %s for the fromThrowable source-text pin.', $fileName),
        );

        return \implode('', \array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
    }

    /**
     * Extracts the line beginning at `if ($e instanceof <fqcn-suffix>)` plus the
     * subsequent `return ...;` statement. The factory's bridge branches are short
     * (one `if`, one `return $this->applyBodyCap(...)`), so a fixed line-window
     * extraction is sufficient and avoids parsing PHP.
     */
    private function extractInstanceofBlock(string $methodSource, string $fqcnSuffix): string
    {
        $needle = 'instanceof ' . $fqcnSuffix;
        $position = \strpos($methodSource, $needle);
        $this->assertNotFalse(
            $position,
            \sprintf('Expected fromThrowable source to contain "instanceof %s".', $fqcnSuffix),
        );

        // Capture from the `if` line through the closing `}` of the branch. The branches are
        // small — bound to ~10 lines forward is generous and keeps the extraction cheap.
        $lineStart = \strrpos(\substr($methodSource, 0, $position), "\n");
        $startOffset = false === $lineStart ? 0 : $lineStart + 1;

        $remainder = \substr($methodSource, $startOffset);
        $lines = \explode("\n", $remainder);
        $window = \array_slice($lines, 0, 10);

        return \implode("\n", $window);
    }
}
