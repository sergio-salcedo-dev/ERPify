<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Http;

use Erpify\Shared\Access\Domain\Role;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload for {@see CreateInvitationController}, mapped from the JSON body via `#[StrictRequestPayload]`
 * (any constraint failure is answered 422 `validation-failed` before the use case runs, so a malformed alta
 * never reaches the domain). It carries only what an invitation needs — the invitee email and the org-scoped
 * roles: an `INVITED` identity has no password and no status yet (both are set later, when the invitation is
 * accepted), so neither belongs on this payload.
 *
 * It bounds the set at the wire edge — a JSON array rather than an object, non-empty, no longer than the
 * vocabulary, and every member inside the {@see Role} vocabulary. The list check is what makes the
 * `list<string>` promise below true: a JSON object denormalizes into a string-keyed array that satisfies every
 * member-level constraint, and the controller would then spread those keys into a variadic as named arguments,
 * where `email` collides with the invitee parameter. Duplicates are deliberately NOT rejected: the invitation
 * collapses them, so a console that sends the same role twice gets the set it asked for rather than an error
 * about a distinction the domain does not make.
 *
 * {@see roleValues()} enumerates the role vocabulary as wire strings. {@see Role} is pure vocabulary by design
 * (no method ranks or maps a role), so the `Role::cases() -> ->value` mapping lives at each payload that needs
 * it rather than as a helper on the enum. The identity console's own role payload carries an identical helper:
 * that duplication is what keeps the two contexts isolated, and it stays until a third caller earns a home for
 * the mapping that neither context owns.
 */
final readonly class InviteUserRequest
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
        #[Assert\NotBlank(message: 'An email is required.')]
        #[Assert\Email(message: 'Enter a valid email address.', mode: Assert\Email::VALIDATION_MODE_STRICT)]
        #[Assert\Length(max: 255, maxMessage: 'The email must not exceed 255 characters.')]
        public string $email = '',
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
