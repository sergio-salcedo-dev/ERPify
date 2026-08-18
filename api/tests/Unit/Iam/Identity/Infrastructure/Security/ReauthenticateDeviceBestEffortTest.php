<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Infrastructure\Security\ReauthenticateDevice;
use Erpify\Iam\Identity\Infrastructure\Security\ReauthenticateDeviceBestEffort;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The containment around the post-commit re-login, pinned where its removal is observable.
 *
 * It needs pinning precisely because it is invisible on the happy path: the branch only runs when the wall
 * refuses, and letting the refusal escape would answer a request whose mutation fully committed with a 403 or
 * a 500. No functional test reaches it — under `KernelBrowser` the programmatic login runs but its listeners
 * do not — so this is where "a failed re-login never decides the status code" is actually asserted.
 *
 * The refusal is provoked through the real collaborator rather than a double of it: `ReauthenticateDevice` is
 * `final readonly`, and a suspended identity behind an in-memory repository reproduces exactly the throw the
 * production path would raise.
 *
 * @internal
 */
#[CoversClass(ReauthenticateDeviceBestEffort::class)]
final class ReauthenticateDeviceBestEffortTest extends TestCase
{
    public function testARefusedReLoginIsSwallowedAndRecordedAsCritical(): void
    {
        $user = UserMother::create();
        $user->suspend();

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('critical');

        // Containing the refusal must not soften it: the wall still refuses, so no session is minted.
        $security = $this->createMock(Security::class);
        $security->expects($this->never())->method('login');

        // No exception escapes: the mutation this follows has already committed.
        $this->bestEffort($user, $logger, $security)->reauthenticate(UserMother::DEFAULT_EMAIL);
    }

    public function testAnIdentityThatVanishedIsContainedToo(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('critical');

        $security = $this->createMock(Security::class);
        $security->expects($this->never())->method('login');

        $this->bestEffort(null, $logger, $security)->reauthenticate(UserMother::DEFAULT_EMAIL);
    }

    public function testAnAdmittedIdentityMintsAndSaysNothing(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('critical');

        $security = $this->createMock(Security::class);
        $security->expects($this->once())->method('login');

        $this->bestEffort(UserMother::create(), $logger, $security)->reauthenticate(UserMother::DEFAULT_EMAIL);
    }

    private function bestEffort(
        ?User $user,
        LoggerInterface $logger,
        ?Security $security = null,
    ): ReauthenticateDeviceBestEffort {
        $users = new InMemoryUserRepository($user);

        return new ReauthenticateDeviceBestEffort(
            new ReauthenticateDevice($users, $security ?? $this->createStub(Security::class)),
            $logger,
        );
    }
}
