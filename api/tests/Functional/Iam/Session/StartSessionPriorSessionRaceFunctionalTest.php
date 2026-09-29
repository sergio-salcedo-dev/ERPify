<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Session;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Session\Application\StartSession;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Iam\Session\Infrastructure\Persistence\Doctrine\DoctrineSessionRepository;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Event\Domain\EventBus;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Unit\Iam\Session\Application\RecordingCurrentSessionReference;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The race a re-login's revocation of the row its cookie correlated is exposed to, against REAL Postgres and a
 * second connection standing in for the rival transaction.
 *
 * The rival cannot be interleaved INSIDE the lock wait from one PHP process, so the claim is proved in its two
 * halves, each observed rather than inferred: while the re-login holds the row it read, a rival cannot write it
 * (a `NOWAIT` write from the second connection is refused — and the unlocked read beside it is the control that
 * shows the refusal comes from the lock); and once a rival HAS committed, the re-login finds the row inadmissible
 * and publishes nothing for it, even with an `ACTIVE` instance of that row already in the identity map, which is
 * what the admission gate leaves behind on the request that logs in. Postgres re-checking the predicate against
 * the row a waiter was blocked on is what joins the two halves; that is the database's guarantee, not this test's.
 *
 * Rows are committed — a second connection sees nothing else — so teardown deletes them, and the event-store rows
 * the re-login appended with them, rather than leaving a person's id behind in either table.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(StartSession::class)]
#[CoversClass(DoctrineSessionRepository::class)]
final class StartSessionPriorSessionRaceFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    private const string REVOKED_EVENT = 'erpify.iam.session.revoked';

    private EntityManagerInterface $entityManager;

    private Connection $connection;

    private ?Connection $outside = null;

    private SessionRepository $sessions;

    private string $userId = '';

    private string $priorId = '';

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();

        $sessions = self::getContainer()->get(SessionRepository::class);
        $this->assertInstanceOf(DoctrineSessionRepository::class, $sessions);
        $this->sessions = $sessions;

        $this->userId = Uuid::generate();
        $this->priorId = $this->persistActiveSession();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        $this->outside?->close();
        $this->outside = null;
        $this->connection->executeStatement(
            'DELETE FROM event_store WHERE aggregate_id = CAST(:u AS uuid) OR aggregate_id IN'
            . ' (SELECT id FROM iam_session WHERE user_id = CAST(:u AS uuid))',
            ['u' => $this->userId],
        );
        $this->connection->executeStatement(
            'DELETE FROM iam_session WHERE user_id = CAST(:u AS uuid)',
            ['u' => $this->userId],
        );
        parent::tearDown();
    }

    public function testTheLockedReadHoldsTheRowAgainstARivalWriteUntilItsTransactionEnds(): void
    {
        $id = SessionId::fromString($this->priorId);

        $this->connection->beginTransaction();
        $this->assertInstanceOf(Session::class, $this->sessions->findActiveById($id));
        $this->assertFalse($this->rivalWriteIsRefused(), 'control: an unlocked read leaves the row writable');
        $this->connection->rollBack();

        $this->connection->beginTransaction();
        $this->assertInstanceOf(Session::class, $this->sessions->lockActiveById($id));
        $this->assertTrue($this->rivalWriteIsRefused(), 'the locked read holds the row until its transaction ends');
        $this->connection->rollBack();

        $this->assertFalse($this->rivalWriteIsRefused(), 'and releases it when that transaction ends');
    }

    public function testARowARivalRevokedAndCommittedIsNeitherRevokedAgainNorAnnounced(): void
    {
        $this->holdTheRowInTheIdentityMap();
        $rivalRevokedAt = '2001-02-03 04:05:06';
        $this->outsideConnection()->executeStatement(
            "UPDATE iam_session SET status = 'REVOKED', revoked_at = :at WHERE id = CAST(:id AS uuid)",
            ['at' => $rivalRevokedAt, 'id' => $this->priorId],
        );

        $minted = $this->relogin();

        $this->assertSame(0, $this->revokedEventCount(), 'no SessionRevoked for a revocation that already happened');
        $this->assertSame(
            $rivalRevokedAt,
            $this->connection->fetchOne(
                'SELECT revoked_at FROM iam_session WHERE id = CAST(:id AS uuid)',
                ['id' => $this->priorId],
            ),
            "the rival's revocation stands rather than being re-stamped",
        );
        $this->assertSame('ACTIVE', $this->statusOf($minted), 'the login itself still mints its session');
    }

    public function testARowARivalDeletedAndCommittedIsNotResurrectedIntoAnEventNamingItsOwner(): void
    {
        $this->holdTheRowInTheIdentityMap();
        $this->outsideConnection()->executeStatement(
            'DELETE FROM iam_session WHERE id = CAST(:id AS uuid)',
            ['id' => $this->priorId],
        );

        $minted = $this->relogin();

        $this->assertSame(0, $this->revokedEventCount(), 'no event names the erased row');
        $this->assertFalse(
            $this->connection->fetchOne(
                'SELECT 1 FROM iam_session WHERE id = CAST(:id AS uuid)',
                ['id' => $this->priorId],
            ),
            'and the row stays gone',
        );
        $this->assertSame('ACTIVE', $this->statusOf($minted));
    }

    /**
     * The positive control for both rival cases: with no rival, the same re-login does revoke and announce the
     * row, so the zeros above are the lock's doing and not a path that never revokes anything.
     */
    public function testWithNoRivalTheReloginRevokesAndAnnouncesTheRowExactlyOnce(): void
    {
        $this->holdTheRowInTheIdentityMap();

        $this->relogin();

        $this->assertSame('REVOKED', $this->statusOf($this->priorId));
        $this->assertSame(1, $this->revokedEventCount());
    }

    private function relogin(): string
    {
        $startSession = new StartSession(
            $this->sessions,
            new RecordingCurrentSessionReference(SessionId::fromString($this->priorId)),
            $this->service(EventBus::class),
            $this->service(TransactionManager::class),
            $this->service(Clock::class),
        );

        return $startSession->start($this->userId, Uuid::generate(), 'test-device', null)->toString();
    }

    /**
     * What the admission gate leaves behind on the request that logs in: the row read, unlocked, and managed.
     */
    private function holdTheRowInTheIdentityMap(): void
    {
        $this->assertInstanceOf(
            Session::class,
            $this->sessions->findActiveById(SessionId::fromString($this->priorId)),
        );
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

        $this->entityManager->persist($session);
        $this->entityManager->flush();
        $this->entityManager->clear();

        return $id;
    }

    private function rivalWriteIsRefused(): bool
    {
        $rival = $this->outsideConnection();
        $rival->beginTransaction();

        try {
            $rival->fetchOne(
                'SELECT id FROM iam_session WHERE id = CAST(:id AS uuid) FOR UPDATE NOWAIT',
                ['id' => $this->priorId],
            );

            return false;
        } catch (DriverException $driverException) {
            $this->assertSame('55P03', $driverException->getSQLState(), 'the rival failed for another reason');

            return true;
        } finally {
            if ($rival->isTransactionActive()) {
                $rival->rollBack();
            }
        }
    }

    private function revokedEventCount(): int
    {
        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM event_store WHERE aggregate_id = CAST(:id AS uuid) AND event_name = :name',
            ['id' => $this->priorId, 'name' => self::REVOKED_EVENT],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }

    private function statusOf(string $sessionId): string
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM iam_session WHERE id = CAST(:id AS uuid)',
            ['id' => $sessionId],
        );
        $this->assertIsString($status);

        return $status;
    }

    private function outsideConnection(): Connection
    {
        if (!$this->outside instanceof Connection) {
            $this->outside = DriverManager::getConnection($this->connection->getParams());
        }

        return $this->outside;
    }
}
