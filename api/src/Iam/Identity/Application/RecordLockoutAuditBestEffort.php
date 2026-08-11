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
 * `Throwable` and not `DbalException`: {@see \Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogWriter}
 * encodes metadata with `JSON_THROW_ON_ERROR`, so a `JsonException` can leave the writer, and it is not a DBAL
 * type. Escaping here it would surface as a 500 on exactly the tenth failed attempt of a resolved identity
 * while an unknown address still answers 401 — a status oracle against the pre-identity indistinguishability
 * invariant (ADR docs/adr/identity-invitation-lifecycle.md D10).
 *
 * The resource type is reached through {@see FulfilIdentityErasure}'s constant rather than spelled here: the
 * type denotes a natural person, so the file holding its literal is the one the audit-resource registry names
 * as obliged to erase it, and a second spelling would nominate this class as an erasure owner it is not.
 */
final readonly class RecordLockoutAuditBestEffort
{
    private const string LOCKED_ACTION = 'USER_LOCKED';

    public function __construct(
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The actor is `anonymous` by construction — no token exists at a failed login — so the subject rides in
     * the resource columns, which the erasure chain rewrites alongside `actor_id`. No metadata: the expiry is
     * already in the event payload, and request-derived strings here would reach `json_encode` on a path the
     * caller cannot afford to have throw.
     */
    public function record(string $userId): void
    {
        try {
            $this->auditLogger->log(
                self::LOCKED_ACTION,
                AuditLevel::SECURITY,
                AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $userId),
            );
        } catch (Throwable $throwable) {
            $this->logger->warning(
                'Lockout committed; security audit projection skipped (write failed).',
                ['exception' => $throwable],
            );
        }
    }
}
