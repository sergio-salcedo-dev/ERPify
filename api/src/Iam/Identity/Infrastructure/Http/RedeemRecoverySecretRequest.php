<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use SensitiveParameter;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body for redeeming a recovery secret. `secret` is the whole `<selector>.<secret>` the owner was
 * shown once; it travels in the BODY and never in the query string, because a URL is the one place a value
 * reaches the access log, the browser history and any referrer before the application has looked at it.
 *
 * The length bound is a coarse DoS guard well above a legitimate UUID-plus-secret, mirroring
 * {@see ResetPasswordRequest}. The real check is the constant-time verify in the use case, which caps the
 * secret it hashes on its own.
 *
 * `#[SensitiveParameter]`: this member is a credential, and a stack trace crossing this constructor must not
 * print it.
 */
final readonly class RedeemRecoverySecretRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        #[SensitiveParameter]
        public string $secret = '',
    ) {
    }
}
