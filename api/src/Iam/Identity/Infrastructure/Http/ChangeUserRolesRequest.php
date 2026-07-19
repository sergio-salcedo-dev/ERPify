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
 * It bounds the set at the wire edge — a JSON array rather than an object, non-empty, no longer than the
 * vocabulary, and every member inside the {@see Role} vocabulary — and nothing else. The list check is what
 * makes the `list<string>` promise below true: a JSON object denormalizes into a string-keyed array that
 * satisfies every member-level constraint, and the controller would then spread those keys into a variadic as
 * named arguments. Duplicates are deliberately NOT rejected: the aggregate owns the duplicate-free invariant
 * and collapses them, so a console that sends the same role twice gets the set it asked for rather than an
 * error about a distinction the domain does not make.
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
     * A set can hold each role at most once, so the whole vocabulary is the ceiling. Attributes need a constant
     * expression, so the number cannot be derived from {@see Role::cases()} here; a test pins the two together.
     */
    public const int MAX_ROLES = 5;

    /**
     * @param list<string> $roles
     */
    public function __construct(
        #[Assert\Type(type: 'list', message: 'Select roles as a list.')]
        #[Assert\Count(
            min: 1,
            max: self::MAX_ROLES,
            minMessage: 'Select at least one role.',
            maxMessage: 'Select no more than {{ limit }} roles.',
        )]
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
