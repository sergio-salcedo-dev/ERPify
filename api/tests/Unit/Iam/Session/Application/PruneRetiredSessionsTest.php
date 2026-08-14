<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use DateTimeImmutable;
use Erpify\Iam\Session\Application\PruneRetiredSessions;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Each case seeds exactly one session and reads the count back, so a row that should have gone and a row that
 * should have stayed can never be confused for each other by a single assertion covering both.
 *
 * The boundary cases are the point rather than decoration: both windows are strict (`<`), so a row dated
 * exactly on its threshold survives, and a change to `<=` would delete a day early on both axes while every
 * "obviously old" and "obviously recent" case kept passing.
 *
 * The first-window-wins rule has a case of its own for the same reason. Restricting the expiry branch to
 * `ACTIVE` rows reads harmless and passes every other case here, while letting a revocation restart the clock
 * on a row that lapsed months earlier.
 *
 * @internal
 */
#[CoversClass(PruneRetiredSessions::class)]
final class PruneRetiredSessionsTest extends TestCase
{
    private const string NOW = '2026-07-10T12:00:00+00:00';

    #[Override]
    protected function tearDown(): void
    {
        // The revocation stamp comes from the ambient clock, so every case sets it; leaving it set would hand
        // the next test in the process a frozen "now" it never asked for.
        SystemClock::reset();
    }

    public function testDeletesASessionRevokedBeforeTheRetentionWindow(): void
    {
        $sessions = new InMemorySessionRepository($this->revokedSession('-31 days'));

        $this->assertSame(1, $this->pruner($sessions)->prune());
    }

    public function testKeepsASessionRevokedExactlyOnTheWindowBoundary(): void
    {
        $sessions = new InMemorySessionRepository($this->revokedSession('-30 days'));

        $this->assertSame(0, $this->pruner($sessions)->prune());
    }

    public function testDeletesAnUnrevokedSessionThatExpiredBeforeTheRetentionWindow(): void
    {
        $sessions = new InMemorySessionRepository($this->activeSession('-91 days'));

        $this->assertSame(1, $this->pruner($sessions)->prune());
    }

    public function testKeepsAnUnrevokedSessionExpiringExactlyOnTheWindowBoundary(): void
    {
        $sessions = new InMemorySessionRepository($this->activeSession('-90 days'));

        $this->assertSame(0, $this->pruner($sessions)->prune());
    }

    public function testKeepsALiveSession(): void
    {
        $sessions = new InMemorySessionRepository($this->activeSession('+1 hour'));

        $this->assertSame(0, $this->pruner($sessions)->prune());
    }

    public function testKeepsARevokedSessionStillInsideBothWindows(): void
    {
        $sessions = new InMemorySessionRepository($this->revokedSession('-1 day'));

        $this->assertSame(0, $this->pruner($sessions)->prune());
    }

    public function testARevocationCannotExtendTheLifeOfAnAlreadyLapsedSession(): void
    {
        // Revoked yesterday, but lapsed two hundred days before that. Judging a revoked row by `revokedAt`
        // alone would hand it another thirty days for having been revoked — and the bulk revocations stamp
        // every ACTIVE row of a user, long-expired ones included, so an ordinary password change would restart
        // the retention clock on rows that had been dead for months.
        $sessions = new InMemorySessionRepository($this->revokedSession('-1 day', expiryOffset: '-200 days'));

        $this->assertSame(1, $this->pruner($sessions)->prune());
    }

    public function testCountsEveryKindOfRetiredRowItRemoves(): void
    {
        $sessions = new InMemorySessionRepository(
            $this->revokedSession('-31 days'),
            $this->activeSession('-91 days'),
            $this->activeSession('+1 hour'),
        );

        $this->assertSame(2, $this->pruner($sessions)->prune());
    }

    public function testASecondSweepAtTheSameInstantRemovesNothing(): void
    {
        $sessions = new InMemorySessionRepository($this->revokedSession('-31 days'));
        $pruner = $this->pruner($sessions);

        $this->assertSame(1, $pruner->prune());
        $this->assertSame(0, $pruner->prune());
    }

    private function revokedSession(string $revokedOffset, string $expiryOffset = '+1 hour'): Session
    {
        $now = new DateTimeImmutable(self::NOW);
        $session = SessionMother::active(id: Uuid::generate(), expiresAt: $now->modify($expiryOffset));

        SystemClock::set(new FixedClock($now->modify($revokedOffset)));
        $session->revoke();

        return $session;
    }

    private function activeSession(string $expiryOffset): Session
    {
        $now = new DateTimeImmutable(self::NOW);

        return SessionMother::active(id: Uuid::generate(), expiresAt: $now->modify($expiryOffset));
    }

    private function pruner(InMemorySessionRepository $sessions): PruneRetiredSessions
    {
        return new PruneRetiredSessions($sessions, new FixedClock(new DateTimeImmutable(self::NOW)));
    }
}
