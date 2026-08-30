<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Infrastructure\Messenger;

use Erpify\Shared\Images\Application\DeleteImage;
use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\Event\ImageDeletionRequested;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Infrastructure\Messenger\DeleteImageOnDeletionRequested;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Unit\Shared\Images\Application\InMemoryImageRepository;
use Erpify\Tests\Unit\Shared\Images\Application\InMemoryImageStorage;
use Erpify\Tests\Unit\Shared\Persistence\Double\ImmediateTransactionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The seam between the queue and the use case — thin, and untested until now, which is how a thin adapter
 * acquires the defect nobody looks for.
 *
 * Two properties live here and nowhere else: the envelope's aggregate id becomes a domain identity rather
 * than travelling on as a string, and a malformed one is refused at that conversion instead of reaching a
 * port. The second is not hypothetical hygiene — the id arrives off a persisted transport, so whatever
 * that column holds is what the handler is handed, and a request nothing can parse must fail loudly rather
 * than be interpreted into a key.
 *
 * @internal
 */
#[CoversClass(DeleteImageOnDeletionRequested::class)]
final class DeleteImageOnDeletionRequestedTest extends TestCase
{
    public function testItReleasesTheBytesAndTheRowForTheIdentityTheEnvelopeCarries(): void
    {
        $storage = new InMemoryImageStorage();
        $repository = new InMemoryImageRepository();
        $image = new Image(ImageId::generate(), \str_repeat('a', 64), 'image/png', 8, 8, 64);
        $storage->store($image->id(), 'canonical bytes');
        $repository->save($image);

        $this->assertNotSame([], $storage->objects, 'seed the object before asserting it is gone');

        $this->handlerFor($storage, $repository)(new ImageDeletionRequested($image->id()->toString()));

        $this->assertSame([], $storage->objects, 'the bytes are gone');
        $this->assertNotInstanceOf(Image::class, $repository->findById($image->id()), 'and so is the row');
    }

    public function testAnEnvelopeCarryingAMalformedIdentityIsRefusedBeforeAnyPortIsTouched(): void
    {
        $storage = new InMemoryImageStorage();
        $repository = new InMemoryImageRepository();
        $bystander = ImageId::generate();
        $storage->store($bystander, 'bytes belonging to somebody else');

        try {
            $this->handlerFor($storage, $repository)(new ImageDeletionRequested('not-an-identifier'));
            $this->fail('a malformed aggregate id must be refused');
        } catch (InvalidUuidException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(
            ['bytes belonging to somebody else'],
            \array_values($storage->objects),
            'nothing was released on the way to the refusal',
        );
    }

    private function handlerFor(
        InMemoryImageStorage $storage,
        InMemoryImageRepository $repository,
    ): DeleteImageOnDeletionRequested {
        return new DeleteImageOnDeletionRequested(
            new DeleteImage($storage, $repository, new ImmediateTransactionManager()),
        );
    }
}
