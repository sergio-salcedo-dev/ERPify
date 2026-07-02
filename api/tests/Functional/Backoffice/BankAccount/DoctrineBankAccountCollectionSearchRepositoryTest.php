<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\BankAccount;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\BankAccount\Domain\Entity\BankAccount;
use Erpify\Backoffice\BankAccount\Domain\Enum\BankAccountStatus;
use Erpify\Backoffice\BankAccount\Domain\Projection\BankAccountCollectionRow;
use Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine\DoctrineBankAccountCollectionSearchRepository;
use Erpify\Backoffice\BankAccount\Infrastructure\Persistence\Doctrine\IbanFieldNormalizer;
use Erpify\Shared\Kernel\Domain\Enum\Currency;
use Erpify\Shared\Search\Domain\Filter;
use Erpify\Shared\Search\Domain\Filters;
use Erpify\Shared\Search\Domain\NavigationDirection;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Erpify\Shared\Search\Domain\SortDirection;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\DoctrineSearchEngine;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * GATE — proves the shared keyset {@see DoctrineSearchEngine} paginates a `SELECT NEW <projection>(…)`
 * query that JOINs `BankAccount → Bank` against REAL Postgres: the joined bank identity hydrates onto
 * each row, the `createdAt` cursor round-trips across pages without gaps or repeats, and DQL `NEW`
 * yields the `Currency`/`BankAccountStatus` enums and `DateTimeImmutable` timestamps (not backing
 * scalars). This is the first JOIN driven through the keyset engine; nothing HTTP is built on it until
 * this passes.
 *
 * Each test runs inside {@see inRolledBackTransaction}: the suite shares the dev database and has no
 * DAMA auto-rollback, so the bank/bank_account tables are truncated and re-seeded inside a transaction
 * that is always rolled back — the seeded rows never leak.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(DoctrineBankAccountCollectionSearchRepository::class)]
#[CoversClass(BankAccountCollectionRow::class)]
final class DoctrineBankAccountCollectionSearchRepositoryTest extends KernelTestCase
{
    private const string BANK_ALPHA_NAME = 'Alpha Bank';

    private const string BANK_ALPHA_SHORT = 'ALPHA';

    private const string BANK_BETA_NAME = 'Beta Bank';

    private const string BANK_BETA_SHORT = 'BETA';

    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private DoctrineBankAccountCollectionSearchRepository $repository;

    /**
     * The gate proves the engine + JOIN projection against real Postgres, so it builds the repository
     * from its REAL collaborators — the shared keyset engine and the IBAN field normalizer — never a fake.
     */
    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();

        $searchEngine = self::getContainer()->get(DoctrineSearchEngine::class);
        $this->assertInstanceOf(DoctrineSearchEngine::class, $searchEngine);

        $ibanNormalizer = self::getContainer()->get(IbanFieldNormalizer::class);
        $this->assertInstanceOf(IbanFieldNormalizer::class, $ibanNormalizer);

        $this->repository = new DoctrineBankAccountCollectionSearchRepository(
            $entityManager,
            $searchEngine,
            $ibanNormalizer,
        );
    }

    public function testFirstPageHydratesTheJoinedBankIdentityAndProjectionTypes(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $banks = $this->seedDataset();

            $page = $this->repository->search(new SearchCriteria(limit: 2, sort: 'createdAt'));

            $this->assertSame(['Account One', 'Account Two'], $this->holders($page));

            // (a) bankName / bankShortName resolved from the JOINed bank, not null/empty.
            $first = $this->firstRow($page);
            $this->assertSame(self::BANK_ALPHA_NAME, $first->bankName);
            $this->assertSame(self::BANK_ALPHA_SHORT, $first->bankShortName);
            $this->assertSame($banks['alpha'], $first->bankId);

            // (d) DQL NEW hydrates the enum CASE (proven via the 3-case BankAccountStatus, which a
            // string 'ACTIVE' could never equal) and a DateTimeImmutable whose value round-trips. The
            // enum/DateTime hydration is also guarded structurally: a backing scalar would have thrown
            // a TypeError against the row's typed constructor before any assertion ran. (Currency is a
            // single-case enum, so it carries no discriminating assertion of its own.)
            $this->assertSame(BankAccountStatus::ACTIVE, $first->status);
            $this->assertSame('2026-01-01 10:00:00', $first->createdAt->format('Y-m-d H:i:s'));
            $this->assertNull($first->bic);
            $this->assertNull($first->alias);

            // The second row carries its own bank and the optional bic/alias populated.
            $second = $page->items[1] ?? null;
            $this->assertInstanceOf(BankAccountCollectionRow::class, $second);
            $this->assertSame(self::BANK_BETA_NAME, $second->bankName);
            $this->assertSame(self::BANK_BETA_SHORT, $second->bankShortName);
            $this->assertSame('DEUTDEFFXXX', $second->bic);
            $this->assertSame('Beta Treasury', $second->alias);
            $this->assertSame(BankAccountStatus::INACTIVE, $second->status);
        });
    }

    public function testFirstPageReportsANextCursorAndNoPrevious(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedDataset();

            $page = $this->repository->search(new SearchCriteria(limit: 2, sort: 'createdAt'));

            $this->assertCount(2, $page->items);
            $this->assertTrue($page->hasNext);
            $this->assertFalse($page->hasPrev);
            $this->assertNotNull($page->nextCursor);
        });
    }

    public function testFollowingTheNextCursorContinuesWithoutGapsOrRepeats(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedDataset();

            $page1 = $this->repository->search(new SearchCriteria(limit: 2, sort: 'createdAt'));
            $this->assertNotNull($page1->nextCursor);

            $page2 = $this->repository->search(new SearchCriteria(
                cursor: $page1->nextCursor,
                limit: 2,
                sort: 'createdAt',
            ));

            // The remaining row, no overlap with page 1, and the full ordering reconstructs exactly.
            $this->assertSame(['Account Three'], $this->holders($page2));
            $this->assertSame([], \array_intersect($this->holders($page1), $this->holders($page2)));
            $this->assertSame(
                ['Account One', 'Account Two', 'Account Three'],
                [...$this->holders($page1), ...$this->holders($page2)],
            );
            $this->assertFalse($page2->hasNext);
            $this->assertTrue($page2->hasPrev);
        });
    }

    public function testBackwardCursorRoundTripsToTheFirstPage(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedDataset();

            $page1 = $this->repository->search(new SearchCriteria(limit: 2, sort: 'createdAt'));
            $page2 = $this->repository->search(new SearchCriteria(
                cursor: $page1->nextCursor,
                limit: 2,
                sort: 'createdAt',
            ));
            $this->assertNotNull($page2->prevCursor);

            $back = $this->repository->search(new SearchCriteria(
                cursor: $page2->prevCursor,
                routingDirection: NavigationDirection::Before,
                limit: 2,
                sort: 'createdAt',
            ));

            $this->assertSame($this->holders($page1), $this->holders($back));
        });
    }

    public function testBankIdFilterNarrowsToASingleBank(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $banks = $this->seedDataset();

            $page = $this->repository->search(new SearchCriteria(
                limit: 10,
                filters: Filters::fromList([Filter::eq('bankId', $banks['alpha'])]),
                sort: 'createdAt',
            ));

            // Alpha owns "Account One" and "Account Three"; "Account Two" belongs to Beta.
            $this->assertSame(['Account One', 'Account Three'], $this->holders($page));

            foreach ($page->items as $row) {
                $this->assertSame($banks['alpha'], $row->bankId);
                $this->assertSame(self::BANK_ALPHA_NAME, $row->bankName);
            }
        });
    }

    public function testBankFilterMatchesByNameOrShortCodeCaseInsensitively(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedDataset();

            // A lower-case term matches the mixed-case bank name AND the upper-case short code through
            // the single CONTAINS over CONCAT(name, ' ', shortName): "beta" hits "Beta Bank"/"BETA".
            $byBeta = $this->repository->search(new SearchCriteria(
                limit: 10,
                filters: Filters::fromList([Filter::contains('bank', 'beta')]),
                sort: 'createdAt',
            ));
            $this->assertSame(['Account Two'], $this->holders($byBeta));

            // A partial term is a valid substring (unlike an exact id): "alph" narrows to Alpha's two.
            $byAlpha = $this->repository->search(new SearchCriteria(
                limit: 10,
                filters: Filters::fromList([Filter::contains('bank', 'alph')]),
                sort: 'createdAt',
            ));
            $this->assertSame(['Account One', 'Account Three'], $this->holders($byAlpha));
        });
    }

    public function testDescendingCreatedAtSortReversesTheCollection(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedDataset();

            $page = $this->repository->search(new SearchCriteria(
                limit: 10,
                sort: 'createdAt',
                direction: SortDirection::DESC,
            ));

            $this->assertSame(['Account Three', 'Account Two', 'Account One'], $this->holders($page));
        });
    }

    /**
     * Seeds two banks and three accounts spread across them with DISTINCT createdAt values so the
     * `createdAt` ordering is deterministic. Banks are flushed before accounts to satisfy the
     * `bank_account.bank_id` foreign key.
     *
     * @return array{alpha: string, beta: string} the seeded bank ids
     */
    private function seedDataset(): array
    {
        $this->connection->executeStatement('TRUNCATE bank_account, bank RESTART IDENTITY CASCADE');

        $alphaId = Uuid::v7()->toRfc4122();
        $betaId = Uuid::v7()->toRfc4122();

        $this->entityManager->persist(Bank::create($alphaId, self::BANK_ALPHA_NAME, self::BANK_ALPHA_SHORT));
        $this->entityManager->persist(Bank::create($betaId, self::BANK_BETA_NAME, self::BANK_BETA_SHORT));
        $this->entityManager->flush();

        $this->persistAccount(
            $alphaId,
            'Account One',
            'DE89370400440532013000',
            new DateTimeImmutable('2026-01-01 10:00:00'),
        );
        $this->persistAccount(
            $betaId,
            'Account Two',
            'GB29NWBK60161331926819',
            new DateTimeImmutable('2026-01-01 11:00:00'),
            'DEUTDEFFXXX',
            'Beta Treasury',
            BankAccountStatus::INACTIVE,
        );
        $this->persistAccount(
            $alphaId,
            'Account Three',
            'FR1420041010050500013M02606',
            new DateTimeImmutable('2026-01-01 12:00:00'),
        );

        $this->entityManager->flush();
        $this->entityManager->clear();

        return ['alpha' => $alphaId, 'beta' => $betaId];
    }

    private function persistAccount(
        string $bankId,
        string $holderName,
        string $iban,
        DateTimeImmutable $createdAt,
        ?string $bic = null,
        ?string $alias = null,
        BankAccountStatus $status = BankAccountStatus::ACTIVE,
    ): void {
        $account = BankAccount::create(
            Uuid::v7()->toRfc4122(),
            $bankId,
            $holderName,
            $iban,
            $bic,
            $alias,
            Currency::EUR,
            $status,
        );
        $account->setCreatedAt($createdAt);

        $this->entityManager->persist($account);
    }

    /**
     * @param Page<BankAccountCollectionRow> $page
     *
     * @return list<string>
     */
    private function holders(Page $page): array
    {
        return \array_map(static fn (BankAccountCollectionRow $row): string => $row->holderName, $page->items);
    }

    /**
     * @param Page<BankAccountCollectionRow> $page
     */
    private function firstRow(Page $page): BankAccountCollectionRow
    {
        $first = $page->items[0] ?? null;
        $this->assertInstanceOf(BankAccountCollectionRow::class, $first);

        return $first;
    }

    /**
     * @param callable(): void $testBody
     */
    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->connection->beginTransaction();

        try {
            $testBody();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
