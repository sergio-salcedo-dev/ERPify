<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
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
        $this->entityManager->persist($account);
        $this->entityManager->flush();
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
