<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Domain\Entity;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Event\PasswordChanged;
use Erpify\Iam\Identity\Domain\Event\PasswordResetCompleted;
use Erpify\Iam\Identity\Domain\Event\UserDeactivated;
use Erpify\Iam\Identity\Domain\Event\UserLocked;
use Erpify\Iam\Identity\Domain\Event\UserRolesChanged;
use Erpify\Iam\Identity\Domain\Event\UserSuspended;
use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Iam\Identity\Domain\Exception\InvalidIdentityTransition;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Shared\Access\Domain\Role;
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
 *
 * The public surface is the aggregate's whole contract — its factories, its lifecycle transitions, the
 * lockout behaviour and the read accessors the security adapter needs — so the "too many public methods"
 * count reflects a rich aggregate root, not a class doing several jobs. Its object coupling reads the same
 * way: the collaborators are inherent responsibilities — persistence/validation metadata, the credential and
 * email value objects, the status lifecycle, the role vocabulary and the six facts it records — so the
 * coupling is deliberately allowed above the default threshold rather than dissolved into anaemic helpers.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[ORM\Entity]
#[ORM\Table(name: 'identity_user')]
#[UniqueEntity(fields: ['email'], message: 'This email is already in use.')]
final class User extends AggregateRoot
{
    /**
     * Consecutive failed attempts that trip the lockout, and how long it then holds (`PT15M`). A second,
     * per-identity line behind the ephemeral per-IP `login_throttling` (max 5): the persistent counter catches
     * a distributed credential-stuffing run that sprays a single account from many IPs and so evades the
     * per-IP throttle — hence the higher threshold, which never fires on honest typos.
     */
    public const int MAX_FAILED_ATTEMPTS = 10;

    public const string LOCK_DURATION = 'PT15M';

    /** @var non-empty-string */
    #[ORM\Column(unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]
    #[Assert\Length(max: 255)]
    private string $email;

    #[ORM\Column(name: 'password_hash', nullable: true)]
    private ?string $passwordHash;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles;

    /**
     * Consecutive failed password attempts against this resolved identity. Reset to zero on any successful
     * login or an explicit lockout clear — the aggregate keeps only the current count, never the history of
     * attempts (the durable record is the {@see UserLocked} domain event, not this snapshot).
     */
    #[ORM\Column(name: 'failed_attempts', type: Types::INTEGER)]
    private int $failedAttempts = 0;

    /**
     * The instant the temporary lockout expires, or `null` when the identity is not locked. A timestamp gate
     * orthogonal to {@see IdentityStatus}: a locked identity stays `ACTIVE`; the admission check reads this
     * separately from the lifecycle wall, so `ACTIVE + locked` is representable while the status is untouched.
     */
    #[ORM\Column(name: 'locked_until', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lockedUntil = null;

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
     * The post-identity admission wall: refuses a non-`ACTIVE` identity, naming the reason its own status
     * implies. Which exception a status warrants is a property of the status, so the aggregate owns it —
     * a caller that reads the predicates and picks the exception itself is deciding on the aggregate's
     * behalf, and the next caller will pick differently.
     *
     * **Only call this where the caller has already proven control of the identity** — a valid single-use
     * token, or verified credentials. The refusal is deliberately specific (`SUSPENDED` and `DEACTIVATED`
     * answer differently), so on a pre-identity surface it would confirm an account exists and leak its
     * status. Where that matters, the uniform path is silence, not a graduated wall.
     *
     * @throws AccountSuspended   the reversible wall — the identity may return
     * @throws AccountDeactivated the terminal wall, and the answer for an identity that never activated
     */
    public function ensureActive(): void
    {
        match ($this->status) {
            IdentityStatus::ACTIVE => null,
            IdentityStatus::SUSPENDED => throw new AccountSuspended(),
            // INVITED is unreachable from the reset flow — a token is only minted for an ACTIVE identity and
            // no transition returns to INVITED — but an unadmitted identity must be refused explicitly
            // rather than fall through some `else` into whichever reason happens to be last.
            IdentityStatus::INVITED, IdentityStatus::DEACTIVATED => throw new AccountDeactivated(),
        };
    }

    /**
     * Replaces the credential of an already-active identity — the password-reset mutator. Distinct from
     * {@see activate()} (guarded `INVITED → ACTIVE`, which sets the FIRST credential): a reset overwrites the
     * credential of an identity that is already `ACTIVE` and leaves the status untouched. Guards `ACTIVE`
     * defensively — the reset use case walls a non-`ACTIVE` identity before reaching here, so an invocation on
     * any other state is an orchestration bug, not a reachable user path. Records {@see PasswordResetCompleted}
     * at its source, like the lifecycle transitions.
     *
     * @throws InvalidIdentityTransition when the identity is not `ACTIVE`
     */
    public function resetPassword(HashedPassword $password): void
    {
        $this->replaceCredential($password);

        $this->record(new PasswordResetCompleted($this->id()));
    }

    /**
     * Replaces the credential at the owner's own request, from a session that already proved the current one —
     * the self-service change. Deliberately NOT {@see resetPassword()}: both overwrite the credential of an
     * `ACTIVE` identity, but they record different facts, and reusing the reset would make the durable log claim
     * a recovery link was consumed whenever a signed-in user simply retyped their password. Guards `ACTIVE`
     * defensively, like the reset — the use case is expected to wall a non-`ACTIVE` identity first, so reaching
     * here in any other state is an orchestration bug. Records {@see PasswordChanged} at its source.
     *
     * @throws InvalidIdentityTransition when the identity is not `ACTIVE`
     */
    public function changePassword(HashedPassword $password): void
    {
        $this->replaceCredential($password);

        $this->record(new PasswordChanged($this->id()));
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
     * Replaces the identity's whole role set — the set is the unit of change, so this is an assignment, not a
     * grant/revoke delta. Deduplicates through the same helper every construction path uses, so the
     * duplicate-free invariant holds however the roles arrive.
     *
     * Carries no lifecycle guard, unlike the transitions above: roles are orthogonal to {@see IdentityStatus},
     * so re-roling a `SUSPENDED` or `INVITED` identity is meaningful (its roles simply stay inert until it can
     * act again) and there is no state matrix to enforce. The cross-aggregate "keep ≥1 active ADMIN" rule is
     * deliberately absent too — it must see every other identity, so it lives in the application use case.
     */
    public function changeRoles(Role ...$roles): void
    {
        $this->roles = $this->distinctRoleValues($roles);
        $this->updatedAt = SystemClock::now();

        $this->record(new UserRolesChanged($this->id(), $this->roles()));
    }

    /**
     * Registers a failed password attempt. Only an `ACTIVE` identity accrues a lockout: the machine is about
     * password attempts, and a non-`ACTIVE` identity either has no password to attempt (`INVITED`) or is already
     * walled by its lifecycle status (`SUSPENDED`/`DEACTIVATED`) — counting it would seal a lock and emit a
     * spurious {@see UserLocked} on an identity that can never present the "temporarily locked" wall. A no-op
     * while already locked — the attempt is refused before the password is even checked, so it neither grows the
     * counter unboundedly nor re-emits {@see UserLocked} on every hit within the window. On crossing
     * {@see MAX_FAILED_ATTEMPTS} it seals the lockout for {@see LOCK_DURATION} and records the fact once. `$now`
     * comes from the application's injected clock so the expiry and the event timestamp share one time source
     * (never a static wall clock that diverges in tests). Reports whether it mutated the aggregate, so the
     * caller can skip the persistence round-trip on a no-op attempt (a non-`ACTIVE` identity, or one already
     * locked within its window) — mirroring {@see clearLockout()} and keeping the failure path free of an empty
     * write under a sustained attack on a locked account.
     */
    public function recordFailedAttempt(DateTimeImmutable $now): bool
    {
        if (IdentityStatus::ACTIVE !== $this->status) {
            return false;
        }

        if ($this->isLockedAt($now)) {
            return false;
        }

        if ($this->lockedUntil instanceof DateTimeImmutable) {
            // A previous lock has lapsed (we are past its expiry). That window is closed, so a fresh run
            // of attempts starts from zero — otherwise the stale count would re-lock on the first
            // post-expiry miss and grow without bound across cycles.
            $this->failedAttempts = 0;
            $this->lockedUntil = null;
        }

        ++$this->failedAttempts;

        if ($this->failedAttempts >= self::MAX_FAILED_ATTEMPTS) {
            $this->lockedUntil = $now->add(new DateInterval(self::LOCK_DURATION));

            $this->record(new UserLocked($this->id(), $this->lockedUntil));
        }

        return true;
    }

    /**
     * Clears any lockout: zeroes the counter and drops the expiry. Returns whether anything actually changed,
     * so a caller can skip the persistence round-trip on the common successful login where there is nothing to
     * clear. Idempotent — safe to call when already clean.
     */
    public function clearLockout(): bool
    {
        if (0 === $this->failedAttempts && !$this->lockedUntil instanceof DateTimeImmutable) {
            return false;
        }

        $this->failedAttempts = 0;
        $this->lockedUntil = null;

        return true;
    }

    /**
     * Whether the identity is locked at `$now`: a lockout is set and its expiry is still in the future. A
     * lapsed `lockedUntil` (in the past) reads as unlocked without any scheduler — the physical reset happens
     * lazily on the next successful login.
     */
    public function isLockedAt(DateTimeImmutable $now): bool
    {
        return $this->lockedUntil instanceof DateTimeImmutable && $this->lockedUntil > $now;
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
     * Whether the identity is admitted to act — the single `ACTIVE` predicate the post-identity flows read
     * before granting anything. Orthogonal to the lockout gate ({@see isLockedAt()}): an `ACTIVE` identity can
     * still be temporarily locked.
     */
    public function isActive(): bool
    {
        return IdentityStatus::ACTIVE === $this->status;
    }

    /**
     * @return list<Role>
     */
    public function roles(): array
    {
        return \array_map(Role::from(...), $this->roles);
    }

    /**
     * The credential overwrite both replacement paths share: the `ACTIVE` guard, the new hash and the touch.
     * Only the fact each path records differs, so keeping the mutation in one place is what guarantees a reset
     * and a self-service change can never drift apart on the guard or on the timestamp.
     *
     * @throws InvalidIdentityTransition when the identity is not `ACTIVE`
     */
    private function replaceCredential(HashedPassword $password): void
    {
        if (IdentityStatus::ACTIVE !== $this->status) {
            throw InvalidIdentityTransition::from($this->status, IdentityStatus::ACTIVE);
        }

        $this->passwordHash = $password->toString();
        $this->updatedAt = SystemClock::now();
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
