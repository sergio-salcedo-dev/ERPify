<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use SensitiveParameter;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body for minting a recovery secret, mapped through `#[StrictRequestPayload]` so a malformed body —
 * or one carrying a member this endpoint does not implement — is answered 422 `validation-failed` before the
 * use case runs.
 *
 * `currentPassword` is bounded by {@see ExistingCredential} and by nothing else: minting re-proves a
 * credential rather than setting one, so no password policy applies to it.
 *
 * `#[SensitiveParameter]`: a stack trace crossing this constructor must not print the plaintext.
 */
final readonly class MintRecoverySecretRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: ExistingCredential::LENGTH_CEILING)]
        #[SensitiveParameter]
        public string $currentPassword = '',
    ) {
    }
}
