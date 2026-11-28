<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\BankAccount;

use Erpify\Backoffice\BankAccount\Application\Evidence\AttachBankAccountEvidence;
use Erpify\Backoffice\BankAccount\Application\Evidence\DetachBankAccountEvidence;
use Erpify\Backoffice\BankAccount\Application\Evidence\ListBankAccountEvidence;
use Erpify\Backoffice\BankAccount\Application\Evidence\ReleaseBankAccountEvidence;
use Erpify\Backoffice\BankAccount\Infrastructure\Controller\BankAccountEvidenceAttachController;
use Erpify\Backoffice\BankAccount\Infrastructure\Controller\BankAccountEvidenceDetachController;
use Erpify\Backoffice\BankAccount\Infrastructure\Controller\BankAccountEvidenceListController;
use Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine\DoctrineBankAccountEvidenceRepository;
use Erpify\Documents\Document\Application\Evidence\DocumentEvidenceReader;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use Erpify\Tests\Functional\Documents\Document\PlantsNamedDocuments;
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
 * `POST`/`GET /api/v1/backoffice/bank-accounts/{id}/evidence` through the kernel, against real Postgres, and the
 * account's removal once it holds evidence: the attach and its server-side retention check, the listing with the names
 * opened and an erased document kept as its tombstone, the refusals, the audit row of the link, and the 409 that keeps
 * an account holding a live document from being deleted.
 *
 * Documents are planted already filed (sealed name, ownership-certificate terms) rather than uploaded: the upload is
 * the Documents suite's, and this one is about what the account does with a document it is handed by id.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the two routes, the account's removal and their audit
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") each public method is one independent answer of the routes
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") one scenario per answer, each with its own rows
 * @SuppressWarnings("PHPMD.TooManyMethods") the scenarios and the request helpers they share
 */
#[CoversClass(BankAccountEvidenceAttachController::class)]
#[CoversClass(BankAccountEvidenceListController::class)]
#[CoversClass(BankAccountEvidenceDetachController::class)]
#[CoversClass(DetachBankAccountEvidence::class)]
#[CoversClass(AttachBankAccountEvidence::class)]
#[CoversClass(ListBankAccountEvidence::class)]
#[CoversClass(ReleaseBankAccountEvidence::class)]
#[CoversClass(DoctrineBankAccountEvidenceRepository::class)]
#[CoversClass(DocumentEvidenceReader::class)]
final class BankAccountEvidenceRouteFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;
    use PlantsNamedDocuments;

    private const string BANK_ID = '0197f2b4-5555-7000-8000-000000000001';

    private const string ACCOUNT_ID = '0197f2b4-5555-7000-8000-000000000002';

    private const string IBAN = 'GB82WEST12345698765432';

    private const string OTHER_ACCOUNT_ID = '0197f2b4-5555-7000-8000-000000000003';

    private const string OTHER_IBAN = 'GB33BUKB20201555555555';

    private const string OVERRIDE_ID = '0197f2b4-5555-7000-8000-000000000009';

    private KernelBrowser $client;

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->removeTheAccount();
        $this->emptyDocumentTables();
        $this->deleteAuditRows();
        $this->plantTheAccount();
    }

    protected function tearDown(): void
    {
        $this->removeTheAccount();
        $this->emptyDocumentTables();
        $this->deleteAuditRows();
        parent::tearDown();
    }

    #[Test]
    public function anAdministratorAttachesAFiledDocumentAndListsItWithItsNameAndState(): void
    {
        $this->authenticateAdminClient($this->client);
        $document = $this->plantNamedDocument('ownership-certificate.pdf', DocumentState::Received);

        $this->attach($document['id']);

        $this->assertStatus(Response::HTTP_CREATED);
        $attached = $this->data();
        $this->assertSame(
            ['id', 'documentId', 'kind', 'name', 'state', 'digest', 'sizeBytes', 'attachedAt'],
            \array_keys($attached),
        );
        $this->assertSame($document['id'], Dig::value($attached, 'documentId'));
        $this->assertSame('ownership-certificate', Dig::value($attached, 'kind'));
        $this->assertSame('ownership-certificate.pdf', Dig::value($attached, 'name'));
        $this->assertSame('received', Dig::value($attached, 'state'));
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);

        $this->list();

        $this->assertStatus(Response::HTTP_OK);
        $listed = $this->dataList();
        $this->assertCount(1, $listed);
        $this->assertSame(Dig::value($attached, 'id'), Dig::value($listed, 0, 'id'));
        $this->assertSame('ownership-certificate.pdf', Dig::value($listed, 0, 'name'));
        $this->assertStringNotContainsString('scopeId', $this->body());
        $this->assertStringNotContainsString('uploaderId', $this->body());
    }

    #[Test]
    public function theLinkWritesOneNonPersonAuditRowNamingTheLinkAndNoName(): void
    {
        $this->authenticateAdminClient($this->client);
        $document = $this->plantNamedDocument('audited.pdf');

        $this->attach($document['id']);

        $this->assertStatus(Response::HTTP_CREATED);
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT resource_type, resource_id::text AS resource_id, metadata::text AS metadata, '
            . "encryption_scope_id FROM audit_log WHERE action = 'BANK_ACCOUNT_EVIDENCE_CREATED'",
        );
        $this->assertCount(1, $rows);
        $this->assertSame('BankAccountEvidence', Dig::value($rows, 0, 'resource_type'));
        $this->assertSame(Dig::value($this->data(), 'id'), Dig::value($rows, 0, 'resource_id'));
        $this->assertSame(
            'BankAccount:' . self::ACCOUNT_ID,
            Dig::value($rows, 0, 'encryption_scope_id'),
            "the document id is sealed under the account's scope, which its subject erasure destroys",
        );
        $this->assertStringNotContainsString($document['id'], Dig::string($rows, 0, 'metadata'));
        $this->assertStringContainsString(self::ACCOUNT_ID, Dig::string($rows, 0, 'metadata'));
        $this->assertStringNotContainsString('audited.pdf', Dig::string($rows, 0, 'metadata'));
    }

    #[Test]
    public function aDocumentFiledUnderOtherRetentionTermsIsRefusedAndNothingIsLinked(): void
    {
        $this->authenticateAdminClient($this->client);
        $document = $this->plantNamedDocument('invoice.pdf');
        $this->connection()->executeStatement(
            "UPDATE document SET retention_reason = 'tax-and-accounting' WHERE id = CAST(:id AS UUID)",
            ['id' => $document['id']],
        );

        $this->attach($document['id']);

        $this->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSame('bank-account-evidence-retention-mismatch', $this->problemType());
        $this->assertSame(0, $this->links());
    }

    #[Test]
    public function anErasedOrUnknownDocumentAndADuplicateAreRefused(): void
    {
        $this->authenticateAdminClient($this->client);
        $erased = $this->plantNamedDocument(null, DocumentState::Erased);
        $ready = $this->plantNamedDocument('ready.pdf');

        $this->attach($erased['id']);
        $this->assertStatus(Response::HTTP_CONFLICT);
        $this->assertSame('bank-account-evidence-document-erased', $this->problemType());

        $this->attach(Uuid::generate());
        $this->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSame('bank-account-evidence-document-not-found', $this->problemType());

        $this->attach($ready['id']);
        $this->assertStatus(Response::HTTP_CREATED);
        $this->attach($ready['id']);
        $this->assertStatus(Response::HTTP_CONFLICT);
        $this->assertSame('bank-account-evidence-already-attached', $this->problemType());
        $this->assertSame(1, $this->links());
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('provideABodyTheMappingRefusesIsUnprocessableCases')]
    public function aBodyTheMappingRefusesIsUnprocessable(array $body): void
    {
        $this->authenticateAdminClient($this->client);

        $this->post(self::ACCOUNT_ID, $body);

        $this->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSame('validation-failed', $this->problemType());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideABodyTheMappingRefusesIsUnprocessableCases(): iterable
    {
        yield 'no document' => [['kind' => 'ownership-certificate']];
        yield 'a document that is not a UUID' => [['documentId' => 'x', 'kind' => 'ownership-certificate']];
        yield 'no kind' => [['documentId' => '0197f2b4-5555-7000-8000-0000000000ff']];
        yield 'a kind outside the vocabulary' => [[
            'documentId' => '0197f2b4-5555-7000-8000-0000000000ff',
            'kind' => 'photo',
        ]];
        yield 'retention terms from the caller' => [[
            'documentId' => '0197f2b4-5555-7000-8000-0000000000ff',
            'kind' => 'ownership-certificate',
            'retentionReason' => 'tax-and-accounting',
        ]];
    }

    #[Test]
    public function aMalformedAccountIdIsABadRequestAndAnUnknownOneIsNotFound(): void
    {
        $this->authenticateAdminClient($this->client);
        $document = $this->plantNamedDocument('a.pdf');

        $this->post('not-a-uuid', ['documentId' => $document['id'], 'kind' => 'ownership-certificate']);
        $this->assertStatus(Response::HTTP_BAD_REQUEST);
        $this->assertSame('invalid-uuid', $this->problemType());

        $unknown = Uuid::generate();
        $this->post($unknown, ['documentId' => $document['id'], 'kind' => 'ownership-certificate']);
        $this->assertStatus(Response::HTTP_NOT_FOUND);
        $this->assertSame('bank-account-not-found', $this->problemType());

        $this->client->request(Request::METHOD_GET, '/api/v1/backoffice/bank-accounts/' . $unknown . '/evidence');
        $this->assertStatus(Response::HTTP_NOT_FOUND);

        $this->client->request(Request::METHOD_GET, '/api/v1/backoffice/bank-accounts/not-a-uuid/evidence');
        $this->assertStatus(Response::HTTP_BAD_REQUEST);
        $this->assertSame('invalid-uuid', $this->problemType());
    }

    #[Test]
    public function aQuarantinedDocumentAndAClosedAccountAreRefusedWhileAFailedScanIsAttached(): void
    {
        $this->authenticateAdminClient($this->client);
        $quarantined = $this->plantNamedDocument('infected.pdf', DocumentState::Quarantined);
        $failed = $this->plantNamedDocument('unscannable.pdf', DocumentState::ScanFailed);

        $this->attach($quarantined['id']);
        $this->assertStatus(Response::HTTP_CONFLICT);
        $this->assertSame('bank-account-evidence-document-not-admissible', $this->problemType());

        $this->attach($failed['id']);
        $this->assertStatus(Response::HTTP_CREATED);

        $this->connection()->executeStatement(
            "UPDATE bank_account SET status = 'CLOSED' WHERE id = CAST(:id AS UUID)",
            ['id' => self::ACCOUNT_ID],
        );
        $other = $this->plantNamedDocument('late.pdf');
        $this->attach($other['id']);
        $this->assertStatus(Response::HTTP_CONFLICT);
        $this->assertSame('bank-account-evidence-account-closed', $this->problemType());
        $this->assertSame(1, $this->links());
    }

    #[Test]
    public function aDocumentIsEvidenceOfOneAccountAndADetachFreesIt(): void
    {
        $this->authenticateAdminClient($this->client);
        $document = $this->plantNamedDocument('certificate.pdf');
        $otherAccount = $this->plantOtherAccount();

        $this->attach($document['id']);
        $this->assertStatus(Response::HTTP_CREATED);
        $linkId = Dig::string($this->data(), 'id');

        $this->post($otherAccount, ['documentId' => $document['id'], 'kind' => 'ownership-certificate']);
        $this->assertStatus(Response::HTTP_CONFLICT);
        $this->assertSame('bank-account-evidence-already-attached', $this->problemType());
        $this->assertStringNotContainsString(self::ACCOUNT_ID, $this->body(), 'the holding account is not named');

        $this->detach($otherAccount, $linkId);
        $this->assertStatus(Response::HTTP_NOT_FOUND);
        $this->assertSame('bank-account-evidence-not-found', $this->problemType());

        $this->detach(self::ACCOUNT_ID, 'not-a-uuid');
        $this->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->detach(self::ACCOUNT_ID, $linkId);
        $this->assertStatus(Response::HTTP_NO_CONTENT);
        $this->assertSame(0, $this->links());
        $this->assertSame(1, $this->intOf($this->connection()->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'BANK_ACCOUNT_EVIDENCE_DELETED' AND resource_id = :id",
            ['id' => $linkId],
        )));

        $this->post($otherAccount, ['documentId' => $document['id'], 'kind' => 'ownership-certificate']);
        $this->assertStatus(Response::HTTP_CREATED);
    }

    #[Test]
    public function theLedgerOverrideAnswersGoneOnTheListingWithNoLinkAndOnTheAttach(): void
    {
        $this->authenticateAdminClient($this->client);
        $document = $this->plantNamedDocument('during-restore.pdf');
        $this->connection()->executeStatement(
            'INSERT INTO erasure_ledger_override (id, reason, record_ref, set_at) VALUES (:id, :reason, :ref, NOW())',
            ['id' => self::OVERRIDE_ID, 'reason' => 'ledger_lost', 'ref' => Uuid::generate()],
        );

        $this->list();
        $this->assertStatus(Response::HTTP_GONE);
        $this->assertSame('gone', $this->problemType());

        $this->attach($document['id']);
        $this->assertStatus(Response::HTTP_GONE);
        $this->assertSame(0, $this->links(), 'the override refuses before the link is written');
    }

    #[Test]
    public function aManagerIsRefusedBothRoutesAndNoSessionIsUnauthenticated(): void
    {
        $document = $this->plantNamedDocument('secret.pdf');

        $this->list();
        $this->assertStatus(Response::HTTP_UNAUTHORIZED);

        $this->authenticateClient($this->client);
        $this->list();
        $this->assertStatus(Response::HTTP_FORBIDDEN);
        $this->attach($document['id']);
        $this->assertStatus(Response::HTTP_FORBIDDEN);
        $this->assertSame(0, $this->links());
    }

    #[Test]
    public function aHeadRequestIsRefusedBeforeAnythingIsOpened(): void
    {
        $this->authenticateAdminClient($this->client);

        $this->client->request(
            Request::METHOD_HEAD,
            '/api/v1/backoffice/bank-accounts/' . self::ACCOUNT_ID . '/evidence',
        );

        $this->assertStatus(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function anErasedDocumentListsAsItsTombstone(): void
    {
        $this->authenticateAdminClient($this->client);
        $document = $this->plantNamedDocument('kept-link.pdf');
        $this->attach($document['id']);
        $this->connection()->executeStatement(
            "UPDATE document SET state = 'erased', filename_ciphertext = NULL WHERE id = CAST(:id AS UUID)",
            ['id' => $document['id']],
        );

        $this->list();

        $this->assertStatus(Response::HTTP_OK);
        $this->assertSame('erased', Dig::value($this->dataList(), 0, 'state'));
        $this->assertNull(Dig::value($this->dataList(), 0, 'name'));
    }

    #[Test]
    public function aClosedAccountHoldingALiveDocumentIsNotDeletedUntilTheDocumentIsErased(): void
    {
        $this->authenticateAdminClient($this->client);
        $document = $this->plantNamedDocument('certificate.pdf', DocumentState::Ready);
        $this->attach($document['id']);
        $this->connection()->executeStatement(
            "UPDATE bank_account SET status = 'CLOSED' WHERE id = CAST(:id AS UUID)",
            ['id' => self::ACCOUNT_ID],
        );

        $this->client->request(Request::METHOD_DELETE, '/api/v1/backoffice/bank-accounts/' . self::ACCOUNT_ID);

        $this->assertStatus(Response::HTTP_CONFLICT);
        $this->assertSame('bank-account-has-live-evidence', $this->problemType());
        $this->assertSame(1, $this->links());
        $this->assertSame(1, $this->accounts());

        $this->connection()->executeStatement(
            "UPDATE document SET state = 'erased', filename_ciphertext = NULL WHERE id = CAST(:id AS UUID)",
            ['id' => $document['id']],
        );
        $this->client->request(Request::METHOD_DELETE, '/api/v1/backoffice/bank-accounts/' . self::ACCOUNT_ID);

        $this->assertStatus(Response::HTTP_NO_CONTENT);
        $this->assertSame(0, $this->links());
        $this->assertSame(0, $this->accounts());
        $this->assertSame(1, $this->intOf($this->connection()->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'BANK_ACCOUNT_EVIDENCE_DELETED'",
        )));
    }

    private function plantTheAccount(): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at) '
            . "VALUES (:id, 'Evidence Bank', 'evidence bank', 'EVB', NOW(), NOW()) ON CONFLICT (id) DO NOTHING",
            ['id' => self::BANK_ID],
        );
        $this->connection()->executeStatement(
            'INSERT INTO bank_account (id, bank_id, holder_name, iban, currency, status, created_at, updated_at) '
            . "VALUES (:id, :bank, 'Evidence Holder', :iban, 'EUR', 'ACTIVE', NOW(), NOW())",
            ['id' => self::ACCOUNT_ID, 'bank' => self::BANK_ID, 'iban' => self::IBAN],
        );
    }

    private function plantOtherAccount(): string
    {
        $this->connection()->executeStatement(
            'INSERT INTO bank_account (id, bank_id, holder_name, iban, currency, status, created_at, updated_at) '
            . "VALUES (:id, :bank, 'Other Holder', :iban, 'EUR', 'ACTIVE', NOW(), NOW())",
            ['id' => self::OTHER_ACCOUNT_ID, 'bank' => self::BANK_ID, 'iban' => self::OTHER_IBAN],
        );

        return self::OTHER_ACCOUNT_ID;
    }

    private function removeTheAccount(): void
    {
        $this->connection()->executeStatement('DELETE FROM bank_account_evidence');
        $this->connection()->executeStatement(
            'DELETE FROM erasure_ledger_override WHERE id = CAST(:id AS UUID)',
            ['id' => self::OVERRIDE_ID],
        );
        $this->connection()->executeStatement(
            'DELETE FROM bank_account WHERE id IN (CAST(:id AS UUID), CAST(:other AS UUID)) '
            . 'OR iban IN (:iban, :otherIban)',
            [
                'id' => self::ACCOUNT_ID,
                'other' => self::OTHER_ACCOUNT_ID,
                'iban' => self::IBAN,
                'otherIban' => self::OTHER_IBAN,
            ],
        );
        $this->connection()->executeStatement(
            'DELETE FROM bank WHERE id = CAST(:id AS UUID)',
            ['id' => self::BANK_ID],
        );
    }

    private function deleteAuditRows(): void
    {
        $this->connection()->executeStatement(
            "DELETE FROM audit_log WHERE action LIKE 'BANK_ACCOUNT_EVIDENCE_%' OR resource_id = CAST(:id AS UUID)",
            ['id' => self::ACCOUNT_ID],
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $accountId, array $body): void
    {
        $this->client->request(
            Request::METHOD_POST,
            '/api/v1/backoffice/bank-accounts/' . $accountId . '/evidence',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: \json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function detach(string $accountId, string $linkId): void
    {
        $this->client->request(
            Request::METHOD_DELETE,
            '/api/v1/backoffice/bank-accounts/' . $accountId . '/evidence/' . $linkId,
        );
    }

    private function attach(string $documentId): void
    {
        $this->post(self::ACCOUNT_ID, ['documentId' => $documentId, 'kind' => 'ownership-certificate']);
    }

    private function list(): void
    {
        $this->client->request(
            Request::METHOD_GET,
            '/api/v1/backoffice/bank-accounts/' . self::ACCOUNT_ID . '/evidence',
        );
    }

    private function links(): int
    {
        return $this->intOf($this->connection()->fetchOne('SELECT COUNT(*) FROM bank_account_evidence'));
    }

    private function accounts(): int
    {
        return $this->intOf($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM bank_account WHERE id = CAST(:id AS UUID)',
            ['id' => self::ACCOUNT_ID],
        ));
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
     * @return array<string, mixed>
     */
    private function data(): array
    {
        $decoded = \json_decode($this->body(), true);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> */
        return Dig::array($decoded, 'data');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function dataList(): array
    {
        $decoded = \json_decode($this->body(), true);
        $this->assertIsArray($decoded);

        return Dig::array($decoded, 'data');
    }

    private function problemType(): string
    {
        $decoded = \json_decode($this->body(), true);
        $this->assertIsArray($decoded);

        return $this->stringOf(Dig::value($decoded, 'type'));
    }
}
