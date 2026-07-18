<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Infrastructure\Controller\UserPatchStatusController;
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
 * End-to-end wire-gate for `PATCH /api/v1/backoffice/users/{id}/status` on the real, wired container. It
 * proves the endpoint autowires — the production `DoctrineActiveAdministratorDirectory` binds, so the
 * last-active-admin guard answers a real `409` (not an autowiring failure), the shape only the in-memory
 * double can otherwise reach — and that an ADMIN suspends a non-admin (200 with the new status) while a
 * non-admin is refused (403).
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(UserPatchStatusController::class)]
final class UserPatchStatusFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;

    private const string ENDPOINT = '/api/v1/backoffice/users';

    private const string TARGET_ID = '0190f200-0000-7000-8000-0000000000b1';

    private const string TARGET_EMAIL = 'change-status-target@erpify.test';

    private KernelBrowser $client;

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * @throws JsonException
     */
    public function testAnAdminSuspendsANonAdminTargetAndGetsTheNewStatus(): void
    {
        $this->resetTarget();
        $this->persistTarget();
        // Seats functional-admin (ACTIVE ADMIN), so an active administrator other than the VIEWER target
        // survives the transition and the guard passes.
        $this->authenticateAdminClient($this->client);

        $data = $this->patchStatus(self::TARGET_ID, 'SUSPENDED', expectedStatusCode: 200);

        $this->assertSame(['id', 'email', 'status', 'roles', 'createdAt', 'updatedAt'], \array_keys($data));
        $this->assertSame(self::TARGET_ID, $this->node($data, 'id'));
        $this->assertSame(self::TARGET_EMAIL, $this->node($data, 'email'));
        $this->assertSame('SUSPENDED', $this->node($data, 'status'));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function node(array $data, string $key): mixed
    {
        $this->assertArrayHasKey($key, $data);

        return $data[$key];
    }

    public function testSuspendingTheLastActiveAdministratorIsARealConflictWithoutMutating(): void
    {
        // Clear every administrator, then seat exactly one: functional-admin is now the SOLE active admin, so
        // the production directory adapter — not a test double — answers 409 when it is the target.
        $this->deleteAllAdministrators();
        $this->authenticateAdminClient($this->client);
        $loneAdminId = $this->soleActiveAdministratorId();

        $this->client->request(
            Request::METHOD_PATCH,
            self::ENDPOINT . '/' . $loneAdminId . '/status',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: '{"status":"DEACTIVATED"}',
        );

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        // The guard runs before the aggregate mutates: the lone admin stays ACTIVE.
        $this->assertSame('ACTIVE', $this->statusOf($loneAdminId));
    }

    public function testANonAdminIsForbidden(): void
    {
        $this->resetTarget();
        $this->persistTarget();
        // functional (MANAGER + AUDIT_READER) holds no users.changeStatus — users opts out of tier auto-grant.
        $this->authenticateClient($this->client);

        $this->client->request(
            Request::METHOD_PATCH,
            self::ENDPOINT . '/' . self::TARGET_ID . '/status',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: '{"status":"SUSPENDED"}',
        );

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @throws JsonException
     *
     * @return array<string, mixed>
     */
    private function patchStatus(string $id, string $status, int $expectedStatusCode): array
    {
        $this->client->request(
            Request::METHOD_PATCH,
            self::ENDPOINT . '/' . $id . '/status',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: \sprintf('{"status":"%s"}', $status),
        );

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

    private function deleteAllAdministrators(): void
    {
        $this->entityManager()->getConnection()->executeStatement(
            'DELETE FROM identity_user WHERE roles::jsonb @> \'["ADMIN"]\'::jsonb',
        );
    }

    private function soleActiveAdministratorId(): string
    {
        $id = $this->entityManager()->getConnection()->fetchOne(
            'SELECT id FROM identity_user WHERE status = \'ACTIVE\' AND roles::jsonb @> \'["ADMIN"]\'::jsonb',
        );
        $this->assertIsString($id);

        return $id;
    }

    private function statusOf(string $id): string
    {
        $status = $this->entityManager()->getConnection()->fetchOne(
            'SELECT status FROM identity_user WHERE id = CAST(:id AS uuid)',
            ['id' => $id],
        );
        $this->assertIsString($status);

        return $status;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
