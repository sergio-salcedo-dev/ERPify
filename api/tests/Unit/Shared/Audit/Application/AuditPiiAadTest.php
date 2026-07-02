<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Application;

use Erpify\Shared\Audit\Application\AuditPiiAad;
use Erpify\Shared\Audit\Application\PiiFieldPosition;
use Erpify\Shared\Audit\Domain\Exception\InvalidAuditPiiAad;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuditPiiAad::class)]
final class AuditPiiAadTest extends TestCase
{
    #[Test]
    public function itJoinsFieldPositionAndRowIntoTheByteExactAad(): void
    {
        // The exact bytes are a contract: a reader must rebuild this identically or authentication fails.
        $this->assertSame(
            'holderName|old|018f-row',
            AuditPiiAad::for('holderName', PiiFieldPosition::Old, '018f-row')->toString(),
        );
    }

    #[Test]
    public function theOldAndNewSidesProduceDistinctAads(): void
    {
        $old = AuditPiiAad::for('iban', PiiFieldPosition::Old, 'row-1')->toString();
        $new = AuditPiiAad::for('iban', PiiFieldPosition::New, 'row-1')->toString();

        $this->assertNotSame($old, $new);
    }

    #[Test]
    public function itRejectsAnEmptyField(): void
    {
        $this->expectException(InvalidAuditPiiAad::class);

        AuditPiiAad::for('', PiiFieldPosition::Old, 'row-1');
    }

    #[Test]
    public function itRejectsAnEmptyRowId(): void
    {
        $this->expectException(InvalidAuditPiiAad::class);

        AuditPiiAad::for('iban', PiiFieldPosition::Old, '');
    }

    #[Test]
    public function itRejectsTheSeparatorInAField(): void
    {
        $this->expectException(InvalidAuditPiiAad::class);

        AuditPiiAad::for('holder|name', PiiFieldPosition::Old, 'row-1');
    }

    #[Test]
    public function itRejectsTheSeparatorInTheRowId(): void
    {
        $this->expectException(InvalidAuditPiiAad::class);

        AuditPiiAad::for('iban', PiiFieldPosition::Old, 'row|1');
    }
}
