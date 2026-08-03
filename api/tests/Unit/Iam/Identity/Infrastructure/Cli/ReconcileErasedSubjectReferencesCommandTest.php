<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Cli;

use Erpify\Iam\Identity\Application\ReconcileErasedSubjectReferences;
use Erpify\Iam\Identity\Infrastructure\Cli\ReconcileErasedSubjectReferencesCommand;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryLiveIdentityDirectory;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedPersonResourceReferences;
use Erpify\Tests\Unit\Shared\Privacy\Infrastructure\Double\FixedPersonReferenceSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The exit code is the contract: a monitoring check calls this command and reads nothing else, so a
 * divergence that prints loudly while exiting `0` is the same as no control at all.
 *
 * The report itself must also stay actionable — an operator who sees the failure needs the offending ids,
 * the place each one is still held, and the repair. WHICH place is what decides whether they are looking at
 * an unanonymised audit trail or at a membership that outlived the person it admitted.
 *
 * @internal
 */
#[CoversClass(ReconcileErasedSubjectReferencesCommand::class)]
final class ReconcileErasedSubjectReferencesCommandTest extends TestCase
{
    private const string MEMBERSHIP_AXIS = Membership::class . '::$userId';

    private const string DANGLING_ID = '0190f500-0000-7000-8000-0000000000d1';

    private const string OTHER_DANGLING_ID = '0190f500-0000-7000-8000-0000000000d2';

    #[Test]
    public function itSucceedsAndSaysHowMuchItCheckedWhenNothingIsUnreconciled(): void
    {
        $tester = $this->tester([], []);

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        // The axes BY NAME, not just the all-clear and not just a count: a control silently reduced to one
        // place would otherwise print exactly what a full clean sweep prints, and the exit code — all a
        // monitoring check reads — would stay zero.
        $this->assertStringContainsString('audit_log.resource_id', $display);
        $this->assertStringContainsString(self::MEMBERSHIP_AXIS, $display);
    }

    #[Test]
    public function itFailsAndNamesEachPlaceItsIdsAndTheRepair(): void
    {
        $tester = $this->tester([self::DANGLING_ID], [self::OTHER_DANGLING_ID]);

        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('2 person reference(s)', $display);
        $this->assertStringContainsString('audit_log.resource_id', $display);
        // The FULL key, because two modules can hold a `Membership` and a short heading would merge them.
        $this->assertStringContainsString(self::MEMBERSHIP_AXIS, $display);
        $this->assertStringContainsString(self::DANGLING_ID, $display);
        $this->assertStringContainsString(self::OTHER_DANGLING_ID, $display);
        // `--force` included: without it the repair prompts, and a non-interactive run declines silently.
        $this->assertStringContainsString('identity:gdpr:erase-subject <id> --force', $display);
    }

    /**
     * Driven through the real reconciler over in-memory doubles: an empty
     * {@see InMemoryLiveIdentityDirectory} resolves every referenced id to a gone identity, so what the
     * doubles hold is exactly the set of divergences.
     *
     * @param list<string> $namedInTheTrail
     * @param list<string> $heldByMembership
     */
    private function tester(array $namedInTheTrail, array $heldByMembership): CommandTester
    {
        $reconciler = new ReconcileErasedSubjectReferences(
            new FixedPersonResourceReferences($namedInTheTrail),
            [new FixedPersonReferenceSource(self::MEMBERSHIP_AXIS, $heldByMembership)],
            new InMemoryLiveIdentityDirectory(),
        );

        return new CommandTester(new ReconcileErasedSubjectReferencesCommand($reconciler));
    }
}
