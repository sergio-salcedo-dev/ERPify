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

/**
 * Re-runs the erasure's anonymising passes over every subject erased within the last window, then forgets the
 * subjects whose window has closed.
 *
 * {@see FulfilIdentityErasure} rewrites every row naming the subject that exists when it runs. A request
 * already in flight when it starts can still commit one afterwards — an access-log or request-boundary audit
 * row, a session row, a session event — and every review that enumerated those writers found the list
 * incomplete. This closes the class rather than the list: whatever names the subject on either audit axis,
 * in `event_store` or in `iam_session` is rewritten on the next tick, whoever wrote it, so such a row
 * survives at most one period of the schedule and the subject's id at most one window beyond the erasure.
 * `docs/adr/audit-activity-log.md` D4.2 is the decision and records what it does not cover — a writer
 * committing after the window ({@see ErasureResweep::WINDOW}) has closed.
 *
 * **The passes are the erasure's own, unchanged.** Each matches by value and a second run over clean tables
 * rewrites nothing, so re-running them adds no mutation to the closed set either log admits. The event-store
 * pass matches by value across every kind of aggregate, which is why the erasure runs it only for a subject
 * whose identity was live; a row here exists only for such a subject, so that precondition holds by
 * construction.
 *
 * **Every tick mints a fresh pseudonym**, never the erasure's: carrying that one forward would need it stored
 * beside the real id, the mapping table D4 vetoes. The cost is that a late row resolves to a different
 * anonymous identity than the rows the erasure rewrote. Within one tick both audit axes and the business log
 * share it, for the same reason the erasure shares one.
 *
 * A tick that rewrote something writes `GDPR_ERASURE_EXECUTED` carrying the new pseudonym, because D4.1
 * treats an erased row whose pseudonym appears in no compliance entry as a violation. A tick that rewrote
 * nothing writes nothing, so an idle window costs no audit rows.
 *
 * One transaction per subject, so the subjects swept before a failure keep their commit. The failure itself
 * ends the tick rather than being absorbed per subject, for the reason {@see NotifyLockedIdentities} measured:
 * a failed commit closes the EntityManager, so a per-subject `catch` would report survivable warnings over a
 * sweep that is already dead. Leaving lets Messenger log it at `critical`; every pass is idempotent, so the
 * next tick repeats the whole set five minutes later, and a subject that keeps failing keeps its row — which
 * the detective source reports once it outlives its window. The lock order is the erasure's — audit rows,
 * then the business log, then sessions — with `identity_user` absent because the row is gone.
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
     * @return int rows rewritten or deleted across every subject, in this tick
     */
    public function resweep(): int
    {
        $now = $this->clock->now();
        $rewritten = 0;

        foreach ($this->erasureResweeps->findAll() as $erasureResweep) {
            $windowClosed = $erasureResweep->windowClosedAt($now);
            $rewritten += $this->transactionManager->transactional(
                fn (): int => $this->resweepSubject($erasureResweep, $windowClosed),
            );
        }

        return $rewritten;
    }

    /**
     * The last tick of a subject's window sweeps before it forgets, in the same transaction, so a closed
     * window never loses the sweep it was owed to a failure between the two.
     */
    private function resweepSubject(ErasureResweep $resweep, bool $windowClosed): int
    {
        $subjectId = $resweep->subjectId();
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
            $this->erasureResweeps->delete($resweep);
        }

        return $rewritten;
    }
}
