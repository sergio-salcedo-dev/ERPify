<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\BankAccount;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Infrastructure\Controller\BankAccountIbanLookupController;
use Erpify\Shared\Kernel\Domain\Enum\Currency;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use JsonException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * End-to-end wire-gate for `POST /api/v1/backoffice/bank-accounts/iban-lookup` on the real, wired
 * container — proves the controller autowires its Application/Infrastructure collaborators (the
 * production `DoctrineBankAccountIbanLookupRepository`, not an in-memory double) and answers the
 * documented shapes through the real HTTP kernel: 200 with the cross-bank projection on a match, 404
 * with no trace of the searched IBAN on a well-formed miss.
 *
 * @internal
 */
#[CoversClass(BankAccountIbanLookupController::class)]
final class BankAccountIbanLookupFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;

    private const string ENDPOINT = '/api/v1/backoffice/bank-accounts/iban-lookup';

    private const string MATCHING_IBAN = 'DE89370400440532013000';

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
    public function testFindingAnAccountByIbanReturnsTheCrossBankProjection(): void
    {
        $this->persistAccount();

        $this->client->request(
            Request::METHOD_POST,
            self::ENDPOINT,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: \json_encode(['iban' => self::MATCHING_IBAN], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedData();
        $this->assertSame(self::MATCHING_IBAN, $this->node($data, 'iban'));
        $this->assertSame('Globex Corporation', $this->node($data, 'holderName'));
    }

    public function testAWellFormedMissReturns404WithoutEchoingTheIban(): void
    {
        $this->client->request(
            Request::METHOD_POST,
            self::ENDPOINT,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: '{"iban":"ES9121000418450200051332"}',
        );

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('ES9121000418450200051332', $content);
        $this->assertStringNotContainsString('iban', $content);
    }

    private function persistAccount(): void
    {
        $entityManager = $this->entityManager();
        $bankId = Uuid::v7()->toRfc4122();
        $entityManager->persist(Bank::create($bankId, 'JPMorgan Chase', 'JPM'));
        $entityManager->flush();

        $entityManager->persist(BankAccount::create(
            Uuid::v7()->toRfc4122(),
            $bankId,
            'Globex Corporation',
            self::MATCHING_IBAN,
            null,
            null,
            Currency::EUR,
        ));
        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * @throws JsonException
     *
     * @return array<string, mixed>
     */
    private function decodedData(): array
    {
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

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
