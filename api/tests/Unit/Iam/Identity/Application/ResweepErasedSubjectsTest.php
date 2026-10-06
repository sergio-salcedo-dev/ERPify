<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use ArrayObject;
use DateInterval;
use Erpify\Iam\Identity\Application\ErasureResweepIncomplete;
use Erpify\Iam\Identity\Application\ResweepErasedSubjects;
use Erpify\Iam\Identity\Domain\Entity\ErasureResweep;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Infrastructure\Persistence\OrderedAuditSubjectTrailErasure;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Event\Application\EventStoreSubjectAnonymiser;
use Erpify\Shared\Event\Application\SubjectPseudonymisation;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Support\PHPUnit\FreezeSystemClockExtension;
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
 * The re-sweep's control flow over in-memory doubles: which passes run for which subject, under which
 * pseudonym, what evidence a tick leaves, and when a subject is forgotten. That the passes actually rewrite
 * a late row in Postgres is {@see \Erpify\Tests\Functional\Iam\Identity\ResweepErasedSubjectsFunctionalTest}.
 *
 * Its object coupling is the re-sweep's own: building it names every pass the erasure runs, and a double or
 * an adapter for each.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(ResweepErasedSubjects::class)]
#[CoversClass(ErasureResweep::class)]
#[CoversClass(ErasureResweepIncomplete::class)]
final class ResweepErasedSubjectsTest extends TestCase
{
    private const string SUBJECT_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a11';

    private const string OTHER_SUBJECT_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a22';

    protected function tearDown(): void
    {
        FreezeSystemClockExtension::pin();
        parent::tearDown();
    }

    public function testEveryPassRunsForTheSubjectUnderOnePseudonymAndTheTickLeavesEvidenceCarryingIt(): void
    {
        $actor = new RecordingAuditActorAnonymiser(matchCount: 1);
        $resource = new RecordingAuditResourceAnonymiser(matchCount: 2);
        $events = new RecordingEventStoreSubjectAnonymiser(matchCount: 3);
        $sessions = new InMemorySessionRepository($this->sessionFor(self::SUBJECT_ID));
        $audit = new RecordingAuditLogger();

        $rewritten = $this->useCase(
            new InMemoryErasureResweepRepository(ErasureResweep::scheduleFor(self::SUBJECT_ID)),
            $actor,
            $resource,
            $events,
            $sessions,
            $audit,
        )->resweep();

        $this->assertSame(7, $rewritten);
        $this->assertSame([self::SUBJECT_ID], $actor->anonymisedActorIds);
        $this->assertSame([[
            'type' => 'User',
            'id' => self::SUBJECT_ID,
            'pseudonym' => $actor->pseudonym,
        ]], $resource->calls);
        $this->assertSame([['subjectId' => self::SUBJECT_ID, 'pseudonym' => $actor->pseudonym]], $events->calls);
        $this->assertSame([self::SUBJECT_ID], $sessions->deleteAllCalls);

        // D4.1: an erased row whose pseudonym appears in no compliance entry is a violation, so the tick that
        // minted one says so — and never names the subject.
        $this->assertCount(1, $audit->records);
        $this->assertSame('GDPR_ERASURE_EXECUTED', $audit->records[0]['action']);
        $this->assertSame(AuditLevel::SECURITY, $audit->records[0]['level']);
        $this->assertNull($audit->records[0]['resource']);
        $this->assertSame([
            'affected_rows' => 1,
            'anonymized_actor_id' => $actor->pseudonym,
            'anonymized_resource_rows' => 2,
            'anonymized_event_rows' => 3,
            'sessions_deleted' => 1,
            'resweep' => true,
        ], $audit->records[0]['metadata']);
        $this->assertStringNotContainsString(self::SUBJECT_ID, (string) \json_encode($audit->records[0]));
    }

    public function testATickThatRewritesNothingLeavesNoEvidenceAndKeepsAnOpenWindow(): void
    {
        $resweeps = new InMemoryErasureResweepRepository(ErasureResweep::scheduleFor(self::SUBJECT_ID));
        $audit = new RecordingAuditLogger();

        $rewritten = $this->useCase(
            $resweeps,
            new RecordingAuditActorAnonymiser(matchCount: 0),
            new RecordingAuditResourceAnonymiser(matchCount: 0),
            new RecordingEventStoreSubjectAnonymiser(),
            new InMemorySessionRepository(),
            $audit,
        )->resweep();

        $this->assertSame(0, $rewritten);
        $this->assertSame([], $audit->records);
        $this->assertSame([self::SUBJECT_ID], $resweeps->scheduledSubjectIds());
        $this->assertSame([], $resweeps->deletedSubjectIds);
    }

    public function testASubjectWhoseWindowHasClosedGetsItsLastSweepAndIsThenForgotten(): void
    {
        $closed = $this->scheduledAgo(self::SUBJECT_ID, 'PT1H1S');
        $open = $this->scheduledAgo(self::OTHER_SUBJECT_ID, 'PT59M');
        $resweeps = new InMemoryErasureResweepRepository($closed, $open);
        $actor = new RecordingAuditActorAnonymiser(matchCount: 0);

        $this->useCase(
            $resweeps,
            $actor,
            new RecordingAuditResourceAnonymiser(matchCount: 0),
            new RecordingEventStoreSubjectAnonymiser(),
            new InMemorySessionRepository(),
            new RecordingAuditLogger(),
        )->resweep();

        // Both are swept — the closed one included, since forgetting is what its last tick does after sweeping.
        $this->assertSame([self::SUBJECT_ID, self::OTHER_SUBJECT_ID], $actor->anonymisedActorIds);
        $this->assertSame([self::SUBJECT_ID], $resweeps->deletedSubjectIds);
        $this->assertSame([self::OTHER_SUBJECT_ID], $resweeps->scheduledSubjectIds());
    }

    public function testTheClosingTickForgetsTheSubjectInsideTheTransactionThatSweptIt(): void
    {
        $transactions = new DepthRecordingTransactionManager();
        $resweeps = new InMemoryErasureResweepRepository($this->scheduledAgo(self::SUBJECT_ID, 'PT1H1S'));
        /** @var ArrayObject<int, int> $depthsAtDelete */
        $depthsAtDelete = new ArrayObject();
        $resweeps->onDelete = static function () use ($transactions, $depthsAtDelete): void {
            $depthsAtDelete[] = $transactions->depth;
        };

        $this->useCase(
            $resweeps,
            new RecordingAuditActorAnonymiser(matchCount: 0),
            new RecordingAuditResourceAnonymiser(matchCount: 0),
            new RecordingEventStoreSubjectAnonymiser(),
            new InMemorySessionRepository(),
            new RecordingAuditLogger(),
            $transactions,
        )->resweep();

        // Outside it, a failure between the sweep and the delete would forget a subject whose last pass rolled
        // back.
        $this->assertSame([1], $depthsAtDelete->getArrayCopy());
    }

    public function testASubjectWhosePassFailsNeitherHoldsBackTheOthersNorIsForgotten(): void
    {
        $failing = $this->scheduledAgo(self::SUBJECT_ID, 'PT2H');
        $healthy = $this->scheduledAgo(self::OTHER_SUBJECT_ID, 'PT1H1S');
        $resweeps = new InMemoryErasureResweepRepository($failing, $healthy);
        $events = new class implements EventStoreSubjectAnonymiser {
            /** @var list<string> */
            public array $subjects = [];

            public string $failsFor = '';

            #[Override]
            public function anonymise(SubjectPseudonymisation $pseudonymisation): int
            {
                if ($pseudonymisation->subjectId === $this->failsFor) {
                    throw new RuntimeException(\sprintf('Key (id)=(%s) is poisoned', $this->failsFor));
                }

                $this->subjects[] = $pseudonymisation->subjectId;

                return 0;
            }
        };

        $events->failsFor = self::SUBJECT_ID;

        try {
            $this->useCase(
                $resweeps,
                new RecordingAuditActorAnonymiser(matchCount: 0),
                new RecordingAuditResourceAnonymiser(matchCount: 0),
                $events,
                new InMemorySessionRepository(),
                new RecordingAuditLogger(),
            )->resweep();
            $this->fail('A failed subject must leave the tick visible.');
        } catch (ErasureResweepIncomplete $erasureResweepIncomplete) {
            // The failure is raised after the rest were swept, and it carries the cause's class, never its text:
            // a driver message quoting a value would put the subject's id in the log the re-sweep keeps it out of.
            $this->assertStringContainsString('1 of 2', $erasureResweepIncomplete->getMessage());
            $this->assertStringContainsString(RuntimeException::class, $erasureResweepIncomplete->getMessage());
            $this->assertStringNotContainsString(self::SUBJECT_ID, $erasureResweepIncomplete->getMessage());
            $this->assertNull($erasureResweepIncomplete->getPrevious());
        }

        // The older subject failed first; the newer one was swept and, its window closed, forgotten anyway.
        $this->assertSame([self::OTHER_SUBJECT_ID], $events->subjects);
        $this->assertSame([self::OTHER_SUBJECT_ID], $resweeps->deletedSubjectIds);
        $this->assertSame([self::SUBJECT_ID], $resweeps->scheduledSubjectIds());
    }

    public function testARowScheduledExactlyOneWindowAgoIsStillSwept(): void
    {
        $resweeps = new InMemoryErasureResweepRepository($this->scheduledAgo(self::SUBJECT_ID, 'PT1H'));

        $this->useCase(
            $resweeps,
            new RecordingAuditActorAnonymiser(matchCount: 0),
            new RecordingAuditResourceAnonymiser(matchCount: 0),
            new RecordingEventStoreSubjectAnonymiser(),
            new InMemorySessionRepository(),
            new RecordingAuditLogger(),
        )->resweep();

        $this->assertSame([], $resweeps->deletedSubjectIds);
    }

    public function testNothingScheduledMeansNothingRuns(): void
    {
        $actor = new RecordingAuditActorAnonymiser(matchCount: 0);

        $rewritten = $this->useCase(
            new InMemoryErasureResweepRepository(),
            $actor,
            new RecordingAuditResourceAnonymiser(matchCount: 0),
            new RecordingEventStoreSubjectAnonymiser(),
            new InMemorySessionRepository(),
            new RecordingAuditLogger(),
        )->resweep();

        $this->assertSame(0, $rewritten);
        $this->assertSame([], $actor->anonymisedActorIds);
    }

    private function useCase(
        InMemoryErasureResweepRepository $resweeps,
        RecordingAuditActorAnonymiser $actor,
        RecordingAuditResourceAnonymiser $resource,
        EventStoreSubjectAnonymiser $events,
        InMemorySessionRepository $sessions,
        RecordingAuditLogger $audit,
        ?TransactionManager $transactions = null,
    ): ResweepErasedSubjects {
        return new ResweepErasedSubjects(
            $resweeps,
            new OrderedAuditSubjectTrailErasure(new RecordingAuditSubjectRowLock(), $actor, $resource),
            $events,
            new PurgeUserSessions($sessions),
            $audit,
            $transactions ?? new InlineTransactionManager(),
            new FixedClock(SystemClock::now()),
        );
    }

    /**
     * Built under a clock set back by `$age`, since `Timestamped` has no setter; the pin is restored before the
     * subject runs so the injected clock agrees with the ambient one.
     */
    private function scheduledAgo(string $subjectId, string $age): ErasureResweep
    {
        SystemClock::set(new FixedClock(SystemClock::now()->sub(new DateInterval($age))));
        $resweep = ErasureResweep::scheduleFor($subjectId);
        FreezeSystemClockExtension::pin();

        return $resweep;
    }

    private function sessionFor(string $userId): Session
    {
        $session = Session::start(
            SessionId::generate()->toString(),
            $userId,
            '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50',
            'Test client',
            '127.0.0.1',
            SystemClock::now()->add(new DateInterval('P1D')),
        );
        $session->pullDomainEvents();

        return $session;
    }
}
