<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body for the reset-password endpoint. `token` is the selector-verifier link `<id>.<secret>`; its
 * bound is a coarse DoS guard well above a legitimate UUID-plus-secret length (the real, opaque check is the
 * constant-time verify in the use case, which also caps the secret it hashes). The password policy is inlined
 * here for lack of a shared one on this branch; it should be reconciled with the invitation-acceptance
 * password constraint when the two surfaces meet.
 */
final readonly class ResetPasswordRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $token = '',
        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 255)]
        public string $password = '',
    ) {
    }
}
