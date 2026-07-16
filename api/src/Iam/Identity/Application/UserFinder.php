<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;

/**
 * Read-side finder for a single identity by id. Reuses the aggregate-lifecycle {@see UserRepository} and
 * the existing {@see UserNotFound} rather than a bespoke read port — the detail view needs the whole
 * aggregate's readable surface, and there is no read composition to project.
 */
final readonly class UserFinder
{
    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    /**
     * A malformed id is a request-target syntax error surfaced as 400 `invalid-uuid` (via
     * {@see InvalidUuidException}), guarded BEFORE any lookup; a well-formed id with no matching row is 404.
     *
     * @throws InvalidUuidException when $id is not a well-formed RFC 4122 UUID
     * @throws UserNotFound         when no user exists for the given $id
     */
    public function find(string $id): User
    {
        Uuid::ensure($id);

        $user = $this->userRepository->findById($id);

        if (!$user instanceof User) {
            throw UserNotFound::withId($id);
        }

        return $user;
    }
}
