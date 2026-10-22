<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Projects a tripped lockout onto the operator's `security` surface. It runs POST-COMMIT and swallows every
 * failure, and both halves are the decision rather than caution.
 *
 * The durable record of the lock is the `UserLocked` row `DbalEventStore` appends inside the same transaction
 * as the `identity_user` write — so this row is a *projection* of a fact that already survives, not the fact
 * itself. Writing it inside that transaction would let a failed `audit_log` INSERT roll the lockout back, and
 * the rollback would be silent twice over: PostgreSQL answers `COMMIT` on an aborted transaction with a
 * `ROLLBACK` tag and no error, and the login-failure path that drives this swallows `DbalException` without a
 * logger. A brute-force defence that its own observability can switch off is the wrong trade in a control
 * whose entire purpose is observability.
 *
 * **Post-commit does not mean unserialised: the row is written under the subject's `identity_user` row lock**,
 * through {@see IdentityRowLock}, which says why that keeps a row naming the subject from committing after an
 * erasure's pass over the trail. The transaction is the lock's own, never the registrar's, and it is opened on
 * the connection rather than through the entity manager: a failed INSERT, a lock wait that times out or a failed
 * COMMIT rolls back this projection alone, flushes nothing the request still holds and leaves the manager open.
 *
 * `Throwable` and not `DbalException`: {@see \Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogWriter}
 * encodes metadata with `JSON_THROW_ON_ERROR`, so a `JsonException` can leave the writer, and it is not a DBAL
 * type. Escaping here it would surface as a 500 on exactly the tenth failed attempt of a resolved identity
 * while an unknown address still answers 401 — a status oracle against the pre-identity indistinguishability
 * invariant (ADR docs/adr/identity-invitation-lifecycle.md D10).
 *
 * The resource type is reached through {@see FulfilIdentityErasure}'s constant rather than spelled here: the
 * type denotes a natural person, so the file holding its literal is the one the audit-resource registry names
 * as obliged to erase it, and a second spelling would nominate this class as an erasure owner it is not.
 *
 * **The report goes to the `observability` channel — bound in `services.yaml`, since deptrac refuses this
 * layer a dependency on the container's attributes.** On the default channel it would not be read: prod
 * routes that channel through `fingers_crossed`, whose activation is decided by the RECORD'S LEVEL and not
 * by the response — an `error` there fires the handler and flushes the whole buffer, and anything below it
 * is discarded with the rest when the request ends without one. `observability` is the always-on stream this
 * repository built for exactly this shape, and `monolog.yaml` excludes it from the buffered handler by name.
 *
 * The level is `error` for what it asserts rather than for whether it survives, which on this channel the
 * level does not decide: a `security` projection owed and not made is an integrity defect in the trail, not
 * a degraded nicety — the reading {@see \Erpify\Shared\Audit\Infrastructure\SymfonyAuditLogger} applies
 * to a lost `activity` row. The pairing matters more than either half: raising the level WITHOUT the channel
 * is strictly worse than leaving it alone, because it turns a discarded line into a buffer flush of every
 * record the request accumulated. Whose records those are is a question about the path, and this one is
 * anonymous — no session token exists at a failed login, so the address `ContextListener` volunteers on an
 * authenticated request is not among them. That is a reason the exposure here is smaller than on its
 * sibling's paths, never a reason the flush is acceptable.
 *
 * {@see \Erpify\Tests\Functional\Iam\Identity\LockoutAuditWriteFailureArrivalTest} is what holds both
 * halves: the binding is a position in a YAML file that a reorder can silently revert, and no static check
 * can see that.
 *
 * The report itself is wrapped, via {@see ReportsAuditFailureSafely}: a catch whose entire purpose is that
 * nothing escapes may not throw, and the report call is real I/O.
 */
final readonly class RecordLockoutAuditBestEffort
{
    use ReportsAbsentSubject;
    use ReportsAuditFailureSafely;

    private const string LOCKED_ACTION = 'USER_LOCKED';

    public function __construct(
        private IdentityRowLock $identityRows,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The actor is `anonymous` by construction — no token exists at a failed login — so the subject rides in
     * the resource columns, which the erasure chain rewrites alongside `actor_id`. No metadata: the expiry is
     * already in the event payload, and request-derived strings here would reach `json_encode` on a path the
     * caller cannot afford to have throw.
     *
     * An identity the locked read no longer finds has been erased since the lockout committed, and it is owed
     * no row: writing one would name a subject whose erasure has already run its pass over the trail. That
     * outcome is reported at `info`, without the id, so the missing row is explained rather than silent.
     */
    public function record(string $userId): void
    {
        $phase = self::PHASE_LOCK;

        try {
            $held = $this->identityRows->whileHeld($userId, function () use ($userId, &$phase): void {
                $phase = self::PHASE_WRITE;
                $this->auditLogger->log(
                    self::LOCKED_ACTION,
                    AuditLevel::SECURITY,
                    AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $userId),
                );
                $phase = self::PHASE_COMMIT;
            });

            if (!$held) {
                $this->reportSafely(fn () => $this->logger->info(
                    'Lockout committed; no security audit row owed, the identity is gone.',
                    ['phase' => self::PHASE_SUBJECT_ABSENT],
                ));
            }
        } catch (Throwable $throwable) {
            $this->reportSafely(fn () => $this->logger->error(
                'Lockout committed; security audit projection skipped.',
                ['phase' => $phase, 'exception' => $throwable],
            ));
        }
    }
}
