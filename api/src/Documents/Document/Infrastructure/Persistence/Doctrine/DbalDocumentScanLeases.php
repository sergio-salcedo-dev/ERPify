<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Documents\Document\Domain\Scanning\DocumentScanLeases;
use Erpify\Documents\Document\Domain\Scanning\ReturnOutcome;
use Erpify\Documents\Document\Domain\Scanning\ScanClaim;
use Erpify\Documents\Document\Domain\Scanning\ScanLease;
use Erpify\Documents\Document\Domain\ScanPolicy;
use Erpify\Shared\Uuid\Domain\Uuid;
use InvalidArgumentException;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use UnexpectedValueException;

/**
 * The scan's compare-and-sets on `document`, one statement per transition and every judgement of time the
 * database's (`NOW()`): a worker's clock, skewed or frozen, decides nothing. Kept apart from the repository whose
 * single `UPDATE document` is the erasure's rewrite, which a lock-order gate reads alone.
 *
 * Every statement names the state it expects, so an `Erased` row matches none of them, and every statement that
 * continues a claim also names its `lease_epoch`, so a worker whose lease was taken over writes nothing.
 */
#[AsAlias(DocumentScanLeases::class)]
final readonly class DbalDocumentScanLeases implements DocumentScanLeases
{
    private const string UNEXPIRED_DEADLINE = 'NOW() < claimed_at + make_interval(secs => :deadline)';

    /** The tail every transition that continues a claim shares: the state, the epoch it was granted, the deadline. */
    private const string ON_LIVE_CLAIM = 'AND state = :scanning AND lease_epoch = :epoch AND '
        . self::UNEXPIRED_DEADLINE;

    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function dueCandidates(int $limit): array
    {
        /** @phpstan-var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT id FROM document WHERE state = :received AND promoted_at IS NOT NULL AND next_scan_at <= NOW() '
            . 'AND scan_attempts < :max_attempts AND scan_outage_returns < :max_outage '
            . 'ORDER BY next_scan_at, id LIMIT :limit',
            ['received' => DocumentState::Received->value, 'limit' => $limit] + $this->caps(),
            ['limit' => Types::INTEGER, 'max_attempts' => Types::INTEGER, 'max_outage' => Types::INTEGER],
        );
    }

    #[Override]
    public function claim(string $documentId): ?ScanClaim
    {
        Uuid::ensure($documentId);

        $row = $this->connection->fetchAssociative(
            'WITH prior AS ('
            . 'SELECT id, first_claim_recorded FROM document WHERE id = CAST(:id AS UUID) AND state = :received '
            . 'AND promoted_at IS NOT NULL AND next_scan_at <= NOW() '
            . 'AND scan_attempts < :max_attempts AND scan_outage_returns < :max_outage FOR UPDATE'
            . ') '
            . 'UPDATE document d SET state = :scanning, lease_epoch = d.lease_epoch + 1, claimed_at = NOW(), '
            . 'lease_expires_at = NOW() + make_interval(secs => :lease), scan_streaming_started_at = NULL, '
            . 'first_claim_recorded = TRUE FROM prior WHERE d.id = prior.id '
            . 'RETURNING d.lease_epoch AS epoch, prior.first_claim_recorded AS was_recorded',
            [
                'id' => \strtolower($documentId),
                'received' => DocumentState::Received->value,
                'scanning' => DocumentState::Scanning->value,
                'lease' => ScanPolicy::LEASE_SECONDS,
            ] + $this->caps(),
            ['lease' => Types::INTEGER, 'max_attempts' => Types::INTEGER, 'max_outage' => Types::INTEGER],
        );

        if (false === $row) {
            return null;
        }

        return new ScanClaim($documentId, $this->intOf($row['epoch'] ?? null), true === ($row['was_recorded'] ?? null));
    }

    #[Override]
    public function markStreaming(string $documentId, int $epoch): bool
    {
        return $this->changed(
            'UPDATE document SET scan_streaming_started_at = NOW() WHERE id = CAST(:id AS UUID) '
            . self::ON_LIVE_CLAIM,
            $documentId,
            $epoch,
        );
    }

    #[Override]
    public function heartbeat(string $documentId, int $epoch): bool
    {
        return $this->changed(
            'UPDATE document SET lease_expires_at = LEAST(NOW() + make_interval(secs => :lease), '
            . 'claimed_at + make_interval(secs => :deadline)) WHERE id = CAST(:id AS UUID) '
            . self::ON_LIVE_CLAIM,
            $documentId,
            $epoch,
            ['lease' => ScanPolicy::LEASE_SECONDS],
        );
    }

    #[Override]
    public function settle(string $documentId, int $epoch, DocumentState $verdict): bool
    {
        if (!\in_array($verdict, [DocumentState::Ready, DocumentState::Quarantined, DocumentState::ScanFailed], true)) {
            throw new InvalidArgumentException('A scan verdict is Ready, Quarantined or ScanFailed.');
        }

        return $this->changed(
            'UPDATE document SET state = :verdict, scan_streaming_started_at = NULL, claimed_at = NULL, '
            . 'lease_expires_at = NULL WHERE id = CAST(:id AS UUID) '
            . self::ON_LIVE_CLAIM,
            $documentId,
            $epoch,
            ['verdict' => $verdict->value],
        );
    }

    #[Override]
    public function returnToReceived(
        string $documentId,
        int $epoch,
        bool $scannerDown,
        bool $onlyIfLeaseLapsed,
    ): ReturnOutcome {
        Uuid::ensure($documentId);

        // A return is counted when streaming had begun and the scanner is still up; otherwise it is an outage.
        $counted = '(scan_streaming_started_at IS NOT NULL AND NOT CAST(:down AS BOOLEAN))';
        $capped = 'CASE WHEN ' . $counted . ' THEN scan_attempts + 1 >= :max_attempts '
            . 'ELSE scan_outage_returns + 1 >= :max_outage END';
        // The ladder entry for the count after this return, the last entry repeating. Read with `unnest` rather
        // than a subscript: DBAL's parser takes a bracket pair as a quoted identifier and would hide the parameter.
        $entry = static fn (string $ladder, string $count): string => '(SELECT ladder.seconds FROM unnest(CAST('
            . $ladder . ' AS INTEGER[])) WITH ORDINALITY AS ladder(seconds, position) WHERE ladder.position = '
            . 'LEAST(' . $count . ', cardinality(CAST(' . $ladder . ' AS INTEGER[]))))';
        $base = 'CASE WHEN ' . $counted . ' THEN ' . $entry(':counted_ladder', 'scan_attempts + 1')
            . ' ELSE ' . $entry(':outage_ladder', 'scan_outage_returns + 1') . ' END';

        $state = $this->connection->fetchOne(
            'UPDATE document SET '
            . 'state = CASE WHEN ' . $capped . ' THEN :failed ELSE :received END, '
            . 'scan_attempts = scan_attempts + CASE WHEN ' . $counted . ' THEN 1 ELSE 0 END, '
            . 'scan_outage_returns = scan_outage_returns + CASE WHEN ' . $counted . ' THEN 0 ELSE 1 END, '
            . 'next_scan_at = CASE WHEN ' . $capped . ' THEN next_scan_at ELSE NOW() + make_interval(secs => ('
            . $base . ') * (1 + (random() * 2 - 1) * :jitter)) END, '
            . 'scan_streaming_started_at = NULL, claimed_at = NULL, lease_expires_at = NULL '
            . 'WHERE id = CAST(:id AS UUID) AND state = :scanning AND lease_epoch = :epoch '
            . 'AND (NOT CAST(:lapsed_only AS BOOLEAN) OR lease_expires_at < NOW()) RETURNING state',
            [
                'id' => \strtolower($documentId),
                'epoch' => $epoch,
                'down' => $scannerDown,
                'lapsed_only' => $onlyIfLeaseLapsed,
                'scanning' => DocumentState::Scanning->value,
                'received' => DocumentState::Received->value,
                'failed' => DocumentState::ScanFailed->value,
                'counted_ladder' => $this->arrayLiteral(ScanPolicy::ladder(true)),
                'outage_ladder' => $this->arrayLiteral(ScanPolicy::ladder(false)),
                'jitter' => ScanPolicy::JITTER,
            ] + $this->caps(),
            [
                'epoch' => Types::BIGINT,
                'down' => Types::BOOLEAN,
                'lapsed_only' => Types::BOOLEAN,
                'jitter' => Types::FLOAT,
                'max_attempts' => Types::INTEGER,
                'max_outage' => Types::INTEGER,
            ],
        );

        return match ($state) {
            DocumentState::Received->value => ReturnOutcome::BackedOff,
            DocumentState::ScanFailed->value => ReturnOutcome::Failed,
            default => ReturnOutcome::Lost,
        };
    }

    #[Override]
    public function expiredLeases(int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, lease_epoch FROM document WHERE state = :scanning AND lease_expires_at < NOW() '
            . 'ORDER BY lease_expires_at, id LIMIT :limit',
            ['scanning' => DocumentState::Scanning->value, 'limit' => $limit],
            ['limit' => Types::INTEGER],
        );

        return \array_map(
            fn (array $row): ScanLease => new ScanLease(
                $this->stringOf($row['id'] ?? null),
                $this->intOf($row['lease_epoch'] ?? null),
            ),
            $rows,
        );
    }

    #[Override]
    public function cappedCandidates(int $limit): array
    {
        /** @phpstan-var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT id FROM document WHERE state = :received '
            . 'AND (scan_attempts >= :max_attempts OR scan_outage_returns >= :max_outage) ORDER BY id LIMIT :limit',
            ['received' => DocumentState::Received->value, 'limit' => $limit] + $this->caps(),
            ['limit' => Types::INTEGER, 'max_attempts' => Types::INTEGER, 'max_outage' => Types::INTEGER],
        );
    }

    #[Override]
    public function failCapped(string $documentId): bool
    {
        Uuid::ensure($documentId);

        return 1 === (int) $this->connection->executeStatement(
            'UPDATE document SET state = :failed WHERE id = CAST(:id AS UUID) AND state = :received '
            . 'AND (scan_attempts >= :max_attempts OR scan_outage_returns >= :max_outage)',
            [
                'id' => \strtolower($documentId),
                'failed' => DocumentState::ScanFailed->value,
                'received' => DocumentState::Received->value,
            ] + $this->caps(),
            ['max_attempts' => Types::INTEGER, 'max_outage' => Types::INTEGER],
        );
    }

    #[Override]
    public function reopen(string $documentId): bool
    {
        Uuid::ensure($documentId);

        return 1 === (int) $this->connection->executeStatement(
            'UPDATE document SET state = :received, scan_attempts = 0, scan_outage_returns = 0, '
            . 'lease_epoch = lease_epoch + 1, next_scan_at = NOW(), first_claim_recorded = FALSE, '
            . 'scan_streaming_started_at = NULL, claimed_at = NULL, lease_expires_at = NULL '
            . 'WHERE id = CAST(:id AS UUID) AND state = :failed',
            [
                'id' => \strtolower($documentId),
                'received' => DocumentState::Received->value,
                'failed' => DocumentState::ScanFailed->value,
            ],
        );
    }

    /**
     * @param array<string, int|string> $extra
     */
    private function changed(string $sql, string $documentId, int $epoch, array $extra = []): bool
    {
        Uuid::ensure($documentId);

        return 1 === (int) $this->connection->executeStatement(
            $sql,
            [
                'id' => \strtolower($documentId),
                'epoch' => $epoch,
                'scanning' => DocumentState::Scanning->value,
                'deadline' => ScanPolicy::DEADLINE_SECONDS,
            ] + $extra,
            ['epoch' => Types::BIGINT, 'deadline' => Types::INTEGER, 'lease' => Types::INTEGER],
        );
    }

    /**
     * @return array{max_attempts: int, max_outage: int}
     */
    private function caps(): array
    {
        return ['max_attempts' => ScanPolicy::MAX_ATTEMPTS, 'max_outage' => ScanPolicy::MAX_OUTAGE_RETURNS];
    }

    /**
     * @param list<int> $values
     */
    private function arrayLiteral(array $values): string
    {
        return '{' . \implode(',', $values) . '}';
    }

    private function intOf(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : throw new UnexpectedValueException('Expected a number.');
    }

    private function stringOf(mixed $value): string
    {
        return \is_string($value) ? $value : throw new UnexpectedValueException('Expected a string.');
    }
}
