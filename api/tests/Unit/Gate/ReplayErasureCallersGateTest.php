<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Documents\Document\Application\Erasure\ApplyErasure;
use Erpify\Documents\Document\Application\Erasure\EraseDocument;
use Erpify\Documents\Document\Application\Erasure\ReplayErasure;
use Erpify\Documents\Document\Domain\Erasure\ErasureReason;
use Erpify\Documents\Document\Domain\Erasure\ErasureSystemReason;
use Erpify\Documents\Document\Infrastructure\Cli\ReplayErasureLedgerCommand;
use Erpify\Documents\Document\Infrastructure\Controller\EraseDocumentController;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\CompleteInterruptedErasuresHandler;
use Erpify\Tests\Support\ApiSourceFiles;
use Erpify\Tests\Support\ConstructorDependents;
use Erpify\Tests\Support\Fixture\ApplyErasureHolder;
use Erpify\Tests\Support\Fixture\ReplayErasureOptionalHolder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionParameter;

/**
 * `ReplayErasure` erases with no operator, no voter and no ledger write, so the set of classes that can be handed it is
 * the set of ways to erase a document unrecorded. It is held to exactly two: the restore command, which runs under the
 * restore procedure, and the completion sweep's handler, which finishes an entry the ledger already holds. The
 * controller's use case ({@see EraseDocument}) is not among them and takes no parameter that would make it one, and the
 * HTTP reason ({@see ErasureReason}) shares no value with the system's ({@see ErasureSystemReason}).
 *
 * `ApplyErasure` is held the same way, to exactly `EraseDocument` and `ReplayErasure`: its `apply()` is public and
 * erases with no ledger write and no voter. The set is read by reflection over every class under `api/src` (a
 * constructor type in any spelling — nullable, union, intersection), and the engine is shown to find a planted holder,
 * so a green is not a sweep that saw nothing.
 *
 * **What a green does not prove.** Constructor injection only: a class that fetches the service from a locator, takes
 * it through a setter or a method argument, or is built by a factory closure holds it unseen, and so does a subclass
 * of an allowed class. That the two allowed classes are reachable only from the restore procedure and the schedule is
 * the compose files' and the router's claim, which this does not read.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the two services, their callers and the planted holders
 */
#[CoversNothing]
final class ReplayErasureCallersGateTest extends TestCase
{
    #[Test]
    public function theSweepSeesEveryFileUnderSrcAsADeclaration(): void
    {
        // Against the real count, not a floor a large shrink would still clear: every `.php` under `api/src` declares
        // one class, interface, trait or enum at its PSR-4 path, and the engine must have resolved every one of them.
        $files = \iterator_count(ApiSourceFiles::phpFiles());

        $this->assertGreaterThan(0, $files);
        $this->assertSame(
            $files,
            ConstructorDependents::universeSize(),
            'a file the sweep could not resolve is a blind spot',
        );
    }

    #[Test]
    public function onlyTheRestoreCommandAndTheCompletionHandlerHoldTheReplayService(): void
    {
        $this->assertSame(
            [ReplayErasureLedgerCommand::class, CompleteInterruptedErasuresHandler::class],
            ConstructorDependents::of(ReplayErasure::class),
        );
    }

    #[Test]
    public function onlyTheOperatorsUseCaseAndTheReplayServiceHoldTheErasureTransaction(): void
    {
        // `ApplyErasure::apply()` is public and erases with no ledger write and no voter, so whoever holds it can erase
        // unrecorded exactly as whoever holds the replay service can.
        $this->assertSame(
            [EraseDocument::class, ReplayErasure::class],
            ConstructorDependents::of(ApplyErasure::class),
        );
    }

    #[Test]
    public function theEngineFindsAHolderTheTreeDoesNotHaveInAnySpellingOfTheType(): void
    {
        $this->assertContains(
            ReplayErasureOptionalHolder::class,
            ConstructorDependents::of(
                ReplayErasure::class,
                \dirname(__DIR__, 2) . '/Support/Fixture',
                'Erpify\Tests\Support\Fixture',
            ),
            'a nullable parameter must not hide a holder',
        );
    }

    #[Test]
    public function theEngineFindsAHolderOfTheErasureTransactionToo(): void
    {
        $this->assertContains(
            ApplyErasureHolder::class,
            ConstructorDependents::of(
                ApplyErasure::class,
                \dirname(__DIR__, 2) . '/Support/Fixture',
                'Erpify\Tests\Support\Fixture',
            ),
        );
    }

    #[Test]
    public function theOperatorsUseCaseHasNoWayToSkipTheLedger(): void
    {
        $controllerChain = [EraseDocument::class, EraseDocumentController::class];

        foreach ($controllerChain as $class) {
            foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
                $this->assertNotSame(ReplayErasure::class, (string) $parameter->getType());
                $this->assertStringNotContainsString('Replay', (string) $parameter->getType());
            }
        }

        $erase = (new ReflectionClass(EraseDocument::class))->getMethod('erase');
        $types = \array_map(
            static fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $erase->getParameters(),
        );

        $this->assertSame(['string', ErasureReason::class, 'string'], $types, 'no flag, no system reason, no bypass');
    }
}
