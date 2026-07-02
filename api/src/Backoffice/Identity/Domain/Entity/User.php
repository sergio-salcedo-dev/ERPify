<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Identity\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Erpify\Backoffice\Identity\Domain\Email;
use Erpify\Backoffice\Identity\Domain\Enum\Role;
use Erpify\Backoffice\Identity\Domain\HashedPassword;
use Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Identity aggregate: an email identifier, an opaque {@see HashedPassword} credential and the roles the
 * user carries as authorization data.
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

    #[ORM\Column(name: 'password_hash')]
    private string $passwordHash;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles;

    /**
     * Every construction path funnels through here, so the aggregate's invariants — canonical
     * (lower-cased) email and a duplicate-free role set — hold no matter which factory builds it.
     */
    private function __construct(string $id, string $email, HashedPassword $password, Role ...$roles)
    {
        parent::__construct();

        $this->id = $id;
        $this->email = Email::from($email)->toString();
        $this->passwordHash = $password->toString();
        $this->roles = $this->distinctRoleValues($roles);
    }

    public static function register(string $id, string $email, HashedPassword $password, Role ...$roles): self
    {
        return new self($id, $email, $password, ...$roles);
    }

    /**
     * @return non-empty-string the canonical email; never blank because {@see Email::from()} rejects blanks
     */
    public function email(): string
    {
        return $this->email;
    }

    public function passwordHash(): HashedPassword
    {
        return HashedPassword::fromHash($this->passwordHash);
    }

    /**
     * @return list<Role>
     */
    public function roles(): array
    {
        return \array_map(Role::from(...), $this->roles);
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
