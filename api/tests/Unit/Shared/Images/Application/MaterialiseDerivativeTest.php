<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Images\Application;

use Erpify\Shared\Images\Application\MaterialiseDerivative;
use Erpify\Shared\Images\Domain\CanonicalImage;
use Erpify\Shared\Images\Domain\DerivativeOrigin;
use Erpify\Shared\Images\Domain\Entity\Image;
use Erpify\Shared\Images\Domain\Exception\DerivativeOriginNotLive;
use Erpify\Shared\Images\Domain\Exception\ImageDecodingFailed;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\ImageProcessor;
use Erpify\Shared\Images\Domain\Repository\ImageRepository;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Unit\Shared\Images\Application\Double\LostAcknowledgementTransactionManager;
use Erpify\Tests\Unit\Shared\Images\Application\Double\RefusingImageProcessor;
use Erpify\Tests\Unit\Shared\Images\Application\Double\RollbackingTransactionManager;
use Erpify\Tests\Unit\Shared\Images\Application\Double\SavedInUnits;
use Erpify\Tests\Unit\Shared\Images\Application\Double\ScriptedOriginGuard;
use Erpify\Tests\Unit\Shared\Images\Application\Double\TrackedImageRepository;
use Erpify\Tests\Unit\Shared\Images\Application\Double\TransactionalEventBus;
use Erpify\Tests\Unit\Shared\Images\Application\Double\UndeletableImageStorage;
use Erpify\Tests\Unit\Shared\Images\Infrastructure\RecordingLogger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;
use Throwable;

/**
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") one double per collaborator, so each failure is placed exactly
 */
#[CoversClass(MaterialiseDerivative::class)]
final class MaterialiseDerivativeTest extends TestCase
{
    private const string ORIGIN_ID = '0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1e01';

    private RollbackingTransactionManager $transactions;

    private InMemoryImageStorage $storage;

    private InMemoryImageRepository $rows;

    private SavedInUnits $savedInUnits;

    private RecordingLogger $logger;

    private TransactionalEventBus $bus;

    #[Override]
    protected function setUp(): void
    {
        $this->transactions = new RollbackingTransactionManager();
        $this->storage = new InMemoryImageStorage();
        $this->rows = new InMemoryImageRepository();
        $this->savedInUnits = new SavedInUnits();
        $this->logger = new RecordingLogger();
        $this->bus = new TransactionalEventBus($this->transactions);
    }

    #[Test]
    public function anAdmittedOriginKeepsTheCanonicalBytesAndARowCarryingTheOrigin(): void
    {
        $guard = new ScriptedOriginGuard(true, $this->transactions);

        $id = $this->materialise()->materialise($this->origin(), $guard, 'raw');

        $row = $this->rows->findById($id);
        $this->assertInstanceOf(Image::class, $row);
        $this->assertSame(['Documents.Document', self::ORIGIN_ID], [$row->origin()?->type, $row->origin()?->id]);
        $this->assertSame('canonical', $this->storage->read($id), 'only the canonical bytes are stored');
        $this->assertSame(
            [['Documents.Document', self::ORIGIN_ID]],
            \array_map(static fn (DerivativeOrigin $asked): array => [$asked->type, $asked->id], $guard->asked),
        );
        $this->assertSame([1], $guard->askedInUnits, 'the guard is asked inside the unit of work');
        $this->assertSame([1], $this->savedInUnits->units, 'the row is saved inside that same unit');
    }

    #[Test]
    public function aRefusedOriginKeepsNeitherTheRowNorTheBytes(): void
    {
        $guard = new ScriptedOriginGuard(false, $this->transactions);

        $refused = $this->thrownBy(fn (): ImageId => $this->materialise()->materialise($this->origin(), $guard, 'raw'));

        $this->assertInstanceOf(DerivativeOriginNotLive::class, $refused);
        $this->assertSame('image-origin-not-live', $refused->type());

        $this->assertSame([], $this->rows->rows, 'no row is inserted');
        $this->assertSame([], $this->storage->objects, 'the stored bytes are deleted');
        $this->assertSame([], $this->savedInUnits->units);
    }

    #[Test]
    public function aRefusalWhoseDeleteFailsQueuesTheDeletionInsteadOfLeavingTheBytes(): void
    {
        $storage = new UndeletableImageStorage();

        $this->assertInstanceOf(DerivativeOriginNotLive::class, $this->thrownBy(
            fn (): ImageId => $this->materialise(storage: $storage)
                ->materialise($this->origin(), new ScriptedOriginGuard(false, $this->transactions), 'raw'),
        ));

        $this->assertSame(\array_keys($storage->objects), $this->bus->queuedIds(), 'the deletion is queued, in a unit');
        $this->assertSame([], $this->logger->records);
    }

    #[Test]
    public function aRefusalWhoseDeleteAndQueueBothFailIsReportedWithTheImageId(): void
    {
        $storage = new UndeletableImageStorage();
        $this->bus->failsWith = new RuntimeException('the queue is down');

        $this->assertInstanceOf(DerivativeOriginNotLive::class, $this->thrownBy(
            fn (): ImageId => $this->materialise(storage: $storage)
                ->materialise($this->origin(), new ScriptedOriginGuard(false, $this->transactions), 'raw'),
        ));

        $this->assertCount(1, $this->logger->records);
        $this->assertSame(LogLevel::WARNING, $this->logger->records[0]['level']);
        $this->assertSame('images.derivative.discard_failed', $this->logger->records[0]['message']);
        $this->assertSame(
            \array_keys($storage->objects)[0] ?? null,
            $this->logger->records[0]['context']['imageId'] ?? null,
            'the one handle an operator has on the residue',
        );
        $this->assertSame(RuntimeException::class, $this->logger->records[0]['context']['exceptionClass'] ?? null);
    }

    #[Test]
    public function aFailedWriteWhoseRowIsConfirmedAbsentDeletesTheBytes(): void
    {
        $this->assertInstanceOf(StubPersistenceFailure::class, $this->thrownBy(
            fn (): ImageId => $this->materialise(repository: new FailingImageRepository())
                ->materialise($this->origin(), new ScriptedOriginGuard(true, $this->transactions), 'raw'),
        ));

        $this->assertSame([], $this->storage->objects, 'bytes no row holds belong to nothing');
    }

    #[Test]
    public function aCommitWhoseAcknowledgementWasLostKeepsItsBytesForTheOriginsRelease(): void
    {
        $transactions = new LostAcknowledgementTransactionManager($this->transactions, 1);

        $this->assertInstanceOf(Throwable::class, $this->thrownBy(
            fn (): ImageId => $this->materialise(transactions: $transactions)
                ->materialise($this->origin(), new ScriptedOriginGuard(true, $this->transactions), 'raw'),
        ));

        $this->assertCount(1, $this->rows->rows, 'the row committed');
        $this->assertCount(1, $this->storage->objects, 'so its bytes stay: the origin releases them by origin');
        $this->assertSame([], $this->bus->queuedIds());
    }

    #[Test]
    public function aRowThatCannotBeReadDecidesNothingAndTheIdIsReported(): void
    {
        $this->assertInstanceOf(Throwable::class, $this->thrownBy(
            fn (): ImageId => $this->materialise(repository: new UnreadableImageRepository())
                ->materialise($this->origin(), new ScriptedOriginGuard(false, $this->transactions), 'raw'),
        ));

        $this->assertCount(1, $this->storage->objects, 'the bytes stay: deleting them could strand a committed row');
        $this->assertSame('images.derivative.discard_undecided', $this->logger->records[0]['message'] ?? null);
        $this->assertSame(
            \array_keys($this->storage->objects),
            [$this->logger->records[0]['context']['imageId'] ?? null],
        );
    }

    #[Test]
    public function bytesThatDoNotDecodeStoreNothingAndNeverReachTheGuard(): void
    {
        $guard = new ScriptedOriginGuard(true, $this->transactions);

        $this->assertInstanceOf(ImageDecodingFailed::class, $this->thrownBy(
            fn (): ImageId => $this->materialise(processor: new RefusingImageProcessor())
                ->materialise($this->origin(), $guard, 'x'),
        ));

        $this->assertSame([], $this->storage->objects);
        $this->assertSame([], $guard->asked);
    }

    private function origin(): DerivativeOrigin
    {
        return new DerivativeOrigin('Documents.Document', self::ORIGIN_ID);
    }

    private function materialise(
        ?ImageProcessor $processor = null,
        ?ImageStorage $storage = null,
        ?ImageRepository $repository = null,
        ?TransactionManager $transactions = null,
    ): MaterialiseDerivative {
        return new MaterialiseDerivative(
            $processor ?? new StubImageProcessor(new CanonicalImage('canonical', 'image/png', 4, 4)),
            $storage ?? $this->storage,
            $repository ?? new TrackedImageRepository($this->rows, $this->transactions, $this->savedInUnits),
            $transactions ?? $this->transactions,
            $this->bus,
            $this->logger,
        );
    }

    /**
     * What `$call` threw, or null when it returned: a refusal is asserted on, never swallowed by an empty catch.
     */
    private function thrownBy(callable $call): ?Throwable
    {
        try {
            $call();
        } catch (Throwable $throwable) {
            return $throwable;
        }

        return null;
    }
}
