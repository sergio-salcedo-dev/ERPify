<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Read;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Documents\Document\Application\Erasure\ErasureOverrideSwitch;
use Erpify\Documents\Document\Application\Erasure\StandingOverride;
use Erpify\Documents\Document\Application\Read\ErasureLedgerOverride;
use Erpify\Documents\Document\Domain\Entity\LedgerOverrideRecord;
use Erpify\Documents\Document\Domain\Erasure\LedgerOverrideReason;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * The erasure ledger's override over its table: the flag is the existence of an uncleared row, so it survives a
 * restart, is the same for every worker and every request, and cannot be set by the routes that ask it.
 *
 * `isActive()` is one read of a table that holds a handful of rows for the life of the installation, run per request by
 * the three Documents read routes; no cache sits in front of it, because a flag that lags is a flag that serves a
 * document the ledger says is erased.
 */
#[AsAlias(ErasureLedgerOverride::class)]
#[AsAlias(ErasureOverrideSwitch::class)]
final readonly class DbalErasureLedgerOverride implements ErasureLedgerOverride, ErasureOverrideSwitch
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Override]
    public function isActive(): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM erasure_ledger_override WHERE cleared_at IS NULL LIMIT 1',
        );
    }

    #[Override]
    public function activate(LedgerOverrideReason $reason): string
    {
        $record = LedgerOverrideRecord::set($reason);

        $this->entityManager->persist($record);
        $this->entityManager->flush();

        return $record->recordRef();
    }

    #[Override]
    public function unclearedIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM erasure_ledger_override WHERE cleared_at IS NULL ORDER BY id',
        );

        return $ids;
    }

    #[Override]
    public function clear(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        // Named by id and guarded by `cleared_at IS NULL`: an override set after the caller took its snapshot is not in
        // the list, so it is left standing however the call raced it.
        /** @var list<string> $references */
        $references = $this->connection->fetchFirstColumn(
            'UPDATE erasure_ledger_override SET cleared_at = NOW() '
            . 'WHERE cleared_at IS NULL AND id IN (:ids) RETURNING record_ref',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        return $references;
    }

    #[Override]
    public function standing(): ?StandingOverride
    {
        // An aggregate always answers one row; the empty fallback only satisfies the driver's `array|false`.
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS standing, EXTRACT(EPOCH FROM NOW() - MIN(set_at)) AS age '
            . 'FROM erasure_ledger_override WHERE cleared_at IS NULL',
        ) ?: [];

        $count = $row['standing'] ?? 0;
        $age = $row['age'] ?? 0;

        if (!\is_numeric($count) || 0 === (int) $count) {
            return null;
        }

        return new StandingOverride((int) $count, \is_numeric($age) ? \max(0, (int) $age) : 0);
    }
}
