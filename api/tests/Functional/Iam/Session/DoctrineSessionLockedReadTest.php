<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Session;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Iam\Session\Infrastructure\Persistence\Doctrine\DoctrineSessionRepository;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Double\Clock\FixedClock;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The locked read every "revoke the others" decision is taken on, against REAL Postgres: which rows it holds
 * and in what order. Apart from {@see DoctrineSessionRepositoryTest} because its subject is the lock set the
 * two eviction paths share, not a read the gate or the listing performs. That the rows are really HELD is
 * observed from a second connection in
 * {@see \Erpify\Tests\Functional\Iam\Identity\RecoverySecretLockOrderFunctionalTest}, which is the one
 * place their rows are committed where a second connection can see them.
 *
 * @internal
 */
#[CoversClass(DoctrineSessionRepository::class)]
final class DoctrineSessionLockedReadTest extends KernelTestCase
{
    private const string NOW = '2026-07-10T12:00:00+00:00';

    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private DoctrineSessionRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->repository = new DoctrineSessionRepository(
            $entityManager,
            new FixedClock(new DateTimeImmutable(self::NOW)),
        );
    }

    public function testLockActiveForUserReturnsTheSetTheBulkRevocationFlipsInAscendingIdOrder(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $userId = Uuid::generate();
            // Saved in descending id order, so an answer in insertion order cannot pass for the id order.
            $first = Uuid::generate();
            $second = Uuid::generate();
            $third = Uuid::generate();
            $this->repository->save($this->activeSession($third, $userId, '+1 hour'));
            // Expired but still ACTIVE: the bulk UPDATE flips it, so the lock has to hold it too.
            $this->repository->save($this->activeSession($second, $userId, '-1 hour'));
            $this->repository->save($this->activeSession($first, $userId, '+1 hour'));

            $revoked = $this->activeSession(Uuid::generate(), $userId, '+1 hour');
            $revoked->revoke();
            $revoked->pullDomainEvents();

            $this->repository->save($revoked);
            $this->repository->save($this->activeSession(Uuid::generate(), Uuid::generate(), '+1 hour'));

            $this->entityManager->clear();

            $locked = \array_map(
                static fn (SessionId $id): string => $id->toString(),
                $this->repository->lockActiveForUser($userId),
            );

            $expected = [$first, $second, $third];
            \sort($expected);
            $this->assertSame($expected, $locked);
        });
    }

    private function activeSession(string $id, string $userId, string $expiryOffset): Session
    {
        $session = Session::start(
            $id,
            $userId,
            Uuid::generate(),
            'Chrome on macOS',
            '203.0.113.7',
            (new DateTimeImmutable(self::NOW))->modify($expiryOffset),
        );
        $session->pullDomainEvents();

        return $session;
    }

    /**
     * @param callable(): void $testBody
     */
    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement('TRUNCATE iam_session RESTART IDENTITY CASCADE');
            $testBody();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
