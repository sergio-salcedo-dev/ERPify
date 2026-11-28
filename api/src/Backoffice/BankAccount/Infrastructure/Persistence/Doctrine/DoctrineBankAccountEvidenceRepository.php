<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Exception\EvidenceAlreadyAttached;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountEvidenceRepository;
use Override;
use SortDirection as NativeSortDirection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(BankAccountEvidenceRepository::class)]
final readonly class DoctrineBankAccountEvidenceRepository implements BankAccountEvidenceRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Override]
    public function save(BankAccountEvidence $evidence): void
    {
        try {
            $this->entityManager->persist($evidence);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // The attach checks first under the account's row lock, so only a link committed by a transaction that
            // did not take that lock reaches here; the answer is the same.
            throw EvidenceAlreadyAttached::create();
        }
    }

    #[Override]
    public function findByBankAccountId(string $bankAccountId): array
    {
        /** @var list<BankAccountEvidence> $links */
        $links = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(BankAccountEvidence::class, 'e')
            ->where('e.bankAccountId = :bankAccountId')
            ->setParameter('bankAccountId', \strtolower($bankAccountId))
            ->orderBy('e.attachedAt', NativeSortDirection::Ascending)
            ->addOrderBy('e.id', NativeSortDirection::Ascending)
            ->getQuery()
            ->getResult()
        ;

        return $links;
    }

    #[Override]
    public function isAttached(string $bankAccountId, string $documentId): bool
    {
        return null !== $this->entityManager->createQueryBuilder()
            ->select('e.id')
            ->from(BankAccountEvidence::class, 'e')
            ->where('e.bankAccountId = :bankAccountId')
            ->andWhere('e.documentId = :documentId')
            ->setParameter('bankAccountId', \strtolower($bankAccountId))
            ->setParameter('documentId', \strtolower($documentId))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    #[Override]
    public function removeAll(array $links): void
    {
        if ([] === $links) {
            return;
        }

        foreach ($links as $link) {
            $this->entityManager->remove($link);
        }

        $this->entityManager->flush();
    }
}
