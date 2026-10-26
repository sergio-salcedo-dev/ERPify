<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Event\Domain\Exception;

use Erpify\Shared\Event\Domain\Exception\CorruptEventStoreRow;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(CorruptEventStoreRow::class)]
final class CorruptEventStoreRowTest extends TestCase
{
    public function testCarriesTheOffendingColumn(): void
    {
        $exception = CorruptEventStoreRow::nonNumericColumn('sequence');

        $this->assertSame('corrupt-event-store-row', $exception->type());
        $this->assertSame(['column' => 'sequence'], $exception->context());
    }
}
