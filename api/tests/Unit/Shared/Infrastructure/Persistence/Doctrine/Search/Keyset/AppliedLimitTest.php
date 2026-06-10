<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset;

use Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\Keyset\AppliedLimit;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AppliedLimit::class)]
final class AppliedLimitTest extends TestCase
{
    #[Test]
    public function itStoresAPositiveLimit(): void
    {
        $this->assertSame(25, (new AppliedLimit(25))->value);
    }

    #[Test]
    #[DataProvider('provideItRejectsANonPositiveLimitCases')]
    public function itRejectsANonPositiveLimit(int $limit): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AppliedLimit($limit);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideItRejectsANonPositiveLimitCases(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }
}
