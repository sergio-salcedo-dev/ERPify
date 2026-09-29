<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Http;

use DomainException;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Infrastructure\Http\RequestBoundarySecurityAudit;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FailingAuditLogger;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sentry\State\HubInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

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

    public function testRecordOnExceptionLeavesTheEventAloneWhenTheWriteSucceeds(): void
    {
        $refusal = new RuntimeException('the refusal the entry records');
        $event = $this->event($refusal);
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->once())->method('log');
        $errorTracker = $this->createMock(HubInterface::class);
        $errorTracker->expects($this->never())->method('captureException');

        $this->seam($logger, transactionActive: false, errorTracker: $errorTracker)
            ->recordOnException($event, self::ACTION, [])
        ;

        $this->assertSame($refusal, $event->getThrowable(), 'a recorded refusal keeps its own status');
        $this->assertFalse($event->hasResponse());
    }

    public function testRecordOnExceptionHandsTheLeakedTransactionRefusalToTheEventAndReportsIt(): void
    {
        $refusal = new RuntimeException('the refusal the entry records');
        $event = $this->event($refusal);
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->never())->method('log');
        $errorTracker = $this->createMock(HubInterface::class);
        $errorTracker->expects($this->once())->method('captureException')
            ->with($this->isInstanceOf(LogicException::class))
        ;

        $this->seam($logger, transactionActive: true, errorTracker: $errorTracker)
            ->recordOnException($event, self::ACTION, [])
        ;

        $this->assertRefusalOf($refusal, $event->getThrowable());
        $this->assertFalse($event->hasResponse(), 'the responder, not the seam, answers the 5xx');
    }

    public function testRecordOnExceptionWrapsAFailedWriteSoItStillNamesWhatWasBeingRecorded(): void
    {
        $refusal = new DomainException('the refusal the entry records');
        $event = $this->event($refusal);
        $failure = new RuntimeException('audit_log is unreachable');
        $reported = null;
        $errorTracker = $this->createMock(HubInterface::class);
        $errorTracker->expects($this->once())->method('captureException')
            ->willReturnCallback(static function (Throwable $throwable) use (&$reported): null {
                $reported = $throwable;

                return null;
            })
        ;

        $this->seam(new FailingAuditLogger($failure), transactionActive: false, errorTracker: $errorTracker)
            ->recordOnException($event, self::ACTION, ['subjectId' => self::PERSON_ID])
        ;

        $handed = $event->getThrowable();
        $this->assertWriteFailureOf($failure, $handed);
        $this->assertSame($handed, $reported, 'the tracker receives what the event now carries');
        $this->assertStringContainsString(self::ACTION, $handed->getMessage());
        $this->assertStringContainsString(DomainException::class, $handed->getMessage());
        $this->assertStringNotContainsString(self::PERSON_ID, $handed->getMessage());
        $this->assertFalse($event->hasResponse());
    }

    public function testRecordOnExceptionStillHandsTheFailureOnWhereNoErrorTrackerIsWired(): void
    {
        $refusal = new RuntimeException('the refusal the entry records');
        $event = $this->event($refusal);
        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->never())->method('log');

        $this->seam($logger, transactionActive: true, errorTracker: null)
            ->recordOnException($event, self::ACTION, [])
        ;

        $this->assertRefusalOf($refusal, $event->getThrowable());
    }

    private function seam(
        AuditLogger $logger,
        bool $transactionActive,
        ?HubInterface $errorTracker,
    ): RequestBoundarySecurityAudit {
        return new RequestBoundarySecurityAudit($logger, $this->connection($transactionActive), $errorTracker);
    }

    private function event(Throwable $throwable): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/api/v1/backoffice/banks'),
            HttpKernelInterface::MAIN_REQUEST,
            $throwable,
        );
    }
}
