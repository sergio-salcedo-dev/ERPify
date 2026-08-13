<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Domain;

use DateTimeImmutable;
use Erpify\Shared\Audit\Domain\AuditErasureEvidence;
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
    public function itPlansAFiveYearComplianceFloorForChangeRows(): void
    {
        $policy = new AuditRetentionPolicy(activityRetentionDays: 90, securityRetentionDays: 365);

        $change = $this->cutoffFor($policy, AuditLevel::CHANGE, new DateTimeImmutable(self::NOW));

        $this->assertSame(
            '2021-06-25 12:00:00',
            $change->format('Y-m-d H:i:s'),
            'change rows are kept at least the five-year legal minimum before becoming prune-eligible',
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

    /**
     * The exemption is carried on **every** line, and only a test says so. Scoping it back to the level that
     * happens to hold the evidence today would leave every functional test green — they seed `security` rows
     * — while quietly restoring the hole the moment an evidence action is written anywhere else.
     */
    public function testEveryPlannedThresholdCarriesTheErasureEvidenceExemption(): void
    {
        $plan = (new AuditRetentionPolicy(90, 365))->thresholdsAt(new DateTimeImmutable('2026-06-25T00:00:00+00:00'));

        $this->assertCount(\count(AuditLevel::cases()), $plan, 'one line per level, so none can be skipped');

        foreach ($plan as $threshold) {
            $this->assertSame(
                AuditErasureEvidence::ACTIONS,
                $threshold->exemptActions,
                'level ' . $threshold->level->value . " would prune the trail's own erasure evidence",
            );
        }
    }

    /**
     * The exemption is a deletion instruction, so an empty one is a licence to delete the evidence rather
     * than a threshold that happens to exempt nothing. Binding it empty is not the loud failure it looks
     * like either: DBAL renders an empty list parameter as the literal `NULL`, so the predicate becomes
     * unknown for every row and the prune deletes nothing while reporting success.
     */
    #[Test]
    public function itRejectsAThresholdThatExemptsNothing(): void
    {
        $this->expectException(InvalidAuditRetentionPolicy::class);

        // @phpstan-ignore new.resultUnused, argument.type (empty on purpose, to exercise the runtime guard)
        new AuditRetentionThreshold(AuditLevel::SECURITY, new DateTimeImmutable(self::NOW), []);
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
