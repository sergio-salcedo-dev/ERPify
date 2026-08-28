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
 * `currentPassword` carries no password policy, for the reason {@see ChangeMyPasswordRequest} gives about its
 * own: it is a credential that already exists, possibly minted under an older or wider rule, and asserting
 * today's minimum on it would refuse the very people the rule was widened for. Its only bound is a coarse
 * ceiling above every credential the system can hold, so an oversized body cannot turn a KDF run into an
 * amplification lever.
 *
 * `#[SensitiveParameter]`: a stack trace crossing this constructor must not print the plaintext.
 */
final readonly class MintRecoverySecretRequest
{
    private const int EXISTING_CREDENTIAL_CEILING = 255;

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: self::EXISTING_CREDENTIAL_CEILING)]
        #[SensitiveParameter]
        public string $currentPassword = '',
    ) {
    }
}
