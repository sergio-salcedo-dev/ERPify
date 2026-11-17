<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Infrastructure\Controller\ListDocumentsController;
use Erpify\Documents\Document\Infrastructure\Http\UploadRoutes;
use Erpify\Documents\Document\Infrastructure\Persistence\Doctrine\DoctrineDocumentListingRepository;
use Erpify\Documents\Document\Infrastructure\Security\DocumentPermission;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use Erpify\Tests\Support\Dig;
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
 * `GET /api/v1/documents` through the kernel, against real Postgres and the real sealer: who may call it, the order
 * and the cap of the page, what an item carries and never carries, and that an unopenable name degrades its own item
 * and nothing else.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the route, its audit, its sealer and its session
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") each public method is one independent answer of the route
 */
#[CoversClass(ListDocumentsController::class)]
#[CoversClass(DoctrineDocumentListingRepository::class)]
final class DocumentListingRouteFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;
    use PlantsNamedDocuments;

    private const string GENERIC_ACTION = 'ROUTE_DOCUMENTS_LIST';

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
    public function anAdministratorListsNewestFirstWithTheNamesOpenedAndNothingTheWireMustNotCarry(): void
    {
        $this->authenticateAdminClient($this->client);
        $oldest = $this->plantNamedDocument('plano-oldest.pdf', ageSeconds: 300);
        $middle = $this->plantNamedDocument('plano-middle.pdf', ageSeconds: 200);
        $newest = $this->plantNamedDocument('plano-newest.pdf', ageSeconds: 100);

        $this->get('/api/v1/documents');

        $this->assertStatus(Response::HTTP_OK);
        $items = $this->items();
        $this->assertSame([$newest['id'], $middle['id'], $oldest['id']], \array_column($items, 'id'));
        $this->assertSame(['plano-newest.pdf', 'plano-middle.pdf', 'plano-oldest.pdf'], \array_column($items, 'name'));
        $this->assertSame(
            [
                'id', 'seriesId', 'predecessorId', 'state', 'digest', 'sizeBytes', 'name', 'retentionReason',
                'legalBasis', 'reviewAt', 'createdAt',
            ],
            \array_keys(Dig::array($items, 0)),
        );
        $this->assertSame('ready', Dig::value($items, 0, 'state'));
        $this->assertNull(Dig::value($items, 0, 'predecessorId'));
        $this->assertNull(Dig::value($items, 0, 'reviewAt'));
        $this->assertSame('contract-execution', Dig::value($items, 0, 'retentionReason'));

        $body = $this->body();
        $this->assertStringNotContainsString($newest['scope'], $body, 'the scope never leaves');
        $this->assertStringNotContainsString('uploader', $body);
        $this->assertStringNotContainsString('ciphertext', $body);
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    #[Test]
    public function aDestroyedScopeAndATamperedNameInsideOnePageListNullAndTheOthersStayIntact(): void
    {
        $this->authenticateAdminClient($this->client);
        $good = $this->plantNamedDocument('good.pdf', ageSeconds: 500);
        $destroyed = $this->plantNamedDocument('destroyed.pdf', ageSeconds: 400);
        $tampered = $this->plantNamedDocument('tampered.pdf', ageSeconds: 300);
        $alsoGood = $this->plantNamedDocument('also-good.pdf', ageSeconds: 200);
        $this->destroyScopeOf($destroyed['scope']);
        $this->tamperWithTheNameOf($tampered['id']);

        $this->get('/api/v1/documents');

        $this->assertStatus(Response::HTTP_OK);
        $names = [];

        foreach ($this->items() as $item) {
            $names[Dig::string($item, 'id')] = Dig::value($item, 'name');
        }

        $this->assertSame(
            [
                $alsoGood['id'] => 'also-good.pdf',
                $tampered['id'] => null,
                $destroyed['id'] => null,
                $good['id'] => 'good.pdf',
            ],
            $names,
        );
    }

    #[Test]
    public function anErasedDocumentIsListedWithNoNameEvenWhenItsCiphertextSurvived(): void
    {
        $this->authenticateAdminClient($this->client);
        $erased = $this->plantNamedDocument('erased.pdf', DocumentState::Erased);
        $nameless = $this->plantNamedDocument(null, DocumentState::Erased, ageSeconds: 10);

        $this->get('/api/v1/documents');

        $this->assertStatus(Response::HTTP_OK);
        $items = $this->items();
        $this->assertSame([$erased['id'], $nameless['id']], \array_column($items, 'id'));
        $this->assertSame([null, null], \array_column($items, 'name'));
        $this->assertSame(['erased', 'erased'], \array_column($items, 'state'));
    }

    #[Test]
    public function theStateFilterAndTheCapAreEnforced(): void
    {
        $this->authenticateAdminClient($this->client);
        $ready = $this->plantNamedDocument('ready.pdf');
        $this->plantNamedDocument('quarantined.pdf', DocumentState::Quarantined, ageSeconds: 5);

        $this->get('/api/v1/documents?filters[0][field]=state&filters[0][operator]=eq&filters[0][value]=ready');
        $this->assertStatus(Response::HTTP_OK);
        $this->assertSame([$ready['id']], \array_column($this->items(), 'id'));

        $this->get('/api/v1/documents?limit=500');
        $this->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSame('validation-failed', $this->problemType());

        $this->get('/api/v1/documents?filters[0][field]=uploaderId&filters[0][operator]=eq&filters[0][value]=x');
        $this->assertGreaterThanOrEqual(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $this->assertLessThan(Response::HTTP_INTERNAL_SERVER_ERROR, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function thePageWalksWithItsCursorInTheSameOrderWithNoLossAndNoRepeat(): void
    {
        $this->authenticateAdminClient($this->client);
        $planted = [];

        for ($age = 1; $age <= 5; ++$age) {
            $planted[] = $this->plantNamedDocument('doc-' . $age . '.pdf', ageSeconds: $age * 10)['id'];
        }

        $this->get('/api/v1/documents?limit=2');
        $this->assertStatus(Response::HTTP_OK);
        $seen = \array_column($this->items(), 'id');
        $link = $this->nextLink();

        while (null !== $link) {
            $this->get($link);
            $this->assertStatus(Response::HTTP_OK);
            $seen = [...$seen, ...\array_column($this->items(), 'id')];
            $link = $this->nextLink();
        }

        $this->assertSame($planted, $seen, 'newest first is the same order on every page');
    }

    #[Test]
    public function documentsSharingOneCreatedAtPageByTheIdTieBreakWithNoLossAndNoRepeat(): void
    {
        $this->authenticateAdminClient($this->client);
        $planted = [];

        for ($i = 0; $i < 5; ++$i) {
            $planted[] = $this->plantNamedDocument('same-' . $i . '.pdf')['id'];
        }

        $this->connection()->executeStatement("UPDATE document SET created_at = TIMESTAMPTZ '2026-06-01 10:00:00+00'");

        $this->get('/api/v1/documents?limit=2');
        $this->assertStatus(Response::HTTP_OK);
        $seen = \array_column($this->items(), 'id');
        $link = $this->nextLink();

        while (null !== $link) {
            $this->get($link);
            $this->assertStatus(Response::HTTP_OK);
            $seen = [...$seen, ...\array_column($this->items(), 'id')];
            $link = $this->nextLink();
        }

        \sort($planted);
        $this->assertSame($planted, $seen, 'one instant, so the id alone orders the walk, once each');
    }

    #[Test]
    public function anExplicitSortByCreatedAtWithNoDirectionIsAscending(): void
    {
        $this->authenticateAdminClient($this->client);
        $oldest = $this->plantNamedDocument('oldest.pdf', ageSeconds: 300);
        $newest = $this->plantNamedDocument('newest.pdf', ageSeconds: 100);

        $this->get('/api/v1/documents?sort=createdAt');

        $this->assertStatus(Response::HTTP_OK);
        $this->assertSame([$oldest['id'], $newest['id']], \array_column($this->items(), 'id'));

        $this->get('/api/v1/documents?sort=createdAt&direction=DESC');
        $this->assertSame([$newest['id'], $oldest['id']], \array_column($this->items(), 'id'));
    }

    #[Test]
    public function aManagerWhoMayFileEvidenceMayNotListItAndNoSessionIsUnauthenticated(): void
    {
        $this->plantNamedDocument('secret.pdf');

        $this->get('/api/v1/documents');
        $this->assertStatus(Response::HTTP_UNAUTHORIZED);

        $this->authenticateClient($this->client);
        $this->get('/api/v1/documents');
        $this->assertStatus(Response::HTTP_FORBIDDEN);
        $this->assertStringNotContainsString('secret.pdf', $this->body());
    }

    #[Test]
    public function aHeadRequestIsRefusedBeforeAnyNameIsOpened(): void
    {
        $this->authenticateAdminClient($this->client);
        $this->plantNamedDocument('head.pdf');

        $this->client->request(Request::METHOD_HEAD, '/api/v1/documents');

        $this->assertStatus(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function theGenericAccessRowNamesTheCallerAndCarriesNoNameOrCustodyActor(): void
    {
        $this->authenticateAdminClient($this->client);
        $document = $this->plantNamedDocument('audited.pdf');
        $this->plantTrail($document['id'], 2);
        $callerId = $this->connection()->fetchOne(
            'SELECT id FROM identity_user WHERE email = :email',
            ['email' => self::FUNCTIONAL_ADMIN_EMAIL],
        );

        $this->get('/api/v1/documents');

        $this->assertStatus(Response::HTTP_OK);
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT level, resource_id, actor_id::text AS actor_id, metadata::text AS metadata '
            . 'FROM audit_log WHERE action = :action',
            ['action' => self::GENERIC_ACTION],
        );
        $this->assertCount(1, $rows);
        $this->assertSame('activity', Dig::value($rows, 0, 'level'));
        $this->assertNull(Dig::value($rows, 0, 'resource_id'), 'a collection route has no single resource');
        $this->assertSame($callerId, Dig::value($rows, 0, 'actor_id'), 'the row names the ADMIN who listed');

        $stored = \json_encode($rows, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('audited.pdf', $stored);

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
    public function theRouteIsDeclaredAndGatedByTheCustodyVerb(): void
    {
        $router = self::getContainer()->get(RouterInterface::class);
        $this->assertInstanceOf(RouterInterface::class, $router);
        $route = $router->getRouteCollection()->get(UploadRoutes::LIST);

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame('/api/v1/documents', $route->getPath());
        $this->assertSame(['GET'], $route->getMethods());
        $this->assertNull($route->getDefault('_audit_canonical'));

        $grants = (new ReflectionClass(ListDocumentsController::class))->getAttributes(IsGranted::class);
        $this->assertCount(1, $grants);
        $this->assertSame(DocumentPermission::CUSTODY, $grants[0]->newInstance()->attribute);
    }

    private function assertStatus(int $expected): void
    {
        $this->assertSame($expected, $this->client->getResponse()->getStatusCode(), $this->body());
    }

    private function body(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    private function get(string $uri): void
    {
        $this->client->request(Request::METHOD_GET, $uri);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(): array
    {
        $decoded = \json_decode($this->body(), true);
        $this->assertIsArray($decoded);
        $data = Dig::value($decoded, 'data');
        $this->assertIsArray($data);

        /** @var list<array<string, mixed>> $data */
        return $data;
    }

    private function nextLink(): ?string
    {
        $decoded = \json_decode($this->body(), true);
        $this->assertIsArray($decoded);
        $link = Dig::value($decoded, 'pagination', 'links', 'next');

        return \is_string($link) ? $link : null;
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
            'DELETE FROM audit_log WHERE action IN (:list, :custody)',
            ['list' => self::GENERIC_ACTION, 'custody' => 'ROUTE_DOCUMENTS_CUSTODY'],
        );
    }
}
