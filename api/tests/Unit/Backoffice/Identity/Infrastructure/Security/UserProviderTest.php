<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Identity\Infrastructure\Security;

use Erpify\Backoffice\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Backoffice\Identity\Infrastructure\Security\UserProvider;
use Erpify\Tests\Unit\Backoffice\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Backoffice\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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
        $provider = new UserProvider(new InMemoryUserRepository(UserMother::create()));

        $securityUser = $provider->loadUserByIdentifier(UserMother::DEFAULT_EMAIL);

        $this->assertSame(UserMother::DEFAULT_EMAIL, $securityUser->getUserIdentifier());
        $this->assertSame(['ROLE_AUDIT_READER'], $securityUser->getRoles());
    }

    public function testLoadsCaseInsensitively(): void
    {
        $provider = new UserProvider(new InMemoryUserRepository(UserMother::create()));

        $securityUser = $provider->loadUserByIdentifier('  ALICE@ERPIFY.TEST  ');

        $this->assertSame(UserMother::DEFAULT_EMAIL, $securityUser->getUserIdentifier());
    }

    public function testRejectsAnUnknownEmailAsUserNotFound(): void
    {
        $provider = new UserProvider(new InMemoryUserRepository());

        $this->expectException(UserNotFoundException::class);

        $provider->loadUserByIdentifier('nobody@erpify.test');
    }

    public function testTreatsABlankIdentifierAsUserNotFoundNeverAServerError(): void
    {
        $provider = new UserProvider(new InMemoryUserRepository());

        $this->expectException(UserNotFoundException::class);

        $provider->loadUserByIdentifier('   ');
    }

    public function testRefreshReloadsTheUserFromTheRepository(): void
    {
        $user = UserMother::create();
        $provider = new UserProvider(new InMemoryUserRepository($user));

        $refreshed = $provider->refreshUser(new SecurityUser($user));

        $this->assertSame(UserMother::DEFAULT_EMAIL, $refreshed->getUserIdentifier());
    }

    public function testRejectsRefreshingAForeignUserType(): void
    {
        $provider = new UserProvider(new InMemoryUserRepository());

        $this->expectException(UnsupportedUserException::class);

        $provider->refreshUser($this->createStub(UserInterface::class));
    }

    public function testSupportsOnlyItsOwnSecurityUser(): void
    {
        $provider = new UserProvider(new InMemoryUserRepository());

        $this->assertTrue($provider->supportsClass(SecurityUser::class));
        $this->assertFalse($provider->supportsClass(UserInterface::class));
    }
}
