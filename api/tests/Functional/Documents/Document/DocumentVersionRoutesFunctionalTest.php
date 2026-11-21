<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Infrastructure\Controller\DeclareVersionController;
use Erpify\Documents\Document\Infrastructure\Controller\DocumentSeriesController;
use Erpify\Documents\Document\Infrastructure\Controller\EraseDocumentSeriesController;
use Erpify\Documents\Document\Infrastructure\Http\DocumentSeriesMapper;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use Erpify\Tests\Support\Dig;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The three versioning routes through the kernel, against real Postgres: who may call each, what each answers, and the
 * reopen of a failed version whose slot a sibling holds, end to end through the re-scan route.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(DeclareVersionController::class)]
#[CoversClass(DocumentSeriesController::class)]
#[CoversClass(EraseDocumentSeriesController::class)]
#[CoversClass(DocumentSeriesMapper::class)]
final class DocumentVersionRoutesFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;
    use PlantsVersionSeries;

    private KernelBrowser $client;

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->emptyDocumentTables();
    }

    protected function tearDown(): void
    {
        $this->emptyDocumentTables();
        parent::tearDown();
    }

    #[Test]
    public function aVersionOfAReadyDocumentIsDeclaredByWhoeverMayWriteAndDownload(): void
    {
        $this->authenticateAdminClient($this->client);
        $first = $this->plantVersion(DocumentState::Ready);

        $this->send(Request::METHOD_POST, '/api/v1/documents/' . $first . '/versions', $this->declaration());

        $this->assertStatus(Response::HTTP_CREATED);
        $this->assertSame('declared', Dig::value($this->data(), 'state'));
        $intentId = Dig::string($this->data(), 'id');
        $this->forgetAfterTest($intentId);
        $this->assertSame($first, $this->connection()->fetchOne(
            'SELECT predecessor_id FROM upload_intent WHERE id = CAST(:id AS UUID)',
            ['id' => $intentId],
        ));
    }

    #[Test]
    public function aCallerThatMayWriteButNotDownloadMayNotDeclareAVersion(): void
    {
        $this->authenticateClient($this->client);
        $first = $this->plantVersion(DocumentState::Ready);

        $this->send(Request::METHOD_POST, '/api/v1/documents/' . $first . '/versions', $this->declaration());

        $this->assertStatus(Response::HTTP_FORBIDDEN);
        $this->assertSame(0, $this->intOf($this->connection()->fetchOne('SELECT COUNT(*) FROM upload_intent')));
    }

    #[Test]
    public function anAbsentPredecessorAnswersLikeOneThatIsNotReadyAndAHeldSlotIsItsOwnConflict(): void
    {
        $this->authenticateAdminClient($this->client);
        $received = $this->plantVersion(DocumentState::Received);
        $first = $this->plantVersion(DocumentState::Ready);
        $this->plantVersion(DocumentState::Ready, $first);

        foreach ([Uuid::generate(), $received] as $predecessor) {
            $this->send(Request::METHOD_POST, '/api/v1/documents/' . $predecessor . '/versions', $this->declaration());
            $this->assertStatus(Response::HTTP_CONFLICT);
            $this->assertSame('document-predecessor-not-ready', Dig::value($this->decoded(), 'type'));
        }

        $this->send(Request::METHOD_POST, '/api/v1/documents/' . $first . '/versions', $this->declaration());
        $this->assertStatus(Response::HTTP_CONFLICT);
        $this->assertSame('document-version-slot-taken', Dig::value($this->decoded(), 'type'));

        $this->send(Request::METHOD_POST, '/api/v1/documents/not-a-uuid/versions', $this->declaration());
        $this->assertStatus(Response::HTTP_BAD_REQUEST);
        $this->assertSame(0, $this->intOf($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM upload_intent WHERE predecessor_id IS NOT NULL',
        )));
    }

    #[Test]
    public function theSeriesPageIsAnAdministratorsReadAndShowsATombstoneWithoutItsUploader(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $erased = $this->plantVersion(DocumentState::Erased, $first);

        $this->authenticateClient($this->client);
        $this->send(Request::METHOD_GET, '/api/v1/documents/series/' . $first);
        $this->assertStatus(Response::HTTP_FORBIDDEN);

        $this->authenticateAdminClient($this->client);
        $this->send(Request::METHOD_GET, '/api/v1/documents/series/' . $first);

        $this->assertStatus(Response::HTTP_OK);
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertSame($first, Dig::value($this->data(), 'seriesId'));
        $this->assertSame($erased, Dig::value($this->data(), 'versions', 1, 'id'));
        $this->assertSame('erased', Dig::value($this->data(), 'versions', 1, 'state'));
        $this->assertNull(Dig::value($this->data(), 'versions', 1, 'uploaderId'));
        $this->assertIsString(Dig::value($this->data(), 'versions', 0, 'uploaderId'));
        $this->assertNull(Dig::value($this->data(), 'nextAfterId'));

        $this->send(Request::METHOD_GET, '/api/v1/documents/series/' . $erased);
        $this->assertStatus(Response::HTTP_NOT_FOUND);

        $this->send(Request::METHOD_GET, '/api/v1/documents/series/' . $first . '?afterId=nope');
        $this->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Test]
    public function aReopenOfAFailedVersionWhoseSlotASiblingHoldsIsAConflictEndToEnd(): void
    {
        $this->authenticateAdminClient($this->client);
        $first = $this->plantVersion(DocumentState::Ready);
        $failed = $this->plantVersion(DocumentState::ScanFailed, $first);
        $this->plantVersion(DocumentState::Ready, $first);

        $this->send(
            Request::METHOD_POST,
            '/api/v1/documents/' . $failed . '/rescan',
            ['reason' => 'failure_investigated'],
        );

        $this->assertStatus(Response::HTTP_CONFLICT);
        $this->assertSame('document-version-slot-taken', Dig::value($this->decoded(), 'type'));
        $this->assertSame('scan_failed', $this->stateOfDocument($failed));
    }

    #[Test]
    public function anAdministratorErasesASeriesAfterReprovingTheirPassword(): void
    {
        $first = $this->plantVersion(DocumentState::Ready);
        $second = $this->plantVersion(DocumentState::Ready, $first);

        $this->authenticateClient($this->client);
        $this->send(Request::METHOD_POST, $this->seriesErase($first), $this->erasure('functional-password'));
        $this->assertStatus(Response::HTTP_FORBIDDEN);

        $this->authenticateAdminClient($this->client);
        $this->send(Request::METHOD_POST, $this->seriesErase($first), $this->erasure('not-the-password'));
        $this->assertStatus(Response::HTTP_FORBIDDEN);
        $this->assertSame('invalid-current-password', Dig::value($this->decoded(), 'type'));
        $this->assertSame('ready', $this->stateOfDocument($second), 'a wrong password erases nothing');

        $this->send(Request::METHOD_POST, $this->seriesErase($first), $this->erasure('functional-password'));

        $this->assertStatus(Response::HTTP_OK);
        $this->assertSame([$second, $first], Dig::value($this->data(), 'erased'));
        $this->assertSame([], Dig::value($this->data(), 'remaining'));
        $this->assertSame('erased', $this->stateOfDocument($first));

        $this->send(Request::METHOD_POST, $this->seriesErase(Uuid::generate()), $this->erasure('functional-password'));
        $this->assertStatus(Response::HTTP_NOT_FOUND);
    }

    /**
     * @return array<string, mixed>
     */
    private function declaration(): array
    {
        return ['size' => 10, 'retentionReason' => 'technical-file', 'legalBasis' => 'contract'];
    }

    /**
     * @return array<string, string>
     */
    private function erasure(string $password): array
    {
        return ['reason' => 'retention_expired', 'currentPassword' => $password];
    }

    private function seriesErase(string $seriesId): string
    {
        return '/api/v1/documents/series/' . $seriesId . '/erase';
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function send(string $method, string $uri, ?array $payload = null): void
    {
        $this->client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: null === $payload ? null : (string) \json_encode($payload),
        );
    }

    private function assertStatus(int $expected): void
    {
        $this->assertSame($expected, $this->client->getResponse()->getStatusCode(), $this->body());
    }

    private function body(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decoded(): array
    {
        return (array) \json_decode($this->body(), true);
    }

    private function data(): mixed
    {
        return Dig::value($this->decoded(), 'data');
    }
}
