<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Erpify\Shared\Audit\Infrastructure\Http\RequestBoundarySecurityAudit;
use Erpify\Shared\Uuid\Domain\Uuid;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves against real Postgres that a request-boundary `security` row is durable on its own: it is committed
 * the moment the seam returns — another session sees it before anything else runs, which only a committed
 * row allows — and inside an open transaction the seam refuses rather than writing a row a rollback would
 * erase. Which listener calls the seam, and in what order against the responder, is not proven here: that is
 * the listeners' unit tests and the `security_denial` acceptance feature.
 *
 * Visibility is judged from a SECOND connection. The container's own connection would see its uncommitted
 * writes and report a row that no other session — and no crash recovery — ever will. The durable case
 * therefore leaves a COMMITTED row, which `tearDown()` removes by its marker however the test ends, so the
 * shared test database is left as it was found.
 *
 * Each row is found by a fresh marker in its `route` metadata, so a concurrent row with the same action can
 * never be mistaken for this test's.
 *
 * @internal
 */
#[CoversClass(RequestBoundarySecurityAudit::class)]
final class RequestBoundarySecurityAuditFunctionalTest extends KernelTestCase
{
    private const string ACTION = 'ACCESS_DENIED';

    private ?Connection $outside = null;

    private ?string $marker = null;

    protected function tearDown(): void
    {
        if ($this->outside instanceof Connection) {
            if (null !== $this->marker) {
                $this->outside->executeStatement(
                    "DELETE FROM audit_log WHERE action = :action AND metadata->>'route' = :marker",
                    ['action' => self::ACTION, 'marker' => $this->marker],
                );
            }

            $this->outside->close();
            $this->outside = null;
        }

        $this->marker = null;

        parent::tearDown();
    }

    public function testRefusesInsideAnOpenTransactionAndLeavesNoRowAfterItsRollback(): void
    {
        self::bootKernel();
        $marker = $this->marker();
        $connection = $this->containerConnection();

        $connection->beginTransaction();

        try {
            $this->seam()->record(self::ACTION, ['route' => $marker]);
            $this->fail('a security write inside an open transaction must be refused');
        } catch (LogicException) {
            // The refusal is the outcome under test; what matters is what survives the rollback below.
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }

        $this->assertSame(0, $this->rowsVisibleOutside($marker), 'a refused write leaves no row behind');
    }

    public function testCommitsTheRowTheMomentItReturnsVisibleToAnotherSession(): void
    {
        self::bootKernel();
        $marker = $this->marker();

        $this->assertFalse($this->containerConnection()->isTransactionActive(), 'the write must start outside one');

        $this->seam()->record(self::ACTION, ['route' => $marker]);

        // Read from a SEPARATE connection before anything else runs: a row still inside an uncommitted
        // transaction is invisible to another session, so a count of one proves the autocommit rather than
        // asserting it. Which listener calls the seam is proven end to end by the security_denial feature.
        $this->assertSame(
            1,
            $this->rowsVisibleOutside($marker),
            'the security row is committed and visible to another session',
        );
    }

    private function seam(): RequestBoundarySecurityAudit
    {
        $seam = self::getContainer()->get(RequestBoundarySecurityAudit::class);
        $this->assertInstanceOf(RequestBoundarySecurityAudit::class, $seam);

        return $seam;
    }

    private function containerConnection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        $this->assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function rowsVisibleOutside(string $marker): int
    {
        $count = $this->outsideConnection()->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE action = :action AND metadata->>'route' = :marker",
            ['action' => self::ACTION, 'marker' => $marker],
        );

        $this->assertIsNumeric($count);

        return (int) $count;
    }

    private function outsideConnection(): Connection
    {
        if (!$this->outside instanceof Connection) {
            $this->outside = DriverManager::getConnection($this->containerConnection()->getParams());
        }

        return $this->outside;
    }

    private function marker(): string
    {
        // The second connection is opened up front, so tearDown() can always reach a row the body committed.
        $this->outsideConnection();
        $this->marker = 'request-boundary-audit-' . Uuid::generate();

        return $this->marker;
    }
}
