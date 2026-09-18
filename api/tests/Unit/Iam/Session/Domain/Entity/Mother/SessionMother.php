<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Domain\Entity\Mother;

use DateInterval;
use DateTimeImmutable;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Shared\Clock\Domain\SystemClock;

final class SessionMother
{
    public const string DEFAULT_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c6d';

    public const string DEFAULT_USER_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c60';

    public const string DEFAULT_ORG_ID = '0190c1d2-e3f4-7a5b-8c6d-1e2f3a4b5c61';

    public const string DEFAULT_DEVICE = 'Chrome on macOS';

    public const string DEFAULT_IP = '203.0.113.7';

    /**
     * The default window is measured FROM the clock the test is running on, mirroring the `P7D` ceiling
     * {@see \Erpify\Iam\Session\Application\StartSession} mints with; pass `expiresAt` to exercise the
     * caducity boundary.
     */
    public const string DEFAULT_TTL_SPEC = 'P7D';

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
            $expiresAt ?? SystemClock::now()->add(new DateInterval(self::DEFAULT_TTL_SPEC)),
        );
    }
}
