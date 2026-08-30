<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\BankAccount;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountRepository;
use Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine\DoctrineBankAccountRepository;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The locking read has to answer about the DATABASE, not about the unit of work, and that distinction is
 * invisible to every other test of this adapter because they all reach it with an empty identity map.
 *
 * `EntityManager::find()` consults the identity map first: on a hit it routes a pessimistic lock through
 * `EntityPersister::refresh()` and returns the managed instance regardless of what the refresh found. So a
 * caller holding the aggregate would be handed a live-looking snapshot of a deleted row — and the one
 * caller of this method is subject erasure, which would then report having erased a record that was already
 * gone. That failure is silent, safe-looking and wrong, which is why it is pinned rather than documented.
 *
 * Nothing is committed. The whole case runs inside one transaction that is rolled back, which also supplies
 * the active transaction `Query::setLockMode()` requires; the shared database is untouched either way.
 *
 * @internal
 */
#[CoversClass(DoctrineBankAccountRepository::class)]
final class BankAccountLockingReadFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    private EntityManagerInterface $entityManager;

    private BankAccountRepository $accounts;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = $this->service(EntityManagerInterface::class);
        $this->accounts = $this->service(BankAccountRepository::class);
    }

    #[Test]
    public function aVanishedRowReadsAsAbsentEvenWhenTheAggregateIsAlreadyManaged(): void
    {
        $bankId = Uuid::generate();
        $accountId = Uuid::generate();
        $connection = $this->entityManager->getConnection();

        $connection->beginTransaction();

        try {
            $this->entityManager->persist(Bank::create($bankId, 'Bank ' . $bankId, $this->shortCode($bankId)));
            $this->entityManager->flush();
            $this->entityManager->persist(
                BankAccount::create($accountId, $bankId, 'Juan Pérez', $this->unusedIban($accountId)),
            );
            $this->entityManager->flush();

            // Anti-vacuity, and it is the whole precondition: the identity map must HOLD the aggregate when
            // the locking read runs. Without this the case degrades into "a read of a row that is not
            // there", which `find()` answers correctly too and which proves nothing about this method.
            $managed = $this->accounts->findById($accountId);

            $this->assertInstanceOf(BankAccount::class, $managed);
            $this->assertTrue(
                $this->entityManager->getUnitOfWork()->isInIdentityMap($managed),
                'the aggregate must be managed for this case to describe the identity-map path',
            );

            // The row goes behind the ORM's back, so the unit of work still believes in it. A concurrent
            // erasure committing its own delete leaves this transaction in exactly that state.
            $connection->delete('bank_account', ['id' => $accountId]);

            $this->assertNotInstanceOf(
                BankAccount::class,
                $this->accounts->findByIdForUpdate($accountId),
                'the locking read answered from the identity map instead of from the row, so a caller that '
                . 'had already loaded this account would decide the fate of an aggregate that is gone',
            );
        } finally {
            $connection->rollBack();
            $this->entityManager->clear();
        }
    }

    /** A code no fixture holds, derived from the id so two runs never collide. */
    private function shortCode(string $bankId): string
    {
        return 'BNK' . \strtoupper(\substr(\str_replace('-', '', $bankId), 0, 8));
    }

    /** Unique per run and never asserted on — the account only has to exist and be loadable. */
    private function unusedIban(string $accountId): string
    {
        return 'ES00' . \strtoupper(\substr(\str_replace('-', '', $accountId), 0, 20));
    }
}
