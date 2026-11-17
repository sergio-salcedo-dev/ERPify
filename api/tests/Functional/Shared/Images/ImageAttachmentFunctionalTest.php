<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Images;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Images\Application\ImageAttachment;
use Erpify\Shared\Images\Application\UploadImage;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Infrastructure\InterventionImageProcessor;
use Erpify\Shared\Images\Infrastructure\Persistence\Doctrine\DoctrineImageRepository;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\Shared\Images\Fixtures\FailingAfterWorkTransactionManager;
use Erpify\Tests\Functional\Shared\Images\Fixtures\ImageReleaseRig;
use Erpify\Tests\Functional\Shared\Images\Fixtures\ProbeHolder;
use Erpify\Tests\Functional\Shared\Images\Fixtures\ProbingTransactionManager;
use Erpify\Tests\Unit\Shared\Images\Application\Double\LostAcknowledgementTransactionManager;
use Erpify\Tests\Unit\Shared\Images\Application\Double\OwnerNotFound;
use Erpify\Tests\Unit\Shared\Images\Infrastructure\LocalImageStorages;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The attach protocol over the REAL substrates: the transaction manager, Postgres, the publish guard and the Doctrine
 * transport's `INSERT`, with a committed holder table standing in for a consumer's aggregate. What the unit test
 * proves in order, this proves in effect — above all that a rollback takes the holder's update AND the queued release
 * with it, which only a SECOND connection can see, because the application's own connection sees its uncommitted rows.
 *
 * Every case commits, so the suite's own rollback harness is not available: the holder table is created here and
 * dropped in the teardown, together with the image rows, the stored objects and the probe queue.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(ImageAttachment::class)]
final class ImageAttachmentFunctionalTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $outside;

    private string $root;

    /** @var list<string> */
    private array $imageIdsBefore = [];

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        // A second connection: only COMMITTED rows are visible from it.
        $this->outside = DriverManager::getConnection($entityManager->getConnection()->getParams());
        $this->root = \sys_get_temp_dir() . '/erpify-image-attachment-' . \bin2hex(\random_bytes(6));
        \mkdir($this->root, 0o755, true);

        // A run killed between a commit and its teardown leaves committed rows that would count for ever.
        ProbeHolder::drop($this->outside);
        ImageReleaseRig::purge($this->outside);
        ProbeHolder::create($this->outside);
        $this->imageIdsBefore = $this->imageIds();
        $this->assertFalse($entityManager->getConnection()->isTransactionActive(), 'the case starts in no transaction');
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $connection = $this->entityManager->getConnection();

            for ($level = $connection->getTransactionNestingLevel(); $level > 0; --$level) {
                $connection->rollBack();
            }
        }

        if (isset($this->outside)) {
            ProbeHolder::drop($this->outside);
            ImageReleaseRig::purge($this->outside);

            foreach (\array_diff($this->imageIds(), $this->imageIdsBefore) as $created) {
                $this->outside->executeStatement('DELETE FROM image WHERE id = ?', [$created]);
            }

            $this->outside->close();
        }

        if (isset($this->root)) {
            \exec('rm -rf ' . \escapeshellarg($this->root));
        }

        parent::tearDown();
    }

    #[Test]
    public function replacingMovesTheHolderToTheNewImageAndQueuesOneReleaseOfTheOldOneAfterTheCommit(): void
    {
        $owner = Uuid::generate();
        $old = ImageId::generate()->toString();
        ProbeHolder::seed($this->outside, $owner, $old);
        $probed = false;
        // The second unit of work is the attach (the first is the upload): after its operation has written the
        // holder and queued the release, and before the commit, the application's own connection sees the queued
        // row and a second connection does not.
        $manager = new ProbingTransactionManager($this->transactions(), 2, function () use ($old, &$probed): void {
            $inserted = ImageReleaseRig::queuedFor($this->entityManager->getConnection(), $old);
            $this->assertSame(1, $inserted, 'the release was not inserted');
            $this->assertSame(0, ImageReleaseRig::queuedFor($this->outside, $old), 'visible before the commit');
            $probed = true;
        });

        $new = $this->attachment($manager)->replace(new ProbeHolder($this->entityManager), $owner, $this->png());

        $this->assertTrue($probed, 'the probe never ran inside the attach unit');

        $this->assertSame($new->toString(), ProbeHolder::heldAsSeenFrom($this->outside, $owner));
        $this->assertSame(1, ImageReleaseRig::queuedFor($this->outside, $old));
        $this->assertSame(0, ImageReleaseRig::queuedFor($this->outside, $new->toString()));
        $this->assertContains($new->toString(), $this->imageIds(), 'one Image row for the new bytes');
    }

    /**
     * The attach unit commits and the acknowledgement is lost: the error propagates, but the owner already holds the
     * new image, so only the previous id is released — the new one is left alone.
     */
    #[Test]
    public function aCommitWhoseAcknowledgementWasLostKeepsTheNewImageAndReleasesOnlyTheOldOne(): void
    {
        $owner = Uuid::generate();
        $old = ImageId::generate()->toString();
        ProbeHolder::seed($this->outside, $owner, $old);
        $manager = new LostAcknowledgementTransactionManager($this->transactions(), 2);

        try {
            $this->attachment($manager)->replace(new ProbeHolder($this->entityManager), $owner, $this->png());
            $this->fail('the lost acknowledgement must propagate');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('the commit was acknowledged to nobody', $runtimeException->getMessage());
        }

        $minted = \array_values(\array_diff($this->imageIds(), $this->imageIdsBefore));
        $this->assertCount(1, $minted);
        $held = ProbeHolder::heldAsSeenFrom($this->outside, $owner);
        $this->assertSame($minted[0], $held, 'the owner keeps the new image');
        $this->assertSame(1, ImageReleaseRig::queuedFor($this->outside, $old));
        $released = ImageReleaseRig::queuedFor($this->outside, $minted[0]);
        $this->assertSame(0, $released, 'the id the owner points at is not released');
    }

    #[Test]
    public function aFirstSetQueuesNothing(): void
    {
        $owner = Uuid::generate();
        ProbeHolder::seed($this->outside, $owner, null);

        $new = $this->attachment()->replace(new ProbeHolder($this->entityManager), $owner, $this->png());

        $this->assertSame($new->toString(), ProbeHolder::heldAsSeenFrom($this->outside, $owner));
        $this->assertSame(0, $this->queuedTotal());
    }

    #[Test]
    public function aRollbackAfterTheWorkTakesTheHoldersUpdateAndTheQueuedReleaseAlongAndTheNewIdIsReleased(): void
    {
        $owner = Uuid::generate();
        $old = ImageId::generate()->toString();
        ProbeHolder::seed($this->outside, $owner, $old);
        $manager = new FailingAfterWorkTransactionManager($this->transactions(), 2);

        try {
            $this->attachment($manager)->replace(new ProbeHolder($this->entityManager), $owner, $this->png());
            $this->fail('the failure after the work must propagate');
        } catch (RuntimeException $runtimeException) {
            $this->assertStringContainsString('after its work', $runtimeException->getMessage());
        }

        $minted = \array_values(\array_diff($this->imageIds(), $this->imageIdsBefore));
        $this->assertCount(1, $minted, 'the new image was stored before the failed unit');
        $this->assertSame($old, ProbeHolder::heldAsSeenFrom($this->outside, $owner), 'the holder row is as it was');
        $this->assertSame(0, ImageReleaseRig::queuedFor($this->outside, $old), 'the old id was never released');
        $this->assertSame(1, ImageReleaseRig::queuedFor($this->outside, $minted[0]), 'the new id is, once');
    }

    #[Test]
    public function anOwnerGoneBeforeTheLockAnswersItsNotFoundAndReleasesTheNewImage(): void
    {
        $owner = Uuid::generate();

        try {
            $this->attachment()->replace(new ProbeHolder($this->entityManager), $owner, $this->png());
            $this->fail('the owner is gone');
        } catch (OwnerNotFound) {
            // expected: the owner's own 404
        }

        $minted = \array_values(\array_diff($this->imageIds(), $this->imageIdsBefore));
        $this->assertCount(1, $minted);
        $this->assertSame(1, ImageReleaseRig::queuedFor($this->outside, $minted[0]));
        $this->assertSame(1, $this->queuedTotal());
    }

    #[Test]
    public function aBodyThatDoesNotDecodeStoresNothingAndTouchesNeitherTheOwnerNorTheQueue(): void
    {
        $owner = Uuid::generate();
        $old = ImageId::generate()->toString();
        ProbeHolder::seed($this->outside, $owner, $old);

        try {
            $this->attachment()->replace(new ProbeHolder($this->entityManager), $owner, 'not an image at all');
            $this->fail('the processor must refuse the body');
        } catch (\Erpify\Shared\Images\Domain\Exception\ImageProcessingException) {
            // expected: 4xx
        }

        $this->assertSame([], \array_diff($this->imageIds(), $this->imageIdsBefore));
        $this->assertSame($old, ProbeHolder::heldAsSeenFrom($this->outside, $owner));
        $this->assertSame(0, $this->queuedTotal());
        $this->assertSame([], \glob($this->root . '/*') ?: [], 'no object either');
    }

    #[Test]
    public function removingClearsTheHolderAndQueuesOneReleaseAndIsIdempotent(): void
    {
        $owner = Uuid::generate();
        $held = ImageId::generate()->toString();
        ProbeHolder::seed($this->outside, $owner, $held);
        $attachment = $this->attachment();
        $holder = new ProbeHolder($this->entityManager);

        $attachment->remove($holder, $owner);
        $attachment->remove($holder, $owner);

        $this->assertNull(ProbeHolder::heldAsSeenFrom($this->outside, $owner));
        $this->assertSame(1, ImageReleaseRig::queuedFor($this->outside, $held), 'one release, not one per call');
        $this->assertSame(1, $this->queuedTotal());
    }

    /**
     * Proves two things and no more: the owner row is locked against another connection for the whole attach unit
     * (a `NOWAIT` lock attempt from a second connection is refused while the replace holds it), and replaces run one
     * after the other release each replaced id once and leave one live image. A second writer actually waiting on the
     * first is not exercised.
     */
    #[Test]
    public function theOwnerRowIsLockedDuringAReplaceAndSequentialReplacesReleaseEachIdOnce(): void
    {
        $owner = Uuid::generate();
        $first = ImageId::generate()->toString();
        ProbeHolder::seed($this->outside, $owner, $first);
        $attachment = $this->attachment();
        $holder = new ProbeHolder($this->entityManager);
        $holder->afterLock = function () use ($owner): void {
            $this->assertRowIsLockedFromAnotherConnection($owner);
        };

        $second = $attachment->replace($holder, $owner, $this->png());
        $third = $attachment->replace($holder, $owner, $this->png());

        $this->assertSame($third->toString(), ProbeHolder::heldAsSeenFrom($this->outside, $owner), 'one live image');
        $this->assertSame(1, ImageReleaseRig::queuedFor($this->outside, $first));
        $this->assertSame(1, ImageReleaseRig::queuedFor($this->outside, $second->toString()));
        $this->assertSame(0, ImageReleaseRig::queuedFor($this->outside, $third->toString()));
        $this->assertSame(2, $this->queuedTotal());
    }

    private function assertRowIsLockedFromAnotherConnection(string $owner): void
    {
        try {
            $this->outside->fetchOne(
                'SELECT owner_id FROM ' . ProbeHolder::TABLE . ' WHERE owner_id = ? FOR UPDATE NOWAIT',
                [$owner],
            );
            $this->fail('a second writer could take the owner row while the first held it');
        } catch (DriverException $driverException) {
            $this->assertSame('55P03', $driverException->getSQLState(), 'lock_not_available');
        }
    }

    private function attachment(?TransactionManager $transactions = null): ImageAttachment
    {
        $transactions ??= $this->transactions();

        return new ImageAttachment(
            new UploadImage(
                $this->processor(),
                LocalImageStorages::rootedAt($this->root),
                new DoctrineImageRepository($this->entityManager),
                $transactions,
            ),
            $transactions,
            (new ImageReleaseRig($this->entityManager))->eventBus(),
            new NullLogger(),
        );
    }

    private function transactions(): TransactionManager
    {
        $transactions = self::getContainer()->get(TransactionManager::class);
        $this->assertInstanceOf(TransactionManager::class, $transactions);

        return $transactions;
    }

    private function processor(): InterventionImageProcessor
    {
        $container = self::getContainer();
        $integer = static fn (string $name): int => (int) $container->getParameter($name);

        return new InterventionImageProcessor(
            $integer('erpify.images.max_input_bytes'),
            $integer('erpify.images.max_decoded_pixels'),
            $integer('erpify.images.max_input_dimension'),
            $integer('erpify.images.max_output_dimension'),
            $integer('erpify.images.encoding_quality'),
            new NullLogger(),
        );
    }

    private function png(): string
    {
        return (string) \file_get_contents(\dirname(__DIR__, 3) . '/Fixtures/Images/valid.png');
    }

    private function queuedTotal(): int
    {
        $count = $this->outside->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?',
            [ImageReleaseRig::QUEUE],
        );

        return \is_numeric($count) ? (int) $count : -1;
    }

    /**
     * @return list<string>
     */
    private function imageIds(): array
    {
        $ids = [];

        foreach ($this->outside->fetchFirstColumn('SELECT id FROM image') as $id) {
            if (\is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
