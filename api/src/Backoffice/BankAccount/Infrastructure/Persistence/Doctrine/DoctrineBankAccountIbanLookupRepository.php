<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\Query\Expr\Join;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Projection\BankAccountCollectionRow;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountIbanLookupRepository;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Exact-match counterpart to {@see DoctrineBankAccountCollectionSearchRepository}: the same read
 * composition (`BankAccount` JOINed to its owning `Bank`), but a single `WHERE ba.iban = :iban`
 * equality bound as a parameter — never the generic filter engine, since this is one fixed predicate
 * rather than a client-tunable vocabulary. `bank_account.iban` is `unique`, so at most one row matches
 * a canonical value; {@see NonUniqueResultException} would signal that invariant is broken, not a
 * reachable input error, so it is left to propagate rather than caught.
 */
#[AsAlias(BankAccountIbanLookupRepository::class)]
final readonly class DoctrineBankAccountIbanLookupRepository implements BankAccountIbanLookupRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws NonUniqueResultException never in practice — see the class docblock
     */
    #[Override]
    public function findByIban(string $canonicalIban): ?BankAccountCollectionRow
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select(\sprintf(
                'NEW %s('
                . 'ba.id, ba.bankId, b.name, b.shortName, ba.holderName, ba.iban, '
                . 'ba.bic, ba.alias, ba.currency, ba.status, ba.createdAt, ba.updatedAt)',
                BankAccountCollectionRow::class,
            ))
            ->from(BankAccount::class, 'ba')
            ->join(Bank::class, 'b', Join::ON, 'ba.bankId = b.id')
            ->andWhere('ba.iban = :iban')
            ->setParameter('iban', $canonicalIban)
            ->getQuery()
        ;

        /** @var BankAccountCollectionRow|null */
        return $query->getOneOrNullResult();
    }
}
