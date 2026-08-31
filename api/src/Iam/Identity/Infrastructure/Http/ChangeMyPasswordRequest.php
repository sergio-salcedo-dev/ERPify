<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use Erpify\Shared\Validation\Infrastructure\PasswordPolicy;
use SensitiveParameter;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body for the authenticated password change, mapped through `#[StrictRequestPayload]` so a malformed
 * body — or one carrying a member this endpoint does not implement — is answered 422 `validation-failed` before
 * the use case runs.
 *
 * The two fields are bounded differently on purpose, and this is the endpoint where the difference is visible
 * in one signature. `newPassword` carries {@see PasswordPolicy}, the single policy the product states, because
 * it is the credential being created; `currentPassword` carries only {@see ExistingCredential}'s coarse
 * ceiling, for the reason stated there — a credential being verified is never held to today's rule.
 *
 * Both parameters are `#[SensitiveParameter]`: a stack trace crossing this constructor must not print either
 * plaintext.
 */
final readonly class ChangeMyPasswordRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: ExistingCredential::LENGTH_CEILING)]
        #[SensitiveParameter]
        public string $currentPassword = '',
        #[Assert\NotBlank]
        #[PasswordPolicy]
        #[SensitiveParameter]
        public string $newPassword = '',
    ) {
    }
}
