<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\RecordRecoveryThrottleAuditBestEffort;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Identity\Infrastructure\Security\RecoveryBudgetKey;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The recovery-throttle projection resolves its address to a subject under `FOR UPDATE`, inside the transaction
 * that writes the row, over the real adapters: the container's recorder, its budget, the repository's locking
 * lookup, the transaction manager and the audit writer. In production it is reached from
 * `RecoveryThrottleAuditListener::onTerminate()`, which hands it the address the controller left on the
 * request; that listener adds no locking of its own, so the recorder is driven directly.
 *
 * This writer is the one late writer that holds an ADDRESS rather than an id, so it cannot go through the
 * id-keyed row lock its siblings share — the hold it has to respect is taken by the aggregate lookup instead,
 * and only Postgres can say whether that lookup queues behind an erasure's lock or reads past it.
 *
 * **The erasure's hold is played by a second connection**, for the reason
 * {@see LateAuditWriterIdentityLockFunctionalTest} gives: one process cannot both block on a lock and release
 * it. A `lock_timeout` turns the wait into an outcome, which the recorder swallows like any lost projection.
 *
 * **The budget is spent by the first attempt whatever happens to its write**, so the case that retries after
 * releasing the hold resets this address's bucket first; without that the retry would be suppressed by design
 * and say nothing about the lock.
 *
 * Rows are found by action and by what existed before the case ran, because a resource-less row names nobody
 * it could be found by. The subject and every row the case wrote are removed by hand in `tearDown()`.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") — the property spans the identity aggregate it seeds, the
 * second connection that holds its row, and the budget the retry has to reset by its own key; each is a real
 * participant rather than an avoidable dependency.
 */
#[CoversClass(RecordRecoveryThrottleAuditBestEffort::class)]
final class RecoveryThrottleAuditIdentityLockFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    private const string THROTTLED_ACTION = 'PASSWORD_RECOVERY_THROTTLED';

    /**
     * Short enough to keep the suite fast, long enough that the elapsed-time floor below cannot be met by a
     * writer that never waited.
     */
    private const int LOCK_TIMEOUT_MS = 400;

    private EntityManagerInterface $entityManager;

    private ?Connection $outside = null;

    private string $subjectId;

    private string $email;

    /**
     * @var list<string>
     */
    private array $throttleRowsBefore = [];

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = $this->service(EntityManagerInterface::class);
        $this->subjectId = Uuid::generate();
        $this->email = 'recovery-throttle-audit-' . $this->subjectId . '@erpify.test';
        $this->throttleRowsBefore = $this->throttleRowIds();
    }

    protected function tearDown(): void
    {
        if (!isset($this->entityManager)) {
            parent::tearDown();

            return;
        }

        $outside = $this->outsideConnection();

        if ($outside->isTransactionActive()) {
            $outside->rollBack();
        }

        $this->entityManager->getConnection()->executeStatement('RESET lock_timeout');

        foreach (\array_diff($this->throttleRowIds(), $this->throttleRowsBefore) as $rowId) {
            $outside->executeStatement('DELETE FROM audit_log WHERE id = CAST(:id AS UUID)', ['id' => $rowId]);
        }

        $outside->executeStatement(
            'DELETE FROM identity_user WHERE id = CAST(:id AS UUID)',
            ['id' => $this->subjectId],
        );
        $outside->close();
        parent::tearDown();
    }

    #[Test]
    public function aResolvedAddressGetsARowNamingItsSubject(): void
    {
        $this->seedCommittedSubject();

        $this->recorder()->record($this->email);

        $this->assertSame(
            [['resource_type' => FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, 'resource_id' => $this->subjectId]],
            $this->resourcesOfNewRows(),
            'the anti-vacuity half: the path does write, and names the subject',
        );
    }

    #[Test]
    public function aWriterWaitsOnAHeldSubjectRowInsteadOfInsertingPastIt(): void
    {
        $this->seedCommittedSubject();
        $this->holdTheSubjectsRowElsewhere();
        $this->entityManager->getConnection()->executeStatement(
            \sprintf("SET lock_timeout = '%dms'", self::LOCK_TIMEOUT_MS),
        );

        $started = \hrtime(true);
        $this->recorder()->record($this->email);
        $waitedMs = (\hrtime(true) - $started) / 1_000_000;

        $this->assertGreaterThanOrEqual(
            self::LOCK_TIMEOUT_MS * 0.9,
            $waitedMs,
            'the writer returned before the lock timeout, so its lookup never queued behind the held row',
        );
        $this->assertSame(
            [],
            $this->resourcesOfNewRows(),
            'A throttle row committed while another transaction held the subject\'s identity row — naming the '
            . 'subject is the row an erasure can no longer see, and a resource-less one would misreport a live '
            . 'identity as an address naming nobody.',
        );

        // Released, the same writer gets through: what stopped it was the hold, not a broken path.
        $this->outsideConnection()->rollBack();
        $this->resetTheAddressesAuditBudget();
        $this->recorder()->record($this->email);

        $this->assertSame(
            [['resource_type' => FulfilIdentityErasure::SUBJECT_RESOURCE_TYPE, 'resource_id' => $this->subjectId]],
            $this->resourcesOfNewRows(),
        );
    }

    #[Test]
    public function anAddressNamingNobodyGetsAResourceLessRowThatDoesNotCarryIt(): void
    {
        // Never seeded: the shape a writer meets for an unknown address, and once an erasure it waited on has
        // committed its DELETE.
        $this->recorder()->record($this->email);

        $this->assertSame([['resource_type' => null, 'resource_id' => null]], $this->resourcesOfNewRows());
        $this->assertSame(
            0,
            $this->newRowsMentioningTheAddress(),
            'the address reached the row, where no erasure path can ever find it',
        );
    }

    private function recorder(): RecordRecoveryThrottleAuditBestEffort
    {
        return $this->service(RecordRecoveryThrottleAuditBestEffort::class);
    }

    private function resetTheAddressesAuditBudget(): void
    {
        $limiter = self::getContainer()->get('limiter.recovery_throttle_audit_per_email');
        $this->assertInstanceOf(RateLimiterFactoryInterface::class, $limiter);
        $limiter->create(RecoveryBudgetKey::forEmail($this->email))->reset();
    }

    private function holdTheSubjectsRowElsewhere(): void
    {
        $outside = $this->outsideConnection();
        $outside->beginTransaction();
        $outside->fetchOne(
            'SELECT 1 FROM identity_user WHERE id = CAST(:id AS UUID) FOR UPDATE',
            ['id' => $this->subjectId],
        );
    }

    /**
     * @return list<string>
     */
    private function throttleRowIds(): array
    {
        return \array_column($this->throttleRows(), 'id');
    }

    /**
     * @return list<array{resource_type: ?string, resource_id: ?string}>
     */
    private function resourcesOfNewRows(): array
    {
        return \array_map(
            static fn (array $row): array => [
                'resource_type' => $row['resource_type'],
                'resource_id' => $row['resource_id'],
            ],
            $this->newRows(),
        );
    }

    private function newRowsMentioningTheAddress(): int
    {
        return \count(\array_filter(
            $this->newRows(),
            fn (array $row): bool => \str_contains(\strtolower($row['as_text']), \strtolower($this->email)),
        ));
    }

    /**
     * @return list<array{id: string, resource_type: ?string, resource_id: ?string, as_text: string}>
     */
    private function newRows(): array
    {
        return \array_values(\array_filter(
            $this->throttleRows(),
            fn (array $row): bool => !\in_array($row['id'], $this->throttleRowsBefore, true),
        ));
    }

    /**
     * Every throttle row, with the whole row rendered as text so a check for the address reaches every column.
     *
     * @return list<array{id: string, resource_type: ?string, resource_id: ?string, as_text: string}>
     */
    private function throttleRows(): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id, resource_type, resource_id, audit_log::text AS as_text FROM audit_log '
            . 'WHERE action = :action ORDER BY id',
            ['action' => self::THROTTLED_ACTION],
        );

        return \array_map(fn (array $row): array => [
            'id' => $this->text($row['id'] ?? null),
            'resource_type' => null === ($row['resource_type'] ?? null) ? null : $this->text($row['resource_type']),
            'resource_id' => null === ($row['resource_id'] ?? null) ? null : $this->text($row['resource_id']),
            'as_text' => $this->text($row['as_text'] ?? null),
        ], $rows);
    }

    private function text(mixed $value): string
    {
        $this->assertIsString($value);

        return $value;
    }

    private function seedCommittedSubject(): void
    {
        $user = User::register(
            $this->subjectId,
            $this->email,
            HashedPassword::fromHash('hashed-password-placeholder'),
            Role::AUDIT_READER,
        );
        $user->pullDomainEvents();
        $this->service(UserRepository::class)->save($user);
        $this->entityManager->clear();
    }

    private function outsideConnection(): Connection
    {
        if (!$this->outside instanceof Connection) {
            $this->outside = DriverManager::getConnection($this->entityManager->getConnection()->getParams());
        }

        return $this->outside;
    }
}
