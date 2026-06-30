<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Domain;

use DateTimeImmutable;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditRetentionPolicy;
use Erpify\Shared\Audit\Domain\AuditRetentionThreshold;
use Erpify\Shared\Audit\Domain\Exception\InvalidAuditRetentionPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuditRetentionPolicy::class)]
#[CoversClass(AuditRetentionThreshold::class)]
final class AuditRetentionPolicyTest extends TestCase
{
    private const string NOW = '2026-06-25 12:00:00';

    #[Test]
    public function itPlansOneCutoffPerLevelAtNowMinusItsWindow(): void
    {
        $policy = new AuditRetentionPolicy(activityRetentionDays: 90, securityRetentionDays: 365);
        $now = new DateTimeImmutable(self::NOW);

        $activity = $this->cutoffFor($policy, AuditLevel::ACTIVITY, $now);
        $security = $this->cutoffFor($policy, AuditLevel::SECURITY, $now);

        $this->assertSame('2026-03-27 12:00:00', $activity->format('Y-m-d H:i:s'));
        $this->assertSame('2025-06-25 12:00:00', $security->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function itPlansNoCeilingCutoffForChangeRows(): void
    {
        $policy = new AuditRetentionPolicy(activityRetentionDays: 90, securityRetentionDays: 365);

        $plannedLevels = \array_map(
            static fn (AuditRetentionThreshold $threshold): AuditLevel => $threshold->level,
            $policy->thresholdsAt(new DateTimeImmutable(self::NOW)),
        );

        $this->assertNotContains(
            AuditLevel::CHANGE,
            $plannedLevels,
            'change rows are compliance evidence governed by a retention floor, not the privacy ceiling',
        );
    }

    #[Test]
    public function itKeepsSecurityRowsLongerThanActivityRows(): void
    {
        $policy = new AuditRetentionPolicy(activityRetentionDays: 90, securityRetentionDays: 365);
        $now = new DateTimeImmutable(self::NOW);

        $this->assertLessThan(
            $this->cutoffFor($policy, AuditLevel::ACTIVITY, $now),
            $this->cutoffFor($policy, AuditLevel::SECURITY, $now),
            'the security cutoff reaches further into the past, so security rows survive longer',
        );
    }

    #[Test]
    public function itRejectsASecurityWindowThatDoesNotExceedActivity(): void
    {
        $this->expectException(InvalidAuditRetentionPolicy::class);

        new AuditRetentionPolicy(activityRetentionDays: 90, securityRetentionDays: 90);
    }

    #[Test]
    public function itRejectsAWindowBelowOneDay(): void
    {
        $this->expectException(InvalidAuditRetentionPolicy::class);

        new AuditRetentionPolicy(activityRetentionDays: 0, securityRetentionDays: 365);
    }

    private function cutoffFor(
        AuditRetentionPolicy $policy,
        AuditLevel $level,
        DateTimeImmutable $now,
    ): DateTimeImmutable {
        foreach ($policy->thresholdsAt($now) as $auditRetentionThreshold) {
            if ($auditRetentionThreshold->level === $level) {
                return $auditRetentionThreshold->deleteBefore;
            }
        }

        $this->fail('no retention threshold planned for level ' . $level->value);
    }
}
