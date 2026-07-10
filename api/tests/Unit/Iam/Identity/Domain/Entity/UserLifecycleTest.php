<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Entity;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Event\UserDeactivated;
use Erpify\Iam\Identity\Domain\Event\UserSuspended;
use Erpify\Iam\Identity\Domain\Exception\InvalidIdentityTransition;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The identity lifecycle machine: `INVITED → ACTIVE → {SUSPENDED, DEACTIVATED}`. Held apart from the
 * identity/role concerns in {@see UserTest} so neither class trips the per-class public-method budget.
 *
 * @internal
 */
#[CoversClass(User::class)]
final class UserLifecycleTest extends TestCase
{
    public function testInviteProvisionsAnInvitedIdentityWithoutACredential(): void
    {
        $user = User::invite(UserMother::DEFAULT_ID, UserMother::DEFAULT_EMAIL);

        $this->assertSame(IdentityStatus::INVITED, $user->status());
        $this->assertNotInstanceOf(HashedPassword::class, $user->passwordHash());
    }

    public function testRegisterProvisionsAnActiveCredentialedIdentity(): void
    {
        $password = HashedPassword::fromHash('a-precomputed-hash');

        $user = User::register(UserMother::DEFAULT_ID, UserMother::DEFAULT_EMAIL, $password);

        $this->assertSame(IdentityStatus::ACTIVE, $user->status());
        $passwordHash = $user->passwordHash();
        $this->assertInstanceOf(HashedPassword::class, $passwordHash);
        $this->assertTrue($passwordHash->equals($password));
    }

    public function testActivateFixesTheCredentialAndAdmitsTheInvitedIdentity(): void
    {
        $user = UserMother::invited();
        $password = HashedPassword::fromHash('the-chosen-hash');

        $user->activate($password);

        $this->assertSame(IdentityStatus::ACTIVE, $user->status());
        $passwordHash = $user->passwordHash();
        $this->assertInstanceOf(HashedPassword::class, $passwordHash);
        $this->assertTrue($passwordHash->equals($password));
    }

    public function testActivateIsRejectedWhenTheIdentityIsAlreadyActive(): void
    {
        $this->expectException(InvalidIdentityTransition::class);

        UserMother::create()->activate(HashedPassword::fromHash('another-hash'));
    }

    public function testSuspendRaisesTheReversibleWallAndRecordsTheEvent(): void
    {
        $user = UserMother::create();

        $user->suspend();

        $this->assertSame(IdentityStatus::SUSPENDED, $user->status());

        $events = $user->pullDomainEvents();
        $this->assertCount(1, $events);

        foreach ($events as $event) {
            $this->assertInstanceOf(UserSuspended::class, $event);
            $this->assertSame(UserMother::DEFAULT_ID, $event->aggregateId());
        }
    }

    public function testDeactivateRetiresTheIdentityAndRecordsTheEvent(): void
    {
        $user = UserMother::create();

        $user->deactivate();

        $this->assertSame(IdentityStatus::DEACTIVATED, $user->status());

        $events = $user->pullDomainEvents();
        $this->assertCount(1, $events);

        foreach ($events as $event) {
            $this->assertInstanceOf(UserDeactivated::class, $event);
            $this->assertSame(UserMother::DEFAULT_ID, $event->aggregateId());
        }
    }

    public function testAnIllegalTransitionIsRejectedBeforeAnyMutationOrEvent(): void
    {
        $user = UserMother::invited();

        try {
            $user->suspend();
            $this->fail('Expected suspend() to reject a non-ACTIVE identity.');
        } catch (InvalidIdentityTransition) {
            // the guard runs before the aggregate mutates or records anything
        }

        $this->assertSame(IdentityStatus::INVITED, $user->status());
        $this->assertSame([], $user->pullDomainEvents());
    }

    public function testDeactivateIsRejectedFromANonActiveState(): void
    {
        $this->expectException(InvalidIdentityTransition::class);

        UserMother::invited()->deactivate();
    }
}
