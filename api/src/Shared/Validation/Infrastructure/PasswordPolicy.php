<?php

declare(strict_types=1);

namespace Erpify\Shared\Validation\Infrastructure;

use Attribute;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint;

/**
 * The product's single policy for a credential being *created*: a bounded length counted in code points, and
 * at least one non-whitespace character.
 *
 * It takes no options, and that is the point. The same policy used to live as six literals across three
 * request DTOs and a console command, and they drifted — the reset surface accepted 255 characters while the
 * other two accepted 128, in production, with no gate able to notice. A configurable
 * `#[PasswordPolicy(max: 255)]` would put those literals straight back, only with better syntax, so the
 * limits are constants of this class and the messages are fixed. A caller that needs different numbers is
 * asking for a different policy, not for an option here.
 *
 * Length is measured in code points, which is what the PWA mirrors with `[...value].length`: JavaScript's
 * `String.length` counts UTF-16 units, so five astral characters read as ten on one side of the wire and
 * five on the other, and the client accepts what the server then refuses.
 *
 * The empty case belongs to `NotBlank`, which owns the "required" vocabulary — this constraint skips it so a
 * missing password produces one violation instead of three. Whitespace-only is *not* that case: `NotBlank`
 * admits eight spaces, and the credential that results cannot be reliably retyped.
 *
 * It is never put on a credential that already exists. `currentPassword` may have been minted under an older
 * or wider rule, and asserting today's policy on it would lock its owner out of the endpoint that would fix
 * it.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class PasswordPolicy extends Constraint
{
    public const int MIN_LENGTH = 8;

    public const int MAX_LENGTH = 128;

    public const string TOO_SHORT_MESSAGE = 'The password must be at least {{ limit }} characters.';

    public const string TOO_LONG_MESSAGE = 'The password must not exceed {{ limit }} characters.';

    public const string BLANK_MESSAGE = 'The password must contain at least one non-whitespace character.';

    #[HasNamedArguments]
    public function __construct(?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
    }
}
