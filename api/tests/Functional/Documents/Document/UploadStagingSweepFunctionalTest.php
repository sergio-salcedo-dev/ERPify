<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\Upload\SweepStagedOrphans;
use Erpify\Documents\Document\Domain\Repository\DocumentRepository;
use Erpify\Documents\Document\Domain\Repository\UploadIntentRepository;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Documents\Document\Domain\Upload\UploadIntentState;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid as RandomUuid;

/**
 * The staging age sweep against the real document volume and Postgres, with a scripted storage whose walk and
 * deletions can be made to fail: how it spends its limit on owned and failing scopes, and when it gives up.
 *
 * @internal
 */
#[CoversClass(SweepStagedOrphans::class)]
final class UploadStagingSweepFunctionalTest extends KernelTestCase
{
    use PlantsUploadIntents;
    use ResolvesContainerServices;

    /** @var list<string> */
    private array $scopes = [];

    private TestHandler $log;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->log = new TestHandler();
    }

    protected function tearDown(): void
    {
        foreach ($this->scopes as $scope) {
            $this->storage()->delete(StorageArea::Staging, $scope);
            $this->storage()->delete(StorageArea::Final, $scope);
        }

        parent::tearDown();
    }

    /**
     * The limit counts deletions: owned objects at the head of the walk are passed over without spending it, and a
     * scope that fails is logged and passed over too, so the orphans behind both are still reached.
     */
    public function testTheStagingSweepReachesOrphansBehindOwnedAndFailingScopes(): void
    {
        $ownedA = $this->plantIntent(Uuid::generate(), UploadIntentState::Declared, claimed: false)->scopeId;
        $ownedB = $this->plantIntent(Uuid::generate(), UploadIntentState::Declared, claimed: false)->scopeId;
        [$orphanA, $poisoned, $orphanB] = [$this->scope(), $this->scope(), $this->scope()];
        $storage = (new ScriptedDocumentStorage($this->storage()))
            ->walkYields([$ownedA, $ownedB, $orphanA, $poisoned, $orphanB])
            ->failDeleteFor($poisoned)
        ;

        $deleted = (new SweepStagedOrphans(
            $storage,
            $this->service(DocumentRepository::class),
            $this->service(UploadIntentRepository::class),
            $this->logger(),
        ))->sweep(2);

        $this->assertSame(2, $deleted);
        $this->assertSame([$orphanA, $orphanB], $storage->deleted);
        $this->assertTrue($this->log->hasErrorThatContains('could not be swept'));
    }

    public function testAStagingWalkThatFailsEndsTheSweepWithOneLine(): void
    {
        $deleted = $this->sweepWith((new ScriptedDocumentStorage($this->storage()))->failWalk(), 10);

        $this->assertSame(0, $deleted);
        $this->assertCount(1, $this->log->getRecords());
        $this->assertTrue($this->log->hasErrorThatContains('could not be walked'));
    }

    /**
     * A store or database that fails every object stops the run after a few, rather than logging once per object
     * of the whole staging area every tick.
     */
    public function testARunOfFailuresStopsTheSweep(): void
    {
        $scopes = \array_map($this->scope(...), \range(1, 12));
        $storage = (new ScriptedDocumentStorage($this->storage()))->walkYields($scopes)->failEveryDelete();

        $this->assertSame(0, $this->sweepWith($storage, 10));
        $this->assertSame(5, $this->logged('could not be swept'));
        $this->assertSame(1, $this->logged('stopped after consecutive failures'));
    }

    /**
     * Scopes the sweep passes over because something owns them are neither a deletion nor a failure, so they do not
     * hide a store that fails every deletion: the run still stops after five failures in a row of its own.
     */
    public function testOwnedScopesBetweenFailuresDoNotKeepTheStagingSweepGoing(): void
    {
        $scopes = [];

        for ($pair = 0; $pair < 6; ++$pair) {
            $scopes[] = $this->scope();
            $scopes[] = $this->plantIntent(Uuid::generate(), UploadIntentState::Declared, claimed: false)->scopeId;
        }

        $storage = (new ScriptedDocumentStorage($this->storage()))->walkYields($scopes)->failEveryDelete();

        $this->assertSame(0, $this->sweepWith($storage, 100));
        $this->assertSame(5, $this->logged('could not be swept'));
        $this->assertSame(1, $this->logged('stopped after consecutive failures'));
    }

    private function sweepWith(ScriptedDocumentStorage $storage, int $limit): int
    {
        return (new SweepStagedOrphans(
            $storage,
            $this->service(DocumentRepository::class),
            $this->service(UploadIntentRepository::class),
            new Logger('observability', [$this->log]),
        ))->sweep($limit);
    }

    private function logged(string $fragment): int
    {
        return \count(\array_filter(
            $this->log->getRecords(),
            static fn (LogRecord $record): bool => \str_contains($record->message, $fragment),
        ));
    }

    private function scope(): string
    {
        $scope = RandomUuid::v4()->toRfc4122();
        $this->scopes[] = $scope;

        return $scope;
    }

    private function logger(): Logger
    {
        return new Logger('observability', [$this->log]);
    }

    private function storage(): DocumentStorage
    {
        return $this->service(DocumentStorage::class);
    }
}
