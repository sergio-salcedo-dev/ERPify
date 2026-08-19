<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Iam\Invitation\Domain\Enum\InvitationStatus;
use Erpify\Iam\Invitation\Domain\Event\InvitationAccepted;
use Erpify\Iam\Invitation\Domain\Event\InvitationCreated;
use Erpify\Iam\Invitation\Domain\Event\InvitationExpired;
use Erpify\Iam\Invitation\Domain\Event\InvitationResent;
use Erpify\Iam\Invitation\Domain\Event\InvitationRevoked;
use Erpify\Iam\Invitation\Domain\Event\InvitationSent;
use Erpify\Iam\Invitation\Domain\Exception\InvalidInvitationTransition;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot;
use Erpify\Shared\Privacy\Domain\PersonSubjectReference;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;
use SensitiveParameter;

/**
 * Invitation aggregate: the delivery record of a pending onboarding. It carries the org it belongs to, the
 * identity it will activate, the at-rest {@see SingleUseToken} digest (never the raw token) and its
 * {@see InvitationStatus} lifecycle `CREATED → SENT → ACCEPTED|REVOKED|EXPIRED`.
 *
 * Cross-module references are by id with no physical FK — `organizationId` points at an Organization,
 * `invitedUserId` at an `Iam\Identity\User` — `Uuid::ensure()`d at the edge. Roles are NOT modelled here
 * (delivery only): `SendInvitation` writes them onto the identity it provisions, which is where authorization
 * reads them from.
 *
 * State-oriented persistence (ADR D5): the business needs the current snapshot of the invitation's lifecycle,
 * not its history as a ledger — auditability is event emission, not event sourcing. Single-use is
 * enforced by the consumer as the `ACCEPTED` status (the token value object itself never blocks a replay);
 * the accept use case retires the invitation in the same transaction as the identity activation.
 *
 * Deliberately NOT an `AuditedEntity`: the `token_hash` is a security-sensitive credential digest that must
 * never enter the five-year audit trail — the invitation's observability is its domain events.
 */
#[ORM\Entity]
#[ORM\Table(name: 'iam_invitation')]
#[ORM\Index(name: 'idx_iam_invitation_invited_user_id', columns: ['invited_user_id'])]
final class Invitation extends AggregateRoot
{
    #[ORM\Column(name: 'organization_id', type: Types::GUID)]
    private string $organizationId;

    #[ORM\Column(name: 'invited_user_id', type: Types::GUID)]
    #[PersonSubjectReference(erasedBy: 'src/Iam/Invitation/Application/PurgeUserInvitations.php')]
    private string $invitedUserId;

    private function __construct(
        string $id,
        string $organizationId,
        string $invitedUserId,
        #[ORM\Column(name: 'token_hash', length: 64)]
        private string $tokenHash,
        #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
        private DateTimeImmutable $expiresAt,
        #[ORM\Column(enumType: InvitationStatus::class)]
        private InvitationStatus $status,
    ) {
        parent::__construct();

        Uuid::ensure($organizationId);
        Uuid::ensure($invitedUserId);

        $this->id = $id;
        $this->organizationId = $organizationId;
        $this->invitedUserId = $invitedUserId;
    }

    /**
     * Mints a `CREATED` invitation from an already-minted {@see SingleUseToken} (its hash + absolute expiry).
     * The raw token never reaches the aggregate — only its digest is persisted.
     */
    public static function create(
        string $id,
        string $organizationId,
        string $invitedUserId,
        SingleUseToken $token,
    ): self {
        $invitation = new self(
            $id,
            $organizationId,
            $invitedUserId,
            $token->toHash(),
            $token->expiresAt(),
            InvitationStatus::CREATED,
        );
        $invitation->record(new InvitationCreated($invitedUserId, occurredOn: $invitation->getCreatedAt()));

        return $invitation;
    }

    /**
     * The delivery transition `CREATED → SENT`.
     *
     * @throws InvalidInvitationTransition when the invitation is not `CREATED`
     */
    public function markSent(): void
    {
        $this->transitionTo(InvitationStatus::SENT, InvitationStatus::CREATED);
        $this->record(new InvitationSent($this->invitedUserId, occurredOn: $this->updatedAt));
    }

    /**
     * Consumes the invitation `SENT → ACCEPTED` — the retire step of the accept flow, run in the same
     * transaction as the identity activation so the single-use guarantee holds.
     *
     * @throws InvalidInvitationTransition when the invitation is not `SENT`
     */
    public function accept(): void
    {
        $this->transitionTo(InvitationStatus::ACCEPTED, InvitationStatus::SENT);
        $this->record(new InvitationAccepted($this->invitedUserId, occurredOn: $this->updatedAt));
    }

    /**
     * Administrator pull `SENT → REVOKED`, terminal.
     *
     * @throws InvalidInvitationTransition when the invitation is not `SENT`
     */
    public function revoke(): void
    {
        $this->transitionTo(InvitationStatus::REVOKED, InvitationStatus::SENT);
        $this->record(new InvitationRevoked($this->invitedUserId, occurredOn: $this->updatedAt));
    }

    /**
     * TTL-lapse `SENT → EXPIRED`, terminal. Materialising expiry as a status is a future sweeper's job; today
     * temporal validity is enforced by {@see verify()} returning false past the TTL, so this transition has no
     * scheduled caller yet (wire-on-consumer).
     *
     * @throws InvalidInvitationTransition when the invitation is not `SENT`
     */
    public function expire(): void
    {
        $this->transitionTo(InvitationStatus::EXPIRED, InvitationStatus::SENT);
        $this->record(new InvitationExpired($this->invitedUserId, occurredOn: $this->updatedAt));
    }

    /**
     * Re-sends a still-live invitation with a fresh token, invalidating the previous digest. Stays `SENT`.
     *
     * @throws InvalidInvitationTransition when the invitation is not `SENT`
     */
    public function resend(SingleUseToken $newToken): void
    {
        if (InvitationStatus::SENT !== $this->status) {
            throw InvalidInvitationTransition::from($this->status, InvitationStatus::SENT);
        }

        $this->tokenHash = $newToken->toHash();
        $this->expiresAt = $newToken->expiresAt();
        $this->updatedAt = SystemClock::now();

        $this->record(new InvitationResent($this->invitedUserId, occurredOn: $this->updatedAt));
    }

    /**
     * True only when the presented secret matches this invitation's digest in constant time AND the TTL has
     * not lapsed. Opaque by construction — a wrong secret and a lapsed token both fail as a plain false — so
     * the accept use case can collapse every dead-token case onto one response.
     */
    public function verify(#[SensitiveParameter] string $presentedSecret, DateTimeImmutable $now): bool
    {
        return SingleUseToken::fromHash($this->tokenHash, $this->expiresAt)->verify($presentedSecret, $now);
    }

    public function invitedUserId(): string
    {
        return $this->invitedUserId;
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }

    public function status(): InvitationStatus
    {
        return $this->status;
    }

    /**
     * @throws InvalidInvitationTransition when the current status is not the required predecessor
     */
    private function transitionTo(InvitationStatus $target, InvitationStatus $requiredFrom): void
    {
        if ($this->status !== $requiredFrom) {
            throw InvalidInvitationTransition::from($this->status, $target);
        }

        $this->status = $target;
        $this->updatedAt = SystemClock::now();
    }
}
