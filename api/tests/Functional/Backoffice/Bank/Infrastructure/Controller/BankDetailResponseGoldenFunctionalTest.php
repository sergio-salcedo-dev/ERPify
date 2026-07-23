<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Bank\Infrastructure\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use JsonException;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * End-to-end byte-stability gate for the bank detail wire contract. The serialized object is the
 * per-view Resource DTO, so this pins the exact ordered key set and the ATOM timestamp format of
 * `GET /banks/{id}` against real Postgres and the real serializer. A key added, removed, renamed or
 * reordered, or a date-format drift fails here — the key-cardinality-only Behat assertions cannot
 * catch any of those.
 *
 * @internal
 */
#[CoversNothing]
final class BankDetailResponseGoldenFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;

    private const string ENDPOINT = '/api/v1/backoffice/banks';

    private const string ATOM_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/';

    private KernelBrowser $client;

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();

        // Isolation: no DAMA rollback in this suite, so start from a known-empty table.
        $this->entityManager()->getConnection()->executeStatement(
            'TRUNCATE bank_account, bank RESTART IDENTITY CASCADE',
        );

        $this->authenticateClient($this->client);
    }

    /**
     * @throws JsonException
     */
    public function testDetailEmitsTheExactOrderedKeySetAndAtomTimestamps(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $this->persistBank($id, 'JPMorgan Chase', 'JPM');

        $data = $this->detail($id);

        // Exact ordered key set — a key added/removed/renamed or reordered fails here.
        $this->assertSame(
            ['id', 'name', 'shortName', 'createdAt', 'updatedAt', 'accountCount'],
            \array_keys($data),
        );
        $this->assertSame($id, $this->node($data, 'id'));
        $this->assertSame('JPMorgan Chase', $this->node($data, 'name'));
        $this->assertSame('JPM', $this->node($data, 'shortName'));
        $this->assertSame(0, $this->node($data, 'accountCount'));
        // Format pin: timestamps stay ISO-8601 ATOM, not the serializer default.
        $this->assertMatchesRegularExpression(self::ATOM_PATTERN, $this->stringNode($data, 'createdAt'));
        $this->assertMatchesRegularExpression(self::ATOM_PATTERN, $this->stringNode($data, 'updatedAt'));
    }

    private function persistBank(string $id, string $name, string $shortName): void
    {
        $entityManager = $this->entityManager();
        $entityManager->persist(Bank::create($id, $name, $shortName));
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
