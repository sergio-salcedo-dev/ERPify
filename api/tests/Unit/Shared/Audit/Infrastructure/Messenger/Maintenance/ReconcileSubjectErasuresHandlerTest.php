<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Messenger\Maintenance;

use Erpify\Shared\Audit\Application\SubjectErasureReconciler;
use Erpify\Shared\Audit\Infrastructure\Messenger\Maintenance\ReconcileSubjectErasuresHandler;
use Erpify\Shared\Audit\Infrastructure\Messenger\Maintenance\ReconcileSubjectErasuresMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
#[CoversClass(ReconcileSubjectErasuresHandler::class)]
final class ReconcileSubjectErasuresHandlerTest extends TestCase
{
    private const string SCOPE_ID = 'BankAccount:0197f2b4-0000-7000-8000-000000000000';

    private const string OTHER_SCOPE_ID = 'BankAccount:0197f2b4-0000-7000-8000-000000000001';

    #[Test]
    public function itAlarmsAtErrorLevelSoProductionDoesNotDiscardIt(): void
    {
        // Prod routes Monolog through `fingers_crossed` with `action_level: error`
        // (config/packages/monolog.yaml), which buffers anything lower and discards it when no error follows.
        // Below `error` this alarm is unreachable in the only environment where it would matter.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $logger->expects($this->never())->method('warning');

        $this->handle([self::SCOPE_ID], $logger);
    }

    #[Test]
    public function itReportsHowManyScopesDivergedAndNeverWhichOnes(): void
    {
        // An encryption scope id names the aggregate it belongs to, so the list identifies the subjects whose
        // keys were destroyed. Emitting them here copies that into log storage, which carries a retention of
        // its own and no declared owner of its erasure — the integrity check would leak what it audits. The
        // scope ids stay in `audit:gdpr:reconcile-erasures`, which an operator runs deliberately.
        $loggedMessage = '';
        $loggedContext = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->willReturnCallback(
                static function (string $message, array $context) use (&$loggedMessage, &$loggedContext): void {
                    $loggedMessage = $message;
                    $loggedContext = $context;
                },
            )
        ;

        $this->handle([self::SCOPE_ID, self::OTHER_SCOPE_ID], $logger);

        // The whole line, message and context together: a scope id would be as leaked in one as in the other.
        $serialised = \json_encode([$loggedMessage, $loggedContext], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::SCOPE_ID, $serialised);
        $this->assertStringNotContainsString(self::OTHER_SCOPE_ID, $serialised);
        $this->assertArrayHasKey('count', $loggedContext);
        $this->assertSame(2, $loggedContext['count']);
    }

    #[Test]
    public function itStaysSilentWhenEverythingReconciles(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $this->handle([], $logger);
    }

    /**
     * @param list<string> $unreconciledScopes
     */
    private function handle(array $unreconciledScopes, LoggerInterface $logger): void
    {
        $reconciler = $this->createStub(SubjectErasureReconciler::class);
        $reconciler->method('unreconciledScopes')->willReturn($unreconciledScopes);

        (new ReconcileSubjectErasuresHandler($reconciler, $logger))(new ReconcileSubjectErasuresMessage());
    }
}
