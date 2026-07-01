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
    #[Test]
    public function itWarnsWhenADestroyedKeyLacksErasureEvidence(): void
    {
        $reconciler = $this->createStub(SubjectErasureReconciler::class);
        $reconciler->method('unreconciledScopes')->willReturn(['BankAccount:0197f2b4-0000-7000-8000-000000000000']);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        (new ReconcileSubjectErasuresHandler($reconciler, $logger))(new ReconcileSubjectErasuresMessage());
    }

    #[Test]
    public function itStaysSilentWhenEverythingReconciles(): void
    {
        $reconciler = $this->createStub(SubjectErasureReconciler::class);
        $reconciler->method('unreconciledScopes')->willReturn([]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        (new ReconcileSubjectErasuresHandler($reconciler, $logger))(new ReconcileSubjectErasuresMessage());
    }
}
