<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Http;

use SensitiveParameter;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request payload for {@see AcceptInvitationController}, mapped from the JSON body via `#[StrictRequestPayload]`
 * (a failure to satisfy these constraints is answered 422 `validation-failed` before the use case runs). The
 * `#[Assert]` attributes are passive validation metadata. The opaque `<invitationId>.<secret>` token is only
 * shape-capped here — its eligibility is the domain's call, uniformly opaque across the five dead-token cases.
 * The password policy mirrors the PWA (`passwordPolicy.ts`: 8..128) so both ends reject identically.
 */
final readonly class AcceptInvitationRequest
{
    public function __construct(
        #[SensitiveParameter]
        #[Assert\NotBlank(message: 'The token is required.')]
        #[Assert\Length(max: 512, maxMessage: 'The token is malformed.')]
        public string $token = '',
        #[SensitiveParameter]
        #[Assert\NotBlank(message: 'A password is required.')]
        #[Assert\Length(
            min: 8,
            max: 128,
            minMessage: 'The password must be at least {{ limit }} characters.',
            maxMessage: 'The password must not exceed {{ limit }} characters.',
        )]
        public string $password = '',
    ) {
    }
}
