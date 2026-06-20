<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Infrastructure\Messenger\Maintenance;

use Erpify\Shared\Event\Infrastructure\Messenger\Maintenance\PruneHandledDomainEventsMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(PruneHandledDomainEventsMessage::class)]
final class PruneHandledDomainEventsMessageTest extends TestCase
{
    #[Test]
    public function itDefaultsToAThirtyDayRetentionWindow(): void
    {
        $this->assertSame(30, (new PruneHandledDomainEventsMessage())->retentionDays);
    }

    #[Test]
    public function itAcceptsACustomRetentionWindow(): void
    {
        $this->assertSame(7, (new PruneHandledDomainEventsMessage(7))->retentionDays);
    }
}
