<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The second-factor code budget, read from the file the container compiles it from: ten codes per identity in a
 * sliding fifteen minutes, written as literals. The number is part of the factor's security argument (ADR
 * `two-step-verification.md` D3), so widening it, or moving it behind an environment variable an env file could
 * widen with no diff to review, has to fail a test rather than pass silently.
 *
 * @internal
 */
#[CoversNothing]
final class SecondFactorCodeBudgetTest extends TestCase
{
    private const string RATE_LIMITER = __DIR__ . '/../../../../../../config/packages/rate_limiter.yaml';

    #[Test]
    public function theBudgetIsTenCodesInASlidingFifteenMinutesWrittenAsLiterals(): void
    {
        $limiter = Yaml::parseFile(self::RATE_LIMITER);

        foreach (['framework', 'rate_limiter', 'second_factor_code_per_identity'] as $key) {
            $this->assertIsArray($limiter, 'the second-factor code limiter is declared');
            $limiter = $limiter[$key] ?? null;
        }

        $this->assertIsArray($limiter, 'the second-factor code limiter is declared');
        $this->assertSame('sliding_window', $limiter['policy'] ?? null);
        $this->assertSame(10, $limiter['limit'] ?? null);
        $this->assertSame('15 minutes', $limiter['interval'] ?? null);
    }
}
