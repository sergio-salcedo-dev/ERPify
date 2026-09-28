<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Infrastructure\Controller\UserPatchRolesController;
use Erpify\Iam\Identity\Infrastructure\Security\StaticAuthorizationPolicy;
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
 * this endpoint diverges on, verified against the production adapter rather than a stub — and that an actor
 * targeting its own identity is refused before any of that runs.
 *
 * The role-delegation refusal is driven over a policy that withholds `users.grantAdmin`, because the shipped
 * grant row makes such an actor unconstructible: both `users.changeRoles` and `users.grantAdmin` are ADMIN-only
 * by design, so no fixture user can hold one without the other. The guard must nevertheless hold independently
 * of that data — the row is one data edit away from naming a narrower grantee, and the refusal is what would
 * then carry the whole separation. Overriding only the policy's grant map leaves the voter, the checker, the
 * controller and the RFC 9457 bridge exactly as production wires them.
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

    private ?string $suspendedActorId = null;

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    protected function tearDown(): void
    {
        $this->reinstateSuspendedActor();
        $this->resetTarget();
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
        // Clear every administrator, seat the actor, seed one ADMIN target and take the actor out of the pool:
        // the target is now the SOLE active administrator, so the production directory adapter — not a test
        // double — answers 409 when its ADMIN is taken away.
        $this->demoteEveryAdministrator();
        $this->authenticateAdminClient($this->client);
        $actorId = $this->soleActiveAdministratorId();
        $this->resetTarget();
        $this->persistTarget([Role::ADMIN->value]);
        $this->suspendActorBeneathItsSession($actorId);
        $this->assertSame(self::TARGET_ID, $this->soleActiveAdministratorId());

        $this->request(self::TARGET_ID, '{"roles":["EDITOR"]}');

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        // Pin the marker, not just the status: any other conflict would also answer 409, and the wired
        // directory adapter is precisely what this test exists to prove.
        $this->assertSame('last-active-administrator-protected', $this->problemString('type'));
        // The guard runs before the aggregate mutates: the lone admin keeps its ADMIN.
        $this->assertContains('ADMIN', $this->rolesOf(self::TARGET_ID));
    }

    /**
     * @throws JsonException
     */
    public function testTheGuardStaysSilentWhenTheSoleAdministratorKeepsAdmin(): void
    {
        // Same single-administrator setup as the conflict above; the only difference is that ADMIN is kept, so
        // nobody leaves the active-admin pool and the guard is never consulted.
        $this->demoteEveryAdministrator();
        $this->authenticateAdminClient($this->client);
        $actorId = $this->soleActiveAdministratorId();
        $this->resetTarget();
        $this->persistTarget([Role::ADMIN->value]);
        $this->suspendActorBeneathItsSession($actorId);
        $this->assertSame(self::TARGET_ID, $this->soleActiveAdministratorId());

        $data = $this->patchRoles(self::TARGET_ID, ['ADMIN', 'EDITOR'], expectedStatusCode: 200);

        $this->assertSame(['ADMIN', 'EDITOR'], $this->node($data, 'roles'));
    }

    /**
     * @throws JsonException
     */
    public function testAnAdministratorCannotChangeTheirOwnRoles(): void
    {
        // A widening that keeps ADMIN — the one self-change the administrator invariant would allow — is
        // refused all the same: the refusal is about who is targeted, never about what the set would do.
        $this->authenticateAdminClient($this->client);
        $actorId = $this->functionalAdministratorId();
        $held = $this->rolesOf($actorId);

        $this->request($actorId, '{"roles":["ADMIN","EDITOR"]}');

        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $this->assertSame('self-role-change-forbidden', $this->problemString('type'));
        $this->assertSame($held, $this->rolesOf($actorId));
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

    /**
     * @throws JsonException
     */
    public function testAnActorWithoutTheDelegationPermissionMayNotPromoteToAdmin(): void
    {
        $this->resetTarget();
        $this->persistTarget();
        $this->withholdTheDelegationPermission();
        $this->authenticateAdminClient($this->client);

        $this->request(self::TARGET_ID, '{"roles":["ADMIN","EDITOR"]}');

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $this->assertSame('forbidden', $this->problemString('type'));
        // The exception message is the wire `title`, so it is part of the contract a client renders.
        $this->assertSame('You may not grant the ADMIN role.', $this->problemString('title'));
        // Refused in the controller, before the use case opens its transaction.
        $this->assertSame([Role::VIEWER->value], $this->rolesOf(self::TARGET_ID));
    }

    /**
     * @throws JsonException
     */
    public function testTheSameActorStillReplacesANonAdminSet(): void
    {
        // The check is conditional on the payload, never on the route: withholding the delegation permission
        // must not turn the endpoint off for the sets this actor is entitled to assign.
        $this->resetTarget();
        $this->persistTarget();
        $this->withholdTheDelegationPermission();
        $this->authenticateAdminClient($this->client);

        $data = $this->patchRoles(self::TARGET_ID, ['EDITOR', 'AUDIT_READER'], expectedStatusCode: 200);

        $this->assertSame(['EDITOR', 'AUDIT_READER'], $this->node($data, 'roles'));
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
     * Replaces the wired authorization policy with one whose only explicit grant is `users.changeRoles`, so
     * the seated ADMIN reaches the endpoint and is refused the delegation. `users` keeps its production
     * tier opt-out, which is what stops the ADMIN wildcard from reaching `users.grantAdmin` anyway. Registered
     * before the policy is resolved (Symfony forbids replacing an initialized service) and with reboot
     * disabled, so the request the client then issues is decided by it.
     */
    private function withholdTheDelegationPermission(): void
    {
        self::getContainer()->set(
            StaticAuthorizationPolicy::class,
            new StaticAuthorizationPolicy(explicitGrants: ['users.changeRoles' => [Role::ADMIN->value]]),
        );
        $this->client->disableReboot();
    }

    /**
     * @throws JsonException
     */
    private function problemString(string $key): string
    {
        $decoded = \json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($decoded);
        /** @phpstan-var array<string, mixed> $decoded */
        $value = $this->node($decoded, $key);
        $this->assertIsString($value);

        return $value;
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

    /**
     * @param list<string> $roles
     */
    private function persistTarget(array $roles = [Role::VIEWER->value]): void
    {
        $entityManager = $this->entityManager();
        $entityManager->persist(
            UserFixtureFactory::create(self::TARGET_ID, self::TARGET_EMAIL, 'target-password', $roles),
        );
        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * Takes the seated administrator out of the active-admin pool beneath its live session. The admission gate
     * reads the session row and never the identity's status, so the actor stays admitted while no longer
     * counting as an active administrator — the only sequential shape in which another administrator can be the
     * last one, since an actor may not target itself. The shape the guard exists for, two administrators
     * acting on each other concurrently, cannot be driven over sequential HTTP.
     */
    private function suspendActorBeneathItsSession(string $actorId): void
    {
        $this->entityManager()->getConnection()->executeStatement(
            "UPDATE identity_user SET status = 'SUSPENDED' WHERE id = CAST(:id AS uuid)",
            ['id' => $actorId],
        );
        $this->suspendedActorId = $actorId;
    }

    private function reinstateSuspendedActor(): void
    {
        if (null === $this->suspendedActorId) {
            return;
        }

        $this->entityManager()->getConnection()->executeStatement(
            "UPDATE identity_user SET status = 'ACTIVE' WHERE id = CAST(:id AS uuid)",
            ['id' => $this->suspendedActorId],
        );
        $this->suspendedActorId = null;
    }

    private function functionalAdministratorId(): string
    {
        $id = $this->entityManager()->getConnection()->fetchOne(
            'SELECT id FROM identity_user WHERE email = :email',
            ['email' => self::FUNCTIONAL_ADMIN_EMAIL],
        );
        $this->assertIsString($id);

        return $id;
    }

    /**
     * Asserts there is exactly ONE active administrator and returns it — a `fetchOne` alone would return the
     * first of several and let a "sole administrator" premise pass over a pool that is not empty.
     */
    private function soleActiveAdministratorId(): string
    {
        $ids = $this->entityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM identity_user WHERE status = \'ACTIVE\' AND roles::jsonb @> \'["ADMIN"]\'::jsonb',
        );
        $this->assertCount(1, $ids);
        $id = $ids[0];
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
