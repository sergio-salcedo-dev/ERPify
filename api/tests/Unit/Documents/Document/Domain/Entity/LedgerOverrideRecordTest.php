<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Domain\Entity;

use Erpify\Documents\Document\Domain\Entity\LedgerOverrideRecord;
use Erpify\Documents\Document\Domain\Erasure\LedgerOverrideReason;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One use of the ledger override as the aggregate holds it: when, why (a closed reason) and a random reference — and
 * nothing that names the operator. The clearing is a statement, never a method here, so a fresh record is uncleared.
 *
 * @internal
 */
#[CoversClass(LedgerOverrideRecord::class)]
final class LedgerOverrideRecordTest extends TestCase
{
    #[Test]
    public function aRecordHoldsTheReasonTheInstantAndTwoDistinctRandomIdentifiersAndIsUncleared(): void
    {
        $record = LedgerOverrideRecord::set(LedgerOverrideReason::LedgerCorrupt);

        $this->assertSame(LedgerOverrideReason::LedgerCorrupt, $record->reason());
        $this->assertSame(SystemClock::now(), $record->setAt());
        $this->assertNull($record->clearedAt());
        $this->assertTrue(Uuid::isValid($record->id()));
        $this->assertTrue(Uuid::isValid($record->recordRef()));
        $this->assertNotSame($record->id(), $record->recordRef(), 'the printed reference is not the row id');
    }

    #[Test]
    public function twoRecordsNeverShareAReference(): void
    {
        $this->assertNotSame(
            LedgerOverrideRecord::set(LedgerOverrideReason::LedgerLost)->recordRef(),
            LedgerOverrideRecord::set(LedgerOverrideReason::LedgerLost)->recordRef(),
        );
    }
}
