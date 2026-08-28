<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Domain;

use DateTimeImmutable;
use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(Image::class)]
final class ImageTest extends TestCase
{
    public function testConstructingWithValidValuesExposesAllAccessors(): void
    {
        $digest = \hash('sha256', 'quadrant-fixture');
        $id = ImageId::generate();

        $image = new Image($id, $digest, 'image/jpeg', 32, 24, 12345);

        $this->assertTrue($id->equals($image->id()));
        $this->assertSame($digest, $image->digest());
        $this->assertSame('image/jpeg', $image->mediaType());
        $this->assertSame(32, $image->width());
        $this->assertSame(24, $image->height());
        $this->assertSame(12345, $image->byteSize());
        $this->assertLessThanOrEqual(new DateTimeImmutable(), $image->createdAt);
    }

    public function testRejectsADigestShorterThanSixtyFourHexCharacters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Image(ImageId::generate(), 'abc123', 'image/png', 10, 10, 100);
    }

    public function testRejectsADigestWithNonHexCharacters(): void
    {
        $notHex = \str_repeat('g', 64);

        $this->expectException(InvalidArgumentException::class);

        new Image(ImageId::generate(), $notHex, 'image/png', 10, 10, 100);
    }

    public function testRejectsANonPositiveWidth(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Image(ImageId::generate(), \hash('sha256', 'x'), 'image/png', 0, 10, 100);
    }

    public function testRejectsANonPositiveHeight(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Image(ImageId::generate(), \hash('sha256', 'x'), 'image/png', 10, 0, 100);
    }

    public function testRejectsANonPositiveByteSize(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Image(ImageId::generate(), \hash('sha256', 'x'), 'image/png', 10, 10, 0);
    }

    public function testRejectsAnEmptyMediaType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Image(ImageId::generate(), \hash('sha256', 'x'), '   ', 10, 10, 100);
    }

    /**
     * Verified on the observable model, not by reflecting for the absence of a method — the
     * constructor's own signature is the complete list of what this aggregate can ever hold, and it
     * carries no conservation-contract / classification field. `final` + a class-level `readonly`
     * modifier is what makes it true by construction: nothing here can add a mutation surface later.
     */
    public function testHasNoConservationContractSurfaceByConstruction(): void
    {
        $reflection = new ReflectionClass(Image::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());

        $constructor = $reflection->getConstructor();
        $this->assertInstanceOf(ReflectionMethod::class, $constructor);

        $parameterNames = \array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters(),
        );

        $this->assertSame(['id', 'digest', 'mediaType', 'width', 'height', 'byteSize'], $parameterNames);
    }
}
