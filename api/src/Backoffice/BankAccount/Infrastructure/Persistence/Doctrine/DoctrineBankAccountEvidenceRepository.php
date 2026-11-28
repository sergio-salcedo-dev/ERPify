<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccountEvidence;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountEvidenceAlreadyAttached;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountEvidenceRepository;
use Erpify\Shared\Persistence\Domain\Exception\ConcurrentUniqueWrite;
use Override;
use SortDirection as NativeSortDirection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Throwable;

/**
 * The evidence links through the ORM, one entity per write so the change capture sees each one.
 *
 * A unique violation is classified by the constraint's NAME, matched whole — delimited on both sides by a character
 * that cannot continue an identifier — in the driver's message, never by its wording, which the server translates:
 * only the document constraint is a lost race of two attaches; any other is a concurrent write of a kind this adapter
 * does not own. The driver text names the offending key, so neither translation chains it.
 */
#[AsAlias(BankAccountEvidenceRepository::class)]
final readonly class DoctrineBankAccountEvidenceRepository implements BankAccountEvidenceRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Override]
    public function add(BankAccountEvidence $evidence): void
    {
        try {
            $this->entityManager->persist($evidence);
            $this->entityManager->flush();
        } catch (Throwable $throwable) {
            if (!$throwable instanceof UniqueConstraintViolationException) {
                throw $throwable;
            }

            if (self::names($throwable, BankAccountEvidence::DOCUMENT_CONSTRAINT)) {
                throw BankAccountEvidenceAlreadyAttached::of($evidence->documentId());
            }

            throw ConcurrentUniqueWrite::onWrite('bank-account-evidence');
        }
    }

    #[Override]
    public function isAttached(string $documentId): bool
    {
        $count = $this->entityManager->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(BankAccountEvidence::class, 'e')
            ->where('e.documentId = :document')
            ->setParameter('document', $documentId)
            ->getQuery()
            ->getSingleScalarResult()
        ;

        return 0 < (int) $count;
    }

    #[Override]
    public function findOnAccount(string $bankAccountId, string $linkId): ?BankAccountEvidence
    {
        $link = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(BankAccountEvidence::class, 'e')
            ->where('e.id = :link')
            ->andWhere('e.bankAccountId = :account')
            ->setParameter('link', $linkId)
            ->setParameter('account', $bankAccountId)
            ->getQuery()
            ->getOneOrNullResult()
        ;

        return $link instanceof BankAccountEvidence ? $link : null;
    }

    #[Override]
    public function ofBankAccount(string $bankAccountId): array
    {
        $links = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(BankAccountEvidence::class, 'e')
            ->where('e.bankAccountId = :account')
            ->orderBy('e.attachedAt', NativeSortDirection::Descending)
            ->addOrderBy('e.id', NativeSortDirection::Descending)
            ->setParameter('account', $bankAccountId)
            ->getQuery()
            ->getResult()
        ;

        if (!\is_array($links)) {
            return [];
        }

        return \array_values(\array_filter(
            $links,
            static fn (mixed $link): bool => $link instanceof BankAccountEvidence,
        ));
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

    private static function names(Throwable $failure, string $constraint): bool
    {
        $message = ($failure->getPrevious() ?? $failure)->getMessage();
        $pattern = '/(?<![A-Za-z0-9_$])' . \preg_quote($constraint, '/') . '(?![A-Za-z0-9_$])/';

        return 1 === \preg_match($pattern, $message);
    }
}
