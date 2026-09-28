<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Http;

use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Infrastructure\Http\RequestBoundarySecurityAudit;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FailingAuditLogger;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(RequestBoundarySecurityAudit::class)]
final class RequestBoundarySecurityAuditTest extends TestCase
{
    use RequestBoundarySecurityAuditDoubles;

    private const string ACTION = 'ACCESS_DENIED';

    private const string PERSON_ID = '0190f400-0000-7000-8000-0000000000d1';

    public function testRecordsASecurityEntryWhenNoTransactionIsOpen(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->once())->method('log')
            ->with(self::ACTION, AuditLevel::SECURITY, null, ['route' => 'backoffice_bank_update'])
        ;

        $this->boundaryAudit($logger)
            ->record(self::ACTION, ['route' => 'backoffice_bank_update'])
        ;
    }

    public function testRefusesBeforeWritingWhenATransactionIsOpen(): void
    {
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->never())->method('log');

        $audit = $this->leakedTransactionBoundaryAudit($logger);

        try {
            $audit->record(self::ACTION, ['route' => 'backoffice_bank_update', 'subjectId' => self::PERSON_ID]);
            $this->fail('a write inside an open transaction must be refused');
        } catch (LogicException $logicException) {
            // The message reaches the error log: it names the action and carries nothing from the metadata.
            $this->assertStringContainsString(self::ACTION, $logicException->getMessage());
            $this->assertStringNotContainsString(self::PERSON_ID, $logicException->getMessage());
            $this->assertStringNotContainsString('backoffice_bank_update', $logicException->getMessage());
        }
    }

    public function testPropagatesAPersistenceFailureUnwrapped(): void
    {
        $failure = new RuntimeException('audit_log is unreachable');

        try {
            $this->boundaryAudit(new FailingAuditLogger($failure))
                ->record(self::ACTION, [])
            ;
            $this->fail('a failed security write must propagate');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame($failure, $runtimeException);
        }
    }
}
