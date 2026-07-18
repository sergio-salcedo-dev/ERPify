<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use DateTimeInterface;
use Erpify\Iam\Identity\Application\Resource\UserDetailResource;
use Erpify\Iam\Identity\Application\Resource\UserListResource;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Projection\UserRow;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Search\Domain\Page;
use LogicException;

/**
 * Maps the read-side sources of an identity to the per-view resource DTO that is actually serialized. The
 * single place that knows each user wire shape: the register list view maps from the {@see UserRow}
 * projection, the detail view from the {@see User} aggregate. In both, `status` / `roles` resolve to their
 * enum identity `->value` and timestamps to ATOM strings. Neither the User entity nor the projection row
 * reaches the serializer — the credential therefore never leaves the domain.
 */
final readonly class UserResourceMapper
{
    public function toListResource(UserRow $row): UserListResource
    {
        return new UserListResource(
            $row->id,
            $row->email,
            $row->status->value,
            $row->roles,
            $row->createdAt->format(DateTimeInterface::ATOM),
            $row->updatedAt->format(DateTimeInterface::ATOM),
        );
    }

    /**
     * @param Page<UserRow> $page
     *
     * @return Page<UserListResource>
     */
    public function toListPage(Page $page): Page
    {
        return new Page(
            \array_map($this->toListResource(...), $page->items),
            $page->hasNext,
            $page->hasPrev,
            $page->count,
            $page->nextCursor,
            $page->prevCursor,
        );
    }

    public function toDetailResource(User $user): UserDetailResource
    {
        return new UserDetailResource(
            $this->requireId($user),
            $user->email(),
            $user->status()->value,
            $this->roleValues($user),
            $user->getCreatedAt()->format(DateTimeInterface::ATOM),
            $user->getUpdatedAt()->format(DateTimeInterface::ATOM),
        );
    }

    /**
     * @return list<string>
     */
    private function roleValues(User $user): array
    {
        return \array_map(static fn (Role $role): string => $role->value, $user->roles());
    }

    private function requireId(User $user): string
    {
        $id = $user->getId();

        if (null === $id) {
            throw new LogicException('Cannot map a User without an assigned id to a resource.');
        }

        return $id;
    }
}
