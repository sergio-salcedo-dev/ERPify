<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Iam\Identity\Domain\Exception\SecondFactorAlreadyEnabled;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot;
use Erpify\Shared\Privacy\Domain\PersonSubjectReference;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * An identity's time-based second factor: the sealed shared secret, whether its owner has confirmed it, and the
 * last time step a code was accepted at. State-oriented by the owner's decision — the current snapshot is the
 * whole business, and the audit trail records each transition.
 *
 * **Pending until confirmed.** An enrolment writes the row with no `enabledAt`; only a valid code computed from
 * the secret enables it, so a factor its owner never managed to set up gates nothing. A pending enrolment may be
 * restarted, which replaces the sealed secret under the same scope; an enabled one may not, because replacing it
 * would remove the owner's authenticator without the code the owner holds.
 *
 * **The secret is held only as ciphertext**, sealed by the Application layer under {@see $scopeId} with an
 * associated data bound to {@see $userId}: this aggregate never sees the plaintext, so nothing that serialises
 * or logs it can leak one. The scope id is random and minted with the row rather than derived from the person's
 * id, because the key's tombstone and the erasure evidence that answers it outlive the person; a scope named by
 * the person would keep their id in both for ever. The associated data is what binds the ciphertext to the
 * identity: a sealed value copied onto another identity's row does not authenticate there.
 *
 * **A code is accepted once.** {@see $lastAcceptedStep} is the step the last accepted code matched, and the
 * algorithm port refuses any code at or before it, so a code read over a shoulder cannot be replayed inside its
 * own window.
 *
 * One row per identity ({@see $userId} is unique). No foreign key to `identity_user`, like every person reference
 * in this schema: the identity erasure deletes this row and destroys its key in the same transaction.
 */
#[ORM\Entity]
#[ORM\Table(name: 'identity_totp_factor')]
#[ORM\UniqueConstraint(name: 'uniq_identity_totp_factor_user_id', columns: ['user_id'])]
final class TotpFactor extends AggregateRoot
{
    #[ORM\Column(name: 'user_id', type: Types::GUID)]
    #[PersonSubjectReference(erasedBy: 'src/Iam/Identity/Application/EraseIdentitySubject.php')]
    private string $userId;

    #[ORM\Column(name: 'scope_id', type: Types::GUID)]
    private string $scopeId;

    #[ORM\Column(name: 'enabled_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $enabledAt = null;

    #[ORM\Column(name: 'last_accepted_step', type: Types::INTEGER, nullable: true)]
    private ?int $lastAcceptedStep = null;

    private function __construct(
        string $id,
        string $userId,
        string $scopeId,
        #[ORM\Column(name: 'sealed_secret', type: Types::TEXT)]
        private string $sealedSecret,
    ) {
        parent::__construct();

        Uuid::ensure($id);
        Uuid::ensure($userId);
        Uuid::ensure($scopeId);

        $this->id = $id;
        $this->userId = \strtolower($userId);
        $this->scopeId = $scopeId;
    }

    /**
     * A pending enrolment. `$sealedSecret` is the ciphertext the caller produced under `$scopeId` for `$userId`.
     */
    public static function enrol(string $id, string $userId, string $scopeId, string $sealedSecret): self
    {
        return new self($id, $userId, $scopeId, $sealedSecret);
    }

    /**
     * Restarts a pending enrolment with a new secret sealed under the same scope.
     *
     * @throws SecondFactorAlreadyEnabled when the factor is already enabled
     */
    public function restartEnrolment(string $sealedSecret): void
    {
        $this->ensureNotEnabled();

        $this->sealedSecret = $sealedSecret;
        $this->updatedAt = SystemClock::now();
    }

    /**
     * Enables the factor once its owner has presented a code matching `$acceptedStep`, which becomes the step no
     * later code may repeat.
     *
     * @throws SecondFactorAlreadyEnabled when the factor is already enabled
     */
    public function enable(int $acceptedStep, DateTimeImmutable $at): void
    {
        $this->ensureNotEnabled();

        $this->enabledAt = $at;
        $this->lastAcceptedStep = $acceptedStep;
        $this->updatedAt = $at;
    }

    public function isEnabled(): bool
    {
        return $this->enabledAt instanceof DateTimeImmutable;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function scopeId(): string
    {
        return $this->scopeId;
    }

    public function sealedSecret(): string
    {
        return $this->sealedSecret;
    }

    public function enabledAt(): ?DateTimeImmutable
    {
        return $this->enabledAt;
    }

    public function lastAcceptedStep(): ?int
    {
        return $this->lastAcceptedStep;
    }

    private function ensureNotEnabled(): void
    {
        if ($this->isEnabled()) {
            throw new SecondFactorAlreadyEnabled();
        }
    }
}
