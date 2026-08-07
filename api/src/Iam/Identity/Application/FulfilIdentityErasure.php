<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Exception\AdministratorErasureRequiresDemotion;
use Erpify\Iam\Identity\Domain\Exception\SelfErasureForbidden;
use Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory;
use Erpify\Iam\Invitation\Application\PurgeUserInvitations;
use Erpify\Iam\Session\Application\PurgeUserSessions;
use Erpify\Organization\Membership\Application\PurgeUserMembership;
use Erpify\Shared\Audit\Application\ActorContextFactory;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Application\AuditSubjectTrailErasure;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Event\Application\EventStoreSubjectAnonymiser;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Fulfils a GDPR "right to erasure" request against one subject as a single atomic operation — the identity
 * and its audit trail are de-identified as one unit. It chains, in one transaction: the administrator refusal,
 * the identity erasure ({@see EraseIdentitySubject} — its own transaction nests and joins), the audit-trail
 * anonymisation across **both** axes ({@see AuditSubjectTrailErasure} — rows the subject authored and rows
 * that name them, locked as one set before either is rewritten), whose DBAL runs on the same Connection the
 * EntityManager wraps, so they commit or roll back with the rest, the anonymisation of the reproducible
 * business log ({@see EventStoreSubjectAnonymiser}, run only for a subject whose identity was live), the
 * hard-delete of the subject's sessions ({@see PurgeUserSessions}), of the membership that admitted them
 * ({@see PurgeUserMembership}) and of every invitation addressed to them ({@see PurgeUserInvitations}), and
 * the combined compliance self-audit.
 *
 * The last two are here for the same reason as the sessions: no column in the schema references
 * `identity_user`, so deleting that row cascades nowhere and every reference owes its removal to a use case
 * rather than to a constraint. Until now these two had none, so the subject's real id simply stayed
 * behind. Each is consumed as its owning context's published use case — the person's context
 * orchestrates the erasure, but never reaches into how another context stores what it owns.
 *
 * Both axes are erased **here**, by the context that owns the person, rather than inside one shared
 * anonymiser: `docs/adr/audit-activity-log.md` D4 assigns erasure of a person-denoting resource to the
 * owning context, and `docs/adr/regulatory-audit-trail.md` D15 keeps the actor and subject erasures from
 * being merged into one operation.
 *
 * Erasing only the actor axis would leave the fresh pseudonym beside the real id in any row where the subject
 * is both — a user changing their own roles — which is a reversible crosswalk, not a cosmetic gap. A failure
 * in any link rolls everything back — no half-erased identity, no half-anonymised trail, no orphaned session
 * PII — and re-running is safe.
 *
 * Both compliance entries are written **here**, including the identity module's own `GDPR_SUBJECT_ERASED`,
 * and that placement is the point rather than a convenience. That entry names the subject as its resource, so
 * writing it persists the person's real id a second time; the only thing that removes that copy is the
 * resource anonymiser three lines below, which needs the pseudonym the actor pass mints. Written from inside
 * {@see EraseIdentitySubject} it would be an identifier that use case cannot erase, left to whichever caller
 * remembers — the distributed obligation `api/.person-reference-policy` exists against. Here the write and
 * the erasure share one `AuditResource` value, so they cannot name different resources.
 *
 * Two guards run before the transaction opens: the id shape ({@see Uuid::ensure} → 400 on a malformed id) and
 * the self-erasure refusal — an actor may not erase their own identity, because a subject cannot both cease to
 * exist and survive as the actor attributing its own erasure evidence. The self-erasure guard reads the actor
 * from the trusted {@see ActorContextFactory}, never a request body, so an off-request `system` actor (the CLI)
 * carries no id and can never trip it.
 *
 * Inside the transaction, one further precondition: an identity carrying `ADMIN` is refused outright
 * ({@see AdministratorErasureRequiresDemotion}). Erasure is irreversible and pseudonymises the subject's whole
 * attribution, so requiring the demotion first puts an audited role change in the trail ahead of it — the
 * declared intent that erasing a peer administrator otherwise lacks. This subsumes the "keep ≥1 active ADMIN"
 * check on this path, since the last active administrator necessarily carries the role: erasure no longer
 * reasons about the administrator set, and that set invariant is enforced by the role and status transitions
 * that can actually shrink it.
 *
 * Absent-id handling is deliberately delegated: this service does not throw `UserNotFound`. It returns a
 * {@see FulfilIdentityErasureResult} whose `identityErased` is `false` when nothing was live, so the HTTP
 * controller can map that to a 404 while the CLI keeps its idempotent "nothing to erase" outcome.
 *
 * Its object coupling sits above the default threshold and stays there. Every collaborator is one link of
 * the single atomic act this orchestrates — refuse an administrator, erase the identity, anonymise both
 * trail axes, anonymise the business log, purge sessions, purge the membership, purge the invitations, seal
 * the actor, own the transaction — and the two types the resource axis speaks in are that seam's vocabulary,
 * not extra
 * responsibilities. Splitting the chain would hide, from the one place a reader looks, that every place the
 * subject's id is persisted is erased together; that atomicity is the property the whole design rests on,
 * and the list grows by one every time a new context comes to hold a person's id.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final readonly class FulfilIdentityErasure
{
    private const string ERASURE_ACTION = 'GDPR_ERASURE_EXECUTED';

    /**
     * The identity module's own compliance entry. It is written here rather than inside
     * {@see EraseIdentitySubject} because it names the subject as its audit resource, and the statement
     * that clears that identifier is three lines below — a use case must not persist an id it has no way
     * of erasing.
     */
    private const string SUBJECT_ERASURE_ACTION = 'GDPR_SUBJECT_ERASED';

    /**
     * The audit `resource_type` under which this context's subject appears. It lives here, not in
     * `Shared/Audit`, because "this type denotes a natural person" is knowledge owned by the context that
     * owns people — the assignment `docs/adr/audit-activity-log.md` D4 makes. The shared anonymiser is told
     * the type; it never learns what the type means. Public because the reconciler that detects a missed
     * erasure ({@see ReconcileErasedSubjectReferences}) must ask about the same type this writes.
     */
    public const string SUBJECT_RESOURCE_TYPE = 'User';

    public function __construct(
        private EraseIdentitySubject $eraseIdentitySubject,
        private AuditSubjectTrailErasure $auditTrail,
        private EventStoreSubjectAnonymiser $eventStoreSubjectAnonymiser,
        private ActiveAdministratorDirectory $administrators,
        private PurgeUserSessions $purgeUserSessions,
        private PurgeUserMembership $purgeUserMembership,
        private PurgeUserInvitations $purgeUserInvitations,
        private AuditLogger $auditLogger,
        private ActorContextFactory $actorContext,
        private TransactionManager $transactionManager,
    ) {
    }

    /**
     * @throws SelfErasureForbidden                 when the acting actor targets their own identity (409)
     * @throws AdministratorErasureRequiresDemotion when the subject still carries the administrator role (409)
     */
    public function execute(string $subjectId): FulfilIdentityErasureResult
    {
        Uuid::ensure($subjectId);
        $this->refuseSelfErasure($subjectId);

        return $this->transactionManager->transactional(
            function () use ($subjectId): FulfilIdentityErasureResult {
                if ($this->administrators->holdsAdministratorRole($subjectId)) {
                    throw AdministratorErasureRequiresDemotion::forUser($subjectId);
                }

                // One value, three uses: the axis lock, the compliance entry's resource, and the pass that
                // clears it. Whoever writes the subject's real id into the trail is the one that clears it,
                // and they cannot disagree about which resource that is because there is only one to
                // disagree about.
                $subject = AuditResource::of(self::SUBJECT_RESOURCE_TYPE, $subjectId);

                $identity = $this->eraseIdentitySubject->execute($subjectId);
                // Locks both axes as one set before rewriting either — see the port for why ordering the two
                // statements individually cannot prevent the deadlock they can reach.
                $anonymisation = $this->auditTrail->beginForSubject($subject);

                // The write has to come first — the anonymiser's UPDATE matches rows that already exist —
                // and it is a `security` entry, so it is inserted synchronously on this connection rather
                // than deferred past the statement that clears it.
                if ($identity->erasedAnything()) {
                    $this->auditLogger->log(
                        self::SUBJECT_ERASURE_ACTION,
                        AuditLevel::SECURITY,
                        $subject,
                        ['reset_tokens_deleted' => $identity->resetTokensDeleted],
                    );
                }

                // Same pseudonym for both axes: one person must not split into two anonymous identities.
                // It re-links nothing, because the original id is gone from both columns.
                $anonymisedResourceRows = $this->auditTrail->completeForSubject($subject, $anonymisation);
                $anonymisedEventRows = $this->anonymiseBusinessLog(
                    $identity,
                    $subjectId,
                    $anonymisation->pseudonym,
                );
                [$sessionsDeleted, $membershipsDeleted, $invitationsDeleted] = $this->purgeReferences($subjectId);

                $result = new FulfilIdentityErasureResult(
                    $identity->identityErased,
                    $identity->resetTokensDeleted,
                    $anonymisation->affectedRows,
                    $anonymisedResourceRows,
                    $anonymisedEventRows,
                    $sessionsDeleted,
                    $membershipsDeleted,
                    $invitationsDeleted,
                );

                $this->recordCombinedErasure($result, $anonymisation->pseudonym);

                return $result;
            },
        );
    }

    /**
     * The business log is the third place the subject's identifier is persisted, and the last one the chain
     * came to reach. Same pseudonym as both audit axes: one person, one anonymous identity, across every
     * table that held them.
     *
     * **It is the only link conditioned on the identity having been live, and the asymmetry is the point.**
     * Every other statement in the chain interrogates a column that holds nothing but person ids, so an
     * argument denoting a bank matches none of them and costs nothing. This one matches by VALUE across every
     * kind of aggregate — which is exactly what lets it reach an event carrying the id under a key nobody
     * enumerated, and equally what would let a mistyped argument rewrite an unrelated aggregate's whole
     * stream. That mistake is unrecoverable, because `docs/adr/audit-activity-log.md` D4 vetoes the mapping
     * table that could undo it, and unobservable, because the absent-id branch is decided by the caller after
     * this transaction has already committed. So the pass runs on the database's own evidence that the
     * argument denoted a person, never on the argument alone.
     *
     * The condition belongs here rather than in the statement: classifying identifiers as people is knowledge
     * owned by the context that owns people — the same assignment D4 makes for the trail's resource axis — and
     * pushing it into the shared module would be an inventory of aggregate types, which is the enumeration
     * `docs/adr/event-store-and-projections.md` D12 rejects and which the payload axis defeats anyway.
     */
    private function anonymiseBusinessLog(
        IdentityErasureResult $identity,
        string $subjectId,
        string $pseudonym,
    ): int {
        if (!$identity->identityErased) {
            return 0;
        }

        return $this->eventStoreSubjectAnonymiser->anonymise($subjectId, $pseudonym);
    }

    /**
     * The three references to the subject that no constraint removes. None has a physical foreign key —
     * sessions live in this context, the membership and the invitations cross a bounded context, so integrity
     * is by id — and nothing cascades when the identity row goes. Without these the subject's real id survives
     * their own erasure in the session store, in the membership that admitted them, and in every invitation
     * addressed to them.
     *
     * @return array{int, int, int} sessions, memberships and invitations deleted, in that order
     */
    private function purgeReferences(string $subjectId): array
    {
        return [
            $this->purgeUserSessions->purge($subjectId),
            $this->purgeUserMembership->purge($subjectId),
            $this->purgeUserInvitations->purge($subjectId),
        ];
    }

    /**
     * Counts, plus the one identifier this entry is contractually required to carry: the pseudonym the actor
     * pass minted. `docs/adr/audit-activity-log.md` D4.1 makes it part of this event's contract and builds a
     * detectable integrity check on it — a row marked `actor_erased` whose pseudonym appears in no compliance
     * entry is a violation — so omitting it here left that check unsatisfiable for every erasure this path
     * fulfils, while the CLI sibling honoured it. It is not a second copy to erase: it is a fresh UUID with no
     * original behind it, which is exactly what D4 requires a pseudonym to be. The subject's real id is what
     * stays out, on both axes.
     */
    private function recordCombinedErasure(FulfilIdentityErasureResult $result, string $pseudonym): void
    {
        if (!$result->erasedAnything()) {
            return;
        }

        $this->auditLogger->log(self::ERASURE_ACTION, AuditLevel::SECURITY, null, [
            'affected_rows' => $result->anonymizedAuditRows,
            'anonymized_actor_id' => $pseudonym,
            'anonymized_resource_rows' => $result->anonymizedResourceRows,
            'anonymized_event_rows' => $result->anonymizedEventRows,
            'reset_tokens_deleted' => $result->resetTokensDeleted,
            'sessions_deleted' => $result->sessionsDeleted,
            'memberships_deleted' => $result->membershipsDeleted,
            'invitations_deleted' => $result->invitationsDeleted,
        ]);
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
