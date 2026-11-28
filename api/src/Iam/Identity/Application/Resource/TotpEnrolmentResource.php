<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application\Resource;

use SensitiveParameter;

/**
 * Wire contract of the ONE response that ever carries a second-factor secret (`POST /me/second-factor/totp`):
 * `secret` in base32 for typing into an authenticator, `provisioningUri` (`otpauth://totp/…`) for a QR code the
 * client renders locally. No other route returns either, and once the factor is confirmed no route can.
 */
final readonly class TotpEnrolmentResource
{
    public function __construct(
        #[SensitiveParameter]
        public string $secret,
        #[SensitiveParameter]
        public string $provisioningUri,
    ) {
    }
}
