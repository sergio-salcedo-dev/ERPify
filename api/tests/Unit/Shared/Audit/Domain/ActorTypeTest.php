<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Domain;

use Erpify\Shared\Audit\Domain\ActorType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ActorType::class)]
final class ActorTypeTest extends TestCase
{
    /**
     * The backing values are the lowercase tokens persisted in the `actor_type`
     * column, so the mapping is the storage contract. The token arrives as a plain
     * string, so `from()` is a real runtime lookup, not a constant-folded literal.
     */
    #[DataProvider('provideEachTokenMapsToItsCaseCases')]
    public function testEachTokenMapsToItsCase(string $token, ActorType $expected): void
    {
        $this->assertSame($expected, ActorType::from($token));
    }

    /**
     * @return iterable<string, array{string, ActorType}>
     */
    public static function provideEachTokenMapsToItsCaseCases(): iterable
    {
        yield 'anonymous' => ['anonymous', ActorType::ANONYMOUS];
        yield 'system' => ['system', ActorType::SYSTEM];
        yield 'api_key' => ['api_key', ActorType::API_KEY];
        yield 'user' => ['user', ActorType::USER];
    }

    public function testEveryActorTypeIsPinnedByAToken(): void
    {
        $pinnedTypes = [];

        foreach (self::provideEachTokenMapsToItsCaseCases() as $case) {
            $pinnedTypes[] = $case[1];
        }

        $this->assertSame(ActorType::cases(), $pinnedTypes);
    }
}
