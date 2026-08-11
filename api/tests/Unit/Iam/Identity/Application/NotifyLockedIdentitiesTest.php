<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateInterval;
use DateTimeImmutable;
use Erpify\Iam\Identity\Application\NotifyLockedIdentities;
use Erpify\Iam\Identity\Domain\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The sweep, driven against a directory that filters nothing. The aggregate's predicate is therefore the only
 * thing between a seeded identity and a mail here, which is what makes these assertions about the control
 * rather than about a fake reimplementation of its `WHERE` clause.
 *
 * The suppression window itself is falsified at the aggregate, in
 * {@see \Erpify\Tests\Unit\Iam\Identity\Domain\Entity\UserLockoutNoticeTest}. What this file adds is the
 * part no aggregate test can see — that one tick reads the clock once, that the stamp follows a send rather
 * than reserving it, and that a fault on one row does not consume the rest.
 *
 * @internal
 */
#[CoversClass(NotifyLockedIdentities::class)]
final class NotifyLockedIdentitiesTest extends TestCase
{
    use BuildsLockoutNotifier;

    private const string NOW = '2026-08-11T12:00:00+00:00';

    private const string FIRST_ID = '0190a1b2-c3d4-7e5f-8a9b-000000000001';

    private const string SECOND_ID = '0190a1b2-c3d4-7e5f-8a9b-000000000002';

    private const string THIRD_ID = '0190a1b2-c3d4-7e5f-8a9b-000000000003';

    #[Test]
    public function notifiesTheOwnerOfEveryLockedIdentityAndStampsTheWindow(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $repository = $this->repositoryOf(
            $this->lockedAt($now, self::FIRST_ID),
            $this->lockedAt($now, self::SECOND_ID),
        );
        $sender = new RecordingAccountLockedEmailSender();

        $this->sweep($repository, $sender, new FixedClock($now), self::FIRST_ID, self::SECOND_ID);

        $this->assertSame($this->emailsOf(self::FIRST_ID, self::SECOND_ID), $sender->sentTo);
        $this->assertCount(2, $repository->saved);
    }

    /**
     * The suppression, end to end. Nothing else in the suite pins it: the aggregate proves the predicate
     * answers `false` inside the window, and only a second tick proves the sweep actually asks it before
     * sending rather than mailing on the strength of the candidate query alone.
     */
    #[Test]
    public function aSecondTickInsideTheWindowSendsNothing(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $repository = $this->repositoryOf($this->lockedAt($now, self::FIRST_ID));
        $sender = new RecordingAccountLockedEmailSender();
        $notifier = $this->notifier($repository, $sender, new FixedClock($now), self::FIRST_ID);

        $notifier->notifyLockedOwners();
        $notifier->notifyLockedOwners();

        $this->assertSame($this->emailsOf(self::FIRST_ID), $sender->sentTo, 'The window must survive the tick.');
    }

    #[Test]
    public function aFailedSendLeavesTheWindowUnspentAndTheSweepFinishesTheRest(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $repository = $this->repositoryOf(
            $this->lockedAt($now, self::FIRST_ID),
            $this->lockedAt($now, self::SECOND_ID),
            $this->lockedAt($now, self::THIRD_ID),
        );
        $sender = new RecordingAccountLockedEmailSender([$this->emailFor(self::SECOND_ID)]);

        $this->sweep(
            $repository,
            $sender,
            new FixedClock($now),
            self::FIRST_ID,
            self::SECOND_ID,
            self::THIRD_ID,
        );

        $everyone = $this->emailsOf(self::FIRST_ID, self::SECOND_ID, self::THIRD_ID);
        $this->assertSame($everyone, $sender->attempted);
        $this->assertSame($this->emailsOf(self::FIRST_ID, self::THIRD_ID), $sender->sentTo);
        $this->assertCount(2, $repository->saved, 'A send that failed must not consume the day.');
        $this->assertNotContains(self::SECOND_ID, $this->idsOf($repository->saved));
    }

    /**
     * A repository or flush fault is absorbed per row. Without the boundary the throw would abandon every
     * identity ordered after it — and since the order is by id and stable across ticks, one permanently
     * unwritable row would starve the same tail on every subsequent tick rather than costing one notice.
     */
    #[Test]
    public function aPersistenceFaultOnOneRowDoesNotAbandonTheRest(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $repository = $this->repositoryOf(
            $this->lockedAt($now, self::FIRST_ID),
            $this->lockedAt($now, self::SECOND_ID),
            $this->lockedAt($now, self::THIRD_ID),
        );
        $repository->onSave = static function (User $user): void {
            if (self::SECOND_ID === $user->getId()) {
                throw new RuntimeException('the flush failed');
            }
        };
        $sender = new RecordingAccountLockedEmailSender();

        $this->sweep(
            $repository,
            $sender,
            new FixedClock($now),
            self::FIRST_ID,
            self::SECOND_ID,
            self::THIRD_ID,
        );

        $this->assertSame($this->emailsOf(self::FIRST_ID, self::SECOND_ID, self::THIRD_ID), $sender->sentTo);
        $this->assertSame([self::FIRST_ID, self::THIRD_ID], $this->idsOf($repository->saved));
    }

    /**
     * One `now` for the execution, asserted rather than merely implemented. A {@see FixedClock} cannot see
     * this: it answers the same instant however often it is read, so a per-row read produces identical output.
     */
    #[Test]
    public function readsTheClockOnceForTheWholeSweep(): void
    {
        $clock = AdvancingClock::at(self::NOW);
        $now = $clock->firstInstant();
        $repository = $this->repositoryOf(
            $this->lockedAt($now, self::FIRST_ID),
            $this->lockedAt($now, self::SECOND_ID),
            $this->lockedAt($now, self::THIRD_ID),
        );
        $directory = new InMemoryLockedIdentityDirectory([self::FIRST_ID, self::SECOND_ID, self::THIRD_ID]);

        $this->notifierWith($repository, new RecordingAccountLockedEmailSender(), $clock, $directory)
            ->notifyLockedOwners()
        ;

        $this->assertSame(1, $clock->calls, 'A clock read per row makes predicate and stamp disagree.');
        $this->assertSame([$now], $directory->queriedAt);
    }

    /**
     * Every row is stamped with the very instant the candidate query was made with. Asserted through the
     * aggregate's own predicate rather than a getter the production code has no use for: a stamp is strictly
     * older than the cutoff or it is not, so bracketing the cutoff on either side of an instant pins the
     * stamp to exactly that instant.
     */
    #[Test]
    public function stampsEveryRowWithTheInstantItQueriedWith(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $repository = $this->repositoryOf(
            $this->lockedAt($now, self::FIRST_ID),
            $this->lockedAt($now, self::SECOND_ID),
        );

        $this->sweep(
            $repository,
            new RecordingAccountLockedEmailSender(),
            new FixedClock($now),
            self::FIRST_ID,
            self::SECOND_ID,
        );

        $this->assertCount(2, $repository->saved);

        foreach ($repository->saved as $user) {
            $this->assertFalse(
                $user->awaitsLockoutNoticeAt($now, $now),
                'A cutoff at the stamp itself must suppress: the stamp is no older than it.',
            );
            $this->assertTrue(
                $user->awaitsLockoutNoticeAt($now, $now->add(new DateInterval('PT1S'))),
                'A cutoff one second later must not: the stamp is no newer than it either.',
            );
        }
    }

    /**
     * The stamp follows a send that reported success. Reserving the window first would turn a mailer outage
     * into a full day of silence about a live lockout, and no assertion taken once the sweep has returned can
     * tell the two orders apart — hence the sample taken at the moment of the send.
     */
    #[Test]
    public function stampsOnlyBehindASendThatSucceeded(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $repository = $this->repositoryOf(
            $this->lockedAt($now, self::FIRST_ID),
            $this->lockedAt($now, self::SECOND_ID),
        );
        $sender = new RecordingAccountLockedEmailSender(
            [],
            static fn (): int => \count($repository->saved),
        );

        $this->sweep($repository, $sender, new FixedClock($now), self::FIRST_ID, self::SECOND_ID);

        // One row already persisted when the second send fires, none when the first does: each save trails
        // its own send. A reserve-then-send order would read [1, 2].
        $this->assertSame([0, 1], $sender->samples);
    }

    /**
     * The candidate query is candidacy and nothing more, so the aggregate has to be able to veto a row the
     * SQL handed over. Both vetoes are reachable: a lock outlives {@see User::deactivate()}, and an owner who
     * signs in between the query and the hydration clears the lock while the stamp stands.
     */
    #[Test]
    public function anIdentityTheAggregateRefusesIsNeverMailed(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        $deactivated = $this->lockedAt($now, self::FIRST_ID);
        $deactivated->deactivate();

        $recovered = $this->lockedAt($now, self::SECOND_ID);
        $recovered->clearLockout();

        $repository = $this->repositoryOf($deactivated, $recovered);
        $sender = new RecordingAccountLockedEmailSender();

        $this->sweep($repository, $sender, new FixedClock($now), self::FIRST_ID, self::SECOND_ID);

        $this->assertSame([], $sender->attempted);
        $this->assertSame([], $repository->saved);
    }

    /** An id the directory returned and the repository no longer has — erased between the two statements. */
    #[Test]
    public function aVanishedIdentityIsSkippedWithoutSinkingTheSweep(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $repository = $this->repositoryOf($this->lockedAt($now, self::SECOND_ID));
        $sender = new RecordingAccountLockedEmailSender();

        $this->sweep($repository, $sender, new FixedClock($now), self::FIRST_ID, self::SECOND_ID);

        $this->assertSame($this->emailsOf(self::SECOND_ID), $sender->sentTo);
    }
}
