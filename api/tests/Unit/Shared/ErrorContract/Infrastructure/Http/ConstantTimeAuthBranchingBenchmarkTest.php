<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\ErrorContract\Application;

use Erpify\Shared\ErrorContract\Application\ProblemDetailsFactory;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\Forbidden;
use Erpify\Shared\ErrorContract\Domain\Exception\Unauthenticated;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Throwable;

/**
 * Informational microbenchmark for the four 401/403 routes through
 * {@see ProblemDetailsFactory::fromThrowable}. NOT CI-gated in the blocking
 * sense: the threshold is deliberately generous (5x) so shared CI hardware
 * noise does not cause false positives. The test exists to surface gross
 * asymmetries — a regression that quintuples one branch's cost will fail
 * this; a 5% jitter under normal load will not.
 *
 * Methodology:
 *   - Warm-up loop (100 iterations per case) seeds the opcode / JIT caches and
 *     amortises class-loading cost so the measurement window only captures the
 *     factory's steady-state work.
 *   - Measurement loop (1000 iterations per case) accumulates wall-clock time
 *     in nanoseconds via `\hrtime(true)`, then divides by the iteration count
 *     to derive a mean. Means are compared by ratio (max / min); a single
 *     coefficient-of-variation gate keeps the test simple and the failure
 *     message informative.
 *   - The four cases exercise the routes:
 *       * Symfony bridge: `AccessDeniedException` (403)
 *       * Symfony bridge: `BadCredentialsException` extends `AuthenticationException` (401)
 *       * Marker path:    `DomainException implements Forbidden` (403)
 *       * Marker path:    `DomainException implements Unauthenticated` (401)
 *
 * Out of scope (explicit): application-level timing — database lookup latency,
 * controller-side resource resolution, repository round trips — is the
 * controller's concern, not the listener's. This bench only covers the
 * listener / factory's own contribution to response time.
 *
 * The use of `\hrtime` in this test file is itself permissible because this is
 * a test, not the production source. The companion {@see ConstantTimeAuthBranchingContractTest}
 * pins that the production source (`api/src/Shared/ErrorContract/Application/ProblemDetailsFactory.php`)
 * carries no such timing primitive.
 *
 * @internal
 */
#[CoversNothing]
final class ConstantTimeAuthBranchingBenchmarkTest extends TestCase
{
    private const string CID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    private const string INSTANCE = 'urn:uuid:0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1a2c';

    private const int WARMUP_ITERATIONS = 100;

    private const int MEASUREMENT_ITERATIONS = 1000;

    /**
     * Maximum acceptable ratio between the slowest and fastest measured branch
     * mean. 5x is intentionally generous: shared CI hardware (containerised
     * runners, neighbour-VM noise, transient I/O contention) can quadruple a
     * single measurement window's wall-clock cost. A real branching asymmetry
     * (e.g. one branch adding a conditional `usleep(1000)`) would manifest as
     * ~10x or more, well above this threshold. The intent of this bench is to
     * surface gross regressions, not microsecond-level precision.
     */
    private const float MAX_BRANCH_MEAN_RATIO = 5.0;

    /**
     * The microbenchmark (documented, not CI-gated in the blocking sense —
     * the 5x threshold is generous enough that the test is informational
     * rather than precision-tuned).
     *
     * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
     */
    public function testConstantTimeAuthBranchingMeansAreWithinMeasurementNoise(): void
    {
        $problemDetailsFactory = new ProblemDetailsFactory('prod', new NullLogger());

        $unauth = new class ('', 'Unauthenticated.') extends DomainException implements Unauthenticated {
        };

        /** @var array<string, Throwable> $cases */
        $cases = [
            '403 access-denied bridge' => new AccessDeniedException('Access denied.'),
            '401 authentication bridge' => new BadCredentialsException('Bad credentials.'),
            '403 forbidden marker' => new class ('', 'Forbidden.') extends DomainException implements Forbidden {
            },
            '401 unauthenticated marker' => $unauth,
        ];

        // Warm-up: prime opcode / JIT caches per branch so the measurement window only
        // captures the factory's steady-state work, not the first-call cold paths.
        foreach ($cases as $case) {
            for ($i = 0; $i < self::WARMUP_ITERATIONS; ++$i) {
                $problemDetailsFactory->fromThrowable($case, self::CID, self::INSTANCE);
            }
        }

        // Measurement: per-branch mean nanoseconds-per-call across 1000 iterations.
        /** @var array<string, float> $means */
        $means = [];

        foreach ($cases as $label => $exception) {
            $start = \hrtime(true);

            for ($i = 0; $i < self::MEASUREMENT_ITERATIONS; ++$i) {
                $problemDetailsFactory->fromThrowable($exception, self::CID, self::INSTANCE);
            }

            $elapsed = \hrtime(true) - $start;
            $means[$label] = $elapsed / self::MEASUREMENT_ITERATIONS;
        }

        $this->assertCount(
            \count($cases),
            $means,
            'Measurement loop must produce one mean per branch.',
        );

        // Narrow `$means` to non-empty for the static analyser by guarding on count.
        if ([] === $means) {
            $this->fail('Measurement table is empty — unreachable after the count assertion above.');
        }

        $min = \min($means);
        $max = \max($means);

        $this->assertGreaterThan(
            0.0,
            $min,
            'Per-branch mean must be positive — a zero mean indicates the measurement loop did '
            . 'not execute or hrtime() returned a non-monotonic delta.',
        );

        $ratio = $max / $min;

        $this->assertLessThan(
            self::MAX_BRANCH_MEAN_RATIO,
            $ratio,
            \sprintf(
                'Auth branching timing asymmetry exceeds %sx threshold (informational): '
                . 'observed ratio = %.3f. Per-branch means (ns/call): %s. This is the '
                . 'documented bench — a 5x threshold is generous enough to absorb shared-CI '
                . 'noise; if you see this fail, inspect the means table for which branch '
                . 'regressed and check the recent diff for conditional sleeps, I/O, or '
                . 'resource-presence-conditional logic in fromThrowable().',
                self::MAX_BRANCH_MEAN_RATIO,
                $ratio,
                \json_encode($means, JSON_THROW_ON_ERROR),
            ),
        );
    }
}
