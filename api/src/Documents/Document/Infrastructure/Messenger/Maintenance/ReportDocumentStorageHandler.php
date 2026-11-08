<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The Documents scheduled report: one `info` line watching the keystore's growth, and an `error` line — the
 * alarm; there is no other surface — for each of three conditions nothing else repairs:
 *
 *  - aborted-upload tombstones minted in the last day above {@see TOMBSTONE_ALARM_PER_DAY}. They are never
 *    pruned (pruning reopens the late-writer hole), so their growth is the cost this line keeps visible;
 *  - a final object no document row names. It cannot arise in the normal flow (the row commits first); it means
 *    a restore to an older dump or a hand, so it is reported and never deleted;
 *  - a non-erased document row with no object in either namespace.
 *
 * Each scan reads at most {@see SCAN_LIMIT} entries, and one that reaches it says so on its own `error` line.
 *
 * Every line carries ids and counts only — never an actor, a filename or a key hash. Read-only and idempotent.
 */
#[AsMessageHandler]
final readonly class ReportDocumentStorageHandler
{
    public const int TOMBSTONE_ALARM_PER_DAY = 500;

    /** The most ids one line lists; the count is always the full one. */
    private const int IDS_PER_LINE = 20;

    private const int SCAN_LIMIT = 10_000;

    public function __construct(
        private Connection $connection,
        private DocumentStorage $storage,
        #[Autowire(service: 'monolog.logger.observability')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReportDocumentStorageMessage $message): void
    {
        unset($message);

        $this->reportKeystoreGrowth();
        $this->alarmOnFinalObjectsWithoutRow();
        $this->alarmOnRowsWithoutObject();
    }

    private function reportKeystoreGrowth(): void
    {
        $prefix = EncryptionScopeId::DOCUMENT_TYPE . ':%';
        $row = $this->connection->fetchAssociative(
            'SELECT '
            . 'COUNT(*) FILTER (WHERE destroy_reason = :aborted) AS aborted_total, '
            . 'COUNT(*) FILTER (WHERE destroy_reason = :aborted '
            . "AND destroyed_at > NOW() - INTERVAL '1 day') AS aborted_last_day, "
            . 'COUNT(*) FILTER (WHERE destroyed_at IS NULL) AS live_total '
            . 'FROM dek_keystore WHERE encryption_scope_id LIKE :prefix',
            ['aborted' => DestroyReason::AbortedUpload->value, 'prefix' => $prefix],
        );

        $abortedLastDay = $this->countIn($row, 'aborted_last_day');
        $context = [
            'abortedUploadTombstones' => $this->countIn($row, 'aborted_total'),
            'abortedUploadTombstonesLastDay' => $abortedLastDay,
            'liveDocumentKeys' => $this->countIn($row, 'live_total'),
        ];

        $this->logger->info('Document keystore growth.', $context);

        if ($abortedLastDay > self::TOMBSTONE_ALARM_PER_DAY) {
            $this->logger->error('Aborted-upload tombstones over threshold.', $context + [
                'threshold' => self::TOMBSTONE_ALARM_PER_DAY,
            ]);
        }
    }

    /**
     * Past the scan window nothing is checked; this says so, rather than reporting a clean store over the part
     * it never read.
     */
    private function reportTruncated(string $what): void
    {
        $this->logger->error('Document storage scan truncated.', [
            'scanned' => $what,
            'limit' => self::SCAN_LIMIT,
        ]);
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function countIn(array|false $row, string $column): int
    {
        $value = false === $row ? null : ($row[$column] ?? null);

        return \is_numeric($value) ? (int) $value : 0;
    }

    private function alarmOnFinalObjectsWithoutRow(): void
    {
        $scopes = [];

        foreach ($this->storage->scopesOlderThan(StorageArea::Final, 0) as $scope) {
            if (\count($scopes) >= self::SCAN_LIMIT) {
                $this->reportTruncated('final objects');

                break;
            }

            $scopes[] = $scope;
        }

        if ([] === $scopes) {
            return;
        }

        /** @var list<string> $owned */
        $owned = $this->connection->fetchFirstColumn(
            'SELECT scope_id FROM document WHERE scope_id IN (:scopes)',
            ['scopes' => $scopes],
            ['scopes' => ArrayParameterType::STRING],
        );

        $orphans = \array_values(\array_diff($scopes, $owned));

        if ([] !== $orphans) {
            $this->logger->error('Final document objects with no row.', [
                'count' => \count($orphans),
                'scopeIds' => \array_slice($orphans, 0, self::IDS_PER_LINE),
            ]);
        }
    }

    private function alarmOnRowsWithoutObject(): void
    {
        /** @var list<array{id: string, scope_id: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, scope_id FROM document WHERE state <> :erased ORDER BY id LIMIT :limit',
            ['erased' => DocumentState::Erased->value, 'limit' => self::SCAN_LIMIT + 1],
            ['limit' => Types::INTEGER],
        );

        if (\count($rows) > self::SCAN_LIMIT) {
            $rows = \array_slice($rows, 0, self::SCAN_LIMIT);
            $this->reportTruncated('document rows');
        }

        $missing = [];

        foreach ($rows as $row) {
            // Staging first, then final — the direction a promotion moves the object — so a promotion landing
            // between the two checks is found in final rather than reported missing from both.
            if (
                !$this->storage->has(StorageArea::Staging, $row['scope_id'])
                && !$this->storage->has(StorageArea::Final, $row['scope_id'])
            ) {
                $missing[] = $row['id'];
            }
        }

        if ([] !== $missing) {
            $this->logger->error('Document rows with no stored object.', [
                'count' => \count($missing),
                'documentIds' => \array_slice($missing, 0, self::IDS_PER_LINE),
            ]);
        }
    }
}
