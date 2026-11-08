<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Doctrine\DBAL\Connection;
use Erpify\Documents\Document\Application\ReceiveDocument;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReportDocumentStorageHandler;
use Erpify\Documents\Document\Infrastructure\Messenger\Maintenance\ReportDocumentStorageMessage;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid as RandomUuid;

/**
 * The daily storage report against real Postgres, with a capturing observability logger and a storage whose walk
 * is scripted. Each test runs in a transaction it rolls back, inside which the document tables and the aborted
 * tombstones are emptied: the suite shares one database, and every other test's documents would otherwise be
 * rows this report rightly alarms on.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the handler's real collaborators and a capturing logger
 */
#[CoversClass(ReportDocumentStorageHandler::class)]
final class ReportDocumentStorageFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    private TestHandler $log;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->log = new TestHandler();
        $this->connection()->beginTransaction();
        $this->connection()->executeStatement('DELETE FROM document_custody');
        $this->connection()->executeStatement('DELETE FROM upload_intent');
        $this->connection()->executeStatement('DELETE FROM document');
        $this->connection()->executeStatement("DELETE FROM dek_keystore WHERE destroy_reason = 'aborted-upload'");
    }

    protected function tearDown(): void
    {
        $this->connection()->rollBack();
        parent::tearDown();
    }

    public function testACleanStoreRaisesNoAlarm(): void
    {
        $this->report([]);

        $this->assertSame([], $this->errors());
        $this->assertTrue($this->log->hasInfoThatContains('Document keystore growth.'));
    }

    public function testAFinalObjectWithNoRowIsAlarmed(): void
    {
        $orphan = RandomUuid::v4()->toRfc4122();

        $this->report([$orphan]);

        $this->assertSame(['Final document objects with no row.'], $this->errors());
        $this->assertSame([$orphan], $this->errorContext('Final document objects with no row.', 'scopeIds'));
    }

    public function testARowWithNoObjectIsAlarmed(): void
    {
        $document = $this->service(ReceiveDocument::class)->receive(
            RandomUuid::v4()->toRfc4122(),
            Uuid::generate(),
            \hash('sha256', 'gone'),
            4,
        );

        $this->report([]);

        $this->assertSame(['Document rows with no stored object.'], $this->errors());
        $this->assertSame(
            [$document->documentId()],
            $this->errorContext('Document rows with no stored object.', 'documentIds'),
        );
    }

    public function testAbortedTombstonesOverTheDailyThresholdAreAlarmed(): void
    {
        $this->connection()->executeStatement(
            'INSERT INTO dek_keystore (encryption_scope_id, wrapped_dek, kek_version, created_at, destroyed_at, '
            . "destroy_reason) SELECT 'Document:' || gen_random_uuid(), NULL, 1, NOW(), NOW(), 'aborted-upload' "
            . 'FROM generate_series(1, :count)',
            ['count' => ReportDocumentStorageHandler::TOMBSTONE_ALARM_PER_DAY + 1],
        );

        $this->report([]);

        $this->assertSame(['Aborted-upload tombstones over threshold.'], $this->errors());
    }

    public function testAScanThatReachesItsLimitSaysSo(): void
    {
        $scopes = [];

        for ($i = 0; $i <= 10_000; ++$i) {
            $scopes[] = RandomUuid::v4()->toRfc4122();
        }

        $this->report($scopes);

        $this->assertContains('Document storage scan truncated.', $this->errors());
    }

    /**
     * @param list<string> $finalScopes what the storage walk of the final area yields
     */
    private function report(array $finalScopes): void
    {
        $storage = (new ScriptedDocumentStorage($this->service(DocumentStorage::class)))->walkYields($finalScopes);

        (new ReportDocumentStorageHandler($this->connection(), $storage, new Logger('observability', [$this->log])))(
            new ReportDocumentStorageMessage(),
        );
    }

    /**
     * @return list<string>
     */
    private function errors(): array
    {
        return \array_values(\array_map(
            static fn (LogRecord $record): string => $record->message,
            \array_filter(
                $this->log->getRecords(),
                static fn (LogRecord $record): bool => Level::Error === $record->level,
            ),
        ));
    }

    private function errorContext(string $message, string $key): mixed
    {
        foreach ($this->log->getRecords() as $record) {
            if ($message === $record->message) {
                return $record->context[$key] ?? null;
            }
        }

        return null;
    }

    private function connection(): Connection
    {
        return $this->service(Connection::class);
    }
}
