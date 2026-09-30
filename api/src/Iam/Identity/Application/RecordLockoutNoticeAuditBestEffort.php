<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use DateTimeImmutable;
use DateTimeInterface;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Projects a DELIVERED lockout notice onto the operator's `security` surface — distinct from
 * {@see RecordLockoutAuditBestEffort}'s row for the TRIP. That row answers "the lock was set"; this one
 * answers "the owner was actually told", which a mailer outage or a suppressed retry inside
 * {@see NotifyLockedIdentities}'s window can leave silent while the first stays true. An operator
 * investigating a live lockout needs to see that gap rather than infer it from the mail server's own logs.
 *
 * Runs POST-SAVE, never inside the aggregate's own persistence: by the time {@see NotifyLockedIdentities}
 * calls this, `UserRepository::save()` has already committed the suppression stamp, so a failure here must
 * not look like the notification itself failed — the mail already sent, and the stamp already stands. Both
 * halves swallow for the same reason {@see RecordLockoutAuditBestEffort} does: `security`-level writes
 * propagate ({@see \Erpify\Shared\Audit\Infrastructure\SymfonyAuditLogger}), and letting that escape here
 * would turn a maintenance tick's lost observability row into a scheduler fault that stops the tick — the
 * mailer already sent, so nothing about that send should be undone by a trail write failing afterwards.
 *
 * **Post-save does not mean unserialised: the row is written under the subject's `identity_user` row lock**,
 * through {@see IdentityRowLock}, which says why that keeps a row naming the subject from committing after an
 * erasure's pass over the trail. The tick is where that matters most: {@see NotifyLockedIdentities} reads the
 * identity, sends, and saves its stamp, and an erasure committing anywhere in that span leaves `save()` updating
 * zero rows without raising — `User` carries no version column — so the notice would otherwise be written for a
 * subject whose erasure had already run. Under the lock the absent row is seen and no row is written; an erasure
 * arriving after the lock waits, then finds this row committed and redacts it.
 *
 * The resource type is reached through {@see FulfilIdentityErasure}'s constant rather than spelled here, for
 * the same reason its sibling does: the type denotes a natural person, so the file holding its literal is the
 * one the audit-resource registry names as obliged to erase it.
 *
 * **The report goes to the `observability` channel** — bound in `services.yaml`, since deptrac refuses this
 * layer a dependency on the container's attributes — for the identical reason {@see RecordLockoutAuditBestEffort}
 * does: this runs from a scheduled tick with no response to protect, but the buffered default channel would
 * still either discard the report below `error` or flush unrelated records at it, so the always-on stream is
 * the only channel that reports a lost row without amplifying the outage that caused it.
 *
 * The report itself is wrapped, via {@see ReportsAuditFailureSafely}: a catch whose entire purpose is that
 * nothing escapes may not throw, and the report call is real I/O. Left unguarded, a failing sink would abort
 * {@see NotifyLockedIdentities::notifyLockedOwners()}'s whole tick — every remaining locked identity in that
 * run goes unreported, not just this one.
 */
final readonly class RecordLockoutNoticeAuditBestEffort
{
    use ReportsAbsentSubject;
    use ReportsAuditFailureSafely;

    private const string NOTICE_ACTION = 'ACCOUNT_LOCKOUT_NOTIFIED';

    public function __construct(
        private IdentityRowLock $identityRows,
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * `$lockedUntil` is the only metadata, and nothing else about the notice belongs on this row (the
     * recipient address is the resource's own email, already governed by the person-reference the resource
     * type carries). It does NOT mirror anything the mail itself discloses — {@see SymfonyAccountLockedEmailSender}
     * deliberately carries no IP, device, timestamp or attempt count, so an attacker reading the mailbox
     * learns nothing an owner's next action needs. `lockedUntil` is safe on this operator-facing row for an
     * unrelated reason: it already lives, durably, in the `UserLocked` event's payload in `event_store` from
     * the trip that locked this identity — this row adds no new disclosure, only a query-friendly duplicate.
     */
    public function record(string $userId, ?DateTimeImmutable $lockedUntil): void
    {
        $metadata = $lockedUntil instanceof DateTimeImmutable
            ? ['lockedUntil' => $lockedUntil->format(DateTimeInterface::ATOM)]
            : [];
        $phase = self::PHASE_LOCK;

        try {
            $held = $this->identityRows->whileHeld($userId, function () use ($userId, $metadata, &$phase): void {
                $phase = self::PHASE_WRITE;
                $this->auditLogger->log(
                    self::NOTICE_ACTION,
                    AuditLevel::SECURITY,
                    AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $userId),
                    $metadata,
                );
                $phase = self::PHASE_COMMIT;
            });

            if (!$held) {
                // The mail has already left: this line is the only trace that a notice was sent to an identity
                // erased before its row could be written.
                $this->reportSafely(fn () => $this->logger->info(
                    'Lockout notice sent; no security audit row owed, the identity is gone.',
                    ['phase' => self::PHASE_SUBJECT_ABSENT],
                ));
            }
        } catch (Throwable $throwable) {
            $this->reportSafely(fn () => $this->logger->error(
                'Lockout notice sent; security audit projection skipped.',
                ['phase' => $phase, 'exception' => $throwable],
            ));
        }
    }
}
