<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Persistence\Infrastructure\KeysetDistinctIds;
use Erpify\Shared\Uuid\Domain\Uuid;
use InvalidArgumentException;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use UnexpectedValueException;

/**
 * Proves the shared keyset loop against REAL Postgres, over a temporary table it owns outright — so, unlike
 * the sources that use it, every assertion here is an exact equality rather than a containment. The table is
 * created inside a transaction that is rolled back, so it never outlives the test.
 *
 * The page boundaries are the subject: a column holding an exact multiple of the page size, one holding a
 * remainder, a repeated value straddling a boundary, and the scope applying to every page rather than to the
 * first. How many statements the loop issues is asserted separately against a stub, because that count is
 * the one fact real Postgres cannot report back.
 *
 * @internal
 */
#[CoversClass(KeysetDistinctIds::class)]
final class KeysetDistinctIdsTest extends KernelTestCase
{
    private Connection $connection;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
    }

    public function testItReadsAnExactMultipleOfThePageSize(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $ids = $this->seed(4);

            $this->assertSame($this->ascending($ids), (new KeysetDistinctIds($this->connection, 2))->idsOf(
                'keyset_probe',
                'ref',
            ));
        });
    }

    public function testItReadsAShortLastPage(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $ids = $this->seed(5);

            $this->assertSame($this->ascending($ids), (new KeysetDistinctIds($this->connection, 2))->idsOf(
                'keyset_probe',
                'ref',
            ));
        });
    }

    public function testItListsARepeatedValueOnceEvenAcrossAPageBoundary(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $ids = $this->seed(3);

            foreach (\array_slice($ids, 0, 2) as $repeated) {
                $this->insert($repeated);
                $this->insert($repeated);
            }

            $this->assertSame($this->ascending($ids), (new KeysetDistinctIds($this->connection, 1))->idsOf(
                'keyset_probe',
                'ref',
            ));
        });
    }

    public function testItExcludesNull(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $ids = $this->seed(2);
            $this->connection->executeStatement("INSERT INTO keyset_probe (ref, kind) VALUES (NULL, 'person')");

            $this->assertSame($this->ascending($ids), (new KeysetDistinctIds($this->connection, 1))->idsOf(
                'keyset_probe',
                'ref',
            ));
        });
    }

    public function testItAppliesTheScopeOnEveryPage(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $people = $this->seed(3);

            for ($i = 0; $i < 3; ++$i) {
                $this->insert(Uuid::generate(), 'bank');
            }

            $this->assertSame($this->ascending($people), (new KeysetDistinctIds($this->connection, 1))->idsOf(
                'keyset_probe',
                'ref',
                'kind = :kind',
                ['kind' => 'person'],
            ));
        });
    }

    public function testItAnswersAnEmptyColumnWithAnEmptyList(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seed(0);

            $this->assertSame([], (new KeysetDistinctIds($this->connection, 3))->idsOf('keyset_probe', 'ref'));
        });
    }

    public function testEachPageResumesAfterTheLastValueAndOnlyAShortPageEndsTheRead(): void
    {
        // An exact multiple costs one extra, empty statement; each statement after the first resumes from the
        // last value the previous page returned.
        $afters = [];
        $exact = $this->createMock(Connection::class);
        $exact->expects($this->exactly(3))
            ->method('fetchFirstColumn')
            ->with($this->anything(), $this->callback(static function (mixed $parameters) use (&$afters): bool {
                \assert(\is_array($parameters));
                $afters[] = $parameters['keyset_after'] ?? null;

                return true;
            }))
            ->willReturnOnConsecutiveCalls(['a', 'b'], ['c', 'd'], [])
        ;

        $this->assertSame(['a', 'b', 'c', 'd'], (new KeysetDistinctIds($exact, 2))->idsOf('t', 'c'));
        $this->assertSame([null, 'b', 'd'], $afters);

        // A short page is the last one, so it costs no further statement.
        $short = $this->createMock(Connection::class);
        $short->expects($this->exactly(2))
            ->method('fetchFirstColumn')
            ->willReturnOnConsecutiveCalls(['a', 'b'], ['c'])
        ;

        $this->assertSame(['a', 'b', 'c'], (new KeysetDistinctIds($short, 2))->idsOf('t', 'c'));
    }

    public function testANonStringValueStopsTheReadRatherThanBeingDropped(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn(['a', 42]);

        $this->expectException(UnexpectedValueException::class);

        (new KeysetDistinctIds($connection, 5))->idsOf('t', 'c');
    }

    public function testAScopeMayNotBindTheNamesThePagingUses(): void
    {
        $refused = [];

        foreach (['keyset_after', 'keyset_limit'] as $reserved) {
            try {
                (new KeysetDistinctIds($this->createStub(Connection::class)))->idsOf('t', 'c', 'x = :' . $reserved, [
                    $reserved => 'value',
                ]);
            } catch (LogicException) {
                $refused[] = $reserved;
            }
        }

        $this->assertSame(['keyset_after', 'keyset_limit'], $refused);
    }

    public function testItRefusesAPageSizeBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new KeysetDistinctIds($this->createStub(Connection::class), 0);
    }

    /**
     * @return list<string> the seeded ids, in insertion order
     */
    private function seed(int $count): array
    {
        $this->connection->executeStatement(
            'CREATE TEMP TABLE keyset_probe (ref UUID NULL, kind TEXT NOT NULL) ON COMMIT DROP',
        );

        $ids = [];

        for ($i = 0; $i < $count; ++$i) {
            $ids[] = $id = Uuid::generate();
            $this->insert($id);
        }

        $rows = $this->connection->fetchOne('SELECT COUNT(*) FROM keyset_probe');
        $this->assertIsNumeric($rows);
        $this->assertSame($count, (int) $rows, 'the seed really inserted every row');

        return $ids;
    }

    private function insert(string $ref, string $kind = 'person'): void
    {
        $this->connection->executeStatement(
            'INSERT INTO keyset_probe (ref, kind) VALUES (:ref, :kind)',
            ['ref' => $ref, 'kind' => $kind],
        );
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function ascending(array $ids): array
    {
        \sort($ids, SORT_STRING);

        return $ids;
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
