<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Documents\Document\Application\Derivative\DocumentDerivativeOrigin;
use Erpify\Documents\Document\Application\Derivative\MaterialiseDocumentDerivative;
use Erpify\Documents\Document\Application\Erasure\ReleaseErasedDerivatives;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Erasure\DocumentErasures;
use Erpify\Documents\Document\Domain\Erasure\ErasureLimits;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReleaseErasedDerivativesHandler;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReleaseErasedDerivativesMessage;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DbalDocumentOriginGuard;
use Erpify\Shared\Images\Application\DeleteImage;
use Erpify\Shared\Images\Application\MaterialiseDerivative;
use Erpify\Shared\Images\Application\ReleaseDerivatives;
use Erpify\Shared\Images\Domain\DerivativeOrigin;
use Erpify\Shared\Images\Domain\Exception\DerivativeOriginNotLive;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Repository\ImageRepository;
use Erpify\Shared\Images\Domain\Storage\ImageBytesNotFound;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Erpify\Shared\Images\Infrastructure\InterventionImageProcessor;
use Erpify\Shared\Images\Infrastructure\Persistence\Doctrine\DoctrineImageRepository;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\Shared\Images\Fixtures\ImageReleaseRig;
use FilesystemIterator;
use LogicException;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * The derivative lifecycle against Postgres: who may receive a derivative, the lock that orders it against the erasure,
 * and the sweep that releases what derives from an erased document until a re-query finds nothing (ADR
 * `images-vs-documents-conservation-contract.md` D8). Releases are published through the probe queue of
 * {@see ImageReleaseRig}, so a SECOND connection can tell what committed from what was rolled back.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") both modules' collaborators, wired over one database
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") each public method is one independent answer of the lifecycle
 * @SuppressWarnings("PHPMD.TooManyMethods") the same
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") the same
 */
#[CoversClass(DbalDocumentOriginGuard::class)]
#[CoversClass(MaterialiseDocumentDerivative::class)]
#[CoversClass(ReleaseErasedDerivatives::class)]
#[CoversClass(ReleaseErasedDerivativesHandler::class)]
#[CoversClass(ReleaseDerivatives::class)]
#[CoversClass(MaterialiseDerivative::class)]
#[CoversClass(DoctrineImageRepository::class)]
final class DocumentDerivativesFunctionalTest extends KernelTestCase
{
    use PlantsNamedDocuments;

    private const string ALARM = 'Derivatives of erased documents are older than the bound.';

    private Connection $outside;

    private TestHandler $log;

    /** @var list<string> */
    private array $imageIdsBefore = [];

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->outside = DriverManager::getConnection($this->connection()->getParams());
        $this->emptyDocumentTables();
        ImageReleaseRig::purge($this->outside);
        $this->imageIdsBefore = $this->imageIds();
        $this->log = new TestHandler();
    }

    protected function tearDown(): void
    {
        if (isset($this->outside)) {
            foreach (\array_diff($this->imageIds(), $this->imageIdsBefore) as $created) {
                $this->service(DeleteImage::class)->delete(ImageId::fromString($created));
            }

            ImageReleaseRig::purge($this->outside);
            $this->outside->close();
        }

        $this->emptyDocumentTables();
        parent::tearDown();
    }

    #[Test]
    public function aReadyDocumentAdmitsADerivativeThatCarriesItsOriginAndIsReadable(): void
    {
        $document = $this->plantNamedDocument(null, DocumentState::Ready);

        $imageId = $this->materialise($document['id']);

        $row = $this->outside->fetchAssociative(
            'SELECT origin_type, origin_id FROM image WHERE id = CAST(:id AS UUID)',
            ['id' => $imageId->toString()],
        );
        $this->assertSame(
            ['origin_type' => DocumentDerivativeOrigin::TYPE, 'origin_id' => $document['id']],
            $row,
            'the origin committed with the row',
        );
        $this->assertNotSame('', $this->service(ImageStorage::class)->read($imageId));
    }

    #[Test]
    #[DataProvider('provideADocumentThatIsNotReadyKeepsNoDerivativeCases')]
    public function aDocumentThatIsNotReadyKeepsNoDerivative(DocumentState $state): void
    {
        $document = $this->plantNamedDocument(null, $state);
        $objectsBefore = $this->storedObjects();

        $this->assertRefused($document['id']);

        $this->assertSame($this->imageIdsBefore, $this->imageIds(), 'no row');
        $this->assertSame($objectsBefore, $this->storedObjects(), 'the stored bytes were deleted');
    }

    /**
     * @return iterable<string, array{DocumentState}>
     */
    public static function provideADocumentThatIsNotReadyKeepsNoDerivativeCases(): iterable
    {
        yield 'received' => [DocumentState::Received];
        yield 'scanning' => [DocumentState::Scanning];
        yield 'quarantined' => [DocumentState::Quarantined];
        yield 'scan failed' => [DocumentState::ScanFailed];
        yield 'erased' => [DocumentState::Erased];
    }

    #[Test]
    public function aDocumentThatDoesNotExistKeepsNoDerivative(): void
    {
        $objectsBefore = $this->storedObjects();

        $this->assertRefused(Uuid::generate());

        $this->assertSame($this->imageIdsBefore, $this->imageIds());
        $this->assertSame($objectsBefore, $this->storedObjects());
    }

    #[Test]
    public function theGuardHoldsTheDocumentRowAgainstTheErasureUntilItsTransactionEnds(): void
    {
        $document = $this->plantNamedDocument(null, DocumentState::Ready);
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $this->assertTrue($this->guard()->admits(DocumentDerivativeOrigin::of($document['id'])));

            try {
                $this->outside->fetchOne(
                    'SELECT id FROM document WHERE id = CAST(:id AS UUID) FOR UPDATE NOWAIT',
                    ['id' => $document['id']],
                );
                $this->fail('the erasure lock must wait on the guard');
            } catch (DriverException $driverException) {
                $this->assertSame('55P03', $driverException->getSQLState(), 'lock_not_available');
            }
        } finally {
            $connection->rollBack();
        }
    }

    #[Test]
    public function theGuardRefusesToRunOutsideATransaction(): void
    {
        $document = $this->plantNamedDocument(null, DocumentState::Ready);

        $this->expectException(LogicException::class);

        $this->guard()->admits(DocumentDerivativeOrigin::of($document['id']));
    }

    #[Test]
    public function theGuardAdmitsNothingUnderAnotherContextsTag(): void
    {
        $document = $this->plantNamedDocument(null, DocumentState::Ready);

        $admitted = $this->service(TransactionManager::class)->transactional(
            fn (): bool => $this->guard()->admits(new DerivativeOrigin('Backoffice.Bank', $document['id'])),
        );

        $this->assertFalse($admitted);
    }

    #[Test]
    public function theSweepQueuesTheReleaseAndMarksTheRowOnlyOnceNothingDerivesFromIt(): void
    {
        $document = $this->plantNamedDocument(null, DocumentState::Ready);
        $imageId = $this->materialise($document['id'])->toString();
        $this->eraseRow($document['id'], 30);

        $this->assertSame(0, $this->sweep()->run(10), 'a queued release is not a confirmed absence');
        $this->assertSame(1, ImageReleaseRig::queuedFor($this->outside, $imageId), 'queued, and committed');
        $this->assertNull($this->releasedAt($document['id']));

        $this->service(DeleteImage::class)->delete(ImageId::fromString($imageId));
        $this->assertGone(ImageId::fromString($imageId));

        $this->assertSame(1, $this->sweep()->run(10));
        $this->assertNotNull($this->releasedAt($document['id']));
        $this->assertSame(0, $this->sweep()->run(10), 'a released row is not asked about again');
        $this->assertSame(1, ImageReleaseRig::queuedFor($this->outside, $imageId), 'and nothing more is queued');
    }

    #[Test]
    public function aReleaseLostFromTheQueueIsQueuedAgainOnTheNextTick(): void
    {
        $document = $this->plantNamedDocument(null, DocumentState::Ready);
        $imageId = $this->materialise($document['id'])->toString();
        $this->eraseRow($document['id'], 30);
        $this->sweep()->run(10);

        ImageReleaseRig::purge($this->outside);
        $this->sweep()->run(10);

        $this->assertSame(1, ImageReleaseRig::queuedFor($this->outside, $imageId), 're-derived from the origin');
        $this->assertNull($this->releasedAt($document['id']));
    }

    #[Test]
    public function anErasedDocumentWithoutDerivativesIsMarkedOnTheFirstTickAndRaisesNoAlarm(): void
    {
        $document = $this->plantNamedDocument(null, DocumentState::Ready);
        $this->eraseRow($document['id'], ErasureLimits::DERIVATIVE_ALARM_SECONDS + 60);

        $this->assertSame(1, $this->sweep()->run(10));

        $this->assertNotNull($this->releasedAt($document['id']));
        $this->assertFalse($this->log->hasRecordThatContains('older than the bound', Level::Error));
    }

    #[Test]
    public function derivativesOutlivingTheBoundAreNamedByOneErrorLineAndYoungOnesAreNot(): void
    {
        $old = $this->plantNamedDocument(null, DocumentState::Ready);
        $young = $this->plantNamedDocument(null, DocumentState::Ready);
        $this->materialise($old['id']);
        $this->materialise($young['id']);
        $this->eraseRow($old['id'], ErasureLimits::DERIVATIVE_ALARM_SECONDS + 60);
        $this->eraseRow($young['id'], ErasureLimits::DERIVATIVE_ALARM_SECONDS - 60);

        $this->sweep()->run(10);

        $alarms = \array_values(\array_filter(
            $this->log->getRecords(),
            static fn (LogRecord $record): bool => self::ALARM === $record->message,
        ));
        $this->assertCount(1, $alarms);
        $this->assertSame(Level::Error, $alarms[0]->level);
        $context = $alarms[0]->context;
        $this->assertSame(1, $context['count'] ?? null);
        $this->assertSame([$old['id']], $context['documentIds'] ?? null);
        $this->assertSame(ErasureLimits::DERIVATIVE_ALARM_SECONDS, $context['boundSeconds'] ?? null);
    }

    #[Test]
    public function theTickRunsTheSweepThroughTheContainersWiring(): void
    {
        $document = $this->plantNamedDocument(null, DocumentState::Ready);
        $this->eraseRow($document['id'], 30);

        ($this->service(ReleaseErasedDerivativesHandler::class))(new ReleaseErasedDerivativesMessage());

        $this->assertNotNull($this->releasedAt($document['id']));
    }

    #[Test]
    public function aRolledBackUnitTakesItsQueuedReleasesAlong(): void
    {
        $document = $this->plantNamedDocument(null, DocumentState::Ready);
        $imageId = $this->materialise($document['id'])->toString();

        $failure = $this->thrownBy(fn () => $this->service(TransactionManager::class)->transactional(
            function () use ($document): never {
                $this->assertSame(1, $this->release()->of(DocumentDerivativeOrigin::of($document['id'])));

                throw new RuntimeException('the unit fails after its publish');
            },
        ));

        $this->assertInstanceOf(RuntimeException::class, $failure);
        $this->assertSame('the unit fails after its publish', $failure->getMessage());

        $this->assertSame(0, ImageReleaseRig::queuedFor($this->outside, $imageId), 'nothing committed');
        $this->assertContains($imageId, $this->imageIds(), 'the image is still there');
    }

    private function materialise(string $documentId): ImageId
    {
        return $this->materialiser()->materialise($documentId, $this->png());
    }

    /**
     * Built from the container's own collaborators: nothing consumes the seam yet, so the container drops it.
     */
    private function materialiser(): MaterialiseDocumentDerivative
    {
        return new MaterialiseDocumentDerivative(
            new MaterialiseDerivative(
                $this->processor(),
                $this->service(ImageStorage::class),
                $this->service(ImageRepository::class),
                $this->service(TransactionManager::class),
                new NullLogger(),
            ),
            $this->guard(),
        );
    }

    private function assertRefused(string $documentId): void
    {
        $refused = $this->thrownBy(fn (): ImageId => $this->materialise($documentId));

        $this->assertInstanceOf(DerivativeOriginNotLive::class, $refused);
        $this->assertSame('image-origin-not-live', $refused->type());
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

    private function guard(): DbalDocumentOriginGuard
    {
        return new DbalDocumentOriginGuard($this->connection());
    }

    private function release(): ReleaseDerivatives
    {
        $entityManager = $this->service(EntityManagerInterface::class);

        return new ReleaseDerivatives(
            new DoctrineImageRepository($entityManager),
            $this->service(TransactionManager::class),
            (new ImageReleaseRig($entityManager))->eventBus(),
        );
    }

    private function sweep(): ReleaseErasedDerivatives
    {
        return new ReleaseErasedDerivatives(
            $this->service(DocumentErasures::class),
            $this->release(),
            new Logger('test', [$this->log]),
        );
    }

    /**
     * Moves a planted row to `Erased` with its custody row `$secondsAgo` old, as the erasure transaction leaves it.
     */
    private function eraseRow(string $documentId, int $secondsAgo): void
    {
        $this->connection()->executeStatement(
            "UPDATE document SET state = 'erased', filename_ciphertext = NULL WHERE id = CAST(:id AS UUID)",
            ['id' => $documentId],
        );
        $this->connection()->executeStatement(
            'INSERT INTO document_custody (id, document_id, seq, event, actor_id, reason, created_at) '
            . 'VALUES (:id, :document, 1, :event, NULL, :reason, NOW() - make_interval(secs => :age))',
            [
                'id' => Uuid::generate(),
                'document' => $documentId,
                'event' => 'erased',
                'reason' => 'ledger-completion',
                'age' => $secondsAgo,
            ],
        );
    }

    private function releasedAt(string $documentId): mixed
    {
        return $this->outside->fetchOne(
            'SELECT derivatives_released_at FROM document WHERE id = CAST(:id AS UUID)',
            ['id' => $documentId],
        );
    }

    /**
     * @return list<string>
     */
    private function imageIds(): array
    {
        $ids = [];

        foreach ($this->outside->fetchFirstColumn('SELECT id FROM image ORDER BY id') as $id) {
            if (\is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Every object under the image storage root, so a case proves the refused bytes did not stay behind.
     *
     * @return list<string>
     */
    private function storedObjects(): array
    {
        $root = self::getContainer()->getParameter('erpify.images.storage_root');
        $this->assertIsString($root);

        if (!\is_dir($root)) {
            return [];
        }

        $objects = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $objects[] = $file->getPathname();
            }
        }

        \sort($objects);

        return $objects;
    }

    private function png(): string
    {
        return (string) \file_get_contents(\dirname(__DIR__, 3) . '/Fixtures/Images/valid.png');
    }

    private function assertGone(ImageId $imageId): void
    {
        $this->assertInstanceOf(
            ImageBytesNotFound::class,
            $this->thrownBy(fn (): string => $this->service(ImageStorage::class)->read($imageId)),
            'the bytes are gone',
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
