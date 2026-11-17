<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Application\Listing\DocumentNameOpener;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReportDocumentNamesHandler;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReportDocumentNamesMessage;
use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Tests\Support\Dig;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The daily name report against real Postgres and the real sealer, with a capturing observability logger: ONE
 * aggregate line per run with the count per cause and at most twenty ids (`error` when a name failed, `warning` when
 * the run did not finish), nothing when the run finished and every name opens, and never a filename, a scope or an
 * actor on any record.
 *
 * @internal
 */
#[CoversClass(ReportDocumentNamesHandler::class)]
final class ReportDocumentNamesFunctionalTest extends KernelTestCase
{
    use PlantsNamedDocuments;

    private TestHandler $log;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->log = new TestHandler();
        $this->emptyDocumentTables();
    }

    protected function tearDown(): void
    {
        $this->emptyDocumentTables();
        parent::tearDown();
    }

    #[Test]
    public function aStoreWhoseNamesAllOpenRaisesNoAlarm(): void
    {
        $this->plantNamedDocument('fine.pdf');
        $this->plantNamedDocument(null, DocumentState::Erased);

        $this->report();

        $this->assertSame([], $this->log->getRecords());
    }

    #[Test]
    public function oneAggregateLineCountsEachCauseAndNamesNeitherAFilenameNorAScope(): void
    {
        $destroyed = $this->plantNamedDocument('destroyed-secret.pdf');
        $tampered = $this->plantNamedDocument('tampered-secret.pdf');
        $good = $this->plantNamedDocument('good-secret.pdf');
        // An erased row whose key is destroyed is the expected state, not an alarm, and costs no lookup.
        $erased = $this->plantNamedDocument('erased-secret.pdf', DocumentState::Erased);
        $this->destroyScopeOf($destroyed['scope']);
        $this->destroyScopeOf($erased['scope']);
        $this->tamperWithTheNameOf($tampered['id']);

        $this->report();

        $errors = $this->errors();
        $this->assertCount(1, $errors, 'ONE line per run');
        $context = $errors[0]->context;
        $this->assertSame(1, Dig::value($context, 'keyMissing'));
        $this->assertSame(1, Dig::value($context, 'decryptionFailed'));
        $this->assertSame(0, Dig::value($context, 'unreadable'));
        $this->assertSame(3, Dig::value($context, 'examined'), 'the erased row is never examined');
        $this->assertTrue(Dig::value($context, 'complete'));
        $ids = Dig::value($context, 'documentIds');
        $this->assertIsArray($ids);
        $this->assertEqualsCanonicalizing([$destroyed['id'], $tampered['id']], $ids);

        // Every record the run wrote on the channel, whatever its level.
        $rendered = \json_encode(
            \array_map(
                static fn (LogRecord $record): array => [$record->message, $record->context],
                $this->log->getRecords(),
            ),
            JSON_THROW_ON_ERROR,
        );

        foreach ([$destroyed, $tampered, $good, $erased] as $document) {
            $this->assertStringNotContainsString($document['scope'], $rendered);
        }

        $this->assertStringNotContainsString('secret', $rendered);
        $this->assertStringNotContainsString($good['id'], $rendered, 'a name that opens is not listed');
    }

    #[Test]
    public function aTwentyFirstDocumentIsCountedAndNotListedAndTheListedOnesAreTheLowestIds(): void
    {
        $planted = [];

        for ($i = 0; $i < 21; ++$i) {
            $document = $this->plantNamedDocument('doc-' . $i . '.pdf', ageSeconds: $i);
            $this->destroyScopeOf($document['scope']);
            $planted[] = $document['id'];
        }

        $this->report();

        $errors = $this->errors();
        $this->assertCount(1, $errors);
        $this->assertSame(21, $errors[0]->context['keyMissing'] ?? null);
        \sort($planted);
        $this->assertSame(\array_slice($planted, 0, 20), $errors[0]->context['documentIds'] ?? null);
    }

    #[Test]
    public function theCountsSpanEveryBatchAndNothingBetweenTwoBatchesIsSkipped(): void
    {
        $destroyedOne = $this->plantNamedDocument('a.pdf');
        $this->plantNamedDocument('b.pdf');
        $this->plantNamedDocument('c.pdf');
        $tampered = $this->plantNamedDocument('d.pdf');
        $destroyedTwo = $this->plantNamedDocument('e.pdf');
        $this->destroyScopeOf($destroyedOne['scope']);
        $this->destroyScopeOf($destroyedTwo['scope']);
        $this->tamperWithTheNameOf($tampered['id']);

        $this->report(batchSize: 2);

        $errors = $this->errors();
        $this->assertCount(1, $errors, 'still ONE line for a run of three batches');
        $context = $errors[0]->context;
        $this->assertSame(2, Dig::value($context, 'keyMissing'));
        $this->assertSame(1, Dig::value($context, 'decryptionFailed'));
        $this->assertSame(5, Dig::value($context, 'examined'));
        $this->assertTrue(Dig::value($context, 'complete'));
        $this->assertSame(
            [$destroyedOne['id'], $tampered['id'], $destroyedTwo['id']],
            Dig::value($context, 'documentIds'),
            'ascending id, across batches',
        );
    }

    #[Test]
    public function aBudgetSpentAfterABatchSaysSoWithAPartialExaminedCount(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $document = $this->plantNamedDocument('doc-' . $i . '.pdf', ageSeconds: 10 - $i);
            $this->destroyScopeOf($document['scope']);
        }

        // The first batch outlasts the one-second budget, so the loop ends before the second one.
        $this->report(budgetSeconds: 1, batchSize: 2, openSeconds: 1.2);

        $errors = $this->errors();
        $this->assertCount(1, $errors);
        $context = $errors[0]->context;
        $this->assertFalse(Dig::value($context, 'complete'));
        $this->assertSame(2, Dig::value($context, 'examined'));
        $this->assertSame(2, Dig::value($context, 'keyMissing'));
    }

    #[Test]
    public function aBudgetSpentWithNoFailingNameStillWritesTheIncompleteCoverageLine(): void
    {
        $this->plantNamedDocument('late.pdf');

        // A negative budget is already spent when the loop is entered: nothing is examined and the run says so.
        $this->report(budgetSeconds: -1);

        $records = $this->log->getRecords();
        $this->assertCount(1, $records);
        $record = $records[0] ?? null;
        $this->assertInstanceOf(LogRecord::class, $record);
        $this->assertSame(Level::Warning, $record->level);
        $this->assertSame(
            [
                'keyMissing' => 0,
                'decryptionFailed' => 0,
                'unreadable' => 0,
                'examined' => 0,
                'complete' => false,
                'documentIds' => [],
            ],
            $record->context,
        );
    }

    private function report(
        ?int $budgetSeconds = null,
        ?int $batchSize = null,
        float $openSeconds = 0.0,
    ): void {
        $encryptor = $this->service(EnvelopeEncryptor::class);
        $opener = new DocumentNameOpener(
            $openSeconds > 0.0 ? new SlowEnvelopeEncryptor($encryptor, $openSeconds) : $encryptor,
        );
        $logger = new Logger('observability', [$this->log]);

        $handler = new ReportDocumentNamesHandler(
            $this->connection(),
            $opener,
            $logger,
            $budgetSeconds ?? 60,
            $batchSize ?? ReportDocumentNamesHandler::DEFAULT_BATCH_SIZE,
        );

        $handler(new ReportDocumentNamesMessage());
    }

    /**
     * @return list<LogRecord>
     */
    private function errors(): array
    {
        return \array_values(\array_filter(
            $this->log->getRecords(),
            static fn (LogRecord $record): bool => Level::Error === $record->level,
        ));
    }
}
