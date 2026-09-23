<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Search\Domain;

use DateTimeImmutable;
use Erpify\Shared\Search\Domain\StrictRangeBound;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(StrictRangeBound::class)]
final class StrictRangeBoundTest extends TestCase
{
    #[Test]
    #[DataProvider('provideItAcceptsACanonicalBoundAndNormalisesItToUtcCases')]
    public function itAcceptsACanonicalBoundAndNormalisesItToUtc(string $value, string $expectedUtc): void
    {
        $bound = StrictRangeBound::parse($value);

        $this->assertInstanceOf(DateTimeImmutable::class, $bound);
        $this->assertSame($expectedUtc, $bound->format('Y-m-d\TH:i:s.uP'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideItAcceptsACanonicalBoundAndNormalisesItToUtcCases(): iterable
    {
        yield 'ATOM with an explicit offset' => ['2026-03-01T13:57:00+02:00', '2026-03-01T11:57:00.000000+00:00'];
        yield 'ATOM spelled with Z' => ['2026-03-01T11:57:00Z', '2026-03-01T11:57:00.000000+00:00'];
        yield 'the millisecond form a JS client emits' => [
            '2026-03-01T11:57:00.123Z',
            '2026-03-01T11:57:00.123000+00:00',
        ];
        yield 'the microsecond form the audit timeline carries' => [
            '2026-03-01T11:57:00.123456+00:00',
            '2026-03-01T11:57:00.123456+00:00',
        ];

        // The inclusive edges of the real-world offset span. Nothing pinned them before: the appliers'
        // own suites test `+25:00` and `-13:00`, which are outside it, so `<=`/`>=` could have flipped to
        // `<`/`>` with every assertion still green.
        yield 'the easternmost real offset, inclusive' => [
            '2026-03-01T13:57:00+14:00',
            '2026-02-28T23:57:00.000000+00:00',
        ];
        yield 'the westernmost real offset, inclusive' => [
            '2026-03-01T13:57:00-12:00',
            '2026-03-02T01:57:00.000000+00:00',
        ];

        // The lowest year the database stores. Its neighbour below is the one refusal case that reached
        // the driver before this gate existed.
        yield 'the earliest storable year' => ['0001-01-01T00:00:00+00:00', '0001-01-01T00:00:00.000000+00:00'];
        yield 'a far-future year that still stores' => [
            '9999-12-31T23:59:59+00:00',
            '9999-12-31T23:59:59.000000+00:00',
        ];
    }

    #[Test]
    #[DataProvider('provideItRefusesABoundItCannotHonourCases')]
    public function itRefusesABoundItCannotHonour(string $value): void
    {
        $this->assertNull(StrictRangeBound::parse($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideItRefusesABoundItCannotHonourCases(): iterable
    {
        yield 'malformed' => ['not-a-date'];
        yield 'a relative form the lenient constructor would take' => ['now'];
        yield 'a date with no time' => ['2026-01-01'];
        // Parses warning-free — `createFromFormat` tolerates a missing leading zero — and only the
        // byte-identical round-trip refuses it.
        yield 'non-canonical single-digit month' => ['2026-6-01T00:00:00+00:00'];
        yield 'trailing data past a canonical prefix' => ['2026-01-01T00:00:00+00:00 and more'];
        // Makes `createFromFormat` throw rather than return, which is why the parse catches.
        yield 'a null byte' => ["2026-01-01T00:00:00+00:00\0evil"];
        yield 'an offset east of any real one' => ['2026-01-01T00:00:00+25:00'];
        yield 'an offset west of any real one' => ['2026-01-01T00:00:00-13:00'];
        yield 'an offset PHP parses but the world does not have' => ['2026-01-01T00:00:00+99:00'];
        yield 'the year the database calendar lacks' => ['0000-01-01T00:00:00+00:00'];
        yield 'empty' => [''];
    }

    /**
     * Both appliers depend on this: a bound that arrives already in UTC must not be shifted by the
     * normalisation, and one that does not must be — the audit timeline stores `occurred_on` in UTC and
     * compares against it directly.
     */
    #[Test]
    public function itLeavesAnInstantAlreadyInUtcWhereItIs(): void
    {
        $bound = StrictRangeBound::parse('2026-03-01T11:57:00+00:00');

        $this->assertInstanceOf(DateTimeImmutable::class, $bound);
        $this->assertSame('UTC', $bound->getTimezone()->getName());
        $this->assertSame(0, $bound->getOffset());
    }
}
