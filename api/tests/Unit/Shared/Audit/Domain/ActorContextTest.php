<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Domain;

use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\ActorType;
use Erpify\Shared\Audit\Domain\Exception\InvalidActorContext;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ActorContext::class)]
#[CoversClass(InvalidActorContext::class)]
final class ActorContextTest extends TestCase
{
    public function testAnonymousActorCarriesNoId(): void
    {
        $context = ActorContext::anonymous();

        $this->assertSame(ActorType::ANONYMOUS, $context->type);
        $this->assertNull($context->actorId);
    }

    public function testSystemActorCarriesNoId(): void
    {
        $context = ActorContext::system();

        $this->assertSame(ActorType::SYSTEM, $context->type);
        $this->assertNull($context->actorId);
    }

    public function testForUserExposesItsValidatedUuid(): void
    {
        $id = Uuid::generate();

        $context = ActorContext::forUser($id);

        $this->assertSame(ActorType::USER, $context->type);
        $this->assertSame($id, $context->actorId);
    }

    public function testForApiKeyExposesItsValidatedUuid(): void
    {
        $id = Uuid::generate();

        $context = ActorContext::forApiKey($id);

        $this->assertSame(ActorType::API_KEY, $context->type);
        $this->assertSame($id, $context->actorId);
    }

    public function testForUserRejectsAnIdThatIsNotAUuid(): void
    {
        $this->expectException(InvalidActorContext::class);

        ActorContext::forUser('not-a-uuid');
    }

    public function testForApiKeyRejectsAnIdThatIsNotAUuid(): void
    {
        $this->expectException(InvalidActorContext::class);

        ActorContext::forApiKey('not-a-uuid');
    }

    /**
     * The id carries hex letters, so its upper-cased spelling is the same UUID; an API key under a user's id
     * still names the key, and the id-less actors name nobody.
     */
    #[TestWith(['user', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', true])]
    #[TestWith(['user', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', '0190A1B2-C3D4-7E5F-8A9B-0C1D2E3F4A5B', true])]
    #[TestWith(['user', '0190A1B2-C3D4-7E5F-8A9B-0C1D2E3F4A5B', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', true])]
    #[TestWith(['user', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c', false])]
    #[TestWith(['api_key', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', false])]
    #[TestWith(['system', null, '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', false])]
    #[TestWith(['anonymous', null, '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', false])]
    public function testIsUserNamesExactlyTheUserIdentityTheActorIs(
        string $kind,
        ?string $actorId,
        string $userId,
        bool $expected,
    ): void {
        $actor = match ($kind) {
            'user' => ActorContext::forUser((string) $actorId),
            'api_key' => ActorContext::forApiKey((string) $actorId),
            'system' => ActorContext::system(),
            default => ActorContext::anonymous(),
        };

        $this->assertSame($expected, $actor->isUser($userId));
    }
}
