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
            // The attach checks first, but the document is not locked: a link of the same document committed by an
            // attach to ANOTHER account (another row lock) in between reaches here. Same answer, no account named.
            throw EvidenceAlreadyAttached::create();
        }
    }

    #[Override]
    public function findByBankAccountId(string $bankAccountId): array
    {
        return $this->onlyLinks($this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(BankAccountEvidence::class, 'e')
            ->where('e.bankAccountId = :bankAccountId')
            ->setParameter('bankAccountId', \strtolower($bankAccountId))
            ->orderBy('e.attachedAt', NativeSortDirection::Ascending)
            ->addOrderBy('e.id', NativeSortDirection::Ascending)
            ->getQuery()
            ->getResult());
    }

    /**
     * @return list<BankAccountEvidence>
     */
    private function onlyLinks(mixed $result): array
    {
        return \is_array($result)
            ? \array_values(\array_filter(
                $result,
                static fn (mixed $link): bool => $link instanceof BankAccountEvidence,
            ))
            : [];
    }

    #[Override]
    public function findByDocumentId(string $documentId): ?BankAccountEvidence
    {
        $link = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(BankAccountEvidence::class, 'e')
            ->where('e.documentId = :documentId')
            ->setParameter('documentId', \strtolower($documentId))
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $link instanceof BankAccountEvidence ? $link : null;
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
