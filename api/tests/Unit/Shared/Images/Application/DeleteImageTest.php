<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Images\Application\DeleteImage;
use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Storage\ImageStorageUnavailable;
use Erpify\Shared\Images\Domain\Storage\StorageOperation;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Every state the handler can meet, because delivery is at-least-once and the upload path accepts orphans:
 * both halves present, only the object, neither, and a lookup that fails rather than answering absent.
 *
 * @internal
 */
#[CoversClass(DeleteImage::class)]
final class DeleteImageTest extends TestCase
{
    public function testWithBothHalvesPresentItRemovesTheObjectAndThenTheRow(): void
    {
        [$storage, $repository, $deleteImage] = $this->deleteImage();
        $image = $this->storedImage($storage, $repository);

        $deleteImage->delete($image->id());

        $this->assertSame([], $storage->objects);
        $this->assertNull($repository->findById($image->id()));
    }

    /**
     * The state the upload path's accepted orphans make inevitable: bytes with no row. The object is still
     * removed, because a request that found no row has not been satisfied until the bytes are gone.
     */
    public function testWithNoRowItStillRemovesTheObject(): void
    {
        [$storage, , $deleteImage] = $this->deleteImage();
        $imageId = ImageId::generate();
        $storage->store($imageId, 'orphaned bytes');

        $deleteImage->delete($imageId);

        $this->assertSame([], $storage->objects);
    }

    public function testWithNeitherHalfPresentItSucceedsWithoutEffect(): void
    {
        [$storage, $repository, $deleteImage] = $this->deleteImage();

        $deleteImage->delete(ImageId::generate());

        $this->assertSame([], $storage->objects);
        $this->assertSame([], $repository->rows);
    }

    /**
     * Two deliveries of the same message leave the same final state. The second must not fail, and must not
     * bring the row back.
     */
    public function testASecondDeliveryOfTheSameRequestChangesNothing(): void
    {
        [$storage, $repository, $deleteImage] = $this->deleteImage();
        $image = $this->storedImage($storage, $repository);

        $deleteImage->delete($image->id());
        $deleteImage->delete($image->id());

        $this->assertSame([], $storage->objects);
        $this->assertSame([], $repository->rows);
    }

    /**
     * A failure while READING the row is not the row being absent. Answering absence there would report an
     * erasure the application never performed.
     */
    public function testALookupFailureIsRaisedRatherThanReadAsAnAbsentRow(): void
    {
        $storage = new InMemoryImageStorage();
        $deleteImage = new DeleteImage($storage, new UnreadableImageRepository(), new ImmediateTransactionManager());

        $this->expectException(RuntimeException::class);
        $deleteImage->delete(ImageId::generate());
    }

    /**
     * Bytes first. A storage failure must stop the sequence with the row intact, so the next delivery can
     * try again — the reverse order would strand an object no record could ever find.
     */
    public function testAStorageFailureLeavesTheRowIntactSoTheRequestStaysRetryable(): void
    {
        $repository = new InMemoryImageRepository();
        $image = new Image(ImageId::generate(), \str_repeat('a', 64), 'image/png', 4, 4, 16);
        $repository->save($image);

        $deleteImage = new DeleteImage(
            new UnavailableImageStorage(),
            $repository,
            new ImmediateTransactionManager(),
        );

        try {
            $deleteImage->delete($image->id());
            self::fail('a storage failure must surface');
        } catch (ImageStorageUnavailable $failure) {
            $this->assertSame(StorageOperation::Delete, $failure->operation());
        }

        $this->assertNotNull($repository->findById($image->id()), 'the row survives, so the work is retryable');
    }

    private function storedImage(InMemoryImageStorage $storage, InMemoryImageRepository $repository): Image
    {
        $image = new Image(ImageId::generate(), \str_repeat('b', 64), 'image/webp', 8, 8, 64);
        $storage->store($image->id(), 'canonical bytes');
        $repository->save($image);

        return $image;
    }

    /**
     * @return array{InMemoryImageStorage, InMemoryImageRepository, DeleteImage}
     */
    private function deleteImage(): array
    {
        $storage = new InMemoryImageStorage();
        $repository = new InMemoryImageRepository();

        return [$storage, $repository, new DeleteImage($storage, $repository, new ImmediateTransactionManager())];
    }
}

