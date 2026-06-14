<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountCounter;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Resolves per-bank account counts in ONE aggregate query (`GROUP BY bank_id`, reusing the existing
 * `IDX_53A23E0A11C8FB41` index) for a whole page of banks, avoiding the N+1 that a per-row
 * {@see \Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository::countByBankId()}
 * loop would cause.
 */
#[AsAlias(BankAccountCounter::class)]
final readonly class DoctrineBankAccountCounter implements BankAccountCounter
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Override]
    public function countsByBankIds(array $bankIds): array
    {
        if ([] === $bankIds) {
            return [];
        }

        /** @var list<array{bankId: string, cnt: int|string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('ba.bankId AS bankId', 'COUNT(ba.id) AS cnt')
            ->from(BankAccount::class, 'ba')
            ->where('ba.bankId IN (:bankIds)')
            ->setParameter('bankIds', $bankIds)
            ->groupBy('ba.bankId')
            ->getQuery()
            ->getResult()
        ;

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['bankId']] = (int) $row['cnt'];
        }

        return $counts;
    }
}
