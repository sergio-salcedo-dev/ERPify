<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Event;

use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankAccountSnapshot::class)]
final class BankAccountSnapshotTest extends TestCase
{
    #[Test]
    public function itRoundTripsEveryFieldThroughPrimitives(): void
    {
        $snapshot = new BankAccountSnapshot(
            '11111111-1111-7000-8000-000000000001',
            'ACTIVE',
            '2026-06-06T00:00:00+00:00',
            '2026-06-07T00:00:00+00:00',
        );

        $primitives = $snapshot->toPrimitives();

        $this->assertSame([
            'bankId' => '11111111-1111-7000-8000-000000000001',
            'status' => 'ACTIVE',
            'createdAt' => '2026-06-06T00:00:00+00:00',
            'updatedAt' => '2026-06-07T00:00:00+00:00',
        ], $primitives);

        $this->assertSame($primitives, BankAccountSnapshot::fromPrimitives($primitives)->toPrimitives());
    }

    #[Test]
    public function itCarriesNoPiiFields(): void
    {
        $snapshot = new BankAccountSnapshot('bank-id', 'CLOSED', 'created', 'updated');

        $this->assertSame(
            ['bankId', 'status', 'createdAt', 'updatedAt'],
            \array_keys($snapshot->toPrimitives()),
        );
    }

    #[Test]
    public function fromPrimitivesCoercesMissingOrNonStringFieldsToEmptyStrings(): void
    {
        $snapshot = BankAccountSnapshot::fromPrimitives([
            'bankId' => 42,
            'status' => null,
            'createdAt' => ['nested'],
        ]);

        $this->assertSame('', $snapshot->bankId);
        $this->assertSame('', $snapshot->status);
        $this->assertSame('', $snapshot->createdAt);
        $this->assertSame('', $snapshot->updatedAt);
    }
}
