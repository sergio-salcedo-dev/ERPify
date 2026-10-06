<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\ErasureResweep;
use Erpify\Iam\Identity\Domain\Repository\ErasureResweepRepository;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Application\AuditSubjectTrailErasure;
use Erpify\Shared\Audit\Domain\AuditErasureEvidence;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Application\EventStoreSubjectAnonymiser;
use Erpify\Shared\Event\Application\SubjectPseudonym;
use Erpify\Shared\Event\Application\SubjectPseudonymisation;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Throwable;

/**
 * Re-runs the erasure's anonymising passes over every subject erased within the last window, then forgets the
 * subjects whose window has closed.
 *
 * {@see FulfilIdentityErasure} rewrites every row naming the subject that exists when it runs. A request
 * already in flight when it starts can still commit one afterwards — an access-log or request-boundary audit
 * row, a session row, a session event — and every review that enumerated those writers found the list
 * incomplete. This closes the class rather than the list: whatever names the subject on either audit axis,
 * in `event_store` or in `iam_session` is rewritten on the next tick, whoever wrote it, so such a row
 * survives at most one period of the schedule and the subject's id at most one window
 * ({@see ErasureResweep::WINDOW}) beyond the erasure. `docs/adr/audit-activity-log.md` D4.2 is the decision
 * and records what it leaves uncovered.
 *
 * **The passes are the erasure's own, unchanged.** Each matches by value and a second run over clean tables
 * rewrites nothing, so re-running them adds no mutation to the closed set either log admits. The event-store
 * pass matches by value across every kind of aggregate, which is why the erasure runs it only for a subject
 * whose identity was live; a row here exists only for such a subject, so that precondition holds by
 * construction.
 *
 * **Every tick mints a fresh pseudonym**, never the erasure's: carrying that one forward would need it stored
 * beside the real id, the mapping table D4 vetoes. Within one tick both audit axes and the business log share
 * it, for the same reason the erasure shares one.
 *
 * A tick that rewrote something writes `GDPR_ERASURE_EXECUTED` carrying the new pseudonym, because D4.1
 * treats an erased row whose pseudonym appears in no compliance entry as a violation. A tick that rewrote
 * nothing writes nothing, so an idle window costs no audit rows.
 *
 * **One transaction per subject, and one subject's failure never holds back another.** Subjects are swept
 * oldest first, so a failure that ended the tick would starve every newer subject for as long as it repeated,
 * keeping their real ids past the window too. Each failure is therefore caught where it happens and the tick
 * goes on; {@see \Erpify\Shared\Persistence\Infrastructure\DoctrineTransactionManager} reopens an
 * EntityManager a failed commit closed, and the row is forgotten by subject id rather than through the entity
 * read before it, so nothing after the failure depends on the manager that failed. The failures are raised
 * together once the others are done ({@see ErasureResweepIncomplete}), which is what keeps the tick visible at
 * `critical`; a failing subject keeps its row, is retried on the next tick, and is reported by the detective
 * source once it outlives its window. The lock order is the erasure's — audit rows, then the business log,
 * then sessions — with `identity_user` absent because the row is gone.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final readonly class ResweepErasedSubjects
{
    private const string ERASURE_ACTION = AuditErasureEvidence::ACTOR_TRAIL_ERASED;

    public function __construct(
        private ErasureResweepRepository $erasureResweeps,
        private AuditSubjectTrailErasure $auditTrail,
        private EventStoreSubjectAnonymiser $eventStoreSubjectAnonymiser,
        private PurgeUserSessions $purgeUserSessions,
        private AuditLogger $auditLogger,
        private TransactionManager $transactionManager,
        private Clock $clock,
    ) {
    }

    /**
     * @throws ErasureResweepIncomplete when one or more subjects could not be swept, after every other was
     *
     * @return int rows rewritten or deleted across every subject swept, in this tick
     */
    public function resweep(): int
    {
        $now = $this->clock->now();
        $scheduled = $this->erasureResweeps->findAll();
        $rewritten = 0;
        $failures = [];

        foreach ($scheduled as $erasureResweep) {
            $subjectId = $erasureResweep->subjectId();
            $windowClosed = $erasureResweep->windowClosedAt($now);

            try {
                $rewritten += $this->transactionManager->transactional(
                    fn (): int => $this->resweepSubject($subjectId, $windowClosed),
                );
            } catch (Throwable $throwable) {
                $failures[] = $throwable::class;
            }
        }

        if ([] !== $failures) {
            throw ErasureResweepIncomplete::after($failures, \count($scheduled));
        }

        return $rewritten;
    }

    /**
     * The last tick of a subject's window sweeps before it forgets, in the same transaction, so a closed
     * window never loses the sweep it was owed to a failure between the two.
     */
    private function resweepSubject(string $subjectId, bool $windowClosed): int
    {
        $subject = AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $subjectId);

        $anonymisation = $this->auditTrail->beginForSubject($subject);
        $resourceRows = $this->auditTrail->completeForSubject($subject, $anonymisation);
        $eventRows = $this->eventStoreSubjectAnonymiser->anonymise(SubjectPseudonymisation::of(
            subjectId: $subjectId,
            pseudonym: SubjectPseudonym::fromString($anonymisation->pseudonym),
        ));
        $sessionsDeleted = $this->purgeUserSessions->purge($subjectId);

        $rewritten = $anonymisation->affectedRows + $resourceRows + $eventRows + $sessionsDeleted;

        if ($rewritten > 0) {
            $this->auditLogger->log(self::ERASURE_ACTION, AuditLevel::SECURITY, null, [
                'affected_rows' => $anonymisation->affectedRows,
                'anonymized_actor_id' => $anonymisation->pseudonym,
                'anonymized_resource_rows' => $resourceRows,
                'anonymized_event_rows' => $eventRows,
                'sessions_deleted' => $sessionsDeleted,
                'resweep' => true,
            ]);
        }

        if ($windowClosed) {
            $this->erasureResweeps->deleteForSubject($subjectId);
        }

        return $rewritten;
    }
}
