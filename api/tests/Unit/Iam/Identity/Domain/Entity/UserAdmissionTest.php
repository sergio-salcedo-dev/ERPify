<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Entity;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The post-identity admission wall: which statuses may act, and which refusal each one warrants. Held apart
 * from {@see UserLifecycleTest} (the transition machine) because it pins a different question — not how the
 * status changes, but what the status *means* to a caller — and because neither class may trip the per-class
 * public-method budget.
 *
 * The wall is exhaustive over {@see \Erpify\Iam\Identity\Domain\Enum\IdentityStatus} on purpose: a status
 * added later fails the `match` loudly instead of being admitted by an `else` nobody revisited.
 *
 * @internal
 */
#[CoversClass(User::class)]
final class UserAdmissionTest extends TestCase
{
    public function testAnActiveIdentityPassesTheAdmissionWall(): void
    {
        $user = UserMother::create();

        $user->ensureActive();

        $this->addToAssertionCount(1);
    }

    public function testASuspendedIdentityIsWalledWithItsOwnReason(): void
    {
        $user = UserMother::create();
        $user->suspend();

        $this->expectException(AccountSuspended::class);

        $user->ensureActive();
    }

    public function testADeactivatedIdentityIsWalledWithItsOwnReason(): void
    {
        $user = UserMother::create();
        $user->deactivate();

        $this->expectException(AccountDeactivated::class);

        $user->ensureActive();
    }

    public function testAnInvitedIdentityIsWalled(): void
    {
        // Unreachable through the reset flow — a token is only ever minted for an ACTIVE identity and no
        // transition returns to INVITED — but the wall must still refuse it rather than admit by omission.
        $user = User::invite(UserMother::DEFAULT_ID, UserMother::DEFAULT_EMAIL);

        $this->expectException(AccountDeactivated::class);

        $user->ensureActive();
    }
}
