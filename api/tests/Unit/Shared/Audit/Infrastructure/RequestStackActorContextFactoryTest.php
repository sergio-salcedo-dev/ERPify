<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure;

use Erpify\Shared\Audit\Domain\ActorType;
use Erpify\Shared\Audit\Infrastructure\RequestStackActorContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @internal
 */
#[CoversClass(RequestStackActorContextFactory::class)]
final class RequestStackActorContextFactoryTest extends TestCase
{
    public function testAnInFlightRequestResolvesToAnonymous(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/banks'));

        $factory = new RequestStackActorContextFactory($requestStack);

        $this->assertSame(ActorType::ANONYMOUS, $factory->current()->type);
    }

    public function testNoRequestResolvesToSystem(): void
    {
        $factory = new RequestStackActorContextFactory(new RequestStack());

        $this->assertSame(ActorType::SYSTEM, $factory->current()->type);
    }

    public function testASubRequestStaysAnonymousNeverDegradingToSystem(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/api/banks'));
        $requestStack->push(Request::create('/api/banks/_fragment'));

        $factory = new RequestStackActorContextFactory($requestStack);

        $this->assertSame(ActorType::ANONYMOUS, $factory->current()->type);
    }
}
