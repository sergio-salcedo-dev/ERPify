<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\ScanPolicy;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The scan's alarms: an `error` line on the observability channel, which is the only surface, for each condition
 * nothing else repairs. Each line carries a count and up to {@see IDS_PER_LINE} document ids — never an actor, a
 * filename or a scope.
 *
 *  - **stuck**: a document in `Received` or `Scanning` older than {@see ScanPolicy::STUCK_BOUND_SECONDS}, above the
 *    longest legitimate retry horizon (the outage-return ladder: about 6.35 hours before jitter, 7.6 at its maximum);
 *  - **awaiting an operator**: a `Quarantined` or `ScanFailed` document older than
 *    {@see ScanPolicy::AWAITING_OPERATOR_BOUND_SECONDS}, which would otherwise be a silent sink;
 *  - **outage**: a document that has been returned by an absent scanner {@see ScanPolicy::OUTAGE_ALARM_AT} times or
 *    more and is still waiting;
 *  - **deterministic**: a `ScanFailed` document no cap ended, so a retry would not help and a person must look.
 *
 * Age is measured from the document's last custody-bearing event (entering `Received`, the first claim, a verdict, an
 * operator decision), which a lease return does not touch: a document looping through an outage still ages, and an
 * operator re-scan of an old document does not alarm at once. Read-only and idempotent.
 */
#[AsMessageHandler]
final readonly class ReportDocumentScansHandler
{
    /** The most ids one line lists; the count is always the full one. */
    private const int IDS_PER_LINE = 20;

    private const string LAST_EVENT = 'COALESCE((SELECT MAX(c.created_at) FROM document_custody c '
        . "WHERE c.document_id = d.id), d.created_at AT TIME ZONE 'UTC')";

    public function __construct(
        private Connection $connection,
        #[Autowire(service: 'monolog.logger.observability')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReportDocumentScansMessage $message): void
    {
        unset($message);

        $this->report(
            'Documents stuck before a scan verdict.',
            'SELECT d.id FROM document d WHERE d.state IN (:received, :scanning) AND '
            . self::LAST_EVENT . ' < NOW() - make_interval(secs => :bound)',
            [
                'received' => DocumentState::Received->value,
                'scanning' => DocumentState::Scanning->value,
                'bound' => ScanPolicy::STUCK_BOUND_SECONDS,
            ],
            ['bound' => ScanPolicy::STUCK_BOUND_SECONDS],
        );
        $this->report(
            'Documents waiting for an operator after a scan.',
            'SELECT d.id FROM document d WHERE d.state IN (:quarantined, :failed) AND '
            . self::LAST_EVENT . ' < NOW() - make_interval(secs => :bound)',
            [
                'quarantined' => DocumentState::Quarantined->value,
                'failed' => DocumentState::ScanFailed->value,
                'bound' => ScanPolicy::AWAITING_OPERATOR_BOUND_SECONDS,
            ],
            ['bound' => ScanPolicy::AWAITING_OPERATOR_BOUND_SECONDS],
        );
        $this->report(
            'Documents returned by an absent scanner repeatedly.',
            'SELECT d.id FROM document d WHERE d.state IN (:received, :scanning) AND d.scan_outage_returns >= :alarm',
            [
                'received' => DocumentState::Received->value,
                'scanning' => DocumentState::Scanning->value,
                'alarm' => ScanPolicy::OUTAGE_ALARM_AT,
            ],
            ['alarm' => ScanPolicy::OUTAGE_ALARM_AT],
        );
        $this->report(
            'Documents whose scan failed for a reason a retry would not change.',
            'SELECT d.id FROM document d WHERE d.state = :failed AND d.scan_attempts < :max_attempts '
            . 'AND d.scan_outage_returns < :max_outage',
            [
                'failed' => DocumentState::ScanFailed->value,
                'max_attempts' => ScanPolicy::MAX_ATTEMPTS,
                'max_outage' => ScanPolicy::MAX_OUTAGE_RETURNS,
            ],
            ['max_attempts' => ScanPolicy::MAX_ATTEMPTS, 'max_outage' => ScanPolicy::MAX_OUTAGE_RETURNS],
        );
    }

    /**
     * @param array<string, int|string> $parameters
     * @param array<string, int>        $context
     */
    private function report(string $message, string $select, array $parameters, array $context): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, COUNT(*) OVER () AS total FROM (' . $select . ') AS found ORDER BY id LIMIT :limit',
            $parameters + ['limit' => self::IDS_PER_LINE],
            ['limit' => Types::INTEGER, 'bound' => Types::INTEGER, 'alarm' => Types::INTEGER,
                'max_attempts' => Types::INTEGER, 'max_outage' => Types::INTEGER],
        );

        if ([] === $rows) {
            return;
        }

        $total = $rows[0]['total'] ?? 0;

        $this->logger->error($message, $context + [
            'count' => \is_numeric($total) ? (int) $total : 0,
            'documentIds' => \array_column($rows, 'id'),
        ]);
    }
}
