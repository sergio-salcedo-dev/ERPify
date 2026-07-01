<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Cli;

use Erpify\Shared\Audit\Application\SubjectErasureReconciler;
use Erpify\Shared\Audit\Infrastructure\Cli\ReconcileSubjectErasuresCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversClass(ReconcileSubjectErasuresCommand::class)]
final class ReconcileSubjectErasuresCommandTest extends TestCase
{
    #[Test]
    public function itSucceedsWhenEveryDestroyedKeyHasEvidence(): void
    {
        $tester = $this->tester([]);

        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Every destroyed encryption key', $tester->getDisplay());
    }

    #[Test]
    public function itFailsAndListsTheScopesOnDivergence(): void
    {
        $scope = 'BankAccount:0197f2b4-0000-7000-8000-000000000000';
        $tester = $this->tester([$scope]);

        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('1 destroyed key(s) without erasure evidence', $display);
        $this->assertStringContainsString($scope, $display);
    }

    /**
     * @param list<string> $unreconciledScopes
     */
    private function tester(array $unreconciledScopes): CommandTester
    {
        $reconciler = $this->createStub(SubjectErasureReconciler::class);
        $reconciler->method('unreconciledScopes')->willReturn($unreconciledScopes);

        return new CommandTester(new ReconcileSubjectErasuresCommand($reconciler));
    }
}
