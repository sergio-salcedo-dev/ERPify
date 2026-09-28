<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DoctrineUserRepository;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The credential compare-and-swap against REAL Postgres and the REAL unit of work, because what it guarantees
 * is about the managed aggregate as much as the row — no in-memory double has an identity map to get wrong.
 *
 * Each test runs inside {@see inRolledBackTransaction}, like its sibling {@see DoctrineUserRepositoryTest}.
 *
 * @internal
 */
#[CoversClass(DoctrineUserRepository::class)]
final class DoctrineUserRepositoryPasswordHashSwapTest extends KernelTestCase
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

    public function testTheCompareAndSwapReplacesTheVerifiedHashAndBringsTheManagedAggregateInLine(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $user = $this->newUser('kate@erpify.test');
            $id = $user->getId();
            $this->assertNotNull($id);
            $this->repository->save($user);
            $updatedAt = $this->storedColumn($id, 'updated_at');

            $swapped = $this->repository->replacePasswordHashIfUnchanged(
                $id,
                HashedPassword::fromHash('hashed-kate@erpify.test'),
                HashedPassword::fromHash('re-encoded'),
            );

            $this->assertTrue($swapped);
            $this->assertSame('re-encoded', $this->storedColumn($id, 'password_hash'));
            $this->assertSame('re-encoded', $user->passwordHash()?->toString(), 'the instance follows the row');
            $this->assertSame($updatedAt, $this->storedColumn($id, 'updated_at'), 'the sort key is untouched');

            // Its original data moved with it: a later flush in the same request has nothing to write back.
            $this->entityManager->getUnitOfWork()->computeChangeSets();
            $this->assertSame([], $this->entityManager->getUnitOfWork()->getEntityChangeSet($user));
        });
    }

    /**
     * The refusal is the security property. The instance a login holds is the one its session serialises and
     * compares against the row on every later request, so a refusal that re-hydrated it — as a locked re-read
     * would — would advance a session proven with a superseded password onto the credential that replaced it,
     * and keep it signed in after the reset meant to end it.
     */
    public function testTheCompareAndSwapRefusesAMovedCredentialWithoutTouchingTheManagedAggregate(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $user = $this->newUser('liam@erpify.test');
            $id = $user->getId();
            $this->assertNotNull($id);
            $this->repository->save($user);

            $this->connection->executeStatement(
                "UPDATE identity_user SET password_hash = 'set-by-a-reset' WHERE id = :id",
                ['id' => $id],
            );

            $swapped = $this->repository->replacePasswordHashIfUnchanged(
                $id,
                HashedPassword::fromHash('hashed-liam@erpify.test'),
                HashedPassword::fromHash('re-encoded'),
            );

            $this->assertFalse($swapped);
            $this->assertSame('set-by-a-reset', $this->storedColumn($id, 'password_hash'));
            $this->assertSame('hashed-liam@erpify.test', $user->passwordHash()?->toString());
        });
    }

    public function testTheCompareAndSwapAnswersFalseForAnUnknownId(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->assertFalse($this->repository->replacePasswordHashIfUnchanged(
                '0190e1f2-a3b4-7c5d-8e6f-1a2b3c4d5eff',
                HashedPassword::fromHash('anything'),
                HashedPassword::fromHash('re-encoded'),
            ));
        });
    }

    private function newUser(string $email): User
    {
        return User::register(
            Uuid::generate(),
            $email,
            HashedPassword::fromHash('hashed-' . $email),
            Role::AUDIT_READER,
        );
    }

    private function storedColumn(string $id, string $column): string
    {
        $value = $this->connection->fetchOne(
            \sprintf('SELECT %s FROM identity_user WHERE id = :id', $column),
            ['id' => $id],
        );
        $this->assertIsString($value);

        return $value;
    }

    /**
     * @param callable(): void $testBody
     */
    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement('TRUNCATE identity_user RESTART IDENTITY CASCADE');
            $testBody();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
