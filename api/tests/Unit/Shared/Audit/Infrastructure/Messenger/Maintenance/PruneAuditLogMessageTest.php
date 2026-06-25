<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Messenger\Maintenance;

use DateTimeImmutable;
use Erpify\Shared\Audit\Domain\AuditRetentionPolicy;
use Erpify\Shared\Audit\Infrastructure\Messenger\Maintenance\PruneAuditLogMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PruneAuditLogMessage::class)]
final class PruneAuditLogMessageTest extends TestCase
{
    #[Test]
    public function itDefaultsToAQuarterForActivityAndAYearForSecurity(): void
    {
        $message = new PruneAuditLogMessage();

        $this->assertSame(90, $message->activityRetentionDays);
        $this->assertSame(365, $message->securityRetentionDays);
    }

    #[Test]
    public function itCarriesExplicitlyConfiguredWindows(): void
    {
        $message = new PruneAuditLogMessage(activityRetentionDays: 30, securityRetentionDays: 400);

        $this->assertSame(30, $message->activityRetentionDays);
        $this->assertSame(400, $message->securityRetentionDays);
    }

    #[Test]
    public function itsDefaultWindowsFormALegalRetentionPolicy(): void
    {
        $message = new PruneAuditLogMessage();

        $policy = new AuditRetentionPolicy($message->activityRetentionDays, $message->securityRetentionDays);

        $this->assertCount(2, $policy->thresholdsAt(new DateTimeImmutable('2026-06-25 12:00:00')));
    }
}
