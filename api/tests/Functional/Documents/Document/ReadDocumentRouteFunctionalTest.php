<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Doctrine\DBAL\DriverManager;
use Erpify\Documents\Document\Application\Read\ReadDocument;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Exception\DocumentIntegrityFailure;
use Erpify\Documents\Document\Infrastructure\Controller\ReadDocumentController;
use Erpify\Documents\Document\Infrastructure\Http\UploadRoutes;
use Erpify\Documents\Document\Infrastructure\Security\DocumentPermission;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Double\Erasure\InactiveErasureLedgerOverride;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use Erpify\Tests\Support\Dig;
use Erpify\Tests\Unit\Documents\Document\Infrastructure\Http\RecordingTimeBudget;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * `GET /api/v1/documents/{id}/content` through the kernel, against real Postgres and the real sealer: who may call
 * it, what it answers for each refusal, the headers a browser would honour, and the one audit row. The use case's
 * own matrix is `ReadDocumentFunctionalTest`; this is the lane that credits the controller and the route
 * declaration to PHPUnit.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the route, its audit, its storage and its session
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") each public method is one independent answer of the route
 */
#[CoversClass(ReadDocumentController::class)]
#[CoversClass(DocumentIntegrityFailure::class)]
final class ReadDocumentRouteFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;
    use PlantsScannableDocuments;

    private KernelBrowser $client;

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->emptyDocumentTables();
        $this->deleteAuditRows();
    }

    protected function tearDown(): void
    {
        $this->discardScannableObjects();
        $this->deleteAuditRows();
        $this->emptyDocumentTables();
        parent::tearDown();
    }

    #[Test]
    public function anAdministratorReadsTheOriginalWithTheHardenedHeadersAndOneAuditRow(): void
    {
        $plaintext = \random_bytes(300_000);
        $this->authenticateAdminClient($this->client);
        $id = $this->fileReadyDocument($plaintext);

        $this->get($id);

        $response = $this->client->getResponse();
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame($plaintext, $this->client->getInternalResponse()->getContent());

        $headers = $response->headers;
        $this->assertSame('application/octet-stream', $headers->get('Content-Type'));
        $this->assertSame('300000', $headers->get('Content-Length'));
        $this->assertSame('attachment', $headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        $this->assertSame('same-origin', $headers->get('Cross-Origin-Resource-Policy'));
        // Under dev and test the web profiler appends `script-src`/`style-src` with a nonce to any CSP (production does
        // not register it): the policy is exact up to what it appends, and nothing else may follow.
        $directives = \array_map(trim(...), \explode(';', (string) $headers->get('Content-Security-Policy')));
        $this->assertSame(["default-src 'none'", 'sandbox'], \array_slice($directives, 0, 2));
        $this->assertSame(
            [],
            \array_diff(
                \array_map(static fn (string $directive): string => \explode(' ', $directive)[0], $directives),
                ['default-src', 'sandbox', 'script-src', 'style-src'],
            ),
        );
        // The stateful firewall's session listener adds its own directives around the one the controller sets.
        $this->assertSame('max-age=0, must-revalidate, no-store, private', $headers->get('Cache-Control'));
        $this->assertFalse($headers->has('ETag'));
        $this->assertFalse($headers->has('Last-Modified'));

        $this->assertSame(
            1,
            $this->auditRowsOnASecondConnection(ReadDocument::AUDIT_ACTION),
            'one row, committed: a second connection sees it',
        );
        $this->assertSame(0, $this->auditRows('ROUTE_DOCUMENTS_READ'), 'the generic row yields to the canonical one');
    }

    #[Test]
    public function theStallBudgetIsRearmedBeforeTheStreamAndAtEveryChunk(): void
    {
        $plaintext = \random_bytes(300_000);
        $id = $this->fileReadyDocument($plaintext);
        $budget = new RecordingTimeBudget();
        $controller = new ReadDocumentController(
            $this->service(ReadDocument::class),
            new InactiveErasureLedgerOverride(),
            $budget,
            true,
        );

        $response = $controller(new Request(), $id);
        $this->assertSame([], $budget->armed(), 'nothing is armed until the body is sent');

        \ob_start();
        $response->sendContent();
        $sent = (string) \ob_get_clean();

        $this->assertSame($plaintext, $sent);
        // Once before the stream, then once per chunk: 300,000 bytes arrive in more than one chunk.
        $this->assertGreaterThan(2, \count($budget->armed()));
        $this->assertSame([60], \array_values(\array_unique($budget->armed())));
    }

    #[Test]
    public function aRangeRequestIsIgnoredAndTheWholeOriginalIsServed(): void
    {
        $plaintext = \random_bytes(5_000);
        $this->authenticateAdminClient($this->client);
        $id = $this->fileReadyDocument($plaintext);

        $this->client->request(Request::METHOD_GET, '/api/v1/documents/' . $id . '/content', server: [
            'HTTP_RANGE' => 'bytes=0-9',
        ]);

        $this->assertStatus(Response::HTTP_OK);
        $this->assertSame($plaintext, $this->client->getInternalResponse()->getContent());
        $this->assertFalse($this->client->getResponse()->headers->has('Accept-Ranges'));
    }

    #[Test]
    public function aManagerWhoMayFileEvidenceMayNotReadItAndNothingIsWritten(): void
    {
        $this->authenticateClient($this->client);
        $id = $this->fileReadyDocument('evidence');

        $this->get($id);

        $this->assertStatus(Response::HTTP_FORBIDDEN);
        $this->assertSame(0, $this->auditRows(ReadDocument::AUDIT_ACTION));
    }

    #[Test]
    public function aMalformedIdIsABadRequestAndAnUnknownOneIsNotFound(): void
    {
        $this->authenticateAdminClient($this->client);

        $this->client->request(Request::METHOD_GET, '/api/v1/documents/not-a-uuid/content');
        $this->assertStatus(Response::HTTP_BAD_REQUEST);
        $this->assertSame('invalid-uuid', $this->problemType());

        $this->get(Uuid::generate());
        $this->assertStatus(Response::HTTP_NOT_FOUND);
        $this->assertSame('document-not-found', $this->problemType());
        $this->assertSame(0, $this->auditRows(ReadDocument::AUDIT_ACTION));
    }

    #[Test]
    public function aDocumentThatIsNotReadyIsAConflictAndAnErasedOneIsGone(): void
    {
        $this->authenticateAdminClient($this->client);

        $quarantined = $this->plantDocument(Uuid::generate(), DocumentState::Quarantined);
        $this->get($quarantined);
        $this->assertStatus(Response::HTTP_CONFLICT);
        $this->assertSame('document-not-readable', $this->problemType());

        $erased = $this->plantDocument(Uuid::generate(), DocumentState::Erased);
        $this->get($erased);
        $this->assertStatus(Response::HTTP_GONE);
        $this->assertSame('gone', $this->problemType());
        $this->assertSame(0, $this->auditRows(ReadDocument::AUDIT_ACTION));
    }

    #[Test]
    public function anAlteredObjectIsAnIntegrityFailureBeforeAnyByteAndWritesNoRow(): void
    {
        $this->authenticateAdminClient($this->client);
        $id = $this->fileReadyDocument(\random_bytes(100_000));
        $this->flipAByteOfTheFinalObject($id);

        $this->get($id);

        $this->assertStatus(Response::HTTP_INTERNAL_SERVER_ERROR);
        $this->assertSame(DocumentIntegrityFailure::TYPE, $this->problemType());
        $this->assertSame(0, $this->auditRows(ReadDocument::AUDIT_ACTION));
    }

    #[Test]
    public function anUpperCasedIdIsServedAndAuditedUnderTheCanonicalId(): void
    {
        $plaintext = \random_bytes(5_000);
        $this->authenticateAdminClient($this->client);
        $id = $this->fileReadyDocument($plaintext);

        $this->get(\strtoupper($id));

        $this->assertStatus(Response::HTTP_OK);
        $this->assertSame($plaintext, $this->client->getInternalResponse()->getContent());
        $this->assertSame(
            [$id],
            $this->connection()->fetchFirstColumn(
                'SELECT resource_id::text FROM audit_log WHERE action = :action',
                ['action' => ReadDocument::AUDIT_ACTION],
            ),
        );
    }

    #[Test]
    public function aHeadRequestIsRefusedBeforeAnythingIsDecryptedOrAudited(): void
    {
        $this->authenticateAdminClient($this->client);
        $id = $this->fileReadyDocument(\random_bytes(5_000));
        // An altered object would answer 500 if either pass ran: the refusal must come first.
        $this->flipAByteOfTheFinalObject($id);

        $this->client->request(Request::METHOD_HEAD, '/api/v1/documents/' . $id . '/content');

        $this->assertStatus(Response::HTTP_METHOD_NOT_ALLOWED);
        $this->assertSame(0, $this->auditRows(ReadDocument::AUDIT_ACTION));
    }

    #[Test]
    public function aFailedAuditWriteIsA5xxProblemWithNoByteOfTheOriginalAndNoRow(): void
    {
        $plaintext = \random_bytes(5_000);
        $this->authenticateAdminClient($this->client);
        $id = $this->fileReadyDocument($plaintext);
        // The audit store refuses this one action, through the real writer: the insert itself fails.
        $this->connection()->executeStatement(
            'ALTER TABLE audit_log ADD CONSTRAINT test_refuse_document_read '
            . "CHECK (action <> 'DOCUMENT_READ') NOT VALID",
        );

        try {
            $this->get($id);
        } finally {
            $this->connection()->executeStatement('ALTER TABLE audit_log DROP CONSTRAINT test_refuse_document_read');
        }

        $response = $this->client->getResponse();

        $this->assertGreaterThanOrEqual(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        $this->assertNotSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString($plaintext, $this->client->getInternalResponse()->getContent());
        $this->assertSame(0, $this->auditRows(ReadDocument::AUDIT_ACTION));
    }

    #[Test]
    public function aCallerWithNoSessionIsRefusedAsUnauthenticated(): void
    {
        $this->get(Uuid::generate());

        $this->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function theRouteIsDeclaredGatedAuditedByItsUseCaseAndNamedOutsideTheAuditOptOut(): void
    {
        $router = self::getContainer()->get(RouterInterface::class);
        $this->assertInstanceOf(RouterInterface::class, $router);
        $route = $router->getRouteCollection()->get(UploadRoutes::READ);

        $this->assertInstanceOf(Route::class, $route, 'the route is registered whatever the switch says');
        $this->assertSame('/api/v1/documents/{id}/content', $route->getPath());
        $this->assertSame(['GET'], $route->getMethods());
        $this->assertTrue($route->getDefault('_audit_canonical'));
        $this->assertStringStartsNotWith('shared_', UploadRoutes::READ);

        $grants = (new ReflectionClass(ReadDocumentController::class))->getAttributes(IsGranted::class);
        $this->assertCount(1, $grants);
        $this->assertSame(DocumentPermission::DOWNLOAD, $grants[0]->newInstance()->attribute);
    }

    private function assertStatus(int $expected): void
    {
        $this->assertSame($expected, $this->client->getResponse()->getStatusCode(), $this->body());
    }

    private function body(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    private function get(string $id): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v1/documents/' . $id . '/content');
    }

    private function problemType(): string
    {
        $decoded = \json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertIsArray($decoded);

        return $this->stringOf(Dig::value($decoded, 'type'));
    }

    private function fileReadyDocument(string $plaintext): string
    {
        $id = $this->fileScannableDocument($plaintext);
        $this->connection()->executeStatement(
            "UPDATE document SET state = 'ready' WHERE id = CAST(:id AS UUID)",
            ['id' => $id],
        );

        return $id;
    }

    /**
     * Counted on a connection of its own: the application's connection sees its own uncommitted rows, a second one
     * sees only what committed.
     */
    private function auditRowsOnASecondConnection(string $action): int
    {
        $other = DriverManager::getConnection($this->connection()->getParams());

        try {
            return $this->intOf($other->fetchOne(
                'SELECT COUNT(*) FROM audit_log WHERE action = :action',
                ['action' => $action],
            ));
        } finally {
            $other->close();
        }
    }

    private function auditRows(string $action): int
    {
        return $this->intOf($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM audit_log WHERE action = :action',
            ['action' => $action],
        ));
    }

    private function deleteAuditRows(): void
    {
        $this->connection()->executeStatement(
            'DELETE FROM audit_log WHERE action IN (:read, :generic)',
            ['read' => ReadDocument::AUDIT_ACTION, 'generic' => 'ROUTE_DOCUMENTS_READ'],
        );
    }
}
