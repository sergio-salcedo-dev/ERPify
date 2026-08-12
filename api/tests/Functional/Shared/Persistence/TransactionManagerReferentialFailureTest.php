<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Persistence\Domain\Exception\ReferentialIntegrityViolation;
use Erpify\Shared\Persistence\Infrastructure\DoctrineTransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * The sibling of {@see TransactionManagerRetryableFailureTest}, and it exists for the same reason: the unit
 * test throws the DBAL exception at a doubled EntityManager, so it can only show that the `catch` matches.
 * What it cannot show is that PostgreSQL and Doctrine ever produce that exception on this path — a
 * translation nothing raises is dead code that passes its own test.
 *
 * The violation is provoked at the flush of a real transaction, which is where it happens in production:
 * the row is deleted while another row still references it, and the foreign key is `NOT DEFERRABLE`, so the
 * rejection lands at the statement rather than at commit.
 *
 * @internal
 */
#[CoversClass(DoctrineTransactionManager::class)]
#[CoversClass(ReferentialIntegrityViolation::class)]
final class TransactionManagerReferentialFailureTest extends KernelTestCase
{
    #[Test]
    public function aForeignKeyRejectedAtFlushArrivesAsTheConflictMarker(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $transactionManager = $container->get(TransactionManager::class);
        $connection = $container->get(Connection::class);
        $this->assertInstanceOf(TransactionManager::class, $transactionManager);
        $this->assertInstanceOf(Connection::class, $connection);

        $bankId = Uuid::generate();
        $this->seedBankWithAccount($connection, $bankId);

        $escaped = null;

        try {
            $transactionManager->transactional(static function () use ($connection, $bankId): void {
                $connection->executeStatement('DELETE FROM bank WHERE id = :id', ['id' => $bankId]);
            });
        } catch (Throwable $throwable) {
            $escaped = $throwable;
        }

        // Asserted on a captured value rather than in a catch, so "nothing was thrown at all" fails here
        // instead of passing an empty try block.
        $this->assertInstanceOf(
            ReferentialIntegrityViolation::class,
            $escaped,
            \sprintf(
                'The seam let %s out of the transaction instead of ReferentialIntegrityViolation, so a '
                . 'foreign key rejected at flush still reaches the caller as a bare 500.',
                \get_debug_type($escaped),
            ),
        );

        $this->cleanUp($connection, $bankId);
    }

    private function seedBankWithAccount(Connection $connection, string $bankId): void
    {
        $shortName = \strtoupper(\substr(\str_replace('-', '', $bankId), 0, 8));

        $banks = $connection->executeStatement(
            'INSERT INTO bank (id, name, name_normalized, short_name, created_at, updated_at)
             VALUES (:id, :name, :normalized, :shortName, NOW(), NOW())',
            [
                'id' => $bankId,
                'name' => 'Referential Test Bank ' . $shortName,
                'normalized' => 'referential test bank ' . \strtolower($shortName),
                'shortName' => $shortName,
            ],
        );
        $accounts = $connection->executeStatement(
            'INSERT INTO bank_account (id, bank_id, holder_name, iban, currency, status, created_at, updated_at)
             VALUES (:id, :bankId, :holder, :iban, :currency, :status, NOW(), NOW())',
            [
                'id' => Uuid::generate(),
                'bankId' => $bankId,
                'holder' => 'Referential Test Holder',
                'iban' => 'ES9121000418450200051332',
                'currency' => 'EUR',
                'status' => 'ACTIVE',
            ],
        );

        // Asserted, not assumed: a seed that inserts nothing leaves the DELETE below with no referencing row,
        // and the test would then pass by never provoking the violation it exists to observe.
        $this->assertSame(1, $banks);
        $this->assertSame(1, $accounts);
    }

    private function cleanUp(Connection $connection, string $bankId): void
    {
        $connection->executeStatement('DELETE FROM bank_account WHERE bank_id = :id', ['id' => $bankId]);
        $connection->executeStatement('DELETE FROM bank WHERE id = :id', ['id' => $bankId]);
    }
}
