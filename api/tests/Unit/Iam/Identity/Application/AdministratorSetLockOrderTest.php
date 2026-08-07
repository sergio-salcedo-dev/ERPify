<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\ChangeUserRoles;
use Erpify\Iam\Identity\Application\ChangeUserStatus;
use Erpify\Iam\Identity\Application\RevokeSessionsBestEffort;
use Erpify\Iam\Session\Application\RevokeAllSessions;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The order in which the two write-side transitions acquire their `identity_user` locks, kept apart from the
 * behaviour tests of either use case because it is one claim about both and a failure has to say so.
 *
 * Both transitions lock two things: the target row, and the active-admin set that
 * {@see \Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory} guards. The set is locked
 * `ORDER BY id`, and a target carrying `ADMIN` is a member of it — so taking the row first holds one member
 * out of that order. Two concurrent transitions on administrators X and Y (`X.id < Y.id`) then each hold one
 * and wait for the other: an ABBA deadlock, surfacing as `40P01` and a 503 with nothing in the code to
 * explain it.
 *
 * What is asserted is therefore a POSITION, not a call. A set lock taken after the row lock orders nothing
 * that has not already happened, so a test that only checked `lockActiveAdministrators()` was reached would
 * pass over the single arrangement the operation exists to forbid. The double records how many row locks had
 * been taken at the instant the set lock was, and the answer has to be none.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(ChangeUserRoles::class)]
#[CoversClass(ChangeUserStatus::class)]
final class AdministratorSetLockOrderTest extends TestCase
{
    private const string OTHER_ADMIN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70';

    #[Test]
    public function changingRolesLocksTheAdministratorSetBeforeTheTargetRow(): void
    {
        $repository = new InMemoryUserRepository(UserMother::create());
        $directory = $this->directoryObserving($repository);

        (new ChangeUserRoles(
            $repository,
            $directory,
            $this->revokeSessions(),
            new RecordingEventBus(),
            new RecordingAuditLogger(),
            new InlineTransactionManager(),
        ))->run(UserMother::DEFAULT_ID, Role::EDITOR);

        $this->assertSame([0], $directory->rowLocksTakenBeforeEachSetLock);
    }

    #[Test]
    public function changingStatusLocksTheAdministratorSetBeforeTheTargetRow(): void
    {
        $repository = new InMemoryUserRepository(UserMother::create());
        $directory = $this->directoryObserving($repository);

        (new ChangeUserStatus(
            $repository,
            $directory,
            $this->revokeSessions(),
            new RecordingEventBus(),
            new InlineTransactionManager(),
        ))->suspend(UserMother::DEFAULT_ID);

        $this->assertSame([0], $directory->rowLocksTakenBeforeEachSetLock);
    }

    #[Test]
    public function theSetIsLockedEvenWhenNothingGoesOnToAskTheGuardItsQuestion(): void
    {
        // A role change that alters nothing returns before the guard runs, and the guard is the other member
        // that takes this lock. Exempting this branch is not an option even though, in isolation, a
        // transaction holding one row and requesting nothing further can never be a node in a wait-for cycle:
        // you cannot know you are on it until the target row is already locked, so the exemption would put the
        // row first on every path, including the ones that go on to need the order.
        $repository = new InMemoryUserRepository(UserMother::create(roles: [Role::EDITOR]));
        $directory = $this->directoryObserving($repository);

        (new ChangeUserRoles(
            $repository,
            $directory,
            $this->revokeSessions(),
            new RecordingEventBus(),
            new RecordingAuditLogger(),
            new InlineTransactionManager(),
        ))->run(UserMother::DEFAULT_ID, Role::EDITOR);

        $this->assertSame(1, $directory->setLocksTaken);
        $this->assertSame(
            [],
            $directory->askedWithout,
            'the guard ran after all, so the lock could have ridden on it and this proves nothing',
        );
    }

    private function directoryObserving(InMemoryUserRepository $repository): InMemoryActiveAdministratorDirectory
    {
        return new InMemoryActiveAdministratorDirectory(
            [self::OTHER_ADMIN_ID => true],
            static fn (): int => \count($repository->forUpdateCalls),
        );
    }

    private function revokeSessions(): RevokeSessionsBestEffort
    {
        return new RevokeSessionsBestEffort(
            new RevokeAllSessions(
                new InMemorySessionRepository(),
                new RecordingEventBus(),
                new InlineTransactionManager(),
                new FixedClock(new DateTimeImmutable('2026-08-07T12:00:00+00:00')),
            ),
            new NullLogger(),
        );
    }
}
