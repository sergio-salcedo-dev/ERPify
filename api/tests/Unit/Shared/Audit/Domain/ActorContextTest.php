<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Domain;

use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\ActorType;
use Erpify\Shared\Audit\Domain\Exception\InvalidActorContext;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
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
}
