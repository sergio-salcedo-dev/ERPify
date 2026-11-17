<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Erpify\Documents\Document\Application\Erasure\ErasureProgress;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@see ErasureProgress} over `document` and `dek_keystore`: reads only, in bounded batches so a long ledger is never
 * one oversized `IN` list.
 */
#[AsAlias(ErasureProgress::class)]
final readonly class DbalErasureProgress implements ErasureProgress
{
    private const int BATCH = 500;

    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function unfinished(array $scopeIds): array
    {
        // A value that is not a canonical UUID can never be a scope this database holds, and one such value in a batch
        // would fail the whole `UUID[]` cast for every other scope: it is reported unfinished on its own and kept out
        // of the query, so nothing downstream depends on the adapter having validated it.
        $valid = \array_values(\array_filter($scopeIds, $this->isCanonical(...)));
        $unfinished = \array_values(\array_diff($scopeIds, $valid));

        foreach (\array_chunk($valid, self::BATCH) as $batch) {
            /** @var list<string> $finished */
            $finished = $this->connection->fetchFirstColumn(
                'SELECT s.scope_id FROM (SELECT unnest(CAST(:scopes AS UUID[])) AS scope_id) s '
                . "JOIN dek_keystore k ON k.encryption_scope_id = :type || ':' || s.scope_id "
                . 'AND k.destroyed_at IS NOT NULL '
                . 'LEFT JOIN document d ON d.scope_id = s.scope_id '
                . 'WHERE d.id IS NULL OR d.state = :erased',
                [
                    'scopes' => $this->pgArray($batch),
                    'type' => EncryptionScopeId::DOCUMENT_TYPE,
                    'erased' => DocumentState::Erased->value,
                ],
            );

            foreach (\array_diff($batch, $finished) as $scopeId) {
                $unfinished[] = $scopeId;
            }
        }

        return $unfinished;
    }

    #[Override]
    public function notErasedAmong(array $scopeIds): int
    {
        $count = 0;

        $valid = \array_values(\array_filter($scopeIds, $this->isCanonical(...)));

        foreach (\array_chunk($valid, self::BATCH) as $batch) {
            $found = $this->connection->fetchOne(
                'SELECT COUNT(*) FROM document WHERE scope_id IN (:scopes) AND state <> :erased',
                ['scopes' => $batch, 'erased' => DocumentState::Erased->value],
                ['scopes' => ArrayParameterType::STRING],
            );
            $count += \is_numeric($found) ? (int) $found : 0;
        }

        return $count;
    }

    private function isCanonical(string $value): bool
    {
        return Uuid::isValid($value) && \strtolower($value) === $value;
    }

    /**
     * A PostgreSQL array literal with every element quoted and escaped, so it is correct for any string and does not
     * lean on the caller having validated the elements.
     *
     * @param list<string> $values
     */
    private function pgArray(array $values): string
    {
        return '{' . \implode(',', \array_map(
            static fn (string $value): string => '"' . \addcslashes($value, '"\\') . '"',
            $values,
        )) . '}';
    }
}
