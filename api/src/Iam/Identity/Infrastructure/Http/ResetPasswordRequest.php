<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use Erpify\Shared\Validation\Infrastructure\PasswordPolicy;
use SensitiveParameter;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body for the reset-password endpoint. `token` is the selector-verifier link `<id>.<secret>`; its
 * bound is a coarse DoS guard well above a legitimate UUID-plus-secret length (the real, opaque check is the
 * constant-time verify in the use case, which also caps the secret it hashes). The password carries
 * {@see PasswordPolicy}, the same one the change and invitation surfaces carry.
 *
 * Both members are `#[SensitiveParameter]`: a stack trace crossing this constructor must print neither the
 * plaintext credential nor the token that authorises replacing it.
 */
final readonly class ResetPasswordRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        #[SensitiveParameter]
        public string $token = '',
        #[Assert\NotBlank]
        #[PasswordPolicy]
        #[SensitiveParameter]
        public string $password = '',
    ) {
    }
}
