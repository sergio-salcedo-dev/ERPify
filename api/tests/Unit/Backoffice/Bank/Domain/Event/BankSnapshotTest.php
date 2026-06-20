<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Domain\Event;

use Erpify\Backoffice\Bank\Domain\Event\BankSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankSnapshot::class)]
final class BankSnapshotTest extends TestCase
{
    #[Test]
    public function itRoundTripsEveryFieldThroughPrimitives(): void
    {
        $snapshot = new BankSnapshot(
            'Acme Savings',
            'ACME',
            '2026-06-06T00:00:00+00:00',
            '2026-06-07T00:00:00+00:00',
            'media-id',
            'logo-hash',
            'object-hash',
            'image/png',
        );

        $primitives = $snapshot->toPrimitives();

        $this->assertSame([
            'name' => 'Acme Savings',
            'shortName' => 'ACME',
            'createdAt' => '2026-06-06T00:00:00+00:00',
            'updatedAt' => '2026-06-07T00:00:00+00:00',
            'logoMediaId' => 'media-id',
            'logoContentHash' => 'logo-hash',
            'storedObjectContentHash' => 'object-hash',
            'storedObjectMimeType' => 'image/png',
        ], $primitives);

        $this->assertSame($primitives, BankSnapshot::fromPrimitives($primitives)->toPrimitives());
    }

    #[Test]
    public function itDefaultsTheOptionalMediaFieldsToNull(): void
    {
        $snapshot = new BankSnapshot('Acme', 'ACME', 'created', 'updated');

        $this->assertNull($snapshot->logoMediaId);
        $this->assertNull($snapshot->logoContentHash);
        $this->assertNull($snapshot->storedObjectContentHash);
        $this->assertNull($snapshot->storedObjectMimeType);
    }

    #[Test]
    public function fromPrimitivesCoercesMissingRequiredFieldsToEmptyStrings(): void
    {
        $snapshot = BankSnapshot::fromPrimitives([]);

        $this->assertSame('', $snapshot->name);
        $this->assertSame('', $snapshot->shortName);
        $this->assertSame('', $snapshot->createdAt);
        $this->assertSame('', $snapshot->updatedAt);
        $this->assertNull($snapshot->logoMediaId);
    }

    #[Test]
    public function fromPrimitivesIgnoresNonStringValues(): void
    {
        $snapshot = BankSnapshot::fromPrimitives([
            'name' => 42,
            'shortName' => null,
            'createdAt' => ['nested'],
            'updatedAt' => false,
            'logoMediaId' => 7,
        ]);

        $this->assertSame('', $snapshot->name);
        $this->assertSame('', $snapshot->shortName);
        $this->assertSame('', $snapshot->createdAt);
        $this->assertSame('', $snapshot->updatedAt);
        $this->assertNull($snapshot->logoMediaId);
    }
}
