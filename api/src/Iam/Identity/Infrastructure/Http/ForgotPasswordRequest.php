<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Http;

use SensitiveParameter;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request body for the forgot-password endpoint. A blank or malformed email is a 422 at mapping time — a
 * format error, orthogonal to whether the address belongs to an account, so it leaks no existence. A
 * well-formed but unknown email passes validation and reaches the uniform use case.
 */
final readonly class ForgotPasswordRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT)]
        #[SensitiveParameter]
        public string $email = '',
    ) {
    }
}
