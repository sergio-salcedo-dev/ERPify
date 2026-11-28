<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use SensitiveParameter;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body for confirming a TOTP enrolment, mapped through `#[StrictRequestPayload]`.
 *
 * Only presence and an upper bound are validated here. Whether the value is six digits is the algorithm's
 * question, answered with the same refusal as a wrong code, so the 422 never grades how close a guess came.
 *
 * `#[SensitiveParameter]`: a code is a credential for the length of its window.
 */
final readonly class ConfirmTotpFactorRequest
{
    public const int CODE_LENGTH_CEILING = 32;

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: self::CODE_LENGTH_CEILING)]
        #[SensitiveParameter]
        public string $code = '',
    ) {
    }
}
