<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine;

use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Shared\Infrastructure\Persistence\Doctrine\AbstractDoctrineRepository;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * @extends AbstractDoctrineRepository<BankAccount>
 */
#[AsAlias(BankAccountRepository::class)]
final class DoctrineBankAccountRepository extends AbstractDoctrineRepository implements BankAccountRepository
{
    #[Override]
    public function countByBankId(string $bankId): int
    {
        return (int) $this->createQueryBuilder('ba')
            ->select('COUNT(ba.id)')
            ->where('IDENTITY(ba.bank) = :bankId')
            ->setParameter('bankId', $bankId)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    #[Override]
    protected static function getEntityClassName(): string
    {
        return BankAccount::class;
    }
}
