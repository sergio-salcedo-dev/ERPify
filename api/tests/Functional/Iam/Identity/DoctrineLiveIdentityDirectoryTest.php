<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DoctrineLiveIdentityDirectory;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the batch existence probe against REAL Postgres, where the two things that can go wrong live: the
 * `uuid` comparison of an expanded `IN` list, and the case of the ids it hands back. RFC 4122 hex is
 * case-insensitive, and the caller diffs this result against its own list with `===` — so an adapter
 * returning the database's canonical spelling would make a present identity read as an erased one, which
 * this control would then report as a compliance divergence.
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

    public function testItAnswersAnEmptyBatchWithoutQuerying(): void
    {
        // An expanded `IN ()` is not valid SQL, so the empty batch has to short-circuit rather than reach
        // the connection at all.
        $this->assertSame([], $this->directory->existingIdsAmong([]));
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
