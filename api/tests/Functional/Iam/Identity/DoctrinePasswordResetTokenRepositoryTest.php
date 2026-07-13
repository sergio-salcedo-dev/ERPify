<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DoctrinePasswordResetTokenRepository;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the adapter against REAL Postgres: a token round-trips through `save`/`findById` (its digest and
 * expiry survive so a `verify` still holds), `consume` hard-deletes it and reports whether it removed a row
 * (the single-use guard a concurrent replay hits), and `deleteAllForUser` drops only the target user's tokens
 * (the supersede-on-reissue). Each test runs inside a rolled-back transaction that truncates the shared dev
 * table.
 *
 * @internal
 */
#[CoversClass(DoctrinePasswordResetTokenRepository::class)]
final class DoctrinePasswordResetTokenRepositoryTest extends KernelTestCase
{
    private const string USER_A = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

    private const string USER_B = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c';

    private const string LATER = '2026-07-13T13:00:00+00:00';

    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private DoctrinePasswordResetTokenRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();

        $this->repository = new DoctrinePasswordResetTokenRepository($entityManager);
    }

    public function testSaveThenFindByIdRoundTripsAndStillVerifies(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $now = new DateTimeImmutable('2026-07-13T12:00:00+00:00');
            $generated = SingleUseToken::mint($now->modify('+1 hour'));
            $id = Uuid::generate();

            $this->repository->save(PasswordResetToken::issue($id, self::USER_A, $generated->token));
            $this->entityManager->clear();

            $found = $this->repository->findById($id);
            $this->assertInstanceOf(PasswordResetToken::class, $found);
            $this->assertSame(self::USER_A, $found->userId());
            $this->assertTrue($found->verify($generated->plaintext(), $now));
        });
    }

    public function testFindByIdOfAMalformedSelectorIsNullNeverAStoreError(): void
    {
        $this->assertNotInstanceOf(PasswordResetToken::class, $this->repository->findById('not-a-uuid-selector'));
    }

    public function testConsumeHardDeletesTheTokenAndReportsWhetherItRemovedARow(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $id = Uuid::generate();
            $minted = SingleUseToken::mint(new DateTimeImmutable(self::LATER))->token;
            $token = PasswordResetToken::issue($id, self::USER_A, $minted);

            $this->repository->save($token);

            $this->assertTrue($this->repository->consume($token));
            $this->entityManager->clear();
            $this->assertNotInstanceOf(PasswordResetToken::class, $this->repository->findById($id));

            // A second consume of the same token deletes no row — the single-use guard a concurrent replay hits.
            $this->assertFalse($this->repository->consume($token));
        });
    }

    public function testDeleteAllForUserRemovesOnlyThatUsersTokens(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $firstA = Uuid::generate();
            $secondA = Uuid::generate();
            $onlyB = Uuid::generate();
            $firstToken = SingleUseToken::mint(new DateTimeImmutable(self::LATER))->token;
            $secondToken = SingleUseToken::mint(new DateTimeImmutable(self::LATER))->token;
            $thirdToken = SingleUseToken::mint(new DateTimeImmutable(self::LATER))->token;
            $this->repository->save(PasswordResetToken::issue($firstA, self::USER_A, $firstToken));
            $this->repository->save(PasswordResetToken::issue($secondA, self::USER_A, $secondToken));
            $this->repository->save(PasswordResetToken::issue($onlyB, self::USER_B, $thirdToken));

            $this->repository->deleteAllForUser(self::USER_A);

            $this->entityManager->clear();

            $this->assertNotInstanceOf(PasswordResetToken::class, $this->repository->findById($firstA));
            $this->assertNotInstanceOf(PasswordResetToken::class, $this->repository->findById($secondA));
            $this->assertInstanceOf(PasswordResetToken::class, $this->repository->findById($onlyB));
        });
    }

    /**
     * @param callable(): void $testBody
     */
    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement('TRUNCATE identity_password_reset_token RESTART IDENTITY CASCADE');
            $testBody();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
