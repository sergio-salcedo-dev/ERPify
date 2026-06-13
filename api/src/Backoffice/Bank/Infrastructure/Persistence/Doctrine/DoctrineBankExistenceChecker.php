<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Backoffice\Bank\Domain\Repository\BankExistenceChecker;
use Erpify\Shared\Domain\Uuid\Uuid;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(BankExistenceChecker::class)]
final readonly class DoctrineBankExistenceChecker implements BankExistenceChecker
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Override]
    public function ensureExists(string $bankId): void
    {
        // Shape first: a malformed id is a 400 request-target error, raised before any query touches
        // the database (the 0-query guarantee the endpoint contract pins).
        Uuid::ensure($bankId);

        $exists = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(b.id)')
            ->from(Bank::class, 'b')
            ->where('b.id = :id')
            ->setParameter('id', $bankId)
            ->getQuery()
            ->getSingleScalarResult()
        ;

        if (0 === $exists) {
            throw BankNotFoundException::withId($bankId);
        }
    }
}
