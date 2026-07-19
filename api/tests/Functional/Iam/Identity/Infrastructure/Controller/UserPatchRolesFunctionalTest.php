<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Infrastructure\Controller\UserPatchRolesController;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Tests\DataFixtures\UserFixtureFactory;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use JsonException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * End-to-end wire-gate for `PATCH /api/v1/backoffice/users/{id}/roles` on the real, wired container. It proves
 * the endpoint autowires — the production `DoctrineActiveAdministratorDirectory` binds, so the last-admin guard
 * answers a real `409` (not an autowiring failure), the shape only the in-memory double can otherwise reach —
 * that an ADMIN reassigns a non-admin's set (200 with the new roles), that a non-admin is refused (403), and
 * that the guard stays silent when the sole administrator keeps `ADMIN` — the conditional-invocation contract
 * this endpoint diverges on, verified against the production adapter rather than a stub.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(UserPatchRolesController::class)]
final class UserPatchRolesFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;

    private const string ENDPOINT = '/api/v1/backoffice/users';

    private const string TARGET_ID = '0190f200-0000-7000-8000-0000000000c1';

    private const string TARGET_EMAIL = 'change-roles-target@erpify.test';

    private KernelBrowser $client;

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    protected function tearDown(): void
    {
        $this->restoreParkedAdministrators();
        parent::tearDown();
    }

    /**
     * @throws JsonException
     */
    public function testAnAdminReplacesANonAdminTargetsRoleSet(): void
    {
        $this->resetTarget();
        $this->persistTarget();
        $this->authenticateAdminClient($this->client);

        $data = $this->patchRoles(self::TARGET_ID, ['EDITOR', 'AUDIT_READER'], expectedStatusCode: 200);

        $this->assertSame(['id', 'email', 'status', 'roles', 'createdAt', 'updatedAt'], \array_keys($data));
        $this->assertSame(self::TARGET_ID, $this->node($data, 'id'));
        $this->assertSame(self::TARGET_EMAIL, $this->node($data, 'email'));
        $this->assertSame(['EDITOR', 'AUDIT_READER'], $this->node($data, 'roles'));
    }

    /**
     * @throws JsonException
     */
    public function testDemotingTheLastActiveAdministratorIsARealConflictWithoutMutating(): void
    {
        // Clear every administrator, then seat exactly one: functional-admin is now the SOLE active admin, so
        // the production directory adapter — not a test double — answers 409 when its ADMIN is taken away.
        $this->demoteEveryAdministrator();
        $this->authenticateAdminClient($this->client);
        $loneAdminId = $this->soleActiveAdministratorId();

        $this->request($loneAdminId, '{"roles":["EDITOR"]}');

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        // Pin the marker, not just the status: any other conflict would also answer 409, and the wired
        // directory adapter is precisely what this test exists to prove.
        $this->assertSame('last-active-administrator-protected', $this->problemType());
        // The guard runs before the aggregate mutates: the lone admin keeps its ADMIN.
        $this->assertContains('ADMIN', $this->rolesOf($loneAdminId));
    }

    /**
     * @throws JsonException
     */
    public function testTheSoleAdministratorMayWidenItsOwnSetBecauseTheGuardDoesNotApply(): void
    {
        // Same single-administrator setup as the conflict above; the only difference is that ADMIN is kept, so
        // nobody leaves the active-admin pool and the guard is never consulted.
        $this->demoteEveryAdministrator();
        $this->authenticateAdminClient($this->client);
        $loneAdminId = $this->soleActiveAdministratorId();

        $data = $this->patchRoles($loneAdminId, ['ADMIN', 'EDITOR'], expectedStatusCode: 200);

        $this->assertSame(['ADMIN', 'EDITOR'], $this->node($data, 'roles'));
    }

    public function testANonAdminIsForbidden(): void
    {
        $this->resetTarget();
        $this->persistTarget();
        // functional (MANAGER + AUDIT_READER) holds no users.changeRoles — users opts out of tier auto-grant.
        $this->authenticateClient($this->client);

        $this->request(self::TARGET_ID, '{"roles":["EDITOR"]}');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnEmptySetIsRefusedAtTheBoundary(): void
    {
        $this->resetTarget();
        $this->persistTarget();
        $this->authenticateAdminClient($this->client);

        $this->request(self::TARGET_ID, '{"roles":[]}');

        self::assertResponseStatusCodeSame(422);
        $this->assertSame([Role::VIEWER->value], $this->rolesOf(self::TARGET_ID));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function node(array $data, string $key): mixed
    {
        $this->assertArrayHasKey($key, $data);

        return $data[$key];
    }

    /**
     * @throws JsonException
     */
    private function problemType(): string
    {
        $decoded = \json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($decoded);
        /** @phpstan-var array<string, mixed> $decoded */
        $type = $this->node($decoded, 'type');
        $this->assertIsString($type);

        return $type;
    }

    /**
     * @param list<string> $roles
     *
     * @throws JsonException
     *
     * @return array<string, mixed>
     */
    private function patchRoles(string $id, array $roles, int $expectedStatusCode): array
    {
        $this->request($id, \json_encode(['roles' => $roles], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame($expectedStatusCode);

        $decoded = \json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertIsArray($decoded['data']);

        /** @phpstan-var array<string, mixed> */
        return $decoded['data'];
    }

    private function request(string $id, string $body): void
    {
        $this->client->request(
            Request::METHOD_PATCH,
            self::ENDPOINT . '/' . $id . '/roles',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: $body,
        );
    }

    private function resetTarget(): void
    {
        $this->entityManager()->getConnection()->executeStatement(
            'DELETE FROM identity_user WHERE email = :email',
            ['email' => self::TARGET_EMAIL],
        );
    }

    private function persistTarget(): void
    {
        $entityManager = $this->entityManager();
        $entityManager->persist(
            UserFixtureFactory::create(self::TARGET_ID, self::TARGET_EMAIL, 'target-password', [Role::VIEWER->value]),
        );
        $entityManager->flush();
        $entityManager->clear();
    }

    private function soleActiveAdministratorId(): string
    {
        $id = $this->entityManager()->getConnection()->fetchOne(
            'SELECT id FROM identity_user WHERE status = \'ACTIVE\' AND roles::jsonb @> \'["ADMIN"]\'::jsonb',
        );
        $this->assertIsString($id);

        return $id;
    }

    /**
     * @throws JsonException
     *
     * @return list<string>
     */
    private function rolesOf(string $id): array
    {
        $roles = $this->entityManager()->getConnection()->fetchOne(
            'SELECT roles FROM identity_user WHERE id = CAST(:id AS uuid)',
            ['id' => $id],
        );
        $this->assertIsString($roles);

        $decoded = \json_decode($roles, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        /** @phpstan-var list<string> */
        return \array_values($decoded);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
