<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Session;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Session\Application\RevokeSession;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\Iam\Session\Fixtures\RivalBeforeLockSessionRepository;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A single-session revocation racing a rival over the same row, against REAL Postgres: the call has already read
 * the row `ACTIVE` without a lock, and a rival on a second connection revokes or deletes it and commits before the
 * call's locked read. {@see RivalBeforeLockSessionRepository} places the rival exactly there; the locked read
 * after it is the adapter's own, so the answer is Postgres's. That the locked read also HOLDS the row against a
 * rival that arrives later is {@see StartSessionPriorSessionRaceFunctionalTest}'s first case, over the same
 * adapter method.
 *
 * Rows are committed — the second connection sees nothing else — so teardown deletes them and the event-store
 * rows appended with them rather than leaving a person's id behind.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(RevokeSession::class)]
final class RevokeSessionRaceFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    private const string REVOKED_EVENT = 'erpify.iam.session.revoked';

    private Connection $connection;

    private ?Connection $outside = null;

    private string $userId = '';

    private string $sessionId = '';

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = $this->service(EntityManagerInterface::class)->getConnection();
        $this->userId = Uuid::generate();
        $this->sessionId = $this->persistActiveSession();
    }

    protected function tearDown(): void
    {
        $this->outside?->close();
        $this->outside = null;
        $this->connection->executeStatement(
            'DELETE FROM event_store WHERE aggregate_id = CAST(:u AS uuid) OR aggregate_id IN'
            . ' (SELECT id FROM iam_session WHERE user_id = CAST(:u AS uuid))',
            ['u' => $this->userId],
        );
        $this->connection->executeStatement(
            'DELETE FROM event_store WHERE aggregate_id = CAST(:id AS uuid)',
            ['id' => $this->sessionId],
        );
        $this->connection->executeStatement(
            'DELETE FROM iam_session WHERE user_id = CAST(:u AS uuid)',
            ['u' => $this->userId],
        );
        parent::tearDown();
    }

    public function testARowARivalRevokesBeforeTheLockIsNeitherRevokedAgainNorAnnounced(): void
    {
        $rivalRevokedAt = '2001-02-03 04:05:06';

        $this->revokeWithRival(function () use ($rivalRevokedAt): void {
            $this->outsideConnection()->executeStatement(
                "UPDATE iam_session SET status = 'REVOKED', revoked_at = :at WHERE id = CAST(:id AS uuid)",
                ['at' => $rivalRevokedAt, 'id' => $this->sessionId],
            );
        });

        $this->assertSame(0, $this->revokedEventCount(), 'no SessionRevoked for a revocation that already happened');
        $this->assertSame(
            $rivalRevokedAt,
            $this->connection->fetchOne(
                'SELECT revoked_at FROM iam_session WHERE id = CAST(:id AS uuid)',
                ['id' => $this->sessionId],
            ),
            "the rival's revocation stands rather than being re-stamped",
        );
    }

    public function testARowARivalDeletesBeforeTheLockIsNotResurrectedIntoAnEventNamingItsOwner(): void
    {
        $this->revokeWithRival(function (): void {
            $this->outsideConnection()->executeStatement(
                'DELETE FROM iam_session WHERE id = CAST(:id AS uuid)',
                ['id' => $this->sessionId],
            );
        });

        $this->assertSame(0, $this->revokedEventCount(), 'no event names the erased row');
        $this->assertFalse(
            $this->connection->fetchOne(
                'SELECT 1 FROM iam_session WHERE id = CAST(:id AS uuid)',
                ['id' => $this->sessionId],
            ),
            'and the row stays gone',
        );
    }

    /**
     * The positive control: through the same decorated store, with a rival that does nothing, the row is revoked
     * and announced exactly once — so the zeros above are the lock's doing.
     */
    public function testWithNoRivalTheRowIsRevokedAndAnnouncedExactlyOnce(): void
    {
        $this->revokeWithRival(static function (): void {
        });

        $this->assertSame(
            'REVOKED',
            $this->connection->fetchOne(
                'SELECT status FROM iam_session WHERE id = CAST(:id AS uuid)',
                ['id' => $this->sessionId],
            ),
        );
        $this->assertSame(1, $this->revokedEventCount());
    }

    private function revokeWithRival(Closure $rival): void
    {
        $revokeSession = new RevokeSession(
            new RivalBeforeLockSessionRepository($this->service(SessionRepository::class), $rival),
            $this->service(EventBus::class),
            $this->service(TransactionManager::class),
        );

        $revokeSession->revoke(SessionId::fromString($this->sessionId));
    }

    private function persistActiveSession(): string
    {
        $id = SessionId::generate()->toString();
        $session = Session::start(
            $id,
            $this->userId,
            Uuid::generate(),
            'test-device',
            null,
            $this->service(Clock::class)->now()->modify('+1 day'),
        );
        $session->pullDomainEvents();

        $entityManager = $this->service(EntityManagerInterface::class);
        $entityManager->persist($session);
        $entityManager->flush();
        $entityManager->clear();

        return $id;
    }

    private function revokedEventCount(): int
    {
        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM event_store WHERE aggregate_id = CAST(:id AS uuid) AND event_name = :name',
            ['id' => $this->sessionId, 'name' => self::REVOKED_EVENT],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }

    private function outsideConnection(): Connection
    {
        if (!$this->outside instanceof Connection) {
            $this->outside = DriverManager::getConnection($this->connection->getParams());
        }

        return $this->outside;
    }
}
