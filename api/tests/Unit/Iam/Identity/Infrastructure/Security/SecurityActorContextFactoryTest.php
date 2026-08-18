<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\SecurityActorContextFactory;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Shared\Audit\Domain\ActorType;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * @internal
 */
#[CoversClass(SecurityActorContextFactory::class)]
final class SecurityActorContextFactoryTest extends TestCase
{
    private const string ENDPOINT = '/api/v1/backoffice/audit/timeline';

    public function testAnAuthenticatedSecurityUserIsAttributedByUuid(): void
    {
        $tokenStorage = new TokenStorage();
        $securityUser = new SecurityUser(UserMother::create());
        $tokenStorage->setToken(new UsernamePasswordToken($securityUser, 'main', $securityUser->getRoles()));

        $actor = (new SecurityActorContextFactory($tokenStorage, $this->requestInFlight()))->current();

        $this->assertSame(ActorType::USER, $actor->type);
        $this->assertSame(UserMother::DEFAULT_ID, $actor->actorId);
    }

    public function testAnInFlightRequestWithoutATokenResolvesToAnonymous(): void
    {
        $actor = (new SecurityActorContextFactory(new TokenStorage(), $this->requestInFlight()))->current();

        $this->assertSame(ActorType::ANONYMOUS, $actor->type);
        $this->assertNull($actor->actorId);
    }

    public function testOffRequestResolvesToSystem(): void
    {
        $actor = (new SecurityActorContextFactory(new TokenStorage(), new RequestStack()))->current();

        $this->assertSame(ActorType::SYSTEM, $actor->type);
        $this->assertNull($actor->actorId);
    }

    public function testAnAuthenticatedTokenOffRequestStillResolvesToSystem(): void
    {
        $tokenStorage = new TokenStorage();
        $securityUser = new SecurityUser(UserMother::create());
        $tokenStorage->setToken(new UsernamePasswordToken($securityUser, 'main', $securityUser->getRoles()));

        $actor = (new SecurityActorContextFactory($tokenStorage, new RequestStack()))->current();

        $this->assertSame(ActorType::SYSTEM, $actor->type);
        $this->assertNull($actor->actorId);
    }

    public function testATokenWhoseUserIsNotASecurityUserFallsBackToRequestPresence(): void
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('someone', null), 'main'));

        $actor = (new SecurityActorContextFactory($tokenStorage, $this->requestInFlight()))->current();

        $this->assertSame(ActorType::ANONYMOUS, $actor->type);
        $this->assertNull($actor->actorId);
    }

    private function requestInFlight(): RequestStack
    {
        return new RequestStack([Request::create(self::ENDPOINT)]);
    }
}
