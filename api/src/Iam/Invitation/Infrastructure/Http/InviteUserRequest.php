<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Http;

use Erpify\Iam\Identity\Domain\Enum\Role;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload for {@see CreateInvitationController}, mapped from the JSON body via `#[MapRequestPayload]`
 * (any constraint failure is answered 422 `validation-failed` before the use case runs, so a malformed alta
 * never reaches the domain). It carries only what an invitation needs — the invitee email and the org-scoped
 * roles: an `INVITED` identity has no password and no status yet (both are set later, when the invitation is
 * accepted), so neither belongs on this payload.
 *
 * {@see roleValues()} is the single production site that enumerates the role vocabulary as wire strings. {@see Role}
 * is pure vocabulary by design (no method ranks or maps a role), so the `Role::cases() -> ->value` mapping lives
 * here at its one caller rather than as a helper on the enum.
 */
final readonly class InviteUserRequest
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        #[Assert\NotBlank(message: 'An email is required.')]
        #[Assert\Email(message: 'Enter a valid email address.', mode: Assert\Email::VALIDATION_MODE_STRICT)]
        public string $email = '',
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
