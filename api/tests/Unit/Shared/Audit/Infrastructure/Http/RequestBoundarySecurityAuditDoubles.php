<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Http;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Infrastructure\Http\RequestBoundarySecurityAudit;
use LogicException;

/**
 * The three states of the audit connection a request-boundary listener can meet, wrapped around the logger the
 * test observes: free (the write goes through), carrying a leaked transaction (the write is refused), and never
 * to be consulted at all (a request the listener does not audit).
 *
 * They live in a trait because the connection is a Doctrine type each listener test would otherwise depend on
 * directly, and the suite caps how many collaborators one test class may name.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait RequestBoundarySecurityAuditDoubles
{
    private function boundaryAudit(AuditLogger $logger): RequestBoundarySecurityAudit
    {
        return new RequestBoundarySecurityAudit($logger, $this->connection(transactionActive: false));
    }

    private function leakedTransactionBoundaryAudit(AuditLogger $logger): RequestBoundarySecurityAudit
    {
        return new RequestBoundarySecurityAudit($logger, $this->connection(transactionActive: true));
    }

    /**
     * A request the listener does not audit must not reach the audit connection at all.
     */
    private function untouchedBoundaryAudit(AuditLogger $logger): RequestBoundarySecurityAudit
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method($this->anything());

        return new RequestBoundarySecurityAudit($logger, $connection);
    }

    private function expectRefusal(): void
    {
        $this->expectException(LogicException::class);
    }

    private function connection(bool $transactionActive): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn($transactionActive);

        return $connection;
    }
}
