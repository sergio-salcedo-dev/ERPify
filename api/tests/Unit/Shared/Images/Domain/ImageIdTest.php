<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Domain;

use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(ImageId::class)]
final class ImageIdTest extends TestCase
{
    public function testGenerateMintsAWellFormedUuid(): void
    {
        $imageId = ImageId::generate();

        $this->assertTrue(Uuid::isValid($imageId->toString()));
    }

    public function testFromStringRoundTripsAWellFormedValue(): void
    {
        $value = Uuid::generate();

        $this->assertSame($value, ImageId::fromString($value)->toString());
    }

    public function testFromStringRejectsAMalformedValueAtTheEdge(): void
    {
        $this->expectException(InvalidUuidException::class);

        ImageId::fromString('not-a-uuid');
    }

    public function testTwoIdsWithTheSameValueAreEqual(): void
    {
        $value = Uuid::generate();

        $this->assertTrue(ImageId::fromString($value)->equals(ImageId::fromString($value)));
    }

    public function testTwoDistinctIdsAreNotEqual(): void
    {
        $this->assertFalse(ImageId::generate()->equals(ImageId::generate()));
    }

    /**
     * The spelling is normalised, because this identifier is read as a VALUE in one place and as a STRING
     * in another and the two must not disagree.
     *
     * `Uuid::isValid()` matches case-insensitively, so `fromString()` accepts upper case; Postgres compares
     * its `uuid` column by value, so the row is found either way; and the storage adapter derives its key
     * by slicing the characters, so the key is not. Left unnormalised, a deletion for an upper-cased id
     * finds no object — a CONFIRMED absence, which is a success — deletes the row, and leaves the bytes
     * behind with nothing referencing them, unreachable for ever.
     *
     * Asserted on the value rather than on `equals()` alone: `equals()` is one of the two readers, and a
     * normalisation that only fixed the comparison would leave the key still sliced from the raw spelling.
     */
    public function testTheSpellingIsNormalisedSoAValueHasExactlyOneStringForm(): void
    {
        $canonical = Uuid::generate();
        $shouted = \strtoupper($canonical);

        $this->assertNotSame($canonical, $shouted, 'the fixture must actually differ, or this proves nothing');
        $this->assertSame($canonical, ImageId::fromString($shouted)->toString());
        $this->assertTrue(ImageId::fromString($shouted)->equals(ImageId::fromString($canonical)));
    }
}
