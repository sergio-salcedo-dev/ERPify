<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother;

use DateTimeImmutable;
use Erpify\Iam\Session\Domain\Entity\Session;

final class SessionMother
{
    public const string DEFAULT_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c6d';

    public const string DEFAULT_USER_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c60';

    public const string DEFAULT_ORG_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c61';

    public const string DEFAULT_DEVICE = 'Chrome on macOS';

    public const string DEFAULT_IP = '203.0.113.7';

    /**
     * The far-future default keeps a session admissible by time so a test that does not care about expiry never
     * trips the temporal predicate; pass `expiresAt` to exercise the caducity boundary.
     */
    public const string DEFAULT_EXPIRES_AT = '2099-01-01T00:00:00+00:00';

    public static function active(
        string $id = self::DEFAULT_ID,
        string $userId = self::DEFAULT_USER_ID,
        string $organizationId = self::DEFAULT_ORG_ID,
        ?DateTimeImmutable $expiresAt = null,
        string $device = self::DEFAULT_DEVICE,
        ?string $ip = self::DEFAULT_IP,
    ): Session {
        return Session::start(
            $id,
            $userId,
            $organizationId,
            $device,
            $ip,
            $expiresAt ?? new DateTimeImmutable(self::DEFAULT_EXPIRES_AT),
        );
    }
}
