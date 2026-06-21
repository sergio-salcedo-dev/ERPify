<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Erpify\Shared\Search\Domain\SortDirection;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\CursorPositionExtractor;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\Keyset\OrderByColumns;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * @internal
 */
#[CoversClass(CursorPositionExtractor::class)]
final class CursorPositionExtractorTest extends TestCase
{
    private CursorPositionExtractor $extractor;

    #[Override]
    protected function setUp(): void
    {
        // No kernel: the accessor is injected, never instantiated per call.
        $this->extractor = new CursorPositionExtractor(PropertyAccess::createPropertyAccessor());
    }

    #[Test]
    public function itExtractsScalarBoundaryValuesFromAnArrayRow(): void
    {
        $position = $this->extractor->extract(
            OrderByColumns::fromPrimarySort('name', SortDirection::ASC),
            ['name' => 'BBVA', 'id' => 'u-1'],
        );

        $this->assertSame(['name' => 'BBVA', 'id' => 'u-1'], $position);
    }

    #[Test]
    public function itTruncatesADatetimeToColumnPrecisionDroppingMicroseconds(): void
    {
        $createdAt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s.u',
            '2026-06-10 12:30:45.123456',
            new DateTimeZone('UTC'),
        );

        $position = $this->extractor->extract(
            OrderByColumns::fromPrimarySort('createdAt', SortDirection::ASC),
            ['createdAt' => $createdAt, 'id' => 'u-1'],
        );

        $this->assertSame(['createdAt' => '2026-06-10T12:30:45+00:00', 'id' => 'u-1'], $position);
    }

    #[Test]
    public function itNormalizesADatetimeToUtc(): void
    {
        $createdAt = DateTimeImmutable::createFromFormat(
            DateTimeInterface::ATOM,
            '2026-06-10T14:30:45+02:00',
        );

        $position = $this->extractor->extract(
            OrderByColumns::fromPrimarySort('createdAt', SortDirection::ASC),
            ['createdAt' => $createdAt, 'id' => 'u-1'],
        );

        $this->assertSame(['createdAt' => '2026-06-10T12:30:45+00:00', 'id' => 'u-1'], $position);
    }

    #[Test]
    public function itReadsObjectRowsAndStripsTheDqlAliasPrefix(): void
    {
        $row = new class {
            public string $name = 'BBVA';

            public string $id = 'u-1';
        };

        $position = $this->extractor->extract(
            OrderByColumns::fromSorts([['column' => 'bank.name', 'direction' => SortDirection::ASC]]),
            $row,
        );

        $this->assertSame(['bank.name' => 'BBVA', 'id' => 'u-1'], $position);
    }

    #[Test]
    public function itStringifiesAStringableBoundaryValue(): void
    {
        $id = new class implements Stringable {
            #[Override]
            public function __toString(): string
            {
                return 'uuid-as-string';
            }
        };

        $position = $this->extractor->extract(
            OrderByColumns::tieBreakOnly(),
            ['id' => $id],
        );

        $this->assertSame(['id' => 'uuid-as-string'], $position);
    }
}
