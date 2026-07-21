<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Exception\LastActiveAdministratorProtected;
use Erpify\Iam\Identity\Domain\Exception\SelfErasureForbidden;
use Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Shared\Audit\Application\ActorContextFactory;
use Erpify\Shared\Audit\Application\AuditActorAnonymiser;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Fulfils a GDPR "right to erasure" request against one subject as a single atomic operation — the identity
 * and its audit trail are de-identified as one unit. It chains, in one transaction: the "keep ≥1 active
 * ADMIN" guard, the identity erasure ({@see EraseIdentitySubject} — its own transaction nests and joins),
 * the audit-trail anonymisation ({@see AuditActorAnonymiser}, whose DBAL runs on the same Connection the
 * EntityManager wraps, so it commits or rolls back with the rest), the hard-delete of the subject's sessions
 * ({@see PurgeUserSessions}) and the combined compliance self-audit. A failure in any link rolls everything
 * back — no half-erased identity, no half-anonymised trail, no orphaned session PII — and re-running is safe.
 *
 * Two guards run before the transaction opens: the id shape ({@see Uuid::ensure} → 400 on a malformed id) and
 * the self-erasure refusal — an actor may not erase their own identity, because a subject cannot both cease to
 * exist and survive as the actor attributing its own erasure evidence. The self-erasure guard reads the actor
 * from the trusted {@see ActorContextFactory}, never a request body, so an off-request `system` actor (the CLI)
 * carries no id and can never trip it.
 *
 * Absent-id handling is deliberately delegated: this service does not throw `UserNotFound`. It returns a
 * {@see FulfilIdentityErasureResult} whose `identityErased` is `false` when nothing was live, so the HTTP
 * controller can map that to a 404 while the CLI keeps its idempotent "nothing to erase" outcome.
 */
final readonly class FulfilIdentityErasure
{
    private const string ERASURE_ACTION = 'GDPR_ERASURE_EXECUTED';

    public function __construct(
        private EraseIdentitySubject $eraseIdentitySubject,
        private AuditActorAnonymiser $auditActorAnonymiser,
        private ActiveAdministratorDirectory $administrators,
        private PurgeUserSessions $purgeUserSessions,
        private AuditLogger $auditLogger,
        private ActorContextFactory $actorContext,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @throws SelfErasureForbidden             when the acting actor targets their own identity (409)
     * @throws LastActiveAdministratorProtected when the subject is the last active administrator (409)
     */
    public function execute(string $subjectId): FulfilIdentityErasureResult
    {
        Uuid::ensure($subjectId);
        $this->refuseSelfErasure($subjectId);

        return $this->transactionManager->transactional(
            function () use ($subjectId): FulfilIdentityErasureResult {
                if (!$this->administrators->keepsAnActiveAdminWithout($subjectId)) {
                    throw LastActiveAdministratorProtected::forErasure($subjectId);
                }

                $identity = $this->eraseIdentitySubject->execute($subjectId);
                $anonymisation = $this->auditActorAnonymiser->anonymise($subjectId);
                $sessionsDeleted = $this->purgeUserSessions->purge($subjectId);

                $result = new FulfilIdentityErasureResult(
                    $identity->identityErased,
                    $identity->resetTokensDeleted,
                    $anonymisation->affectedRows,
                    $sessionsDeleted,
                );

                if ($result->erasedAnything()) {
                    // Counts only. Recording the subject id beside its anonymisation pseudonym would be a
                    // reversible crosswalk — this row shares the request's correlation id with GDPR_SUBJECT_ERASED
                    // (which carries the subject id), so a pseudonym here re-links the anonymised trail to the
                    // person, defeating the anonymisation. Which subject was erased lives in GDPR_SUBJECT_ERASED.
                    $this->auditLogger->log(self::ERASURE_ACTION, AuditLevel::SECURITY, null, [
                        'affected_rows' => $anonymisation->affectedRows,
                        'reset_tokens_deleted' => $identity->resetTokensDeleted,
                        'sessions_deleted' => $sessionsDeleted,
                    ]);
                }

                return $result;
            },
        );
    }

    private function refuseSelfErasure(string $subjectId): void
    {
        $actorId = $this->actorContext->current()->actorId;

        // RFC 4122 hex is case-insensitive: the route id and the sealed actor id can spell one UUID in different
        // case, so compare case-insensitively (as the ≥1-admin directory does) — a `===` here would be bypassable.
        if (null !== $actorId && 0 === \strcasecmp($actorId, $subjectId)) {
            throw SelfErasureForbidden::forActor($subjectId);
        }
    }
}
