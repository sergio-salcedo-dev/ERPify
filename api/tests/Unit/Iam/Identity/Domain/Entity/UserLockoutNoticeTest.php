<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Domain\Entity;

use DateInterval;
use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Whether the owner of a locked identity is still owed a notice, and what stamping one does to the aggregate.
 *
 * A concern of its own rather than more cases in {@see UserLockoutTest}: that file drives the lock itself —
 * the counter, the expiry, the event — while this one drives the policy layered over it, which is the only
 * home of the rule the scheduled sweep consults and restates nowhere.
 *
 * @internal
 */
#[CoversClass(User::class)]
final class UserLockoutNoticeTest extends TestCase
{
    private const string NOW = '2026-07-11T12:00:00+00:00';

    /**
     * The suppression window, driven at the aggregate because that is where the rule lives — the sweep's SQL
     * expresses candidacy and never restates any of this. Three transitions rather than a send/do-not-send
     * boolean: a predicate that only ever answered "not yet notified" would satisfy both ends and prove no
     * window exists at all.
     */
    #[Test]
    public function theNoticeWindowIsFalsifiableAtBothOfItsEdges(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $staleFrom = $now->sub(new DateInterval('P1D'));

        $neverNotified = $this->lockedAt($now);
        $this->assertTrue($neverNotified->awaitsLockoutNoticeAt($now, $staleFrom));

        $insideTheWindow = $this->lockedAt($now);
        $insideTheWindow->markLockoutNotified($now->sub(new DateInterval('PT23H59M')));
        $this->assertFalse($insideTheWindow->awaitsLockoutNoticeAt($now, $staleFrom));

        $justPastTheWindow = $this->lockedAt($now);
        $justPastTheWindow->markLockoutNotified($staleFrom->sub(new DateInterval('PT1S')));
        $this->assertTrue($justPastTheWindow->awaitsLockoutNoticeAt($now, $staleFrom));
    }

    /**
     * A stamp exactly on the cutoff still suppresses: the comparison is strictly-older-than, so the boundary
     * belongs to the window it closes and one notice can never be answered by two.
     */
    #[Test]
    public function aStampExactlyOnTheCutoffStillSuppresses(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $staleFrom = $now->sub(new DateInterval('P1D'));

        $user = $this->lockedAt($now);
        $user->markLockoutNotified($staleFrom);

        $this->assertFalse($user->awaitsLockoutNoticeAt($now, $staleFrom));
    }

    /**
     * The two conjuncts that are not about the window, each answering a sequence the sweep can actually
     * reach: a lock survives {@see User::deactivate()}, and {@see User::clearLockout()} spares the stamp, so
     * an owner who signs in between the candidate query and this check would otherwise be told their live
     * account was locked *and* have a day of their budget burnt by the false notice.
     */
    #[Test]
    public function aRetiredOrRecoveredIdentityIsOwedNoNotice(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $staleFrom = $now->sub(new DateInterval('P1D'));

        $deactivated = $this->lockedAt($now);
        $deactivated->deactivate();
        $this->assertFalse($deactivated->awaitsLockoutNoticeAt($now, $staleFrom));

        $recovered = $this->lockedAt($now);
        $recovered->clearLockout();
        $this->assertFalse($recovered->awaitsLockoutNoticeAt($now, $staleFrom));

        $lapsed = $this->lockedAt($now);
        $this->assertFalse(
            $lapsed->awaitsLockoutNoticeAt($now->add(new DateInterval('PT16M')), $staleFrom),
        );
    }

    /**
     * `updatedAt` is the keyset sort key of the user register, so an unattended mailer touching it would
     * reorder the administrator list under an open cursor. Its two sibling lockout mutators spare it too.
     */
    #[Test]
    public function stampingTheNoticeLeavesTheKeysetSortKeyAlone(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $user = $this->lockedAt($now);
        $user->pullDomainEvents();

        $before = $user->getUpdatedAt();

        $user->markLockoutNotified($now);

        $this->assertSame($before, $user->getUpdatedAt());
        $this->assertSame([], $user->pullDomainEvents(), 'The lock is the fact worth replaying, not the mail.');
    }

    private function lockedAt(DateTimeImmutable $now): User
    {
        $user = UserMother::create();

        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS; ++$attempt) {
            $user->recordFailedAttempt($now);
        }

        return $user;
    }
}
