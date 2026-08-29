<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Images\Application\UploadImage;
use Erpify\Shared\Images\Domain\CanonicalImage;
use Erpify\Shared\Images\Domain\ImageProcessor;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(UploadImage::class)]
final class UploadImageTest extends TestCase
{
    public function testAssemblesAnImageFromTheProcessorsCanonicalOutput(): void
    {
        $canonicalImage = new CanonicalImage('canonical-bytes', 'image/png', 10, 20);
        $processor = new StubImageProcessor($canonicalImage);
        $uploadImage = $this->uploadImageWith($processor);

        $image = $uploadImage->upload('raw-bytes');

        $this->assertSame($canonicalImage->digest, $image->digest());
        $this->assertSame($canonicalImage->mediaType, $image->mediaType());
        $this->assertSame($canonicalImage->width, $image->width());
        $this->assertSame($canonicalImage->height, $image->height());
        $this->assertSame($canonicalImage->byteSize, $image->byteSize());
    }

    /**
     * The module mints the id internally — nothing a caller passes in ever reaches the
     * processor, because the public signature has no parameter for one to travel through.
     */
    public function testGeneratesADistinctIdInternallyOnEveryUpload(): void
    {
        $canonicalImage = new CanonicalImage('canonical-bytes', 'image/png', 10, 20);
        $uploadImage = $this->uploadImageWith(new StubImageProcessor($canonicalImage));

        $first = $uploadImage->upload('raw-bytes');
        $second = $uploadImage->upload('raw-bytes');

        $this->assertFalse($first->id()->equals($second->id()));
    }

    /**
     * No signature anywhere in this class accepts a conservation-contract parameter — the
     * only two arguments {@see UploadImage::upload()} declares are bytes and an optional declared
     * media type.
     */
    public function testUploadAcceptsOnlyBytesAndAnOptionalDeclaredMediaType(): void
    {
        $reflection = new ReflectionMethod(UploadImage::class, 'upload');

        $parameterNames = \array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $reflection->getParameters(),
        );

        $this->assertSame(['bytes', 'declaredMediaType'], $parameterNames);
    }

    public function testForwardsTheDeclaredMediaTypeToTheProcessorUnchanged(): void
    {
        $canonicalImage = new CanonicalImage('canonical-bytes', 'image/jpeg', 10, 20);
        $processor = new StubImageProcessor($canonicalImage);
        $uploadImage = $this->uploadImageWith($processor);

        $uploadImage->upload('raw-bytes', 'image/jpeg');

        $this->assertSame(
            [['bytes' => 'raw-bytes', 'declaredMediaType' => 'image/jpeg']],
            $processor->receivedCalls,
        );
    }

    public function testForwardsANullDeclaredMediaTypeAsIs(): void
    {
        $canonicalImage = new CanonicalImage('canonical-bytes', 'image/jpeg', 10, 20);
        $processor = new StubImageProcessor($canonicalImage);
        $uploadImage = $this->uploadImageWith($processor);

        $uploadImage->upload('raw-bytes');

        $this->assertSame([['bytes' => 'raw-bytes', 'declaredMediaType' => null]], $processor->receivedCalls);
    }

    /**
     * The collaborators the storage and persistence steps need. Cases here are about assembling the
     * aggregate, so they take working in-memory implementations of the ports and assert nothing about them.
     */
    private function uploadImageWith(ImageProcessor $processor): UploadImage
    {
        return new UploadImage(
            $processor,
            new InMemoryImageStorage(),
            new InMemoryImageRepository(),
            new ImmediateTransactionManager(),
        );
    }
}
