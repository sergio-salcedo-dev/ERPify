<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset;

use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\WirePaginationPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(WirePaginationPolicy::class)]
final class WirePaginationPolicyTest extends TestCase
{
    #[Test]
    #[DataProvider('provideItExposesItsConfigurationCases')]
    public function itExposesItsConfiguration(
        WirePaginationPolicy $policy,
        int $defaultLimit,
        int $maxLimit,
        bool $exclusiveBoundary,
        bool $emitsCursors,
    ): void {
        $this->assertSame($defaultLimit, $policy->defaultLimit);
        $this->assertSame($maxLimit, $policy->maxLimit);
        $this->assertSame($exclusiveBoundary, $policy->exclusiveBoundary);
        $this->assertSame($emitsCursors, $policy->emitsCursors);
    }

    /**
     * @return iterable<string, array{WirePaginationPolicy, int, int, bool, bool}>
     */
    public static function provideItExposesItsConfigurationCases(): iterable
    {
        yield 'wire defaults' => [WirePaginationPolicy::wire(), 25, 100, true, true];
        yield 'explicit overrides' => [
            new WirePaginationPolicy(defaultLimit: 10, maxLimit: 50, exclusiveBoundary: false, emitsCursors: false),
            10,
            50,
            false,
            false,
        ];
    }
}
