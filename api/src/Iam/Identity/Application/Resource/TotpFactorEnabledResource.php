<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Application\Resource;

/**
 * Wire contract of a confirmed enrolment (`POST /me/second-factor/totp/confirm`): when the factor took effect.
 * It carries nothing about the secret.
 */
final readonly class TotpFactorEnabledResource
{
    public function __construct(
        public string $enabledAt,
    ) {
    }
}
