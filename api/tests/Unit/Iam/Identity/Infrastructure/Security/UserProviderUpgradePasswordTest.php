<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Application\RehashPasswordBestEffort;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Iam\Identity\Infrastructure\Security\UserProvider;
use Erpify\Tests\Unit\Iam\Identity\Application\CountingPreIdentityTimingFloor;
use Erpify\Tests\Unit\Iam\Identity\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

/**
 * The provider's second role, kept apart from its lookup behaviour because it answers a different question —
 * not "who is this identifier" but "which credential did this login prove, and may it be re-encoded".
 *
 * @internal
 */
#[CoversClass(UserProvider::class)]
final class UserProviderUpgradePasswordTest extends TestCase
{
    public function testUpgradesTheHashTheAuthenticatedUserCarried(): void
    {
        $user = UserMother::create();
        $repository = new InMemoryUserRepository($user);

        $this->provider($repository)->upgradePassword(new SecurityUser($user), 're-encoded-at-the-configured-cost');

        $this->assertSame([UserMother::DEFAULT_ID], $repository->replacePasswordHashCalls);
        $this->assertSame('re-encoded-at-the-configured-cost', $user->passwordHash()?->toString());
    }

    /**
     * The verified hash is read off the object the firewall authenticated. A provider that reloaded the row
     * instead would read whatever a concurrent reset had just committed and "verify" against it — handing the
     * swap a credential the login never proved.
     */
    public function testComparesAgainstTheCredentialTheLoginProvedNotTheCurrentRow(): void
    {
        $proved = UserMother::create();
        $current = UserMother::create(password: HashedPassword::fromHash('set-by-a-reset-after-the-login-verified'));
        $repository = new InMemoryUserRepository($current);

        $this->provider($repository)->upgradePassword(new SecurityUser($proved), 're-encoded-at-the-configured-cost');

        $this->assertSame('set-by-a-reset-after-the-login-verified', $current->passwordHash()?->toString());
    }

    public function testIgnoresAUserItDoesNotProvide(): void
    {
        $repository = new InMemoryUserRepository(UserMother::create());

        $this->provider($repository)->upgradePassword(
            $this->createStub(PasswordAuthenticatedUserInterface::class),
            're-encoded-at-the-configured-cost',
        );

        $this->assertSame([], $repository->replacePasswordHashCalls);
    }

    public function testIgnoresAnIdentityWithoutAnIdOrACredential(): void
    {
        $repository = new InMemoryUserRepository();
        $withoutId = UserMother::create();
        $withoutId->setId(null);

        $provider = $this->provider($repository);
        $provider->upgradePassword(new SecurityUser($withoutId), 're-encoded');
        $provider->upgradePassword(new SecurityUser(UserMother::invited()), 're-encoded');

        $this->assertSame([], $repository->replacePasswordHashCalls);
    }

    private function provider(InMemoryUserRepository $repository): UserProvider
    {
        return new UserProvider(
            $repository,
            new CountingPreIdentityTimingFloor(),
            new RehashPasswordBestEffort($repository, new InlineTransactionManager(), new NullLogger()),
        );
    }
}
