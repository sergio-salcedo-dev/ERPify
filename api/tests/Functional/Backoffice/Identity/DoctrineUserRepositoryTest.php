<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Backoffice\Identity\Domain\Entity\User;
use Erpify\Backoffice\Identity\Domain\Enum\Role;
use Erpify\Backoffice\Identity\Domain\HashedPassword;
use Erpify\Backoffice\Identity\Infrastructure\Persistence\Doctrine\DoctrineUserRepository;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the adapter against REAL Postgres: an aggregate round-trips through `save`/`findById`, the
 * email lookup is case-insensitive against the canonical UNIQUE column, the index rejects a duplicate
 * email, and `remove` hard-deletes (GDPR-satisfiable).
 *
 * Each test runs inside {@see inRolledBackTransaction}: the suite shares the dev database, so
 * `identity_user` is truncated and re-seeded inside a transaction that is always rolled back.
 *
 * @internal
 */
#[CoversClass(DoctrineUserRepository::class)]
final class DoctrineUserRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private DoctrineUserRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();

        $this->repository = new DoctrineUserRepository($entityManager);
    }

    public function testSaveThenFindByIdRoundTrips(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $user = $this->newUser('carol@erpify.test');
            $id = $user->getId();
            $this->assertNotNull($id);

            $this->repository->save($user);
            $this->entityManager->clear();

            $found = $this->repository->findById($id);
            $this->assertInstanceOf(User::class, $found);
            $this->assertSame('carol@erpify.test', $found->email());
            $this->assertSame([Role::AUDIT_READER], $found->roles());
            $this->assertTrue($found->passwordHash()->equals(HashedPassword::fromHash('hashed-carol@erpify.test')));
        });
    }

    public function testFindByEmailIsCaseInsensitive(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->repository->save($this->newUser('dave@erpify.test'));
            $this->entityManager->clear();

            $found = $this->repository->findByEmail('  DAVE@ERPify.TEST  ');

            $this->assertInstanceOf(User::class, $found);
            $this->assertSame('dave@erpify.test', $found->email());
        });
    }

    public function testDuplicateEmailIsRejectedByTheUniqueIndex(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->repository->save($this->newUser('erin@erpify.test'));

            $this->expectException(UniqueConstraintViolationException::class);

            // Canonicalises to the same stored email, so the UNIQUE index catches it.
            $this->repository->save($this->newUser('ERIN@erpify.test'));
        });
    }

    public function testRemoveHardDeletesTheAggregate(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $user = $this->newUser('frank@erpify.test');
            $id = $user->getId();
            $this->assertNotNull($id);

            $this->repository->save($user);
            $this->repository->remove($user);

            $this->entityManager->clear();

            $this->assertNotInstanceOf(User::class, $this->repository->findById($id));
        });
    }

    private function newUser(string $email): User
    {
        return User::register(
            Uuid::generate(),
            $email,
            HashedPassword::fromHash('hashed-' . \mb_strtolower(\trim($email))),
            Role::AUDIT_READER,
        );
    }

    /**
     * @param callable(): void $testBody
     */
    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->connection->beginTransaction();

        try {
            // TRUNCATE is transactional in Postgres, so it is undone with the rollback below.
            $this->connection->executeStatement('TRUNCATE identity_user RESTART IDENTITY CASCADE');
            $testBody();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
