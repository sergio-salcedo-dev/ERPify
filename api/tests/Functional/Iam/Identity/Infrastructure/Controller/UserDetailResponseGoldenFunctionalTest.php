<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Iam\Identity\Infrastructure\Controller\UserGetController;
use Erpify\Tests\DataFixtures\UserFixtureFactory;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use JsonException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * End-to-end byte-stability gate for the identity detail wire contract. The serialized object is the
 * per-view Resource DTO, so this pins the EXACT ordered key set — `{id, email, status, roles, createdAt,
 * updatedAt}` — the enum `->value` for status/roles, the ATOM timestamp format, and the ABSENCE of the
 * credential columns (`password_hash` / `failedAttempts` / `lockedUntil`) and of any `permissions`
 * section against real Postgres and the real serializer. A key added, removed, renamed or reordered, a
 * leaked credential, or a date-format drift fails here — the key-cardinality-only Behat assertions cannot.
 *
 * The target is a user OTHER than the authenticated administrator, so the finder actually resolves it
 * through the read path (the admin's own row is already in the identity map from the firewall reload).
 *
 * @internal
 */
#[CoversClass(UserGetController::class)]
final class UserDetailResponseGoldenFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;

    private const string ENDPOINT = '/api/v1/backoffice/users';

    private const string TARGET_ID = '0190f000-0000-7000-8000-0000000000a1';

    private const string TARGET_EMAIL = 'golden-target@erpify.test';

    private const string ATOM_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';

    private KernelBrowser $client;

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();

        // Idempotent isolation: drop any prior target (unique email) before re-seeding. Surgical rather than
        // a table truncate, so the suite's other identity rows and sessions are untouched.
        $this->entityManager()->getConnection()->executeStatement(
            'DELETE FROM identity_user WHERE email = :email',
            ['email' => self::TARGET_EMAIL],
        );

        $this->persistTarget();
        $this->authenticateAdminClient($this->client);
    }

    /**
     * @throws JsonException
     */
    public function testDetailEmitsTheExactOrderedKeySetEnumValuesAndNoCredential(): void
    {
        $data = $this->detail(self::TARGET_ID);

        $this->assertSame(['id', 'email', 'status', 'roles', 'createdAt', 'updatedAt'], \array_keys($data));
        $this->assertSame(self::TARGET_ID, $this->node($data, 'id'));
        $this->assertSame(self::TARGET_EMAIL, $this->node($data, 'email'));
        $this->assertSame('ACTIVE', $this->node($data, 'status'));
        $this->assertSame(['EDITOR'], $this->node($data, 'roles'));
        $this->assertMatchesRegularExpression(self::ATOM_PATTERN, $this->stringNode($data, 'createdAt'));
        $this->assertMatchesRegularExpression(self::ATOM_PATTERN, $this->stringNode($data, 'updatedAt'));

        // The credential and lockout columns never reach the wire, and there is no permissions section.
        foreach (['password_hash', 'passwordHash', 'failedAttempts', 'lockedUntil', 'permissions'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data);
        }
    }

    private function persistTarget(): void
    {
        $entityManager = $this->entityManager();
        $entityManager->persist(UserFixtureFactory::create(
            self::TARGET_ID,
            self::TARGET_EMAIL,
            'golden-password',
            [Role::EDITOR->value],
        ));
        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * @throws JsonException
     *
     * @return array<string, mixed>
     */
    private function detail(string $id): array
    {
        $this->client->request(Request::METHOD_GET, self::ENDPOINT . '/' . $id);

        self::assertResponseIsSuccessful();

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

    /**
     * @param array<string, mixed> $data
     */
    private function node(array $data, string $key): mixed
    {
        $this->assertArrayHasKey($key, $data);

        return $data[$key];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stringNode(array $data, string $key): string
    {
        $value = $this->node($data, $key);
        $this->assertIsString($value);

        return $value;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
