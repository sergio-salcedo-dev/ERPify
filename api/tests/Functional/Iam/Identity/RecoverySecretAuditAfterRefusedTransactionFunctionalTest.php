<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\RecordRecoverySecretAuditBestEffort;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Exception\AccountSuspended;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Persistence\Infrastructure\DoctrineTransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The redemption's two late audit rows, written over the container's real services right after the
 * transaction that decides them — the half no unit test can reach, because it lives in how the entity manager
 * survives that transaction.
 *
 * **The compensated row is written straight after a REFUSED transaction**, which no other late writer is: the
 * consuming pass locks the user row and then raises a status wall or the opaque refusal, `wrapInTransaction`
 * closes the entity manager on the way out, and the compensation row then needs a fresh transaction on that
 * same manager — one whose identity-row lock must see the transaction on the connection the manager wraps. It
 * lands only because the transaction manager reopens a manager its own failure stranded. A stranded manager
 * runs the writer's lock and INSERT and then cannot commit them, so the row rolls back and is swallowed like
 * any lost projection, and an admitted-then-revoked session is left with no trace in the trail. The control
 * case strands the manager without that reopen and shows the row lost, so the first case cannot be green for
 * a reason that has nothing to do with it.
 *
 * The interrupted row follows a transaction that COMMITTED (the eviction), so it is the ordinary late-writer
 * shape, kept here as its sibling's baseline.
 *
 * The subject is committed, and it and every row written about it are removed by hand in `tearDown()`.
 *
 * @internal
 */
#[CoversClass(RecordRecoverySecretAuditBestEffort::class)]
#[CoversClass(DoctrineTransactionManager::class)]
final class RecoverySecretAuditAfterRefusedTransactionFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    private const string COMPENSATED_ACTION = 'RECOVERY_SECRET_REDEMPTION_COMPENSATED';

    private const string INTERRUPTED_ACTION = 'RECOVERY_SECRET_REDEMPTION_INTERRUPTED';

    private EntityManagerInterface $entityManager;

    private string $subjectId;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = $this->service(EntityManagerInterface::class);
        $this->subjectId = Uuid::generate();
        $this->seedCommittedSubject();
    }

    protected function tearDown(): void
    {
        if (!isset($this->entityManager)) {
            parent::tearDown();

            return;
        }

        $connection = $this->connection();

        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        $connection->executeStatement(
            'DELETE FROM audit_log WHERE resource_type = :type AND resource_id = CAST(:id AS UUID)',
            ['type' => FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, 'id' => $this->subjectId],
        );
        $connection->executeStatement(
            'DELETE FROM identity_user WHERE id = CAST(:id AS UUID)',
            ['id' => $this->subjectId],
        );
        parent::tearDown();
    }

    #[Test]
    public function theCompensatedRowLandsAfterTheRefusedTransactionClosedTheManager(): void
    {
        $this->refuseUnderTheSubjectsLock();

        $this->recorder()->recordRedemptionCompensated($this->subjectId);

        $this->assertSame(
            [self::COMPENSATED_ACTION],
            $this->actionsNamingTheSubject(),
            'The compensation row did not survive the refused transaction before it — the only durable trace '
            . 'of a session admitted and then revoked by the redemption is missing.',
        );
    }

    #[Test]
    public function aManagerLeftStrandedLosesTheRow(): void
    {
        // The same refusal, raised through the bare ORM call so nothing reopens the manager it closes.
        try {
            $this->entityManager->wrapInTransaction(function (): never {
                $this->service(UserRepository::class)->findByIdForUpdate($this->subjectId);

                throw new AccountSuspended();
            });
        } catch (AccountSuspended) {
            // Expected: the refusal is the setup, the closed manager it leaves behind is what is asserted.
        }

        $this->assertFalse($this->entityManager->isOpen(), 'the control never stranded the manager');

        $this->recorder()->recordRedemptionCompensated($this->subjectId);

        $this->assertSame(
            [],
            $this->actionsNamingTheSubject(),
            'A stranded manager committed the row anyway, so the case above proves nothing about the reopen.',
        );
    }

    #[Test]
    public function theInterruptedRowLandsAfterTheEvictionCommitted(): void
    {
        $this->service(TransactionManager::class)->transactional(
            fn (): ?User => $this->service(UserRepository::class)->findByIdForUpdate($this->subjectId),
        );

        $this->recorder()->recordRedemptionInterrupted($this->subjectId);

        $this->assertSame([self::INTERRUPTED_ACTION], $this->actionsNamingTheSubject());
    }

    /**
     * The consuming pass's shape: the user row taken `FOR UPDATE`, then a status wall raised from inside the
     * container's transaction manager, which is where the entity manager is closed.
     */
    private function refuseUnderTheSubjectsLock(): void
    {
        try {
            $this->service(TransactionManager::class)->transactional(function (): never {
                $this->service(UserRepository::class)->findByIdForUpdate($this->subjectId);

                throw new AccountSuspended();
            });
        } catch (AccountSuspended) {
            // The refusal the caller rethrows; what matters here is the state it leaves the manager in.
        }
    }

    private function recorder(): RecordRecoverySecretAuditBestEffort
    {
        return $this->service(RecordRecoverySecretAuditBestEffort::class);
    }

    /**
     * @return list<mixed>
     */
    private function actionsNamingTheSubject(): array
    {
        return $this->connection()->fetchFirstColumn(
            'SELECT action FROM audit_log WHERE resource_type = :type AND resource_id = CAST(:id AS UUID) '
            . 'ORDER BY action',
            ['type' => FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, 'id' => $this->subjectId],
        );
    }

    private function seedCommittedSubject(): void
    {
        $user = User::register(
            $this->subjectId,
            'recovery-secret-audit-' . $this->subjectId . '@erpify.test',
            HashedPassword::fromHash('hashed-password-placeholder'),
            Role::AUDIT_READER,
        );
        $user->pullDomainEvents();
        $this->service(UserRepository::class)->save($user);
        $this->entityManager->clear();
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
