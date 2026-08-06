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
 * The two fields are bounded differently on purpose. `newPassword` carries {@see PasswordPolicy}, the single
 * policy the product states, because it is the credential being created. `currentPassword` carries no policy
 * at all — it is a credential that already exists, possibly minted under an older or wider rule, so asserting
 * today's minimum on it would lock its owner out of the very endpoint that would fix it. Its only bound is a
 * coarse ceiling above every credential the system can hold, so an oversized body cannot turn a KDF run into
 * an amplification lever.
 *
 * Both parameters are `#[SensitiveParameter]`: a stack trace crossing this constructor must not print either
 * plaintext.
 */
final readonly class ChangeMyPasswordRequest
{
    private const int EXISTING_CREDENTIAL_CEILING = 255;

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: self::EXISTING_CREDENTIAL_CEILING)]
        #[SensitiveParameter]
        public string $currentPassword = '',
        #[Assert\NotBlank]
        #[PasswordPolicy]
        #[SensitiveParameter]
        public string $newPassword = '',
    ) {
    }
}
