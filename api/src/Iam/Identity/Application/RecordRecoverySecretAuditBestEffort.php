<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Projects the three transitions of a recovery secret onto the operator's `security` surface. Every one runs
 * POST-COMMIT and swallows its own failure, and both halves are decisions rather than caution.
 *
 * Post-commit, because each of the three has already destroyed or created something the caller cannot get
 * back by retrying: the mint's plaintext exists exactly once and is in the response being built, and a
 * redemption or a revocation has already retired the row. Writing inside the transaction would let a failing
 * `audit_log` INSERT roll back the very thing the row exists to attest — and for the mint it would be worse
 * than a lost row, because the identity would keep the committed secret while its owner never saw the
 * plaintext and now meets a 409 on every attempt to mint another.
 *
 * Swallowed, for the same reason: `AuditLevel::SECURITY` propagates by design inside
 * {@see \Erpify\Shared\Audit\Infrastructure\SymfonyAuditLogger}, so without this catch a trail outage would
 * turn all three endpoints into 500s over work that had already succeeded. `Throwable` and not
 * `DbalException`, mirroring {@see RecordLockoutAuditBestEffort}: the writer encodes metadata with
 * `JSON_THROW_ON_ERROR`, and a `JsonException` is not a DBAL type.
 *
 * **The rows carry the SUBJECT, and never the secret.** The resource is the user — the type reached through
 * {@see FulfilIdentityErasure}'s constant, so the erasure chain that anonymises that axis reaches these rows
 * like every other one, and no new classification joins the audit-resource registry. The selector is absent
 * from all three by hard requirement: it is the row's primary key and therefore a denial capability, so
 * whoever could read the trail would be able to close the channel in silence. The consequence is stated
 * rather than hidden — the owner sees THAT a secret of theirs was redeemed, never WHICH, and with one secret
 * per identity that distinction only starts to matter if the one-row invariant is ever relaxed.
 *
 * No metadata at all. There is nothing to put there that is not either the selector, the plaintext, or a
 * request-derived string that would reach `json_encode` on a path whose whole contract is that it cannot
 * throw.
 *
 * **The report goes to the always-on `observability` channel**, bound in `services.yaml` because deptrac
 * refuses this layer a dependency on the container's attributes. On the default channel it would not be read:
 * prod routes that through `fingers_crossed`, which discards its buffer unless a record at `error` or above
 * fires it — and raising the level without moving the channel is strictly worse, since it converts a
 * discarded line into a flush of every record the request accumulated, on a request that is a person's own
 * authenticated session. The report call is itself real I/O, so it is wrapped by
 * {@see ReportsAuditFailureSafely}: a catch whose entire purpose is that nothing escapes may not throw.
 */
final readonly class RecordRecoverySecretAuditBestEffort
{
    use ReportsAuditFailureSafely;

    private const string MINTED_ACTION = 'RECOVERY_SECRET_MINTED';

    private const string REDEEMED_ACTION = 'RECOVERY_SECRET_REDEEMED';

    private const string REVOKED_ACTION = 'RECOVERY_SECRET_REVOKED';

    private const string REDEMPTION_COMPENSATED_ACTION = 'RECOVERY_SECRET_REDEMPTION_COMPENSATED';

    public function __construct(
        private AuditLogger $auditLogger,
        private LoggerInterface $logger,
    ) {
    }

    public function recordMinted(string $userId): void
    {
        $this->record(self::MINTED_ACTION, $userId);
    }

    public function recordRedeemed(string $userId): void
    {
        $this->record(self::REDEEMED_ACTION, $userId);
    }

    public function recordRevoked(string $userId): void
    {
        $this->record(self::REVOKED_ACTION, $userId);
    }

    /**
     * A redemption authenticated and then could not consume, so its sessions were revoked to stop the refusal
     * being answered over live access. It is the only durable trace of that interleaving: the consumption
     * never persisted, so no `RECOVERY_SECRET_REDEEMED` row is written and the domain event died with the
     * rolled-back transaction. Without it an admitted-then-revoked session appears in *Active sessions* with
     * nothing anywhere attributing it to the recovery channel.
     */
    public function recordRedemptionCompensated(string $userId): void
    {
        $this->record(self::REDEMPTION_COMPENSATED_ACTION, $userId);
    }

    /**
     * The report names the action in its CONTEXT rather than in three separate messages: the token is a
     * closed constant of this class, so it identifies which transition lost its row without becoming a
     * per-transition sentence somebody has to keep in step. Nothing else is put there — the user id is
     * deliberately absent, since a line asserting that a person's audit row went missing may not answer by
     * writing that person's identifier into a sink with no retention bound and no erasure owner.
     */
    private function record(string $action, string $userId): void
    {
        try {
            $this->auditLogger->log(
                $action,
                AuditLevel::SECURITY,
                AuditResource::of(FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, $userId),
            );
        } catch (Throwable $throwable) {
            $this->reportSafely(fn () => $this->logger->error(
                'Recovery-secret transition committed; security audit projection skipped (write failed).',
                ['action' => $action, 'exception' => $throwable],
            ));
        }
    }
}
