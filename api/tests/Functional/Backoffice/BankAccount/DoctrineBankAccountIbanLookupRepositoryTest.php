<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\BankAccount;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Projection\BankAccountCollectionRow;
use Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine\DoctrineBankAccountIbanLookupRepository;
use Erpify\Shared\Kernel\Domain\Enum\Currency;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * GATE — proves the exact-match lookup against REAL Postgres: a canonical IBAN match hydrates the
 * joined bank identity, a miss returns null rather than throwing, and the lookup is scoped to the
 * exact stored (canonicalized) value — never a substring match.
 *
 * Each test runs inside {@see inRolledBackTransaction}: the suite shares the dev database and has no
 * DAMA auto-rollback, so the bank/bank_account tables are truncated and re-seeded inside a transaction
 * that is always rolled back — the seeded rows never leak.
 *
 * @internal
 */
#[CoversClass(DoctrineBankAccountIbanLookupRepository::class)]
final class DoctrineBankAccountIbanLookupRepositoryTest extends KernelTestCase
{
    private const string BANK_NAME = 'Alpha Bank';

    private const string BANK_SHORT = 'ALPHA';

    private const string MATCHING_IBAN = 'DE89370400440532013000';

    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private DoctrineBankAccountIbanLookupRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();

        $this->repository = new DoctrineBankAccountIbanLookupRepository($entityManager);
    }

    public function testFindsTheAccountByItsExactCanonicalIbanAndHydratesTheOwningBank(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $bankId = $this->seedOneAccount();

            $row = $this->repository->findByIban(self::MATCHING_IBAN);

            $this->assertInstanceOf(BankAccountCollectionRow::class, $row);
            $this->assertSame(self::MATCHING_IBAN, $row->iban);
            $this->assertSame($bankId, $row->bankId);
            $this->assertSame(self::BANK_NAME, $row->bankName);
            $this->assertSame(self::BANK_SHORT, $row->bankShortName);
        });
    }

    public function testReturnsNullWhenNoAccountMatchesTheIban(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedOneAccount();

            $row = $this->repository->findByIban('GB29NWBK60161331926819');

            $this->assertNotInstanceOf(BankAccountCollectionRow::class, $row);
        });
    }

    public function testDoesNotMatchAPartialIbanSubstring(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedOneAccount();

            // "DE893704" is a genuine substring of MATCHING_IBAN; the lookup is an equality, not a
            // CONTAINS, so a fragment must never match.
            $row = $this->repository->findByIban('DE893704');

            $this->assertNotInstanceOf(BankAccountCollectionRow::class, $row);
        });
    }

    /**
     * @return string the seeded bank's id
     */
    private function seedOneAccount(): string
    {
        $this->connection->executeStatement('TRUNCATE bank_account, bank RESTART IDENTITY CASCADE');

        $bankId = Uuid::v7()->toRfc4122();
        $this->entityManager->persist(Bank::create($bankId, self::BANK_NAME, self::BANK_SHORT));
        $this->entityManager->flush();

        $account = BankAccount::create(
            Uuid::v7()->toRfc4122(),
            $bankId,
            'Globex Corporation',
            self::MATCHING_IBAN,
            null,
            null,
            Currency::EUR,
        );
        $account->setCreatedAt(new DateTimeImmutable('2026-01-01 10:00:00'));

        $this->entityManager->persist($account);
        $this->entityManager->flush();
        $this->entityManager->clear();

        return $bankId;
    }

    /**
     * @param callable(): void $testBody
     */
    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->connection->beginTransaction();

        try {
            $testBody();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
