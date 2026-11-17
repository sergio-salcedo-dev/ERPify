<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\ErasedPersonMarker;
use Erpify\Documents\Document\Infrastructure\Controller\DocumentCustodyController;
use Erpify\Documents\Document\Infrastructure\Http\UploadRoutes;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DoctrineDocumentCustodyRepository;
use Erpify\Documents\Document\Infrastructure\Security\DocumentPermission;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use Erpify\Tests\Support\Dig;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
 * `GET /api/v1/documents/{id}/custody` through the kernel, against real Postgres: the ordered trail, the plain cursor
 * on `seq` scoped to one document, the metadata and name riding the first page only, the erased document, the
 * refusals, and the one generic audit row (which names the document and the ADMIN who read it).
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the route, its audit, its sealer and its session
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") each public method is one independent answer of the route
 * @SuppressWarnings("PHPMD.ExcessiveClassLength") the same: one scenario per answer, each with its own rows
 */
#[CoversClass(DocumentCustodyController::class)]
#[CoversClass(DoctrineDocumentCustodyRepository::class)]
final class DocumentCustodyRouteFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;
    use PlantsNamedDocuments;

    private const string GENERIC_ACTION = 'ROUTE_DOCUMENTS_CUSTODY';

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
        $this->deleteAuditRows();
        $this->emptyDocumentTables();
        parent::tearDown();
    }

    #[Test]
    public function theFirstPageCarriesTheMetadataTheOpenedNameAndTheRowsInSeqOrder(): void
    {
        $this->authenticateAdminClient($this->client);
        $document = $this->plantNamedDocument('plano-planta-baja.pdf');
        $this->plantTrail($document['id'], 3);

        $this->get($document['id']);

        $this->assertStatus(Response::HTTP_OK);
        $data = $this->data();
        $this->assertSame(
            [
                'id', 'seriesId', 'predecessorId', 'state', 'digest', 'sizeBytes', 'name', 'retentionReason',
                'legalBasis', 'reviewAt', 'createdAt', 'entries', 'nextAfterSeq',
            ],
            \array_keys($data),
        );
        $this->assertSame($document['id'], Dig::value($data, 'id'));
        $this->assertSame('plano-planta-baja.pdf', Dig::value($data, 'name'));
        $this->assertNull(Dig::value($data, 'nextAfterSeq'));
        $entries = Dig::array($data, 'entries');
        $this->assertSame([1, 2, 3], \array_column($entries, 'seq'));
        $this->assertSame(['received', 'rescan_requested', 'rescan_requested'], \array_column($entries, 'event'));
        $this->assertSame([null, 'failure_investigated', 'failure_investigated'], \array_column($entries, 'reason'));
        $this->assertSame(['seq', 'event', 'actorId', 'reason', 'occurredAt'], \array_keys(Dig::array($entries, 0)));
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    #[Test]
    public function theRowsComeBackBySeqWhateverOrderTheyWereWritten(): void
    {
        $this->authenticateAdminClient($this->client);
        $document = $this->plantNamedDocument('order.pdf');

        foreach ([3, 1, 4, 2] as $seq) {
            $this->plantTrailRow($document['id'], $seq);
        }

        $this->get($document['id']);

        $this->assertSame([1, 2, 3, 4], \array_column(Dig::array($this->data(), 'entries'), 'seq'));
    }

    #[Test]
    public function withNoLimitThePageIsTwentyFiveRowsAndTheCursorPointsPastThem(): void
    {
        $this->authenticateAdminClient($this->client);
        $document = $this->plantNamedDocument('default-limit.pdf');
        $this->plantTrail($document['id'], 30);

        $this->get($document['id']);

        $this->assertStatus(Response::HTTP_OK);
        $this->assertSame(\range(1, 25), \array_column(Dig::array($this->data(), 'entries'), 'seq'));
        $this->assertSame(25, Dig::value($this->data(), 'nextAfterSeq'));
    }

    #[Test]
    public function anExplicitAfterSeqOfZeroIsACursorAndAnswersTheLaterPageShapeFromTheFirstRow(): void
    {
        $this->authenticateAdminClient($this->client);
        $document = $this->plantNamedDocument('zero.pdf');
        $this->plantTrail($document['id'], 3);

        $this->get($document['id'], 'afterSeq=0');

        $this->assertStatus(Response::HTTP_OK);
        $data = $this->data();
        $this->assertSame(['entries', 'nextAfterSeq'], \array_keys($data), 'no metadata and no name');
        $this->assertSame([1, 2, 3], \array_column(Dig::array($data, 'entries'), 'seq'));
    }

    #[Test]
    public function aTrailOfOneHundredAndThirtyRowsWalksToItsEndWithNoLossAndNoRepeat(): void
    {
        $this->authenticateAdminClient($this->client);
        $document = $this->plantNamedDocument('long.pdf');
        $this->plantTrail($document['id'], 130);

        $this->get($document['id'], 'limit=100');
        $this->assertStatus(Response::HTTP_OK);
        $first = $this->data();
        $this->assertSame('long.pdf', Dig::value($first, 'name'));
        $firstSeqs = \array_column(Dig::array($first, 'entries'), 'seq');
        $this->assertCount(100, $firstSeqs);
        $this->assertSame(100, Dig::value($first, 'nextAfterSeq'));

        $this->get($document['id'], 'limit=100&afterSeq=100');
        $this->assertStatus(Response::HTTP_OK);
        $second = $this->data();
        $this->assertSame(
            ['entries', 'nextAfterSeq'],
            \array_keys($second),
            'metadata and name ride the first page only',
        );
        $secondSeqs = \array_column(Dig::array($second, 'entries'), 'seq');
        $this->assertCount(30, $secondSeqs);
        $this->assertNull(Dig::value($second, 'nextAfterSeq'));

        $this->assertSame(\range(1, 130), [...$firstSeqs, ...$secondSeqs]);
    }

    #[Test]
    public function aWalkOfSmallPagesFollowsNextAfterSeqExactly(): void
    {
        $this->authenticateAdminClient($this->client);
        $document = $this->plantNamedDocument('small.pdf');
        $this->plantTrail($document['id'], 7);

        $seen = [];
        $after = null;

        do {
            $this->get($document['id'], 'limit=3' . (null === $after ? '' : '&afterSeq=' . $after));
            $this->assertStatus(Response::HTTP_OK);
            $page = $this->data();
            $seen = [...$seen, ...\array_column(Dig::array($page, 'entries'), 'seq')];
            $next = Dig::value($page, 'nextAfterSeq');
            $after = \is_int($next) ? $next : null;
        } while (null !== $after);

        $this->assertSame(\range(1, 7), $seen);
    }

    #[Test]
    public function aCursorNeverCrossesIntoAnotherDocumentThatHoldsTheSameSeqValues(): void
    {
        $this->authenticateAdminClient($this->client);
        $mine = $this->plantNamedDocument('mine.pdf');
        $other = $this->plantNamedDocument('other.pdf', ageSeconds: 5);
        $this->plantTrail($mine['id'], 3);
        $this->plantTrail($other['id'], 6);

        $this->get($mine['id'], 'afterSeq=1');

        $this->assertSame([2, 3], \array_column(Dig::array($this->data(), 'entries'), 'seq'));

        $this->get($mine['id'], 'afterSeq=3');
        $this->assertSame([], Dig::array($this->data(), 'entries'));
        $this->assertNull(Dig::value($this->data(), 'nextAfterSeq'));
    }

    #[Test]
    public function aRowAppendedBetweenTwoRequestsAppearsOnTheLaterPage(): void
    {
        $this->authenticateAdminClient($this->client);
        $document = $this->plantNamedDocument('growing.pdf');
        $this->plantTrail($document['id'], 2);

        $this->get($document['id'], 'limit=2');
        $this->assertNull(Dig::value($this->data(), 'nextAfterSeq'));

        $this->plantTrailRow($document['id'], 3);

        $this->get($document['id'], 'afterSeq=2');
        $this->assertSame([3], \array_column(Dig::array($this->data(), 'entries'), 'seq'));
    }

    #[Test]
    public function anErasedDocumentAnswersWithItsTrailAndNoName(): void
    {
        $this->authenticateAdminClient($this->client);
        $document = $this->plantNamedDocument('erased.pdf', DocumentState::Erased);
        $this->plantTrail($document['id'], 2, ErasedPersonMarker::UUID);

        $this->get($document['id']);

        $this->assertStatus(Response::HTTP_OK);
        $data = $this->data();
        $this->assertSame('erased', Dig::value($data, 'state'));
        $this->assertNull(Dig::value($data, 'name'));
        $entries = Dig::array($data, 'entries');
        $this->assertSame([1, 2], \array_column($entries, 'seq'));
        $this->assertSame(
            [ErasedPersonMarker::UUID, ErasedPersonMarker::UUID],
            \array_column($entries, 'actorId'),
            'the erased-person marker is returned as stored',
        );
    }

    #[Test]
    public function aDestroyedKeyOrATamperedNameListsNullAndStillAnswersTheTrail(): void
    {
        $this->authenticateAdminClient($this->client);
        $destroyed = $this->plantNamedDocument('destroyed.pdf');
        $tampered = $this->plantNamedDocument('tampered.pdf', ageSeconds: 5);
        $this->plantTrail($destroyed['id'], 1);
        $this->plantTrail($tampered['id'], 1);
        $this->destroyScopeOf($destroyed['scope']);
        $this->tamperWithTheNameOf($tampered['id']);

        foreach ([$destroyed['id'], $tampered['id']] as $id) {
            $this->get($id);
            $this->assertStatus(Response::HTTP_OK);
            $this->assertNull(Dig::value($this->data(), 'name'));
            $this->assertCount(1, Dig::array($this->data(), 'entries'));
        }
    }

    #[Test]
    #[DataProvider('provideAMalformedOrOutOfRangeCursorIsUnprocessableCases')]
    public function aMalformedOrOutOfRangeCursorIsUnprocessable(string $query): void
    {
        $this->authenticateAdminClient($this->client);
        $document = $this->plantNamedDocument('cursor.pdf');
        $this->plantTrail($document['id'], 2);

        $this->get($document['id'], $query);

        $this->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSame('validation-failed', $this->problemType());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAMalformedOrOutOfRangeCursorIsUnprocessableCases(): iterable
    {
        yield 'negative afterSeq' => ['afterSeq=-1'];
        yield 'non numeric afterSeq' => ['afterSeq=x'];
        yield 'afterSeq past the column' => ['afterSeq=2147483648'];
        yield 'limit past the cap' => ['limit=101'];
        yield 'zero limit' => ['limit=0'];
        yield 'non numeric limit' => ['limit=lots'];
    }

    #[Test]
    public function aMalformedIdIsABadRequestAndAnUnknownOneIsNotFoundOnEveryPage(): void
    {
        $this->authenticateAdminClient($this->client);

        $this->client->request(Request::METHOD_GET, '/api/v1/documents/not-a-uuid/custody');
        $this->assertStatus(Response::HTTP_BAD_REQUEST);
        $this->assertSame('invalid-uuid', $this->problemType());

        $unknown = Uuid::generate();
        $this->get($unknown);
        $this->assertStatus(Response::HTTP_NOT_FOUND);
        $this->assertSame('document-not-found', $this->problemType());

        $this->get($unknown, 'afterSeq=3');
        $this->assertStatus(Response::HTTP_NOT_FOUND);
        $this->assertSame('document-not-found', $this->problemType());
    }

    #[Test]
    public function aManagerWhoMayFileEvidenceMayNotReadTheTrailAndNoSessionIsUnauthenticated(): void
    {
        $document = $this->plantNamedDocument('secret.pdf');
        $this->plantTrail($document['id'], 1);

        $this->get($document['id']);
        $this->assertStatus(Response::HTTP_UNAUTHORIZED);

        $this->authenticateClient($this->client);
        $this->get($document['id']);
        $this->assertStatus(Response::HTTP_FORBIDDEN);
        $this->assertStringNotContainsString('secret.pdf', $this->body());
    }

    #[Test]
    public function aHeadRequestIsRefusedBeforeAnythingIsOpened(): void
    {
        $this->authenticateAdminClient($this->client);
        $document = $this->plantNamedDocument('head.pdf');

        $this->client->request(Request::METHOD_HEAD, '/api/v1/documents/' . $document['id'] . '/custody');

        $this->assertStatus(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function theGenericAccessRowNamesTheDocumentAndTheCallerAndCarriesNoNameDigestOrCustodyActor(): void
    {
        $this->authenticateAdminClient($this->client);
        $document = $this->plantNamedDocument('audited-name.pdf');
        $this->plantTrail($document['id'], 2);
        $callerId = $this->connection()->fetchOne(
            'SELECT id FROM identity_user WHERE email = :email',
            ['email' => self::FUNCTIONAL_ADMIN_EMAIL],
        );

        $this->get($document['id']);

        $this->assertStatus(Response::HTTP_OK);
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT level, resource_type, resource_id::text AS resource_id, actor_id::text AS actor_id, '
            . 'metadata::text AS metadata FROM audit_log WHERE action = :action',
            ['action' => self::GENERIC_ACTION],
        );
        $this->assertCount(1, $rows);
        $this->assertSame('activity', Dig::value($rows, 0, 'level'));
        $this->assertSame('Document', Dig::value($rows, 0, 'resource_type'));
        $this->assertSame($document['id'], Dig::value($rows, 0, 'resource_id'));
        $this->assertSame($callerId, Dig::value($rows, 0, 'actor_id'), 'the row names the ADMIN who read the trail');

        $stored = \json_encode($rows, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('audited-name.pdf', $stored);
        $this->assertStringNotContainsString(\hash('sha256', $document['id']), $stored);

        $custodyActors = $this->connection()->fetchFirstColumn('SELECT actor_id::text FROM document_custody');

        foreach ($custodyActors as $custodyActor) {
            $this->assertStringNotContainsString(
                $this->stringOf($custodyActor),
                $stored,
                'no custody actor appears in the row',
            );
        }

        $this->assertSame(
            0,
            $this->intOf($this->connection()->fetchOne(
                "SELECT COUNT(*) FROM audit_log WHERE action = 'DOCUMENT_READ'",
            )),
        );
    }

    #[Test]
    public function theRouteIsDeclaredGatedAndNamesItsAuditResource(): void
    {
        $router = self::getContainer()->get(RouterInterface::class);
        $this->assertInstanceOf(RouterInterface::class, $router);
        $route = $router->getRouteCollection()->get(UploadRoutes::CUSTODY);

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame('/api/v1/documents/{id}/custody', $route->getPath());
        $this->assertSame(['GET'], $route->getMethods());
        $this->assertSame('Document', $route->getDefault('_audit_resource_type'));
        $this->assertNull($route->getDefault('_audit_canonical'));

        $grants = (new ReflectionClass(DocumentCustodyController::class))->getAttributes(IsGranted::class);
        $this->assertCount(1, $grants);
        $this->assertSame(DocumentPermission::CUSTODY, $grants[0]->newInstance()->attribute);
    }

    private function plantTrailRow(string $documentId, int $seq): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO document_custody (id, document_id, seq, event, actor_id, created_at) '
            . "VALUES (:id, :document, :seq, 'received', :actor, NOW())",
            ['id' => Uuid::generate(), 'document' => $documentId, 'seq' => $seq, 'actor' => Uuid::generate()],
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

    private function get(string $id, string $query = ''): void
    {
        $this->client->request(
            Request::METHOD_GET,
            '/api/v1/documents/' . $id . '/custody' . ('' === $query ? '' : '?' . $query),
        );
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

    private function problemType(): string
    {
        $decoded = \json_decode($this->body(), true);
        $this->assertIsArray($decoded);

        return $this->stringOf(Dig::value($decoded, 'type'));
    }

    private function deleteAuditRows(): void
    {
        $this->connection()->executeStatement(
            'DELETE FROM audit_log WHERE action = :action',
            ['action' => self::GENERIC_ACTION],
        );
    }
}
