<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Crypto;

use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Crypto\Application\WrappedDek;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Override;

/**
 * In-memory {@see Keystore} for unit tests. Mirrors the production contract faithfully, tombstone included:
 * first write wins (`ON CONFLICT DO NOTHING`); destroying a scope keeps a surviving tombstone so its key
 * reads as unavailable, the destroy is idempotent, and a later `store` for that scope is a no-op — the
 * scope can never be re-minted, exactly as the surviving `destroyed_at` row enforces in Postgres.
 */
final class InMemoryKeystore implements Keystore
{
    /** @var array<string, WrappedDek> */
    private array $live = [];

    /** @var array<string, true> */
    private array $tombstoned = [];

    #[Override]
    public function wrappedDekFor(EncryptionScopeId $scope): ?WrappedDek
    {
        return $this->live[$scope->toString()] ?? null;
    }

    #[Override]
    public function store(EncryptionScopeId $scope, WrappedDek $dek): void
    {
        $key = $scope->toString();

        if (isset($this->tombstoned[$key])) {
            return;
        }

        $this->live[$key] ??= $dek;
    }

    #[Override]
    public function destroy(EncryptionScopeId $scope): bool
    {
        $key = $scope->toString();

        if (!isset($this->live[$key])) {
            return false;
        }

        unset($this->live[$key]);
        $this->tombstoned[$key] = true;

        return true;
    }
}
