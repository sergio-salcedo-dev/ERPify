<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Iam\Identity\Infrastructure\Security\UserProvider;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @internal
 */
#[CoversClass(UserProvider::class)]
final class UserProviderTest extends TestCase
{
    public function testLoadsAKnownUserAsASecurityUser(): void
    {
        $provider = $this->provider(new InMemoryUserRepository(UserMother::create()));

        $securityUser = $provider->loadUserByIdentifier(UserMother::DEFAULT_EMAIL);

        $this->assertSame(UserMother::DEFAULT_EMAIL, $securityUser->getUserIdentifier());
        $this->assertSame(['ROLE_AUDIT_READER'], $securityUser->getRoles());
    }

    public function testLoadsCaseInsensitively(): void
    {
        $provider = $this->provider(new InMemoryUserRepository(UserMother::create()));

        $securityUser = $provider->loadUserByIdentifier('  ALICE@ERPIFY.TEST  ');

        $this->assertSame(UserMother::DEFAULT_EMAIL, $securityUser->getUserIdentifier());
    }

    public function testRejectsAnUnknownEmailAsUserNotFound(): void
    {
        $provider = $this->provider(new InMemoryUserRepository());

        $this->expectException(UserNotFoundException::class);

        $provider->loadUserByIdentifier('nobody@erpify.test');
    }

    public function testTreatsABlankIdentifierAsUserNotFoundNeverAServerError(): void
    {
        $provider = $this->provider(new InMemoryUserRepository());

        $this->expectException(UserNotFoundException::class);

        $provider->loadUserByIdentifier('   ');
    }

    public function testAnUnknownEmailStillRunsAHashVerificationSoTimingDoesNotLeakExistence(): void
    {
        $hasher = $this->createMock(PasswordHasherInterface::class);
        $hasher->expects($this->once())->method('hash')->willReturn('dummy-hash');
        $hasher->expects($this->once())->method('verify')->with('dummy-hash', $this->anything())->willReturn(false);

        $factory = $this->createMock(PasswordHasherFactoryInterface::class);
        $factory->expects($this->once())
            ->method('getPasswordHasher')
            ->with(SecurityUser::class)
            ->willReturn($hasher)
        ;

        $provider = new UserProvider(new InMemoryUserRepository(), $factory);

        $this->expectException(UserNotFoundException::class);

        $provider->loadUserByIdentifier('nobody@erpify.test');
    }

    public function testRefreshReloadsTheUserFromTheRepository(): void
    {
        $user = UserMother::create();
        $provider = $this->provider(new InMemoryUserRepository($user));

        $refreshed = $provider->refreshUser(new SecurityUser($user));

        $this->assertSame(UserMother::DEFAULT_EMAIL, $refreshed->getUserIdentifier());
    }

    public function testRejectsRefreshingAForeignUserType(): void
    {
        $provider = $this->provider(new InMemoryUserRepository());

        $this->expectException(UnsupportedUserException::class);

        $provider->refreshUser($this->createStub(UserInterface::class));
    }

    public function testSupportsOnlyItsOwnSecurityUser(): void
    {
        $provider = $this->provider(new InMemoryUserRepository());

        $this->assertTrue($provider->supportsClass(SecurityUser::class));
        $this->assertFalse($provider->supportsClass(UserInterface::class));
    }

    private function provider(InMemoryUserRepository $repository): UserProvider
    {
        return new UserProvider($repository, new PasswordHasherFactory([
            SecurityUser::class => ['algorithm' => 'plaintext'],
        ]));
    }
}
