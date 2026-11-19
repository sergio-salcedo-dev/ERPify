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
use Erpify\Documents\Document\Application\Erasure\SweepOrphanDerivatives;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Erasure\DocumentDerivativeReleases;
use Erpify\Documents\Document\Domain\Erasure\DocumentErasures;
use Erpify\Documents\Document\Domain\Erasure\ErasureLimits;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReleaseErasedDerivativesHandler;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReleaseErasedDerivativesMessage;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DbalDocumentDerivativeReleases;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DbalDocumentOriginGuard;
use Erpify\Shared\Images\Application\DeleteImage;
use Erpify\Shared\Images\Application\DerivativeOrigins;
use Erpify\Shared\Images\Application\MaterialiseDerivative;
use Erpify\Shared\Images\Application\ReleaseDerivatives;
use Erpify\Shared\Images\Domain\DerivativeOrigin;
use Erpify\Shared\Images\Domain\Entity\Image;
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
use Erpify\Tests\Unit\Shared\Images\Application\Double\UndeletableImageStorage;
use FilesystemIterator;
use InvalidArgumentException;
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
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity") the same
 */
#[CoversClass(DbalDocumentOriginGuard::class)]
#[CoversClass(MaterialiseDocumentDerivative::class)]
#[CoversClass(ReleaseErasedDerivatives::class)]
#[CoversClass(ReleaseErasedDerivativesHandler::class)]
#[CoversClass(ReleaseDerivatives::class)]
#[CoversClass(MaterialiseDerivative::class)]
#[CoversClass(DoctrineImageRepository::class)]
#[CoversClass(DbalDocumentDerivativeReleases::class)]
#[CoversClass(SweepOrphanDerivatives::class)]
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
    public function aReadyDocumentAdmitsADerivativeThatCarriesItsOriginAndKeepsItsBytes(): void
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

        $this->assertSame(1, $this->sweep()->run(10), 'confirmed on the next tick, without waiting out the interval');
        $this->assertNotNull($this->releasedAt($document['id']));
        $this->assertSame(0, $this->sweep()->run(10), 'a released row is not asked about again');
        $this->assertSame(1, ImageReleaseRig::queuedFor($this->outside, $imageId), 'and nothing more is queued');
    }

    #[Test]
    public function aReleaseLostFromTheQueueIsQueuedAgainOnceTheRetryIntervalHasPassed(): void
    {
        $document = $this->plantNamedDocument(null, DocumentState::Ready);
        $imageId = $this->materialise($document['id'])->toString();
        $this->eraseRow($document['id'], 30);
        $this->sweep()->run(10);
        ImageReleaseRig::purge($this->outside);

        $this->sweep()->run(10);
        $this->assertSame(0, ImageReleaseRig::queuedFor($this->outside, $imageId), 'not while a retry may still run');

        $this->requestedSecondsAgo($document['id'], ErasureLimits::DERIVATIVE_REREQUEST_SECONDS + 60);
        $this->sweep()->run(10);
        $this->assertSame(1, ImageReleaseRig::queuedFor($this->outside, $imageId), 're-derived from the origin');
        $this->assertNull($this->releasedAt($document['id']));
    }

    #[Test]
    public function aRowWaitingForItsRetryIntervalLeavesTheTickToTheRowsAfterIt(): void
    {
        $waiting = $this->plantNamedDocument(null, DocumentState::Ready);
        $this->materialise($waiting['id']);
        $this->eraseRow($waiting['id'], 30);
        $this->sweep()->run(1);
        $later = $this->plantNamedDocument(null, DocumentState::Ready);
        $this->eraseRow($later['id'], 30);

        $this->sweep()->run(1);

        $this->assertNotNull($this->releasedAt($later['id']), 'the pending row did not take its place');
    }

    #[Test]
    public function theAlarmNamesAtMostTwentyDocumentsAndCountsThemAll(): void
    {
        for ($i = 0; $i < 21; ++$i) {
            $document = $this->plantNamedDocument(null, DocumentState::Ready);
            $this->materialise($document['id']);
            $this->eraseRow($document['id'], ErasureLimits::DERIVATIVE_ALARM_SECONDS + 60);
        }

        $this->sweep()->run(50);

        $alarms = \array_values(\array_filter(
            $this->log->getRecords(),
            static fn (LogRecord $record): bool => self::ALARM === $record->message,
        ));
        $this->assertCount(1, $alarms);
        $this->assertSame(21, $alarms[0]->context['count'] ?? null);
        $ids = $alarms[0]->context['documentIds'] ?? null;
        $this->assertIsArray($ids);
        $this->assertCount(20, $ids);
    }

    #[Test]
    public function theGuardRefusesToJoinACallersTransaction(): void
    {
        $document = $this->plantNamedDocument(null, DocumentState::Ready);

        $failure = $this->thrownBy(fn () => $this->service(TransactionManager::class)->transactional(
            fn () => $this->service(TransactionManager::class)->transactional(
                fn (): bool => $this->guard()->admits(DocumentDerivativeOrigin::of($document['id'])),
            ),
        ));

        $this->assertInstanceOf(LogicException::class, $failure);
    }

    #[Test]
    public function aRefusedDerivativeWhoseBytesCannotBeDeletedQueuesTheirDeletionDurably(): void
    {
        $document = $this->plantNamedDocument(null, DocumentState::Erased);
        $storage = new UndeletableImageStorage();

        $this->assertInstanceOf(
            DerivativeOriginNotLive::class,
            $this->thrownBy(
                fn (): ImageId => $this->materialiser($storage)->materialise($document['id'], $this->png()),
            ),
        );

        $stored = \array_keys($storage->objects);
        $this->assertCount(1, $stored);
        $this->assertSame(1, ImageReleaseRig::queuedFor($this->outside, $stored[0]), 'committed, seen from outside');
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
    public function aReopenedErasureIsOverdueOnlyOnceTheBoundHasPassedSinceTheReopen(): void
    {
        $document = $this->plantNamedDocument(null, DocumentState::Ready);
        $this->eraseRow($document['id'], ErasureLimits::DERIVATIVE_ALARM_SECONDS + 60);
        $this->connection()->executeStatement(
            'UPDATE document SET derivatives_released_at = NOW() WHERE id = CAST(:id AS UUID)',
            ['id' => $document['id']],
        );
        $releases = $this->service(DocumentDerivativeReleases::class);

        $this->assertTrue($this->service(DocumentErasures::class)->reopenObjectDeletion($document['id']));
        $this->assertSame(
            [],
            $releases->derivativesOverdue(ErasureLimits::DERIVATIVE_ALARM_SECONDS, 10),
            'a restore does not make an old erasure overdue at once',
        );

        $this->connection()->executeStatement(
            'UPDATE document SET erasure_reopened_at = NOW() - make_interval(secs => :age) '
            . 'WHERE id = CAST(:id AS UUID)',
            ['id' => $document['id'], 'age' => ErasureLimits::DERIVATIVE_ALARM_SECONDS + 60],
        );
        $this->assertSame(
            [$document['id']],
            $releases->derivativesOverdue(ErasureLimits::DERIVATIVE_ALARM_SECONDS, 10),
            'past the bound since the reopen it is overdue again',
        );
    }

    #[Test]
    public function aRestoreReplayReopensTheDerivativeMarkersInTheStatementThatReopensTheObject(): void
    {
        $document = $this->plantNamedDocument(null, DocumentState::Ready);
        $this->eraseRow($document['id'], 30);
        $this->connection()->executeStatement(
            'UPDATE document SET derivatives_released_at = NOW(), derivatives_requested_at = NOW() '
            . 'WHERE id = CAST(:id AS UUID)',
            ['id' => $document['id']],
        );

        $this->assertTrue($this->service(DocumentErasures::class)->reopenObjectDeletion($document['id']));

        $this->assertNotNull($this->outside->fetchOne(
            'SELECT erasure_reopened_at FROM document WHERE id = CAST(:id AS UUID)',
            ['id' => $document['id']],
        ), 'the reopen is stamped');
        $this->assertNull($this->releasedAt($document['id']));
        $this->assertNull($this->outside->fetchOne(
            'SELECT derivatives_requested_at FROM document WHERE id = CAST(:id AS UUID)',
            ['id' => $document['id']],
        ));
    }

    #[Test]
    public function theOrphanSweepReleasesWhatNoLiveDocumentAccountsForAndNamesIt(): void
    {
        $absent = Uuid::generate();
        $ofAbsent = $this->plantDerivativeOf($absent);
        $confirmed = $this->plantNamedDocument(null, DocumentState::Ready);
        $this->eraseRow($confirmed['id'], 30);
        $this->connection()->executeStatement(
            'UPDATE document SET derivatives_released_at = NOW() WHERE id = CAST(:id AS UUID)',
            ['id' => $confirmed['id']],
        );
        $ofConfirmed = $this->plantDerivativeOf($confirmed['id']);
        $live = $this->plantNamedDocument(null, DocumentState::Ready);
        $ofLive = $this->materialise($live['id'])->toString();
        $pending = $this->plantNamedDocument(null, DocumentState::Ready);
        $ofPending = $this->materialise($pending['id'])->toString();
        $this->eraseRow($pending['id'], 30);

        $this->assertSame(2, $this->orphanSweep()->run(1));

        $this->assertSame(1, ImageReleaseRig::queuedFor($this->outside, $ofAbsent), 'a document row that is gone');
        $this->assertSame(1, ImageReleaseRig::queuedFor($this->outside, $ofConfirmed), 'a release a restore undid');
        $this->assertSame(0, ImageReleaseRig::queuedFor($this->outside, $ofLive), 'a Ready document keeps it');
        $this->assertSame(0, ImageReleaseRig::queuedFor($this->outside, $ofPending), 'the release sweep owns it');
        $lines = \array_values(\array_filter(
            $this->log->getRecords(),
            static fn (LogRecord $record): bool => 'Derivatives were found that no live document accounts for.'
                === $record->message,
        ));
        $this->assertCount(1, $lines);
        $this->assertSame(Level::Error, $lines[0]->level);
        $ids = $lines[0]->context['documentIds'] ?? null;
        $this->assertIsArray($ids);
        \sort($ids);
        $expected = [\strtolower($absent), $confirmed['id']];
        \sort($expected);
        $this->assertSame($expected, $ids);
    }

    #[Test]
    public function theOrphanSweepIsSilentWhenEveryDerivativeHasALiveDocument(): void
    {
        $live = $this->plantNamedDocument(null, DocumentState::Ready);
        $this->materialise($live['id']);

        $this->assertSame(0, $this->orphanSweep()->run(10));
        $this->assertSame([], $this->log->getRecords());
    }

    #[Test]
    public function anOriginWhoseLastDerivativeWentBeforeItsReleaseIsNoAnomaly(): void
    {
        $confirmed = $this->plantNamedDocument(null, DocumentState::Ready);
        $this->eraseRow($confirmed['id'], 30);
        $this->connection()->executeStatement(
            'UPDATE document SET derivatives_released_at = NOW() WHERE id = CAST(:id AS UUID)',
            ['id' => $confirmed['id']],
        );

        $this->assertSame(0, $this->orphanSweep([[$confirmed['id']]])->run(10));
        $this->assertSame([], $this->log->getRecords());
    }

    #[Test]
    public function aPageReadThatFailsStillReportsTheOrphansAlreadyReleased(): void
    {
        $absent = Uuid::generate();
        $this->plantDerivativeOf($absent);
        $failure = new RuntimeException('page read failed');

        $this->assertSame(
            $failure,
            $this->thrownBy(fn (): int => $this->orphanSweep([[\strtolower($absent)], $failure])->run(1)),
        );

        $lines = \array_values(\array_filter(
            $this->log->getRecords(),
            static fn (LogRecord $record): bool => 'Derivatives were found that no live document accounts for.'
                === $record->message,
        ));
        $this->assertCount(1, $lines);
        $this->assertSame([\strtolower($absent)], $lines[0]->context['documentIds'] ?? null);
    }

    #[Test]
    public function theOrphanSweepRefusesAnEmptyPage(): void
    {
        $this->assertInstanceOf(
            InvalidArgumentException::class,
            $this->thrownBy(fn (): int => $this->orphanSweep()->run(0)),
        );
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
    private function materialiser(?ImageStorage $storage = null): MaterialiseDocumentDerivative
    {
        return new MaterialiseDocumentDerivative(
            new MaterialiseDerivative(
                $this->processor(),
                $storage ?? $this->service(ImageStorage::class),
                $this->service(ImageRepository::class),
                $this->service(TransactionManager::class),
                (new ImageReleaseRig($this->service(EntityManagerInterface::class)))->eventBus(),
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
            $this->service(DocumentDerivativeReleases::class),
            $this->release(),
            $this->origins(),
            new Logger('test', [$this->log]),
        );
    }

    /**
     * @param list<list<string>|Throwable>|null $pages the origin pages to walk instead of the table's, when given
     */
    private function orphanSweep(?array $pages = null): SweepOrphanDerivatives
    {
        $origins = null === $pages ? $this->origins() : new DerivativeOrigins(new ScriptedOriginPages(
            new DoctrineImageRepository($this->service(EntityManagerInterface::class)),
            $pages,
        ));

        return new SweepOrphanDerivatives(
            $origins,
            $this->service(DocumentDerivativeReleases::class),
            $this->release(),
            new Logger('test', [$this->log]),
        );
    }

    private function origins(): DerivativeOrigins
    {
        return new DerivativeOrigins(new DoctrineImageRepository($this->service(EntityManagerInterface::class)));
    }

    /**
     * A derivative row and its bytes written straight through the repository and the storage, past the guard: the
     * shape a restore or a document row that vanished leaves behind, which no admitted path can produce.
     */
    private function plantDerivativeOf(string $documentId): string
    {
        $bytes = 'derivative-bytes';
        $image = new Image(
            ImageId::generate(),
            \hash('sha256', $bytes),
            'image/png',
            1,
            1,
            \strlen($bytes),
            DocumentDerivativeOrigin::of($documentId),
        );
        $this->service(ImageStorage::class)->store($image->id(), $bytes);
        $this->service(TransactionManager::class)->transactional(function () use ($image): void {
            $this->service(ImageRepository::class)->save($image);
        });

        return $image->id()->toString();
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

    private function requestedSecondsAgo(string $documentId, int $seconds): void
    {
        $this->connection()->executeStatement(
            'UPDATE document SET derivatives_requested_at = NOW() - make_interval(secs => :age) '
            . 'WHERE id = CAST(:id AS UUID)',
            ['id' => $documentId, 'age' => $seconds],
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
