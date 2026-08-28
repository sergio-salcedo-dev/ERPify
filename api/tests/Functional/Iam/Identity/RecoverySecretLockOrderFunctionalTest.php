<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\RecordRecoverySecretAuditBestEffort;
use Erpify\Iam\Identity\Application\RedeemRecoverySecret;
use Erpify\Iam\Identity\Application\RevokeSessionsBestEffort;
use Erpify\Iam\Identity\Domain\Entity\RecoverySecret;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\RecoverySecretRepository;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The redemption's lock order, measured against REAL Postgres and the real adapters.
 *
 * {@see \Erpify\Tests\Unit\Iam\Identity\Application\RedeemRecoverySecretTest} pins the order the USE CASE
 * reaches for the two tables, over in-memory doubles that record a call rather than a lock. This closes the
 * other half: that the adapters those calls resolve to really take row locks, and really take them in that
 * order. Neither proves the other — a finder that stopped locking leaves the unit test green, and a
 * reordering leaves a per-adapter lock test green.
 *
 * **Two observation points, three questions**, asked from a second connection with `NOWAIT` (which turns
 * "somebody else holds it" into an immediate `55P03` instead of a hang, and whose own transaction is rolled
 * back either way so the probe never becomes a contender itself). Each question has its own witness, and
 * each was measured by mutating the code and watching exactly that one flip:
 *
 *   - ARRIVING at the `identity_recovery_secret` lock: `identity_user` must ALREADY be held. That is I-B,
 *     the order minting takes as well, and the order recording a failed login cannot contradict because it
 *     takes the user row alone. Redemption could not reverse it even if it wanted to — it learns which
 *     identity to lock only from the row its selector resolves. Swapping the two acquisitions flips this.
 *   - At the same instant: the secret row must NOT be held yet. This one is the probe's own control. Without
 *     it a probe that answered "locked" for every row would satisfy the question above for the wrong reason,
 *     and the whole test would pass over an adapter that locks nothing at all. It turned out to catch a
 *     second thing nobody designed it for, found while falsifying: a DUPLICATED acquisition — a second
 *     `findBySelectorForUpdate` added ahead of the real one — reaches this point with the row already held
 *     and flips it, while both other questions stay green.
 *   - LEAVING that lock: the secret row must now be held. This is the question the `before` point is
 *     structurally blind to — it runs first, so it can never observe whether the inner call locked anything.
 *     Reverting `findBySelectorForUpdate` to the unlocked finder flips this one and only this one.
 *
 * The audit logger is doubled: it sits past the observation point and would otherwise leave `security` rows
 * in a table other functional tests read. Everything touching the two tables under test is the container's
 * own service.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(RedeemRecoverySecret::class)]
final class RecoverySecretLockOrderFunctionalTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private ?Connection $outside = null;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
    }

    protected function tearDown(): void
    {
        $this->outside?->close();
        $this->outside = null;
        parent::tearDown();
    }

    #[Test]
    public function theIdentityRowIsAlreadyHeldWhenTheSecretRowIsTaken(): void
    {
        [$userId, $plaintext, $selector] = $this->seedLockedIdentityHoldingASecret();

        $identityLockedOnArrival = null;
        $secretLockedOnArrival = null;
        $secretLockedOnLeaving = null;

        $redeem = $this->redeemWithProbe(
            function () use ($userId, $selector, &$identityLockedOnArrival, &$secretLockedOnArrival): void {
                $identityLockedOnArrival = $this->isLocked('identity_user', $userId);
                $secretLockedOnArrival = $this->isLocked('identity_recovery_secret', $selector);
            },
            function () use ($selector, &$secretLockedOnLeaving): void {
                $secretLockedOnLeaving = $this->isLocked('identity_recovery_secret', $selector);
            },
        );

        $signedIn = [];
        // The seam records rather than ignores: this test is about locks, but a redemption that never
        // reached its login would take those locks and prove nothing about the flow they belong to.
        $redeem->redeem($plaintext, static function (string $email) use (&$signedIn): void {
            $signedIn[] = $email;
        });

        $this->assertCount(1, $signedIn, 'the flow never reached the session seam');

        $this->assertTrue(
            $identityLockedOnArrival,
            'the secret row was taken before the identity row — that is the ABBA cycle I-B exists to forbid',
        );
        $this->assertFalse(
            $secretLockedOnArrival,
            'the secret row was already held at its own acquisition, so the probe cannot tell a lock from none',
        );
        $this->assertTrue(
            $secretLockedOnLeaving,
            'the resolving read took no row lock, so the verify and the consume are not serialised at all',
        );

        // The redemption really happened; a probe over a flow that did nothing proves nothing about it.
        $this->assertSame(
            0,
            $this->countSecretsFor($userId),
            'the secret survived a redemption that reported success',
        );
    }

    /**
     * @return array{string, string, string} user id, the `<selector>.<secret>` plaintext, and the selector
     */
    private function seedLockedIdentityHoldingASecret(): array
    {
        $userId = Uuid::generate();
        $user = User::register($userId, \sprintf('locked-%s@erpify.test', $userId), HashedPassword::fromHash(
            '$2y$04$abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ012',
        ), Role::AUDIT_READER);
        $user->pullDomainEvents();

        $generated = RecoverySecret::mint($userId, new DateTimeImmutable());
        $generated->secret->pullDomainEvents();

        $this->entityManager->persist($user);
        $this->entityManager->persist($generated->secret);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $selector = $generated->secret->getId();
        $this->assertNotNull($selector);

        return [$userId, $generated->plaintext(), $selector];
    }

    /**
     * @param callable(): void $onArrival
     * @param callable(): void $onLeaving
     */
    private function redeemWithProbe(callable $onArrival, callable $onLeaving): RedeemRecoverySecret
    {
        $users = self::getContainer()->get(UserRepository::class);
        $secrets = self::getContainer()->get(RecoverySecretRepository::class);
        $transactions = self::getContainer()->get(TransactionManager::class);
        $revokeSessions = self::getContainer()->get(RevokeSessionsBestEffort::class);
        $eventBus = self::getContainer()->get(EventBus::class);
        $clock = self::getContainer()->get(Clock::class);

        $this->assertInstanceOf(UserRepository::class, $users);
        $this->assertInstanceOf(RecoverySecretRepository::class, $secrets);
        $this->assertInstanceOf(TransactionManager::class, $transactions);
        $this->assertInstanceOf(RevokeSessionsBestEffort::class, $revokeSessions);
        $this->assertInstanceOf(EventBus::class, $eventBus);
        $this->assertInstanceOf(Clock::class, $clock);

        return new RedeemRecoverySecret(
            $users,
            new ProbingRecoverySecretRepository($secrets, $onArrival(...), $onLeaving(...)),
            new RecordRecoverySecretAuditBestEffort(new RecordingAuditLogger(), new NullLogger()),
            $revokeSessions,
            $eventBus,
            $transactions,
            $clock,
        );
    }

    private function countSecretsFor(string $userId): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM identity_recovery_secret WHERE user_id = CAST(:id AS UUID)',
            ['id' => $userId],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }

    private function isLocked(string $table, string $id): bool
    {
        $probe = $this->outsideConnection();
        $probe->beginTransaction();

        try {
            $probe->fetchOne(
                \sprintf('SELECT id FROM %s WHERE id = CAST(:id AS UUID) FOR UPDATE NOWAIT', $table),
                ['id' => $id],
            );

            return false;
        } catch (DriverException $driverException) {
            $this->assertSame('55P03', $driverException->getSQLState(), 'the probe failed for another reason');

            return true;
        } finally {
            if ($probe->isTransactionActive()) {
                $probe->rollBack();
            }
        }
    }

    private function outsideConnection(): Connection
    {
        if (!$this->outside instanceof Connection) {
            $this->outside = DriverManager::getConnection($this->connection->getParams());
        }

        return $this->outside;
    }
}
