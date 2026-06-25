<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Domain;

use DateTimeImmutable;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditRetentionPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuditRetentionPolicy::class)]
final class AuditRetentionPolicyTest extends TestCase
{
    private const string NOW = '2026-06-25 12:00:00';

    #[Test]
    public function itResolvesEachLevelThresholdAsNowMinusItsWindow(): void
    {
        $policy = new AuditRetentionPolicy(activityRetentionDays: 90, securityRetentionDays: 365);
        $now = new DateTimeImmutable(self::NOW);

        $this->assertSame(
            '2026-03-27 12:00:00',
            $policy->thresholdFor(AuditLevel::ACTIVITY, $now)->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2025-06-25 12:00:00',
            $policy->thresholdFor(AuditLevel::SECURITY, $now)->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function itKeepsSecurityRowsLongerThanActivityRows(): void
    {
        $policy = new AuditRetentionPolicy(activityRetentionDays: 90, securityRetentionDays: 365);
        $now = new DateTimeImmutable(self::NOW);

        $this->assertLessThan(
            $policy->thresholdFor(AuditLevel::ACTIVITY, $now),
            $policy->thresholdFor(AuditLevel::SECURITY, $now),
            'the security threshold reaches further into the past, so security rows survive longer',
        );
    }

    #[Test]
    public function itRejectsASecurityWindowThatDoesNotExceedActivity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuditRetentionPolicy(activityRetentionDays: 90, securityRetentionDays: 90);
    }

    #[Test]
    public function itRejectsAWindowBelowOneDay(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AuditRetentionPolicy(activityRetentionDays: 0, securityRetentionDays: 365);
    }
}
