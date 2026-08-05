<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Application\PreIdentityTimingFloor;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Iam\Identity\Infrastructure\Security\ReauthenticateDevice;
use Erpify\Iam\Identity\Infrastructure\Security\UserProvider;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The admission wall in front of every programmatic login, pinned where its removal is *observable*.
 *
 * It needs its own test because coverage was not the same thing as falsifiability here: the guard runs on the
 * happy path of the functional suite, so it counted as covered, while deleting it left every gate green — no
 * assertion anywhere depended on it. The three flows that re-authenticate all do so post-commit, so nothing
 * upstream refuses on their behalf; if this collaborator admits a walled identity, a session is minted for it.
 *
 * Doubles rather than a firewall on purpose: `Security::login()` is the one step that would need a real
 * request context, and every case below is about whether the wall lets execution reach it at all.
 *
 * @internal
 */
#[CoversClass(ReauthenticateDevice::class)]
final class ReauthenticateDeviceTest extends TestCase
{
    public function testItMintsForAnIdentityThatIsStillAdmissible(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->once())->method('login');

        $this->device(UserMother::create(), $security)->reauthenticate(UserMother::DEFAULT_EMAIL);
    }

    public function testItRefusesToMintForAnIdentitySuspendedWhileTheRequestWasInFlight(): void
    {
        $user = UserMother::create();
        $user->suspend();

        $security = $this->createMock(Security::class);
        $security->expects($this->never())->method('login');

        $this->expectException(AccountSuspended::class);

        $this->device($user, $security)->reauthenticate(UserMother::DEFAULT_EMAIL);
    }

    public function testItRefusesToMintForAnIdentityDeactivatedWhileTheRequestWasInFlight(): void
    {
        $user = UserMother::create();
        $user->deactivate();

        $security = $this->createMock(Security::class);
        $security->expects($this->never())->method('login');

        $this->expectException(AccountDeactivated::class);

        $this->device($user, $security)->reauthenticate(UserMother::DEFAULT_EMAIL);
    }

    /**
     * An identity that never completed its invitation holds no credential, so a flow arriving here with one
     * is an orchestration fault. The wall refuses it in the same breath as the other two rather than trusting
     * the caller to have checked.
     */
    public function testItRefusesToMintForAnIdentityThatNeverActivated(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->never())->method('login');

        $this->expectException(AccountDeactivated::class);

        $this->device(UserMother::invited(), $security)->reauthenticate(UserMother::DEFAULT_EMAIL);
    }

    /**
     * The row vanishing between a committed credential change and this line is an orchestration fault, not a
     * user path — it must fail closed rather than mint a session for an identity nobody can vouch for.
     */
    public function testItFailsClosedWhenTheIdentityIsGone(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects($this->never())->method('login');

        $this->expectException(LogicException::class);

        $this->device(null, $security)->reauthenticate(UserMother::DEFAULT_EMAIL);
    }

    private function device(?User $user, Security $security): ReauthenticateDevice
    {
        $users = new InMemoryUserRepository($user);

        return new ReauthenticateDevice(
            $users,
            new UserProvider($users, $this->createStub(PreIdentityTimingFloor::class)),
            $security,
        );
    }
}
