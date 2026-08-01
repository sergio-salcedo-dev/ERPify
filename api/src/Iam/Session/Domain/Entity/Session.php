<?php

declare(strict_types=1);

namespace Erpify\Iam\Session\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Iam\Session\Domain\Enum\SessionStatus;
use Erpify\Iam\Session\Domain\Event\SessionRevoked;
use Erpify\Iam\Session\Domain\Event\SessionStarted;
use Erpify\Iam\Session\Domain\Exception\InvalidSessionTransition;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot;
use Erpify\Shared\Privacy\Domain\PersonSubjectReference;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Session aggregate: the server-side registry row that makes "authenticated" mean "has a live, revocable
 * session". It is the source of truth for the per-actor lifecycle `ACTIVE → REVOKED` — a single, terminal,
 * guarded transition. Temporal validity is a separate axis the {@see isActive()} predicate reads from the
 * absolute {@see $expiresAt}, never a persisted state (there is no `EXPIRED`).
 *
 * Cross-module references are by id with no physical FK — `userId` points at an `Iam\Identity\User`,
 * `organizationId` at an Organization — mirroring `membership.user_id`. Integrity needs no FK: the gate keeps a
 * session of a deleted user inert, and the absence of a FK is what lets a user be hard-deleted for GDPR erasure
 * without a `RESTRICT` block or a `CASCADE` that couples the delete across modules.
 *
 * Deliberately NOT an `AuditedEntity`: `ip`/`device` are short-lived operational PII that must never enter the
 * five-year audit trail — the session's observability comes from its domain events, not the audit table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'iam_session')]
#[ORM\Index(name: 'idx_iam_session_user_id_status', columns: ['user_id', 'status'])]
final class Session extends AggregateRoot
{
    #[ORM\Column(name: 'user_id', type: Types::GUID)]
    #[PersonSubjectReference(erasedBy: 'src/Iam/Session/Application/PurgeUserSessions.php')]
    private string $userId;

    #[ORM\Column(name: 'organization_id', type: Types::GUID)]
    private string $organizationId;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    private function __construct(
        string $id,
        string $userId,
        string $organizationId,
        #[ORM\Column(length: 255)]
        private string $device,
        #[ORM\Column(length: 45, nullable: true)]
        private ?string $ip,
        #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $expiresAt,
        #[ORM\Column(enumType: SessionStatus::class)]
        private SessionStatus $status,
    ) {
        parent::__construct();

        Uuid::ensure($userId);
        Uuid::ensure($organizationId);

        $this->id = $id;
        $this->userId = $userId;
        $this->organizationId = $organizationId;
    }

    /**
     * Mints an `ACTIVE` session with an absolute expiry, recording {@see SessionStarted}. The `$expiresAt` is
     * passed in already absolute (the minting use case computes `now + ttl` from the domain clock) so the
     * aggregate never reads a client clock.
     */
    public static function start(
        string $id,
        string $userId,
        string $organizationId,
        string $device,
        ?string $ip,
        DateTimeImmutable $expiresAt,
    ): self {
        $session = new self($id, $userId, $organizationId, $device, $ip, $expiresAt, SessionStatus::ACTIVE);
        $session->record(new SessionStarted($id, $userId, occurredOn: $session->getCreatedAt()));

        return $session;
    }

    /**
     * The only lifecycle transition: `ACTIVE → REVOKED`, terminal and guarded. Idempotent callers guard against
     * a double-revoke by loading only active sessions; a direct illegal call is rejected before any mutation.
     *
     * @throws InvalidSessionTransition when the session is not `ACTIVE`
     */
    public function revoke(): void
    {
        $this->guardTransitionTo(SessionStatus::REVOKED, SessionStatus::ACTIVE);

        $now = SystemClock::now();
        $this->status = SessionStatus::REVOKED;
        $this->revokedAt = $now;
        $this->updatedAt = $now;

        $this->record(new SessionRevoked($this->id(), $this->userId, occurredOn: $now));
    }

    /**
     * Temporal validity predicate: a session is expired the instant `now` reaches its absolute
     * {@see $expiresAt}. The caducity axis is orthogonal to the `ACTIVE/REVOKED` lifecycle; the gate query and
     * the "my sessions" projection both apply it, so caducity is never written as a state.
     */
    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function isActive(DateTimeImmutable $now): bool
    {
        return SessionStatus::ACTIVE === $this->status && !$this->isExpired($now);
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }

    public function status(): SessionStatus
    {
        return $this->status;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function device(): string
    {
        return $this->device;
    }

    public function ip(): ?string
    {
        return $this->ip;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    /**
     * @throws InvalidSessionTransition when the current status is not the required predecessor
     */
    private function guardTransitionTo(SessionStatus $target, SessionStatus $requiredFrom): void
    {
        if ($this->status !== $requiredFrom) {
            throw InvalidSessionTransition::from($this->status, $target);
        }
    }
}
