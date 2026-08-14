<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DoctrineUserRepository;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Persistence\Domain\Exception\ConcurrentUniqueWrite;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

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
#[CoversClass(ConcurrentUniqueWrite::class)]
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
            $passwordHash = $found->passwordHash();
            $this->assertInstanceOf(HashedPassword::class, $passwordHash);
            $this->assertTrue($passwordHash->equals(HashedPassword::fromHash('hashed-carol@erpify.test')));
        });
    }

    public function testFindByEmailIsCaseInsensitive(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->repository->save($this->newUser('dave@erpify.test'));
            $this->entityManager->clear();

            $found = $this->repository->findByEmail(Email::from('  DAVE@ERPify.TEST  '));

            $this->assertInstanceOf(User::class, $found);
            $this->assertSame('dave@erpify.test', $found->email());
        });
    }

    /**
     * The index refuses it, and what the port hands back is a conflict rather than the driver's own
     * message — which names the offending value, and an email address identifies a natural person.
     * Untranslated this is an `unhandled-exception` 500, and a 500 is not a `ClientError`, so the
     * message reaches the error log AND the Sentry event, whose retention no erasure path can touch.
     */
    public function testDuplicateEmailArrivesAsAConflictAndNotAsTheDriversMessage(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->repository->save($this->newUser('erin@erpify.test'));

            try {
                // Canonicalises to the same stored email, so the UNIQUE index catches it.
                $this->repository->save($this->newUser('ERIN@erpify.test'));

                $this->fail('Postgres accepted a second row on a unique index.');
            } catch (ConcurrentUniqueWrite $concurrentUniqueWrite) {
                $this->assertSame('concurrent-unique-write', $concurrentUniqueWrite->type());
                // Three ports raise this one type, so `resource` is the only payload telling them
                // apart — and the only one of the three assertions here that a wrong literal reds.
                $this->assertSame('identity-user', $concurrentUniqueWrite->context()['resource'] ?? null);
                // `previous` is the reachable leak vector: both siblings in that namespace keep the
                // driver exception there on purpose, so harmonising the three would carry
                // `Key (email)=(…) already exists.` into the dev/test `debug.previous_chain`.
                $this->assertNotInstanceOf(Throwable::class, $concurrentUniqueWrite->getPrevious());
                // The message is a literal on the class, so this cannot fail for any implementation of
                // the port; it watches the title itself never coming to name what it refused.
                $this->assertStringNotContainsStringIgnoringCase(
                    'erin@erpify.test',
                    $concurrentUniqueWrite->getMessage(),
                );
            }
        });
    }

    public function testFindByIdForUpdateTakesARowLevelWriteLock(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $user = $this->newUser('grace@erpify.test');
            $id = $user->getId();
            $this->assertNotNull($id);
            $this->repository->save($user);

            $this->repository->findByIdForUpdate($id);

            // SELECT … FOR UPDATE acquires RowShareLock on the table (a plain SELECT only takes
            // AccessShareLock), so its presence for this backend proves the locking form of the query.
            $rowShareLocks = $this->connection->fetchOne(
                'SELECT count(*) FROM pg_locks l JOIN pg_class c ON c.oid = l.relation'
                . " WHERE c.relname = 'identity_user' AND l.mode = 'RowShareLock' AND l.pid = pg_backend_pid()",
            );

            $this->assertSame(1, (int) (\is_scalar($rowShareLocks) ? $rowShareLocks : 0));
        });
    }

    public function testFindByIdForUpdateRefreshesAStaleManagedAggregate(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $user = $this->newUser('heidi@erpify.test');
            $id = $user->getId();
            $this->assertNotNull($id);
            $this->repository->save($user);

            // A rival write lands behind the ORM's back after the aggregate is already managed — the exact
            // TOCTOU window: without the refresh, the locked re-fetch would serve the stale ACTIVE snapshot
            // from the identity map and the status re-check would miss the suspension.
            $this->connection->executeStatement(
                "UPDATE identity_user SET status = 'SUSPENDED' WHERE id = :id",
                ['id' => $id],
            );

            $reloaded = $this->repository->findByIdForUpdate($id);

            $this->assertInstanceOf(User::class, $reloaded);
            $this->assertSame(IdentityStatus::SUSPENDED, $reloaded->status());
        });
    }

    public function testFindByIdForUpdateReturnsNullForAnUnknownId(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->assertNotInstanceOf(
                User::class,
                $this->repository->findByIdForUpdate('0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5eff'),
            );
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
