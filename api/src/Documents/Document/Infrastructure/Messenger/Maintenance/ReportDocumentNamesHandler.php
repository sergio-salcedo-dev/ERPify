<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Erpify\Documents\Document\Application\Listing\DocumentNameOpener;
use Erpify\Documents\Document\Application\Listing\NameCause;
use Erpify\Documents\Document\Application\Listing\NameSource;
use Erpify\Documents\Document\Domain\DocumentState;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The alarm for a name that no longer opens. The listing degrades such a name to `null` and logs nothing — a page of
 * broken names would otherwise write one error line per name per request into the buffered default channel, and a
 * document nobody lists would never alarm at all — so this scheduled probe opens the names of the non-`Erased`
 * documents through the same {@see DocumentNameOpener} (one place knows how a name opens) and says what it found.
 *
 * Documents are taken in ascending id in batches ({@see DEFAULT_BATCH_SIZE} by default), one keystore query per batch,
 * until the table is done or the wall-clock budget is spent. A run that stopped early starts over from the beginning
 * next time, so a table too large for one budget is covered by its head only; the line says so (`complete: false`).
 * It is read-only and idempotent.
 *
 * It writes ONE line per run on the `observability` channel: an `error` when a name failed, with the count per cause
 * (`key-missing`, `decryption-failed`, `unreadable`), the number of documents examined, whether the run finished and
 * the first {@see IDS_PER_LINE} failing document ids in ascending order (a further one is counted, not listed); a
 * `warning` with the same shape and zero counts when no name failed but the run did not finish, so incomplete coverage
 * is never silent; nothing when the run finished and every name opened. Never a filename, a scope or an actor.
 */
#[AsMessageHandler]
final readonly class ReportDocumentNamesHandler
{
    /** The most ids one line lists; the counts are always the full ones. */
    private const int IDS_PER_LINE = 20;

    /** Documents per keystore query: one `IN` list, small enough to keep the unwrapped keys of a batch few. */
    public const int DEFAULT_BATCH_SIZE = 100;

    /** Wall-clock the run may spend, so a large table never holds a worker for the whole of its length. */
    private const int BUDGET_SECONDS = 60;

    /** Collates before every UUID, so the first batch starts at the beginning of the table. */
    private const string START = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private Connection $connection,
        private DocumentNameOpener $nameOpener,
        #[Autowire(service: 'monolog.logger.observability')]
        private LoggerInterface $logger,
        private int $budgetSeconds = self::BUDGET_SECONDS,
        private int $batchSize = self::DEFAULT_BATCH_SIZE,
    ) {
    }

    public function __invoke(ReportDocumentNamesMessage $message): void
    {
        unset($message);

        $deadline = \hrtime(true) + $this->budgetSeconds * 1_000_000_000;
        $counts = \array_fill_keys(\array_column(NameCause::cases(), 'value'), 0);
        $ids = [];
        $examined = 0;
        $after = self::START;
        $complete = false;

        while (\hrtime(true) < $deadline) {
            $batch = $this->nextBatch($after);

            if ([] === $batch) {
                $complete = true;

                break;
            }

            $outcomes = $this->nameOpener->open($batch);

            // In batch order, which is ascending id: the first ids kept are the lowest ones.
            foreach ($batch as $source) {
                $failure = ($outcomes[$source->documentId] ?? null)?->failure;

                if ($failure instanceof NameCause) {
                    ++$counts[$failure->value];

                    if (\count($ids) < self::IDS_PER_LINE) {
                        $ids[] = $source->documentId;
                    }
                }
            }

            $examined += \count($batch);
            $after = \array_last($batch)->documentId;
        }

        $failed = \array_sum($counts) > 0;

        if (!$failed && $complete) {
            return;
        }

        $context = [
            'keyMissing' => $counts[NameCause::KeyMissing->value],
            'decryptionFailed' => $counts[NameCause::DecryptionFailed->value],
            'unreadable' => $counts[NameCause::Unreadable->value],
            'examined' => $examined,
            'complete' => $complete,
            'documentIds' => $ids,
        ];

        if ($failed) {
            $this->logger->error('Documents whose sealed name does not open.', $context);

            return;
        }

        $this->logger->warning('Documents whose sealed names were not all checked.', $context);
    }

    /**
     * @return list<NameSource>
     */
    private function nextBatch(string $after): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, scope_id, filename_ciphertext FROM document '
            . 'WHERE state <> :erased AND filename_ciphertext IS NOT NULL AND id > CAST(:after AS UUID) '
            . 'ORDER BY id LIMIT :limit',
            ['erased' => DocumentState::Erased->value, 'after' => $after, 'limit' => $this->batchSize],
            ['limit' => Types::INTEGER],
        );

        $sources = [];

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $scope = $row['scope_id'] ?? null;
            $ciphertext = $row['filename_ciphertext'] ?? null;

            if (\is_string($id) && \is_string($scope) && \is_string($ciphertext)) {
                $sources[] = new NameSource($id, $scope, $ciphertext);
            }
        }

        return $sources;
    }
}
