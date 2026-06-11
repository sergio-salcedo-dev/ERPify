<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * BankAccount persistence by COMPOSITION (PR3): implements only its domain port with an injected
 * {@see EntityManagerInterface} — no `ServiceEntityRepository` inheritance, no
 * `getEntityClassName()`. It has no paginated read-path, so it does NOT use the
 * {@see \Erpify\Shared\Infrastructure\Persistence\Doctrine\Search\DoctrineSearchEngine}; only the
 * referential-integrity count is exposed.
 */
#[AsAlias(BankAccountRepository::class)]
final readonly class DoctrineBankAccountRepository implements BankAccountRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
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
