<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Messenger\Maintenance;

use Erpify\Iam\Identity\Application\ErasureResweepIncomplete;
use Erpify\Iam\Identity\Application\ResweepErasedSubjects;
use Erpify\Iam\Identity\Domain\Entity\ErasureResweep;
use Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\ResweepErasedSubjectsHandler;
use Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\ResweepErasedSubjectsMessage;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Shared\Audit\Infrastructure\Persistence\OrderedAuditSubjectTrailErasure;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Event\Application\EventStoreSubjectAnonymiser;
use Erpify\Shared\Event\Application\SubjectPseudonymisation;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryErasureResweepRepository;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditActorAnonymiser;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditResourceAnonymiser;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditSubjectRowLock;
use Erpify\Tests\Unit\Shared\Event\Infrastructure\Double\RecordingEventStoreSubjectAnonymiser;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The handler is the re-sweep's only arm, so its contract is two-sided: a tick reaches the sweep exactly once,
 * and a tick that could not sweep a subject leaves through the handler rather than being absorbed by it —
 * Messenger logging it at `critical` is the only signal a stuck subject produces before the detective source.
 * What each pass does is the use case's, pinned by its own cases.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(ResweepErasedSubjectsHandler::class)]
final class ResweepErasedSubjectsHandlerTest extends TestCase
{
    private const string SUBJECT_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a11';

    public function testATickSweepsEveryScheduledSubjectExactlyOnce(): void
    {
        $actor = new RecordingAuditActorAnonymiser(matchCount: 0);

        $this->handler($actor, new RecordingEventStoreSubjectAnonymiser())(new ResweepErasedSubjectsMessage());

        $this->assertSame([self::SUBJECT_ID], $actor->anonymisedActorIds);
    }

    public function testATickThatCouldNotSweepASubjectIsNotAbsorbed(): void
    {
        $failing = new class implements EventStoreSubjectAnonymiser {
            #[Override]
            public function anonymise(SubjectPseudonymisation $pseudonymisation): int
            {
                unset($pseudonymisation);

                throw new RuntimeException('event store unavailable');
            }
        };

        $this->expectException(ErasureResweepIncomplete::class);

        $this->handler(new RecordingAuditActorAnonymiser(matchCount: 0), $failing)(new ResweepErasedSubjectsMessage());
    }

    private function handler(
        RecordingAuditActorAnonymiser $actor,
        EventStoreSubjectAnonymiser $events,
    ): ResweepErasedSubjectsHandler {
        return new ResweepErasedSubjectsHandler(new ResweepErasedSubjects(
            new InMemoryErasureResweepRepository(ErasureResweep::scheduleFor(self::SUBJECT_ID)),
            new OrderedAuditSubjectTrailErasure(
                new RecordingAuditSubjectRowLock(),
                $actor,
                new RecordingAuditResourceAnonymiser(matchCount: 0),
            ),
            $events,
            new PurgeUserSessions(new InMemorySessionRepository()),
            new RecordingAuditLogger(),
            new InlineTransactionManager(),
            new FixedClock(SystemClock::now()),
        ));
    }
}
