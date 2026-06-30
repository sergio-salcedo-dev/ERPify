<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(Bank::class)]
final class BankAuditResourceTest extends TestCase
{
    public function testItDeclaresItsRegulatoryAuditResourceIdentity(): void
    {
        $resource = BankMother::create()->auditResource();

        $this->assertSame('Bank', $resource->type);
        $this->assertSame(BankMother::DEFAULT_ID, $resource->id);
    }
}
