<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Domain;

use Erpify\Shared\Audit\Domain\ActorContext;
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

    /**
     * `isIdentified()` is what the `audit_log` CHECK constraints are derived from, and `ActorContext`'s
     * factories are what every writer builds its actor through, so the two must state the same rule.
     */
    #[DataProvider('provideIsIdentifiedAgreesWithTheActorContextFactoriesCases')]
    public function testIsIdentifiedAgreesWithTheActorContextFactories(ActorContext $actor): void
    {
        $this->assertSame(null !== $actor->actorId, $actor->type->isIdentified());
    }

    /**
     * @return iterable<string, array{ActorContext}>
     */
    public static function provideIsIdentifiedAgreesWithTheActorContextFactoriesCases(): iterable
    {
        yield 'anonymous' => [ActorContext::anonymous()];
        yield 'system' => [ActorContext::system()];
        yield 'api_key' => [ActorContext::forApiKey('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b')];
        yield 'user' => [ActorContext::forUser('0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c')];
    }

    public function testEveryActorTypeIsBuiltByAFactory(): void
    {
        $builtTypes = [];

        foreach (self::provideIsIdentifiedAgreesWithTheActorContextFactoriesCases() as $case) {
            $builtTypes[] = $case[0]->type;
        }

        $this->assertSame(ActorType::cases(), $builtTypes);
    }
}
