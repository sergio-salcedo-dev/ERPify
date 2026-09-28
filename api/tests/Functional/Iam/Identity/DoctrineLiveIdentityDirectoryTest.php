<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\CorruptIdentityRow;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DoctrineLiveIdentityDirectory;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Uuid\Domain\Uuid;
use InvalidArgumentException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the batch existence probe against REAL Postgres, where the three things that can go wrong live: the
 * `uuid` comparison of an expanded `IN` list, the driver's bound-parameter ceiling that list meets past
 * 65535 ids, and the case of the ids it hands back. RFC 4122 hex is case-insensitive, and the caller diffs
 * this result against its own list with `===` — so an adapter returning the database's canonical spelling
 * would make a present identity read as an erased one, which this control would then report as a compliance
 * divergence.
 *
 * Ids are generated per run and asserted by containment, so the shared dev database's own rows cannot make
 * this pass or fail. Each test runs inside a rolled-back transaction and leaves nothing behind.
 *
 * @internal
 */
#[CoversClass(DoctrineLiveIdentityDirectory::class)]
final class DoctrineLiveIdentityDirectoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private DoctrineLiveIdentityDirectory $directory;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();

        $this->directory = new DoctrineLiveIdentityDirectory($this->connection);
    }

    public function testItSeparatesTheLiveIdentitiesFromTheGoneOnesInOneBatch(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $liveId = $this->seedIdentity();
            $goneId = Uuid::generate();

            $this->assertSame(1, $this->identityRows($liveId), 'the seeded identity really exists');
            $this->assertSame(0, $this->identityRows($goneId), 'and the other one really does not');

            $this->assertSame([$liveId], $this->directory->existingIdsAmong([$liveId, $goneId]));
        });
    }

    public function testItAnswersABatchPastTheBoundParameterCeiling(): void
    {
        // 70 000 ids bound in one statement is past PostgreSQL's 65535-parameter ceiling, where the driver
        // refuses the query outright. The adapter must split it, and the one live id must survive the split.
        $this->inRolledBackTransaction(function (): void {
            $liveId = $this->seedIdentity();
            $ids = [];

            for ($i = 0; $i < 69_999; ++$i) {
                $ids[] = Uuid::generate();
            }

            $ids[] = $liveId;

            $this->assertCount(70_000, \array_unique($ids), 'the batch really is past the ceiling');

            $this->assertSame([$liveId], $this->directory->existingIdsAmong($ids));
        });
    }

    public function testItKeepsTheCallersOrderAndSpellingAcrossChunks(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $firstLive = $this->seedIdentity();
            $secondLive = \strtoupper($this->seedIdentity());
            $gone = [Uuid::generate(), Uuid::generate(), Uuid::generate()];

            // Chunks of two: [firstLive, gone0], [gone1, SECONDLIVE], [gone2] — the live ids sit in
            // different statements, and the second is spelled differently from how Postgres writes it.
            $batch = [$firstLive, $gone[0], $gone[1], $secondLive, $gone[2]];

            $this->assertSame(
                [$firstLive, $secondLive],
                (new DoctrineLiveIdentityDirectory($this->connection, 2))->existingIdsAmong($batch),
            );
        });
    }

    public function testItProbesEveryIdExactlyOnceHoweverTheBatchIsCut(): void
    {
        $batch = [Uuid::generate(), Uuid::generate(), Uuid::generate(), Uuid::generate(), Uuid::generate()];
        $probed = [];

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(3))
            ->method('fetchFirstColumn')
            ->with($this->anything(), $this->callback(static function (mixed $parameters) use (&$probed): bool {
                \assert(\is_array($parameters) && \is_array($parameters['ids'] ?? null));
                \array_push($probed, ...$parameters['ids']);

                return true;
            }))
            ->willReturn([])
        ;

        (new DoctrineLiveIdentityDirectory($connection, 2))->existingIdsAmong($batch);

        $this->assertSame($batch, $probed, 'each id lands in exactly one chunk, in order');
    }

    public function testItRefusesAChunkSizeNoStatementCanCarry(): void
    {
        $refused = [];

        foreach ([0, 65_536] as $chunkSize) {
            try {
                new DoctrineLiveIdentityDirectory($this->connection, $chunkSize);
            } catch (InvalidArgumentException) {
                $refused[] = $chunkSize;
            }
        }

        $this->assertSame([0, 65_536], $refused);
    }

    public function testAChunkAtTheCeilingIsOneStatementTheDriverAccepts(): void
    {
        $absent = [];

        for ($i = 0; $i < 65_535; ++$i) {
            $absent[] = Uuid::generate();
        }

        $this->assertSame(
            [],
            (new DoctrineLiveIdentityDirectory($this->connection, 65_535))->existingIdsAmong($absent),
        );
    }

    public function testItAnswersAnEmptyBatchWithoutQuerying(): void
    {
        // Asserted on the ABSENCE OF THE QUERY, not on the return value. DBAL expands an empty array
        // parameter to the literal `NULL`, so `… IN (NULL)` is valid SQL that returns nothing: an assertion
        // on the result alone stays green with the short-circuit deleted, and would be a test that cannot
        // fail for the reason its name gives.
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('fetchFirstColumn');

        $this->assertSame([], (new DoctrineLiveIdentityDirectory($connection))->existingIdsAmong([]));
    }

    public function testItReturnsTheCallersOwnSpellingOfAnIdentityItFound(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $liveId = $this->seedIdentity();
            $shouted = \strtoupper($liveId);

            // Postgres matches it — `id` is a `uuid` column — and the caller gets its own string back, so a
            // `===` diff against the list it passed in still resolves.
            $this->assertSame([$shouted], $this->directory->existingIdsAmong([$shouted]));
        });
    }

    /**
     * A row that is not an identity id stops the reconciliation instead of shrinking it. Driven through a
     * mocked connection because the schema cannot produce one — `identity_user.id` is a non-null `uuid` — and
     * that is exactly why the guard needs its own red: dropping the row here would report a live person as
     * erased, a fabricated GDPR divergence with nothing to distinguish it from a real one.
     */
    public function testACorruptRowStopsTheProbeRatherThanShrinkingIt(): void
    {
        // A stub, not a mock: the call is the test's input, not an expectation about it — the assertion is
        // the throw. Configuring it as a mock with no expectation is what PHPUnit notices.
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([42]);

        $this->expectException(CorruptIdentityRow::class);

        (new DoctrineLiveIdentityDirectory($connection))->existingIdsAmong([Uuid::generate()]);
    }

    private function seedIdentity(): string
    {
        $id = Uuid::generate();
        $this->entityManager->persist(User::register(
            $id,
            \sprintf('live-%s@erpify.test', $id),
            HashedPassword::fromHash('hashed-' . $id),
            Role::AUDIT_READER,
        ));
        $this->entityManager->flush();

        return $id;
    }

    private function identityRows(string $id): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM identity_user WHERE id = CAST(:id AS UUID)',
            ['id' => $id],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
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
