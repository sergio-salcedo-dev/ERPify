<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\Derivative\MaterialiseDocumentDerivative;
use Erpify\Documents\Document\Application\Erasure\EraseDocument;
use Erpify\Documents\Document\Application\Read\ReadDocument;
use Erpify\Documents\Document\Domain\Erasure\ErasureReason;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReleaseErasedDerivativesHandler;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReleaseErasedDerivativesMessage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ScanDueDocumentsHandler;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ScanDueDocumentsMessage;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DbalDocumentOriginGuard;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Images\Application\DeleteImage;
use Erpify\Shared\Images\Application\MaterialiseDerivative;
use Erpify\Shared\Images\Domain\Event\ImageDeletionRequested;
use Erpify\Shared\Images\Domain\Exception\DerivativeOriginNotLive;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Shared\Images\Domain\Repository\ImageRepository;
use Erpify\Shared\Images\Domain\Storage\ImageBytesNotFound;
use Erpify\Shared\Images\Domain\Storage\ImageStorage;
use Erpify\Shared\Images\Infrastructure\InterventionImageProcessor;
use Erpify\Shared\Images\Infrastructure\Messenger\DeleteImageOnDeletionRequested;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Double\Erasure\FakeErasureLedger;
use Erpify\Tests\Double\Scanner\FakeMalwareScanner;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Throwable;

/**
 * The acceptance of the Documents spec's success signal, end to end and through the routes a caller uses: an
 * uploaded drawing is stored byte-identical, is unreadable until scanned, and after its erasure leaves its digest and
 * its custody while every derivative is gone — its row and its stored bytes; the image route never served it. Each
 * step runs the production collaborator (the scan tick, the erasure, the release tick, the image deletion handler);
 * only the scanner and the ledger are the test environment's doubles, and the deletion request is taken off the
 * in-memory `async` transport and handed to the real handler, which is what a worker does.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the whole lifecycle in one place is the point of an acceptance
 */
#[CoversNothing]
final class DocumentDerivativeLifecycleFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;
    use PlantsScannableDocuments;

    private KernelBrowser $client;

    /** @var list<string> */
    private array $derivatives = [];

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        FakeMalwareScanner::reset();
        FakeErasureLedger::reset();
        $this->emptyDocumentTables();
        $this->deleteReadAuditRows();
    }

    protected function tearDown(): void
    {
        foreach ($this->derivatives as $derivative) {
            $this->service(DeleteImage::class)->delete(ImageId::fromString($derivative));
        }

        $this->discardScannableObjects();
        FakeMalwareScanner::reset();
        FakeErasureLedger::reset();
        $this->emptyDocumentTables();
        $this->deleteReadAuditRows();
        parent::tearDown();
    }

    /**
     * @SuppressWarnings("PHPMD.ExcessiveMethodLength") one scenario read top to bottom is what an acceptance is
     */
    #[Test]
    public function aDrawingIsKeptByteIdenticalReadableOnlyOnceScannedAndItsErasureLeavesProofButNoDerivative(): void
    {
        $drawing = \random_bytes(120_000);
        $this->authenticateAdminClient($this->client);
        $documentId = $this->fileScannableDocument($drawing);

        $this->readDocument($documentId);
        $this->assertStatus(Response::HTTP_CONFLICT, 'unreadable until scanned');

        ($this->service(ScanDueDocumentsHandler::class))(new ScanDueDocumentsMessage());

        $this->readDocument($documentId);
        $this->assertStatus(Response::HTTP_OK, 'readable once the verdict is clean');
        $this->assertSame($drawing, $this->client->getInternalResponse()->getContent(), 'byte-identical');

        $derivative = $this->materialiser()->materialise($documentId, $this->png())->toString();
        $this->derivatives[] = $derivative;
        $this->assertNotSame(
            '',
            $this->service(ImageStorage::class)->read(ImageId::fromString($derivative)),
            'the derivative is kept while its document lives',
        );
        $this->readImage($derivative);
        $this->assertStatus(Response::HTTP_NOT_FOUND, 'the route any session may call never serves evidence');

        $this->service(EraseDocument::class)->erase($documentId, ErasureReason::DataSubjectRequest, Uuid::generate());

        $this->assertInstanceOf(
            DerivativeOriginNotLive::class,
            $this->thrownBy(fn (): ImageId => $this->materialiser()->materialise($documentId, $this->png())),
            'a derivative that arrives after the erasure is refused',
        );

        $this->releaseTick();
        $this->deliverQueuedDeletions();
        $this->releaseTick();

        $this->readImage($derivative);
        $this->assertStatus(Response::HTTP_NOT_FOUND, 'and it stays unreachable');
        $this->assertNull($this->service(ImageRepository::class)->findById(ImageId::fromString($derivative)));

        $this->assertInstanceOf(
            ImageBytesNotFound::class,
            $this->thrownBy(fn (): string => $this->service(ImageStorage::class)->read(
                ImageId::fromString($derivative),
            )),
            'the derivative bytes are gone',
        );

        $this->assertSame(
            0,
            $this->rowsMatching('SELECT COUNT(*) FROM image WHERE origin_id = CAST(:id AS UUID)', $documentId),
            'no image with that origin remains',
        );
        $this->assertSame(
            1,
            $this->rowsMatching(
                'SELECT COUNT(*) FROM document WHERE id = CAST(:id AS UUID) AND derivatives_released_at IS NOT NULL',
                $documentId,
            ),
            'the release is confirmed',
        );
        $this->assertSame(
            \hash('sha256', $drawing),
            $this->connection()->fetchOne(
                'SELECT digest FROM document WHERE id = CAST(:id AS UUID)',
                ['id' => $documentId],
            ),
            'the digest survives the erasure',
        );
        $this->assertContains('erased', $this->custodyEvents($documentId), 'so does the custody trail');

        $this->readDocument($documentId);
        $this->assertStatus(Response::HTTP_GONE, 'the original is gone too');
        $this->derivatives = [];
    }

    /**
     * The read route writes its audit rows, and other suites count them: this case leaves none behind.
     */
    private function deleteReadAuditRows(): void
    {
        $this->connection()->executeStatement(
            'DELETE FROM audit_log WHERE action IN (:read, :generic)',
            ['read' => ReadDocument::AUDIT_ACTION, 'generic' => 'ROUTE_DOCUMENTS_READ'],
        );
    }

    private function releaseTick(): void
    {
        ($this->service(ReleaseErasedDerivativesHandler::class))(new ReleaseErasedDerivativesMessage());
    }

    /**
     * What a worker does with the `async` transport: each queued deletion request goes to its handler.
     */
    private function deliverQueuedDeletions(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        $this->assertInstanceOf(InMemoryTransport::class, $transport);
        $handler = $this->service(DeleteImageOnDeletionRequested::class);
        $delivered = 0;

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof ImageDeletionRequested) {
                $handler($message);
                ++$delivered;
            }
        }

        $this->assertGreaterThan(0, $delivered, 'the release tick queued a deletion');
        $transport->reset();
    }

    /**
     * Built from the container's own collaborators: nothing consumes the seam yet, so the container drops it.
     */
    private function materialiser(): MaterialiseDocumentDerivative
    {
        $container = self::getContainer();
        $integer = static fn (string $name): int => (int) $container->getParameter($name);

        return new MaterialiseDocumentDerivative(
            new MaterialiseDerivative(
                new InterventionImageProcessor(
                    $integer('erpify.images.max_input_bytes'),
                    $integer('erpify.images.max_decoded_pixels'),
                    $integer('erpify.images.max_input_dimension'),
                    $integer('erpify.images.max_output_dimension'),
                    $integer('erpify.images.encoding_quality'),
                    new NullLogger(),
                ),
                $this->service(ImageStorage::class),
                $this->service(ImageRepository::class),
                $this->service(TransactionManager::class),
                $this->service(EventBus::class),
                new NullLogger(),
            ),
            new DbalDocumentOriginGuard($this->connection()),
        );
    }

    private function readDocument(string $documentId): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v1/documents/' . $documentId . '/content');
    }

    private function readImage(string $imageId): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v1/images/' . $imageId);
    }

    private function assertStatus(int $expected, string $message): void
    {
        $response = $this->client->getResponse();
        $this->assertSame($expected, $response->getStatusCode(), $message . ': ' . $response->getContent());
    }

    private function rowsMatching(string $sql, string $documentId): int
    {
        $count = $this->connection()->fetchOne($sql, ['id' => $documentId]);

        return \is_numeric($count) ? (int) $count : -1;
    }

    private function png(): string
    {
        return (string) \file_get_contents(\dirname(__DIR__, 3) . '/Fixtures/Images/valid.png');
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
