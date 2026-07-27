<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Cli;

use Erpify\Iam\Identity\Application\ReconcileErasedSubjectReferences;
use Erpify\Iam\Identity\Infrastructure\Cli\ReconcileErasedSubjectReferencesCommand;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedPersonResourceReferences;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The exit code is the contract: a monitoring check calls this command and reads nothing else, so a
 * divergence that prints loudly while exiting `0` is the same as no control at all.
 *
 * The report itself must also stay actionable — an operator who sees the failure needs the offending ids and
 * the repair, not just a count.
 *
 * @internal
 */
#[CoversClass(ReconcileErasedSubjectReferencesCommand::class)]
final class ReconcileErasedSubjectReferencesCommandTest extends TestCase
{
    private const string DANGLING_ID = '0190f500-0000-7000-8000-0000000000d1';

    #[Test]
    public function itSucceedsWhenTheTrailNamesNoErasedIdentity(): void
    {
        $tester = $this->tester([]);

        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('No erased identity', $tester->getDisplay());
    }

    #[Test]
    public function itFailsAndNamesBothTheDivergenceAndItsRepair(): void
    {
        $tester = $this->tester([self::DANGLING_ID]);

        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('1 erased identity(ies)', $display);
        $this->assertStringContainsString(self::DANGLING_ID, $display);
        $this->assertStringContainsString('identity:gdpr:erase-subject', $display);
    }

    /**
     * Driven through the real reconciler over in-memory doubles: an empty {@see InMemoryUserRepository}
     * resolves every named id to a gone identity, so the trail's ids are exactly the divergences.
     *
     * @param list<string> $namedInTheTrail
     */
    private function tester(array $namedInTheTrail): CommandTester
    {
        $reconciler = new ReconcileErasedSubjectReferences(
            new FixedPersonResourceReferences($namedInTheTrail),
            new InMemoryUserRepository(),
        );

        return new CommandTester(new ReconcileErasedSubjectReferencesCommand($reconciler));
    }
}
