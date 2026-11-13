<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Exception\DocumentNotFound;
use Erpify\Documents\Document\Domain\Exception\DocumentNotRescannable;
use Erpify\Documents\Document\Domain\Scanning\RescanReason;
use Erpify\Documents\Document\Infrastructure\Controller\RescanDocumentController;
use Erpify\Documents\Document\Infrastructure\Http\RescanDocumentRequest;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use Erpify\Tests\Support\Dig;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/v1/documents/{id}/rescan` through the kernel, against real Postgres: who may call it, what it answers
 * for each state and each bad payload, and what the decision leaves in custody. The acceptance scenarios say the same
 * through Behat; this is the lane that credits the controller and its request to PHPUnit.
 *
 * @internal
 */
#[CoversClass(RescanDocumentController::class)]
#[CoversClass(RescanDocumentRequest::class)]
#[CoversClass(DocumentNotRescannable::class)]
#[CoversClass(DocumentNotFound::class)]
final class RescanDocumentRouteFunctionalTest extends WebTestCase
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
    }

    protected function tearDown(): void
    {
        $this->emptyDocumentTables();
        parent::tearDown();
    }

    #[Test]
    #[DataProvider('provideAnAdministratorReopensAFailedScanAndTheReasonIsInCustodyCases')]
    public function anAdministratorReopensAFailedScanAndTheReasonIsInCustody(string $reason): void
    {
        $this->authenticateAdminClient($this->client);
        $id = $this->plantDocument(Uuid::generate(), DocumentState::ScanFailed);
        $this->plantCustody($id, 1, Uuid::generate());

        $this->post($id, ['reason' => $reason]);

        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), $this->body());
        $this->assertSame('received', Dig::value($this->data(), 'state'));
        $this->assertSame($id, Dig::value($this->data(), 'id'));
        $this->assertSame(
            $reason,
            $this->connection()->fetchOne(
                'SELECT reason FROM document_custody WHERE document_id = CAST(:id AS UUID) '
                . "AND event = 'rescan_requested'",
                ['id' => $id],
            ),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnAdministratorReopensAFailedScanAndTheReasonIsInCustodyCases(): iterable
    {
        foreach (RescanReason::cases() as $reason) {
            yield $reason->value => [$reason->value];
        }
    }

    #[Test]
    public function aManagerWhoMayFileEvidenceMayNotReopenAFailedScan(): void
    {
        $this->authenticateClient($this->client);
        $id = $this->plantDocument(Uuid::generate(), DocumentState::ScanFailed);

        $this->post($id, ['reason' => 'failure_investigated']);

        $this->assertStatus(Response::HTTP_FORBIDDEN);
        $this->assertSame('scan_failed', $this->scanRow($id)['state']);
    }

    #[Test]
    public function aDocumentThatIsNotFailedIsAConflictAndAnAbsentOneIsNotFound(): void
    {
        $this->authenticateAdminClient($this->client);
        $ready = $this->plantDocument(Uuid::generate(), DocumentState::Ready);

        $this->post($ready, ['reason' => 'failure_investigated']);
        $this->assertStatus(Response::HTTP_CONFLICT);
        $this->assertSame('document-not-rescannable', Dig::value($this->decoded(), 'type'));

        $this->post(Uuid::generate(), ['reason' => 'failure_investigated']);
        $this->assertStatus(Response::HTTP_NOT_FOUND);
        $this->assertSame('document-not-found', Dig::value($this->decoded(), 'type'));
    }

    #[Test]
    public function aReasonOutsideTheClosedListOrAnUndeclaredMemberIsRefusedBeforeTheDocumentIsTouched(): void
    {
        $this->authenticateAdminClient($this->client);
        $id = $this->plantDocument(Uuid::generate(), DocumentState::ScanFailed);

        foreach ([['reason' => 'because'], [], ['reason' => 'failure_investigated', 'state' => 'ready']] as $payload) {
            $this->post($id, $payload);

            $this->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->assertSame('scan_failed', $this->scanRow($id)['state']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $id, array $payload): void
    {
        $this->client->request(
            Request::METHOD_POST,
            '/api/v1/documents/' . $id . '/rescan',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) \json_encode($payload),
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
