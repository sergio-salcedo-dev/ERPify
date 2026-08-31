<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use SensitiveParameter;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body for revoking a recovery secret, mapped through `#[StrictRequestPayload]` so a malformed body —
 * or one carrying a member this endpoint does not implement — is answered 422 `validation-failed` before the
 * use case runs.
 *
 * **This endpoint has a body at all because the proof it demands may not travel anywhere else.** The access
 * log is written before the application has validated anything, and it keeps every request header but
 * `Referer`, plus the URL path; that sink has no TTL and no erasure owner, so a credential carried in a header
 * is retained there in clear by any request that reaches the edge, and one carried in a query string escapes
 * only while its `?` is spelled literally. A body is the one carrier that stops at the controller.
 *
 * `currentPassword` is bounded by {@see ExistingCredential} and by nothing else: revocation re-proves a
 * credential rather than setting one, so no password policy applies to it.
 *
 * `#[SensitiveParameter]`: a stack trace crossing this constructor must not print the plaintext.
 */
final readonly class RevokeRecoverySecretRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: ExistingCredential::LENGTH_CEILING)]
        #[SensitiveParameter]
        public string $currentPassword = '',
    ) {
    }
}
