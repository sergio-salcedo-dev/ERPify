<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure;

use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Storage\ImageBytesNotFound;
use Erpify\Shared\Images\Domain\Storage\ImageStorageUnavailable;
use Erpify\Shared\Images\Domain\Storage\StorageFailureCategory;
use Erpify\Shared\Images\Infrastructure\FlysystemImageStorage;
use League\Flysystem\UnableToReadFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A deletion landing between the existence check and the read is a CONFIRMED ABSENCE, not a transient
 * substrate fault, and that difference is the whole promise of the port's three-verdict vocabulary.
 *
 * The window is designed into the module rather than incidental to it: `DeleteImage` unlinks the bytes
 * before deleting the row, precisely so a crash can never leave a row promising bytes that are gone. A
 * reader racing it therefore meets a `read()` failing on an object the pre-check had just found.
 *
 * Untranslated, the library reports it with PHP's own `No such file or directory`, which matches none of the
 * four permanent conditions and so fell through to `ImageStorageUnavailable` — a 503 telling the caller to
 * retry a deletion, and an operator signal pointing at a substrate fault that does not exist. Nothing
 * observed it: every other test of this adapter reaches absence through the pre-check, which answers
 * correctly, so the arm below it was never exercised with the object gone.
 *
 * @internal
 */
#[CoversClass(FlysystemImageStorage::class)]
final class FlysystemImageStorageRacedDeletionTest extends TestCase
{
    use TemporaryImageStorage;

    public function testAnObjectDeletedBetweenTheCheckAndTheReadIsAConfirmedAbsence(): void
    {
        $identifier = ImageId::generate();
        $this->storage()->store($identifier, 'canonical bytes');

        $racing = $this->storage(filesystem: new VanishingFilesystem($this->root));

        $this->expectException(ImageBytesNotFound::class);

        $racing->read($identifier);
    }

    /**
     * The other direction, so the fix is a discrimination rather than a blanket reclassification: a refusal
     * whose object is STILL THERE stays a substrate verdict. Without this, translating every
     * `UnableToReadFile` into an absence would satisfy the case above while reporting a broken disk as an
     * erased image — which is the confusion this vocabulary exists to prevent, inverted.
     */
    public function testARefusalWhoseObjectIsStillThereRemainsASubstrateVerdict(): void
    {
        $identifier = ImageId::generate();
        $this->storage()->store($identifier, 'canonical bytes');

        $refusing = $this->storage(filesystem: FailingFilesystem::raisingFrom(
            'read',
            UnableToReadFile::fromLocation('somewhere', 'Input/output error'),
            $this->root,
        ));

        try {
            $refusing->read($identifier);
            $this->fail('a refusal over an object that is still there must not be reported as an absence');
        } catch (ImageStorageUnavailable $imageStorageUnavailable) {
            $this->assertSame(StorageFailureCategory::Transient, $imageStorageUnavailable->storageFailure());
        }
    }
}
