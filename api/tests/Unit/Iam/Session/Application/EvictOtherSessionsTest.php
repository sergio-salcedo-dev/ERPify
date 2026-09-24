<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use DateTimeImmutable;
use Erpify\Iam\Session\Application\EvictOtherSessions;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Event\AllSessionsRevoked;
use Erpify\Iam\Session\Domain\Event\OtherSessionsRevoked;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother\SessionMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(EvictOtherSessions::class)]
final class EvictOtherSessionsTest extends TestCase
{
    private const string OTHER_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c7e';

    private const string THIRD_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c7f';

    #[Test]
    public function itRevokesEveryOtherSessionAndReportsTheSurvivorAlive(): void
    {
        $sessions = new InMemorySessionRepository(
            SessionMother::active(),
            SessionMother::active(id: self::OTHER_ID),
            SessionMother::active(id: self::THIRD_ID),
        );
        $eventBus = new RecordingEventBus();

        $survived = $this->useCase($sessions, $eventBus)->evict(
            SessionMother::DEFAULT_USER_ID,
            SessionId::fromString(SessionMother::DEFAULT_ID),
        );

        $this->assertTrue($survived);
        $this->assertSame([SessionMother::DEFAULT_ID], $this->activeIds($sessions));
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(OtherSessionsRevoked::class, $eventBus->publishedEvents[0]);
    }

    /**
     * Its authority is not the survivor, so the survivor being gone does not stop it: the rest are evicted
     * all the same, and the caller learns that there is nobody left to keep.
     */
    #[Test]
    public function aSurvivorRevokedBeforeTheLockIsReportedDeadAndTheRestAreStillEvicted(): void
    {
        $sessions = new InMemorySessionRepository(
            SessionMother::active(),
            SessionMother::active(id: self::OTHER_ID),
        );
        $sessions->beforeLockActive = static function () use ($sessions): void {
            $sessions->revokeOthersForUser(SessionMother::DEFAULT_USER_ID, SessionId::fromString(self::OTHER_ID));
        };

        $eventBus = new RecordingEventBus();

        $survived = $this->useCase($sessions, $eventBus)->evict(
            SessionMother::DEFAULT_USER_ID,
            SessionId::fromString(SessionMother::DEFAULT_ID),
        );

        $this->assertFalse($survived);
        $this->assertSame([], $this->activeIds($sessions));
        // Nothing was kept, so the fact may not name the dead survivor as kept.
        $this->assertCount(1, $eventBus->publishedEvents);
        $this->assertInstanceOf(AllSessionsRevoked::class, $eventBus->publishedEvents[0]);
    }

    #[Test]
    public function aSurvivorSpelledInAnotherCaseIsStillRecognisedAsAlive(): void
    {
        // `iam_session.id` is a `uuid`, whose equality normalises hex case; a spelling difference reporting the
        // survivor dead would withhold a redemption's consumption for no reason at all.
        $sessions = new InMemorySessionRepository(SessionMother::active());

        $survived = $this->useCase($sessions, new RecordingEventBus())->evict(
            SessionMother::DEFAULT_USER_ID,
            SessionId::fromString(\strtoupper(SessionMother::DEFAULT_ID)),
        );

        $this->assertTrue($survived);
    }

    #[Test]
    public function itRejectsAMalformedUserIdBeforeTouchingTheStore(): void
    {
        $sessions = new InMemorySessionRepository();

        $this->expectException(InvalidUuidException::class);

        try {
            $this->useCase($sessions, new RecordingEventBus())->evict('not-a-uuid', SessionId::generate());
        } finally {
            $this->assertSame([], $sessions->revokeOthersCalls);
        }
    }

    /**
     * @return list<string>
     */
    private function activeIds(InMemorySessionRepository $sessions): array
    {
        return \array_map(
            static fn (Session $session): string => $session->getId() ?? '',
            $sessions->findByUserId(SessionMother::DEFAULT_USER_ID),
        );
    }

    private function useCase(InMemorySessionRepository $sessions, RecordingEventBus $eventBus): EvictOtherSessions
    {
        return new EvictOtherSessions(
            $sessions,
            $eventBus,
            new FixedClock(new DateTimeImmutable('2026-07-10T12:00:00+00:00')),
        );
    }
}
