<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\BankAccount;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine\DoctrineBankAccountSearchRepository;
use Erpify\Shared\Kernel\Domain\Enum\Currency;
use Erpify\Shared\Search\Domain\NavigationDirection;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Erpify\Shared\Search\Domain\SortDirection;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\DoctrineSearchEngine;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The per-bank account listing against real Postgres, through the real keyset engine: it is scoped to the bank
 * in the path, it sorts by the fields its map declares, and its cursors walk forwards and back. Each claim is the
 * repository's own — the engine is shared, but the scope predicate, the sort map and the cursor direction are
 * decided here.
 *
 * Each test runs inside a transaction that is always rolled back, so the rows it seeds never leak.
 *
 * @internal
 */
#[CoversClass(DoctrineBankAccountSearchRepository::class)]
final class DoctrineBankAccountSearchRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private DoctrineBankAccountSearchRepository $repository;

    private string $bankId;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $searchEngine = self::getContainer()->get(DoctrineSearchEngine::class);
        $this->assertInstanceOf(DoctrineSearchEngine::class, $searchEngine);

        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->repository = new DoctrineBankAccountSearchRepository($entityManager, $searchEngine);

        $this->connection->beginTransaction();
        $this->bankId = $this->seedBank('Scoped Bank', 'SCOPED');
        $otherBankId = $this->seedBank('Other Bank', 'OTHER');
        $this->seedAccount($this->bankId, 'Carla', 'DE89370400440532013000');
        $this->seedAccount($otherBankId, 'Bruno', 'GB29NWBK60161331926819');
        $this->seedAccount($this->bankId, 'Ana', 'FR1420041010050500013M02606');
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itListsOnlyTheAccountsOfTheBankInThePath(): void
    {
        $page = $this->repository->search($this->bankId, new SearchCriteria(sort: 'holderName'));

        $this->assertSame(['Ana', 'Carla'], $this->holders($page));
    }

    #[Test]
    public function itSortsByHolderNameInEitherDirection(): void
    {
        $descending = $this->repository->search(
            $this->bankId,
            new SearchCriteria(sort: 'holderName', direction: SortDirection::DESC),
        );

        $this->assertSame(['Carla', 'Ana'], $this->holders($descending));
    }

    #[Test]
    public function itsCursorsWalkForwardAndBackWithinTheBank(): void
    {
        $first = $this->repository->search($this->bankId, new SearchCriteria(limit: 1, sort: 'holderName'));
        $this->assertSame(['Ana'], $this->holders($first));
        $this->assertNotNull($first->nextCursor);

        $second = $this->repository->search(
            $this->bankId,
            new SearchCriteria(cursor: $first->nextCursor, limit: 1, sort: 'holderName'),
        );
        $this->assertSame(['Carla'], $this->holders($second));
        $this->assertNotNull($second->prevCursor);

        $back = $this->repository->search(
            $this->bankId,
            new SearchCriteria(
                cursor: $second->prevCursor,
                routingDirection: NavigationDirection::Before,
                limit: 1,
                sort: 'holderName',
            ),
        );
        $this->assertSame(['Ana'], $this->holders($back));
    }

    private function seedBank(string $name, string $shortName): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->entityManager->persist(Bank::create($id, $name, $shortName));
        $this->entityManager->flush();

        return $id;
    }

    private function seedAccount(string $bankId, string $holderName, string $iban): void
    {
        $this->entityManager->persist(BankAccount::create(
            Uuid::v7()->toRfc4122(),
            $bankId,
            $holderName,
            $iban,
            null,
            null,
            Currency::EUR,
        ));
    }

    /**
     * @param Page<BankAccount> $page
     *
     * @return list<string>
     */
    private function holders(Page $page): array
    {
        return \array_map(static fn (BankAccount $account): string => $account->getHolderName(), $page->items);
    }
}
