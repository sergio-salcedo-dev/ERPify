<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use Erpify\Shared\Access\Domain\Role;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input payload for {@see \Erpify\Iam\Identity\Infrastructure\Controller\UserPatchRolesController}, mapped from
 * the JSON body via `#[MapRequestPayload]` so a malformed set is answered 422 `validation-failed` before the
 * use case runs.
 *
 * It bounds the set at the wire edge — non-empty, and every member inside the {@see Role} vocabulary — and
 * nothing else. Duplicates are deliberately NOT rejected: the aggregate owns the duplicate-free invariant and
 * collapses them, so a console that sends the same role twice gets the set it asked for rather than an error
 * about a distinction the domain does not make.
 *
 * Carries an array of wire strings rather than an array of {@see Role} because the constraint layer must be
 * able to report which member was invalid; the controller maps them to the enum once they are known good.
 * {@see roleValues()} duplicates the identical helper the Invitation context's payload owns, on purpose: the
 * two contexts are isolated by design, and sharing it would either couple them or push a method onto a
 * vocabulary enum that deliberately has none.
 */
final readonly class ChangeUserRolesRequest
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        #[Assert\Count(min: 1, minMessage: 'Select at least one role.')]
        #[Assert\Choice(
            callback: [self::class, 'roleValues'],
            multiple: true,
            multipleMessage: 'Select a valid role.',
        )]
        public array $roles = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public static function roleValues(): array
    {
        return \array_map(static fn (Role $role): string => $role->value, Role::cases());
    }
}
