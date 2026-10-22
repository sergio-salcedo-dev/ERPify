<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Bank\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Infrastructure\Persistence\Doctrine\DoctrineBankExistenceChecker;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The check a bank account's write path runs before it names a bank, against real Postgres: a present bank
 * passes, a well-formed absent id is a 404, and a malformed one is a 400 refused before any query runs.
 *
 * Each test runs inside a transaction that is always rolled back, so the bank it seeds never leaks.
 *
 * @internal
 */
#[CoversClass(DoctrineBankExistenceChecker::class)]
final class DoctrineBankExistenceCheckerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $connection;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;

        $this->connection = $entityManager->getConnection();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    #[DoesNotPerformAssertions]
    public function aBankThatExistsPasses(): void
    {
        $this->connection->beginTransaction();
        $bankId = Uuid::v7()->toRfc4122();
        $this->entityManager->persist(Bank::create($bankId, 'Existence Probe Bank', 'EXISTPROBE'));
        $this->entityManager->flush();

        (new DoctrineBankExistenceChecker($this->entityManager))->ensureExists($bankId);
    }

    #[Test]
    public function aWellFormedIdNoBankCarriesIsNotFound(): void
    {
        $absentId = Uuid::v7()->toRfc4122();

        $this->expectExceptionObject(BankNotFoundException::withId($absentId));

        (new DoctrineBankExistenceChecker($this->entityManager))->ensureExists($absentId);
    }

    #[Test]
    public function aMalformedIdIsRefusedBeforeAnyQuery(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('createQueryBuilder');

        $this->expectException(InvalidUuidException::class);

        (new DoctrineBankExistenceChecker($entityManager))->ensureExists('not-a-uuid');
    }
}
