<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application\Audit;

use DateTimeInterface;
use Erpify\Backoffice\BankAccount\Application\Audit\BankAccountsViewedAuditEvent;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Clock\Infrastructure\SymfonyClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * @internal
 */
#[CoversClass(BankAccountsViewedAuditEvent::class)]
final class BankAccountsViewedAuditEventTest extends TestCase
{
    private const string BANK_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    protected function tearDown(): void
    {
        SystemClock::reset();

        parent::tearDown();
    }

    public function testRecordStampsTheBankIdAndTheAmbientInstant(): void
    {
        $instant = '2026-06-16T12:34:56+00:00';
        SystemClock::set(new SymfonyClock(new MockClock($instant)));

        $event = BankAccountsViewedAuditEvent::record(self::BANK_ID);

        $this->assertSame(self::BANK_ID, $event->bankId);
        $this->assertSame($instant, $event->occurredOn->format(DateTimeInterface::ATOM));
    }
}
