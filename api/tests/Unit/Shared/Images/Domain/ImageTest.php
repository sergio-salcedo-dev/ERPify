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
use ReflectionProperty;

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
        $this->assertLessThanOrEqual(new DateTimeImmutable(), $image->createdAt());
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
     * 63 hex digits and a newline are 64 characters, and PCRE's `$` matches before a trailing newline —
     * so the length check and an unanchored `$` both pass a value that is not a SHA-256 digest. `digest`
     * is the module's only integrity anchor and it is persisted, so the guard is anchored with `D`.
     */
    public function testADigestPaddedWithANewlineIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Image(ImageId::generate(), \str_repeat('a', 63) . "\n", 'image/png', 10, 10, 100);
    }

    /**
     * The whole surface, and it has to be the whole surface.
     *
     * The earlier version of this read the CONSTRUCTOR SIGNATURE and called it "the complete list of what
     * this aggregate can ever hold". That was never true of a Doctrine entity and is now visibly untrue:
     * `$id` and `$createdAt` are declared in the class body and mapped as columns without appearing in the
     * signature at all. A `private string $conservationContract` written the same way, with its accessor,
     * satisfied a signature check and reached the database — which is exactly the field this test exists
     * to keep out.
     *
     * So both sets are pinned in full: every declared property and every public method. `final` plus a
     * class-level `readonly` still carries the argument that no mutation surface can be added later; what
     * it never carried is the argument that no FIELD can, and those are different claims.
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

        $propertyNames = \array_map(
            static fn (ReflectionProperty $property): string => $property->getName(),
            $reflection->getProperties(),
        );
        \sort($propertyNames);

        $this->assertSame(
            ['byteSize', 'createdAt', 'digest', 'height', 'id', 'mediaType', 'width'],
            $propertyNames,
            'the persisted state is exactly the seven fields; a new property is a new column',
        );

        $methodNames = \array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        \sort($methodNames);

        $this->assertSame(
            ['__construct', 'byteSize', 'createdAt', 'digest', 'height', 'id', 'mediaType', 'width'],
            $methodNames,
            'seven readers and the constructor — no mutator, and no method that classifies the image',
        );
    }
}
