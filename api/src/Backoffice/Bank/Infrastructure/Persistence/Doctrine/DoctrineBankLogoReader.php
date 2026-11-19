<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankLogoReader;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * A scalar DQL select on the column, so every call reaches the database. The realtime handler runs in a worker that
 * keeps its entity manager for hours and clears it never: `find()` there would answer from the identity map with the
 * logo the bank held when it was first loaded.
 */
#[AsAlias(BankLogoReader::class)]
final readonly class DoctrineBankLogoReader implements BankLogoReader
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Override]
    public function logoImageIdOf(string $bankId): ?string
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('b.logoImageId AS logoImageId')
            ->from(Bank::class, 'b')
            ->where('b.id = :id')
            ->setParameter('id', $bankId)
            ->getQuery()
            ->getScalarResult()
        ;

        $logoImageId = \is_array($rows[0] ?? null) ? ($rows[0]['logoImageId'] ?? null) : null;

        return \is_string($logoImageId) ? $logoImageId : null;
    }
}
