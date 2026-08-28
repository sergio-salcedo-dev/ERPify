<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Shared\Persistence\Domain\Exception\ConcurrentUniqueWrite;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * BankAccount persistence by COMPOSITION: implements its domain port with an injected
 * {@see EntityManagerInterface} — no `ServiceEntityRepository` inheritance, no `getEntityClassName()`.
 * It has no paginated read-path, so it does NOT use the
 * {@see \Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\DoctrineSearchEngine}; it exposes the
 * aggregate write surface plus the referential-integrity count.
 */
#[AsAlias(BankAccountRepository::class)]
final readonly class DoctrineBankAccountRepository implements BankAccountRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Override]
    public function save(BankAccount $account): void
    {
        // The port keeps persist+flush as its observable contract (POST/PUT Behat depends on it).
        // When a use case wraps this in a transaction the flush synchronizes but does not commit until
        // that transaction commits.
        try {
            $this->entityManager->persist($account);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw ConcurrentUniqueWrite::onWrite('bank-account');
        }
    }

    #[Override]
    public function remove(BankAccount $account): void
    {
        $this->entityManager->remove($account);
        $this->entityManager->flush();
    }

    #[Override]
    public function findById(string $id): ?BankAccount
    {
        return $this->entityManager->find(BankAccount::class, $id);
    }

    /**
     * A DQL read rather than `find()`, and the difference is the whole guarantee. `find()` consults the
     * identity map FIRST: on a hit it routes the lock through `EntityPersister::refresh()` and returns the
     * managed instance either way, so a caller that had already loaded this account would be handed a stale
     * snapshot of a row that no longer exists — the erasure would then report a record it did not erase. A
     * query always reaches the database, so a vanished row is zero rows and `null` whatever the unit of work
     * holds, and `HINT_REFRESH` overwrites the managed snapshot with the state the lock just froze. That
     * turns the port's guarantee into a property of this adapter instead of an obligation on its callers.
     */
    #[Override]
    public function findByIdForUpdate(string $id): ?BankAccount
    {
        $account = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(BankAccount::class, 'a')
            ->where('a.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult()
        ;

        return $account instanceof BankAccount ? $account : null;
    }

    #[Override]
    public function countByBankId(string $bankId): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(ba.id)')
            ->from(BankAccount::class, 'ba')
            ->where('ba.bankId = :bankId')
            ->setParameter('bankId', $bankId)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }
}
