<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Exception\AccountDeactivated;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DbalSessionSubjectLock;
use Erpify\Iam\Session\Domain\SubjectAdmission;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\DataFixtures\UserFixtureFactory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * The admission rule lives twice — in the adapter that answers a login's mint from the locked row, and in
 * `User::ensureActive()`, which every re-authentication and the firewall's wall weigh — because the session
 * context may not read the identity aggregate. This holds the two in agreement over every status the identity
 * can carry, read through the real adapter against Postgres. The cases are derived from
 * {@see IdentityStatus::cases()}, so a status added to the enum runs here before anything decides how it admits.
 *
 * Each case seeds its row inside a transaction that always rolls back.
 *
 * @internal
 */
#[CoversClass(DbalSessionSubjectLock::class)]
final class DbalSessionSubjectLockAdmissionTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $connection;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
    }

    #[DataProvider('provideTheMintAndTheAggregateAdmitTheSameStatusesCases')]
    public function testTheMintAndTheAggregateAdmitTheSameStatuses(IdentityStatus $status): void
    {
        $userId = Uuid::generate();
        $user = UserFixtureFactory::createWithPasswordHash(
            $userId,
            \sprintf('admission-%s@erpify.test', \str_replace('-', '', $userId)),
            'hashed-password-placeholder',
            [],
            $status->value,
        );

        $this->connection->beginTransaction();

        try {
            $this->entityManager->persist($user);
            $this->entityManager->flush();
            $this->entityManager->clear();

            $admission = (new DbalSessionSubjectLock($this->connection))->holdForAdmission($userId);
        } finally {
            $this->connection->rollBack();
        }

        $this->assertSame(
            $this->whatTheAggregateSays($user),
            $admission,
            \sprintf('the mint and User::ensureActive() disagree about %s', $status->value),
        );
    }

    /**
     * @return iterable<string, array{IdentityStatus}>
     */
    public static function provideTheMintAndTheAggregateAdmitTheSameStatusesCases(): iterable
    {
        foreach (IdentityStatus::cases() as $status) {
            yield $status->value => [$status];
        }
    }

    public function testAnIdentityWithNoRowIsGone(): void
    {
        $this->connection->beginTransaction();

        try {
            $admission = (new DbalSessionSubjectLock($this->connection))->holdForAdmission(Uuid::generate());
        } finally {
            $this->connection->rollBack();
        }

        $this->assertSame(SubjectAdmission::Gone, $admission);
    }

    private function whatTheAggregateSays(User $user): SubjectAdmission
    {
        try {
            $user->ensureActive();
        } catch (AccountSuspended) {
            return SubjectAdmission::Suspended;
        } catch (AccountDeactivated) {
            return SubjectAdmission::Deactivated;
        } catch (Throwable $unexpected) {
            $this->fail('ensureActive() raised something no admission maps: ' . $unexpected::class);
        }

        return SubjectAdmission::Admissible;
    }
}
