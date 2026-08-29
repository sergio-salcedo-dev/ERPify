<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Images;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Images\Application\DeleteImage;
use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Erpify\Shared\Images\Domain\Storage\ImageStorageException;
use Erpify\Shared\Images\Infrastructure\FlysystemImageStorage;
use Erpify\Shared\Images\Infrastructure\Persistence\Doctrine\DoctrineImageRepository;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Unit\Shared\Images\Infrastructure\RecordingLogger;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The deletion protocol over BOTH real substrates at once — a temporary filesystem and Postgres — because
 * the property it delivers is precisely that the two halves are not atomic and the order between them is
 * the only guarantee available.
 *
 * The unit test beside it pins the same states against doubles, which is what makes each state readable;
 * what it cannot show is that a real repository answers `null` for an absent row and raises for a failed
 * lookup, or that a real filesystem's idempotent absence is the one the use case leans on. Delivery is
 * at-least-once, so "the same message twice" is not an edge case here — it is the ordinary Monday.
 *
 * Each case runs inside a transaction that is always rolled back: the suite has no auto-rollback and
 * shares the dev database connection.
 *
 * @internal
 */
#[CoversClass(DeleteImage::class)]
final class DeleteImageIntegrationTest extends KernelTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = \sys_get_temp_dir() . '/erpify-delete-image-' . \bin2hex(\random_bytes(6));
        \mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->root));

        parent::tearDown();
    }

    public function testBothHalvesArePresentAndBothAreGone(): void
    {
        $this->withDeletion(function (
            DeleteImage $deleteImage,
            ImageStorage $storage,
            EntityManagerInterface $entityManager,
        ): void {
            $identifier = $this->seed($storage, $entityManager);

            $deleteImage->delete($identifier);
            $entityManager->clear();

            $this->assertObjectIsGone($storage, $identifier);
            $this->assertNotInstanceOf(Image::class, $this->rowFor($entityManager, $identifier));
        });
    }

    /**
     * The state the accepted orphans of the upload path make inevitable: a stored object whose row never
     * committed. The request must still release the bytes.
     */
    public function testAnObjectWithNoRowIsStillReleased(): void
    {
        $this->withDeletion(function (
            DeleteImage $deleteImage,
            ImageStorage $storage,
            EntityManagerInterface $entityManager,
        ): void {
            $identifier = ImageId::generate();
            $storage->store($identifier, 'orphaned canonical bytes');
            $this->assertSame(
                'orphaned canonical bytes',
                $storage->read($identifier),
                'seed the object before asserting it is gone',
            );
            $this->assertNotInstanceOf(
                Image::class,
                $this->rowFor($entityManager, $identifier),
                'and there is genuinely no row',
            );

            $deleteImage->delete($identifier);

            $this->assertObjectIsGone($storage, $identifier);
        });
    }

    /**
     * The state the bytes-first order is CHOSEN to produce, over both real substrates.
     *
     * A crash between the two steps leaves the row alive and the object gone, and the argument for this
     * order is that the next delivery resolves it while the mirror image — an object no row can name —
     * is unrecoverable by construction. That argument is the reason the protocol looks the way it does,
     * and until now nothing drove the state it turns on: the storage-failure case below raises inside the
     * root guard, before a byte is touched, so it observes the ORDER of the two lines rather than the
     * retryability of what sits between them.
     */
    public function testARowWhoseObjectIsAlreadyGoneIsClosedByTheNextDelivery(): void
    {
        $this->withDeletion(function (
            DeleteImage $deleteImage,
            ImageStorage $storage,
            EntityManagerInterface $entityManager,
        ): void {
            $identifier = $this->seed($storage, $entityManager);

            // The crash, reproduced: the bytes are released and the process dies before the row is.
            $storage->delete($identifier);
            $this->assertObjectIsGone($storage, $identifier);
            $this->assertInstanceOf(
                Image::class,
                $this->rowFor($entityManager, $identifier),
                'the intermediate state is a live row over absent bytes, or this case proves nothing',
            );

            $deleteImage->delete($identifier);
            $entityManager->clear();

            $this->assertObjectIsGone($storage, $identifier);
            $this->assertNotInstanceOf(
                Image::class,
                $this->rowFor($entityManager, $identifier),
                'the redelivery must close the pair rather than stalling on the absent object',
            );
        });
    }

    /**
     * At-least-once delivery, made concrete: the second run must not fail, must not resurrect the row and
     * must leave the same final state. It is the case an implementation that treated absence as an error
     * would pass every other test and fail here, in production, on a redelivery.
     */
    public function testASecondDeliveryOfTheSameRequestLeavesTheSameStateAndDoesNotFail(): void
    {
        $this->withDeletion(function (
            DeleteImage $deleteImage,
            ImageStorage $storage,
            EntityManagerInterface $entityManager,
        ): void {
            $identifier = $this->seed($storage, $entityManager);

            $deleteImage->delete($identifier);
            $entityManager->clear();
            $deleteImage->delete($identifier);
            $entityManager->clear();

            $this->assertObjectIsGone($storage, $identifier);
            $this->assertNotInstanceOf(
                Image::class,
                $this->rowFor($entityManager, $identifier),
                'the row must not come back',
            );
        });
    }

    /**
     * Bytes first is only worth anything if the failure in between leaves a retryable state. With the
     * substrate unreachable the row must survive: a request that dropped the row while the bytes stayed
     * would strand them behind a reference this module keeps no record of.
     */
    public function testAStorageFailureLeavesTheRowIntactSoTheRequestStaysRetryable(): void
    {
        $this->withDeletion(function (
            DeleteImage $deleteImage,
            ImageStorage $storage,
            EntityManagerInterface $entityManager,
        ): void {
            $identifier = $this->seed($storage, $entityManager);
            $unreachable = $this->storageRootedAt($this->root . '/never-provisioned');

            $failing = new DeleteImage(
                $unreachable,
                new DoctrineImageRepository($entityManager),
                $this->transactionManager(),
            );

            try {
                $failing->delete($identifier);
                self::fail('an unreachable substrate must not report a completed deletion');
            } catch (ImageStorageException) {
                // expected — what matters is the state it leaves behind.
            }

            $entityManager->clear();

            $this->assertInstanceOf(
                Image::class,
                $this->rowFor($entityManager, $identifier),
                'the row is the retry handle',
            );
            $this->assertSame('canonical bytes under test', $storage->read($identifier), 'and the bytes are untouched');

            // The retry, now that the substrate is back, completes the protocol.
            $deleteImage->delete($identifier);
            $entityManager->clear();

            $this->assertObjectIsGone($storage, $identifier);
            $this->assertNotInstanceOf(Image::class, $this->rowFor($entityManager, $identifier));
        });
    }

    private function seed(ImageStorage $storage, EntityManagerInterface $entityManager): ImageId
    {
        $identifier = ImageId::generate();
        $storage->store($identifier, 'canonical bytes under test');
        (new DoctrineImageRepository($entityManager))->save(
            new Image($identifier, \str_repeat('a', 64), 'image/png', 24, 24, 512),
        );
        $entityManager->clear();

        $this->assertSame(
            'canonical bytes under test',
            $storage->read($identifier),
            'seed the object before asserting its absence',
        );
        $this->assertInstanceOf(
            Image::class,
            $this->rowFor($entityManager, $identifier),
            'seed the row before asserting its absence',
        );

        return $identifier;
    }

    private function assertObjectIsGone(ImageStorage $storage, ImageId $identifier): void
    {
        try {
            $storage->read($identifier);
            $this->fail('the stored object is still there');
        } catch (ImageStorageException $imageStorageException) {
            $this->assertSame('storage_confirmed_absence', $imageStorageException->storageFailure()->value);
        }
    }

    private function rowFor(EntityManagerInterface $entityManager, ImageId $identifier): ?Image
    {
        return (new DoctrineImageRepository($entityManager))->findById($identifier);
    }

    private function storageRootedAt(string $root): FlysystemImageStorage
    {
        return new FlysystemImageStorage(
            new Filesystem(new LocalFilesystemAdapter($root, lazyRootCreation: true)),
            $root,
            new RecordingLogger(),
        );
    }

    /**
     * The wired manager rather than a hand-built one: its retry and translation behaviour is part of what
     * the deletion path inherits, and a locally constructed copy would quietly stop being the same object
     * the application uses.
     */
    private function transactionManager(): TransactionManager
    {
        $transactionManager = self::getContainer()->get(TransactionManager::class);
        $this->assertInstanceOf(TransactionManager::class, $transactionManager);

        return $transactionManager;
    }

    /**
     * @param callable(DeleteImage, ImageStorage, EntityManagerInterface): void $work
     */
    private function withDeletion(callable $work): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $storage = $this->storageRootedAt($this->root);
        $deleteImage = new DeleteImage(
            $storage,
            new DoctrineImageRepository($entityManager),
            $this->transactionManager(),
        );

        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $work($deleteImage, $storage, $entityManager);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
