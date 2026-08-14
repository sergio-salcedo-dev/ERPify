<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Domain\Exception;

use Erpify\Shared\Audit\Domain\Exception\InvalidAuditRetentionPolicy;
use Erpify\Shared\ErrorContract\Domain\Exception\ClientError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(InvalidAuditRetentionPolicy::class)]
final class InvalidAuditRetentionPolicyTest extends TestCase
{
    #[Test]
    public function aSubOneDayWindowReportsThePolicyTypeWithoutLeakingContext(): void
    {
        $exception = InvalidAuditRetentionPolicy::windowMustBeAtLeastOneDay();

        $this->assertSame('invalid-audit-retention-policy', $exception->type());
        $this->assertSame('Audit retention windows must be at least one day.', $exception->title());
        $this->assertSame([], $exception->context());
    }

    #[Test]
    public function aSecurityWindowThatDoesNotOutliveActivityCarriesBothWindows(): void
    {
        $exception = InvalidAuditRetentionPolicy::securityMustOutliveActivity(90, 90);

        $this->assertSame('invalid-audit-retention-policy', $exception->type());
        $this->assertSame(
            'Audit security retention window must strictly exceed the activity window.',
            $exception->title(),
        );
        $this->assertSame(['activityDays' => 90, 'securityDays' => 90], $exception->context());
    }

    #[Test]
    public function anEmptyExemptionNamesTheLevelWhosePlanLineWouldDeleteTheEvidence(): void
    {
        $exception = InvalidAuditRetentionPolicy::exemptActionsMustNotBeEmpty('security');

        $this->assertSame('invalid-audit-retention-policy', $exception->type());
        $this->assertSame('Audit retention threshold must exempt at least one action.', $exception->title());
        $this->assertSame(['level' => 'security'], $exception->context());
    }

    #[Test]
    public function itIsMarkerLessSoAMisconfiguredWindowSurfacesAsAServerFaultNotA4xx(): void
    {
        $interfaces = (new ReflectionClass(InvalidAuditRetentionPolicy::class))->getInterfaceNames();

        $this->assertNotContains(ClientError::class, $interfaces);
    }
}
