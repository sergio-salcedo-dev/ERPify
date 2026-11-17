<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Images\Application\ImageAttachment;
use Erpify\Shared\Images\Application\UploadImage;
use Erpify\Shared\Images\Domain\CanonicalImage;
use Erpify\Shared\Images\Domain\Event\ImageDeletionRequested;
use Erpify\Shared\Images\Domain\Exception\ImageDecodingFailed;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\ImageProcessor;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Unit\Shared\Images\Application\Double\InMemoryImageHolder;
use Erpify\Tests\Unit\Shared\Images\Application\Double\LostAcknowledgementTransactionManager;
use Erpify\Tests\Unit\Shared\Images\Application\Double\OwnerNotFound;
use Erpify\Tests\Unit\Shared\Images\Application\Double\RefusingImageProcessor;
use Erpify\Tests\Unit\Shared\Images\Application\Double\RollbackingTransactionManager;
use Erpify\Tests\Unit\Shared\Images\Application\Double\SavedInUnits;
use Erpify\Tests\Unit\Shared\Images\Application\Double\TrackedImageRepository;
use Erpify\Tests\Unit\Shared\Images\Application\Double\TransactionalEventBus;
use Erpify\Tests\Unit\Shared\Images\Infrastructure\RecordingLogger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The attach protocol over doubles that undo a failed unit of work: what each outcome of the matrix leaves in the
 * holder, in storage and in the queue. The doubles prove the ORDER of the protocol; that the real transaction really
 * takes the queue's row back, and that two writers really serialise, is the functional test's, against Postgres.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
#[CoversClass(ImageAttachment::class)]
final class ImageAttachmentTest extends TestCase
{
    private const string OWNER = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50';

    private RollbackingTransactionManager $transactions;

    private TransactionalEventBus $bus;

    private InMemoryImageStorage $storage;

    private InMemoryImageRepository $rows;

    private SavedInUnits $savedInUnits;

    private RecordingLogger $logger;

    #[Override]
    protected function setUp(): void
    {
        $this->transactions = new RollbackingTransactionManager();
        $this->bus = new TransactionalEventBus($this->transactions);
        $this->savedInUnits = new SavedInUnits();
        $this->storage = new InMemoryImageStorage();
        $this->rows = new InMemoryImageRepository();
        $this->logger = new RecordingLogger();
    }

    #[Test]
    public function replacingStoresTheNewImageAttachesItAndQueuesOneReleaseOfTheOldOne(): void
    {
        $old = ImageId::generate();
        $holder = $this->holder([self::OWNER => $old]);

        $new = $this->attachment()->replace($holder, self::OWNER, 'raw');

        $this->assertTrue($holder->holds(self::OWNER)?->equals($new) ?? false);
        $this->assertCount(1, $this->rows->rows, 'one Image row for the new bytes');
        $this->assertArrayHasKey($new->toString(), $this->storage->objects);
        $this->assertSame([$old->toString()], $this->bus->queuedIds());
        $this->assertContainsOnlyInstancesOf(ImageDeletionRequested::class, $this->bus->published);
    }

    #[Test]
    public function aFirstSetQueuesNothing(): void
    {
        $holder = $this->holder([self::OWNER => null]);

        $new = $this->attachment()->replace($holder, self::OWNER, 'raw');

        $this->assertTrue($holder->holds(self::OWNER)?->equals($new) ?? false);
        $this->assertSame([], $this->bus->queuedIds());
    }

    #[Test]
    public function aFailureAfterTheMintRollsBackAndThenQueuesTheReleaseOfTheNewIdInAFreshUnit(): void
    {
        $old = ImageId::generate();
        $holder = $this->holder([self::OWNER => $old]);
        $holder->attachFails = new RuntimeException('the attach failed after the lock');

        try {
            $this->attachment()->replace($holder, self::OWNER, 'raw');
            $this->fail('the original failure must propagate');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('the attach failed after the lock', $runtimeException->getMessage());
        }

        $minted = \array_key_first($this->storage->objects);
        $this->assertIsString($minted);
        $this->assertTrue($holder->holds(self::OWNER)?->equals($old) ?? false, 'the owner still holds what it held');
        $this->assertSame([$minted], $this->bus->queuedIds(), 'the old id is NOT released, the new one is, once');
        $this->assertSame(3, $this->transactions->started, 'upload, the failed attach, then the compensation');
    }

    #[Test]
    public function anOwnerThatVanishedBeforeTheLockStoresNothingAttachedAndReleasesTheNewId(): void
    {
        $holder = $this->holder([]);

        try {
            $this->attachment()->replace($holder, self::OWNER, 'raw');
            $this->fail('the owner is gone');
        } catch (OwnerNotFound) {
            // expected: the owner's own 404
        }

        $minted = \array_key_first($this->storage->objects);
        $this->assertIsString($minted);
        $this->assertSame([$minted], $this->bus->queuedIds());
        $this->assertNull($holder->holds(self::OWNER));
    }

    #[Test]
    public function aFailedCompensationIsLoggedByClassAndNeverReplacesTheOriginalFailure(): void
    {
        $holder = $this->holder([self::OWNER => null]);
        $holder->attachFails = new RuntimeException('the attach failed');

        $this->bus->failsWith = new RuntimeException('the queue is down, and so is the secret 0190');

        try {
            $this->attachment()->replace($holder, self::OWNER, 'raw');
            $this->fail('the original failure must propagate');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('the attach failed', $runtimeException->getMessage());
        }

        $this->assertCount(1, $this->logger->records);
        $this->assertSame(
            ['event' => 'images.attachment.compensation_failed', 'exceptionClass' => RuntimeException::class],
            $this->logger->records[0]['context'],
        );
        $this->assertStringNotContainsString('secret', \json_encode($this->logger->records, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function aBodyTheProcessorRefusesLeavesNothingStoredAttachedOrQueued(): void
    {
        $old = ImageId::generate();
        $holder = $this->holder([self::OWNER => $old]);

        try {
            $this->attachment(new RefusingImageProcessor())->replace($holder, self::OWNER, 'garbage');
            $this->fail('the processor refusal must propagate');
        } catch (ImageDecodingFailed) {
            // expected: 422 image-invalid
        }

        $this->assertSame([], $this->storage->objects);
        $this->assertSame([], $this->rows->rows);
        $this->assertSame([], $this->bus->queuedIds());
        $this->assertTrue($holder->holds(self::OWNER)?->equals($old) ?? false);
        $this->assertSame([], $holder->lockedInUnits, 'the owner row is never locked for a body that cannot be stored');
    }

    #[Test]
    public function theDeclaredMediaTypeReachesTheProcessorUnchanged(): void
    {
        $processor = new StubImageProcessor(new CanonicalImage('canonical', 'image/png', 4, 4));

        $this->attachment($processor)->replace($this->holder([self::OWNER => null]), self::OWNER, 'raw', 'image/png');

        $this->assertSame([['bytes' => 'raw', 'declaredMediaType' => 'image/png']], $processor->receivedCalls);
    }

    #[Test]
    public function removingClearsTheHolderAndQueuesOneReleaseOfTheHeldId(): void
    {
        $held = ImageId::generate();
        $holder = $this->holder([self::OWNER => $held]);

        $this->attachment()->remove($holder, self::OWNER);

        $this->assertNull($holder->holds(self::OWNER));
        $this->assertSame([$held->toString()], $this->bus->queuedIds());
    }

    #[Test]
    public function removingWhenNothingIsHeldChangesNothingAndQueuesNothing(): void
    {
        $holder = $this->holder([self::OWNER => null]);

        $this->attachment()->remove($holder, self::OWNER);

        $this->assertNull($holder->holds(self::OWNER));
        $this->assertSame([], $this->bus->queuedIds());
    }

    #[Test]
    public function removingFromAnOwnerThatIsGoneRaisesTheOwnersNotFound(): void
    {
        $this->expectException(OwnerNotFound::class);

        $this->attachment()->remove($this->holder([]), self::OWNER);
    }

    #[Test]
    public function aRemovalThatFailsAfterTheClearTakesTheQueuedReleaseWithIt(): void
    {
        $held = ImageId::generate();
        $holder = $this->holder([self::OWNER => $held]);
        $holder->attachFails = new RuntimeException('the clear failed');

        try {
            $this->attachment()->remove($holder, self::OWNER);
            $this->fail('the failure must propagate');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertTrue($holder->holds(self::OWNER)?->equals($held) ?? false);
        $this->assertSame([], $this->bus->queuedIds());
    }

    /**
     * Where the aggregate's row and the image row are written: the upload unit writes the image row and never the
     * owner's, the attach unit takes the owner's row and never an image row. No unit therefore takes both, which is
     * what rules out an image row being locked before the identity row.
     */
    #[Test]
    public function noUnitOfWorkTakesBothTheOwnerRowAndAnImageRow(): void
    {
        $holder = $this->holder([self::OWNER => ImageId::generate()]);

        $this->attachment()->replace($holder, self::OWNER, 'raw');

        $this->assertSame([1], $this->savedInUnits->units, 'the image row is written by the upload unit');
        $this->assertSame([2], $holder->lockedInUnits, 'the owner is locked by the attach unit');
        $this->assertSame([], \array_intersect($this->savedInUnits->units, $holder->lockedInUnits));
    }

    #[Test]
    public function aCommitWhoseAcknowledgementWasLostLeavesTheNewImageAttachedAndReleasesOnlyTheOldOne(): void
    {
        $old = ImageId::generate();
        $holder = $this->holder([self::OWNER => $old]);
        $transactions = new LostAcknowledgementTransactionManager($this->transactions, 2);

        try {
            $this->attachment(null, $transactions)->replace($holder, self::OWNER, 'raw');
            $this->fail('the lost acknowledgement must propagate');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('the commit was acknowledged to nobody', $runtimeException->getMessage());
        }

        $held = $holder->holds(self::OWNER);
        $this->assertInstanceOf(ImageId::class, $held);
        $this->assertSame([$old->toString()], $this->bus->queuedIds(), 'the id the owner points at is not released');
        $this->assertArrayHasKey($held->toString(), $this->storage->objects);
    }

    #[Test]
    public function aCompensationWhoseReReadFailsQueuesNothingAndLeavesTheOriginalFailureStanding(): void
    {
        $holder = $this->holder([self::OWNER => null]);
        $holder->attachFails = new RuntimeException('the attach failed');
        $holder->rereadFails = new RuntimeException('the lock timed out');

        try {
            $this->attachment()->replace($holder, self::OWNER, 'raw');
            $this->fail('the original failure must propagate');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('the attach failed', $runtimeException->getMessage());
        }

        $this->assertSame([], $this->bus->queuedIds());
        $this->assertCount(1, $this->logger->records);
        $this->assertSame(
            ['event' => 'images.attachment.compensation_failed', 'exceptionClass' => RuntimeException::class],
            $this->logger->records[0]['context'],
        );
    }

    /**
     * A writer got in between the commit whose acknowledgement was lost and the compensation's re-read: it replaced
     * the new id and queued it as its previous one, so the compensation queues the same id again. Safe because the
     * deletion is idempotent; what is pinned here is that the owner is NOT left pointing at released bytes.
     */
    #[Test]
    public function aWriterThatReplacedTheNewIdBeforeTheReReadMakesTheCompensationQueueItASecondTime(): void
    {
        $old = ImageId::generate();
        $third = ImageId::generate();
        $holder = $this->holder([self::OWNER => $old]);
        $transactions = new LostAcknowledgementTransactionManager(
            $this->transactions,
            2,
            function () use ($holder, $third): void {
                $minted = \array_key_first($this->storage->objects);
                $this->assertIsString($minted);

                $this->transactions->transactional(function () use ($holder, $third, $minted): void {
                    $holder->attach(self::OWNER, $third);
                    $this->bus->publish(new ImageDeletionRequested($minted));
                });
            },
        );

        try {
            $this->attachment(null, $transactions)->replace($holder, self::OWNER, 'raw');
            $this->fail('the lost acknowledgement must propagate');
        } catch (RuntimeException) {
            // expected
        }

        $minted = \array_key_first($this->storage->objects);
        $this->assertIsString($minted);
        $this->assertTrue($holder->holds(self::OWNER)?->equals($third) ?? false, 'the later writer keeps its id');
        $this->assertSame([$old->toString(), $minted, $minted], $this->bus->queuedIds());
    }

    #[Test]
    public function anOwnerRemovedAfterALostAcknowledgementHoldsNothingSoTheNewIdIsReleased(): void
    {
        $old = ImageId::generate();
        $holder = $this->holder([self::OWNER => $old]);
        $transactions = new LostAcknowledgementTransactionManager(
            $this->transactions,
            2,
            static function () use ($holder): void {
                $holder->remove(self::OWNER);
            },
        );

        try {
            $this->attachment(null, $transactions)->replace($holder, self::OWNER, 'raw');
            $this->fail('the lost acknowledgement must propagate');
        } catch (RuntimeException) {
            // expected
        }

        $minted = \array_key_first($this->storage->objects);
        $this->assertIsString($minted);
        $this->assertSame([$old->toString(), $minted], $this->bus->queuedIds());
    }

    /**
     * @param array<string, ImageId|null> $held
     */
    private function holder(array $held): InMemoryImageHolder
    {
        return new InMemoryImageHolder($held, $this->transactions);
    }

    private function attachment(?ImageProcessor $processor = null, ?TransactionManager $manager = null): ImageAttachment
    {
        $manager ??= $this->transactions;
        $this->storage = new InMemoryImageStorage();
        $this->rows = new InMemoryImageRepository();
        $this->logger = new RecordingLogger();

        return new ImageAttachment(
            new UploadImage(
                $processor ?? new StubImageProcessor(new CanonicalImage('canonical', 'image/png', 4, 4)),
                $this->storage,
                new TrackedImageRepository($this->rows, $this->transactions, $this->savedInUnits),
                $manager,
            ),
            $manager,
            $this->bus,
            $this->logger,
        );
    }
}
