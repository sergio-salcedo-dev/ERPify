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
}
