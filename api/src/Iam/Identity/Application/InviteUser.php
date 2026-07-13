<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Shared\Validation\Application\Validator;

/**
 * Provisions an `INVITED` identity with no credential yet — the invitation onboarding path, the credential-less
 * sibling of {@see CreateUser}. The server mints the id (UUID v7), the aggregate enforces its invariants
 * (canonical email, distinct roles) and {@see Validator::ensure()} runs the entity constraints (`#[Assert\Email]`
 * and the `#[UniqueEntity(email)]` guard) before persisting, so a duplicate email surfaces as a clean validation
 * failure rather than a raw DB error. The owner sets the password later by accepting the invitation.
 *
 * This is the published seam the invitation orchestrator funnels through, so it never touches the {@see User}
 * aggregate factory across the context boundary — the same shape the bootstrap uses via {@see CreateUser}.
 */
final readonly class InviteUser
{
    public function __construct(
        private UserRepository $users,
        private Validator $validator,
    ) {
    }

    public function invite(string $email, Role ...$roles): User
    {
        $user = User::invite(Uuid::generate(), $email, ...$roles);

        $this->validator->ensure($user);
        $this->users->save($user);

        return $user;
    }
}
