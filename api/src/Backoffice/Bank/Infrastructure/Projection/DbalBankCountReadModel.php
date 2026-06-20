<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Projection;

use Doctrine\DBAL\Connection;
use Erpify\Backoffice\Bank\Application\Projection\BankCountReadModel;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link BankCountReadModel} on the singleton `bank_count` row (`id = 1`) via the default DBAL
 * connection, so the write commits in the projection runner's transaction with the checkpoint advance.
 * The singleton invariant is enforced by always upserting on `id = 1`.
 */
#[AsAlias(BankCountReadModel::class)]
final readonly class DbalBankCountReadModel implements BankCountReadModel
{
    public function __construct(private Connection $connection)
    {
    }

    #[Override]
    public function increment(): void
    {
        $this->applyDelta(1);
    }

    #[Override]
    public function decrement(): void
    {
        $this->applyDelta(-1);
    }

    #[Override]
    public function total(): int
    {
        $value = $this->connection->fetchOne('SELECT total FROM bank_count WHERE id = 1');

        return \is_numeric($value) ? (int) $value : 0;
    }

    #[Override]
    public function reset(): void
    {
        $this->connection->executeStatement('DELETE FROM bank_count');
    }

    private function applyDelta(int $by): void
    {
        // Seed clamps to 0 so a first event that is a decrement (never happens in a consistent log:
        // a delete cannot precede its create) cannot seed a negative singleton.
        $this->connection->executeStatement(
            'INSERT INTO bank_count (id, total, updated_at) VALUES (1, :seed, now()) '
            . 'ON CONFLICT (id) DO UPDATE SET total = bank_count.total + :delta, updated_at = now()',
            ['seed' => \max(0, $by), 'delta' => $by],
        );
    }
}
