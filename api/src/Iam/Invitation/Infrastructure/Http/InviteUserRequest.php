<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Http;

use Erpify\Iam\Identity\Infrastructure\Http\ExistingCredential;
use Erpify\Shared\Access\Domain\Role;
use SensitiveParameter;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Request payload for {@see CreateInvitationController}, mapped from the JSON body via `#[StrictRequestPayload]`
 * (any constraint failure is answered 422 `validation-failed` before the use case runs, so a malformed alta
 * never reaches the domain). It carries only what an invitation needs — the invitee email and the org-scoped
 * roles: an `INVITED` identity has no password and no status yet (both are set later, when the invitation is
 * accepted), so neither belongs on this payload. The one exception is `currentPassword`: the CALLER's own
 * password, required exactly when `roles` carries `ADMIN` (see {@see CreateInvitationController}) and ignored
 * otherwise, which is why it is conditional here and not on the routes that always need it.
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
        #[SensitiveParameter]
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
        #[Assert\Length(max: ExistingCredential::LENGTH_CEILING)]
        #[SensitiveParameter]
        public string $currentPassword = '',
    ) {
    }

    /**
     * The proof is owed exactly when the set hands out `ADMIN`, so the requirement depends on a sibling member
     * and cannot be a constraint on the property alone. A violation is reported on `currentPassword` so a
     * client can put the message beside the field.
     */
    #[Assert\Callback]
    public function requireTheCurrentPasswordToInviteAnAdministrator(ExecutionContextInterface $context): void
    {
        if (!\in_array(Role::ADMIN->value, $this->roles, true) || '' !== \trim($this->currentPassword)) {
            return;
        }

        $context
            ->buildViolation('Enter your current password to invite an administrator.')
            ->atPath('currentPassword')
            ->addViolation()
        ;
    }

    /**
     * @return list<string>
     */
    public static function roleValues(): array
    {
        return \array_map(static fn (Role $role): string => $role->value, Role::cases());
    }
}
