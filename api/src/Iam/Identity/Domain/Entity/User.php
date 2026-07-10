<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Iam\Identity\Domain\Event\UserDeactivated;
use Erpify\Iam\Identity\Domain\Event\UserSuspended;
use Erpify\Iam\Identity\Domain\Exception\InvalidIdentityTransition;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Identity aggregate: an email identifier, an opaque {@see HashedPassword} credential, the {@see Role}s the
 * user carries as authorization data, and an {@see IdentityStatus} lifecycle the admission check reads.
 *
 * The credential is nullable until the identity activates: an `INVITED` identity is provisioned (with its
 * membership) before its owner ever sets a password, so `password_hash` is null until {@see activate()}.
 * Only an `ACTIVE` identity is admitted to a session; the {@see suspend()} / {@see deactivate()} walls are
 * enforced post-authentication so a rejected login mints no session at all, never one that is then revoked.
 *
 * Deliberately does NOT implement {@see \Erpify\Shared\Audit\Domain\AuditedEntity}: the write-capture
 * listener records a field-level diff only for entities that opt into that marker, so staying out keeps
 * the `password_hash` from ever entering the audit trail (a credential leak). If user management is ever
 * audited, `password_hash` must be excluded/classified first.
 */
#[ORM\Entity]
#[ORM\Table(name: 'identity_user')]
#[UniqueEntity(fields: ['email'], message: 'This email is already in use.')]
final class User extends AggregateRoot
{
    /** @var non-empty-string */
    #[ORM\Column(unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]
    private string $email;

    #[ORM\Column(name: 'password_hash', nullable: true)]
    private ?string $passwordHash;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles;

    /**
     * Every construction path funnels through here, so the aggregate's invariants — canonical
     * (lower-cased) email and a duplicate-free role set — hold no matter which factory builds it. The
     * credential is nullable so an `INVITED` identity can exist before a password is set.
     */
    private function __construct(
        string $id,
        string $email,
        ?HashedPassword $password,
        #[ORM\Column(enumType: IdentityStatus::class)]
        private IdentityStatus $status,
        Role ...$roles,
    ) {
        parent::__construct();

        $this->id = $id;
        $this->email = Email::from($email)->toString();
        $this->passwordHash = $password?->toString();
        $this->roles = $this->distinctRoleValues($roles);
    }

    /**
     * Builds an already-credentialed, `ACTIVE` identity — the bootstrap/administrator path where the
     * password is known up front. The invitation path that begins credential-less is {@see invite()}.
     */
    public static function register(string $id, string $email, HashedPassword $password, Role ...$roles): self
    {
        return new self($id, $email, $password, IdentityStatus::ACTIVE, ...$roles);
    }

    /**
     * Provisions an `INVITED` identity with no credential yet: its owner sets the password when it
     * {@see activate()}s the invitation. Roles are assigned up front — they are a property of belonging.
     */
    public static function invite(string $id, string $email, Role ...$roles): self
    {
        return new self($id, $email, null, IdentityStatus::INVITED, ...$roles);
    }

    /**
     * Fixes the credential and admits the identity: `INVITED → ACTIVE`.
     *
     * @throws InvalidIdentityTransition when the identity is not `INVITED`
     */
    public function activate(HashedPassword $password): void
    {
        $this->guardTransitionTo(IdentityStatus::ACTIVE, IdentityStatus::INVITED);

        $this->passwordHash = $password->toString();
        $this->status = IdentityStatus::ACTIVE;
        $this->updatedAt = SystemClock::now();
    }

    /**
     * Raises the reversible post-active wall: `ACTIVE → SUSPENDED`.
     *
     * @throws InvalidIdentityTransition when the identity is not `ACTIVE`
     */
    public function suspend(): void
    {
        $this->guardTransitionTo(IdentityStatus::SUSPENDED, IdentityStatus::ACTIVE);

        $this->status = IdentityStatus::SUSPENDED;
        $this->updatedAt = SystemClock::now();

        $this->record(new UserSuspended($this->id(), null, $this->updatedAt));
    }

    /**
     * Retires the identity: `ACTIVE → DEACTIVATED`.
     *
     * @throws InvalidIdentityTransition when the identity is not `ACTIVE`
     */
    public function deactivate(): void
    {
        $this->guardTransitionTo(IdentityStatus::DEACTIVATED, IdentityStatus::ACTIVE);

        $this->status = IdentityStatus::DEACTIVATED;
        $this->updatedAt = SystemClock::now();

        $this->record(new UserDeactivated($this->id(), null, $this->updatedAt));
    }

    /**
     * @return non-empty-string the canonical email; never blank because {@see Email::from()} rejects blanks
     */
    public function email(): string
    {
        return $this->email;
    }

    /**
     * The stored credential, or `null` for an identity that has not set one yet (`INVITED`). The nullness
     * lives here, not in {@see HashedPassword}, so the value object never represents an "empty" credential.
     */
    public function passwordHash(): ?HashedPassword
    {
        return null === $this->passwordHash ? null : HashedPassword::fromHash($this->passwordHash);
    }

    public function status(): IdentityStatus
    {
        return $this->status;
    }

    /**
     * @return list<Role>
     */
    public function roles(): array
    {
        return \array_map(Role::from(...), $this->roles);
    }

    /**
     * Guards a lifecycle transition: only the single legal predecessor may reach `$target`. Cross-aggregate
     * invariants (e.g. the organization keeps ≥1 active ADMIN) live in the application use case, not here.
     *
     * @throws InvalidIdentityTransition when the current status is not the required predecessor
     */
    private function guardTransitionTo(IdentityStatus $target, IdentityStatus $requiredFrom): void
    {
        if ($this->status !== $requiredFrom) {
            throw InvalidIdentityTransition::from($this->status, $target);
        }
    }

    /**
     * @param array<Role> $roles
     *
     * @return list<string>
     */
    private function distinctRoleValues(array $roles): array
    {
        $values = [];

        foreach ($roles as $role) {
            $values[$role->value] = $role->value;
        }

        return \array_values($values);
    }
}
