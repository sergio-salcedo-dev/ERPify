<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\BankAccount;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine\DoctrineBankAccountRepository;
use Erpify\Shared\Kernel\Domain\Enum\Currency;
use Erpify\Shared\Persistence\Domain\Exception\ConcurrentUniqueWrite;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * Proves the translation against REAL Postgres, which is the half a double cannot reach.
 *
 * The unit sibling constructs `UniqueConstraintViolationException` itself, so it can only show that the
 * `catch` matches a class the test chose. What decides whether this change works at all is whether
 * PostgreSQL 18 through this DBAL driver raises **that** class rather than a bare `DriverException` —
 * and if it does not, production still answers 500 with the offending value in the message while every
 * other gate stays green.
 *
 * No race is needed to reach it: the `#[UniqueEntity]` check lives in the use case, not in the port, so
 * saving the same IBAN twice through the port alone drives the index straight to 23505 — the identical
 * failure a lost race produces.
 *
 * Runs inside a transaction that is always rolled back; the suite shares the dev database.
 *
 * @internal
 */
#[CoversClass(DoctrineBankAccountRepository::class)]
#[CoversClass(ConcurrentUniqueWrite::class)]
final class DoctrineBankAccountRepositoryUniqueTest extends KernelTestCase
{
    private const string BANK_ID = '11111111-1111-7000-8000-000000000003';

    private const string IBAN = 'GB82WEST12345698765432';

    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private DoctrineBankAccountRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();

        $this->repository = new DoctrineBankAccountRepository($entityManager);
    }

    #[Test]
    public function theUniqueIndexRefusalArrivesAsAConflictAndNotAsTheDriversMessage(): void
    {
        $this->connection->beginTransaction();

        try {
            // TRUNCATE is transactional in Postgres, so the rollback below undoes it.
            $this->connection->executeStatement('TRUNCATE bank_account RESTART IDENTITY CASCADE');
            // The account's bank_id is a real foreign key, so the parent row has to exist for the
            // UNIQUE index to be the constraint under test rather than the FK.
            $this->connection->executeStatement(
                'INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at)'
                . " VALUES (:id, 'Race Bank', 'race bank', 'RCB', NOW(), NOW())"
                . ' ON CONFLICT (id) DO NOTHING',
                ['id' => self::BANK_ID],
            );

            $this->repository->save($this->account());
            $this->entityManager->clear();

            try {
                $this->repository->save($this->account());

                $this->fail('Postgres accepted a second row on a unique index.');
            } catch (ConcurrentUniqueWrite $concurrentUniqueWrite) {
                $this->assertSame('concurrent-unique-write', $concurrentUniqueWrite->type());
                $this->assertNotInstanceOf(Throwable::class, $concurrentUniqueWrite->getPrevious());
                $this->assertStringNotContainsString(self::IBAN, $concurrentUniqueWrite->getMessage());
            }
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }

    private function account(): BankAccount
    {
        return BankAccount::create(
            Uuid::generate(),
            self::BANK_ID,
            'Race Holder',
            self::IBAN,
            null,
            null,
            Currency::EUR,
        );
    }
}
